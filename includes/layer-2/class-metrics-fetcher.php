<?php
/**
 * Metrics Fetcher - Layer 2 Component
 *
 * Fetches social media metrics from various platforms
 * Routes to appropriate API integration based on platform
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Metrics_Fetcher {

    /**
     * Fetch metrics for a platform
     *
     * @param int $podcast_id Podcast ID
     * @param string $platform Platform name
     * @return array|WP_Error Result with metrics and cost
     */
    public static function fetch($podcast_id, $platform) {
        $start_time = microtime(true);

        // Get social link for this platform
        $social_links = PIT_Database::get_social_links($podcast_id);
        $link = null;

        foreach ($social_links as $l) {
            if ($l->platform === $platform) {
                $link = $l;
                break;
            }
        }

        if (!$link) {
            return new WP_Error('no_link', 'No social link found for this platform');
        }

        // Route to appropriate fetcher
        $result = null;
        $cost = 0;

        switch ($platform) {
            case 'youtube':
                $result = PIT_YouTube_API::fetch_metrics($link->profile_url, $link->profile_handle);
                $cost = 0; // YouTube API is free
                break;

            case 'twitter':
            case 'instagram':
            case 'facebook':
            case 'linkedin':
            case 'tiktok':
                $result = PIT_Apify_Client::fetch_metrics($platform, $link->profile_url, $link->profile_handle);
                $cost = $result['cost'] ?? 0.05;
                break;

            case 'spotify':
            case 'apple_podcasts':
                $result = self::fetch_podcast_platform_metrics($platform, $link->profile_url);
                $cost = 0; // Public data is free
                break;

            default:
                return new WP_Error('unsupported_platform', 'Platform not supported: ' . $platform);
        }

        if (is_wp_error($result)) {
            return $result;
        }

        $duration = microtime(true) - $start_time;

        // Save metrics to database
        $metrics_data = [
            'podcast_id' => $podcast_id,
            'platform' => $platform,
            'followers_count' => $result['followers'] ?? 0,
            'following_count' => $result['following'] ?? 0,
            'posts_count' => $result['posts'] ?? 0,
            'engagement_rate' => $result['engagement_rate'] ?? 0,
            'avg_likes' => $result['avg_likes'] ?? 0,
            'avg_comments' => $result['avg_comments'] ?? 0,
            'avg_shares' => $result['avg_shares'] ?? 0,
            'total_views' => $result['total_views'] ?? 0,
            'subscriber_count' => $result['subscribers'] ?? 0,
            'video_count' => $result['videos'] ?? 0,
            'api_response' => json_encode($result['raw_data'] ?? []),
            'cost_usd' => $cost,
            'fetch_duration_seconds' => $duration,
            'fetched_at' => current_time('mysql'),
            'expires_at' => self::calculate_expiry(),
        ];

        $metrics_id = PIT_Database::insert_metrics($metrics_data);

        if (!$metrics_id) {
            return new WP_Error('db_save_failed', 'Failed to save metrics to database');
        }

        // Log cost
        PIT_Database::log_cost([
            'podcast_id' => $podcast_id,
            'action_type' => 'enrichment',
            'platform' => $platform,
            'cost_usd' => $cost,
            'api_provider' => self::get_api_provider($platform),
            'success' => 1,
            'metadata' => json_encode(['metrics_id' => $metrics_id]),
        ]);

        return [
            'success' => true,
            'metrics_id' => $metrics_id,
            'cost' => $cost,
            'duration' => $duration,
            'metrics' => $metrics_data,
        ];
    }

    /**
     * Fetch podcast platform metrics (Spotify, Apple Podcasts)
     *
     * @param string $platform Platform name
     * @param string $url Profile URL
     * @return array Metrics data
     */
    private static function fetch_podcast_platform_metrics($platform, $url) {
        // For now, return placeholder data
        // In production, you would scrape public data or use APIs

        switch ($platform) {
            case 'spotify':
                return self::scrape_spotify_data($url);

            case 'apple_podcasts':
                return self::scrape_apple_podcasts_data($url);

            default:
                return new WP_Error('unsupported', 'Platform not yet implemented');
        }
    }

    /**
     * Scrape Spotify public data
     *
     * Extracts available public data from Spotify podcast pages including
     * show description metadata and episode counts. Note: Follower counts
     * are not publicly exposed by Spotify.
     */
    private static function scrape_spotify_data($url) {
        // Normalize URL to ensure we have the right format
        $url = self::normalize_spotify_url($url);

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ],
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('fetch_failed', 'Failed to fetch Spotify data: ' . $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return new WP_Error('http_error', 'Spotify returned HTTP ' . $status_code);
        }

        $html = wp_remote_retrieve_body($response);
        $data = [
            'followers' => 0,
            'posts' => 0,
            'total_views' => 0,
            'raw_data' => [],
        ];

        // Try to extract show ID from URL
        if (preg_match('/show\/([a-zA-Z0-9]+)/', $url, $matches)) {
            $data['raw_data']['show_id'] = $matches[1];
        }

        // Try to find JSON-LD data embedded in page
        if (preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches)) {
            $json_ld = json_decode($matches[1], true);
            if ($json_ld && isset($json_ld['@type']) && $json_ld['@type'] === 'PodcastSeries') {
                $data['raw_data']['name'] = $json_ld['name'] ?? '';
                $data['raw_data']['description'] = $json_ld['description'] ?? '';
                $data['raw_data']['publisher'] = $json_ld['publisher']['name'] ?? '';

                // Count episodes if available
                if (isset($json_ld['episode']) && is_array($json_ld['episode'])) {
                    $data['posts'] = count($json_ld['episode']);
                }
            }
        }

        // Try to extract episode count from meta or page content
        if (preg_match('/(\d+)\s*episodes?/i', $html, $matches)) {
            $episode_count = (int) $matches[1];
            if ($episode_count > $data['posts']) {
                $data['posts'] = $episode_count;
            }
        }

        // Extract show title from og:title
        if (preg_match('/<meta property="og:title" content="([^"]+)"/', $html, $matches)) {
            $data['raw_data']['title'] = html_entity_decode($matches[1]);
        }

        // Note about limitations
        $data['raw_data']['note'] = 'Spotify does not publicly expose follower counts. Episode count extracted where available.';
        $data['raw_data']['scraped_at'] = current_time('mysql');

        return $data;
    }

    /**
     * Normalize Spotify URL to the open.spotify.com format
     */
    private static function normalize_spotify_url($url) {
        // Handle spotify: URI format
        if (strpos($url, 'spotify:show:') === 0) {
            $show_id = str_replace('spotify:show:', '', $url);
            return 'https://open.spotify.com/show/' . $show_id;
        }

        // Ensure https
        if (strpos($url, 'http://') === 0) {
            $url = str_replace('http://', 'https://', $url);
        }

        return $url;
    }

    /**
     * Scrape Apple Podcasts public data
     *
     * Extracts ratings, reviews, episode counts and other metadata from
     * Apple Podcasts pages. Uses ratings count as a proxy for popularity.
     */
    private static function scrape_apple_podcasts_data($url) {
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'user-agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ],
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('fetch_failed', 'Failed to fetch Apple Podcasts data: ' . $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return new WP_Error('http_error', 'Apple Podcasts returned HTTP ' . $status_code);
        }

        $html = wp_remote_retrieve_body($response);
        $data = [
            'followers' => 0,
            'posts' => 0,
            'engagement_rate' => 0,
            'raw_data' => [],
        ];

        // Extract podcast ID from URL
        if (preg_match('/id(\d+)/', $url, $matches)) {
            $data['raw_data']['podcast_id'] = $matches[1];
        }

        // Extract ratings count (multiple patterns)
        $ratings = 0;
        $patterns = [
            '/(\d+(?:,\d+)*)\s+Ratings?/i',
            '/(\d+(?:,\d+)*)\s+reviews?/i',
            '/"ratingCount"[:\s]*(\d+)/i',
            '/data-test-rating-count[^>]*>(\d+(?:,\d+)*)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $found_ratings = (int) str_replace(',', '', $matches[1]);
                if ($found_ratings > $ratings) {
                    $ratings = $found_ratings;
                }
            }
        }
        $data['followers'] = $ratings; // Use ratings as popularity proxy
        $data['raw_data']['ratings_count'] = $ratings;

        // Extract average rating
        if (preg_match('/(\d+(?:\.\d+)?)\s*out of\s*5/i', $html, $matches)) {
            $data['raw_data']['average_rating'] = floatval($matches[1]);
            $data['engagement_rate'] = round(floatval($matches[1]) * 20, 2); // Convert 5-star to percentage
        } elseif (preg_match('/"ratingValue"[:\s]*"?(\d+(?:\.\d+)?)"?/i', $html, $matches)) {
            $data['raw_data']['average_rating'] = floatval($matches[1]);
            $data['engagement_rate'] = round(floatval($matches[1]) * 20, 2);
        }

        // Extract episode count
        $episode_patterns = [
            '/(\d+(?:,\d+)*)\s+episodes?/i',
            '/"numberOfEpisodes"[:\s]*(\d+)/i',
        ];

        foreach ($episode_patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $data['posts'] = (int) str_replace(',', '', $matches[1]);
                break;
            }
        }

        // Extract show title from og:title or JSON-LD
        if (preg_match('/<meta property="og:title" content="([^"]+)"/', $html, $matches)) {
            $data['raw_data']['title'] = html_entity_decode($matches[1]);
        }

        // Try to get publisher/author
        if (preg_match('/"author"[:\s]*\{[^}]*"name"[:\s]*"([^"]+)"/i', $html, $matches)) {
            $data['raw_data']['publisher'] = $matches[1];
        }

        // Extract category
        if (preg_match('/"genre"[:\s]*"([^"]+)"/i', $html, $matches)) {
            $data['raw_data']['category'] = $matches[1];
        }

        $data['raw_data']['note'] = 'Apple Podcasts does not expose subscriber counts. Ratings used as popularity proxy.';
        $data['raw_data']['scraped_at'] = current_time('mysql');

        return $data;
    }

    /**
     * Calculate cache expiry time
     *
     * @return string MySQL datetime
     */
    private static function calculate_expiry() {
        // Metrics expire after 7 days
        return date('Y-m-d H:i:s', strtotime('+7 days'));
    }

    /**
     * Get API provider for platform
     *
     * @param string $platform Platform name
     * @return string API provider
     */
    private static function get_api_provider($platform) {
        $providers = [
            'youtube' => 'youtube',
            'twitter' => 'apify',
            'instagram' => 'apify',
            'facebook' => 'apify',
            'linkedin' => 'apify',
            'tiktok' => 'apify',
            'spotify' => 'other',
            'apple_podcasts' => 'other',
        ];

        return $providers[$platform] ?? 'other';
    }

    /**
     * Check if metrics are cached and still valid
     *
     * @param int $podcast_id Podcast ID
     * @param string $platform Platform name
     * @return bool True if cached and valid
     */
    public static function is_cached($podcast_id, $platform) {
        $metrics = PIT_Database::get_latest_metrics($podcast_id, $platform);

        if (!$metrics) {
            return false;
        }

        // Check if expired
        if (strtotime($metrics->expires_at) < time()) {
            return false;
        }

        return true;
    }

    /**
     * Get cached metrics
     *
     * @param int $podcast_id Podcast ID
     * @param string $platform Platform name
     * @return object|null Metrics object
     */
    public static function get_cached($podcast_id, $platform) {
        if (!self::is_cached($podcast_id, $platform)) {
            return null;
        }

        return PIT_Database::get_latest_metrics($podcast_id, $platform);
    }

    /**
     * Invalidate cache for a podcast
     *
     * @param int $podcast_id Podcast ID
     * @param string $platform Optional platform to invalidate
     */
    public static function invalidate_cache($podcast_id, $platform = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_metrics';

        $where = ['podcast_id' => $podcast_id];

        if ($platform) {
            $where['platform'] = $platform;
        }

        // Set expiry to past
        $wpdb->update(
            $table,
            ['expires_at' => date('Y-m-d H:i:s', strtotime('-1 day'))],
            $where,
            ['%s'],
            $platform ? ['%d', '%s'] : ['%d']
        );
    }
}
