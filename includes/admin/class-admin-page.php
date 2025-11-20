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
        ?>
        <div class="wrap">
            <h1><?php _e('Podcasts', 'podcast-influence-tracker'); ?></h1>

            <div id="pit-app-podcasts">
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
