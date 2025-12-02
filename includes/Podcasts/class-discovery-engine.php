<?php
/**
 * Discovery Engine - Layer 1 Orchestrator
 *
 * Combines RSS parsing and homepage scraping to discover
 * podcast information and social media links.
 *
 * This runs immediately when a podcast is imported (< 3 seconds)
 * Cost: $0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Discovery_Engine {

    /**
     * Discover podcast from RSS URL (main entry point)
     *
     * @param string $rss_url RSS feed URL
     * @return array|WP_Error Result with podcast_id and social_links_found
     */
    public static function discover_from_rss($rss_url) {
        return self::discover($rss_url);
    }

    /**
     * Discover podcast data and social links
     *
     * @param string $rss_url RSS feed URL
     * @return array|WP_Error Result with podcast_id and social_links_found
     */
    public static function discover($rss_url) {
        $start_time = microtime(true);

        // Step 1: Parse RSS feed (< 1 second)
        $rss_data = PIT_RSS_Parser::parse($rss_url);

        if (is_wp_error($rss_data)) {
            return $rss_data;
        }

        // Step 2: Extract social links from RSS
        $social_links = isset($rss_data['social_links']) ? $rss_data['social_links'] : [];
        unset($rss_data['social_links']);

        // Step 3: Prepare podcast data for database
        $podcast_data = self::map_rss_to_podcast($rss_data, $rss_url);

        // Step 4: Insert/update podcast using Repository (handles deduplication)
        $podcast_id = PIT_Podcast_Repository::upsert($podcast_data);

        if (!$podcast_id) {
            return new WP_Error('db_insert_failed', 'Failed to save podcast to database');
        }

        // Step 5: Create contact from RSS owner info
        $contact_created = self::create_contact_from_rss($podcast_id, $rss_data);

        // Step 6: Scrape homepage for additional social links (2-3 seconds)
        $homepage_url = isset($rss_data['homepage_url']) ? $rss_data['homepage_url'] : '';
        if (!empty($homepage_url)) {
            $homepage_links = PIT_Homepage_Scraper::scrape($homepage_url);
            if (is_array($homepage_links)) {
                $social_links = array_merge($social_links, $homepage_links);
            }
        }

        // Step 7: Save social links to database
        $saved_links = 0;
        foreach ($social_links as $link) {
            $link_data = [
                'podcast_id'       => $podcast_id,
                'platform'         => $link['platform'],
                'profile_url'      => $link['url'] ?? $link['profile_url'] ?? '',
                'profile_handle'   => $link['handle'] ?? $link['profile_handle'] ?? '',
                'discovery_source' => $link['source'] ?? 'rss',
                'discovered_at'    => current_time('mysql'),
            ];

            if (PIT_Social_Link_Repository::create($link_data)) {
                $saved_links++;
            }
        }

        // Update podcast discovery flags
        PIT_Podcast_Repository::update($podcast_id, [
            'social_links_discovered' => 1,
            'homepage_scraped' => !empty($homepage_url) ? 1 : 0,
            'last_rss_check' => current_time('mysql'),
        ]);

        $duration = microtime(true) - $start_time;

        return [
            'success'           => true,
            'podcast_id'        => $podcast_id,
            'podcast_name'      => $podcast_data['title'],
            'social_links_found' => $saved_links,
            'contact_created'   => $contact_created,
            'homepage_url'      => $homepage_url,
            'duration_seconds'  => round($duration, 2),
        ];
    }

    /**
     * Create contact from RSS owner/author info
     *
     * @param int $podcast_id Podcast ID
     * @param array $rss_data Parsed RSS data
     * @return bool Whether a contact was created
     */
    private static function create_contact_from_rss($podcast_id, $rss_data) {
        // Check if Contact Repository exists
        if (!class_exists('PIT_Contact_Repository')) {
            return false;
        }

        // Extract author and email from RSS data
        $author = isset($rss_data['author']) ? trim($rss_data['author']) : '';
        $email = isset($rss_data['email']) ? trim($rss_data['email']) : '';

        // Also check for owner_name if author is empty
        if (empty($author) && isset($rss_data['owner_name'])) {
            $author = trim($rss_data['owner_name']);
        }

        // Need at least a name or email to create a contact
        if (empty($author) && empty($email)) {
            return false;
        }

        // Check if contact with this email already exists for this podcast
        if (!empty($email)) {
            $existing = self::find_existing_contact_by_email($podcast_id, $email);
            if ($existing) {
                // Contact already exists, no need to create
                return false;
            }
        }

        // Parse name into first/last
        $name_parts = self::parse_name($author);

        // Create the contact
        $contact_data = [
            'full_name'    => $author ?: 'Podcast Owner',
            'first_name'   => $name_parts['first_name'],
            'last_name'    => $name_parts['last_name'],
            'email'        => $email,
            'role'         => 'host',
            'source'       => 'rss_discovery',
            'is_public'    => 1,
            'visibility'   => 'public',
        ];

        $contact_id = PIT_Contact_Repository::create($contact_data);

        if (!$contact_id) {
            return false;
        }

        // Link contact to podcast as primary host
        $linked = PIT_Contact_Repository::link_to_podcast($contact_id, $podcast_id, 'host', true);

        return $linked ? true : false;
    }

    /**
     * Find existing contact by email for a podcast
     *
     * @param int $podcast_id Podcast ID
     * @param string $email Email address
     * @return bool Whether contact exists
     */
    private static function find_existing_contact_by_email($podcast_id, $email) {
        global $wpdb;
        
        $contacts_table = $wpdb->prefix . 'pit_podcast_contacts';
        $relationships_table = $wpdb->prefix . 'pit_podcast_contact_relationships';

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT c.id FROM $contacts_table c
             INNER JOIN $relationships_table r ON c.id = r.contact_id
             WHERE r.podcast_id = %d AND c.email = %s AND r.active = 1
             LIMIT 1",
            $podcast_id,
            $email
        ));

        return !empty($result);
    }

    /**
     * Parse full name into first and last name
     *
     * @param string $full_name Full name
     * @return array Array with 'first_name' and 'last_name'
     */
    private static function parse_name($full_name) {
        $parts = preg_split('/\s+/', trim($full_name));
        
        if (count($parts) === 0) {
            return ['first_name' => '', 'last_name' => ''];
        }
        
        if (count($parts) === 1) {
            return ['first_name' => $parts[0], 'last_name' => ''];
        }
        
        // First word is first name, rest is last name
        $first_name = array_shift($parts);
        $last_name = implode(' ', $parts);
        
        return [
            'first_name' => $first_name,
            'last_name'  => $last_name,
        ];
    }

    /**
     * Map RSS parser output to podcast database fields
     *
     * @param array $rss_data Data from RSS parser
     * @param string $rss_url Original RSS URL
     * @return array Podcast data ready for database
     */
    private static function map_rss_to_podcast($rss_data, $rss_url) {
        return [
            'title'           => $rss_data['podcast_name'] ?? $rss_data['title'] ?? 'Unknown Podcast',
            'description'     => $rss_data['description'] ?? '',
            'author'          => $rss_data['author'] ?? $rss_data['itunes_author'] ?? '',
            'email'           => $rss_data['email'] ?? $rss_data['owner_email'] ?? '',
            'rss_feed_url'    => $rss_url,
            'website_url'     => $rss_data['homepage_url'] ?? $rss_data['link'] ?? '',
            'artwork_url'     => $rss_data['artwork_url'] ?? $rss_data['image'] ?? '',
            'category'        => is_array($rss_data['categories'] ?? null) 
                                 ? implode(', ', $rss_data['categories']) 
                                 : ($rss_data['category'] ?? ''),
            'language'        => $rss_data['language'] ?? 'en',
            'episode_count'   => $rss_data['episode_count'] ?? 0,
            'itunes_id'       => $rss_data['itunes_id'] ?? null,
            'tracking_status' => 'not_tracked',
            'source'          => 'rss_discovery',
            // New metadata fields
            'explicit_rating'    => $rss_data['explicit'] ?? 'clean',
            'copyright'          => $rss_data['copyright'] ?? '',
            'founded_date'       => $rss_data['founded_date'] ?? null,
            'last_episode_date'  => $rss_data['last_episode_date'] ?? null,
            'frequency'          => $rss_data['frequency'] ?? 'Unknown',
            'average_duration'   => $rss_data['average_duration'] ?? null,
            'metadata_updated_at' => current_time('mysql'),
        ];
    }

    /**
     * Re-discover social links for an existing podcast
     *
     * @param int $podcast_id Podcast ID
     * @return array|WP_Error Result
     */
    public static function rediscover($podcast_id) {
        $podcast = PIT_Podcast_Repository::get($podcast_id);

        if (!$podcast) {
            return new WP_Error('podcast_not_found', 'Podcast not found');
        }

        // Re-parse RSS feed
        $rss_data = PIT_RSS_Parser::parse($podcast->rss_feed_url);

        if (is_wp_error($rss_data)) {
            return $rss_data;
        }

        // Try to create contact if none exists
        $contact_created = self::create_contact_from_rss($podcast_id, $rss_data);

        // Extract social links from RSS
        $social_links = isset($rss_data['social_links']) ? $rss_data['social_links'] : [];

        // Also scrape homepage again
        if (!empty($podcast->website_url)) {
            $homepage_links = PIT_Homepage_Scraper::scrape($podcast->website_url);
            if (is_array($homepage_links)) {
                $social_links = array_merge($social_links, $homepage_links);
            }
        }

        // Save social links
        $saved_links = 0;
        foreach ($social_links as $link) {
            $link_data = [
                'podcast_id'       => $podcast_id,
                'platform'         => $link['platform'],
                'profile_url'      => $link['url'] ?? $link['profile_url'] ?? '',
                'profile_handle'   => $link['handle'] ?? $link['profile_handle'] ?? '',
                'discovery_source' => $link['source'] ?? 'rss',
                'discovered_at'    => current_time('mysql'),
            ];

            if (PIT_Social_Link_Repository::create($link_data)) {
                $saved_links++;
            }
        }

        // Update discovery timestamp
        PIT_Podcast_Repository::update($podcast_id, [
            'last_rss_check' => current_time('mysql'),
        ]);

        return [
            'success'           => true,
            'podcast_id'        => $podcast_id,
            'social_links_found' => $saved_links,
            'contact_created'   => $contact_created,
        ];
    }

    /**
     * Discover contacts for existing podcasts that don't have any
     * 
     * This can be run as a one-time migration or scheduled task
     *
     * @param int $limit Max podcasts to process
     * @return array Results summary
     */
    public static function backfill_contacts($limit = 100) {
        global $wpdb;
        
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';
        $relationships_table = $wpdb->prefix . 'pit_podcast_contact_relationships';

        // Find podcasts without any contacts
        $podcasts = $wpdb->get_results($wpdb->prepare(
            "SELECT p.id, p.rss_feed_url, p.author, p.email, p.title
             FROM $podcasts_table p
             LEFT JOIN $relationships_table r ON p.id = r.podcast_id AND r.active = 1
             WHERE r.id IS NULL
             AND (p.author IS NOT NULL AND p.author != '' OR p.email IS NOT NULL AND p.email != '')
             LIMIT %d",
            $limit
        ));

        $results = [
            'processed' => 0,
            'contacts_created' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach ($podcasts as $podcast) {
            $results['processed']++;

            // Create RSS data array from podcast record
            $rss_data = [
                'author' => $podcast->author,
                'email'  => $podcast->email,
            ];

            $created = self::create_contact_from_rss($podcast->id, $rss_data);

            if ($created) {
                $results['contacts_created']++;
            } else {
                $results['skipped']++;
            }
        }

        return $results;
    }

    /**
     * Get discovery summary for a podcast
     *
     * @param int $podcast_id Podcast ID
     * @return array|null Summary data
     */
    public static function get_summary($podcast_id) {
        $podcast = PIT_Podcast_Repository::get($podcast_id);

        if (!$podcast) {
            return null;
        }

        $social_links = PIT_Social_Link_Repository::get_for_podcast($podcast_id);

        // Group by platform
        $platforms = [];
        foreach ($social_links as $link) {
            $platforms[$link->platform] = [
                'url'    => $link->profile_url,
                'handle' => $link->profile_handle,
                'source' => $link->discovery_source,
            ];
        }

        // Get contacts count
        $contacts_count = 0;
        if (class_exists('PIT_Contact_Repository')) {
            $contacts = PIT_Contact_Repository::get_for_podcast($podcast_id, null);
            $contacts_count = count($contacts);
        }

        return [
            'podcast_id'          => $podcast->id,
            'podcast_name'        => $podcast->title,
            'homepage_url'        => $podcast->website_url,
            'is_tracked'          => (bool) $podcast->is_tracked,
            'tracking_status'     => $podcast->tracking_status,
            'platforms_discovered' => count($platforms),
            'platforms'           => $platforms,
            'contacts_count'      => $contacts_count,
            'discovered_at'       => $podcast->created_at,
        ];
    }

    /**
     * Add social link manually
     *
     * @param int $podcast_id Podcast ID
     * @param string $platform Platform name
     * @param string $profile_url Profile URL
     * @return int|false Link ID or false
     */
    public static function add_manual_link($podcast_id, $platform, $profile_url) {
        $podcast = PIT_Podcast_Repository::get($podcast_id);

        if (!$podcast) {
            return false;
        }

        // Extract handle from URL
        $handle = PIT_Homepage_Scraper::extract_handle($profile_url, $platform);

        $data = [
            'podcast_id'       => $podcast_id,
            'platform'         => $platform,
            'profile_url'      => $profile_url,
            'profile_handle'   => $handle,
            'discovery_source' => 'manual',
            'is_verified'      => 1,
            'discovered_at'    => current_time('mysql'),
        ];

        return PIT_Social_Link_Repository::create($data);
    }

    /**
     * Remove social link
     *
     * @param int $link_id Social link ID
     * @return bool Success
     */
    public static function remove_link($link_id) {
        return PIT_Social_Link_Repository::delete($link_id);
    }

    /**
     * Batch discover multiple podcasts
     *
     * @param array $rss_urls Array of RSS URLs
     * @return array Results
     */
    public static function batch_discover($rss_urls) {
        $results = [
            'success' => [],
            'failed'  => [],
        ];

        foreach ($rss_urls as $rss_url) {
            $result = self::discover($rss_url);

            if (is_wp_error($result)) {
                $results['failed'][] = [
                    'url'   => $rss_url,
                    'error' => $result->get_error_message(),
                ];
            } else {
                $results['success'][] = $result;
            }

            // Small delay to be nice to servers
            usleep(500000); // 0.5 seconds
        }

        return $results;
    }

    /**
     * Get statistics about discovered platforms
     *
     * @return array Statistics
     */
    public static function get_statistics() {
        global $wpdb;
        $table_podcasts = $wpdb->prefix . 'pit_podcasts';
        $table_social = $wpdb->prefix . 'pit_social_links';
        $table_contacts = $wpdb->prefix . 'pit_podcast_contacts';
        $table_relationships = $wpdb->prefix . 'pit_podcast_contact_relationships';

        $stats = [
            'total_podcasts'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_podcasts"),
            'podcasts_with_links' => (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT podcast_id) FROM $table_social"
            ),
            'total_links'        => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_social"),
            'total_contacts'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_contacts"),
            'podcasts_with_contacts' => (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT podcast_id) FROM $table_relationships WHERE active = 1"
            ),
            'by_platform'        => [],
            'by_source'          => [],
        ];

        // Count by platform
        $platform_counts = $wpdb->get_results(
            "SELECT platform, COUNT(*) as count FROM $table_social GROUP BY platform"
        );

        foreach ($platform_counts as $row) {
            $stats['by_platform'][$row->platform] = (int) $row->count;
        }

        // Count by source
        $source_counts = $wpdb->get_results(
            "SELECT discovery_source, COUNT(*) as count FROM $table_social GROUP BY discovery_source"
        );

        foreach ($source_counts as $row) {
            $stats['by_source'][$row->discovery_source] = (int) $row->count;
        }

        return $stats;
    }
}
