<?php
/**
 * Bridge between ShowAuthority and Guestify Outreach Plugin
 *
 * This class provides a clean interface for email messaging functionality.
 * It supports two modes:
 *
 * 1. Public API Mode (preferred): Uses Guestify_Outreach_Public_API class
 *    when available (Outreach plugin v2.0+)
 *
 * 2. Legacy Mode: Direct database queries for backward compatibility
 *    with older Outreach plugin versions
 *
 * @package ShowAuthority
 * @since 4.2.0
 * @updated 5.0.0 - Added Public API support, entity_type support
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Guestify_Outreach_Bridge {

    /**
     * Minimum version of Outreach plugin that supports Public API
     */
    const MIN_API_VERSION = '2.0.0';

    /**
     * Entity type identifier for ShowAuthority appearances
     */
    const ENTITY_TYPE = 'appearance';

    /**
     * Check if Guestify Outreach plugin is active
     *
     * Prefers version constant check over class_exists for reliability.
     */
    public static function is_active(): bool {
        // Preferred: Check for version constant (Outreach v2.0+)
        if (defined('GUESTIFY_OUTREACH_VERSION')) {
            return true;
        }

        // Fallback: Check for sender class (legacy)
        return class_exists('Guestify_Outreach_Email_Sender');
    }

    /**
     * Check if Guestify Outreach is configured (has API key)
     */
    public static function is_configured(): bool {
        if (!self::is_active()) {
            return false;
        }

        // Use Public API if available
        if (self::has_public_api()) {
            return Guestify_Outreach_Public_API::is_configured();
        }

        // Fallback: Check settings directly
        $settings = get_option('guestify_outreach_settings', []);
        return !empty($settings['brevo_api_key']);
    }

    /**
     * Check if Public API is available
     */
    public static function has_public_api(): bool {
        if (!defined('GUESTIFY_OUTREACH_VERSION')) {
            return false;
        }

        return version_compare(GUESTIFY_OUTREACH_VERSION, self::MIN_API_VERSION, '>=')
            && class_exists('Guestify_Outreach_Public_API');
    }

    /**
     * Get the Outreach plugin version
     */
    public static function get_version(): ?string {
        if (defined('GUESTIFY_OUTREACH_VERSION')) {
            return GUESTIFY_OUTREACH_VERSION;
        }
        return null;
    }

    /**
     * Send email using Guestify Outreach
     *
     * @param int   $appearance_id  ShowAuthority appearance/opportunity ID
     * @param array $args           Email arguments
     * @return array Result with success, message, and optional tracking_id
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

        // Use Public API if available
        if (self::has_public_api()) {
            $result = Guestify_Outreach_Public_API::send_email([
                'entity_id'    => $appearance_id,
                'entity_type'  => self::ENTITY_TYPE,
                'to_email'     => sanitize_email($args['to_email']),
                'to_name'      => sanitize_text_field($args['to_name'] ?? ''),
                'subject'      => sanitize_text_field($args['subject']),
                'html_content' => wp_kses_post($args['html_content']),
                'template_id'  => absint($args['template_id'] ?? 0) ?: null,
            ]);
        } else {
            // Legacy: Direct sender instantiation
            $result = self::send_email_legacy($appearance_id, $args);
        }

        // Log to activity feed on success
        if (!empty($result['success'])) {
            self::log_to_activity_feed($appearance_id, $args['subject'], $args['to_email']);
        }

        return $result;
    }

    /**
     * Legacy email sending via direct class instantiation
     */
    private static function send_email_legacy(int $appearance_id, array $args): array {
        if (!class_exists('Guestify_Outreach_Email_Sender')) {
            return [
                'success' => false,
                'message' => 'Email sender class not found.'
            ];
        }

        $sender = new Guestify_Outreach_Email_Sender();

        return $sender->send_email([
            'to_email'           => sanitize_email($args['to_email']),
            'to_name'            => sanitize_text_field($args['to_name'] ?? ''),
            'subject'            => sanitize_text_field($args['subject']),
            'html_content'       => wp_kses_post($args['html_content']),
            'template_id'        => absint($args['template_id'] ?? 0) ?: null,
            // Legacy field name - will be migrated to entity_id in Outreach v2.0
            'interview_entry_id' => $appearance_id,
            'campaign_id'        => null,
            'campaign_step'      => null,
        ]);
    }

    /**
     * Get message history for an appearance
     *
     * @param int $appearance_id ShowAuthority appearance/opportunity ID
     * @return array Messages with tracking data
     */
    public static function get_messages(int $appearance_id): array {
        if (!self::is_active()) {
            return [];
        }

        // Use Public API if available
        if (self::has_public_api()) {
            return Guestify_Outreach_Public_API::get_messages(
                $appearance_id,
                self::ENTITY_TYPE
            );
        }

        // Legacy: Direct database query
        return self::get_messages_legacy($appearance_id);
    }

    /**
     * Legacy message retrieval via direct database query
     */
    private static function get_messages_legacy(int $appearance_id): array {
        global $wpdb;

        $table = $wpdb->prefix . 'guestify_messages';

        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$table_exists) {
            return [];
        }

        // Try entity_id first (v2.0 schema), fall back to interview_entry_id
        $id_column = self::get_entity_id_column($table);

        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT
                id,
                recipient_email,
                recipient_name,
                subject,
                status,
                sent_at,
                brevo_message_id
             FROM {$table}
             WHERE {$id_column} = %d
             ORDER BY sent_at DESC
             LIMIT 50",
            $appearance_id
        ));

        if (!$messages) {
            return [];
        }

        // Get open/click events
        $events_table = $wpdb->prefix . 'guestify_message_events';
        $events_table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $events_table));

        $formatted = [];
        foreach ($messages as $msg) {
            $open_count = 0;
            $first_opened = null;
            $clicked = false;

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
                'subject'         => $msg->subject,
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
     * Get templates available to current user
     *
     * @return array Templates
     */
    public static function get_templates(): array {
        if (!self::is_active()) {
            return [];
        }

        // Use Public API if available
        if (self::has_public_api()) {
            return Guestify_Outreach_Public_API::get_templates();
        }

        // Legacy: Direct database query
        return self::get_templates_legacy();
    }

    /**
     * Legacy template retrieval via direct database query
     */
    private static function get_templates_legacy(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_email_templates';

        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$table_exists) {
            return [];
        }

        $user_id = get_current_user_id();

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
     * @param int $appearance_id ShowAuthority appearance/opportunity ID
     * @return array Stats with total_sent, opened, clicked
     */
    public static function get_stats(int $appearance_id): array {
        $default_stats = [
            'total_sent' => 0,
            'opened' => 0,
            'clicked' => 0,
        ];

        if (!self::is_active()) {
            return $default_stats;
        }

        // Use Public API if available
        if (self::has_public_api()) {
            return Guestify_Outreach_Public_API::get_stats(
                $appearance_id,
                self::ENTITY_TYPE
            );
        }

        // Legacy: Direct database query
        return self::get_stats_legacy($appearance_id);
    }

    /**
     * Legacy stats retrieval via direct database query
     */
    private static function get_stats_legacy(int $appearance_id): array {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_messages';

        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$table_exists) {
            return [
                'total_sent' => 0,
                'opened' => 0,
                'clicked' => 0,
            ];
        }

        $id_column = self::get_entity_id_column($table);

        $total_sent = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE {$id_column} = %d",
            $appearance_id
        ));

        $events_table = $wpdb->prefix . 'guestify_message_events';
        $events_table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $events_table));

        $opened = 0;
        $clicked = 0;

        if ($events_table_exists) {
            $opened = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT m.id)
                 FROM {$table} m
                 INNER JOIN {$events_table} e ON m.id = e.message_id
                 WHERE m.{$id_column} = %d AND e.event_type = 'open'",
                $appearance_id
            ));

            $clicked = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT m.id)
                 FROM {$table} m
                 INNER JOIN {$events_table} e ON m.id = e.message_id
                 WHERE m.{$id_column} = %d AND e.event_type = 'click'",
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
     * Determine which ID column to use in the messages table
     *
     * Supports both legacy (interview_entry_id) and new (entity_id) schemas.
     */
    private static function get_entity_id_column(string $table): string {
        global $wpdb;

        // Check if entity_id column exists (v2.0 schema)
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table}");
        $column_names = array_map(fn($col) => $col->Field, $columns);

        if (in_array('entity_id', $column_names)) {
            return 'entity_id';
        }

        // Fallback to legacy column
        return 'interview_entry_id';
    }

    /**
     * Log email send to activity feed (for UI convenience only)
     * Tracking is handled by Guestify Outreach, not here.
     */
    private static function log_to_activity_feed(int $appearance_id, string $subject, string $to_email): void {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_appearance_notes';

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
