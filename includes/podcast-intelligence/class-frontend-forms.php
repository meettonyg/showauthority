<?php
/**
 * Frontend Forms for Podcast Intelligence
 *
 * Provides shortcodes with forms to add entries to podcast intelligence tables.
 * Each form includes proper validation, nonce security, and AJAX submission.
 *
 * Shortcodes:
 * - [guestify_add_podcast] - Add a new podcast
 * - [guestify_add_contact] - Add a new contact
 * - [guestify_add_social_account] - Add social account to a podcast
 * - [guestify_link_contact] - Link a contact to a podcast
 * - [guestify_link_entry] - Link a Formidable entry to a podcast
 *
 * @package Podcast_Influence_Tracker
 * @subpackage Podcast_Intelligence
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Frontend_Forms {

    /**
     * @var PIT_Frontend_Forms Singleton instance
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
        // Register shortcodes
        add_shortcode('guestify_add_podcast', [$this, 'render_add_podcast_form']);
        add_shortcode('guestify_add_contact', [$this, 'render_add_contact_form']);
        add_shortcode('guestify_add_social_account', [$this, 'render_add_social_account_form']);
        add_shortcode('guestify_link_contact', [$this, 'render_link_contact_form']);
        add_shortcode('guestify_link_entry', [$this, 'render_link_entry_form']);

        // Register AJAX handlers
        add_action('wp_ajax_pit_add_podcast', [$this, 'ajax_add_podcast']);
        add_action('wp_ajax_pit_add_contact', [$this, 'ajax_add_contact']);
        add_action('wp_ajax_pit_add_social_account', [$this, 'ajax_add_social_account']);
        add_action('wp_ajax_pit_link_contact', [$this, 'ajax_link_contact']);
        add_action('wp_ajax_pit_link_entry', [$this, 'ajax_link_entry']);

        // Enqueue scripts
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_scripts() {
        wp_enqueue_script(
            'pit-frontend-forms',
            PIT_PLUGIN_URL . 'assets/js/frontend-forms.js',
            ['jquery'],
            PIT_VERSION,
            true
        );

        wp_localize_script('pit-frontend-forms', 'pitForms', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('pit_frontend_forms'),
            'messages' => [
                'success' => __('Successfully saved!', 'podcast-influence-tracker'),
                'error' => __('An error occurred. Please try again.', 'podcast-influence-tracker'),
                'required' => __('Please fill in all required fields.', 'podcast-influence-tracker'),
            ],
        ]);

        wp_enqueue_style(
            'pit-frontend-forms',
            PIT_PLUGIN_URL . 'assets/css/frontend-forms.css',
            [],
            PIT_VERSION
        );
    }

    // ==================== FORM RENDERERS ====================

    /**
     * [guestify_add_podcast]
     * Render form to add a new podcast
     */
    public function render_add_podcast_form($atts) {
        $atts = shortcode_atts([
            'class' => 'guestify-form',
            'redirect' => '',
            'show_external_ids' => 'true',
            'button_text' => 'Add Podcast',
        ], $atts);

        ob_start();
        ?>
        <form class="<?php echo esc_attr($atts['class']); ?> guestify-add-podcast-form" data-action="pit_add_podcast">
            <?php wp_nonce_field('pit_frontend_forms', 'pit_nonce'); ?>
            <input type="hidden" name="redirect" value="<?php echo esc_url($atts['redirect']); ?>">

            <div class="guestify-form-group required">
                <label for="podcast_title"><?php _e('Podcast Name', 'podcast-influence-tracker'); ?> *</label>
                <input type="text" name="title" id="podcast_title" required>
            </div>

            <div class="guestify-form-group required">
                <label for="podcast_rss"><?php _e('RSS Feed URL', 'podcast-influence-tracker'); ?> *</label>
                <input type="url" name="rss_feed_url" id="podcast_rss" required placeholder="https://example.com/feed.xml">
                <small class="field-note"><?php _e('Primary unique identifier - must be unique', 'podcast-influence-tracker'); ?></small>
            </div>

            <div class="guestify-form-group">
                <label for="podcast_website"><?php _e('Website URL', 'podcast-influence-tracker'); ?></label>
                <input type="url" name="website_url" id="podcast_website" placeholder="https://example.com">
            </div>

            <div class="guestify-form-group">
                <label for="podcast_description"><?php _e('Description', 'podcast-influence-tracker'); ?></label>
                <textarea name="description" id="podcast_description" rows="3"></textarea>
            </div>

            <div class="guestify-form-group">
                <label for="podcast_category"><?php _e('Category', 'podcast-influence-tracker'); ?></label>
                <input type="text" name="category" id="podcast_category" placeholder="Business, Technology, etc.">
            </div>

            <?php if ($atts['show_external_ids'] === 'true'): ?>
            <fieldset class="guestify-fieldset">
                <legend><?php _e('External IDs (Optional)', 'podcast-influence-tracker'); ?></legend>

                <div class="guestify-form-group">
                    <label for="podcast_itunes_id"><?php _e('iTunes/Apple Podcasts ID', 'podcast-influence-tracker'); ?></label>
                    <input type="text" name="itunes_id" id="podcast_itunes_id" placeholder="1234567890">
                </div>

                <div class="guestify-form-group">
                    <label for="podcast_index_id"><?php _e('Podcast Index ID', 'podcast-influence-tracker'); ?></label>
                    <input type="number" name="podcast_index_id" id="podcast_index_id">
                </div>

                <div class="guestify-form-group">
                    <label for="podcast_index_guid"><?php _e('Podcast Index GUID', 'podcast-influence-tracker'); ?></label>
                    <input type="text" name="podcast_index_guid" id="podcast_index_guid">
                </div>

                <div class="guestify-form-group">
                    <label for="taddy_uuid"><?php _e('Taddy UUID', 'podcast-influence-tracker'); ?></label>
                    <input type="text" name="taddy_podcast_uuid" id="taddy_uuid">
                </div>
            </fieldset>
            <?php endif; ?>

            <div class="guestify-form-actions">
                <button type="submit" class="guestify-submit-btn"><?php echo esc_html($atts['button_text']); ?></button>
                <span class="guestify-form-message"></span>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    /**
     * [guestify_add_contact]
     * Render form to add a new contact
     */
    public function render_add_contact_form($atts) {
        $atts = shortcode_atts([
            'class' => 'guestify-form',
            'redirect' => '',
            'podcast_id' => '',  // If provided, auto-link to this podcast
            'entry_id' => '',    // If provided, get podcast from entry
            'button_text' => 'Add Contact',
        ], $atts);

        // Get podcast_id from entry if provided
        $podcast_id = $atts['podcast_id'];
        if (empty($podcast_id) && !empty($atts['entry_id'])) {
            $podcast = PIT_Database::get_entry_podcast($atts['entry_id']);
            if ($podcast) {
                $podcast_id = $podcast->id;
            }
        }

        ob_start();
        ?>
        <form class="<?php echo esc_attr($atts['class']); ?> guestify-add-contact-form" data-action="pit_add_contact">
            <?php wp_nonce_field('pit_frontend_forms', 'pit_nonce'); ?>
            <input type="hidden" name="redirect" value="<?php echo esc_url($atts['redirect']); ?>">
            <input type="hidden" name="podcast_id" value="<?php echo esc_attr($podcast_id); ?>">

            <div class="guestify-form-group required">
                <label for="contact_name"><?php _e('Full Name', 'podcast-influence-tracker'); ?> *</label>
                <input type="text" name="full_name" id="contact_name" required>
            </div>

            <div class="guestify-form-row">
                <div class="guestify-form-group">
                    <label for="contact_first_name"><?php _e('First Name', 'podcast-influence-tracker'); ?></label>
                    <input type="text" name="first_name" id="contact_first_name">
                </div>

                <div class="guestify-form-group">
                    <label for="contact_last_name"><?php _e('Last Name', 'podcast-influence-tracker'); ?></label>
                    <input type="text" name="last_name" id="contact_last_name">
                </div>
            </div>

            <div class="guestify-form-group">
                <label for="contact_email"><?php _e('Email', 'podcast-influence-tracker'); ?></label>
                <input type="email" name="email" id="contact_email">
            </div>

            <div class="guestify-form-group">
                <label for="contact_phone"><?php _e('Phone', 'podcast-influence-tracker'); ?></label>
                <input type="tel" name="phone" id="contact_phone">
            </div>

            <div class="guestify-form-group">
                <label for="contact_role"><?php _e('Role', 'podcast-influence-tracker'); ?></label>
                <select name="role" id="contact_role">
                    <option value=""><?php _e('Select role...', 'podcast-influence-tracker'); ?></option>
                    <option value="host"><?php _e('Host', 'podcast-influence-tracker'); ?></option>
                    <option value="producer"><?php _e('Producer', 'podcast-influence-tracker'); ?></option>
                    <option value="guest"><?php _e('Guest', 'podcast-influence-tracker'); ?></option>
                    <option value="owner"><?php _e('Owner', 'podcast-influence-tracker'); ?></option>
                    <option value="booking"><?php _e('Booking Manager', 'podcast-influence-tracker'); ?></option>
                    <option value="other"><?php _e('Other', 'podcast-influence-tracker'); ?></option>
                </select>
            </div>

            <div class="guestify-form-row">
                <div class="guestify-form-group">
                    <label for="contact_company"><?php _e('Company', 'podcast-influence-tracker'); ?></label>
                    <input type="text" name="company" id="contact_company">
                </div>

                <div class="guestify-form-group">
                    <label for="contact_title"><?php _e('Job Title', 'podcast-influence-tracker'); ?></label>
                    <input type="text" name="title" id="contact_title">
                </div>
            </div>

            <fieldset class="guestify-fieldset">
                <legend><?php _e('Social Profiles', 'podcast-influence-tracker'); ?></legend>

                <div class="guestify-form-group">
                    <label for="contact_linkedin"><?php _e('LinkedIn URL', 'podcast-influence-tracker'); ?></label>
                    <input type="url" name="linkedin_url" id="contact_linkedin" placeholder="https://linkedin.com/in/username">
                </div>

                <div class="guestify-form-group">
                    <label for="contact_twitter"><?php _e('Twitter/X URL', 'podcast-influence-tracker'); ?></label>
                    <input type="url" name="twitter_url" id="contact_twitter" placeholder="https://twitter.com/username">
                </div>

                <div class="guestify-form-group">
                    <label for="contact_website"><?php _e('Personal Website', 'podcast-influence-tracker'); ?></label>
                    <input type="url" name="website_url" id="contact_website">
                </div>
            </fieldset>

            <?php if (!empty($podcast_id)): ?>
            <div class="guestify-form-group">
                <label>
                    <input type="checkbox" name="is_primary" value="1">
                    <?php _e('Set as primary contact for this podcast', 'podcast-influence-tracker'); ?>
                </label>
            </div>
            <?php endif; ?>

            <div class="guestify-form-actions">
                <button type="submit" class="guestify-submit-btn"><?php echo esc_html($atts['button_text']); ?></button>
                <span class="guestify-form-message"></span>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    /**
     * [guestify_add_social_account]
     * Render form to add a social account to a podcast
     */
    public function render_add_social_account_form($atts) {
        $atts = shortcode_atts([
            'class' => 'guestify-form',
            'redirect' => '',
            'podcast_id' => '',
            'entry_id' => '',
            'button_text' => 'Add Social Account',
        ], $atts);

        // Get podcast_id from entry if provided
        $podcast_id = $atts['podcast_id'];
        if (empty($podcast_id) && !empty($atts['entry_id'])) {
            $podcast = PIT_Database::get_entry_podcast($atts['entry_id']);
            if ($podcast) {
                $podcast_id = $podcast->id;
            }
        }

        ob_start();
        ?>
        <form class="<?php echo esc_attr($atts['class']); ?> guestify-add-social-form" data-action="pit_add_social_account">
            <?php wp_nonce_field('pit_frontend_forms', 'pit_nonce'); ?>
            <input type="hidden" name="redirect" value="<?php echo esc_url($atts['redirect']); ?>">

            <?php if (empty($podcast_id)): ?>
            <div class="guestify-form-group required">
                <label for="social_podcast_id"><?php _e('Podcast ID', 'podcast-influence-tracker'); ?> *</label>
                <input type="number" name="podcast_id" id="social_podcast_id" required>
            </div>
            <?php else: ?>
            <input type="hidden" name="podcast_id" value="<?php echo esc_attr($podcast_id); ?>">
            <?php endif; ?>

            <div class="guestify-form-group required">
                <label for="social_platform"><?php _e('Platform', 'podcast-influence-tracker'); ?> *</label>
                <select name="platform" id="social_platform" required>
                    <option value=""><?php _e('Select platform...', 'podcast-influence-tracker'); ?></option>
                    <option value="twitter">Twitter/X</option>
                    <option value="instagram">Instagram</option>
                    <option value="facebook">Facebook</option>
                    <option value="youtube">YouTube</option>
                    <option value="linkedin">LinkedIn</option>
                    <option value="tiktok">TikTok</option>
                    <option value="spotify">Spotify</option>
                    <option value="apple_podcasts">Apple Podcasts</option>
                </select>
            </div>

            <div class="guestify-form-group required">
                <label for="social_url"><?php _e('Profile URL', 'podcast-influence-tracker'); ?> *</label>
                <input type="url" name="profile_url" id="social_url" required placeholder="https://...">
            </div>

            <div class="guestify-form-group">
                <label for="social_username"><?php _e('Username/Handle', 'podcast-influence-tracker'); ?></label>
                <input type="text" name="username" id="social_username" placeholder="@username">
            </div>

            <div class="guestify-form-group">
                <label for="social_display_name"><?php _e('Display Name', 'podcast-influence-tracker'); ?></label>
                <input type="text" name="display_name" id="social_display_name">
            </div>

            <div class="guestify-form-actions">
                <button type="submit" class="guestify-submit-btn"><?php echo esc_html($atts['button_text']); ?></button>
                <span class="guestify-form-message"></span>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    /**
     * [guestify_link_contact]
     * Render form to link an existing contact to a podcast
     */
    public function render_link_contact_form($atts) {
        $atts = shortcode_atts([
            'class' => 'guestify-form',
            'redirect' => '',
            'podcast_id' => '',
            'entry_id' => '',
            'button_text' => 'Link Contact',
        ], $atts);

        // Get podcast_id from entry if provided
        $podcast_id = $atts['podcast_id'];
        if (empty($podcast_id) && !empty($atts['entry_id'])) {
            $podcast = PIT_Database::get_entry_podcast($atts['entry_id']);
            if ($podcast) {
                $podcast_id = $podcast->id;
            }
        }

        // Get available contacts for dropdown
        global $wpdb;
        $contacts = $wpdb->get_results(
            "SELECT id, full_name, email, role FROM {$wpdb->prefix}guestify_podcast_contacts ORDER BY full_name"
        );

        ob_start();
        ?>
        <form class="<?php echo esc_attr($atts['class']); ?> guestify-link-contact-form" data-action="pit_link_contact">
            <?php wp_nonce_field('pit_frontend_forms', 'pit_nonce'); ?>
            <input type="hidden" name="redirect" value="<?php echo esc_url($atts['redirect']); ?>">

            <?php if (empty($podcast_id)): ?>
            <div class="guestify-form-group required">
                <label for="link_podcast_id"><?php _e('Podcast ID', 'podcast-influence-tracker'); ?> *</label>
                <input type="number" name="podcast_id" id="link_podcast_id" required>
            </div>
            <?php else: ?>
            <input type="hidden" name="podcast_id" value="<?php echo esc_attr($podcast_id); ?>">
            <?php endif; ?>

            <div class="guestify-form-group required">
                <label for="link_contact_id"><?php _e('Contact', 'podcast-influence-tracker'); ?> *</label>
                <select name="contact_id" id="link_contact_id" required>
                    <option value=""><?php _e('Select contact...', 'podcast-influence-tracker'); ?></option>
                    <?php foreach ($contacts as $contact): ?>
                        <option value="<?php echo esc_attr($contact->id); ?>">
                            <?php echo esc_html($contact->full_name); ?>
                            <?php if ($contact->email): ?>
                                (<?php echo esc_html($contact->email); ?>)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="guestify-form-group required">
                <label for="link_role"><?php _e('Role', 'podcast-influence-tracker'); ?> *</label>
                <select name="role" id="link_role" required>
                    <option value="host"><?php _e('Host', 'podcast-influence-tracker'); ?></option>
                    <option value="producer"><?php _e('Producer', 'podcast-influence-tracker'); ?></option>
                    <option value="guest"><?php _e('Guest', 'podcast-influence-tracker'); ?></option>
                    <option value="owner"><?php _e('Owner', 'podcast-influence-tracker'); ?></option>
                    <option value="booking"><?php _e('Booking Manager', 'podcast-influence-tracker'); ?></option>
                    <option value="other"><?php _e('Other', 'podcast-influence-tracker'); ?></option>
                </select>
            </div>

            <div class="guestify-form-group">
                <label>
                    <input type="checkbox" name="is_primary" value="1">
                    <?php _e('Set as primary contact for this role', 'podcast-influence-tracker'); ?>
                </label>
            </div>

            <div class="guestify-form-group">
                <label for="link_notes"><?php _e('Notes', 'podcast-influence-tracker'); ?></label>
                <textarea name="notes" id="link_notes" rows="2"></textarea>
            </div>

            <div class="guestify-form-actions">
                <button type="submit" class="guestify-submit-btn"><?php echo esc_html($atts['button_text']); ?></button>
                <span class="guestify-form-message"></span>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    /**
     * [guestify_link_entry]
     * Render form to link a Formidable entry to a podcast
     */
    public function render_link_entry_form($atts) {
        $atts = shortcode_atts([
            'class' => 'guestify-form',
            'redirect' => '',
            'entry_id' => '',
            'button_text' => 'Link to Podcast',
        ], $atts);

        // Get available podcasts for dropdown
        global $wpdb;
        $podcasts = $wpdb->get_results(
            "SELECT id, title, rss_feed_url FROM {$wpdb->prefix}guestify_podcasts ORDER BY title"
        );

        ob_start();
        ?>
        <form class="<?php echo esc_attr($atts['class']); ?> guestify-link-entry-form" data-action="pit_link_entry">
            <?php wp_nonce_field('pit_frontend_forms', 'pit_nonce'); ?>
            <input type="hidden" name="redirect" value="<?php echo esc_url($atts['redirect']); ?>">

            <?php if (empty($atts['entry_id'])): ?>
            <div class="guestify-form-group required">
                <label for="entry_formidable_id"><?php _e('Formidable Entry ID', 'podcast-influence-tracker'); ?> *</label>
                <input type="number" name="entry_id" id="entry_formidable_id" required>
            </div>
            <?php else: ?>
            <input type="hidden" name="entry_id" value="<?php echo esc_attr($atts['entry_id']); ?>">
            <?php endif; ?>

            <div class="guestify-form-group required">
                <label for="entry_podcast_id"><?php _e('Podcast', 'podcast-influence-tracker'); ?> *</label>
                <select name="podcast_id" id="entry_podcast_id" required>
                    <option value=""><?php _e('Select podcast...', 'podcast-influence-tracker'); ?></option>
                    <?php foreach ($podcasts as $podcast): ?>
                        <option value="<?php echo esc_attr($podcast->id); ?>">
                            <?php echo esc_html($podcast->title); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="guestify-form-group">
                <label for="entry_status"><?php _e('Outreach Status', 'podcast-influence-tracker'); ?></label>
                <select name="outreach_status" id="entry_status">
                    <option value=""><?php _e('Select status...', 'podcast-influence-tracker'); ?></option>
                    <option value="researching"><?php _e('Researching', 'podcast-influence-tracker'); ?></option>
                    <option value="queued"><?php _e('Queued', 'podcast-influence-tracker'); ?></option>
                    <option value="pitched"><?php _e('Pitched', 'podcast-influence-tracker'); ?></option>
                    <option value="follow_up"><?php _e('Follow Up', 'podcast-influence-tracker'); ?></option>
                    <option value="scheduled"><?php _e('Scheduled', 'podcast-influence-tracker'); ?></option>
                    <option value="completed"><?php _e('Completed', 'podcast-influence-tracker'); ?></option>
                    <option value="declined"><?php _e('Declined', 'podcast-influence-tracker'); ?></option>
                </select>
            </div>

            <div class="guestify-form-actions">
                <button type="submit" class="guestify-submit-btn"><?php echo esc_html($atts['button_text']); ?></button>
                <span class="guestify-form-message"></span>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    // ==================== AJAX HANDLERS ====================

    /**
     * AJAX: Add new podcast
     */
    public function ajax_add_podcast() {
        check_ajax_referer('pit_frontend_forms', 'pit_nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in.', 'podcast-influence-tracker')]);
        }

        // Validate required fields
        if (empty($_POST['title']) || empty($_POST['rss_feed_url'])) {
            wp_send_json_error(['message' => __('Podcast name and RSS URL are required.', 'podcast-influence-tracker')]);
        }

        $data = [
            'title' => sanitize_text_field($_POST['title']),
            'rss_feed_url' => esc_url_raw($_POST['rss_feed_url']),
            'website_url' => !empty($_POST['website_url']) ? esc_url_raw($_POST['website_url']) : null,
            'description' => !empty($_POST['description']) ? sanitize_textarea_field($_POST['description']) : null,
            'category' => !empty($_POST['category']) ? sanitize_text_field($_POST['category']) : null,
            'itunes_id' => !empty($_POST['itunes_id']) ? sanitize_text_field($_POST['itunes_id']) : null,
            'podcast_index_id' => !empty($_POST['podcast_index_id']) ? intval($_POST['podcast_index_id']) : null,
            'podcast_index_guid' => !empty($_POST['podcast_index_guid']) ? sanitize_text_field($_POST['podcast_index_guid']) : null,
            'taddy_podcast_uuid' => !empty($_POST['taddy_podcast_uuid']) ? sanitize_text_field($_POST['taddy_podcast_uuid']) : null,
            'source' => 'manual',
        ];

        // Calculate quality score
        $data['data_quality_score'] = $this->calculate_quality_score($data);

        $podcast_id = PIT_Database::upsert_guestify_podcast($data);

        if ($podcast_id) {
            wp_send_json_success([
                'message' => __('Podcast added successfully!', 'podcast-influence-tracker'),
                'podcast_id' => $podcast_id,
                'redirect' => !empty($_POST['redirect']) ? $_POST['redirect'] : null,
            ]);
        } else {
            wp_send_json_error(['message' => __('Failed to add podcast.', 'podcast-influence-tracker')]);
        }
    }

    /**
     * AJAX: Add new contact
     */
    public function ajax_add_contact() {
        check_ajax_referer('pit_frontend_forms', 'pit_nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in.', 'podcast-influence-tracker')]);
        }

        if (empty($_POST['full_name'])) {
            wp_send_json_error(['message' => __('Full name is required.', 'podcast-influence-tracker')]);
        }

        $data = [
            'full_name' => sanitize_text_field($_POST['full_name']),
            'first_name' => !empty($_POST['first_name']) ? sanitize_text_field($_POST['first_name']) : null,
            'last_name' => !empty($_POST['last_name']) ? sanitize_text_field($_POST['last_name']) : null,
            'email' => !empty($_POST['email']) ? sanitize_email($_POST['email']) : null,
            'phone' => !empty($_POST['phone']) ? sanitize_text_field($_POST['phone']) : null,
            'role' => !empty($_POST['role']) ? sanitize_text_field($_POST['role']) : null,
            'company' => !empty($_POST['company']) ? sanitize_text_field($_POST['company']) : null,
            'title' => !empty($_POST['title']) ? sanitize_text_field($_POST['title']) : null,
            'linkedin_url' => !empty($_POST['linkedin_url']) ? esc_url_raw($_POST['linkedin_url']) : null,
            'twitter_url' => !empty($_POST['twitter_url']) ? esc_url_raw($_POST['twitter_url']) : null,
            'website_url' => !empty($_POST['website_url']) ? esc_url_raw($_POST['website_url']) : null,
            'enrichment_source' => 'manual',
        ];

        // Auto-parse first/last name if not provided
        if (empty($data['first_name']) && empty($data['last_name'])) {
            $parts = explode(' ', $data['full_name'], 2);
            $data['first_name'] = $parts[0];
            $data['last_name'] = $parts[1] ?? '';
        }

        $contact_id = PIT_Database::upsert_contact($data);

        if ($contact_id) {
            // Link to podcast if podcast_id provided
            $podcast_id = !empty($_POST['podcast_id']) ? intval($_POST['podcast_id']) : null;
            if ($podcast_id) {
                $role = $data['role'] ?: 'host';
                $is_primary = !empty($_POST['is_primary']) ? 1 : 0;
                PIT_Database::link_podcast_contact($podcast_id, $contact_id, $role, $is_primary);
            }

            wp_send_json_success([
                'message' => __('Contact added successfully!', 'podcast-influence-tracker'),
                'contact_id' => $contact_id,
                'redirect' => !empty($_POST['redirect']) ? $_POST['redirect'] : null,
            ]);
        } else {
            wp_send_json_error(['message' => __('Failed to add contact.', 'podcast-influence-tracker')]);
        }
    }

    /**
     * AJAX: Add social account
     */
    public function ajax_add_social_account() {
        check_ajax_referer('pit_frontend_forms', 'pit_nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in.', 'podcast-influence-tracker')]);
        }

        if (empty($_POST['podcast_id']) || empty($_POST['platform']) || empty($_POST['profile_url'])) {
            wp_send_json_error(['message' => __('Podcast, platform, and URL are required.', 'podcast-influence-tracker')]);
        }

        $data = [
            'podcast_id' => intval($_POST['podcast_id']),
            'platform' => sanitize_text_field($_POST['platform']),
            'profile_url' => esc_url_raw($_POST['profile_url']),
            'username' => !empty($_POST['username']) ? sanitize_text_field($_POST['username']) : null,
            'display_name' => !empty($_POST['display_name']) ? sanitize_text_field($_POST['display_name']) : null,
            'discovery_method' => 'manual',
        ];

        $social_id = PIT_Database::upsert_social_account($data);

        if ($social_id) {
            wp_send_json_success([
                'message' => __('Social account added successfully!', 'podcast-influence-tracker'),
                'social_id' => $social_id,
                'redirect' => !empty($_POST['redirect']) ? $_POST['redirect'] : null,
            ]);
        } else {
            wp_send_json_error(['message' => __('Failed to add social account.', 'podcast-influence-tracker')]);
        }
    }

    /**
     * AJAX: Link contact to podcast
     */
    public function ajax_link_contact() {
        check_ajax_referer('pit_frontend_forms', 'pit_nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in.', 'podcast-influence-tracker')]);
        }

        if (empty($_POST['podcast_id']) || empty($_POST['contact_id']) || empty($_POST['role'])) {
            wp_send_json_error(['message' => __('Podcast, contact, and role are required.', 'podcast-influence-tracker')]);
        }

        $podcast_id = intval($_POST['podcast_id']);
        $contact_id = intval($_POST['contact_id']);
        $role = sanitize_text_field($_POST['role']);
        $is_primary = !empty($_POST['is_primary']) ? 1 : 0;
        $notes = !empty($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : null;

        $result = PIT_Database::link_podcast_contact($podcast_id, $contact_id, $role, $is_primary, $notes);

        if ($result) {
            wp_send_json_success([
                'message' => __('Contact linked successfully!', 'podcast-influence-tracker'),
                'redirect' => !empty($_POST['redirect']) ? $_POST['redirect'] : null,
            ]);
        } else {
            wp_send_json_error(['message' => __('Failed to link contact.', 'podcast-influence-tracker')]);
        }
    }

    /**
     * AJAX: Link entry to podcast
     */
    public function ajax_link_entry() {
        check_ajax_referer('pit_frontend_forms', 'pit_nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in.', 'podcast-influence-tracker')]);
        }

        if (empty($_POST['entry_id']) || empty($_POST['podcast_id'])) {
            wp_send_json_error(['message' => __('Entry ID and Podcast are required.', 'podcast-influence-tracker')]);
        }

        $entry_id = intval($_POST['entry_id']);
        $podcast_id = intval($_POST['podcast_id']);
        $outreach_status = !empty($_POST['outreach_status']) ? sanitize_text_field($_POST['outreach_status']) : null;

        $data = ['outreach_status' => $outreach_status];

        $result = PIT_Database::link_entry_to_podcast($entry_id, $podcast_id, $data);

        if ($result) {
            wp_send_json_success([
                'message' => __('Entry linked to podcast successfully!', 'podcast-influence-tracker'),
                'redirect' => !empty($_POST['redirect']) ? $_POST['redirect'] : null,
            ]);
        } else {
            wp_send_json_error(['message' => __('Failed to link entry.', 'podcast-influence-tracker')]);
        }
    }

    // ==================== HELPERS ====================

    /**
     * Calculate data quality score
     */
    private function calculate_quality_score($data) {
        $score = 30; // Base score

        if (!empty($data['rss_feed_url'])) $score += 20;
        if (!empty($data['description'])) $score += 10;
        if (!empty($data['podcast_index_id']) || !empty($data['taddy_podcast_uuid']) || !empty($data['itunes_id'])) $score += 30;
        if (!empty($data['website_url'])) $score += 10;

        return min($score, 100);
    }
}
