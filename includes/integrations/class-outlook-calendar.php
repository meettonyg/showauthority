<?php
/**
 * Microsoft Outlook Calendar Integration
 *
 * Handles OAuth 2.0 flow and Microsoft Graph API operations
 * for two-way calendar synchronization with Outlook.
 *
 * @package Podcast_Influence_Tracker
 * @since 4.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Outlook_Calendar {

    /**
     * Microsoft OAuth URLs
     */
    const AUTH_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';
    const TOKEN_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
    const GRAPH_API_URL = 'https://graph.microsoft.com/v1.0';

    /**
     * Required OAuth scopes
     */
    const SCOPES = [
        'openid',
        'email',
        'profile',
        'offline_access',
        'Calendars.ReadWrite',
        'User.Read',
    ];

    /**
     * Get Microsoft API credentials from settings
     */
    public static function get_credentials() {
        $settings = get_option('pit_settings', []);
        return [
            'client_id' => $settings['outlook_client_id'] ?? '',
            'client_secret' => $settings['outlook_client_secret'] ?? '',
        ];
    }

    /**
     * Check if Outlook Calendar is configured
     */
    public static function is_configured() {
        $creds = self::get_credentials();
        return !empty($creds['client_id']) && !empty($creds['client_secret']);
    }

    /**
     * Get OAuth redirect URI
     */
    public static function get_redirect_uri() {
        return rest_url('pit/v1/calendar-sync/outlook/callback');
    }

    /**
     * Generate OAuth authorization URL
     */
    public static function get_auth_url($user_id = null) {
        if (!self::is_configured()) {
            return new WP_Error('not_configured', 'Outlook Calendar is not configured');
        }

        $creds = self::get_credentials();

        // Generate state token for CSRF protection
        $state = wp_generate_password(32, false);
        set_transient('pit_outlook_oauth_state_' . $state, [
            'user_id' => $user_id ?: get_current_user_id(),
            'created' => time(),
        ], 600); // 10 minute expiry

        $params = [
            'client_id' => $creds['client_id'],
            'redirect_uri' => self::get_redirect_uri(),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'response_mode' => 'query',
            'state' => $state,
        ];

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for tokens
     */
    public static function exchange_code($code) {
        if (!self::is_configured()) {
            return new WP_Error('not_configured', 'Outlook Calendar is not configured');
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
            return new WP_Error('not_configured', 'Outlook Calendar is not configured');
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
            'refresh_token' => $body['refresh_token'] ?? $refresh_token,
            'expires_in' => $body['expires_in'],
        ];
    }

    /**
     * Get user info from Microsoft Graph
     */
    public static function get_user_info($access_token) {
        $response = wp_remote_get(self::GRAPH_API_URL . '/me', [
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
            'email' => $body['mail'] ?? $body['userPrincipalName'] ?? '',
            'name' => $body['displayName'] ?? '',
            'id' => $body['id'] ?? '',
        ];
    }

    /**
     * Get list of user's calendars
     */
    public static function get_calendars($access_token) {
        $response = wp_remote_get(self::GRAPH_API_URL . '/me/calendars', [
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
        foreach ($body['value'] ?? [] as $cal) {
            // Only include calendars user can write to
            if ($cal['canEdit'] ?? true) {
                $calendars[] = [
                    'id' => $cal['id'],
                    'name' => $cal['name'],
                    'primary' => $cal['isDefaultCalendar'] ?? false,
                    'color' => self::map_outlook_color($cal['color'] ?? 'auto'),
                ];
            }
        }

        return $calendars;
    }

    /**
     * Create event in Outlook Calendar
     */
    public static function create_event($access_token, $calendar_id, $event_data) {
        $outlook_event = self::format_event_for_outlook($event_data);

        $response = wp_remote_post(
            self::GRAPH_API_URL . '/me/calendars/' . urlencode($calendar_id) . '/events',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($outlook_event),
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
            'web_link' => $body['webLink'] ?? '',
            'status' => 'created',
        ];
    }

    /**
     * Update event in Outlook Calendar
     */
    public static function update_event($access_token, $calendar_id, $event_id, $event_data) {
        $outlook_event = self::format_event_for_outlook($event_data);

        $response = wp_remote_request(
            self::GRAPH_API_URL . '/me/calendars/' . urlencode($calendar_id) . '/events/' . urlencode($event_id),
            [
                'method' => 'PATCH',
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($outlook_event),
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
            'web_link' => $body['webLink'] ?? '',
            'status' => 'updated',
            'last_modified' => $body['lastModifiedDateTime'] ?? null,
        ];
    }

    /**
     * Delete event from Outlook Calendar
     */
    public static function delete_event($access_token, $calendar_id, $event_id) {
        $response = wp_remote_request(
            self::GRAPH_API_URL . '/me/calendars/' . urlencode($calendar_id) . '/events/' . urlencode($event_id),
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

        // 204 = success, 404 = already deleted
        if ($status_code === 204 || $status_code === 404) {
            return true;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return new WP_Error('api_error', $body['error']['message'] ?? 'Failed to delete event');
    }

    /**
     * Get events from Outlook Calendar (for sync)
     */
    public static function get_events($access_token, $calendar_id, $delta_link = null, $time_min = null) {
        if ($delta_link) {
            // Use delta link for incremental sync
            $url = $delta_link;
        } else {
            // Initial sync
            $time_min = $time_min ?: date('c', strtotime('-30 days'));
            $params = [
                '$filter' => "start/dateTime ge '$time_min'",
                '$top' => 100,
                '$orderby' => 'start/dateTime',
            ];

            $url = self::GRAPH_API_URL . '/me/calendars/' . urlencode($calendar_id) . '/events/delta?' . http_build_query($params);
        }

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Prefer' => 'odata.maxpagesize=100',
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return new WP_Error('api_error', $body['error']['message'] ?? 'Failed to get events');
        }

        $events = [];
        foreach ($body['value'] ?? [] as $item) {
            $events[] = self::format_event_from_outlook($item);
        }

        return [
            'events' => $events,
            'delta_link' => $body['@odata.deltaLink'] ?? null,
            'next_link' => $body['@odata.nextLink'] ?? null,
        ];
    }

    /**
     * Format local event data for Outlook Graph API
     */
    private static function format_event_for_outlook($event_data) {
        $outlook_event = [
            'subject' => $event_data['title'],
            'body' => [
                'contentType' => 'text',
                'content' => $event_data['description'] ?? '',
            ],
        ];

        if (!empty($event_data['location'])) {
            $outlook_event['location'] = [
                'displayName' => $event_data['location'],
            ];
        }

        // Handle all-day vs timed events
        $timezone = $event_data['timezone'] ?? 'America/Chicago';

        if (!empty($event_data['is_all_day'])) {
            // All-day event
            $outlook_event['isAllDay'] = true;
            $outlook_event['start'] = [
                'dateTime' => date('Y-m-d\T00:00:00', strtotime($event_data['start_datetime'])),
                'timeZone' => $timezone,
            ];

            $end_date = !empty($event_data['end_datetime'])
                ? date('Y-m-d\T00:00:00', strtotime($event_data['end_datetime'] . ' +1 day'))
                : date('Y-m-d\T00:00:00', strtotime($event_data['start_datetime'] . ' +1 day'));

            $outlook_event['end'] = [
                'dateTime' => $end_date,
                'timeZone' => $timezone,
            ];
        } else {
            // Timed event
            $outlook_event['isAllDay'] = false;
            $outlook_event['start'] = [
                'dateTime' => date('Y-m-d\TH:i:s', strtotime($event_data['start_datetime'])),
                'timeZone' => $timezone,
            ];

            $end_datetime = !empty($event_data['end_datetime'])
                ? $event_data['end_datetime']
                : date('Y-m-d H:i:s', strtotime($event_data['start_datetime'] . ' +1 hour'));

            $outlook_event['end'] = [
                'dateTime' => date('Y-m-d\TH:i:s', strtotime($end_datetime)),
                'timeZone' => $timezone,
            ];
        }

        // Add extended properties for tracking
        $outlook_event['singleValueExtendedProperties'] = [
            [
                'id' => 'String {00000000-0000-0000-0000-000000000000} Name pit_event_id',
                'value' => (string)($event_data['id'] ?? ''),
            ],
            [
                'id' => 'String {00000000-0000-0000-0000-000000000000} Name pit_event_type',
                'value' => $event_data['event_type'] ?? '',
            ],
        ];

        return $outlook_event;
    }

    /**
     * Format Outlook Calendar event to local format
     */
    private static function format_event_from_outlook($outlook_event) {
        $is_all_day = $outlook_event['isAllDay'] ?? false;

        if ($is_all_day) {
            $start_datetime = date('Y-m-d', strtotime($outlook_event['start']['dateTime'])) . ' 00:00:00';
            $end_datetime = isset($outlook_event['end']['dateTime'])
                ? date('Y-m-d', strtotime($outlook_event['end']['dateTime'] . ' -1 day')) . ' 23:59:59'
                : null;
        } else {
            $start_datetime = date('Y-m-d H:i:s', strtotime($outlook_event['start']['dateTime']));
            $end_datetime = isset($outlook_event['end']['dateTime'])
                ? date('Y-m-d H:i:s', strtotime($outlook_event['end']['dateTime']))
                : null;
        }

        // Extract extended properties if present
        $pit_event_id = null;
        $pit_event_type = null;
        foreach ($outlook_event['singleValueExtendedProperties'] ?? [] as $prop) {
            if (strpos($prop['id'], 'pit_event_id') !== false) {
                $pit_event_id = $prop['value'];
            }
            if (strpos($prop['id'], 'pit_event_type') !== false) {
                $pit_event_type = $prop['value'];
            }
        }

        return [
            'outlook_event_id' => $outlook_event['id'],
            'title' => $outlook_event['subject'] ?? '(No title)',
            'description' => $outlook_event['body']['content'] ?? '',
            'location' => $outlook_event['location']['displayName'] ?? '',
            'start_datetime' => $start_datetime,
            'end_datetime' => $end_datetime,
            'is_all_day' => $is_all_day,
            'timezone' => $outlook_event['start']['timeZone'] ?? 'America/Chicago',
            'status' => isset($outlook_event['@removed']) ? 'cancelled' : 'confirmed',
            'web_link' => $outlook_event['webLink'] ?? '',
            'last_modified' => $outlook_event['lastModifiedDateTime'] ?? null,
            // Extended properties from our app
            'pit_event_id' => $pit_event_id,
            'pit_event_type' => $pit_event_type,
        ];
    }

    /**
     * Map Outlook color to hex
     */
    private static function map_outlook_color($color) {
        $colors = [
            'auto' => '#0078d4',
            'lightBlue' => '#0078d4',
            'lightGreen' => '#107c10',
            'lightOrange' => '#ff8c00',
            'lightGray' => '#767676',
            'lightYellow' => '#c19c00',
            'lightTeal' => '#008272',
            'lightPink' => '#e3008c',
            'lightBrown' => '#8e562e',
            'lightRed' => '#d13438',
            'maxColor' => '#0078d4',
        ];

        return $colors[$color] ?? $colors['auto'];
    }
}
