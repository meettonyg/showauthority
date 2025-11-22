<?php
/**
 * Content Analysis Repository
 *
 * Handles database operations for podcast content analysis.
 *
 * @package PodcastInfluenceTracker
 * @subpackage Podcasts
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Content_Analysis_Repository {

    /**
     * Get content analysis by ID
     *
     * @param int $analysis_id Analysis ID
     * @return object|null
     */
    public static function get($analysis_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_content_analysis';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $analysis_id
        ));
    }

    /**
     * Get content analysis for a podcast
     *
     * @param int $podcast_id Podcast ID
     * @return object|null
     */
    public static function get_for_podcast($podcast_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_content_analysis';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE podcast_id = %d",
            $podcast_id
        ));
    }

    /**
     * Create content analysis
     *
     * @param array $data Analysis data
     * @return int|false Analysis ID or false
     */
    public static function create($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_content_analysis';

        $wpdb->insert($table, $data);

        return $wpdb->insert_id ?: false;
    }

    /**
     * Update content analysis
     *
     * @param int $analysis_id Analysis ID
     * @param array $data Data to update
     * @return bool
     */
    public static function update($analysis_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_content_analysis';

        unset($data['created_at']);

        return $wpdb->update($table, $data, ['id' => $analysis_id]) !== false;
    }

    /**
     * Insert or update content analysis
     *
     * @param int $podcast_id Podcast ID
     * @param array $data Analysis data
     * @return int Analysis ID
     */
    public static function upsert($podcast_id, $data) {
        $existing = self::get_for_podcast($podcast_id);

        $data['podcast_id'] = $podcast_id;

        if ($existing) {
            self::update($existing->id, $data);
            return $existing->id;
        }

        return self::create($data);
    }

    /**
     * Delete content analysis
     *
     * @param int $analysis_id Analysis ID
     * @return bool
     */
    public static function delete($analysis_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_content_analysis';

        return $wpdb->delete($table, ['id' => $analysis_id], ['%d']) !== false;
    }

    /**
     * Check if analysis is expired
     *
     * @param int $podcast_id Podcast ID
     * @return bool
     */
    public static function is_expired($podcast_id) {
        $analysis = self::get_for_podcast($podcast_id);

        if (!$analysis || empty($analysis->cache_expires_at)) {
            return true;
        }

        return strtotime($analysis->cache_expires_at) < time();
    }
}
