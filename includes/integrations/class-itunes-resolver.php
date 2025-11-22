<?php
/**
 * iTunes ID Resolver
 *
 * Resolves Apple Podcasts iTunes ID from various sources:
 * - Podcast Index API response (itunesId field)
 * - iTunes Search API lookup by name/RSS
 * - Apple Podcasts URL extraction
 *
 * iTunes ID is the most stable identifier for podcast deduplication
 * as it persists across feed migrations (e.g., Anchor → Transistor).
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_iTunes_Resolver {

    const ITUNES_SEARCH_API = 'https://itunes.apple.com/search';
    const ITUNES_LOOKUP_API = 'https://itunes.apple.com/lookup';

    /**
     * Resolve iTunes ID from available data sources
     *
     * Priority:
     * 1. Direct iTunes ID if provided
     * 2. Podcast Index API response (contains itunesId)
     * 3. Apple Podcasts URL extraction
     * 4. iTunes Search API lookup by title + author
     *
     * @param array $podcast_data Podcast data array
     * @return string|null iTunes ID or null if not found
     */
    public static function resolve($podcast_data) {
        // Already have iTunes ID
        if (!empty($podcast_data['itunes_id'])) {
            return self::normalize_itunes_id($podcast_data['itunes_id']);
        }

        // Check Podcast Index response for itunesId
        if (!empty($podcast_data['podcast_index_response']['itunesId'])) {
            return self::normalize_itunes_id($podcast_data['podcast_index_response']['itunesId']);
        }

        // Check raw API data (from Podcast Index or Taddy)
        if (!empty($podcast_data['raw_data']['itunesId'])) {
            return self::normalize_itunes_id($podcast_data['raw_data']['itunesId']);
        }

        // Extract from Apple Podcasts URL if present
        if (!empty($podcast_data['apple_podcasts_url'])) {
            $extracted = self::extract_from_url($podcast_data['apple_podcasts_url']);
            if ($extracted) {
                return $extracted;
            }
        }

        // Check social links for Apple Podcasts URL
        if (!empty($podcast_data['social_links']) && is_array($podcast_data['social_links'])) {
            foreach ($podcast_data['social_links'] as $link) {
                if (strpos($link, 'podcasts.apple.com') !== false) {
                    $extracted = self::extract_from_url($link);
                    if ($extracted) {
                        return $extracted;
                    }
                }
            }
        }

        // Search iTunes API as last resort (if we have title)
        if (!empty($podcast_data['title'])) {
            $author = $podcast_data['author'] ?? $podcast_data['publisher'] ?? '';
            return self::search_itunes($podcast_data['title'], $author);
        }

        return null;
    }

    /**
     * Extract iTunes ID from Apple Podcasts URL
     *
     * URL formats:
     * - https://podcasts.apple.com/us/podcast/show-name/id123456789
     * - https://podcasts.apple.com/podcast/id123456789
     * - https://itunes.apple.com/podcast/id123456789
     *
     * @param string $url Apple Podcasts URL
     * @return string|null iTunes ID or null
     */
    public static function extract_from_url($url) {
        // Match id followed by numbers
        if (preg_match('/\/id(\d+)/', $url, $matches)) {
            return $matches[1];
        }

        // Match collectionId parameter
        if (preg_match('/collectionId=(\d+)/', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Search iTunes for a podcast by title and author
     *
     * @param string $title Podcast title
     * @param string $author Author/publisher name (optional)
     * @return string|null iTunes ID or null
     */
    public static function search_itunes($title, $author = '') {
        // Build search term
        $term = trim($title);
        if (!empty($author)) {
            $term .= ' ' . trim($author);
        }

        $params = [
            'term' => $term,
            'media' => 'podcast',
            'entity' => 'podcast',
            'limit' => 5,
        ];

        $response = wp_remote_get(add_query_arg($params, self::ITUNES_SEARCH_API), [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            error_log('iTunes Resolver: Search failed - ' . $response->get_error_message());
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['results'])) {
            return null;
        }

        // Find best match
        $title_lower = strtolower($title);
        foreach ($body['results'] as $result) {
            $result_title = strtolower($result['collectionName'] ?? '');

            // Exact title match
            if ($result_title === $title_lower) {
                return (string) $result['collectionId'];
            }

            // Title contains search term or vice versa
            if (strpos($result_title, $title_lower) !== false ||
                strpos($title_lower, $result_title) !== false) {
                return (string) $result['collectionId'];
            }
        }

        // Return first result if no exact match (may need manual review)
        if (!empty($body['results'][0]['collectionId'])) {
            // Log for potential review
            error_log(sprintf(
                'iTunes Resolver: No exact match for "%s", using first result: %s (%d)',
                $title,
                $body['results'][0]['collectionName'] ?? 'Unknown',
                $body['results'][0]['collectionId']
            ));
            return (string) $body['results'][0]['collectionId'];
        }

        return null;
    }

    /**
     * Lookup podcast details by iTunes ID
     *
     * @param string $itunes_id iTunes ID
     * @return array|null Podcast data or null
     */
    public static function lookup($itunes_id) {
        $params = [
            'id' => $itunes_id,
            'entity' => 'podcast',
        ];

        $response = wp_remote_get(add_query_arg($params, self::ITUNES_LOOKUP_API), [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['results'][0])) {
            return null;
        }

        $result = $body['results'][0];

        return [
            'itunes_id' => (string) $result['collectionId'],
            'title' => $result['collectionName'] ?? '',
            'author' => $result['artistName'] ?? '',
            'rss_feed_url' => $result['feedUrl'] ?? '',
            'artwork_url' => $result['artworkUrl600'] ?? $result['artworkUrl100'] ?? '',
            'genre' => $result['primaryGenreName'] ?? '',
            'country' => $result['country'] ?? '',
            'track_count' => $result['trackCount'] ?? 0,
            'release_date' => $result['releaseDate'] ?? '',
            'raw_data' => $result,
        ];
    }

    /**
     * Normalize iTunes ID to string format
     *
     * @param mixed $id iTunes ID (may be int or string)
     * @return string Normalized ID
     */
    public static function normalize_itunes_id($id) {
        // Remove any non-numeric characters
        return preg_replace('/[^0-9]/', '', (string) $id);
    }

    /**
     * Validate iTunes ID format
     *
     * @param string $id iTunes ID to validate
     * @return bool True if valid format
     */
    public static function is_valid_id($id) {
        // iTunes IDs are numeric, typically 9-10 digits
        return preg_match('/^\d{6,12}$/', $id) === 1;
    }

    /**
     * Build Apple Podcasts URL from iTunes ID
     *
     * @param string $itunes_id iTunes ID
     * @param string $country Country code (default: us)
     * @return string Apple Podcasts URL
     */
    public static function build_apple_url($itunes_id, $country = 'us') {
        return sprintf(
            'https://podcasts.apple.com/%s/podcast/id%s',
            strtolower($country),
            $itunes_id
        );
    }

    /**
     * Batch resolve iTunes IDs for multiple podcasts
     *
     * @param array $podcasts Array of podcast data arrays
     * @return array Podcasts with iTunes IDs added where found
     */
    public static function batch_resolve($podcasts) {
        $resolved = [];

        foreach ($podcasts as $podcast) {
            $itunes_id = self::resolve($podcast);
            if ($itunes_id) {
                $podcast['itunes_id'] = $itunes_id;
            }
            $resolved[] = $podcast;

            // Rate limiting for iTunes API
            if (!empty($podcast['title']) && empty($podcast['itunes_id'])) {
                usleep(200000); // 200ms delay between API calls
            }
        }

        return $resolved;
    }
}
