<?php
/**
 * REST API Controller for Pipeline Stages
 * 
 * Provides endpoints for managing CRM pipeline stages.
 * Supports system defaults and user-customizable stages.
 * 
 * @package Podcast_Influence_Tracker
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_REST_Pipeline_Stages {

    const NAMESPACE = 'guestify/v1';

    /**
     * Register REST routes
     */
    public static function register_routes() {
        // Get stages (for current user, falls back to system defaults)
        register_rest_route(self::NAMESPACE, '/pipeline-stages', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_stages'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Create custom stage
        register_rest_route(self::NAMESPACE, '/pipeline-stages', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_stage'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Update stage
        register_rest_route(self::NAMESPACE, '/pipeline-stages/(?P<id>\d+)', [
            'methods' => 'PATCH',
            'callback' => [__CLASS__, 'update_stage'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Delete stage
        register_rest_route(self::NAMESPACE, '/pipeline-stages/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [__CLASS__, 'delete_stage'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Reorder stages
        register_rest_route(self::NAMESPACE, '/pipeline-stages/reorder', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'reorder_stages'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);
    }

    public static function check_permissions() {
        return is_user_logged_in();
    }

    /**
     * Get pipeline stages for current user
     * Returns user's custom stages if they exist, otherwise system defaults
     */
    public static function get_stages($request) {
        global $wpdb;

        $user_id = get_current_user_id();
        $table = $wpdb->prefix . 'pit_pipeline_stages';

        // Check if user has custom stages
        $has_custom = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND is_active = 1",
            $user_id
        ));

        if ($has_custom > 0) {
            // Return user's custom stages
            $stages = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} 
                 WHERE user_id = %d AND is_active = 1 
                 ORDER BY sort_order ASC",
                $user_id
            ));
        } else {
            // Return system defaults (user_id IS NULL)
            $stages = $wpdb->get_results(
                "SELECT * FROM {$table} 
                 WHERE user_id IS NULL AND is_active = 1 
                 ORDER BY sort_order ASC"
            );
        }

        return new WP_REST_Response([
            'data' => array_map([__CLASS__, 'format_stage'], $stages),
            'is_custom' => $has_custom > 0,
        ], 200);
    }

    /**
     * Create a custom stage for the user
     */
    public static function create_stage($request) {
        global $wpdb;

        $user_id = get_current_user_id();
        $table = $wpdb->prefix . 'pit_pipeline_stages';

        $stage_key = sanitize_key($request->get_param('stage_key'));
        $label = sanitize_text_field($request->get_param('label'));

        if (empty($stage_key) || empty($label)) {
            return new WP_Error('missing_data', 'stage_key and label are required', ['status' => 400]);
        }

        // Check if stage_key already exists for this user
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id = %d AND stage_key = %s",
            $user_id, $stage_key
        ));

        if ($exists) {
            return new WP_Error('duplicate', 'Stage key already exists', ['status' => 400]);
        }

        // Get max sort_order for this user
        $max_order = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(sort_order) FROM {$table} WHERE user_id = %d",
            $user_id
        )) ?: 0;

        $data = [
            'user_id' => $user_id,
            'stage_key' => $stage_key,
            'label' => $label,
            'color' => sanitize_hex_color($request->get_param('color')) ?: '#6b7280',
            'sort_order' => $max_order + 1,
            'row_group' => (int) ($request->get_param('row_group') ?: 1),
            'is_active' => 1,
            'is_system' => 0,
        ];

        if (!$wpdb->insert($table, $data)) {
            return new WP_Error('insert_failed', 'Failed to create stage', ['status' => 500]);
        }

        return new WP_REST_Response([
            'id' => $wpdb->insert_id,
            'message' => 'Stage created successfully',
        ], 201);
    }

    /**
     * Update a stage
     */
    public static function update_stage($request) {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $user_id = get_current_user_id();
        $table = $wpdb->prefix . 'pit_pipeline_stages';

        // Verify ownership (user can only edit their own stages, not system defaults)
        $stage = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $id
        ));

        if (!$stage) {
            return new WP_Error('not_found', 'Stage not found', ['status' => 404]);
        }

        // System stages can only be edited by admins
        if ($stage->is_system && !current_user_can('manage_options')) {
            return new WP_Error('forbidden', 'Cannot edit system stages', ['status' => 403]);
        }

        // User stages can only be edited by owner
        if ($stage->user_id && $stage->user_id != $user_id && !current_user_can('manage_options')) {
            return new WP_Error('forbidden', 'Not authorized', ['status' => 403]);
        }

        $allowed = ['label', 'color', 'sort_order', 'row_group', 'is_active'];
        $data = [];

        foreach ($allowed as $field) {
            $value = $request->get_param($field);
            if ($value !== null) {
                if ($field === 'color') {
                    $data[$field] = sanitize_hex_color($value) ?: '#6b7280';
                } elseif ($field === 'label') {
                    $data[$field] = sanitize_text_field($value);
                } else {
                    $data[$field] = (int) $value;
                }
            }
        }

        if (empty($data)) {
            return new WP_Error('no_data', 'No valid fields to update', ['status' => 400]);
        }

        $wpdb->update($table, $data, ['id' => $id]);

        return new WP_REST_Response([
            'id' => $id,
            'message' => 'Stage updated successfully',
        ], 200);
    }

    /**
     * Delete a stage
     */
    public static function delete_stage($request) {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $user_id = get_current_user_id();
        $table = $wpdb->prefix . 'pit_pipeline_stages';

        $stage = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $id
        ));

        if (!$stage) {
            return new WP_Error('not_found', 'Stage not found', ['status' => 404]);
        }

        // Cannot delete system stages
        if ($stage->is_system) {
            return new WP_Error('forbidden', 'Cannot delete system stages', ['status' => 403]);
        }

        // User stages can only be deleted by owner
        if ($stage->user_id != $user_id && !current_user_can('manage_options')) {
            return new WP_Error('forbidden', 'Not authorized', ['status' => 403]);
        }

        $wpdb->delete($table, ['id' => $id]);

        return new WP_REST_Response(['message' => 'Stage deleted'], 200);
    }

    /**
     * Reorder stages
     */
    public static function reorder_stages($request) {
        global $wpdb;

        $user_id = get_current_user_id();
        $table = $wpdb->prefix . 'pit_pipeline_stages';
        $order = $request->get_param('order'); // Array of stage IDs in new order

        if (!is_array($order) || empty($order)) {
            return new WP_Error('invalid_data', 'Order must be an array of stage IDs', ['status' => 400]);
        }

        foreach ($order as $sort_order => $stage_id) {
            $wpdb->update(
                $table,
                ['sort_order' => $sort_order + 1],
                ['id' => (int) $stage_id, 'user_id' => $user_id]
            );
        }

        return new WP_REST_Response(['message' => 'Stages reordered'], 200);
    }

    /**
     * Format stage for API response
     */
    private static function format_stage($row) {
        return [
            'id' => (int) $row->id,
            'key' => $row->stage_key,
            'label' => $row->label,
            'color' => $row->color,
            'sort_order' => (int) $row->sort_order,
            'row_group' => (int) $row->row_group,
            'is_system' => (bool) $row->is_system,
            'is_active' => (bool) $row->is_active,
        ];
    }
}
