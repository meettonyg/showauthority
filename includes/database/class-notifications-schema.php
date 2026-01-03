<?php
/**
 * Notifications Database Table
 *
 * Schema for pit_notifications table to store user notifications
 * for task reminders, calendar events, and system alerts.
 *
 * @package Podcast_Influence_Tracker
 * @since 3.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Notifications_Schema {

    /**
     * Table name (without prefix)
     */
    const TABLE_NAME = 'pit_notifications';

    /**
     * Database version
     */
    const DB_VERSION = '1.0.0';

    /**
     * Get full table name with prefix
     */
    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Create the notifications table
     */
    public static function create_table() {
        global $wpdb;

        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,

            -- Notification Type & Source
            type VARCHAR(50) NOT NULL DEFAULT 'info',
            source VARCHAR(50) NOT NULL DEFAULT 'system',
            source_id BIGINT UNSIGNED NULL,

            -- Content
            title VARCHAR(255) NOT NULL,
            message TEXT NULL,
            action_url VARCHAR(500) NULL,
            action_label VARCHAR(100) NULL,

            -- Related entities (for linking)
            appearance_id BIGINT UNSIGNED NULL,
            task_id BIGINT UNSIGNED NULL,
            event_id BIGINT UNSIGNED NULL,

            -- Status
            is_read TINYINT(1) DEFAULT 0,
            is_dismissed TINYINT(1) DEFAULT 0,
            read_at DATETIME NULL,

            -- Email tracking
            email_sent TINYINT(1) DEFAULT 0,
            email_sent_at DATETIME NULL,

            -- Scheduling (for reminders)
            scheduled_for DATETIME NULL,
            processed_at DATETIME NULL,

            -- Metadata
            meta JSON NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            INDEX idx_user_unread (user_id, is_read, is_dismissed),
            INDEX idx_user_created (user_id, created_at),
            INDEX idx_type (type),
            INDEX idx_source (source, source_id),
            INDEX idx_scheduled (scheduled_for, processed_at),
            INDEX idx_task (task_id),
            INDEX idx_event (event_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Verify table was created
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

        if ($table_exists) {
            update_option('pit_notifications_db_version', self::DB_VERSION);
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
        delete_option('pit_notifications_db_version');
    }

    /**
     * Get valid notification types
     */
    public static function get_types() {
        return [
            'task_reminder'    => 'Task Reminder',
            'task_overdue'     => 'Task Overdue',
            'event_reminder'   => 'Event Reminder',
            'interview_soon'   => 'Interview Coming Up',
            'info'             => 'Information',
            'success'          => 'Success',
            'warning'          => 'Warning',
            'error'            => 'Error',
        ];
    }

    /**
     * Get valid notification sources
     */
    public static function get_sources() {
        return [
            'task'     => 'Task',
            'event'    => 'Calendar Event',
            'system'   => 'System',
            'interview'=> 'Interview',
        ];
    }
}
