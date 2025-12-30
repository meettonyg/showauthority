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
            return '<div class="pit-error"><p>Please log in to access notes.</p></div>';
        }

        $atts = shortcode_atts([
            'view'      => 'list',    // 'list', 'grid', 'widget'
            'note_type' => '',        // Filter by note type
            'limit'     => 50,        // Max items for widget view
        ], $atts);

        // Enqueue scripts
        self::enqueue_scripts($atts);

        ob_start();
        ?>
        <div id="notes-app"
             class="pit-notes-page"
             data-view="<?php echo esc_attr($atts['view']); ?>"
             data-note-type="<?php echo esc_attr($atts['note_type']); ?>"
             data-limit="<?php echo esc_attr($atts['limit']); ?>">
            <div class="pit-loading">
                <div class="pit-loading-spinner"></div>
                <p>Loading notes...</p>
            </div>
        </div>
        <?php
        self::render_styles();

        return ob_get_clean();
    }

    /**
     * Enqueue required scripts
     */
    private static function enqueue_scripts($atts) {
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

        // Localize script data
        wp_localize_script('pit-notes', 'pitNotesData', [
            'restUrl'            => rest_url('guestify/v1/'),
            'nonce'              => wp_create_nonce('wp_rest'),
            'userId'             => get_current_user_id(),
            'isAdmin'            => current_user_can('manage_options'),
            'interviewDetailUrl' => '/app/interview/detail/',
            'defaultView'        => $atts['view'],
            'defaultNoteType'    => $atts['note_type'],
            'defaultLimit'       => (int) $atts['limit'],
        ]);
    }

    /**
     * Render inline styles
     */
    private static function render_styles() {
        ?>
        <style>
            /* Notes Dashboard Container */
            .pit-notes-page {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                max-width: 1400px;
                margin: 0 auto;
                padding: 20px;
            }

            /* Header */
            .pit-notes-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 24px;
            }
            .pit-notes-header h1 {
                margin: 0;
                font-size: 24px;
                font-weight: 600;
                color: #1f2937;
            }

            /* Stats Cards */
            .pit-notes-stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
                gap: 12px;
                margin-bottom: 24px;
            }
            .pit-note-stat {
                background: white;
                border-radius: 12px;
                padding: 14px;
                border: 1px solid #e2e8f0;
                text-align: center;
                cursor: pointer;
                transition: all 0.15s;
            }
            .pit-note-stat:hover {
                border-color: #0ea5e9;
                box-shadow: 0 2px 8px rgba(14, 165, 233, 0.1);
            }
            .pit-note-stat.active {
                border-color: #0ea5e9;
                background: #f0f9ff;
            }
            .pit-note-stat-icon {
                width: 32px;
                height: 32px;
                border-radius: 8px;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 8px;
            }
            .pit-note-stat-value {
                font-size: 20px;
                font-weight: 700;
                color: #1f2937;
            }
            .pit-note-stat-label {
                font-size: 12px;
                color: #6b7280;
                margin-top: 2px;
            }

            /* Toolbar */
            .pit-notes-toolbar {
                background: white;
                border-radius: 12px;
                padding: 16px;
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 12px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.08);
                margin-bottom: 16px;
                border: 1px solid #e2e8f0;
            }

            /* Search */
            .pit-search-wrapper {
                position: relative;
                flex: 1;
                min-width: 200px;
            }
            .pit-search-icon {
                position: absolute;
                left: 12px;
                top: 50%;
                transform: translateY(-50%);
                color: #94a3b8;
                width: 18px;
                height: 18px;
            }
            .pit-search-input {
                width: 100%;
                height: 40px;
                padding: 0 12px 0 40px !important;
                border: 1px solid #e2e8f0;
                border-radius: 8px;
                font-size: 14px;
                background: #f8fafc;
            }
            .pit-search-input:focus {
                outline: none;
                border-color: #0ea5e9;
                background: white;
            }

            /* Select Dropdowns */
            .pit-select {
                height: 40px;
                padding: 0 32px 0 12px;
                border: 1px solid #e2e8f0;
                border-radius: 8px;
                font-size: 14px;
                background: #f8fafc url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") no-repeat right 10px center;
                appearance: none;
                cursor: pointer;
                min-width: 130px;
            }
            .pit-select:focus {
                outline: none;
                border-color: #0ea5e9;
                background-color: white;
            }

            /* View Toggle */
            .pit-view-toggle {
                display: flex;
                background: #f1f5f9;
                border-radius: 8px;
                padding: 4px;
            }
            .pit-view-toggle button {
                padding: 6px 12px;
                border: none;
                background: transparent;
                border-radius: 6px;
                cursor: pointer;
                color: #64748b;
                transition: all 0.15s;
            }
            .pit-view-toggle button.active {
                background: white;
                color: #1f2937;
                box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            }

            /* Notes List View */
            .pit-notes-list {
                background: white;
                border-radius: 12px;
                border: 1px solid #e2e8f0;
                overflow: hidden;
            }

            /* Note Item */
            .pit-note-item {
                display: flex;
                gap: 16px;
                padding: 16px;
                border-bottom: 1px solid #f1f5f9;
                transition: background 0.15s;
            }
            .pit-note-item:last-child {
                border-bottom: none;
            }
            .pit-note-item:hover {
                background: #f8fafc;
            }
            .pit-note-item.pinned {
                background: #fffbeb;
                border-left: 3px solid #f59e0b;
            }

            /* Note Type Icon */
            .pit-note-type-icon {
                width: 40px;
                height: 40px;
                border-radius: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }
            .pit-note-type-icon svg {
                width: 20px;
                height: 20px;
                color: white;
            }

            /* Note Content */
            .pit-note-content {
                flex: 1;
                min-width: 0;
            }
            .pit-note-header {
                display: flex;
                align-items: flex-start;
                gap: 8px;
                margin-bottom: 6px;
            }
            .pit-note-title {
                font-size: 15px;
                font-weight: 600;
                color: #1f2937;
                flex: 1;
            }
            .pit-note-pin {
                color: #f59e0b;
            }
            .pit-note-preview {
                font-size: 14px;
                color: #6b7280;
                line-height: 1.5;
                margin-bottom: 8px;
            }
            .pit-note-meta {
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
                font-size: 13px;
                color: #9ca3af;
            }
            .pit-note-podcast {
                display: flex;
                align-items: center;
                gap: 6px;
            }
            .pit-note-podcast img {
                width: 18px;
                height: 18px;
                border-radius: 4px;
            }
            .pit-note-podcast a {
                color: #0ea5e9;
                text-decoration: none;
            }
            .pit-note-podcast a:hover {
                text-decoration: underline;
            }

            /* Note Type Badge */
            .pit-note-type-badge {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                padding: 3px 8px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 500;
                text-transform: capitalize;
            }

            /* Notes Grid View */
            .pit-notes-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
                gap: 16px;
            }
            .pit-note-card {
                background: white;
                border-radius: 12px;
                border: 1px solid #e2e8f0;
                padding: 16px;
                transition: all 0.15s;
            }
            .pit-note-card:hover {
                border-color: #0ea5e9;
                box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            }
            .pit-note-card.pinned {
                border-color: #f59e0b;
                background: #fffbeb;
            }
            .pit-note-card-header {
                display: flex;
                align-items: flex-start;
                gap: 12px;
                margin-bottom: 12px;
            }
            .pit-note-card-content {
                font-size: 14px;
                color: #4b5563;
                line-height: 1.6;
                margin-bottom: 12px;
            }

            /* Empty State */
            .pit-empty-state {
                text-align: center;
                padding: 60px 20px;
                color: #6b7280;
            }
            .pit-empty-state svg {
                width: 48px;
                height: 48px;
                margin-bottom: 16px;
                opacity: 0.5;
            }

            /* Loading */
            .pit-loading {
                text-align: center;
                padding: 60px 20px;
            }
            .pit-loading-spinner {
                width: 32px;
                height: 32px;
                border: 3px solid #e2e8f0;
                border-top-color: #0ea5e9;
                border-radius: 50%;
                animation: spin 0.8s linear infinite;
                margin: 0 auto 16px;
            }
            @keyframes spin {
                to { transform: rotate(360deg); }
            }

            /* Pagination */
            .pit-pagination {
                display: flex;
                justify-content: center;
                align-items: center;
                gap: 8px;
                padding: 16px;
                border-top: 1px solid #f1f5f9;
            }
            .pit-pagination button {
                padding: 8px 16px;
                border: 1px solid #e2e8f0;
                border-radius: 6px;
                background: white;
                font-size: 14px;
                cursor: pointer;
                transition: all 0.15s;
            }
            .pit-pagination button:hover:not(:disabled) {
                border-color: #0ea5e9;
                color: #0ea5e9;
            }
            .pit-pagination button:disabled {
                opacity: 0.5;
                cursor: not-allowed;
            }

            /* Note Type Colors */
            .type-general { background-color: #6b7280; }
            .type-contact { background-color: #3b82f6; }
            .type-research { background-color: #8b5cf6; }
            .type-meeting { background-color: #10b981; }
            .type-follow_up { background-color: #f59e0b; }
            .type-pitch { background-color: #ec4899; }
            .type-feedback { background-color: #14b8a6; }

            .badge-general { background-color: #f3f4f6; color: #6b7280; }
            .badge-contact { background-color: #eff6ff; color: #3b82f6; }
            .badge-research { background-color: #f5f3ff; color: #8b5cf6; }
            .badge-meeting { background-color: #ecfdf5; color: #10b981; }
            .badge-follow_up { background-color: #fffbeb; color: #f59e0b; }
            .badge-pitch { background-color: #fdf2f8; color: #ec4899; }
            .badge-feedback { background-color: #f0fdfa; color: #14b8a6; }

            /* Responsive */
            @media (max-width: 768px) {
                .pit-notes-page {
                    padding: 12px;
                }
                .pit-notes-toolbar {
                    flex-direction: column;
                    align-items: stretch;
                }
                .pit-search-wrapper {
                    min-width: 100%;
                }
                .pit-select {
                    width: 100%;
                }
                .pit-notes-grid {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        <?php
    }
}
