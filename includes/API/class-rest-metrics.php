<?php
/**
 * REST API - Social Metrics Endpoints
 *
 * Handles Layer 2 social metrics fetching (YouTube, Twitter, etc.)
 *
 * @package PodcastInfluenceTracker
 * @subpackage API
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_REST_Metrics {

    const NAMESPACE = 'podcast-influence/v1';

    /**
     * Register routes
     */
    public static function register_routes() {
        // YouTube API test
        register_rest_route(self::NAMESPACE, '/youtube/test', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'test_youtube_api'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // Fetch YouTube metrics for single podcast
        register_rest_route(self::NAMESPACE, '/youtube/fetch/(?P<podcast_id>\d+)', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'fetch_youtube_metrics'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
            'args' => [
                'podcast_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // Batch fetch YouTube metrics
        register_rest_route(self::NAMESPACE, '/youtube/batch', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'batch_fetch_youtube'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // Get YouTube stats overview
        register_rest_route(self::NAMESPACE, '/youtube/stats', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_youtube_stats'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // Get metrics for a podcast (all platforms)
        register_rest_route(self::NAMESPACE, '/metrics/(?P<podcast_id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_podcast_metrics'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
            'args' => [
                'podcast_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // Fix duplicate www in URLs
        register_rest_route(self::NAMESPACE, '/fix-urls', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'fix_duplicate_www'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // Resolve YouTube /c/ and /user/ URLs to channel IDs
        register_rest_route(self::NAMESPACE, '/youtube/resolve-urls', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'resolve_youtube_urls'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // List all non-enriched YouTube links
        register_rest_route(self::NAMESPACE, '/youtube/not-enriched', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_not_enriched_youtube'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);
    }

    /**
     * Check admin permission
     */
    public static function check_admin_permission() {
        return current_user_can('manage_options');
    }

    /**
     * Test YouTube API connection
     */
    public static function test_youtube_api($request) {
        if (!class_exists('PIT_YouTube_API')) {
            return new WP_Error('class_missing', 'YouTube API class not loaded');
        }

        $result = PIT_YouTube_API::test_connection();

        if (is_wp_error($result)) {
            return rest_ensure_response([
                'success' => false,
                'error' => $result->get_error_message(),
            ]);
        }

        return rest_ensure_response($result);
    }

    /**
     * Fetch YouTube metrics for a single podcast
     */
    public static function fetch_youtube_metrics($request) {
        $podcast_id = $request->get_param('podcast_id');

        // Get YouTube social link for this podcast
        global $wpdb;
        $table = $wpdb->prefix . 'pit_social_links';

        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE podcast_id = %d AND platform = 'youtube' LIMIT 1",
            $podcast_id
        ));

        if (!$link) {
            return new WP_Error('no_youtube', 'No YouTube link found for this podcast');
        }

        // Fetch metrics
        $result = PIT_YouTube_API::fetch_metrics($link->profile_url, $link->profile_handle);

        if (is_wp_error($result)) {
            return $result;
        }

        // Save to metrics table
        $metrics_data = [
            'podcast_id' => $podcast_id,
            'social_link_id' => $link->id,
            'platform' => 'youtube',
            'followers_count' => $result['subscribers'],
            'subscriber_count' => $result['subscribers'],
            'total_views' => $result['total_views'],
            'video_count' => $result['videos'],
            'posts_count' => $result['videos'],
            'api_response' => json_encode($result['raw_data']),
            'cost_usd' => 0,
            'fetched_at' => current_time('mysql'),
            'expires_at' => date('Y-m-d H:i:s', strtotime('+7 days')),
        ];

        $metrics_id = self::save_metrics($metrics_data);

        // Update social_links table with latest counts
        $wpdb->update(
            $table,
            [
                'followers_count' => $result['subscribers'],
                'metrics_enriched' => 1,
                'enriched_at' => current_time('mysql'),
            ],
            ['id' => $link->id]
        );

        return rest_ensure_response([
            'success' => true,
            'podcast_id' => $podcast_id,
            'metrics_id' => $metrics_id,
            'subscribers' => $result['subscribers'],
            'total_views' => $result['total_views'],
            'videos' => $result['videos'],
            'channel_title' => $result['raw_data']['statistics']['title'] ?? '',
        ]);
    }

    /**
     * Batch fetch YouTube metrics for all podcasts with YouTube links
     */
    public static function batch_fetch_youtube($request) {
        global $wpdb;

        $params = $request->get_json_params();
        $limit = isset($params['limit']) ? min((int) $params['limit'], 200) : 50;
        $skip_enriched = isset($params['skip_enriched']) ? (bool) $params['skip_enriched'] : true;

        $table = $wpdb->prefix . 'pit_social_links';

        // Get YouTube links that need enrichment
        $where_enriched = $skip_enriched ? "AND (metrics_enriched = 0 OR metrics_enriched IS NULL)" : "";

        $links = $wpdb->get_results($wpdb->prepare(
            "SELECT id, podcast_id, profile_url, profile_handle 
             FROM $table 
             WHERE platform = 'youtube' 
             AND profile_url IS NOT NULL 
             AND profile_url != ''
             $where_enriched
             LIMIT %d",
            $limit
        ));

        if (empty($links)) {
            return rest_ensure_response([
                'success' => true,
                'message' => 'No YouTube links to process',
                'processed' => 0,
            ]);
        }

        // Check quota estimate
        $quota = PIT_YouTube_API::estimate_quota_usage(count($links));

        // Prepare batch data
        $channels = [];
        $link_map = []; // podcast_id => link_id

        foreach ($links as $link) {
            $channels[] = [
                'podcast_id' => $link->podcast_id,
                'profile_url' => $link->profile_url,
                'handle' => $link->profile_handle,
            ];
            $link_map[$link->podcast_id] = $link->id;
        }

        // Batch fetch
        $results = PIT_YouTube_API::batch_fetch($channels);

        $stats = [
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($results as $podcast_id => $result) {
            $stats['processed']++;
            $link_id = $link_map[$podcast_id] ?? null;

            if ($result['success']) {
                $stats['success']++;

                // Save metrics
                $metrics_data = [
                    'podcast_id' => $podcast_id,
                    'social_link_id' => $link_id,
                    'platform' => 'youtube',
                    'followers_count' => $result['subscribers'],
                    'subscriber_count' => $result['subscribers'],
                    'total_views' => $result['total_views'],
                    'video_count' => $result['videos'],
                    'posts_count' => $result['videos'],
                    'api_response' => json_encode($result['raw_data']),
                    'cost_usd' => 0,
                    'fetched_at' => current_time('mysql'),
                    'expires_at' => date('Y-m-d H:i:s', strtotime('+7 days')),
                ];

                self::save_metrics($metrics_data);

                // Update social_links table
                if ($link_id) {
                    $wpdb->update(
                        $table,
                        [
                            'followers_count' => $result['subscribers'],
                            'metrics_enriched' => 1,
                            'enriched_at' => current_time('mysql'),
                        ],
                        ['id' => $link_id]
                    );
                }
            } else {
                $stats['failed']++;
                $stats['errors'][] = [
                    'podcast_id' => $podcast_id,
                    'error' => $result['error'],
                ];
            }
        }

        return rest_ensure_response([
            'success' => true,
            'message' => "{$stats['success']} channels fetched successfully",
            'stats' => $stats,
            'quota_used' => $quota,
        ]);
    }

    /**
     * Get YouTube enrichment stats
     */
    public static function get_youtube_stats($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_social_links';

        $stats = [
            'total_youtube_links' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM $table WHERE platform = 'youtube'"
            ),
            'enriched' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM $table WHERE platform = 'youtube' AND metrics_enriched = 1"
            ),
            'not_enriched' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM $table WHERE platform = 'youtube' AND (metrics_enriched = 0 OR metrics_enriched IS NULL)"
            ),
            'with_subscribers' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM $table WHERE platform = 'youtube' AND followers_count > 0"
            ),
            'total_subscribers' => (int) $wpdb->get_var(
                "SELECT SUM(followers_count) FROM $table WHERE platform = 'youtube'"
            ),
            'avg_subscribers' => (int) $wpdb->get_var(
                "SELECT AVG(followers_count) FROM $table WHERE platform = 'youtube' AND followers_count > 0"
            ),
        ];

        // Top channels by subscribers
        $stats['top_channels'] = $wpdb->get_results(
            "SELECT sl.*, p.title as podcast_title
             FROM $table sl
             JOIN {$wpdb->prefix}pit_podcasts p ON sl.podcast_id = p.id
             WHERE sl.platform = 'youtube' AND sl.followers_count > 0
             ORDER BY sl.followers_count DESC
             LIMIT 10"
        );

        // Check if API is configured
        $stats['api_configured'] = PIT_YouTube_API::is_configured();

        // Quota estimate for remaining
        if ($stats['not_enriched'] > 0) {
            $stats['quota_estimate'] = PIT_YouTube_API::estimate_quota_usage($stats['not_enriched']);
        }

        // Fix any www.www. URLs in the top channels
        foreach ($stats['top_channels'] as &$channel) {
            if (isset($channel->profile_url)) {
                $channel->profile_url = preg_replace('/www\.www\./', 'www.', $channel->profile_url);
            }
        }

        return rest_ensure_response($stats);
    }

    /**
     * Get all metrics for a podcast
     */
    public static function get_podcast_metrics($request) {
        $podcast_id = $request->get_param('podcast_id');

        global $wpdb;
        $metrics_table = $wpdb->prefix . 'pit_metrics';
        $social_table = $wpdb->prefix . 'pit_social_links';

        // Get latest metrics from metrics history table
        $metrics = $wpdb->get_results($wpdb->prepare(
            "SELECT m.*, sl.profile_url, sl.profile_handle
             FROM $metrics_table m
             LEFT JOIN $social_table sl ON m.social_link_id = sl.id
             WHERE m.podcast_id = %d
             ORDER BY m.fetched_at DESC",
            $podcast_id
        ));

        // Also get current social link data
        $social_links = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $social_table WHERE podcast_id = %d ORDER BY platform",
            $podcast_id
        ));

        return rest_ensure_response([
            'podcast_id' => $podcast_id,
            'social_links' => $social_links,
            'metrics_history' => $metrics,
        ]);
    }

    /**
     * Save metrics to database
     */
    private static function save_metrics($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_metrics';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;

        if (!$table_exists) {
            // Create metrics table if it doesn't exist
            self::create_metrics_table();
        }

        $wpdb->insert($table, $data);

        return $wpdb->insert_id ?: false;
    }

    /**
     * Create metrics table if missing
     */
    private static function create_metrics_table() {
        global $wpdb;

        $table = $wpdb->prefix . 'pit_metrics';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            podcast_id bigint(20) unsigned NOT NULL,
            social_link_id bigint(20) unsigned DEFAULT NULL,
            platform varchar(50) NOT NULL,
            followers_count bigint(20) DEFAULT 0,
            following_count bigint(20) DEFAULT 0,
            posts_count bigint(20) DEFAULT 0,
            subscriber_count bigint(20) DEFAULT 0,
            video_count bigint(20) DEFAULT 0,
            total_views bigint(20) DEFAULT 0,
            engagement_rate decimal(5,2) DEFAULT 0,
            avg_likes bigint(20) DEFAULT 0,
            avg_comments bigint(20) DEFAULT 0,
            avg_shares bigint(20) DEFAULT 0,
            api_response longtext DEFAULT NULL,
            cost_usd decimal(10,4) DEFAULT 0,
            fetch_duration_seconds decimal(5,2) DEFAULT 0,
            fetched_at datetime DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY podcast_id (podcast_id),
            KEY platform (platform),
            KEY fetched_at (fetched_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Fix www.www. URLs in social_links table
     */
    public static function fix_duplicate_www($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_social_links';

        // Find and fix URLs with www.www.
        $affected = $wpdb->query(
            "UPDATE $table SET profile_url = REPLACE(profile_url, 'www.www.', 'www.') WHERE profile_url LIKE '%www.www.%'"
        );

        return rest_ensure_response([
            'success' => true,
            'fixed' => $affected,
            'message' => "{$affected} URLs fixed",
        ]);
    }

    /**
     * Resolve YouTube /c/ and /user/ URLs to channel IDs
     * 
     * This fixes existing URLs that couldn't be resolved via API
     */
    public static function resolve_youtube_urls($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_social_links';

        $params = $request->get_json_params();
        $limit = isset($params['limit']) ? min((int) $params['limit'], 50) : 20;

        // Find YouTube URLs with /c/ or /user/ format
        $links = $wpdb->get_results($wpdb->prepare(
            "SELECT id, podcast_id, profile_url, profile_handle 
             FROM $table 
             WHERE platform = 'youtube' 
             AND (profile_url LIKE '%%/c/%%' OR profile_url LIKE '%%/user/%%')
             LIMIT %d",
            $limit
        ));

        if (empty($links)) {
            return rest_ensure_response([
                'success' => true,
                'message' => 'No /c/ or /user/ YouTube URLs to resolve',
                'processed' => 0,
            ]);
        }

        $stats = [
            'processed' => 0,
            'resolved' => 0,
            'failed' => 0,
            'results' => [],
        ];

        foreach ($links as $link) {
            $stats['processed']++;
            
            $resolved_url = self::resolve_youtube_url_standalone($link->profile_url);
            
            if ($resolved_url && $resolved_url !== $link->profile_url) {
                // Extract new handle if it's a channel ID or @ handle
                $new_handle = '';
                if (preg_match('/\/channel\/(UC[a-zA-Z0-9_-]+)/', $resolved_url, $matches)) {
                    $new_handle = $matches[1];
                } elseif (preg_match('/@([a-zA-Z0-9_-]+)/', $resolved_url, $matches)) {
                    $new_handle = $matches[1];
                }

                // Update database
                $wpdb->update(
                    $table,
                    [
                        'profile_url' => $resolved_url,
                        'profile_handle' => $new_handle ?: $link->profile_handle,
                    ],
                    ['id' => $link->id]
                );

                $stats['resolved']++;
                $stats['results'][] = [
                    'podcast_id' => $link->podcast_id,
                    'old_url' => $link->profile_url,
                    'new_url' => $resolved_url,
                ];
            } else {
                $stats['failed']++;
            }

            // Small delay to be nice to YouTube
            usleep(500000); // 0.5 seconds
        }

        return rest_ensure_response([
            'success' => true,
            'message' => "{$stats['resolved']} URLs resolved from {$stats['processed']} processed",
            'stats' => $stats,
        ]);
    }

    /**
     * Resolve a YouTube URL to its canonical form
     */
    private static function resolve_youtube_url_standalone($url) {
        // Only resolve /c/ and /user/ URLs
        if (!preg_match('/youtube\.com\/(c|user)\/([a-zA-Z0-9_-]+)/', $url)) {
            return $url;
        }

        // Fetch the YouTube page
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'headers' => [
                'Accept-Language' => 'en-US,en;q=0.9',
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return null; // Channel might not exist
        }

        $html = wp_remote_retrieve_body($response);

        if (empty($html)) {
            return null;
        }

        // Try to extract channel ID from page
        // Pattern 1: "channelId":"UCxxxxxxxx"
        if (preg_match('/"channelId"\s*:\s*"(UC[a-zA-Z0-9_-]+)"/', $html, $matches)) {
            return 'https://www.youtube.com/channel/' . $matches[1];
        }

        // Pattern 2: "externalId":"UCxxxxxxxx"
        if (preg_match('/"externalId"\s*:\s*"(UC[a-zA-Z0-9_-]+)"/', $html, $matches)) {
            return 'https://www.youtube.com/channel/' . $matches[1];
        }

        // Pattern 3: canonical link with @handle
        if (preg_match('/"canonicalBaseUrl"\s*:\s*"\/@([a-zA-Z0-9_-]+)"/', $html, $matches)) {
            return 'https://www.youtube.com/@' . $matches[1];
        }

        // Pattern 4: browse_id in ytInitialData
        if (preg_match('/"browseId"\s*:\s*"(UC[a-zA-Z0-9_-]+)"/', $html, $matches)) {
            return 'https://www.youtube.com/channel/' . $matches[1];
        }

        return null;
    }

    /**
     * List all non-enriched YouTube links with details
     */
    public static function list_not_enriched_youtube($request) {
        global $wpdb;
        $social_table = $wpdb->prefix . 'pit_social_links';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';

        $links = $wpdb->get_results(
            "SELECT sl.id, sl.podcast_id, sl.profile_url, sl.profile_handle, sl.metrics_enriched,
                    p.title as podcast_title
             FROM $social_table sl
             LEFT JOIN $podcasts_table p ON sl.podcast_id = p.id
             WHERE sl.platform = 'youtube' 
             AND (sl.metrics_enriched = 0 OR sl.metrics_enriched IS NULL)
             ORDER BY sl.podcast_id ASC"
        );

        $result = [
            'total' => count($links),
            'by_url_type' => [
                'channel' => 0,
                'handle' => 0,
                'custom_c' => 0,
                'user' => 0,
                'other' => 0,
            ],
            'links' => [],
        ];

        foreach ($links as $link) {
            $url_type = 'other';
            if (strpos($link->profile_url, '/channel/') !== false) {
                $url_type = 'channel';
            } elseif (strpos($link->profile_url, '/@') !== false) {
                $url_type = 'handle';
            } elseif (strpos($link->profile_url, '/c/') !== false) {
                $url_type = 'custom_c';
            } elseif (strpos($link->profile_url, '/user/') !== false) {
                $url_type = 'user';
            }

            $result['by_url_type'][$url_type]++;

            $result['links'][] = [
                'podcast_id' => $link->podcast_id,
                'podcast_title' => $link->podcast_title,
                'profile_url' => $link->profile_url,
                'url_type' => $url_type,
            ];
        }

        return rest_ensure_response($result);
    }
}
