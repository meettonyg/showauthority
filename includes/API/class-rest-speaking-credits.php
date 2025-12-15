<?php
/**
 * REST API Controller for Speaking Credits
 *
 * Provides endpoints for managing guest-engagement relationships.
 * Uses pit_speaking_credits table.
 *
 * @package Podcast_Influence_Tracker
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_REST_Speaking_Credits {

    const NAMESPACE = 'guestify/v1';

    /**
     * Register REST routes
     */
    public static function register_routes() {
        // Get credits for guest
        register_rest_route(self::NAMESPACE, '/speaking-credits/guest/(?P<guest_id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_for_guest'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Get credits for engagement
        register_rest_route(self::NAMESPACE, '/speaking-credits/engagement/(?P<engagement_id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_for_engagement'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Create/link credit
        register_rest_route(self::NAMESPACE, '/speaking-credits', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_credit'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Update credit
        register_rest_route(self::NAMESPACE, '/speaking-credits/(?P<id>\d+)', [
            'methods' => 'PATCH',
            'callback' => [__CLASS__, 'update_credit'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Delete credit
        register_rest_route(self::NAMESPACE, '/speaking-credits/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [__CLASS__, 'delete_credit'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Verify credit (admin only - global trust signal)
        register_rest_route(self::NAMESPACE, '/speaking-credits/(?P<id>\d+)/verify', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'verify_credit'],
            'permission_callback' => [__CLASS__, 'check_admin_permissions'],
        ]);

        // Link guest to engagement (convenience endpoint)
        register_rest_route(self::NAMESPACE, '/speaking-credits/link', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'link'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Unlink guest from engagement
        register_rest_route(self::NAMESPACE, '/speaking-credits/unlink', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'unlink'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Get statistics
        register_rest_route(self::NAMESPACE, '/speaking-credits/stats', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_statistics'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Portfolio: Get all engagements where the current user has a speaking credit
        // This is the "My Portfolio" view showing all episodes the user appeared on
        register_rest_route(self::NAMESPACE, '/portfolio', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_portfolio'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
            'args' => [
                'search' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'podcast_id' => ['type' => 'integer', 'sanitize_callback' => 'absint'],
                'engagement_type' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'date_from' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'date_to' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'per_page' => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100],
            ],
        ]);

        // Portfolio CSV Export
        register_rest_route(self::NAMESPACE, '/portfolio/export', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'export_portfolio'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);
    }

    public static function check_permissions() {
        return is_user_logged_in();
    }

    public static function check_admin_permissions() {
        return current_user_can('manage_options');
    }

    /**
     * Get credits for a guest
     */
    public static function get_for_guest($request) {
        $guest_id = (int) $request->get_param('guest_id');
        $credits = PIT_Speaking_Credit_Repository::get_for_guest($guest_id);

        return new WP_REST_Response([
            'data' => array_map([__CLASS__, 'format_credit_with_engagement'], $credits),
            'count' => count($credits),
        ], 200);
    }

    /**
     * Get credits for an engagement
     */
    public static function get_for_engagement($request) {
        $engagement_id = (int) $request->get_param('engagement_id');
        $credits = PIT_Speaking_Credit_Repository::get_for_engagement($engagement_id);

        return new WP_REST_Response([
            'data' => array_map([__CLASS__, 'format_credit_with_guest'], $credits),
            'count' => count($credits),
        ], 200);
    }

    /**
     * Create a speaking credit
     */
    public static function create_credit($request) {
        $guest_id = (int) $request->get_param('guest_id');
        $engagement_id = (int) $request->get_param('engagement_id');
        $role = sanitize_text_field($request->get_param('role')) ?: 'guest';

        if (!$guest_id || !$engagement_id) {
            return new WP_Error('missing_data', 'guest_id and engagement_id are required', ['status' => 400]);
        }

        $credit_id = PIT_Speaking_Credit_Repository::create([
            'guest_id' => $guest_id,
            'engagement_id' => $engagement_id,
            'role' => $role,
            'is_primary' => (bool) ($request->get_param('is_primary') ?? true),
            'credit_order' => (int) ($request->get_param('credit_order') ?? 1),
            'ai_confidence_score' => (int) ($request->get_param('ai_confidence_score') ?? 0),
            'extraction_method' => sanitize_text_field($request->get_param('extraction_method')) ?: 'api',
        ]);

        if (!$credit_id) {
            return new WP_Error('create_failed', 'Failed to create speaking credit', ['status' => 500]);
        }

        return new WP_REST_Response([
            'id' => $credit_id,
            'message' => 'Speaking credit created successfully',
        ], 201);
    }

    /**
     * Update a speaking credit
     */
    public static function update_credit($request) {
        $id = (int) $request->get_param('id');

        $credit = PIT_Speaking_Credit_Repository::get($id);
        if (!$credit) {
            return new WP_Error('not_found', 'Speaking credit not found', ['status' => 404]);
        }

        $allowed = ['role', 'is_primary', 'credit_order', 'ai_confidence_score'];
        $data = [];

        foreach ($allowed as $field) {
            $value = $request->get_param($field);
            if ($value !== null) {
                if ($field === 'role') {
                    $data[$field] = sanitize_text_field($value);
                } elseif ($field === 'is_primary') {
                    $data[$field] = (bool) $value ? 1 : 0;
                } else {
                    $data[$field] = (int) $value;
                }
            }
        }

        if (empty($data)) {
            return new WP_Error('no_data', 'No valid fields to update', ['status' => 400]);
        }

        PIT_Speaking_Credit_Repository::update($id, $data);

        return new WP_REST_Response([
            'id' => $id,
            'message' => 'Speaking credit updated successfully',
        ], 200);
    }

    /**
     * Delete a speaking credit
     */
    public static function delete_credit($request) {
        $id = (int) $request->get_param('id');

        $credit = PIT_Speaking_Credit_Repository::get($id);
        if (!$credit) {
            return new WP_Error('not_found', 'Speaking credit not found', ['status' => 404]);
        }

        PIT_Speaking_Credit_Repository::delete($id);

        return new WP_REST_Response(['message' => 'Speaking credit deleted'], 200);
    }

    /**
     * Verify a speaking credit
     */
    public static function verify_credit($request) {
        $id = (int) $request->get_param('id');
        $verified = (bool) ($request->get_param('verified') ?? true);

        $credit = PIT_Speaking_Credit_Repository::get($id);
        if (!$credit) {
            return new WP_Error('not_found', 'Speaking credit not found', ['status' => 404]);
        }

        PIT_Speaking_Credit_Repository::verify($id, $verified);

        return new WP_REST_Response([
            'id' => $id,
            'verified' => $verified,
            'message' => $verified ? 'Speaking credit verified' : 'Speaking credit unverified',
        ], 200);
    }

    /**
     * Link a guest to an engagement
     */
    public static function link($request) {
        $guest_id = (int) $request->get_param('guest_id');
        $engagement_id = (int) $request->get_param('engagement_id');
        $role = sanitize_text_field($request->get_param('role')) ?: 'guest';

        if (!$guest_id || !$engagement_id) {
            return new WP_Error('missing_data', 'guest_id and engagement_id are required', ['status' => 400]);
        }

        $credit_id = PIT_Speaking_Credit_Repository::link($guest_id, $engagement_id, $role);

        return new WP_REST_Response([
            'id' => $credit_id,
            'message' => 'Guest linked to engagement',
        ], 201);
    }

    /**
     * Unlink a guest from an engagement
     */
    public static function unlink($request) {
        $guest_id = (int) $request->get_param('guest_id');
        $engagement_id = (int) $request->get_param('engagement_id');
        $role = $request->get_param('role') ? sanitize_text_field($request->get_param('role')) : null;

        if (!$guest_id || !$engagement_id) {
            return new WP_Error('missing_data', 'guest_id and engagement_id are required', ['status' => 400]);
        }

        PIT_Speaking_Credit_Repository::unlink($guest_id, $engagement_id, $role);

        return new WP_REST_Response(['message' => 'Guest unlinked from engagement'], 200);
    }

    /**
     * Get statistics
     */
    public static function get_statistics($request) {
        return new WP_REST_Response(PIT_Speaking_Credit_Repository::get_statistics(), 200);
    }

    /**
     * Format credit with engagement info
     */
    private static function format_credit_with_engagement($row) {
        return [
            'id' => (int) $row->id,
            'guest_id' => (int) $row->guest_id,
            'engagement_id' => (int) $row->engagement_id,
            'role' => $row->role,
            'is_primary' => (bool) $row->is_primary,
            'manually_verified' => (bool) $row->manually_verified,
            'engagement' => [
                'title' => $row->title ?? null,
                'engagement_type' => $row->engagement_type ?? null,
                'engagement_date' => $row->engagement_date ?? null,
                'podcast_id' => isset($row->podcast_id) ? (int) $row->podcast_id : null,
            ],
            'created_at' => $row->created_at,
        ];
    }

    /**
     * Format credit with guest info
     */
    private static function format_credit_with_guest($row) {
        return [
            'id' => (int) $row->id,
            'guest_id' => (int) $row->guest_id,
            'engagement_id' => (int) $row->engagement_id,
            'role' => $row->role,
            'is_primary' => (bool) $row->is_primary,
            'credit_order' => (int) $row->credit_order,
            'manually_verified' => (bool) $row->manually_verified,
            'guest' => [
                'full_name' => $row->full_name ?? null,
                'current_company' => $row->current_company ?? null,
                'email' => $row->email ?? null,
                'linkedin_url' => $row->linkedin_url ?? null,
            ],
            'created_at' => $row->created_at,
        ];
    }

    /**
     * Get Portfolio - All engagements where the current user has a speaking credit
     *
     * This implements Phase 5 of the Prospector-GuestIntel integration.
     * Shows all episodes/events where the user appeared as a guest.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function get_portfolio($request) {
        global $wpdb;

        $user_id = get_current_user_id();
        $page = (int) ($request->get_param('page') ?? 1);
        $per_page = (int) ($request->get_param('per_page') ?? 20);
        $offset = ($page - 1) * $per_page;

        // Build the query based on the plan's SQL
        $engagements_table = $wpdb->prefix . 'pit_engagements';
        $credits_table = $wpdb->prefix . 'pit_speaking_credits';
        $guests_table = $wpdb->prefix . 'pit_guests';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';
        $opportunities_table = $wpdb->prefix . 'pit_opportunities';

        $where = ["g.created_by_user_id = %d"];
        $prepare_args = [$user_id];

        // Search filter
        $search = $request->get_param('search');
        if (!empty($search)) {
            $where[] = "(e.title LIKE %s OR p.title LIKE %s OR e.description LIKE %s)";
            $search_like = '%' . $wpdb->esc_like($search) . '%';
            $prepare_args[] = $search_like;
            $prepare_args[] = $search_like;
            $prepare_args[] = $search_like;
        }

        // Podcast filter
        $podcast_id = $request->get_param('podcast_id');
        if (!empty($podcast_id)) {
            $where[] = "e.podcast_id = %d";
            $prepare_args[] = (int) $podcast_id;
        }

        // Engagement type filter
        $engagement_type = $request->get_param('engagement_type');
        if (!empty($engagement_type)) {
            $where[] = "e.engagement_type = %s";
            $prepare_args[] = $engagement_type;
        }

        // Date range filters
        $date_from = $request->get_param('date_from');
        if (!empty($date_from)) {
            $where[] = "e.engagement_date >= %s";
            $prepare_args[] = $date_from;
        }

        $date_to = $request->get_param('date_to');
        if (!empty($date_to)) {
            $where[] = "e.engagement_date <= %s";
            $prepare_args[] = $date_to;
        }

        $where_clause = implode(' AND ', $where);

        // Main query - following the plan's SQL structure
        $sql = "SELECT
                    e.id,
                    e.title AS episode_title,
                    e.engagement_date,
                    e.episode_url,
                    e.duration_seconds,
                    e.view_count,
                    e.episode_number,
                    e.engagement_type,
                    e.description,
                    e.is_verified,
                    e.thumbnail_url,
                    e.audio_url,
                    p.id AS podcast_id,
                    p.title AS podcast_name,
                    p.artwork_url AS podcast_image,
                    sc.role,
                    sc.id AS credit_id,
                    o.status AS pipeline_status,
                    o.id AS opportunity_id
                FROM {$engagements_table} e
                INNER JOIN {$credits_table} sc ON e.id = sc.engagement_id
                INNER JOIN {$guests_table} g ON sc.guest_id = g.id
                LEFT JOIN {$podcasts_table} p ON e.podcast_id = p.id
                LEFT JOIN {$opportunities_table} o ON o.engagement_id = e.id AND o.user_id = g.created_by_user_id
                WHERE {$where_clause}
                ORDER BY e.engagement_date DESC
                LIMIT %d OFFSET %d";

        $prepare_args[] = $per_page;
        $prepare_args[] = $offset;

        $results = $wpdb->get_results($wpdb->prepare($sql, $prepare_args));

        // Count query
        $count_sql = "SELECT COUNT(*)
                FROM {$engagements_table} e
                INNER JOIN {$credits_table} sc ON e.id = sc.engagement_id
                INNER JOIN {$guests_table} g ON sc.guest_id = g.id
                LEFT JOIN {$podcasts_table} p ON e.podcast_id = p.id
                WHERE {$where_clause}";

        $count_args = array_slice($prepare_args, 0, -2); // Remove LIMIT and OFFSET
        $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $count_args));

        // Get unique podcasts for filter dropdown
        $podcasts_sql = "SELECT DISTINCT p.id, p.title
                FROM {$engagements_table} e
                INNER JOIN {$credits_table} sc ON e.id = sc.engagement_id
                INNER JOIN {$guests_table} g ON sc.guest_id = g.id
                LEFT JOIN {$podcasts_table} p ON e.podcast_id = p.id
                WHERE g.created_by_user_id = %d AND p.id IS NOT NULL
                ORDER BY p.title ASC";
        $podcasts = $wpdb->get_results($wpdb->prepare($podcasts_sql, $user_id));

        // Format results
        $formatted = array_map(function($row) {
            return [
                'id' => (int) $row->id,
                'episode_title' => $row->episode_title,
                'engagement_date' => $row->engagement_date,
                'episode_url' => $row->episode_url,
                'duration_seconds' => $row->duration_seconds ? (int) $row->duration_seconds : null,
                'duration_display' => $row->duration_seconds ? self::format_duration((int) $row->duration_seconds) : null,
                'view_count' => $row->view_count ? (int) $row->view_count : null,
                'episode_number' => $row->episode_number,
                'engagement_type' => $row->engagement_type,
                'description' => $row->description,
                'is_verified' => (bool) $row->is_verified,
                'thumbnail_url' => $row->thumbnail_url,
                'audio_url' => $row->audio_url,
                'podcast_id' => $row->podcast_id ? (int) $row->podcast_id : null,
                'podcast_name' => $row->podcast_name,
                'podcast_image' => $row->podcast_image,
                'role' => $row->role,
                'credit_id' => (int) $row->credit_id,
                'pipeline_status' => $row->pipeline_status,
                'opportunity_id' => $row->opportunity_id ? (int) $row->opportunity_id : null,
            ];
        }, $results);

        return new WP_REST_Response([
            'data' => $formatted,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'pages' => ceil($total / $per_page),
            'podcasts' => array_map(function($p) {
                return ['id' => (int) $p->id, 'title' => $p->title];
            }, $podcasts),
        ], 200);
    }

    /**
     * Export Portfolio as CSV
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function export_portfolio($request) {
        global $wpdb;

        $user_id = get_current_user_id();

        $engagements_table = $wpdb->prefix . 'pit_engagements';
        $credits_table = $wpdb->prefix . 'pit_speaking_credits';
        $guests_table = $wpdb->prefix . 'pit_guests';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';

        $sql = "SELECT
                    e.title AS episode_title,
                    e.engagement_date,
                    e.episode_url,
                    e.duration_seconds,
                    e.episode_number,
                    e.engagement_type,
                    p.title AS podcast_name,
                    sc.role
                FROM {$engagements_table} e
                INNER JOIN {$credits_table} sc ON e.id = sc.engagement_id
                INNER JOIN {$guests_table} g ON sc.guest_id = g.id
                LEFT JOIN {$podcasts_table} p ON e.podcast_id = p.id
                WHERE g.created_by_user_id = %d
                ORDER BY e.engagement_date DESC";

        $results = $wpdb->get_results($wpdb->prepare($sql, $user_id));

        // Build CSV
        $csv_lines = [];
        $csv_lines[] = 'Podcast,Episode Title,Date,Episode Number,Duration,Role,URL';

        foreach ($results as $row) {
            $duration = $row->duration_seconds ? self::format_duration((int) $row->duration_seconds) : '';
            $csv_lines[] = sprintf(
                '"%s","%s","%s","%s","%s","%s","%s"',
                str_replace('"', '""', $row->podcast_name ?? ''),
                str_replace('"', '""', $row->episode_title ?? ''),
                $row->engagement_date ?? '',
                $row->episode_number ?? '',
                $duration,
                $row->role ?? 'guest',
                $row->episode_url ?? ''
            );
        }

        $csv_content = implode("\n", $csv_lines);

        return new WP_REST_Response([
            'filename' => 'portfolio-export-' . date('Y-m-d') . '.csv',
            'content' => $csv_content,
            'count' => count($results),
        ], 200);
    }

    /**
     * Format duration in seconds to display string
     *
     * @param int $seconds Duration in seconds
     * @return string Formatted duration (e.g., "45 min", "1 hr 23 min")
     */
    private static function format_duration($seconds) {
        if ($seconds <= 0) {
            return '';
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        if ($hours > 0) {
            return $hours . ' hr ' . $minutes . ' min';
        }

        return $minutes . ' min';
    }
}
