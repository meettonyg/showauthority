<?php
/**
 * Interview Tracker Shortcode
 * 
 * Renders the Vue.js Interview Tracker (Kanban/Table views)
 * 
 * @package Podcast_Influence_Tracker
 * @since 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Interview_Tracker_Shortcode {

    /**
     * Initialize the shortcode
     */
    public static function init() {
        add_shortcode('guestify_interview_tracker', [__CLASS__, 'render']);
    }

    /**
     * Render the interview tracker
     *
     * @param array $atts Shortcode attributes
     *   - user_id: Filter by specific user ID (admin only). Default: current user
     */
    public static function render($atts) {
        if (!is_user_logged_in()) {
            return '<p>Please log in to access the Interview Tracker.</p>';
        }

        // Parse shortcode attributes
        $atts = shortcode_atts([
            'user_id' => get_current_user_id(),
        ], $atts, 'guestify_interview_tracker');

        // Only admins can view other users' data
        $user_id = (int) $atts['user_id'];
        if ($user_id !== get_current_user_id() && !current_user_can('manage_options')) {
            $user_id = get_current_user_id();
        }

        // Enqueue scripts
        self::enqueue_scripts($user_id);

        // Determine initial view from URL
        $current_url = $_SERVER['REQUEST_URI'] ?? '';
        $initial_view = 'kanban';
        if (strpos($current_url, '/list') !== false) {
            $initial_view = 'table';
        }

        ob_start();
        ?>
        <div id="interview-tracker-app" data-initial-view="<?php echo esc_attr($initial_view); ?>">
            <div class="pit-loading">
                <p>Loading Interview Tracker...</p>
            </div>
        </div>
        
        <style>
            /* Interview Tracker Container */
            .pit-interview-tracker {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                max-width: 1400px;
                margin: 0 auto;
                padding: 20px;
            }
            
            /* Search Toolbar (single row) */
            .pit-toolbar {
                background: white;
                border-radius: 12px;
                padding: 12px 16px;
                display: flex;
                align-items: center;
                gap: 12px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.08);
                margin-bottom: 16px;
                border: 1px solid #e2e8f0;
            }

            /* Filter Button (Prospector-style) */
            .pit-filter-btn {
                display: flex;
                align-items: center;
                gap: 8px;
                height: 44px;
                padding: 0 16px;
                font-size: 14px;
                font-weight: 500;
                color: #0ea5e9;
                background: white;
                border: 1px solid #0ea5e9;
                border-radius: 8px;
                cursor: pointer;
                transition: all 0.15s ease;
            }
            .pit-filter-btn:hover {
                background: #f0f9ff;
                border-color: #0284c7;
                color: #0284c7;
            }
            .pit-filter-btn.is-active {
                background: #f0f9ff;
                border-color: #0ea5e9;
                color: #0284c7;
            }
            .pit-filter-btn svg {
                flex-shrink: 0;
            }

            /* Filter Panel (expandable) - Prospector-style blue background */
            .pit-filter-panel {
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                padding: 20px;
                margin-bottom: 16px;
                animation: fadeSlideIn 0.2s ease-out;
            }
            @keyframes fadeSlideIn {
                from { opacity: 0; transform: translateY(-8px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .pit-filter-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 16px 24px;
            }
            @media (max-width: 1024px) {
                .pit-filter-grid {
                    grid-template-columns: repeat(2, 1fr);
                }
            }
            @media (max-width: 640px) {
                .pit-filter-grid {
                    grid-template-columns: 1fr;
                }
            }
            .pit-filter-field {
                display: flex;
                flex-direction: column;
                gap: 6px;
            }
            .pit-filter-label {
                font-size: 11px;
                font-weight: 500;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                color: #64748b;
            }
            .pit-filter-footer {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-top: 16px;
                padding-top: 16px;
                border-top: 1px solid #f1f5f9;
            }
            /* Reset Filters Button - Prospector outline-primary style */
            .pit-reset-filters {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 8px 14px;
                font-size: 13px;
                font-weight: 500;
                color: #0ea5e9;
                background: transparent;
                border: 1px solid #0ea5e9;
                border-radius: 6px;
                cursor: pointer;
                transition: all 0.15s ease;
            }
            .pit-reset-filters:hover {
                background: #f0f9ff;
                border-color: #0284c7;
                color: #0284c7;
            }
            .pit-reset-filters svg {
                width: 14px;
                height: 14px;
            }
            
            /* Search Wrapper with Icon */
            .pit-search-wrapper {
                position: relative;
                flex: 1;
                min-width: 250px;
            }
            .pit-search-icon {
                position: absolute;
                left: 14px;
                top: 50%;
                transform: translateY(-50%);
                color: #94a3b8;
                width: 20px;
                height: 20px;
                pointer-events: none;
                z-index: 1;
            }
            .pit-search-input {
                width: 100%;
                height: 44px;
                padding: 0 16px 0 44px !important;
                border: 1px solid #e2e8f0;
                border-radius: 8px;
                font-size: 14px;
                transition: all 0.15s ease;
                background: white;
                box-sizing: border-box;
            }
            .pit-search-input:focus {
                outline: none;
                border-color: #0ea5e9;
                box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
            }
            .pit-search-input::placeholder {
                color: #94a3b8;
            }
            
            /* Filter Group */
            .pit-filter-group {
                display: flex;
                align-items: center;
                gap: 8px;
            }
            
            /* Select Dropdowns */
            .pit-select {
                padding: 10px 36px 10px 12px;
                border: 1px solid #e2e8f0;
                border-radius: 8px;
                font-size: 14px;
                color: #475569;
                background: #f8fafc url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") no-repeat right 12px center;
                appearance: none;
                cursor: pointer;
                transition: all 0.2s;
                min-width: 130px;
            }
            .pit-select:focus {
                outline: none;
                border-color: #3b9edd;
                background-color: white;
                box-shadow: 0 0 0 3px rgba(59, 158, 221, 0.1);
            }
            .pit-select:hover {
                border-color: #cbd5e1;
            }
            
            /* Divider */
            .pit-divider {
                width: 1px;
                height: 32px;
                background: #e2e8f0;
                margin: 0 4px;
            }
            
            /* Checkbox Label */
            .pit-checkbox-label {
                display: flex;
                align-items: center;
                gap: 8px;
                font-size: 14px;
                color: #64748b;
                cursor: pointer;
                padding: 8px 12px;
                border-radius: 8px;
                transition: all 0.2s;
            }
            .pit-checkbox-label:hover {
                background: #f1f5f9;
            }
            .pit-checkbox-label input[type="checkbox"] {
                width: 16px;
                height: 16px;
                accent-color: #3b9edd;
                cursor: pointer;
            }
            
            /* Filter Submit Button */
            .pit-filter-submit {
                padding: 10px 20px;
                background: #3b9edd;
                border: none;
                border-radius: 8px;
                font-size: 14px;
                font-weight: 500;
                color: white;
                cursor: pointer;
                transition: all 0.2s;
            }
            .pit-filter-submit:hover {
                background: #2b8ecd;
            }
            
            /* View Toggle - Blue Style */
            .pit-view-toggle {
                display: inline-flex;
                gap: 0;
                background: #f1f5f9;
                border-radius: 8px;
                padding: 4px;
                overflow: hidden;
                margin-left: auto;
            }
            .pit-view-toggle button {
                padding: 8px 16px;
                border: none;
                background: transparent;
                border-radius: 6px;
                cursor: pointer;
                font-size: 13px;
                font-weight: 500;
                color: #64748b;
                transition: all 0.2s;
            }
            .pit-view-toggle button:hover {
                color: #1e3a5f;
            }
            .pit-view-toggle button.active {
                background: #3b9edd;
                color: white;
            }
            
            /* Kanban Board */
            .pit-kanban-board {
                margin-top: 0;
            }
            .pit-kanban-board > div {
                display: grid !important;
                grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
                gap: 16px !important;
                margin-bottom: 20px !important;
            }
            
            /* Kanban Column */
            .pit-kanban-column {
                background: #fff;
                border-radius: 8px;
                min-height: 300px;
                border: 1px solid #e5e7eb;
                border-top: 3px solid #5eead4;
                overflow: hidden;
            }
            .pit-column-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 12px 16px;
                background: #f8fafc;
                border-bottom: 1px solid #e5e7eb;
            }
            .pit-column-title {
                font-weight: 600;
                font-size: 15px;
                color: #1f2937;
            }
            .pit-column-count {
                font-size: 13px;
                color: #6b7280;
            }
            .pit-column-cards {
                padding: 12px;
                max-height: 500px;
                overflow-y: auto;
            }
            
            /* Kanban Card */
            .pit-kanban-card {
                background: white;
                border-radius: 6px;
                padding: 12px 14px;
                margin-bottom: 10px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.08);
                cursor: pointer;
                transition: box-shadow 0.2s, transform 0.2s;
                border: 1px solid #e5e7eb;
            }
            .pit-kanban-card:hover {
                box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                border-color: #3b82f6;
            }
            
            /* Drag and Drop Styles */
            .pit-kanban-card.dragging {
                opacity: 0.5;
                transform: rotate(2deg);
            }
            .pit-kanban-column.drag-over {
                background-color: rgba(59, 130, 246, 0.05);
            }
            
            /* Selection Bar */
            .pit-selection-bar {
                position: fixed;
                bottom: 20px;
                left: 50%;
                transform: translateX(-50%);
                background: #1f2937;
                color: white;
                padding: 12px 24px;
                border-radius: 8px;
                display: flex;
                align-items: center;
                gap: 16px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.3);
                z-index: 1000;
            }
            .pit-selection-bar button {
                background: #3b82f6;
                color: white;
                border: none;
                padding: 8px 16px;
                border-radius: 4px;
                cursor: pointer;
            }
            .pit-selection-bar button:hover {
                background: #2563eb;
            }
            .pit-selection-bar button.cancel {
                background: #6b7280;
            }
            
            /* Bulk Edit Panel */
            .pit-bulk-panel {
                position: fixed;
                top: 0;
                right: 0;
                width: 400px;
                height: 100vh;
                background: white;
                box-shadow: -4px 0 20px rgba(0,0,0,0.15);
                z-index: 1001;
                padding: 24px;
                overflow-y: auto;
            }
            .pit-bulk-panel h3 {
                margin-top: 0;
                margin-bottom: 24px;
            }
            .pit-bulk-panel .form-group {
                margin-bottom: 16px;
            }
            .pit-bulk-panel label {
                display: block;
                margin-bottom: 4px;
                font-weight: 500;
            }
            .pit-bulk-panel select,
            .pit-bulk-panel input {
                width: 100%;
                padding: 10px 14px;
                border: 1px solid #d1d5db;
                border-radius: 6px;
            }
            .pit-bulk-panel .actions {
                display: flex;
                gap: 12px;
                margin-top: 24px;
            }
            .pit-bulk-panel .btn-primary {
                background: #3b82f6;
                color: white;
                border: none;
                padding: 10px 20px;
                border-radius: 6px;
                cursor: pointer;
                flex: 1;
            }
            .pit-bulk-panel .btn-primary:hover {
                background: #2563eb;
            }
            .pit-bulk-panel .btn-secondary {
                background: #f3f4f6;
                color: #374151;
                border: 1px solid #d1d5db;
                padding: 10px 20px;
                border-radius: 6px;
                cursor: pointer;
            }
            
            /* Loading State */
            .pit-loading {
                text-align: center;
                padding: 60px 40px;
                color: #6b7280;
                background: #fff;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            
            /* Error State */
            .pit-error {
                background: #fef2f2;
                border: 1px solid #fecaca;
                color: #991b1b;
                padding: 16px;
                border-radius: 8px;
                margin-bottom: 16px;
            }
            
            /* Priority Indicators */
            .priority-high { border-left: 4px solid #ef4444; }
            .priority-medium { border-left: 4px solid #f59e0b; }
            .priority-low { border-left: 4px solid #10b981; }
            
            /* Table View */
            .pit-table-view {
                background: #fff;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                overflow: hidden;
            }
            .pit-table-view table {
                width: 100%;
                border-collapse: collapse;
            }
            .pit-table-view th {
                background: #f8fafc;
                text-align: left;
                padding: 14px 16px;
                font-weight: 600;
                font-size: 13px;
                color: #374151;
                border-bottom: 2px solid #e5e7eb;
            }
            .pit-table-view td {
                padding: 14px 16px;
                border-bottom: 1px solid #e5e7eb;
                font-size: 14px;
                color: #374151;
            }
            .pit-table-view tr:hover {
                background: #f8fafc;
            }
            
            /* Responsive */
            @media (max-width: 1200px) {
                .pit-kanban-board > div {
                    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
                }
            }
            @media (max-width: 900px) {
                .pit-toolbar {
                    flex-direction: column;
                    align-items: stretch;
                }
                .pit-toolbar-left {
                    flex-wrap: wrap;
                }
            }
            @media (max-width: 768px) {
                .pit-interview-tracker {
                    padding: 12px;
                }
                .pit-kanban-board > div {
                    grid-template-columns: minmax(0, 1fr) !important;
                }
                .pit-toolbar-left {
                    flex-direction: column;
                    align-items: stretch;
                }
                .pit-search-input,
                .pit-select {
                    width: 100%;
                    min-width: auto;
                }
            }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * Enqueue required scripts
     *
     * @param int $user_id The user ID to filter by
     */
    private static function enqueue_scripts($user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
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

        // Interview Tracker App
        wp_enqueue_script(
            'pit-interview-tracker',
            PIT_PLUGIN_URL . 'assets/js/interview-tracker-vue.js',
            ['vue', 'pinia', 'guestify-api'],
            PIT_VERSION,
            true
        );

        // Localize script data
        wp_localize_script('pit-interview-tracker', 'guestifyData', [
            'restUrl' => rest_url('guestify/v1/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'userId' => get_current_user_id(),
            'filterUserId' => $user_id,
            'isAdmin' => current_user_can('manage_options'),
        ]);
    }
}
