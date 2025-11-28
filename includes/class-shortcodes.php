<?php
/**
 * Shortcodes
 *
 * WordPress shortcodes for displaying podcast/guest data on frontend.
 * Uses public REST API endpoints for data fetching.
 *
 * @package PodcastInfluenceTracker
 * @subpackage Frontend
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Shortcodes {

    /**
     * Initialize shortcodes
     */
    public static function init() {
        add_shortcode('podcast_card', [__CLASS__, 'podcast_card']);
        add_shortcode('podcast_list', [__CLASS__, 'podcast_list']);
        add_shortcode('guest_card', [__CLASS__, 'guest_card']);
        add_shortcode('guest_list', [__CLASS__, 'guest_list']);
        add_shortcode('podcast_metrics', [__CLASS__, 'podcast_metrics']);
        add_shortcode('podcast_contacts', [__CLASS__, 'podcast_contacts']);

        // Enqueue frontend scripts/styles
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    /**
     * Enqueue frontend assets
     */
    public static function enqueue_assets() {
        global $post;

        // Only load if shortcodes are present
        if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'podcast_')) {
            if (!has_shortcode($post->post_content, 'guest_')) {
                return;
            }
        }

        wp_enqueue_style(
            'pit-frontend',
            PIT_PLUGIN_URL . 'assets/css/frontend.css',
            [],
            PIT_VERSION
        );

        wp_enqueue_script(
            'pit-frontend',
            PIT_PLUGIN_URL . 'assets/js/frontend.js',
            [],
            PIT_VERSION,
            true
        );

        wp_localize_script('pit-frontend', 'pitPublicData', [
            'apiUrl' => rest_url('podcast-influence/v1/public'),
        ]);
    }

    /**
     * Podcast card shortcode
     *
     * Usage: [podcast_card id="123"]
     * or: [podcast_card rss="https://example.com/feed"]
     */
    public static function podcast_card($atts) {
        $atts = shortcode_atts([
            'id' => 0,
            'rss' => '',
        ], $atts);

        $podcast = null;

        if ($atts['id']) {
            $podcast = PIT_Podcast_Repository::get((int) $atts['id']);
        } elseif ($atts['rss']) {
            global $wpdb;
            $table = $wpdb->prefix . 'pit_podcasts';
            $podcast = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE rss_feed_url = %s",
                $atts['rss']
            ));
        }

        if (!$podcast || !$podcast->is_tracked) {
            return '<div class="pit-error">Podcast not found</div>';
        }

        return self::render_podcast_card($podcast);
    }

    /**
     * Podcast list shortcode
     *
     * Usage: [podcast_list limit="10" category="Technology"]
     */
    public static function podcast_list($atts) {
        $atts = shortcode_atts([
            'limit' => 10,
            'category' => '',
            'search' => '',
        ], $atts);

        $args = [
            'per_page' => min((int) $atts['limit'], 50),
            'is_tracked' => 1,
        ];

        if ($atts['category']) {
            $args['category'] = $atts['category'];
        }

        if ($atts['search']) {
            $args['search'] = $atts['search'];
        }

        $podcasts = PIT_Podcast_Repository::find($args);

        if (empty($podcasts)) {
            return '<div class="pit-no-results">No podcasts found</div>';
        }

        $output = '<div class="pit-podcast-list">';
        foreach ($podcasts as $podcast) {
            $output .= self::render_podcast_card($podcast);
        }
        $output .= '</div>';

        return $output;
    }

    /**
     * Guest card shortcode
     *
     * Usage: [guest_card id="456"]
     */
    public static function guest_card($atts) {
        $atts = shortcode_atts([
            'id' => 0,
        ], $atts);

        if (!$atts['id']) {
            return '<div class="pit-error">Guest ID required</div>';
        }

        $guest = PIT_Guest_Repository::get((int) $atts['id']);

        if (!$guest) {
            return '<div class="pit-error">Guest not found</div>';
        }

        return self::render_guest_card($guest);
    }

    /**
     * Guest list shortcode
     *
     * Usage: [guest_list limit="10" verified="1"]
     */
    public static function guest_list($atts) {
        $atts = shortcode_atts([
            'limit' => 10,
            'verified' => '',
            'search' => '',
        ], $atts);

        $args = [
            'per_page' => min((int) $atts['limit'], 50),
        ];

        if ($atts['verified'] !== '') {
            $args['is_verified'] = (bool) $atts['verified'];
        }

        if ($atts['search']) {
            $args['search'] = $atts['search'];
        }

        $guests = PIT_Guest_Repository::find($args);

        if (empty($guests)) {
            return '<div class="pit-no-results">No guests found</div>';
        }

        $output = '<div class="pit-guest-list">';
        foreach ($guests as $guest) {
            $output .= self::render_guest_card($guest);
        }
        $output .= '</div>';

        return $output;
    }

    /**
     * Podcast metrics shortcode
     *
     * Usage: [podcast_metrics id="123"]
     */
    public static function podcast_metrics($atts) {
        $atts = shortcode_atts([
            'id' => 0,
        ], $atts);

        if (!$atts['id']) {
            return '<div class="pit-error">Podcast ID required</div>';
        }

        $podcast = PIT_Podcast_Repository::get((int) $atts['id']);

        if (!$podcast || !$podcast->is_tracked) {
            return '<div class="pit-error">Podcast not found</div>';
        }

        // Get social links and metrics
        $social_links = PIT_Social_Link_Repository::get_by_podcast($podcast->id);

        if (empty($social_links)) {
            return '<div class="pit-no-results">No metrics available</div>';
        }

        $output = '<div class="pit-podcast-metrics">';
        $output .= '<h3>' . esc_html($podcast->title) . ' - Social Metrics</h3>';
        $output .= '<div class="pit-metrics-grid">';

        foreach ($social_links as $link) {
            $latest = PIT_Metrics_Repository::get_latest($link->id);

            if ($latest) {
                $output .= '<div class="pit-metric-card pit-metric-' . esc_attr($link->platform) . '">';
                $output .= '<div class="pit-metric-platform">' . esc_html(ucfirst($link->platform)) . '</div>';

                if ($latest->followers) {
                    $output .= '<div class="pit-metric-stat">';
                    $output .= '<span class="pit-metric-label">Followers:</span> ';
                    $output .= '<span class="pit-metric-value">' . number_format($latest->followers) . '</span>';
                    $output .= '</div>';
                }

                if ($latest->subscribers) {
                    $output .= '<div class="pit-metric-stat">';
                    $output .= '<span class="pit-metric-label">Subscribers:</span> ';
                    $output .= '<span class="pit-metric-value">' . number_format($latest->subscribers) . '</span>';
                    $output .= '</div>';
                }

                if ($latest->views) {
                    $output .= '<div class="pit-metric-stat">';
                    $output .= '<span class="pit-metric-label">Views:</span> ';
                    $output .= '<span class="pit-metric-value">' . number_format($latest->views) . '</span>';
                    $output .= '</div>';
                }

                $output .= '</div>';
            }
        }

        $output .= '</div></div>';

        return $output;
    }

    /**
     * Render podcast card HTML
     */
    private static function render_podcast_card($podcast) {
        $output = '<div class="pit-podcast-card" data-podcast-id="' . esc_attr($podcast->id) . '">';

        if ($podcast->artwork_url) {
            $output .= '<div class="pit-podcast-artwork">';
            $output .= '<img src="' . esc_url($podcast->artwork_url) . '" alt="' . esc_attr($podcast->title) . '">';
            $output .= '</div>';
        }

        $output .= '<div class="pit-podcast-content">';
        $output .= '<h3 class="pit-podcast-title">' . esc_html($podcast->title) . '</h3>';

        if ($podcast->author) {
            $output .= '<div class="pit-podcast-author">By ' . esc_html($podcast->author) . '</div>';
        }

        if ($podcast->description) {
            $output .= '<div class="pit-podcast-description">' . esc_html(wp_trim_words($podcast->description, 30)) . '</div>';
        }

        if ($podcast->category) {
            $output .= '<div class="pit-podcast-category">' . esc_html($podcast->category) . '</div>';
        }

        if ($podcast->website_url) {
            $output .= '<a href="' . esc_url($podcast->website_url) . '" class="pit-podcast-link" target="_blank">Visit Website</a>';
        }

        $output .= '</div></div>';

        return $output;
    }

    /**
     * Render guest card HTML
     */
    private static function render_guest_card($guest) {
        $output = '<div class="pit-guest-card" data-guest-id="' . esc_attr($guest->id) . '">';

        if ($guest->photo_url) {
            $output .= '<div class="pit-guest-photo">';
            $output .= '<img src="' . esc_url($guest->photo_url) . '" alt="' . esc_attr($guest->full_name) . '">';
            $output .= '</div>';
        }

        $output .= '<div class="pit-guest-content">';
        $output .= '<h3 class="pit-guest-name">' . esc_html($guest->full_name);

        if ($guest->is_verified) {
            $output .= ' <span class="pit-verified-badge" title="Verified">✓</span>';
        }

        $output .= '</h3>';

        if ($guest->title && $guest->company) {
            $output .= '<div class="pit-guest-title">' . esc_html($guest->title) . ' at ' . esc_html($guest->company) . '</div>';
        } elseif ($guest->title) {
            $output .= '<div class="pit-guest-title">' . esc_html($guest->title) . '</div>';
        } elseif ($guest->company) {
            $output .= '<div class="pit-guest-company">' . esc_html($guest->company) . '</div>';
        }

        if ($guest->bio) {
            $output .= '<div class="pit-guest-bio">' . esc_html(wp_trim_words($guest->bio, 30)) . '</div>';
        }

        $output .= '<div class="pit-guest-social">';

        if ($guest->linkedin_url) {
            $output .= '<a href="' . esc_url($guest->linkedin_url) . '" class="pit-social-link pit-linkedin" target="_blank">LinkedIn</a>';
        }

        if ($guest->twitter_handle) {
            $output .= '<a href="https://twitter.com/' . esc_attr($guest->twitter_handle) . '" class="pit-social-link pit-twitter" target="_blank">Twitter</a>';
        }

        $output .= '</div></div></div>';

        return $output;
    }

    /**
     * Podcast contacts shortcode
     *
     * Displays contacts associated with a podcast.
     * Integrates with Formidable Forms RSS field.
     *
     * Usage: [podcast_contacts]
     *        [podcast_contacts rss="https://example.com/feed.xml"]
     *        [podcast_contacts podcast_id="123"]
     *        [podcast_contacts layout="cards|list|inline"]
     */
    public static function podcast_contacts($atts) {
        $atts = shortcode_atts([
            'rss'        => '',         // RSS feed URL
            'podcast_id' => 0,          // Direct podcast ID
            'layout'     => 'cards',    // cards, list, or inline
            'roles'      => '',         // Filter by roles (comma-separated)
            'limit'      => 10,         // Max contacts to show
        ], $atts);

        $podcast = null;

        // Method 1: Direct podcast ID
        if ($atts['podcast_id']) {
            $podcast = self::get_podcast_by_id((int) $atts['podcast_id']);
        }
        // Method 2: RSS feed URL provided directly
        elseif ($atts['rss']) {
            $podcast = self::get_podcast_by_rss($atts['rss']);
        }
        // Method 3: Check for Formidable Forms context (auto-detect RSS from entry)
        else {
            $podcast = self::get_podcast_from_formidable_context();
        }

        if (!$podcast) {
            return '<div class="pit-error">Podcast not found. Make sure the podcast is added to the Influence Tracker.</div>';
        }

        // Get contacts for this podcast
        $user_id = get_current_user_id();
        $contacts = [];
        
        if (class_exists('PIT_Contact_Repository')) {
            $contacts = PIT_Contact_Repository::get_for_podcast($podcast->id, $user_id);
        }

        if (empty($contacts)) {
            return '<div class="pit-no-contacts">No contacts found for this podcast.</div>';
        }

        // Filter by roles if specified
        if ($atts['roles']) {
            $allowed_roles = array_map('trim', explode(',', strtolower($atts['roles'])));
            $contacts = array_filter($contacts, function($contact) use ($allowed_roles) {
                return in_array(strtolower($contact->role), $allowed_roles);
            });
        }

        // Limit results
        $contacts = array_slice($contacts, 0, (int) $atts['limit']);

        // Render based on layout
        switch ($atts['layout']) {
            case 'list':
                return self::render_contacts_list($contacts, $podcast);
            case 'inline':
                return self::render_contacts_inline($contacts, $podcast);
            case 'cards':
            default:
                return self::render_contacts_cards($contacts, $podcast);
        }
    }

    /**
     * Get podcast by ID
     */
    private static function get_podcast_by_id($podcast_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcasts';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $podcast_id
        ));
    }

    /**
     * Get podcast by RSS feed URL
     */
    private static function get_podcast_by_rss($rss_url) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcasts';

        // Clean up the RSS URL
        $rss_url = trim($rss_url);

        // Try exact match first
        $podcast = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE rss_feed_url = %s",
            $rss_url
        ));

        // If not found, try with/without trailing slash
        if (!$podcast) {
            $alt_url = rtrim($rss_url, '/') === $rss_url
                ? $rss_url . '/'
                : rtrim($rss_url, '/');

            $podcast = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE rss_feed_url = %s",
                $alt_url
            ));
        }

        return $podcast;
    }

    /**
     * Get podcast from Formidable Forms context
     *
     * Attempts to find the podcast linked to the current Formidable entry.
     * Uses the pit_formidable_podcast_links table for the relationship.
     */
    private static function get_podcast_from_formidable_context() {
        // Check for entry_id in URL - multiple parameter names used by Formidable
        $entry_id = 0;
        
        // Check various URL parameter names
        $param_names = ['entry_id', 'entry', 'interid', 'id'];
        foreach ($param_names as $param) {
            if (isset($_GET[$param]) && absint($_GET[$param]) > 0) {
                $entry_id = absint($_GET[$param]);
                break;
            }
        }

        // Fallback: Check global $entry (set by Formidable in views)
        if (!$entry_id) {
            global $entry;
            if (isset($entry) && is_object($entry) && isset($entry->id)) {
                $entry_id = absint($entry->id);
            }
        }

        if (!$entry_id) {
            return null;
        }

        // Method 1: Use Formidable Integration link table (preferred)
        if (class_exists('PIT_Formidable_Integration')) {
            $podcast_id = PIT_Formidable_Integration::get_podcast_for_entry($entry_id);
            if ($podcast_id) {
                return self::get_podcast_by_id($podcast_id);
            }
        }

        // Method 2: Fallback - Look up RSS from field and find podcast
        if (class_exists('PIT_Settings')) {
            $settings = PIT_Settings::get_all();
            $rss_field_id = isset($settings['rss_field_id']) ? $settings['rss_field_id'] : '';

            if (!empty($rss_field_id)) {
                $rss_url = null;

                // Try to get RSS from Formidable entry
                if (class_exists('FrmProEntryMetaHelper')) {
                    $rss_url = FrmProEntryMetaHelper::get_post_or_meta_value($entry_id, $rss_field_id);
                } elseif (class_exists('FrmEntryMeta')) {
                    $rss_url = FrmEntryMeta::get_entry_meta_by_field($entry_id, $rss_field_id);
                }

                if ($rss_url) {
                    return self::get_podcast_by_rss($rss_url);
                }
            }
        }

        return null;
    }

    /**
     * Render contacts as cards
     */
    private static function render_contacts_cards($contacts, $podcast) {
        $output = '<div class="pit-contacts-grid">';

        foreach ($contacts as $contact) {
            $output .= '<div class="pit-contact-card">';

            // Avatar/initials
            $initials = self::get_initials($contact->full_name);
            $output .= '<div class="pit-contact-header">';
            $output .= '<div class="pit-contact-avatar">' . esc_html($initials) . '</div>';
            $output .= '<div class="pit-contact-info">';
            $output .= '<h4 class="pit-contact-name">' . esc_html($contact->full_name) . '</h4>';
            if ($contact->role) {
                $output .= '<span class="pit-contact-role">' . esc_html(ucfirst($contact->role)) . '</span>';
            }
            $output .= '</div></div>';

            // Contact details
            $output .= '<div class="pit-contact-details">';

            if ($contact->email) {
                $output .= '<div class="pit-contact-item">';
                $output .= '<a href="mailto:' . esc_attr($contact->email) . '">' . esc_html($contact->email) . '</a>';
                $output .= '</div>';
            }

            if ($contact->linkedin_url) {
                $output .= '<div class="pit-contact-item">';
                $output .= '<a href="' . esc_url($contact->linkedin_url) . '" target="_blank">LinkedIn</a>';
                $output .= '</div>';
            }

            if ($contact->twitter_url) {
                $output .= '<div class="pit-contact-item">';
                $output .= '<a href="' . esc_url($contact->twitter_url) . '" target="_blank">Twitter</a>';
                $output .= '</div>';
            }

            $output .= '</div></div>';
        }

        $output .= '</div>';
        return $output;
    }

    /**
     * Render contacts as list
     */
    private static function render_contacts_list($contacts, $podcast) {
        $output = '<ul class="pit-contacts-list">';

        foreach ($contacts as $contact) {
            $output .= '<li class="pit-contact-list-item">';
            $output .= '<strong>' . esc_html($contact->full_name) . '</strong>';
            if ($contact->role) {
                $output .= ' <span class="pit-contact-role">(' . esc_html(ucfirst($contact->role)) . ')</span>';
            }
            if ($contact->email) {
                $output .= ' - <a href="mailto:' . esc_attr($contact->email) . '">' . esc_html($contact->email) . '</a>';
            }
            $output .= '</li>';
        }

        $output .= '</ul>';
        return $output;
    }

    /**
     * Render contacts inline
     */
    private static function render_contacts_inline($contacts, $podcast) {
        $names = array_map(function($contact) {
            $name = esc_html($contact->full_name);
            if ($contact->email) {
                return '<a href="mailto:' . esc_attr($contact->email) . '">' . $name . '</a>';
            }
            return $name;
        }, $contacts);

        return '<span class="pit-contacts-inline">' . implode(', ', $names) . '</span>';
    }

    /**
     * Get initials from name
     */
    private static function get_initials($name) {
        $words = explode(' ', trim($name));
        $initials = '';
        foreach ($words as $word) {
            if (!empty($word)) {
                $initials .= strtoupper(substr($word, 0, 1));
            }
        }
        return substr($initials, 0, 2);
    }
}
