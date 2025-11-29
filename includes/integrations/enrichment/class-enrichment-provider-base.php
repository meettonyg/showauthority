<?php
/**
 * Abstract Enrichment Provider Base Class
 *
 * Provides common functionality for all enrichment providers.
 *
 * @package PodcastInfluenceTracker
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class PIT_Enrichment_Provider_Base implements PIT_Enrichment_Provider_Interface {

    /**
     * Provider name
     * @var string
     */
    protected $name = '';

    /**
     * API key setting name
     * @var string
     */
    protected $api_key_setting = '';

    /**
     * Platform configurations
     * @var array
     */
    protected $platform_config = [];

    /**
     * Get provider name
     */
    public function get_name(): string {
        return $this->name;
    }

    /**
     * Get supported platforms
     */
    public function get_supported_platforms(): array {
        return array_keys($this->platform_config);
    }

    /**
     * Check if platform is supported
     */
    public function supports_platform(string $platform): bool {
        return isset($this->platform_config[$platform]);
    }

    /**
     * Get cost per profile for a platform
     */
    public function get_cost_per_profile(string $platform): float {
        if (!isset($this->platform_config[$platform])) {
            return 0.0;
        }
        return $this->platform_config[$platform]['cost_per_1k'] / 1000;
    }

    /**
     * Check if provider is configured
     */
    public function is_configured(): bool {
        $api_key = $this->get_api_key();
        return !empty($api_key);
    }

    /**
     * Get API key from settings
     */
    protected function get_api_key(): string {
        $settings = PIT_Settings::get_all();
        return $settings[$this->api_key_setting] ?? '';
    }

    /**
     * Make HTTP GET request
     */
    protected function http_get(string $url, array $args = []) {
        $defaults = [
            'timeout' => 30,
            'headers' => [],
        ];
        $args = wp_parse_args($args, $defaults);

        return wp_remote_get($url, $args);
    }

    /**
     * Make HTTP POST request
     */
    protected function http_post(string $url, array $body, array $args = []) {
        $defaults = [
            'timeout' => 60,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($body),
        ];
        $args = wp_parse_args($args, $defaults);

        return wp_remote_post($url, $args);
    }

    /**
     * Parse HTTP response
     */
    protected function parse_response($response) {
        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return [
            'status' => $status_code,
            'data' => $data,
            'raw' => $body,
        ];
    }

    /**
     * Create empty metrics template
     */
    protected function empty_metrics(): array {
        return [
            'followers' => 0,
            'following' => 0,
            'posts' => 0,
            'avg_likes' => 0,
            'avg_comments' => 0,
            'avg_shares' => 0,
            'engagement_rate' => 0,
            'total_views' => 0,
            'name' => '',
            'bio' => '',
            'location' => '',
            'verified' => false,
            'raw_data' => [],
            'provider' => $this->name,
        ];
    }

    /**
     * Get nested value from array using dot notation
     */
    protected function get_nested_value(array $array, string $key) {
        if (isset($array[$key])) {
            return $array[$key];
        }

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
     * Map response fields to normalized metrics
     */
    protected function map_response(array $data, array $field_map): array {
        $metrics = $this->empty_metrics();

        foreach ($field_map as $our_field => $possible_keys) {
            if (!is_array($possible_keys)) {
                $possible_keys = [$possible_keys];
            }

            foreach ($possible_keys as $key) {
                $value = $this->get_nested_value($data, $key);
                if ($value !== null) {
                    $metrics[$our_field] = $value;
                    break;
                }
            }
        }

        $metrics['raw_data'] = $data;

        return $metrics;
    }

    /**
     * Extract handle from URL
     */
    protected function extract_handle_from_url(string $url, string $platform): string {
        $patterns = [
            'linkedin' => '/linkedin\.com\/in\/([^\/\?]+)/i',
            'twitter' => '/(?:twitter|x)\.com\/([^\/\?]+)/i',
            'instagram' => '/instagram\.com\/([^\/\?]+)/i',
            'facebook' => '/facebook\.com\/([^\/\?]+)/i',
            'tiktok' => '/tiktok\.com\/@?([^\/\?]+)/i',
            'youtube' => '/youtube\.com\/(?:@|channel\/|user\/)?([^\/\?]+)/i',
        ];

        if (isset($patterns[$platform]) && preg_match($patterns[$platform], $url, $matches)) {
            return ltrim($matches[1], '@');
        }

        return '';
    }
}
