<?php
/**
 * Formidable Podcast Bridge
 *
 * Handles integration between Formidable Forms (Interview Tracker) and Podcast Intelligence Database.
 * Automatically creates podcast and contact records when new entries are created.
 *
 * @package Podcast_Influence_Tracker
 * @subpackage Podcast_Intelligence
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Formidable_Podcast_Bridge {

    /**
     * @var PIT_Formidable_Podcast_Bridge Singleton instance
     */
    private static $instance = null;

    /**
     * @var int Interview Tracker form ID
     */
    private $tracker_form_id;

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
        $this->tracker_form_id = get_option('pit_tracker_form_id', 0);

        // Hook into Formidable Forms
        add_action('frm_after_create_entry', [$this, 'process_new_entry'], 30, 2);
        add_action('frm_after_update_entry', [$this, 'process_updated_entry'], 30, 2);
    }

    /**
     * Process new Formidable entry
     *
     * @param int $entry_id
     * @param int $form_id
     */
    public function process_new_entry($entry_id, $form_id) {
        // Only process Interview Tracker entries
        if ($this->tracker_form_id && $form_id != $this->tracker_form_id) {
            return;
        }

        $this->auto_populate_podcast($entry_id);
    }

    /**
     * Process updated Formidable entry
     *
     * @param int $entry_id
     * @param int $form_id
     */
    public function process_updated_entry($entry_id, $form_id) {
        if ($this->tracker_form_id && $form_id != $this->tracker_form_id) {
            return;
        }

        // Check if podcast data was updated
        $this->auto_populate_podcast($entry_id);
    }

    /**
     * Auto-populate podcast from entry data
     *
     * @param int $entry_id
     */
    public function auto_populate_podcast($entry_id) {
        // Get entry data - map to actual Formidable field keys
        $podcast_name = $this->get_field_value($entry_id, 'kb1pc');        // Field 8111 - Podcast
        $rss_feed = $this->get_field_value($entry_id, 'e69un');            // Field 9928 - Feed
        $website = $this->get_field_value($entry_id, 'dvolk');             // Field 9011 - Website
        $description = $this->get_field_value($entry_id, 'e4lmu');         // Field 8112 - Podcast Description
        $host_name = $this->get_field_value($entry_id, 'mbu0g');           // Field 8115 - Podcast Host
        $host_email = $this->get_field_value($entry_id, 'j44t3');          // Field 8277 - Email

        // Get external IDs
        $podcast_index_id = $this->get_field_value($entry_id, 'osjpb');    // Field 9930 - PodID
        $podcast_index_guid = $this->get_field_value($entry_id, 'aho2u');  // Field 9931 - PodGuid
        $itunes_id = $this->get_field_value($entry_id, 'sa8as');           // Field 9929 - iTunes ID

        // Taddy UUID (if you add this field later, use its field_key)
        // For now, we'll try common field keys
        $taddy_uuid = $this->get_field_value($entry_id, 'taddy_uuid');     // Recommended: add this field
        if (!$taddy_uuid) {
            $taddy_uuid = $this->get_field_value($entry_id, 'taddy_id');   // Alternative
        }

        if (empty($podcast_name)) {
            // No podcast name - can't create podcast record
            return;
        }

        $manager = PIT_Podcast_Intelligence_Manager::get_instance();

        // DEDUPLICATION: RSS URL is the PRIMARY unique identifier
        // because it's the same across ALL directories (Podcast Index, Taddy, Apple, etc.)
        // Podcast Index ID and Taddy UUID are DIFFERENT IDs from different systems
        // for the SAME podcast - they cannot be used to match each other.
        $existing_podcast = PIT_Database::get_podcast_by_external_id([
            'rss_feed_url' => $rss_feed,        // PRIMARY - same across all directories
            'itunes_id' => $itunes_id,          // SECONDARY - also universal
            'podcast_index_id' => $podcast_index_id,      // Directory-specific
            'podcast_index_guid' => $podcast_index_guid,  // Directory-specific
            'taddy_podcast_uuid' => $taddy_uuid,          // Directory-specific
        ]);

        if ($existing_podcast) {
            // Podcast already exists! Just link this entry to it
            $podcast_id = $existing_podcast->id;

            // Update podcast with any new data from this entry (if more complete)
            $update_data = ['id' => $podcast_id];
            $needs_update = false;

            // Update external IDs if they're missing in the database
            // This enables progressive enrichment from multiple sources
            if ($itunes_id && empty($existing_podcast->itunes_id)) {
                $update_data['itunes_id'] = $itunes_id;
                $needs_update = true;
            }
            if ($podcast_index_id && empty($existing_podcast->podcast_index_id)) {
                $update_data['podcast_index_id'] = $podcast_index_id;
                $needs_update = true;
            }
            if ($podcast_index_guid && empty($existing_podcast->podcast_index_guid)) {
                $update_data['podcast_index_guid'] = $podcast_index_guid;
                $needs_update = true;
            }
            if ($taddy_uuid && empty($existing_podcast->taddy_podcast_uuid)) {
                $update_data['taddy_podcast_uuid'] = $taddy_uuid;
                $needs_update = true;
            }
            if ($description && empty($existing_podcast->description)) {
                $update_data['description'] = $description;
                $needs_update = true;
            }
            if ($website && empty($existing_podcast->website_url)) {
                $update_data['website_url'] = $website;
                $needs_update = true;
            }

            if ($needs_update) {
                PIT_Database::upsert_guestify_podcast($update_data);
            }
        } else {
            // Create new podcast with all available data
            $podcast_data = [
                'title' => $podcast_name,
                'rss_feed_url' => $rss_feed ?: null,  // PRIMARY unique identifier
                'website_url' => $website ?: null,
                'description' => $description ?: null,
                'itunes_id' => $itunes_id ?: null,    // Universal ID (Apple Podcasts)
                'podcast_index_id' => $podcast_index_id ?: null,
                'podcast_index_guid' => $podcast_index_guid ?: null,
                'taddy_podcast_uuid' => $taddy_uuid ?: null,
                'source' => $this->determine_source($podcast_index_id, $taddy_uuid),
                'data_quality_score' => $this->calculate_quality_score([
                    'has_rss' => !empty($rss_feed),
                    'has_description' => !empty($description),
                    'has_external_id' => !empty($podcast_index_id) || !empty($taddy_uuid) || !empty($itunes_id),
                    'has_website' => !empty($website),
                ]),
            ];

            $podcast_id = $manager->create_or_find_podcast($podcast_data);
        }

        // Create or find contact if host info provided
        $contact_id = null;
        if (!empty($host_name) || !empty($host_email)) {
            $name_parts = $this->parse_name($host_name ?: 'Host');

            $contact_data = [
                'full_name' => $host_name ?: 'Host',
                'first_name' => $name_parts['first_name'],
                'last_name' => $name_parts['last_name'],
                'email' => $host_email ?: null,
                'role' => 'host',
                'enrichment_source' => 'formidable',
            ];

            $contact_id = $manager->create_or_find_contact($contact_data);

            // Link podcast and contact
            $manager->link_podcast_contact($podcast_id, $contact_id, 'host', true);
        }

        // Link entry to podcast (this allows multiple entries per podcast)
        $manager->link_entry_to_podcast($entry_id, $podcast_id, $contact_id);

        // Log the creation
        do_action('pit_podcast_auto_populated', $entry_id, $podcast_id, $contact_id);

        // Store the podcast_id back to Formidable for quick lookup
        // This is optional but helpful for performance
        $this->store_podcast_id_in_entry($entry_id, $podcast_id);
    }

    /**
     * Determine the source based on available IDs
     */
    private function determine_source($podcast_index_id, $taddy_uuid) {
        if (!empty($podcast_index_id)) {
            return 'podcast_index';
        }
        if (!empty($taddy_uuid)) {
            return 'taddy';
        }
        return 'formidable';
    }

    /**
     * Calculate data quality score
     */
    private function calculate_quality_score($criteria) {
        $score = 30; // Base score

        if ($criteria['has_rss']) $score += 20;
        if ($criteria['has_description']) $score += 10;
        if ($criteria['has_external_id']) $score += 30; // External IDs are very valuable
        if ($criteria['has_website']) $score += 10;

        return min($score, 100);
    }

    /**
     * Store podcast_id back in Formidable entry for quick lookup
     * This creates a direct reference without querying the bridge table
     */
    private function store_podcast_id_in_entry($entry_id, $podcast_id) {
        // Store in entry meta
        global $wpdb;
        $table = $wpdb->prefix . 'frm_item_metas';

        // Check if meta already exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_id FROM $table WHERE item_id = %d AND field_id = 0 AND meta_key = %s",
            $entry_id,
            '_guestify_podcast_id'
        ));

        if ($existing) {
            // Update existing
            $wpdb->update(
                $table,
                ['meta_value' => $podcast_id],
                ['meta_id' => $existing],
                ['%d'],
                ['%d']
            );
        } else {
            // Insert new
            $wpdb->insert(
                $table,
                [
                    'item_id' => $entry_id,
                    'field_id' => 0, // 0 = custom meta not tied to a field
                    'meta_key' => '_guestify_podcast_id',
                    'meta_value' => $podcast_id,
                ],
                ['%d', '%d', '%s', '%d']
            );
        }
    }

    /**
     * Get field value from entry
     *
     * @param int $entry_id
     * @param string $field_key
     * @return mixed
     */
    private function get_field_value($entry_id, $field_key) {
        if (!class_exists('FrmProEntriesController')) {
            return null;
        }

        $value = FrmProEntriesController::get_field_value_shortcode([
            'field_key' => $field_key,
            'entry' => $entry_id
        ]);

        return $value ?: null;
    }

    /**
     * Parse full name into first and last name
     *
     * @param string $full_name
     * @return array
     */
    private function parse_name($full_name) {
        $parts = explode(' ', trim($full_name), 2);

        return [
            'first_name' => $parts[0] ?? '',
            'last_name' => $parts[1] ?? '',
        ];
    }

    /**
     * Get podcast for entry (public method)
     *
     * @param int $entry_id
     * @return object|null
     */
    public function get_podcast_for_entry($entry_id) {
        return PIT_Database::get_entry_podcast($entry_id);
    }

    /**
     * Get contact for entry (public method)
     *
     * @param int $entry_id
     * @return object|null
     */
    public function get_contact_for_entry($entry_id) {
        return PIT_Database::get_entry_contact($entry_id);
    }

    /**
     * Get contact email for entry (for email integration)
     *
     * @param int $entry_id
     * @return string|null
     */
    public function get_contact_email_for_entry($entry_id) {
        $manager = PIT_Podcast_Intelligence_Manager::get_instance();
        return $manager->get_entry_contact_email($entry_id);
    }

    /**
     * Update outreach status
     *
     * @param int $entry_id
     * @param string $status
     */
    public function update_outreach_status($entry_id, $status) {
        $podcast = PIT_Database::get_entry_podcast($entry_id);
        if (!$podcast) {
            return;
        }

        PIT_Database::link_entry_to_podcast($entry_id, $podcast->id, [
            'outreach_status' => $status,
            'last_contact_date' => current_time('mysql'),
        ]);
    }

    /**
     * Mark first contact
     *
     * @param int $entry_id
     */
    public function mark_first_contact($entry_id) {
        $podcast = PIT_Database::get_entry_podcast($entry_id);
        if (!$podcast) {
            return;
        }

        PIT_Database::link_entry_to_podcast($entry_id, $podcast->id, [
            'first_contact_date' => current_time('mysql'),
            'last_contact_date' => current_time('mysql'),
            'outreach_status' => 'sent',
        ]);
    }
}
