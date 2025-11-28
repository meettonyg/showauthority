<?php
/**
 * Plugin Name: Podcast Influence Tracker
 * Plugin URI: https://github.com/meettonyg/showauthority
 * Description: Track and analyze social media influence metrics for podcasts with intelligent guest management
 * Version: 2.0.0
 * Author: Guestify
 * Author URI: https://guestify.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: podcast-influence-tracker
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('PIT_VERSION', '2.0.1');
define('PIT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PIT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PIT_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main Plugin Class
 *
 * Domain-based organization for v2.0.
 */
class Podcast_Influence_Tracker {

    /**
     * @var Podcast_Influence_Tracker Singleton instance
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load required dependencies
     *
     * Organized by domain for maintainability.
     */
    private function load_dependencies() {
        // ===========================================
        // CORE
        // ===========================================
        require_once PIT_PLUGIN_DIR . 'includes/Core/class-database-schema.php';

        // Multi-tenancy / SaaS Support
        require_once PIT_PLUGIN_DIR . 'includes/Core/class-user-context.php';
        require_once PIT_PLUGIN_DIR . 'includes/Core/class-user-limits-repository.php';
        require_once PIT_PLUGIN_DIR . 'includes/Core/class-user-podcasts-repository.php';
        require_once PIT_PLUGIN_DIR . 'includes/Core/class-rate-limiter.php';

        // ===========================================
        // PODCASTS DOMAIN
        // ===========================================
        require_once PIT_PLUGIN_DIR . 'includes/Podcasts/class-podcast-repository.php';
        require_once PIT_PLUGIN_DIR . 'includes/Podcasts/class-contact-repository.php';
        require_once PIT_PLUGIN_DIR . 'includes/Podcasts/class-content-analysis-repository.php';

        // Discovery Engine
        require_once PIT_PLUGIN_DIR . 'includes/Podcasts/class-rss-parser.php';
        require_once PIT_PLUGIN_DIR . 'includes/Podcasts/class-homepage-scraper.php';
        require_once PIT_PLUGIN_DIR . 'includes/Podcasts/class-discovery-engine.php';

        // ===========================================
        // GUESTS DOMAIN
        // ===========================================
        require_once PIT_PLUGIN_DIR . 'includes/Guests/class-guest-repository.php';
        require_once PIT_PLUGIN_DIR . 'includes/Guests/class-appearance-repository.php';
        require_once PIT_PLUGIN_DIR . 'includes/Guests/class-topic-repository.php';
        require_once PIT_PLUGIN_DIR . 'includes/Guests/class-network-repository.php';

        // ===========================================
        // SOCIAL METRICS DOMAIN
        // ===========================================
        require_once PIT_PLUGIN_DIR . 'includes/SocialMetrics/class-social-link-repository.php';
        require_once PIT_PLUGIN_DIR . 'includes/SocialMetrics/class-metrics-repository.php';
        require_once PIT_PLUGIN_DIR . 'includes/SocialMetrics/class-metrics-fetcher.php';

        // ===========================================
        // JOBS DOMAIN
        // ===========================================
        require_once PIT_PLUGIN_DIR . 'includes/Jobs/class-job-repository.php';
        require_once PIT_PLUGIN_DIR . 'includes/Jobs/class-job-queue.php';
        require_once PIT_PLUGIN_DIR . 'includes/Jobs/class-background-refresh.php';

        // ===========================================
        // API INTEGRATIONS
        // ===========================================
        require_once PIT_PLUGIN_DIR . 'includes/integrations/class-youtube-api.php';
        require_once PIT_PLUGIN_DIR . 'includes/integrations/class-apify-client.php';
        require_once PIT_PLUGIN_DIR . 'includes/integrations/class-itunes-resolver.php';
        require_once PIT_PLUGIN_DIR . 'includes/integrations/class-formidable-integration.php';

        // ===========================================
        // REST API
        // ===========================================
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-base.php';
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-podcasts.php';
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-guests.php';
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-export.php';
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-public.php';
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-settings.php';
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-formidable.php';

        // ===========================================
        // ADMIN
        // ===========================================
        require_once PIT_PLUGIN_DIR . 'includes/admin/class-admin-page.php';
        require_once PIT_PLUGIN_DIR . 'includes/admin/class-settings.php';
        require_once PIT_PLUGIN_DIR . 'includes/admin/class-admin-bulk-tools.php';

        // ===========================================
        // COST TRACKING
        // ===========================================
        require_once PIT_PLUGIN_DIR . 'includes/class-cost-tracker.php';

        // ===========================================
        // FRONTEND / SHORTCODES
        // ===========================================
        require_once PIT_PLUGIN_DIR . 'includes/class-shortcodes.php';
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Activation/Deactivation
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        // Init
        add_action('init', [$this, 'init']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Create database schema
        Database_Schema::create_tables();

        // Schedule cron job for background refresh
        if (!wp_next_scheduled('pit_background_refresh')) {
            wp_schedule_event(time(), 'weekly', 'pit_background_refresh');
        }

        // Schedule job processor
        if (!wp_next_scheduled('pit_process_jobs')) {
            wp_schedule_event(time(), 'every_minute', 'pit_process_jobs');
        }

        // Schedule rate limit cleanup (hourly)
        if (!wp_next_scheduled('pit_rate_limit_cleanup')) {
            wp_schedule_event(time(), 'hourly', 'pit_rate_limit_cleanup');
        }

        // Schedule monthly usage reset (daily check)
        if (!wp_next_scheduled('pit_monthly_usage_reset')) {
            wp_schedule_event(time(), 'daily', 'pit_monthly_usage_reset');
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled cron jobs
        wp_clear_scheduled_hook('pit_background_refresh');
        wp_clear_scheduled_hook('pit_process_jobs');
        wp_clear_scheduled_hook('pit_rate_limit_cleanup');
        wp_clear_scheduled_hook('pit_monthly_usage_reset');

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Load text domain
        load_plugin_textdomain('podcast-influence-tracker', false, dirname(PIT_PLUGIN_BASENAME) . '/languages');

        // Check for database migration
        if (Database_Schema::needs_migration()) {
            Database_Schema::migrate();
        }

        // Add custom cron schedules
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);

        // Initialize admin components
        PIT_Admin_Page::init();
        PIT_Admin_Bulk_Tools::get_instance();

        // Initialize frontend shortcodes
        PIT_Shortcodes::init();

        // Initialize background jobs
        PIT_Background_Refresh::init();

        // Initialize Formidable Forms integration
        PIT_Formidable_Integration::init();

        // Hook for job processing
        add_action('pit_process_jobs', ['PIT_Job_Queue', 'process_next_job']);

        // Hook for rate limit cleanup
        add_action('pit_rate_limit_cleanup', ['PIT_Rate_Limiter', 'cleanup']);

        // Hook for monthly usage reset
        add_action('pit_monthly_usage_reset', ['PIT_User_Limits_Repository', 'reset_monthly_usage']);
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        PIT_REST_Podcasts::register_routes();
        PIT_REST_Guests::register_routes();
        PIT_REST_Export::register_routes();
        PIT_REST_Public::register_routes();
        PIT_REST_Settings::register_routes();
        PIT_REST_Formidable::register_routes();
    }

    /**
     * Add custom cron schedules
     */
    public function add_cron_schedules($schedules) {
        $schedules['every_minute'] = [
            'interval' => 60,
            'display' => __('Every Minute', 'podcast-influence-tracker'),
        ];

        return $schedules;
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our admin pages
        if (strpos($hook, 'podcast-influence') === false) {
            return;
        }

        // Vue 3 - load in header to ensure it's available globally
        wp_enqueue_script(
            'pit-vue',
            'https://unpkg.com/vue@3.3.4/dist/vue.global.prod.js',
            [],
            '3.3.4',
            false // Load in header
        );

        // Vue Demi - required by Pinia for Vue 3 compatibility
        wp_enqueue_script(
            'pit-vue-demi',
            'https://unpkg.com/vue-demi@0.14.6/lib/index.iife.js',
            ['pit-vue'],
            '0.14.6',
            false // Load in header
        );

        // Pinia - depends on Vue and Vue Demi being loaded first
        wp_enqueue_script(
            'pit-pinia',
            'https://unpkg.com/pinia@2.1.7/dist/pinia.iife.js',
            ['pit-vue', 'pit-vue-demi'],
            '2.1.7',
            false // Load in header
        );

        // Admin app - load in footer after Vue and Pinia are ready
        wp_enqueue_script(
            'pit-admin-app',
            PIT_PLUGIN_URL . 'assets/js/admin-app.js',
            ['pit-vue', 'pit-pinia'],
            PIT_VERSION,
            true
        );

        // Styles
        wp_enqueue_style(
            'pit-admin-styles',
            PIT_PLUGIN_URL . 'assets/css/admin-styles.css',
            [],
            PIT_VERSION
        );

        // Localize script - attach to pit-vue so it's available before admin-app loads
        wp_localize_script('pit-vue', 'pitData', [
            'apiUrl' => rest_url('podcast-influence/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'settings' => PIT_Settings::get_all(),
            'version' => PIT_VERSION,
        ]);
    }

    /**
     * Enqueue frontend scripts
     */
    public function enqueue_frontend_scripts() {
        // Frontend scripts if needed
    }
}

/**
 * Initialize the plugin
 */
function pit_init() {
    return Podcast_Influence_Tracker::get_instance();
}

// Start the plugin
pit_init();
