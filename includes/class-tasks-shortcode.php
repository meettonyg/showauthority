<?php
/**
 * Tasks Dashboard Shortcode
 *
 * Renders the Vue.js Tasks Dashboard showing all tasks across appearances.
 * URL: /app/tasks/
 *
 * @package Podcast_Influence_Tracker
 * @since 3.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Tasks_Shortcode {

    /**
     * Initialize the shortcode
     */
    public static function init() {
        add_shortcode('guestify_tasks', [__CLASS__, 'render']);
    }

    /**
     * Render the tasks dashboard
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public static function render($atts) {
        if (!is_user_logged_in()) {
            return '<div class="pit-error"><p>Please log in to access tasks.</p></div>';
        }

        $atts = shortcode_atts([
            'view'   => 'list',    // 'list', 'board', 'widget'
            'status' => '',        // Filter by status
            'limit'  => 50,        // Max items for widget view
        ], $atts);

        // Enqueue scripts
        self::enqueue_scripts($atts);

        ob_start();
        ?>
        <div id="tasks-app"
             class="pit-tasks-page"
             data-view="<?php echo esc_attr($atts['view']); ?>"
             data-status="<?php echo esc_attr($atts['status']); ?>"
             data-limit="<?php echo esc_attr($atts['limit']); ?>">
            <div class="pit-loading">
                <div class="pit-loading-spinner"></div>
                <p>Loading tasks...</p>
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

        // Tasks App
        wp_enqueue_script(
            'pit-tasks',
            PIT_PLUGIN_URL . 'assets/js/tasks-vue.js',
            ['vue', 'pinia', 'guestify-api'],
            PIT_VERSION,
            true
        );

        // Localize script data
        wp_localize_script('pit-tasks', 'pitTasksData', [
            'restUrl'            => rest_url('guestify/v1/'),
            'nonce'              => wp_create_nonce('wp_rest'),
            'userId'             => get_current_user_id(),
            'isAdmin'            => current_user_can('manage_options'),
            'interviewDetailUrl' => '/app/interview/detail/',
            'defaultView'        => $atts['view'],
            'defaultStatus'      => $atts['status'],
            'defaultLimit'       => (int) $atts['limit'],
        ]);
    }

    /**
     * Render inline styles
     */
    private static function render_styles() {
        ?>
        <style>
            /* Tasks Dashboard Container */
            .pit-tasks-page {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                max-width: 1400px;
                margin: 0 auto;
                padding: 20px;
            }

            /* Header */
            .pit-tasks-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 24px;
            }
            .pit-tasks-header h1 {
                margin: 0;
                font-size: 24px;
                font-weight: 600;
                color: #1f2937;
            }

            /* Stats Cards */
            .pit-tasks-stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
                gap: 16px;
                margin-bottom: 24px;
            }
            .pit-stat-card {
                background: white;
                border-radius: 12px;
                padding: 16px;
                border: 1px solid #e2e8f0;
                text-align: center;
            }
            .pit-stat-card.overdue {
                border-color: #fecaca;
                background: #fef2f2;
            }
            .pit-stat-value {
                font-size: 28px;
                font-weight: 700;
                color: #1f2937;
            }
            .pit-stat-card.overdue .pit-stat-value {
                color: #dc2626;
            }
            .pit-stat-label {
                font-size: 13px;
                color: #6b7280;
                margin-top: 4px;
            }

            /* Toolbar */
            .pit-tasks-toolbar {
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

            /* Task List */
            .pit-tasks-list {
                background: white;
                border-radius: 12px;
                border: 1px solid #e2e8f0;
                overflow: hidden;
            }

            /* Task Item */
            .pit-task-item {
                display: flex;
                align-items: flex-start;
                gap: 12px;
                padding: 16px;
                border-bottom: 1px solid #f1f5f9;
                transition: background 0.15s;
            }
            .pit-task-item:last-child {
                border-bottom: none;
            }
            .pit-task-item:hover {
                background: #f8fafc;
            }
            .pit-task-item.completed {
                opacity: 0.6;
            }
            .pit-task-item.overdue {
                border-left: 3px solid #ef4444;
            }

            /* Checkbox */
            .pit-task-checkbox {
                width: 20px;
                height: 20px;
                border: 2px solid #d1d5db;
                border-radius: 50%;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
                margin-top: 2px;
                transition: all 0.15s;
            }
            .pit-task-checkbox:hover {
                border-color: #0ea5e9;
            }
            .pit-task-checkbox.checked {
                background: #10b981;
                border-color: #10b981;
            }
            .pit-task-checkbox.checked svg {
                color: white;
            }

            /* Task Content */
            .pit-task-content {
                flex: 1;
                min-width: 0;
            }
            .pit-task-title {
                font-size: 15px;
                font-weight: 500;
                color: #1f2937;
                margin-bottom: 4px;
            }
            .completed .pit-task-title {
                text-decoration: line-through;
                color: #9ca3af;
            }
            .pit-task-meta {
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
                font-size: 13px;
                color: #6b7280;
            }
            .pit-task-podcast {
                display: flex;
                align-items: center;
                gap: 6px;
            }
            .pit-task-podcast img {
                width: 20px;
                height: 20px;
                border-radius: 4px;
            }
            .pit-task-podcast a {
                color: #0ea5e9;
                text-decoration: none;
            }
            .pit-task-podcast a:hover {
                text-decoration: underline;
            }

            /* Priority Badge */
            .pit-priority-badge {
                display: inline-flex;
                align-items: center;
                padding: 2px 8px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
            }
            .pit-priority-badge.urgent {
                background: #fef2f2;
                color: #dc2626;
            }
            .pit-priority-badge.high {
                background: #fff7ed;
                color: #ea580c;
            }
            .pit-priority-badge.medium {
                background: #fefce8;
                color: #ca8a04;
            }
            .pit-priority-badge.low {
                background: #f0fdf4;
                color: #16a34a;
            }

            /* Due Date */
            .pit-task-due {
                display: flex;
                align-items: center;
                gap: 4px;
            }
            .pit-task-due.overdue {
                color: #dc2626;
                font-weight: 500;
            }
            .pit-task-due.today {
                color: #ea580c;
                font-weight: 500;
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
            .pit-pagination-info {
                font-size: 14px;
                color: #6b7280;
            }

            /* Responsive */
            @media (max-width: 768px) {
                .pit-tasks-page {
                    padding: 12px;
                }
                .pit-tasks-toolbar {
                    flex-direction: column;
                    align-items: stretch;
                }
                .pit-search-wrapper {
                    min-width: 100%;
                }
                .pit-select {
                    width: 100%;
                }
                .pit-tasks-stats {
                    grid-template-columns: repeat(2, 1fr);
                }
            }
        </style>
        <?php
    }
}
