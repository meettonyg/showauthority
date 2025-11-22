<?php
/**
 * Podcast Intelligence Manager
 *
 * Main orchestration class for the Podcast Intelligence Database system.
 * Handles high-level operations for managing podcasts, contacts, and their relationships.
 *
 * @package Podcast_Influence_Tracker
 * @subpackage Podcast_Intelligence
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Podcast_Intelligence_Manager {

    /**
     * @var PIT_Podcast_Intelligence_Manager Singleton instance
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Hook into Formidable Forms
        add_action('frm_after_create_entry', [$this, 'handle_new_entry'], 30, 2);
    }

    /**
     * Create or find podcast from various sources
     *
     * @param array $data Podcast data (title, rss_url, etc.)
     * @return int Podcast ID
     */
    public function create_or_find_podcast($data) {
        // Check if podcast already exists
        if (!empty($data['rss_feed_url'])) {
            $existing = PIT_Database::get_podcast_by_rss($data['rss_feed_url']);
            if ($existing) {
                return $existing->id;
            }
        }

        // Create new podcast
        $podcast_data = [
            'title' => $data['title'] ?? '',
            'rss_feed_url' => $data['rss_feed_url'] ?? null,
            'website_url' => $data['website_url'] ?? null,
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? null,
            'data_quality_score' => 30, // Base score for manual entry
        ];

        $podcast_id = PIT_Database::upsert_guestify_podcast($podcast_data);

        // If RSS feed provided, parse it immediately (Layer 1)
        if (!empty($data['rss_feed_url'])) {
            $this->parse_rss_feed($podcast_id);
        }

        // If website URL provided, scrape for social links (Layer 1)
        if (!empty($data['website_url'])) {
            $this->discover_social_links($podcast_id);
        }

        return $podcast_id;
    }

    /**
     * Create or find contact
     *
     * @param array $data Contact data
     * @return int Contact ID
     */
    public function create_or_find_contact($data) {
        // Check if contact exists by email
        if (!empty($data['email'])) {
            $existing = PIT_Database::get_contact_by_email($data['email']);
            if ($existing) {
                return $existing->id;
            }
        }

        // Create new contact
        $contact_data = [
            'full_name' => $data['full_name'] ?? '',
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'email' => $data['email'] ?? null,
            'role' => $data['role'] ?? 'host',
            'data_quality_score' => 20, // Base score
        ];

        return PIT_Database::upsert_contact($contact_data);
    }

    /**
     * Link podcast and contact
     *
     * @param int $podcast_id
     * @param int $contact_id
     * @param string $role
     * @param bool $is_primary
     */
    public function link_podcast_contact($podcast_id, $contact_id, $role = 'host', $is_primary = true) {
        $relationship_data = [
            'podcast_id' => $podcast_id,
            'contact_id' => $contact_id,
            'role' => $role,
            'is_primary' => $is_primary ? 1 : 0,
            'active' => 1,
        ];

        return PIT_Database::create_podcast_contact_relationship($relationship_data);
    }

    /**
     * Link Formidable entry to podcast
     *
     * @param int $entry_id Formidable entry ID
     * @param int $podcast_id Podcast ID
     * @param int $contact_id Primary contact ID
     * @return int Link ID
     */
    public function link_entry_to_podcast($entry_id, $podcast_id, $contact_id = null) {
        $link_data = [
            'outreach_status' => 'researching',
        ];

        if ($contact_id) {
            $link_data['primary_contact_id'] = $contact_id;
        }

        return PIT_Database::link_entry_to_podcast($entry_id, $podcast_id, $link_data);
    }

    /**
     * Parse RSS feed and extract basic info (Layer 1 - Free)
     *
     * @param int $podcast_id
     */
    public function parse_rss_feed($podcast_id) {
        $podcast = PIT_Database::get_guestify_podcast($podcast_id);
        if (!$podcast || !$podcast->rss_feed_url) {
            return false;
        }

        // Use existing RSS parser
        if (class_exists('PIT_RSS_Parser')) {
            $parser = new PIT_RSS_Parser();
            $rss_data = $parser->parse_feed($podcast->rss_feed_url);

            if ($rss_data) {
                // Update podcast with RSS data
                $update_data = [];

                if (!empty($rss_data['title']) && empty($podcast->title)) {
                    $update_data['title'] = $rss_data['title'];
                }

                if (!empty($rss_data['description'])) {
                    $update_data['description'] = $rss_data['description'];
                }

                if (!empty($rss_data['website_url'])) {
                    $update_data['website_url'] = $rss_data['website_url'];
                }

                if (!empty($rss_data['social_links'])) {
                    foreach ($rss_data['social_links'] as $platform => $url) {
                        PIT_Database::upsert_social_account([
                            'podcast_id' => $podcast_id,
                            'platform' => $platform,
                            'profile_url' => $url,
                            'discovery_method' => 'rss',
                        ]);
                    }

                    $update_data['social_links_discovered'] = 1;
                }

                if (!empty($update_data)) {
                    $update_data['last_rss_check'] = current_time('mysql');
                    $update_data['data_quality_score'] = 60; // Improved score with RSS data
                    PIT_Database::upsert_guestify_podcast(array_merge(['id' => $podcast_id], $update_data));
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Discover social links from homepage (Layer 1 - Free)
     *
     * @param int $podcast_id
     */
    public function discover_social_links($podcast_id) {
        $podcast = PIT_Database::get_guestify_podcast($podcast_id);
        if (!$podcast || !$podcast->website_url) {
            return false;
        }

        // Use existing homepage scraper
        if (class_exists('PIT_Homepage_Scraper')) {
            $scraper = new PIT_Homepage_Scraper();
            $social_links = $scraper->scrape_social_links($podcast->website_url);

            if (!empty($social_links)) {
                foreach ($social_links as $platform => $url) {
                    PIT_Database::upsert_social_account([
                        'podcast_id' => $podcast_id,
                        'platform' => $platform,
                        'profile_url' => $url,
                        'discovery_method' => 'homepage',
                    ]);
                }

                // Update podcast
                PIT_Database::upsert_guestify_podcast([
                    'id' => $podcast_id,
                    'homepage_scraped' => 1,
                    'social_links_discovered' => 1,
                    'data_quality_score' => 70, // Even better score
                ]);

                return true;
            }
        }

        return false;
    }

    /**
     * Handle new Formidable entry creation
     *
     * @param int $entry_id
     * @param int $form_id
     */
    public function handle_new_entry($entry_id, $form_id) {
        // Only handle Interview Tracker form (adjust form ID as needed)
        // This will be configured in settings
        $tracker_form_id = get_option('pit_tracker_form_id', 0);
        if ($form_id != $tracker_form_id) {
            return;
        }

        // Get entry data
        $entry = FrmEntry::getOne($entry_id);
        if (!$entry) {
            return;
        }

        // Extract podcast info from entry
        // This will depend on your Formidable form field structure
        $podcast_title = FrmProEntriesController::get_field_value_shortcode([
            'field_key' => 'podcast_name',
            'entry' => $entry_id
        ]);

        $rss_url = FrmProEntriesController::get_field_value_shortcode([
            'field_key' => 'rss_feed',
            'entry' => $entry_id
        ]);

        // Create or find podcast
        if ($podcast_title) {
            $podcast_data = [
                'title' => $podcast_title,
                'rss_feed_url' => $rss_url ?: null,
            ];

            $podcast_id = $this->create_or_find_podcast($podcast_data);

            // Link entry to podcast
            $this->link_entry_to_podcast($entry_id, $podcast_id);
        }
    }

    /**
     * Get complete podcast data for entry
     *
     * @param int $entry_id Formidable entry ID
     * @return array|null
     */
    public function get_entry_podcast_data($entry_id) {
        $podcast = PIT_Database::get_entry_podcast($entry_id);
        if (!$podcast) {
            return null;
        }

        $contacts = PIT_Database::get_podcast_contacts($podcast->id);
        $social_accounts = PIT_Database::get_podcast_social_accounts($podcast->id);
        $primary_contact = PIT_Database::get_primary_contact($podcast->id);

        return [
            'podcast' => $podcast,
            'contacts' => $contacts,
            'social_accounts' => $social_accounts,
            'primary_contact' => $primary_contact,
        ];
    }

    /**
     * Get contact email for entry (for email integration)
     *
     * @param int $entry_id Formidable entry ID
     * @return string|null
     */
    public function get_entry_contact_email($entry_id) {
        $contact = PIT_Database::get_entry_contact($entry_id);
        if ($contact && !empty($contact->email)) {
            return $contact->email;
        }

        // Fall back to primary contact
        $podcast = PIT_Database::get_entry_podcast($entry_id);
        if ($podcast) {
            $primary = PIT_Database::get_primary_contact($podcast->id);
            if ($primary && !empty($primary->email)) {
                return $primary->email;
            }
        }

        return null;
    }

    /**
     * Enrich contact with Clay
     *
     * @param int $contact_id
     * @param array $enrichment_data Data from Clay
     * @return bool
     */
    public function enrich_contact_with_clay($contact_id, $enrichment_data) {
        $update_data = [
            'clay_enriched' => 1,
            'clay_enriched_at' => current_time('mysql'),
            'enrichment_source' => 'clay',
            'data_quality_score' => 90, // High score for Clay-enriched data
        ];

        // Map Clay fields
        if (!empty($enrichment_data['email'])) {
            $update_data['email'] = $enrichment_data['email'];
        }

        if (!empty($enrichment_data['linkedin_url'])) {
            $update_data['linkedin_url'] = $enrichment_data['linkedin_url'];
        }

        if (!empty($enrichment_data['twitter_url'])) {
            $update_data['twitter_url'] = $enrichment_data['twitter_url'];
        }

        if (!empty($enrichment_data['company'])) {
            $update_data['company'] = $enrichment_data['company'];
        }

        if (!empty($enrichment_data['title'])) {
            $update_data['title'] = $enrichment_data['title'];
        }

        // Get existing contact and merge
        $contact = PIT_Database::get_contact($contact_id);
        if ($contact) {
            $update_data['id'] = $contact_id;
            return PIT_Database::upsert_contact($update_data);
        }

        return false;
    }
}
