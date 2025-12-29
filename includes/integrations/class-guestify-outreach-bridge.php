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
 * @updated 5.1.0 - Added campaign management support (v2.0 API)
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
     * Minimum API version constant that must be defined
     */
    const MIN_API_VERSION_CONST = 1;

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
     *
     * Checks both version constant and API version constant for compatibility.
     */
    public static function has_public_api(): bool {
        // Check class existence upfront to avoid repetition
        if (!class_exists('Guestify_Outreach_Public_API') || !defined('GUESTIFY_OUTREACH_VERSION')) {
            return false;
        }

        // Check API version constant (preferred method)
        if (defined('GUESTIFY_OUTREACH_API_VERSION')) {
            return GUESTIFY_OUTREACH_API_VERSION >= self::MIN_API_VERSION_CONST;
        }

        // Fallback to version string comparison
        return version_compare(GUESTIFY_OUTREACH_VERSION, self::MIN_API_VERSION, '>=');
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
     * Get the Outreach API version
     */
    public static function get_api_version(): ?int {
        if (defined('GUESTIFY_OUTREACH_API_VERSION')) {
            return (int) GUESTIFY_OUTREACH_API_VERSION;
        }
        return null;
    }

    /**
     * Check if entity type is valid via Guestify helper function
     */
    public static function is_valid_entity_type(string $type): bool {
        if (function_exists('guestify_is_valid_entity_type')) {
            return guestify_is_valid_entity_type($type);
        }

        // Fallback: assume 'appearance' is always valid for ShowAuthority
        return $type === self::ENTITY_TYPE;
    }

    /**
     * Get valid entity types from Guestify
     */
    public static function get_entity_types(): array {
        if (function_exists('guestify_get_entity_types')) {
            return guestify_get_entity_types();
        }

        // Fallback: return default types
        return ['formidable_entry', 'appearance', 'contact', 'lead', 'prospect', 'custom'];
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
            // Get sender info from Guestify Outreach settings
            $settings = get_option('guestify_outreach_settings', []);
            $from_email = $settings['from_email'] ?? '';
            $from_name = $settings['from_name'] ?? '';

            $result = Guestify_Outreach_Public_API::send_email([
                'entity_id'    => $appearance_id,
                'entity_type'  => self::ENTITY_TYPE,
                'to_email'     => sanitize_email($args['to_email']),
                'to_name'      => sanitize_text_field($args['to_name'] ?? ''),
                'from_email'   => sanitize_email($from_email),
                'from_name'    => sanitize_text_field($from_name),
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

    // =========================================================================
    // Campaign Management (v2.0+ API)
    // =========================================================================

    /**
     * Check if campaign management is available
     *
     * @param bool $require_configured Whether to also check if Brevo is configured
     * @return array|null Error response if not available, null if OK
     */
    private static function check_campaign_availability(bool $require_configured = false): ?array {
        if (!self::has_public_api()) {
            return [
                'success' => false,
                'message' => 'Campaign management requires Guestify Outreach v2.0 or later.'
            ];
        }

        if ($require_configured && !self::is_configured()) {
            return [
                'success' => false,
                'message' => 'Brevo API key not configured in Guestify Outreach settings.'
            ];
        }

        return null;
    }

    /**
     * Start a new email campaign
     *
     * @param array $args Campaign arguments
     * @return array Result with success, message, and optional campaign_id
     */
    public static function start_campaign(array $args): array {
        $error = self::check_campaign_availability(true);
        if ($error !== null) {
            return $error;
        }

        // Validate entity type
        $entity_type = $args['entity_type'] ?? self::ENTITY_TYPE;
        if (!self::is_valid_entity_type($entity_type)) {
            return [
                'success' => false,
                'message' => 'Invalid entity type: ' . $entity_type
            ];
        }

        // Prepare campaign data
        $campaign_args = [
            'entity_id'   => absint($args['entity_id'] ?? 0),
            'entity_type' => $entity_type,
            'name'        => sanitize_text_field($args['name'] ?? ''),
            'template_id' => absint($args['template_id'] ?? 0) ?: null,
            'schedule'    => $args['schedule'] ?? null,
            'steps'       => $args['steps'] ?? [],
        ];

        $result = Guestify_Outreach_Public_API::start_campaign($campaign_args);

        // Log to activity feed on success
        if (!empty($result['success']) && !empty($args['entity_id'])) {
            self::log_campaign_activity($args['entity_id'], 'started', $args['name'] ?? 'Campaign');
        }

        return $result;
    }

    /**
     * Pause an active campaign
     *
     * @param int $campaign_id Campaign ID
     * @return array Result with success and message
     */
    public static function pause_campaign(int $campaign_id): array {
        $error = self::check_campaign_availability();
        if ($error !== null) {
            return $error;
        }

        $result = Guestify_Outreach_Public_API::pause_campaign($campaign_id);

        if (!empty($result['success'])) {
            self::log_campaign_status_change($campaign_id, 'paused');
        }

        return $result;
    }

    /**
     * Resume a paused campaign
     *
     * @param int $campaign_id Campaign ID
     * @return array Result with success and message
     */
    public static function resume_campaign(int $campaign_id): array {
        $error = self::check_campaign_availability();
        if ($error !== null) {
            return $error;
        }

        $result = Guestify_Outreach_Public_API::resume_campaign($campaign_id);

        if (!empty($result['success'])) {
            self::log_campaign_status_change($campaign_id, 'resumed');
        }

        return $result;
    }

    /**
     * Cancel a campaign
     *
     * @param int $campaign_id Campaign ID
     * @return array Result with success and message
     */
    public static function cancel_campaign(int $campaign_id): array {
        $error = self::check_campaign_availability();
        if ($error !== null) {
            return $error;
        }

        $result = Guestify_Outreach_Public_API::cancel_campaign($campaign_id);

        if (!empty($result['success'])) {
            self::log_campaign_status_change($campaign_id, 'cancelled');
        }

        return $result;
    }

    /**
     * Get campaigns for an appearance
     *
     * @param int $appearance_id ShowAuthority appearance/opportunity ID
     * @return array List of campaigns
     */
    public static function get_campaigns(int $appearance_id): array {
        if (!self::has_public_api()) {
            return [];
        }

        if (!method_exists('Guestify_Outreach_Public_API', 'get_campaigns')) {
            return [];
        }

        return Guestify_Outreach_Public_API::get_campaigns(
            $appearance_id,
            self::ENTITY_TYPE
        );
    }

    /**
     * Get a single campaign by ID
     *
     * @param int $campaign_id Campaign ID
     * @return array|null Campaign data or null if not found
     */
    public static function get_campaign(int $campaign_id): ?array {
        if (!self::has_public_api()) {
            return null;
        }

        if (!method_exists('Guestify_Outreach_Public_API', 'get_campaign')) {
            return null;
        }

        return Guestify_Outreach_Public_API::get_campaign($campaign_id);
    }

    // =========================================================================
    // Sequence-Based Campaigns (v2.0+ API)
    // =========================================================================

    /**
     * Get available campaign sequences
     *
     * @return array List of sequences with their steps
     */
    public static function get_sequences(): array {
        if (!self::has_public_api()) {
            return [];
        }

        global $wpdb;
        $user_id = get_current_user_id();

        $sequences_table = $wpdb->prefix . 'guestify_campaign_sequences';
        $steps_table = $wpdb->prefix . 'guestify_campaign_steps';

        // Check if table exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $table_exists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $sequences_table)
        );
        if (!$table_exists) {
            return [];
        }

        // Get sequences available to user (user's own + system defaults)
        $sequences = $wpdb->get_results($wpdb->prepare(
            "SELECT id, sequence_name, description, category, total_steps, usage_count
             FROM {$sequences_table}
             WHERE (user_id = %d OR user_id = 0)
             AND is_active = 1
             ORDER BY usage_count DESC, sequence_name ASC",
            $user_id
        ), ARRAY_A);

        if (!$sequences) {
            return [];
        }

        // Get steps for each sequence
        foreach ($sequences as &$seq) {
            $seq['steps'] = $wpdb->get_results($wpdb->prepare(
                "SELECT step_number, step_name, delay_value, delay_unit, template_id
                 FROM {$steps_table}
                 WHERE sequence_id = %d
                 ORDER BY step_number ASC",
                $seq['id']
            ), ARRAY_A);

            $seq['id'] = (int) $seq['id'];
            $seq['total_steps'] = (int) $seq['total_steps'];
            $seq['usage_count'] = (int) $seq['usage_count'];
        }

        return $sequences;
    }

    /**
     * Get a single sequence with its steps
     *
     * @param int $sequence_id Sequence ID
     * @return array|null Sequence data or null if not found
     */
    public static function get_sequence(int $sequence_id): ?array {
        if (!self::has_public_api()) {
            return null;
        }

        global $wpdb;
        $user_id = get_current_user_id();

        $sequences_table = $wpdb->prefix . 'guestify_campaign_sequences';
        $steps_table = $wpdb->prefix . 'guestify_campaign_steps';

        $sequence = $wpdb->get_row($wpdb->prepare(
            "SELECT id, sequence_name, description, category, total_steps, usage_count
             FROM {$sequences_table}
             WHERE id = %d
             AND (user_id = %d OR user_id = 0)
             AND is_active = 1",
            $sequence_id,
            $user_id
        ), ARRAY_A);

        if (!$sequence) {
            return null;
        }

        $sequence['steps'] = $wpdb->get_results($wpdb->prepare(
            "SELECT step_number, step_name, delay_value, delay_unit, template_id
             FROM {$steps_table}
             WHERE sequence_id = %d
             ORDER BY step_number ASC",
            $sequence_id
        ), ARRAY_A);

        $sequence['id'] = (int) $sequence['id'];
        $sequence['total_steps'] = (int) $sequence['total_steps'];
        $sequence['usage_count'] = (int) $sequence['usage_count'];

        return $sequence;
    }

    /**
     * Get template variable data for an opportunity
     *
     * Gathers all data needed for template variable replacement from
     * Show Authority's pit_opportunities, pit_guests, and pit_engagements tables.
     *
     * @param int $opportunity_id Opportunity/Appearance ID
     * @return array Variable data for template replacement
     */
    private static function get_opportunity_variable_data(int $opportunity_id): array {
        global $wpdb;

        $opportunities_table = $wpdb->prefix . 'pit_opportunities';
        $guests_table = $wpdb->prefix . 'pit_guests';
        $engagements_table = $wpdb->prefix . 'pit_engagements';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';

        // Get opportunity with related data
        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT o.*,
                    g.full_name as guest_full_name, g.email as guest_email,
                    g.current_company, g.current_role,
                    e.title as episode_title, e.url as episode_url,
                    p.name as podcast_name, p.host_name, p.website_url as podcast_url
             FROM {$opportunities_table} o
             LEFT JOIN {$guests_table} g ON o.guest_id = g.id
             LEFT JOIN {$engagements_table} e ON o.engagement_id = e.id
             LEFT JOIN {$podcasts_table} p ON o.podcast_id = p.id
             WHERE o.id = %d",
            $opportunity_id
        ), ARRAY_A);

        if (!$opportunity) {
            return [];
        }

        // Map to template variable names
        return [
            'entity_type'    => self::ENTITY_TYPE,
            'entity_id'      => $opportunity_id,
            'podcast_name'   => $opportunity['podcast_name'] ?? '',
            'host_name'      => $opportunity['host_name'] ?? '',
            'podcast_url'    => $opportunity['podcast_url'] ?? '',
            'episode_title'  => $opportunity['episode_title'] ?? '',
            'episode_url'    => $opportunity['episode_url'] ?? '',
            'guest_name'     => $opportunity['guest_full_name'] ?? '',
            'guest_email'    => $opportunity['guest_email'] ?? '',
            'guest_company'  => $opportunity['current_company'] ?? '',
            'guest_title'    => $opportunity['current_role'] ?? '',
            'custom_variables' => [
                'opportunity_status' => $opportunity['status'] ?? '',
                'priority'           => $opportunity['priority'] ?? '',
            ],
        ];
    }

    /**
     * Start a campaign using a sequence
     *
     * CRITICAL: This method gathers all template variable data from Show Authority's
     * pit_opportunities table and passes it in the campaign metadata. The Campaign
     * Processor will use this data instead of querying guestify_interview_entries.
     *
     * @param array $args {
     *     @type int    $entity_id       Required. Appearance/Opportunity ID.
     *     @type int    $sequence_id     Required. Sequence to use.
     *     @type string $recipient_email Required. Recipient email.
     *     @type string $recipient_name  Optional. Recipient name.
     *     @type string $name            Optional. Campaign name (auto-generated if not provided).
     * }
     * @return array Result with success, message, and campaign_id
     */
    public static function start_sequence_campaign(array $args): array {
        $error = self::check_campaign_availability(true);
        if ($error !== null) {
            return $error;
        }

        // Validate required fields
        if (empty($args['entity_id'])) {
            return ['success' => false, 'message' => 'Missing entity_id'];
        }
        if (empty($args['sequence_id'])) {
            return ['success' => false, 'message' => 'Missing sequence_id'];
        }
        if (empty($args['recipient_email'])) {
            return ['success' => false, 'message' => 'Missing recipient_email'];
        }

        // CRITICAL: Get template variable data from Show Authority tables
        // This data will be stored in campaign metadata for the processor to use
        $variable_data = self::get_opportunity_variable_data(absint($args['entity_id']));

        if (empty($variable_data)) {
            return [
                'success' => false,
                'message' => 'Could not load opportunity data for campaign variables'
            ];
        }

        // Prepare campaign arguments for Public API
        // Note: We pass interview_entry_id as 0 since we're using metadata for variables
        $campaign_args = [
            'sequence_id'        => absint($args['sequence_id']),
            'entity_id'          => absint($args['entity_id']),
            'entity_type'        => self::ENTITY_TYPE,
            'recipient_email'    => sanitize_email($args['recipient_email']),
            'recipient_name'     => sanitize_text_field($args['recipient_name'] ?? ''),
            'metadata'           => $variable_data,  // CRITICAL: Template variables for processor
        ];

        $result = Guestify_Outreach_Public_API::start_campaign($campaign_args);

        // Log to activity feed on success
        if (!empty($result['success'])) {
            $seq = self::get_sequence($args['sequence_id']);
            $seq_name = $seq ? $seq['sequence_name'] : 'Sequence #' . $args['sequence_id'];
            self::log_campaign_activity(
                $args['entity_id'],
                'started',
                $args['name'] ?? $seq_name
            );
        }

        return $result;
    }

    /**
     * Get unified statistics matching the Outreach plugin's analytics
     *
     * Returns stats that match the aggregate reports in the Outreach plugin,
     * including campaign status for active campaigns.
     *
     * @param int $appearance_id Appearance ID
     * @return array Unified stats
     */
    public static function get_unified_stats(int $appearance_id): array {
        $default_stats = [
            'total_sent'       => 0,
            'opened'           => 0,
            'clicked'          => 0,
            'replied'          => 0,
            'bounced'          => 0,
            'open_rate'        => 0,
            'click_rate'       => 0,
            'reply_rate'       => 0,
            'campaign_status'  => null,
            'campaign_step'    => null,
            'campaign_total'   => null,
            'campaign_name'    => null,
            'campaign_id'      => null,
            'next_send_date'   => null,
        ];

        if (!self::is_active()) {
            return $default_stats;
        }

        // Get basic stats from Public API
        if (self::has_public_api()) {
            $stats = Guestify_Outreach_Public_API::get_stats($appearance_id, self::ENTITY_TYPE);
            $default_stats = array_merge($default_stats, $stats);
        } else {
            $stats = self::get_stats_legacy($appearance_id);
            $default_stats = array_merge($default_stats, $stats);
        }

        // Get active campaign info
        if (self::has_public_api()) {
            global $wpdb;
            $table_active = $wpdb->prefix . 'guestify_active_campaigns';
            $sequences_table = $wpdb->prefix . 'guestify_campaign_sequences';

            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_active)) === $table_active) {
                // Check for entity columns
                $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table_active}");
                $column_names = array_map(fn($col) => $col->Field, $columns);
                $use_entity = in_array('entity_id', $column_names, true);

                if ($use_entity) {
                    $where_clause = $wpdb->prepare(
                        "ac.entity_id = %d AND ac.entity_type = %s",
                        $appearance_id,
                        self::ENTITY_TYPE
                    );
                } else {
                    $where_clause = $wpdb->prepare(
                        "ac.interview_entry_id = %d",
                        $appearance_id
                    );
                }

                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $campaign = $wpdb->get_row(
                    "SELECT ac.*, cs.sequence_name
                     FROM {$table_active} ac
                     LEFT JOIN {$sequences_table} cs ON ac.sequence_id = cs.id
                     WHERE {$where_clause}
                     AND ac.status IN ('active', 'paused')
                     ORDER BY ac.id DESC LIMIT 1"
                );

                if ($campaign) {
                    $default_stats['campaign_status'] = $campaign->status;
                    $default_stats['campaign_step'] = (int) $campaign->current_step;
                    $default_stats['campaign_total'] = (int) $campaign->total_steps;
                    $default_stats['campaign_name'] = $campaign->sequence_name ?? 'Campaign';
                    $default_stats['campaign_id'] = (int) $campaign->id;
                    $default_stats['next_send_date'] = $campaign->next_send_date;
                }
            }
        }

        return $default_stats;
    }

    /**
     * Log campaign activity to activity feed
     */
    private static function log_campaign_activity(int $appearance_id, string $action, string $name): void {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_appearance_notes';

        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$exists) {
            return;
        }

        $wpdb->insert($table, [
            'appearance_id' => $appearance_id,
            'title'         => 'Campaign ' . $action . ': ' . wp_trim_words($name, 6),
            'content'       => sprintf('Email campaign "%s" was %s.', $name, $action),
            'note_type'     => 'campaign',
            'created_by'    => get_current_user_id(),
            'created_at'    => current_time('mysql'),
        ]);
    }

    /**
     * Log campaign status change to activity feed
     */
    private static function log_campaign_status_change(int $campaign_id, string $status): void {
        // Get campaign info to find appearance_id
        $campaign = self::get_campaign($campaign_id);
        if (!$campaign || empty($campaign['entity_id'])) {
            return;
        }

        self::log_campaign_activity(
            (int) $campaign['entity_id'],
            $status,
            $campaign['name'] ?? 'Campaign #' . $campaign_id
        );
    }

    /**
     * Get extended status information including version details
     *
     * @return array Status information
     */
    public static function get_extended_status(): array {
        return [
            'available'    => self::is_active(),
            'configured'   => self::is_configured(),
            'has_api'      => self::has_public_api(),
            'version'      => self::get_version(),
            'api_version'  => self::get_api_version(),
            'entity_type'  => self::ENTITY_TYPE,
            'entity_types' => self::get_entity_types(),
            'features'     => [
                'send_email'   => self::is_active(),
                'templates'    => self::is_active(),
                'campaigns'    => self::has_public_api(),
                'tracking'     => self::is_configured(),
            ],
        ];
    }

    // =========================================================================
    // Template CRUD Operations (v5.4.0+)
    // =========================================================================

    /**
     * Create a new email template
     *
     * Routes through Guestify Outreach Public API if available,
     * otherwise creates directly in the database.
     *
     * @param array $args Template data
     * @return array Result with success, message, template_id
     */
    public static function create_template(array $args): array {
        if (!self::is_active()) {
            return [
                'success' => false,
                'message' => 'Guestify Outreach plugin is not active.'
            ];
        }

        // Validate required fields
        if (empty($args['name'])) {
            return ['success' => false, 'message' => 'Template name is required'];
        }
        if (empty($args['subject'])) {
            return ['success' => false, 'message' => 'Subject is required'];
        }

        // Use Public API if available
        if (self::has_public_api() && method_exists('Guestify_Outreach_Public_API', 'create_template')) {
            return Guestify_Outreach_Public_API::create_template([
                'name'        => sanitize_text_field($args['name']),
                'subject'     => sanitize_text_field($args['subject']),
                'body_html'   => wp_kses_post($args['body_html'] ?? ''),
                'category'    => sanitize_text_field($args['category'] ?? 'Custom'),
                'user_id'     => get_current_user_id(),
            ]);
        }

        // Fallback: Direct database insert
        return self::create_template_legacy($args);
    }

    /**
     * Legacy template creation via direct database insert
     */
    private static function create_template_legacy(array $args): array {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_email_templates';

        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$table_exists) {
            return [
                'success' => false,
                'message' => 'Email templates table not found. Please ensure Guestify Outreach is properly installed.'
            ];
        }

        $user_id = get_current_user_id();

        // Check for duplicate name for this user
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE template_name = %s AND user_id = %d",
            $args['name'],
            $user_id
        ));

        if ($existing) {
            return [
                'success' => false,
                'message' => 'A template with this name already exists.'
            ];
        }

        $result = $wpdb->insert($table, [
            'template_name'     => sanitize_text_field($args['name']),
            'category'          => sanitize_text_field($args['category'] ?? 'Custom'),
            'subject'           => sanitize_text_field($args['subject']),
            'body_html'         => wp_kses_post($args['body_html'] ?? ''),
            'variables_schema'  => wp_json_encode([]),
            'user_id'           => $user_id,
            'is_default'        => 0,
            'is_active'         => 1,
            'created_at'        => current_time('mysql'),
            'updated_at'        => current_time('mysql'),
        ]);

        if ($result === false) {
            return [
                'success' => false,
                'message' => 'Failed to create template: ' . $wpdb->last_error
            ];
        }

        return [
            'success'     => true,
            'message'     => 'Template created successfully',
            'template_id' => $wpdb->insert_id
        ];
    }

    /**
     * Update an existing template
     *
     * @param int   $template_id Template ID
     * @param array $args        Updated template data
     * @return array Result with success and message
     */
    public static function update_template(int $template_id, array $args): array {
        if (!self::is_active()) {
            return [
                'success' => false,
                'message' => 'Guestify Outreach plugin is not active.'
            ];
        }

        // Use Public API if available
        if (self::has_public_api() && method_exists('Guestify_Outreach_Public_API', 'update_template')) {
            return Guestify_Outreach_Public_API::update_template($template_id, [
                'subject'   => sanitize_text_field($args['subject'] ?? ''),
                'body_html' => wp_kses_post($args['body_html'] ?? ''),
            ]);
        }

        // Fallback: Direct database update
        return self::update_template_legacy($template_id, $args);
    }

    /**
     * Legacy template update via direct database query
     */
    private static function update_template_legacy(int $template_id, array $args): array {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_email_templates';

        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$table_exists) {
            return [
                'success' => false,
                'message' => 'Email templates table not found.'
            ];
        }

        $user_id = get_current_user_id();

        // Check if user owns the template or is admin
        $template = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id FROM {$table} WHERE id = %d",
            $template_id
        ));

        if (!$template) {
            return [
                'success' => false,
                'message' => 'Template not found.'
            ];
        }

        // Only allow update if user owns template or is admin
        if ((int) $template->user_id !== $user_id && !current_user_can('manage_options')) {
            return [
                'success' => false,
                'message' => 'You do not have permission to update this template.'
            ];
        }

        // Build update data
        $update_data = ['updated_at' => current_time('mysql')];
        $update_format = ['%s'];

        if (isset($args['subject'])) {
            $update_data['subject'] = sanitize_text_field($args['subject']);
            $update_format[] = '%s';
        }

        if (isset($args['body_html'])) {
            $update_data['body_html'] = wp_kses_post($args['body_html']);
            $update_format[] = '%s';
        }

        if (isset($args['name'])) {
            $update_data['template_name'] = sanitize_text_field($args['name']);
            $update_format[] = '%s';
        }

        if (isset($args['category'])) {
            $update_data['category'] = sanitize_text_field($args['category']);
            $update_format[] = '%s';
        }

        $result = $wpdb->update(
            $table,
            $update_data,
            ['id' => $template_id],
            $update_format,
            ['%d']
        );

        if ($result === false) {
            return [
                'success' => false,
                'message' => 'Failed to update template: ' . $wpdb->last_error
            ];
        }

        return [
            'success' => true,
            'message' => 'Template updated successfully'
        ];
    }

    /**
     * Get a single template by ID
     *
     * @param int $template_id Template ID
     * @return array|null Template data or null if not found
     */
    public static function get_template(int $template_id): ?array {
        if (!self::is_active()) {
            return null;
        }

        // Use Public API if available
        if (self::has_public_api() && method_exists('Guestify_Outreach_Public_API', 'get_template')) {
            return Guestify_Outreach_Public_API::get_template($template_id);
        }

        // Fallback: Direct database query
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_email_templates';

        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$table_exists) {
            return null;
        }

        $user_id = get_current_user_id();

        $template = $wpdb->get_row($wpdb->prepare(
            "SELECT id, template_name, category, subject, body_html, variables_schema, is_default
             FROM {$table}
             WHERE id = %d
             AND (user_id = %d OR user_id = 0 OR %d = 1)",
            $template_id,
            $user_id,
            current_user_can('manage_options') ? 1 : 0
        ), ARRAY_A);

        if (!$template) {
            return null;
        }

        return [
            'id'         => (int) $template['id'],
            'name'       => $template['template_name'],
            'category'   => $template['category'],
            'subject'    => $template['subject'],
            'body_html'  => $template['body_html'],
            'variables'  => json_decode($template['variables_schema'], true) ?: [],
            'is_default' => (bool) $template['is_default'],
        ];
    }
}
