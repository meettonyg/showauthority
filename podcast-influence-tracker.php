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
define('PIT_VERSION', '2.0.0');
define('PIT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PIT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PIT_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main Plugin Class
 *
 * Restructured for v2.0 with domain-based organization.
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
     * Organized by domain for better maintainability.
     */
    private function load_dependencies() {
        // ===========================================
        // CORE CLASSES
        // ===========================================
        require_once PIT_PLUGIN_DIR . 'includes/Core/class-database-schema.php';

        // Legacy database class (for backwards compatibility during migration)
        require_once PIT_PLUGIN_DIR . 'includes/class-database.php';

        // ===========================================
        // PODCASTS DOMAIN
        // ===========================================
        require_once PIT_PLUGIN_DIR . 'includes/Podcasts/class-podcast-repository.php';
        require_once PIT_PLUGIN_DIR . 'includes/Podcasts/class-content-analysis-repository.php';

        // Legacy discovery classes
        require_once PIT_PLUGIN_DIR . 'includes/layer-1/class-rss-parser.php';
        require_once PIT_PLUGIN_DIR . 'includes/layer-1/class-homepage-scraper.php';
        require_once PIT_PLUGIN_DIR . 'includes/layer-1/class-discovery-engine.php';

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

        // Legacy metrics fetcher
        require_once PIT_PLUGIN_DIR . 'includes/layer-2/class-metrics-fetcher.php';

        // ===========================================
        // JOBS DOMAIN
        // ===========================================
        require_once PIT_PLUGIN_DIR . 'includes/Jobs/class-job-repository.php';

        // Legacy job queue classes
        require_once PIT_PLUGIN_DIR . 'includes/layer-2/class-job-queue.php';
        require_once PIT_PLUGIN_DIR . 'includes/layer-3/class-background-refresh.php';

        // ===========================================
        // API INTEGRATIONS
        // ===========================================
        require_once PIT_PLUGIN_DIR . 'includes/integrations/class-youtube-api.php';
        require_once PIT_PLUGIN_DIR . 'includes/integrations/class-apify-client.php';
        require_once PIT_PLUGIN_DIR . 'includes/integrations/class-itunes-resolver.php';

        // ===========================================
        // REST API (New split controllers)
        // ===========================================
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-base.php';
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-podcasts.php';
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-guests.php';
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-export.php';

        // Legacy REST controller (for backwards compatibility)
        require_once PIT_PLUGIN_DIR . 'includes/api/class-rest-controller.php';

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
        // BRIDGES (Legacy integrations)
        // ===========================================
        if (file_exists(PIT_PLUGIN_DIR . 'includes/podcast-intelligence/class-podcast-intelligence-manager.php')) {
            require_once PIT_PLUGIN_DIR . 'includes/podcast-intelligence/class-podcast-intelligence-manager.php';
            require_once PIT_PLUGIN_DIR . 'includes/podcast-intelligence/class-formidable-podcast-bridge.php';
            require_once PIT_PLUGIN_DIR . 'includes/podcast-intelligence/class-email-integration.php';
            require_once PIT_PLUGIN_DIR . 'includes/podcast-intelligence/class-shortcodes.php';
            require_once PIT_PLUGIN_DIR . 'includes/podcast-intelligence/class-frontend-forms.php';
            require_once PIT_PLUGIN_DIR . 'includes/podcast-intelligence/class-rss-bridge.php';
        }
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
        // Create new unified database schema
        Database_Schema::create_tables();

        // Schedule cron job for background refresh
        if (!wp_next_scheduled('pit_background_refresh')) {
            wp_schedule_event(time(), 'weekly', 'pit_background_refresh');
        }

        // Schedule job processor
        if (!wp_next_scheduled('pit_process_jobs')) {
            wp_schedule_event(time(), 'every_minute', 'pit_process_jobs');
        }

        // Flush rewrite rules
        flush_rewrite_rules();

        // Set flag for migration notice
        if (get_option('pit_db_version') && version_compare(get_option('pit_db_version'), '2.0.0', '<')) {
            update_option('pit_needs_migration', true);
        }
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled cron jobs
        wp_clear_scheduled_hook('pit_background_refresh');
        wp_clear_scheduled_hook('pit_process_jobs');

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Load text domain
        load_plugin_textdomain('podcast-influence-tracker', false, dirname(PIT_PLUGIN_BASENAME) . '/languages');

        // Add custom cron schedules
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);

        // Initialize legacy bridges if available
        if (class_exists('PIT_Podcast_Intelligence_Manager')) {
            PIT_Podcast_Intelligence_Manager::get_instance();
        }
        if (class_exists('PIT_Formidable_Podcast_Bridge')) {
            PIT_Formidable_Podcast_Bridge::get_instance();
        }
        if (class_exists('PIT_Email_Integration')) {
            PIT_Email_Integration::get_instance();
        }
        if (class_exists('PIT_Shortcodes')) {
            PIT_Shortcodes::get_instance();
        }
        if (class_exists('PIT_Frontend_Forms')) {
            PIT_Frontend_Forms::get_instance();
        }
        if (class_exists('PIT_RSS_Bridge')) {
            PIT_RSS_Bridge::get_instance();
        }

        // Initialize admin components
        PIT_Admin_Page::init();
        PIT_Admin_Bulk_Tools::get_instance();

        // Initialize background jobs
        PIT_Background_Refresh::init();

        // Hook for job processing
        add_action('pit_process_jobs', ['PIT_Job_Queue', 'process_next_job']);
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        // New domain-based controllers
        PIT_REST_Podcasts::register_routes();
        PIT_REST_Guests::register_routes();
        PIT_REST_Export::register_routes();

        // Legacy controller (for backwards compatibility)
        PIT_REST_Controller::init();
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

        // Vue 3 and dependencies
        wp_enqueue_script(
            'pit-vue',
            'https://unpkg.com/vue@3/dist/vue.global.prod.js',
            [],
            '3.3.4',
            true
        );

        wp_enqueue_script(
            'pit-pinia',
            'https://unpkg.com/pinia@2/dist/pinia.iife.prod.js',
            ['pit-vue'],
            '2.1.6',
            true
        );

        // Admin app
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

        // Localize script
        wp_localize_script('pit-admin-app', 'pitData', [
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
 * Backwards compatibility aliases for class names
 *
 * These allow old code to continue working with new class names.
 */
class_alias('PIT_Podcast_Repository', 'PIT_Podcast_Discovery');
class_alias('PIT_Job_Repository', 'PIT_Job_Service');
class_alias('PIT_Appearance_Repository', 'PIT_Guest_Appearance');
class_alias('PIT_Social_Link_Repository', 'PIT_Social_Links');

/**
 * Initialize the plugin
 */
function pit_init() {
    return Podcast_Influence_Tracker::get_instance();
}

// Start the plugin
pit_init();
