<?php
/**
 * PIT Database Helper Class
 *
 * Provides database helper methods for podcast and job operations.
 * Used by the job queue and other components.
 *
 * @package PodcastInfluenceTracker
 * @subpackage Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Database {

    /**
     * Get a podcast by ID
     *
     * @param int $podcast_id Podcast ID
     * @return object|null Podcast object or null
     */
    public static function get_podcast($podcast_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcasts';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $podcast_id
        ));
    }

    /**
     * Get social links for a podcast
     *
     * @param int $podcast_id Podcast ID
     * @return array Social links
     */
    public static function get_social_links($podcast_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_social_links';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE podcast_id = %d AND active = 1",
            $podcast_id
        ));
    }

    /**
     * Create a new job
     *
     * @param array $data Job data
     * @return int|false Job ID or false on failure
     */
    public static function create_job($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_jobs';

        // Add user_id if not present
        if (!isset($data['user_id'])) {
            $data['user_id'] = get_current_user_id();
        }

        $result = $wpdb->insert($table, $data);

        if ($result === false) {
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Update a job
     *
     * @param int   $job_id Job ID
     * @param array $data   Data to update
     * @return bool Success
     */
    public static function update_job($job_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_jobs';

        $result = $wpdb->update(
            $table,
            $data,
            ['id' => $job_id],
            null,
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Get next queued job (highest priority, oldest first)
     *
     * @return object|null Job object or null
     */
    public static function get_next_job() {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_jobs';

        return $wpdb->get_row(
            "SELECT * FROM $table 
             WHERE status = 'queued' 
             ORDER BY priority DESC, created_at ASC 
             LIMIT 1"
        );
    }

    /**
     * Get a job by ID
     *
     * @param int $job_id Job ID
     * @return object|null Job object or null
     */
    public static function get_job($job_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_jobs';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $job_id
        ));
    }

    /**
     * Get jobs for a podcast
     *
     * @param int $podcast_id Podcast ID
     * @param int $limit      Max results
     * @return array Jobs
     */
    public static function get_podcast_jobs($podcast_id, $limit = 10) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_jobs';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE podcast_id = %d ORDER BY created_at DESC LIMIT %d",
            $podcast_id,
            $limit
        ));
    }

    /**
     * Update a podcast
     *
     * @param int   $podcast_id Podcast ID
     * @param array $data       Data to update
     * @return bool Success
     */
    public static function update_podcast($podcast_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcasts';

        $result = $wpdb->update(
            $table,
            $data,
            ['id' => $podcast_id],
            null,
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Create a social link
     *
     * @param array $data Social link data
     * @return int|false Link ID or false
     */
    public static function create_social_link($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_social_links';

        $result = $wpdb->insert($table, $data);

        if ($result === false) {
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Update a social link
     *
     * @param int   $link_id Social link ID
     * @param array $data    Data to update
     * @return bool Success
     */
    public static function update_social_link($link_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_social_links';

        $result = $wpdb->update(
            $table,
            $data,
            ['id' => $link_id],
            null,
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Log a cost entry
     *
     * @param array $data Cost data
     * @return int|false Log ID or false
     */
    public static function log_cost($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_cost_log';

        // Add user_id if not present
        if (!isset($data['user_id'])) {
            $data['user_id'] = get_current_user_id();
        }

        $result = $wpdb->insert($table, $data);

        if ($result === false) {
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Save metrics snapshot
     *
     * @param array $data Metrics data
     * @return int|false Metrics ID or false
     */
    public static function save_metrics($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_metrics';

        $result = $wpdb->insert($table, $data);

        if ($result === false) {
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Get latest metrics for a social link
     *
     * @param int $social_link_id Social link ID
     * @return object|null Metrics or null
     */
    public static function get_latest_metrics($social_link_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_metrics';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE social_link_id = %d ORDER BY fetched_at DESC LIMIT 1",
            $social_link_id
        ));
    }

    /**
     * Get metrics history for a podcast
     *
     * @param int    $podcast_id Podcast ID
     * @param string $platform   Platform filter (optional)
     * @param int    $days       Days of history
     * @return array Metrics history
     */
    public static function get_metrics_history($podcast_id, $platform = null, $days = 30) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_metrics';

        $sql = "SELECT * FROM $table WHERE podcast_id = %d AND fetched_at >= DATE_SUB(NOW(), INTERVAL %d DAY)";
        $params = [$podcast_id, $days];

        if ($platform) {
            $sql .= " AND platform = %s";
            $params[] = $platform;
        }

        $sql .= " ORDER BY fetched_at DESC";

        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }
}
