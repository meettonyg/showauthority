<?php
/**
 * Settings Management
 *
 * Handles plugin settings and configuration
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Settings {

    const OPTION_KEY = 'pit_settings';

    /**
     * Default settings
     */
    private static $defaults = [
        'youtube_api_key' => '',
        'apify_api_token' => '',
        'scrapingdog_api_key' => '',
        'weekly_budget' => 50.00,
        'monthly_budget' => 200.00,
        'auto_track_on_import' => false,
        'refresh_frequency' => 'weekly',
        'enable_notifications' => true,
        'notification_email' => '',

        // Formidable Forms Integration
        'tracker_form_id' => 0,
        'rss_field_id' => '',
        'podcast_name_field_id' => '',
        'formidable_auto_sync' => true,
    ];

    /**
     * Get all settings
     *
     * @return array Settings
     */
    public static function get_all() {
        $settings = get_option(self::OPTION_KEY, []);

        return wp_parse_args($settings, self::$defaults);
    }

    /**
     * Get single setting
     *
     * @param string $key Setting key
     * @param mixed $default Default value
     * @return mixed Setting value
     */
    public static function get($key, $default = null) {
        $settings = self::get_all();

        if (isset($settings[$key])) {
            return $settings[$key];
        }

        return $default ?? self::$defaults[$key] ?? null;
    }

    /**
     * Set single setting
     *
     * @param string $key Setting key
     * @param mixed $value Setting value
     * @return bool Success
     */
    public static function set($key, $value) {
        $settings = self::get_all();
        $settings[$key] = $value;

        return update_option(self::OPTION_KEY, $settings);
    }

    /**
     * Set multiple settings
     *
     * @param array $settings Settings array
     * @return bool Success
     */
    public static function set_multiple($settings) {
        $current = self::get_all();
        $updated = array_merge($current, $settings);

        return update_option(self::OPTION_KEY, $updated);
    }

    /**
     * Delete setting
     *
     * @param string $key Setting key
     * @return bool Success
     */
    public static function delete($key) {
        $settings = self::get_all();
        unset($settings[$key]);

        return update_option(self::OPTION_KEY, $settings);
    }

    /**
     * Reset all settings to defaults
     *
     * @return bool Success
     */
    public static function reset() {
        return update_option(self::OPTION_KEY, self::$defaults);
    }

    /**
     * Validate settings
     *
     * @param array $settings Settings to validate
     * @return array|WP_Error Validated settings or error
     */
    public static function validate($settings) {
        $errors = [];

        // Validate YouTube API key if provided
        if (!empty($settings['youtube_api_key'])) {
            $result = PIT_YouTube_API::validate_api_key($settings['youtube_api_key']);
            if (is_wp_error($result)) {
                $errors['youtube_api_key'] = $result->get_error_message();
            }
        }

        // Validate Apify token if provided
        if (!empty($settings['apify_api_token'])) {
            $result = PIT_Apify_Client::validate_api_token($settings['apify_api_token']);
            if (is_wp_error($result)) {
                $errors['apify_api_token'] = $result->get_error_message();
            }
        }

        // Validate ScrapingDog API key if provided
        // Skip validation during save - it will be validated on first use
        // This avoids initialization order issues with PIT_Enrichment_Manager

        // Validate budgets
        if (isset($settings['weekly_budget'])) {
            $settings['weekly_budget'] = (float) $settings['weekly_budget'];
            if ($settings['weekly_budget'] < 0) {
                $errors['weekly_budget'] = 'Weekly budget must be positive';
            }
        }

        if (isset($settings['monthly_budget'])) {
            $settings['monthly_budget'] = (float) $settings['monthly_budget'];
            if ($settings['monthly_budget'] < 0) {
                $errors['monthly_budget'] = 'Monthly budget must be positive';
            }
        }

        // Validate email
        if (!empty($settings['notification_email'])) {
            if (!is_email($settings['notification_email'])) {
                $errors['notification_email'] = 'Invalid email address';
            }
        }

        if (!empty($errors)) {
            return new WP_Error('validation_failed', 'Settings validation failed', $errors);
        }

        return $settings;
    }

    /**
     * Get settings schema for frontend
     *
     * @return array Schema
     */
    public static function get_schema() {
        return [
            'youtube_api_key' => [
                'type' => 'string',
                'label' => 'YouTube API Key',
                'description' => 'Free API key from Google Cloud Console',
                'required' => false,
            ],
            'apify_api_token' => [
                'type' => 'string',
                'label' => 'Apify API Token',
                'description' => 'API token from Apify platform (fallback provider)',
                'required' => false,
            ],
            'scrapingdog_api_key' => [
                'type' => 'string',
                'label' => 'ScrapingDog API Key',
                'description' => 'API key from ScrapingDog (primary provider - get free trial at scrapingdog.com)',
                'required' => false,
            ],
            'weekly_budget' => [
                'type' => 'number',
                'label' => 'Weekly Budget (USD)',
                'description' => 'Maximum weekly spending on API calls',
                'min' => 0,
                'step' => 0.01,
                'default' => 50,
            ],
            'monthly_budget' => [
                'type' => 'number',
                'label' => 'Monthly Budget (USD)',
                'description' => 'Maximum monthly spending on API calls',
                'min' => 0,
                'step' => 0.01,
                'default' => 200,
            ],
            'auto_track_on_import' => [
                'type' => 'boolean',
                'label' => 'Auto-track on Import',
                'description' => 'Automatically track metrics when importing podcasts',
                'default' => false,
            ],
            'refresh_frequency' => [
                'type' => 'select',
                'label' => 'Refresh Frequency',
                'description' => 'How often to refresh tracked podcasts',
                'options' => [
                    'daily' => 'Daily',
                    'weekly' => 'Weekly',
                    'monthly' => 'Monthly',
                ],
                'default' => 'weekly',
            ],
            'enable_notifications' => [
                'type' => 'boolean',
                'label' => 'Enable Notifications',
                'description' => 'Send email notifications for important events',
                'default' => true,
            ],
            'notification_email' => [
                'type' => 'email',
                'label' => 'Notification Email',
                'description' => 'Email address for notifications',
                'default' => '',
            ],
        ];
    }
}
