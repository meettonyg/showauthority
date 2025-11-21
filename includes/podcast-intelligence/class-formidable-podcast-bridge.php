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
        // Get entry data
        $podcast_name = $this->get_field_value($entry_id, 'podcast_name');
        $rss_feed = $this->get_field_value($entry_id, 'rss_feed');
        $website = $this->get_field_value($entry_id, 'website');
        $host_name = $this->get_field_value($entry_id, 'host_name');
        $host_email = $this->get_field_value($entry_id, 'host_email');

        if (empty($podcast_name)) {
            return;
        }

        $manager = PIT_Podcast_Intelligence_Manager::get_instance();

        // Create or find podcast
        $podcast_data = [
            'title' => $podcast_name,
            'rss_feed_url' => $rss_feed ?: null,
            'website_url' => $website ?: null,
        ];

        $podcast_id = $manager->create_or_find_podcast($podcast_data);

        // Create or find contact if host info provided
        $contact_id = null;
        if (!empty($host_name)) {
            $name_parts = $this->parse_name($host_name);

            $contact_data = [
                'full_name' => $host_name,
                'first_name' => $name_parts['first_name'],
                'last_name' => $name_parts['last_name'],
                'email' => $host_email ?: null,
                'role' => 'host',
            ];

            $contact_id = $manager->create_or_find_contact($contact_data);

            // Link podcast and contact
            $manager->link_podcast_contact($podcast_id, $contact_id, 'host', true);
        }

        // Link entry to podcast
        $manager->link_entry_to_podcast($entry_id, $podcast_id, $contact_id);

        // Log the creation
        do_action('pit_podcast_auto_populated', $entry_id, $podcast_id, $contact_id);
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
