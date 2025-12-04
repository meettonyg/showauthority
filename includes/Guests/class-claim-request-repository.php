<?php
/**
 * Claim Request Repository
 *
 * Handles all database operations for guest claim requests.
 * Claim requests allow users to claim ownership of guest profiles.
 *
 * @package PodcastInfluenceTracker
 * @subpackage Guests
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Claim_Request_Repository {

    /**
     * Claim statuses
     */
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_AUTO_APPROVED = 'auto_approved';

    /**
     * Verification methods
     */
    const METHOD_EMAIL = 'email';
    const METHOD_LINKEDIN = 'linkedin';
    const METHOD_MANUAL = 'manual';
    const METHOD_ADMIN = 'admin';

    /**
     * Get claim request by ID
     *
     * @param int $request_id Request ID
     * @return object|null Request object or null
     */
    public static function get($request_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_claim_requests';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $request_id
        ));
    }

    /**
     * Get claim request for a guest by user
     *
     * @param int $guest_id Guest ID
     * @param int|null $user_id User ID (defaults to current user)
     * @return object|null Request object or null
     */
    public static function get_for_guest($guest_id, $user_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_claim_requests';

        if ($user_id === null) {
            $user_id = PIT_User_Context::get_user_id();
        }

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE guest_id = %d AND user_id = %d",
            $guest_id, $user_id
        ));
    }

    /**
     * Get all claims for a user
     *
     * @param int|null $user_id User ID (defaults to current user)
     * @return array Request objects
     */
    public static function get_for_user($user_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_claim_requests';
        $guests_table = $wpdb->prefix . 'pit_guests';

        if ($user_id === null) {
            $user_id = PIT_User_Context::get_user_id();
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT cr.*, g.full_name, g.current_company, g.email as guest_email
             FROM $table cr
             INNER JOIN $guests_table g ON cr.guest_id = g.id
             WHERE cr.user_id = %d
             ORDER BY cr.created_at DESC",
            $user_id
        ));
    }

    /**
     * Get pending claims (for admin review)
     *
     * @param array $args Query arguments
     * @return array Results with requests, total, and pages
     */
    public static function get_pending($args = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_claim_requests';
        $guests_table = $wpdb->prefix . 'pit_guests';
        $users_table = $wpdb->users;

        $defaults = [
            'page' => 1,
            'per_page' => 20,
        ];
        $args = wp_parse_args($args, $defaults);

        $offset = ($args['page'] - 1) * $args['per_page'];

        $requests = $wpdb->get_results($wpdb->prepare(
            "SELECT cr.*, g.full_name as guest_name, g.current_company, g.email as guest_email,
                    u.display_name as user_name, u.user_email
             FROM $table cr
             INNER JOIN $guests_table g ON cr.guest_id = g.id
             INNER JOIN $users_table u ON cr.user_id = u.ID
             WHERE cr.status = %s
             ORDER BY cr.created_at ASC
             LIMIT %d OFFSET %d",
            self::STATUS_PENDING, $args['per_page'], $offset
        ));

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE status = %s",
            self::STATUS_PENDING
        ));

        return [
            'requests' => $requests,
            'total' => $total,
            'pages' => ceil($total / $args['per_page']),
        ];
    }

    /**
     * Create a new claim request
     *
     * @param array $data Request data
     * @return int|false Request ID or false on failure
     */
    public static function create($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_claim_requests';

        // Set user_id if not provided
        if (!isset($data['user_id'])) {
            $data['user_id'] = PIT_User_Context::get_user_id();
        }

        // Set defaults
        if (!isset($data['status'])) {
            $data['status'] = self::STATUS_PENDING;
        }
        if (!isset($data['created_at'])) {
            $data['created_at'] = current_time('mysql');
        }
        if (!isset($data['updated_at'])) {
            $data['updated_at'] = current_time('mysql');
        }

        $wpdb->insert($table, $data);

        return $wpdb->insert_id ?: false;
    }

    /**
     * Submit a claim request
     *
     * @param int $guest_id Guest ID
     * @param array $data Additional data (claim_reason, proof_url, etc.)
     * @param int|null $user_id User ID
     * @return array ['success' => bool, 'message' => string, 'request_id' => int|null]
     */
    public static function submit_claim($guest_id, $data = [], $user_id = null) {
        global $wpdb;

        if ($user_id === null) {
            $user_id = PIT_User_Context::get_user_id();
        }

        // Check if already claimed
        $guests_table = $wpdb->prefix . 'pit_guests';
        $guest = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $guests_table WHERE id = %d",
            $guest_id
        ));

        if (!$guest) {
            return ['success' => false, 'message' => 'Guest not found', 'request_id' => null];
        }

        if ($guest->claim_status === 'verified') {
            return ['success' => false, 'message' => 'This profile has already been claimed', 'request_id' => null];
        }

        // Check for existing pending request
        $existing = self::get_for_guest($guest_id, $user_id);
        if ($existing && $existing->status === self::STATUS_PENDING) {
            return ['success' => false, 'message' => 'You already have a pending claim for this profile', 'request_id' => (int) $existing->id];
        }

        // Check for auto-approval conditions
        $user = get_user_by('ID', $user_id);
        $auto_approved = false;
        $verification_method = null;

        // Auto-approve if email matches
        if ($user && !empty($guest->email) && strtolower($user->user_email) === strtolower($guest->email)) {
            $auto_approved = true;
            $verification_method = self::METHOD_EMAIL;
        }

        // Create the request
        $request_data = array_merge($data, [
            'guest_id' => $guest_id,
            'user_id' => $user_id,
            'status' => $auto_approved ? self::STATUS_AUTO_APPROVED : self::STATUS_PENDING,
            'verification_method' => $verification_method,
        ]);

        $request_id = self::create($request_data);

        if (!$request_id) {
            return ['success' => false, 'message' => 'Failed to create claim request', 'request_id' => null];
        }

        // If auto-approved, update the guest
        if ($auto_approved) {
            $wpdb->update($guests_table, [
                'claimed_by_user_id' => $user_id,
                'claim_status' => 'verified',
                'claim_verified_at' => current_time('mysql'),
                'claim_verification_method' => $verification_method,
            ], ['id' => $guest_id]);

            return ['success' => true, 'message' => 'Your claim has been automatically approved!', 'request_id' => $request_id, 'auto_approved' => true];
        }

        return ['success' => true, 'message' => 'Your claim request has been submitted for review', 'request_id' => $request_id, 'auto_approved' => false];
    }

    /**
     * Approve a claim request
     *
     * @param int $request_id Request ID
     * @param string|null $notes Review notes
     * @return bool Success
     */
    public static function approve($request_id, $notes = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_claim_requests';
        $guests_table = $wpdb->prefix . 'pit_guests';

        $request = self::get($request_id);
        if (!$request) {
            return false;
        }

        // Update request
        $result = $wpdb->update($table, [
            'status' => self::STATUS_APPROVED,
            'reviewed_by_user_id' => get_current_user_id(),
            'reviewed_at' => current_time('mysql'),
            'review_notes' => $notes,
            'verification_method' => self::METHOD_ADMIN,
            'updated_at' => current_time('mysql'),
        ], ['id' => $request_id]);

        if ($result === false) {
            return false;
        }

        // Update guest
        $wpdb->update($guests_table, [
            'claimed_by_user_id' => $request->user_id,
            'claim_status' => 'verified',
            'claim_verified_at' => current_time('mysql'),
            'claim_verification_method' => self::METHOD_ADMIN,
        ], ['id' => $request->guest_id]);

        return true;
    }

    /**
     * Reject a claim request
     *
     * @param int $request_id Request ID
     * @param string|null $reason Rejection reason
     * @return bool Success
     */
    public static function reject($request_id, $reason = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_claim_requests';
        $guests_table = $wpdb->prefix . 'pit_guests';

        $request = self::get($request_id);
        if (!$request) {
            return false;
        }

        // Update request
        $result = $wpdb->update($table, [
            'status' => self::STATUS_REJECTED,
            'reviewed_by_user_id' => get_current_user_id(),
            'reviewed_at' => current_time('mysql'),
            'rejection_reason' => $reason,
            'updated_at' => current_time('mysql'),
        ], ['id' => $request_id]);

        if ($result === false) {
            return false;
        }

        // Update guest claim_status back to unclaimed if it was pending
        $wpdb->update($guests_table, [
            'claim_status' => 'rejected',
        ], ['id' => $request->guest_id, 'claim_status' => 'pending']);

        return true;
    }

    /**
     * Update a claim request
     *
     * @param int $request_id Request ID
     * @param array $data Data to update
     * @return bool Success
     */
    public static function update($request_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_claim_requests';

        unset($data['created_at']);
        $data['updated_at'] = current_time('mysql');

        return $wpdb->update($table, $data, ['id' => $request_id]) !== false;
    }

    /**
     * Delete a claim request
     *
     * @param int $request_id Request ID
     * @return bool Success
     */
    public static function delete($request_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_claim_requests';

        return $wpdb->delete($table, ['id' => $request_id], ['%d']) !== false;
    }

    /**
     * Get statistics
     *
     * @return array Statistics
     */
    public static function get_statistics() {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_claim_requests';

        $stats = [
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table"),
            'pending' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE status = %s", self::STATUS_PENDING)),
            'approved' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE status = %s", self::STATUS_APPROVED)),
            'rejected' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE status = %s", self::STATUS_REJECTED)),
            'auto_approved' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE status = %s", self::STATUS_AUTO_APPROVED)),
        ];

        return $stats;
    }

    /**
     * Check if user has claimed a guest
     *
     * @param int $guest_id Guest ID
     * @param int|null $user_id User ID
     * @return bool
     */
    public static function has_claimed($guest_id, $user_id = null) {
        global $wpdb;
        $guests_table = $wpdb->prefix . 'pit_guests';

        if ($user_id === null) {
            $user_id = PIT_User_Context::get_user_id();
        }

        $claimed_by = $wpdb->get_var($wpdb->prepare(
            "SELECT claimed_by_user_id FROM $guests_table WHERE id = %d AND claim_status = 'verified'",
            $guest_id
        ));

        return $claimed_by !== null && (int) $claimed_by === (int) $user_id;
    }
}
