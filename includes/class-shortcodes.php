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
}
