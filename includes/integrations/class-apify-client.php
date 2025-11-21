<?php
/**
 * Apify API Client
 *
 * Fetches social media metrics from Twitter, Instagram, Facebook, etc.
 * Uses Apify platform actors for scraping
 *
 * Cost: ~$0.05-0.20 per platform depending on complexity
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Apify_Client {

    const API_BASE_URL = 'https://api.apify.com/v2';

    /**
     * Actor IDs for different platforms
     */
    private static $actors = [
        'twitter' => 'apidojo/tweet-scraper', // Alternative: apify/twitter-scraper
        'instagram' => 'apify/instagram-profile-scraper',
        'facebook' => 'apify/facebook-pages-scraper',
        'linkedin' => 'apify/linkedin-profile-scraper',
        'tiktok' => 'apify/tiktok-scraper',
    ];

    /**
     * Fetch metrics for a platform
     *
     * @param string $platform Platform name
     * @param string $profile_url Profile URL
     * @param string $handle Profile handle
     * @return array|WP_Error Metrics data
     */
    public static function fetch_metrics($platform, $profile_url, $handle) {
        $settings = PIT_Settings::get_all();
        $api_token = $settings['apify_api_token'] ?? '';

        if (empty($api_token)) {
            return new WP_Error('no_api_token', 'Apify API token not configured');
        }

        if (!isset(self::$actors[$platform])) {
            return new WP_Error('unsupported_platform', 'Platform not supported: ' . $platform);
        }

        $actor_id = self::$actors[$platform];

        // Prepare input based on platform
        $input = self::prepare_input($platform, $profile_url, $handle);

        // Run actor
        $run_result = self::run_actor($actor_id, $input, $api_token);

        if (is_wp_error($run_result)) {
            return $run_result;
        }

        // Parse results
        $metrics = self::parse_results($platform, $run_result['data']);

        // Calculate cost
        $cost = self::calculate_cost($run_result['usage']);

        $metrics['cost'] = $cost;

        return $metrics;
    }

    /**
     * Prepare input for actor based on platform
     *
     * @param string $platform Platform name
     * @param string $url Profile URL
     * @param string $handle Profile handle
     * @return array Input data
     */
    private static function prepare_input($platform, $url, $handle) {
        switch ($platform) {
            case 'twitter':
                return [
                    'urls' => [$url],
                    'maxItems' => 10,
                    'onlyUserInfo' => true,
                ];

            case 'instagram':
                return [
                    'directUrls' => [$url],
                    'resultsType' => 'details',
                    'resultsLimit' => 1,
                ];

            case 'facebook':
                return [
                    'startUrls' => [['url' => $url]],
                    'maxPosts' => 10,
                ];

            case 'linkedin':
                return [
                    'urls' => [$url],
                ];

            case 'tiktok':
                return [
                    'profiles' => [$handle],
                    'resultsPerPage' => 10,
                ];

            default:
                return ['url' => $url];
        }
    }

    /**
     * Run Apify actor
     *
     * @param string $actor_id Actor ID
     * @param array $input Input data
     * @param string $api_token API token
     * @return array|WP_Error Run result
     */
    private static function run_actor($actor_id, $input, $api_token) {
        // Start actor run
        $endpoint = self::API_BASE_URL . '/acts/' . $actor_id . '/runs';

        $response = wp_remote_post($endpoint, [
            'timeout' => 180, // 3 minutes max
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_token,
            ],
            'body' => json_encode($input),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code !== 201) {
            return new WP_Error(
                'actor_run_failed',
                $body['error']['message'] ?? 'Failed to run Apify actor',
                ['status' => $status_code]
            );
        }

        $run_id = $body['data']['id'];

        // Wait for run to complete (poll status)
        $result = self::wait_for_run($run_id, $api_token);

        if (is_wp_error($result)) {
            return $result;
        }

        // Fetch dataset
        $dataset_id = $result['defaultDatasetId'];
        $data = self::fetch_dataset($dataset_id, $api_token);

        if (is_wp_error($data)) {
            return $data;
        }

        return [
            'data' => $data,
            'usage' => $result['stats'] ?? [],
        ];
    }

    /**
     * Wait for actor run to complete
     *
     * @param string $run_id Run ID
     * @param string $api_token API token
     * @return array|WP_Error Run data
     */
    private static function wait_for_run($run_id, $api_token) {
        $max_attempts = 60; // 60 attempts = 2 minutes max
        $attempt = 0;

        while ($attempt < $max_attempts) {
            $endpoint = self::API_BASE_URL . '/actor-runs/' . $run_id;

            $response = wp_remote_get($endpoint . '?token=' . $api_token, [
                'timeout' => 10,
            ]);

            if (is_wp_error($response)) {
                return $response;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            $status = $body['data']['status'] ?? '';

            if ($status === 'SUCCEEDED') {
                return $body['data'];
            } elseif ($status === 'FAILED' || $status === 'ABORTED' || $status === 'TIMED-OUT') {
                return new WP_Error('run_failed', 'Actor run failed: ' . $status);
            }

            // Wait 2 seconds before next attempt
            sleep(2);
            $attempt++;
        }

        return new WP_Error('timeout', 'Actor run timed out');
    }

    /**
     * Fetch dataset results
     *
     * @param string $dataset_id Dataset ID
     * @param string $api_token API token
     * @return array|WP_Error Dataset items
     */
    private static function fetch_dataset($dataset_id, $api_token) {
        $endpoint = self::API_BASE_URL . '/datasets/' . $dataset_id . '/items';

        $response = wp_remote_get($endpoint . '?token=' . $api_token, [
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200) {
            return new WP_Error('fetch_failed', 'Failed to fetch dataset');
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        return $data;
    }

    /**
     * Parse results based on platform
     *
     * @param string $platform Platform name
     * @param array $data Raw data from Apify
     * @return array Normalized metrics
     */
    private static function parse_results($platform, $data) {
        if (empty($data)) {
            return self::empty_metrics();
        }

        $item = $data[0]; // Get first item

        switch ($platform) {
            case 'twitter':
                return self::parse_twitter($item);

            case 'instagram':
                return self::parse_instagram($item);

            case 'facebook':
                return self::parse_facebook($item);

            case 'linkedin':
                return self::parse_linkedin($item);

            case 'tiktok':
                return self::parse_tiktok($item);

            default:
                return self::empty_metrics();
        }
    }

    /**
     * Parse Twitter data
     */
    private static function parse_twitter($data) {
        return [
            'followers' => $data['followersCount'] ?? 0,
            'following' => $data['followingCount'] ?? 0,
            'posts' => $data['tweetsCount'] ?? 0,
            'avg_likes' => $data['avgLikes'] ?? 0,
            'avg_comments' => $data['avgReplies'] ?? 0,
            'avg_shares' => $data['avgRetweets'] ?? 0,
            'engagement_rate' => self::calculate_engagement_rate(
                $data['avgLikes'] ?? 0,
                $data['avgReplies'] ?? 0,
                $data['followersCount'] ?? 1
            ),
            'raw_data' => $data,
        ];
    }

    /**
     * Parse Instagram data
     */
    private static function parse_instagram($data) {
        return [
            'followers' => $data['followersCount'] ?? 0,
            'following' => $data['followsCount'] ?? 0,
            'posts' => $data['postsCount'] ?? 0,
            'avg_likes' => $data['avgLikes'] ?? 0,
            'avg_comments' => $data['avgComments'] ?? 0,
            'engagement_rate' => self::calculate_engagement_rate(
                $data['avgLikes'] ?? 0,
                $data['avgComments'] ?? 0,
                $data['followersCount'] ?? 1
            ),
            'raw_data' => $data,
        ];
    }

    /**
     * Parse Facebook data
     */
    private static function parse_facebook($data) {
        return [
            'followers' => $data['likes'] ?? 0,
            'posts' => $data['postsCount'] ?? 0,
            'avg_likes' => $data['avgLikes'] ?? 0,
            'avg_comments' => $data['avgComments'] ?? 0,
            'avg_shares' => $data['avgShares'] ?? 0,
            'engagement_rate' => self::calculate_engagement_rate(
                $data['avgLikes'] ?? 0,
                $data['avgComments'] ?? 0,
                $data['likes'] ?? 1
            ),
            'raw_data' => $data,
        ];
    }

    /**
     * Parse LinkedIn data
     */
    private static function parse_linkedin($data) {
        return [
            'followers' => $data['followersCount'] ?? 0,
            'posts' => $data['postsCount'] ?? 0,
            'raw_data' => $data,
        ];
    }

    /**
     * Parse TikTok data
     */
    private static function parse_tiktok($data) {
        return [
            'followers' => $data['followerCount'] ?? 0,
            'following' => $data['followingCount'] ?? 0,
            'posts' => $data['videoCount'] ?? 0,
            'total_views' => $data['totalViews'] ?? 0,
            'avg_likes' => $data['avgLikes'] ?? 0,
            'avg_comments' => $data['avgComments'] ?? 0,
            'avg_shares' => $data['avgShares'] ?? 0,
            'engagement_rate' => self::calculate_engagement_rate(
                $data['avgLikes'] ?? 0,
                $data['avgComments'] ?? 0,
                $data['followerCount'] ?? 1
            ),
            'raw_data' => $data,
        ];
    }

    /**
     * Calculate engagement rate
     *
     * @param int $avg_likes Average likes
     * @param int $avg_comments Average comments
     * @param int $followers Followers count
     * @return float Engagement rate percentage
     */
    private static function calculate_engagement_rate($avg_likes, $avg_comments, $followers) {
        if ($followers <= 0) {
            return 0;
        }

        return round((($avg_likes + $avg_comments) / $followers) * 100, 2);
    }

    /**
     * Calculate cost from usage stats
     *
     * @param array $usage Usage stats
     * @return float Cost in USD
     */
    private static function calculate_cost($usage) {
        // Apify pricing:
        // $49/month = $49 credit
        // Typical scraper: $0.05-0.20 per run

        // For now, use flat rate
        // In production, calculate from actual compute units used
        return 0.05;
    }

    /**
     * Empty metrics template
     */
    private static function empty_metrics() {
        return [
            'followers' => 0,
            'following' => 0,
            'posts' => 0,
            'avg_likes' => 0,
            'avg_comments' => 0,
            'avg_shares' => 0,
            'engagement_rate' => 0,
            'raw_data' => [],
        ];
    }

    /**
     * Validate API token
     *
     * @param string $api_token API token to validate
     * @return bool|WP_Error True if valid, error otherwise
     */
    public static function validate_api_token($api_token) {
        $endpoint = self::API_BASE_URL . '/users/me';

        $response = wp_remote_get($endpoint . '?token=' . $api_token, [
            'timeout' => 5,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code === 200) {
            return true;
        }

        return new WP_Error('invalid_token', 'Invalid Apify API token');
    }

    /**
     * Get account usage information
     *
     * @param string $api_token API token
     * @return array|WP_Error Usage data
     */
    public static function get_usage($api_token) {
        $endpoint = self::API_BASE_URL . '/users/me';

        $response = wp_remote_get($endpoint . '?token=' . $api_token, [
            'timeout' => 5,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        return $body['data'] ?? [];
    }
}
