<?php
/**
 * YouTube Data API Integration
 *
 * Fetches YouTube channel metrics using the free YouTube Data API v3
 * No cost - uses free tier (10,000 quota units/day)
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_YouTube_API {

    const API_BASE_URL = 'https://www.googleapis.com/youtube/v3';

    /**
     * Fetch metrics for a YouTube channel
     *
     * @param string $channel_url YouTube channel URL
     * @param string $handle Channel handle/username
     * @return array|WP_Error Metrics data
     */
    public static function fetch_metrics($channel_url, $handle) {
        $settings = PIT_Settings::get_all();
        $api_key = $settings['youtube_api_key'] ?? '';

        if (empty($api_key)) {
            return new WP_Error('no_api_key', 'YouTube API key not configured');
        }

        // Extract channel ID from URL
        $channel_id = self::extract_channel_id($channel_url, $handle, $api_key);

        if (is_wp_error($channel_id)) {
            return $channel_id;
        }

        // Fetch channel statistics
        $stats = self::fetch_channel_stats($channel_id, $api_key);

        if (is_wp_error($stats)) {
            return $stats;
        }

        // Fetch recent videos for engagement analysis
        $engagement = self::fetch_engagement_metrics($channel_id, $api_key);

        // Combine metrics
        return [
            'followers' => $stats['subscriberCount'] ?? 0,
            'subscribers' => $stats['subscriberCount'] ?? 0,
            'videos' => $stats['videoCount'] ?? 0,
            'total_views' => $stats['viewCount'] ?? 0,
            'posts' => $stats['videoCount'] ?? 0,
            'avg_likes' => $engagement['avg_likes'] ?? 0,
            'avg_comments' => $engagement['avg_comments'] ?? 0,
            'avg_views' => $engagement['avg_views'] ?? 0,
            'engagement_rate' => $engagement['engagement_rate'] ?? 0,
            'raw_data' => [
                'channel_id' => $channel_id,
                'stats' => $stats,
                'engagement' => $engagement,
            ],
        ];
    }

    /**
     * Extract channel ID from URL or username
     *
     * @param string $url Channel URL
     * @param string $handle Username/handle
     * @param string $api_key API key
     * @return string|WP_Error Channel ID
     */
    private static function extract_channel_id($url, $handle, $api_key) {
        // Try to extract from URL first
        // Format: youtube.com/channel/CHANNEL_ID
        if (preg_match('/youtube\.com\/channel\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
            return $matches[1];
        }

        // Format: youtube.com/@username or youtube.com/c/username or youtube.com/user/username
        if (preg_match('/youtube\.com\/(?:@|c\/|user\/)([a-zA-Z0-9_-]+)/', $url, $matches)) {
            $username = $matches[1];
        } else {
            $username = $handle;
        }

        if (empty($username)) {
            return new WP_Error('invalid_url', 'Could not extract channel ID from URL');
        }

        // Look up channel by username
        $endpoint = self::API_BASE_URL . '/channels';
        $params = [
            'part' => 'id',
            'forHandle' => $username,
            'key' => $api_key,
        ];

        $response = wp_remote_get(add_query_arg($params, $endpoint), [
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['items'][0]['id'])) {
            return $body['items'][0]['id'];
        }

        // Try searching by username
        $endpoint = self::API_BASE_URL . '/search';
        $params = [
            'part' => 'snippet',
            'q' => $username,
            'type' => 'channel',
            'maxResults' => 1,
            'key' => $api_key,
        ];

        $response = wp_remote_get(add_query_arg($params, $endpoint), [
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['items'][0]['snippet']['channelId'])) {
            return $body['items'][0]['snippet']['channelId'];
        }

        return new WP_Error('channel_not_found', 'Could not find YouTube channel');
    }

    /**
     * Fetch channel statistics
     *
     * @param string $channel_id Channel ID
     * @param string $api_key API key
     * @return array|WP_Error Statistics
     */
    private static function fetch_channel_stats($channel_id, $api_key) {
        $endpoint = self::API_BASE_URL . '/channels';
        $params = [
            'part' => 'statistics,snippet',
            'id' => $channel_id,
            'key' => $api_key,
        ];

        $response = wp_remote_get(add_query_arg($params, $endpoint), [
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code !== 200) {
            return new WP_Error(
                'api_error',
                $body['error']['message'] ?? 'YouTube API error',
                ['status' => $status_code]
            );
        }

        if (empty($body['items'])) {
            return new WP_Error('no_data', 'No data returned from YouTube API');
        }

        return $body['items'][0]['statistics'];
    }

    /**
     * Fetch engagement metrics from recent videos
     *
     * @param string $channel_id Channel ID
     * @param string $api_key API key
     * @return array Engagement metrics
     */
    private static function fetch_engagement_metrics($channel_id, $api_key) {
        // Get recent videos
        $endpoint = self::API_BASE_URL . '/search';
        $params = [
            'part' => 'id',
            'channelId' => $channel_id,
            'type' => 'video',
            'order' => 'date',
            'maxResults' => 10,
            'key' => $api_key,
        ];

        $response = wp_remote_get(add_query_arg($params, $endpoint), [
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['items'])) {
            return [];
        }

        // Get video IDs
        $video_ids = array_map(function($item) {
            return $item['id']['videoId'];
        }, $body['items']);

        // Fetch video statistics
        $endpoint = self::API_BASE_URL . '/videos';
        $params = [
            'part' => 'statistics',
            'id' => implode(',', $video_ids),
            'key' => $api_key,
        ];

        $response = wp_remote_get(add_query_arg($params, $endpoint), [
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['items'])) {
            return [];
        }

        // Calculate averages
        $total_likes = 0;
        $total_comments = 0;
        $total_views = 0;
        $count = count($body['items']);

        foreach ($body['items'] as $video) {
            $stats = $video['statistics'];
            $total_likes += (int) ($stats['likeCount'] ?? 0);
            $total_comments += (int) ($stats['commentCount'] ?? 0);
            $total_views += (int) ($stats['viewCount'] ?? 0);
        }

        $avg_likes = $count > 0 ? round($total_likes / $count) : 0;
        $avg_comments = $count > 0 ? round($total_comments / $count) : 0;
        $avg_views = $count > 0 ? round($total_views / $count) : 0;

        // Calculate engagement rate (likes + comments) / views
        $engagement_rate = $avg_views > 0 ? round((($avg_likes + $avg_comments) / $avg_views) * 100, 2) : 0;

        return [
            'avg_likes' => $avg_likes,
            'avg_comments' => $avg_comments,
            'avg_views' => $avg_views,
            'engagement_rate' => $engagement_rate,
            'videos_analyzed' => $count,
        ];
    }

    /**
     * Validate API key
     *
     * @param string $api_key API key to validate
     * @return bool|WP_Error True if valid, error otherwise
     */
    public static function validate_api_key($api_key) {
        $endpoint = self::API_BASE_URL . '/channels';
        $params = [
            'part' => 'id',
            'mine' => 'true',
            'key' => $api_key,
        ];

        $response = wp_remote_get(add_query_arg($params, $endpoint), [
            'timeout' => 5,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code === 200 || $status_code === 401) {
            // 401 means key is valid but request needs authentication (expected)
            // 200 means key is valid
            return true;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        return new WP_Error(
            'invalid_key',
            $body['error']['message'] ?? 'Invalid API key'
        );
    }

    /**
     * Get API quota usage estimate
     *
     * @return array Quota information
     */
    public static function get_quota_info() {
        // YouTube API quota:
        // - channels.list: 1 unit
        // - search.list: 100 units
        // - videos.list: 1 unit
        // Total per podcast: ~102 units
        // Daily limit: 10,000 units
        // Max podcasts per day: ~98

        return [
            'daily_limit' => 10000,
            'cost_per_channel' => 102,
            'max_channels_per_day' => 98,
            'cost_usd' => 0, // Free tier
        ];
    }
}
