<?php
/**
 * Task Repository
 *
 * Handles database operations for appearance tasks,
 * including queries for reminders and overdue notifications.
 *
 * @package Podcast_Influence_Tracker
 * @since 3.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Task_Repository {

    /**
     * Get tasks with pending reminders (not yet notified)
     *
     * Returns tasks where reminder_date has passed and no notification exists.
     *
     * @param string $as_of_date Date to check reminders against (Y-m-d format)
     * @return array
     */
    public static function get_due_reminders($as_of_date) {
        global $wpdb;

        $tasks_table = $wpdb->prefix . 'pit_appearance_tasks';
        $notifications_table = $wpdb->prefix . 'pit_notifications';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';
        $appearances_table = $wpdb->prefix . 'pit_guest_appearances';
        $opportunities_table = $wpdb->prefix . 'pit_opportunities';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT t.*,
                    COALESCE(a.podcast_id, o.podcast_id) as podcast_id,
                    COALESCE(p1.title, p2.title) as podcast_name
             FROM {$tasks_table} t
             LEFT JOIN {$appearances_table} a ON t.appearance_id = a.id
             LEFT JOIN {$opportunities_table} o ON t.appearance_id = o.id AND a.id IS NULL
             LEFT JOIN {$podcasts_table} p1 ON a.podcast_id = p1.id
             LEFT JOIN {$podcasts_table} p2 ON o.podcast_id = p2.id
             WHERE t.reminder_date <= %s
               AND t.is_done = 0
               AND t.id NOT IN (
                   SELECT source_id FROM {$notifications_table}
                   WHERE source = 'task' AND type = 'task_reminder'
               )",
            $as_of_date
        ), ARRAY_A);
    }

    /**
     * Get overdue tasks (not yet notified)
     *
     * Returns tasks where due_date has passed and no overdue notification exists.
     *
     * @param string $as_of_date Date to check against (tasks due before this date are overdue)
     * @return array
     */
    public static function get_overdue_tasks($as_of_date) {
        global $wpdb;

        $tasks_table = $wpdb->prefix . 'pit_appearance_tasks';
        $notifications_table = $wpdb->prefix . 'pit_notifications';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';
        $appearances_table = $wpdb->prefix . 'pit_guest_appearances';
        $opportunities_table = $wpdb->prefix . 'pit_opportunities';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT t.*,
                    COALESCE(a.podcast_id, o.podcast_id) as podcast_id,
                    COALESCE(p1.title, p2.title) as podcast_name
             FROM {$tasks_table} t
             LEFT JOIN {$appearances_table} a ON t.appearance_id = a.id
             LEFT JOIN {$opportunities_table} o ON t.appearance_id = o.id AND a.id IS NULL
             LEFT JOIN {$podcasts_table} p1 ON a.podcast_id = p1.id
             LEFT JOIN {$podcasts_table} p2 ON o.podcast_id = p2.id
             WHERE t.due_date < %s
               AND t.is_done = 0
               AND t.id NOT IN (
                   SELECT source_id FROM {$notifications_table}
                   WHERE source = 'task' AND type = 'task_overdue'
               )",
            $as_of_date
        ), ARRAY_A);
    }

    /**
     * Get a single task by ID
     *
     * @param int $task_id Task ID
     * @return object|null
     */
    public static function get($task_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_appearance_tasks';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $task_id
        ));
    }

    /**
     * Get tasks for an appearance
     *
     * @param int $appearance_id Appearance ID
     * @return array
     */
    public static function get_for_appearance($appearance_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_appearance_tasks';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE appearance_id = %d ORDER BY due_date ASC",
            $appearance_id
        ), ARRAY_A);
    }

    /**
     * Get tasks for a user
     *
     * @param int $user_id User ID
     * @param bool $include_completed Include completed tasks
     * @return array
     */
    public static function get_for_user($user_id, $include_completed = false) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_appearance_tasks';

        $completed_clause = $include_completed ? '' : 'AND is_done = 0';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d {$completed_clause} ORDER BY due_date ASC",
            $user_id
        ), ARRAY_A);
    }
}
