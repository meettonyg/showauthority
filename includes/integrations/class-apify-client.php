<?php
/**
 * Apify API Client
 *
 * Fetches social media metrics from Twitter, Instagram, Facebook, LinkedIn, TikTok
 * Uses Apify platform actors for scraping (pay-per-result pricing)
 *
 * Pricing (per 1,000 profiles):
 * - LinkedIn: $4 (harvestapi/linkedin-profile-scraper)
 * - Twitter: $3 (harvestapi/twitter-user-scraper)  
 * - Instagram: $5 (apify/instagram-profile-scraper)
 * - Facebook: $5 (apify/facebook-pages-scraper)
 * - TikTok: $3 (clockworks/tiktok-profile-scraper)
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Apify_Client {

    const API_BASE_URL = 'https://api.apify.com/v2';

    /**
     * Actor configurations for different platforms
     * 
     * Each entry contains:
     * - actor: The Apify actor ID
     * - cost_per_1k: Cost per 1000 results in USD
     * - input_format: How to structure the input
     * - response_map: How to map response fields to our standard format
     */
    private static $platform_config = [
        'linkedin' => [
            'actor' => 'harvestapi/linkedin-profile-scraper',
            'cost_per_1k' => 4.00,
            'input_format' => 'urls_array',
            'response_map' => [
                'followers' => ['followersCount', 'connectionsCount', 'followerCount'],
                'name' => ['fullName', 'name'],
                'headline' => ['headline', 'title'],
                'location' => ['location', 'locationName'],
                'company' => ['currentCompany', 'companyName'],
                'about' => ['about', 'summary', 'bio'],
            ],
        ],
        'twitter' => [
            'actor' => 'harvestapi/twitter-user-scraper',
            'cost_per_1k' => 3.00,
            'input_format' => 'handles_array',
            'response_map' => [
                'followers' => ['followersCount', 'followers_count'],
                'following' => ['followingCount', 'friends_count'],
                'posts' => ['tweetsCount', 'statuses_count'],
                'name' => ['name', 'displayName'],
                'bio' => ['description', 'bio'],
                'location' => ['location'],
                'verified' => ['verified', 'isVerified'],
            ],
        ],
        'instagram' => [
            'actor' => 'apify/instagram-profile-scraper',
            'cost_per_1k' => 5.00,
            'input_format' => 'direct_urls',
            'response_map' => [
                'followers' => ['followersCount', 'edge_followed_by.count'],
                'following' => ['followsCount', 'edge_follow.count'],
                'posts' => ['postsCount', 'edge_owner_to_timeline_media.count'],
                'name' => ['fullName', 'full_name'],
                'bio' => ['biography', 'bio'],
                'is_verified' => ['isVerified', 'is_verified'],
            ],
        ],
        'facebook' => [
            'actor' => 'apify/facebook-pages-scraper',
            'cost_per_1k' => 5.00,
            'input_format' => 'start_urls',
            'response_map' => [
                'followers' => ['likes', 'followersCount', 'followers'],
                'name' => ['name', 'title'],
                'category' => ['categories', 'category'],
                'about' => ['about', 'description'],
            ],
        ],
        'tiktok' => [
            'actor' => 'clockworks/tiktok-profile-scraper',
            'cost_per_1k' => 3.00,
            'input_format' => 'profiles_array',
            'response_map' => [
                'followers' => ['followerCount', 'fans', 'followersCount'],
                'following' => ['followingCount', 'following'],
                'posts' => ['videoCount', 'video', 'videosCount'],
                'total_views' => ['heartCount', 'heart', 'likesCount'],
                'name' => ['nickname', 'name'],
                'bio' => ['signature', 'bio', 'description'],
                'verified' => ['verified', 'isVerified'],
            ],
        ],
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
            return new WP_Error('no_api_token', 'Apify API token not configured. Add it in Settings → API Keys.');
        }

        if (!isset(self::$platform_config[$platform])) {
            return new WP_Error('unsupported_platform', 'Platform not supported: ' . $platform);
        }

        $config = self::$platform_config[$platform];
        $actor_id = $config['actor'];

        // Prepare input based on platform
        $input = self::prepare_input($platform, $profile_url, $handle);

        // Run actor
        $run_result = self::run_actor($actor_id, $input, $api_token);

        if (is_wp_error($run_result)) {
            return $run_result;
        }

        // Parse results using platform-specific mapping
        $metrics = self::parse_results($platform, $run_result['data'], $config['response_map']);

        // Calculate cost (pay-per-result)
        $cost = $config['cost_per_1k'] / 1000; // Cost for single profile

        $metrics['cost'] = $cost;

        return $metrics;
    }

    /**
     * Batch fetch metrics for multiple profiles on same platform
     *
     * @param string $platform Platform name
     * @param array $profiles Array of ['url' => X, 'handle' => Y]
     * @return array Results keyed by URL
     */
    public static function batch_fetch($platform, $profiles) {
        $settings = PIT_Settings::get_all();
        $api_token = $settings['apify_api_token'] ?? '';

        if (empty($api_token)) {
            return new WP_Error('no_api_token', 'Apify API token not configured');
        }

        if (!isset(self::$platform_config[$platform])) {
            return new WP_Error('unsupported_platform', 'Platform not supported: ' . $platform);
        }

        $config = self::$platform_config[$platform];
        $actor_id = $config['actor'];

        // Prepare batch input
        $input = self::prepare_batch_input($platform, $profiles);

        // Run actor
        $run_result = self::run_actor($actor_id, $input, $api_token);

        if (is_wp_error($run_result)) {
            return $run_result;
        }

        // Parse all results
        $results = [];
        $cost_per_profile = $config['cost_per_1k'] / 1000;

        foreach ($run_result['data'] as $item) {
            $profile_url = self::extract_url_from_result($platform, $item);
            $metrics = self::parse_single_result($platform, $item, $config['response_map']);
            $metrics['cost'] = $cost_per_profile;
            $results[$profile_url] = $metrics;
        }

        return [
            'results' => $results,
            'total_cost' => count($run_result['data']) * $cost_per_profile,
            'profiles_fetched' => count($run_result['data']),
        ];
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
        $config = self::$platform_config[$platform];

        switch ($config['input_format']) {
            case 'urls_array':
                // LinkedIn style: { "urls": ["https://linkedin.com/in/..."] }
                return [
                    'urls' => [$url],
                ];

            case 'handles_array':
                // Twitter style: { "handles": ["@username"] } or { "usernames": ["username"] }
                $clean_handle = ltrim($handle, '@');
                if (empty($clean_handle) && !empty($url)) {
                    // Extract handle from URL
                    if (preg_match('/(?:twitter|x)\.com\/([^\/\?]+)/i', $url, $matches)) {
                        $clean_handle = $matches[1];
                    }
                }
                return [
                    'usernames' => [$clean_handle],
                ];

            case 'direct_urls':
                // Instagram style: { "directUrls": ["https://instagram.com/..."] }
                return [
                    'directUrls' => [$url],
                    'resultsType' => 'details',
                    'resultsLimit' => 1,
                ];

            case 'start_urls':
                // Facebook style: { "startUrls": [{"url": "..."}] }
                return [
                    'startUrls' => [['url' => $url]],
                    'maxPosts' => 0, // Just profile info
                ];

            case 'profiles_array':
                // TikTok style: { "profiles": ["username"] }
                $clean_handle = ltrim($handle, '@');
                if (empty($clean_handle) && !empty($url)) {
                    if (preg_match('/tiktok\.com\/@?([^\/\?]+)/i', $url, $matches)) {
                        $clean_handle = $matches[1];
                    }
                }
                return [
                    'profiles' => [$clean_handle],
                ];

            default:
                return ['url' => $url];
        }
    }

    /**
     * Prepare batch input for multiple profiles
     *
     * @param string $platform Platform name
     * @param array $profiles Array of profiles
     * @return array Batch input
     */
    private static function prepare_batch_input($platform, $profiles) {
        $config = self::$platform_config[$platform];
        $urls = [];
        $handles = [];

        foreach ($profiles as $profile) {
            $urls[] = $profile['url'];
            if (!empty($profile['handle'])) {
                $handles[] = ltrim($profile['handle'], '@');
            }
        }

        switch ($config['input_format']) {
            case 'urls_array':
                return ['urls' => $urls];

            case 'handles_array':
                return ['usernames' => $handles];

            case 'direct_urls':
                return [
                    'directUrls' => $urls,
                    'resultsType' => 'details',
                ];

            case 'start_urls':
                return [
                    'startUrls' => array_map(fn($u) => ['url' => $u], $urls),
                    'maxPosts' => 0,
                ];

            case 'profiles_array':
                return ['profiles' => $handles];

            default:
                return ['urls' => $urls];
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
            $error_msg = $body['error']['message'] ?? 'Failed to run Apify actor';
            return new WP_Error(
                'actor_run_failed',
                $error_msg . ' (Actor: ' . $actor_id . ')',
                ['status' => $status_code, 'actor' => $actor_id]
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
            'run_id' => $run_id,
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
        $max_attempts = 90; // 90 attempts × 2 seconds = 3 minutes max
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
            } elseif (in_array($status, ['FAILED', 'ABORTED', 'TIMED-OUT'])) {
                return new WP_Error('run_failed', 'Actor run failed: ' . $status);
            }

            // Wait 2 seconds before next attempt
            sleep(2);
            $attempt++;
        }

        return new WP_Error('timeout', 'Actor run timed out after 3 minutes');
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
            return new WP_Error('fetch_failed', 'Failed to fetch dataset (HTTP ' . $status_code . ')');
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        return $data ?? [];
    }

    /**
     * Parse results using response mapping
     *
     * @param string $platform Platform name
     * @param array $data Raw data from Apify
     * @param array $response_map Field mapping
     * @return array Normalized metrics
     */
    private static function parse_results($platform, $data, $response_map) {
        if (empty($data)) {
            return self::empty_metrics();
        }

        $item = $data[0]; // Get first item
        return self::parse_single_result($platform, $item, $response_map);
    }

    /**
     * Parse a single result item
     *
     * @param string $platform Platform name
     * @param array $item Single result item
     * @param array $response_map Field mapping
     * @return array Normalized metrics
     */
    private static function parse_single_result($platform, $item, $response_map) {
        $metrics = self::empty_metrics();

        foreach ($response_map as $our_field => $possible_keys) {
            foreach ($possible_keys as $key) {
                $value = self::get_nested_value($item, $key);
                if ($value !== null) {
                    $metrics[$our_field] = $value;
                    break;
                }
            }
        }

        // Calculate engagement rate if we have followers
        if ($metrics['followers'] > 0) {
            $avg_engagement = ($metrics['avg_likes'] ?? 0) + ($metrics['avg_comments'] ?? 0);
            $metrics['engagement_rate'] = round(($avg_engagement / $metrics['followers']) * 100, 2);
        }

        $metrics['raw_data'] = $item;

        return $metrics;
    }

    /**
     * Get nested value from array using dot notation
     *
     * @param array $array Source array
     * @param string $key Key (supports dot notation like "edge_followed_by.count")
     * @return mixed Value or null
     */
    private static function get_nested_value($array, $key) {
        if (isset($array[$key])) {
            return $array[$key];
        }

        // Handle dot notation
        if (strpos($key, '.') !== false) {
            $keys = explode('.', $key);
            $value = $array;
            foreach ($keys as $k) {
                if (!isset($value[$k])) {
                    return null;
                }
                $value = $value[$k];
            }
            return $value;
        }

        return null;
    }

    /**
     * Extract URL from result item (for batch processing)
     *
     * @param string $platform Platform name
     * @param array $item Result item
     * @return string URL
     */
    private static function extract_url_from_result($platform, $item) {
        $url_fields = [
            'linkedin' => ['url', 'linkedinUrl', 'profileUrl'],
            'twitter' => ['url', 'twitterUrl', 'profileUrl'],
            'instagram' => ['url', 'profileUrl', 'instagramUrl'],
            'facebook' => ['url', 'pageUrl', 'facebookUrl'],
            'tiktok' => ['url', 'profileUrl', 'tiktokUrl'],
        ];

        foreach ($url_fields[$platform] ?? ['url'] as $field) {
            if (!empty($item[$field])) {
                return $item[$field];
            }
        }

        return '';
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
            'total_views' => 0,
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
            'timeout' => 10,
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
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        return $body['data'] ?? [];
    }

    /**
     * Get estimated cost for platforms
     *
     * @param array $platforms Platform names
     * @param int $count Number of profiles per platform
     * @return array Cost breakdown
     */
    public static function estimate_cost($platforms, $count = 1) {
        $breakdown = [];
        $total = 0;

        foreach ($platforms as $platform) {
            if (isset(self::$platform_config[$platform])) {
                $cost = (self::$platform_config[$platform]['cost_per_1k'] / 1000) * $count;
                $breakdown[$platform] = $cost;
                $total += $cost;
            }
        }

        return [
            'breakdown' => $breakdown,
            'total' => $total,
        ];
    }

    /**
     * Get supported platforms
     *
     * @return array Platform names and their costs
     */
    public static function get_supported_platforms() {
        $platforms = [];
        foreach (self::$platform_config as $name => $config) {
            $platforms[$name] = [
                'actor' => $config['actor'],
                'cost_per_1k' => $config['cost_per_1k'],
                'cost_per_profile' => $config['cost_per_1k'] / 1000,
            ];
        }
        return $platforms;
    }
}
