<?php
/**
 * Homepage Scraper - Layer 1 Discovery
 *
 * Scrapes podcast homepage to discover social media links
 * Uses native WordPress HTTP API (no external dependencies)
 *
 * Cost: $0 (no API calls)
 * Speed: 2-3 seconds
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Homepage_Scraper {

    /**
     * Scrape homepage for social links
     *
     * @param string $homepage_url Homepage URL
     * @return array Array of social links
     */
    public static function scrape($homepage_url) {
        if (empty($homepage_url)) {
            return [];
        }

        $start_time = microtime(true);

        // Fetch homepage
        $response = wp_remote_get($homepage_url, [
            'timeout' => 10,
            'user-agent' => 'Mozilla/5.0 (compatible; Podcast Influence Tracker/1.0)',
            'sslverify' => false, // Some podcast sites have SSL issues
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $html = wp_remote_retrieve_body($response);

        if (empty($html)) {
            return [];
        }

        // Extract social links
        $social_links = [];

        // Method 1: Look for common social link patterns in HTML
        $social_links = array_merge($social_links, self::extract_from_html($html));

        // Method 2: Look for Open Graph and meta tags
        $social_links = array_merge($social_links, self::extract_from_meta_tags($html));

        // Method 3: Look for JSON-LD structured data
        $social_links = array_merge($social_links, self::extract_from_jsonld($html));

        // Remove duplicates
        $social_links = self::deduplicate_links($social_links);

        $duration = microtime(true) - $start_time;

        return $social_links;
    }

    /**
     * Extract social links from HTML content
     */
    private static function extract_from_html($html) {
        // Use the RSS parser's extract function since it already handles this well
        return PIT_RSS_Parser::extract_social_links($html, 'homepage');
    }

    /**
     * Extract social links from meta tags
     */
    private static function extract_from_meta_tags($html) {
        $links = [];

        // Look for common meta tag patterns
        $meta_patterns = [
            'twitter:creator' => 'twitter',
            'twitter:site' => 'twitter',
            'og:see_also' => null, // Can contain any social link
        ];

        foreach ($meta_patterns as $property => $platform) {
            $pattern = '/<meta[^>]+(?:property|name)=["\']' . preg_quote($property, '/') . '["\'][^>]+content=["\'](.*?)["\']/i';

            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[1] as $content) {
                    // If platform is known, construct the link
                    if ($platform === 'twitter') {
                        $handle = str_replace('@', '', $content);
                        $links[] = [
                            'platform' => 'twitter',
                            'profile_url' => 'https://twitter.com/' . $handle,
                            'profile_handle' => $handle,
                            'discovery_source' => 'homepage',
                        ];
                    } else {
                        // Parse the URL to determine platform
                        $extracted = PIT_RSS_Parser::extract_social_links($content, 'homepage');
                        $links = array_merge($links, $extracted);
                    }
                }
            }
        }

        return $links;
    }

    /**
     * Extract social links from JSON-LD structured data
     */
    private static function extract_from_jsonld($html) {
        $links = [];

        // Find all JSON-LD scripts
        preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches);

        if (empty($matches[1])) {
            return [];
        }

        foreach ($matches[1] as $json_string) {
            $data = json_decode($json_string, true);

            if (!$data) {
                continue;
            }

            // Handle both single objects and arrays
            $items = isset($data['@graph']) ? $data['@graph'] : [$data];

            foreach ($items as $item) {
                // Look for sameAs property (common for social links)
                if (isset($item['sameAs'])) {
                    $same_as = is_array($item['sameAs']) ? $item['sameAs'] : [$item['sameAs']];

                    foreach ($same_as as $url) {
                        $extracted = PIT_RSS_Parser::extract_social_links($url, 'homepage');
                        $links = array_merge($links, $extracted);
                    }
                }

                // Look for specific social properties
                $social_properties = [
                    'twitter' => 'twitter',
                    'instagram' => 'instagram',
                    'facebook' => 'facebook',
                    'youtube' => 'youtube',
                    'linkedin' => 'linkedin',
                ];

                foreach ($social_properties as $key => $platform) {
                    if (isset($item[$key])) {
                        $url = $item[$key];
                        if (filter_var($url, FILTER_VALIDATE_URL)) {
                            $extracted = PIT_RSS_Parser::extract_social_links($url, 'homepage');
                            $links = array_merge($links, $extracted);
                        }
                    }
                }
            }
        }

        return $links;
    }

    /**
     * Deduplicate social links
     */
    private static function deduplicate_links($links) {
        $unique = [];
        $seen = [];

        foreach ($links as $link) {
            $key = $link['platform'] . ':' . strtolower($link['profile_url']);

            if (!isset($seen[$key])) {
                $unique[] = $link;
                $seen[$key] = true;
            }
        }

        return $unique;
    }

    /**
     * Verify a social link is accessible
     *
     * @param string $url Social media URL
     * @return bool True if accessible
     */
    public static function verify_link($url) {
        $response = wp_remote_head($url, [
            'timeout' => 5,
            'redirection' => 5,
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        return $status_code >= 200 && $status_code < 400;
    }

    /**
     * Extract profile handle from URL
     *
     * @param string $url Social media URL
     * @param string $platform Platform name
     * @return string Profile handle
     */
    public static function extract_handle($url, $platform) {
        $patterns = [
            'twitter' => '/(?:twitter\.com|x\.com)\/([a-zA-Z0-9_]+)/',
            'instagram' => '/instagram\.com\/([a-zA-Z0-9_.]+)/',
            'facebook' => '/facebook\.com\/([a-zA-Z0-9.]+)/',
            'youtube' => '/youtube\.com\/(?:c\/|channel\/|user\/|@)?([a-zA-Z0-9_-]+)/',
            'linkedin' => '/linkedin\.com\/(?:company|in)\/([a-zA-Z0-9-]+)/',
            'tiktok' => '/tiktok\.com\/@([a-zA-Z0-9_.]+)/',
        ];

        if (!isset($patterns[$platform])) {
            return '';
        }

        if (preg_match($patterns[$platform], $url, $matches)) {
            return $matches[1];
        }

        return '';
    }
}
