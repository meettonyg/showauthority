<?php
/**
 * Google Calendar Integration
 *
 * Handles OAuth 2.0 flow and Google Calendar API operations
 * for two-way calendar synchronization.
 *
 * @package Podcast_Influence_Tracker
 * @since 3.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Google_Calendar {

    /**
     * Google OAuth URLs
     */
    const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const CALENDAR_API_URL = 'https://www.googleapis.com/calendar/v3';
    const USERINFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';

    /**
     * Required OAuth scopes
     */
    const SCOPES = [
        'https://www.googleapis.com/auth/calendar',
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/userinfo.email',
        'https://www.googleapis.com/auth/userinfo.profile',
    ];

    /**
     * Get Google API credentials from settings
     */
    public static function get_credentials() {
        $settings = get_option('pit_settings', []);
        return [
            'client_id' => $settings['google_client_id'] ?? '',
            'client_secret' => $settings['google_client_secret'] ?? '',
        ];
    }

    /**
     * Check if Google Calendar is configured
     */
    public static function is_configured() {
        $creds = self::get_credentials();
        return !empty($creds['client_id']) && !empty($creds['client_secret']);
    }

    /**
     * Get OAuth redirect URI
     */
    public static function get_redirect_uri() {
        return rest_url('pit/v1/calendar-sync/google/callback');
    }

    /**
     * Generate OAuth authorization URL
     */
    public static function get_auth_url($user_id = null) {
        if (!self::is_configured()) {
            return new WP_Error('not_configured', 'Google Calendar is not configured');
        }

        $creds = self::get_credentials();

        // Generate state token for CSRF protection
        $state = wp_generate_password(32, false);
        set_transient('pit_google_oauth_state_' . $state, [
            'user_id' => $user_id ?: get_current_user_id(),
            'created' => time(),
        ], 600); // 10 minute expiry

        $params = [
            'client_id' => $creds['client_id'],
            'redirect_uri' => self::get_redirect_uri(),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ];

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for tokens
     */
    public static function exchange_code($code) {
        if (!self::is_configured()) {
            return new WP_Error('not_configured', 'Google Calendar is not configured');
        }

        $creds = self::get_credentials();

        $response = wp_remote_post(self::TOKEN_URL, [
            'body' => [
                'client_id' => $creds['client_id'],
                'client_secret' => $creds['client_secret'],
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => self::get_redirect_uri(),
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return new WP_Error('oauth_error', $body['error_description'] ?? $body['error']);
        }

        return [
            'access_token' => $body['access_token'],
            'refresh_token' => $body['refresh_token'] ?? null,
            'expires_in' => $body['expires_in'],
            'token_type' => $body['token_type'],
        ];
    }

    /**
     * Refresh access token using refresh token
     */
    public static function refresh_token($refresh_token) {
        if (!self::is_configured()) {
            return new WP_Error('not_configured', 'Google Calendar is not configured');
        }

        $creds = self::get_credentials();

        $response = wp_remote_post(self::TOKEN_URL, [
            'body' => [
                'client_id' => $creds['client_id'],
                'client_secret' => $creds['client_secret'],
                'refresh_token' => $refresh_token,
                'grant_type' => 'refresh_token',
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return new WP_Error('oauth_error', $body['error_description'] ?? $body['error']);
        }

        return [
            'access_token' => $body['access_token'],
            'expires_in' => $body['expires_in'],
        ];
    }

    /**
     * Get user info from Google
     */
    public static function get_user_info($access_token) {
        $response = wp_remote_get(self::USERINFO_URL, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return new WP_Error('api_error', $body['error']['message'] ?? 'Failed to get user info');
        }

        return [
            'email' => $body['email'] ?? '',
            'name' => $body['name'] ?? '',
            'picture' => $body['picture'] ?? '',
        ];
    }

    /**
     * Get list of user's calendars
     */
    public static function get_calendars($access_token) {
        $response = wp_remote_get(self::CALENDAR_API_URL . '/users/me/calendarList', [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return new WP_Error('api_error', $body['error']['message'] ?? 'Failed to get calendars');
        }

        $calendars = [];
        foreach ($body['items'] ?? [] as $cal) {
            // Only include calendars user can write to
            if (in_array($cal['accessRole'], ['owner', 'writer'])) {
                $calendars[] = [
                    'id' => $cal['id'],
                    'name' => $cal['summary'],
                    'primary' => $cal['primary'] ?? false,
                    'color' => $cal['backgroundColor'] ?? '#4285f4',
                ];
            }
        }

        return $calendars;
    }

    /**
     * Create event in Google Calendar
     */
    public static function create_event($access_token, $calendar_id, $event_data) {
        $google_event = self::format_event_for_google($event_data);

        $response = wp_remote_post(
            self::CALENDAR_API_URL . '/calendars/' . urlencode($calendar_id) . '/events',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($google_event),
                'timeout' => 15,
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return new WP_Error('api_error', $body['error']['message'] ?? 'Failed to create event');
        }

        return [
            'id' => $body['id'],
            'html_link' => $body['htmlLink'] ?? '',
            'status' => $body['status'],
        ];
    }

    /**
     * Update event in Google Calendar
     */
    public static function update_event($access_token, $calendar_id, $event_id, $event_data) {
        $google_event = self::format_event_for_google($event_data);

        $response = wp_remote_request(
            self::CALENDAR_API_URL . '/calendars/' . urlencode($calendar_id) . '/events/' . urlencode($event_id),
            [
                'method' => 'PUT',
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($google_event),
                'timeout' => 15,
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return new WP_Error('api_error', $body['error']['message'] ?? 'Failed to update event');
        }

        return [
            'id' => $body['id'],
            'html_link' => $body['htmlLink'] ?? '',
            'status' => $body['status'],
            'updated' => $body['updated'],
        ];
    }

    /**
     * Delete event from Google Calendar
     */
    public static function delete_event($access_token, $calendar_id, $event_id) {
        $response = wp_remote_request(
            self::CALENDAR_API_URL . '/calendars/' . urlencode($calendar_id) . '/events/' . urlencode($event_id),
            [
                'method' => 'DELETE',
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                ],
                'timeout' => 15,
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        // 204 = success, 410 = already deleted
        if ($status_code === 204 || $status_code === 410) {
            return true;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return new WP_Error('api_error', $body['error']['message'] ?? 'Failed to delete event');
    }

    /**
     * Get events from Google Calendar (for sync)
     */
    public static function get_events($access_token, $calendar_id, $sync_token = null, $time_min = null) {
        $params = [
            'maxResults' => 100,
            'singleEvents' => 'true',
            'orderBy' => 'startTime',
        ];

        if ($sync_token) {
            // Incremental sync
            $params['syncToken'] = $sync_token;
        } else {
            // Full sync - only get future events
            $params['timeMin'] = $time_min ?: date('c', strtotime('-30 days'));
        }

        $url = self::CALENDAR_API_URL . '/calendars/' . urlencode($calendar_id) . '/events?' . http_build_query($params);

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            // Handle invalid sync token
            if ($body['error']['code'] === 410) {
                return new WP_Error('sync_token_expired', 'Sync token expired, full sync required');
            }
            return new WP_Error('api_error', $body['error']['message'] ?? 'Failed to get events');
        }

        $events = [];
        foreach ($body['items'] ?? [] as $item) {
            $events[] = self::format_event_from_google($item);
        }

        return [
            'events' => $events,
            'next_sync_token' => $body['nextSyncToken'] ?? null,
            'next_page_token' => $body['nextPageToken'] ?? null,
        ];
    }

    /**
     * Format local event data for Google Calendar API
     */
    private static function format_event_for_google($event_data) {
        $google_event = [
            'summary' => $event_data['title'],
            'description' => $event_data['description'] ?? '',
            'location' => $event_data['location'] ?? '',
        ];

        // Handle all-day vs timed events
        if (!empty($event_data['is_all_day'])) {
            // All-day event uses date (not dateTime)
            $start_date = date('Y-m-d', strtotime($event_data['start_datetime']));
            $end_date = !empty($event_data['end_datetime'])
                ? date('Y-m-d', strtotime($event_data['end_datetime'] . ' +1 day'))
                : date('Y-m-d', strtotime($start_date . ' +1 day'));

            $google_event['start'] = ['date' => $start_date];
            $google_event['end'] = ['date' => $end_date];
        } else {
            // Timed event uses dateTime
            $timezone = $event_data['timezone'] ?? 'America/Chicago';

            $google_event['start'] = [
                'dateTime' => self::format_datetime($event_data['start_datetime'], $timezone),
                'timeZone' => $timezone,
            ];

            $end_datetime = !empty($event_data['end_datetime'])
                ? $event_data['end_datetime']
                : date('Y-m-d H:i:s', strtotime($event_data['start_datetime'] . ' +1 hour'));

            $google_event['end'] = [
                'dateTime' => self::format_datetime($end_datetime, $timezone),
                'timeZone' => $timezone,
            ];
        }

        // Add extended properties for tracking
        $google_event['extendedProperties'] = [
            'private' => [
                'pit_event_id' => (string)($event_data['id'] ?? ''),
                'pit_event_type' => $event_data['event_type'] ?? '',
                'pit_appearance_id' => (string)($event_data['appearance_id'] ?? ''),
            ],
        ];

        return $google_event;
    }

    /**
     * Format Google Calendar event to local format
     */
    private static function format_event_from_google($google_event) {
        $is_all_day = isset($google_event['start']['date']);

        if ($is_all_day) {
            $start_datetime = $google_event['start']['date'] . ' 00:00:00';
            $end_datetime = isset($google_event['end']['date'])
                ? date('Y-m-d', strtotime($google_event['end']['date'] . ' -1 day')) . ' 23:59:59'
                : null;
        } else {
            $start_datetime = date('Y-m-d H:i:s', strtotime($google_event['start']['dateTime']));
            $end_datetime = isset($google_event['end']['dateTime'])
                ? date('Y-m-d H:i:s', strtotime($google_event['end']['dateTime']))
                : null;
        }

        // Extract extended properties if present
        $ext_props = $google_event['extendedProperties']['private'] ?? [];

        return [
            'google_event_id' => $google_event['id'],
            'title' => $google_event['summary'] ?? '(No title)',
            'description' => $google_event['description'] ?? '',
            'location' => $google_event['location'] ?? '',
            'start_datetime' => $start_datetime,
            'end_datetime' => $end_datetime,
            'is_all_day' => $is_all_day,
            'timezone' => $google_event['start']['timeZone'] ?? 'America/Chicago',
            'status' => $google_event['status'],
            'html_link' => $google_event['htmlLink'] ?? '',
            'updated' => $google_event['updated'] ?? null,
            // Extended properties from our app
            'pit_event_id' => $ext_props['pit_event_id'] ?? null,
            'pit_event_type' => $ext_props['pit_event_type'] ?? null,
            'pit_appearance_id' => $ext_props['pit_appearance_id'] ?? null,
        ];
    }

    /**
     * Format datetime for Google API (RFC3339)
     */
    private static function format_datetime($datetime, $timezone) {
        $dt = new DateTime($datetime, new DateTimeZone($timezone));
        return $dt->format('Y-m-d\TH:i:sP');
    }
}
