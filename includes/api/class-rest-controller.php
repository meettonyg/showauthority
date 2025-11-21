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
    }

    /**
     * Check permission
     */
    public static function check_permission() {
        return current_user_can('manage_options');
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
        $profile_url = $params['profile_url'] ?? '';

        if (empty($platform) || empty($profile_url)) {
            return new WP_Error('missing_params', 'Platform and profile URL are required', ['status' => 400]);
        }

        $result = PIT_Discovery_Engine::add_manual_link($podcast_id, $platform, $profile_url);

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
}
