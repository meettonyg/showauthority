<?php
/**
 * ScrapingDog Enrichment Provider
 *
 * Uses ScrapingDog API for social media profile enrichment.
 * Simple REST API with dedicated endpoints per platform.
 *
 * Pricing (per 1,000 profiles on LITE plan):
 * - LinkedIn: ~$50 (250 credits/request per docs, 200K credits = 800 requests)
 * - Twitter/X: ~$1 (5 credits/request)
 * - Instagram: ~$3 (15 credits/request)
 * - Facebook: ~$1 (5 credits/request)
 * - YouTube: ~$1 (5 credits/request)
 *
 * @see https://docs.scrapingdog.com/
 * @package PodcastInfluenceTracker
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_ScrapingDog_Provider extends PIT_Enrichment_Provider_Base {

    const API_BASE_URL = 'https://api.scrapingdog.com';

    /**
     * Constructor
     */
    public function __construct() {
        $this->name = 'scrapingdog';
        $this->api_key_setting = 'scrapingdog_api_key';

        // Platform configurations with credits per request
        // Based on LITE plan: $40/mo = 200K credits
        $this->platform_config = [
            'linkedin' => [
                'endpoint' => '/linkedin',
                'credits_per_request' => 250,
                'cost_per_1k' => 50.00,
                'param_name' => 'linkId',
                'extra_params' => ['type' => 'profile'],
                // Response mapping - ScrapingDog returns data in these keys
                'response_map' => [
                    // Profile identity - use fullName as primary
                    'name' => ['fullName', 'full_name', 'name', 'first_name'],
                    'first_name' => ['first_name', 'firstName'],
                    'last_name' => ['last_name', 'lastName'],
                    'headline' => ['headline', 'sub_title', 'title', 'occupation'],
                    'about' => ['about', 'summary', 'bio', 'description'],
                    'profile_picture' => ['profile_photo', 'profile_picture', 'avatar', 'profilePicture'],
                    'background_image' => ['background_cover_image_url', 'backgroundImage'],
                    'public_identifier' => ['public_identifier', 'publicIdentifier', 'username'],
                    // Work info
                    'company' => ['company_name', 'company', 'current_company'],
                    // Experience and education (arrays)
                    'experience' => ['experience', 'positions', 'work_experience'],
                    'education' => ['education', 'schools'],
                    // Articles
                    'articles' => ['articles', 'posts'],
                    // Internal IDs
                    'linkedin_internal_id' => ['linkedin_internal_id', 'linkedinId'],
                ],
            ],
            'twitter' => [
                'endpoint' => '/x/profile',
                'credits_per_request' => 5,
                'cost_per_1k' => 1.00,
                'param_name' => 'profileId',
                'response_map' => [
                    'followers' => ['followers_count', 'followersCount', 'followers'],
                    'following' => ['friends_count', 'following_count', 'followingCount'],
                    'posts' => ['statuses_count', 'tweets_count', 'tweetsCount'],
                    'name' => ['name', 'profile_name', 'displayName', 'full_name'],
                    'bio' => ['description', 'bio'],
                    'location' => ['location'],
                    'verified' => ['is_blue_verified', 'verified', 'is_verified'],
                    'likes' => ['favourites_count', 'likes_count'],
                    'media_count' => ['media_count'],
                    'handle' => ['screen_name', 'profile_handle'],
                ],
            ],
            'instagram' => [
                'endpoint' => '/instagram',
                'credits_per_request' => 15,
                'cost_per_1k' => 3.00,
                'response_map' => [
                    'followers' => ['followers', 'follower_count', 'edge_followed_by.count'],
                    'following' => ['following', 'following_count', 'edge_follow.count'],
                    'posts' => ['posts', 'media_count', 'edge_owner_to_timeline_media.count'],
                    'name' => ['full_name', 'name'],
                    'bio' => ['biography', 'bio'],
                    'verified' => ['is_verified', 'verified'],
                ],
            ],
            'facebook' => [
                'endpoint' => '/facebook',
                'credits_per_request' => 5,
                'cost_per_1k' => 1.00,
                'response_map' => [
                    'followers' => ['followers', 'likes', 'follower_count'],
                    'name' => ['name', 'page_name'],
                    'about' => ['about', 'description'],
                    'category' => ['category', 'categories'],
                ],
            ],
            'youtube' => [
                'endpoint' => '/youtube',
                'credits_per_request' => 5,
                'cost_per_1k' => 1.00,
                'response_map' => [
                    'followers' => ['subscribers', 'subscriber_count', 'subscriberCount'],
                    'posts' => ['videos', 'video_count', 'videoCount'],
                    'total_views' => ['views', 'view_count', 'viewCount'],
                    'name' => ['name', 'channel_name', 'title'],
                    'bio' => ['description', 'about'],
                ],
            ],
        ];
    }

    /**
     * Fetch metrics for a single profile
     */
    public function fetch_metrics(string $platform, string $profile_url, string $handle = '') {
        if (!$this->is_configured()) {
            return new WP_Error(
                'not_configured',
                'ScrapingDog API key not configured. Add it in Settings → API Keys.'
            );
        }

        if (!$this->supports_platform($platform)) {
            return new WP_Error(
                'unsupported_platform',
                "Platform '$platform' is not supported by ScrapingDog provider."
            );
        }

        // LinkedIn: ScrapingDog only supports personal profiles (/in/), not company pages (/company/)
        if ($platform === 'linkedin' && strpos($profile_url, '/company/') !== false) {
            return new WP_Error(
                'unsupported_profile_type',
                'ScrapingDog does not support LinkedIn company pages, only personal profiles (/in/).',
                ['profile_type' => 'company']
            );
        }

        $config = $this->platform_config[$platform];
        $api_key = $this->get_api_key();

        // Build request URL
        $url = self::API_BASE_URL . $config['endpoint'];
        
        // Different platforms use different parameter names
        $param_name = $config['param_name'] ?? 'link';
        
        // Determine the profile value to send
        if ($platform === 'twitter') {
            $profile_value = $handle ?: $this->extract_handle_from_url($profile_url, 'twitter');
        } elseif ($platform === 'linkedin') {
            $profile_value = $handle ?: $this->extract_handle_from_url($profile_url, 'linkedin');
        } else {
            $profile_value = $profile_url;
        }
        
        // Ensure we have a valid profile value for Twitter
        if ($platform === 'twitter' && empty($profile_value)) {
            return new WP_Error(
                'missing_handle',
                'Could not extract Twitter handle from URL: ' . $profile_url
            );
        }
        
        $params = [
            'api_key' => $api_key,
            $param_name => $profile_value,
        ];
        
        // Add any extra params from config (e.g., type=profile for LinkedIn)
        if (!empty($config['extra_params'])) {
            $params = array_merge($params, $config['extra_params']);
        }

        $request_url = $url . '?' . http_build_query($params);

        // Make request
        $response = $this->http_get($request_url, ['timeout' => 60]);
        $parsed = $this->parse_response($response);

        if (is_wp_error($parsed)) {
            return $parsed;
        }

        // Check for errors
        if ($parsed['status'] !== 200) {
            $error_msg = $parsed['data']['message'] ?? $parsed['data']['error'] ?? 'Request failed';
            return new WP_Error(
                'api_error',
                "ScrapingDog API error ($platform): $error_msg",
                ['status' => $parsed['status'], 'response' => $parsed['data']]
            );
        }

        $data = $parsed['data'];
        
        // Handle array response (some endpoints return array)
        if (isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }

        // Platform-specific data extraction
        if ($platform === 'linkedin') {
            $metrics = $this->extract_linkedin_data($data, $config['response_map']);
        } elseif ($platform === 'twitter') {
            $data = $this->extract_twitter_user_data($data);
            $metrics = $this->map_response($data, $config['response_map']);
        } else {
            $metrics = $this->map_response($data, $config['response_map']);
        }

        $metrics['cost'] = $this->get_cost_per_profile($platform);
        $metrics['provider'] = $this->name;
        $metrics['credits_used'] = $config['credits_per_request'];

        return $metrics;
    }

    /**
     * Extract LinkedIn profile data from ScrapingDog response
     * ScrapingDog returns profile data directly at root level
     */
    private function extract_linkedin_data(array $raw_data, array $response_map): array {
        $metrics = $this->empty_metrics();
        
        // Map fields from raw data
        foreach ($response_map as $our_field => $possible_keys) {
            if (!is_array($possible_keys)) {
                $possible_keys = [$possible_keys];
            }

            foreach ($possible_keys as $key) {
                $value = $this->get_nested_value($raw_data, $key);
                if ($value !== null && $value !== '') {
                    $metrics[$our_field] = $value;
                    break;
                }
            }
        }
        
        // Extract follower count from location string if present (ScrapingDog quirk)
        // The "location" field sometimes contains "19M followers" instead of actual location
        if (!empty($raw_data['location']) && preg_match('/^([\d.]+[KMB]?)\s*followers?$/i', $raw_data['location'], $matches)) {
            $metrics['followers'] = $this->parse_follower_count($matches[1]);
            // Clear location since it's not actually a location
            $metrics['location'] = '';
        }
        
        // Extract company from first experience entry if not set
        if (empty($metrics['company']) && !empty($raw_data['experience']) && is_array($raw_data['experience'])) {
            $first_job = $raw_data['experience'][0] ?? null;
            if ($first_job && !empty($first_job['company_name'])) {
                $metrics['company'] = $first_job['company_name'];
            }
        }
        
        // Count articles as posts if available
        if (!empty($raw_data['articles']) && is_array($raw_data['articles'])) {
            $metrics['posts'] = count($raw_data['articles']);
        }
        
        // Store the full raw data for reference
        $metrics['raw_data'] = $raw_data;
        
        return $metrics;
    }
    
    /**
     * Parse follower count string to number
     * Handles formats like "19M", "1.5K", "500"
     */
    private function parse_follower_count(string $count_str): int {
        $count_str = strtoupper(trim($count_str));
        
        $multipliers = [
            'K' => 1000,
            'M' => 1000000,
            'B' => 1000000000,
        ];
        
        foreach ($multipliers as $suffix => $multiplier) {
            if (strpos($count_str, $suffix) !== false) {
                $number = (float) str_replace($suffix, '', $count_str);
                return (int) ($number * $multiplier);
            }
        }
        
        return (int) $count_str;
    }

    /**
     * Batch fetch metrics
     * ScrapingDog doesn't have a native batch endpoint, so we iterate
     */
    public function batch_fetch(string $platform, array $profiles) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', 'ScrapingDog API key not configured.');
        }

        $results = [];
        $total_cost = 0;
        $total_credits = 0;
        $errors = [];

        foreach ($profiles as $profile) {
            $url = $profile['url'] ?? '';
            $handle = $profile['handle'] ?? '';

            if (empty($url)) {
                continue;
            }

            $result = $this->fetch_metrics($platform, $url, $handle);

            if (is_wp_error($result)) {
                $errors[$url] = $result->get_error_message();
                continue;
            }

            $results[$url] = $result;
            $total_cost += $result['cost'] ?? 0;
            $total_credits += $result['credits_used'] ?? 0;

            // Rate limiting: small delay between requests
            usleep(200000); // 200ms
        }

        return [
            'results' => $results,
            'total_cost' => $total_cost,
            'total_credits' => $total_credits,
            'profiles_fetched' => count($results),
            'errors' => $errors,
        ];
    }

    /**
     * Validate API credentials
     */
    public function validate_credentials() {
        $api_key = $this->get_api_key();

        if (empty($api_key)) {
            return new WP_Error('no_api_key', 'ScrapingDog API key is not set.');
        }

        // Make a simple test request
        $test_url = self::API_BASE_URL . '/scrape?api_key=' . $api_key . '&url=https://httpbin.org/ip';

        $response = $this->http_get($test_url, ['timeout' => 15]);
        $parsed = $this->parse_response($response);

        if (is_wp_error($parsed)) {
            return $parsed;
        }

        if ($parsed['status'] === 401 || $parsed['status'] === 403) {
            return new WP_Error('invalid_api_key', 'Invalid ScrapingDog API key.');
        }

        if ($parsed['status'] === 402) {
            return new WP_Error('no_credits', 'ScrapingDog account has no credits remaining.');
        }

        if ($parsed['status'] === 200) {
            return true;
        }

        return new WP_Error(
            'validation_failed',
            'Failed to validate ScrapingDog API key. Status: ' . $parsed['status']
        );
    }

    /**
     * Get account credits info (if available)
     */
    public function get_credits_info(): array {
        return [
            'provider' => $this->name,
            'note' => 'Check credits at https://api.scrapingdog.com/dashboard',
        ];
    }
    
    /**
     * Extract user data from Twitter/X API response
     */
    private function extract_twitter_user_data(array $raw_data): array {
        // Structure 1: Direct user object
        if (isset($raw_data['user'])) {
            return $raw_data['user'];
        }
        
        // Structure 2: Nested in data.tweetResult.result.core.user_results.result
        if (isset($raw_data['data']['tweetResult']['result']['core']['user_results']['result'])) {
            $user = $raw_data['data']['tweetResult']['result']['core']['user_results']['result'];
            
            return [
                'name' => $user['core']['name'] ?? '',
                'screen_name' => $user['core']['screen_name'] ?? '',
                'description' => $user['legacy']['description'] ?? '',
                'followers_count' => $user['legacy']['followers_count'] ?? 0,
                'friends_count' => $user['legacy']['friends_count'] ?? 0,
                'statuses_count' => $user['legacy']['statuses_count'] ?? 0,
                'favourites_count' => $user['legacy']['favourites_count'] ?? 0,
                'media_count' => $user['legacy']['media_count'] ?? 0,
                'listed_count' => $user['legacy']['listed_count'] ?? 0,
                'is_blue_verified' => $user['is_blue_verified'] ?? false,
                'location' => $user['location']['location'] ?? '',
                'profile_image_url' => $user['avatar']['image_url'] ?? '',
                'created_at' => $user['core']['created_at'] ?? '',
            ];
        }
        
        // Structure 3: Direct profile response
        if (isset($raw_data['profile_name']) || isset($raw_data['followers_count'])) {
            return $raw_data;
        }
        
        // Structure 4: Nested in 'data' only
        if (isset($raw_data['data']) && is_array($raw_data['data']) && isset($raw_data['data']['followers_count'])) {
            return $raw_data['data'];
        }
        
        return $raw_data;
    }
}
