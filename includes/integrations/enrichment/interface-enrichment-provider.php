<?php
/**
 * Enrichment Provider Interface
 *
 * Abstract interface for social media enrichment providers.
 * Allows swapping between ScrapingDog, Apify, Bright Data, etc.
 *
 * @package PodcastInfluenceTracker
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

interface PIT_Enrichment_Provider_Interface {

    /**
     * Get provider name/identifier
     *
     * @return string Provider name (e.g., 'scrapingdog', 'apify')
     */
    public function get_name(): string;

    /**
     * Get supported platforms
     *
     * @return array List of supported platform names
     */
    public function get_supported_platforms(): array;

    /**
     * Check if provider supports a specific platform
     *
     * @param string $platform Platform name
     * @return bool
     */
    public function supports_platform(string $platform): bool;

    /**
     * Fetch metrics for a single profile
     *
     * @param string $platform Platform name (linkedin, twitter, instagram, etc.)
     * @param string $profile_url Full profile URL
     * @param string $handle Profile handle/username (optional)
     * @return array|WP_Error Normalized metrics or error
     */
    public function fetch_metrics(string $platform, string $profile_url, string $handle = '');

    /**
     * Batch fetch metrics for multiple profiles
     *
     * @param string $platform Platform name
     * @param array $profiles Array of ['url' => string, 'handle' => string]
     * @return array|WP_Error Results keyed by URL or error
     */
    public function batch_fetch(string $platform, array $profiles);

    /**
     * Get estimated cost per profile for a platform
     *
     * @param string $platform Platform name
     * @return float Cost in USD
     */
    public function get_cost_per_profile(string $platform): float;

    /**
     * Validate API credentials
     *
     * @return bool|WP_Error True if valid, error otherwise
     */
    public function validate_credentials();

    /**
     * Check if provider is configured (has API key)
     *
     * @return bool
     */
    public function is_configured(): bool;
}
