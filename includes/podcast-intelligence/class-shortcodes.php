<?php
/**
 * Podcast Intelligence Shortcodes
 *
 * Provides shortcodes for displaying podcast and contact data.
 * Shortcodes can be used in two ways:
 *
 * 1. With Formidable Views (using entry_id):
 *    [guestify_podcast_title entry_id="[id]"]
 *    [guestify_contact_email entry_id="[id]"]
 *
 * 2. Standalone Admin Use (using direct IDs):
 *    [guestify_podcast_title podcast_id="5"]
 *    [guestify_contact_email contact_id="10"]
 *    [guestify_all_contacts podcast_id="5"]
 *
 * Direct IDs (podcast_id, contact_id) take priority over entry_id.
 *
 * @package Podcast_Influence_Tracker
 * @subpackage Podcast_Intelligence
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Shortcodes {

    /**
     * @var PIT_Shortcodes Singleton instance
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
     * Constructor - Register all shortcodes
     */
    private function __construct() {
        // ==================== PODCAST SHORTCODES ====================
        add_shortcode('guestify_podcast_title', [$this, 'podcast_title']);
        add_shortcode('guestify_podcast_description', [$this, 'podcast_description']);
        add_shortcode('guestify_podcast_rss', [$this, 'podcast_rss']);
        add_shortcode('guestify_podcast_website', [$this, 'podcast_website']);
        add_shortcode('guestify_podcast_category', [$this, 'podcast_category']);
        add_shortcode('guestify_podcast_language', [$this, 'podcast_language']);
        add_shortcode('guestify_podcast_episode_count', [$this, 'podcast_episode_count']);
        add_shortcode('guestify_podcast_frequency', [$this, 'podcast_frequency']);
        add_shortcode('guestify_podcast_duration', [$this, 'podcast_duration']);

        // External IDs
        add_shortcode('guestify_podcast_index_id', [$this, 'podcast_index_id']);
        add_shortcode('guestify_podcast_index_guid', [$this, 'podcast_index_guid']);
        add_shortcode('guestify_podcast_itunes_id', [$this, 'podcast_itunes_id']);
        add_shortcode('guestify_podcast_taddy_uuid', [$this, 'podcast_taddy_uuid']);
        add_shortcode('guestify_podcast_source', [$this, 'podcast_source']);

        // Scores and status
        add_shortcode('guestify_podcast_quality_score', [$this, 'podcast_quality_score']);
        add_shortcode('guestify_podcast_relevance_score', [$this, 'podcast_relevance_score']);
        add_shortcode('guestify_podcast_is_tracked', [$this, 'podcast_is_tracked']);

        // ==================== CONTACT SHORTCODES ====================
        add_shortcode('guestify_contact_name', [$this, 'contact_name']);
        add_shortcode('guestify_contact_first_name', [$this, 'contact_first_name']);
        add_shortcode('guestify_contact_last_name', [$this, 'contact_last_name']);
        add_shortcode('guestify_contact_email', [$this, 'contact_email']);
        add_shortcode('guestify_contact_phone', [$this, 'contact_phone']);
        add_shortcode('guestify_contact_role', [$this, 'contact_role']);
        add_shortcode('guestify_contact_company', [$this, 'contact_company']);
        add_shortcode('guestify_contact_title', [$this, 'contact_title']);
        add_shortcode('guestify_contact_linkedin', [$this, 'contact_linkedin']);
        add_shortcode('guestify_contact_twitter', [$this, 'contact_twitter']);
        add_shortcode('guestify_contact_website', [$this, 'contact_website']);

        // ==================== OUTREACH SHORTCODES ====================
        add_shortcode('guestify_outreach_status', [$this, 'outreach_status']);
        add_shortcode('guestify_first_contact_date', [$this, 'first_contact_date']);
        add_shortcode('guestify_last_contact_date', [$this, 'last_contact_date']);

        // ==================== COMPOSITE SHORTCODES ====================
        add_shortcode('guestify_podcast_card', [$this, 'podcast_card']);
        add_shortcode('guestify_contact_card', [$this, 'contact_card']);

        // ==================== ALL CONTACTS SHORTCODES ====================
        add_shortcode('guestify_all_contacts', [$this, 'all_contacts']);
        add_shortcode('guestify_contacts_list', [$this, 'contacts_list']);
        add_shortcode('guestify_contacts_table', [$this, 'contacts_table']);
        add_shortcode('guestify_contacts_count', [$this, 'contacts_count']);

        // ==================== INTERVIEW TRACKER SHORTCODES ====================
        add_shortcode('guestify_interview_contacts', [$this, 'interview_contacts']);

        // ==================== GENERIC FIELD SHORTCODE ====================
        add_shortcode('guestify_field', [$this, 'generic_field']);
    }

    // ==================== HELPER METHODS ====================

    /**
     * Get podcast data - supports both entry_id and podcast_id
     */
    private function get_podcast($entry_id = null, $podcast_id = null) {
        // Direct podcast_id takes priority
        if (!empty($podcast_id)) {
            return PIT_Database::get_guestify_podcast($podcast_id);
        }
        // Fall back to entry_id lookup
        if (!empty($entry_id)) {
            return PIT_Database::get_entry_podcast($entry_id);
        }
        return null;
    }

    /**
     * Get contact data - supports both entry_id and contact_id
     */
    private function get_contact($entry_id = null, $contact_id = null) {
        // Direct contact_id takes priority
        if (!empty($contact_id)) {
            return PIT_Database::get_contact($contact_id);
        }
        // Fall back to entry_id lookup
        if (!empty($entry_id)) {
            return PIT_Database::get_entry_contact($entry_id);
        }
        return null;
    }

    /**
     * Get bridge data for entry
     */
    private function get_bridge($entry_id) {
        if (empty($entry_id)) return null;
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_interview_tracker_podcasts';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE formidable_entry_id = %d LIMIT 1",
            $entry_id
        ));
    }

    /**
     * Format output with optional wrapper
     */
    private function format_output($value, $atts) {
        if (empty($value) && isset($atts['default'])) {
            $value = $atts['default'];
        }

        if (empty($value)) {
            return '';
        }

        // Apply formatting
        if (!empty($atts['format'])) {
            switch ($atts['format']) {
                case 'date':
                    $value = date_i18n(get_option('date_format'), strtotime($value));
                    break;
                case 'datetime':
                    $value = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($value));
                    break;
                case 'number':
                    $value = number_format_i18n($value);
                    break;
                case 'url':
                    $value = esc_url($value);
                    break;
            }
        }

        // Add wrapper if specified
        if (!empty($atts['before'])) {
            $value = $atts['before'] . $value;
        }
        if (!empty($atts['after'])) {
            $value = $value . $atts['after'];
        }

        // Make it a link if link="true"
        if (!empty($atts['link']) && $atts['link'] === 'true' && filter_var($value, FILTER_VALIDATE_URL)) {
            $target = !empty($atts['target']) ? $atts['target'] : '_blank';
            $value = '<a href="' . esc_url($value) . '" target="' . esc_attr($target) . '">' . esc_html($value) . '</a>';
        }

        return $value;
    }

    // ==================== PODCAST SHORTCODES ====================

    /**
     * [guestify_podcast_title entry_id="123"]
     * [guestify_podcast_title podcast_id="5"]
     */
    public function podcast_title($atts) {
        $atts = shortcode_atts(['podcast_id' => '', 'entry_id' => '', 'default' => '', 'before' => '', 'after' => ''], $atts);
        $podcast = $this->get_podcast($atts['entry_id'], $atts['podcast_id']);
        return $this->format_output($podcast->title ?? null, $atts);
    }

    /**
     * [guestify_podcast_description entry_id="123"]
     * [guestify_podcast_description podcast_id="5"]
     */
    public function podcast_description($atts) {
        $atts = shortcode_atts(['podcast_id' => '', 'entry_id' => '', 'default' => '', 'before' => '', 'after' => '', 'limit' => ''], $atts);
        $podcast = $this->get_podcast($atts['entry_id'], $atts['podcast_id']);
        $value = $podcast->description ?? null;

        // Truncate if limit specified
        if (!empty($value) && !empty($atts['limit'])) {
            $limit = intval($atts['limit']);
            if (strlen($value) > $limit) {
                $value = substr($value, 0, $limit) . '...';
            }
        }

        return $this->format_output($value, $atts);
    }

    /**
     * [guestify_podcast_rss entry_id="123" link="true"]
     * [guestify_podcast_rss podcast_id="5" link="true"]
     */
    public function podcast_rss($atts) {
        $atts = shortcode_atts(['podcast_id' => '', 'entry_id' => '', 'default' => '', 'before' => '', 'after' => '', 'link' => 'false', 'target' => '_blank'], $atts);
        $podcast = $this->get_podcast($atts['entry_id'], $atts['podcast_id']);
        return $this->format_output($podcast->rss_feed_url ?? null, $atts);
    }

    /**
     * [guestify_podcast_website entry_id="123" link="true"]
     * [guestify_podcast_website podcast_id="5" link="true"]
     */
    public function podcast_website($atts) {
        $atts = shortcode_atts(['podcast_id' => '', 'entry_id' => '', 'default' => '', 'before' => '', 'after' => '', 'link' => 'false', 'target' => '_blank'], $atts);
        $podcast = $this->get_podcast($atts['entry_id'], $atts['podcast_id']);
        return $this->format_output($podcast->website_url ?? null, $atts);
    }

    /**
     * [guestify_podcast_category entry_id="123"]
     * [guestify_podcast_category podcast_id="5"]
     */
    public function podcast_category($atts) {
        $atts = shortcode_atts(['podcast_id' => '', 'entry_id' => '', 'default' => ''], $atts);
        $podcast = $this->get_podcast($atts['entry_id'], $atts['podcast_id']);
        return $this->format_output($podcast->category ?? null, $atts);
    }

    /**
     * [guestify_podcast_language entry_id="123"]
     * [guestify_podcast_language podcast_id="5"]
     */
    public function podcast_language($atts) {
        $atts = shortcode_atts(['podcast_id' => '', 'entry_id' => '', 'default' => 'en'], $atts);
        $podcast = $this->get_podcast($atts['entry_id'], $atts['podcast_id']);
        return $this->format_output($podcast->language ?? null, $atts);
    }

    /**
     * [guestify_podcast_episode_count entry_id="123"]
     * [guestify_podcast_episode_count podcast_id="5"]
     */
    public function podcast_episode_count($atts) {
        $atts = shortcode_atts(['podcast_id' => '', 'entry_id' => '', 'default' => '0', 'format' => 'number'], $atts);
        $podcast = $this->get_podcast($atts['entry_id'], $atts['podcast_id']);
        return $this->format_output($podcast->episode_count ?? null, $atts);
    }

    /**
     * [guestify_podcast_frequency entry_id="123"]
     * [guestify_podcast_frequency podcast_id="5"]
     */
    public function podcast_frequency($atts) {
        $atts = shortcode_atts(['podcast_id' => '', 'entry_id' => '', 'default' => ''], $atts);
        $podcast = $this->get_podcast($atts['entry_id'], $atts['podcast_id']);
        return $this->format_output($podcast->frequency ?? null, $atts);
    }

    /**
     * [guestify_podcast_duration entry_id="123"]
     * [guestify_podcast_duration podcast_id="5"]
     */
    public function podcast_duration($atts) {
        $atts = shortcode_atts(['podcast_id' => '', 'entry_id' => '', 'default' => '', 'format' => 'number', 'after' => ' min'], $atts);
        $podcast = $this->get_podcast($atts['entry_id'], $atts['podcast_id']);
        return $this->format_output($podcast->average_duration ?? null, $atts);
    }

    // ==================== EXTERNAL ID SHORTCODES ====================

    /**
     * [guestify_podcast_index_id entry_id="123"]
     * [guestify_podcast_index_id podcast_id="5"]
     */
    public function podcast_index_id($atts) {
        $atts = shortcode_atts(['podcast_id' => '', 'entry_id' => '', 'default' => ''], $atts);
        $podcast = $this->get_podcast($atts['entry_id'], $atts['podcast_id']);
        return $this->format_output($podcast->podcast_index_id ?? null, $atts);
    }

    /**
     * [guestify_podcast_index_guid entry_id="123"]
     * [guestify_podcast_index_guid podcast_id="5"]
     */
    public function podcast_index_guid($atts) {
        $atts = shortcode_atts(['podcast_id' => '', 'entry_id' => '', 'default' => ''], $atts);
        $podcast = $this->get_podcast($atts['entry_id'], $atts['podcast_id']);
        return $this->format_output($podcast->podcast_index_guid ?? null, $atts);
    }

    /**
     * [guestify_podcast_itunes_id entry_id="123"]
     * [guestify_podcast_itunes_id podcast_id="5"]
     */
    public function podcast_itunes_id($atts) {
        $atts = shortcode_atts(['podcast_id' => '', 'entry_id' => '', 'default' => ''], $atts);
        $podcast = $this->get_podcast($atts['entry_id'], $atts['podcast_id']);
        return $this->format_output($podcast->itunes_id ?? null, $atts);
    }

    /**
     * [guestify_podcast_taddy_uuid entry_id="123"]
     * [guestify_podcast_taddy_uuid podcast_id="5"]
     */
    public function podcast_taddy_uuid($atts) {
        $atts = shortcode_atts(['podcast_id' => '', 'entry_id' => '', 'default' => ''], $atts);
        $podcast = $this->get_podcast($atts['entry_id'], $atts['podcast_id']);
        return $this->format_output($podcast->taddy_podcast_uuid ?? null, $atts);
    }

    /**
     * [guestify_podcast_source entry_id="123"]
     * [guestify_podcast_source podcast_id="5"]
     */
    public function podcast_source($atts) {
        $atts = shortcode_atts(['podcast_id' => '', 'entry_id' => '', 'default' => 'manual'], $atts);
        $podcast = $this->get_podcast($atts['entry_id'], $atts['podcast_id']);
        return $this->format_output($podcast->source ?? null, $atts);
    }

    // ==================== SCORE SHORTCODES ====================

    /**
     * [guestify_podcast_quality_score entry_id="123"]
     * [guestify_podcast_quality_score podcast_id="5"]
     */
    public function podcast_quality_score($atts) {
        $atts = shortcode_atts(['podcast_id' => '', 'entry_id' => '', 'default' => '0', 'format' => 'number', 'after' => '%'], $atts);
        $podcast = $this->get_podcast($atts['entry_id'], $atts['podcast_id']);
        return $this->format_output($podcast->data_quality_score ?? null, $atts);
    }

    /**
     * [guestify_podcast_relevance_score entry_id="123"]
     * [guestify_podcast_relevance_score podcast_id="5"]
     */
    public function podcast_relevance_score($atts) {
        $atts = shortcode_atts(['podcast_id' => '', 'entry_id' => '', 'default' => '0', 'format' => 'number', 'after' => '%'], $atts);
        $podcast = $this->get_podcast($atts['entry_id'], $atts['podcast_id']);
        return $this->format_output($podcast->relevance_score ?? null, $atts);
    }

    /**
     * [guestify_podcast_is_tracked entry_id="123" true="Yes" false="No"]
     * [guestify_podcast_is_tracked podcast_id="5" true="Yes" false="No"]
     */
    public function podcast_is_tracked($atts) {
        $atts = shortcode_atts(['podcast_id' => '', 'entry_id' => '', 'true' => 'Yes', 'false' => 'No'], $atts);
        $podcast = $this->get_podcast($atts['entry_id'], $atts['podcast_id']);
        $is_tracked = !empty($podcast->is_tracked);
        return $is_tracked ? $atts['true'] : $atts['false'];
    }

    // ==================== CONTACT SHORTCODES ====================

    /**
     * [guestify_contact_name entry_id="123"]
     * [guestify_contact_name contact_id="5"]
     */
    public function contact_name($atts) {
        $atts = shortcode_atts(['contact_id' => '', 'entry_id' => '', 'default' => ''], $atts);
        $contact = $this->get_contact($atts['entry_id'], $atts['contact_id']);
        return $this->format_output($contact->full_name ?? null, $atts);
    }

    /**
     * [guestify_contact_first_name entry_id="123"]
     * [guestify_contact_first_name contact_id="5"]
     */
    public function contact_first_name($atts) {
        $atts = shortcode_atts(['contact_id' => '', 'entry_id' => '', 'default' => ''], $atts);
        $contact = $this->get_contact($atts['entry_id'], $atts['contact_id']);
        return $this->format_output($contact->first_name ?? null, $atts);
    }

    /**
     * [guestify_contact_last_name entry_id="123"]
     * [guestify_contact_last_name contact_id="5"]
     */
    public function contact_last_name($atts) {
        $atts = shortcode_atts(['contact_id' => '', 'entry_id' => '', 'default' => ''], $atts);
        $contact = $this->get_contact($atts['entry_id'], $atts['contact_id']);
        return $this->format_output($contact->last_name ?? null, $atts);
    }

    /**
     * [guestify_contact_email entry_id="123"]
     * [guestify_contact_email contact_id="5"]
     */
    public function contact_email($atts) {
        $atts = shortcode_atts(['contact_id' => '', 'entry_id' => '', 'default' => '', 'link' => 'false'], $atts);
        $contact = $this->get_contact($atts['entry_id'], $atts['contact_id']);
        $email = $contact->email ?? null;

        if (!empty($email) && $atts['link'] === 'true') {
            return '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>';
        }

        return $this->format_output($email, $atts);
    }

    /**
     * [guestify_contact_phone entry_id="123"]
     * [guestify_contact_phone contact_id="5"]
     */
    public function contact_phone($atts) {
        $atts = shortcode_atts(['contact_id' => '', 'entry_id' => '', 'default' => '', 'link' => 'false'], $atts);
        $contact = $this->get_contact($atts['entry_id'], $atts['contact_id']);
        $phone = $contact->phone ?? null;

        if (!empty($phone) && $atts['link'] === 'true') {
            $tel = preg_replace('/[^0-9+]/', '', $phone);
            return '<a href="tel:' . esc_attr($tel) . '">' . esc_html($phone) . '</a>';
        }

        return $this->format_output($phone, $atts);
    }

    /**
     * [guestify_contact_role entry_id="123"]
     * [guestify_contact_role contact_id="5"]
     */
    public function contact_role($atts) {
        $atts = shortcode_atts(['contact_id' => '', 'entry_id' => '', 'default' => ''], $atts);
        $contact = $this->get_contact($atts['entry_id'], $atts['contact_id']);
        return $this->format_output($contact->role ?? null, $atts);
    }

    /**
     * [guestify_contact_company entry_id="123"]
     * [guestify_contact_company contact_id="5"]
     */
    public function contact_company($atts) {
        $atts = shortcode_atts(['contact_id' => '', 'entry_id' => '', 'default' => ''], $atts);
        $contact = $this->get_contact($atts['entry_id'], $atts['contact_id']);
        return $this->format_output($contact->company ?? null, $atts);
    }

    /**
     * [guestify_contact_title entry_id="123"]
     * [guestify_contact_title contact_id="5"]
     */
    public function contact_title($atts) {
        $atts = shortcode_atts(['contact_id' => '', 'entry_id' => '', 'default' => ''], $atts);
        $contact = $this->get_contact($atts['entry_id'], $atts['contact_id']);
        return $this->format_output($contact->title ?? null, $atts);
    }

    /**
     * [guestify_contact_linkedin entry_id="123" link="true"]
     * [guestify_contact_linkedin contact_id="5" link="true"]
     */
    public function contact_linkedin($atts) {
        $atts = shortcode_atts(['contact_id' => '', 'entry_id' => '', 'default' => '', 'link' => 'false', 'target' => '_blank'], $atts);
        $contact = $this->get_contact($atts['entry_id'], $atts['contact_id']);
        return $this->format_output($contact->linkedin_url ?? null, $atts);
    }

    /**
     * [guestify_contact_twitter entry_id="123" link="true"]
     * [guestify_contact_twitter contact_id="5" link="true"]
     */
    public function contact_twitter($atts) {
        $atts = shortcode_atts(['contact_id' => '', 'entry_id' => '', 'default' => '', 'link' => 'false', 'target' => '_blank'], $atts);
        $contact = $this->get_contact($atts['entry_id'], $atts['contact_id']);
        return $this->format_output($contact->twitter_url ?? null, $atts);
    }

    /**
     * [guestify_contact_website entry_id="123" link="true"]
     * [guestify_contact_website contact_id="5" link="true"]
     */
    public function contact_website($atts) {
        $atts = shortcode_atts(['contact_id' => '', 'entry_id' => '', 'default' => '', 'link' => 'false', 'target' => '_blank'], $atts);
        $contact = $this->get_contact($atts['entry_id'], $atts['contact_id']);
        return $this->format_output($contact->website_url ?? null, $atts);
    }

    // ==================== OUTREACH SHORTCODES ====================

    /**
     * [guestify_outreach_status entry_id="123"]
     */
    public function outreach_status($atts) {
        $atts = shortcode_atts(['entry_id' => '', 'default' => 'not started'], $atts);
        $bridge = $this->get_bridge($atts['entry_id']);
        return $this->format_output($bridge->outreach_status ?? null, $atts);
    }

    /**
     * [guestify_first_contact_date entry_id="123" format="date"]
     */
    public function first_contact_date($atts) {
        $atts = shortcode_atts(['entry_id' => '', 'default' => '', 'format' => 'date'], $atts);
        $bridge = $this->get_bridge($atts['entry_id']);
        return $this->format_output($bridge->first_contact_date ?? null, $atts);
    }

    /**
     * [guestify_last_contact_date entry_id="123" format="date"]
     */
    public function last_contact_date($atts) {
        $atts = shortcode_atts(['entry_id' => '', 'default' => '', 'format' => 'date'], $atts);
        $bridge = $this->get_bridge($atts['entry_id']);
        return $this->format_output($bridge->last_contact_date ?? null, $atts);
    }

    // ==================== COMPOSITE SHORTCODES ====================

    /**
     * [guestify_podcast_card entry_id="123"]
     * [guestify_podcast_card podcast_id="5"]
     * Displays a formatted podcast info card
     */
    public function podcast_card($atts) {
        $atts = shortcode_atts(['podcast_id' => '', 'entry_id' => '', 'class' => 'guestify-podcast-card'], $atts);
        $podcast = $this->get_podcast($atts['entry_id'], $atts['podcast_id']);

        if (!$podcast) {
            return '<div class="' . esc_attr($atts['class']) . ' empty">No podcast data found</div>';
        }

        ob_start();
        ?>
        <div class="<?php echo esc_attr($atts['class']); ?>">
            <h4 class="podcast-title"><?php echo esc_html($podcast->title); ?></h4>
            <?php if (!empty($podcast->description)): ?>
                <p class="podcast-description"><?php echo esc_html(wp_trim_words($podcast->description, 30)); ?></p>
            <?php endif; ?>
            <div class="podcast-meta">
                <?php if (!empty($podcast->website_url)): ?>
                    <span class="podcast-website"><a href="<?php echo esc_url($podcast->website_url); ?>" target="_blank">Website</a></span>
                <?php endif; ?>
                <?php if (!empty($podcast->rss_feed_url)): ?>
                    <span class="podcast-rss"><a href="<?php echo esc_url($podcast->rss_feed_url); ?>" target="_blank">RSS</a></span>
                <?php endif; ?>
                <?php if (!empty($podcast->data_quality_score)): ?>
                    <span class="podcast-quality">Quality: <?php echo esc_html($podcast->data_quality_score); ?>%</span>
                <?php endif; ?>
            </div>
            <?php if (!empty($podcast->source)): ?>
                <div class="podcast-source">Source: <?php echo esc_html(ucfirst($podcast->source)); ?></div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * [guestify_contact_card entry_id="123"]
     * [guestify_contact_card contact_id="5"]
     * Displays a formatted contact info card
     */
    public function contact_card($atts) {
        $atts = shortcode_atts(['contact_id' => '', 'entry_id' => '', 'class' => 'guestify-contact-card'], $atts);
        $contact = $this->get_contact($atts['entry_id'], $atts['contact_id']);

        if (!$contact) {
            return '<div class="' . esc_attr($atts['class']) . ' empty">No contact data found</div>';
        }

        ob_start();
        ?>
        <div class="<?php echo esc_attr($atts['class']); ?>">
            <h4 class="contact-name"><?php echo esc_html($contact->full_name); ?></h4>
            <?php if (!empty($contact->role)): ?>
                <p class="contact-role"><?php echo esc_html(ucfirst($contact->role)); ?></p>
            <?php endif; ?>
            <?php if (!empty($contact->email)): ?>
                <p class="contact-email"><a href="mailto:<?php echo esc_attr($contact->email); ?>"><?php echo esc_html($contact->email); ?></a></p>
            <?php endif; ?>
            <div class="contact-social">
                <?php if (!empty($contact->linkedin_url)): ?>
                    <a href="<?php echo esc_url($contact->linkedin_url); ?>" target="_blank" class="linkedin">LinkedIn</a>
                <?php endif; ?>
                <?php if (!empty($contact->twitter_url)): ?>
                    <a href="<?php echo esc_url($contact->twitter_url); ?>" target="_blank" class="twitter">Twitter</a>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ==================== GENERIC FIELD SHORTCODE ====================

    /**
     * [guestify_field entry_id="123" table="podcast" field="title"]
     * [guestify_field podcast_id="5" table="podcast" field="title"]
     * [guestify_field contact_id="5" table="contact" field="email"]
     * Generic shortcode for any field from any table
     */
    public function generic_field($atts) {
        $atts = shortcode_atts([
            'entry_id' => '',
            'podcast_id' => '',    // Direct podcast ID for standalone use
            'contact_id' => '',    // Direct contact ID for standalone use
            'table' => 'podcast',  // podcast, contact, bridge
            'field' => '',
            'default' => '',
            'before' => '',
            'after' => '',
            'format' => '',
            'link' => 'false',
        ], $atts);

        if (empty($atts['field'])) {
            return '';
        }

        $data = null;
        switch ($atts['table']) {
            case 'podcast':
                $data = $this->get_podcast($atts['entry_id'], $atts['podcast_id']);
                break;
            case 'contact':
                $data = $this->get_contact($atts['entry_id'], $atts['contact_id']);
                break;
            case 'bridge':
                $data = $this->get_bridge($atts['entry_id']);
                break;
        }

        if (!$data) {
            return $atts['default'];
        }

        $field = $atts['field'];
        $value = $data->$field ?? null;

        return $this->format_output($value, $atts);
    }

    // ==================== ALL CONTACTS SHORTCODES ====================

    /**
     * Get all contacts for a podcast - supports entry_id OR podcast_id
     */
    private function get_all_contacts($entry_id = null, $podcast_id = null, $role = null) {
        // Direct podcast_id takes priority
        if (!empty($podcast_id)) {
            return PIT_Database::get_podcast_contacts($podcast_id, $role);
        }
        // Fall back to entry_id lookup
        if (!empty($entry_id)) {
            $podcast = $this->get_podcast($entry_id);
            if (!$podcast) return [];
            return PIT_Database::get_podcast_contacts($podcast->id, $role);
        }
        return [];
    }

    /**
     * [guestify_all_contacts podcast_id="5" role="host" format="cards"]
     * [guestify_all_contacts entry_id="123" role="host" format="cards"]
     *
     * Displays all contacts for a podcast. Use EITHER podcast_id OR entry_id.
     *
     * @param string podcast_id - Direct podcast ID (preferred for admin use)
     * @param string entry_id - Formidable entry ID (for Interview Tracker views)
     * @param string role - Filter by role (host/producer/guest/owner) or leave empty for all
     * @param string format - Output format: cards, list, table, json
     * @param string fields - Comma-separated fields to show (default: name,email,role)
     * @param string class - CSS class for wrapper
     * @param string empty - Message when no contacts found
     */
    public function all_contacts($atts) {
        $atts = shortcode_atts([
            'podcast_id' => '',     // Direct podcast ID (takes priority)
            'entry_id' => '',       // Formidable entry ID (fallback)
            'role' => '',           // Filter by role: host, producer, guest, owner
            'format' => 'cards',    // cards, list, table, json
            'fields' => 'name,email,role',  // Which fields to display
            'class' => 'guestify-contacts',
            'empty' => 'No contacts found',
            'link_email' => 'true',
            'link_social' => 'true',
        ], $atts);

        $contacts = $this->get_all_contacts($atts['entry_id'], $atts['podcast_id'], $atts['role'] ?: null);

        if (empty($contacts)) {
            return '<div class="' . esc_attr($atts['class']) . ' empty">' . esc_html($atts['empty']) . '</div>';
        }

        $fields = array_map('trim', explode(',', $atts['fields']));

        switch ($atts['format']) {
            case 'json':
                return json_encode(array_map(function($c) use ($fields) {
                    $data = [];
                    foreach ($fields as $field) {
                        $data[$field] = $this->get_contact_field($c, $field);
                    }
                    return $data;
                }, $contacts));

            case 'table':
                return $this->render_contacts_table($contacts, $fields, $atts);

            case 'list':
                return $this->render_contacts_list($contacts, $fields, $atts);

            case 'cards':
            default:
                return $this->render_contacts_cards($contacts, $atts);
        }
    }

    /**
     * [guestify_contacts_list podcast_id="5"]
     * [guestify_contacts_list entry_id="123"]
     * Simple bulleted list of contacts
     */
    public function contacts_list($atts) {
        $atts = shortcode_atts([
            'podcast_id' => '',     // Direct podcast ID (takes priority)
            'entry_id' => '',       // Formidable entry ID (fallback)
            'role' => '',
            'fields' => 'name,role,email',
            'class' => 'guestify-contacts-list',
            'empty' => 'No contacts found',
            'separator' => ' - ',
            'link_email' => 'true',
        ], $atts);

        $contacts = $this->get_all_contacts($atts['entry_id'], $atts['podcast_id'], $atts['role'] ?: null);

        if (empty($contacts)) {
            return '<div class="' . esc_attr($atts['class']) . ' empty">' . esc_html($atts['empty']) . '</div>';
        }

        $fields = array_map('trim', explode(',', $atts['fields']));

        return $this->render_contacts_list($contacts, $fields, $atts);
    }

    /**
     * [guestify_contacts_table podcast_id="5"]
     * [guestify_contacts_table entry_id="123"]
     * HTML table of contacts
     */
    public function contacts_table($atts) {
        $atts = shortcode_atts([
            'podcast_id' => '',     // Direct podcast ID (takes priority)
            'entry_id' => '',       // Formidable entry ID (fallback)
            'role' => '',
            'fields' => 'name,role,email,linkedin',
            'class' => 'guestify-contacts-table',
            'empty' => 'No contacts found',
            'link_email' => 'true',
            'link_social' => 'true',
        ], $atts);

        $contacts = $this->get_all_contacts($atts['entry_id'], $atts['podcast_id'], $atts['role'] ?: null);

        if (empty($contacts)) {
            return '<div class="' . esc_attr($atts['class']) . ' empty">' . esc_html($atts['empty']) . '</div>';
        }

        $fields = array_map('trim', explode(',', $atts['fields']));

        return $this->render_contacts_table($contacts, $fields, $atts);
    }

    /**
     * [guestify_contacts_count podcast_id="5" role="host"]
     * [guestify_contacts_count entry_id="123" role="host"]
     * Returns count of contacts
     */
    public function contacts_count($atts) {
        $atts = shortcode_atts([
            'podcast_id' => '',     // Direct podcast ID (takes priority)
            'entry_id' => '',       // Formidable entry ID (fallback)
            'role' => '',
            'before' => '',
            'after' => '',
        ], $atts);

        $contacts = $this->get_all_contacts($atts['entry_id'], $atts['podcast_id'], $atts['role'] ?: null);
        $count = count($contacts);

        return $atts['before'] . $count . $atts['after'];
    }

    /**
     * Helper: Get a specific field from a contact object
     */
    private function get_contact_field($contact, $field) {
        $map = [
            'name' => 'full_name',
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'email' => 'email',
            'phone' => 'phone',
            'role' => 'role',
            'company' => 'company',
            'title' => 'title',
            'linkedin' => 'linkedin_url',
            'twitter' => 'twitter_url',
            'website' => 'website_url',
            'is_primary' => 'is_primary',
        ];

        $prop = $map[$field] ?? $field;
        return $contact->$prop ?? '';
    }

    /**
     * Render contacts as cards
     */
    private function render_contacts_cards($contacts, $atts) {
        ob_start();
        ?>
        <div class="<?php echo esc_attr($atts['class']); ?>">
            <?php foreach ($contacts as $contact): ?>
                <div class="guestify-contact-item<?php echo $contact->is_primary ? ' is-primary' : ''; ?>">
                    <div class="contact-header">
                        <span class="contact-name"><?php echo esc_html($contact->full_name); ?></span>
                        <?php if (!empty($contact->role)): ?>
                            <span class="contact-role">(<?php echo esc_html(ucfirst($contact->role)); ?>)</span>
                        <?php endif; ?>
                        <?php if ($contact->is_primary): ?>
                            <span class="primary-badge">Primary</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($contact->email)): ?>
                        <div class="contact-email">
                            <?php if ($atts['link_email'] === 'true'): ?>
                                <a href="mailto:<?php echo esc_attr($contact->email); ?>"><?php echo esc_html($contact->email); ?></a>
                            <?php else: ?>
                                <?php echo esc_html($contact->email); ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($contact->phone)): ?>
                        <div class="contact-phone"><?php echo esc_html($contact->phone); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($contact->company) || !empty($contact->title)): ?>
                        <div class="contact-work">
                            <?php if (!empty($contact->title)): ?>
                                <span class="contact-title"><?php echo esc_html($contact->title); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($contact->company)): ?>
                                <span class="contact-company"><?php echo esc_html($contact->company); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($atts['link_social'] === 'true'): ?>
                        <div class="contact-social">
                            <?php if (!empty($contact->linkedin_url)): ?>
                                <a href="<?php echo esc_url($contact->linkedin_url); ?>" target="_blank" class="social-link linkedin">LinkedIn</a>
                            <?php endif; ?>
                            <?php if (!empty($contact->twitter_url)): ?>
                                <a href="<?php echo esc_url($contact->twitter_url); ?>" target="_blank" class="social-link twitter">Twitter</a>
                            <?php endif; ?>
                            <?php if (!empty($contact->website_url)): ?>
                                <a href="<?php echo esc_url($contact->website_url); ?>" target="_blank" class="social-link website">Website</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render contacts as a simple list
     */
    private function render_contacts_list($contacts, $fields, $atts) {
        ob_start();
        ?>
        <ul class="<?php echo esc_attr($atts['class']); ?>">
            <?php foreach ($contacts as $contact): ?>
                <li class="contact-item<?php echo $contact->is_primary ? ' is-primary' : ''; ?>">
                    <?php
                    $parts = [];
                    foreach ($fields as $field) {
                        $value = $this->get_contact_field($contact, $field);
                        if (!empty($value)) {
                            // Make email a link if enabled
                            if ($field === 'email' && $atts['link_email'] === 'true') {
                                $value = '<a href="mailto:' . esc_attr($value) . '">' . esc_html($value) . '</a>';
                            } elseif (in_array($field, ['linkedin', 'twitter', 'website']) && isset($atts['link_social']) && $atts['link_social'] === 'true') {
                                $value = '<a href="' . esc_url($value) . '" target="_blank">' . ucfirst($field) . '</a>';
                            } else {
                                $value = esc_html($value);
                            }
                            $parts[] = $value;
                        }
                    }
                    echo implode($atts['separator'] ?? ' - ', $parts);
                    if ($contact->is_primary) {
                        echo ' <span class="primary-badge">(Primary)</span>';
                    }
                    ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
        return ob_get_clean();
    }

    /**
     * Render contacts as a table
     */
    private function render_contacts_table($contacts, $fields, $atts) {
        // Field labels
        $labels = [
            'name' => 'Name',
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'email' => 'Email',
            'phone' => 'Phone',
            'role' => 'Role',
            'company' => 'Company',
            'title' => 'Title',
            'linkedin' => 'LinkedIn',
            'twitter' => 'Twitter',
            'website' => 'Website',
        ];

        ob_start();
        ?>
        <table class="<?php echo esc_attr($atts['class']); ?>">
            <thead>
                <tr>
                    <?php foreach ($fields as $field): ?>
                        <th><?php echo esc_html($labels[$field] ?? ucfirst($field)); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($contacts as $contact): ?>
                    <tr class="<?php echo $contact->is_primary ? 'is-primary' : ''; ?>">
                        <?php foreach ($fields as $field): ?>
                            <td>
                                <?php
                                $value = $this->get_contact_field($contact, $field);
                                if (!empty($value)) {
                                    if ($field === 'email' && $atts['link_email'] === 'true') {
                                        echo '<a href="mailto:' . esc_attr($value) . '">' . esc_html($value) . '</a>';
                                    } elseif (in_array($field, ['linkedin', 'twitter', 'website']) && $atts['link_social'] === 'true') {
                                        echo '<a href="' . esc_url($value) . '" target="_blank">' . ucfirst($field) . '</a>';
                                    } elseif ($field === 'name' && $contact->is_primary) {
                                        echo esc_html($value) . ' <span class="primary-badge">(Primary)</span>';
                                    } else {
                                        echo esc_html($value);
                                    }
                                }
                                ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        return ob_get_clean();
    }

    // ==================== INTERVIEW TRACKER SHORTCODES ====================

    /**
     * [guestify_interview_contacts entry_id="[id]"]
     * [guestify_interview_contacts podcast_id="5"]
     *
     * Displays contacts in the Interview Tracker view format with cards matching the existing CSS.
     * Designed to replace the hardcoded Contact tab HTML in Formidable Views.
     *
     * @param string entry_id - Formidable entry ID (use [id] in Formidable Views)
     * @param string podcast_id - Direct podcast ID (for standalone use)
     * @param string show_header - Whether to show the section header (default: true)
     * @param string show_add_button - Whether to show Add Contact button (default: true)
     * @param string show_notes - Whether to show the contact notes section (default: true)
     * @param string role - Filter by role (host/producer/guest/owner) or leave empty for all
     */
    public function interview_contacts($atts) {
        $atts = shortcode_atts([
            'podcast_id' => '',
            'entry_id' => '',
            'show_header' => 'true',
            'show_add_button' => 'true',
            'show_notes' => 'true',
            'role' => '',
        ], $atts);

        $contacts = $this->get_all_contacts($atts['entry_id'], $atts['podcast_id'], $atts['role'] ?: null);

        ob_start();
        ?>
        <?php if ($atts['show_header'] === 'true'): ?>
        <div class="section-header">
            <h2 class="section-heading">Contact Information</h2>
            <?php if ($atts['show_add_button'] === 'true'): ?>
            <button class="button outline-button small guestify-add-contact-btn" data-entry-id="<?php echo esc_attr($atts['entry_id']); ?>" data-podcast-id="<?php echo esc_attr($atts['podcast_id']); ?>">
                <svg class="button-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                Add Contact
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Contact Cards Grid -->
        <div class="contacts-grid">
            <?php if (empty($contacts)): ?>
                <div class="no-contacts-message">
                    <p>No contacts have been added yet.</p>
                    <?php if ($atts['show_add_button'] === 'true'): ?>
                    <p>Click "Add Contact" to add podcast contacts.</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($contacts as $contact): ?>
                    <?php echo $this->render_interview_contact_card($contact); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($atts['show_notes'] === 'true' && !empty($contacts)): ?>
        <div class="divider"></div>

        <div class="notes-section">
            <div class="notes-header">
                <h2 class="section-heading">Contact Notes</h2>
                <button class="button outline-button guestify-edit-notes-btn">
                    <svg class="button-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                    </svg>
                    Edit Notes
                </button>
            </div>

            <div class="notes-content">
                <?php
                // Collect notes from all contacts
                $notes = [];
                foreach ($contacts as $contact) {
                    if (!empty($contact->notes)) {
                        $notes[] = '<strong>' . esc_html($contact->full_name) . ':</strong> ' . esc_html($contact->notes);
                    }
                }
                if (!empty($notes)) {
                    echo '<p class="notes-text">' . implode('<br><br>', $notes) . '</p>';
                } else {
                    echo '<p class="notes-text empty">No contact notes yet.</p>';
                }
                ?>
            </div>
        </div>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    /**
     * Render a single contact card in Interview Tracker format
     */
    private function render_interview_contact_card($contact) {
        // Generate initials from name
        $initials = $this->get_initials($contact->full_name);

        // Determine if this is a team/group contact
        $is_team = (stripos($contact->full_name, 'team') !== false ||
                   stripos($contact->full_name, 'group') !== false ||
                   stripos($contact->full_name, 'department') !== false);

        ob_start();
        ?>
        <div class="contact-card" data-contact-id="<?php echo esc_attr($contact->id); ?>">
            <div class="contact-card-header">
                <div class="contact-avatar<?php echo $is_team ? ' team' : ''; ?>"><?php echo esc_html($initials); ?></div>
                <div>
                    <h3 class="contact-name"><?php echo esc_html($contact->full_name); ?></h3>
                    <?php if (!empty($contact->role)): ?>
                        <span class="contact-role"><?php echo esc_html(ucfirst($contact->role)); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="contact-details">
                <?php if (!empty($contact->email)): ?>
                <div class="contact-detail-item">
                    <svg class="contact-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                        <polyline points="22,6 12,13 2,6"></polyline>
                    </svg>
                    <a href="mailto:<?php echo esc_attr($contact->email); ?>" class="contact-detail-text"><?php echo esc_html($contact->email); ?></a>
                </div>
                <?php endif; ?>

                <?php if (!empty($contact->phone)): ?>
                <div class="contact-detail-item">
                    <svg class="contact-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
                    </svg>
                    <a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $contact->phone)); ?>" class="contact-detail-text"><?php echo esc_html($contact->phone); ?></a>
                </div>
                <?php endif; ?>

                <?php if (!empty($contact->website_url)): ?>
                <div class="contact-detail-item">
                    <svg class="contact-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="2" y1="12" x2="22" y2="12"></line>
                        <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
                    </svg>
                    <a href="<?php echo esc_url($contact->website_url); ?>" target="_blank" class="contact-detail-text"><?php echo esc_html(parse_url($contact->website_url, PHP_URL_HOST) ?: $contact->website_url); ?></a>
                </div>
                <?php endif; ?>

                <?php if (!empty($contact->linkedin_url)): ?>
                <div class="contact-detail-item">
                    <svg class="contact-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"></path>
                        <rect x="2" y="9" width="4" height="12"></rect>
                        <circle cx="4" cy="4" r="2"></circle>
                    </svg>
                    <a href="<?php echo esc_url($contact->linkedin_url); ?>" target="_blank" class="contact-detail-text">LinkedIn</a>
                </div>
                <?php endif; ?>

                <?php if (!empty($contact->twitter_url)): ?>
                <div class="contact-detail-item">
                    <svg class="contact-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M23 3a10.9 10.9 0 0 1-3.14 1.53 4.48 4.48 0 0 0-7.86 3v1A10.66 10.66 0 0 1 3 4s-4 9 5 13a11.64 11.64 0 0 1-7 2c9 5 20 0 20-11.5a4.5 4.5 0 0 0-.08-.83A7.72 7.72 0 0 0 23 3z"></path>
                    </svg>
                    <a href="<?php echo esc_url($contact->twitter_url); ?>" target="_blank" class="contact-detail-text">Twitter</a>
                </div>
                <?php endif; ?>

                <?php if (!empty($contact->company) || !empty($contact->title)): ?>
                <div class="contact-detail-item">
                    <svg class="contact-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                    </svg>
                    <span class="contact-detail-text">
                        <?php
                        $work_info = [];
                        if (!empty($contact->title)) $work_info[] = $contact->title;
                        if (!empty($contact->company)) $work_info[] = $contact->company;
                        echo esc_html(implode(' at ', $work_info));
                        ?>
                    </span>
                </div>
                <?php endif; ?>

                <?php if (!empty($contact->notes)): ?>
                <div class="contact-detail-item">
                    <svg class="contact-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                    </svg>
                    <span class="contact-detail-text"><?php echo esc_html($contact->notes); ?></span>
                </div>
                <?php endif; ?>

                <div class="contact-actions">
                    <?php if (!empty($contact->email)): ?>
                    <button class="contact-action-button" onclick="window.location.href='mailto:<?php echo esc_attr($contact->email); ?>'">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                            <polyline points="22,6 12,13 2,6"></polyline>
                        </svg>
                        Email
                    </button>
                    <?php endif; ?>
                    <button class="contact-action-button guestify-edit-contact-btn" data-contact-id="<?php echo esc_attr($contact->id); ?>">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                        </svg>
                        Edit
                    </button>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Generate initials from a name
     */
    private function get_initials($name) {
        if (empty($name)) return '??';

        $words = preg_split('/\s+/', trim($name));
        $initials = '';

        // Get first letter of first two words
        foreach (array_slice($words, 0, 2) as $word) {
            if (!empty($word)) {
                $initials .= strtoupper(mb_substr($word, 0, 1));
            }
        }

        return $initials ?: '??';
    }
}
