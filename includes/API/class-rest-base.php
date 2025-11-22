<?php
/**
 * REST API Base Controller
 *
 * Provides common functionality for all REST controllers.
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
}
