<?php
/**
 * Speaking Credit Repository
 *
 * Handles all database operations for speaking credits.
 * Speaking credits link guests to engagements with role information.
 *
 * @package PodcastInfluenceTracker
 * @subpackage Engagements
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Speaking_Credit_Repository {

    /**
     * Get speaking credit by ID
     *
     * @param int $credit_id Credit ID
     * @return object|null Credit object or null
     */
    public static function get($credit_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_speaking_credits';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $credit_id
        ));
    }

    /**
     * Get credits for a guest
     *
     * @param int $guest_id Guest ID
     * @return array Credit objects
     */
    public static function get_for_guest($guest_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_speaking_credits';
        $engagements_table = $wpdb->prefix . 'pit_engagements';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT sc.*, e.title, e.engagement_type, e.engagement_date, e.podcast_id
             FROM $table sc
             INNER JOIN $engagements_table e ON sc.engagement_id = e.id
             WHERE sc.guest_id = %d
             ORDER BY e.engagement_date DESC",
            $guest_id
        ));
    }

    /**
     * Get credits for an engagement
     *
     * @param int $engagement_id Engagement ID
     * @return array Credit objects with guest info
     */
    public static function get_for_engagement($engagement_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_speaking_credits';
        $guests_table = $wpdb->prefix . 'pit_guests';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT sc.*, g.full_name, g.current_company, g.email, g.linkedin_url
             FROM $table sc
             INNER JOIN $guests_table g ON sc.guest_id = g.id
             WHERE sc.engagement_id = %d
             ORDER BY sc.credit_order ASC",
            $engagement_id
        ));
    }

    /**
     * Create a new speaking credit
     *
     * @param array $data Credit data
     * @return int|false Credit ID or false on failure
     */
    public static function create($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_speaking_credits';

        // Check for existing credit with same guest/engagement/role
        if (!empty($data['guest_id']) && !empty($data['engagement_id'])) {
            $role = $data['role'] ?? 'guest';
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE guest_id = %d AND engagement_id = %d AND role = %s",
                $data['guest_id'], $data['engagement_id'], $role
            ));
            if ($existing) {
                return (int) $existing; // Return existing ID
            }
        }

        // Set defaults
        if (!isset($data['role'])) {
            $data['role'] = 'guest';
        }
        if (!isset($data['is_primary'])) {
            $data['is_primary'] = 1;
        }
        if (!isset($data['credit_order'])) {
            $data['credit_order'] = 1;
        }
        if (!isset($data['created_at'])) {
            $data['created_at'] = current_time('mysql');
        }
        if (!isset($data['discovered_by_user_id'])) {
            $data['discovered_by_user_id'] = PIT_User_Context::get_user_id();
        }

        $wpdb->insert($table, $data);

        return $wpdb->insert_id ?: false;
    }

    /**
     * Update a speaking credit
     *
     * @param int $credit_id Credit ID
     * @param array $data Data to update
     * @return bool Success
     */
    public static function update($credit_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_speaking_credits';

        unset($data['created_at']);

        return $wpdb->update($table, $data, ['id' => $credit_id]) !== false;
    }

    /**
     * Delete a speaking credit
     *
     * @param int $credit_id Credit ID
     * @return bool Success
     */
    public static function delete($credit_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_speaking_credits';

        return $wpdb->delete($table, ['id' => $credit_id], ['%d']) !== false;
    }

    /**
     * Delete all credits for a guest
     *
     * @param int $guest_id Guest ID
     * @return int Number of deleted rows
     */
    public static function delete_for_guest($guest_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_speaking_credits';

        return $wpdb->delete($table, ['guest_id' => $guest_id], ['%d']);
    }

    /**
     * Delete all credits for an engagement
     *
     * @param int $engagement_id Engagement ID
     * @return int Number of deleted rows
     */
    public static function delete_for_engagement($engagement_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_speaking_credits';

        return $wpdb->delete($table, ['engagement_id' => $engagement_id], ['%d']);
    }

    /**
     * Verify a speaking credit
     *
     * @param int $credit_id Credit ID
     * @param bool $verified Verification status
     * @return bool Success
     */
    public static function verify($credit_id, $verified = true) {
        return self::update($credit_id, [
            'manually_verified' => $verified ? 1 : 0,
            'verified_by_user_id' => get_current_user_id(),
            'verified_at' => current_time('mysql'),
        ]);
    }

    /**
     * Get credit count for a guest
     *
     * @param int $guest_id Guest ID
     * @return int Count
     */
    public static function count_for_guest($guest_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_speaking_credits';

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE guest_id = %d",
            $guest_id
        ));
    }

    /**
     * Get guest count for an engagement
     *
     * @param int $engagement_id Engagement ID
     * @return int Count
     */
    public static function count_for_engagement($engagement_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_speaking_credits';

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE engagement_id = %d",
            $engagement_id
        ));
    }

    /**
     * Link guest to engagement
     *
     * @param int $guest_id Guest ID
     * @param int $engagement_id Engagement ID
     * @param string $role Role (guest, host, etc.)
     * @return int|false Credit ID or false
     */
    public static function link($guest_id, $engagement_id, $role = 'guest') {
        return self::create([
            'guest_id' => $guest_id,
            'engagement_id' => $engagement_id,
            'role' => $role,
        ]);
    }

    /**
     * Unlink guest from engagement
     *
     * @param int $guest_id Guest ID
     * @param int $engagement_id Engagement ID
     * @param string|null $role Optional role filter
     * @return bool Success
     */
    public static function unlink($guest_id, $engagement_id, $role = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_speaking_credits';

        $where = ['guest_id' => $guest_id, 'engagement_id' => $engagement_id];
        $formats = ['%d', '%d'];

        if ($role !== null) {
            $where['role'] = $role;
            $formats[] = '%s';
        }

        return $wpdb->delete($table, $where, $formats) !== false;
    }

    /**
     * Update guest references when merging guests
     *
     * @param int $old_guest_id Old guest ID
     * @param int $new_guest_id New guest ID
     * @return int Number of updated rows
     */
    public static function update_guest_references($old_guest_id, $new_guest_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_speaking_credits';

        // Get existing credits for new guest to avoid duplicates
        $existing = $wpdb->get_col($wpdb->prepare(
            "SELECT CONCAT(engagement_id, '-', role) FROM $table WHERE guest_id = %d",
            $new_guest_id
        ));

        // Update credits that won't create duplicates
        $updated = 0;
        $old_credits = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE guest_id = %d",
            $old_guest_id
        ));

        foreach ($old_credits as $credit) {
            $key = $credit->engagement_id . '-' . $credit->role;
            if (in_array($key, $existing)) {
                // Delete duplicate
                self::delete($credit->id);
            } else {
                // Update to new guest
                $wpdb->update($table, ['guest_id' => $new_guest_id], ['id' => $credit->id]);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Get statistics
     *
     * @return array Statistics
     */
    public static function get_statistics() {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_speaking_credits';

        $stats = [
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table"),
            'verified' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE manually_verified = 1"),
            'by_role' => [],
        ];

        // Get counts by role
        $role_counts = $wpdb->get_results("SELECT role, COUNT(*) as count FROM $table GROUP BY role");
        foreach ($role_counts as $row) {
            $stats['by_role'][$row->role] = (int) $row->count;
        }

        return $stats;
    }
}
