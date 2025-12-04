<?php
/**
 * Private Contact Repository
 *
 * Handles all database operations for user-owned private contact information.
 * Private contacts store sensitive contact details that are not shared globally.
 *
 * @package PodcastInfluenceTracker
 * @subpackage Guests
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Private_Contact_Repository {

    /**
     * Get private contact by ID
     *
     * @param int $contact_id Contact ID
     * @param int|null $user_id User ID for ownership check
     * @return object|null Contact object or null
     */
    public static function get($contact_id, $user_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guest_private_contacts';

        $query = "SELECT * FROM $table WHERE id = %d";
        $params = [$contact_id];

        if ($user_id !== null && !PIT_User_Context::is_admin()) {
            $query .= " AND user_id = %d";
            $params[] = $user_id;
        }

        return $wpdb->get_row($wpdb->prepare($query, ...$params));
    }

    /**
     * Get private contact for a guest (by user)
     *
     * @param int $guest_id Guest ID
     * @param int|null $user_id User ID (defaults to current user)
     * @return object|null Contact object or null
     */
    public static function get_for_guest($guest_id, $user_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guest_private_contacts';

        if ($user_id === null) {
            $user_id = PIT_User_Context::get_user_id();
        }

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE guest_id = %d AND user_id = %d",
            $guest_id, $user_id
        ));
    }

    /**
     * Get all private contacts for a user
     *
     * @param int|null $user_id User ID (defaults to current user)
     * @return array Contact objects
     */
    public static function get_all_for_user($user_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guest_private_contacts';
        $guests_table = $wpdb->prefix . 'pit_guests';

        if ($user_id === null) {
            $user_id = PIT_User_Context::get_user_id();
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT pc.*, g.full_name, g.current_company, g.email as public_email
             FROM $table pc
             INNER JOIN $guests_table g ON pc.guest_id = g.id
             WHERE pc.user_id = %d
             ORDER BY g.full_name ASC",
            $user_id
        ));
    }

    /**
     * Create or update private contact (upsert)
     *
     * @param int $guest_id Guest ID
     * @param array $data Contact data
     * @param int|null $user_id User ID (defaults to current user)
     * @return int|false Contact ID or false on failure
     */
    public static function upsert($guest_id, $data, $user_id = null) {
        if ($user_id === null) {
            $user_id = PIT_User_Context::get_user_id();
        }

        $existing = self::get_for_guest($guest_id, $user_id);

        if ($existing) {
            self::update($existing->id, $data);
            return (int) $existing->id;
        }

        $data['guest_id'] = $guest_id;
        $data['user_id'] = $user_id;
        return self::create($data);
    }

    /**
     * Create a new private contact
     *
     * @param array $data Contact data
     * @return int|false Contact ID or false on failure
     */
    public static function create($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guest_private_contacts';

        // Set user_id if not provided
        if (!isset($data['user_id'])) {
            $data['user_id'] = PIT_User_Context::get_user_id();
        }

        // Set timestamps
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
     * Update a private contact
     *
     * @param int $contact_id Contact ID
     * @param array $data Data to update
     * @return bool Success
     */
    public static function update($contact_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guest_private_contacts';

        unset($data['created_at']);
        $data['updated_at'] = current_time('mysql');

        return $wpdb->update($table, $data, ['id' => $contact_id]) !== false;
    }

    /**
     * Delete a private contact
     *
     * @param int $contact_id Contact ID
     * @return bool Success
     */
    public static function delete($contact_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guest_private_contacts';

        return $wpdb->delete($table, ['id' => $contact_id], ['%d']) !== false;
    }

    /**
     * Delete all private contacts for a guest
     *
     * @param int $guest_id Guest ID
     * @return int Number of deleted rows
     */
    public static function delete_for_guest($guest_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guest_private_contacts';

        return $wpdb->delete($table, ['guest_id' => $guest_id], ['%d']);
    }

    /**
     * Delete all private contacts for a user
     *
     * @param int $user_id User ID
     * @return int Number of deleted rows
     */
    public static function delete_for_user($user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guest_private_contacts';

        return $wpdb->delete($table, ['user_id' => $user_id], ['%d']);
    }

    /**
     * Get guest with private contacts merged
     *
     * @param int $guest_id Guest ID
     * @param int|null $user_id User ID (defaults to current user)
     * @return object|null Guest object with private fields
     */
    public static function get_guest_with_private($guest_id, $user_id = null) {
        global $wpdb;
        $guests_table = $wpdb->prefix . 'pit_guests';
        $table = $wpdb->prefix . 'pit_guest_private_contacts';

        if ($user_id === null) {
            $user_id = PIT_User_Context::get_user_id();
        }

        return $wpdb->get_row($wpdb->prepare(
            "SELECT g.*,
                    pc.personal_email AS private_email,
                    pc.secondary_email AS private_secondary_email,
                    pc.phone AS private_phone,
                    pc.mobile_phone AS private_mobile,
                    pc.assistant_name AS private_assistant_name,
                    pc.assistant_email AS private_assistant_email,
                    pc.assistant_phone AS private_assistant_phone,
                    pc.private_notes,
                    pc.relationship_notes,
                    pc.last_contact_date,
                    pc.preferred_contact_method
             FROM $guests_table g
             LEFT JOIN $table pc ON g.id = pc.guest_id AND pc.user_id = %d
             WHERE g.id = %d",
            $user_id, $guest_id
        ));
    }

    /**
     * Update last contact date
     *
     * @param int $guest_id Guest ID
     * @param string $date Date string (Y-m-d format)
     * @param int|null $user_id User ID
     * @return bool Success
     */
    public static function update_last_contact($guest_id, $date, $user_id = null) {
        if ($user_id === null) {
            $user_id = PIT_User_Context::get_user_id();
        }

        return self::upsert($guest_id, ['last_contact_date' => $date], $user_id) !== false;
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
        $table = $wpdb->prefix . 'pit_guest_private_contacts';

        // Get users who have private contacts for new guest
        $existing_users = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM $table WHERE guest_id = %d",
            $new_guest_id
        ));

        // Update contacts that won't create duplicates
        $updated = 0;
        $old_contacts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE guest_id = %d",
            $old_guest_id
        ));

        foreach ($old_contacts as $contact) {
            if (in_array($contact->user_id, $existing_users)) {
                // Merge data into existing contact
                $existing = self::get_for_guest($new_guest_id, $contact->user_id);
                $merge_data = [];

                // Only fill empty fields
                $fields = ['personal_email', 'secondary_email', 'phone', 'mobile_phone',
                          'assistant_name', 'assistant_email', 'assistant_phone',
                          'private_notes', 'relationship_notes', 'preferred_contact_method'];

                foreach ($fields as $field) {
                    if (empty($existing->$field) && !empty($contact->$field)) {
                        $merge_data[$field] = $contact->$field;
                    }
                }

                if (!empty($merge_data)) {
                    self::update($existing->id, $merge_data);
                }

                // Delete old contact
                self::delete($contact->id);
            } else {
                // Update to new guest
                $wpdb->update($table, ['guest_id' => $new_guest_id], ['id' => $contact->id]);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Get count for user
     *
     * @param int|null $user_id User ID
     * @return int Count
     */
    public static function count_for_user($user_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guest_private_contacts';

        if ($user_id === null) {
            $user_id = PIT_User_Context::get_user_id();
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE user_id = %d",
            $user_id
        ));
    }
}
