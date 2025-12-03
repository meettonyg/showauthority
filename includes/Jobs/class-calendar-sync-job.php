<?php
/**
 * Calendar Sync Background Job
 *
 * Handles periodic synchronization of calendar events with
 * external providers (Google Calendar, Outlook).
 *
 * @package Podcast_Influence_Tracker
 * @since 3.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Calendar_Sync_Job {

    /**
     * Hook name for the cron job
     */
    const HOOK = 'pit_calendar_sync';

    /**
     * Initialize the job scheduler
     */
    public static function init() {
        // Schedule cron job if not already scheduled
        add_action('init', [__CLASS__, 'schedule_sync']);

        // Hook the sync action
        add_action(self::HOOK, [__CLASS__, 'run_sync']);

        // Also run sync on user login (optional, for freshness)
        add_action('wp_login', [__CLASS__, 'sync_on_login'], 10, 2);
    }

    /**
     * Schedule the sync cron job
     */
    public static function schedule_sync() {
        if (!wp_next_scheduled(self::HOOK)) {
            // Run every 15 minutes
            wp_schedule_event(time(), 'pit_fifteen_minutes', self::HOOK);
        }
    }

    /**
     * Add custom cron interval
     */
    public static function add_cron_interval($schedules) {
        $schedules['pit_fifteen_minutes'] = [
            'interval' => 900, // 15 minutes
            'display' => __('Every 15 Minutes', 'podcast-influence-tracker'),
        ];
        return $schedules;
    }

    /**
     * Run sync for all users with enabled connections
     */
    public static function run_sync() {
        global $wpdb;

        // Check if sync service exists
        if (!class_exists('PIT_Calendar_Sync_Service')) {
            return;
        }

        $connections_table = PIT_Calendar_Connections_Schema::get_table_name();

        // Get all users with enabled Google sync
        $connections = $wpdb->get_results(
            "SELECT user_id, provider, last_sync_at
             FROM $connections_table
             WHERE sync_enabled = 1
               AND calendar_id IS NOT NULL
               AND (
                   last_sync_at IS NULL
                   OR last_sync_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
               )
             ORDER BY last_sync_at ASC
             LIMIT 10",
            ARRAY_A
        );

        foreach ($connections as $connection) {
            try {
                $result = PIT_Calendar_Sync_Service::sync_user(
                    $connection['user_id'],
                    $connection['provider']
                );

                if (is_wp_error($result)) {
                    error_log(sprintf(
                        'PIT Calendar Sync: Failed for user %d (%s): %s',
                        $connection['user_id'],
                        $connection['provider'],
                        $result->get_error_message()
                    ));
                } else {
                    error_log(sprintf(
                        'PIT Calendar Sync: User %d (%s) - Pushed: %d, Pulled: %d, Updated: %d',
                        $connection['user_id'],
                        $connection['provider'],
                        $result['pushed'],
                        $result['pulled'],
                        $result['updated']
                    ));
                }
            } catch (Exception $e) {
                error_log(sprintf(
                    'PIT Calendar Sync: Exception for user %d: %s',
                    $connection['user_id'],
                    $e->getMessage()
                ));
            }

            // Small delay between users to avoid rate limiting
            usleep(500000); // 0.5 seconds
        }
    }

    /**
     * Sync on user login
     */
    public static function sync_on_login($user_login, $user) {
        global $wpdb;

        if (!class_exists('PIT_Calendar_Sync_Service')) {
            return;
        }

        $connections_table = PIT_Calendar_Connections_Schema::get_table_name();

        // Check if user has an enabled connection that hasn't synced recently
        $connection = $wpdb->get_row($wpdb->prepare(
            "SELECT provider
             FROM $connections_table
             WHERE user_id = %d
               AND sync_enabled = 1
               AND calendar_id IS NOT NULL
               AND (
                   last_sync_at IS NULL
                   OR last_sync_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
               )
             LIMIT 1",
            $user->ID
        ), ARRAY_A);

        if ($connection) {
            // Schedule immediate async sync (don't block login)
            wp_schedule_single_event(
                time(),
                'pit_calendar_sync_user',
                [$user->ID, $connection['provider']]
            );
        }
    }

    /**
     * Sync single user (for async execution)
     */
    public static function sync_single_user($user_id, $provider) {
        if (!class_exists('PIT_Calendar_Sync_Service')) {
            return;
        }

        PIT_Calendar_Sync_Service::sync_user($user_id, $provider);
    }

    /**
     * Unschedule cron on deactivation
     */
    public static function deactivate() {
        $timestamp = wp_next_scheduled(self::HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK);
        }
    }
}

// Add custom cron interval
add_filter('cron_schedules', ['PIT_Calendar_Sync_Job', 'add_cron_interval']);

// Hook for single user sync
add_action('pit_calendar_sync_user', ['PIT_Calendar_Sync_Job', 'sync_single_user'], 10, 2);
