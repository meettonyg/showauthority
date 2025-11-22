<?php
/**
 * Metrics Repository
 *
 * Handles database operations for social metrics history.
 *
 * @package PodcastInfluenceTracker
 * @subpackage SocialMetrics
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Metrics_Repository {

    /**
     * Get metric by ID
     *
     * @param int $metric_id Metric ID
     * @return object|null
     */
    public static function get($metric_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_metrics';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $metric_id
        ));
    }

    /**
     * Get latest metrics for a podcast (all platforms)
     *
     * @param int $podcast_id Podcast ID
     * @return array Metrics by platform
     */
    public static function get_latest_for_podcast($podcast_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_metrics';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT m.*
            FROM $table m
            INNER JOIN (
                SELECT platform, MAX(fetched_at) as max_date
                FROM $table
                WHERE podcast_id = %d
                GROUP BY platform
            ) latest ON m.platform = latest.platform AND m.fetched_at = latest.max_date
            WHERE m.podcast_id = %d",
            $podcast_id, $podcast_id
        ));
    }

    /**
     * Get latest metrics for a specific platform
     *
     * @param int $podcast_id Podcast ID
     * @param string $platform Platform name
     * @return object|null
     */
    public static function get_latest($podcast_id, $platform) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_metrics';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table
            WHERE podcast_id = %d AND platform = %s
            ORDER BY fetched_at DESC LIMIT 1",
            $podcast_id, $platform
        ));
    }

    /**
     * Get metrics history for a platform
     *
     * @param int $podcast_id Podcast ID
     * @param string $platform Platform name
     * @param int $limit Max records
     * @return array
     */
    public static function get_history($podcast_id, $platform, $limit = 10) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_metrics';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table
            WHERE podcast_id = %d AND platform = %s
            ORDER BY fetched_at DESC LIMIT %d",
            $podcast_id, $platform, $limit
        ));
    }

    /**
     * Create a metric record
     *
     * @param array $data Metric data
     * @return int|false Metric ID or false
     */
    public static function create($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_metrics';

        $wpdb->insert($table, $data);

        if ($wpdb->insert_id) {
            // Update cached values in social_links table
            if (!empty($data['social_link_id'])) {
                self::update_social_link_cache($data['social_link_id'], $data);
            }
        }

        return $wpdb->insert_id ?: false;
    }

    /**
     * Update cached metrics in social_links table
     *
     * @param int $link_id Social link ID
     * @param array $metrics Metric data
     */
    private static function update_social_link_cache($link_id, $metrics) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_social_links';

        $wpdb->update($table, [
            'followers_count' => $metrics['followers_count'] ?? null,
            'engagement_rate' => $metrics['engagement_rate'] ?? null,
            'metrics_enriched' => 1,
            'enriched_at' => current_time('mysql'),
        ], ['id' => $link_id]);
    }

    /**
     * Check if metrics are expired
     *
     * @param int $podcast_id Podcast ID
     * @param string $platform Platform name
     * @return bool
     */
    public static function is_expired($podcast_id, $platform) {
        $latest = self::get_latest($podcast_id, $platform);

        if (!$latest) {
            return true;
        }

        if (empty($latest->expires_at)) {
            return false;
        }

        return strtotime($latest->expires_at) < time();
    }

    /**
     * Get statistics
     *
     * @return array
     */
    public static function get_statistics() {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_metrics';

        return [
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table"),
            'this_week' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM $table WHERE YEARWEEK(fetched_at) = YEARWEEK(NOW())"
            ),
        ];
    }
}
