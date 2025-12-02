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
            'categories' => [],
            'language' => (string) $channel->language,
            'artwork_url' => '',
            'social_links' => [],
            // New metadata fields
            'copyright' => (string) ($channel->copyright ?? ''),
            'explicit' => 'clean',
            'episode_count' => 0,
            'founded_date' => null,
            'last_episode_date' => null,
        ];

        // Extract email from managingEditor or itunes:owner
        if (isset($channel->managingEditor)) {
            $data['email'] = self::extract_email((string) $channel->managingEditor);
        } elseif (isset($itunes->owner->email)) {
            $data['email'] = (string) $itunes->owner->email;
        }

        // Extract category (single for backwards compatibility)
        if (isset($itunes->category)) {
            $data['category'] = (string) $itunes->category['text'];
        }

        // Extract all categories
        if (isset($itunes->category)) {
            foreach ($itunes->category as $cat) {
                if (isset($cat['text'])) {
                    $data['categories'][] = (string) $cat['text'];
                    // Also get subcategories
                    if (isset($cat->category)) {
                        foreach ($cat->category as $subcat) {
                            if (isset($subcat['text'])) {
                                $data['categories'][] = (string) $subcat['text'];
                            }
                        }
                    }
                }
            }
        }

        // Extract explicit rating
        if (isset($itunes->explicit)) {
            $explicit_value = strtolower((string) $itunes->explicit);
            // Normalize values: 'yes', 'true', 'explicit' -> 'explicit'; 'no', 'false', 'clean' -> 'clean'
            if (in_array($explicit_value, ['yes', 'true', 'explicit', '1'])) {
                $data['explicit'] = 'explicit';
            } else {
                $data['explicit'] = 'clean';
            }
        }

        // Extract artwork
        if (isset($itunes->image)) {
            $data['artwork_url'] = (string) $itunes->image['href'];
        } elseif (isset($channel->image->url)) {
            $data['artwork_url'] = (string) $channel->image->url;
        }

        // Episode analysis (count and dates)
        $items = $channel->item;
        $count = count($items);
        $data['episode_count'] = $count;

        // Calculate frequency from episode dates
        $data['frequency'] = self::calculate_frequency($items);

        // Calculate average duration
        $avg_duration = self::calculate_average_duration($items);
        if ($avg_duration) {
            $data['average_duration'] = $avg_duration;
        }

        if ($count > 0) {
            // Last episode date (usually first item in feed - most recent)
            $first_item_date = isset($items[0]->pubDate) ? (string) $items[0]->pubDate : '';
            if ($first_item_date) {
                $timestamp = strtotime($first_item_date);
                if ($timestamp) {
                    $data['last_episode_date'] = date('Y-m-d', $timestamp);
                }
            }

            // Founded date (approximate via last/oldest item in current feed)
            // Note: RSS feeds are often paginated, so this is "oldest visible episode"
            $last_item = $items[$count - 1];
            $last_item_date = isset($last_item->pubDate) ? (string) $last_item->pubDate : '';
            if ($last_item_date) {
                $timestamp = strtotime($last_item_date);
                if ($timestamp) {
                    $data['founded_date'] = date('Y-m-d', $timestamp);
                }
            }
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
                        // Skip bogus/generic platform URLs
                        if (self::is_bogus_social_url($url, $platform)) {
                            continue;
                        }

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
     * Check if a social URL is a bogus/generic platform URL
     * 
     * These are URLs belonging to podcast hosting platforms, not the actual podcasts
     *
     * @param string $url The URL to check
     * @param string $platform The platform type
     * @return bool True if bogus, false if legitimate
     */
    private static function is_bogus_social_url($url, $platform) {
        $url_lower = strtolower($url);

        // YouTube bogus accounts (hosting platforms, not podcasts)
        $bogus_youtube = [
            'spotifyforcreators',
            'spotify',
            'applepodcasts',
            'apple',
            'anchor',
            'anchorfm',
            'buzzsprout',
            'libsyn',
            'podbean',
            'spreaker',
            'soundcloud',
            'stitcher',
            'iheartradio',
            'tunein',
            'castbox',
            'pocketcasts',
            'overcast',
            'googlepodcasts',
            'amazonmusic',
            'audible',
            'deezer',
            'pandora',
            'rss',
            'rssfeed',
        ];

        // Twitter bogus accounts
        $bogus_twitter = [
            'spotify',
            'spotifypodcasts',
            'applepodcasts',
            'anchor',
            'buzzsprout',
        ];

        // Facebook bogus accounts
        $bogus_facebook = [
            'spotify',
            'applepodcasts',
            'anchor',
            'buzzsprout',
        ];

        // Instagram bogus accounts
        $bogus_instagram = [
            'spotify',
            'applepodcasts',
            'anchor',
            'buzzsprout',
        ];

        switch ($platform) {
            case 'youtube':
                foreach ($bogus_youtube as $bogus) {
                    if (strpos($url_lower, '/' . $bogus) !== false ||
                        strpos($url_lower, '@' . $bogus) !== false) {
                        return true;
                    }
                }
                break;

            case 'twitter':
                foreach ($bogus_twitter as $bogus) {
                    if (preg_match('/twitter\.com\/' . $bogus . '\/?$/i', $url_lower) ||
                        preg_match('/x\.com\/' . $bogus . '\/?$/i', $url_lower)) {
                        return true;
                    }
                }
                break;

            case 'facebook':
                foreach ($bogus_facebook as $bogus) {
                    if (preg_match('/facebook\.com\/' . $bogus . '\/?$/i', $url_lower)) {
                        return true;
                    }
                }
                break;

            case 'instagram':
                foreach ($bogus_instagram as $bogus) {
                    if (preg_match('/instagram\.com\/' . $bogus . '\/?$/i', $url_lower)) {
                        return true;
                    }
                }
                break;
        }

        return false;
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
                // Ensure www (but don't duplicate if already present)
                if (strpos($url, 'www.youtube.com') === false) {
                    $url = str_replace('youtube.com', 'www.youtube.com', $url);
                }
                // Resolve /c/ and /user/ URLs to get actual channel ID
                $url = self::resolve_youtube_url($url);
                break;
        }

        // Fix any duplicate www
        $url = preg_replace('/www\.www\./', 'www.', $url);

        // Remove trailing slash
        $url = rtrim($url, '/');

        return $url;
    }

    /**
     * Resolve YouTube URL to canonical format
     * 
     * Fetches the YouTube page and extracts the actual channel ID or handle
     * This handles /c/CustomName and /user/Username URLs that may have different handles
     *
     * @param string $url YouTube URL
     * @return string Resolved URL (or original if resolution fails)
     */
    private static function resolve_youtube_url($url) {
        // Only resolve /c/ and /user/ URLs - @handles and /channel/ are already correct
        if (!preg_match('/youtube\.com\/(c|user)\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
            return $url;
        }

        // Fetch the YouTube page
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'headers' => [
                'Accept-Language' => 'en-US,en;q=0.9',
            ],
        ]);

        if (is_wp_error($response)) {
            return $url; // Return original on error
        }

        $html = wp_remote_retrieve_body($response);

        if (empty($html)) {
            return $url;
        }

        // Try to extract channel ID from page
        // Pattern 1: "channelId":"UCxxxxxxxx"
        if (preg_match('/"channelId"\s*:\s*"(UC[a-zA-Z0-9_-]+)"/', $html, $matches)) {
            return 'https://www.youtube.com/channel/' . $matches[1];
        }

        // Pattern 2: "externalId":"UCxxxxxxxx"
        if (preg_match('/"externalId"\s*:\s*"(UC[a-zA-Z0-9_-]+)"/', $html, $matches)) {
            return 'https://www.youtube.com/channel/' . $matches[1];
        }

        // Pattern 3: canonical link with @handle
        if (preg_match('/"canonicalBaseUrl"\s*:\s*"\/@([a-zA-Z0-9_-]+)"/', $html, $matches)) {
            return 'https://www.youtube.com/@' . $matches[1];
        }

        // Pattern 4: <link rel="canonical" href="..."> 
        if (preg_match('/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/', $html, $matches)) {
            $canonical = $matches[1];
            if (strpos($canonical, 'youtube.com') !== false) {
                return $canonical;
            }
        }

        // Pattern 5: browse_id in ytInitialData
        if (preg_match('/"browseId"\s*:\s*"(UC[a-zA-Z0-9_-]+)"/', $html, $matches)) {
            return 'https://www.youtube.com/channel/' . $matches[1];
        }

        // Could not resolve, return original
        return $url;
    }

    /**
     * Parse episodes from RSS feed
     *
     * Fetches and parses individual episodes from a podcast RSS feed.
     * Results are cached via WordPress transients for performance.
     *
     * @param string $rss_url RSS feed URL
     * @param int    $offset  Starting position (default 0)
     * @param int    $limit   Number of episodes to return (default 10)
     * @param bool   $refresh Force refresh, bypass cache (default false)
     * @return array|WP_Error Array with episodes and metadata, or error
     */
    public static function parse_episodes($rss_url, $offset = 0, $limit = 10, $refresh = false) {
        // Generate cache key
        $cache_key = 'pit_episodes_' . md5($rss_url);
        $cache_duration = 15 * MINUTE_IN_SECONDS;

        // Check cache first (unless refresh requested)
        if (!$refresh) {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                // Return paginated slice from cached data
                $all_episodes = $cached['episodes'];
                $total = count($all_episodes);
                $sliced = array_slice($all_episodes, $offset, $limit);

                return [
                    'success' => true,
                    'episodes' => $sliced,
                    'total_available' => $total,
                    'has_more' => ($offset + $limit) < $total,
                    'cached' => true,
                    'cache_expires' => $cached['expires'],
                ];
            }
        }

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
            return new WP_Error('rss_parse_failed', 'Failed to parse RSS XML');
        }

        // Get channel and items
        $channel = $xml->channel;
        if (!$channel) {
            return new WP_Error('invalid_rss', 'Invalid RSS feed structure - no channel found');
        }

        // Get iTunes namespace for episode-level data
        $itunes_ns = 'http://www.itunes.com/dtds/podcast-1.0.dtd';

        // Parse all episodes
        $all_episodes = [];
        foreach ($channel->item as $item) {
            $itunes = $item->children($itunes_ns);

            // Get title
            $title = (string) $item->title;

            // Get description (prefer iTunes summary, fall back to description)
            $description = '';
            if (!empty($itunes->summary)) {
                $description = (string) $itunes->summary;
            } elseif (!empty($item->description)) {
                $description = (string) $item->description;
            }
            // Strip HTML and normalize whitespace
            $description = strip_tags(str_replace(["\r", "\n"], ' ', $description));
            $description = preg_replace('/\s+/', ' ', trim($description));

            // Get publication date
            $pub_date = (string) $item->pubDate;
            $date_formatted = '';
            $date_iso = '';
            if ($pub_date) {
                $timestamp = strtotime($pub_date);
                if ($timestamp) {
                    $date_formatted = date('j F Y', $timestamp);
                    $date_iso = date('Y-m-d', $timestamp);
                }
            }

            // Get enclosure (audio file)
            $audio_url = '';
            $enclosure = $item->enclosure;
            if ($enclosure && isset($enclosure['url'])) {
                $audio_url = (string) $enclosure['url'];
            }

            // Get duration
            $duration_seconds = 0;
            $duration_display = '';
            if (!empty($itunes->duration)) {
                $duration_raw = (string) $itunes->duration;
                $duration_seconds = self::parse_duration($duration_raw);
                $duration_display = self::format_duration($duration_seconds);
            }

            // Get episode thumbnail (iTunes image)
            $thumbnail_url = '';
            if (!empty($itunes->image)) {
                $image_attrs = $itunes->image->attributes();
                if (isset($image_attrs['href'])) {
                    $thumbnail_url = (string) $image_attrs['href'];
                }
            }

            // Get episode number if available
            $episode_number = '';
            if (!empty($itunes->episode)) {
                $episode_number = (string) $itunes->episode;
            }

            // Get episode URL (link)
            $episode_url = (string) $item->link;

            // Get GUID
            $guid = (string) $item->guid;

            $all_episodes[] = [
                'title' => $title,
                'description' => $description,
                'date' => $date_formatted,
                'date_iso' => $date_iso,
                'audio_url' => $audio_url,
                'duration_seconds' => $duration_seconds,
                'duration_display' => $duration_display,
                'thumbnail_url' => $thumbnail_url,
                'episode_number' => $episode_number,
                'episode_url' => $episode_url,
                'guid' => $guid,
            ];
        }

        // Cache the full result
        $cache_expires = gmdate('c', time() + $cache_duration);
        set_transient($cache_key, [
            'episodes' => $all_episodes,
            'expires' => $cache_expires,
        ], $cache_duration);

        // Return paginated slice
        $total = count($all_episodes);
        $sliced = array_slice($all_episodes, $offset, $limit);

        return [
            'success' => true,
            'episodes' => $sliced,
            'total_available' => $total,
            'has_more' => ($offset + $limit) < $total,
            'cached' => false,
            'cache_expires' => $cache_expires,
        ];
    }

    /**
     * Parse duration string to seconds
     *
     * Handles formats: "HH:MM:SS", "MM:SS", or raw seconds
     *
     * @param string $duration Duration string
     * @return int Duration in seconds
     */
    private static function parse_duration($duration) {
        // If already numeric, return as-is
        if (is_numeric($duration)) {
            return (int) $duration;
        }

        // Parse HH:MM:SS or MM:SS format
        $parts = explode(':', $duration);
        $count = count($parts);

        if ($count === 3) {
            // HH:MM:SS
            return ((int) $parts[0] * 3600) + ((int) $parts[1] * 60) + (int) $parts[2];
        } elseif ($count === 2) {
            // MM:SS
            return ((int) $parts[0] * 60) + (int) $parts[1];
        }

        return 0;
    }

    /**
     * Format duration in seconds to display string
     *
     * @param int $seconds Duration in seconds
     * @return string Formatted duration (e.g., "45 min", "1 hr 23 min")
     */
    private static function format_duration($seconds) {
        if ($seconds <= 0) {
            return '';
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        if ($hours > 0) {
            return $hours . ' hr ' . $minutes . ' min';
        }

        return $minutes . ' min';
    }

    /**
     * Calculate publishing frequency based on recent episodes
     *
     * Analyzes the gaps between recent episode publication dates
     * to determine the podcast's publishing schedule.
     *
     * @param SimpleXMLElement|array $items XML items/entries from RSS feed
     * @return string Frequency label (Daily, Weekly, Bi-Weekly, etc.)
     */
    private static function calculate_frequency($items) {
        // Need at least 3 episodes to establish a pattern
        if (empty($items) || count($items) < 3) {
            return 'Unknown';
        }

        // Extract dates from the last 5-10 episodes for better accuracy
        $dates = [];
        $max_check = min(count($items), 10);

        for ($i = 0; $i < $max_check; $i++) {
            $item = $items[$i];
            // Handle both RSS (pubDate) and Atom (published)
            $date_str = isset($item->pubDate) ? (string) $item->pubDate : (string) ($item->published ?? '');
            $timestamp = strtotime($date_str);
            if ($timestamp) {
                $dates[] = $timestamp;
            }
        }

        // Need at least 3 valid dates
        if (count($dates) < 3) {
            return 'Unknown';
        }

        // Calculate intervals between consecutive episodes (in days)
        $intervals = [];
        for ($i = 0; $i < count($dates) - 1; $i++) {
            // Dates are newest first, so dates[$i] > dates[$i+1]
            $diff_days = ($dates[$i] - $dates[$i + 1]) / 86400; // 86400 seconds per day
            if ($diff_days > 0) {
                $intervals[] = $diff_days;
            }
        }

        if (empty($intervals)) {
            return 'Irregular';
        }

        // Calculate average days between episodes
        $avg_days = array_sum($intervals) / count($intervals);

        // Map average interval to human-readable frequency
        if ($avg_days <= 1.5) {
            return 'Daily';
        } elseif ($avg_days <= 4.5) {
            return 'Twice Weekly';
        } elseif ($avg_days <= 9.0) {
            return 'Weekly';
        } elseif ($avg_days <= 16.0) {
            return 'Bi-Weekly';
        } elseif ($avg_days <= 35.0) {
            return 'Monthly';
        } elseif ($avg_days <= 95.0) {
            return 'Quarterly';
        }

        return 'Irregular';
    }

    /**
     * Calculate average episode duration from recent episodes
     *
     * @param SimpleXMLElement|array $items XML items/entries from RSS feed
     * @return int|null Average duration in seconds, or null if unavailable
     */
    private static function calculate_average_duration($items) {
        if (empty($items)) {
            return null;
        }

        $itunes_ns = 'http://www.itunes.com/dtds/podcast-1.0.dtd';
        $durations = [];
        $max_check = min(count($items), 10);

        for ($i = 0; $i < $max_check; $i++) {
            $item = $items[$i];
            $itunes = $item->children($itunes_ns);

            if (!empty($itunes->duration)) {
                $duration_raw = (string) $itunes->duration;
                $seconds = self::parse_duration($duration_raw);
                if ($seconds > 0) {
                    $durations[] = $seconds;
                }
            }
        }

        if (empty($durations)) {
            return null;
        }

        return (int) round(array_sum($durations) / count($durations));
    }

    /**
     * Clear episode cache for a specific RSS URL
     *
     * @param string $rss_url RSS feed URL
     * @return bool True if cache was cleared
     */
    public static function clear_episode_cache($rss_url) {
        $cache_key = 'pit_episodes_' . md5($rss_url);
        return delete_transient($cache_key);
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
