<?php
/**
 * Metrics Fetcher - Layer 2 Component
 *
 * Fetches social media metrics from various platforms
 * Routes to appropriate API integration based on platform
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Metrics_Fetcher {

    /**
     * Fetch metrics for a platform
     *
     * @param int $podcast_id Podcast ID
     * @param string $platform Platform name
     * @return array|WP_Error Result with metrics and cost
     */
    public static function fetch($podcast_id, $platform) {
        $start_time = microtime(true);

        // Get social link for this platform
        $social_links = PIT_Database::get_social_links($podcast_id);
        $link = null;

        foreach ($social_links as $l) {
            if ($l->platform === $platform) {
                $link = $l;
                break;
            }
        }

        if (!$link) {
            return new WP_Error('no_link', 'No social link found for this platform');
        }

        // Route to appropriate fetcher
        $result = null;
        $cost = 0;

        switch ($platform) {
            case 'youtube':
                $result = PIT_YouTube_API::fetch_metrics($link->profile_url, $link->profile_handle);
                $cost = 0; // YouTube API is free
                break;

            case 'twitter':
            case 'instagram':
            case 'facebook':
            case 'linkedin':
            case 'tiktok':
                $result = PIT_Apify_Client::fetch_metrics($platform, $link->profile_url, $link->profile_handle);
                $cost = $result['cost'] ?? 0.05;
                break;

            case 'spotify':
            case 'apple_podcasts':
                $result = self::fetch_podcast_platform_metrics($platform, $link->profile_url);
                $cost = 0; // Public data is free
                break;

            default:
                return new WP_Error('unsupported_platform', 'Platform not supported: ' . $platform);
        }

        if (is_wp_error($result)) {
            return $result;
        }

        $duration = microtime(true) - $start_time;

        // Save metrics to database
        $metrics_data = [
            'podcast_id' => $podcast_id,
            'platform' => $platform,
            'followers_count' => $result['followers'] ?? 0,
            'following_count' => $result['following'] ?? 0,
            'posts_count' => $result['posts'] ?? 0,
            'engagement_rate' => $result['engagement_rate'] ?? 0,
            'avg_likes' => $result['avg_likes'] ?? 0,
            'avg_comments' => $result['avg_comments'] ?? 0,
            'avg_shares' => $result['avg_shares'] ?? 0,
            'total_views' => $result['total_views'] ?? 0,
            'subscriber_count' => $result['subscribers'] ?? 0,
            'video_count' => $result['videos'] ?? 0,
            'api_response' => json_encode($result['raw_data'] ?? []),
            'cost_usd' => $cost,
            'fetch_duration_seconds' => $duration,
            'fetched_at' => current_time('mysql'),
            'expires_at' => self::calculate_expiry(),
        ];

        $metrics_id = PIT_Database::insert_metrics($metrics_data);

        if (!$metrics_id) {
            return new WP_Error('db_save_failed', 'Failed to save metrics to database');
        }

        // Log cost
        PIT_Database::log_cost([
            'podcast_id' => $podcast_id,
            'action_type' => 'enrichment',
            'platform' => $platform,
            'cost_usd' => $cost,
            'api_provider' => self::get_api_provider($platform),
            'success' => 1,
            'metadata' => json_encode(['metrics_id' => $metrics_id]),
        ]);

        return [
            'success' => true,
            'metrics_id' => $metrics_id,
            'cost' => $cost,
            'duration' => $duration,
            'metrics' => $metrics_data,
        ];
    }

    /**
     * Fetch podcast platform metrics (Spotify, Apple Podcasts)
     *
     * @param string $platform Platform name
     * @param string $url Profile URL
     * @return array Metrics data
     */
    private static function fetch_podcast_platform_metrics($platform, $url) {
        // For now, return placeholder data
        // In production, you would scrape public data or use APIs

        switch ($platform) {
            case 'spotify':
                return self::scrape_spotify_data($url);

            case 'apple_podcasts':
                return self::scrape_apple_podcasts_data($url);

            default:
                return new WP_Error('unsupported', 'Platform not yet implemented');
        }
    }

    /**
     * Scrape Spotify public data
     */
    private static function scrape_spotify_data($url) {
        // Spotify doesn't expose follower counts publicly in an easy way
        // You would need to either:
        // 1. Use Spotify Web API (requires authentication)
        // 2. Scrape the page (unreliable)
        // 3. Use a service like Chartable or Podchaser

        return [
            'followers' => 0,
            'episodes' => 0,
            'raw_data' => ['note' => 'Spotify metrics require API integration'],
        ];
    }

    /**
     * Scrape Apple Podcasts public data
     */
    private static function scrape_apple_podcasts_data($url) {
        // Apple Podcasts doesn't expose follower counts publicly
        // You would need to scrape reviews/ratings

        $response = wp_remote_get($url, [
            'timeout' => 10,
            'user-agent' => 'Mozilla/5.0 (compatible; Podcast Influence Tracker/1.0)',
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('fetch_failed', 'Failed to fetch Apple Podcasts data');
        }

        $html = wp_remote_retrieve_body($response);

        // Try to extract ratings count
        $ratings = 0;
        if (preg_match('/(\d+(?:,\d+)*)\s+Ratings?/', $html, $matches)) {
            $ratings = (int) str_replace(',', '', $matches[1]);
        }

        return [
            'followers' => $ratings, // Use ratings as proxy for followers
            'posts' => 0,
            'raw_data' => ['ratings_count' => $ratings],
        ];
    }

    /**
     * Calculate cache expiry time
     *
     * @return string MySQL datetime
     */
    private static function calculate_expiry() {
        // Metrics expire after 7 days
        return date('Y-m-d H:i:s', strtotime('+7 days'));
    }

    /**
     * Get API provider for platform
     *
     * @param string $platform Platform name
     * @return string API provider
     */
    private static function get_api_provider($platform) {
        $providers = [
            'youtube' => 'youtube',
            'twitter' => 'apify',
            'instagram' => 'apify',
            'facebook' => 'apify',
            'linkedin' => 'apify',
            'tiktok' => 'apify',
            'spotify' => 'other',
            'apple_podcasts' => 'other',
        ];

        return $providers[$platform] ?? 'other';
    }

    /**
     * Check if metrics are cached and still valid
     *
     * @param int $podcast_id Podcast ID
     * @param string $platform Platform name
     * @return bool True if cached and valid
     */
    public static function is_cached($podcast_id, $platform) {
        $metrics = PIT_Database::get_latest_metrics($podcast_id, $platform);

        if (!$metrics) {
            return false;
        }

        // Check if expired
        if (strtotime($metrics->expires_at) < time()) {
            return false;
        }

        return true;
    }

    /**
     * Get cached metrics
     *
     * @param int $podcast_id Podcast ID
     * @param string $platform Platform name
     * @return object|null Metrics object
     */
    public static function get_cached($podcast_id, $platform) {
        if (!self::is_cached($podcast_id, $platform)) {
            return null;
        }

        return PIT_Database::get_latest_metrics($podcast_id, $platform);
    }

    /**
     * Invalidate cache for a podcast
     *
     * @param int $podcast_id Podcast ID
     * @param string $platform Optional platform to invalidate
     */
    public static function invalidate_cache($podcast_id, $platform = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_metrics';

        $where = ['podcast_id' => $podcast_id];

        if ($platform) {
            $where['platform'] = $platform;
        }

        // Set expiry to past
        $wpdb->update(
            $table,
            ['expires_at' => date('Y-m-d H:i:s', strtotime('-1 day'))],
            $where,
            ['%s'],
            $platform ? ['%d', '%s'] : ['%d']
        );
    }
}
