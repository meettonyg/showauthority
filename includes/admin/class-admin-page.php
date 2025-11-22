<?php
/**
 * Admin Page
 *
 * Creates WordPress admin pages for the plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Admin_Page {

    /**
     * Initialize admin page
     */
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu_pages']);
    }

    /**
     * Add menu pages
     */
    public static function add_menu_pages() {
        // Main menu page
        add_menu_page(
            __('Podcast Influence Tracker', 'podcast-influence-tracker'),
            __('Podcast Influence', 'podcast-influence-tracker'),
            'manage_options',
            'podcast-influence',
            [__CLASS__, 'render_dashboard_page'],
            'dashicons-microphone',
            30
        );

        // Dashboard (duplicate of main)
        add_submenu_page(
            'podcast-influence',
            __('Dashboard', 'podcast-influence-tracker'),
            __('Dashboard', 'podcast-influence-tracker'),
            'manage_options',
            'podcast-influence',
            [__CLASS__, 'render_dashboard_page']
        );

        // Podcasts list
        add_submenu_page(
            'podcast-influence',
            __('Podcasts', 'podcast-influence-tracker'),
            __('Podcasts', 'podcast-influence-tracker'),
            'manage_options',
            'podcast-influence-podcasts',
            [__CLASS__, 'render_podcasts_page']
        );

        // Guests Directory
        add_submenu_page(
            'podcast-influence',
            __('Guests', 'podcast-influence-tracker'),
            __('Guests', 'podcast-influence-tracker'),
            'manage_options',
            'podcast-influence-guests',
            [__CLASS__, 'render_guests_page']
        );

        // Guest Tools (Deduplication & Verification)
        add_submenu_page(
            'podcast-influence',
            __('Guest Tools', 'podcast-influence-tracker'),
            __('Guest Tools', 'podcast-influence-tracker'),
            'manage_options',
            'podcast-influence-guest-tools',
            [__CLASS__, 'render_guest_tools_page']
        );

        // Network Intelligence
        add_submenu_page(
            'podcast-influence',
            __('Network', 'podcast-influence-tracker'),
            __('Network', 'podcast-influence-tracker'),
            'manage_options',
            'podcast-influence-network',
            [__CLASS__, 'render_network_page']
        );

        // Export Center
        add_submenu_page(
            'podcast-influence',
            __('Export', 'podcast-influence-tracker'),
            __('Export', 'podcast-influence-tracker'),
            'manage_options',
            'podcast-influence-export',
            [__CLASS__, 'render_export_page']
        );

        // Analytics
        add_submenu_page(
            'podcast-influence',
            __('Analytics', 'podcast-influence-tracker'),
            __('Analytics', 'podcast-influence-tracker'),
            'manage_options',
            'podcast-influence-analytics',
            [__CLASS__, 'render_analytics_page']
        );

        // Settings
        add_submenu_page(
            'podcast-influence',
            __('Settings', 'podcast-influence-tracker'),
            __('Settings', 'podcast-influence-tracker'),
            'manage_options',
            'podcast-influence-settings',
            [__CLASS__, 'render_settings_page']
        );
    }

    /**
     * Render dashboard page
     */
    public static function render_dashboard_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Podcast Influence Tracker - Dashboard', 'podcast-influence-tracker'); ?></h1>

            <div id="pit-app-dashboard">
                <p><?php _e('Loading...', 'podcast-influence-tracker'); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Render podcasts page
     */
    public static function render_podcasts_page() {
        $podcast_id = isset($_GET['podcast_id']) ? intval($_GET['podcast_id']) : 0;

        if ($podcast_id > 0) {
            // Show podcast detail view
            ?>
            <div class="wrap">
                <div id="pit-app-podcast-detail" data-podcast-id="<?php echo esc_attr($podcast_id); ?>">
                    <p><?php _e('Loading...', 'podcast-influence-tracker'); ?></p>
                </div>
            </div>
            <?php
        } else {
            // Show podcasts list
            ?>
            <div class="wrap">
                <h1><?php _e('Podcasts', 'podcast-influence-tracker'); ?></h1>

                <div id="pit-app-podcasts">
                    <p><?php _e('Loading...', 'podcast-influence-tracker'); ?></p>
                </div>
            </div>
            <?php
        }
    }

    /**
     * Render guests page
     */
    public static function render_guests_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Guest Directory', 'podcast-influence-tracker'); ?></h1>

            <div id="pit-app-guests">
                <p><?php _e('Loading...', 'podcast-influence-tracker'); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Render guest tools page (Deduplication & Verification)
     */
    public static function render_guest_tools_page() {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'deduplication';
        ?>
        <div class="wrap">
            <h1><?php _e('Guest Tools', 'podcast-influence-tracker'); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=podcast-influence-guest-tools&tab=deduplication"
                   class="nav-tab <?php echo $active_tab === 'deduplication' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Deduplication', 'podcast-influence-tracker'); ?>
                </a>
                <a href="?page=podcast-influence-guest-tools&tab=verification"
                   class="nav-tab <?php echo $active_tab === 'verification' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Verification', 'podcast-influence-tracker'); ?>
                </a>
            </nav>

            <?php if ($active_tab === 'deduplication') : ?>
                <div id="pit-app-deduplication" style="margin-top: 20px;">
                    <p><?php _e('Loading...', 'podcast-influence-tracker'); ?></p>
                </div>
            <?php else : ?>
                <div id="pit-app-verification" style="margin-top: 20px;">
                    <p><?php _e('Loading...', 'podcast-influence-tracker'); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render network intelligence page
     */
    public static function render_network_page() {
        ?>
        <div class="wrap">
            <div id="pit-app-network">
                <p><?php _e('Loading...', 'podcast-influence-tracker'); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Render export page
     */
    public static function render_export_page() {
        ?>
        <div class="wrap">
            <div id="pit-app-export">
                <p><?php _e('Loading...', 'podcast-influence-tracker'); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Render analytics page
     */
    public static function render_analytics_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Analytics & Costs', 'podcast-influence-tracker'); ?></h1>

            <div id="pit-app-analytics">
                <p><?php _e('Loading...', 'podcast-influence-tracker'); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Render settings page
     */
    public static function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Settings', 'podcast-influence-tracker'); ?></h1>

            <div id="pit-app-settings">
                <p><?php _e('Loading...', 'podcast-influence-tracker'); ?></p>
            </div>
        </div>
        <?php
    }
}
