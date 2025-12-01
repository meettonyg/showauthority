<?php
/**
 * Interview Detail Page Shortcode
 * 
 * Renders the Vue.js Interview Detail Page for viewing/editing a single appearance.
 * URL: /app/interview/detail/?id={appearance_id}
 * 
 * @package Podcast_Influence_Tracker
 * @since 3.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Interview_Detail_Shortcode {

    /**
     * Initialize the shortcode
     */
    public static function init() {
        add_shortcode('guestify_interview_detail', [__CLASS__, 'render']);
    }

    /**
     * Render the interview detail page
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public static function render($atts) {
        if (!is_user_logged_in()) {
            return '<div class="pit-error"><p>Please log in to access interview details.</p></div>';
        }

        // Get interview ID from URL parameter
        $interview_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        
        if (!$interview_id) {
            return '<div class="pit-error"><p>No interview specified. <a href="/app/interview/board/">Return to Interview Tracker</a></p></div>';
        }

        // Verify access
        $access = self::verify_access($interview_id);
        if (!$access) {
            return '<div class="pit-error"><p>Interview not found or you don\'t have permission to view it. <a href="/app/interview/board/">Return to Interview Tracker</a></p></div>';
        }

        // Enqueue scripts
        self::enqueue_scripts($interview_id);

        ob_start();
        ?>
        <div id="interview-detail-app" data-interview-id="<?php echo esc_attr($interview_id); ?>">
            <div class="pit-loading">
                <div class="pit-loading-spinner"></div>
                <p>Loading interview details...</p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Verify user has access to this appearance
     */
    private static function verify_access($interview_id) {
        global $wpdb;
        
        $user_id = get_current_user_id();
        $table = $wpdb->prefix . 'pit_guest_appearances';
        
        // Admins can access all
        if (current_user_can('manage_options')) {
            return $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE id = %d",
                $interview_id
            ));
        }
        
        // Regular users can only access their own
        return $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d AND user_id = %d",
            $interview_id,
            $user_id
        ));
    }

    /**
     * Enqueue required scripts
     */
    private static function enqueue_scripts($interview_id) {
        // Vue 3
        wp_enqueue_script(
            'vue',
            'https://unpkg.com/vue@3.3.4/dist/vue.global.prod.js',
            [],
            '3.3.4',
            true
        );

        // Vue Demi (required for Pinia)
        wp_enqueue_script(
            'vue-demi',
            'https://unpkg.com/vue-demi@0.14.6/lib/index.iife.js',
            ['vue'],
            '0.14.6',
            true
        );

        // Pinia
        wp_enqueue_script(
            'pinia',
            'https://unpkg.com/pinia@2.1.7/dist/pinia.iife.js',
            ['vue', 'vue-demi'],
            '2.1.7',
            true
        );

        // Interview Detail App
        wp_enqueue_script(
            'pit-interview-detail',
            PIT_PLUGIN_URL . 'assets/js/interview-detail-vue.js',
            ['vue', 'pinia'],
            PIT_VERSION,
            true
        );

        // Localize script data
        wp_localize_script('pit-interview-detail', 'guestifyDetailData', [
            'restUrl' => rest_url('guestify/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'userId' => get_current_user_id(),
            'interviewId' => $interview_id,
            'boardUrl' => '/app/interview/board/',
            'listUrl' => '/app/interview/list/',
            'isAdmin' => current_user_can('manage_options'),
        ]);
    }
}
