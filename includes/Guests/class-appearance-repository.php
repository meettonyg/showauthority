<?php
/**
 * Appearance Repository
 *
 * Handles database operations for guest appearances on podcasts.
 *
 * @package PodcastInfluenceTracker
 * @subpackage Guests
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Appearance_Repository {

    /**
     * Get appearance by ID
     *
     * @param int $appearance_id Appearance ID
     * @return object|null
     */
    public static function get($appearance_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guest_appearances';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $appearance_id
        ));
    }

    /**
     * Get appearances for a guest
     *
     * @param int $guest_id Guest ID
     * @return array
     */
    public static function get_for_guest($guest_id) {
        global $wpdb;
        $appearances_table = $wpdb->prefix . 'pit_guest_appearances';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, p.title as podcast_title, p.artwork_url as podcast_artwork
            FROM $appearances_table a
            LEFT JOIN $podcasts_table p ON a.podcast_id = p.id
            WHERE a.guest_id = %d
            ORDER BY a.episode_date DESC, a.created_at DESC",
            $guest_id
        ));
    }

    /**
     * Get appearances for a podcast
     *
     * @param int $podcast_id Podcast ID
     * @return array
     */
    public static function get_for_podcast($podcast_id) {
        global $wpdb;
        $appearances_table = $wpdb->prefix . 'pit_guest_appearances';
        $guests_table = $wpdb->prefix . 'pit_guests';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, g.full_name as guest_name, g.current_company, g.current_role, g.linkedin_url
            FROM $appearances_table a
            LEFT JOIN $guests_table g ON a.guest_id = g.id
            WHERE a.podcast_id = %d AND (g.is_merged = 0 OR g.is_merged IS NULL)
            ORDER BY a.episode_date DESC, a.created_at DESC",
            $podcast_id
        ));
    }

    /**
     * Create an appearance
     *
     * @param array $data Appearance data
     * @return int|false Appearance ID or false
     */
    public static function create($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guest_appearances';

        // Check for duplicate
        if (!empty($data['guest_id']) && !empty($data['podcast_id']) && !empty($data['episode_guid'])) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE guest_id = %d AND podcast_id = %d AND episode_guid = %s",
                $data['guest_id'], $data['podcast_id'], $data['episode_guid']
            ));
            if ($existing) {
                return $existing;
            }
        }

        $wpdb->insert($table, $data);

        return $wpdb->insert_id ?: false;
    }

    /**
     * Update an appearance
     *
     * @param int $appearance_id Appearance ID
     * @param array $data Data to update
     * @return bool
     */
    public static function update($appearance_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guest_appearances';

        unset($data['created_at']);

        return $wpdb->update($table, $data, ['id' => $appearance_id]) !== false;
    }

    /**
     * Delete an appearance
     *
     * @param int $appearance_id Appearance ID
     * @return bool
     */
    public static function delete($appearance_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guest_appearances';

        return $wpdb->delete($table, ['id' => $appearance_id], ['%d']) !== false;
    }

    /**
     * Get statistics
     *
     * @return array
     */
    public static function get_statistics() {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guest_appearances';

        return [
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table"),
            'verified' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE manually_verified = 1"),
        ];
    }
}
