<?php
/**
 * REST API Controller for Appearance Notes
 * 
 * Provides CRUD endpoints for notes linked to interview appearances.
 * 
 * @package Podcast_Influence_Tracker
 * @since 3.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_REST_Appearance_Notes {

    const NAMESPACE = 'guestify/v1';

    /**
     * Register REST routes
     */
    public static function register_routes() {
        // Get notes for an appearance
        register_rest_route(self::NAMESPACE, '/appearances/(?P<appearance_id>\d+)/notes', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_notes'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
            'args' => [
                'appearance_id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
                'note_type' => [
                    'type' => 'string',
                    'enum' => ['general', 'contact', 'research', 'meeting', 'follow_up', 'pitch', 'feedback'],
                ],
            ],
        ]);

        // Create note for an appearance
        register_rest_route(self::NAMESPACE, '/appearances/(?P<appearance_id>\d+)/notes', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_note'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
            'args' => [
                'appearance_id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
            ],
        ]);

        // Update note
        register_rest_route(self::NAMESPACE, '/appearances/(?P<appearance_id>\d+)/notes/(?P<note_id>\d+)', [
            'methods' => 'PATCH',
            'callback' => [__CLASS__, 'update_note'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
            'args' => [
                'appearance_id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
                'note_id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
            ],
        ]);

        // Delete note
        register_rest_route(self::NAMESPACE, '/appearances/(?P<appearance_id>\d+)/notes/(?P<note_id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [__CLASS__, 'delete_note'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
            'args' => [
                'appearance_id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
                'note_id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
            ],
        ]);

        // Toggle note pin status
        register_rest_route(self::NAMESPACE, '/appearances/(?P<appearance_id>\d+)/notes/(?P<note_id>\d+)/pin', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'toggle_pin'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
            'args' => [
                'appearance_id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
                'note_id' => [
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
     * Get notes for an appearance
     */
    public static function get_notes($request) {
        global $wpdb;

        $appearance_id = (int) $request->get_param('appearance_id');
        
        if (!self::verify_ownership($appearance_id)) {
            return new WP_Error('not_found', 'Appearance not found', ['status' => 404]);
        }

        $table = $wpdb->prefix . 'pit_appearance_notes';
        
        // Build query
        $where = ['appearance_id = %d'];
        $params = [$appearance_id];
        
        // Note type filter
        $note_type = $request->get_param('note_type');
        if ($note_type) {
            $where[] = 'note_type = %s';
            $params[] = $note_type;
        }
        
        $where_sql = implode(' AND ', $where);
        
        // Order: pinned first, then by date
        $sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY is_pinned DESC, created_at DESC";
        $notes = $wpdb->get_results($wpdb->prepare($sql, $params));
        
        $data = array_map([__CLASS__, 'format_note'], $notes);
        
        // Group by type for UI
        $by_type = [];
        foreach ($data as $note) {
            $type = $note['note_type'];
            if (!isset($by_type[$type])) {
                $by_type[$type] = [];
            }
            $by_type[$type][] = $note;
        }
        
        return new WP_REST_Response([
            'data' => $data,
            'by_type' => $by_type,
            'meta' => [
                'total' => count($data),
                'pinned' => count(array_filter($data, function($n) { return $n['is_pinned']; })),
            ],
        ], 200);
    }

    /**
     * Create a new note
     */
    public static function create_note($request) {
        global $wpdb;

        $appearance_id = (int) $request->get_param('appearance_id');
        
        if (!self::verify_ownership($appearance_id)) {
            return new WP_Error('not_found', 'Appearance not found', ['status' => 404]);
        }

        $table = $wpdb->prefix . 'pit_appearance_notes';
        $user_id = get_current_user_id();
        
        $content = wp_kses_post($request->get_param('content'));
        if (empty($content)) {
            return new WP_Error('missing_content', 'Note content is required', ['status' => 400]);
        }
        
        $data = [
            'appearance_id' => $appearance_id,
            'user_id' => $user_id,
            'title' => sanitize_text_field($request->get_param('title')),
            'content' => $content,
            'note_type' => sanitize_text_field($request->get_param('note_type')) ?: 'general',
            'is_pinned' => $request->get_param('is_pinned') ? 1 : 0,
            'note_date' => sanitize_text_field($request->get_param('note_date')) ?: current_time('Y-m-d'),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];
        
        $result = $wpdb->insert($table, $data);
        
        if ($result === false) {
            return new WP_Error('insert_failed', 'Failed to create note', ['status' => 500]);
        }
        
        $note_id = $wpdb->insert_id;
        $note = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $note_id));
        
        return new WP_REST_Response([
            'data' => self::format_note($note),
            'message' => 'Note created successfully',
        ], 201);
    }

    /**
     * Update a note
     */
    public static function update_note($request) {
        global $wpdb;

        $appearance_id = (int) $request->get_param('appearance_id');
        $note_id = (int) $request->get_param('note_id');
        
        if (!self::verify_ownership($appearance_id)) {
            return new WP_Error('not_found', 'Appearance not found', ['status' => 404]);
        }

        $table = $wpdb->prefix . 'pit_appearance_notes';
        
        // Verify note belongs to appearance
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d AND appearance_id = %d",
            $note_id,
            $appearance_id
        ));
        
        if (!$exists) {
            return new WP_Error('note_not_found', 'Note not found', ['status' => 404]);
        }
        
        // Build update data
        $data = [];
        
        $title = $request->get_param('title');
        if ($title !== null) {
            $data['title'] = sanitize_text_field($title);
        }
        
        $content = $request->get_param('content');
        if ($content !== null) {
            $data['content'] = wp_kses_post($content);
        }
        
        $note_type = $request->get_param('note_type');
        if ($note_type !== null) {
            $data['note_type'] = sanitize_text_field($note_type);
        }
        
        $is_pinned = $request->get_param('is_pinned');
        if ($is_pinned !== null) {
            $data['is_pinned'] = $is_pinned ? 1 : 0;
        }
        
        $note_date = $request->get_param('note_date');
        if ($note_date !== null) {
            $data['note_date'] = sanitize_text_field($note_date);
        }
        
        if (empty($data)) {
            return new WP_Error('no_data', 'No valid fields to update', ['status' => 400]);
        }
        
        $data['updated_at'] = current_time('mysql');
        
        $result = $wpdb->update($table, $data, ['id' => $note_id]);
        
        if ($result === false) {
            return new WP_Error('update_failed', 'Failed to update note', ['status' => 500]);
        }
        
        $note = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $note_id));
        
        return new WP_REST_Response([
            'data' => self::format_note($note),
            'message' => 'Note updated successfully',
        ], 200);
    }

    /**
     * Delete a note
     */
    public static function delete_note($request) {
        global $wpdb;

        $appearance_id = (int) $request->get_param('appearance_id');
        $note_id = (int) $request->get_param('note_id');
        
        if (!self::verify_ownership($appearance_id)) {
            return new WP_Error('not_found', 'Appearance not found', ['status' => 404]);
        }

        $table = $wpdb->prefix . 'pit_appearance_notes';
        
        // Verify note belongs to appearance
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d AND appearance_id = %d",
            $note_id,
            $appearance_id
        ));
        
        if (!$exists) {
            return new WP_Error('note_not_found', 'Note not found', ['status' => 404]);
        }
        
        $result = $wpdb->delete($table, ['id' => $note_id]);
        
        if ($result === false) {
            return new WP_Error('delete_failed', 'Failed to delete note', ['status' => 500]);
        }
        
        return new WP_REST_Response([
            'message' => 'Note deleted successfully',
        ], 200);
    }

    /**
     * Toggle note pin status
     */
    public static function toggle_pin($request) {
        global $wpdb;

        $appearance_id = (int) $request->get_param('appearance_id');
        $note_id = (int) $request->get_param('note_id');
        
        if (!self::verify_ownership($appearance_id)) {
            return new WP_Error('not_found', 'Appearance not found', ['status' => 404]);
        }

        $table = $wpdb->prefix . 'pit_appearance_notes';
        
        // Get current note
        $note = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND appearance_id = %d",
            $note_id,
            $appearance_id
        ));
        
        if (!$note) {
            return new WP_Error('note_not_found', 'Note not found', ['status' => 404]);
        }
        
        // Toggle
        $new_pinned = $note->is_pinned ? 0 : 1;
        
        $wpdb->update($table, [
            'is_pinned' => $new_pinned,
            'updated_at' => current_time('mysql'),
        ], ['id' => $note_id]);
        
        $note = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $note_id));
        
        return new WP_REST_Response([
            'data' => self::format_note($note),
            'message' => $new_pinned ? 'Note pinned' : 'Note unpinned',
        ], 200);
    }

    /**
     * Format note for API response
     */
    private static function format_note($row) {
        return [
            'id' => (int) $row->id,
            'appearance_id' => (int) $row->appearance_id,
            'user_id' => (int) $row->user_id,
            'title' => $row->title,
            'content' => $row->content,
            'content_preview' => wp_trim_words(wp_strip_all_tags($row->content), 20, '...'),
            'note_type' => $row->note_type,
            'is_pinned' => (bool) $row->is_pinned,
            'note_date' => $row->note_date,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
            'time_ago' => human_time_diff(strtotime($row->created_at), current_time('timestamp')) . ' ago',
        ];
    }
}
