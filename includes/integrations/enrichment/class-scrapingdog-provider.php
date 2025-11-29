<?php
/**
 * ScrapingDog Enrichment Provider
 *
 * Uses ScrapingDog API for social media profile enrichment.
 * Simple REST API with dedicated endpoints per platform.
 *
 * Pricing (per 1,000 profiles on LITE plan):
 * - LinkedIn: ~$10 (50 credits/request, 200K credits = 4K requests)
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
                'credits_per_request' => 50,
                'cost_per_1k' => 10.00, // 50 credits × 1000 / 200000 credits × $40
                'response_map' => [
                    'followers' => ['followers', 'connections', 'follower_count'],
                    'name' => ['full_name', 'name', 'firstName'],
                    'headline' => ['headline', 'title', 'occupation'],
                    'location' => ['location', 'city'],
                    'company' => ['company', 'current_company', 'company_name'],
                    'about' => ['about', 'summary', 'bio'],
                    'posts' => ['posts_count', 'activities'],
                ],
            ],
            'twitter' => [
                'endpoint' => '/x/profile',
                'credits_per_request' => 5,
                'cost_per_1k' => 1.00,
                'param_name' => 'profile', // ScrapingDog uses 'profile' param for X/Twitter
                'response_map' => [
                    'followers' => ['followers_count', 'followersCount', 'followers'],
                    'following' => ['following_count', 'followingCount', 'friends_count'],
                    'posts' => ['tweets_count', 'tweetsCount', 'statuses_count'],
                    'name' => ['name', 'displayName', 'full_name'],
                    'bio' => ['description', 'bio'],
                    'location' => ['location'],
                    'verified' => ['verified', 'is_verified'],
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
        
        // For Twitter/X, we need the handle/username, not the full URL
        if ($platform === 'twitter') {
            $profile_value = $handle ?: $this->extract_handle_from_url($profile_url, 'twitter');
            // Debug: Log what we're sending
            error_log("ScrapingDog Twitter - handle: '$handle', extracted: '" . $this->extract_handle_from_url($profile_url, 'twitter') . "', using: '$profile_value'");
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
        
        // Debug: Log the full request
        error_log("ScrapingDog request - platform: $platform, param_name: $param_name, value: $profile_value");

        // Add parsed=true to get JSON response (only for LinkedIn)
        if ($platform === 'linkedin') {
            $params['parsed'] = 'true';
        }

        $request_url = $url . '?' . http_build_query($params);
        
        // Debug: Log the full request URL (hide API key)
        $debug_url = preg_replace('/api_key=[^&]+/', 'api_key=***', $request_url);
        error_log("ScrapingDog FULL REQUEST URL: $debug_url");

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

        // Map response to normalized metrics
        $data = $parsed['data'];
        
        // Handle array response (some endpoints return array)
        if (isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }

        $metrics = $this->map_response($data, $config['response_map']);
        $metrics['cost'] = $this->get_cost_per_profile($platform);
        $metrics['provider'] = $this->name;
        $metrics['credits_used'] = $config['credits_per_request'];

        return $metrics;
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

        // Make a simple test request (Google Search uses 5 credits)
        // We'll use the account info endpoint if available, or a minimal request
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
        // ScrapingDog doesn't expose credits via API
        // User needs to check dashboard
        return [
            'provider' => $this->name,
            'note' => 'Check credits at https://api.scrapingdog.com/dashboard',
        ];
    }
}
