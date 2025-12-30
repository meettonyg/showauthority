<?php
/**
 * REST API: Global Tasks
 *
 * Provides endpoints for viewing tasks across all appearances.
 * Follows the Calendar Events pattern for global data access.
 *
 * @package Podcast_Influence_Tracker
 * @since 3.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_REST_Global_Tasks {

    /**
     * Namespace for REST routes
     */
    const NAMESPACE = 'guestify/v1';

    /**
     * Base route
     */
    const BASE = 'tasks';

    /**
     * Register REST routes
     */
    public static function register_routes() {
        // GET /tasks - List all tasks for current user
        register_rest_route(self::NAMESPACE, '/' . self::BASE, [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [__CLASS__, 'get_tasks'],
                'permission_callback' => [__CLASS__, 'check_permission'],
                'args'                => self::get_collection_params(),
            ],
        ]);

        // GET /tasks/stats - Get task statistics
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/stats', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'get_stats'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // GET /tasks/types - Get task types
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/types', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'get_task_types'],
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
            'status' => [
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => function($value) {
                    return in_array($value, ['pending', 'in_progress', 'completed', 'cancelled', '']);
                },
            ],
            'priority' => [
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => function($value) {
                    return in_array($value, ['low', 'medium', 'high', 'urgent', '']);
                },
            ],
            'is_overdue' => [
                'sanitize_callback' => function($value) {
                    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
                },
            ],
            'due_from' => [
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'due_to' => [
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
     * GET /tasks - List all tasks for current user
     */
    public static function get_tasks($request) {
        global $wpdb;

        $tasks_table = $wpdb->prefix . 'pit_appearance_tasks';
        $appearances_table = $wpdb->prefix . 'pit_guest_appearances';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';
        $user_id = get_current_user_id();

        // Build query with joins to get appearance and podcast context
        $select = "SELECT t.*,
                   a.podcast_id, a.status as appearance_status, a.episode_title,
                   p.title as podcast_name, p.artwork_url as podcast_artwork";

        $from = " FROM {$tasks_table} t
                  INNER JOIN {$appearances_table} a ON t.appearance_id = a.id
                  LEFT JOIN {$podcasts_table} p ON a.podcast_id = p.id";

        $where = ['t.user_id = %d'];
        $params = [$user_id];

        // Filter by appearance
        if ($appearance_id = $request->get_param('appearance_id')) {
            $where[] = 't.appearance_id = %d';
            $params[] = $appearance_id;
        }

        // Filter by podcast
        if ($podcast_id = $request->get_param('podcast_id')) {
            $where[] = 'a.podcast_id = %d';
            $params[] = $podcast_id;
        }

        // Filter by status
        if ($status = $request->get_param('status')) {
            $where[] = 't.status = %s';
            $params[] = $status;
        }

        // Filter by priority
        if ($priority = $request->get_param('priority')) {
            $where[] = 't.priority = %s';
            $params[] = $priority;
        }

        // Filter by overdue
        if ($request->get_param('is_overdue')) {
            $where[] = 't.due_date IS NOT NULL AND t.due_date < CURDATE() AND t.is_done = 0';
        }

        // Filter by due date range
        if ($due_from = $request->get_param('due_from')) {
            $where[] = 't.due_date >= %s';
            $params[] = $due_from;
        }

        if ($due_to = $request->get_param('due_to')) {
            $where[] = 't.due_date <= %s';
            $params[] = $due_to;
        }

        // Search in title and description
        if ($search = $request->get_param('search')) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(t.title LIKE %s OR t.description LIKE %s OR p.title LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_clause = implode(' AND ', $where);

        // Ordering
        $orderby = $request->get_param('orderby');
        $order = $request->get_param('order');
        $allowed_orderby = ['created_at', 'due_date', 'priority', 'status', 'title'];
        if (!in_array($orderby, $allowed_orderby)) {
            $orderby = 'created_at';
        }

        // Special handling for priority ordering
        if ($orderby === 'priority') {
            $order_sql = "FIELD(t.priority, 'urgent', 'high', 'medium', 'low')";
            if ($order === 'DESC') {
                $order_sql = "FIELD(t.priority, 'low', 'medium', 'high', 'urgent')";
            }
        } else {
            $order_sql = "t.{$orderby} {$order}";
        }

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

        // Get tasks
        $sql = $wpdb->prepare(
            "{$select} {$from} WHERE {$where_clause} ORDER BY {$order_sql} LIMIT %d OFFSET %d",
            array_merge($params, [$per_page, $offset])
        );
        $tasks = $wpdb->get_results($sql, ARRAY_A);

        // Format tasks
        $tasks = array_map([__CLASS__, 'format_task'], $tasks);

        return rest_ensure_response([
            'success' => true,
            'data'    => $tasks,
            'meta'    => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $per_page,
                'total_pages' => ceil($total / $per_page),
            ],
        ]);
    }

    /**
     * GET /tasks/stats - Get task statistics
     */
    public static function get_stats($request) {
        global $wpdb;

        $tasks_table = $wpdb->prefix . 'pit_appearance_tasks';
        $user_id = get_current_user_id();

        // Get counts by status
        $status_counts = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) as count
             FROM {$tasks_table}
             WHERE user_id = %d
             GROUP BY status",
            $user_id
        ), ARRAY_A);

        // Get counts by priority
        $priority_counts = $wpdb->get_results($wpdb->prepare(
            "SELECT priority, COUNT(*) as count
             FROM {$tasks_table}
             WHERE user_id = %d AND status != 'completed' AND status != 'cancelled'
             GROUP BY priority",
            $user_id
        ), ARRAY_A);

        // Get overdue count
        $overdue_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$tasks_table}
             WHERE user_id = %d
             AND due_date IS NOT NULL
             AND due_date < CURDATE()
             AND is_done = 0",
            $user_id
        ));

        // Get due today count
        $due_today_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$tasks_table}
             WHERE user_id = %d
             AND due_date = CURDATE()
             AND is_done = 0",
            $user_id
        ));

        // Get due this week count
        $due_week_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$tasks_table}
             WHERE user_id = %d
             AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
             AND is_done = 0",
            $user_id
        ));

        // Format counts into objects
        $by_status = [];
        foreach ($status_counts as $row) {
            $by_status[$row['status']] = (int) $row['count'];
        }

        $by_priority = [];
        foreach ($priority_counts as $row) {
            $by_priority[$row['priority']] = (int) $row['count'];
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => [
                'by_status'   => $by_status,
                'by_priority' => $by_priority,
                'overdue'     => $overdue_count,
                'due_today'   => $due_today_count,
                'due_week'    => $due_week_count,
                'total'       => array_sum(array_column($status_counts, 'count')),
            ],
        ]);
    }

    /**
     * GET /tasks/types - Get available task types
     */
    public static function get_task_types($request) {
        return rest_ensure_response([
            'success' => true,
            'data'    => [
                'statuses' => [
                    'pending'     => 'Pending',
                    'in_progress' => 'In Progress',
                    'completed'   => 'Completed',
                    'cancelled'   => 'Cancelled',
                ],
                'priorities' => [
                    'urgent' => 'Urgent',
                    'high'   => 'High',
                    'medium' => 'Medium',
                    'low'    => 'Low',
                ],
                'task_types' => [
                    'todo'      => 'To Do',
                    'follow_up' => 'Follow Up',
                    'prep'      => 'Preparation',
                    'outreach'  => 'Outreach',
                    'review'    => 'Review',
                ],
            ],
        ]);
    }

    /**
     * Format task for API response
     */
    private static function format_task($task) {
        if (!$task) {
            return null;
        }

        // Calculate is_overdue
        $is_overdue = false;
        if (!empty($task['due_date']) && !$task['is_done']) {
            $is_overdue = strtotime($task['due_date']) < strtotime('today');
        }

        return [
            'id'                => (int) $task['id'],
            'appearance_id'     => (int) $task['appearance_id'],
            'user_id'           => (int) $task['user_id'],
            'title'             => $task['title'],
            'description'       => $task['description'],
            'task_type'         => $task['task_type'],
            'status'            => $task['status'],
            'priority'          => $task['priority'],
            'is_done'           => (bool) $task['is_done'],
            'due_date'          => $task['due_date'],
            'reminder_date'     => $task['reminder_date'],
            'completed_at'      => $task['completed_at'],
            'is_overdue'        => $is_overdue,
            'created_at'        => $task['created_at'],
            'updated_at'        => $task['updated_at'],
            // Appearance context
            'podcast_id'        => isset($task['podcast_id']) ? (int) $task['podcast_id'] : null,
            'podcast_name'      => $task['podcast_name'] ?? null,
            'podcast_artwork'   => $task['podcast_artwork'] ?? null,
            'episode_title'     => $task['episode_title'] ?? null,
            'appearance_status' => $task['appearance_status'] ?? null,
        ];
    }
}

// Register routes on REST API init
add_action('rest_api_init', ['PIT_REST_Global_Tasks', 'register_routes']);
