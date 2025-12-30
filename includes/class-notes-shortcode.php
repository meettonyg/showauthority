<?php
/**
 * Notes Dashboard Shortcode
 *
 * Renders the Vue.js Notes Dashboard showing all notes across appearances.
 * URL: /app/notes/
 *
 * @package Podcast_Influence_Tracker
 * @since 3.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Notes_Shortcode {

    /**
     * Initialize the shortcode
     */
    public static function init() {
        add_shortcode('guestify_notes', [__CLASS__, 'render']);
    }

    /**
     * Render the notes dashboard
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public static function render($atts) {
        if (!is_user_logged_in()) {
            return '<div class="pit-error"><p>' . esc_html__('Please log in to access notes.', 'podcast-influence-tracker') . '</p></div>';
        }

        $atts = shortcode_atts([
            'view'      => 'list',    // 'list', 'grid', 'widget'
            'note_type' => '',        // Filter by note type
            'limit'     => 50,        // Max items for widget view
        ], $atts);

        // Enqueue scripts and styles
        self::enqueue_assets($atts);

        ob_start();
        ?>
        <div id="notes-app"
             class="pit-notes-page"
             data-view="<?php echo esc_attr($atts['view']); ?>"
             data-note-type="<?php echo esc_attr($atts['note_type']); ?>"
             data-limit="<?php echo esc_attr($atts['limit']); ?>">
            <div class="pit-loading">
                <div class="pit-loading-spinner"></div>
                <p><?php esc_html_e('Loading notes...', 'podcast-influence-tracker'); ?></p>
            </div>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Enqueue required scripts and styles
     */
    private static function enqueue_assets($atts) {
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

        // Shared API utility
        wp_enqueue_script(
            'guestify-api',
            PIT_PLUGIN_URL . 'assets/js/shared/api.js',
            [],
            PIT_VERSION,
            true
        );

        // Notes App
        wp_enqueue_script(
            'pit-notes',
            PIT_PLUGIN_URL . 'assets/js/notes-vue.js',
            ['vue', 'pinia', 'guestify-api'],
            PIT_VERSION,
            true
        );

        // Notes CSS
        wp_enqueue_style(
            'pit-notes',
            PIT_PLUGIN_URL . 'assets/css/notes.css',
            [],
            PIT_VERSION
        );

        // Localize script data with translations
        wp_localize_script('pit-notes', 'pitNotesData', [
            'restUrl'            => rest_url('guestify/v1/'),
            'nonce'              => wp_create_nonce('wp_rest'),
            'userId'             => get_current_user_id(),
            'isAdmin'            => current_user_can('manage_options'),
            'interviewDetailUrl' => '/app/interview/detail/',
            'defaultView'        => $atts['view'],
            'defaultNoteType'    => $atts['note_type'],
            'defaultLimit'       => (int) $atts['limit'],
            'i18n'               => self::get_translations(),
        ]);
    }

    /**
     * Get translations for JavaScript
     */
    private static function get_translations() {
        return [
            // Header
            'notes'              => __('Notes', 'podcast-influence-tracker'),

            // Stats
            'allNotes'           => __('All Notes', 'podcast-influence-tracker'),
            'pinned'             => __('Pinned', 'podcast-influence-tracker'),

            // Note Types
            'general'            => __('General', 'podcast-influence-tracker'),
            'contact'            => __('Contact', 'podcast-influence-tracker'),
            'research'           => __('Research', 'podcast-influence-tracker'),
            'meeting'            => __('Meeting', 'podcast-influence-tracker'),
            'followUp'           => __('Follow Up', 'podcast-influence-tracker'),
            'pitch'              => __('Pitch', 'podcast-influence-tracker'),
            'feedback'           => __('Feedback', 'podcast-influence-tracker'),

            // Filters
            'searchNotes'        => __('Search notes...', 'podcast-influence-tracker'),
            'allTypes'           => __('All Types', 'podcast-influence-tracker'),
            'newestFirst'        => __('Newest First', 'podcast-influence-tracker'),
            'noteDate'           => __('Note Date', 'podcast-influence-tracker'),
            'title'              => __('Title', 'podcast-influence-tracker'),
            'clearFilters'       => __('Clear Filters', 'podcast-influence-tracker'),

            // Views
            'listView'           => __('List View', 'podcast-influence-tracker'),
            'gridView'           => __('Grid View', 'podcast-influence-tracker'),

            // States
            'loadingNotes'       => __('Loading notes...', 'podcast-influence-tracker'),
            'noNotesMatch'       => __('No notes match your filters.', 'podcast-influence-tracker'),
            'noNotesYet'         => __('No notes yet. Notes will appear here when you add them to your appearances.', 'podcast-influence-tracker'),
            'failedToLoad'       => __('Failed to load notes. Please try again.', 'podcast-influence-tracker'),
            'tryAgain'           => __('Try Again', 'podcast-influence-tracker'),
            'untitledNote'       => __('Untitled Note', 'podcast-influence-tracker'),

            // Pagination
            'previous'           => __('Previous', 'podcast-influence-tracker'),
            'next'               => __('Next', 'podcast-influence-tracker'),
            'pageOf'             => __('Page %1$d of %2$d', 'podcast-influence-tracker'),
        ];
    }
}
