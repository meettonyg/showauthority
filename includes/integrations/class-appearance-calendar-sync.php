<?php
/**
 * Appearance Calendar Sync
 *
 * Automatically creates and syncs calendar events when interview dates
 * (record_date, air_date, promotion_date) are set on appearances.
 *
 * @package Podcast_Influence_Tracker
 * @since 3.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Appearance_Calendar_Sync {

    /**
     * Date field to event type mapping
     */
    private static $date_field_map = [
        'record_date' => 'recording',
        'air_date' => 'air_date',
        'promotion_date' => 'promotion',
    ];

    /**
     * Event type labels for titles
     */
    private static $event_labels = [
        'recording' => 'Recording',
        'air_date' => 'Air Date',
        'promotion' => 'Promotion',
    ];

    /**
     * Sync calendar events for an appearance after update
     *
     * @param int   $appearance_id The appearance ID
     * @param array $updated_fields Fields that were updated
     * @param int   $user_id The user ID
     */
    public static function sync_appearance_dates($appearance_id, $updated_fields, $user_id) {
        global $wpdb;

        // Check if any date fields were updated
        $date_fields = array_intersect(array_keys(self::$date_field_map), $updated_fields);
        if (empty($date_fields)) {
            return;
        }

        // Get the appearance with podcast info
        $table = $wpdb->prefix . 'pit_opportunities';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';

        $appearance = $wpdb->get_row($wpdb->prepare(
            "SELECT o.*, p.title as podcast_name
             FROM {$table} o
             LEFT JOIN {$podcasts_table} p ON o.podcast_id = p.id
             WHERE o.id = %d",
            $appearance_id
        ));

        if (!$appearance) {
            return;
        }

        // Sync each date field
        foreach ($date_fields as $date_field) {
            self::sync_date_field($appearance, $date_field, $user_id);
        }
    }

    /**
     * Sync a specific date field to calendar
     *
     * Note: If an event already exists (created via interview detail modal),
     * we only update the date portion to preserve user's time settings.
     */
    private static function sync_date_field($appearance, $date_field, $user_id) {
        global $wpdb;

        $event_type = self::$date_field_map[$date_field];
        $date_value = $appearance->$date_field;
        $calendar_table = $wpdb->prefix . 'pit_calendar_events';

        // Check for existing linked event
        $existing_event = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$calendar_table}
             WHERE appearance_id = %d AND event_type = %s AND user_id = %d",
            $appearance->id,
            $event_type,
            $user_id
        ));

        if (empty($date_value)) {
            // Date was cleared - delete the calendar event if it exists
            if ($existing_event) {
                self::delete_calendar_event($existing_event->id, $user_id);
            }
            return;
        }

        // Build event title with podcast name
        $event_label = self::$event_labels[$event_type];
        $podcast_name = $appearance->podcast_name ?: 'Interview';
        $title = "{$event_label}: {$podcast_name}";

        if ($existing_event) {
            // Event already exists - check if date changed
            $existing_date = substr($existing_event->start_datetime, 0, 10);
            if ($existing_date === $date_value) {
                // Same date, don't overwrite user's time settings
                return;
            }

            // Date changed - update only date portion, preserve times
            $existing_start_time = substr($existing_event->start_datetime, 11);
            $existing_end_time = substr($existing_event->end_datetime, 11);

            $event_data = [
                'title' => $title,
                'start_datetime' => $date_value . ' ' . $existing_start_time,
                'end_datetime' => $date_value . ' ' . $existing_end_time,
                'is_all_day' => $existing_event->is_all_day,
            ];
            self::update_calendar_event($existing_event->id, $event_data, $user_id);
        } else {
            // No event exists - create new with default times
            $event_data = [
                'title' => $title,
                'start_datetime' => $date_value . ' 09:00:00',
                'end_datetime' => $date_value . ' 10:00:00',
                'is_all_day' => 0,
                'event_type' => $event_type,
                'appearance_id' => $appearance->id,
                'podcast_id' => $appearance->podcast_id,
                'user_id' => $user_id,
                'timezone' => wp_timezone_string(),
            ];
            self::create_calendar_event($event_data, $user_id);
        }
    }

    /**
     * Create a new calendar event
     */
    private static function create_calendar_event($data, $user_id) {
        global $wpdb;

        $calendar_table = $wpdb->prefix . 'pit_calendar_events';

        // Check if user has calendar sync enabled
        $sync_settings = get_user_meta($user_id, 'pit_calendar_sync_settings', true);
        $sync_enabled = !empty($sync_settings['google']['connected']);

        $insert_data = array_merge($data, [
            'sync_enabled' => $sync_enabled ? 1 : 0,
            'sync_status' => $sync_enabled ? 'pending_sync' : 'local_only',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);

        $wpdb->insert($calendar_table, $insert_data);
        $event_id = $wpdb->insert_id;

        // Trigger sync if enabled
        if ($sync_enabled && $event_id) {
            self::trigger_event_sync($event_id);
        }

        return $event_id;
    }

    /**
     * Update an existing calendar event
     */
    private static function update_calendar_event($event_id, $data, $user_id) {
        global $wpdb;

        $calendar_table = $wpdb->prefix . 'pit_calendar_events';

        // Get current event to check sync status
        $current = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$calendar_table} WHERE id = %d AND user_id = %d",
            $event_id,
            $user_id
        ));

        if (!$current) {
            return false;
        }

        $update_data = [
            'title' => $data['title'],
            'start_datetime' => $data['start_datetime'],
            'end_datetime' => $data['end_datetime'],
            'is_all_day' => $data['is_all_day'],
            'updated_at' => current_time('mysql'),
        ];

        // Mark for re-sync if previously synced
        if ($current->sync_status === 'synced') {
            $update_data['sync_status'] = 'pending_sync';
        }

        $wpdb->update($calendar_table, $update_data, ['id' => $event_id]);

        // Trigger sync if needed
        if ($update_data['sync_status'] ?? '' === 'pending_sync') {
            self::trigger_event_sync($event_id);
        }

        return true;
    }

    /**
     * Delete a calendar event
     */
    private static function delete_calendar_event($event_id, $user_id) {
        global $wpdb;

        $calendar_table = $wpdb->prefix . 'pit_calendar_events';

        // Get event to check if synced
        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$calendar_table} WHERE id = %d AND user_id = %d",
            $event_id,
            $user_id
        ));

        if (!$event) {
            return false;
        }

        // If synced to Google, delete from Google first
        if (!empty($event->google_event_id) && class_exists('PIT_Calendar_Sync_Service')) {
            try {
                PIT_Calendar_Sync_Service::delete_google_event($user_id, $event->google_event_id);
            } catch (Exception $e) {
                error_log('Failed to delete Google Calendar event: ' . $e->getMessage());
            }
        }

        // Delete local event
        $wpdb->delete($calendar_table, ['id' => $event_id]);

        return true;
    }

    /**
     * Trigger async sync for an event
     */
    private static function trigger_event_sync($event_id) {
        // Schedule immediate sync via WP Cron if sync service exists
        if (class_exists('PIT_Calendar_Sync_Service')) {
            if (!wp_next_scheduled('pit_sync_single_event', [$event_id])) {
                wp_schedule_single_event(time(), 'pit_sync_single_event', [$event_id]);
            }
        }
    }

    /**
     * Get all calendar events for an appearance
     *
     * @param int $appearance_id
     * @param int $user_id
     * @return array
     */
    public static function get_appearance_events($appearance_id, $user_id) {
        global $wpdb;

        $calendar_table = $wpdb->prefix . 'pit_calendar_events';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$calendar_table}
             WHERE appearance_id = %d AND user_id = %d
             ORDER BY start_datetime ASC",
            $appearance_id,
            $user_id
        ));
    }

    /**
     * Bulk sync all existing appearances that have dates but no calendar events
     * Useful for initial migration
     *
     * @param int $user_id
     * @return int Number of events created
     */
    public static function migrate_existing_dates($user_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'pit_opportunities';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';
        $calendar_table = $wpdb->prefix . 'pit_calendar_events';

        // Get appearances with dates that don't have linked calendar events
        $appearances = $wpdb->get_results($wpdb->prepare(
            "SELECT o.*, p.title as podcast_name
             FROM {$table} o
             LEFT JOIN {$podcasts_table} p ON o.podcast_id = p.id
             WHERE o.user_id = %d
             AND (o.record_date IS NOT NULL OR o.air_date IS NOT NULL OR o.promotion_date IS NOT NULL)",
            $user_id
        ));

        $created = 0;

        foreach ($appearances as $appearance) {
            foreach (self::$date_field_map as $date_field => $event_type) {
                if (empty($appearance->$date_field)) {
                    continue;
                }

                // Check if event already exists
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$calendar_table}
                     WHERE appearance_id = %d AND event_type = %s AND user_id = %d",
                    $appearance->id,
                    $event_type,
                    $user_id
                ));

                if (!$exists) {
                    self::sync_date_field($appearance, $date_field, $user_id);
                    $created++;
                }
            }
        }

        return $created;
    }
}
