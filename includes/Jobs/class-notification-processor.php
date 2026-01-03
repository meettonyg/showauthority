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
        global $wpdb;

        $tasks_table = $wpdb->prefix . 'pit_appearance_tasks';
        $notifications_table = $wpdb->prefix . 'pit_notifications';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';
        $appearances_table = $wpdb->prefix . 'pit_guest_appearances';
        $opportunities_table = $wpdb->prefix . 'pit_opportunities';

        // Get tasks with reminder_date = today that haven't been notified
        $today = current_time('Y-m-d');

        $tasks = $wpdb->get_results($wpdb->prepare(
            "SELECT t.*,
                    COALESCE(a.podcast_id, o.podcast_id) as podcast_id,
                    COALESCE(p1.title, p2.title) as podcast_name
             FROM {$tasks_table} t
             LEFT JOIN {$appearances_table} a ON t.appearance_id = a.id
             LEFT JOIN {$opportunities_table} o ON t.appearance_id = o.id AND a.id IS NULL
             LEFT JOIN {$podcasts_table} p1 ON a.podcast_id = p1.id
             LEFT JOIN {$podcasts_table} p2 ON o.podcast_id = p2.id
             WHERE t.reminder_date = %s
               AND t.is_done = 0
               AND t.id NOT IN (
                   SELECT source_id FROM {$notifications_table}
                   WHERE source = 'task' AND type = 'task_reminder'
                   AND DATE(created_at) = %s
               )",
            $today,
            $today
        ), ARRAY_A);

        foreach ($tasks as $task) {
            self::create_task_reminder_notification($task);
        }
    }

    /**
     * Process overdue tasks
     */
    private static function process_overdue_tasks() {
        global $wpdb;

        $tasks_table = $wpdb->prefix . 'pit_appearance_tasks';
        $notifications_table = $wpdb->prefix . 'pit_notifications';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';
        $appearances_table = $wpdb->prefix . 'pit_guest_appearances';
        $opportunities_table = $wpdb->prefix . 'pit_opportunities';

        // Get tasks that became overdue today
        $today = current_time('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day', strtotime($today)));

        $tasks = $wpdb->get_results($wpdb->prepare(
            "SELECT t.*,
                    COALESCE(a.podcast_id, o.podcast_id) as podcast_id,
                    COALESCE(p1.title, p2.title) as podcast_name
             FROM {$tasks_table} t
             LEFT JOIN {$appearances_table} a ON t.appearance_id = a.id
             LEFT JOIN {$opportunities_table} o ON t.appearance_id = o.id AND a.id IS NULL
             LEFT JOIN {$podcasts_table} p1 ON a.podcast_id = p1.id
             LEFT JOIN {$podcasts_table} p2 ON o.podcast_id = p2.id
             WHERE t.due_date = %s
               AND t.is_done = 0
               AND t.id NOT IN (
                   SELECT source_id FROM {$notifications_table}
                   WHERE source = 'task' AND type = 'task_overdue'
                   AND source_id = t.id
               )",
            $yesterday
        ), ARRAY_A);

        foreach ($tasks as $task) {
            self::create_overdue_notification($task);
        }
    }

    /**
     * Process upcoming interviews (recording dates within 24 hours)
     */
    private static function process_upcoming_interviews() {
        global $wpdb;

        $appearances_table = $wpdb->prefix . 'pit_guest_appearances';
        $opportunities_table = $wpdb->prefix . 'pit_opportunities';
        $notifications_table = $wpdb->prefix . 'pit_notifications';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';

        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        // Check guest_appearances
        $appearances = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, p.title as podcast_name
             FROM {$appearances_table} a
             JOIN {$podcasts_table} p ON a.podcast_id = p.id
             WHERE a.record_date = %s
               AND a.id NOT IN (
                   SELECT appearance_id FROM {$notifications_table}
                   WHERE type = 'interview_soon' AND appearance_id IS NOT NULL
                   AND DATE(created_at) = CURDATE()
               )",
            $tomorrow
        ), ARRAY_A);

        foreach ($appearances as $appearance) {
            self::create_interview_notification($appearance, 'appearance');
        }

        // Check opportunities
        $opportunities = $wpdb->get_results($wpdb->prepare(
            "SELECT o.*, p.title as podcast_name
             FROM {$opportunities_table} o
             JOIN {$podcasts_table} p ON o.podcast_id = p.id
             WHERE o.record_date = %s
               AND o.id NOT IN (
                   SELECT appearance_id FROM {$notifications_table}
                   WHERE type = 'interview_soon' AND appearance_id IS NOT NULL
                   AND DATE(created_at) = CURDATE()
               )",
            $tomorrow
        ), ARRAY_A);

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

        // Send email if enabled
        if ($notification_id) {
            self::maybe_send_email($user_id, 'task_reminder', $notification_data);
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

        // Send email if enabled
        if ($notification_id) {
            self::maybe_send_email($user_id, 'overdue_task', $notification_data);
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

        // Send email if enabled
        if ($notification_id) {
            self::maybe_send_email($user_id, 'interview_reminder', $notification_data);
        }
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
     */
    private static function get_email_body($type, $data, $name) {
        $action_url = $data['action_url'] ?? home_url('/app/');
        $action_label = $data['action_label'] ?? 'View in Guestify';

        $body = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; border-radius: 10px 10px 0 0;">
        <h1 style="color: white; margin: 0; font-size: 24px;">Guestify</h1>
    </div>
    <div style="background: #ffffff; padding: 30px; border: 1px solid #e2e8f0; border-top: none; border-radius: 0 0 10px 10px;">
        <p style="margin-top: 0;">Hi ' . esc_html($name) . ',</p>
        <h2 style="color: #1e293b; margin-top: 20px;">' . esc_html($data['title']) . '</h2>
        <p style="color: #64748b;">' . esc_html($data['message']) . '</p>';

        // Add metadata based on type
        if (!empty($data['meta'])) {
            $meta = $data['meta'];
            if (!empty($meta['podcast_name'])) {
                $body .= '<p><strong>Podcast:</strong> ' . esc_html($meta['podcast_name']) . '</p>';
            }
            if (!empty($meta['due_date'])) {
                $body .= '<p><strong>Due Date:</strong> ' . esc_html($meta['due_date']) . '</p>';
            }
            if (!empty($meta['record_date'])) {
                $body .= '<p><strong>Interview Date:</strong> ' . esc_html($meta['record_date']) . '</p>';
            }
            if (!empty($meta['priority'])) {
                $priority_colors = [
                    'urgent' => '#ef4444',
                    'high'   => '#f97316',
                    'medium' => '#22c55e',
                    'low'    => '#0ea5e9',
                ];
                $color = $priority_colors[$meta['priority']] ?? '#6b7280';
                $body .= '<p><strong>Priority:</strong> <span style="color: ' . $color . '; font-weight: 600;">' . ucfirst(esc_html($meta['priority'])) . '</span></p>';
            }
        }

        $body .= '
        <div style="margin-top: 30px;">
            <a href="' . esc_url($action_url) . '" style="display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: 600;">' . esc_html($action_label) . '</a>
        </div>
        <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 30px 0;">
        <p style="color: #94a3b8; font-size: 12px; margin-bottom: 0;">
            You\'re receiving this email because you have notifications enabled in Guestify.
            <a href="' . esc_url(home_url('/app/settings/')) . '" style="color: #667eea;">Manage notification settings</a>
        </p>
    </div>
</body>
</html>';

        return $body;
    }

    /**
     * Cleanup old notifications (older than 30 days)
     */
    private static function cleanup_old_notifications() {
        global $wpdb;

        $table = $wpdb->prefix . 'pit_notifications';
        $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s AND is_dismissed = 1",
            $cutoff
        ));
    }
}
