<?php
/**
 * RSS Feed to Database Bridge
 *
 * Automatically seeds the Podcast Intelligence database from RSS feed data
 * when Interview Tracker entries are created or updated.
 *
 * Features:
 * - Auto-creates podcast record from RSS channel data
 * - Auto-creates "Owner" contact from itunes:owner name/email
 * - Links podcast to Formidable entry via bridge table
 * - Supports manual refresh/sync
 *
 * @package Podcast_Influence_Tracker
 * @subpackage Podcast_Intelligence
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_RSS_Bridge {

    /**
     * @var PIT_RSS_Bridge Singleton instance
     */
    private static $instance = null;

    /**
     * Field ID for RSS feed URL in Interview Tracker form
     * This should match the field used in Formidable Views: [9928]
     */
    const RSS_FIELD_ID = 9928;

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
     * Constructor - Register hooks
     */
    private function __construct() {
        // Hook into Formidable entry creation
        add_action('frm_after_create_entry', [$this, 'on_entry_created'], 20, 2);

        // Hook into Formidable entry update
        add_action('frm_after_update_entry', [$this, 'on_entry_updated'], 20, 2);

        // Register AJAX handlers for manual sync
        add_action('wp_ajax_pit_sync_podcast_from_rss', [$this, 'ajax_sync_podcast']);

        // Register shortcode for sync button
        add_shortcode('guestify_sync_button', [$this, 'sync_button_shortcode']);
    }

    /**
     * Handle new Formidable entry creation
     */
    public function on_entry_created($entry_id, $form_id) {
        $this->process_entry($entry_id, $form_id);
    }

    /**
     * Handle Formidable entry update
     */
    public function on_entry_updated($entry_id, $form_id) {
        $this->process_entry($entry_id, $form_id, true);
    }

    /**
     * Process an entry - create/update podcast and contact from RSS
     */
    private function process_entry($entry_id, $form_id, $is_update = false) {
        // Get the RSS feed URL from the entry
        $rss_url = $this->get_entry_rss_url($entry_id);

        if (empty($rss_url)) {
            return; // No RSS URL, nothing to do
        }

        // Check if podcast already exists for this entry
        $existing_podcast = PIT_Database::get_entry_podcast($entry_id);

        if ($existing_podcast && !$is_update) {
            // Podcast already linked, skip on create (but continue on update)
            return;
        }

        // Sync podcast data from RSS
        $result = $this->sync_podcast_from_rss($rss_url, $entry_id, $existing_podcast);

        if (is_wp_error($result)) {
            error_log('PIT RSS Bridge Error: ' . $result->get_error_message());
        }
    }

    /**
     * Get RSS feed URL from a Formidable entry
     */
    private function get_entry_rss_url($entry_id) {
        if (!function_exists('FrmEntryMeta') && !class_exists('FrmEntryMeta')) {
            // Try direct database query if Formidable classes not available
            global $wpdb;
            $url = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->prefix}frm_item_metas
                 WHERE item_id = %d AND field_id = %d",
                $entry_id,
                self::RSS_FIELD_ID
            ));
            return $url ? esc_url_raw(trim($url)) : '';
        }

        // Use Formidable API
        $url = FrmEntryMeta::get_entry_meta_by_field($entry_id, self::RSS_FIELD_ID);
        return $url ? esc_url_raw(trim($url)) : '';
    }

    /**
     * Sync podcast data from RSS feed to database
     *
     * @param string $rss_url The RSS feed URL
     * @param int $entry_id The Formidable entry ID
     * @param object|null $existing_podcast Existing podcast record if updating
     * @return int|WP_Error Podcast ID on success, WP_Error on failure
     */
    public function sync_podcast_from_rss($rss_url, $entry_id = null, $existing_podcast = null) {
        // Check if RSS plugin is available
        if (!class_exists('GPF_Core')) {
            return new WP_Error('no_rss_plugin', 'Guestify Podcast Feeds plugin is not active.');
        }

        // Get channel data from RSS
        $channel_data = GPF_Core::get_channel_data($rss_url);

        if (!empty($channel_data['error'])) {
            return new WP_Error('rss_error', $channel_data['error']);
        }

        // Prepare podcast data for database
        $podcast_data = [
            'title' => $channel_data['title'] ?? '',
            'description' => $channel_data['description'] ?? '',
            'rss_feed_url' => $rss_url,
            'website_url' => $channel_data['link'] ?? '',
            'language' => $channel_data['language'] ?? 'en',
            'category' => !empty($channel_data['itunes_categories']) ? implode(', ', $channel_data['itunes_categories']) : '',
            'artwork_url' => $channel_data['itunes_image_url'] ?: ($channel_data['image_url'] ?? ''),
            'source' => 'rss_import',
            'is_active' => 1,
        ];

        // Upsert podcast (will find existing by RSS URL or create new)
        $podcast_id = PIT_Database::upsert_guestify_podcast($podcast_data);

        if (!$podcast_id) {
            return new WP_Error('db_error', 'Failed to create/update podcast in database.');
        }

        // Link to Formidable entry if provided
        if ($entry_id) {
            $this->link_podcast_to_entry($podcast_id, $entry_id);
        }

        // Create owner contact from itunes:owner if available
        if (!empty($channel_data['itunes_owner_email']) || !empty($channel_data['itunes_owner_name'])) {
            $this->create_owner_contact($podcast_id, $channel_data);
        }

        // Also create author as a contact if different from owner
        if (!empty($channel_data['itunes_author']) &&
            $channel_data['itunes_author'] !== ($channel_data['itunes_owner_name'] ?? '')) {
            $this->create_author_contact($podcast_id, $channel_data);
        }

        return $podcast_id;
    }

    /**
     * Link podcast to Formidable entry via bridge table
     */
    private function link_podcast_to_entry($podcast_id, $entry_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_interview_tracker_podcasts';

        // Check if link already exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE formidable_entry_id = %d",
            $entry_id
        ));

        if ($existing) {
            // Update existing link
            $wpdb->update(
                $table,
                ['podcast_id' => $podcast_id, 'updated_at' => current_time('mysql')],
                ['formidable_entry_id' => $entry_id],
                ['%d', '%s'],
                ['%d']
            );
        } else {
            // Create new link
            $wpdb->insert(
                $table,
                [
                    'formidable_entry_id' => $entry_id,
                    'podcast_id' => $podcast_id,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ],
                ['%d', '%d', '%s', '%s']
            );
        }
    }

    /**
     * Create owner contact from RSS itunes:owner data
     */
    private function create_owner_contact($podcast_id, $channel_data) {
        $owner_name = $channel_data['itunes_owner_name'] ?? '';
        $owner_email = $channel_data['itunes_owner_email'] ?? '';

        if (empty($owner_name) && empty($owner_email)) {
            return null;
        }

        // Parse name into first/last
        $name_parts = $this->parse_name($owner_name);

        // Check if contact with this email already exists
        $existing_contact = null;
        if (!empty($owner_email)) {
            $existing_contact = PIT_Database::get_contact_by_email($owner_email);
        }

        $contact_data = [
            'full_name' => $owner_name ?: 'Podcast Owner',
            'first_name' => $name_parts['first'],
            'last_name' => $name_parts['last'],
            'email' => $owner_email,
            'source' => 'rss_import',
        ];

        if ($existing_contact) {
            // Use existing contact
            $contact_id = $existing_contact->id;
        } else {
            // Create new contact using upsert
            $contact_id = PIT_Database::upsert_contact($contact_data);
        }

        if ($contact_id) {
            // Link contact to podcast as owner
            $this->link_contact_to_podcast($contact_id, $podcast_id, 'owner', true);
        }

        return $contact_id;
    }

    /**
     * Create author contact from RSS itunes:author data
     */
    private function create_author_contact($podcast_id, $channel_data) {
        $author_name = $channel_data['itunes_author'] ?? '';

        if (empty($author_name)) {
            return null;
        }

        // Parse name into first/last
        $name_parts = $this->parse_name($author_name);

        // Check if this contact already exists by name (no email for author)
        global $wpdb;
        $contacts_table = $wpdb->prefix . 'guestify_contacts';
        $existing_contact = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $contacts_table WHERE full_name = %s LIMIT 1",
            $author_name
        ));

        if ($existing_contact) {
            $contact_id = $existing_contact->id;
        } else {
            // Create new contact using upsert
            $contact_data = [
                'full_name' => $author_name,
                'first_name' => $name_parts['first'],
                'last_name' => $name_parts['last'],
                'source' => 'rss_import',
            ];
            $contact_id = PIT_Database::upsert_contact($contact_data);
        }

        if ($contact_id) {
            // Link contact to podcast as host (author is usually the host)
            $this->link_contact_to_podcast($contact_id, $podcast_id, 'host', false);
        }

        return $contact_id;
    }

    /**
     * Link contact to podcast with role
     */
    private function link_contact_to_podcast($contact_id, $podcast_id, $role = 'owner', $is_primary = false) {
        // Check if relationship already exists
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_podcast_contacts';

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE podcast_id = %d AND contact_id = %d",
            $podcast_id,
            $contact_id
        ));

        if ($existing) {
            // Update if needed
            if ($existing->role !== $role || (bool)$existing->is_primary !== $is_primary) {
                $wpdb->update(
                    $table,
                    [
                        'role' => $role,
                        'is_primary' => $is_primary ? 1 : 0,
                        'updated_at' => current_time('mysql'),
                    ],
                    ['id' => $existing->id],
                    ['%s', '%d', '%s'],
                    ['%d']
                );
            }
        } else {
            // Create new relationship
            PIT_Database::create_podcast_contact_relationship([
                'podcast_id' => $podcast_id,
                'contact_id' => $contact_id,
                'role' => $role,
                'is_primary' => $is_primary ? 1 : 0,
                'active' => 1,
            ]);
        }
    }

    /**
     * Parse a full name into first and last name
     */
    private function parse_name($full_name) {
        $full_name = trim($full_name);

        if (empty($full_name)) {
            return ['first' => '', 'last' => ''];
        }

        $parts = preg_split('/\s+/', $full_name);

        if (count($parts) === 1) {
            return ['first' => $parts[0], 'last' => ''];
        }

        $last = array_pop($parts);
        $first = implode(' ', $parts);

        return ['first' => $first, 'last' => $last];
    }

    /**
     * AJAX handler for manual podcast sync
     */
    public function ajax_sync_podcast() {
        check_ajax_referer('pit_sync_podcast', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        $entry_id = isset($_POST['entry_id']) ? intval($_POST['entry_id']) : 0;
        $rss_url = isset($_POST['rss_url']) ? esc_url_raw($_POST['rss_url']) : '';

        if (empty($rss_url) && $entry_id) {
            $rss_url = $this->get_entry_rss_url($entry_id);
        }

        if (empty($rss_url)) {
            wp_send_json_error(['message' => 'No RSS URL provided.']);
        }

        $result = $this->sync_podcast_from_rss($rss_url, $entry_id);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => 'Podcast synced successfully.',
            'podcast_id' => $result,
        ]);
    }

    /**
     * Shortcode for sync button in views
     *
     * Usage: [guestify_sync_button entry_id="[id]"]
     */
    public function sync_button_shortcode($atts) {
        $atts = shortcode_atts([
            'entry_id' => '',
            'label' => 'Sync from RSS',
            'class' => 'button outline-button',
        ], $atts);

        if (empty($atts['entry_id'])) {
            return '';
        }

        $nonce = wp_create_nonce('pit_sync_podcast');

        ob_start();
        ?>
        <button type="button"
                class="<?php echo esc_attr($atts['class']); ?> guestify-sync-btn"
                data-entry-id="<?php echo esc_attr($atts['entry_id']); ?>"
                data-nonce="<?php echo esc_attr($nonce); ?>">
            <svg class="button-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/>
            </svg>
            <?php echo esc_html($atts['label']); ?>
        </button>
        <?php
        return ob_get_clean();
    }

    /**
     * Manual sync for a specific entry (callable from code)
     */
    public static function sync_entry($entry_id) {
        $instance = self::get_instance();
        $rss_url = $instance->get_entry_rss_url($entry_id);

        if (empty($rss_url)) {
            return new WP_Error('no_rss_url', 'No RSS URL found for this entry.');
        }

        return $instance->sync_podcast_from_rss($rss_url, $entry_id);
    }

    /**
     * Bulk sync all Interview Tracker entries
     */
    public static function bulk_sync_all() {
        global $wpdb;

        // Get all entries with RSS URLs
        $entries = $wpdb->get_results($wpdb->prepare(
            "SELECT item_id, meta_value as rss_url
             FROM {$wpdb->prefix}frm_item_metas
             WHERE field_id = %d AND meta_value != ''",
            self::RSS_FIELD_ID
        ));

        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        $instance = self::get_instance();

        foreach ($entries as $entry) {
            $result = $instance->sync_podcast_from_rss($entry->rss_url, $entry->item_id);

            if (is_wp_error($result)) {
                $results['failed']++;
                $results['errors'][] = "Entry {$entry->item_id}: " . $result->get_error_message();
            } else {
                $results['success']++;
            }
        }

        return $results;
    }
}
