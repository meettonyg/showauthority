<?php
/**
 * Appearance Tag Repository
 *
 * Handles database operations for appearance tags and tag-appearance relationships.
 * Tags are user-owned and can be applied to multiple appearances.
 *
 * @package PodcastInfluenceTracker
 * @subpackage Guests
 * @since 3.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Appearance_Tag_Repository {

    /**
     * Get tag by ID
     *
     * @param int $tag_id Tag ID
     * @param int|null $user_id User ID for ownership verification
     * @return object|null
     */
    public static function get($tag_id, $user_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_appearance_tags';

        $sql = "SELECT * FROM $table WHERE id = %d";
        $params = [$tag_id];

        if ($user_id) {
            $sql .= " AND user_id = %d";
            $params[] = $user_id;
        }

        return $wpdb->get_row($wpdb->prepare($sql, $params));
    }

    /**
     * Get tag by slug for a user
     *
     * @param string $slug Tag slug
     * @param int $user_id User ID
     * @return object|null
     */
    public static function get_by_slug($slug, $user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_appearance_tags';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE slug = %s AND user_id = %d",
            $slug,
            $user_id
        ));
    }

    /**
     * Create a new tag
     *
     * @param array $data Tag data (name, color, description)
     * @param int $user_id User ID
     * @return int|false Tag ID or false on failure
     */
    public static function create($data, $user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_appearance_tags';

        $name = sanitize_text_field($data['name']);
        $slug = sanitize_title($name);

        // Check for duplicate slug
        $existing = self::get_by_slug($slug, $user_id);
        if ($existing) {
            return $existing->id;
        }

        $insert_data = [
            'user_id' => $user_id,
            'name' => $name,
            'slug' => $slug,
            'color' => isset($data['color']) ? sanitize_hex_color($data['color']) : '#6b7280',
            'description' => isset($data['description']) ? sanitize_textarea_field($data['description']) : null,
            'usage_count' => 0,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        $result = $wpdb->insert($table, $insert_data);

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Update a tag
     *
     * @param int $tag_id Tag ID
     * @param array $data Fields to update
     * @param int $user_id User ID for ownership verification
     * @return bool
     */
    public static function update($tag_id, $data, $user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_appearance_tags';

        // Verify ownership
        $tag = self::get($tag_id, $user_id);
        if (!$tag) {
            return false;
        }

        $update_data = [];

        if (isset($data['name'])) {
            $update_data['name'] = sanitize_text_field($data['name']);
            $update_data['slug'] = sanitize_title($data['name']);
        }

        if (isset($data['color'])) {
            $update_data['color'] = sanitize_hex_color($data['color']);
        }

        if (isset($data['description'])) {
            $update_data['description'] = sanitize_textarea_field($data['description']);
        }

        if (empty($update_data)) {
            return true;
        }

        $update_data['updated_at'] = current_time('mysql');

        return $wpdb->update($table, $update_data, ['id' => $tag_id]) !== false;
    }

    /**
     * Delete a tag
     *
     * @param int $tag_id Tag ID
     * @param int $user_id User ID for ownership verification
     * @return bool
     */
    public static function delete($tag_id, $user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_appearance_tags';
        $links_table = $wpdb->prefix . 'pit_appearance_tag_links';

        // Verify ownership
        $tag = self::get($tag_id, $user_id);
        if (!$tag) {
            return false;
        }

        // Remove all tag links first
        $wpdb->delete($links_table, ['tag_id' => $tag_id]);

        // Delete the tag
        return $wpdb->delete($table, ['id' => $tag_id]) !== false;
    }

    /**
     * List all tags for a user
     *
     * @param int $user_id User ID
     * @param array $args Query arguments
     * @return array
     */
    public static function list_for_user($user_id, $args = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_appearance_tags';

        $defaults = [
            'orderby' => 'usage_count',
            'order' => 'DESC',
            'search' => '',
        ];

        $args = wp_parse_args($args, $defaults);

        $where = 'user_id = %d';
        $params = [$user_id];

        if (!empty($args['search'])) {
            $where .= ' AND name LIKE %s';
            $params[] = '%' . $wpdb->esc_like($args['search']) . '%';
        }

        $allowed_orderby = ['usage_count', 'name', 'created_at'];
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'usage_count';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $sql = "SELECT * FROM $table WHERE $where ORDER BY $orderby $order";

        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    /**
     * Get tags for an appearance
     *
     * @param int $appearance_id Appearance ID
     * @param int $user_id User ID for ownership verification
     * @return array
     */
    public static function get_for_appearance($appearance_id, $user_id) {
        global $wpdb;
        $tags_table = $wpdb->prefix . 'pit_appearance_tags';
        $links_table = $wpdb->prefix . 'pit_appearance_tag_links';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT t.*, l.created_at as linked_at
            FROM $tags_table t
            INNER JOIN $links_table l ON t.id = l.tag_id
            WHERE l.appearance_id = %d AND t.user_id = %d
            ORDER BY t.name ASC",
            $appearance_id,
            $user_id
        ));
    }

    /**
     * Add tag to appearance
     *
     * @param int $appearance_id Appearance ID
     * @param int $tag_id Tag ID
     * @param int $user_id User ID for ownership verification
     * @return bool
     */
    public static function add_to_appearance($appearance_id, $tag_id, $user_id) {
        global $wpdb;
        $links_table = $wpdb->prefix . 'pit_appearance_tag_links';
        $tags_table = $wpdb->prefix . 'pit_appearance_tags';

        // Verify tag ownership
        $tag = self::get($tag_id, $user_id);
        if (!$tag) {
            return false;
        }

        // Check if already linked
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $links_table WHERE appearance_id = %d AND tag_id = %d",
            $appearance_id,
            $tag_id
        ));

        if ($existing) {
            return true;
        }

        // Create link
        $result = $wpdb->insert($links_table, [
            'appearance_id' => $appearance_id,
            'tag_id' => $tag_id,
            'created_at' => current_time('mysql'),
        ]);

        if ($result) {
            // Increment usage count
            $wpdb->query($wpdb->prepare(
                "UPDATE $tags_table SET usage_count = usage_count + 1 WHERE id = %d",
                $tag_id
            ));
        }

        return $result !== false;
    }

    /**
     * Remove tag from appearance
     *
     * @param int $appearance_id Appearance ID
     * @param int $tag_id Tag ID
     * @param int $user_id User ID for ownership verification
     * @return bool
     */
    public static function remove_from_appearance($appearance_id, $tag_id, $user_id) {
        global $wpdb;
        $links_table = $wpdb->prefix . 'pit_appearance_tag_links';
        $tags_table = $wpdb->prefix . 'pit_appearance_tags';

        // Verify tag ownership
        $tag = self::get($tag_id, $user_id);
        if (!$tag) {
            return false;
        }

        $result = $wpdb->delete($links_table, [
            'appearance_id' => $appearance_id,
            'tag_id' => $tag_id,
        ]);

        if ($result) {
            // Decrement usage count
            $wpdb->query($wpdb->prepare(
                "UPDATE $tags_table SET usage_count = GREATEST(usage_count - 1, 0) WHERE id = %d",
                $tag_id
            ));
        }

        return $result !== false;
    }

    /**
     * Get tags for multiple appearances (batch query)
     *
     * @param array $appearance_ids Array of appearance IDs
     * @param int $user_id User ID
     * @return array Tags grouped by appearance_id
     */
    public static function get_for_appearances($appearance_ids, $user_id) {
        global $wpdb;
        $tags_table = $wpdb->prefix . 'pit_appearance_tags';
        $links_table = $wpdb->prefix . 'pit_appearance_tag_links';

        if (empty($appearance_ids)) {
            return [];
        }

        $ids = array_map('intval', $appearance_ids);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT t.*, l.appearance_id
            FROM $tags_table t
            INNER JOIN $links_table l ON t.id = l.tag_id
            WHERE l.appearance_id IN ($placeholders) AND t.user_id = %d
            ORDER BY l.appearance_id, t.name ASC",
            array_merge($ids, [$user_id])
        ));

        // Group by appearance_id
        $grouped = [];
        foreach ($results as $tag) {
            $appearance_id = $tag->appearance_id;
            unset($tag->appearance_id);
            if (!isset($grouped[$appearance_id])) {
                $grouped[$appearance_id] = [];
            }
            $grouped[$appearance_id][] = $tag;
        }

        return $grouped;
    }

    /**
     * Get appearances by tag
     *
     * @param int $tag_id Tag ID
     * @param int $user_id User ID
     * @return array Array of appearance IDs
     */
    public static function get_appearances_by_tag($tag_id, $user_id) {
        global $wpdb;
        $links_table = $wpdb->prefix . 'pit_appearance_tag_links';
        $tags_table = $wpdb->prefix . 'pit_appearance_tags';

        // Verify tag ownership
        $tag = self::get($tag_id, $user_id);
        if (!$tag) {
            return [];
        }

        return $wpdb->get_col($wpdb->prepare(
            "SELECT appearance_id FROM $links_table WHERE tag_id = %d",
            $tag_id
        ));
    }

    /**
     * Format tag for API response
     *
     * @param object $tag Tag object
     * @return array
     */
    public static function format_tag($tag) {
        return [
            'id' => (int) $tag->id,
            'name' => $tag->name,
            'slug' => $tag->slug,
            'color' => $tag->color,
            'description' => $tag->description,
            'usage_count' => (int) $tag->usage_count,
            'created_at' => $tag->created_at,
            'updated_at' => $tag->updated_at,
        ];
    }
}
