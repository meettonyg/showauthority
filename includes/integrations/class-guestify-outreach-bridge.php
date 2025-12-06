<?php
/**
 * Bridge between Interview Tracker and Guestify Outreach Plugin
 *
 * This class provides a THIN wrapper that calls existing Guestify Outreach
 * classes directly. It does NOT duplicate any functionality.
 *
 * @package Podcast_Influence_Tracker
 * @since 4.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Guestify_Outreach_Bridge {

    /**
     * Check if Guestify Outreach plugin is active
     */
    public static function is_active(): bool {
        return class_exists('Guestify_Outreach_Email_Sender');
    }

    /**
     * Check if Guestify Outreach is configured (has API key)
     */
    public static function is_configured(): bool {
        if (!self::is_active()) {
            return false;
        }

        $settings = get_option('guestify_outreach_settings', []);
        return !empty($settings['brevo_api_key']);
    }

    /**
     * Send email using Guestify Outreach's existing sender
     *
     * @param int   $appearance_id  PIT appearance/opportunity ID
     * @param array $args           Email arguments
     * @return array Result from Guestify Outreach sender
     */
    public static function send_email(int $appearance_id, array $args): array {
        if (!self::is_active()) {
            return [
                'success' => false,
                'message' => 'Guestify Outreach plugin is not active. Please install and activate it.'
            ];
        }

        if (!self::is_configured()) {
            return [
                'success' => false,
                'message' => 'Brevo API key not configured in Guestify Outreach settings.'
            ];
        }

        // Instantiate the EXISTING sender from Guestify Outreach
        $sender = new Guestify_Outreach_Email_Sender();

        // Call send_email with interview_entry_id mapped to appearance_id
        // The existing sender already:
        // - Adds tracking pixel
        // - Saves to guestify_messages table
        // - Returns tracking_id
        $result = $sender->send_email([
            'to_email'           => sanitize_email($args['to_email']),
            'to_name'            => sanitize_text_field($args['to_name'] ?? ''),
            'subject'            => sanitize_text_field($args['subject']),
            'html_content'       => wp_kses_post($args['html_content']),
            'template_id'        => absint($args['template_id'] ?? 0) ?: null,
            'interview_entry_id' => $appearance_id,  // THIS IS THE LINK
            'campaign_id'        => null,            // Ad-hoc email, not campaign
            'campaign_step'      => null,
        ]);

        // Log to PIT Activity Feed (for UI convenience only)
        if (!empty($result['success'])) {
            self::log_to_activity_feed($appearance_id, $args['subject'], $args['to_email']);
        }

        return $result;
    }

    /**
     * Get message history from Guestify Outreach's existing table
     *
     * @param int $appearance_id PIT appearance/opportunity ID
     * @return array Messages
     */
    public static function get_messages(int $appearance_id): array {
        if (!self::is_active()) {
            return [];
        }

        global $wpdb;

        // Query the EXISTING guestify_messages table
        $table = $wpdb->prefix . 'guestify_messages';

        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$table_exists) {
            return [];
        }

        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT
                id,
                recipient_email,
                recipient_name,
                subject_line,
                status,
                sent_at,
                brevo_message_id,
                tracking_id
             FROM {$table}
             WHERE interview_entry_id = %d
             ORDER BY sent_at DESC
             LIMIT 50",
            $appearance_id
        ));

        if (!$messages) {
            return [];
        }

        // Get open/click events from guestify_message_events
        $events_table = $wpdb->prefix . 'guestify_message_events';
        $events_table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $events_table));

        $formatted = [];
        foreach ($messages as $msg) {
            $open_count = 0;
            $first_opened = null;
            $clicked = false;

            // Get events for this message if events table exists
            if ($events_table_exists) {
                $events = $wpdb->get_results($wpdb->prepare(
                    "SELECT event_type, event_timestamp
                     FROM {$events_table}
                     WHERE message_id = %d
                     ORDER BY event_timestamp ASC",
                    $msg->id
                ));

                foreach ($events as $event) {
                    if ($event->event_type === 'open') {
                        $open_count++;
                        if (!$first_opened) {
                            $first_opened = $event->event_timestamp;
                        }
                    }
                    if ($event->event_type === 'click') {
                        $clicked = true;
                    }
                }
            }

            $formatted[] = [
                'id'              => (int) $msg->id,
                'to_email'        => $msg->recipient_email,
                'to_name'         => $msg->recipient_name,
                'subject'         => $msg->subject_line,
                'status'          => $msg->status,
                'sent_at'         => $msg->sent_at,
                'sent_at_human'   => human_time_diff(strtotime($msg->sent_at)) . ' ago',
                'is_opened'       => $open_count > 0,
                'open_count'      => $open_count,
                'first_opened_at' => $first_opened,
                'is_clicked'      => $clicked,
            ];
        }

        return $formatted;
    }

    /**
     * Get templates from Guestify Outreach's existing table
     *
     * @return array Templates
     */
    public static function get_templates(): array {
        if (!self::is_active()) {
            return [];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'guestify_email_templates';

        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$table_exists) {
            return [];
        }

        $user_id = get_current_user_id();

        // Get user's templates and default templates
        $templates = $wpdb->get_results($wpdb->prepare(
            "SELECT
                id,
                template_name,
                category,
                subject,
                body_html,
                variables_schema,
                is_default
             FROM {$table}
             WHERE (user_id = %d OR user_id = 0)
               AND is_active = 1
             ORDER BY is_default DESC, template_name ASC",
            $user_id
        ));

        if (!$templates) {
            return [];
        }

        return array_map(function($t) {
            return [
                'id'         => (int) $t->id,
                'name'       => $t->template_name,
                'category'   => $t->category,
                'subject'    => $t->subject,
                'body_html'  => $t->body_html,
                'variables'  => json_decode($t->variables_schema, true) ?: [],
                'is_default' => (bool) $t->is_default,
            ];
        }, $templates);
    }

    /**
     * Get email statistics for an appearance
     *
     * @param int $appearance_id PIT appearance/opportunity ID
     * @return array Stats
     */
    public static function get_stats(int $appearance_id): array {
        if (!self::is_active()) {
            return [
                'total_sent' => 0,
                'opened' => 0,
                'clicked' => 0,
            ];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'guestify_messages';

        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$table_exists) {
            return [
                'total_sent' => 0,
                'opened' => 0,
                'clicked' => 0,
            ];
        }

        $total_sent = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE interview_entry_id = %d",
            $appearance_id
        ));

        // Get opened/clicked counts from events table
        $events_table = $wpdb->prefix . 'guestify_message_events';
        $events_table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $events_table));

        $opened = 0;
        $clicked = 0;

        if ($events_table_exists) {
            $opened = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT m.id)
                 FROM {$table} m
                 INNER JOIN {$events_table} e ON m.id = e.message_id
                 WHERE m.interview_entry_id = %d AND e.event_type = 'open'",
                $appearance_id
            ));

            $clicked = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT m.id)
                 FROM {$table} m
                 INNER JOIN {$events_table} e ON m.id = e.message_id
                 WHERE m.interview_entry_id = %d AND e.event_type = 'click'",
                $appearance_id
            ));
        }

        return [
            'total_sent' => $total_sent,
            'opened' => $opened,
            'clicked' => $clicked,
        ];
    }

    /**
     * Log email send to PIT activity feed (for UI convenience only)
     * This is NOT for tracking - that's handled by Guestify Outreach
     */
    private static function log_to_activity_feed(int $appearance_id, string $subject, string $to_email): void {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_appearance_notes';

        // Check if notes table exists
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$exists) {
            return;
        }

        $wpdb->insert($table, [
            'appearance_id' => $appearance_id,
            'title'         => 'Email sent: ' . wp_trim_words($subject, 8),
            'content'       => sprintf('Sent to %s', $to_email),
            'note_type'     => 'email',
            'created_by'    => get_current_user_id(),
            'created_at'    => current_time('mysql'),
        ]);
    }
}
