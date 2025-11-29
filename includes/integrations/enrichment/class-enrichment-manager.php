<?php
/**
 * Enrichment Manager
 *
 * Central manager for social media profile enrichment.
 * Handles provider selection, fallback logic, and cost tracking.
 *
 * Provider Priority (configurable):
 * 1. ScrapingDog - Primary for LinkedIn (reliable dedicated API)
 * 2. Apify - Fallback for all platforms (marketplace actors)
 *
 * @package PodcastInfluenceTracker
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Enrichment_Manager {

    /**
     * Registered providers
     * @var array<string, PIT_Enrichment_Provider_Interface>
     */
    private static $providers = [];

    /**
     * Provider priority by platform
     * @var array
     */
    private static $platform_priorities = [
        'linkedin' => ['scrapingdog', 'apify'],
        'twitter' => ['scrapingdog', 'apify'],
        'instagram' => ['scrapingdog', 'apify'],
        'facebook' => ['scrapingdog', 'apify'],
        'youtube' => ['scrapingdog'],
        'tiktok' => ['apify'],
    ];

    /**
     * Initialize the manager
     */
    public static function init() {
        // Register default providers
        self::register_provider(new PIT_ScrapingDog_Provider());
        self::register_provider(new PIT_Apify_Provider());

        // Allow plugins to register additional providers
        do_action('pit_register_enrichment_providers');
    }

    /**
     * Register a provider
     */
    public static function register_provider(PIT_Enrichment_Provider_Interface $provider) {
        self::$providers[$provider->get_name()] = $provider;
    }

    /**
     * Get a specific provider
     */
    public static function get_provider(string $name): ?PIT_Enrichment_Provider_Interface {
        return self::$providers[$name] ?? null;
    }

    /**
     * Get all registered providers
     */
    public static function get_providers(): array {
        return self::$providers;
    }

    /**
     * Get configured providers (those with API keys set)
     */
    public static function get_configured_providers(): array {
        return array_filter(self::$providers, function($provider) {
            return $provider->is_configured();
        });
    }

    /**
     * Fetch metrics using best available provider
     *
     * @param string $platform Platform name
     * @param string $profile_url Profile URL
     * @param string $handle Optional handle
     * @param string|null $preferred_provider Force specific provider
     * @return array|WP_Error
     */
    public static function fetch_metrics(
        string $platform,
        string $profile_url,
        string $handle = '',
        ?string $preferred_provider = null
    ) {
        // If specific provider requested
        if ($preferred_provider && isset(self::$providers[$preferred_provider])) {
            $provider = self::$providers[$preferred_provider];
            if ($provider->is_configured() && $provider->supports_platform($platform)) {
                return $provider->fetch_metrics($platform, $profile_url, $handle);
            }
        }

        // Get priority list for this platform
        $priorities = self::$platform_priorities[$platform] ?? array_keys(self::$providers);

        $last_error = null;

        foreach ($priorities as $provider_name) {
            if (!isset(self::$providers[$provider_name])) {
                continue;
            }

            $provider = self::$providers[$provider_name];

            if (!$provider->is_configured()) {
                continue;
            }

            if (!$provider->supports_platform($platform)) {
                continue;
            }

            $result = $provider->fetch_metrics($platform, $profile_url, $handle);

            if (!is_wp_error($result)) {
                // Success! Log which provider was used
                do_action('pit_enrichment_success', $platform, $provider_name, $profile_url);
                return $result;
            }

            // Store error for potential fallback
            $last_error = $result;

            // Log the failure
            do_action('pit_enrichment_failed', $platform, $provider_name, $profile_url, $result);
        }

        // All providers failed
        if ($last_error) {
            return $last_error;
        }

        return new WP_Error(
            'no_provider',
            "No configured provider available for platform: $platform"
        );
    }

    /**
     * Batch fetch metrics
     */
    public static function batch_fetch(
        string $platform,
        array $profiles,
        ?string $preferred_provider = null
    ) {
        // Similar logic to fetch_metrics but for batch
        $priorities = self::$platform_priorities[$platform] ?? array_keys(self::$providers);

        if ($preferred_provider) {
            array_unshift($priorities, $preferred_provider);
            $priorities = array_unique($priorities);
        }

        foreach ($priorities as $provider_name) {
            if (!isset(self::$providers[$provider_name])) {
                continue;
            }

            $provider = self::$providers[$provider_name];

            if (!$provider->is_configured() || !$provider->supports_platform($platform)) {
                continue;
            }

            $result = $provider->batch_fetch($platform, $profiles);

            if (!is_wp_error($result)) {
                return $result;
            }
        }

        return new WP_Error('no_provider', "No provider available for batch fetch on: $platform");
    }

    /**
     * Get cost estimate for enrichment
     */
    public static function estimate_cost(string $platform, int $count = 1, ?string $provider = null): array {
        $estimates = [];

        $providers_to_check = $provider
            ? [self::$providers[$provider] ?? null]
            : self::$providers;

        foreach (array_filter($providers_to_check) as $p) {
            if ($p->supports_platform($platform)) {
                $cost_per = $p->get_cost_per_profile($platform);
                $estimates[$p->get_name()] = [
                    'cost_per_profile' => $cost_per,
                    'total_cost' => $cost_per * $count,
                    'configured' => $p->is_configured(),
                ];
            }
        }

        // Find cheapest configured option
        $cheapest = null;
        $cheapest_cost = PHP_FLOAT_MAX;

        foreach ($estimates as $name => $data) {
            if ($data['configured'] && $data['cost_per_profile'] < $cheapest_cost) {
                $cheapest = $name;
                $cheapest_cost = $data['cost_per_profile'];
            }
        }

        return [
            'platform' => $platform,
            'count' => $count,
            'estimates' => $estimates,
            'recommended' => $cheapest,
            'recommended_cost' => $cheapest ? $estimates[$cheapest]['total_cost'] : null,
        ];
    }

    /**
     * Get platform availability across providers
     */
    public static function get_platform_support(): array {
        $support = [];

        $all_platforms = ['linkedin', 'twitter', 'instagram', 'facebook', 'youtube', 'tiktok'];

        foreach ($all_platforms as $platform) {
            $support[$platform] = [];

            foreach (self::$providers as $name => $provider) {
                if ($provider->supports_platform($platform)) {
                    $support[$platform][$name] = [
                        'configured' => $provider->is_configured(),
                        'cost_per_1k' => $provider->get_cost_per_profile($platform) * 1000,
                    ];
                }
            }
        }

        return $support;
    }

    /**
     * Validate all provider credentials
     */
    public static function validate_all_credentials(): array {
        $results = [];

        foreach (self::$providers as $name => $provider) {
            if (!$provider->is_configured()) {
                $results[$name] = [
                    'status' => 'not_configured',
                    'message' => 'API key not set',
                ];
                continue;
            }

            $validation = $provider->validate_credentials();

            if (is_wp_error($validation)) {
                $results[$name] = [
                    'status' => 'invalid',
                    'message' => $validation->get_error_message(),
                ];
            } else {
                $results[$name] = [
                    'status' => 'valid',
                    'message' => 'Credentials verified',
                ];
            }
        }

        return $results;
    }

    /**
     * Set provider priority for a platform
     */
    public static function set_platform_priority(string $platform, array $providers) {
        self::$platform_priorities[$platform] = $providers;
    }

    /**
     * Get pricing comparison table
     */
    public static function get_pricing_comparison(): array {
        $comparison = [];
        $platforms = ['linkedin', 'twitter', 'instagram', 'facebook', 'youtube', 'tiktok'];

        foreach ($platforms as $platform) {
            $comparison[$platform] = [];

            foreach (self::$providers as $name => $provider) {
                if ($provider->supports_platform($platform)) {
                    $comparison[$platform][$name] = [
                        'cost_per_profile' => $provider->get_cost_per_profile($platform),
                        'cost_per_1k' => $provider->get_cost_per_profile($platform) * 1000,
                    ];
                }
            }
        }

        return $comparison;
    }
}

// Initialize on plugins_loaded
add_action('plugins_loaded', ['PIT_Enrichment_Manager', 'init'], 15);
