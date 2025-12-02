<?php
/**
 * Background Refresh - Layer 3 System
 *
 * Automatically refreshes metrics for tracked podcasts
 * Uses tiered refresh frequency based on follower count:
 * 
 * < 1,000 followers     = 90 days
 * 1,000 - 10,000        = 60 days
 * 10,000 - 100,000      = 30 days
 * 100,000 - 1,000,000   = 14 days
 * > 1,000,000           = 7 days
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Background_Refresh {

    /**
     * Initialize background refresh
     */
    public static function init() {
        // Daily cron for checking expired metrics
        add_action('pit_background_refresh', [__CLASS__, 'run_refresh']);

        // Hourly cron for auto-enriching empty data
        add_action('pit_auto_enrich', [__CLASS__, 'run_auto_enrich']);

        // Daily cron for RSS metadata refresh
        add_action('pit_rss_metadata_refresh', [__CLASS__, 'run_rss_metadata_refresh']);

        // Schedule daily refresh check if not scheduled
        if (!wp_next_scheduled('pit_background_refresh')) {
            wp_schedule_event(time(), 'daily', 'pit_background_refresh');
        }

        // Schedule hourly auto-enrich for empty data
        if (!wp_next_scheduled('pit_auto_enrich')) {
            wp_schedule_event(time(), 'hourly', 'pit_auto_enrich');
        }

        // Schedule daily RSS metadata refresh
        if (!wp_next_scheduled('pit_rss_metadata_refresh')) {
            wp_schedule_event(time(), 'daily', 'pit_rss_metadata_refresh');
        }
    }

    /**
     * Run background refresh for expired metrics
     * 
     * This checks ALL tracked podcasts and queues refresh jobs
     * for any metrics that have expired based on their tier.
     */
    public static function run_refresh() {
        $settings = PIT_Settings::get_all();
        $budget_limit = $settings['weekly_budget'] ?? 50; // Default $50/week
        $current_week_cost = self::get_current_week_cost();

        // Check budget before starting
        if ($current_week_cost >= $budget_limit) {
            error_log('PIT Background Refresh: Weekly budget limit reached. Skipping refresh.');
            return;
        }

        // Get metrics that need refresh (uses tiered logic)
        $expired_metrics = PIT_Metrics_Fetcher::get_metrics_needing_refresh(50);

        if (empty($expired_metrics)) {
            error_log('PIT Background Refresh: No metrics need refresh.');
            return;
        }

        // Group by podcast_id
        $podcasts_to_refresh = [];
        foreach ($expired_metrics as $metric) {
            $podcasts_to_refresh[$metric->podcast_id][] = $metric->platform;
        }

        $jobs_queued = 0;
        $estimated_total = 0;

        foreach ($podcasts_to_refresh as $podcast_id => $platforms) {
            // Estimate cost for this podcast
            $estimated_cost = self::estimate_cost($platforms);

            // Check if we have budget
            if (($current_week_cost + $estimated_total + $estimated_cost) > $budget_limit) {
                error_log(sprintf(
                    'PIT Background Refresh: Stopping - would exceed budget. Queued %d jobs.',
                    $jobs_queued
                ));
                break;
            }

            // Queue refresh job
            $job_id = PIT_Job_Queue::queue_job(
                $podcast_id,
                'background_refresh',
                $platforms,
                30 // Lower priority for background jobs
            );

            if ($job_id) {
                $jobs_queued++;
                $estimated_total += $estimated_cost;
            }

            // Small delay between queueing
            usleep(50000); // 0.05 seconds
        }

        error_log(sprintf(
            'PIT Background Refresh: Queued %d jobs for %d podcasts. Estimated cost: $%.2f',
            $jobs_queued,
            count($podcasts_to_refresh),
            $estimated_total
        ));
    }

    /**
     * Run auto-enrichment for links with no metrics
     * 
     * This finds social links that have NEVER been enriched
     * and queues fetch jobs for them.
     */
    public static function run_auto_enrich() {
        $settings = PIT_Settings::get_all();
        $budget_limit = $settings['weekly_budget'] ?? 50;
        $current_week_cost = self::get_current_week_cost();

        // Check budget
        if ($current_week_cost >= $budget_limit) {
            return;
        }

        // Get unenriched links
        $unenriched = PIT_Metrics_Fetcher::get_unenriched_links(20);

        if (empty($unenriched)) {
            return;
        }

        // Group by podcast_id
        $podcasts_to_enrich = [];
        foreach ($unenriched as $link) {
            $podcasts_to_enrich[$link->podcast_id][] = $link->platform;
        }

        $jobs_queued = 0;

        foreach ($podcasts_to_enrich as $podcast_id => $platforms) {
            $estimated_cost = self::estimate_cost($platforms);

            if (($current_week_cost + $estimated_cost) > $budget_limit) {
                break;
            }

            $job_id = PIT_Job_Queue::queue_job(
                $podcast_id,
                'auto_enrich',
                $platforms,
                50 // Medium priority for auto-enrich
            );

            if ($job_id) {
                $jobs_queued++;
                $current_week_cost += $estimated_cost;
            }
        }

        if ($jobs_queued > 0) {
            error_log(sprintf('PIT Auto-Enrich: Queued %d jobs for unenriched links.', $jobs_queued));
        }
    }

    /**
     * Run RSS metadata refresh for stale podcasts
     *
     * Refreshes episode counts, frequencies, and dates for podcasts
     * that haven't had their RSS checked recently.
     *
     * Runs daily, processes podcasts not checked in 3+ days.
     * This is FREE (no API costs) - just RSS parsing.
     */
    public static function run_rss_metadata_refresh() {
        global $wpdb;

        $podcasts_table = $wpdb->prefix . 'pit_podcasts';

        // Find podcasts that haven't had RSS checked in 3+ days
        // Only refresh tracked podcasts or those with active interviews
        $stale_podcasts = $wpdb->get_results("
            SELECT id, rss_feed_url, title
            FROM $podcasts_table
            WHERE rss_feed_url IS NOT NULL 
              AND rss_feed_url != ''
              AND (
                  last_rss_check IS NULL 
                  OR last_rss_check < DATE_SUB(NOW(), INTERVAL 3 DAY)
              )
              AND (is_tracked = 1 OR is_active = 1)
            ORDER BY last_rss_check ASC
            LIMIT 20
        ");

        if (empty($stale_podcasts)) {
            error_log('PIT RSS Refresh: No podcasts need metadata refresh.');
            return;
        }

        $refreshed = 0;
        $failed = 0;

        foreach ($stale_podcasts as $podcast) {
            try {
                // Parse RSS feed for latest metadata
                $rss_data = PIT_RSS_Parser::parse($podcast->rss_feed_url);

                if (is_wp_error($rss_data)) {
                    error_log(sprintf(
                        'PIT RSS Refresh: Failed to parse %s - %s',
                        $podcast->title,
                        $rss_data->get_error_message()
                    ));
                    $failed++;

                    // Still update last_rss_check to avoid retrying constantly
                    $wpdb->update(
                        $podcasts_table,
                        ['last_rss_check' => current_time('mysql')],
                        ['id' => $podcast->id]
                    );
                    continue;
                }

                // Build update data
                $update_data = [
                    'episode_count'     => $rss_data['episode_count'] ?? 0,
                    'frequency'         => $rss_data['frequency'] ?? 'Unknown',
                    'average_duration'  => $rss_data['average_duration'] ?? null,
                    'last_episode_date' => $rss_data['last_episode_date'] ?? null,
                    'last_rss_check'    => current_time('mysql'),
                    'metadata_updated_at' => current_time('mysql'),
                ];

                // Update podcast in database
                PIT_Podcast_Repository::update($podcast->id, $update_data);
                $refreshed++;

            } catch (Exception $e) {
                error_log(sprintf(
                    'PIT RSS Refresh: Exception for %s - %s',
                    $podcast->title,
                    $e->getMessage()
                ));
                $failed++;
            }

            // Small delay to be polite to RSS servers
            usleep(500000); // 0.5 seconds
        }

        error_log(sprintf(
            'PIT RSS Refresh: Completed. Refreshed: %d, Failed: %d',
            $refreshed,
            $failed
        ));
    }

    /**
     * Estimate cost for platforms
     *
     * @param array $platforms Platform names
     * @return float Estimated cost in USD
     */
    private static function estimate_cost($platforms) {
        $costs = [
            'youtube' => 0,       // Free (YouTube Data API)
            'twitter' => 0.003,   // Apify ~$3/1000
            'instagram' => 0.005, // Apify ~$5/1000
            'facebook' => 0.005,  // Apify ~$5/1000
            'linkedin' => 0.004,  // Apify ~$4/1000
            'tiktok' => 0.003,    // Apify ~$3/1000
            'spotify' => 0,       // Free (scraping)
            'apple_podcasts' => 0, // Free (scraping)
        ];

        $total = 0;
        foreach ($platforms as $platform) {
            $total += $costs[$platform] ?? 0.005;
        }

        return $total;
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
     * Get refresh statistics with tier breakdown
     *
     * @return array Statistics
     */
    public static function get_statistics() {
        global $wpdb;
        $metrics_table = $wpdb->prefix . 'pit_metrics';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';

        // Get tier breakdown
        $tier_sql = "
            SELECT 
                CASE 
                    WHEN COALESCE(m.followers_count, m.subscriber_count, 0) < 1000 THEN 'small'
                    WHEN COALESCE(m.followers_count, m.subscriber_count, 0) < 10000 THEN 'growing'
                    WHEN COALESCE(m.followers_count, m.subscriber_count, 0) < 100000 THEN 'active'
                    WHEN COALESCE(m.followers_count, m.subscriber_count, 0) < 1000000 THEN 'popular'
                    ELSE 'high_profile'
                END as tier,
                COUNT(DISTINCT CONCAT(m.podcast_id, '-', m.platform)) as count
            FROM $metrics_table m
            INNER JOIN (
                SELECT podcast_id, platform, MAX(fetched_at) as max_fetch
                FROM $metrics_table
                GROUP BY podcast_id, platform
            ) latest ON m.podcast_id = latest.podcast_id 
                    AND m.platform = latest.platform 
                    AND m.fetched_at = latest.max_fetch
            GROUP BY tier
        ";

        $tier_counts = $wpdb->get_results($tier_sql, OBJECT_K);

        $stats = [
            'tracked_podcasts' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM $podcasts_table WHERE is_tracked = 1"
            ),
            'metrics_this_week' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM $metrics_table WHERE YEARWEEK(fetched_at) = YEARWEEK(NOW())"
            ),
            'cost_this_week' => self::get_current_week_cost(),
            'last_refresh' => $wpdb->get_var(
                "SELECT MAX(fetched_at) FROM $metrics_table"
            ),
            'tiers' => [
                'small' => [
                    'count' => (int) ($tier_counts['small']->count ?? 0),
                    'refresh_days' => 90,
                    'label' => '< 1K followers',
                ],
                'growing' => [
                    'count' => (int) ($tier_counts['growing']->count ?? 0),
                    'refresh_days' => 60,
                    'label' => '1K - 10K followers',
                ],
                'active' => [
                    'count' => (int) ($tier_counts['active']->count ?? 0),
                    'refresh_days' => 30,
                    'label' => '10K - 100K followers',
                ],
                'popular' => [
                    'count' => (int) ($tier_counts['popular']->count ?? 0),
                    'refresh_days' => 14,
                    'label' => '100K - 1M followers',
                ],
                'high_profile' => [
                    'count' => (int) ($tier_counts['high_profile']->count ?? 0),
                    'refresh_days' => 7,
                    'label' => '1M+ followers',
                ],
            ],
            'needing_refresh' => count(PIT_Metrics_Fetcher::get_metrics_needing_refresh(1000)),
            'unenriched' => count(PIT_Metrics_Fetcher::get_unenriched_links(1000)),
        ];

        return $stats;
    }

    /**
     * Get podcasts due for refresh with tier info
     *
     * @param int $limit Max results
     * @return array Podcasts needing refresh
     */
    public static function get_podcasts_due_for_refresh($limit = 50) {
        $expired = PIT_Metrics_Fetcher::get_metrics_needing_refresh($limit);

        $results = [];
        foreach ($expired as $metric) {
            $refresh_days = PIT_Metrics_Fetcher::get_refresh_days($metric->followers);
            $tier = PIT_Metrics_Fetcher::get_refresh_tier_description($metric->followers);

            $results[] = [
                'podcast_id' => $metric->podcast_id,
                'platform' => $metric->platform,
                'followers' => $metric->followers,
                'fetched_at' => $metric->fetched_at,
                'refresh_days' => $refresh_days,
                'tier' => $tier,
                'days_overdue' => floor((time() - strtotime($metric->fetched_at)) / 86400) - $refresh_days,
            ];
        }

        return $results;
    }
}
