<?php
/**
 * REST API Base Controller
 *
 * Provides common functionality for all REST controllers.
 * Includes user context, rate limiting, and usage tracking for SaaS.
 *
 * @package PodcastInfluenceTracker
 * @subpackage API
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class PIT_REST_Base {

    /**
     * API namespace
     */
    const NAMESPACE = 'podcast-influence/v1';

    /**
     * Register routes (must be implemented by subclasses)
     */
    abstract public static function register_routes();

    /**
     * Check if user has admin permissions
     *
     * @return bool
     */
    public static function check_admin_permission() {
        return current_user_can('manage_options');
    }

    /**
     * Check if user is logged in
     *
     * @return bool
     */
    public static function check_logged_in() {
        return is_user_logged_in();
    }

    /**
     * Get current user ID using User Context
     *
     * @return int
     */
    protected static function get_current_user_id() {
        return PIT_User_Context::get_user_id();
    }

    /**
     * Check rate limit for an endpoint
     *
     * @param string $endpoint Endpoint name
     * @return WP_Error|true Returns true if allowed, WP_Error if rate limited
     */
    protected static function check_rate_limit($endpoint) {
        $user_id = self::get_current_user_id();

        if (!$user_id) {
            return self::error('auth_required', 'Authentication required', 401);
        }

        // Check monthly limit first
        if (PIT_Rate_Limiter::has_exceeded_monthly_limit($user_id)) {
            return self::error(
                'monthly_limit_exceeded',
                'You have exceeded your monthly API call limit. Please upgrade your plan.',
                429
            );
        }

        // Check per-minute rate limit
        $check = PIT_Rate_Limiter::check_and_record($user_id, $endpoint);

        if (!$check['allowed']) {
            $response = self::error(
                'rate_limit_exceeded',
                'Rate limit exceeded. Please try again in ' . ($check['reset'] - time()) . ' seconds.',
                429
            );

            // Add rate limit headers to error
            $response->add_data(['headers' => PIT_Rate_Limiter::get_headers($check)]);

            return $response;
        }

        return true;
    }

    /**
     * Check if user can add more guests
     *
     * @return WP_Error|true
     */
    protected static function check_guest_limit() {
        $user_id = self::get_current_user_id();

        if (!PIT_User_Limits_Repository::can_add_guest($user_id)) {
            return self::error(
                'guest_limit_exceeded',
                'You have reached your guest limit. Please upgrade your plan.',
                403
            );
        }

        return true;
    }

    /**
     * Check if user can track more podcasts
     *
     * @return WP_Error|true
     */
    protected static function check_podcast_limit() {
        $user_id = self::get_current_user_id();

        if (!PIT_User_Limits_Repository::can_track_podcast($user_id)) {
            return self::error(
                'podcast_limit_exceeded',
                'You have reached your podcast tracking limit. Please upgrade your plan.',
                403
            );
        }

        return true;
    }

    /**
     * Check if user can export
     *
     * @return WP_Error|true
     */
    protected static function check_export_limit() {
        $user_id = self::get_current_user_id();

        if (!PIT_User_Limits_Repository::can_export($user_id)) {
            return self::error(
                'export_limit_exceeded',
                'You have reached your export limit for this month. Please upgrade your plan.',
                403
            );
        }

        return true;
    }

    /**
     * Record an export usage
     *
     * @return void
     */
    protected static function record_export() {
        $user_id = self::get_current_user_id();
        PIT_User_Limits_Repository::increment_exports($user_id);
    }

    /**
     * Return success response
     *
     * @param mixed $data Response data
     * @param int $status HTTP status code
     * @return WP_REST_Response
     */
    protected static function success($data, $status = 200) {
        return new WP_REST_Response($data, $status);
    }

    /**
     * Return error response
     *
     * @param string $code Error code
     * @param string $message Error message
     * @param int $status HTTP status code
     * @return WP_Error
     */
    protected static function error($code, $message, $status = 400) {
        return new WP_Error($code, $message, ['status' => $status]);
    }

    /**
     * Get pagination parameters from request
     *
     * @param WP_REST_Request $request
     * @return array
     */
    protected static function get_pagination_params($request) {
        return [
            'page' => (int) ($request->get_param('page') ?? 1),
            'per_page' => (int) ($request->get_param('per_page') ?? 20),
        ];
    }

    /**
     * Get search parameter from request
     *
     * @param WP_REST_Request $request
     * @return string
     */
    protected static function get_search_param($request) {
        return sanitize_text_field($request->get_param('search') ?? '');
    }

    /**
     * Get user's usage summary
     *
     * @return array
     */
    protected static function get_usage_summary() {
        $user_id = self::get_current_user_id();
        return PIT_User_Limits_Repository::get_usage_summary($user_id);
    }

    /**
     * Add user_id to query args based on user context
     *
     * @param array $args Query arguments
     * @return array Modified arguments
     */
    protected static function scope_query_args($args) {
        return PIT_User_Context::scope_query($args);
    }
}
