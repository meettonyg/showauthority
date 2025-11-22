<?php
/**
 * Job Queue - Layer 2 System
 *
 * Manages async job processing for metrics fetching.
 * Uses WordPress cron for job processing (Action Scheduler alternative)
 *
 * When user clicks "Track" → Job queued → Processed async → Metrics saved
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Job_Queue {

    /**
     * Initialize job queue
     */
    public static function init() {
        // Hook for processing jobs
        add_action('pit_process_jobs', [__CLASS__, 'process_next_job']);

        // Schedule recurring job processor if not scheduled
        if (!wp_next_scheduled('pit_process_jobs')) {
            wp_schedule_event(time(), 'every_minute', 'pit_process_jobs');
        }

        // Add custom cron schedule
        add_filter('cron_schedules', [__CLASS__, 'add_cron_schedules']);
    }

    /**
     * Add custom cron schedules
     */
    public static function add_cron_schedules($schedules) {
        $schedules['every_minute'] = [
            'interval' => 60,
            'display' => __('Every Minute', 'podcast-influence-tracker'),
        ];

        return $schedules;
    }

    /**
     * Queue a new job
     *
     * @param int $podcast_id Podcast ID
     * @param string $job_type Job type (initial_tracking, background_refresh, manual_refresh)
     * @param array $platforms Platforms to fetch
     * @param int $priority Priority (0-100, higher = more important)
     * @return int|false Job ID or false on failure
     */
    public static function queue_job($podcast_id, $job_type = 'initial_tracking', $platforms = [], $priority = 50) {
        $podcast = PIT_Database::get_podcast($podcast_id);

        if (!$podcast) {
            return false;
        }

        // If no platforms specified, get all discovered platforms
        if (empty($platforms)) {
            $social_links = PIT_Database::get_social_links($podcast_id);
            $platforms = array_unique(array_column($social_links, 'platform'));
        }

        if (empty($platforms)) {
            return false;
        }

        // Estimate cost
        $estimated_cost = self::estimate_cost($platforms);

        // Create job
        $job_id = PIT_Database::create_job([
            'podcast_id' => $podcast_id,
            'job_type' => $job_type,
            'platforms_to_fetch' => json_encode($platforms),
            'status' => 'queued',
            'priority' => $priority,
            'estimated_cost_usd' => $estimated_cost,
        ]);

        // Update podcast tracking status
        PIT_Database::update_job($podcast_id, [
            'tracking_status' => 'queued',
        ]);

        // Trigger immediate processing (async)
        wp_schedule_single_event(time(), 'pit_process_jobs');

        return $job_id;
    }

    /**
     * Process next queued job
     */
    public static function process_next_job() {
        // Get next job
        $job = PIT_Database::get_next_job();

        if (!$job) {
            return;
        }

        // Check if we've exceeded max attempts
        if ($job->attempts >= $job->max_attempts) {
            PIT_Database::update_job($job->id, [
                'status' => 'failed',
                'error_message' => 'Maximum retry attempts exceeded',
            ]);
            return;
        }

        // Mark as processing
        PIT_Database::update_job($job->id, [
            'status' => 'processing',
            'attempts' => $job->attempts + 1,
            'started_at' => current_time('mysql'),
        ]);

        // Update podcast status
        global $wpdb;
        $table_podcasts = $wpdb->prefix . 'pit_podcasts';
        $wpdb->update(
            $table_podcasts,
            ['tracking_status' => 'processing'],
            ['id' => $job->podcast_id],
            ['%s'],
            ['%d']
        );

        // Decode platforms
        $platforms = json_decode($job->platforms_to_fetch, true);

        if (empty($platforms)) {
            PIT_Database::update_job($job->id, [
                'status' => 'failed',
                'error_message' => 'No platforms to fetch',
            ]);
            return;
        }

        // Process job
        $result = self::process_job($job, $platforms);

        if ($result['success']) {
            // Job completed successfully
            PIT_Database::update_job($job->id, [
                'status' => 'completed',
                'progress_percent' => 100,
                'actual_cost_usd' => $result['total_cost'],
                'completed_at' => current_time('mysql'),
            ]);

            // Update podcast status
            $wpdb->update(
                $table_podcasts,
                [
                    'tracking_status' => 'tracked',
                    'is_tracked' => 1,
                ],
                ['id' => $job->podcast_id],
                ['%s', '%d'],
                ['%d']
            );
        } else {
            // Job failed
            if ($job->attempts >= $job->max_attempts - 1) {
                PIT_Database::update_job($job->id, [
                    'status' => 'failed',
                    'error_message' => $result['error'],
                ]);

                $wpdb->update(
                    $table_podcasts,
                    ['tracking_status' => 'failed'],
                    ['id' => $job->podcast_id],
                    ['%s'],
                    ['%d']
                );
            } else {
                // Retry
                PIT_Database::update_job($job->id, [
                    'status' => 'queued',
                    'error_message' => $result['error'],
                ]);
            }
        }
    }

    /**
     * Process a job
     *
     * @param object $job Job object
     * @param array $platforms Platforms to fetch
     * @return array Result
     */
    private static function process_job($job, $platforms) {
        $total_platforms = count($platforms);
        $completed_platforms = 0;
        $total_cost = 0;
        $errors = [];

        foreach ($platforms as $platform) {
            try {
                // Update progress
                $progress = round(($completed_platforms / $total_platforms) * 100);
                PIT_Database::update_job($job->id, [
                    'progress_percent' => $progress,
                ]);

                // Fetch metrics for this platform
                $result = PIT_Metrics_Fetcher::fetch($job->podcast_id, $platform);

                if (is_wp_error($result)) {
                    $errors[] = sprintf('%s: %s', $platform, $result->get_error_message());
                } else {
                    $total_cost += $result['cost'];
                    $completed_platforms++;
                }

                // Small delay between API calls
                sleep(1);

            } catch (Exception $e) {
                $errors[] = sprintf('%s: %s', $platform, $e->getMessage());
            }
        }

        // Check if at least some platforms succeeded
        $success = $completed_platforms > 0;

        return [
            'success' => $success,
            'total_cost' => $total_cost,
            'completed_platforms' => $completed_platforms,
            'total_platforms' => $total_platforms,
            'error' => !empty($errors) ? implode('; ', $errors) : null,
        ];
    }

    /**
     * Estimate cost for platforms
     *
     * @param array $platforms Platforms to fetch
     * @return float Estimated cost in USD
     */
    private static function estimate_cost($platforms) {
        $costs = [
            'youtube' => 0, // Free (YouTube Data API)
            'twitter' => 0.05, // Apify
            'instagram' => 0.05, // Apify
            'facebook' => 0.05, // Apify
            'linkedin' => 0.05, // Apify
            'tiktok' => 0.05, // Apify
            'spotify' => 0, // Free (public data)
            'apple_podcasts' => 0, // Free (public data)
        ];

        $total = 0;
        foreach ($platforms as $platform) {
            $total += $costs[$platform] ?? 0.05;
        }

        return $total;
    }

    /**
     * Get job status
     *
     * @param int $job_id Job ID
     * @return object|null Job object
     */
    public static function get_job_status($job_id) {
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
     * @return array Jobs
     */
    public static function get_podcast_jobs($podcast_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_jobs';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE podcast_id = %d ORDER BY created_at DESC LIMIT 10",
            $podcast_id
        ));
    }

    /**
     * Cancel a job
     *
     * @param int $job_id Job ID
     * @return bool Success
     */
    public static function cancel_job($job_id) {
        $job = self::get_job_status($job_id);

        if (!$job || $job->status !== 'queued') {
            return false;
        }

        return PIT_Database::update_job($job_id, [
            'status' => 'failed',
            'error_message' => 'Cancelled by user',
        ]);
    }

    /**
     * Get queue statistics
     *
     * @return array Statistics
     */
    public static function get_statistics() {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_jobs';

        $stats = [
            'queued' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM $table WHERE status = 'queued'"
            ),
            'processing' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM $table WHERE status = 'processing'"
            ),
            'completed' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM $table WHERE status = 'completed'"
            ),
            'failed' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM $table WHERE status = 'failed'"
            ),
            'total_cost' => (float) $wpdb->get_var(
                "SELECT COALESCE(SUM(actual_cost_usd), 0) FROM $table WHERE status = 'completed'"
            ),
        ];

        return $stats;
    }

    /**
     * Retry failed job
     *
     * @param int $job_id Job ID
     * @return bool Success
     */
    public static function retry_job($job_id) {
        $job = self::get_job_status($job_id);

        if (!$job || $job->status !== 'failed') {
            return false;
        }

        return PIT_Database::update_job($job_id, [
            'status' => 'queued',
            'attempts' => 0,
            'error_message' => null,
            'progress_percent' => 0,
        ]);
    }
}
