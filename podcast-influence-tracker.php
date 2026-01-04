<?php
/**
 * Plugin Name: Podcast Influence Tracker
 * Plugin URI: https://github.com/meettonyg/showauthority
 * Description: Track and analyze social media influence metrics for podcasts with intelligent guest management
 * Version: 4.0.0
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
define('PIT_VERSION', '4.1.9');
define('PIT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PIT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PIT_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Load Composer autoloader if available (for web-push library)
$composer_autoload = PIT_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($composer_autoload)) {
    require_once $composer_autoload;
}

/**
 * Main Plugin Class
 *
 * Domain-based organization for v2.0.
 */
class Podcast_Influence_Tracker {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    private function load_dependencies() {
        // CORE
        require_once PIT_PLUGIN_DIR . 'includes/Core/class-database-schema.php';
        require_once PIT_PLUGIN_DIR . 'includes/Core/class-database.php';
        require_once PIT_PLUGIN_DIR . 'includes/Core/class-user-context.php';
        require_once PIT_PLUGIN_DIR . 'includes/Core/class-user-limits-repository.php';
        require_once PIT_PLUGIN_DIR . 'includes/Core/class-user-podcasts-repository.php';
        require_once PIT_PLUGIN_DIR . 'includes/Core/class-rate-limiter.php';
        require_once PIT_PLUGIN_DIR . 'includes/Core/class-pipeline-stage-repository.php';
        require_once PIT_PLUGIN_DIR . 'includes/Core/class-task-repository.php';

        // PODCASTS DOMAIN
        require_once PIT_PLUGIN_DIR . 'includes/Podcasts/class-podcast-repository.php';
        require_once PIT_PLUGIN_DIR . 'includes/Podcasts/class-contact-repository.php';
        require_once PIT_PLUGIN_DIR . 'includes/Podcasts/class-content-analysis-repository.php';
        require_once PIT_PLUGIN_DIR . 'includes/Podcasts/class-rss-parser.php';
        require_once PIT_PLUGIN_DIR . 'includes/Podcasts/class-homepage-scraper.php';
        require_once PIT_PLUGIN_DIR . 'includes/Podcasts/class-discovery-engine.php';

        // GUESTS DOMAIN
        require_once PIT_PLUGIN_DIR . 'includes/Guests/class-guest-repository.php';
        require_once PIT_PLUGIN_DIR . 'includes/Guests/class-appearance-repository.php';
        require_once PIT_PLUGIN_DIR . 'includes/Guests/class-topic-repository.php';
        require_once PIT_PLUGIN_DIR . 'includes/Guests/class-network-repository.php';
        require_once PIT_PLUGIN_DIR . 'includes/Guests/class-guest-merge-helper.php';
        require_once PIT_PLUGIN_DIR . 'includes/Guests/class-opportunity-repository.php';
        require_once PIT_PLUGIN_DIR . 'includes/Guests/class-private-contact-repository.php';
        require_once PIT_PLUGIN_DIR . 'includes/Guests/class-claim-request-repository.php';
        require_once PIT_PLUGIN_DIR . 'includes/Guests/class-appearance-tag-repository.php';

        // ENGAGEMENTS DOMAIN (v4.0)
        require_once PIT_PLUGIN_DIR . 'includes/Engagements/class-engagement-repository.php';
        require_once PIT_PLUGIN_DIR . 'includes/Engagements/class-speaking-credit-repository.php';

        // SOCIAL METRICS DOMAIN
        require_once PIT_PLUGIN_DIR . 'includes/SocialMetrics/class-social-link-repository.php';
        require_once PIT_PLUGIN_DIR . 'includes/SocialMetrics/class-metrics-repository.php';
        require_once PIT_PLUGIN_DIR . 'includes/SocialMetrics/class-metrics-fetcher.php';

        // JOBS DOMAIN
        require_once PIT_PLUGIN_DIR . 'includes/Jobs/class-job-repository.php';
        require_once PIT_PLUGIN_DIR . 'includes/Jobs/class-job-queue.php';
        require_once PIT_PLUGIN_DIR . 'includes/Jobs/class-background-refresh.php';

