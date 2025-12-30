<?php
/**
 * REST API: Global Notes
 *
 * Provides endpoints for viewing notes across all appearances.
 * Follows the Calendar Events pattern for global data access.
 *
 * @package Podcast_Influence_Tracker
 * @since 3.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_REST_Global_Notes {

    /**
     * Namespace for REST routes
     */
    const NAMESPACE = 'guestify/v1';

    /**
     * Base route
     */
    const BASE = 'notes';

    /**
     * Register REST routes
     */
    public static function register_routes() {
        // GET /notes - List all notes for current user
        register_rest_route(self::NAMESPACE, '/' . self::BASE, [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [__CLASS__, 'get_notes'],
                'permission_callback' => [__CLASS__, 'check_permission'],
                'args'                => self::get_collection_params(),
            ],
        ]);

        // GET /notes/stats - Get notes statistics
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/stats', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'get_stats'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // GET /notes/types - Get note types
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/types', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'get_note_types'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);
    }

    /**
     * Check if user has permission
     */
    public static function check_permission($request) {
        return is_user_logged_in();
    }

    /**
     * Get collection parameters
     */
    private static function get_collection_params() {
        return [
            'per_page' => [
                'default'           => 50,
                'sanitize_callback' => 'absint',
                'validate_callback' => function($value) {
                    return $value >= 1 && $value <= 100;
                },
            ],
            'page' => [
                'default'           => 1,
                'sanitize_callback' => 'absint',
            ],
            'appearance_id' => [
                'sanitize_callback' => 'absint',
            ],
            'podcast_id' => [
                'sanitize_callback' => 'absint',
            ],
            'note_type' => [
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => function($value) {
                    return in_array($value, ['general', 'contact', 'research', 'meeting', 'follow_up', 'pitch', 'feedback', '']);
                },
            ],
            'is_pinned' => [
                'sanitize_callback' => function($value) {
                    if ($value === '' || $value === null) return null;
                    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
                },
            ],
            'date_from' => [
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'date_to' => [
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'search' => [
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'orderby' => [
                'default'           => 'created_at',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'order' => [
                'default'           => 'DESC',
                'sanitize_callback' => function($value) {
                    return strtoupper($value) === 'ASC' ? 'ASC' : 'DESC';
                },
            ],
        ];
    }

    /**
     * GET /notes - List all notes for current user
     */
    public static function get_notes($request) {
        global $wpdb;

        $notes_table = $wpdb->prefix . 'pit_appearance_notes';
        $appearances_table = $wpdb->prefix . 'pit_guest_appearances';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';
        $user_id = get_current_user_id();

        // Build query with joins to get appearance and podcast context
        $select = "SELECT n.*,
                   a.podcast_id, a.status as appearance_status, a.episode_title,
                   p.title as podcast_name, p.artwork_url as podcast_artwork";

        $from = " FROM {$notes_table} n
                  INNER JOIN {$appearances_table} a ON n.appearance_id = a.id
                  LEFT JOIN {$podcasts_table} p ON a.podcast_id = p.id";

        $where = ['n.user_id = %d'];
        $params = [$user_id];

        // Filter by appearance
        if ($appearance_id = $request->get_param('appearance_id')) {
            $where[] = 'n.appearance_id = %d';
            $params[] = $appearance_id;
        }

        // Filter by podcast
        if ($podcast_id = $request->get_param('podcast_id')) {
            $where[] = 'a.podcast_id = %d';
            $params[] = $podcast_id;
        }

        // Filter by note type
        if ($note_type = $request->get_param('note_type')) {
            $where[] = 'n.note_type = %s';
            $params[] = $note_type;
        }

        // Filter by pinned status
        $is_pinned = $request->get_param('is_pinned');
        if ($is_pinned !== null) {
            $where[] = 'n.is_pinned = %d';
            $params[] = $is_pinned ? 1 : 0;
        }

        // Filter by date range
        if ($date_from = $request->get_param('date_from')) {
            $where[] = 'n.note_date >= %s';
            $params[] = $date_from;
        }

        if ($date_to = $request->get_param('date_to')) {
            $where[] = 'n.note_date <= %s';
            $params[] = $date_to;
        }

        // Search in title and content
        if ($search = $request->get_param('search')) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(n.title LIKE %s OR n.content LIKE %s OR p.title LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_clause = implode(' AND ', $where);

        // Ordering - pinned notes first, then by specified order
        $orderby = $request->get_param('orderby');
        $order = $request->get_param('order');
        $allowed_orderby = ['created_at', 'note_date', 'title', 'note_type', 'updated_at'];
        if (!in_array($orderby, $allowed_orderby)) {
            $orderby = 'created_at';
        }

        $order_sql = "n.is_pinned DESC, n.{$orderby} {$order}";

        // Pagination
        $per_page = $request->get_param('per_page');
        $page = $request->get_param('page');
        $offset = ($page - 1) * $per_page;

        // Get total count
        $count_sql = $wpdb->prepare(
            "SELECT COUNT(*) {$from} WHERE {$where_clause}",
            ...$params
        );
        $total = (int) $wpdb->get_var($count_sql);

        // Get notes
        $sql = $wpdb->prepare(
            "{$select} {$from} WHERE {$where_clause} ORDER BY {$order_sql} LIMIT %d OFFSET %d",
            array_merge($params, [$per_page, $offset])
        );
        $notes = $wpdb->get_results($sql, ARRAY_A);

        // Format notes
        $notes = array_map([__CLASS__, 'format_note'], $notes);

        return rest_ensure_response([
            'success' => true,
            'data'    => $notes,
            'meta'    => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $per_page,
                'total_pages' => ceil($total / $per_page),
            ],
        ]);
    }

    /**
     * GET /notes/stats - Get notes statistics
     */
    public static function get_stats($request) {
        global $wpdb;

        $notes_table = $wpdb->prefix . 'pit_appearance_notes';
        $user_id = get_current_user_id();

        // Get counts by type
        $type_counts = $wpdb->get_results($wpdb->prepare(
            "SELECT note_type, COUNT(*) as count
             FROM {$notes_table}
             WHERE user_id = %d
             GROUP BY note_type",
            $user_id
        ), ARRAY_A);

        // Get pinned count
        $pinned_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$notes_table}
             WHERE user_id = %d AND is_pinned = 1",
            $user_id
        ));

        // Get recent count (last 7 days)
        $recent_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$notes_table}
             WHERE user_id = %d
             AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            $user_id
        ));

        // Format counts into object
        $by_type = [];
        foreach ($type_counts as $row) {
            $by_type[$row['note_type']] = (int) $row['count'];
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => [
                'by_type'  => $by_type,
                'pinned'   => $pinned_count,
                'recent'   => $recent_count,
                'total'    => array_sum(array_column($type_counts, 'count')),
            ],
        ]);
    }

    /**
     * GET /notes/types - Get available note types
     */
    public static function get_note_types($request) {
        return rest_ensure_response([
            'success' => true,
            'data'    => [
                'general'   => ['label' => 'General', 'icon' => 'file-text', 'color' => '#6b7280'],
                'contact'   => ['label' => 'Contact', 'icon' => 'user', 'color' => '#3b82f6'],
                'research'  => ['label' => 'Research', 'icon' => 'search', 'color' => '#8b5cf6'],
                'meeting'   => ['label' => 'Meeting', 'icon' => 'calendar', 'color' => '#10b981'],
                'follow_up' => ['label' => 'Follow Up', 'icon' => 'clock', 'color' => '#f59e0b'],
                'pitch'     => ['label' => 'Pitch', 'icon' => 'send', 'color' => '#ec4899'],
                'feedback'  => ['label' => 'Feedback', 'icon' => 'message-circle', 'color' => '#14b8a6'],
            ],
        ]);
    }

    /**
     * Format note for API response
     */
    private static function format_note($note) {
        if (!$note) {
            return null;
        }

        return [
            'id'                => (int) $note['id'],
            'appearance_id'     => (int) $note['appearance_id'],
            'user_id'           => (int) $note['user_id'],
            'title'             => $note['title'],
            'content'           => $note['content'],
            'content_preview'   => wp_trim_words(wp_strip_all_tags($note['content']), 20, '...'),
            'note_type'         => $note['note_type'],
            'is_pinned'         => (bool) $note['is_pinned'],
            'note_date'         => $note['note_date'],
            'created_at'        => $note['created_at'],
            'updated_at'        => $note['updated_at'],
            'time_ago'          => human_time_diff(strtotime($note['created_at']), current_time('timestamp')) . ' ago',
            // Appearance context
            'podcast_id'        => isset($note['podcast_id']) ? (int) $note['podcast_id'] : null,
            'podcast_name'      => $note['podcast_name'] ?? null,
            'podcast_artwork'   => $note['podcast_artwork'] ?? null,
            'episode_title'     => $note['episode_title'] ?? null,
            'appearance_status' => $note['appearance_status'] ?? null,
        ];
    }
}

// Register routes on REST API init
add_action('rest_api_init', ['PIT_REST_Global_Notes', 'register_routes']);
