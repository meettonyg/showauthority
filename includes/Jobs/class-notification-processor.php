<?php
/**
 * Notification Processor Job
 *
 * Processes task reminders, overdue tasks, and upcoming interviews
 * to create in-app and email notifications.
 *
 * @package Podcast_Influence_Tracker
 * @since 3.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Notification_Processor {

    /**
     * Initialize the processor
     */
    public static function init() {
        // Nothing needed here - cron action is hooked in main plugin file
    }

    /**
     * Main processing function - called by cron
     */
    public static function process() {
        // Process task reminders
        self::process_task_reminders();

        // Process overdue tasks
        self::process_overdue_tasks();

        // Process upcoming interviews (within 24 hours)
        self::process_upcoming_interviews();

        // Clean up old notifications (older than 30 days)
        self::cleanup_old_notifications();
    }

    /**
     * Process task reminders
     */
    private static function process_task_reminders() {
        $today = current_time('Y-m-d');

        // Use repository pattern for better maintainability
        $tasks = PIT_Task_Repository::get_due_reminders($today);

        foreach ($tasks as $task) {
            self::create_task_reminder_notification($task);
        }
    }

    /**
     * Process overdue tasks
     */
    private static function process_overdue_tasks() {
        $today = current_time('Y-m-d');

        // Use repository pattern for better maintainability
        $tasks = PIT_Task_Repository::get_overdue_tasks($today);

        foreach ($tasks as $task) {
            self::create_overdue_notification($task);
        }
    }

    /**
     * Process upcoming interviews (recording dates within 24 hours)
     */
    private static function process_upcoming_interviews() {
        // Use WordPress timezone-aware function
        $today = current_time('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day', strtotime($today)));

        // Use repository pattern for better maintainability
        $appearances = PIT_Appearance_Repository::get_upcoming_interviews($tomorrow);

        foreach ($appearances as $appearance) {
            self::create_interview_notification($appearance, 'appearance');
        }

        // Check opportunities
        $opportunities = PIT_Opportunity_Repository::get_upcoming_interviews($tomorrow);

        foreach ($opportunities as $opp) {
            self::create_interview_notification($opp, 'opportunity');
        }
    }

    /**
     * Create a task reminder notification
     */
    private static function create_task_reminder_notification($task) {
        $user_id = $task['user_id'];
        $podcast_name = $task['podcast_name'] ?: 'Unknown Podcast';

        $notification_data = [
            'type'          => 'task_reminder',
            'source'        => 'task',
            'source_id'     => $task['id'],
            'title'         => 'Task Reminder: ' . $task['title'],
            'message'       => "Reminder for your task on {$podcast_name}",
            'action_url'    => home_url('/app/tasks/'),
            'action_label'  => 'View Tasks',
            'task_id'       => $task['id'],
            'appearance_id' => $task['appearance_id'],
            'meta'          => [
                'priority'     => $task['priority'],
                'podcast_name' => $podcast_name,
                'due_date'     => $task['due_date'],
            ],
        ];

        $notification_id = PIT_REST_Notifications::create_notification($user_id, $notification_data);

        // Send email and push notification if enabled
        if ($notification_id) {
            self::maybe_send_email($user_id, 'task_reminder', $notification_data);
            self::maybe_send_push($user_id, $notification_id, $notification_data);
        }
    }

    /**
     * Create an overdue task notification
     */
    private static function create_overdue_notification($task) {
        $user_id = $task['user_id'];
        $podcast_name = $task['podcast_name'] ?: 'Unknown Podcast';

        $notification_data = [
            'type'          => 'task_overdue',
            'source'        => 'task',
            'source_id'     => $task['id'],
            'title'         => 'Overdue: ' . $task['title'],
            'message'       => "This task for {$podcast_name} is now overdue",
            'action_url'    => home_url('/app/tasks/'),
            'action_label'  => 'View Tasks',
            'task_id'       => $task['id'],
            'appearance_id' => $task['appearance_id'],
            'meta'          => [
                'priority'     => $task['priority'],
                'podcast_name' => $podcast_name,
                'due_date'     => $task['due_date'],
            ],
        ];

        $notification_id = PIT_REST_Notifications::create_notification($user_id, $notification_data);

        // Send email and push notification if enabled
        if ($notification_id) {
            self::maybe_send_email($user_id, 'overdue_task', $notification_data);
            self::maybe_send_push($user_id, $notification_id, $notification_data);
        }
    }

    /**
     * Create an upcoming interview notification
     */
    private static function create_interview_notification($record, $type) {
        $user_id = $record['user_id'];
        $podcast_name = $record['podcast_name'] ?: 'Unknown Podcast';
        $record_date = $record['record_date'];

        $notification_data = [
            'type'          => 'interview_soon',
            'source'        => 'interview',
            'source_id'     => $record['id'],
            'title'         => 'Interview Tomorrow: ' . $podcast_name,
            'message'       => "Your interview with {$podcast_name} is scheduled for tomorrow ({$record_date})",
            'action_url'    => home_url('/app/interviews/' . $record['id'] . '/'),
            'action_label'  => 'View Interview',
            'appearance_id' => $record['id'],
            'meta'          => [
                'podcast_name' => $podcast_name,
                'record_date'  => $record_date,
                'type'         => $type,
            ],
        ];

        $notification_id = PIT_REST_Notifications::create_notification($user_id, $notification_data);

        // Send email and push notification if enabled
        if ($notification_id) {
            self::maybe_send_email($user_id, 'interview_reminder', $notification_data);
            self::maybe_send_push($user_id, $notification_id, $notification_data);
        }
    }

    /**
     * Maybe send a push notification
     */
    private static function maybe_send_push($user_id, $notification_id, $data) {
        // Check if push notifications are configured
        if (!PIT_Web_Push_Sender::is_configured()) {
            return;
        }

        // Check user notification settings for push
        $settings = get_user_meta($user_id, 'pit_notification_settings', true);
        $settings = is_array($settings) ? $settings : [];

        // Check if push is enabled (default: enabled if not explicitly disabled)
        if (isset($settings['push_enabled']) && !$settings['push_enabled']) {
            return;
        }

        // Prepare push payload
        $payload = [
            'title'           => $data['title'],
            'body'            => $data['message'] ?? '',
            'notification_id' => $notification_id,
            'url'             => $data['action_url'] ?? home_url('/app/'),
            'tag'             => $data['type'] ?? 'guestify-notification',
        ];

        // Send to all user's subscriptions
        PIT_Web_Push_Sender::send_to_user($user_id, $payload);
    }

    /**
     * Maybe send an email notification
     */
    private static function maybe_send_email($user_id, $email_type, $data) {
        // Get user notification settings
        $settings = get_user_meta($user_id, 'pit_notification_settings', true);
        $settings = is_array($settings) ? $settings : [];

        // Check if email is enabled globally
        if (isset($settings['email_enabled']) && !$settings['email_enabled']) {
            return;
        }

        // Check specific email type settings
        $type_setting_map = [
            'task_reminder'      => 'email_task_reminders',
            'overdue_task'       => 'email_overdue_tasks',
            'interview_reminder' => 'email_interview_reminders',
        ];

        $setting_key = $type_setting_map[$email_type] ?? null;
        if ($setting_key && isset($settings[$setting_key]) && !$settings[$setting_key]) {
            return;
        }

        // Get user email
        $user = get_userdata($user_id);
        if (!$user || !$user->user_email) {
            return;
        }

        // Send the email
        self::send_notification_email($user->user_email, $user->display_name, $email_type, $data);
    }

    /**
     * Send a notification email
     */
    private static function send_notification_email($email, $name, $type, $data) {
        $subject = self::get_email_subject($type, $data);
        $body = self::get_email_body($type, $data, $name);

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: Guestify <noreply@guestify.ai>',
        ];

        wp_mail($email, $subject, $body, $headers);

        // Update notification record to mark email as sent
        if (!empty($data['source_id'])) {
            global $wpdb;
            $table = $wpdb->prefix . 'pit_notifications';
            $wpdb->update(
                $table,
                [
                    'email_sent'    => 1,
                    'email_sent_at' => current_time('mysql'),
                ],
                [
                    'source'    => $data['source'],
                    'source_id' => $data['source_id'],
                    'type'      => $data['type'],
                ]
            );
        }
    }

    /**
     * Get email subject based on type
     */
    private static function get_email_subject($type, $data) {
        switch ($type) {
            case 'task_reminder':
                return 'Reminder: ' . $data['title'];
            case 'overdue_task':
                return 'Overdue Task: ' . ($data['meta']['podcast_name'] ?? 'Task');
            case 'interview_reminder':
                return 'Interview Tomorrow: ' . ($data['meta']['podcast_name'] ?? 'Podcast');
            default:
                return 'Guestify Notification';
        }
    }

    /**
     * Get email body based on type
     *
     * Loads the email template from templates/emails/notification-default.php
     */
    private static function get_email_body($type, $data, $name) {
        // Template variables
        $title = $data['title'];
        $message = $data['message'];
        $action_url = $data['action_url'] ?? home_url('/app/');
        $action_label = $data['action_label'] ?? 'View in Guestify';
        $meta = $data['meta'] ?? [];

        // Allow custom templates per type
        $template_file = apply_filters(
            'pit_notification_email_template',
            PIT_PLUGIN_DIR . 'templates/emails/notification-default.php',
            $type
        );

        // Load template with output buffering
        ob_start();
        if (file_exists($template_file)) {
            include $template_file;
        } else {
            // Fallback to default if custom template not found
            include PIT_PLUGIN_DIR . 'templates/emails/notification-default.php';
        }
        $body = ob_get_clean();

        return $body;
    }

    /**
     * Cleanup old notifications (older than 30 days)
     */
    private static function cleanup_old_notifications() {
        global $wpdb;

        $table = $wpdb->prefix . 'pit_notifications';
        // Use WordPress timezone-aware time calculation
        $cutoff = date('Y-m-d H:i:s', strtotime('-30 days', current_time('timestamp')));

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s AND is_dismissed = 1",
            $cutoff
        ));
    }
}
