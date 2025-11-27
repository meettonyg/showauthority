<?php
/**
 * Contact Repository
 *
 * Handles database operations for podcast contacts (hosts, producers).
 * Supports crowdsourcing with public/private visibility.
 *
 * @package PodcastInfluenceTracker
 * @subpackage Podcasts
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Contact_Repository {

    /**
     * Get contact by ID
     *
     * @param int $contact_id Contact ID
     * @param int|null $user_id User ID for visibility check (null for admin)
     * @return object|null
     */
    public static function get($contact_id, $user_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcast_contacts';

        $contact = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $contact_id
        ));

        // Check visibility if user_id provided
        if ($contact && $user_id !== null && !PIT_User_Context::is_admin()) {
            if (!self::can_view($contact, $user_id)) {
                return null;
            }
        }

        return $contact;
    }

    /**
     * Check if user can view a contact
     *
     * @param object $contact Contact object
     * @param int $user_id User ID
     * @return bool
     */
    public static function can_view($contact, $user_id) {
        // Public contacts visible to all
        if ($contact->is_public && $contact->visibility === 'public') {
            return true;
        }

        // Verified-only contacts visible to authenticated users
        if ($contact->visibility === 'verified_only' && $user_id > 0) {
            return true;
        }

        // Private contacts only visible to creator
        if ((int) $contact->created_by_user_id === (int) $user_id) {
            return true;
        }

        return false;
    }

    /**
     * Check if user can edit a contact
     *
     * @param object $contact Contact object
     * @param int $user_id User ID
     * @return bool
     */
    public static function can_edit($contact, $user_id) {
        // Admins can edit all
        if (PIT_User_Context::is_admin()) {
            return true;
        }

        // Creator can edit their own contacts
        if ((int) $contact->created_by_user_id === (int) $user_id) {
            return true;
        }

        // Public contacts can be edited by anyone (crowdsourcing)
        if ($contact->is_public && $contact->visibility === 'public') {
            return true;
        }

        return false;
    }

    /**
     * Get contacts for a podcast (with visibility filtering)
     *
     * @param int $podcast_id Podcast ID
     * @param int|null $user_id User ID for visibility filtering
     * @return array
     */
    public static function get_for_podcast($podcast_id, $user_id = null) {
        global $wpdb;
        $contacts_table = $wpdb->prefix . 'pit_podcast_contacts';
        $relationships_table = $wpdb->prefix . 'pit_podcast_contact_relationships';

        // Build visibility clause
        $visibility_clause = '';
        $params = [$podcast_id];

        if ($user_id !== null && !PIT_User_Context::is_admin()) {
            // Show public contacts OR contacts created by this user
            $visibility_clause = "AND (c.is_public = 1 OR c.created_by_user_id = %d)";
            $params[] = $user_id;
        }

        $query = "SELECT c.*, r.role, r.is_primary
            FROM $contacts_table c
            INNER JOIN $relationships_table r ON c.id = r.contact_id
            WHERE r.podcast_id = %d AND r.active = 1 $visibility_clause
            ORDER BY r.is_primary DESC, c.full_name ASC";

        return $wpdb->get_results($wpdb->prepare($query, ...$params));
    }

    /**
     * Create a contact
     *
     * @param array $data Contact data
     * @return int|false Contact ID or false
     */
    public static function create($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcast_contacts';

        // Set creator if not already set
        if (!isset($data['created_by_user_id'])) {
            $data['created_by_user_id'] = PIT_User_Context::get_user_id();
        }

        // Default to public visibility for crowdsourcing
        if (!isset($data['is_public'])) {
            $data['is_public'] = 1;
        }
        if (!isset($data['visibility'])) {
            $data['visibility'] = 'public';
        }

        $wpdb->insert($table, $data);

        return $wpdb->insert_id ?: false;
    }

    /**
     * Update a contact
     *
     * @param int $contact_id Contact ID
     * @param array $data Data to update
     * @return bool
     */
    public static function update($contact_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcast_contacts';

        unset($data['created_at']);

        return $wpdb->update($table, $data, ['id' => $contact_id]) !== false;
    }

    /**
     * Delete a contact
     *
     * @param int $contact_id Contact ID
     * @return bool
     */
    public static function delete($contact_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcast_contacts';

        return $wpdb->delete($table, ['id' => $contact_id], ['%d']) !== false;
    }

    /**
     * Link contact to podcast
     *
     * @param int $contact_id Contact ID
     * @param int $podcast_id Podcast ID
     * @param string $role Role (host, producer, etc.)
     * @param bool $is_primary Is primary contact
     * @return int|false Relationship ID or false
     */
    public static function link_to_podcast($contact_id, $podcast_id, $role = 'host', $is_primary = false) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcast_contact_relationships';

        // Check if already linked
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE contact_id = %d AND podcast_id = %d AND role = %s",
            $contact_id, $podcast_id, $role
        ));

        if ($existing) {
            return $existing;
        }

        $wpdb->insert($table, [
            'contact_id' => $contact_id,
            'podcast_id' => $podcast_id,
            'role' => $role,
            'is_primary' => $is_primary ? 1 : 0,
            'active' => 1,
        ]);

        return $wpdb->insert_id ?: false;
    }

    /**
     * Unlink contact from podcast
     *
     * @param int $contact_id Contact ID
     * @param int $podcast_id Podcast ID
     * @return bool
     */
    public static function unlink_from_podcast($contact_id, $podcast_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcast_contact_relationships';

        return $wpdb->update(
            $table,
            ['active' => 0],
            ['contact_id' => $contact_id, 'podcast_id' => $podcast_id]
        ) !== false;
    }

    /**
     * Set contact visibility
     *
     * @param int $contact_id Contact ID
     * @param string $visibility Visibility level (public, private, verified_only)
     * @param int $user_id User making the change
     * @return bool
     */
    public static function set_visibility($contact_id, $visibility, $user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcast_contacts';

        $contact = self::get($contact_id);
        if (!$contact) {
            return false;
        }

        // Only creator or admin can change visibility
        if (!PIT_User_Context::is_admin() && (int) $contact->created_by_user_id !== (int) $user_id) {
            return false;
        }

        $is_public = $visibility === 'public' ? 1 : 0;

        return $wpdb->update($table, [
            'visibility' => $visibility,
            'is_public' => $is_public,
        ], ['id' => $contact_id]) !== false;
    }

    /**
     * Verify a contact (community verification)
     *
     * @param int $contact_id Contact ID
     * @param int $user_id User verifying
     * @return bool
     */
    public static function verify($contact_id, $user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcast_contacts';

        return $wpdb->query($wpdb->prepare(
            "UPDATE $table
            SET verification_count = verification_count + 1,
                last_verified_at = NOW(),
                last_verified_by = %d,
                community_verified = CASE WHEN verification_count >= 3 THEN 1 ELSE community_verified END
            WHERE id = %d",
            $user_id,
            $contact_id
        )) !== false;
    }

    /**
     * Report a contact as inaccurate
     *
     * @param int $contact_id Contact ID
     * @param int $user_id User reporting
     * @param string $reason Reason for report
     * @return bool
     */
    public static function report($contact_id, $user_id, $reason = '') {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcast_contacts';

        // Increment report count
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE $table SET report_count = report_count + 1 WHERE id = %d",
            $contact_id
        ));

        // If too many reports, unverify the contact
        $contact = self::get($contact_id);
        if ($contact && $contact->report_count >= 5) {
            $wpdb->update($table, ['community_verified' => 0], ['id' => $contact_id]);
        }

        return $result !== false;
    }

    /**
     * Get verified public contacts (for crowdsourcing)
     *
     * @param array $args Query arguments
     * @return array
     */
    public static function get_verified_public($args = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcast_contacts';

        $limit = isset($args['limit']) ? (int) $args['limit'] : 50;
        $offset = isset($args['offset']) ? (int) $args['offset'] : 0;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table
            WHERE is_public = 1 AND community_verified = 1
            ORDER BY verification_count DESC, updated_at DESC
            LIMIT %d OFFSET %d",
            $limit,
            $offset
        ));
    }

    /**
     * Get contacts created by a specific user
     *
     * @param int $user_id User ID
     * @param array $args Query arguments
     * @return array
     */
    public static function get_by_user($user_id, $args = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcast_contacts';

        $limit = isset($args['limit']) ? (int) $args['limit'] : 50;
        $offset = isset($args['offset']) ? (int) $args['offset'] : 0;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table
            WHERE created_by_user_id = %d
            ORDER BY created_at DESC
            LIMIT %d OFFSET %d",
            $user_id,
            $limit,
            $offset
        ));
    }

    /**
     * Search contacts globally (public only for non-creators)
     *
     * @param string $search Search term
     * @param int|null $user_id User ID for visibility
     * @param array $args Additional arguments
     * @return array
     */
    public static function search($search, $user_id = null, $args = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcast_contacts';

        $limit = isset($args['limit']) ? (int) $args['limit'] : 50;
        $search_term = '%' . $wpdb->esc_like($search) . '%';

        // Build visibility clause
        $visibility_clause = 'is_public = 1';
        $params = [$search_term, $search_term, $search_term];

        if ($user_id !== null && !PIT_User_Context::is_admin()) {
            $visibility_clause = "(is_public = 1 OR created_by_user_id = %d)";
            $params[] = $user_id;
        } elseif (PIT_User_Context::is_admin()) {
            $visibility_clause = '1=1';
        }

        $params[] = $limit;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table
            WHERE (full_name LIKE %s OR email LIKE %s OR company LIKE %s)
            AND $visibility_clause
            ORDER BY community_verified DESC, full_name ASC
            LIMIT %d",
            ...$params
        ));
    }
}
