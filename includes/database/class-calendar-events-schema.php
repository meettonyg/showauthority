<?php
/**
 * Calendar Events Database Table
 * 
 * Schema for pit_calendar_events table to store podcast-related events
 * with support for Google Calendar and Outlook integration.
 *
 * @package Podcast_Influence_Tracker
 * @since 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Calendar_Events_Schema {

    /**
     * Table name (without prefix)
     */
    const TABLE_NAME = 'pit_calendar_events';

    /**
     * Get full table name with prefix
     */
    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Create the calendar events table
     */
    public static function create_table() {
        global $wpdb;
        
        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            appearance_id BIGINT UNSIGNED NULL,
            podcast_id BIGINT UNSIGNED NULL,
            
            -- Event Details
            event_type VARCHAR(50) NOT NULL DEFAULT 'other',
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            location VARCHAR(255) NULL,
            
            -- Timing
            start_datetime DATETIME NOT NULL,
            end_datetime DATETIME NULL,
            is_all_day TINYINT(1) DEFAULT 0,
            timezone VARCHAR(50) DEFAULT 'America/Chicago',
            
            -- External Calendar Sync
            google_calendar_id VARCHAR(255) NULL,
            google_event_id VARCHAR(255) NULL,
            outlook_calendar_id VARCHAR(255) NULL,
            outlook_event_id VARCHAR(255) NULL,
            
            -- Sync Tracking
            sync_enabled TINYINT(1) DEFAULT 0,
            sync_status VARCHAR(20) DEFAULT 'local_only',
            last_synced_at DATETIME NULL,
            sync_error_message TEXT NULL,
            
            -- Reminders (JSON)
            reminders JSON NULL,
            
            -- Metadata
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_user_dates (user_id, start_datetime),
            INDEX idx_appearance (appearance_id),
            INDEX idx_event_type (event_type),
            INDEX idx_google_event (google_event_id),
            INDEX idx_outlook_event (outlook_event_id),
            INDEX idx_sync_status (sync_status)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Verify table was created
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        if ($table_exists) {
            update_option('pit_calendar_events_db_version', '1.0.0');
        }

        return $table_exists;
    }

    /**
     * Check if table exists
     */
    public static function table_exists() {
        global $wpdb;
        $table_name = self::get_table_name();
        return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    }

    /**
     * Drop the table (use with caution)
     */
    public static function drop_table() {
        global $wpdb;
        $table_name = self::get_table_name();
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
        delete_option('pit_calendar_events_db_version');
    }

    /**
     * Get valid event types
     */
    public static function get_event_types() {
        return [
            'recording'   => 'Recording Session',
            'air_date'    => 'Air Date',
            'prep_call'   => 'Prep Call',
            'follow_up'   => 'Follow Up',
            'promotion'   => 'Promotion',
            'deadline'    => 'Deadline',
            'podrec'      => 'Podcast Recording', // Legacy from Formidable
            'other'       => 'Other',
        ];
    }

    /**
     * Get valid sync statuses
     */
    public static function get_sync_statuses() {
        return [
            'local_only'   => 'Local Only',
            'synced'       => 'Synced',
            'pending_sync' => 'Pending Sync',
            'sync_error'   => 'Sync Error',
        ];
    }
}
