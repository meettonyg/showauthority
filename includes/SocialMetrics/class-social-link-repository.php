<?php
/**
 * Social Link Repository
 *
 * Handles database operations for social media links.
 *
 * @package PodcastInfluenceTracker
 * @subpackage SocialMetrics
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Social_Link_Repository {

    /**
     * Get social link by ID
     *
     * @param int $link_id Social link ID
     * @return object|null Social link or null
     */
    public static function get($link_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_social_links';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $link_id
        ));
    }

    /**
     * Get all social links for a podcast
     *
     * @param int $podcast_id Podcast ID
     * @return array Social links
     */
    public static function get_for_podcast($podcast_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_social_links';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE podcast_id = %d AND active = 1 ORDER BY platform",
            $podcast_id
        ));
    }

    /**
     * Get social link by podcast and platform
     *
     * @param int $podcast_id Podcast ID
     * @param string $platform Platform name
     * @return object|null Social link or null
     */
    public static function get_by_platform($podcast_id, $platform) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_social_links';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE podcast_id = %d AND platform = %s",
            $podcast_id, $platform
        ));
    }

    /**
     * Create a social link
     *
     * @param array $data Social link data
     * @return int|false Link ID or false
     */
    public static function create($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_social_links';

        $wpdb->insert($table, $data);

        return $wpdb->insert_id ?: false;
    }

    /**
     * Update a social link
     *
     * @param int $link_id Link ID
     * @param array $data Data to update
     * @return bool Success
     */
    public static function update($link_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_social_links';

        unset($data['created_at']);
        unset($data['discovered_at']);

        return $wpdb->update($table, $data, ['id' => $link_id]) !== false;
    }

    /**
     * Insert or update social link
     *
     * @param array $data Social link data (must include podcast_id and platform)
     * @return int Link ID
     */
    public static function upsert($data) {
        $existing = self::get_by_platform($data['podcast_id'], $data['platform']);

        if ($existing) {
            self::update($existing->id, $data);
            return $existing->id;
        }

        return self::create($data);
    }

    /**
     * Delete a social link
     *
     * @param int $link_id Link ID
     * @return bool Success
     */
    public static function delete($link_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_social_links';

        return $wpdb->delete($table, ['id' => $link_id], ['%d']) !== false;
    }

    /**
     * Get statistics
     *
     * @return array Statistics
     */
    public static function get_statistics() {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_social_links';

        $stats = [
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table"),
            'by_platform' => [],
            'by_source' => [],
        ];

        // By platform
        $platform_counts = $wpdb->get_results(
            "SELECT platform, COUNT(*) as count FROM $table GROUP BY platform"
        );
        foreach ($platform_counts as $row) {
            $stats['by_platform'][$row->platform] = (int) $row->count;
        }

        // By source
        $source_counts = $wpdb->get_results(
            "SELECT discovery_source, COUNT(*) as count FROM $table GROUP BY discovery_source"
        );
        foreach ($source_counts as $row) {
            $stats['by_source'][$row->discovery_source] = (int) $row->count;
        }

        return $stats;
    }
}
