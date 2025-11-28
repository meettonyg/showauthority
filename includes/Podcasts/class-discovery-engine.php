<?php
/**
 * Discovery Engine - Layer 1 Orchestrator
 *
 * Combines RSS parsing and homepage scraping to discover
 * podcast information and social media links.
 *
 * This runs immediately when a podcast is imported (< 3 seconds)
 * Cost: $0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Discovery_Engine {

    /**
     * Discover podcast from RSS URL (main entry point)
     *
     * @param string $rss_url RSS feed URL
     * @return array|WP_Error Result with podcast_id and social_links_found
     */
    public static function discover_from_rss($rss_url) {
        return self::discover($rss_url);
    }

    /**
     * Discover podcast data and social links
     *
     * @param string $rss_url RSS feed URL
     * @return array|WP_Error Result with podcast_id and social_links_found
     */
    public static function discover($rss_url) {
        $start_time = microtime(true);

        // Step 1: Parse RSS feed (< 1 second)
        $rss_data = PIT_RSS_Parser::parse($rss_url);

        if (is_wp_error($rss_data)) {
            return $rss_data;
        }

        // Step 2: Extract social links from RSS
        $social_links = isset($rss_data['social_links']) ? $rss_data['social_links'] : [];
        unset($rss_data['social_links']);

        // Step 3: Prepare podcast data for database
        $podcast_data = self::map_rss_to_podcast($rss_data, $rss_url);

        // Step 4: Insert/update podcast using Repository (handles deduplication)
        $podcast_id = PIT_Podcast_Repository::upsert($podcast_data);

        if (!$podcast_id) {
            return new WP_Error('db_insert_failed', 'Failed to save podcast to database');
        }

        // Step 5: Scrape homepage for additional social links (2-3 seconds)
        $homepage_url = isset($rss_data['homepage_url']) ? $rss_data['homepage_url'] : '';
        if (!empty($homepage_url)) {
            $homepage_links = PIT_Homepage_Scraper::scrape($homepage_url);
            if (is_array($homepage_links)) {
                $social_links = array_merge($social_links, $homepage_links);
            }
        }

        // Step 6: Save social links to database
        $saved_links = 0;
        foreach ($social_links as $link) {
            $link_data = [
                'podcast_id'       => $podcast_id,
                'platform'         => $link['platform'],
                'profile_url'      => $link['url'] ?? $link['profile_url'] ?? '',
                'profile_handle'   => $link['handle'] ?? $link['profile_handle'] ?? '',
                'discovery_source' => $link['source'] ?? 'rss',
                'discovered_at'    => current_time('mysql'),
            ];

            if (PIT_Social_Link_Repository::create($link_data)) {
                $saved_links++;
            }
        }

        // Update podcast discovery flags
        PIT_Podcast_Repository::update($podcast_id, [
            'social_links_discovered' => 1,
            'homepage_scraped' => !empty($homepage_url) ? 1 : 0,
            'last_rss_check' => current_time('mysql'),
        ]);

        $duration = microtime(true) - $start_time;

        return [
            'success'           => true,
            'podcast_id'        => $podcast_id,
            'podcast_name'      => $podcast_data['title'],
            'social_links_found' => $saved_links,
            'homepage_url'      => $homepage_url,
            'duration_seconds'  => round($duration, 2),
        ];
    }

    /**
     * Map RSS parser output to podcast database fields
     *
     * @param array $rss_data Data from RSS parser
     * @param string $rss_url Original RSS URL
     * @return array Podcast data ready for database
     */
    private static function map_rss_to_podcast($rss_data, $rss_url) {
        return [
            'title'           => $rss_data['podcast_name'] ?? $rss_data['title'] ?? 'Unknown Podcast',
            'description'     => $rss_data['description'] ?? '',
            'author'          => $rss_data['author'] ?? $rss_data['itunes_author'] ?? '',
            'email'           => $rss_data['email'] ?? $rss_data['owner_email'] ?? '',
            'rss_feed_url'    => $rss_url,
            'website_url'     => $rss_data['homepage_url'] ?? $rss_data['link'] ?? '',
            'artwork_url'     => $rss_data['artwork_url'] ?? $rss_data['image'] ?? '',
            'category'        => is_array($rss_data['categories'] ?? null) 
                                 ? implode(', ', $rss_data['categories']) 
                                 : ($rss_data['category'] ?? ''),
            'language'        => $rss_data['language'] ?? 'en',
            'episode_count'   => $rss_data['episode_count'] ?? 0,
            'itunes_id'       => $rss_data['itunes_id'] ?? null,
            'tracking_status' => 'not_tracked',
            'source'          => 'rss_discovery',
        ];
    }

    /**
     * Re-discover social links for an existing podcast
     *
     * @param int $podcast_id Podcast ID
     * @return array|WP_Error Result
     */
    public static function rediscover($podcast_id) {
        $podcast = PIT_Podcast_Repository::get($podcast_id);

        if (!$podcast) {
            return new WP_Error('podcast_not_found', 'Podcast not found');
        }

        // Re-parse RSS feed
        $rss_data = PIT_RSS_Parser::parse($podcast->rss_feed_url);

        if (is_wp_error($rss_data)) {
            return $rss_data;
        }

        // Extract social links from RSS
        $social_links = isset($rss_data['social_links']) ? $rss_data['social_links'] : [];

        // Also scrape homepage again
        if (!empty($podcast->website_url)) {
            $homepage_links = PIT_Homepage_Scraper::scrape($podcast->website_url);
            if (is_array($homepage_links)) {
                $social_links = array_merge($social_links, $homepage_links);
            }
        }

        // Save social links
        $saved_links = 0;
        foreach ($social_links as $link) {
            $link_data = [
                'podcast_id'       => $podcast_id,
                'platform'         => $link['platform'],
                'profile_url'      => $link['url'] ?? $link['profile_url'] ?? '',
                'profile_handle'   => $link['handle'] ?? $link['profile_handle'] ?? '',
                'discovery_source' => $link['source'] ?? 'rss',
                'discovered_at'    => current_time('mysql'),
            ];

            if (PIT_Social_Link_Repository::create($link_data)) {
                $saved_links++;
            }
        }

        // Update discovery timestamp
        PIT_Podcast_Repository::update($podcast_id, [
            'last_rss_check' => current_time('mysql'),
        ]);

        return [
            'success'           => true,
            'podcast_id'        => $podcast_id,
            'social_links_found' => $saved_links,
        ];
    }

    /**
     * Get discovery summary for a podcast
     *
     * @param int $podcast_id Podcast ID
     * @return array|null Summary data
     */
    public static function get_summary($podcast_id) {
        $podcast = PIT_Podcast_Repository::get($podcast_id);

        if (!$podcast) {
            return null;
        }

        $social_links = PIT_Social_Link_Repository::get_for_podcast($podcast_id);

        // Group by platform
        $platforms = [];
        foreach ($social_links as $link) {
            $platforms[$link->platform] = [
                'url'    => $link->profile_url,
                'handle' => $link->profile_handle,
                'source' => $link->discovery_source,
            ];
        }

        return [
            'podcast_id'          => $podcast->id,
            'podcast_name'        => $podcast->title,
            'homepage_url'        => $podcast->website_url,
            'is_tracked'          => (bool) $podcast->is_tracked,
            'tracking_status'     => $podcast->tracking_status,
            'platforms_discovered' => count($platforms),
            'platforms'           => $platforms,
            'discovered_at'       => $podcast->created_at,
        ];
    }

    /**
     * Add social link manually
     *
     * @param int $podcast_id Podcast ID
     * @param string $platform Platform name
     * @param string $profile_url Profile URL
     * @return int|false Link ID or false
     */
    public static function add_manual_link($podcast_id, $platform, $profile_url) {
        $podcast = PIT_Podcast_Repository::get($podcast_id);

        if (!$podcast) {
            return false;
        }

        // Extract handle from URL
        $handle = PIT_Homepage_Scraper::extract_handle($profile_url, $platform);

        $data = [
            'podcast_id'       => $podcast_id,
            'platform'         => $platform,
            'profile_url'      => $profile_url,
            'profile_handle'   => $handle,
            'discovery_source' => 'manual',
            'is_verified'      => 1,
            'discovered_at'    => current_time('mysql'),
        ];

        return PIT_Social_Link_Repository::create($data);
    }

    /**
     * Remove social link
     *
     * @param int $link_id Social link ID
     * @return bool Success
     */
    public static function remove_link($link_id) {
        return PIT_Social_Link_Repository::delete($link_id);
    }

    /**
     * Batch discover multiple podcasts
     *
     * @param array $rss_urls Array of RSS URLs
     * @return array Results
     */
    public static function batch_discover($rss_urls) {
        $results = [
            'success' => [],
            'failed'  => [],
        ];

        foreach ($rss_urls as $rss_url) {
            $result = self::discover($rss_url);

            if (is_wp_error($result)) {
                $results['failed'][] = [
                    'url'   => $rss_url,
                    'error' => $result->get_error_message(),
                ];
            } else {
                $results['success'][] = $result;
            }

            // Small delay to be nice to servers
            usleep(500000); // 0.5 seconds
        }

        return $results;
    }

    /**
     * Get statistics about discovered platforms
     *
     * @return array Statistics
     */
    public static function get_statistics() {
        global $wpdb;
        $table_podcasts = $wpdb->prefix . 'pit_podcasts';
        $table_social = $wpdb->prefix . 'pit_social_links';

        $stats = [
            'total_podcasts'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_podcasts"),
            'podcasts_with_links' => (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT podcast_id) FROM $table_social"
            ),
            'total_links'        => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_social"),
            'by_platform'        => [],
            'by_source'          => [],
        ];

        // Count by platform
        $platform_counts = $wpdb->get_results(
            "SELECT platform, COUNT(*) as count FROM $table_social GROUP BY platform"
        );

        foreach ($platform_counts as $row) {
            $stats['by_platform'][$row->platform] = (int) $row->count;
        }

        // Count by source
        $source_counts = $wpdb->get_results(
            "SELECT discovery_source, COUNT(*) as count FROM $table_social GROUP BY discovery_source"
        );

        foreach ($source_counts as $row) {
            $stats['by_source'][$row->discovery_source] = (int) $row->count;
        }

        return $stats;
    }
}
