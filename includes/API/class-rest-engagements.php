<?php
/**
 * REST API Controller for Engagements
 *
 * Provides endpoints for managing public speaking engagements.
 * Uses pit_engagements table.
 *
 * @package Podcast_Influence_Tracker
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_REST_Engagements {

    const NAMESPACE = 'guestify/v1';

    /**
     * Register REST routes
     */
    public static function register_routes() {
        // List engagements
        register_rest_route(self::NAMESPACE, '/engagements', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_engagements'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
            'args' => [
                'engagement_type' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'podcast_id' => ['type' => 'integer', 'sanitize_callback' => 'absint'],
                'guest_id' => ['type' => 'integer', 'sanitize_callback' => 'absint'],
                'search' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'verified_only' => ['type' => 'boolean', 'default' => false],
                'date_from' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'date_to' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'per_page' => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100],
            ],
        ]);

        // Get single engagement
        register_rest_route(self::NAMESPACE, '/engagements/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_engagement'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Create engagement
        register_rest_route(self::NAMESPACE, '/engagements', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_engagement'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Update engagement
        register_rest_route(self::NAMESPACE, '/engagements/(?P<id>\d+)', [
            'methods' => 'PATCH',
            'callback' => [__CLASS__, 'update_engagement'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Delete engagement
        register_rest_route(self::NAMESPACE, '/engagements/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [__CLASS__, 'delete_engagement'],
            'permission_callback' => [__CLASS__, 'check_admin_permissions'],
        ]);

        // Verify engagement (admin only - global trust signal)
        register_rest_route(self::NAMESPACE, '/engagements/(?P<id>\d+)/verify', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'verify_engagement'],
            'permission_callback' => [__CLASS__, 'check_admin_permissions'],
        ]);

        // Get engagements for guest
        register_rest_route(self::NAMESPACE, '/engagements/by-guest/(?P<guest_id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_for_guest'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Get statistics
        register_rest_route(self::NAMESPACE, '/engagements/stats', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_statistics'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);
    }

    public static function check_permissions() {
        return is_user_logged_in();
    }

    public static function check_admin_permissions() {
        return current_user_can('manage_options');
    }

    /**
     * Get engagements list
     */
    public static function get_engagements($request) {
        $args = [
            'page' => (int) $request->get_param('page'),
            'per_page' => (int) $request->get_param('per_page'),
            'engagement_type' => $request->get_param('engagement_type'),
            'podcast_id' => $request->get_param('podcast_id'),
            'search' => $request->get_param('search'),
            'verified_only' => (bool) $request->get_param('verified_only'),
            'date_from' => $request->get_param('date_from'),
            'date_to' => $request->get_param('date_to'),
        ];

        $result = PIT_Engagement_Repository::list($args);

        return new WP_REST_Response([
            'data' => array_map([__CLASS__, 'format_engagement'], $result['engagements'] ?: []),
            'meta' => [
                'total' => $result['total'],
                'page' => $args['page'],
                'per_page' => $args['per_page'],
                'total_pages' => $result['pages'],
            ],
        ], 200);
    }

    /**
     * Get single engagement
     */
    public static function get_engagement($request) {
        $id = (int) $request->get_param('id');
        $engagement = PIT_Engagement_Repository::get($id);

        if (!$engagement) {
            return new WP_Error('not_found', 'Engagement not found', ['status' => 404]);
        }

        // Get speaking credits for this engagement
        $credits = PIT_Speaking_Credit_Repository::get_for_engagement($id);

        $data = self::format_engagement($engagement, true);
        $data['speakers'] = array_map(function($credit) {
            return [
                'guest_id' => (int) $credit->guest_id,
                'full_name' => $credit->full_name,
                'current_company' => $credit->current_company,
                'role' => $credit->role,
                'is_primary' => (bool) $credit->is_primary,
            ];
        }, $credits);

        return new WP_REST_Response($data, 200);
    }

    /**
     * Create engagement
     */
    public static function create_engagement($request) {
        $data = [
            'title' => sanitize_text_field($request->get_param('title')),
            'engagement_type' => sanitize_text_field($request->get_param('engagement_type')) ?: 'podcast',
            'podcast_id' => $request->get_param('podcast_id') ? (int) $request->get_param('podcast_id') : null,
            'episode_guid' => sanitize_text_field($request->get_param('episode_guid')),
            'episode_number' => $request->get_param('episode_number') ? (int) $request->get_param('episode_number') : null,
            'season_number' => $request->get_param('season_number') ? (int) $request->get_param('season_number') : null,
            'description' => sanitize_textarea_field($request->get_param('description')),
            'url' => esc_url_raw($request->get_param('url')),
            'engagement_date' => sanitize_text_field($request->get_param('engagement_date')),
            'published_date' => sanitize_text_field($request->get_param('published_date')),
            'duration_seconds' => $request->get_param('duration_seconds') ? (int) $request->get_param('duration_seconds') : null,
            'event_name' => sanitize_text_field($request->get_param('event_name')),
            'event_location' => sanitize_text_field($request->get_param('event_location')),
            'discovery_source' => 'api',
        ];

        if (empty($data['title'])) {
            return new WP_Error('missing_title', 'Title is required', ['status' => 400]);
        }

        // Use upsert to handle deduplication
        $result = PIT_Engagement_Repository::upsert($data);

        return new WP_REST_Response([
            'id' => $result['id'],
            'created' => $result['created'],
            'message' => $result['created'] ? 'Engagement created successfully' : 'Existing engagement found',
        ], $result['created'] ? 201 : 200);
    }

    /**
     * Update engagement
     */
    public static function update_engagement($request) {
        $id = (int) $request->get_param('id');

        $engagement = PIT_Engagement_Repository::get($id);
        if (!$engagement) {
            return new WP_Error('not_found', 'Engagement not found', ['status' => 404]);
        }

        $allowed = [
            'title', 'description', 'engagement_type', 'episode_number', 'season_number',
            'url', 'embed_url', 'audio_url', 'video_url', 'thumbnail_url', 'transcript_url',
            'engagement_date', 'published_date', 'duration_seconds',
            'topics', 'key_quotes', 'summary', 'ai_summary',
            'event_name', 'event_location', 'event_url',
            'view_count', 'like_count', 'comment_count', 'share_count',
        ];

        $data = [];
        foreach ($allowed as $field) {
            $value = $request->get_param($field);
            if ($value !== null) {
                if (in_array($field, ['title', 'engagement_type', 'event_name', 'event_location'])) {
                    $data[$field] = sanitize_text_field($value);
                } elseif (in_array($field, ['description', 'topics', 'key_quotes', 'summary', 'ai_summary'])) {
                    $data[$field] = sanitize_textarea_field($value);
                } elseif (in_array($field, ['url', 'embed_url', 'audio_url', 'video_url', 'thumbnail_url', 'transcript_url', 'event_url'])) {
                    $data[$field] = esc_url_raw($value);
                } elseif (in_array($field, ['episode_number', 'season_number', 'duration_seconds', 'view_count', 'like_count', 'comment_count', 'share_count'])) {
                    $data[$field] = (int) $value;
                } else {
                    // Handles 'engagement_date', 'published_date', and any other text-like fields
                    $data[$field] = sanitize_text_field($value);
                }
            }
        }

        if (empty($data)) {
            return new WP_Error('no_data', 'No valid fields to update', ['status' => 400]);
        }

        PIT_Engagement_Repository::update($id, $data);

        return new WP_REST_Response([
            'id' => $id,
            'message' => 'Engagement updated successfully',
            'updated_fields' => array_keys($data),
        ], 200);
    }

    /**
     * Delete engagement
     */
    public static function delete_engagement($request) {
        $id = (int) $request->get_param('id');

        $engagement = PIT_Engagement_Repository::get($id);
        if (!$engagement) {
            return new WP_Error('not_found', 'Engagement not found', ['status' => 404]);
        }

        PIT_Engagement_Repository::delete($id);

        return new WP_REST_Response(['message' => 'Engagement deleted'], 200);
    }

    /**
     * Verify engagement
     */
    public static function verify_engagement($request) {
        $id = (int) $request->get_param('id');
        $verified = (bool) ($request->get_param('verified') ?? true);

        $engagement = PIT_Engagement_Repository::get($id);
        if (!$engagement) {
            return new WP_Error('not_found', 'Engagement not found', ['status' => 404]);
        }

        PIT_Engagement_Repository::verify($id, $verified);

        return new WP_REST_Response([
            'id' => $id,
            'verified' => $verified,
            'message' => $verified ? 'Engagement verified' : 'Engagement unverified',
        ], 200);
    }

    /**
     * Get engagements for a guest
     */
    public static function get_for_guest($request) {
        $guest_id = (int) $request->get_param('guest_id');

        $engagements = PIT_Engagement_Repository::get_for_guest($guest_id, [
            'limit' => 100,
        ]);

        return new WP_REST_Response([
            'data' => array_map(function($e) {
                $formatted = self::format_engagement($e);
                $formatted['role'] = $e->role;
                $formatted['is_primary'] = (bool) $e->is_primary;
                return $formatted;
            }, $engagements),
        ], 200);
    }

    /**
     * Get statistics
     */
    public static function get_statistics($request) {
        return new WP_REST_Response(PIT_Engagement_Repository::get_statistics(), 200);
    }

    /**
     * Format engagement for API response
     */
    private static function format_engagement($row, $full = false) {
        $data = [
            'id' => (int) $row->id,
            'title' => $row->title,
            'engagement_type' => $row->engagement_type,
            'podcast_id' => $row->podcast_id ? (int) $row->podcast_id : null,
            'podcast_name' => $row->podcast_name ?? null,
            'podcast_image' => $row->podcast_image ?? null,
            'episode_number' => $row->episode_number ? (int) $row->episode_number : null,
            'season_number' => $row->season_number ? (int) $row->season_number : null,
            'url' => $row->url,
            'engagement_date' => $row->engagement_date,
            'published_date' => $row->published_date,
            'duration_seconds' => $row->duration_seconds ? (int) $row->duration_seconds : null,
            'is_verified' => (bool) $row->is_verified,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];

        if ($full) {
            $data = array_merge($data, [
                'episode_guid' => $row->episode_guid,
                'description' => $row->description,
                'embed_url' => $row->embed_url,
                'audio_url' => $row->audio_url,
                'video_url' => $row->video_url,
                'thumbnail_url' => $row->thumbnail_url,
                'transcript_url' => $row->transcript_url,
                'topics' => $row->topics,
                'key_quotes' => $row->key_quotes,
                'summary' => $row->summary,
                'ai_summary' => $row->ai_summary,
                'event_name' => $row->event_name,
                'event_location' => $row->event_location,
                'event_url' => $row->event_url,
                'view_count' => $row->view_count ? (int) $row->view_count : null,
                'like_count' => $row->like_count ? (int) $row->like_count : null,
                'comment_count' => $row->comment_count ? (int) $row->comment_count : null,
                'share_count' => $row->share_count ? (int) $row->share_count : null,
                'verified_by_user_id' => $row->verified_by_user_id ? (int) $row->verified_by_user_id : null,
                'verified_at' => $row->verified_at,
                'discovered_by_user_id' => $row->discovered_by_user_id ? (int) $row->discovered_by_user_id : null,
                'discovery_source' => $row->discovery_source,
            ]);
        }

        return $data;
    }
}
