<?php
/**
 * RSS Parser - Layer 1 Discovery
 *
 * Parses podcast RSS feeds to extract:
 * - Basic podcast information
 * - Homepage URL
 * - Social media links embedded in RSS
 * - Author/email information
 *
 * Cost: $0 (uses native PHP functionality)
 * Speed: < 1 second
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_RSS_Parser {

    /**
     * Parse RSS feed and extract podcast data
     *
     * @param string $rss_url RSS feed URL
     * @return array|WP_Error Parsed data or error
     */
    public static function parse($rss_url) {
        $start_time = microtime(true);

        // Fetch RSS feed
        $response = wp_remote_get($rss_url, [
            'timeout' => 15,
            'user-agent' => 'Podcast Influence Tracker/1.0',
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('rss_fetch_failed', 'Failed to fetch RSS feed: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);

        if (empty($body)) {
            return new WP_Error('rss_empty', 'RSS feed is empty');
        }

        // Disable XML errors
        libxml_use_internal_errors(true);

        // Parse XML
        $xml = simplexml_load_string($body);

        if ($xml === false) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            return new WP_Error('rss_parse_failed', 'Failed to parse RSS XML: ' . json_encode($errors));
        }

        // Determine feed type (RSS 2.0 or Atom)
        $namespaces = $xml->getNamespaces(true);
        $is_atom = isset($namespaces['']) && strpos($namespaces[''], 'atom') !== false;

        if ($is_atom) {
            return self::parse_atom_feed($xml, $rss_url);
        } else {
            return self::parse_rss_feed($xml, $rss_url);
        }
    }

    /**
     * Parse RSS 2.0 feed
     */
    private static function parse_rss_feed($xml, $rss_url) {
        $channel = $xml->channel;

        if (!$channel) {
            return new WP_Error('invalid_rss', 'Invalid RSS feed structure');
        }

        // Get iTunes namespace
        $itunes = $channel->children('http://www.itunes.com/dtds/podcast-1.0.dtd');

        $data = [
            'podcast_name' => (string) $channel->title,
            'rss_feed_url' => $rss_url,
            'description' => (string) ($itunes->summary ?? $channel->description),
            'author' => (string) ($itunes->author ?? $channel->author ?? ''),
            'email' => '',
            'homepage_url' => (string) $channel->link,
            'category' => '',
            'language' => (string) $channel->language,
            'artwork_url' => '',
            'social_links' => [],
        ];

        // Extract email from managingEditor or itunes:owner
        if (isset($channel->managingEditor)) {
            $data['email'] = self::extract_email((string) $channel->managingEditor);
        } elseif (isset($itunes->owner->email)) {
            $data['email'] = (string) $itunes->owner->email;
        }

        // Extract category
        if (isset($itunes->category)) {
            $data['category'] = (string) $itunes->category['text'];
        }

        // Extract artwork
        if (isset($itunes->image)) {
            $data['artwork_url'] = (string) $itunes->image['href'];
        } elseif (isset($channel->image->url)) {
            $data['artwork_url'] = (string) $channel->image->url;
        }

        // Look for social links in description
        $description_text = (string) $channel->description;
        $data['social_links'] = self::extract_social_links($description_text, 'rss');

        return $data;
    }

    /**
     * Parse Atom feed
     */
    private static function parse_atom_feed($xml, $rss_url) {
        $data = [
            'podcast_name' => (string) $xml->title,
            'rss_feed_url' => $rss_url,
            'description' => (string) $xml->subtitle,
            'author' => (string) $xml->author->name,
            'email' => (string) $xml->author->email,
            'homepage_url' => '',
            'category' => '',
            'language' => '',
            'artwork_url' => '',
            'social_links' => [],
        ];

        // Find alternate link
        foreach ($xml->link as $link) {
            if ((string) $link['rel'] === 'alternate') {
                $data['homepage_url'] = (string) $link['href'];
                break;
            }
        }

        // Look for social links in subtitle
        $subtitle_text = (string) $xml->subtitle;
        $data['social_links'] = self::extract_social_links($subtitle_text, 'rss');

        return $data;
    }

    /**
     * Extract email from string
     */
    private static function extract_email($text) {
        preg_match('/[\w\-\.]+@[\w\-\.]+\.\w+/', $text, $matches);
        return $matches[0] ?? '';
    }

    /**
     * Extract social media links from text
     *
     * @param string $text Text to search
     * @param string $source Discovery source (rss/homepage)
     * @return array Array of social links
     */
    public static function extract_social_links($text, $source = 'homepage') {
        $links = [];

        $patterns = [
            'twitter' => [
                '/(?:https?:\/\/)?(?:www\.)?(?:twitter\.com|x\.com)\/([a-zA-Z0-9_]+)/i',
            ],
            'instagram' => [
                '/(?:https?:\/\/)?(?:www\.)?instagram\.com\/([a-zA-Z0-9_.]+)/i',
            ],
            'facebook' => [
                '/(?:https?:\/\/)?(?:www\.)?facebook\.com\/([a-zA-Z0-9.]+)/i',
            ],
            'youtube' => [
                '/(?:https?:\/\/)?(?:www\.)?youtube\.com\/(?:c\/|channel\/|user\/|@)([a-zA-Z0-9_-]+)/i',
                '/(?:https?:\/\/)?(?:www\.)?youtube\.com\/@([a-zA-Z0-9_-]+)/i',
            ],
            'linkedin' => [
                '/(?:https?:\/\/)?(?:www\.)?linkedin\.com\/(?:company|in)\/([a-zA-Z0-9-]+)/i',
            ],
            'tiktok' => [
                '/(?:https?:\/\/)?(?:www\.)?tiktok\.com\/@([a-zA-Z0-9_.]+)/i',
            ],
            'spotify' => [
                '/(?:https?:\/\/)?(?:open\.)?spotify\.com\/show\/([a-zA-Z0-9]+)/i',
            ],
            'apple_podcasts' => [
                '/(?:https?:\/\/)?(?:podcasts\.)?apple\.com\/(?:[a-z]{2}\/)?podcast\/[^\/]+\/id(\d+)/i',
            ],
        ];

        foreach ($patterns as $platform => $platform_patterns) {
            foreach ($platform_patterns as $pattern) {
                if (preg_match_all($pattern, $text, $matches)) {
                    foreach ($matches[0] as $index => $url) {
                        // Normalize URL
                        $url = self::normalize_social_url($url, $platform);

                        $links[] = [
                            'platform' => $platform,
                            'profile_url' => $url,
                            'profile_handle' => $matches[1][$index] ?? '',
                            'discovery_source' => $source,
                        ];

                        // Only take first match per platform
                        break 2;
                    }
                }
            }
        }

        return $links;
    }

    /**
     * Normalize social media URL
     */
    private static function normalize_social_url($url, $platform) {
        // Add https if missing
        if (!preg_match('/^https?:\/\//', $url)) {
            $url = 'https://' . $url;
        }

        // Platform-specific normalization
        switch ($platform) {
            case 'twitter':
                // Normalize x.com to twitter.com for consistency
                $url = str_replace('x.com', 'twitter.com', $url);
                break;
            case 'youtube':
                // Ensure www
                $url = str_replace('youtube.com', 'www.youtube.com', $url);
                break;
        }

        // Remove trailing slash
        $url = rtrim($url, '/');

        return $url;
    }

    /**
     * Validate RSS feed URL
     */
    public static function is_valid_rss_url($url) {
        if (empty($url)) {
            return false;
        }

        // Basic URL validation
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        // Check if it's likely an RSS feed
        $valid_extensions = ['.rss', '.xml', '/feed', '/rss'];
        foreach ($valid_extensions as $ext) {
            if (strpos($url, $ext) !== false) {
                return true;
            }
        }

        // Try to fetch and verify it's actually XML
        $response = wp_remote_head($url, ['timeout' => 5]);
        if (is_wp_error($response)) {
            return false;
        }

        $content_type = wp_remote_retrieve_header($response, 'content-type');
        if (strpos($content_type, 'xml') !== false || strpos($content_type, 'rss') !== false) {
            return true;
        }

        return false;
    }
}
