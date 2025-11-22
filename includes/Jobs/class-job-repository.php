<?php
/**
 * Job Repository
 *
 * Handles database operations for background jobs.
 *
 * @package PodcastInfluenceTracker
 * @subpackage Jobs
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Job_Repository {

    /**
     * Get job by ID
     *
     * @param int $job_id Job ID
     * @return object|null
     */
    public static function get($job_id) {
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
     * @param int $limit Max records
     * @return array
     */
    public static function get_for_podcast($podcast_id, $limit = 10) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_jobs';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE podcast_id = %d ORDER BY created_at DESC LIMIT %d",
            $podcast_id, $limit
        ));
    }

    /**
     * Get next queued job
     *
     * @return object|null
     */
    public static function get_next() {
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
     * Create a job
     *
     * @param array $data Job data
     * @return int|false Job ID or false
     */
    public static function create($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_jobs';

        $defaults = [
            'status' => 'queued',
            'priority' => 50,
            'attempts' => 0,
            'max_attempts' => 3,
            'progress_percent' => 0,
        ];

        $data = wp_parse_args($data, $defaults);

        $wpdb->insert($table, $data);

        return $wpdb->insert_id ?: false;
    }

    /**
     * Update a job
     *
     * @param int $job_id Job ID
     * @param array $data Data to update
     * @return bool
     */
    public static function update($job_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_jobs';

        return $wpdb->update($table, $data, ['id' => $job_id]) !== false;
    }

    /**
     * Mark job as processing
     *
     * @param int $job_id Job ID
     * @return bool
     */
    public static function mark_processing($job_id) {
        $job = self::get($job_id);
        if (!$job) return false;

        return self::update($job_id, [
            'status' => 'processing',
            'attempts' => $job->attempts + 1,
            'started_at' => current_time('mysql'),
        ]);
    }

    /**
     * Mark job as completed
     *
     * @param int $job_id Job ID
     * @param float $cost Actual cost in USD
     * @return bool
     */
    public static function mark_completed($job_id, $cost = 0) {
        return self::update($job_id, [
            'status' => 'completed',
            'progress_percent' => 100,
            'actual_cost_usd' => $cost,
            'completed_at' => current_time('mysql'),
        ]);
    }

    /**
     * Mark job as failed
     *
     * @param int $job_id Job ID
     * @param string $error Error message
     * @return bool
     */
    public static function mark_failed($job_id, $error = '') {
        return self::update($job_id, [
            'status' => 'failed',
            'error_message' => $error,
        ]);
    }

    /**
     * Requeue a failed job
     *
     * @param int $job_id Job ID
     * @return bool
     */
    public static function requeue($job_id) {
        return self::update($job_id, [
            'status' => 'queued',
            'attempts' => 0,
            'error_message' => null,
            'progress_percent' => 0,
        ]);
    }

    /**
     * Get statistics
     *
     * @return array
     */
    public static function get_statistics() {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_jobs';

        return [
            'queued' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'queued'"),
            'processing' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'processing'"),
            'completed' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'completed'"),
            'failed' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE status = 'failed'"),
            'total_cost' => (float) $wpdb->get_var("SELECT COALESCE(SUM(actual_cost_usd), 0) FROM $table WHERE status = 'completed'"),
        ];
    }
}
