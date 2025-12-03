<?php
/**
 * Calendar Connections Database Table
 *
 * Schema for storing user OAuth tokens and calendar connection settings
 * for Google Calendar and Outlook integration.
 *
 * @package Podcast_Influence_Tracker
 * @since 3.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Calendar_Connections_Schema {

    /**
     * Table name (without prefix)
     */
    const TABLE_NAME = 'pit_calendar_connections';

    /**
     * Get full table name with prefix
     */
    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Create the calendar connections table
     */
    public static function create_table() {
        global $wpdb;

        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(20) NOT NULL DEFAULT 'google',

            -- OAuth Tokens (encrypted)
            access_token TEXT NULL,
            refresh_token TEXT NULL,
            token_expires_at DATETIME NULL,

            -- Calendar Settings
            calendar_id VARCHAR(255) NULL,
            calendar_name VARCHAR(255) NULL,
            sync_enabled TINYINT(1) DEFAULT 1,
            sync_direction VARCHAR(20) DEFAULT 'both',

            -- User Info from Provider
            provider_email VARCHAR(255) NULL,
            provider_name VARCHAR(255) NULL,

            -- Sync State
            last_sync_at DATETIME NULL,
            last_sync_token TEXT NULL,
            sync_error TEXT NULL,

            -- Metadata
            connected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            UNIQUE KEY idx_user_provider (user_id, provider),
            INDEX idx_provider (provider),
            INDEX idx_sync_enabled (sync_enabled)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

        if ($table_exists) {
            update_option('pit_calendar_connections_db_version', '1.0.0');
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
     * Get supported providers
     */
    public static function get_providers() {
        return [
            'google' => 'Google Calendar',
            'outlook' => 'Microsoft Outlook',
        ];
    }

    /**
     * Get sync direction options
     */
    public static function get_sync_directions() {
        return [
            'both' => 'Two-way sync',
            'push' => 'Push to calendar only',
            'pull' => 'Pull from calendar only',
        ];
    }
}
