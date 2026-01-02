<?php
/**
 * Calendar Sync Service
 *
 * Handles two-way synchronization between local calendar events
 * and external calendar providers (Google, Outlook).
 *
 * @package Podcast_Influence_Tracker
 * @since 3.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Calendar_Sync_Service {

    /**
     * Sync user's calendar events
     *
     * @param int $user_id User ID
     * @param string $provider Provider name (google, outlook)
     * @return array|WP_Error Sync results or error
     */
    public static function sync_user($user_id, $provider = 'google') {
        global $wpdb;

        $connection = self::get_connection($user_id, $provider);

        if (!$connection) {
            return new WP_Error('not_connected', 'Calendar not connected');
        }

        if (!$connection['sync_enabled']) {
            return new WP_Error('sync_disabled', 'Sync is disabled');
        }

        if (!$connection['calendar_id']) {
            return new WP_Error('no_calendar', 'No calendar selected');
        }

        $access_token = PIT_REST_Calendar_Sync::get_valid_access_token($user_id, $provider);

        if (is_wp_error($access_token)) {
            self::log_sync_error($connection['id'], $access_token->get_error_message());
            return $access_token;
        }

        $results = [
            'pushed' => 0,
            'pulled' => 0,
            'updated' => 0,
            'deleted' => 0,
            'errors' => [],
        ];

        $sync_direction = $connection['sync_direction'] ?? 'both';

        // Push local events to Google
        if (in_array($sync_direction, ['both', 'push'])) {
            $push_result = self::push_to_google($user_id, $access_token, $connection['calendar_id']);
            $results['pushed'] = $push_result['created'];
            $results['updated'] += $push_result['updated'];
            $results['deleted'] += $push_result['deleted'];
            $results['errors'] = array_merge($results['errors'], $push_result['errors']);
        }

        // Pull events from Google
        if (in_array($sync_direction, ['both', 'pull'])) {
            $pull_result = self::pull_from_google(
                $user_id,
                $access_token,
                $connection['calendar_id'],
                $connection['last_sync_token']
            );

            if (!is_wp_error($pull_result)) {
                $results['pulled'] = $pull_result['created'];
                $results['updated'] += $pull_result['updated'];
                $results['deleted'] += $pull_result['deleted'];
                $results['errors'] = array_merge($results['errors'], $pull_result['errors']);

                // Save sync token for incremental sync
                if (!empty($pull_result['sync_token'])) {
                    self::update_sync_token($connection['id'], $pull_result['sync_token']);
                }
            } else {
                $results['errors'][] = 'Pull failed: ' . $pull_result->get_error_message();
            }
        }

        // Update last sync time
        self::update_last_sync($connection['id']);

        // Clear any previous errors if sync succeeded
        if (empty($results['errors'])) {
            self::clear_sync_error($connection['id']);
        }

        return $results;
    }

    /**
     * Push local events to Google Calendar
     */
    private static function push_to_google($user_id, $access_token, $calendar_id) {
        global $wpdb;

        $events_table = PIT_Calendar_Events_Schema::get_table_name();

        $results = [
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
            'errors' => [],
        ];

        // Get date range limits from settings
        $days_back = (int) PIT_Settings::get('calendar_sync_days_back', 30);
        $days_forward = (int) PIT_Settings::get('calendar_sync_days_forward', 365);

        // Build date range SQL conditions
        $date_conditions = '';
        if ($days_back > 0) {
            $min_date = date('Y-m-d H:i:s', strtotime("-{$days_back} days"));
            $date_conditions .= " AND start_datetime >= '{$min_date}'";
        }
        if ($days_forward > 0) {
            $max_date = date('Y-m-d H:i:s', strtotime("+{$days_forward} days"));
            $date_conditions .= " AND start_datetime <= '{$max_date}'";
        }

        // Get events that need to be pushed (created or updated locally) within date range
        $pending_events = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $events_table
             WHERE user_id = %d
               AND sync_enabled = 1
               AND (
                   (google_event_id IS NULL AND sync_status = 'local_only')
                   OR sync_status = 'pending_sync'
               )
               {$date_conditions}
             ORDER BY start_datetime ASC
             LIMIT 50",
            $user_id
        ), ARRAY_A);

        foreach ($pending_events as $event) {
            if (empty($event['google_event_id'])) {
                // Create new event in Google
                $result = PIT_Google_Calendar::create_event($access_token, $calendar_id, $event);

                if (is_wp_error($result)) {
                    $results['errors'][] = "Create failed for event {$event['id']}: " . $result->get_error_message();
                    self::mark_event_sync_error($event['id'], $result->get_error_message());
                } else {
                    // Update local event with Google ID
                    $wpdb->update(
                        $events_table,
                        [
                            'google_calendar_id' => $calendar_id,
                            'google_event_id' => $result['id'],
                            'sync_status' => 'synced',
                            'sync_error_message' => null,
                        ],
                        ['id' => $event['id']]
                    );
                    $results['created']++;
                }
            } else {
                // Update existing event in Google
                $result = PIT_Google_Calendar::update_event(
                    $access_token,
                    $calendar_id,
                    $event['google_event_id'],
                    $event
                );

                if (is_wp_error($result)) {
                    // If event not found in Google, try creating it
                    if (strpos($result->get_error_message(), '404') !== false) {
                        $create_result = PIT_Google_Calendar::create_event($access_token, $calendar_id, $event);
                        if (!is_wp_error($create_result)) {
                            $wpdb->update(
                                $events_table,
                                [
                                    'google_event_id' => $create_result['id'],
                                    'sync_status' => 'synced',
                                    'sync_error_message' => null,
                                ],
                                ['id' => $event['id']]
                            );
                            $results['created']++;
                            continue;
                        }
                    }
                    $results['errors'][] = "Update failed for event {$event['id']}: " . $result->get_error_message();
                    self::mark_event_sync_error($event['id'], $result->get_error_message());
                } else {
                    $wpdb->update(
                        $events_table,
                        [
                            'sync_status' => 'synced',
                            'sync_error_message' => null,
                        ],
                        ['id' => $event['id']]
                    );
                    $results['updated']++;
                }
            }
        }

        // Handle deleted events (marked for deletion)
        $deleted_events = $wpdb->get_results($wpdb->prepare(
            "SELECT id, google_event_id FROM $events_table
             WHERE user_id = %d
               AND google_event_id IS NOT NULL
               AND sync_status = 'pending_delete'
             LIMIT 20",
            $user_id
        ), ARRAY_A);

        foreach ($deleted_events as $event) {
            $result = PIT_Google_Calendar::delete_event(
                $access_token,
                $calendar_id,
                $event['google_event_id']
            );

            if (is_wp_error($result)) {
                $results['errors'][] = "Delete failed for event {$event['id']}: " . $result->get_error_message();
            } else {
                // Actually delete the local record
                $wpdb->delete($events_table, ['id' => $event['id']]);
                $results['deleted']++;
            }
        }

        return $results;
    }

    /**
     * Pull events from Google Calendar
     */
    private static function pull_from_google($user_id, $access_token, $calendar_id, $sync_token = null) {
        global $wpdb;

        $events_table = PIT_Calendar_Events_Schema::get_table_name();

        $results = [
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
            'errors' => [],
            'sync_token' => null,
        ];

        // Fetch events from Google
        $google_events = PIT_Google_Calendar::get_events($access_token, $calendar_id, $sync_token);

        if (is_wp_error($google_events)) {
            // If sync token expired, do full sync
            if ($google_events->get_error_code() === 'sync_token_expired') {
                $google_events = PIT_Google_Calendar::get_events($access_token, $calendar_id, null);
                if (is_wp_error($google_events)) {
                    return $google_events;
                }
            } else {
                return $google_events;
            }
        }

        $results['sync_token'] = $google_events['next_sync_token'];

        foreach ($google_events['events'] as $google_event) {
            // Skip events that originated from our app
            if (!empty($google_event['pit_event_id'])) {
                // This event was created by us, skip importing
                continue;
            }

            // Check if we already have this event
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, updated_at FROM $events_table
                 WHERE user_id = %d AND google_event_id = %s",
                $user_id,
                $google_event['google_event_id']
            ), ARRAY_A);

            // Handle cancelled (deleted) events
            if ($google_event['status'] === 'cancelled') {
                if ($existing) {
                    $wpdb->delete($events_table, ['id' => $existing['id']]);
                    $results['deleted']++;
                }
                continue;
            }

            // Prepare event data
            $event_data = [
                'user_id' => $user_id,
                'event_type' => 'other', // Default for imported events
                'title' => $google_event['title'],
                'description' => $google_event['description'],
                'location' => $google_event['location'],
                'start_datetime' => $google_event['start_datetime'],
                'end_datetime' => $google_event['end_datetime'],
                'is_all_day' => $google_event['is_all_day'] ? 1 : 0,
                'timezone' => $google_event['timezone'],
                'google_calendar_id' => $calendar_id,
                'google_event_id' => $google_event['google_event_id'],
                'sync_status' => 'synced',
                'sync_enabled' => 1,
                'updated_at' => current_time('mysql'),
            ];

            if ($existing) {
                // Update existing event
                $wpdb->update($events_table, $event_data, ['id' => $existing['id']]);
                $results['updated']++;
            } else {
                // Create new event
                $event_data['created_at'] = current_time('mysql');
                $wpdb->insert($events_table, $event_data);
                $results['created']++;
            }
        }

        return $results;
    }

    /**
     * Sync single event to Google (called on create/update)
     */
    public static function sync_event_to_google($event_id) {
        global $wpdb;

        $events_table = PIT_Calendar_Events_Schema::get_table_name();

        // Get the event
        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $events_table WHERE id = %d",
            $event_id
        ), ARRAY_A);

        if (!$event) {
            return new WP_Error('not_found', 'Event not found');
        }

        // Get user's connection
        $connection = self::get_connection($event['user_id'], 'google');

        if (!$connection || !$connection['sync_enabled'] || !$connection['calendar_id']) {
            // No sync configured, just mark as local only
            return true;
        }

        // Get valid access token
        $access_token = PIT_REST_Calendar_Sync::get_valid_access_token($event['user_id'], 'google');

        if (is_wp_error($access_token)) {
            // Mark for later sync
            $wpdb->update(
                $events_table,
                ['sync_status' => 'pending_sync'],
                ['id' => $event_id]
            );
            return $access_token;
        }

        // Enable sync for this event
        $wpdb->update(
            $events_table,
            ['sync_enabled' => 1],
            ['id' => $event_id]
        );

        if (empty($event['google_event_id'])) {
            // Create new event in Google
            $result = PIT_Google_Calendar::create_event(
                $access_token,
                $connection['calendar_id'],
                $event
            );

            if (is_wp_error($result)) {
                self::mark_event_sync_error($event_id, $result->get_error_message());
                return $result;
            }

            // Update local event with Google ID
            $wpdb->update(
                $events_table,
                [
                    'google_calendar_id' => $connection['calendar_id'],
                    'google_event_id' => $result['id'],
                    'sync_status' => 'synced',
                    'sync_error_message' => null,
                ],
                ['id' => $event_id]
            );
        } else {
            // Update existing event
            $result = PIT_Google_Calendar::update_event(
                $access_token,
                $connection['calendar_id'],
                $event['google_event_id'],
                $event
            );

            if (is_wp_error($result)) {
                self::mark_event_sync_error($event_id, $result->get_error_message());
                return $result;
            }

            $wpdb->update(
                $events_table,
                [
                    'sync_status' => 'synced',
                    'sync_error_message' => null,
                ],
                ['id' => $event_id]
            );
        }

        return true;
    }

    /**
     * Delete event from Google (called on local delete)
     */
    public static function delete_event_from_google($event) {
        if (empty($event['google_event_id'])) {
            return true; // Not synced, nothing to delete
        }

        $connection = self::get_connection($event['user_id'], 'google');

        if (!$connection || !$connection['sync_enabled']) {
            return true;
        }

        $access_token = PIT_REST_Calendar_Sync::get_valid_access_token($event['user_id'], 'google');

        if (is_wp_error($access_token)) {
            return $access_token;
        }

        return PIT_Google_Calendar::delete_event(
            $access_token,
            $event['google_calendar_id'],
            $event['google_event_id']
        );
    }

    /**
     * Get user's calendar connection
     */
    private static function get_connection($user_id, $provider) {
        global $wpdb;

        $table = PIT_Calendar_Connections_Schema::get_table_name();

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d AND provider = %s",
            $user_id,
            $provider
        ), ARRAY_A);
    }

    /**
     * Mark event with sync error
     */
    private static function mark_event_sync_error($event_id, $error_message) {
        global $wpdb;

        $table = PIT_Calendar_Events_Schema::get_table_name();

        $wpdb->update(
            $table,
            [
                'sync_status' => 'sync_error',
                'sync_error_message' => $error_message,
            ],
            ['id' => $event_id]
        );
    }

    /**
     * Update sync token
     */
    private static function update_sync_token($connection_id, $sync_token) {
        global $wpdb;

        $table = PIT_Calendar_Connections_Schema::get_table_name();

        $wpdb->update(
            $table,
            ['last_sync_token' => $sync_token],
            ['id' => $connection_id]
        );
    }

    /**
     * Update last sync time
     */
    private static function update_last_sync($connection_id) {
        global $wpdb;

        $table = PIT_Calendar_Connections_Schema::get_table_name();

        $wpdb->update(
            $table,
            ['last_sync_at' => current_time('mysql')],
            ['id' => $connection_id]
        );
    }

    /**
     * Log sync error
     */
    private static function log_sync_error($connection_id, $error_message) {
        global $wpdb;

        $table = PIT_Calendar_Connections_Schema::get_table_name();

        $wpdb->update(
            $table,
            ['sync_error' => $error_message],
            ['id' => $connection_id]
        );
    }

    /**
     * Clear sync error
     */
    private static function clear_sync_error($connection_id) {
        global $wpdb;

        $table = PIT_Calendar_Connections_Schema::get_table_name();

        $wpdb->update(
            $table,
            ['sync_error' => null],
            ['id' => $connection_id]
        );
    }

    /**
     * Cleanup old events from local database
     *
     * Deletes events older than the configured threshold to prevent database bloat.
     * Only deletes events that are already synced or local-only (won't delete pending events).
     *
     * @return array Cleanup results
     */
    public static function cleanup_old_events() {
        global $wpdb;

        // Check if cleanup is enabled
        $cleanup_enabled = PIT_Settings::get('calendar_cleanup_enabled', true);
        if (!$cleanup_enabled) {
            return [
                'deleted' => 0,
                'skipped' => true,
                'message' => 'Cleanup is disabled in settings',
            ];
        }

        $days_old = (int) PIT_Settings::get('calendar_cleanup_days_old', 90);

        // 0 means never delete
        if ($days_old <= 0) {
            return [
                'deleted' => 0,
                'skipped' => true,
                'message' => 'Cleanup threshold set to 0 (never delete)',
            ];
        }

        $events_table = PIT_Calendar_Events_Schema::get_table_name();
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_old} days"));

        // First, get count of events that will be deleted (for logging)
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $events_table
             WHERE end_datetime < %s
               AND sync_status IN ('synced', 'local_only', 'sync_error')",
            $cutoff_date
        ));

        if ($count > 0) {
            // Delete old events that have already been synced or are local-only
            // Don't delete pending_sync or pending_delete events to avoid data loss
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM $events_table
                 WHERE end_datetime < %s
                   AND sync_status IN ('synced', 'local_only', 'sync_error')",
                $cutoff_date
            ));

            // Log the cleanup
            error_log(sprintf(
                '[PIT Calendar Cleanup] Deleted %d events older than %s',
                $deleted,
                $cutoff_date
            ));

            return [
                'deleted' => $deleted,
                'skipped' => false,
                'cutoff_date' => $cutoff_date,
                'message' => sprintf('Deleted %d events older than %d days', $deleted, $days_old),
            ];
        }

        return [
            'deleted' => 0,
            'skipped' => false,
            'cutoff_date' => $cutoff_date,
            'message' => 'No old events to delete',
        ];
    }

    /**
     * Get cleanup statistics (preview what would be deleted)
     *
     * @return array Statistics about old events
     */
    public static function get_cleanup_stats() {
        global $wpdb;

        $events_table = PIT_Calendar_Events_Schema::get_table_name();
        $days_old = (int) PIT_Settings::get('calendar_cleanup_days_old', 90);

        if ($days_old <= 0) {
            return [
                'events_to_delete' => 0,
                'total_events' => $wpdb->get_var("SELECT COUNT(*) FROM $events_table"),
                'oldest_event' => null,
                'cleanup_enabled' => false,
            ];
        }

        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_old} days"));

        return [
            'events_to_delete' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $events_table
                 WHERE end_datetime < %s
                   AND sync_status IN ('synced', 'local_only', 'sync_error')",
                $cutoff_date
            )),
            'total_events' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $events_table"),
            'oldest_event' => $wpdb->get_var("SELECT MIN(start_datetime) FROM $events_table"),
            'cutoff_date' => $cutoff_date,
            'cleanup_enabled' => PIT_Settings::get('calendar_cleanup_enabled', true),
        ];
    }
}
