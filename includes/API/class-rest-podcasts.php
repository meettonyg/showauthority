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

        // Enrich with social links and metrics
        foreach ($result['podcasts'] as &$podcast) {
            $podcast->social_links = PIT_Social_Link_Repository::get_for_podcast($podcast->id);
            $podcast->metrics = PIT_Metrics_Repository::get_latest_for_podcast($podcast->id);
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
        $result = PIT_Podcast_Discovery::discover($rss_url);

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

        $job_id = PIT_Job_Service::queue_tracking_job($podcast_id, $platforms);

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

        $job_id = PIT_Job_Service::queue_refresh_job($podcast_id, $platforms);

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
}
