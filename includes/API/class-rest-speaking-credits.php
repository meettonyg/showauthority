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
}
