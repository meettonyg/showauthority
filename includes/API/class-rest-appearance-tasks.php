<?php
/**
 * REST API Controller for Appearance Tasks
 * 
 * Provides CRUD endpoints for tasks linked to interview appearances.
 * 
 * @package Podcast_Influence_Tracker
 * @since 3.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_REST_Appearance_Tasks {

    const NAMESPACE = 'guestify/v1';

    /**
     * Register REST routes
     */
    public static function register_routes() {
        // Get tasks for an appearance
        register_rest_route(self::NAMESPACE, '/appearances/(?P<appearance_id>\d+)/tasks', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_tasks'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
            'args' => [
                'appearance_id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['pending', 'in_progress', 'completed', 'cancelled'],
                ],
                'sort' => [
                    'type' => 'string',
                    'enum' => ['due_date', 'priority', 'created_at'],
                    'default' => 'created_at',
                ],
            ],
        ]);

        // Create task for an appearance
        register_rest_route(self::NAMESPACE, '/appearances/(?P<appearance_id>\d+)/tasks', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_task'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
            'args' => [
                'appearance_id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
            ],
        ]);

        // Update task
        register_rest_route(self::NAMESPACE, '/appearances/(?P<appearance_id>\d+)/tasks/(?P<task_id>\d+)', [
            'methods' => 'PATCH',
            'callback' => [__CLASS__, 'update_task'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
            'args' => [
                'appearance_id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
                'task_id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
            ],
        ]);

        // Delete task
        register_rest_route(self::NAMESPACE, '/appearances/(?P<appearance_id>\d+)/tasks/(?P<task_id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [__CLASS__, 'delete_task'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
            'args' => [
                'appearance_id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
                'task_id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
            ],
        ]);

        // Toggle task completion (quick action)
        register_rest_route(self::NAMESPACE, '/appearances/(?P<appearance_id>\d+)/tasks/(?P<task_id>\d+)/toggle', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'toggle_task'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
            'args' => [
                'appearance_id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
                'task_id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
            ],
        ]);
    }

    /**
     * Check if user has permissions
     */
    public static function check_permissions($request) {
        return is_user_logged_in();
    }

    /**
     * Verify user owns the appearance
     */
    private static function verify_ownership($appearance_id) {
        global $wpdb;
        
        $user_id = get_current_user_id();
        $table = $wpdb->prefix . 'pit_guest_appearances';
        
        // Admins can access all appearances
        if (current_user_can('manage_options')) {
            return $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE id = %d",
                $appearance_id
            ));
        }
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d AND user_id = %d",
            $appearance_id,
            $user_id
        ));
    }

    /**
     * Get tasks for an appearance
     */
    public static function get_tasks($request) {
        global $wpdb;

        $appearance_id = (int) $request->get_param('appearance_id');
        
        if (!self::verify_ownership($appearance_id)) {
            return new WP_Error('not_found', 'Appearance not found', ['status' => 404]);
        }

        $table = $wpdb->prefix . 'pit_appearance_tasks';
        
        // Build query
        $where = ['appearance_id = %d'];
        $params = [$appearance_id];
        
        // Status filter
        $status = $request->get_param('status');
        if ($status) {
            $where[] = 'status = %s';
            $params[] = $status;
        }
        
        $where_sql = implode(' AND ', $where);
        
        // Sort order
        $sort = $request->get_param('sort') ?: 'created_at';
        $sort_map = [
            'due_date' => 'due_date ASC, priority DESC',
            'priority' => 'FIELD(priority, "urgent", "high", "medium", "low"), due_date ASC',
            'created_at' => 'created_at DESC',
        ];
        $order_sql = $sort_map[$sort] ?? 'created_at DESC';
        
        $sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$order_sql}";
        $tasks = $wpdb->get_results($wpdb->prepare($sql, $params));
        
        $data = array_map([__CLASS__, 'format_task'], $tasks);
        
        return new WP_REST_Response([
            'data' => $data,
            'meta' => [
                'total' => count($data),
                'pending' => count(array_filter($data, function($t) { return $t['status'] === 'pending'; })),
                'completed' => count(array_filter($data, function($t) { return $t['status'] === 'completed'; })),
            ],
        ], 200);
    }

    /**
     * Create a new task
     */
    public static function create_task($request) {
        global $wpdb;

        $appearance_id = (int) $request->get_param('appearance_id');
        
        if (!self::verify_ownership($appearance_id)) {
            return new WP_Error('not_found', 'Appearance not found', ['status' => 404]);
        }

        $table = $wpdb->prefix . 'pit_appearance_tasks';
        $user_id = get_current_user_id();
        
        $title = sanitize_text_field($request->get_param('title'));
        if (empty($title)) {
            return new WP_Error('missing_title', 'Task title is required', ['status' => 400]);
        }
        
        $data = [
            'appearance_id' => $appearance_id,
            'user_id' => $user_id,
            'title' => $title,
            'description' => wp_kses_post($request->get_param('description')),
            'task_type' => sanitize_text_field($request->get_param('task_type')) ?: 'todo',
            'status' => 'pending',
            'priority' => sanitize_text_field($request->get_param('priority')) ?: 'medium',
            'is_done' => 0,
            'due_date' => sanitize_text_field($request->get_param('due_date')) ?: null,
            'reminder_date' => sanitize_text_field($request->get_param('reminder_date')) ?: null,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];
        
        $result = $wpdb->insert($table, $data);
        
        if ($result === false) {
            return new WP_Error('insert_failed', 'Failed to create task', ['status' => 500]);
        }
        
        $task_id = $wpdb->insert_id;
        $task = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $task_id));
        
        return new WP_REST_Response([
            'data' => self::format_task($task),
            'message' => 'Task created successfully',
        ], 201);
    }

    /**
     * Update a task
     */
    public static function update_task($request) {
        global $wpdb;

        $appearance_id = (int) $request->get_param('appearance_id');
        $task_id = (int) $request->get_param('task_id');
        
        if (!self::verify_ownership($appearance_id)) {
            return new WP_Error('not_found', 'Appearance not found', ['status' => 404]);
        }

        $table = $wpdb->prefix . 'pit_appearance_tasks';
        
        // Verify task belongs to appearance
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d AND appearance_id = %d",
            $task_id,
            $appearance_id
        ));
        
        if (!$exists) {
            return new WP_Error('task_not_found', 'Task not found', ['status' => 404]);
        }
        
        // Build update data
        $allowed = ['title', 'description', 'task_type', 'status', 'priority', 'due_date', 'reminder_date'];
        $data = [];
        
        foreach ($allowed as $field) {
            $value = $request->get_param($field);
            if ($value !== null) {
                if ($field === 'description') {
                    $data[$field] = wp_kses_post($value);
                } else {
                    $data[$field] = sanitize_text_field($value);
                }
            }
        }
        
        // Handle status changes
        if (isset($data['status'])) {
            if ($data['status'] === 'completed') {
                $data['is_done'] = 1;
                $data['completed_at'] = current_time('mysql');
            } elseif ($data['status'] === 'pending') {
                $data['is_done'] = 0;
                $data['completed_at'] = null;
            }
        }
        
        if (empty($data)) {
            return new WP_Error('no_data', 'No valid fields to update', ['status' => 400]);
        }
        
        $data['updated_at'] = current_time('mysql');
        
        $result = $wpdb->update($table, $data, ['id' => $task_id]);
        
        if ($result === false) {
            return new WP_Error('update_failed', 'Failed to update task', ['status' => 500]);
        }
        
        $task = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $task_id));
        
        return new WP_REST_Response([
            'data' => self::format_task($task),
            'message' => 'Task updated successfully',
        ], 200);
    }

    /**
     * Delete a task
     */
    public static function delete_task($request) {
        global $wpdb;

        $appearance_id = (int) $request->get_param('appearance_id');
        $task_id = (int) $request->get_param('task_id');
        
        if (!self::verify_ownership($appearance_id)) {
            return new WP_Error('not_found', 'Appearance not found', ['status' => 404]);
        }

        $table = $wpdb->prefix . 'pit_appearance_tasks';
        
        // Verify task belongs to appearance
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d AND appearance_id = %d",
            $task_id,
            $appearance_id
        ));
        
        if (!$exists) {
            return new WP_Error('task_not_found', 'Task not found', ['status' => 404]);
        }
        
        $result = $wpdb->delete($table, ['id' => $task_id]);
        
        if ($result === false) {
            return new WP_Error('delete_failed', 'Failed to delete task', ['status' => 500]);
        }
        
        return new WP_REST_Response([
            'message' => 'Task deleted successfully',
        ], 200);
    }

    /**
     * Toggle task completion
     */
    public static function toggle_task($request) {
        global $wpdb;

        $appearance_id = (int) $request->get_param('appearance_id');
        $task_id = (int) $request->get_param('task_id');
        
        if (!self::verify_ownership($appearance_id)) {
            return new WP_Error('not_found', 'Appearance not found', ['status' => 404]);
        }

        $table = $wpdb->prefix . 'pit_appearance_tasks';
        
        // Get current task
        $task = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND appearance_id = %d",
            $task_id,
            $appearance_id
        ));
        
        if (!$task) {
            return new WP_Error('task_not_found', 'Task not found', ['status' => 404]);
        }
        
        // Toggle
        $new_done = $task->is_done ? 0 : 1;
        $new_status = $new_done ? 'completed' : 'pending';
        $completed_at = $new_done ? current_time('mysql') : null;
        
        $wpdb->update($table, [
            'is_done' => $new_done,
            'status' => $new_status,
            'completed_at' => $completed_at,
            'updated_at' => current_time('mysql'),
        ], ['id' => $task_id]);
        
        $task = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $task_id));
        
        return new WP_REST_Response([
            'data' => self::format_task($task),
            'message' => $new_done ? 'Task completed' : 'Task reopened',
        ], 200);
    }

    /**
     * Format task for API response
     */
    private static function format_task($row) {
        return [
            'id' => (int) $row->id,
            'appearance_id' => (int) $row->appearance_id,
            'user_id' => (int) $row->user_id,
            'title' => $row->title,
            'description' => $row->description,
            'task_type' => $row->task_type,
            'status' => $row->status,
            'priority' => $row->priority,
            'is_done' => (bool) $row->is_done,
            'due_date' => $row->due_date,
            'reminder_date' => $row->reminder_date,
            'completed_at' => $row->completed_at,
            'is_overdue' => $row->due_date && !$row->is_done && strtotime($row->due_date) < strtotime('today'),
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}
