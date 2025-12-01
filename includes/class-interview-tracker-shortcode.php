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
            }
            
            /* Filter Bar */
            .pit-filter-bar {
                display: flex !important;
                flex-direction: row !important;
                flex-wrap: wrap !important;
                gap: 12px !important;
                margin-bottom: 16px !important;
                align-items: center !important;
            }
            .pit-filter-bar input[type="text"],
            .pit-filter-bar select {
                padding: 8px 12px;
                border: 1px solid #d1d5db;
                border-radius: 4px;
                font-size: 14px;
                min-width: 150px;
            }
            .pit-filter-bar label {
                display: flex;
                align-items: center;
                gap: 6px;
                font-size: 14px;
                white-space: nowrap;
            }
            
            /* Kanban Board */
            .pit-kanban-board > div {
                display: grid !important;
                grid-template-columns: repeat(4, 1fr) !important;
                gap: 12px !important;
                margin-bottom: 16px !important;
            }
            
            /* Kanban Column */
            .pit-kanban-column {
                background: #f3f4f6;
                border-radius: 8px;
                padding: 12px;
                min-height: 200px;
            }
            
            /* Kanban Card */
            .pit-kanban-card {
                background: white;
                border-radius: 8px;
                padding: 12px;
                margin-bottom: 8px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                cursor: pointer;
                transition: box-shadow 0.2s, transform 0.2s;
            }
            .pit-kanban-card:hover {
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                transform: translateY(-1px);
            }
            
            /* Drag and Drop Styles */
            .pit-kanban-card.dragging {
                opacity: 0.5;
                transform: rotate(2deg);
            }
            .pit-kanban-column.drag-over {
                background-color: rgba(59, 130, 246, 0.1);
                border: 2px dashed #3b82f6;
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
                padding: 8px 12px;
                border: 1px solid #d1d5db;
                border-radius: 4px;
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
                border-radius: 4px;
                cursor: pointer;
                flex: 1;
            }
            .pit-bulk-panel .btn-secondary {
                background: #f3f4f6;
                color: #374151;
                border: 1px solid #d1d5db;
                padding: 10px 20px;
                border-radius: 4px;
                cursor: pointer;
            }
            
            /* Loading State */
            .pit-loading {
                text-align: center;
                padding: 40px;
                color: #6b7280;
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
            
            /* View Toggle */
            .pit-view-toggle {
                display: inline-flex;
                gap: 0;
                margin-bottom: 16px;
                border: 1px solid #d1d5db;
                border-radius: 6px;
                overflow: hidden;
            }
            .pit-view-toggle button {
                padding: 8px 16px;
                border: none;
                background: white;
                cursor: pointer;
                font-size: 14px;
                border-right: 1px solid #d1d5db;
            }
            .pit-view-toggle button:last-child {
                border-right: none;
            }
            .pit-view-toggle button.active {
                background: #3b82f6;
                color: white;
            }
            
            /* Priority Indicators */
            .priority-high { border-left: 4px solid #ef4444; }
            .priority-medium { border-left: 4px solid #f59e0b; }
            .priority-low { border-left: 4px solid #10b981; }
            
            /* Table View */
            .pit-table-view table {
                width: 100%;
                border-collapse: collapse;
                background: white;
                border-radius: 8px;
                overflow: hidden;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            .pit-table-view th {
                background: #f9fafb;
                text-align: left;
                padding: 12px;
                font-weight: 600;
                font-size: 14px;
                border-bottom: 1px solid #e5e7eb;
            }
            .pit-table-view td {
                padding: 12px;
                border-bottom: 1px solid #e5e7eb;
                font-size: 14px;
            }
            .pit-table-view tr:hover {
                background: #f9fafb;
            }
            
            /* Responsive */
            @media (max-width: 1200px) {
                .pit-kanban-board > div {
                    grid-template-columns: repeat(2, 1fr) !important;
                }
            }
            @media (max-width: 768px) {
                .pit-kanban-board > div {
                    grid-template-columns: 1fr !important;
                }
                .pit-filter-bar {
                    flex-direction: column !important;
                    align-items: stretch !important;
                }
                .pit-filter-bar input[type="text"],
                .pit-filter-bar select {
                    width: 100%;
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

        // Interview Tracker App
        wp_enqueue_script(
            'pit-interview-tracker',
            PIT_PLUGIN_URL . 'assets/js/interview-tracker-vue.js',
            ['vue', 'pinia'],
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