        // API INTEGRATIONS
        require_once PIT_PLUGIN_DIR . 'includes/integrations/class-youtube-api.php';
        require_once PIT_PLUGIN_DIR . 'includes/integrations/class-apify-client.php';
        require_once PIT_PLUGIN_DIR . 'includes/integrations/class-itunes-resolver.php';
        // DEPRECATED: Formidable integration removed in v4.1 - using Guest Intel direct import
        // require_once PIT_PLUGIN_DIR . 'includes/integrations/class-formidable-integration.php';

        // ENRICHMENT PROVIDERS (Abstract Interface)
        require_once PIT_PLUGIN_DIR . 'includes/integrations/enrichment/interface-enrichment-provider.php';
        require_once PIT_PLUGIN_DIR . 'includes/integrations/enrichment/class-enrichment-provider-base.php';
        require_once PIT_PLUGIN_DIR . 'includes/integrations/enrichment/class-scrapingdog-provider.php';
        require_once PIT_PLUGIN_DIR . 'includes/integrations/enrichment/class-apify-provider.php';
        require_once PIT_PLUGIN_DIR . 'includes/integrations/enrichment/class-enrichment-manager.php';

        // REST API
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-base.php';
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-podcasts.php';
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-guests.php';
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-export.php';
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-public.php';
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-settings.php';
        // DEPRECATED: require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-formidable.php'; // Removed in v4.1
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-metrics.php';

        // ADMIN
        require_once PIT_PLUGIN_DIR . 'includes/admin/class-admin-page.php';
        require_once PIT_PLUGIN_DIR . 'includes/admin/class-settings.php';
        require_once PIT_PLUGIN_DIR . 'includes/admin/class-admin-bulk-tools.php';
        require_once PIT_PLUGIN_DIR . 'includes/admin/class-admin-migration-v4.php';
        require_once PIT_PLUGIN_DIR . 'includes/admin/class-admin-notifications-test.php';

        // COST TRACKING
        require_once PIT_PLUGIN_DIR . 'includes/class-cost-tracker.php';

        // NOTIFICATION SETTINGS SHORTCODE (v4.2)
        require_once PIT_PLUGIN_DIR . 'includes/class-notification-settings-shortcode.php';

        // FRONTEND / SHORTCODES
        require_once PIT_PLUGIN_DIR . 'includes/class-shortcodes.php';

        // INTERVIEW TRACKER CRM (v3.0 + v3.1)
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-appearances.php';
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-appearance-tasks.php';
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-appearance-notes.php';
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-appearance-tags.php';
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-guest-profiles.php';
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-calendar-events.php';
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-pipeline-stages.php';

        // v4.0 CRM/INTELLIGENCE SEPARATION
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-engagements.php';
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-speaking-credits.php';
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-private-contacts.php';
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-claim-requests.php';

        // v4.1 PROSPECTOR INTEGRATION
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-episode-link.php';

        // v4.2 GUESTIFY OUTREACH BRIDGE (Email Integration)
        require_once PIT_PLUGIN_DIR . 'includes/integrations/class-guestify-outreach-bridge.php';
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-guestify-bridge.php';

        // v5.4.0 AI REFINEMENT (Email AI Features)
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-ai.php';

        // GLOBAL TASKS/NOTES API (v3.5)
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-global-tasks.php';
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-global-notes.php';

        // VUE SCRIPTS HELPER (v3.5)
        require_once PIT_PLUGIN_DIR . 'includes/class-vue-scripts.php';

        // MIGRATIONS
        require_once PIT_PLUGIN_DIR . 'includes/migrations/class-schema-migration-v4.php';
        require_once PIT_PLUGIN_DIR . 'includes/database/class-calendar-events-schema.php';
        require_once PIT_PLUGIN_DIR . 'includes/database/class-notifications-schema.php';
        require_once PIT_PLUGIN_DIR . 'includes/database/class-push-subscriptions-schema.php';
        require_once PIT_PLUGIN_DIR . 'includes/class-interview-tracker-shortcode.php';
        require_once PIT_PLUGIN_DIR . 'includes/class-interview-detail-shortcode.php';
        require_once PIT_PLUGIN_DIR . 'includes/class-calendar-shortcode.php';
        require_once PIT_PLUGIN_DIR . 'includes/class-tasks-shortcode.php';
        require_once PIT_PLUGIN_DIR . 'includes/class-notes-shortcode.php';

