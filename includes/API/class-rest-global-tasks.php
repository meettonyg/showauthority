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
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [__CLASS__, 'create_task'],
                'permission_callback' => [__CLASS__, 'check_permission'],
                'args'                => [
                    'appearance_id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'title' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'description' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'wp_kses_post',
                    ],
                    'task_type' => [
                        'type'              => 'string',
                        'default'           => 'todo',
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => function($value) {
                            return in_array($value, ['todo', 'follow_up', 'prep', 'outreach', 'review']);
                        },
                    ],
                    'priority' => [
                        'type'              => 'string',
                        'default'           => 'medium',
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => function($value) {
                            return in_array($value, ['low', 'medium', 'high', 'urgent']);
                        },
                    ],
                    'due_date' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => function($value) {
                            if (empty($value)) {
                                return true;
                            }
                            $d = DateTime::createFromFormat('Y-m-d', $value);
                            return $d && $d->format('Y-m-d') === $value;
                        },
                    ],
                    'reminder_date' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => function($value) {
                            if (empty($value)) {
                                return true;
                            }
                            $d = DateTime::createFromFormat('Y-m-d', $value);
                            return $d && $d->format('Y-m-d') === $value;
                        },
                    ],
                ],
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

        // GET /tasks/appearances - Get appearances for task creation dropdown
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/appearances', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'get_appearances_for_tasks'],
            'permission_callback' => [__CLASS__, 'check_permission'],
            'args'                => [
                'search' => [
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // GET /tasks/calendar - Get tasks formatted for FullCalendar
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/calendar', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'get_tasks_for_calendar'],
            'permission_callback' => [__CLASS__, 'check_permission'],
            'args'                => [
                'start_date' => [
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'end_date' => [
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'show_completed' => [
                    'type'              => 'boolean',
                    'default'           => false,
                ],
            ],
        ]);

        // POST /tasks/{id}/toggle - Toggle task completion from calendar
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/(?P<task_id>\d+)/toggle', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [__CLASS__, 'toggle_task'],
            'permission_callback' => [__CLASS__, 'check_permission'],
            'args'                => [
                'task_id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
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
        $opportunities_table = $wpdb->prefix . 'pit_opportunities';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';
        $user_id = get_current_user_id();

        // Build query with joins to get appearance and podcast context
        // Support both pit_guest_appearances and pit_opportunities tables
        $select = "SELECT t.*,
                   COALESCE(a.podcast_id, o.podcast_id) as podcast_id,
                   COALESCE(a.status, o.status) as appearance_status,
                   a.episode_title,
                   COALESCE(p1.title, p2.title) as podcast_name,
                   COALESCE(p1.artwork_url, p2.artwork_url) as podcast_artwork";

        $from = " FROM {$tasks_table} t
                  LEFT JOIN {$appearances_table} a ON t.appearance_id = a.id
                  LEFT JOIN {$opportunities_table} o ON t.appearance_id = o.id AND a.id IS NULL
                  LEFT JOIN {$podcasts_table} p1 ON a.podcast_id = p1.id
                  LEFT JOIN {$podcasts_table} p2 ON o.podcast_id = p2.id";

        $where = ['t.user_id = %d'];
        $params = [$user_id];

        // Filter by appearance
        if ($appearance_id = $request->get_param('appearance_id')) {
            $where[] = 't.appearance_id = %d';
            $params[] = $appearance_id;
        }

        // Filter by podcast (check both tables)
        if ($podcast_id = $request->get_param('podcast_id')) {
            $where[] = '(a.podcast_id = %d OR o.podcast_id = %d)';
            $params[] = $podcast_id;
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

        // Search in title and description (check both podcast tables)
        if ($search = $request->get_param('search')) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(t.title LIKE %s OR t.description LIKE %s OR p1.title LIKE %s OR p2.title LIKE %s)';
            $params[] = $like;
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
     * POST /tasks - Create a new task
     */
    public static function create_task($request) {
        global $wpdb;

        $user_id = get_current_user_id();
        $appearance_id = (int) $request->get_param('appearance_id');

        // Verify appearance ownership
        $appearances_table = $wpdb->prefix . 'pit_guest_appearances';
        $opportunities_table = $wpdb->prefix . 'pit_opportunities';

        // Check both tables for the appearance
        $appearance_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$appearances_table} WHERE id = %d AND user_id = %d",
            $appearance_id,
            $user_id
        ));

        // Also check opportunities table
        if (!$appearance_exists) {
            $appearance_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$opportunities_table} WHERE id = %d AND user_id = %d",
                $appearance_id,
                $user_id
            ));
        }

        if (!$appearance_exists && !current_user_can('manage_options')) {
            return new WP_Error('not_found', 'Appearance not found or not owned by user', ['status' => 404]);
        }

        $tasks_table = $wpdb->prefix . 'pit_appearance_tasks';

        $data = [
            'appearance_id' => $appearance_id,
            'user_id'       => $user_id,
            'title'         => $request->get_param('title'),
            'description'   => $request->get_param('description') ?: '',
            'task_type'     => $request->get_param('task_type') ?: 'todo',
            'status'        => 'pending',
            'priority'      => $request->get_param('priority') ?: 'medium',
            'is_done'       => 0,
            'due_date'      => $request->get_param('due_date') ?: null,
            'reminder_date' => $request->get_param('reminder_date') ?: null,
            'created_at'    => current_time('mysql'),
            'updated_at'    => current_time('mysql'),
        ];

        $result = $wpdb->insert($tasks_table, $data);

        if ($result === false) {
            return new WP_Error('insert_failed', 'Failed to create task', ['status' => 500]);
        }

        $task_id = $wpdb->insert_id;

        // Fetch the created task with appearance context
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';

        // Try guest_appearances first
        $task = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*,
                    a.podcast_id, a.status as appearance_status, a.episode_title,
                    p.title as podcast_name, p.artwork_url as podcast_artwork
             FROM {$tasks_table} t
             LEFT JOIN {$appearances_table} a ON t.appearance_id = a.id
             LEFT JOIN {$podcasts_table} p ON a.podcast_id = p.id
             WHERE t.id = %d",
            $task_id
        ), ARRAY_A);

        // If no podcast info, try opportunities table
        if ($task && empty($task['podcast_name'])) {
            $opp_data = $wpdb->get_row($wpdb->prepare(
                "SELECT o.podcast_id, o.status as appearance_status,
                        p.title as podcast_name, p.artwork_url as podcast_artwork
                 FROM {$opportunities_table} o
                 LEFT JOIN {$podcasts_table} p ON o.podcast_id = p.id
                 WHERE o.id = %d",
                $appearance_id
            ), ARRAY_A);

            if ($opp_data) {
                $task = array_merge($task, $opp_data);
            }
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => self::format_task($task),
            'message' => 'Task created successfully',
        ]);
    }

    /**
     * GET /tasks/appearances - Get appearances for task creation dropdown
     * Fetches from both pit_opportunities and pit_guest_appearances tables
     */
    public static function get_appearances_for_tasks($request) {
        global $wpdb;

        $user_id = get_current_user_id();
        $search = $request->get_param('search');

        $opportunities_table = $wpdb->prefix . 'pit_opportunities';
        $appearances_table = $wpdb->prefix . 'pit_guest_appearances';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';

        // Build search condition
        $search_condition = '';
        $search_params = [];
        if ($search) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $search_condition = 'AND p.title LIKE %s';
            $search_params = [$like];
        }

        // Query opportunities table
        $opp_params = array_merge([$user_id], $search_params);
        $opp_sql = $wpdb->prepare(
            "SELECT o.id, o.status, 'opportunity' as source,
                    p.title as podcast_name, p.artwork_url as podcast_artwork,
                    o.updated_at
             FROM {$opportunities_table} o
             LEFT JOIN {$podcasts_table} p ON o.podcast_id = p.id
             WHERE o.user_id = %d
             AND (o.is_archived = 0 OR o.is_archived IS NULL)
             {$search_condition}",
            $opp_params
        );

        // Query guest_appearances table
        $app_params = array_merge([$user_id], $search_params);
        $app_sql = $wpdb->prepare(
            "SELECT a.id, a.status, 'appearance' as source,
                    p.title as podcast_name, p.artwork_url as podcast_artwork,
                    a.updated_at
             FROM {$appearances_table} a
             LEFT JOIN {$podcasts_table} p ON a.podcast_id = p.id
             WHERE a.user_id = %d
             {$search_condition}",
            $app_params
        );

        // Combine with UNION and order by updated_at
        $combined_sql = "({$opp_sql}) UNION ({$app_sql}) ORDER BY updated_at DESC LIMIT 100";
        $appearances = $wpdb->get_results($combined_sql, ARRAY_A);

        $data = array_map(function($row) {
            return [
                'id'              => (int) $row['id'],
                'podcast_name'    => $row['podcast_name'] ?: 'Unknown Podcast',
                'podcast_artwork' => $row['podcast_artwork'] ?: '',
                'status'          => $row['status'] ?: 'potential',
                'source'          => $row['source'],
            ];
        }, $appearances ?: []);

        return rest_ensure_response([
            'success' => true,
            'data'    => $data,
        ]);
    }

    /**
     * GET /tasks/calendar - Get tasks formatted for FullCalendar
     * Returns tasks with due dates as calendar events
     */
    public static function get_tasks_for_calendar($request) {
        global $wpdb;

        $user_id = get_current_user_id();
        $start_date = $request->get_param('start_date');
        $end_date = $request->get_param('end_date');
        $show_completed = $request->get_param('show_completed');

        $tasks_table = $wpdb->prefix . 'pit_appearance_tasks';
        $opportunities_table = $wpdb->prefix . 'pit_opportunities';
        $appearances_table = $wpdb->prefix . 'pit_guest_appearances';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';

        // Build query - only get tasks with due dates
        $where = ['t.user_id = %d', 't.due_date IS NOT NULL'];
        $params = [$user_id];

        // Filter by date range
        if ($start_date) {
            $where[] = 't.due_date >= %s';
            $params[] = $start_date;
        }
        if ($end_date) {
            $where[] = 't.due_date <= %s';
            $params[] = $end_date;
        }

        // Optionally exclude completed tasks
        if (!$show_completed) {
            $where[] = 't.is_done = 0';
        }

        $where_sql = implode(' AND ', $where);

        // Query with joins to both tables
        $sql = $wpdb->prepare(
            "SELECT t.*,
                    COALESCE(a.podcast_id, o.podcast_id) as podcast_id,
                    COALESCE(p1.title, p2.title) as podcast_name,
                    COALESCE(p1.artwork_url, p2.artwork_url) as podcast_artwork
             FROM {$tasks_table} t
             LEFT JOIN {$appearances_table} a ON t.appearance_id = a.id
             LEFT JOIN {$opportunities_table} o ON t.appearance_id = o.id AND a.id IS NULL
             LEFT JOIN {$podcasts_table} p1 ON a.podcast_id = p1.id
             LEFT JOIN {$podcasts_table} p2 ON o.podcast_id = p2.id
             WHERE {$where_sql}
             ORDER BY t.due_date ASC",
            $params
        );

        $tasks = $wpdb->get_results($sql, ARRAY_A);

        // Format for FullCalendar
        $calendar_tasks = array_map(function($task) {
            $is_overdue = !$task['is_done'] && strtotime($task['due_date']) < strtotime('today');

            return [
                'id'              => 'task-' . $task['id'],
                'task_id'         => (int) $task['id'],
                'title'           => $task['title'],
                'start'           => $task['due_date'],
                'allDay'          => true,
                'is_task'         => true,
                'is_done'         => (bool) $task['is_done'],
                'is_overdue'      => $is_overdue,
                'priority'        => $task['priority'],
                'task_type'       => $task['task_type'],
                'appearance_id'   => (int) $task['appearance_id'],
                'podcast_name'    => $task['podcast_name'] ?: '',
                'podcast_artwork' => $task['podcast_artwork'] ?: '',
                'description'     => $task['description'] ?: '',
            ];
        }, $tasks ?: []);

        return rest_ensure_response([
            'success' => true,
            'data'    => $calendar_tasks,
        ]);
    }

    /**
     * POST /tasks/{id}/toggle - Toggle task completion
     */
    public static function toggle_task($request) {
        global $wpdb;

        $task_id = (int) $request->get_param('task_id');
        $user_id = get_current_user_id();

        $tasks_table = $wpdb->prefix . 'pit_appearance_tasks';

        // Get current task
        $task = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tasks_table} WHERE id = %d AND user_id = %d",
            $task_id,
            $user_id
        ));

        if (!$task) {
            return new WP_Error('not_found', 'Task not found', ['status' => 404]);
        }

        // Toggle completion
        $new_done = $task->is_done ? 0 : 1;
        $new_status = $new_done ? 'completed' : 'pending';
        $completed_at = $new_done ? current_time('mysql') : null;

        $wpdb->update($tasks_table, [
            'is_done'      => $new_done,
            'status'       => $new_status,
            'completed_at' => $completed_at,
            'updated_at'   => current_time('mysql'),
        ], ['id' => $task_id]);

        return rest_ensure_response([
            'success'  => true,
            'is_done'  => (bool) $new_done,
            'message'  => $new_done ? 'Task completed' : 'Task reopened',
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
