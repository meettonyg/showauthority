<?php
/**
 * REST API: Notifications
 *
 * Provides endpoints for user notifications including
 * task reminders, calendar events, and system alerts.
 *
 * @package Podcast_Influence_Tracker
 * @since 3.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_REST_Notifications {

    /**
     * Namespace for REST routes
     */
    const NAMESPACE = 'guestify/v1';

    /**
     * Base route
     */
    const BASE = 'notifications';

    /**
     * Register REST routes
     */
    public static function register_routes() {
        // GET /notifications - List notifications for current user
        register_rest_route(self::NAMESPACE, '/' . self::BASE, [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'get_notifications'],
            'permission_callback' => [__CLASS__, 'check_permission'],
            'args'                => [
                'per_page' => [
                    'default'           => 20,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function($value) {
                        return $value >= 1 && $value <= 100;
                    },
                ],
                'page' => [
                    'default'           => 1,
                    'sanitize_callback' => 'absint',
                ],
                'unread_only' => [
                    'type'    => 'boolean',
                    'default' => false,
                ],
            ],
        ]);

        // GET /notifications/count - Get unread count
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/count', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'get_count'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // POST /notifications/{id}/read - Mark as read
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/(?P<id>\d+)/read', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [__CLASS__, 'mark_read'],
            'permission_callback' => [__CLASS__, 'check_permission'],
            'args'                => [
                'id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // POST /notifications/read-all - Mark all as read
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/read-all', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [__CLASS__, 'mark_all_read'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // POST /notifications/{id}/dismiss - Dismiss notification
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/(?P<id>\d+)/dismiss', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [__CLASS__, 'dismiss'],
            'permission_callback' => [__CLASS__, 'check_permission'],
            'args'                => [
                'id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // GET /notifications/settings - Get notification preferences
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/settings', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'get_settings'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // POST /notifications/settings - Update notification preferences
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/settings', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [__CLASS__, 'update_settings'],
            'permission_callback' => [__CLASS__, 'check_permission'],
            'args'                => [
                'email_enabled' => [
                    'type' => 'boolean',
                ],
                'email_task_reminders' => [
                    'type' => 'boolean',
                ],
                'email_interview_reminders' => [
                    'type' => 'boolean',
                ],
                'email_overdue_tasks' => [
                    'type' => 'boolean',
                ],
                'reminder_hours_before' => [
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
     * GET /notifications - List notifications
     */
    public static function get_notifications($request) {
        global $wpdb;

        $user_id = get_current_user_id();
        $per_page = $request->get_param('per_page');
        $page = $request->get_param('page');
        $unread_only = $request->get_param('unread_only');
        $offset = ($page - 1) * $per_page;

        $table = $wpdb->prefix . 'pit_notifications';

        $where = ['user_id = %d', 'is_dismissed = 0'];
        $params = [$user_id];

        if ($unread_only) {
            $where[] = 'is_read = 0';
        }

        $where_sql = implode(' AND ', $where);

        // Get total count
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}",
            $params
        ));

        // Get notifications
        $params[] = $per_page;
        $params[] = $offset;

        $notifications = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE {$where_sql}
             ORDER BY created_at DESC
             LIMIT %d OFFSET %d",
            $params
        ), ARRAY_A);

        // Format notifications
        $formatted = array_map(function($n) {
            return [
                'id'           => (int) $n['id'],
                'type'         => $n['type'],
                'source'       => $n['source'],
                'title'        => $n['title'],
                'message'      => $n['message'],
                'action_url'   => $n['action_url'],
                'action_label' => $n['action_label'],
                'is_read'      => (bool) $n['is_read'],
                'created_at'   => $n['created_at'],
                'meta'         => $n['meta'] ? json_decode($n['meta'], true) : null,
            ];
        }, $notifications ?: []);

        return rest_ensure_response([
            'success' => true,
            'data'    => $formatted,
            'meta'    => [
                'total'    => (int) $total,
                'page'     => $page,
                'per_page' => $per_page,
                'pages'    => ceil($total / $per_page),
            ],
        ]);
    }

    /**
     * GET /notifications/count - Get unread count
     */
    public static function get_count($request) {
        global $wpdb;

        $user_id = get_current_user_id();
        $table = $wpdb->prefix . 'pit_notifications';

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE user_id = %d AND is_read = 0 AND is_dismissed = 0",
            $user_id
        ));

        return rest_ensure_response([
            'success' => true,
            'count'   => (int) $count,
        ]);
    }

    /**
     * POST /notifications/{id}/read - Mark as read
     */
    public static function mark_read($request) {
        global $wpdb;

        $user_id = get_current_user_id();
        $id = (int) $request->get_param('id');
        $table = $wpdb->prefix . 'pit_notifications';

        $result = $wpdb->update(
            $table,
            [
                'is_read' => 1,
                'read_at' => current_time('mysql'),
            ],
            [
                'id'      => $id,
                'user_id' => $user_id,
            ]
        );

        if ($result === false) {
            return new WP_Error('update_failed', 'Failed to mark notification as read', ['status' => 500]);
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'Notification marked as read',
        ]);
    }

    /**
     * POST /notifications/read-all - Mark all as read
     */
    public static function mark_all_read($request) {
        global $wpdb;

        $user_id = get_current_user_id();
        $table = $wpdb->prefix . 'pit_notifications';

        $wpdb->update(
            $table,
            [
                'is_read' => 1,
                'read_at' => current_time('mysql'),
            ],
            [
                'user_id' => $user_id,
                'is_read' => 0,
            ]
        );

        return rest_ensure_response([
            'success' => true,
            'message' => 'All notifications marked as read',
        ]);
    }

    /**
     * POST /notifications/{id}/dismiss - Dismiss notification
     */
    public static function dismiss($request) {
        global $wpdb;

        $user_id = get_current_user_id();
        $id = (int) $request->get_param('id');
        $table = $wpdb->prefix . 'pit_notifications';

        $result = $wpdb->update(
            $table,
            ['is_dismissed' => 1],
            [
                'id'      => $id,
                'user_id' => $user_id,
            ]
        );

        if ($result === false) {
            return new WP_Error('update_failed', 'Failed to dismiss notification', ['status' => 500]);
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'Notification dismissed',
        ]);
    }

    /**
     * GET /notifications/settings - Get notification preferences
     */
    public static function get_settings($request) {
        $user_id = get_current_user_id();

        $defaults = [
            'email_enabled'             => true,
            'email_task_reminders'      => true,
            'email_interview_reminders' => true,
            'email_overdue_tasks'       => true,
            'reminder_hours_before'     => 24,
        ];

        $settings = get_user_meta($user_id, 'pit_notification_settings', true);
        $settings = is_array($settings) ? array_merge($defaults, $settings) : $defaults;

        return rest_ensure_response([
            'success' => true,
            'data'    => $settings,
        ]);
    }

    /**
     * POST /notifications/settings - Update notification preferences
     */
    public static function update_settings($request) {
        $user_id = get_current_user_id();

        $current = get_user_meta($user_id, 'pit_notification_settings', true);
        $current = is_array($current) ? $current : [];

        $params = $request->get_params();
        $allowed = [
            'email_enabled',
            'email_task_reminders',
            'email_interview_reminders',
            'email_overdue_tasks',
            'reminder_hours_before',
        ];

        foreach ($allowed as $key) {
            if (isset($params[$key])) {
                $current[$key] = $params[$key];
            }
        }

        update_user_meta($user_id, 'pit_notification_settings', $current);

        return rest_ensure_response([
            'success' => true,
            'message' => 'Settings updated',
            'data'    => $current,
        ]);
    }

    /**
     * Create a notification for a user
     *
     * @param int    $user_id User ID
     * @param array  $data    Notification data
     * @return int|false Notification ID or false on failure
     */
    public static function create_notification($user_id, $data) {
        global $wpdb;

        $table = $wpdb->prefix . 'pit_notifications';

        $insert_data = [
            'user_id'       => $user_id,
            'type'          => $data['type'] ?? 'info',
            'source'        => $data['source'] ?? 'system',
            'source_id'     => $data['source_id'] ?? null,
            'title'         => $data['title'],
            'message'       => $data['message'] ?? null,
            'action_url'    => $data['action_url'] ?? null,
            'action_label'  => $data['action_label'] ?? null,
            'appearance_id' => $data['appearance_id'] ?? null,
            'task_id'       => $data['task_id'] ?? null,
            'event_id'      => $data['event_id'] ?? null,
            'scheduled_for' => $data['scheduled_for'] ?? null,
            'meta'          => isset($data['meta']) ? json_encode($data['meta']) : null,
            'created_at'    => current_time('mysql'),
        ];

        $result = $wpdb->insert($table, $insert_data);

        return $result ? $wpdb->insert_id : false;
    }
}
