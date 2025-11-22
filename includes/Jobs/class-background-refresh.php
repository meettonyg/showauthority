<?php
/**
 * Background Refresh - Layer 3 System
 *
 * Automatically refreshes metrics for tracked podcasts
 * Runs weekly to keep data fresh
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Background_Refresh {

    /**
     * Initialize background refresh
     */
    public static function init() {
        add_action('pit_background_refresh', [__CLASS__, 'run_refresh']);
    }

    /**
     * Run background refresh for all tracked podcasts
     */
    public static function run_refresh() {
        global $wpdb;
        $table_podcasts = $wpdb->prefix . 'pit_podcasts';

        // Get all tracked podcasts
        $podcasts = $wpdb->get_results(
            "SELECT id FROM $table_podcasts WHERE is_tracked = 1 AND tracking_status = 'tracked'"
        );

        if (empty($podcasts)) {
            return;
        }

        $settings = PIT_Settings::get_all();
        $budget_limit = $settings['weekly_budget'] ?? 50; // Default $50/week
        $current_week_cost = self::get_current_week_cost();

        foreach ($podcasts as $podcast) {
            // Check budget
            if ($current_week_cost >= $budget_limit) {
                error_log('PIT: Weekly budget limit reached. Stopping refresh.');
                break;
            }

            // Get platforms that need refresh
            $platforms = self::get_platforms_needing_refresh($podcast->id);

            if (empty($platforms)) {
                continue;
            }

            // Estimate cost
            $estimated_cost = PIT_Job_Queue::estimate_cost($platforms);

            // Check if we have budget
            if (($current_week_cost + $estimated_cost) > $budget_limit) {
                error_log(sprintf(
                    'PIT: Skipping podcast %d - would exceed budget (current: $%.2f, limit: $%.2f)',
                    $podcast->id,
                    $current_week_cost,
                    $budget_limit
                ));
                continue;
            }

            // Queue refresh job
            $job_id = PIT_Job_Queue::queue_job(
                $podcast->id,
                'background_refresh',
                $platforms,
                30 // Lower priority for background jobs
            );

            if ($job_id) {
                $current_week_cost += $estimated_cost;
            }

            // Small delay between queueing
            usleep(100000); // 0.1 seconds
        }
    }

    /**
     * Get platforms that need refresh for a podcast
     *
     * @param int $podcast_id Podcast ID
     * @return array Platforms needing refresh
     */
    private static function get_platforms_needing_refresh($podcast_id) {
        global $wpdb;
        $table_metrics = $wpdb->prefix . 'pit_metrics';
        $table_social = $wpdb->prefix . 'pit_social_links';

        // Get all platforms that either:
        // 1. Have no metrics yet
        // 2. Have expired metrics
        $sql = "
            SELECT DISTINCT s.platform
            FROM $table_social s
            LEFT JOIN (
                SELECT platform, MAX(fetched_at) as last_fetch, expires_at
                FROM $table_metrics
                WHERE podcast_id = %d
                GROUP BY platform
            ) m ON s.platform = m.platform
            WHERE s.podcast_id = %d
            AND (
                m.platform IS NULL
                OR m.expires_at < NOW()
            )
        ";

        $results = $wpdb->get_col($wpdb->prepare($sql, $podcast_id, $podcast_id));

        return $results;
    }

    /**
     * Get current week's total cost
     *
     * @return float Total cost this week
     */
    private static function get_current_week_cost() {
        global $wpdb;
        $table_cost = $wpdb->prefix . 'pit_cost_log';

        $sql = "
            SELECT COALESCE(SUM(cost_usd), 0)
            FROM $table_cost
            WHERE YEARWEEK(logged_at) = YEARWEEK(NOW())
        ";

        return (float) $wpdb->get_var($sql);
    }

    /**
     * Manually trigger refresh for a specific podcast
     *
     * @param int $podcast_id Podcast ID
     * @param array $platforms Optional specific platforms
     * @return int|false Job ID or false
     */
    public static function manual_refresh($podcast_id, $platforms = []) {
        $podcast = PIT_Database::get_podcast($podcast_id);

        if (!$podcast) {
            return false;
        }

        // If no platforms specified, refresh all
        if (empty($platforms)) {
            $social_links = PIT_Database::get_social_links($podcast_id);
            $platforms = array_column($social_links, 'platform');
        }

        // Queue high-priority job
        return PIT_Job_Queue::queue_job(
            $podcast_id,
            'manual_refresh',
            $platforms,
            80 // High priority for manual refreshes
        );
    }

    /**
     * Get refresh statistics
     *
     * @return array Statistics
     */
    public static function get_statistics() {
        global $wpdb;
        $table_podcasts = $wpdb->prefix . 'pit_podcasts';
        $table_metrics = $wpdb->prefix . 'pit_metrics';

        $stats = [
            'tracked_podcasts' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM $table_podcasts WHERE is_tracked = 1"
            ),
            'metrics_this_week' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM $table_metrics WHERE YEARWEEK(fetched_at) = YEARWEEK(NOW())"
            ),
            'cost_this_week' => self::get_current_week_cost(),
            'last_refresh' => $wpdb->get_var(
                "SELECT MAX(fetched_at) FROM $table_metrics"
            ),
        ];

        return $stats;
    }

    /**
     * Get podcasts due for refresh
     *
     * @return array Podcasts needing refresh
     */
    public static function get_podcasts_due_for_refresh() {
        global $wpdb;
        $table_podcasts = $wpdb->prefix . 'pit_podcasts';
        $table_metrics = $wpdb->prefix . 'pit_metrics';

        $sql = "
            SELECT p.*, COUNT(DISTINCT m.platform) as platforms_count
            FROM $table_podcasts p
            LEFT JOIN $table_metrics m ON p.id = m.podcast_id
            WHERE p.is_tracked = 1
            AND (
                m.expires_at IS NULL
                OR m.expires_at < NOW()
            )
            GROUP BY p.id
            ORDER BY p.updated_at DESC
        ";

        return $wpdb->get_results($sql);
    }
}
