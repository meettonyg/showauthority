<?php
/**
 * REST API Controller
 *
 * Exposes endpoints for the frontend Vue.js application
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_REST_Controller {

    const NAMESPACE = 'podcast-influence/v1';

    /**
     * Initialize REST API
     */
    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    /**
     * Register REST routes
     */
    public static function register_routes() {
        // Podcasts endpoints
        register_rest_route(self::NAMESPACE, '/podcasts', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_podcasts'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/podcasts', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'add_podcast'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/podcasts/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_podcast'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/podcasts/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [__CLASS__, 'delete_podcast'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // Tracking endpoints
        register_rest_route(self::NAMESPACE, '/podcasts/(?P<id>\d+)/track', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'track_podcast'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/podcasts/(?P<id>\d+)/untrack', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'untrack_podcast'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/podcasts/(?P<id>\d+)/refresh', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'refresh_podcast'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // Social links endpoints
        register_rest_route(self::NAMESPACE, '/podcasts/(?P<id>\d+)/social-links', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_social_links'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/podcasts/(?P<id>\d+)/social-links', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'add_social_link'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // Metrics endpoints
        register_rest_route(self::NAMESPACE, '/podcasts/(?P<id>\d+)/metrics', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_metrics'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // Add metrics to social link
        register_rest_route(self::NAMESPACE, '/social-links/(?P<id>\d+)/metrics', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'add_social_link_metrics'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // Get guests for a podcast (convenience endpoint)
        register_rest_route(self::NAMESPACE, '/podcasts/(?P<id>\d+)/guests', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_podcast_guests'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // Jobs endpoints
        register_rest_route(self::NAMESPACE, '/jobs/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_job_status'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/jobs/(?P<id>\d+)/cancel', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'cancel_job'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // Statistics endpoints
        register_rest_route(self::NAMESPACE, '/stats/overview', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_overview_stats'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/stats/costs', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_cost_stats'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // Settings endpoints
        register_rest_route(self::NAMESPACE, '/settings', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_settings'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/settings', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'save_settings'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // ========== PODCAST INTELLIGENCE ENDPOINTS ==========

        // Contacts endpoints
        register_rest_route(self::NAMESPACE, '/contacts', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_contacts'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/contacts', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_contact'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/contacts/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_contact'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/contacts/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [__CLASS__, 'update_contact'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/contacts/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [__CLASS__, 'delete_contact'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // Podcast-Contact relationships
        register_rest_route(self::NAMESPACE, '/podcasts/(?P<id>\d+)/contacts', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_podcast_contacts'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/podcasts/(?P<id>\d+)/contacts', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'link_podcast_contact'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // Entry-Podcast bridge
        register_rest_route(self::NAMESPACE, '/entries/(?P<id>\d+)/podcast', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_entry_podcast_data'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/entries/(?P<id>\d+)/contact-email', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_entry_contact_email'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // Intelligence podcasts (guestify_podcasts table)
        register_rest_route(self::NAMESPACE, '/intelligence/podcasts', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_intelligence_podcasts'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/intelligence/podcasts', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_intelligence_podcast'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/intelligence/podcasts/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_intelligence_podcast'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // ========== GUEST INTELLIGENCE ENDPOINTS (Phase 1) ==========

        // Guests CRUD
        register_rest_route(self::NAMESPACE, '/guests', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_guests'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        register_rest_route(self::NAMESPACE, '/guests', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_guest'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        register_rest_route(self::NAMESPACE, '/guests/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_guest'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        register_rest_route(self::NAMESPACE, '/guests/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [__CLASS__, 'update_guest'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        register_rest_route(self::NAMESPACE, '/guests/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [__CLASS__, 'delete_guest'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // Guest Appearances
        register_rest_route(self::NAMESPACE, '/guests/(?P<id>\d+)/appearances', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_guest_appearances'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        register_rest_route(self::NAMESPACE, '/guests/(?P<id>\d+)/appearances', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'add_guest_appearance'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        register_rest_route(self::NAMESPACE, '/appearances/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [__CLASS__, 'update_appearance'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        register_rest_route(self::NAMESPACE, '/appearances/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [__CLASS__, 'delete_appearance'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        // Podcast Guests
        register_rest_route(self::NAMESPACE, '/intelligence/podcasts/(?P<id>\d+)/guests', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_podcast_guests'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        register_rest_route(self::NAMESPACE, '/intelligence/podcasts/(?P<id>\d+)/guests', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'add_podcast_guest'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        // Topics
        register_rest_route(self::NAMESPACE, '/topics', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_topics'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        register_rest_route(self::NAMESPACE, '/topics', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_topic'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        // Guest Topics
        register_rest_route(self::NAMESPACE, '/guests/(?P<id>\d+)/topics', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_guest_topics'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        register_rest_route(self::NAMESPACE, '/guests/(?P<id>\d+)/topics', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'assign_guest_topic'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        // Content Analysis
        register_rest_route(self::NAMESPACE, '/intelligence/podcasts/(?P<id>\d+)/content-analysis', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_content_analysis'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        register_rest_route(self::NAMESPACE, '/intelligence/podcasts/(?P<id>\d+)/content-analysis', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'save_content_analysis'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        // Guest Network
        register_rest_route(self::NAMESPACE, '/guests/(?P<id>\d+)/network', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_guest_network'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        // Guest Verification
        register_rest_route(self::NAMESPACE, '/guests/(?P<id>\d+)/verify', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'verify_guest'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        // Social Links (Manual Entry)
        register_rest_route(self::NAMESPACE, '/intelligence/podcasts/(?P<id>\d+)/social-links', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'add_intelligence_social_link'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        // Metrics (Manual Entry)
        register_rest_route(self::NAMESPACE, '/social-links/(?P<id>\d+)/metrics', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'add_manual_metrics'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        register_rest_route(self::NAMESPACE, '/social-links/(?P<id>\d+)/metrics/history', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_metrics_history'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);
    }

    /**
     * Check permission (admin only)
     */
    public static function check_permission() {
        return current_user_can('manage_options');
    }

    /**
     * Check if user is logged in (for frontend access)
     */
    public static function check_logged_in() {
        return is_user_logged_in();
    }

    /**
     * Get podcasts list
     */
    public static function get_podcasts($request) {
        $params = $request->get_query_params();

        $args = [
            'per_page' => $params['per_page'] ?? 20,
            'page' => $params['page'] ?? 1,
            'search' => $params['search'] ?? '',
            'tracking_status' => $params['tracking_status'] ?? '',
            'orderby' => $params['orderby'] ?? 'created_at',
            'order' => $params['order'] ?? 'DESC',
        ];

        $result = PIT_Database::get_podcasts($args);

        // Enrich with social links and metrics
        foreach ($result['podcasts'] as &$podcast) {
            $podcast->social_links = PIT_Database::get_social_links($podcast->id);
            $podcast->metrics = PIT_Database::get_latest_metrics($podcast->id);
        }

        return rest_ensure_response($result);
    }

    /**
     * Add new podcast
     */
    public static function add_podcast($request) {
        $params = $request->get_json_params();
        $rss_url = $params['rss_url'] ?? '';

        if (empty($rss_url)) {
            return new WP_Error('missing_rss_url', 'RSS URL is required', ['status' => 400]);
        }

        // Validate RSS URL
        if (!PIT_RSS_Parser::is_valid_rss_url($rss_url)) {
            return new WP_Error('invalid_rss_url', 'Invalid RSS URL', ['status' => 400]);
        }

        // Discover podcast (Layer 1)
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

        $summary = PIT_Discovery_Engine::get_summary($podcast_id);

        if (!$summary) {
            return new WP_Error('not_found', 'Podcast not found', ['status' => 404]);
        }

        // Add metrics
        $summary['metrics'] = PIT_Database::get_latest_metrics($podcast_id);
        $summary['jobs'] = PIT_Job_Queue::get_podcast_jobs($podcast_id);

        return rest_ensure_response($summary);
    }

    /**
     * Delete podcast
     */
    public static function delete_podcast($request) {
        global $wpdb;
        $podcast_id = (int) $request['id'];

        $table = $wpdb->prefix . 'pit_podcasts';
        $result = $wpdb->delete($table, ['id' => $podcast_id], ['%d']);

        if ($result === false) {
            return new WP_Error('delete_failed', 'Failed to delete podcast', ['status' => 500]);
        }

        return rest_ensure_response(['success' => true]);
    }

    /**
     * Track podcast (Layer 2 - Queue job)
     */
    public static function track_podcast($request) {
        $podcast_id = (int) $request['id'];
        $params = $request->get_json_params();
        $platforms = $params['platforms'] ?? [];

        $job_id = PIT_Job_Queue::queue_job($podcast_id, 'initial_tracking', $platforms, 70);

        if (!$job_id) {
            return new WP_Error('queue_failed', 'Failed to queue tracking job', ['status' => 500]);
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
        global $wpdb;
        $podcast_id = (int) $request['id'];

        $table = $wpdb->prefix . 'pit_podcasts';
        $wpdb->update(
            $table,
            ['is_tracked' => 0, 'tracking_status' => 'not_tracked'],
            ['id' => $podcast_id],
            ['%d', '%s'],
            ['%d']
        );

        return rest_ensure_response(['success' => true]);
    }

    /**
     * Refresh podcast metrics
     */
    public static function refresh_podcast($request) {
        $podcast_id = (int) $request['id'];
        $params = $request->get_json_params();
        $platforms = $params['platforms'] ?? [];

        $job_id = PIT_Background_Refresh::manual_refresh($podcast_id, $platforms);

        if (!$job_id) {
            return new WP_Error('refresh_failed', 'Failed to queue refresh job', ['status' => 500]);
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

        $links = PIT_Database::get_social_links($podcast_id);

        return rest_ensure_response($links);
    }

    /**
     * Add social link manually
     */
    public static function add_social_link($request) {
        $podcast_id = (int) $request['id'];
        $params = $request->get_json_params();

        $platform = $params['platform'] ?? '';
        $profile_url = $params['url'] ?? $params['profile_url'] ?? '';
        $handle = $params['handle'] ?? '';

        if (empty($platform) || empty($profile_url)) {
            return new WP_Error('missing_params', 'Platform and URL are required', ['status' => 400]);
        }

        // Try using Discovery Engine first
        $result = PIT_Discovery_Engine::add_manual_link($podcast_id, $platform, $profile_url);

        if (!$result) {
            // Fallback: Insert directly
            global $wpdb;
            $table_name = $wpdb->prefix . 'guestify_social_links';
            $result = $wpdb->insert($table_name, [
                'podcast_id' => $podcast_id,
                'platform' => sanitize_text_field($platform),
                'url' => esc_url_raw($profile_url),
                'handle' => sanitize_text_field($handle),
                'source' => 'manual',
                'created_at' => current_time('mysql'),
            ]);
        }

        if (!$result) {
            return new WP_Error('add_failed', 'Failed to add social link', ['status' => 500]);
        }

        return rest_ensure_response(['success' => true]);
    }

    /**
     * Get metrics
     */
    public static function get_metrics($request) {
        $podcast_id = (int) $request['id'];

        $metrics = PIT_Database::get_latest_metrics($podcast_id);

        return rest_ensure_response($metrics);
    }

    /**
     * Add metrics to a social link
     */
    public static function add_social_link_metrics($request) {
        $social_link_id = (int) $request['id'];
        $params = $request->get_json_params();

        global $wpdb;
        $table_name = $wpdb->prefix . 'guestify_social_metrics';

        $data = [
            'social_link_id' => $social_link_id,
            'followers_count' => isset($params['followers_count']) ? (int) $params['followers_count'] : null,
            'following_count' => isset($params['following_count']) ? (int) $params['following_count'] : null,
            'posts_count' => isset($params['posts_count']) ? (int) $params['posts_count'] : null,
            'total_views' => isset($params['total_views']) ? (int) $params['total_views'] : null,
            'engagement_rate' => isset($params['engagement_rate']) ? (float) $params['engagement_rate'] : null,
            'avg_likes' => isset($params['avg_likes']) ? (int) $params['avg_likes'] : null,
            'data_source' => $params['data_source'] ?? 'manual',
            'data_quality_score' => isset($params['data_quality_score']) ? (int) $params['data_quality_score'] : 90,
            'fetched_at' => $params['fetched_at'] ?? current_time('mysql'),
        ];

        $result = $wpdb->insert($table_name, $data);

        if (!$result) {
            return new WP_Error('insert_failed', 'Failed to add metrics', ['status' => 500]);
        }

        return rest_ensure_response([
            'success' => true,
            'id' => $wpdb->insert_id,
        ]);
    }

    /**
     * Get guests for a podcast
     */
    public static function get_podcast_guests($request) {
        $podcast_id = (int) $request['id'];

        global $wpdb;
        $guests_table = $wpdb->prefix . 'guestify_guests';
        $appearances_table = $wpdb->prefix . 'guestify_guest_appearances';

        $guests = $wpdb->get_results($wpdb->prepare("
            SELECT g.*, a.episode_number, a.episode_title, a.episode_date
            FROM {$guests_table} g
            INNER JOIN {$appearances_table} a ON g.id = a.guest_id
            WHERE a.podcast_id = %d
            ORDER BY a.episode_date DESC
        ", $podcast_id));

        return rest_ensure_response($guests ?: []);
    }

    /**
     * Get job status
     */
    public static function get_job_status($request) {
        $job_id = (int) $request['id'];

        $job = PIT_Job_Queue::get_job_status($job_id);

        if (!$job) {
            return new WP_Error('not_found', 'Job not found', ['status' => 404]);
        }

        return rest_ensure_response($job);
    }

    /**
     * Cancel job
     */
    public static function cancel_job($request) {
        $job_id = (int) $request['id'];

        $result = PIT_Job_Queue::cancel_job($job_id);

        if (!$result) {
            return new WP_Error('cancel_failed', 'Failed to cancel job', ['status' => 500]);
        }

        return rest_ensure_response(['success' => true]);
    }

    /**
     * Get overview statistics
     */
    public static function get_overview_stats($request) {
        $discovery_stats = PIT_Discovery_Engine::get_statistics();
        $job_stats = PIT_Job_Queue::get_statistics();
        $refresh_stats = PIT_Background_Refresh::get_statistics();

        return rest_ensure_response([
            'discovery' => $discovery_stats,
            'jobs' => $job_stats,
            'refresh' => $refresh_stats,
        ]);
    }

    /**
     * Get cost statistics
     */
    public static function get_cost_stats($request) {
        $stats = [
            'today' => PIT_Database::get_total_costs('day'),
            'this_week' => PIT_Database::get_total_costs('week'),
            'this_month' => PIT_Database::get_total_costs('month'),
            'this_year' => PIT_Database::get_total_costs('year'),
            'all_time' => PIT_Database::get_total_costs('all'),
        ];

        return rest_ensure_response($stats);
    }

    /**
     * Get settings
     */
    public static function get_settings($request) {
        $settings = PIT_Settings::get_all();

        return rest_ensure_response($settings);
    }

    /**
     * Save settings
     */
    public static function save_settings($request) {
        $params = $request->get_json_params();

        foreach ($params as $key => $value) {
            PIT_Settings::set($key, $value);
        }

        return rest_ensure_response(['success' => true]);
    }

    // ========== PODCAST INTELLIGENCE CALLBACKS ==========

    /**
     * Get contacts list
     */
    public static function get_contacts($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_podcast_contacts';

        $params = $request->get_query_params();
        $search = $params['search'] ?? '';

        $sql = "SELECT * FROM $table";

        if ($search) {
            $sql .= $wpdb->prepare(" WHERE full_name LIKE %s OR email LIKE %s",
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%'
            );
        }

        $sql .= " ORDER BY created_at DESC LIMIT 100";

        $contacts = $wpdb->get_results($sql);

        return rest_ensure_response($contacts);
    }

    /**
     * Create contact
     */
    public static function create_contact($request) {
        $params = $request->get_json_params();

        $manager = PIT_Podcast_Intelligence_Manager::get_instance();
        $contact_id = $manager->create_or_find_contact($params);

        if (!$contact_id) {
            return new WP_Error('create_failed', 'Failed to create contact', ['status' => 500]);
        }

        $contact = PIT_Database::get_contact($contact_id);

        return rest_ensure_response($contact);
    }

    /**
     * Get single contact
     */
    public static function get_contact($request) {
        $contact_id = (int) $request['id'];

        $contact = PIT_Database::get_contact($contact_id);

        if (!$contact) {
            return new WP_Error('not_found', 'Contact not found', ['status' => 404]);
        }

        return rest_ensure_response($contact);
    }

    /**
     * Update contact
     */
    public static function update_contact($request) {
        $contact_id = (int) $request['id'];
        $params = $request->get_json_params();

        $params['id'] = $contact_id;
        $result = PIT_Database::upsert_contact($params);

        if (!$result) {
            return new WP_Error('update_failed', 'Failed to update contact', ['status' => 500]);
        }

        $contact = PIT_Database::get_contact($contact_id);

        return rest_ensure_response($contact);
    }

    /**
     * Delete contact
     */
    public static function delete_contact($request) {
        global $wpdb;
        $contact_id = (int) $request['id'];

        $table = $wpdb->prefix . 'guestify_podcast_contacts';
        $result = $wpdb->delete($table, ['id' => $contact_id], ['%d']);

        if ($result === false) {
            return new WP_Error('delete_failed', 'Failed to delete contact', ['status' => 500]);
        }

        return rest_ensure_response(['success' => true]);
    }

    /**
     * Get contacts for a podcast
     */
    public static function get_podcast_contacts($request) {
        $podcast_id = (int) $request['id'];

        $contacts = PIT_Database::get_podcast_contacts($podcast_id);

        return rest_ensure_response($contacts);
    }

    /**
     * Link podcast to contact
     */
    public static function link_podcast_contact($request) {
        $podcast_id = (int) $request['id'];
        $params = $request->get_json_params();

        $contact_id = $params['contact_id'] ?? 0;
        $role = $params['role'] ?? 'host';
        $is_primary = $params['is_primary'] ?? false;

        if (!$contact_id) {
            return new WP_Error('missing_contact', 'Contact ID is required', ['status' => 400]);
        }

        $manager = PIT_Podcast_Intelligence_Manager::get_instance();
        $result = $manager->link_podcast_contact($podcast_id, $contact_id, $role, $is_primary);

        if (!$result) {
            return new WP_Error('link_failed', 'Failed to link podcast and contact', ['status' => 500]);
        }

        return rest_ensure_response(['success' => true]);
    }

    /**
     * Get podcast data for entry
     */
    public static function get_entry_podcast_data($request) {
        $entry_id = (int) $request['id'];

        $manager = PIT_Podcast_Intelligence_Manager::get_instance();
        $data = $manager->get_entry_podcast_data($entry_id);

        if (!$data) {
            return new WP_Error('not_found', 'No podcast data found for this entry', ['status' => 404]);
        }

        return rest_ensure_response($data);
    }

    /**
     * Get contact email for entry
     */
    public static function get_entry_contact_email($request) {
        $entry_id = (int) $request['id'];

        $integration = PIT_Email_Integration::get_instance();
        $contact = $integration->get_contact_from_all_sources($entry_id);

        return rest_ensure_response($contact);
    }

    /**
     * Get intelligence podcasts
     */
    public static function get_intelligence_podcasts($request) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_podcasts';

        $params = $request->get_query_params();
        $search = $params['search'] ?? '';
        $per_page = $params['per_page'] ?? 20;
        $page = $params['page'] ?? 1;

        $offset = ($page - 1) * $per_page;

        $sql = "SELECT * FROM $table";

        if ($search) {
            $sql .= $wpdb->prepare(" WHERE title LIKE %s OR description LIKE %s",
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%'
            );
        }

        $sql .= " ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $sql = $wpdb->prepare($sql, $per_page, $offset);

        $podcasts = $wpdb->get_results($sql);

        // Enrich with contacts and social accounts
        foreach ($podcasts as &$podcast) {
            $podcast->contacts = PIT_Database::get_podcast_contacts($podcast->id);
            $podcast->social_accounts = PIT_Database::get_podcast_social_accounts($podcast->id);
        }

        return rest_ensure_response($podcasts);
    }

    /**
     * Create intelligence podcast
     */
    public static function create_intelligence_podcast($request) {
        $params = $request->get_json_params();

        $manager = PIT_Podcast_Intelligence_Manager::get_instance();
        $podcast_id = $manager->create_or_find_podcast($params);

        if (!$podcast_id) {
            return new WP_Error('create_failed', 'Failed to create podcast', ['status' => 500]);
        }

        $podcast = PIT_Database::get_guestify_podcast($podcast_id);

        return rest_ensure_response($podcast);
    }

    /**
     * Get intelligence podcast
     */
    public static function get_intelligence_podcast($request) {
        $podcast_id = (int) $request['id'];

        $podcast = PIT_Database::get_guestify_podcast($podcast_id);

        if (!$podcast) {
            return new WP_Error('not_found', 'Podcast not found', ['status' => 404]);
        }

        // Enrich with contacts and social accounts
        $podcast->contacts = PIT_Database::get_podcast_contacts($podcast_id);
        $podcast->social_accounts = PIT_Database::get_podcast_social_accounts($podcast_id);

        return rest_ensure_response($podcast);
    }

    // ========== GUEST INTELLIGENCE CALLBACKS (Phase 1) ==========

    /**
     * List guests with filtering
     */
    public static function list_guests($request) {
        $params = $request->get_query_params();

        $args = [
            'page' => $params['page'] ?? 1,
            'per_page' => $params['per_page'] ?? 20,
            'search' => $params['search'] ?? '',
            'company' => $params['company'] ?? '',
            'industry' => $params['industry'] ?? '',
            'verified_only' => !empty($params['verified_only']),
            'enriched_only' => !empty($params['enriched_only']),
            'orderby' => $params['orderby'] ?? 'created_at',
            'order' => $params['order'] ?? 'DESC',
        ];

        $result = PIT_Database::list_guests($args);

        // Enrich guests with appearances and topics
        foreach ($result['guests'] as &$guest) {
            $guest->appearances_count = count(PIT_Database::get_guest_appearances($guest->id));
            $guest->topics = PIT_Database::get_guest_topics($guest->id);
        }

        return rest_ensure_response($result);
    }

    /**
     * Create guest
     */
    public static function create_guest($request) {
        $params = $request->get_json_params();

        // Required fields
        if (empty($params['full_name'])) {
            return new WP_Error('missing_name', 'Full name is required', ['status' => 400]);
        }

        // Parse name if first/last not provided
        if (empty($params['first_name']) && empty($params['last_name'])) {
            $name_parts = explode(' ', $params['full_name'], 2);
            $params['first_name'] = $name_parts[0];
            $params['last_name'] = $name_parts[1] ?? '';
        }

        $params['source'] = $params['source'] ?? 'manual';

        $guest_id = PIT_Database::upsert_guest($params);

        if (!$guest_id) {
            return new WP_Error('create_failed', 'Failed to create guest', ['status' => 500]);
        }

        $guest = PIT_Database::get_guest($guest_id);

        return rest_ensure_response($guest);
    }

    /**
     * Get single guest
     */
    public static function get_guest($request) {
        $guest_id = (int) $request['id'];

        $guest = PIT_Database::get_guest($guest_id);

        if (!$guest) {
            return new WP_Error('not_found', 'Guest not found', ['status' => 404]);
        }

        // Enrich with appearances, topics, network
        $guest->appearances = PIT_Database::get_guest_appearances($guest_id);
        $guest->topics = PIT_Database::get_guest_topics($guest_id);
        $guest->network = PIT_Database::get_guest_network($guest_id, 1); // 1st degree only for profile

        return rest_ensure_response($guest);
    }

    /**
     * Update guest
     */
    public static function update_guest($request) {
        $guest_id = (int) $request['id'];
        $params = $request->get_json_params();

        $result = PIT_Database::update_guest($guest_id, $params);

        if ($result === false) {
            return new WP_Error('update_failed', 'Failed to update guest', ['status' => 500]);
        }

        $guest = PIT_Database::get_guest($guest_id);

        return rest_ensure_response($guest);
    }

    /**
     * Delete guest
     */
    public static function delete_guest($request) {
        $guest_id = (int) $request['id'];

        $result = PIT_Database::delete_guest($guest_id);

        if (!$result) {
            return new WP_Error('delete_failed', 'Failed to delete guest', ['status' => 500]);
        }

        return rest_ensure_response(['success' => true]);
    }

    /**
     * Get guest appearances
     */
    public static function get_guest_appearances($request) {
        $guest_id = (int) $request['id'];

        $appearances = PIT_Database::get_guest_appearances($guest_id);

        return rest_ensure_response($appearances);
    }

    /**
     * Add guest appearance
     */
    public static function add_guest_appearance($request) {
        $guest_id = (int) $request['id'];
        $params = $request->get_json_params();

        if (empty($params['podcast_id'])) {
            return new WP_Error('missing_podcast', 'Podcast ID is required', ['status' => 400]);
        }

        $params['guest_id'] = $guest_id;
        $params['extraction_method'] = $params['extraction_method'] ?? 'manual';

        $appearance_id = PIT_Database::create_appearance($params);

        if (!$appearance_id) {
            return new WP_Error('create_failed', 'Failed to create appearance', ['status' => 500]);
        }

        $appearance = PIT_Database::get_appearance($appearance_id);

        return rest_ensure_response($appearance);
    }

    /**
     * Update appearance
     */
    public static function update_appearance($request) {
        $appearance_id = (int) $request['id'];
        $params = $request->get_json_params();

        $result = PIT_Database::update_appearance($appearance_id, $params);

        if ($result === false) {
            return new WP_Error('update_failed', 'Failed to update appearance', ['status' => 500]);
        }

        $appearance = PIT_Database::get_appearance($appearance_id);

        return rest_ensure_response($appearance);
    }

    /**
     * Delete appearance
     */
    public static function delete_appearance($request) {
        $appearance_id = (int) $request['id'];

        $result = PIT_Database::delete_appearance($appearance_id);

        if (!$result) {
            return new WP_Error('delete_failed', 'Failed to delete appearance', ['status' => 500]);
        }

        return rest_ensure_response(['success' => true]);
    }

    /**
     * Get podcast guests
     */
    public static function get_podcast_guests($request) {
        $podcast_id = (int) $request['id'];

        $appearances = PIT_Database::get_podcast_guest_appearances($podcast_id);

        return rest_ensure_response($appearances);
    }

    /**
     * Add guest to podcast (with appearance)
     */
    public static function add_podcast_guest($request) {
        $podcast_id = (int) $request['id'];
        $params = $request->get_json_params();

        // Create or get guest
        if (!empty($params['guest_id'])) {
            $guest_id = (int) $params['guest_id'];
        } else {
            // Create new guest
            if (empty($params['full_name'])) {
                return new WP_Error('missing_name', 'Guest name is required', ['status' => 400]);
            }

            $guest_data = [
                'full_name' => $params['full_name'],
                'first_name' => $params['first_name'] ?? '',
                'last_name' => $params['last_name'] ?? '',
                'current_company' => $params['current_company'] ?? '',
                'current_role' => $params['current_role'] ?? '',
                'linkedin_url' => $params['linkedin_url'] ?? '',
                'email' => $params['email'] ?? '',
                'source' => 'manual',
                'source_podcast_id' => $podcast_id,
            ];

            $guest_id = PIT_Database::upsert_guest($guest_data);
        }

        if (!$guest_id) {
            return new WP_Error('guest_failed', 'Failed to create/find guest', ['status' => 500]);
        }

        // Create appearance
        $appearance_data = [
            'guest_id' => $guest_id,
            'podcast_id' => $podcast_id,
            'episode_number' => $params['episode_number'] ?? null,
            'episode_title' => $params['episode_title'] ?? '',
            'episode_date' => $params['episode_date'] ?? null,
            'episode_url' => $params['episode_url'] ?? '',
            'topics_discussed' => json_encode($params['topics_discussed'] ?? []),
            'extraction_method' => 'manual',
        ];

        $appearance_id = PIT_Database::create_appearance($appearance_data);

        $guest = PIT_Database::get_guest($guest_id);
        $guest->appearance = PIT_Database::get_appearance($appearance_id);

        return rest_ensure_response($guest);
    }

    /**
     * List topics
     */
    public static function list_topics($request) {
        $params = $request->get_query_params();

        $args = [
            'category' => $params['category'] ?? '',
            'orderby' => $params['orderby'] ?? 'usage_count',
            'order' => $params['order'] ?? 'DESC',
        ];

        $topics = PIT_Database::list_topics($args);

        return rest_ensure_response($topics);
    }

    /**
     * Create topic
     */
    public static function create_topic($request) {
        $params = $request->get_json_params();

        if (empty($params['name'])) {
            return new WP_Error('missing_name', 'Topic name is required', ['status' => 400]);
        }

        $topic_id = PIT_Database::get_or_create_topic(
            $params['name'],
            $params['category'] ?? null
        );

        $topic = PIT_Database::get_topic($topic_id);

        return rest_ensure_response($topic);
    }

    /**
     * Get guest topics
     */
    public static function get_guest_topics($request) {
        $guest_id = (int) $request['id'];

        $topics = PIT_Database::get_guest_topics($guest_id);

        return rest_ensure_response($topics);
    }

    /**
     * Assign topic to guest
     */
    public static function assign_guest_topic($request) {
        $guest_id = (int) $request['id'];
        $params = $request->get_json_params();

        // Get or create topic
        if (!empty($params['topic_id'])) {
            $topic_id = (int) $params['topic_id'];
        } else if (!empty($params['topic_name'])) {
            $topic_id = PIT_Database::get_or_create_topic(
                $params['topic_name'],
                $params['category'] ?? null
            );
        } else {
            return new WP_Error('missing_topic', 'Topic ID or name is required', ['status' => 400]);
        }

        $result = PIT_Database::assign_topic_to_guest(
            $guest_id,
            $topic_id,
            $params['confidence'] ?? 100,
            $params['source'] ?? 'manual'
        );

        return rest_ensure_response(['success' => true, 'id' => $result]);
    }

    /**
     * Get content analysis
     */
    public static function get_content_analysis($request) {
        $podcast_id = (int) $request['id'];

        $analysis = PIT_Database::get_content_analysis($podcast_id);

        if ($analysis) {
            // Decode JSON fields
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

        $result = PIT_Database::upsert_content_analysis($podcast_id, $params);

        if (!$result) {
            return new WP_Error('save_failed', 'Failed to save content analysis', ['status' => 500]);
        }

        return rest_ensure_response(['success' => true, 'id' => $result]);
    }

    /**
     * Get guest network
     */
    public static function get_guest_network($request) {
        $guest_id = (int) $request['id'];
        $params = $request->get_query_params();

        $max_degree = min((int) ($params['max_degree'] ?? 2), 2); // Max 2nd degree

        $network = PIT_Database::get_guest_network($guest_id, $max_degree);

        return rest_ensure_response($network);
    }

    /**
     * Verify guest
     */
    public static function verify_guest($request) {
        $guest_id = (int) $request['id'];
        $params = $request->get_json_params();

        $status = $params['status'] ?? 'correct';
        $notes = $params['notes'] ?? '';
        $is_host = !empty($params['is_host']);

        $update_data = [
            'manually_verified' => ($status === 'correct') ? 1 : 0,
            'verified_by_user_id' => get_current_user_id(),
            'verified_at' => current_time('mysql'),
            'verification_notes' => $notes,
        ];

        // If marked as host, update all appearances
        if ($is_host) {
            global $wpdb;
            $table = $wpdb->prefix . 'guestify_guest_appearances';
            $wpdb->update($table, ['is_host' => 1], ['guest_id' => $guest_id]);
        }

        $result = PIT_Database::update_guest($guest_id, $update_data);

        if ($result === false) {
            return new WP_Error('verify_failed', 'Failed to verify guest', ['status' => 500]);
        }

        return rest_ensure_response(['success' => true]);
    }

    /**
     * Add social link to intelligence podcast
     */
    public static function add_intelligence_social_link($request) {
        $podcast_id = (int) $request['id'];
        $params = $request->get_json_params();

        if (empty($params['platform']) || empty($params['profile_url'])) {
            return new WP_Error('missing_params', 'Platform and profile URL are required', ['status' => 400]);
        }

        $data = [
            'podcast_id' => $podcast_id,
            'platform' => $params['platform'],
            'profile_url' => $params['profile_url'],
            'username' => $params['username'] ?? '',
            'display_name' => $params['display_name'] ?? '',
            'discovery_method' => 'manual',
        ];

        $result = PIT_Database::upsert_social_account($data);

        if (!$result) {
            return new WP_Error('create_failed', 'Failed to add social link', ['status' => 500]);
        }

        return rest_ensure_response(['success' => true, 'id' => $result]);
    }

    /**
     * Add manual metrics to social link
     */
    public static function add_manual_metrics($request) {
        $social_link_id = (int) $request['id'];
        $params = $request->get_json_params();

        global $wpdb;
        $table = $wpdb->prefix . 'guestify_podcast_metrics';

        $data = [
            'social_link_id' => $social_link_id,
            'followers_count' => $params['followers_count'] ?? null,
            'following_count' => $params['following_count'] ?? null,
            'posts_count' => $params['posts_count'] ?? null,
            'engagement_rate' => $params['engagement_rate'] ?? null,
            'avg_likes' => $params['avg_likes'] ?? null,
            'avg_comments' => $params['avg_comments'] ?? null,
            'subscribers_count' => $params['subscribers_count'] ?? null,
            'total_views' => $params['total_views'] ?? null,
            'video_count' => $params['video_count'] ?? null,
            'fetched_at' => $params['fetched_at'] ?? current_time('mysql'),
            'fetch_method' => 'manual',
            'data_quality_score' => $params['data_quality_score'] ?? 90,
        ];

        $wpdb->insert($table, $data);
        $metrics_id = $wpdb->insert_id;

        if (!$metrics_id) {
            return new WP_Error('create_failed', 'Failed to add metrics', ['status' => 500]);
        }

        // Update social account with latest counts
        $social_table = $wpdb->prefix . 'guestify_podcast_social_accounts';
        $wpdb->update($social_table, [
            'followers_count' => $params['followers_count'] ?? null,
            'engagement_rate' => $params['engagement_rate'] ?? null,
            'metrics_enriched' => 1,
            'enriched_at' => current_time('mysql'),
        ], ['id' => $social_link_id]);

        return rest_ensure_response(['success' => true, 'id' => $metrics_id]);
    }

    /**
     * Get metrics history for social link
     */
    public static function get_metrics_history($request) {
        $social_link_id = (int) $request['id'];

        global $wpdb;
        $table = $wpdb->prefix . 'guestify_podcast_metrics';

        $metrics = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE social_link_id = %d ORDER BY fetched_at DESC LIMIT 30",
            $social_link_id
        ));

        return rest_ensure_response($metrics);
    }
}
