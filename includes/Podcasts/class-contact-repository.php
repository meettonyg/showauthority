<?php
/**
 * Contact Repository
 *
 * Handles database operations for podcast contacts (hosts, producers).
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
     * @return object|null
     */
    public static function get($contact_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcast_contacts';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $contact_id
        ));
    }

    /**
     * Get contacts for a podcast
     *
     * @param int $podcast_id Podcast ID
     * @return array
     */
    public static function get_for_podcast($podcast_id) {
        global $wpdb;
        $contacts_table = $wpdb->prefix . 'pit_podcast_contacts';
        $relationships_table = $wpdb->prefix . 'pit_podcast_contact_relationships';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, r.role, r.is_primary
            FROM $contacts_table c
            INNER JOIN $relationships_table r ON c.id = r.contact_id
            WHERE r.podcast_id = %d AND r.active = 1
            ORDER BY r.is_primary DESC, c.full_name ASC",
            $podcast_id
        ));
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
}
