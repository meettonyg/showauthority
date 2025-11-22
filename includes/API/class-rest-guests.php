<?php
/**
 * REST API Guests Controller
 *
 * Handles all guest-related REST endpoints.
 *
 * @package PodcastInfluenceTracker
 * @subpackage API
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_REST_Guests extends PIT_REST_Base {

    /**
     * Register routes
     */
    public static function register_routes() {
        // List guests
        register_rest_route(self::NAMESPACE, '/guests', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_guests'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        // Create guest
        register_rest_route(self::NAMESPACE, '/guests', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_guest'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        // Get single guest
        register_rest_route(self::NAMESPACE, '/guests/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_guest'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        // Update guest
        register_rest_route(self::NAMESPACE, '/guests/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [__CLASS__, 'update_guest'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        // Delete guest
        register_rest_route(self::NAMESPACE, '/guests/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [__CLASS__, 'delete_guest'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // Get guest appearances
        register_rest_route(self::NAMESPACE, '/guests/(?P<id>\d+)/appearances', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_appearances'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        // Add guest appearance
        register_rest_route(self::NAMESPACE, '/guests/(?P<id>\d+)/appearances', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'add_appearance'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        // Get guest topics
        register_rest_route(self::NAMESPACE, '/guests/(?P<id>\d+)/topics', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_topics'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        // Assign topic to guest
        register_rest_route(self::NAMESPACE, '/guests/(?P<id>\d+)/topics', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'assign_topic'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        // Get guest network
        register_rest_route(self::NAMESPACE, '/guests/(?P<id>\d+)/network', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_network'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        // Verify guest
        register_rest_route(self::NAMESPACE, '/guests/(?P<id>\d+)/verify', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'verify_guest'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        // Get duplicates
        register_rest_route(self::NAMESPACE, '/guests/duplicates', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_duplicates'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // Merge guests
        register_rest_route(self::NAMESPACE, '/guests/merge', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'merge_guests'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // Topics list
        register_rest_route(self::NAMESPACE, '/topics', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_topics'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        // Create topic
        register_rest_route(self::NAMESPACE, '/topics', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_topic'],
            'permission_callback' => [__CLASS__, 'check_logged_in'],
        ]);

        // Appearances
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
    }

    /**
     * List guests
     */
    public static function list_guests($request) {
        $args = array_merge(
            self::get_pagination_params($request),
            [
                'search' => self::get_search_param($request),
                'company' => $request->get_param('company') ?? '',
                'industry' => $request->get_param('industry') ?? '',
                'verified_only' => !empty($request->get_param('verified_only')),
                'enriched_only' => !empty($request->get_param('enriched_only')),
                'orderby' => $request->get_param('orderby') ?? 'created_at',
                'order' => $request->get_param('order') ?? 'DESC',
            ]
        );

        $result = PIT_Guest_Repository::list($args);

        // Enrich with appearances count and topics
        foreach ($result['guests'] as &$guest) {
            $guest->appearances_count = count(PIT_Appearance_Repository::get_for_guest($guest->id));
            $guest->topics = PIT_Topic_Repository::get_for_guest($guest->id);
        }

        return rest_ensure_response($result);
    }

    /**
     * Create guest
     */
    public static function create_guest($request) {
        $params = $request->get_json_params();

        if (empty($params['full_name'])) {
            return self::error('missing_name', 'Full name is required', 400);
        }

        // Parse name if first/last not provided
        if (empty($params['first_name']) && empty($params['last_name'])) {
            $name_parts = explode(' ', $params['full_name'], 2);
            $params['first_name'] = $name_parts[0];
            $params['last_name'] = $name_parts[1] ?? '';
        }

        $params['source'] = $params['source'] ?? 'manual';

        $guest_id = PIT_Guest_Repository::upsert($params);

        if (!$guest_id) {
            return self::error('create_failed', 'Failed to create guest', 500);
        }

        return rest_ensure_response(PIT_Guest_Repository::get($guest_id));
    }

    /**
     * Get single guest
     */
    public static function get_guest($request) {
        $guest_id = (int) $request['id'];

        $guest = PIT_Guest_Repository::get($guest_id);

        if (!$guest) {
            return self::error('not_found', 'Guest not found', 404);
        }

        // Enrich
        $guest->appearances = PIT_Appearance_Repository::get_for_guest($guest_id);
        $guest->topics = PIT_Topic_Repository::get_for_guest($guest_id);
        $guest->network = PIT_Network_Repository::get_connections($guest_id, 1);

        return rest_ensure_response($guest);
    }

    /**
     * Update guest
     */
    public static function update_guest($request) {
        $guest_id = (int) $request['id'];
        $params = $request->get_json_params();

        $result = PIT_Guest_Repository::update($guest_id, $params);

        if ($result === false) {
            return self::error('update_failed', 'Failed to update guest', 500);
        }

        return rest_ensure_response(PIT_Guest_Repository::get($guest_id));
    }

    /**
     * Delete guest
     */
    public static function delete_guest($request) {
        $guest_id = (int) $request['id'];

        $result = PIT_Guest_Repository::delete($guest_id);

        if (!$result) {
            return self::error('delete_failed', 'Failed to delete guest', 500);
        }

        return rest_ensure_response(['success' => true]);
    }

    /**
     * Get guest appearances
     */
    public static function get_appearances($request) {
        $guest_id = (int) $request['id'];

        $appearances = PIT_Appearance_Repository::get_for_guest($guest_id);

        return rest_ensure_response($appearances);
    }

    /**
     * Add guest appearance
     */
    public static function add_appearance($request) {
        $guest_id = (int) $request['id'];
        $params = $request->get_json_params();

        if (empty($params['podcast_id'])) {
            return self::error('missing_podcast', 'Podcast ID is required', 400);
        }

        $params['guest_id'] = $guest_id;
        $params['extraction_method'] = $params['extraction_method'] ?? 'manual';

        $appearance_id = PIT_Appearance_Repository::create($params);

        if (!$appearance_id) {
            return self::error('create_failed', 'Failed to create appearance', 500);
        }

        return rest_ensure_response(PIT_Appearance_Repository::get($appearance_id));
    }

    /**
     * Update appearance
     */
    public static function update_appearance($request) {
        $appearance_id = (int) $request['id'];
        $params = $request->get_json_params();

        $result = PIT_Appearance_Repository::update($appearance_id, $params);

        if ($result === false) {
            return self::error('update_failed', 'Failed to update appearance', 500);
        }

        return rest_ensure_response(PIT_Appearance_Repository::get($appearance_id));
    }

    /**
     * Delete appearance
     */
    public static function delete_appearance($request) {
        $appearance_id = (int) $request['id'];

        $result = PIT_Appearance_Repository::delete($appearance_id);

        if (!$result) {
            return self::error('delete_failed', 'Failed to delete appearance', 500);
        }

        return rest_ensure_response(['success' => true]);
    }

    /**
     * Get guest topics
     */
    public static function get_topics($request) {
        $guest_id = (int) $request['id'];

        $topics = PIT_Topic_Repository::get_for_guest($guest_id);

        return rest_ensure_response($topics);
    }

    /**
     * Assign topic to guest
     */
    public static function assign_topic($request) {
        $guest_id = (int) $request['id'];
        $params = $request->get_json_params();

        if (!empty($params['topic_id'])) {
            $topic_id = (int) $params['topic_id'];
        } elseif (!empty($params['topic_name'])) {
            $topic_id = PIT_Topic_Repository::get_or_create(
                $params['topic_name'],
                $params['category'] ?? null
            );
        } else {
            return self::error('missing_topic', 'Topic ID or name is required', 400);
        }

        $result = PIT_Topic_Repository::assign_to_guest(
            $guest_id,
            $topic_id,
            $params['confidence'] ?? 100,
            $params['source'] ?? 'manual'
        );

        return rest_ensure_response(['success' => true, 'id' => $result]);
    }

    /**
     * Get guest network
     */
    public static function get_network($request) {
        $guest_id = (int) $request['id'];
        $max_degree = min((int) ($request->get_param('max_degree') ?? 2), 2);

        $network = PIT_Network_Repository::get_connections($guest_id, $max_degree);

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

        $result = PIT_Guest_Repository::verify($guest_id, $status === 'correct', $notes);

        if ($result === false) {
            return self::error('verify_failed', 'Failed to verify guest', 500);
        }

        return rest_ensure_response(['success' => true]);
    }

    /**
     * Get duplicates
     */
    public static function get_duplicates($request) {
        $duplicates = PIT_Guest_Repository::find_duplicates();

        return rest_ensure_response($duplicates);
    }

    /**
     * Merge guests
     */
    public static function merge_guests($request) {
        $params = $request->get_json_params();
        $source_id = (int) ($params['source_id'] ?? 0);
        $target_id = (int) ($params['target_id'] ?? 0);

        if (!$source_id || !$target_id || $source_id === $target_id) {
            return self::error('invalid_params', 'Invalid source or target ID', 400);
        }

        $result = PIT_Guest_Repository::merge($source_id, $target_id);

        if (!$result) {
            return self::error('merge_failed', 'Failed to merge guests', 500);
        }

        return rest_ensure_response(['success' => true, 'merged_into' => $target_id]);
    }

    /**
     * List topics
     */
    public static function list_topics($request) {
        $args = [
            'category' => $request->get_param('category') ?? '',
            'orderby' => $request->get_param('orderby') ?? 'usage_count',
            'order' => $request->get_param('order') ?? 'DESC',
        ];

        $topics = PIT_Topic_Repository::list($args);

        return rest_ensure_response($topics);
    }

    /**
     * Create topic
     */
    public static function create_topic($request) {
        $params = $request->get_json_params();

        if (empty($params['name'])) {
            return self::error('missing_name', 'Topic name is required', 400);
        }

        $topic_id = PIT_Topic_Repository::get_or_create(
            $params['name'],
            $params['category'] ?? null
        );

        return rest_ensure_response(PIT_Topic_Repository::get($topic_id));
    }
}
