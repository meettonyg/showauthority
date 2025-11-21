<?php
/**
 * Plugin Name: Podcast Influence Tracker
 * Plugin URI: https://github.com/meettonyg/showauthority
 * Description: Track and analyze social media influence metrics for podcasts with hybrid just-in-time strategy
 * Version: 1.0.0
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
define('PIT_VERSION', '1.0.0');
define('PIT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PIT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PIT_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main Plugin Class
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
     */
    private function load_dependencies() {
        // Database
        require_once PIT_PLUGIN_DIR . 'includes/class-database.php';

        // Podcast Intelligence System (NEW)
        require_once PIT_PLUGIN_DIR . 'includes/podcast-intelligence/class-podcast-intelligence-manager.php';
        require_once PIT_PLUGIN_DIR . 'includes/podcast-intelligence/class-formidable-podcast-bridge.php';
        require_once PIT_PLUGIN_DIR . 'includes/podcast-intelligence/class-email-integration.php';
        require_once PIT_PLUGIN_DIR . 'includes/podcast-intelligence/class-shortcodes.php';
        require_once PIT_PLUGIN_DIR . 'includes/podcast-intelligence/class-frontend-forms.php';
        require_once PIT_PLUGIN_DIR . 'includes/podcast-intelligence/class-rss-bridge.php';

        // Layer 1: Discovery Engine
        require_once PIT_PLUGIN_DIR . 'includes/layer-1/class-rss-parser.php';
        require_once PIT_PLUGIN_DIR . 'includes/layer-1/class-homepage-scraper.php';
        require_once PIT_PLUGIN_DIR . 'includes/layer-1/class-discovery-engine.php';

        // Layer 2: Job Queue System
        require_once PIT_PLUGIN_DIR . 'includes/layer-2/class-job-queue.php';
        require_once PIT_PLUGIN_DIR . 'includes/layer-2/class-metrics-fetcher.php';

        // Layer 3: Background Refresh
        require_once PIT_PLUGIN_DIR . 'includes/layer-3/class-background-refresh.php';

        // API Integrations
        require_once PIT_PLUGIN_DIR . 'includes/integrations/class-youtube-api.php';
        require_once PIT_PLUGIN_DIR . 'includes/integrations/class-apify-client.php';
        require_once PIT_PLUGIN_DIR . 'includes/integrations/class-itunes-resolver.php';

        // REST API
        require_once PIT_PLUGIN_DIR . 'includes/api/class-rest-controller.php';

        // Admin
        require_once PIT_PLUGIN_DIR . 'includes/admin/class-admin-page.php';
        require_once PIT_PLUGIN_DIR . 'includes/admin/class-settings.php';
        require_once PIT_PLUGIN_DIR . 'includes/admin/class-admin-bulk-tools.php';

        // Cost Management
        require_once PIT_PLUGIN_DIR . 'includes/class-cost-tracker.php';
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
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Create database tables
        PIT_Database::create_tables();

        // Schedule cron job for Layer 3
        if (!wp_next_scheduled('pit_background_refresh')) {
            wp_schedule_event(time(), 'weekly', 'pit_background_refresh');
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled cron
        wp_clear_scheduled_hook('pit_background_refresh');

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Load text domain
        load_plugin_textdomain('podcast-influence-tracker', false, dirname(PIT_PLUGIN_BASENAME) . '/languages');

        // Initialize Podcast Intelligence System
        PIT_Podcast_Intelligence_Manager::get_instance();
        PIT_Formidable_Podcast_Bridge::get_instance();
        PIT_Email_Integration::get_instance();
        PIT_Shortcodes::get_instance();
        PIT_Frontend_Forms::get_instance();
        PIT_RSS_Bridge::get_instance();

        // Initialize components
        PIT_REST_Controller::init();
        PIT_Admin_Page::init();
        PIT_Admin_Bulk_Tools::get_instance();
        PIT_Background_Refresh::init();
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
