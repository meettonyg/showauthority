<?php
/**
 * Apify Enrichment Provider
 *
 * Uses Apify actor marketplace for social media profile enrichment.
 * Actors may change/disappear, so this is used as a fallback.
 *
 * Pricing varies by actor (per 1,000 profiles):
 * - LinkedIn: $3-10 (actors vary)
 * - Twitter: $3
 * - Instagram: $5
 * - Facebook: $5
 * - TikTok: $3
 *
 * @see https://apify.com/store
 * @package PodcastInfluenceTracker
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Apify_Provider extends PIT_Enrichment_Provider_Base {

    const API_BASE_URL = 'https://api.apify.com/v2';

    /**
     * Constructor
     */
    public function __construct() {
        $this->name = 'apify';
        $this->api_key_setting = 'apify_api_token';

        // Platform configurations with actor IDs
        // Note: Actors can be deprecated/removed - check availability
        $this->platform_config = [
            'linkedin' => [
                'actor' => 'dev_fusion/linkedin-profile-scraper',
                'cost_per_1k' => 10.00,
                'input_format' => 'urls_array',
                'response_map' => [
                    'followers' => ['followersCount', 'connectionsCount', 'followerCount', 'connections'],
                    'name' => ['fullName', 'name', 'firstName'],
                    'headline' => ['headline', 'title', 'occupation'],
                    'location' => ['location', 'locationName', 'city'],
                    'company' => ['currentCompany', 'companyName', 'company'],
                    'about' => ['about', 'summary', 'bio'],
                ],
            ],
            'twitter' => [
                'actor' => 'apidojo/tweet-scraper',
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
                    'verified' => ['isVerified', 'is_verified'],
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
    }

    /**
     * Fetch metrics for a single profile
     */
    public function fetch_metrics(string $platform, string $profile_url, string $handle = '') {
        if (!$this->is_configured()) {
            return new WP_Error(
                'not_configured',
                'Apify API token not configured. Add it in Settings → API Keys.'
            );
        }

        if (!$this->supports_platform($platform)) {
            return new WP_Error(
                'unsupported_platform',
                "Platform '$platform' is not supported by Apify provider."
            );
        }

        $config = $this->platform_config[$platform];
        $api_token = $this->get_api_key();

        // Prepare input based on platform
        $input = $this->prepare_input($platform, $profile_url, $handle);

        // Run actor
        $run_result = $this->run_actor($config['actor'], $input, $api_token);

        if (is_wp_error($run_result)) {
            return $run_result;
        }

        // Parse results
        if (empty($run_result['data'])) {
            return new WP_Error('no_data', 'No data returned from Apify actor.');
        }

        $data = $run_result['data'][0] ?? $run_result['data'];
        $metrics = $this->map_response($data, $config['response_map']);
        $metrics['cost'] = $this->get_cost_per_profile($platform);
        $metrics['provider'] = $this->name;
        $metrics['actor'] = $config['actor'];

        return $metrics;
    }

    /**
     * Batch fetch metrics
     */
    public function batch_fetch(string $platform, array $profiles) {
        if (!$this->is_configured()) {
            return new WP_Error('not_configured', 'Apify API token not configured.');
        }

        if (!$this->supports_platform($platform)) {
            return new WP_Error('unsupported_platform', "Platform '$platform' not supported.");
        }

        $config = $this->platform_config[$platform];
        $api_token = $this->get_api_key();

        // Prepare batch input
        $input = $this->prepare_batch_input($platform, $profiles);

        // Run actor
        $run_result = $this->run_actor($config['actor'], $input, $api_token);

        if (is_wp_error($run_result)) {
            return $run_result;
        }

        // Parse results
        $results = [];
        $cost_per_profile = $this->get_cost_per_profile($platform);

        foreach ($run_result['data'] as $item) {
            $url = $this->extract_url_from_result($platform, $item);
            $metrics = $this->map_response($item, $config['response_map']);
            $metrics['cost'] = $cost_per_profile;
            $metrics['provider'] = $this->name;
            $results[$url] = $metrics;
        }

        return [
            'results' => $results,
            'total_cost' => count($results) * $cost_per_profile,
            'profiles_fetched' => count($results),
        ];
    }

    /**
     * Validate API credentials
     */
    public function validate_credentials() {
        $api_token = $this->get_api_key();

        if (empty($api_token)) {
            return new WP_Error('no_api_token', 'Apify API token is not set.');
        }

        $endpoint = self::API_BASE_URL . '/users/me?token=' . $api_token;
        $response = $this->http_get($endpoint, ['timeout' => 10]);
        $parsed = $this->parse_response($response);

        if (is_wp_error($parsed)) {
            return $parsed;
        }

        if ($parsed['status'] === 200) {
            return true;
        }

        return new WP_Error('invalid_token', 'Invalid Apify API token.');
    }

    /**
     * Prepare input for actor based on platform
     */
    private function prepare_input(string $platform, string $url, string $handle): array {
        $config = $this->platform_config[$platform];

        switch ($config['input_format']) {
            case 'urls_array':
                return ['urls' => [$url]];

            case 'handles_array':
                $clean_handle = $handle ?: $this->extract_handle_from_url($url, $platform);
                return ['usernames' => [$clean_handle]];

            case 'direct_urls':
                return [
                    'directUrls' => [$url],
                    'resultsType' => 'details',
                    'resultsLimit' => 1,
                ];

            case 'start_urls':
                return [
                    'startUrls' => [['url' => $url]],
                    'maxPosts' => 0,
                ];

            case 'profiles_array':
                $clean_handle = $handle ?: $this->extract_handle_from_url($url, $platform);
                return ['profiles' => [$clean_handle]];

            default:
                return ['url' => $url];
        }
    }

    /**
     * Prepare batch input
     */
    private function prepare_batch_input(string $platform, array $profiles): array {
        $config = $this->platform_config[$platform];
        $urls = [];
        $handles = [];

        foreach ($profiles as $profile) {
            $url = $profile['url'] ?? '';
            $handle = $profile['handle'] ?? '';

            if (!empty($url)) {
                $urls[] = $url;
            }
            if (!empty($handle)) {
                $handles[] = ltrim($handle, '@');
            } elseif (!empty($url)) {
                $handles[] = $this->extract_handle_from_url($url, $platform);
            }
        }

        switch ($config['input_format']) {
            case 'urls_array':
                return ['urls' => $urls];

            case 'handles_array':
                return ['usernames' => array_filter($handles)];

            case 'direct_urls':
                return ['directUrls' => $urls, 'resultsType' => 'details'];

            case 'start_urls':
                return [
                    'startUrls' => array_map(fn($u) => ['url' => $u], $urls),
                    'maxPosts' => 0,
                ];

            case 'profiles_array':
                return ['profiles' => array_filter($handles)];

            default:
                return ['urls' => $urls];
        }
    }

    /**
     * Run Apify actor
     */
    private function run_actor(string $actor_id, array $input, string $api_token) {
        $endpoint = self::API_BASE_URL . '/acts/' . $actor_id . '/runs';

        $response = wp_remote_post($endpoint, [
            'timeout' => 180,
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

        // Wait for completion
        $result = $this->wait_for_run($run_id, $api_token);
        if (is_wp_error($result)) {
            return $result;
        }

        // Fetch dataset
        $data = $this->fetch_dataset($result['defaultDatasetId'], $api_token);
        if (is_wp_error($data)) {
            return $data;
        }

        return [
            'data' => $data,
            'run_id' => $run_id,
        ];
    }

    /**
     * Wait for actor run to complete
     */
    private function wait_for_run(string $run_id, string $api_token) {
        $max_attempts = 90;
        $attempt = 0;

        while ($attempt < $max_attempts) {
            $endpoint = self::API_BASE_URL . '/actor-runs/' . $run_id . '?token=' . $api_token;
            $response = $this->http_get($endpoint, ['timeout' => 10]);

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

            sleep(2);
            $attempt++;
        }

        return new WP_Error('timeout', 'Actor run timed out after 3 minutes');
    }

    /**
     * Fetch dataset results
     */
    private function fetch_dataset(string $dataset_id, string $api_token) {
        $endpoint = self::API_BASE_URL . '/datasets/' . $dataset_id . '/items?token=' . $api_token;
        $response = $this->http_get($endpoint, ['timeout' => 30]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return new WP_Error('fetch_failed', 'Failed to fetch dataset');
        }

        return json_decode(wp_remote_retrieve_body($response), true) ?? [];
    }

    /**
     * Extract URL from result item
     */
    private function extract_url_from_result(string $platform, array $item): string {
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
}