        // CALENDAR SYNC (v3.3 Google, v4.3 Outlook)
        require_once PIT_PLUGIN_DIR . 'includes/database/class-calendar-connections-schema.php';
        require_once PIT_PLUGIN_DIR . 'includes/integrations/class-google-calendar.php';
        require_once PIT_PLUGIN_DIR . 'includes/integrations/class-outlook-calendar.php';
        require_once PIT_PLUGIN_DIR . 'includes/integrations/class-calendar-sync-service.php';
        require_once PIT_PLUGIN_DIR . 'includes/integrations/class-appearance-calendar-sync.php';
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-calendar-sync.php';
        require_once PIT_PLUGIN_DIR . 'includes/Jobs/class-calendar-sync-job.php';

        // NOTIFICATIONS (v3.6+)
        require_once PIT_PLUGIN_DIR . 'includes/Jobs/class-notification-processor.php';
        require_once PIT_PLUGIN_DIR . 'includes/Jobs/class-web-push-sender.php';
        require_once PIT_PLUGIN_DIR . 'includes/API/class-rest-notifications.php';
    }

    private function init_hooks() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('init', [$this, 'init']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);

        // Notification settings shortcode (v4.2)
        PIT_Notification_Settings_Shortcode::init();

        // AJAX handlers for admin diagnostics
        add_action('wp_ajax_pit_enrichment_status', [$this, 'ajax_enrichment_status']);
        add_action('wp_ajax_pit_test_single_enrichment', [$this, 'ajax_test_single_enrichment']);
        add_action('wp_ajax_pit_provider_pricing', [$this, 'ajax_provider_pricing']);
    }

    public function activate() {
        Database_Schema::create_tables();

        // Create calendar-specific tables (v3.3+)
        PIT_Calendar_Events_Schema::create_table();
        PIT_Calendar_Connections_Schema::create_table();
        update_option('pit_calendar_tables_created', '1');

        // Create notifications table (v3.6+)
        PIT_Notifications_Schema::create_table();
        update_option('pit_notifications_table_created', '1');

        // Create push subscriptions table (v3.6+)
        PIT_Push_Subscriptions_Schema::create_table();
        update_option('pit_push_subscriptions_table_created', '1');


        if (!wp_next_scheduled('pit_background_refresh')) {
            wp_schedule_event(time(), 'weekly', 'pit_background_refresh');
        }
        if (!wp_next_scheduled('pit_process_jobs')) {
            wp_schedule_event(time(), 'every_minute', 'pit_process_jobs');
        }
        if (!wp_next_scheduled('pit_rate_limit_cleanup')) {
            wp_schedule_event(time(), 'hourly', 'pit_rate_limit_cleanup');
        }
        if (!wp_next_scheduled('pit_monthly_usage_reset')) {
            wp_schedule_event(time(), 'daily', 'pit_monthly_usage_reset');
        }
        if (!wp_next_scheduled('pit_process_notifications')) {
            wp_schedule_event(time(), 'every_fifteen_minutes', 'pit_process_notifications');
        }

        flush_rewrite_rules();
    }

    public function deactivate() {
        wp_clear_scheduled_hook('pit_background_refresh');
        wp_clear_scheduled_hook('pit_process_jobs');
        wp_clear_scheduled_hook('pit_rate_limit_cleanup');
        wp_clear_scheduled_hook('pit_monthly_usage_reset');
        wp_clear_scheduled_hook('pit_process_notifications');
        PIT_Calendar_Sync_Job::deactivate();
        // Reset calendar tables flag so they're checked on reactivation
        delete_option('pit_calendar_tables_created');
        delete_option('pit_notifications_table_created');
        flush_rewrite_rules();
    }

    public function init() {
        load_plugin_textdomain('podcast-influence-tracker', false, dirname(PIT_PLUGIN_BASENAME) . '/languages');

        if (Database_Schema::needs_migration()) {
            Database_Schema::migrate();
        }

        // Ensure calendar tables exist (v3.3+ - lazy creation if missing)
        // Use option flag to avoid running table checks on every page load
        if (get_option('pit_calendar_tables_created') !== '1') {
            if (!PIT_Calendar_Connections_Schema::table_exists()) {
                PIT_Calendar_Connections_Schema::create_table();
            }
            if (!PIT_Calendar_Events_Schema::table_exists()) {
                PIT_Calendar_Events_Schema::create_table();
            }
            update_option('pit_calendar_tables_created', '1');
        }

        // Ensure notifications table exists (v3.6+)
        if (get_option('pit_notifications_table_created') !== '1') {
            if (!PIT_Notifications_Schema::table_exists()) {
                PIT_Notifications_Schema::create_table();
            }
            update_option('pit_notifications_table_created', '1');
        }

        // Ensure push subscriptions table exists (v3.6+)
        if (get_option('pit_push_subscriptions_table_created') !== '1') {
            if (!PIT_Push_Subscriptions_Schema::table_exists()) {
                PIT_Push_Subscriptions_Schema::create_table();
            }
            update_option('pit_push_subscriptions_table_created', '1');
        }

        add_filter('cron_schedules', [$this, 'add_cron_schedules']);

        PIT_Admin_Page::init();
        PIT_Admin_Notifications_Test::init();
        PIT_Admin_Bulk_Tools::get_instance();
        // PIT_Admin_Migration_V4::init(); // Migration complete - uncomment if rollback needed
        PIT_Shortcodes::init();
        PIT_Background_Refresh::init();
        // DEPRECATED: PIT_Formidable_Integration::init(); // Removed in v4.1
        PIT_Interview_Tracker_Shortcode::init();
        PIT_Interview_Detail_Shortcode::init();
        PIT_Calendar_Shortcode::init();
        PIT_Tasks_Shortcode::init();
        PIT_Notes_Shortcode::init();
        PIT_Calendar_Sync_Job::init();
        PIT_Notification_Processor::init();

        add_action('pit_process_jobs', ['PIT_Job_Queue', 'process_next_job']);
        add_action('pit_rate_limit_cleanup', ['PIT_Rate_Limiter', 'cleanup']);
        add_action('pit_monthly_usage_reset', ['PIT_User_Limits_Repository', 'reset_monthly_usage']);
        add_action('pit_process_notifications', ['PIT_Notification_Processor', 'process']);
    }

    public function register_rest_routes() {
        PIT_REST_Podcasts::register_routes();
        PIT_REST_Guests::register_routes();
        PIT_REST_Export::register_routes();
        PIT_REST_Public::register_routes();
        PIT_REST_Settings::register_routes();
        // DEPRECATED: PIT_REST_Formidable::register_routes(); // Removed in v4.1
        PIT_REST_Metrics::register_routes();
        PIT_REST_Appearances::register_routes();
        PIT_REST_Appearance_Tasks::register_routes();
        PIT_REST_Appearance_Notes::register_routes();
        PIT_REST_Appearance_Tags::register_routes();
        PIT_REST_Calendar_Sync::register_routes();
        PIT_REST_Pipeline_Stages::register_routes();

        // v4.0 CRM/Intelligence Separation APIs
        PIT_REST_Engagements::register_routes();
        PIT_REST_Speaking_Credits::register_routes();
        PIT_REST_Private_Contacts::register_routes();
        PIT_REST_Claim_Requests::register_routes();

        // v4.1 Prospector Integration - Episode Linking
        PIT_REST_Episode_Link::register_routes();

        // v4.2 Guestify Outreach Bridge - Email Integration
        PIT_REST_Guestify_Bridge::register_routes();

        // v3.6 Notifications
        PIT_REST_Notifications::register_routes();
    }

    public function add_cron_schedules($schedules) {
        $schedules['every_minute'] = [
            'interval' => 60,
            'display' => __('Every Minute', 'podcast-influence-tracker'),
        ];
        $schedules['every_fifteen_minutes'] = [
            'interval' => 900,
            'display' => __('Every 15 Minutes', 'podcast-influence-tracker'),
        ];
        return $schedules;
    }

    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'podcast-influence') === false) {
            return;
        }

        wp_enqueue_script('pit-vue', 'https://unpkg.com/vue@3.3.4/dist/vue.global.prod.js', [], '3.3.4', false);
        wp_enqueue_script('pit-vue-demi', 'https://unpkg.com/vue-demi@0.14.6/lib/index.iife.js', ['pit-vue'], '0.14.6', false);
        wp_enqueue_script('pit-pinia', 'https://unpkg.com/pinia@2.1.7/dist/pinia.iife.js', ['pit-vue', 'pit-vue-demi'], '2.1.7', false);
        wp_enqueue_script('pit-admin-app', PIT_PLUGIN_URL . 'assets/js/admin-app.js', ['pit-vue', 'pit-pinia'], PIT_VERSION, true);
        wp_enqueue_style('pit-admin-styles', PIT_PLUGIN_URL . 'assets/css/admin-styles.css', [], PIT_VERSION);

        wp_localize_script('pit-vue', 'pitData', [
            'apiUrl' => rest_url('podcast-influence/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'ajaxNonce' => wp_create_nonce('pit_ajax_nonce'),
            'settings' => PIT_Settings::get_all(),
            'version' => PIT_VERSION,
            'siteUrl' => home_url(),
        ]);
    }

    public function enqueue_frontend_scripts() {
        // CSS is now enqueued conditionally by individual shortcode classes:
        // - PIT_Shortcodes::enqueue_assets() handles frontend.css for podcast_*/guest_* shortcodes
        // - PIT_Interview_Detail_Shortcode::enqueue_scripts() handles frontend.css, interview-detail.css, and interview-detail-modals.css
        // - PIT_Calendar_Shortcode::enqueue_scripts() handles calendar.css
        // This prevents CSS from loading on pages that don't use the plugin.
    }

    /**
     * AJAX: Get enrichment status for all platforms
     */
    public function ajax_enrichment_status() {
        if (!wp_verify_nonce($_POST['_ajax_nonce'] ?? '', 'pit_ajax_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        global $wpdb;

        $platforms = ['youtube', 'linkedin', 'twitter', 'instagram', 'facebook', 'tiktok', 'spotify', 'apple_podcasts'];
        $results = [];

        // Get pricing from Enrichment Manager if available
        $pricing = [];
        if (class_exists('PIT_Enrichment_Manager')) {
            $pricing = PIT_Enrichment_Manager::get_pricing_comparison();
        }

        foreach ($platforms as $platform) {
            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}pit_social_links WHERE platform = %s",
                $platform
            ));

            $enriched = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT s.id) 
                 FROM {$wpdb->prefix}pit_social_links s
                 INNER JOIN {$wpdb->prefix}pit_metrics m 
                     ON s.podcast_id = m.podcast_id AND s.platform = m.platform
                 WHERE s.platform = %s",
                $platform
            ));

            // Get cost from pricing comparison or use defaults
            $cost_per = 0;
            if (isset($pricing[$platform])) {
                foreach ($pricing[$platform] as $pname => $pdata) {
                    if (class_exists('PIT_Enrichment_Manager')) {
                        $prov = PIT_Enrichment_Manager::get_provider($pname);
                        if ($prov && $prov->is_configured()) {
                            $cost_per = $pdata['cost_per_profile'];
                            break;
                        }
                    }
                }
            }

            $remaining = $total - $enriched;
            $results[] = [
                'platform' => $platform,
                'total' => $total,
                'enriched' => $enriched,
                'remaining' => $remaining,
                'cost_estimate' => '$' . number_format($remaining * $cost_per, 2),
            ];
        }

        $total_remaining = array_sum(array_column($results, 'remaining'));
        $total_cost = 0;
        foreach ($results as $r) {
            $total_cost += (float) str_replace(['$', ','], '', $r['cost_estimate']);
        }

        $pending_jobs = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}pit_jobs WHERE status IN ('queued', 'processing')"
        );

        $weekly_spend = (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(cost_usd), 0) FROM {$wpdb->prefix}pit_cost_log 
             WHERE YEARWEEK(logged_at) = YEARWEEK(NOW())"
        );

        $credentials = [];
        if (class_exists('PIT_Enrichment_Manager')) {
            $credentials = PIT_Enrichment_Manager::validate_all_credentials();
        }

        wp_send_json_success([
            'platforms' => $results,
            'summary' => [
                'total_remaining' => $total_remaining,
                'total_cost_estimate' => '$' . number_format($total_cost, 2),
                'pending_jobs' => $pending_jobs,
                'weekly_spend' => '$' . number_format($weekly_spend, 2),
            ],
            'providers' => $credentials,
        ]);
    }

    /**
     * AJAX: Test single enrichment
     */
    public function ajax_test_single_enrichment() {
        if (!wp_verify_nonce($_POST['_ajax_nonce'] ?? '', 'pit_ajax_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        global $wpdb;

        $platform = sanitize_text_field($_POST['platform'] ?? 'linkedin');
        $provider = sanitize_text_field($_POST['provider'] ?? '');
        
        // Allow custom URL/handle for testing
        $test_url = sanitize_text_field($_POST['test_url'] ?? '');
        $test_handle = sanitize_text_field($_POST['test_handle'] ?? '');
        
        if ($test_url) {
            // Use custom test URL
            $link = (object) [
                'profile_url' => $test_url,
                'profile_handle' => $test_handle ?: '',
                'podcast_name' => 'Manual Test',
            ];
        } else {
            // Find an unenriched profile from database
            // Skip obviously bad URLs
            $link = $wpdb->get_row($wpdb->prepare(
                "SELECT s.*, p.title as podcast_name
                 FROM {$wpdb->prefix}pit_social_links s
                 LEFT JOIN {$wpdb->prefix}pit_podcasts p ON s.podcast_id = p.id
                 LEFT JOIN {$wpdb->prefix}pit_metrics m 
                     ON s.podcast_id = m.podcast_id AND s.platform = m.platform
                 WHERE s.platform = %s 
                   AND m.id IS NULL
                   AND s.profile_url NOT LIKE '%%/intent%%'
                   AND s.profile_handle != 'intent'
                   AND s.profile_handle IS NOT NULL
                   AND s.profile_handle != ''
                 LIMIT 1",
                $platform
            ));
        }

        if (!$link) {
            wp_send_json_error("No valid unenriched {$platform} profiles found");
        }

        // Use the Enrichment Manager
        $result = PIT_Enrichment_Manager::fetch_metrics(
            $platform,
            $link->profile_url,
            $link->profile_handle ?? '',
            $provider ?: null
        );

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'profile' => [
                    'podcast' => $link->podcast_name,
                    'url' => $link->profile_url,
                    'handle' => $link->profile_handle,
                ],
            ]);
        }

        // Return full result for debugging
        wp_send_json_success([
            'profile' => [
                'podcast' => $link->podcast_name,
                'url' => $link->profile_url,
                'handle' => $link->profile_handle,
            ],
            'metrics' => [
                'followers' => $result['followers'] ?? 0,
                'following' => $result['following'] ?? 0,
                'posts' => $result['posts'] ?? 0,
                'name' => $result['name'] ?? '',
                'headline' => $result['headline'] ?? '',
                'about' => $result['about'] ?? '',
                'location' => $result['location'] ?? '',
                'company' => $result['company'] ?? '',
            ],
            'raw_result' => $result, // DEBUG: Full result from provider
            'provider' => $result['provider'] ?? 'unknown',
            'cost' => '$' . number_format($result['cost'] ?? 0, 4),
        ]);
    }

    /**
     * AJAX: Get provider pricing comparison
     */
    public function ajax_provider_pricing() {
        if (!wp_verify_nonce($_POST['_ajax_nonce'] ?? '', 'pit_ajax_nonce')) {
            wp_send_json_error('Invalid nonce');
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        wp_send_json_success([
            'pricing' => PIT_Enrichment_Manager::get_pricing_comparison(),
            'credentials' => PIT_Enrichment_Manager::validate_all_credentials(),
            'platform_support' => PIT_Enrichment_Manager::get_platform_support(),
        ]);
    }
}

/**
 * Initialize the plugin
 */
function pit_init() {
    return Podcast_Influence_Tracker::get_instance();
}

pit_init();
