<?php
/**
 * User Limits Repository
 *
 * Handles database operations for user limits and usage tracking.
 *
 * @package PodcastInfluenceTracker
 * @subpackage Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_User_Limits_Repository {

    /**
     * Plan configurations
     */
    const PLANS = [
        'free' => [
            'max_tracked_podcasts' => 10,
            'max_guests' => 100,
            'max_api_calls_month' => 500,
            'max_exports_month' => 10,
        ],
        'starter' => [
            'max_tracked_podcasts' => 50,
            'max_guests' => 500,
            'max_api_calls_month' => 2500,
            'max_exports_month' => 50,
        ],
        'pro' => [
            'max_tracked_podcasts' => 200,
            'max_guests' => 2000,
            'max_api_calls_month' => 10000,
            'max_exports_month' => 200,
        ],
        'enterprise' => [
            'max_tracked_podcasts' => -1, // unlimited
            'max_guests' => -1,
            'max_api_calls_month' => -1,
            'max_exports_month' => -1,
        ],
    ];

    /**
     * Get user limits by user ID
     *
     * @param int $user_id User ID
     * @return object|null
     */
    public static function get($user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_user_limits';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d",
            $user_id
        ));
    }

    /**
     * Get or create user limits
     *
     * @param int $user_id User ID
     * @return object
     */
    public static function get_or_create($user_id) {
        $limits = self::get($user_id);

        if (!$limits) {
            self::create_default($user_id);
            $limits = self::get($user_id);
        }

        return $limits;
    }

    /**
     * Create default limits for a user (free plan)
     *
     * @param int $user_id User ID
     * @return int|false Insert ID or false
     */
    public static function create_default($user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_user_limits';

        $defaults = self::PLANS['free'];

        $result = $wpdb->insert($table, [
            'user_id' => $user_id,
            'plan_type' => 'free',
            'plan_started_at' => current_time('mysql'),
            'max_tracked_podcasts' => $defaults['max_tracked_podcasts'],
            'max_guests' => $defaults['max_guests'],
            'max_api_calls_month' => $defaults['max_api_calls_month'],
            'max_exports_month' => $defaults['max_exports_month'],
            'billing_cycle_start' => current_time('Y-m-d'),
            'last_usage_reset' => current_time('mysql'),
        ]);

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Update user plan
     *
     * @param int $user_id User ID
     * @param string $plan_type Plan type
     * @param array $extra Extra data (stripe IDs, etc.)
     * @return bool
     */
    public static function update_plan($user_id, $plan_type, $extra = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_user_limits';

        if (!isset(self::PLANS[$plan_type])) {
            return false;
        }

        $limits = self::PLANS[$plan_type];

        $data = array_merge([
            'plan_type' => $plan_type,
            'plan_started_at' => current_time('mysql'),
            'max_tracked_podcasts' => $limits['max_tracked_podcasts'],
            'max_guests' => $limits['max_guests'],
            'max_api_calls_month' => $limits['max_api_calls_month'],
            'max_exports_month' => $limits['max_exports_month'],
        ], $extra);

        return $wpdb->update($table, $data, ['user_id' => $user_id]) !== false;
    }

    /**
     * Check if user can track more podcasts
     *
     * @param int $user_id User ID
     * @return bool
     */
    public static function can_track_podcast($user_id) {
        $limits = self::get_or_create($user_id);

        // -1 means unlimited
        if ($limits->max_tracked_podcasts === -1) {
            return true;
        }

        return $limits->current_tracked_podcasts < $limits->max_tracked_podcasts;
    }

    /**
     * Check if user can add more guests
     *
     * @param int $user_id User ID
     * @return bool
     */
    public static function can_add_guest($user_id) {
        $limits = self::get_or_create($user_id);

        if ($limits->max_guests === -1) {
            return true;
        }

        return $limits->current_guests < $limits->max_guests;
    }

    /**
     * Check if user can make API calls
     *
     * @param int $user_id User ID
     * @return bool
     */
    public static function can_make_api_call($user_id) {
        $limits = self::get_or_create($user_id);

        if ($limits->max_api_calls_month === -1) {
            return true;
        }

        return $limits->current_api_calls < $limits->max_api_calls_month;
    }

    /**
     * Check if user can export
     *
     * @param int $user_id User ID
     * @return bool
     */
    public static function can_export($user_id) {
        $limits = self::get_or_create($user_id);

        if ($limits->max_exports_month === -1) {
            return true;
        }

        return $limits->current_exports < $limits->max_exports_month;
    }

    /**
     * Increment tracked podcasts count
     *
     * @param int $user_id User ID
     * @param int $amount Amount to increment (default 1)
     * @return bool
     */
    public static function increment_podcasts($user_id, $amount = 1) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_user_limits';

        return $wpdb->query($wpdb->prepare(
            "UPDATE $table SET current_tracked_podcasts = current_tracked_podcasts + %d WHERE user_id = %d",
            $amount,
            $user_id
        )) !== false;
    }

    /**
     * Decrement tracked podcasts count
     *
     * @param int $user_id User ID
     * @param int $amount Amount to decrement (default 1)
     * @return bool
     */
    public static function decrement_podcasts($user_id, $amount = 1) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_user_limits';

        return $wpdb->query($wpdb->prepare(
            "UPDATE $table SET current_tracked_podcasts = GREATEST(0, current_tracked_podcasts - %d) WHERE user_id = %d",
            $amount,
            $user_id
        )) !== false;
    }

    /**
     * Increment guests count
     *
     * @param int $user_id User ID
     * @param int $amount Amount to increment (default 1)
     * @return bool
     */
    public static function increment_guests($user_id, $amount = 1) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_user_limits';

        return $wpdb->query($wpdb->prepare(
            "UPDATE $table SET current_guests = current_guests + %d WHERE user_id = %d",
            $amount,
            $user_id
        )) !== false;
    }

    /**
     * Increment API calls count
     *
     * @param int $user_id User ID
     * @param int $amount Amount to increment (default 1)
     * @return bool
     */
    public static function increment_api_calls($user_id, $amount = 1) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_user_limits';

        return $wpdb->query($wpdb->prepare(
            "UPDATE $table SET current_api_calls = current_api_calls + %d WHERE user_id = %d",
            $amount,
            $user_id
        )) !== false;
    }

    /**
     * Increment exports count
     *
     * @param int $user_id User ID
     * @param int $amount Amount to increment (default 1)
     * @return bool
     */
    public static function increment_exports($user_id, $amount = 1) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_user_limits';

        return $wpdb->query($wpdb->prepare(
            "UPDATE $table SET current_exports = current_exports + %d WHERE user_id = %d",
            $amount,
            $user_id
        )) !== false;
    }

    /**
     * Reset monthly usage (called by cron)
     *
     * @return int Number of users reset
     */
    public static function reset_monthly_usage() {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_user_limits';

        // Reset users whose billing cycle has passed
        return $wpdb->query("
            UPDATE $table
            SET current_api_calls = 0,
                current_exports = 0,
                billing_cycle_start = CURDATE(),
                last_usage_reset = NOW()
            WHERE billing_cycle_start <= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
        ");
    }

    /**
     * Get usage summary for a user
     *
     * @param int $user_id User ID
     * @return array
     */
    public static function get_usage_summary($user_id) {
        $limits = self::get_or_create($user_id);

        return [
            'plan' => $limits->plan_type,
            'podcasts' => [
                'used' => (int) $limits->current_tracked_podcasts,
                'max' => (int) $limits->max_tracked_podcasts,
                'remaining' => $limits->max_tracked_podcasts === -1 ? 'unlimited' : max(0, $limits->max_tracked_podcasts - $limits->current_tracked_podcasts),
            ],
            'guests' => [
                'used' => (int) $limits->current_guests,
                'max' => (int) $limits->max_guests,
                'remaining' => $limits->max_guests === -1 ? 'unlimited' : max(0, $limits->max_guests - $limits->current_guests),
            ],
            'api_calls' => [
                'used' => (int) $limits->current_api_calls,
                'max' => (int) $limits->max_api_calls_month,
                'remaining' => $limits->max_api_calls_month === -1 ? 'unlimited' : max(0, $limits->max_api_calls_month - $limits->current_api_calls),
            ],
            'exports' => [
                'used' => (int) $limits->current_exports,
                'max' => (int) $limits->max_exports_month,
                'remaining' => $limits->max_exports_month === -1 ? 'unlimited' : max(0, $limits->max_exports_month - $limits->current_exports),
            ],
            'billing_cycle_start' => $limits->billing_cycle_start,
        ];
    }

    /**
     * Sync actual counts from database
     * (useful after migrations or data corrections)
     *
     * @param int $user_id User ID
     * @return bool
     */
    public static function sync_actual_counts($user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_user_limits';
        $user_podcasts_table = $wpdb->prefix . 'pit_user_podcasts';
        $guests_table = $wpdb->prefix . 'pit_guests';

        // Get actual counts
        $podcast_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $user_podcasts_table WHERE user_id = %d AND is_tracked = 1",
            $user_id
        ));

        $guest_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $guests_table WHERE user_id = %d AND is_merged = 0",
            $user_id
        ));

        return $wpdb->update($table, [
            'current_tracked_podcasts' => $podcast_count,
            'current_guests' => $guest_count,
        ], ['user_id' => $user_id]) !== false;
    }
}
