<?php
/**
 * Rate Limiter Service
 *
 * Handles API rate limiting for multi-tenant SaaS.
 *
 * @package PodcastInfluenceTracker
 * @subpackage Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Rate_Limiter {

    /**
     * Default rate limits per endpoint type
     */
    const DEFAULT_LIMITS = [
        'read' => ['requests' => 100, 'window' => 60],      // 100 per minute
        'write' => ['requests' => 30, 'window' => 60],       // 30 per minute
        'export' => ['requests' => 5, 'window' => 60],       // 5 per minute
        'enrichment' => ['requests' => 10, 'window' => 60],  // 10 per minute
        'default' => ['requests' => 60, 'window' => 60],     // 60 per minute
    ];

    /**
     * Endpoint type mappings
     */
    const ENDPOINT_TYPES = [
        // Read endpoints
        'get_podcasts' => 'read',
        'get_guests' => 'read',
        'get_contacts' => 'read',
        'get_network' => 'read',
        'get_statistics' => 'read',
        // Write endpoints
        'create_guest' => 'write',
        'update_guest' => 'write',
        'create_contact' => 'write',
        'update_contact' => 'write',
        'track_podcast' => 'write',
        // Export endpoints
        'export_guests' => 'export',
        'export_podcasts' => 'export',
        'export_network' => 'export',
        // Enrichment endpoints
        'enrich_guest' => 'enrichment',
        'enrich_podcast' => 'enrichment',
    ];

    /**
     * Check if request is allowed (rate limit check)
     *
     * @param int $user_id User ID
     * @param string $endpoint Endpoint name
     * @return array ['allowed' => bool, 'remaining' => int, 'reset' => int]
     */
    public static function check($user_id, $endpoint) {
        if (!$user_id) {
            return ['allowed' => false, 'remaining' => 0, 'reset' => 0, 'error' => 'Authentication required'];
        }

        // Admins bypass rate limiting
        if (PIT_User_Context::is_admin()) {
            return ['allowed' => true, 'remaining' => PHP_INT_MAX, 'reset' => 0];
        }

        $endpoint_type = self::ENDPOINT_TYPES[$endpoint] ?? 'default';
        $limits = self::DEFAULT_LIMITS[$endpoint_type];

        $current = self::get_current_count($user_id, $endpoint, $limits['window']);

        $allowed = $current['count'] < $limits['requests'];
        $remaining = max(0, $limits['requests'] - $current['count']);
        $reset = $current['window_start'] + $limits['window'];

        return [
            'allowed' => $allowed,
            'remaining' => $remaining,
            'reset' => $reset,
            'limit' => $limits['requests'],
            'window' => $limits['window'],
        ];
    }

    /**
     * Record a request (increment counter)
     *
     * @param int $user_id User ID
     * @param string $endpoint Endpoint name
     * @return bool
     */
    public static function record($user_id, $endpoint) {
        if (!$user_id || PIT_User_Context::is_admin()) {
            return true;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pit_rate_limits';

        $endpoint_type = self::ENDPOINT_TYPES[$endpoint] ?? 'default';
        $limits = self::DEFAULT_LIMITS[$endpoint_type];
        $window_seconds = $limits['window'];

        // Calculate window start (round down to nearest window)
        $now = time();
        $window_start = gmdate('Y-m-d H:i:s', $now - ($now % $window_seconds));

        // Try to insert or update
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO $table (user_id, endpoint, request_count, window_start, window_seconds)
             VALUES (%d, %s, 1, %s, %d)
             ON DUPLICATE KEY UPDATE request_count = request_count + 1",
            $user_id,
            $endpoint,
            $window_start,
            $window_seconds
        ));

        // Also increment monthly API call counter
        PIT_User_Limits_Repository::increment_api_calls($user_id);

        return $result !== false;
    }

    /**
     * Check and record in one operation
     *
     * @param int $user_id User ID
     * @param string $endpoint Endpoint name
     * @return array ['allowed' => bool, ...]
     */
    public static function check_and_record($user_id, $endpoint) {
        $check = self::check($user_id, $endpoint);

        if ($check['allowed']) {
            self::record($user_id, $endpoint);
            $check['remaining'] = max(0, $check['remaining'] - 1);
        }

        return $check;
    }

    /**
     * Get current request count for user/endpoint
     *
     * @param int $user_id User ID
     * @param string $endpoint Endpoint name
     * @param int $window_seconds Window in seconds
     * @return array ['count' => int, 'window_start' => int]
     */
    private static function get_current_count($user_id, $endpoint, $window_seconds) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_rate_limits';

        $now = time();
        $window_start = $now - ($now % $window_seconds);
        $window_start_str = gmdate('Y-m-d H:i:s', $window_start);

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT request_count FROM $table
             WHERE user_id = %d AND endpoint = %s AND window_start = %s",
            $user_id,
            $endpoint,
            $window_start_str
        ));

        return [
            'count' => (int) ($count ?? 0),
            'window_start' => $window_start,
        ];
    }

    /**
     * Get rate limit headers for response
     *
     * @param array $check Result from check()
     * @return array Headers
     */
    public static function get_headers($check) {
        return [
            'X-RateLimit-Limit' => $check['limit'] ?? 0,
            'X-RateLimit-Remaining' => $check['remaining'],
            'X-RateLimit-Reset' => $check['reset'],
        ];
    }

    /**
     * Clean up old rate limit records
     *
     * @return int Number of records deleted
     */
    public static function cleanup() {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_rate_limits';

        // Delete records older than 1 hour
        return $wpdb->query(
            "DELETE FROM $table WHERE window_start < DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
    }

    /**
     * Get rate limit status for a user
     *
     * @param int $user_id User ID
     * @return array Status for all endpoint types
     */
    public static function get_status($user_id) {
        $status = [];

        foreach (self::DEFAULT_LIMITS as $type => $limits) {
            // Find an endpoint of this type
            $endpoint = array_search($type, self::ENDPOINT_TYPES) ?: $type;
            $check = self::check($user_id, $endpoint);

            $status[$type] = [
                'limit' => $limits['requests'],
                'window' => $limits['window'],
                'remaining' => $check['remaining'],
                'reset' => $check['reset'],
            ];
        }

        return $status;
    }

    /**
     * Check if user has exceeded monthly API limit
     *
     * @param int $user_id User ID
     * @return bool
     */
    public static function has_exceeded_monthly_limit($user_id) {
        return !PIT_User_Limits_Repository::can_make_api_call($user_id);
    }
}
