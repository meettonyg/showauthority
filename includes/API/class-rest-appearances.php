<?php
/**
 * REST API Controller for Opportunities (Interview Tracker CRM)
 * 
 * Provides endpoints for the Vue.js Interview Tracker frontend.
 * Uses pit_opportunities table.
 * 
 * @package Podcast_Influence_Tracker
 * @since 4.0.0
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
        // List opportunities
        register_rest_route(self::NAMESPACE, '/appearances', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_appearances'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
            'args' => [
                'status' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'priority' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'source' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'search' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'show_archived' => ['type' => 'boolean', 'default' => false],
                'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'per_page' => ['type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100],
                'user_id' => ['type' => 'integer', 'sanitize_callback' => 'absint'],
            ],
        ]);

        // Get single opportunity
        register_rest_route(self::NAMESPACE, '/appearances/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_appearance'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Create opportunity
        register_rest_route(self::NAMESPACE, '/appearances', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_appearance'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Update opportunity
        register_rest_route(self::NAMESPACE, '/appearances/(?P<id>\d+)', [
            'methods' => 'PATCH',
            'callback' => [__CLASS__, 'update_appearance'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Bulk update
        register_rest_route(self::NAMESPACE, '/appearances/bulk', [
            'methods' => 'PATCH',
            'callback' => [__CLASS__, 'bulk_update'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Delete opportunity
        register_rest_route(self::NAMESPACE, '/appearances/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [__CLASS__, 'delete_appearance'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Get personalization variables
        register_rest_route(self::NAMESPACE, '/appearances/(?P<id>\d+)/variables', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_appearance_variables'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);
    }

    public static function check_permissions() {
        return is_user_logged_in();
    }

    /**
     * Get opportunities list
     */
    public static function get_appearances($request) {
        global $wpdb;

        $user_id = get_current_user_id();
        
        // Admins can view other users' data
        if ($request->get_param('user_id') && current_user_can('manage_options')) {
            $user_id = (int) $request->get_param('user_id');
        }

        $table = $wpdb->prefix . 'pit_opportunities';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';

        // Build WHERE clause
        $where = ['o.user_id = %d'];
        $params = [$user_id];

        if ($status = $request->get_param('status')) {
            $where[] = 'o.status = %s';
            $params[] = $status;
        }

        if ($priority = $request->get_param('priority')) {
            $where[] = 'o.priority = %s';
            $params[] = $priority;
        }

        if ($source = $request->get_param('source')) {
            $where[] = 'o.source = %s';
            $params[] = $source;
        }

        if ($search = $request->get_param('search')) {
            $where[] = 'p.title LIKE %s';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        if (!$request->get_param('show_archived')) {
            $where[] = '(o.is_archived = 0 OR o.is_archived IS NULL)';
        }

        $where_sql = implode(' AND ', $where);

        // Pagination
        $page = max(1, (int) $request->get_param('page'));
        $per_page = min(100, max(1, (int) $request->get_param('per_page')));
        $offset = ($page - 1) * $per_page;

        // Count
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} o 
             LEFT JOIN {$podcasts_table} p ON o.podcast_id = p.id 
             WHERE {$where_sql}",
            $params
        ));

        // Get data
        $params[] = $per_page;
        $params[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT o.*, 
                    p.title as podcast_name, 
                    p.artwork_url as podcast_image,
                    p.rss_feed_url as rss_url
             FROM {$table} o
             LEFT JOIN {$podcasts_table} p ON o.podcast_id = p.id
             WHERE {$where_sql}
             ORDER BY o.updated_at DESC
             LIMIT %d OFFSET %d",
            $params
        ));

        return new WP_REST_Response([
            'data' => array_map([__CLASS__, 'format_row'], $rows ?: []),
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => ceil($total / $per_page),
            ],
        ], 200);
    }

    /**
     * Get single opportunity
     */
    public static function get_appearance($request) {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $user_id = get_current_user_id();

        $table = $wpdb->prefix . 'pit_opportunities';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';

        $sql = "SELECT o.*, 
                       p.title as podcast_name, 
                       p.artwork_url as podcast_image,
                       p.rss_feed_url as rss_url,
                       p.description,
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
                       p.metadata_updated_at
                FROM {$table} o
                LEFT JOIN {$podcasts_table} p ON o.podcast_id = p.id
                WHERE o.id = %d";

        // Non-admins can only see their own
        if (!current_user_can('manage_options')) {
            $sql .= " AND o.user_id = %d";
            $row = $wpdb->get_row($wpdb->prepare($sql, $id, $user_id));
        } else {
            $row = $wpdb->get_row($wpdb->prepare($sql, $id));
        }

        if (!$row) {
            return new WP_Error('not_found', 'Opportunity not found', ['status' => 404]);
        }

        return new WP_REST_Response(self::format_row($row, true), 200);
    }

    /**
     * Create opportunity
     */
    public static function create_appearance($request) {
        global $wpdb;

        $table = $wpdb->prefix . 'pit_opportunities';

        $data = [
            'user_id' => get_current_user_id(),
            'podcast_id' => (int) $request->get_param('podcast_id'),
            'guest_id' => $request->get_param('guest_id') ? (int) $request->get_param('guest_id') : null,
            'status' => sanitize_text_field($request->get_param('status')) ?: 'potential',
            'priority' => sanitize_text_field($request->get_param('priority')) ?: 'medium',
            'source' => sanitize_text_field($request->get_param('source')),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        if (!$wpdb->insert($table, $data)) {
            return new WP_Error('insert_failed', 'Failed to create opportunity', ['status' => 500]);
        }

        return new WP_REST_Response([
            'id' => $wpdb->insert_id,
            'message' => 'Opportunity created successfully',
        ], 201);
    }

    /**
     * Update opportunity
     */
    public static function update_appearance($request) {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $user_id = get_current_user_id();
        $table = $wpdb->prefix . 'pit_opportunities';

        // Verify ownership
        $where_check = current_user_can('manage_options') 
            ? $wpdb->prepare("SELECT id FROM {$table} WHERE id = %d", $id)
            : $wpdb->prepare("SELECT id FROM {$table} WHERE id = %d AND user_id = %d", $id, $user_id);

        if (!$wpdb->get_var($where_check)) {
            return new WP_Error('not_found', 'Opportunity not found', ['status' => 404]);
        }

        // Allowed fields
        $allowed = ['status', 'priority', 'source', 'is_archived', 'guest_profile_id', 
                    'record_date', 'air_date', 'promotion_date', 'notes', 'internal_notes',
                    'estimated_value', 'actual_value', 'audience', 'commission'];
        
        $data = [];
        foreach ($allowed as $field) {
            $value = $request->get_param($field);
            if ($value !== null) {
                $data[$field] = is_string($value) ? sanitize_text_field($value) : $value;
            }
        }

        if (empty($data)) {
            return new WP_Error('no_data', 'No valid fields to update', ['status' => 400]);
        }

        $data['updated_at'] = current_time('mysql');
        $wpdb->update($table, $data, ['id' => $id]);

        return new WP_REST_Response([
            'id' => $id,
            'message' => 'Opportunity updated successfully',
            'updated_fields' => array_keys($data),
        ], 200);
    }

    /**
     * Bulk update
     */
    public static function bulk_update($request) {
        global $wpdb;

        $ids = $request->get_param('ids');
        $updates = $request->get_param('updates');

        if (!is_array($ids) || empty($ids) || !is_array($updates) || empty($updates)) {
            return new WP_Error('invalid_data', 'Invalid IDs or updates', ['status' => 400]);
        }

        $user_id = get_current_user_id();
        $table = $wpdb->prefix . 'pit_opportunities';
        $ids = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // Verify ownership
        $owned = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE id IN ({$placeholders}) AND user_id = %d",
            array_merge($ids, [$user_id])
        ));

        if ($owned != count($ids)) {
            return new WP_Error('not_authorized', 'Not authorized', ['status' => 403]);
        }

        // Build update
        $allowed = ['status', 'priority', 'source', 'is_archived', 'guest_profile_id'];
        $data = [];
        foreach ($allowed as $field) {
            if (isset($updates[$field])) {
                $data[$field] = is_string($updates[$field]) ? sanitize_text_field($updates[$field]) : $updates[$field];
            }
        }

        if (empty($data)) {
            return new WP_Error('no_data', 'No valid fields', ['status' => 400]);
        }

        $data['updated_at'] = current_time('mysql');

        $set_parts = [];
        $set_values = [];
        foreach ($data as $key => $value) {
            $set_parts[] = "{$key} = %s";
            $set_values[] = $value;
        }

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET " . implode(', ', $set_parts) . " WHERE id IN ({$placeholders}) AND user_id = %d",
            array_merge($set_values, $ids, [$user_id])
        ));

        return new WP_REST_Response([
            'updated_count' => $result,
            'message' => "Updated {$result} opportunities",
        ], 200);
    }

    /**
     * Delete opportunity
     */
    public static function delete_appearance($request) {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $user_id = get_current_user_id();
        $table = $wpdb->prefix . 'pit_opportunities';

        // Verify ownership
        $where_check = current_user_can('manage_options')
            ? $wpdb->prepare("SELECT id FROM {$table} WHERE id = %d", $id)
            : $wpdb->prepare("SELECT id FROM {$table} WHERE id = %d AND user_id = %d", $id, $user_id);

        if (!$wpdb->get_var($where_check)) {
            return new WP_Error('not_found', 'Opportunity not found', ['status' => 404]);
        }

        $wpdb->delete($table, ['id' => $id]);

        return new WP_REST_Response(['message' => 'Opportunity deleted'], 200);
    }

    /**
     * Get personalization variables for an appearance
     * Returns template variables with their actual populated values
     */
    public static function get_appearance_variables($request) {
        global $wpdb;

        $id = (int) $request->get_param('id');
        $user_id = get_current_user_id();

        $table = $wpdb->prefix . 'pit_opportunities';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';

        $sql = "SELECT o.*,
                       p.title as podcast_name,
                       p.author as host_name,
                       p.email as host_email,
                       p.website_url as website,
                       p.description,
                       p.category,
                       p.language,
                       p.episode_count,
                       p.frequency,
                       p.average_duration
                FROM {$table} o
                LEFT JOIN {$podcasts_table} p ON o.podcast_id = p.id
                WHERE o.id = %d";

        // Non-admins can only see their own
        if (!current_user_can('manage_options')) {
            $sql .= " AND o.user_id = %d";
            $row = $wpdb->get_row($wpdb->prepare($sql, $id, $user_id));
        } else {
            $row = $wpdb->get_row($wpdb->prepare($sql, $id));
        }

        if (!$row) {
            return new WP_Error('not_found', 'Appearance not found', ['status' => 404]);
        }

        // Try to get variables from Guestify Profile Variables if available
        if (class_exists('Guestify_Profile_Variables')) {
            try {
                $variables = Guestify_Profile_Variables::get_variables_json($row->guest_profile_id ?? 0, $row);
                if (!empty($variables)) {
                    return new WP_REST_Response([
                        'success' => true,
                        'data' => $variables,
                    ], 200);
                }
            } catch (Exception $e) {
                error_log('Failed to get variables from Guestify Profile: ' . $e->getMessage());
                // Fall through to local variables
            }
        }

        // Build local variables from podcast/guest data
        $variables = self::build_local_variables($row);

        return new WP_REST_Response([
            'success' => true,
            'data' => $variables,
        ], 200);
    }

    /**
     * Build personalization variables from local data
     */
    private static function build_local_variables($row) {
        $user = wp_get_current_user();

        // Get guest profile data if available
        $guest_name = '';
        $guest_title = '';
        $guest_bio = '';
        $guest_email = '';

        if (!empty($row->guest_profile_id)) {
            $profile_id = (int) $row->guest_profile_id;
            $guest_name = get_the_title($profile_id);
            $guest_title = get_post_meta($profile_id, '_guestify_title', true) ?: '';
            $guest_bio = get_post_meta($profile_id, '_guestify_bio', true) ?: '';
            $guest_email = get_post_meta($profile_id, '_guestify_email', true) ?: '';
        }

        // Fallback to user data if no guest profile
        if (empty($guest_name)) {
            $guest_name = $user->display_name ?: '';
        }
        if (empty($guest_email)) {
            $guest_email = $user->user_email ?: '';
        }

        $categories = [
            [
                'name' => 'Podcast Information',
                'variables' => [
                    [
                        'tag' => '{{podcast_name}}',
                        'label' => 'Podcast Name',
                        'value' => $row->podcast_name ?? '',
                    ],
                    [
                        'tag' => '{{host_name}}',
                        'label' => 'Host Name',
                        'value' => $row->host_name ?? '',
                    ],
                    [
                        'tag' => '{{host_email}}',
                        'label' => 'Host Email',
                        'value' => $row->host_email ?? '',
                    ],
                    [
                        'tag' => '{{podcast_website}}',
                        'label' => 'Podcast Website',
                        'value' => $row->website ?? '',
                    ],
                    [
                        'tag' => '{{podcast_category}}',
                        'label' => 'Category',
                        'value' => $row->category ?? '',
                    ],
                    [
                        'tag' => '{{podcast_description}}',
                        'label' => 'Description',
                        'value' => $row->description ?? '',
                    ],
                    [
                        'tag' => '{{episode_count}}',
                        'label' => 'Episode Count',
                        'value' => $row->episode_count ? (string) $row->episode_count : '',
                    ],
                ],
            ],
            [
                'name' => 'Guest Information',
                'variables' => [
                    [
                        'tag' => '{{guest_name}}',
                        'label' => 'Guest Name',
                        'value' => $guest_name,
                    ],
                    [
                        'tag' => '{{guest_title}}',
                        'label' => 'Guest Title',
                        'value' => $guest_title,
                    ],
                    [
                        'tag' => '{{guest_email}}',
                        'label' => 'Guest Email',
                        'value' => $guest_email,
                    ],
                    [
                        'tag' => '{{guest_bio}}',
                        'label' => 'Guest Bio',
                        'value' => $guest_bio,
                    ],
                    [
                        'tag' => '{{sender_name}}',
                        'label' => 'Sender Name',
                        'value' => $user->display_name ?: '',
                    ],
                    [
                        'tag' => '{{sender_email}}',
                        'label' => 'Sender Email',
                        'value' => $user->user_email ?: '',
                    ],
                ],
            ],
            [
                'name' => 'System',
                'variables' => [
                    [
                        'tag' => '{{current_date}}',
                        'label' => 'Current Date',
                        'value' => date_i18n(get_option('date_format')),
                    ],
                    [
                        'tag' => '{{current_year}}',
                        'label' => 'Current Year',
                        'value' => date('Y'),
                    ],
                ],
            ],
        ];

        return ['categories' => $categories];
    }

    /**
     * Format row for API response
     */
    private static function format_row($row, $full = false) {
        $guest_profile_id = isset($row->guest_profile_id) ? (int) $row->guest_profile_id : 0;

        $data = [
            'id' => (int) $row->id,
            'podcast_id' => (int) $row->podcast_id,
            'podcast_name' => $row->podcast_name ?? '',
            'podcast_image' => $row->podcast_image ?? '',
            'rss_url' => $row->rss_url ?? '',
            'guest_id' => isset($row->guest_id) ? (int) $row->guest_id : 0,
            'guest_profile_id' => $guest_profile_id,
            'guest_profile_name' => $guest_profile_id ? get_the_title($guest_profile_id) : '',
            'guest_profile_link' => $guest_profile_id ? get_permalink($guest_profile_id) : '',
            'status' => $row->status ?? 'potential',
            'priority' => $row->priority ?? 'medium',
            'source' => $row->source ?? '',
            'record_date' => $row->record_date ?? null,
            'air_date' => $row->air_date ?? null,
            'promotion_date' => $row->promotion_date ?? null,
            'is_archived' => (bool) ($row->is_archived ?? false),
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];

        // Add full details for single view
        if ($full) {
            $data = array_merge($data, [
                'description' => $row->description ?? '',
                'host_name' => $row->host_name ?? '',
                'host_email' => $row->host_email ?? '',
                'website' => $row->website ?? '',
                'language' => $row->language ?? 'English',
                'category' => $row->category ?? '',
                'categories' => !empty($row->category) ? array_map('trim', explode(',', $row->category)) : [],
                'booking_link' => $row->booking_link ?? '',
                'recording_link' => $row->recording_link ?? '',
                'episode_count' => isset($row->episode_count) ? (int) $row->episode_count : null,
                'frequency' => $row->frequency ?? null,
                'average_duration' => isset($row->average_duration) ? (int) $row->average_duration : null,
                'founded_date' => $row->founded_date ?? null,
                'last_episode_date' => $row->last_episode_date ?? null,
                'content_rating' => $row->explicit_rating ?? 'clean',
                'explicit_rating' => $row->explicit_rating ?? 'clean',
                'copyright' => $row->copyright ?? '',
                'metadata_updated_at' => $row->metadata_updated_at ?? null,
                'notes' => $row->notes ?? '',
                'internal_notes' => $row->internal_notes ?? '',
                'estimated_value' => isset($row->estimated_value) ? (float) $row->estimated_value : null,
                'actual_value' => isset($row->actual_value) ? (float) $row->actual_value : null,
                'engagement_id' => isset($row->engagement_id) ? (int) $row->engagement_id : null,
                // Empty placeholders for fields that used to come from legacy table
                'episode_title' => '',
                'episode_number' => '',
                'episode_date' => '',
                'episode_url' => '',
                'interview_topic' => '',
                'audience' => $row->audience ?? '',
                'commission' => $row->commission ?? '',
            ]);
        }

        return $data;
    }
}
