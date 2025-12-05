<?php
/**
 * REST API Controller for Episode Linking
 *
 * Provides endpoints to search RSS episodes and link them to opportunities.
 * Part of the Prospector <-> Guest Intelligence integration (Phase 2).
 *
 * @package PodcastInfluenceTracker
 * @subpackage API
 * @since 4.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_REST_Episode_Link {

    const NAMESPACE = 'guestify/v1';

    /**
     * Register REST routes
     */
    public static function register_routes() {
        // Search episodes from podcast RSS feed
        register_rest_route(self::NAMESPACE, '/appearances/(?P<id>\d+)/episodes', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_episodes'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
            'args' => [
                'id' => [
                    'type' => 'integer',
                    'required' => true,
                    'sanitize_callback' => 'absint',
                ],
                'search' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default' => '',
                ],
                'offset' => [
                    'type' => 'integer',
                    'default' => 0,
                    'minimum' => 0,
                ],
                'limit' => [
                    'type' => 'integer',
                    'default' => 20,
                    'minimum' => 1,
                    'maximum' => 100,
                ],
                'refresh' => [
                    'type' => 'boolean',
                    'default' => false,
                ],
            ],
        ]);

        // Link episode to opportunity (creates engagement)
        register_rest_route(self::NAMESPACE, '/appearances/(?P<id>\d+)/link-episode', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'link_episode'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
            'args' => [
                'id' => [
                    'type' => 'integer',
                    'required' => true,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // Unlink episode from opportunity
        register_rest_route(self::NAMESPACE, '/appearances/(?P<id>\d+)/unlink-episode', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'unlink_episode'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);
    }

    /**
     * Check permissions
     */
    public static function check_permissions() {
        return is_user_logged_in();
    }

    /**
     * Get episodes from podcast RSS feed
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function get_episodes($request) {
        global $wpdb;

        $opportunity_id = (int) $request->get_param('id');
        $user_id = get_current_user_id();
        $search = $request->get_param('search');
        $offset = (int) $request->get_param('offset');
        $limit = (int) $request->get_param('limit');
        $refresh = (bool) $request->get_param('refresh');

        // Get opportunity with podcast info
        $table = $wpdb->prefix . 'pit_opportunities';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';

        $sql = "SELECT o.*, p.rss_feed_url, p.title as podcast_name
                FROM {$table} o
                LEFT JOIN {$podcasts_table} p ON o.podcast_id = p.id
                WHERE o.id = %d";

        // Non-admins can only access their own opportunities
        if (!current_user_can('manage_options')) {
            $sql .= " AND o.user_id = %d";
            $opportunity = $wpdb->get_row($wpdb->prepare($sql, $opportunity_id, $user_id));
        } else {
            $opportunity = $wpdb->get_row($wpdb->prepare($sql, $opportunity_id));
        }

        if (!$opportunity) {
            return new WP_Error('not_found', 'Opportunity not found', ['status' => 404]);
        }

        if (empty($opportunity->rss_feed_url)) {
            return new WP_Error('no_rss', 'This podcast does not have an RSS feed URL', ['status' => 400]);
        }

        // Fetch episodes from RSS
        $result = PIT_RSS_Parser::parse_episodes(
            $opportunity->rss_feed_url,
            $offset,
            $limit,
            $refresh
        );

        if (is_wp_error($result)) {
            return $result;
        }

        $episodes = $result['episodes'];

        // Filter by search term if provided
        if (!empty($search)) {
            $search_lower = strtolower($search);
            $episodes = array_filter($episodes, function ($ep) use ($search_lower) {
                return strpos(strtolower($ep['title']), $search_lower) !== false ||
                       strpos(strtolower($ep['description']), $search_lower) !== false;
            });
            $episodes = array_values($episodes); // Re-index
        }

        return new WP_REST_Response([
            'success' => true,
            'podcast_name' => $opportunity->podcast_name,
            'episodes' => $episodes,
            'total_available' => $result['total_available'],
            'has_more' => $result['has_more'],
            'cached' => $result['cached'] ?? false,
            'cache_expires' => $result['cache_expires'] ?? null,
        ], 200);
    }

    /**
     * Link episode to opportunity
     *
     * Creates an engagement record and links it to the opportunity.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function link_episode($request) {
        global $wpdb;

        $opportunity_id = (int) $request->get_param('id');
        $user_id = get_current_user_id();

        // Episode data from request body
        $episode_title = sanitize_text_field($request->get_param('episode_title') ?? '');
        $episode_date = sanitize_text_field($request->get_param('episode_date') ?? '');
        $episode_url = esc_url_raw($request->get_param('episode_url') ?? '');
        $episode_guid = sanitize_text_field($request->get_param('episode_guid') ?? '');
        $episode_duration = (int) ($request->get_param('episode_duration') ?? 0);
        $episode_description = sanitize_textarea_field($request->get_param('episode_description') ?? '');

        if (empty($episode_title)) {
            return new WP_Error('missing_title', 'Episode title is required', ['status' => 400]);
        }

        // Get opportunity and verify ownership
        $opportunity = PIT_Opportunity_Repository::get($opportunity_id, $user_id);

        if (!$opportunity) {
            return new WP_Error('not_found', 'Opportunity not found', ['status' => 404]);
        }

        // Check if already linked
        if (!empty($opportunity->engagement_id)) {
            return new WP_Error(
                'already_linked',
                'This opportunity is already linked to an episode. Unlink it first.',
                ['status' => 400]
            );
        }

        // Create engagement record
        $engagement_data = [
            'podcast_id' => $opportunity->podcast_id,
            'engagement_type' => 'podcast_interview',
            'title' => $episode_title,
            'engagement_date' => $episode_date ?: null,
            'episode_url' => $episode_url,
            'episode_guid' => $episode_guid,
            'duration_seconds' => $episode_duration ?: null,
            'description' => $episode_description,
            'discovery_source' => 'prospector_link',
            'discovered_by_user_id' => $user_id,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        $engagement_id = PIT_Engagement_Repository::create($engagement_data);

        if (!$engagement_id) {
            return new WP_Error('create_failed', 'Failed to create engagement record', ['status' => 500]);
        }

        // Link engagement to opportunity
        $linked = PIT_Opportunity_Repository::link_engagement($opportunity_id, $engagement_id);

        if (!$linked) {
            // Rollback engagement creation
            PIT_Engagement_Repository::delete($engagement_id);
            return new WP_Error('link_failed', 'Failed to link episode to opportunity', ['status' => 500]);
        }

        // Update opportunity status to "aired" if currently in a pre-aired status
        $pre_aired_statuses = ['lead', 'potential', 'contacted', 'scheduled', 'confirmed', 'recorded'];
        if (in_array($opportunity->status, $pre_aired_statuses)) {
            PIT_Opportunity_Repository::update($opportunity_id, ['status' => 'aired']);
        }

        // Update opportunity air_date if not set
        if (empty($opportunity->air_date) && !empty($episode_date)) {
            PIT_Opportunity_Repository::update($opportunity_id, ['air_date' => $episode_date]);
        }

        /**
         * Fires when an episode is linked to an opportunity.
         *
         * @since 4.1.0
         * @param int    $opportunity_id Opportunity ID
         * @param int    $engagement_id  Engagement ID
         * @param array  $episode_data   Episode data
         */
        do_action('pit_episode_linked', $opportunity_id, $engagement_id, [
            'title' => $episode_title,
            'date' => $episode_date,
            'url' => $episode_url,
            'guid' => $episode_guid,
        ]);

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Episode linked successfully',
            'opportunity_id' => $opportunity_id,
            'engagement_id' => $engagement_id,
            'new_status' => 'aired',
        ], 200);
    }

    /**
     * Unlink episode from opportunity
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function unlink_episode($request) {
        global $wpdb;

        $opportunity_id = (int) $request->get_param('id');
        $user_id = get_current_user_id();

        // Get opportunity and verify ownership
        $opportunity = PIT_Opportunity_Repository::get($opportunity_id, $user_id);

        if (!$opportunity) {
            return new WP_Error('not_found', 'Opportunity not found', ['status' => 404]);
        }

        if (empty($opportunity->engagement_id)) {
            return new WP_Error('not_linked', 'This opportunity is not linked to an episode', ['status' => 400]);
        }

        $engagement_id = $opportunity->engagement_id;

        // Unlink from opportunity (set engagement_id to null)
        $unlinked = PIT_Opportunity_Repository::update($opportunity_id, ['engagement_id' => null]);

        if (!$unlinked) {
            return new WP_Error('unlink_failed', 'Failed to unlink episode', ['status' => 500]);
        }

        // Optionally delete the engagement record
        // For now, we keep it in case it's referenced elsewhere
        // PIT_Engagement_Repository::delete($engagement_id);

        /**
         * Fires when an episode is unlinked from an opportunity.
         *
         * @since 4.1.0
         * @param int $opportunity_id Opportunity ID
         * @param int $engagement_id  Former engagement ID
         */
        do_action('pit_episode_unlinked', $opportunity_id, $engagement_id);

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Episode unlinked successfully',
            'opportunity_id' => $opportunity_id,
        ], 200);
    }
}
