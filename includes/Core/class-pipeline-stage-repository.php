<?php
/**
 * Pipeline Stage Repository
 *
 * Handles all database operations for CRM pipeline stages.
 * Supports system defaults and user-customizable stages.
 *
 * @package PodcastInfluenceTracker
 * @subpackage Core
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Pipeline_Stage_Repository {

    /**
     * Get stage by ID
     *
     * @param int $stage_id Stage ID
     * @return object|null Stage object or null
     */
    public static function get($stage_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_pipeline_stages';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $stage_id
        ));
    }

    /**
     * Get stage by key
     *
     * @param string $stage_key Stage key
     * @param int|null $user_id User ID (null for system default)
     * @return object|null Stage object or null
     */
    public static function get_by_key($stage_key, $user_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_pipeline_stages';

        if ($user_id !== null) {
            // Try user-specific first
            $stage = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE stage_key = %s AND user_id = %d",
                $stage_key, $user_id
            ));
            if ($stage) {
                return $stage;
            }
        }

        // Fall back to system default
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE stage_key = %s AND user_id IS NULL",
            $stage_key
        ));
    }

    /**
     * Get stages for a user (or system defaults)
     *
     * @param int|null $user_id User ID (null for current user)
     * @param bool $active_only Only return active stages
     * @return array Stage objects
     */
    public static function get_for_user($user_id = null, $active_only = true) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_pipeline_stages';

        if ($user_id === null) {
            $user_id = PIT_User_Context::get_user_id();
        }

        $active_clause = $active_only ? " AND is_active = 1" : "";

        // Check if user has custom stages
        $has_custom = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE user_id = %d {$active_clause}",
            $user_id
        ));

        if ($has_custom > 0) {
            // Return user's custom stages
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE user_id = %d {$active_clause} ORDER BY sort_order ASC",
                $user_id
            ));
        }

        // Return system defaults
        return $wpdb->get_results(
            "SELECT * FROM $table WHERE user_id IS NULL {$active_clause} ORDER BY sort_order ASC"
        );
    }

    /**
     * Get system default stages
     *
     * @param bool $active_only Only return active stages
     * @return array Stage objects
     */
    public static function get_system_defaults($active_only = true) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_pipeline_stages';

        $active_clause = $active_only ? " AND is_active = 1" : "";

        return $wpdb->get_results(
            "SELECT * FROM $table WHERE user_id IS NULL AND is_system = 1 {$active_clause} ORDER BY sort_order ASC"
        );
    }

    /**
     * Create a new stage
     *
     * @param array $data Stage data
     * @return int|false Stage ID or false on failure
     */
    public static function create($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_pipeline_stages';

        // Set defaults
        if (!isset($data['color'])) {
            $data['color'] = '#6b7280';
        }
        if (!isset($data['is_system'])) {
            $data['is_system'] = 0;
        }
        if (!isset($data['is_active'])) {
            $data['is_active'] = 1;
        }
        if (!isset($data['row_group'])) {
            $data['row_group'] = 1;
        }
        if (!isset($data['created_at'])) {
            $data['created_at'] = current_time('mysql');
        }
        if (!isset($data['updated_at'])) {
            $data['updated_at'] = current_time('mysql');
        }

        // Get next sort_order if not provided
        if (!isset($data['sort_order'])) {
            $max_order = $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(sort_order) FROM $table WHERE user_id = %d",
                $data['user_id'] ?? null
            ));
            $data['sort_order'] = ($max_order ?? 0) + 1;
        }

        $wpdb->insert($table, $data);

        return $wpdb->insert_id ?: false;
    }

    /**
     * Update a stage
     *
     * @param int $stage_id Stage ID
     * @param array $data Data to update
     * @return bool Success
     */
    public static function update($stage_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_pipeline_stages';

        unset($data['created_at']);
        $data['updated_at'] = current_time('mysql');

        return $wpdb->update($table, $data, ['id' => $stage_id]) !== false;
    }

    /**
     * Delete a stage
     *
     * @param int $stage_id Stage ID
     * @return bool Success
     */
    public static function delete($stage_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_pipeline_stages';

        // Don't allow deleting system stages
        $stage = self::get($stage_id);
        if ($stage && $stage->is_system) {
            return false;
        }

        return $wpdb->delete($table, ['id' => $stage_id], ['%d']) !== false;
    }

    /**
     * Reorder stages
     *
     * @param array $order Array of stage IDs in new order
     * @param int|null $user_id User ID
     * @return bool Success
     */
    public static function reorder($order, $user_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_pipeline_stages';

        if ($user_id === null) {
            $user_id = PIT_User_Context::get_user_id();
        }

        foreach ($order as $sort_order => $stage_id) {
            $wpdb->update(
                $table,
                ['sort_order' => $sort_order + 1, 'updated_at' => current_time('mysql')],
                ['id' => (int) $stage_id, 'user_id' => $user_id]
            );
        }

        return true;
    }

    /**
     * Clone system defaults for a user
     *
     * @param int|null $user_id User ID
     * @return bool Success
     */
    public static function clone_defaults_for_user($user_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_pipeline_stages';

        if ($user_id === null) {
            $user_id = PIT_User_Context::get_user_id();
        }

        // Check if user already has custom stages
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE user_id = %d",
            $user_id
        ));

        if ($existing > 0) {
            return false; // Already has custom stages
        }

        // Get system defaults
        $defaults = self::get_system_defaults(false);

        foreach ($defaults as $stage) {
            self::create([
                'user_id' => $user_id,
                'stage_key' => $stage->stage_key,
                'label' => $stage->label,
                'color' => $stage->color,
                'sort_order' => $stage->sort_order,
                'row_group' => $stage->row_group,
                'is_system' => 0,
                'is_active' => $stage->is_active,
            ]);
        }

        return true;
    }

    /**
     * Reset user stages to system defaults
     *
     * @param int|null $user_id User ID
     * @return bool Success
     */
    public static function reset_to_defaults($user_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_pipeline_stages';

        if ($user_id === null) {
            $user_id = PIT_User_Context::get_user_id();
        }

        // Delete all user-specific stages
        $wpdb->delete($table, ['user_id' => $user_id], ['%d']);

        return true;
    }

    /**
     * Check if user has custom stages
     *
     * @param int|null $user_id User ID
     * @return bool
     */
    public static function has_custom_stages($user_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_pipeline_stages';

        if ($user_id === null) {
            $user_id = PIT_User_Context::get_user_id();
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE user_id = %d",
            $user_id
        )) > 0;
    }

    /**
     * Get stages grouped by row
     *
     * @param int|null $user_id User ID
     * @return array Stages grouped by row_group
     */
    public static function get_grouped($user_id = null) {
        $stages = self::get_for_user($user_id);
        $grouped = [];

        foreach ($stages as $stage) {
            $group = (int) $stage->row_group;
            if (!isset($grouped[$group])) {
                $grouped[$group] = [];
            }
            $grouped[$group][] = $stage;
        }

        ksort($grouped);
        return $grouped;
    }

    /**
     * Format stage for API response
     *
     * @param object $stage Stage object
     * @return array Formatted stage
     */
    public static function format($stage) {
        return [
            'id' => (int) $stage->id,
            'key' => $stage->stage_key,
            'label' => $stage->label,
            'color' => $stage->color,
            'sort_order' => (int) $stage->sort_order,
            'row_group' => (int) $stage->row_group,
            'is_system' => (bool) $stage->is_system,
            'is_active' => (bool) $stage->is_active,
        ];
    }
}
