<?php
/**
 * REST API: Calendar Sync
 *
 * Handles OAuth callbacks and calendar connection management
 * for Google Calendar (and future Outlook) integration.
 *
 * @package Podcast_Influence_Tracker
 * @since 3.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_REST_Calendar_Sync {

    /**
     * Namespace for REST routes
     */
    const NAMESPACE = 'pit/v1';

    /**
     * Base route
     */
    const BASE = 'calendar-sync';

    /**
     * Register REST routes
     */
    public static function register_routes() {
        // GET /calendar-sync/status - Get connection status
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/status', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'get_status'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // GET /calendar-sync/google/auth - Get auth URL
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/google/auth', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'get_google_auth_url'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // GET /calendar-sync/google/callback - OAuth callback (no auth required)
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/google/callback', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'google_oauth_callback'],
            'permission_callback' => '__return_true',
        ]);

        // GET /calendar-sync/google/calendars - List user's calendars
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/google/calendars', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'get_google_calendars'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // POST /calendar-sync/google/select-calendar - Select calendar to sync
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/google/select-calendar', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [__CLASS__, 'select_google_calendar'],
            'permission_callback' => [__CLASS__, 'check_permission'],
            'args' => [
                'calendar_id' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'calendar_name' => [
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // POST /calendar-sync/google/disconnect - Disconnect Google Calendar
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/google/disconnect', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [__CLASS__, 'disconnect_google'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // POST /calendar-sync/google/sync - Trigger manual sync
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/google/sync', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [__CLASS__, 'trigger_sync'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // PATCH /calendar-sync/settings - Update sync settings
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/settings', [
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => [__CLASS__, 'update_settings'],
            'permission_callback' => [__CLASS__, 'check_permission'],
            'args' => [
                'sync_enabled' => [
                    'type' => 'boolean',
                ],
                'sync_direction' => [
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function ($value) {
                        return in_array($value, ['both', 'push', 'pull']);
                    },
                ],
            ],
        ]);

        // =====================
        // Outlook Calendar Routes
        // =====================

        // GET /calendar-sync/outlook/auth - Get Outlook auth URL
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/outlook/auth', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'get_outlook_auth_url'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // GET /calendar-sync/outlook/callback - OAuth callback (no auth required)
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/outlook/callback', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'outlook_oauth_callback'],
            'permission_callback' => '__return_true',
        ]);

        // GET /calendar-sync/outlook/calendars - List user's Outlook calendars
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/outlook/calendars', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'get_outlook_calendars'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // POST /calendar-sync/outlook/select-calendar - Select Outlook calendar to sync
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/outlook/select-calendar', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [__CLASS__, 'select_outlook_calendar'],
            'permission_callback' => [__CLASS__, 'check_permission'],
            'args' => [
                'calendar_id' => [
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'calendar_name' => [
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // POST /calendar-sync/outlook/disconnect - Disconnect Outlook Calendar
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/outlook/disconnect', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [__CLASS__, 'disconnect_outlook'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // POST /calendar-sync/outlook/sync - Trigger Outlook manual sync
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/outlook/sync', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [__CLASS__, 'trigger_outlook_sync'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);
    }

    /**
     * Check if user has permission
     */
    public static function check_permission() {
        return is_user_logged_in();
    }

    /**
     * Get connection status
     */
    public static function get_status($request) {
        $user_id = get_current_user_id();
        $google_connection = self::get_user_connection($user_id, 'google');
        $outlook_connection = self::get_user_connection($user_id, 'outlook');

        $google_configured = PIT_Google_Calendar::is_configured();
        $outlook_configured = PIT_Outlook_Calendar::is_configured();

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'google' => [
                    'configured' => $google_configured,
                    'connected' => !empty($google_connection),
                    'email' => $google_connection['provider_email'] ?? null,
                    'name' => $google_connection['provider_name'] ?? null,
                    'calendar_id' => $google_connection['calendar_id'] ?? null,
                    'calendar_name' => $google_connection['calendar_name'] ?? null,
                    'sync_enabled' => (bool)($google_connection['sync_enabled'] ?? false),
                    'sync_direction' => $google_connection['sync_direction'] ?? 'both',
                    'last_sync_at' => $google_connection['last_sync_at'] ?? null,
                    'sync_error' => $google_connection['sync_error'] ?? null,
                ],
                'outlook' => [
                    'configured' => $outlook_configured,
                    'connected' => !empty($outlook_connection),
                    'email' => $outlook_connection['provider_email'] ?? null,
                    'name' => $outlook_connection['provider_name'] ?? null,
                    'calendar_id' => $outlook_connection['calendar_id'] ?? null,
                    'calendar_name' => $outlook_connection['calendar_name'] ?? null,
                    'sync_enabled' => (bool)($outlook_connection['sync_enabled'] ?? false),
                    'sync_direction' => $outlook_connection['sync_direction'] ?? 'both',
                    'last_sync_at' => $outlook_connection['last_sync_at'] ?? null,
                    'sync_error' => $outlook_connection['sync_error'] ?? null,
                ],
            ],
        ]);
    }

    /**
     * Get Google auth URL
     */
    public static function get_google_auth_url($request) {
        if (!PIT_Google_Calendar::is_configured()) {
            return new WP_Error(
                'not_configured',
                'Google Calendar integration is not configured. Please contact the administrator.',
                ['status' => 400]
            );
        }

        $auth_url = PIT_Google_Calendar::get_auth_url();

        if (is_wp_error($auth_url)) {
            return $auth_url;
        }

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'auth_url' => $auth_url,
            ],
        ]);
    }

    /**
     * Handle Google OAuth callback
     */
    public static function google_oauth_callback($request) {
        $code = $request->get_param('code');
        $state = $request->get_param('state');
        $error = $request->get_param('error');

        // Handle OAuth errors
        if ($error) {
            return self::redirect_with_error('Google authorization was denied: ' . $error);
        }

        if (!$code || !$state) {
            return self::redirect_with_error('Invalid OAuth callback parameters');
        }

        // Verify state token
        $state_data = get_transient('pit_google_oauth_state_' . $state);
        delete_transient('pit_google_oauth_state_' . $state);

        if (!$state_data) {
            return self::redirect_with_error('Invalid or expired authorization state');
        }

        $user_id = $state_data['user_id'];

        // Exchange code for tokens
        $tokens = PIT_Google_Calendar::exchange_code($code);

        if (is_wp_error($tokens)) {
            return self::redirect_with_error('Failed to get access token: ' . $tokens->get_error_message());
        }

        // Get user info
        $user_info = PIT_Google_Calendar::get_user_info($tokens['access_token']);

        if (is_wp_error($user_info)) {
            return self::redirect_with_error('Failed to get user info: ' . $user_info->get_error_message());
        }

        // Save connection to database
        $saved = self::save_connection($user_id, 'google', [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_in' => $tokens['expires_in'],
            'email' => $user_info['email'],
            'name' => $user_info['name'],
        ]);

        if (!$saved) {
            return self::redirect_with_error('Failed to save connection');
        }

        // Redirect to calendar page with success
        return self::redirect_with_success('Google Calendar connected successfully!');
    }

    /**
     * Get user's Google calendars
     */
    public static function get_google_calendars($request) {
        $user_id = get_current_user_id();
        $access_token = self::get_valid_access_token($user_id, 'google');

        if (is_wp_error($access_token)) {
            return $access_token;
        }

        $calendars = PIT_Google_Calendar::get_calendars($access_token);

        if (is_wp_error($calendars)) {
            return $calendars;
        }

        return rest_ensure_response([
            'success' => true,
            'data' => $calendars,
        ]);
    }

    /**
     * Select Google calendar for sync
     */
    public static function select_google_calendar($request) {
        global $wpdb;

        $user_id = get_current_user_id();
        $calendar_id = $request->get_param('calendar_id');
        $calendar_name = $request->get_param('calendar_name') ?: 'Primary';

        $table = PIT_Calendar_Connections_Schema::get_table_name();

        $updated = $wpdb->update(
            $table,
            [
                'calendar_id' => $calendar_id,
                'calendar_name' => $calendar_name,
                'sync_enabled' => 1,
            ],
            [
                'user_id' => $user_id,
                'provider' => 'google',
            ]
        );

        if ($updated === false) {
            return new WP_Error('update_failed', 'Failed to update calendar selection', ['status' => 500]);
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'Calendar selected successfully',
            'data' => [
                'calendar_id' => $calendar_id,
                'calendar_name' => $calendar_name,
            ],
        ]);
    }

    /**
     * Disconnect Google Calendar
     */
    public static function disconnect_google($request) {
        global $wpdb;

        $user_id = get_current_user_id();
        $table = PIT_Calendar_Connections_Schema::get_table_name();

        // Delete the connection
        $deleted = $wpdb->delete($table, [
            'user_id' => $user_id,
            'provider' => 'google',
        ]);

        // Also clear google_event_id from user's events (keep local events)
        $events_table = PIT_Calendar_Events_Schema::get_table_name();
        $wpdb->query($wpdb->prepare(
            "UPDATE $events_table
             SET google_calendar_id = NULL,
                 google_event_id = NULL,
                 sync_status = 'local_only'
             WHERE user_id = %d",
            $user_id
        ));

        return rest_ensure_response([
            'success' => true,
            'message' => 'Google Calendar disconnected',
        ]);
    }

    /**
     * Trigger manual sync
     */
    public static function trigger_sync($request) {
        $user_id = get_current_user_id();

        $result = PIT_Calendar_Sync_Service::sync_user($user_id, 'google');

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'Sync completed',
            'data' => $result,
        ]);
    }

    /**
     * Update sync settings
     */
    public static function update_settings($request) {
        global $wpdb;

        $user_id = get_current_user_id();
        $table = PIT_Calendar_Connections_Schema::get_table_name();

        $data = [];

        if ($request->has_param('sync_enabled')) {
            $data['sync_enabled'] = $request->get_param('sync_enabled') ? 1 : 0;
        }

        if ($request->has_param('sync_direction')) {
            $data['sync_direction'] = $request->get_param('sync_direction');
        }

        if (empty($data)) {
            return new WP_Error('no_data', 'No settings to update', ['status' => 400]);
        }

        $updated = $wpdb->update(
            $table,
            $data,
            [
                'user_id' => $user_id,
                'provider' => 'google',
            ]
        );

        return rest_ensure_response([
            'success' => true,
            'message' => 'Settings updated',
        ]);
    }

    // =====================
    // Outlook Calendar Handlers
    // =====================

    /**
     * Get Outlook auth URL
     */
    public static function get_outlook_auth_url($request) {
        if (!PIT_Outlook_Calendar::is_configured()) {
            return new WP_Error(
                'not_configured',
                'Outlook Calendar integration is not configured. Please contact the administrator.',
                ['status' => 400]
            );
        }

        $auth_url = PIT_Outlook_Calendar::get_auth_url();

        if (is_wp_error($auth_url)) {
            return $auth_url;
        }

        return rest_ensure_response([
            'success' => true,
            'data' => [
                'auth_url' => $auth_url,
            ],
        ]);
    }

    /**
     * Handle Outlook OAuth callback
     */
    public static function outlook_oauth_callback($request) {
        $code = $request->get_param('code');
        $state = $request->get_param('state');
        $error = $request->get_param('error');

        // Handle OAuth errors
        if ($error) {
            return self::redirect_with_error('Outlook authorization was denied: ' . $error);
        }

        if (!$code || !$state) {
            return self::redirect_with_error('Invalid OAuth callback parameters');
        }

        // Verify state token
        $state_data = get_transient('pit_outlook_oauth_state_' . $state);
        delete_transient('pit_outlook_oauth_state_' . $state);

        if (!$state_data) {
            return self::redirect_with_error('Invalid or expired authorization state');
        }

        $user_id = $state_data['user_id'];

        // Exchange code for tokens
        $tokens = PIT_Outlook_Calendar::exchange_code($code);

        if (is_wp_error($tokens)) {
            return self::redirect_with_error('Failed to get access token: ' . $tokens->get_error_message());
        }

        // Get user info
        $user_info = PIT_Outlook_Calendar::get_user_info($tokens['access_token']);

        if (is_wp_error($user_info)) {
            return self::redirect_with_error('Failed to get user info: ' . $user_info->get_error_message());
        }

        // Save connection to database
        $saved = self::save_connection($user_id, 'outlook', [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_in' => $tokens['expires_in'],
            'email' => $user_info['email'],
            'name' => $user_info['name'],
        ]);

        if (!$saved) {
            return self::redirect_with_error('Failed to save connection');
        }

        // Redirect to calendar page with success
        return self::redirect_with_success('Outlook Calendar connected successfully!');
    }

    /**
     * Get user's Outlook calendars
     */
    public static function get_outlook_calendars($request) {
        $user_id = get_current_user_id();
        $access_token = self::get_valid_access_token($user_id, 'outlook');

        if (is_wp_error($access_token)) {
            return $access_token;
        }

        $calendars = PIT_Outlook_Calendar::get_calendars($access_token);

        if (is_wp_error($calendars)) {
            return $calendars;
        }

        return rest_ensure_response([
            'success' => true,
            'data' => $calendars,
        ]);
    }

    /**
     * Select Outlook calendar for sync
     */
    public static function select_outlook_calendar($request) {
        global $wpdb;

        $user_id = get_current_user_id();
        $calendar_id = $request->get_param('calendar_id');
        $calendar_name = $request->get_param('calendar_name') ?: 'Calendar';

        $table = PIT_Calendar_Connections_Schema::get_table_name();

        $updated = $wpdb->update(
            $table,
            [
                'calendar_id' => $calendar_id,
                'calendar_name' => $calendar_name,
                'sync_enabled' => 1,
            ],
            [
                'user_id' => $user_id,
                'provider' => 'outlook',
            ]
        );

        if ($updated === false) {
            return new WP_Error('update_failed', 'Failed to update calendar selection', ['status' => 500]);
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'Outlook calendar selected successfully',
            'data' => [
                'calendar_id' => $calendar_id,
                'calendar_name' => $calendar_name,
            ],
        ]);
    }

    /**
     * Disconnect Outlook Calendar
     */
    public static function disconnect_outlook($request) {
        global $wpdb;

        $user_id = get_current_user_id();
        $table = PIT_Calendar_Connections_Schema::get_table_name();

        // Delete the connection
        $wpdb->delete($table, [
            'user_id' => $user_id,
            'provider' => 'outlook',
        ]);

        // Also clear outlook_event_id from user's events (keep local events)
        $events_table = PIT_Calendar_Events_Schema::get_table_name();
        $wpdb->query($wpdb->prepare(
            "UPDATE $events_table
             SET outlook_calendar_id = NULL,
                 outlook_event_id = NULL,
                 sync_status = CASE WHEN google_event_id IS NULL THEN 'local_only' ELSE sync_status END
             WHERE user_id = %d",
            $user_id
        ));

        return rest_ensure_response([
            'success' => true,
            'message' => 'Outlook Calendar disconnected',
        ]);
    }

    /**
     * Trigger Outlook manual sync
     */
    public static function trigger_outlook_sync($request) {
        $user_id = get_current_user_id();

        $result = PIT_Calendar_Sync_Service::sync_user($user_id, 'outlook');

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'Outlook sync completed',
            'data' => $result,
        ]);
    }

    // =====================
    // Helper Methods
    // =====================

    /**
     * Get user's calendar connection
     */
    private static function get_user_connection($user_id, $provider) {
        global $wpdb;

        $table = PIT_Calendar_Connections_Schema::get_table_name();

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d AND provider = %s",
            $user_id,
            $provider
        ), ARRAY_A);
    }

    /**
     * Save or update user connection
     */
    private static function save_connection($user_id, $provider, $data) {
        global $wpdb;

        $table = PIT_Calendar_Connections_Schema::get_table_name();

        // Check if connection exists
        $existing = self::get_user_connection($user_id, $provider);

        $record = [
            'user_id' => $user_id,
            'provider' => $provider,
            'access_token' => self::encrypt_token($data['access_token']),
            'token_expires_at' => date('Y-m-d H:i:s', time() + $data['expires_in']),
            'provider_email' => $data['email'],
            'provider_name' => $data['name'],
            'connected_at' => current_time('mysql'),
        ];

        // Only store refresh token if provided (not on refresh)
        if (!empty($data['refresh_token'])) {
            $record['refresh_token'] = self::encrypt_token($data['refresh_token']);
        }

        if ($existing) {
            return $wpdb->update($table, $record, ['id' => $existing['id']]);
        } else {
            return $wpdb->insert($table, $record);
        }
    }

    /**
     * Get valid access token, refreshing if needed
     */
    public static function get_valid_access_token($user_id, $provider) {
        global $wpdb;

        $connection = self::get_user_connection($user_id, $provider);

        if (!$connection) {
            return new WP_Error('not_connected', 'Calendar not connected', ['status' => 401]);
        }

        $expires_at = strtotime($connection['token_expires_at']);

        // Refresh if expires in less than 5 minutes
        if ($expires_at < time() + 300) {
            $refresh_token = self::decrypt_token($connection['refresh_token']);

            if (!$refresh_token) {
                return new WP_Error('no_refresh_token', 'No refresh token available', ['status' => 401]);
            }

            // Use appropriate provider's refresh method
            if ($provider === 'outlook') {
                $new_tokens = PIT_Outlook_Calendar::refresh_token($refresh_token);
            } else {
                $new_tokens = PIT_Google_Calendar::refresh_token($refresh_token);
            }

            if (is_wp_error($new_tokens)) {
                // Mark connection as having an error
                $table = PIT_Calendar_Connections_Schema::get_table_name();
                $wpdb->update(
                    $table,
                    ['sync_error' => 'Token refresh failed: ' . $new_tokens->get_error_message()],
                    ['id' => $connection['id']]
                );
                return $new_tokens;
            }

            // Update stored token (Outlook may return new refresh token)
            $update_data = [
                'access_token' => self::encrypt_token($new_tokens['access_token']),
                'token_expires_at' => date('Y-m-d H:i:s', time() + $new_tokens['expires_in']),
                'sync_error' => null,
            ];

            // Outlook returns a new refresh token on each refresh
            if (!empty($new_tokens['refresh_token'])) {
                $update_data['refresh_token'] = self::encrypt_token($new_tokens['refresh_token']);
            }

            $table = PIT_Calendar_Connections_Schema::get_table_name();
            $wpdb->update($table, $update_data, ['id' => $connection['id']]);

            return $new_tokens['access_token'];
        }

        return self::decrypt_token($connection['access_token']);
    }

    /**
     * Encrypt token for storage
     */
    private static function encrypt_token($token) {
        // Use WordPress salts for encryption key
        $key = wp_salt('auth');
        $iv = substr(wp_salt('secure_auth'), 0, 16);

        $encrypted = openssl_encrypt($token, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($encrypted);
    }

    /**
     * Decrypt token from storage
     */
    private static function decrypt_token($encrypted) {
        if (empty($encrypted)) {
            return null;
        }

        $key = wp_salt('auth');
        $iv = substr(wp_salt('secure_auth'), 0, 16);

        $decrypted = openssl_decrypt(base64_decode($encrypted), 'AES-256-CBC', $key, 0, $iv);
        return $decrypted ?: null;
    }

    /**
     * Redirect helper with error
     */
    private static function redirect_with_error($message) {
        $redirect_url = add_query_arg([
            'calendar_error' => urlencode($message),
        ], home_url('/app/calendar/'));

        wp_redirect($redirect_url);
        exit;
    }

    /**
     * Redirect helper with success
     */
    private static function redirect_with_success($message) {
        $redirect_url = add_query_arg([
            'calendar_connected' => 1,
            'message' => urlencode($message),
        ], home_url('/app/calendar/'));

        wp_redirect($redirect_url);
        exit;
    }
}

// Register routes on REST API init
add_action('rest_api_init', ['PIT_REST_Calendar_Sync', 'register_routes']);
