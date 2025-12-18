<?php
/**
 * REST API Controller for Appearance Tags
 *
 * Provides endpoints for managing user-defined tags on appearances.
 *
 * Endpoints:
 * - GET    /tags                           - List all user's tags
 * - POST   /tags                           - Create a new tag
 * - PATCH  /tags/{tag_id}                  - Update a tag
 * - DELETE /tags/{tag_id}                  - Delete a tag
 * - GET    /appearances/{id}/tags          - Get tags for an appearance
 * - POST   /appearances/{id}/tags          - Add tag to appearance
 * - DELETE /appearances/{id}/tags/{tag_id} - Remove tag from appearance
 *
 * @package Podcast_Influence_Tracker
 * @since 3.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_REST_Appearance_Tags {

    const NAMESPACE = 'guestify/v1';

    /**
     * Register REST routes
     */
    public static function register_routes() {
        // List all tags for the user
        register_rest_route(self::NAMESPACE, '/tags', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'list_tags'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
            'args' => [
                'search' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'orderby' => [
                    'type' => 'string',
                    'enum' => ['usage_count', 'name', 'created_at'],
                    'default' => 'usage_count',
                ],
                'order' => [
                    'type' => 'string',
                    'enum' => ['ASC', 'DESC'],
                    'default' => 'DESC',
                ],
            ],
        ]);

        // Create a new tag
        register_rest_route(self::NAMESPACE, '/tags', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_tag'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
            'args' => [
                'name' => [
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'color' => [
                    'type' => 'string',
                    'default' => '#6b7280',
                ],
                'description' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
            ],
        ]);

        // Update a tag
        register_rest_route(self::NAMESPACE, '/tags/(?P<tag_id>\d+)', [
            'methods' => 'PATCH',
            'callback' => [__CLASS__, 'update_tag'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
            'args' => [
                'tag_id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
                'name' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'color' => [
                    'type' => 'string',
                ],
                'description' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
            ],
        ]);

        // Delete a tag
        register_rest_route(self::NAMESPACE, '/tags/(?P<tag_id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [__CLASS__, 'delete_tag'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
            'args' => [
                'tag_id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
            ],
        ]);

        // Get tags for an appearance
        register_rest_route(self::NAMESPACE, '/appearances/(?P<appearance_id>\d+)/tags', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_appearance_tags'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
            'args' => [
                'appearance_id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
            ],
        ]);

        // Add tag to appearance (can create tag if needed)
        register_rest_route(self::NAMESPACE, '/appearances/(?P<appearance_id>\d+)/tags', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'add_tag_to_appearance'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
            'args' => [
                'appearance_id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
                'tag_id' => [
                    'type' => 'integer',
                ],
                'name' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'color' => [
                    'type' => 'string',
                    'default' => '#6b7280',
                ],
            ],
        ]);

        // Remove tag from appearance
        register_rest_route(self::NAMESPACE, '/appearances/(?P<appearance_id>\d+)/tags/(?P<tag_id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [__CLASS__, 'remove_tag_from_appearance'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
            'args' => [
                'appearance_id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
                'tag_id' => [
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
    private static function verify_appearance_ownership($appearance_id) {
        global $wpdb;

        $user_id = get_current_user_id();

        // Try pit_opportunities first (new table)
        $table = $wpdb->prefix . 'pit_opportunities';
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d AND user_id = %d",
            $appearance_id,
            $user_id
        ));

        if ($exists) {
            return true;
        }

        // Fall back to pit_guest_appearances (legacy table)
        $legacy_table = $wpdb->prefix . 'pit_guest_appearances';
        if (current_user_can('manage_options')) {
            return $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$legacy_table} WHERE id = %d",
                $appearance_id
            ));
        }

        return $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$legacy_table} WHERE id = %d AND user_id = %d",
            $appearance_id,
            $user_id
        ));
    }

    /**
     * List all tags for the current user
     */
    public static function list_tags($request) {
        $user_id = get_current_user_id();

        $args = [
            'search' => $request->get_param('search') ?: '',
            'orderby' => $request->get_param('orderby') ?: 'usage_count',
            'order' => $request->get_param('order') ?: 'DESC',
        ];

        $tags = PIT_Appearance_Tag_Repository::list_for_user($user_id, $args);
        $data = array_map([PIT_Appearance_Tag_Repository::class, 'format_tag'], $tags);

        return new WP_REST_Response([
            'data' => $data,
            'meta' => [
                'total' => count($data),
            ],
        ], 200);
    }

    /**
     * Create a new tag
     */
    public static function create_tag($request) {
        $user_id = get_current_user_id();

        $name = $request->get_param('name');
        if (empty($name)) {
            return new WP_Error('missing_name', 'Tag name is required', ['status' => 400]);
        }

        $data = [
            'name' => $name,
            'color' => $request->get_param('color') ?: '#6b7280',
            'description' => $request->get_param('description'),
        ];

        $tag_id = PIT_Appearance_Tag_Repository::create($data, $user_id);

        if (!$tag_id) {
            return new WP_Error('create_failed', 'Failed to create tag', ['status' => 500]);
        }

        $tag = PIT_Appearance_Tag_Repository::get($tag_id, $user_id);

        return new WP_REST_Response([
            'data' => PIT_Appearance_Tag_Repository::format_tag($tag),
            'message' => 'Tag created successfully',
        ], 201);
    }

    /**
     * Update a tag
     */
    public static function update_tag($request) {
        $user_id = get_current_user_id();
        $tag_id = (int) $request->get_param('tag_id');

        $data = [];

        $name = $request->get_param('name');
        if ($name !== null) {
            $data['name'] = $name;
        }

        $color = $request->get_param('color');
        if ($color !== null) {
            $data['color'] = $color;
        }

        $description = $request->get_param('description');
        if ($description !== null) {
            $data['description'] = $description;
        }

        if (empty($data)) {
            return new WP_Error('no_data', 'No valid fields to update', ['status' => 400]);
        }

        $result = PIT_Appearance_Tag_Repository::update($tag_id, $data, $user_id);

        if (!$result) {
            return new WP_Error('update_failed', 'Failed to update tag or tag not found', ['status' => 404]);
        }

        $tag = PIT_Appearance_Tag_Repository::get($tag_id, $user_id);

        return new WP_REST_Response([
            'data' => PIT_Appearance_Tag_Repository::format_tag($tag),
            'message' => 'Tag updated successfully',
        ], 200);
    }

    /**
     * Delete a tag
     */
    public static function delete_tag($request) {
        $user_id = get_current_user_id();
        $tag_id = (int) $request->get_param('tag_id');

        $result = PIT_Appearance_Tag_Repository::delete($tag_id, $user_id);

        if (!$result) {
            return new WP_Error('delete_failed', 'Failed to delete tag or tag not found', ['status' => 404]);
        }

        return new WP_REST_Response([
            'message' => 'Tag deleted successfully',
        ], 200);
    }

    /**
     * Get tags for an appearance
     */
    public static function get_appearance_tags($request) {
        $user_id = get_current_user_id();
        $appearance_id = (int) $request->get_param('appearance_id');

        if (!self::verify_appearance_ownership($appearance_id)) {
            return new WP_Error('not_found', 'Appearance not found', ['status' => 404]);
        }

        $tags = PIT_Appearance_Tag_Repository::get_for_appearance($appearance_id, $user_id);
        $data = array_map([PIT_Appearance_Tag_Repository::class, 'format_tag'], $tags);

        return new WP_REST_Response([
            'data' => $data,
            'meta' => [
                'total' => count($data),
            ],
        ], 200);
    }

    /**
     * Add tag to appearance
     *
     * Can either:
     * - Use existing tag_id
     * - Create new tag with name (and optionally color)
     */
    public static function add_tag_to_appearance($request) {
        $user_id = get_current_user_id();
        $appearance_id = (int) $request->get_param('appearance_id');

        if (!self::verify_appearance_ownership($appearance_id)) {
            return new WP_Error('not_found', 'Appearance not found', ['status' => 404]);
        }

        $tag_id = $request->get_param('tag_id');
        $name = $request->get_param('name');

        // Either tag_id or name is required
        if (!$tag_id && !$name) {
            return new WP_Error('missing_data', 'Either tag_id or name is required', ['status' => 400]);
        }

        // If name provided (not tag_id), create or get the tag
        if (!$tag_id && $name) {
            $data = [
                'name' => $name,
                'color' => $request->get_param('color') ?: '#6b7280',
            ];
            $tag_id = PIT_Appearance_Tag_Repository::create($data, $user_id);

            if (!$tag_id) {
                return new WP_Error('create_failed', 'Failed to create tag', ['status' => 500]);
            }
        }

        $result = PIT_Appearance_Tag_Repository::add_to_appearance($appearance_id, $tag_id, $user_id);

        if (!$result) {
            return new WP_Error('add_failed', 'Failed to add tag to appearance', ['status' => 500]);
        }

        $tag = PIT_Appearance_Tag_Repository::get($tag_id, $user_id);

        // Return all tags for the appearance
        $all_tags = PIT_Appearance_Tag_Repository::get_for_appearance($appearance_id, $user_id);
        $all_data = array_map([PIT_Appearance_Tag_Repository::class, 'format_tag'], $all_tags);

        return new WP_REST_Response([
            'data' => PIT_Appearance_Tag_Repository::format_tag($tag),
            'all_tags' => $all_data,
            'message' => 'Tag added to appearance',
        ], 200);
    }

    /**
     * Remove tag from appearance
     */
    public static function remove_tag_from_appearance($request) {
        $user_id = get_current_user_id();
        $appearance_id = (int) $request->get_param('appearance_id');
        $tag_id = (int) $request->get_param('tag_id');

        if (!self::verify_appearance_ownership($appearance_id)) {
            return new WP_Error('not_found', 'Appearance not found', ['status' => 404]);
        }

        $result = PIT_Appearance_Tag_Repository::remove_from_appearance($appearance_id, $tag_id, $user_id);

        if (!$result) {
            return new WP_Error('remove_failed', 'Failed to remove tag from appearance', ['status' => 500]);
        }

        // Return remaining tags for the appearance
        $all_tags = PIT_Appearance_Tag_Repository::get_for_appearance($appearance_id, $user_id);
        $all_data = array_map([PIT_Appearance_Tag_Repository::class, 'format_tag'], $all_tags);

        return new WP_REST_Response([
            'all_tags' => $all_data,
            'message' => 'Tag removed from appearance',
        ], 200);
    }
}
