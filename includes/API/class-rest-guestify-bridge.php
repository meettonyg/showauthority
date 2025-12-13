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
}
