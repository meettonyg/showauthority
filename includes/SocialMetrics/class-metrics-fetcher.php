<?php
/**
 * Metrics Fetcher - Layer 2 Component
 *
 * Fetches social media metrics from various platforms
 * Routes to appropriate API integration based on platform
 * 
 * Features:
 * - Auto-fetch when data is empty
 * - Tiered refresh frequency based on follower count
 * - Smart caching to minimize API costs
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Metrics_Fetcher {

    /**
     * Tiered refresh intervals based on follower count (in days)
     * 
     * < 1,000 followers     = 90 days (small channels grow slowly)
     * 1,000 - 10,000        = 60 days (moderate growth)
     * 10,000 - 100,000      = 30 days (active growth phase)
     * 100,000 - 1,000,000   = 14 days (rapid changes possible)
     * > 1,000,000           = 7 days  (high-profile, fast-moving)
     */
    const REFRESH_TIERS = [
        1000      => 90,  // < 1K followers
        10000     => 60,  // 1K - 10K followers
        100000    => 30,  // 10K - 100K followers
        1000000   => 14,  // 100K - 1M followers
        PHP_INT_MAX => 7, // > 1M followers
    ];

    /**
     * Default refresh interval for new/unknown accounts (in days)
     */
    const DEFAULT_REFRESH_DAYS = 30;

    /**
     * Get or fetch metrics for a platform (auto-fetch if empty)
     * 
     * This is the primary method to call when displaying metrics.
     * It will automatically queue a fetch job if data is empty or expired.
     *
     * @param int $podcast_id Podcast ID
     * @param string $platform Platform name
     * @param bool $queue_if_empty Whether to queue fetch if empty (default: true)
     * @return object|null Metrics object or null
     */
    public static function get_or_fetch($podcast_id, $platform, $queue_if_empty = true) {
        // Check for existing valid metrics
        $metrics = PIT_Database::get_latest_metrics($podcast_id, $platform);

        if ($metrics) {
            // Check if expired based on tiered refresh
            $followers = (int) ($metrics->followers_count ?? $metrics->subscriber_count ?? 0);
            $refresh_days = self::get_refresh_days($followers);
            $expires_at = strtotime($metrics->fetched_at . " + {$refresh_days} days");

            if (time() < $expires_at) {
                // Still valid, return cached data
                return $metrics;
            }
        }

        // No metrics or expired - queue a fetch if requested
        if ($queue_if_empty) {
            self::queue_fetch_if_needed($podcast_id, $platform);
        }

        // Return whatever we have (might be stale but better than nothing)
        return $metrics;
    }

    /**
     * Queue a fetch job if not already queued
     *
     * @param int $podcast_id Podcast ID
     * @param string $platform Platform name
     * @return int|false Job ID or false if already queued/not needed
     */
    public static function queue_fetch_if_needed($podcast_id, $platform) {
        // Check if there's already a pending job for this platform
        global $wpdb;
        $jobs_table = $wpdb->prefix . 'pit_jobs';

        $pending_job = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $jobs_table 
             WHERE podcast_id = %d 
             AND status IN ('queued', 'processing')
             AND platforms_to_fetch LIKE %s
             LIMIT 1",
            $podcast_id,
            '%"' . $platform . '"%'
        ));

        if ($pending_job) {
            return false; // Already queued
        }

        // Queue the fetch job
        if (class_exists('PIT_Job_Queue')) {
            return PIT_Job_Queue::queue_job($podcast_id, 'auto_fetch', [$platform], 60);
        }

        return false;
    }

    /**
     * Get refresh interval in days based on follower count
     *
     * @param int $followers Follower count
     * @return int Days until refresh
     */
    public static function get_refresh_days($followers) {
        foreach (self::REFRESH_TIERS as $threshold => $days) {
            if ($followers < $threshold) {
                return $days;
            }
        }
        return self::DEFAULT_REFRESH_DAYS;
    }

    /**
     * Get human-readable refresh tier description
     *
     * @param int $followers Follower count
     * @return string Description
     */
    public static function get_refresh_tier_description($followers) {
        $days = self::get_refresh_days($followers);
        $tier_names = [
            90 => 'Small (< 1K)',
            60 => 'Growing (1K - 10K)',
            30 => 'Active (10K - 100K)',
            14 => 'Popular (100K - 1M)',
            7  => 'High-profile (1M+)',
        ];
        return $tier_names[$days] ?? 'Standard';
    }

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

        // Calculate tiered expiry based on follower count
        $followers = (int) ($result['followers'] ?? $result['subscribers'] ?? 0);
        $refresh_days = self::get_refresh_days($followers);

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
            'expires_at' => self::calculate_expiry($followers),
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
            'metadata' => json_encode([
                'metrics_id' => $metrics_id,
                'refresh_days' => $refresh_days,
                'followers' => $followers,
                'tier' => self::get_refresh_tier_description($followers),
            ]),
        ]);

        return [
            'success' => true,
            'metrics_id' => $metrics_id,
            'cost' => $cost,
            'duration' => $duration,
            'refresh_days' => $refresh_days,
            'next_refresh' => date('Y-m-d', strtotime("+{$refresh_days} days")),
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
     *
     * Extracts available public data from Spotify podcast pages including
     * show description metadata and episode counts. Note: Follower counts
     * are not publicly exposed by Spotify.
     */
    private static function scrape_spotify_data($url) {
        // Normalize URL to ensure we have the right format
        $url = self::normalize_spotify_url($url);

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ],
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('fetch_failed', 'Failed to fetch Spotify data: ' . $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return new WP_Error('http_error', 'Spotify returned HTTP ' . $status_code);
        }

        $html = wp_remote_retrieve_body($response);
        $data = [
            'followers' => 0,
            'posts' => 0,
            'total_views' => 0,
            'raw_data' => [],
        ];

        // Try to extract show ID from URL
        if (preg_match('/show\/([a-zA-Z0-9]+)/', $url, $matches)) {
            $data['raw_data']['show_id'] = $matches[1];
        }

        // Try to find JSON-LD data embedded in page
        if (preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches)) {
            $json_ld = json_decode($matches[1], true);
            if ($json_ld && isset($json_ld['@type']) && $json_ld['@type'] === 'PodcastSeries') {
                $data['raw_data']['name'] = $json_ld['name'] ?? '';
                $data['raw_data']['description'] = $json_ld['description'] ?? '';
                $data['raw_data']['publisher'] = $json_ld['publisher']['name'] ?? '';

                // Count episodes if available
                if (isset($json_ld['episode']) && is_array($json_ld['episode'])) {
                    $data['posts'] = count($json_ld['episode']);
                }
            }
        }

        // Try to extract episode count from meta or page content
        if (preg_match('/(\d+)\s*episodes?/i', $html, $matches)) {
            $episode_count = (int) $matches[1];
            if ($episode_count > $data['posts']) {
                $data['posts'] = $episode_count;
            }
        }

        // Extract show title from og:title
        if (preg_match('/<meta property="og:title" content="([^"]+)"/', $html, $matches)) {
            $data['raw_data']['title'] = html_entity_decode($matches[1]);
        }

        // Note about limitations
        $data['raw_data']['note'] = 'Spotify does not publicly expose follower counts. Episode count extracted where available.';
        $data['raw_data']['scraped_at'] = current_time('mysql');

        return $data;
    }

    /**
     * Normalize Spotify URL to the open.spotify.com format
     */
    private static function normalize_spotify_url($url) {
        // Handle spotify: URI format
        if (strpos($url, 'spotify:show:') === 0) {
            $show_id = str_replace('spotify:show:', '', $url);
            return 'https://open.spotify.com/show/' . $show_id;
        }

        // Ensure https
        if (strpos($url, 'http://') === 0) {
            $url = str_replace('http://', 'https://', $url);
        }

        return $url;
    }

    /**
     * Scrape Apple Podcasts public data
     *
     * Extracts ratings, reviews, episode counts and other metadata from
     * Apple Podcasts pages. Uses ratings count as a proxy for popularity.
     */
    private static function scrape_apple_podcasts_data($url) {
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'user-agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5',
            ],
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('fetch_failed', 'Failed to fetch Apple Podcasts data: ' . $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return new WP_Error('http_error', 'Apple Podcasts returned HTTP ' . $status_code);
        }

        $html = wp_remote_retrieve_body($response);
        $data = [
            'followers' => 0,
            'posts' => 0,
            'engagement_rate' => 0,
            'raw_data' => [],
        ];

        // Extract podcast ID from URL
        if (preg_match('/id(\d+)/', $url, $matches)) {
            $data['raw_data']['podcast_id'] = $matches[1];
        }

        // Extract ratings count (multiple patterns)
        $ratings = 0;
        $patterns = [
            '/(\d+(?:,\d+)*)\s+Ratings?/i',
            '/(\d+(?:,\d+)*)\s+reviews?/i',
            '/"ratingCount"[:\s]*(\d+)/i',
            '/data-test-rating-count[^>]*>(\d+(?:,\d+)*)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $found_ratings = (int) str_replace(',', '', $matches[1]);
                if ($found_ratings > $ratings) {
                    $ratings = $found_ratings;
                }
            }
        }
        $data['followers'] = $ratings; // Use ratings as popularity proxy
        $data['raw_data']['ratings_count'] = $ratings;

        // Extract average rating
        if (preg_match('/(\d+(?:\.\d+)?)\s*out of\s*5/i', $html, $matches)) {
            $data['raw_data']['average_rating'] = floatval($matches[1]);
            $data['engagement_rate'] = round(floatval($matches[1]) * 20, 2); // Convert 5-star to percentage
        } elseif (preg_match('/"ratingValue"[:\s]*"?(\d+(?:\.\d+)?)"?/i', $html, $matches)) {
            $data['raw_data']['average_rating'] = floatval($matches[1]);
            $data['engagement_rate'] = round(floatval($matches[1]) * 20, 2);
        }

        // Extract episode count
        $episode_patterns = [
            '/(\d+(?:,\d+)*)\s+episodes?/i',
            '/"numberOfEpisodes"[:\s]*(\d+)/i',
        ];

        foreach ($episode_patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $data['posts'] = (int) str_replace(',', '', $matches[1]);
                break;
            }
        }

        // Extract show title from og:title or JSON-LD
        if (preg_match('/<meta property="og:title" content="([^"]+)"/', $html, $matches)) {
            $data['raw_data']['title'] = html_entity_decode($matches[1]);
        }

        // Try to get publisher/author
        if (preg_match('/"author"[:\s]*\{[^}]*"name"[:\s]*"([^"]+)"/i', $html, $matches)) {
            $data['raw_data']['publisher'] = $matches[1];
        }

        // Extract category
        if (preg_match('/"genre"[:\s]*"([^"]+)"/i', $html, $matches)) {
            $data['raw_data']['category'] = $matches[1];
        }

        $data['raw_data']['note'] = 'Apple Podcasts does not expose subscriber counts. Ratings used as popularity proxy.';
        $data['raw_data']['scraped_at'] = current_time('mysql');

        return $data;
    }

    /**
     * Calculate cache expiry time based on follower count
     *
     * @param int $followers Follower count (0 for unknown/new)
     * @return string MySQL datetime
     */
    private static function calculate_expiry($followers = 0) {
        $refresh_days = self::get_refresh_days($followers);
        return date('Y-m-d H:i:s', strtotime("+{$refresh_days} days"));
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
     * Check if metrics are cached and still valid (using tiered expiry)
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

        // Use tiered expiry check
        $followers = (int) ($metrics->followers_count ?? $metrics->subscriber_count ?? 0);
        $refresh_days = self::get_refresh_days($followers);
        $expires_at = strtotime($metrics->fetched_at . " + {$refresh_days} days");

        return time() < $expires_at;
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

    /**
     * Get metrics needing refresh across all platforms
     * 
     * Used by background refresh job to find expired metrics
     *
     * @param int $limit Max records to return
     * @return array Array of [podcast_id, platform, followers, fetched_at]
     */
    public static function get_metrics_needing_refresh($limit = 100) {
        global $wpdb;
        $metrics_table = $wpdb->prefix . 'pit_metrics';
        $social_table = $wpdb->prefix . 'pit_social_links';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';

        // Get latest metrics per podcast/platform that are expired
        $sql = "
            SELECT 
                m.podcast_id,
                m.platform,
                COALESCE(m.followers_count, m.subscriber_count, 0) as followers,
                m.fetched_at
            FROM $metrics_table m
            INNER JOIN (
                SELECT podcast_id, platform, MAX(fetched_at) as max_fetch
                FROM $metrics_table
                GROUP BY podcast_id, platform
            ) latest ON m.podcast_id = latest.podcast_id 
                    AND m.platform = latest.platform 
                    AND m.fetched_at = latest.max_fetch
            INNER JOIN $podcasts_table p ON m.podcast_id = p.id
            WHERE p.is_tracked = 1
            AND (
                (COALESCE(m.followers_count, m.subscriber_count, 0) < 1000 
                    AND m.fetched_at < DATE_SUB(NOW(), INTERVAL 90 DAY))
                OR (COALESCE(m.followers_count, m.subscriber_count, 0) >= 1000 
                    AND COALESCE(m.followers_count, m.subscriber_count, 0) < 10000 
                    AND m.fetched_at < DATE_SUB(NOW(), INTERVAL 60 DAY))
                OR (COALESCE(m.followers_count, m.subscriber_count, 0) >= 10000 
                    AND COALESCE(m.followers_count, m.subscriber_count, 0) < 100000 
                    AND m.fetched_at < DATE_SUB(NOW(), INTERVAL 30 DAY))
                OR (COALESCE(m.followers_count, m.subscriber_count, 0) >= 100000 
                    AND COALESCE(m.followers_count, m.subscriber_count, 0) < 1000000 
                    AND m.fetched_at < DATE_SUB(NOW(), INTERVAL 14 DAY))
                OR (COALESCE(m.followers_count, m.subscriber_count, 0) >= 1000000 
                    AND m.fetched_at < DATE_SUB(NOW(), INTERVAL 7 DAY))
            )
            ORDER BY m.fetched_at ASC
            LIMIT %d
        ";

        return $wpdb->get_results($wpdb->prepare($sql, $limit));
    }

    /**
     * Get social links with no metrics (never enriched)
     *
     * @param int $limit Max records to return
     * @return array Array of [podcast_id, platform]
     */
    public static function get_unenriched_links($limit = 100) {
        global $wpdb;
        $metrics_table = $wpdb->prefix . 'pit_metrics';
        $social_table = $wpdb->prefix . 'pit_social_links';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';

        $sql = "
            SELECT s.podcast_id, s.platform
            FROM $social_table s
            INNER JOIN $podcasts_table p ON s.podcast_id = p.id
            LEFT JOIN $metrics_table m ON s.podcast_id = m.podcast_id AND s.platform = m.platform
            WHERE p.is_tracked = 1
            AND s.active = 1
            AND m.id IS NULL
            ORDER BY s.created_at ASC
            LIMIT %d
        ";

        return $wpdb->get_results($wpdb->prepare($sql, $limit));
    }
}
