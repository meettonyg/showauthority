<?php
/**
 * REST API Controller for Claim Requests
 *
 * Provides endpoints for managing guest profile claim requests.
 * Uses pit_claim_requests table.
 *
 * @package Podcast_Influence_Tracker
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_REST_Claim_Requests {

    const NAMESPACE = 'guestify/v1';

    /**
     * Register REST routes
     */
    public static function register_routes() {
        // Get my claims
        register_rest_route(self::NAMESPACE, '/claim-requests', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_my_claims'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Get pending claims (admin)
        register_rest_route(self::NAMESPACE, '/claim-requests/pending', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_pending'],
            'permission_callback' => [__CLASS__, 'check_admin_permissions'],
            'args' => [
                'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'per_page' => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100],
            ],
        ]);

        // Get single claim
        register_rest_route(self::NAMESPACE, '/claim-requests/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_claim'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Submit a claim
        register_rest_route(self::NAMESPACE, '/claim-requests/submit', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'submit_claim'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Approve claim (admin)
        register_rest_route(self::NAMESPACE, '/claim-requests/(?P<id>\d+)/approve', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'approve_claim'],
            'permission_callback' => [__CLASS__, 'check_admin_permissions'],
        ]);

        // Reject claim (admin)
        register_rest_route(self::NAMESPACE, '/claim-requests/(?P<id>\d+)/reject', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'reject_claim'],
            'permission_callback' => [__CLASS__, 'check_admin_permissions'],
        ]);

        // Check if user has claimed a guest
        register_rest_route(self::NAMESPACE, '/claim-requests/check/(?P<guest_id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'check_claim'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Get claim statistics (admin)
        register_rest_route(self::NAMESPACE, '/claim-requests/stats', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_statistics'],
            'permission_callback' => [__CLASS__, 'check_admin_permissions'],
        ]);
    }

    public static function check_permissions() {
        return is_user_logged_in();
    }

    public static function check_admin_permissions() {
        return current_user_can('manage_options');
    }

    /**
     * Get my claims
     */
    public static function get_my_claims($request) {
        $user_id = get_current_user_id();
        $claims = PIT_Claim_Request_Repository::get_for_user($user_id);

        return new WP_REST_Response([
            'data' => array_map([__CLASS__, 'format_claim'], $claims),
            'count' => count($claims),
        ], 200);
    }

    /**
     * Get pending claims (admin)
     */
    public static function get_pending($request) {
        $result = PIT_Claim_Request_Repository::get_pending([
            'page' => (int) $request->get_param('page'),
            'per_page' => (int) $request->get_param('per_page'),
        ]);

        return new WP_REST_Response([
            'data' => array_map([__CLASS__, 'format_claim_admin'], $result['requests']),
            'meta' => [
                'total' => $result['total'],
                'page' => (int) $request->get_param('page'),
                'per_page' => (int) $request->get_param('per_page'),
                'total_pages' => $result['pages'],
            ],
        ], 200);
    }

    /**
     * Get single claim
     */
    public static function get_claim($request) {
        $id = (int) $request->get_param('id');
        $claim = PIT_Claim_Request_Repository::get($id);

        if (!$claim) {
            return new WP_Error('not_found', 'Claim request not found', ['status' => 404]);
        }

        // Non-admins can only see their own claims
        if (!current_user_can('manage_options') && (int) $claim->user_id !== get_current_user_id()) {
            return new WP_Error('forbidden', 'Not authorized', ['status' => 403]);
        }

        return new WP_REST_Response([
            'data' => self::format_claim($claim),
        ], 200);
    }

    /**
     * Submit a claim
     */
    public static function submit_claim($request) {
        $guest_id = (int) $request->get_param('guest_id');

        if (!$guest_id) {
            return new WP_Error('missing_guest_id', 'guest_id is required', ['status' => 400]);
        }

        $data = [
            'claim_reason' => sanitize_textarea_field($request->get_param('claim_reason')),
            'proof_url' => esc_url_raw($request->get_param('proof_url')),
        ];

        $result = PIT_Claim_Request_Repository::submit_claim($guest_id, $data);

        if (!$result['success']) {
            return new WP_Error('claim_failed', $result['message'], ['status' => 400]);
        }

        return new WP_REST_Response([
            'request_id' => $result['request_id'],
            'auto_approved' => $result['auto_approved'] ?? false,
            'message' => $result['message'],
        ], 201);
    }

    /**
     * Approve a claim (admin)
     */
    public static function approve_claim($request) {
        $id = (int) $request->get_param('id');
        $notes = sanitize_textarea_field($request->get_param('notes'));

        $claim = PIT_Claim_Request_Repository::get($id);
        if (!$claim) {
            return new WP_Error('not_found', 'Claim request not found', ['status' => 404]);
        }

        if ($claim->status !== 'pending') {
            return new WP_Error('invalid_status', 'Can only approve pending claims', ['status' => 400]);
        }

        $success = PIT_Claim_Request_Repository::approve($id, $notes);

        if (!$success) {
            return new WP_Error('approve_failed', 'Failed to approve claim', ['status' => 500]);
        }

        return new WP_REST_Response([
            'id' => $id,
            'status' => 'approved',
            'message' => 'Claim approved successfully',
        ], 200);
    }

    /**
     * Reject a claim (admin)
     */
    public static function reject_claim($request) {
        $id = (int) $request->get_param('id');
        $reason = sanitize_textarea_field($request->get_param('reason'));

        $claim = PIT_Claim_Request_Repository::get($id);
        if (!$claim) {
            return new WP_Error('not_found', 'Claim request not found', ['status' => 404]);
        }

        if ($claim->status !== 'pending') {
            return new WP_Error('invalid_status', 'Can only reject pending claims', ['status' => 400]);
        }

        $success = PIT_Claim_Request_Repository::reject($id, $reason);

        if (!$success) {
            return new WP_Error('reject_failed', 'Failed to reject claim', ['status' => 500]);
        }

        return new WP_REST_Response([
            'id' => $id,
            'status' => 'rejected',
            'message' => 'Claim rejected',
        ], 200);
    }

    /**
     * Check if user has claimed a guest
     */
    public static function check_claim($request) {
        $guest_id = (int) $request->get_param('guest_id');
        $user_id = get_current_user_id();

        $has_claimed = PIT_Claim_Request_Repository::has_claimed($guest_id, $user_id);
        $pending_claim = PIT_Claim_Request_Repository::get_for_guest($guest_id, $user_id);

        return new WP_REST_Response([
            'has_claimed' => $has_claimed,
            'pending_request' => $pending_claim && $pending_claim->status === 'pending',
            'claim_status' => $pending_claim ? $pending_claim->status : null,
        ], 200);
    }

    /**
     * Get claim statistics (admin)
     */
    public static function get_statistics($request) {
        return new WP_REST_Response(PIT_Claim_Request_Repository::get_statistics(), 200);
    }

    /**
     * Format claim for user response
     */
    private static function format_claim($row) {
        return [
            'id' => (int) $row->id,
            'guest_id' => (int) $row->guest_id,
            'guest_name' => $row->full_name ?? null,
            'guest_company' => $row->current_company ?? null,
            'status' => $row->status,
            'claim_reason' => $row->claim_reason,
            'proof_url' => $row->proof_url,
            'verification_method' => $row->verification_method,
            'reviewed_at' => $row->reviewed_at,
            'rejection_reason' => $row->rejection_reason,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /**
     * Format claim for admin response
     */
    private static function format_claim_admin($row) {
        return [
            'id' => (int) $row->id,
            'user_id' => (int) $row->user_id,
            'user_name' => $row->user_name ?? null,
            'user_email' => $row->user_email ?? null,
            'guest_id' => (int) $row->guest_id,
            'guest_name' => $row->guest_name ?? null,
            'guest_company' => $row->current_company ?? null,
            'guest_email' => $row->guest_email ?? null,
            'status' => $row->status,
            'claim_reason' => $row->claim_reason,
            'proof_url' => $row->proof_url,
            'verification_method' => $row->verification_method,
            'review_notes' => $row->review_notes,
            'rejection_reason' => $row->rejection_reason,
            'reviewed_by_user_id' => $row->reviewed_by_user_id ? (int) $row->reviewed_by_user_id : null,
            'reviewed_at' => $row->reviewed_at,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}
