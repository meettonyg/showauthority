<?php
/**
 * REST API Controller for Guest Appearances (Interview Tracker CRM)
 * 
 * Provides endpoints for the Vue.js Interview Tracker frontend.
 * 
 * @package Podcast_Influence_Tracker
 * @since 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_REST_Appearances {

    const NAMESPACE = 'guestify/v1';

    /**
     * Register REST routes
     */
    public static function register_routes() {
        // List appearances with filters
        register_rest_route(self::NAMESPACE, '/appearances', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_appearances'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
            'args' => [
                'status' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'priority' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'source' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'search' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'created_after' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'show_archived' => [
                    'type' => 'boolean',
                    'default' => false,
                ],
                'page' => [
                    'type' => 'integer',
                    'default' => 1,
                    'minimum' => 1,
                ],
                'per_page' => [
                    'type' => 'integer',
                    'default' => 50,
                    'minimum' => 1,
                    'maximum' => 100,
                ],
                'user_id' => [
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                    'description' => 'Filter by user ID (admin only)',
                ],
            ],
        ]);

        // Get single appearance
        register_rest_route(self::NAMESPACE, '/appearances/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_appearance'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
            'args' => [
                'id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
            ],
        ]);

        // Create appearance
        register_rest_route(self::NAMESPACE, '/appearances', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_appearance'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Update single appearance
        register_rest_route(self::NAMESPACE, '/appearances/(?P<id>\d+)', [
            'methods' => 'PATCH',
            'callback' => [__CLASS__, 'update_appearance'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
            'args' => [
                'id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
            ],
        ]);

        // Bulk update appearances
        register_rest_route(self::NAMESPACE, '/appearances/bulk', [
            'methods' => 'PATCH',
            'callback' => [__CLASS__, 'bulk_update'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Delete appearance
        register_rest_route(self::NAMESPACE, '/appearances/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [__CLASS__, 'delete_appearance'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
            'args' => [
                'id' => [
                    'type' => 'integer',
                    'required' => true,
                ],
            ],
        ]);
    }

    /**
     * Check if user has permissions
     */
    public static function check_permissions() {
        return is_user_logged_in();
    }

    /**
     * Get appearances list with filters
     */
    public static function get_appearances($request) {
        global $wpdb;

        // Determine which user's data to show
        $user_id = get_current_user_id();
        $filter_user_id = $request->get_param('user_id');
        
        // Only admins can view other users' data
        if ($filter_user_id && current_user_can('manage_options')) {
            $user_id = (int) $filter_user_id;
        }

        $table = $wpdb->prefix . 'pit_guest_appearances';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';

        // Build WHERE clause
        $where = ['a.user_id = %d'];
        $params = [$user_id];

        // Status filter
        if ($request->get_param('status')) {
            $where[] = 'a.status = %s';
            $params[] = $request->get_param('status');
        }

        // Priority filter
        if ($request->get_param('priority')) {
            $where[] = 'a.priority = %s';
            $params[] = $request->get_param('priority');
        }

        // Source filter
        if ($request->get_param('source')) {
            $where[] = 'a.source = %s';
            $params[] = $request->get_param('source');
        }

        // Search filter (podcast title or episode title)
        if ($request->get_param('search')) {
            $search = '%' . $wpdb->esc_like($request->get_param('search')) . '%';
            $where[] = '(p.title LIKE %s OR a.episode_title LIKE %s)';
            $params[] = $search;
            $params[] = $search;
        }

        // Date filter
        if ($request->get_param('created_after')) {
            $where[] = 'a.created_at >= %s';
            $params[] = $request->get_param('created_after');
        }

        // Archive filter
        if (!$request->get_param('show_archived')) {
            $where[] = 'a.is_archived = 0';
        }

        $where_sql = implode(' AND ', $where);

        // Pagination
        $page = max(1, (int) $request->get_param('page'));
        $per_page = min(100, max(1, (int) $request->get_param('per_page')));
        $offset = ($page - 1) * $per_page;

        // Get total count
        $count_sql = "SELECT COUNT(*) FROM {$table} a 
                      LEFT JOIN {$podcasts_table} p ON a.podcast_id = p.id 
                      WHERE {$where_sql}";
        $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));

        // Get appearances
        $sql = "SELECT a.*, p.title as podcast_name, p.rss_feed_url as rss_url, p.artwork_url as podcast_image
                FROM {$table} a
                LEFT JOIN {$podcasts_table} p ON a.podcast_id = p.id
                WHERE {$where_sql}
                ORDER BY a.updated_at DESC
                LIMIT %d OFFSET %d";

        $params[] = $per_page;
        $params[] = $offset;

        $appearances = $wpdb->get_results($wpdb->prepare($sql, $params));

        // Format response
        $data = array_map([__CLASS__, 'format_appearance'], $appearances);

        return new WP_REST_Response([
            'data' => $data,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => ceil($total / $per_page),
            ],
        ], 200);
    }

    /**
     * Get single appearance
     */
    public static function get_appearance($request) {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $user_id = get_current_user_id();

        $table = $wpdb->prefix . 'pit_guest_appearances';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';
        $offers_table = $wpdb->prefix . 'pit_appearance_offers';

        // Include all podcast metadata fields in the query
        $select_fields = "a.*, 
                          p.title as podcast_name, 
                          p.rss_feed_url as rss_url, 
                          p.artwork_url as podcast_image,
                          p.description as description,
                          p.author as host_name,
                          p.email as host_email,
                          p.website_url as website,
                          p.language,
                          p.category,
                          p.booking_link, 
                          p.recording_link,
                          p.episode_count,
                          p.frequency,
                          p.average_duration,
                          p.founded_date,
                          p.last_episode_date,
                          p.explicit_rating,
                          p.copyright,
                          p.metadata_updated_at";

        // Admins can view any appearance
        if (current_user_can('manage_options')) {
            $appearance = $wpdb->get_row($wpdb->prepare(
                "SELECT {$select_fields}
                 FROM {$table} a
                 LEFT JOIN {$podcasts_table} p ON a.podcast_id = p.id
                 WHERE a.id = %d",
                $id
            ));
        } else {
            $appearance = $wpdb->get_row($wpdb->prepare(
                "SELECT {$select_fields}
                 FROM {$table} a
                 LEFT JOIN {$podcasts_table} p ON a.podcast_id = p.id
                 WHERE a.id = %d AND a.user_id = %d",
                $id, $user_id
            ));
        }

        if (!$appearance) {
            return new WP_Error('not_found', 'Appearance not found', ['status' => 404]);
        }

        // Get offers
        $offers = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$offers_table} WHERE appearance_id = %d",
            $id
        ));

        $data = self::format_appearance($appearance);
        $data['offers'] = $offers;

        return new WP_REST_Response($data, 200);
    }

    /**
     * Create new appearance
     */
    public static function create_appearance($request) {
        global $wpdb;

        $user_id = get_current_user_id();
        $table = $wpdb->prefix . 'pit_guest_appearances';

        $data = [
            'user_id' => $user_id,
            'podcast_id' => (int) $request->get_param('podcast_id'),
            'guest_id' => (int) $request->get_param('guest_id') ?: 0,
            'status' => sanitize_text_field($request->get_param('status')) ?: 'potential',
            'priority' => sanitize_text_field($request->get_param('priority')) ?: 'medium',
            'source' => sanitize_text_field($request->get_param('source')),
            'episode_title' => sanitize_text_field($request->get_param('episode_title')),
            'episode_date' => sanitize_text_field($request->get_param('episode_date')),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        $result = $wpdb->insert($table, $data);

        if ($result === false) {
            return new WP_Error('insert_failed', 'Failed to create appearance', ['status' => 500]);
        }

        $id = $wpdb->insert_id;

        return new WP_REST_Response([
            'id' => $id,
            'message' => 'Appearance created successfully',
        ], 201);
    }

    /**
     * Update single appearance
     */
    public static function update_appearance($request) {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $user_id = get_current_user_id();

        $table = $wpdb->prefix . 'pit_guest_appearances';

        // Verify ownership (admins can update any)
        if (current_user_can('manage_options')) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE id = %d",
                $id
            ));
        } else {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE id = %d AND user_id = %d",
                $id, $user_id
            ));
        }

        if (!$exists) {
            return new WP_Error('not_found', 'Appearance not found', ['status' => 404]);
        }

        // Build update data
        $allowed_fields = ['status', 'priority', 'source', 'episode_title', 'episode_date', 'is_archived', 'guest_profile_id', 'record_date', 'air_date', 'promotion_date'];
        $data = [];

        foreach ($allowed_fields as $field) {
            $value = $request->get_param($field);
            if ($value !== null) {
                if ($field === 'guest_profile_id') {
                    $data[$field] = intval($value);
                } else {
                    $data[$field] = is_string($value) ? sanitize_text_field($value) : $value;
                }
            }
        }

        if (empty($data)) {
            return new WP_Error('no_data', 'No valid fields to update', ['status' => 400]);
        }

        $data['updated_at'] = current_time('mysql');

        $result = $wpdb->update($table, $data, ['id' => $id]);

        if ($result === false) {
            return new WP_Error('update_failed', 'Failed to update appearance', ['status' => 500]);
        }

        return new WP_REST_Response([
            'id' => $id,
            'message' => 'Appearance updated successfully',
            'updated_fields' => array_keys($data),
        ], 200);
    }

    /**
     * Bulk update appearances
     */
    public static function bulk_update($request) {
        global $wpdb;

        $ids = $request->get_param('ids');
        $updates = $request->get_param('updates');

        if (!is_array($ids) || empty($ids)) {
            return new WP_Error('invalid_ids', 'IDs must be a non-empty array', ['status' => 400]);
        }

        if (!is_array($updates) || empty($updates)) {
            return new WP_Error('invalid_updates', 'Updates must be a non-empty array', ['status' => 400]);
        }

        $user_id = get_current_user_id();
        $table = $wpdb->prefix . 'pit_guest_appearances';

        // Sanitize IDs
        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // Verify ownership of all IDs
        $owned_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE id IN ({$placeholders}) AND user_id = %d",
            array_merge($ids, [$user_id])
        ));

        if ($owned_count != count($ids)) {
            return new WP_Error('not_authorized', 'Not authorized to update all specified appearances', ['status' => 403]);
        }

        // Build update data
        $allowed_fields = ['status', 'priority', 'source', 'is_archived', 'guest_profile_id', 'record_date', 'air_date', 'promotion_date'];
        $data = [];

        foreach ($allowed_fields as $field) {
            if (isset($updates[$field])) {
                $data[$field] = is_string($updates[$field]) ? sanitize_text_field($updates[$field]) : $updates[$field];
            }
        }

        if (empty($data)) {
            return new WP_Error('no_data', 'No valid fields to update', ['status' => 400]);
        }

        $data['updated_at'] = current_time('mysql');

        // Build SET clause
        $set_parts = [];
        $set_values = [];
        foreach ($data as $key => $value) {
            $set_parts[] = "{$key} = %s";
            $set_values[] = $value;
        }
        $set_sql = implode(', ', $set_parts);

        // Execute update
        $sql = "UPDATE {$table} SET {$set_sql} WHERE id IN ({$placeholders}) AND user_id = %d";
        $params = array_merge($set_values, $ids, [$user_id]);

        $result = $wpdb->query($wpdb->prepare($sql, $params));

        return new WP_REST_Response([
            'updated_count' => $result,
            'message' => "Updated {$result} appearances",
        ], 200);
    }

    /**
     * Delete appearance
     */
    public static function delete_appearance($request) {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $user_id = get_current_user_id();

        $table = $wpdb->prefix . 'pit_guest_appearances';

        // Verify ownership
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d AND user_id = %d",
            $id, $user_id
        ));

        if (!$exists) {
            return new WP_Error('not_found', 'Appearance not found', ['status' => 404]);
        }

        $result = $wpdb->delete($table, ['id' => $id]);

        if ($result === false) {
            return new WP_Error('delete_failed', 'Failed to delete appearance', ['status' => 500]);
        }

        return new WP_REST_Response([
            'message' => 'Appearance deleted successfully',
        ], 200);
    }

    /**
     * Format appearance for API response
     */
    private static function format_appearance($row) {
        $guest_profile_id = isset($row->guest_profile_id) ? (int) $row->guest_profile_id : 0;
        $guest_profile_name = '';
        $guest_profile_link = '';

        if ($guest_profile_id) {
            $guest_profile_name = get_the_title($guest_profile_id);
            $guest_profile_link = get_permalink($guest_profile_id);
        }

        return [
            'id' => (int) $row->id,
            'podcast_id' => (int) $row->podcast_id,
            'podcast_name' => $row->podcast_name ?? '',
            'podcast_image' => $row->podcast_image ?? '',
            'rss_url' => $row->rss_url ?? '',
            'guest_id' => (int) $row->guest_id,
            'guest_profile_id' => $guest_profile_id,
            'guest_profile_name' => $guest_profile_name,
            'guest_profile_link' => $guest_profile_link,
            'status' => $row->status ?? 'potential',
            'priority' => $row->priority ?? 'medium',
            'source' => $row->source ?? '',
            'episode_title' => $row->episode_title ?? '',
            'episode_number' => $row->episode_number ?? '',
            'episode_date' => $row->episode_date ?? '',
            'episode_url' => $row->episode_url ?? '',
            'interview_topic' => $row->interview_topic ?? '',
            'audience' => $row->audience ?? '',
            'commission' => $row->commission ?? '',
            'record_date' => $row->record_date ?? null,
            'air_date' => $row->air_date ?? null,
            'promotion_date' => $row->promotion_date ?? null,
            'is_archived' => (bool) $row->is_archived,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
            'booking_link' => $row->booking_link ?? '',
            'recording_link' => $row->recording_link ?? '',
            // Podcast metadata fields
            'description' => $row->description ?? '',
            'host_name' => $row->host_name ?? '',
            'host_email' => $row->host_email ?? '',
            'website' => $row->website ?? '',
            'language' => $row->language ?? 'English',
            'category' => $row->category ?? '',
            'categories' => !empty($row->category) ? array_map('trim', explode(',', $row->category)) : [],
            'episode_count' => $row->episode_count ? (int) $row->episode_count : null,
            'frequency' => $row->frequency ?? null,
            'average_duration' => $row->average_duration ? (int) $row->average_duration : null,
            'founded_date' => $row->founded_date ?? null,
            'last_episode_date' => $row->last_episode_date ?? null,
            'content_rating' => $row->explicit_rating ?? 'clean',  // Mapped for Vue template compatibility
            'explicit_rating' => $row->explicit_rating ?? 'clean',
            'copyright' => $row->copyright ?? '',
            'metadata_updated_at' => $row->metadata_updated_at ?? null,
        ];
    }
}
