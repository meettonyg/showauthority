<?php
/**
 * REST API Podcasts Controller
 *
 * Handles all podcast-related REST endpoints.
 *
 * @package PodcastInfluenceTracker
 * @subpackage API
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_REST_Podcasts extends PIT_REST_Base {

    /**
     * Register routes
     */
    public static function register_routes() {
        // List podcasts
        register_rest_route(self::NAMESPACE, '/podcasts', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_podcasts'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // Create podcast
        register_rest_route(self::NAMESPACE, '/podcasts', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_podcast'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // Get single podcast
        register_rest_route(self::NAMESPACE, '/podcasts/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_podcast'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // Update podcast
        register_rest_route(self::NAMESPACE, '/podcasts/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [__CLASS__, 'update_podcast'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // Delete podcast
        register_rest_route(self::NAMESPACE, '/podcasts/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [__CLASS__, 'delete_podcast'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // Track podcast
        register_rest_route(self::NAMESPACE, '/podcasts/(?P<id>\d+)/track', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'track_podcast'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // Untrack podcast
        register_rest_route(self::NAMESPACE, '/podcasts/(?P<id>\d+)/untrack', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'untrack_podcast'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // Refresh podcast
        register_rest_route(self::NAMESPACE, '/podcasts/(?P<id>\d+)/refresh', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'refresh_podcast'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // Get podcast social links
        register_rest_route(self::NAMESPACE, '/podcasts/(?P<id>\d+)/social-links', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_social_links'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // Add social link
        register_rest_route(self::NAMESPACE, '/podcasts/(?P<id>\d+)/social-links', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'add_social_link'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // Get podcast metrics
        register_rest_route(self::NAMESPACE, '/podcasts/(?P<id>\d+)/metrics', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_metrics'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // Get podcast contacts
        register_rest_route(self::NAMESPACE, '/podcasts/(?P<id>\d+)/contacts', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_contacts'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // Get podcast guests
        register_rest_route(self::NAMESPACE, '/podcasts/(?P<id>\d+)/guests', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_guests'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        // Add guest to podcast
        register_rest_route(self::NAMESPACE, '/podcasts/(?P<id>\d+)/guests', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'add_guest'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        // Get content analysis
        register_rest_route(self::NAMESPACE, '/podcasts/(?P<id>\d+)/content-analysis', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_content_analysis'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        // Save content analysis
        register_rest_route(self::NAMESPACE, '/podcasts/(?P<id>\d+)/content-analysis', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'save_content_analysis'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        // Get podcast episodes (from RSS feed)
        register_rest_route(self::NAMESPACE, '/podcasts/(?P<id>\d+)/episodes', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_episodes'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
            'args' => [
                'offset' => [
                    'default' => 0,
                    'sanitize_callback' => 'absint',
                ],
                'limit' => [
                    'default' => 10,
                    'sanitize_callback' => 'absint',
                ],
                'refresh' => [
                    'default' => false,
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ],
            ],
        ]);

        // Discovery statistics (Layer 1 verification)
        register_rest_route(self::NAMESPACE, '/discovery/stats', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_discovery_stats'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // Backfill missing podcast data from RSS
        register_rest_route(self::NAMESPACE, '/podcasts/backfill', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'backfill_podcast_data'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);
    }

    /**
     * List podcasts
     */
    public static function list_podcasts($request) {
        $args = array_merge(
            self::get_pagination_params($request),
            [
                'search' => self::get_search_param($request),
                'tracking_status' => $request->get_param('tracking_status') ?? '',
                'orderby' => $request->get_param('orderby') ?? 'created_at',
                'order' => $request->get_param('order') ?? 'DESC',
            ]
        );

        $result = PIT_Podcast_Repository::list($args);

        // Batch fetch social links and metrics to avoid N+1 queries
        if (!empty($result['podcasts'])) {
            $podcast_ids = array_map(function($p) { return $p->id; }, $result['podcasts']);

            $social_links = PIT_Social_Link_Repository::get_for_podcasts($podcast_ids);
            $metrics = PIT_Metrics_Repository::get_latest_for_podcasts($podcast_ids);

            foreach ($result['podcasts'] as &$podcast) {
                $podcast->social_links = $social_links[$podcast->id] ?? [];
                $podcast->metrics = $metrics[$podcast->id] ?? [];
            }
        }

        return rest_ensure_response($result);
    }

    /**
     * Create podcast
     */
    public static function create_podcast($request) {
        $params = $request->get_json_params();
        $rss_url = $params['rss_url'] ?? '';

        if (empty($rss_url)) {
            return self::error('missing_rss_url', 'RSS URL is required', 400);
        }

        // Use discovery engine
        $result = PIT_Discovery_Engine::discover($rss_url);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * Get single podcast
     */
    public static function get_podcast($request) {
        $podcast_id = (int) $request['id'];

        $podcast = PIT_Podcast_Repository::get($podcast_id);

        if (!$podcast) {
            return self::error('not_found', 'Podcast not found', 404);
        }

        // Enrich
        $podcast->social_links = PIT_Social_Link_Repository::get_for_podcast($podcast_id);
        $podcast->metrics = PIT_Metrics_Repository::get_latest_for_podcast($podcast_id);
        $podcast->jobs = PIT_Job_Repository::get_for_podcast($podcast_id);

        return rest_ensure_response($podcast);
    }

    /**
     * Update podcast
     */
    public static function update_podcast($request) {
        $podcast_id = (int) $request['id'];
        $params = $request->get_json_params();

        $result = PIT_Podcast_Repository::update($podcast_id, $params);

        if (!$result) {
            return self::error('update_failed', 'Failed to update podcast', 500);
        }

        return rest_ensure_response(['success' => true]);
    }

    /**
     * Delete podcast
     */
    public static function delete_podcast($request) {
        $podcast_id = (int) $request['id'];

        $result = PIT_Podcast_Repository::delete($podcast_id);

        if (!$result) {
            return self::error('delete_failed', 'Failed to delete podcast', 500);
        }

        return rest_ensure_response(['success' => true]);
    }

    /**
     * Track podcast
     */
    public static function track_podcast($request) {
        $podcast_id = (int) $request['id'];
        $params = $request->get_json_params();
        $platforms = $params['platforms'] ?? [];

        $job_id = PIT_Job_Queue::queue_job($podcast_id, 'initial_tracking', $platforms);

        if (!$job_id) {
            return self::error('queue_failed', 'Failed to queue tracking job', 500);
        }

        return rest_ensure_response([
            'success' => true,
            'job_id' => $job_id,
            'message' => 'Tracking job queued',
        ]);
    }

    /**
     * Untrack podcast
     */
    public static function untrack_podcast($request) {
        $podcast_id = (int) $request['id'];

        $result = PIT_Podcast_Repository::update($podcast_id, [
            'is_tracked' => 0,
            'tracking_status' => 'not_tracked',
        ]);

        return rest_ensure_response(['success' => true]);
    }

    /**
     * Refresh podcast
     */
    public static function refresh_podcast($request) {
        $podcast_id = (int) $request['id'];
        $params = $request->get_json_params();
        $platforms = $params['platforms'] ?? [];

        $job_id = PIT_Job_Queue::queue_job($podcast_id, 'manual_refresh', $platforms, 80);

        if (!$job_id) {
            return self::error('refresh_failed', 'Failed to queue refresh job', 500);
        }

        return rest_ensure_response([
            'success' => true,
            'job_id' => $job_id,
            'message' => 'Refresh job queued',
        ]);
    }

    /**
     * Get social links
     */
    public static function get_social_links($request) {
        $podcast_id = (int) $request['id'];

        $links = PIT_Social_Link_Repository::get_for_podcast($podcast_id);

        return rest_ensure_response($links);
    }

    /**
     * Add social link
     */
    public static function add_social_link($request) {
        $podcast_id = (int) $request['id'];
        $params = $request->get_json_params();

        $platform = $params['platform'] ?? '';
        $profile_url = $params['url'] ?? $params['profile_url'] ?? '';

        if (empty($platform) || empty($profile_url)) {
            return self::error('missing_params', 'Platform and URL are required', 400);
        }

        $link_id = PIT_Social_Link_Repository::upsert([
            'podcast_id' => $podcast_id,
            'platform' => sanitize_text_field($platform),
            'profile_url' => esc_url_raw($profile_url),
            'profile_handle' => $params['handle'] ?? '',
            'discovery_source' => 'manual',
            'is_verified' => 1,
        ]);

        return rest_ensure_response(['success' => true, 'id' => $link_id]);
    }

    /**
     * Get metrics
     */
    public static function get_metrics($request) {
        $podcast_id = (int) $request['id'];

        $metrics = PIT_Metrics_Repository::get_latest_for_podcast($podcast_id);

        return rest_ensure_response($metrics);
    }

    /**
     * Get contacts
     */
    public static function get_contacts($request) {
        $podcast_id = (int) $request['id'];

        $contacts = PIT_Contact_Repository::get_for_podcast($podcast_id);

        return rest_ensure_response($contacts);
    }

    /**
     * Get guests
     */
    public static function get_guests($request) {
        $podcast_id = (int) $request['id'];

        $appearances = PIT_Appearance_Repository::get_for_podcast($podcast_id);

        return rest_ensure_response($appearances);
    }

    /**
     * Add guest to podcast
     */
    public static function add_guest($request) {
        $podcast_id = (int) $request['id'];
        $params = $request->get_json_params();

        // Create or get guest
        if (!empty($params['guest_id'])) {
            $guest_id = (int) $params['guest_id'];
        } else {
            if (empty($params['full_name'])) {
                return self::error('missing_name', 'Guest name is required', 400);
            }

            $guest_id = PIT_Guest_Repository::upsert([
                'full_name' => $params['full_name'],
                'first_name' => $params['first_name'] ?? '',
                'last_name' => $params['last_name'] ?? '',
                'current_company' => $params['current_company'] ?? '',
                'current_role' => $params['current_role'] ?? '',
                'linkedin_url' => $params['linkedin_url'] ?? '',
                'email' => $params['email'] ?? '',
                'source' => 'manual',
                'source_podcast_id' => $podcast_id,
            ]);
        }

        if (!$guest_id) {
            return self::error('guest_failed', 'Failed to create guest', 500);
        }

        // Create appearance
        $appearance_id = PIT_Appearance_Repository::create([
            'guest_id' => $guest_id,
            'podcast_id' => $podcast_id,
            'episode_number' => $params['episode_number'] ?? null,
            'episode_title' => $params['episode_title'] ?? '',
            'episode_date' => $params['episode_date'] ?? null,
            'episode_url' => $params['episode_url'] ?? '',
            'extraction_method' => 'manual',
        ]);

        $guest = PIT_Guest_Repository::get($guest_id);

        return rest_ensure_response($guest);
    }

    /**
     * Get content analysis
     */
    public static function get_content_analysis($request) {
        $podcast_id = (int) $request['id'];

        $analysis = PIT_Content_Analysis_Repository::get_for_podcast($podcast_id);

        if ($analysis) {
            $analysis->topic_clusters = json_decode($analysis->topic_clusters, true);
            $analysis->keywords = json_decode($analysis->keywords, true);
            $analysis->recent_episodes = json_decode($analysis->recent_episodes, true);
        }

        return rest_ensure_response($analysis);
    }

    /**
     * Save content analysis
     */
    public static function save_content_analysis($request) {
        $podcast_id = (int) $request['id'];
        $params = $request->get_json_params();

        // Encode JSON fields
        if (isset($params['topic_clusters']) && is_array($params['topic_clusters'])) {
            $params['topic_clusters'] = json_encode($params['topic_clusters']);
        }
        if (isset($params['keywords']) && is_array($params['keywords'])) {
            $params['keywords'] = json_encode($params['keywords']);
        }
        if (isset($params['recent_episodes']) && is_array($params['recent_episodes'])) {
            $params['recent_episodes'] = json_encode($params['recent_episodes']);
        }

        $result = PIT_Content_Analysis_Repository::upsert($podcast_id, $params);

        if (!$result) {
            return self::error('save_failed', 'Failed to save content analysis', 500);
        }

        return rest_ensure_response(['success' => true, 'id' => $result]);
    }

    /**
     * Get discovery statistics (Layer 1 verification)
     *
     * Returns comprehensive stats about RSS parsing, social discovery, and contacts.
     */
    public static function get_discovery_stats($request) {
        global $wpdb;

        $podcasts_table = $wpdb->prefix . 'pit_podcasts';
        $social_table = $wpdb->prefix . 'pit_social_links';
        $contacts_table = $wpdb->prefix . 'pit_podcast_contacts';
        $relationships_table = $wpdb->prefix . 'pit_podcast_contact_relationships';

        $stats = [];

        // Podcast counts
        $stats['total_podcasts'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $podcasts_table");
        $stats['podcasts_with_website'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $podcasts_table WHERE website_url IS NOT NULL AND website_url != ''"
        );
        $stats['podcasts_with_artwork'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $podcasts_table WHERE artwork_url IS NOT NULL AND artwork_url != ''"
        );
        $stats['podcasts_with_author'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $podcasts_table WHERE author IS NOT NULL AND author != ''"
        );
        $stats['podcasts_with_email'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $podcasts_table WHERE email IS NOT NULL AND email != ''"
        );
        $stats['homepage_scraped'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $podcasts_table WHERE homepage_scraped = 1"
        );
        $stats['social_links_discovered'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $podcasts_table WHERE social_links_discovered = 1"
        );

        // Social links
        $stats['total_social_links'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $social_table");
        $stats['podcasts_with_social_links'] = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT podcast_id) FROM $social_table"
        );

        // By platform
        $platform_counts = $wpdb->get_results(
            "SELECT platform, COUNT(*) as count FROM $social_table GROUP BY platform ORDER BY count DESC"
        );
        $stats['by_platform'] = [];
        foreach ($platform_counts as $row) {
            $stats['by_platform'][$row->platform] = (int) $row->count;
        }

        // By discovery source
        $source_counts = $wpdb->get_results(
            "SELECT discovery_source, COUNT(*) as count FROM $social_table GROUP BY discovery_source ORDER BY count DESC"
        );
        $stats['by_source'] = [];
        foreach ($source_counts as $row) {
            $stats['by_source'][$row->discovery_source ?? 'unknown'] = (int) $row->count;
        }

        // Contacts
        $stats['total_contacts'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $contacts_table");
        $stats['podcasts_with_contacts'] = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT podcast_id) FROM $relationships_table WHERE active = 1"
        );
        $stats['contacts_from_rss'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $contacts_table WHERE source = 'rss_discovery'"
        );

        // Layer 1 completion percentage
        $total = $stats['total_podcasts'];
        if ($total > 0) {
            $stats['layer1_completion'] = [
                'rss_parsed' => round(($stats['podcasts_with_author'] / $total) * 100, 1) . '%',
                'homepage_scraped' => round(($stats['homepage_scraped'] / $total) * 100, 1) . '%',
                'social_discovered' => round(($stats['podcasts_with_social_links'] / $total) * 100, 1) . '%',
                'contacts_created' => round(($stats['podcasts_with_contacts'] / $total) * 100, 1) . '%',
            ];
        }

        // Sample of podcasts missing social links (for debugging)
        $missing_social = $wpdb->get_results(
            "SELECT p.id, p.title, p.website_url, p.homepage_scraped 
             FROM $podcasts_table p 
             LEFT JOIN $social_table s ON p.id = s.podcast_id 
             WHERE s.id IS NULL 
             LIMIT 5"
        );
        $stats['sample_missing_social'] = $missing_social;

        return rest_ensure_response($stats);
    }

    /**
     * Backfill missing podcast data from RSS feeds
     *
     * Re-parses RSS feeds to fill in missing artwork, description, etc.
     */
    public static function backfill_podcast_data($request) {
        global $wpdb;
        $params = $request->get_json_params();
        $limit = isset($params['limit']) ? min((int) $params['limit'], 500) : 100;
        $field = isset($params['field']) ? sanitize_text_field($params['field']) : 'artwork_url';

        $podcasts_table = $wpdb->prefix . 'pit_podcasts';

        // Find podcasts missing the specified field
        $podcasts = $wpdb->get_results($wpdb->prepare(
            "SELECT id, rss_feed_url, title FROM $podcasts_table 
             WHERE rss_feed_url IS NOT NULL AND rss_feed_url != ''
             AND ($field IS NULL OR $field = '')
             LIMIT %d",
            $limit
        ));

        $results = [
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach ($podcasts as $podcast) {
            $results['processed']++;

            // Re-parse RSS feed
            $rss_data = PIT_RSS_Parser::parse($podcast->rss_feed_url);

            if (is_wp_error($rss_data)) {
                $results['errors'][] = [
                    'podcast_id' => $podcast->id,
                    'title' => $podcast->title,
                    'error' => $rss_data->get_error_message(),
                ];
                continue;
            }

            // Build update data from RSS
            $update_data = [];

            // Map RSS fields to database fields
            $field_mapping = [
                'artwork_url' => 'artwork_url',
                'description' => 'description',
                'category' => 'category',
                'language' => 'language',
                'website_url' => 'homepage_url',
            ];

            foreach ($field_mapping as $db_field => $rss_field) {
                if (!empty($rss_data[$rss_field])) {
                    $update_data[$db_field] = $rss_data[$rss_field];
                }
            }

            if (!empty($update_data)) {
                PIT_Podcast_Repository::update($podcast->id, $update_data);
                $results['updated']++;
            } else {
                $results['skipped']++;
            }

            // Small delay to be nice to RSS servers
            usleep(100000); // 0.1 seconds
        }

        return rest_ensure_response([
            'success' => true,
            'message' => "{$results['updated']} podcasts updated from {$results['processed']} processed",
            'results' => $results,
        ]);
    }
}
