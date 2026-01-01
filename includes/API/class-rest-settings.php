<?php
/**
 * REST API Settings Controller
 *
 * Handles settings GET/POST operations via REST API
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_REST_Settings {

    /**
     * Namespace
     */
    const NAMESPACE = 'podcast-influence/v1';

    /**
     * Register REST routes
     */
    public static function register_routes() {
        // GET /settings - Retrieve all settings
        register_rest_route(
            self::NAMESPACE,
            '/settings',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [__CLASS__, 'get_settings'],
                'permission_callback' => [__CLASS__, 'check_admin_permission'],
            ]
        );

        // POST /settings - Update settings
        register_rest_route(
            self::NAMESPACE,
            '/settings',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [__CLASS__, 'update_settings'],
                'permission_callback' => [__CLASS__, 'check_admin_permission'],
            ]
        );
    }

    /**
     * Check if user has admin permission
     *
     * @param WP_REST_Request $request
     * @return bool
     */
    public static function check_admin_permission($request) {
        return current_user_can('manage_options');
    }

    /**
     * Get all settings
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function get_settings($request) {
        $settings = PIT_Settings::get_all();
        return rest_ensure_response($settings);
    }

    /**
     * Update settings
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function update_settings($request) {
        $params = $request->get_json_params();

        if (empty($params)) {
            return new WP_Error(
                'invalid_params',
                __('No settings provided', 'podcast-influence-tracker'),
                ['status' => 400]
            );
        }

        // Sanitize settings before saving
        $sanitized = self::sanitize_settings($params);

        // Validate settings
        $validated = PIT_Settings::validate($sanitized);

        if (is_wp_error($validated)) {
            return $validated;
        }

        // Save settings
        $result = PIT_Settings::set_multiple($sanitized);

        if (!$result) {
            return new WP_Error(
                'save_failed',
                __('Failed to save settings', 'podcast-influence-tracker'),
                ['status' => 500]
            );
        }

        return rest_ensure_response([
            'success' => true,
            'message' => __('Settings saved successfully', 'podcast-influence-tracker'),
            'settings' => PIT_Settings::get_all(),
        ]);
    }

    /**
     * Sanitize settings before saving
     *
     * @param array $settings Raw settings
     * @return array Sanitized settings
     */
    private static function sanitize_settings($settings) {
        $sanitized = [];

        // API Keys
        if (isset($settings['youtube_api_key'])) {
            $sanitized['youtube_api_key'] = sanitize_text_field($settings['youtube_api_key']);
        }

        if (isset($settings['apify_api_token'])) {
            $sanitized['apify_api_token'] = sanitize_text_field($settings['apify_api_token']);
        }

        if (isset($settings['scrapingdog_api_key'])) {
            $sanitized['scrapingdog_api_key'] = sanitize_text_field($settings['scrapingdog_api_key']);
        }

        // Budgets
        if (isset($settings['weekly_budget'])) {
            $sanitized['weekly_budget'] = floatval($settings['weekly_budget']);
        }

        if (isset($settings['monthly_budget'])) {
            $sanitized['monthly_budget'] = floatval($settings['monthly_budget']);
        }

        // Tracking settings
        if (isset($settings['cache_duration'])) {
            $sanitized['cache_duration'] = absint($settings['cache_duration']);
        }

        if (isset($settings['auto_refresh'])) {
            $sanitized['auto_refresh'] = (bool) $settings['auto_refresh'];
        }

        if (isset($settings['default_platforms']) && is_array($settings['default_platforms'])) {
            $allowed_platforms = ['youtube', 'twitter', 'instagram', 'facebook', 'linkedin', 'tiktok', 'spotify', 'apple_podcasts'];
            $sanitized['default_platforms'] = array_intersect($settings['default_platforms'], $allowed_platforms);
        }

        // Auto-track on import
        if (isset($settings['auto_track_on_import'])) {
            $sanitized['auto_track_on_import'] = (bool) $settings['auto_track_on_import'];
        }

        // Refresh frequency
        if (isset($settings['refresh_frequency'])) {
            $allowed_frequencies = ['daily', 'weekly', 'monthly'];
            if (in_array($settings['refresh_frequency'], $allowed_frequencies)) {
                $sanitized['refresh_frequency'] = $settings['refresh_frequency'];
            }
        }

        // Notifications
        if (isset($settings['enable_notifications'])) {
            $sanitized['enable_notifications'] = (bool) $settings['enable_notifications'];
        }

        if (isset($settings['notification_email'])) {
            $sanitized['notification_email'] = sanitize_email($settings['notification_email']);
        }

        // Formidable Forms Integration
        if (isset($settings['tracker_form_id'])) {
            $sanitized['tracker_form_id'] = absint($settings['tracker_form_id']);
        }

        if (isset($settings['rss_field_id'])) {
            $sanitized['rss_field_id'] = sanitize_text_field($settings['rss_field_id']);
        }

        if (isset($settings['podcast_name_field_id'])) {
            $sanitized['podcast_name_field_id'] = sanitize_text_field($settings['podcast_name_field_id']);
        }

        if (isset($settings['formidable_auto_sync'])) {
            $sanitized['formidable_auto_sync'] = (bool) $settings['formidable_auto_sync'];
        }

        // Google Calendar Integration
        if (isset($settings['google_client_id'])) {
            $sanitized['google_client_id'] = sanitize_text_field($settings['google_client_id']);
        }

        if (isset($settings['google_client_secret'])) {
            $sanitized['google_client_secret'] = sanitize_text_field($settings['google_client_secret']);
        }

        // Microsoft Outlook Calendar Integration
        if (isset($settings['outlook_client_id'])) {
            $sanitized['outlook_client_id'] = sanitize_text_field($settings['outlook_client_id']);
        }

        if (isset($settings['outlook_client_secret'])) {
            $sanitized['outlook_client_secret'] = sanitize_text_field($settings['outlook_client_secret']);
        }

        return $sanitized;
    }
}
