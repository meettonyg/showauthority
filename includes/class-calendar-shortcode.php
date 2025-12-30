<?php
/**
 * Calendar Page Shortcode
 *
 * Renders the Vue.js Calendar Page with FullCalendar integration.
 * Features: Month/Week/Day views, list view, event filtering
 * URL: /app/calendar/
 *
 * @package Podcast_Influence_Tracker
 * @since 3.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Calendar_Shortcode {

    /**
     * Initialize the shortcode
     */
    public static function init() {
        add_shortcode('guestify_calendar', [__CLASS__, 'render']);
    }

    /**
     * Render the calendar page
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public static function render($atts) {
        if (!is_user_logged_in()) {
            return '<div class="pit-error"><p>Please log in to access the calendar.</p></div>';
        }

        $atts = shortcode_atts([
            'view' => 'full', // 'full', 'list', 'widget'
        ], $atts);

        // Enqueue scripts
        self::enqueue_scripts($atts['view']);

        ob_start();

        if ($atts['view'] === 'widget') {
            // Dashboard widget - compact view
            ?>
            <div id="calendar-widget-app" class="pit-calendar-widget">
                <div class="pit-loading">
                    <div class="pit-loading-spinner"></div>
                </div>
            </div>
            <?php
        } else {
            // Full calendar or list view
            ?>
            <div id="calendar-app" class="pit-calendar-page" data-view="<?php echo esc_attr($atts['view']); ?>">
                <div class="pit-loading">
                    <div class="pit-loading-spinner"></div>
                    <p>Loading calendar...</p>
                </div>
            </div>
            <?php
        }

        return ob_get_clean();
    }

    /**
     * Enqueue required scripts
     */
    private static function enqueue_scripts($view = 'full') {
        // Use centralized Vue/Pinia helper
        PIT_Vue_Scripts::enqueue();

        // FullCalendar (only for full view)
        if ($view === 'full') {
            $fullcalendar_url = self::get_fullcalendar_url();
            wp_enqueue_script(
                'fullcalendar',
                $fullcalendar_url,
                [],
                '6.1.10',
                true
            );
        }

        // Calendar App
        $script_handle = $view === 'widget' ? 'pit-calendar-widget' : 'pit-calendar';
        $script_file = $view === 'widget' ? 'calendar-widget-vue.js' : 'calendar-vue.js';

        $deps = $view === 'full'
            ? array_merge(PIT_Vue_Scripts::get_dependencies(), ['fullcalendar'])
            : PIT_Vue_Scripts::get_dependencies();

        wp_enqueue_script(
            $script_handle,
            PIT_PLUGIN_URL . 'assets/js/' . $script_file,
            $deps,
            PIT_VERSION,
            true
        );

        // Calendar CSS
        wp_enqueue_style(
            'pit-calendar',
            PIT_PLUGIN_URL . 'assets/css/calendar.css',
            [],
            PIT_VERSION
        );

        // Localize script data
        $localize_data = [
            'restUrl' => rest_url('pit/v1/'),
            'guestifyRestUrl' => rest_url('guestify/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'userId' => get_current_user_id(),
            'isAdmin' => current_user_can('manage_options'),
            'interviewDetailUrl' => '/app/interview/detail/',
            'eventTypes' => PIT_Calendar_Events_Schema::get_event_types(),
        ];

        wp_localize_script($script_handle, 'pitCalendarData', $localize_data);
    }

    /**
     * Get FullCalendar URL (local or CDN)
     * Tries local file first, then jsdelivr CDN as fallback
     */
    private static function get_fullcalendar_url() {
        $local_file = PIT_PLUGIN_DIR . 'assets/js/vendor/fullcalendar.global.min.js';
        if (file_exists($local_file)) {
            return PIT_PLUGIN_URL . 'assets/js/vendor/fullcalendar.global.min.js';
        }
        // Use jsdelivr CDN - FullCalendar 6.x uses this bundle format
        return 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js';
    }
}
