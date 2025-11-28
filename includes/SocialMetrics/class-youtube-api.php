<?php
/**
 * YouTube API Integration - Layer 2
 *
 * Fetches channel statistics from YouTube Data API v3.
 * Cost: FREE (10,000 quota units/day)
 *
 * @package PodcastInfluenceTracker
 * @subpackage SocialMetrics
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_YouTube_API {

    /**
     * API Base URL
     */
    const API_BASE = 'https://www.googleapis.com/youtube/v3';

    /**
     * Get API key from settings
     *
     * @return string|null API key or null
     */
    private static function get_api_key() {
        if (class_exists('PIT_Settings')) {
            $settings = PIT_Settings::get_all();
            return $settings['youtube_api_key'] ?? null;
        }
        return get_option('pit_youtube_api_key');
    }

    /**
     * Check if API is configured
     *
     * @return bool
     */
    public static function is_configured() {
        $key = self::get_api_key();
        return !empty($key);
    }

    /**
     * Fetch metrics for a YouTube channel
     *
     * @param string $profile_url YouTube channel URL
     * @param string $handle Optional channel handle
     * @return array|WP_Error Metrics data or error
     */
    public static function fetch_metrics($profile_url, $handle = '') {
        $api_key = self::get_api_key();

        if (empty($api_key)) {
            return new WP_Error('no_api_key', 'YouTube API key not configured. Add it in Settings.');
        }

        // Extract channel identifier from URL
        $channel_id = self::extract_channel_id($profile_url, $handle);

        if (is_wp_error($channel_id)) {
            return $channel_id;
        }

        // Fetch channel statistics
        $stats = self::get_channel_statistics($channel_id, $api_key);

        if (is_wp_error($stats)) {
            return $stats;
        }

        return [
            'subscribers' => (int) ($stats['subscriberCount'] ?? 0),
            'followers' => (int) ($stats['subscriberCount'] ?? 0), // Alias for consistency
            'total_views' => (int) ($stats['viewCount'] ?? 0),
            'videos' => (int) ($stats['videoCount'] ?? 0),
            'posts' => (int) ($stats['videoCount'] ?? 0), // Alias
            'raw_data' => [
                'channel_id' => $channel_id,
                'statistics' => $stats,
                'fetched_at' => current_time('mysql'),
            ],
            'cost' => 0, // YouTube API is free
        ];
    }

    /**
     * Extract channel ID from various URL formats
     *
     * Supports:
     * - https://www.youtube.com/channel/UCxxxxxx (direct channel ID)
     * - https://www.youtube.com/@handle (handle format)
     * - https://www.youtube.com/c/CustomName (custom URL - deprecated)
     * - https://www.youtube.com/user/Username (legacy user URL)
     *
     * @param string $url YouTube URL
     * @param string $handle Optional handle hint
     * @return string|WP_Error Channel ID or error
     */
    public static function extract_channel_id($url, $handle = '') {
        $api_key = self::get_api_key();

        // Pattern 1: Direct channel ID in URL
        if (preg_match('/youtube\.com\/channel\/(UC[a-zA-Z0-9_-]+)/', $url, $matches)) {
            return $matches[1];
        }

        // Pattern 2: Handle format (@username)
        if (preg_match('/youtube\.com\/@([a-zA-Z0-9_-]+)/', $url, $matches)) {
            $handle_name = $matches[1];
            return self::resolve_handle_to_channel_id($handle_name, $api_key);
        }

        // Pattern 3: Custom URL format (/c/name)
        if (preg_match('/youtube\.com\/c\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
            $custom_name = $matches[1];
            return self::search_channel_by_name($custom_name, $api_key);
        }

        // Pattern 4: Legacy user URL (/user/name)
        if (preg_match('/youtube\.com\/user\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
            $username = $matches[1];
            return self::resolve_username_to_channel_id($username, $api_key);
        }

        // Try using the handle parameter if provided
        if (!empty($handle)) {
            $clean_handle = ltrim($handle, '@');
            return self::resolve_handle_to_channel_id($clean_handle, $api_key);
        }

        return new WP_Error('invalid_url', 'Could not extract channel ID from URL: ' . $url);
    }

    /**
     * Resolve @handle to channel ID
     *
     * @param string $handle Handle without @
     * @param string $api_key API key
     * @return string|WP_Error Channel ID or error
     */
    private static function resolve_handle_to_channel_id($handle, $api_key) {
        // Use channels.list with forHandle parameter
        $url = add_query_arg([
            'part' => 'id',
            'forHandle' => $handle,
            'key' => $api_key,
        ], self::API_BASE . '/channels');

        $response = wp_remote_get($url, ['timeout' => 15]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return new WP_Error('api_error', $body['error']['message'] ?? 'YouTube API error');
        }

        if (empty($body['items'])) {
            // Fallback to search
            return self::search_channel_by_name($handle, $api_key);
        }

        return $body['items'][0]['id'];
    }

    /**
     * Resolve legacy username to channel ID
     *
     * @param string $username Username
     * @param string $api_key API key
     * @return string|WP_Error Channel ID or error
     */
    private static function resolve_username_to_channel_id($username, $api_key) {
        $url = add_query_arg([
            'part' => 'id',
            'forUsername' => $username,
            'key' => $api_key,
        ], self::API_BASE . '/channels');

        $response = wp_remote_get($url, ['timeout' => 15]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return new WP_Error('api_error', $body['error']['message'] ?? 'YouTube API error');
        }

        if (empty($body['items'])) {
            return new WP_Error('not_found', 'Channel not found for username: ' . $username);
        }

        return $body['items'][0]['id'];
    }

    /**
     * Search for channel by name (fallback)
     *
     * @param string $name Channel name to search
     * @param string $api_key API key
     * @return string|WP_Error Channel ID or error
     */
    private static function search_channel_by_name($name, $api_key) {
        $url = add_query_arg([
            'part' => 'snippet',
            'q' => $name,
            'type' => 'channel',
            'maxResults' => 1,
            'key' => $api_key,
        ], self::API_BASE . '/search');

        $response = wp_remote_get($url, ['timeout' => 15]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return new WP_Error('api_error', $body['error']['message'] ?? 'YouTube API error');
        }

        if (empty($body['items'])) {
            return new WP_Error('not_found', 'Channel not found: ' . $name);
        }

        return $body['items'][0]['snippet']['channelId'] ?? $body['items'][0]['id']['channelId'];
    }

    /**
     * Get channel statistics
     *
     * @param string $channel_id YouTube channel ID
     * @param string $api_key API key
     * @return array|WP_Error Statistics array or error
     */
    private static function get_channel_statistics($channel_id, $api_key) {
        $url = add_query_arg([
            'part' => 'statistics,snippet',
            'id' => $channel_id,
            'key' => $api_key,
        ], self::API_BASE . '/channels');

        $response = wp_remote_get($url, ['timeout' => 15]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code !== 200) {
            $error_msg = $body['error']['message'] ?? 'Unknown error';
            return new WP_Error('api_error', "YouTube API error ($status_code): $error_msg");
        }

        if (empty($body['items'])) {
            return new WP_Error('not_found', 'Channel not found: ' . $channel_id);
        }

        $channel = $body['items'][0];

        return array_merge(
            $channel['statistics'] ?? [],
            [
                'title' => $channel['snippet']['title'] ?? '',
                'description' => $channel['snippet']['description'] ?? '',
                'customUrl' => $channel['snippet']['customUrl'] ?? '',
                'thumbnails' => $channel['snippet']['thumbnails'] ?? [],
            ]
        );
    }

    /**
     * Batch fetch metrics for multiple channels
     *
     * YouTube API allows up to 50 channel IDs per request.
     *
     * @param array $channels Array of ['podcast_id' => X, 'profile_url' => Y, 'handle' => Z]
     * @return array Results keyed by podcast_id
     */
    public static function batch_fetch($channels) {
        $api_key = self::get_api_key();

        if (empty($api_key)) {
            return new WP_Error('no_api_key', 'YouTube API key not configured');
        }

        $results = [];
        $channel_map = []; // channel_id => podcast_id

        // First, resolve all channel IDs
        foreach ($channels as $channel) {
            $channel_id = self::extract_channel_id(
                $channel['profile_url'],
                $channel['handle'] ?? ''
            );

            if (is_wp_error($channel_id)) {
                $results[$channel['podcast_id']] = [
                    'success' => false,
                    'error' => $channel_id->get_error_message(),
                ];
                continue;
            }

            $channel_map[$channel_id] = $channel['podcast_id'];
        }

        if (empty($channel_map)) {
            return $results;
        }

        // Batch fetch in groups of 50
        $channel_ids = array_keys($channel_map);
        $batches = array_chunk($channel_ids, 50);

        foreach ($batches as $batch) {
            $url = add_query_arg([
                'part' => 'statistics,snippet',
                'id' => implode(',', $batch),
                'key' => $api_key,
            ], self::API_BASE . '/channels');

            $response = wp_remote_get($url, ['timeout' => 30]);

            if (is_wp_error($response)) {
                foreach ($batch as $cid) {
                    $results[$channel_map[$cid]] = [
                        'success' => false,
                        'error' => $response->get_error_message(),
                    ];
                }
                continue;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);

            if (isset($body['error'])) {
                foreach ($batch as $cid) {
                    $results[$channel_map[$cid]] = [
                        'success' => false,
                        'error' => $body['error']['message'] ?? 'API error',
                    ];
                }
                continue;
            }

            // Process results
            foreach ($body['items'] ?? [] as $item) {
                $cid = $item['id'];
                $podcast_id = $channel_map[$cid] ?? null;

                if (!$podcast_id) continue;

                $stats = $item['statistics'] ?? [];

                $results[$podcast_id] = [
                    'success' => true,
                    'subscribers' => (int) ($stats['subscriberCount'] ?? 0),
                    'followers' => (int) ($stats['subscriberCount'] ?? 0),
                    'total_views' => (int) ($stats['viewCount'] ?? 0),
                    'videos' => (int) ($stats['videoCount'] ?? 0),
                    'channel_id' => $cid,
                    'channel_title' => $item['snippet']['title'] ?? '',
                    'raw_data' => [
                        'statistics' => $stats,
                        'snippet' => $item['snippet'] ?? [],
                    ],
                ];
            }

            // Mark any missing channels as not found
            foreach ($batch as $cid) {
                $podcast_id = $channel_map[$cid];
                if (!isset($results[$podcast_id])) {
                    $results[$podcast_id] = [
                        'success' => false,
                        'error' => 'Channel not found in API response',
                    ];
                }
            }

            // Small delay between batches
            usleep(100000); // 0.1 seconds
        }

        return $results;
    }

    /**
     * Test API connection
     *
     * @return array|WP_Error Test results or error
     */
    public static function test_connection() {
        $api_key = self::get_api_key();

        if (empty($api_key)) {
            return new WP_Error('no_api_key', 'YouTube API key not configured');
        }

        // Test with a known channel (YouTube's own channel)
        $url = add_query_arg([
            'part' => 'statistics',
            'id' => 'UCBR8-60-B28hp2BmDPdntcQ', // YouTube channel
            'key' => $api_key,
        ], self::API_BASE . '/channels');

        $response = wp_remote_get($url, ['timeout' => 15]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code !== 200) {
            $error_msg = $body['error']['message'] ?? 'Unknown error';
            return new WP_Error('api_error', "API test failed ($status_code): $error_msg");
        }

        if (empty($body['items'])) {
            return new WP_Error('unexpected_response', 'API returned empty response');
        }

        return [
            'success' => true,
            'message' => 'YouTube API connection successful',
            'quota_note' => 'Free tier: 10,000 quota units/day (1 channel lookup = ~3 units)',
        ];
    }

    /**
     * Get quota usage estimate
     *
     * @param int $channel_count Number of channels to fetch
     * @return array Quota estimate
     */
    public static function estimate_quota_usage($channel_count) {
        // channels.list with statistics,snippet = 3 quota units per request
        // Each request can handle 50 channels
        $requests_needed = ceil($channel_count / 50);
        $quota_per_request = 3;
        $total_quota = $requests_needed * $quota_per_request;

        return [
            'channels' => $channel_count,
            'requests_needed' => $requests_needed,
            'quota_per_request' => $quota_per_request,
            'total_quota' => $total_quota,
            'daily_limit' => 10000,
            'percentage_of_daily' => round(($total_quota / 10000) * 100, 2) . '%',
        ];
    }
}
