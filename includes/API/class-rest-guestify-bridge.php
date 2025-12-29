<?php
/**
 * REST API endpoints for Guestify Outreach Bridge
 *
 * These endpoints proxy requests to Guestify Outreach plugin.
 * No functionality is duplicated - all calls are delegated to the existing plugin.
 *
 * @package Podcast_Influence_Tracker
 * @since 4.2.0
 * @updated 5.1.0 - Added campaign management endpoints
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_REST_Guestify_Bridge {

    const NAMESPACE = 'guestify/v1';

    public static function register_routes(): void {
        // Check integration status
        register_rest_route(self::NAMESPACE, '/pit-bridge/status', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_status'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // Get templates from Guestify Outreach
        register_rest_route(self::NAMESPACE, '/pit-bridge/templates', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_templates'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // Send email via Guestify Outreach
        register_rest_route(self::NAMESPACE, '/pit-bridge/appearances/(?P<id>\d+)/send', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'send_email'],
            'permission_callback' => [__CLASS__, 'check_permission'],
            'args' => [
                'id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'to_email' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_email',
                ],
                'to_name' => [
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => '',
                ],
                'subject' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'body' => [
                    'required'          => true,
                    'type'              => 'string',
                ],
                'template_id' => [
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                    'default'           => 0,
                ],
            ],
        ]);

        // Get messages for an appearance from Guestify Outreach
        register_rest_route(self::NAMESPACE, '/pit-bridge/appearances/(?P<id>\d+)/messages', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_messages'],
            'permission_callback' => [__CLASS__, 'check_permission'],
            'args' => [
                'id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // Get email stats for an appearance
        register_rest_route(self::NAMESPACE, '/pit-bridge/appearances/(?P<id>\d+)/stats', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_stats'],
            'permission_callback' => [__CLASS__, 'check_permission'],
            'args' => [
                'id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // Get/Start campaigns for an appearance (consolidated GET + POST)
        register_rest_route(self::NAMESPACE, '/pit-bridge/appearances/(?P<id>\d+)/campaigns', [
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'get_campaigns'],
                'permission_callback' => [__CLASS__, 'check_permission'],
                'args' => [
                    'id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'start_campaign'],
                'permission_callback' => [__CLASS__, 'check_permission'],
                'args' => [
                    'id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'name' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'template_id' => [
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                        'default'           => 0,
                    ],
                    'steps' => [
                        'type'    => 'array',
                        'default' => [],
                    ],
                ],
            ],
        ]);

        // Pause a campaign
        register_rest_route(self::NAMESPACE, '/pit-bridge/campaigns/(?P<campaign_id>\d+)/pause', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'pause_campaign'],
            'permission_callback' => [__CLASS__, 'check_permission'],
            'args' => [
                'campaign_id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // Resume a campaign
        register_rest_route(self::NAMESPACE, '/pit-bridge/campaigns/(?P<campaign_id>\d+)/resume', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'resume_campaign'],
            'permission_callback' => [__CLASS__, 'check_permission'],
            'args' => [
                'campaign_id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // Cancel a campaign
        register_rest_route(self::NAMESPACE, '/pit-bridge/campaigns/(?P<campaign_id>\d+)/cancel', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'cancel_campaign'],
            'permission_callback' => [__CLASS__, 'check_permission'],
            'args' => [
                'campaign_id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // Get extended status with version info
        register_rest_route(self::NAMESPACE, '/pit-bridge/status/extended', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_extended_status'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // Debug endpoint for sequences troubleshooting
        register_rest_route(self::NAMESPACE, '/pit-bridge/debug/sequences', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'debug_sequences'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // =========================================================================
        // Sequence-Based Campaign Endpoints (v5.2.0+)
        // =========================================================================

        // Get all available sequences
        register_rest_route(self::NAMESPACE, '/pit-bridge/sequences', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_sequences'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // Get a single sequence with steps
        register_rest_route(self::NAMESPACE, '/pit-bridge/sequences/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_sequence'],
            'permission_callback' => [__CLASS__, 'check_permission'],
            'args' => [
                'id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // Start a sequence-based campaign for an appearance
        register_rest_route(self::NAMESPACE, '/pit-bridge/appearances/(?P<id>\d+)/campaigns/sequence', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'start_sequence_campaign'],
            'permission_callback' => [__CLASS__, 'check_permission'],
            'args' => [
                'id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'sequence_id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'recipient_email' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_email',
                ],
                'recipient_name' => [
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default'           => '',
                ],
            ],
        ]);

        // Get unified stats for an appearance (single emails + campaigns)
        register_rest_route(self::NAMESPACE, '/pit-bridge/appearances/(?P<id>\d+)/unified-stats', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_unified_stats'],
            'permission_callback' => [__CLASS__, 'check_permission'],
            'args' => [
                'id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // =========================================================================
        // Template CRUD Endpoints (v5.4.0+)
        // =========================================================================

        // Create a new template
        register_rest_route(self::NAMESPACE, '/pit-bridge/templates', [
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'get_templates'],
                'permission_callback' => [__CLASS__, 'check_permission'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'create_template'],
                'permission_callback' => [__CLASS__, 'check_permission'],
                'args' => [
                    'name' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'subject' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'body_html' => [
                        'required'          => true,
                        'type'              => 'string',
                    ],
                    'category' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                        'default'           => 'Custom',
                    ],
                ],
            ],
        ]);

        // Update an existing template
        register_rest_route(self::NAMESPACE, '/pit-bridge/templates/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'get_template'],
                'permission_callback' => [__CLASS__, 'check_permission'],
                'args' => [
                    'id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [__CLASS__, 'update_template'],
                'permission_callback' => [__CLASS__, 'check_permission'],
                'args' => [
                    'id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'subject' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'body_html' => [
                        'type'              => 'string',
                    ],
                ],
            ],
        ]);

        // =========================================================================
        // Draft Management Endpoints (v5.4.0+)
        // =========================================================================

        // Get/Save drafts for an appearance
        register_rest_route(self::NAMESPACE, '/pit-bridge/appearances/(?P<id>\d+)/drafts', [
            [
                'methods'             => 'GET',
                'callback'            => [__CLASS__, 'get_drafts'],
                'permission_callback' => [__CLASS__, 'check_permission'],
                'args' => [
                    'id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'save_draft'],
                'permission_callback' => [__CLASS__, 'check_permission'],
                'args' => [
                    'id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'draft_type' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                        'default'           => 'single_email',
                    ],
                    'recipient_email' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_email',
                    ],
                    'recipient_name' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'subject' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'body_html' => [
                        'type'              => 'string',
                    ],
                    'template_id' => [
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        ]);

        // Mark email as sent (manual tracking)
        register_rest_route(self::NAMESPACE, '/pit-bridge/appearances/(?P<id>\d+)/mark-sent', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'mark_as_sent'],
            'permission_callback' => [__CLASS__, 'check_permission'],
            'args' => [
                'id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'recipient_email' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_email',
                ],
                'subject' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'body_html' => [
                    'type'              => 'string',
                ],
            ],
        ]);

        // Delete a draft
        register_rest_route(self::NAMESPACE, '/pit-bridge/drafts/(?P<draft_id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [__CLASS__, 'delete_draft'],
            'permission_callback' => [__CLASS__, 'check_permission'],
            'args' => [
                'draft_id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    /**
     * Get integration status
     */
    public static function get_status(): WP_REST_Response {
        return new WP_REST_Response([
            'available'  => PIT_Guestify_Outreach_Bridge::is_active(),
            'configured' => PIT_Guestify_Outreach_Bridge::is_configured(),
        ]);
    }

    /**
     * Get templates from Guestify Outreach
     */
    public static function get_templates(): WP_REST_Response {
        return new WP_REST_Response([
            'data' => PIT_Guestify_Outreach_Bridge::get_templates(),
        ]);
    }

    /**
     * Send email via Guestify Outreach
     */
    public static function send_email(WP_REST_Request $request): WP_REST_Response {
        $appearance_id = (int) $request->get_param('id');

        // Verify the appearance belongs to current user
        if (!self::verify_appearance_ownership($appearance_id)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'You do not have permission to send emails for this interview.',
            ], 403);
        }

        // Convert plain text body to HTML if not already HTML
        $body = $request->get_param('body');
        if (strip_tags($body) === $body) {
            // Plain text - convert to HTML
            $html_content = wpautop(nl2br(esc_html($body)));
        } else {
            // Already HTML - sanitize
            $html_content = wp_kses_post($body);
        }

        $result = PIT_Guestify_Outreach_Bridge::send_email($appearance_id, [
            'to_email'     => $request->get_param('to_email'),
            'to_name'      => $request->get_param('to_name'),
            'subject'      => $request->get_param('subject'),
            'html_content' => $html_content,
            'template_id'  => $request->get_param('template_id'),
        ]);

        $status = !empty($result['success']) ? 201 : 400;
        return new WP_REST_Response($result, $status);
    }

    /**
     * Get messages for an appearance
     */
    public static function get_messages(WP_REST_Request $request): WP_REST_Response {
        $appearance_id = (int) $request->get_param('id');

        // Verify the appearance belongs to current user
        if (!self::verify_appearance_ownership($appearance_id)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'You do not have permission to view messages for this interview.',
            ], 403);
        }

        return new WP_REST_Response([
            'data' => PIT_Guestify_Outreach_Bridge::get_messages($appearance_id),
        ]);
    }

    /**
     * Get email stats for an appearance
     */
    public static function get_stats(WP_REST_Request $request): WP_REST_Response {
        $appearance_id = (int) $request->get_param('id');

        // Verify the appearance belongs to current user
        if (!self::verify_appearance_ownership($appearance_id)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'You do not have permission to view stats for this interview.',
            ], 403);
        }

        return new WP_REST_Response([
            'data' => PIT_Guestify_Outreach_Bridge::get_stats($appearance_id),
        ]);
    }

    /**
     * Check if user has permission to access bridge endpoints
     */
    public static function check_permission(): bool {
        return current_user_can('edit_posts');
    }

    /**
     * Verify the appearance belongs to the current user
     */
    private static function verify_appearance_ownership(int $appearance_id): bool {
        // Admins can access any appearance
        if (current_user_can('manage_options')) {
            return true;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pit_opportunities';

        $user_id = get_current_user_id();

        $owner_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$table} WHERE id = %d",
            $appearance_id
        ));

        return (int) $owner_id === $user_id;
    }

    // =========================================================================
    // Campaign Management Endpoints (v2.0+ API)
    // =========================================================================

    /**
     * Get campaigns for an appearance
     */
    public static function get_campaigns(WP_REST_Request $request): WP_REST_Response {
        $appearance_id = (int) $request->get_param('id');

        if (!self::verify_appearance_ownership($appearance_id)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'You do not have permission to view campaigns for this appearance.',
            ], 403);
        }

        if (!PIT_Guestify_Outreach_Bridge::has_public_api()) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Campaign management requires Guestify Outreach v2.0 or later.',
                'data'    => [],
            ], 200);
        }

        return new WP_REST_Response([
            'success' => true,
            'data'    => PIT_Guestify_Outreach_Bridge::get_campaigns($appearance_id),
        ]);
    }

    /**
     * Start a new campaign for an appearance
     */
    public static function start_campaign(WP_REST_Request $request): WP_REST_Response {
        $appearance_id = (int) $request->get_param('id');

        if (!self::verify_appearance_ownership($appearance_id)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'You do not have permission to start campaigns for this appearance.',
            ], 403);
        }

        $result = PIT_Guestify_Outreach_Bridge::start_campaign([
            'entity_id'   => $appearance_id,
            'name'        => $request->get_param('name'),
            'template_id' => $request->get_param('template_id'),
            'steps'       => $request->get_param('steps'),
        ]);

        $status = !empty($result['success']) ? 201 : 400;
        return new WP_REST_Response($result, $status);
    }

    /**
     * Pause a campaign
     */
    public static function pause_campaign(WP_REST_Request $request): WP_REST_Response {
        $campaign_id = (int) $request->get_param('campaign_id');

        if (!self::verify_campaign_ownership($campaign_id)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'You do not have permission to pause this campaign.',
            ], 403);
        }

        $result = PIT_Guestify_Outreach_Bridge::pause_campaign($campaign_id);

        $status = !empty($result['success']) ? 200 : 400;
        return new WP_REST_Response($result, $status);
    }

    /**
     * Resume a campaign
     */
    public static function resume_campaign(WP_REST_Request $request): WP_REST_Response {
        $campaign_id = (int) $request->get_param('campaign_id');

        if (!self::verify_campaign_ownership($campaign_id)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'You do not have permission to resume this campaign.',
            ], 403);
        }

        $result = PIT_Guestify_Outreach_Bridge::resume_campaign($campaign_id);

        $status = !empty($result['success']) ? 200 : 400;
        return new WP_REST_Response($result, $status);
    }

    /**
     * Cancel a campaign
     */
    public static function cancel_campaign(WP_REST_Request $request): WP_REST_Response {
        $campaign_id = (int) $request->get_param('campaign_id');

        if (!self::verify_campaign_ownership($campaign_id)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'You do not have permission to cancel this campaign.',
            ], 403);
        }

        $result = PIT_Guestify_Outreach_Bridge::cancel_campaign($campaign_id);

        $status = !empty($result['success']) ? 200 : 400;
        return new WP_REST_Response($result, $status);
    }

    /**
     * Get extended status with version info
     */
    public static function get_extended_status(): WP_REST_Response {
        return new WP_REST_Response(
            PIT_Guestify_Outreach_Bridge::get_extended_status()
        );
    }

    /**
     * Verify the campaign belongs to an appearance owned by the current user
     */
    private static function verify_campaign_ownership(int $campaign_id): bool {
        // Admins can access any campaign
        if (current_user_can('manage_options')) {
            return true;
        }

        // Get the campaign to find its linked appearance
        $campaign = PIT_Guestify_Outreach_Bridge::get_campaign($campaign_id);
        if (!$campaign || empty($campaign['entity_id'])) {
            return false;
        }

        // Verify the linked appearance belongs to current user
        return self::verify_appearance_ownership((int) $campaign['entity_id']);
    }

    // =========================================================================
    // Sequence-Based Campaign Endpoint Callbacks (v5.2.0+)
    // =========================================================================

    /**
     * Get all available sequences
     */
    public static function get_sequences(): WP_REST_Response {
        if (!PIT_Guestify_Outreach_Bridge::has_public_api()) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Sequence campaigns require Guestify Outreach v2.0 or later.',
                'data'    => [],
            ], 200);
        }

        $sequences = PIT_Guestify_Outreach_Bridge::get_sequences();

        return new WP_REST_Response([
            'success' => true,
            'data'    => $sequences,
        ]);
    }

    /**
     * Get a single sequence with steps
     */
    public static function get_sequence(WP_REST_Request $request): WP_REST_Response {
        if (!PIT_Guestify_Outreach_Bridge::has_public_api()) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Sequence campaigns require Guestify Outreach v2.0 or later.',
                'data'    => null,
            ], 200);
        }

        $sequence_id = (int) $request->get_param('id');
        $sequence = PIT_Guestify_Outreach_Bridge::get_sequence($sequence_id);

        if (!$sequence) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Sequence not found.',
                'data'    => null,
            ], 404);
        }

        return new WP_REST_Response([
            'success' => true,
            'data'    => $sequence,
        ]);
    }

    /**
     * Start a sequence-based campaign for an appearance
     */
    public static function start_sequence_campaign(WP_REST_Request $request): WP_REST_Response {
        $appearance_id = (int) $request->get_param('id');

        if (!self::verify_appearance_ownership($appearance_id)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'You do not have permission to start campaigns for this appearance.',
            ], 403);
        }

        if (!PIT_Guestify_Outreach_Bridge::has_public_api()) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Sequence campaigns require Guestify Outreach v2.0 or later.',
            ], 400);
        }

        $result = PIT_Guestify_Outreach_Bridge::start_sequence_campaign([
            'entity_id'       => $appearance_id,
            'sequence_id'     => $request->get_param('sequence_id'),
            'recipient_email' => $request->get_param('recipient_email'),
            'recipient_name'  => $request->get_param('recipient_name'),
        ]);

        $status = !empty($result['success']) ? 201 : 400;
        return new WP_REST_Response($result, $status);
    }

    /**
     * Get unified stats for an appearance (single emails + campaigns)
     */
    public static function get_unified_stats(WP_REST_Request $request): WP_REST_Response {
        $appearance_id = (int) $request->get_param('id');

        if (!self::verify_appearance_ownership($appearance_id)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'You do not have permission to view stats for this appearance.',
            ], 403);
        }

        $stats = PIT_Guestify_Outreach_Bridge::get_unified_stats($appearance_id);

        return new WP_REST_Response([
            'success' => true,
            'data'    => $stats,
        ]);
    }

    /**
     * Debug endpoint for troubleshooting sequences
     * Access via: /wp-json/guestify/v1/pit-bridge/debug/sequences
     */
    public static function debug_sequences(): WP_REST_Response {
        global $wpdb;

        $user_id = get_current_user_id();
        $sequences_table = $wpdb->prefix . 'guestify_campaign_sequences';

        // Check table existence
        $table_exists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $sequences_table)
        );

        // Get raw sequences from DB
        $raw_sequences = [];
        if ($table_exists) {
            $raw_sequences = $wpdb->get_results(
                "SELECT id, user_id, sequence_name, is_active, total_steps FROM {$sequences_table} LIMIT 20",
                ARRAY_A
            );
        }

        // Get sequences via Bridge
        $bridge_sequences = PIT_Guestify_Outreach_Bridge::get_sequences();

        return new WP_REST_Response([
            'debug' => true,
            'checks' => [
                'has_public_api'     => PIT_Guestify_Outreach_Bridge::has_public_api(),
                'is_active'          => PIT_Guestify_Outreach_Bridge::is_active(),
                'is_configured'      => PIT_Guestify_Outreach_Bridge::is_configured(),
                'guestify_version'   => PIT_Guestify_Outreach_Bridge::get_version(),
                'api_version'        => PIT_Guestify_Outreach_Bridge::get_api_version(),
                'current_user_id'    => $user_id,
            ],
            'table' => [
                'name'   => $sequences_table,
                'exists' => (bool) $table_exists,
            ],
            'raw_sequences'    => $raw_sequences,
            'bridge_sequences' => $bridge_sequences,
        ]);
    }

    // =========================================================================
    // Template CRUD Endpoint Callbacks (v5.4.0+)
    // =========================================================================

    /**
     * Get a single template by ID
     */
    public static function get_template(WP_REST_Request $request): WP_REST_Response {
        $template_id = (int) $request->get_param('id');
        $template = PIT_Guestify_Outreach_Bridge::get_template($template_id);

        if (!$template) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        return new WP_REST_Response([
            'success' => true,
            'data'    => $template,
        ]);
    }

    /**
     * Create a new template
     */
    public static function create_template(WP_REST_Request $request): WP_REST_Response {
        $result = PIT_Guestify_Outreach_Bridge::create_template([
            'name'      => $request->get_param('name'),
            'subject'   => $request->get_param('subject'),
            'body_html' => wp_kses_post($request->get_param('body_html')),
            'category'  => $request->get_param('category'),
        ]);

        $status = !empty($result['success']) ? 201 : 400;
        return new WP_REST_Response($result, $status);
    }

    /**
     * Update an existing template
     */
    public static function update_template(WP_REST_Request $request): WP_REST_Response {
        $template_id = (int) $request->get_param('id');

        $args = [];
        if ($request->has_param('subject')) {
            $args['subject'] = $request->get_param('subject');
        }
        if ($request->has_param('body_html')) {
            $args['body_html'] = wp_kses_post($request->get_param('body_html'));
        }

        $result = PIT_Guestify_Outreach_Bridge::update_template($template_id, $args);

        $status = !empty($result['success']) ? 200 : 400;
        return new WP_REST_Response($result, $status);
    }

    // =========================================================================
    // Draft Management Endpoint Callbacks (v5.4.0+)
    // =========================================================================

    /**
     * Get drafts for an appearance
     */
    public static function get_drafts(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $appearance_id = (int) $request->get_param('id');

        if (!self::verify_appearance_ownership($appearance_id)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $table = $wpdb->prefix . 'pit_email_drafts';

        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$table_exists) {
            return new WP_REST_Response([
                'success' => true,
                'data'    => [],
            ]);
        }

        $user_id = get_current_user_id();

        $drafts = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE appearance_id = %d AND user_id = %d AND status = 'draft'
             ORDER BY updated_at DESC",
            $appearance_id,
            $user_id
        ), ARRAY_A);

        return new WP_REST_Response([
            'success' => true,
            'data'    => $drafts ?: [],
        ]);
    }

    /**
     * Save a draft
     */
    public static function save_draft(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $appearance_id = (int) $request->get_param('id');

        if (!self::verify_appearance_ownership($appearance_id)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $table = $wpdb->prefix . 'pit_email_drafts';

        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$table_exists) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Drafts table not found. Please update the plugin.',
            ], 500);
        }

        $result = $wpdb->insert($table, [
            'user_id'         => get_current_user_id(),
            'appearance_id'   => $appearance_id,
            'draft_type'      => sanitize_text_field($request->get_param('draft_type') ?? 'single_email'),
            'recipient_email' => sanitize_email($request->get_param('recipient_email') ?? ''),
            'recipient_name'  => sanitize_text_field($request->get_param('recipient_name') ?? ''),
            'subject'         => sanitize_text_field($request->get_param('subject') ?? ''),
            'body_html'       => wp_kses_post($request->get_param('body_html') ?? ''),
            'template_id'     => absint($request->get_param('template_id') ?? 0) ?: null,
            'status'          => 'draft',
            'created_at'      => current_time('mysql'),
            'updated_at'      => current_time('mysql'),
        ]);

        if ($result === false) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to save draft',
            ], 500);
        }

        return new WP_REST_Response([
            'success'  => true,
            'draft_id' => $wpdb->insert_id,
            'message'  => 'Draft saved',
        ], 201);
    }

    /**
     * Mark email as sent (manual tracking without actually sending)
     */
    public static function mark_as_sent(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $appearance_id = (int) $request->get_param('id');

        if (!self::verify_appearance_ownership($appearance_id)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $table = $wpdb->prefix . 'pit_email_drafts';

        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$table_exists) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Drafts table not found. Please update the plugin.',
            ], 500);
        }

        // Insert record with status='marked_sent'
        $result = $wpdb->insert($table, [
            'user_id'         => get_current_user_id(),
            'appearance_id'   => $appearance_id,
            'draft_type'      => 'single_email',
            'recipient_email' => sanitize_email($request->get_param('recipient_email')),
            'subject'         => sanitize_text_field($request->get_param('subject')),
            'body_html'       => wp_kses_post($request->get_param('body_html') ?? ''),
            'status'          => 'marked_sent',
            'marked_sent_at'  => current_time('mysql'),
            'created_at'      => current_time('mysql'),
            'updated_at'      => current_time('mysql'),
        ]);

        if ($result === false) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to mark as sent',
            ], 500);
        }

        // Log to activity feed
        $notes_table = $wpdb->prefix . 'pit_appearance_notes';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $notes_table)) === $notes_table) {
            $wpdb->insert($notes_table, [
                'appearance_id' => $appearance_id,
                'title'         => 'Email marked as sent: ' . wp_trim_words($request->get_param('subject'), 8),
                'content'       => sprintf('Sent to %s (manual tracking)', $request->get_param('recipient_email')),
                'note_type'     => 'email',
                'created_by'    => get_current_user_id(),
                'created_at'    => current_time('mysql'),
            ]);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Email marked as sent',
        ], 201);
    }

    /**
     * Delete a draft
     */
    public static function delete_draft(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $draft_id = (int) $request->get_param('draft_id');

        $table = $wpdb->prefix . 'pit_email_drafts';

        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$table_exists) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Drafts table not found.',
            ], 500);
        }

        $user_id = get_current_user_id();

        // Verify ownership (user must own the draft or be admin)
        $draft = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id, appearance_id FROM {$table} WHERE id = %d",
            $draft_id
        ));

        if (!$draft) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Draft not found.',
            ], 404);
        }

        if ((int) $draft->user_id !== $user_id && !current_user_can('manage_options')) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'You do not have permission to delete this draft.',
            ], 403);
        }

        $result = $wpdb->delete($table, ['id' => $draft_id], ['%d']);

        if ($result === false) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to delete draft',
            ], 500);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Draft deleted',
        ]);
    }
}
