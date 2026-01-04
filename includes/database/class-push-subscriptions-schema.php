<?php
/**
 * Push Subscriptions Database Schema
 *
 * Schema for pit_push_subscriptions table to store browser push
 * notification subscriptions for Web Push API.
 *
 * @package Podcast_Influence_Tracker
 * @since 3.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Push_Subscriptions_Schema {

    /**
     * Table name (without prefix)
     */
    const TABLE_NAME = 'pit_push_subscriptions';

    /**
     * Database version
     */
    const DB_VERSION = '1.0.0';

    /**
     * Get full table name with prefix
     */
    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Create the push subscriptions table
     */
    public static function create_table() {
        global $wpdb;

        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,

            -- Web Push subscription details
            endpoint VARCHAR(500) NOT NULL,
            p256dh VARCHAR(255) NOT NULL,
            auth VARCHAR(100) NOT NULL,

            -- Device info (for user management)
            user_agent TEXT NULL,
            device_name VARCHAR(100) NULL,

            -- Status
            is_active TINYINT(1) DEFAULT 1,
            last_used_at DATETIME NULL,
            failed_attempts INT DEFAULT 0,

            -- Timestamps
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            -- Indexes
            UNIQUE KEY idx_endpoint (endpoint(191)),
            INDEX idx_user_id (user_id),
            INDEX idx_user_active (user_id, is_active)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Verify table was created
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

        if ($table_exists) {
            update_option('pit_push_subscriptions_db_version', self::DB_VERSION);
        }

        return $table_exists;
    }

    /**
     * Check if table exists
     */
    public static function table_exists() {
        global $wpdb;
        $table_name = self::get_table_name();
        return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    }

    /**
     * Drop the table (use with caution)
     */
    public static function drop_table() {
        global $wpdb;
        $table_name = self::get_table_name();
        $wpdb->query("DROP TABLE IF EXISTS $table_name");
        delete_option('pit_push_subscriptions_db_version');
    }

    /**
     * Save a push subscription
     *
     * @param int   $user_id      User ID
     * @param array $subscription Subscription data (endpoint, keys)
     * @param array $device_info  Optional device info
     * @return int|false Subscription ID or false on failure
     */
    public static function save_subscription($user_id, $subscription, $device_info = []) {
        global $wpdb;

        $table = self::get_table_name();

        $endpoint = $subscription['endpoint'] ?? '';
        $p256dh = $subscription['keys']['p256dh'] ?? '';
        $auth = $subscription['keys']['auth'] ?? '';

        if (empty($endpoint) || empty($p256dh) || empty($auth)) {
            return false;
        }

        // Check if subscription already exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE endpoint = %s",
            $endpoint
        ));

        if ($existing) {
            // Update existing subscription
            $wpdb->update(
                $table,
                [
                    'user_id'     => $user_id,
                    'p256dh'      => $p256dh,
                    'auth'        => $auth,
                    'is_active'   => 1,
                    'user_agent'  => $device_info['user_agent'] ?? null,
                    'device_name' => $device_info['device_name'] ?? null,
                    'updated_at'  => current_time('mysql'),
                ],
                ['id' => $existing]
            );
            return $existing;
        }

        // Insert new subscription
        $result = $wpdb->insert($table, [
            'user_id'     => $user_id,
            'endpoint'    => $endpoint,
            'p256dh'      => $p256dh,
            'auth'        => $auth,
            'user_agent'  => $device_info['user_agent'] ?? null,
            'device_name' => $device_info['device_name'] ?? null,
            'created_at'  => current_time('mysql'),
        ]);

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Get active subscriptions for a user
     *
     * @param int $user_id User ID
     * @return array
     */
    public static function get_user_subscriptions($user_id) {
        global $wpdb;
        $table = self::get_table_name();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d AND is_active = 1",
            $user_id
        ), ARRAY_A);
    }

    /**
     * Delete a subscription
     *
     * @param int $subscription_id Subscription ID
     * @return bool
     */
    public static function delete_subscription($subscription_id) {
        global $wpdb;
        $table = self::get_table_name();

        return $wpdb->delete($table, ['id' => $subscription_id]) !== false;
    }

    /**
     * Delete subscription by endpoint
     *
     * @param string $endpoint Push endpoint URL
     * @return bool
     */
    public static function delete_by_endpoint($endpoint) {
        global $wpdb;
        $table = self::get_table_name();

        return $wpdb->delete($table, ['endpoint' => $endpoint]) !== false;
    }

    /**
     * Mark subscription as failed
     *
     * Increments fail counter and deactivates if too many failures.
     *
     * @param int $subscription_id Subscription ID
     * @return bool
     */
    public static function mark_failed($subscription_id) {
        global $wpdb;
        $table = self::get_table_name();

        // Increment fail counter
        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET failed_attempts = failed_attempts + 1 WHERE id = %d",
            $subscription_id
        ));

        // Deactivate if too many failures (e.g., 3)
        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET is_active = 0 WHERE id = %d AND failed_attempts >= 3",
            $subscription_id
        ));

        return true;
    }

    /**
     * Mark subscription as successfully used
     *
     * @param int $subscription_id Subscription ID
     * @return bool
     */
    public static function mark_success($subscription_id) {
        global $wpdb;
        $table = self::get_table_name();

        return $wpdb->update(
            $table,
            [
                'last_used_at'    => current_time('mysql'),
                'failed_attempts' => 0,
            ],
            ['id' => $subscription_id]
        ) !== false;
    }

    /**
     * Get subscription by endpoint
     *
     * @param string $endpoint Push endpoint URL
     * @return array|null
     */
    public static function get_by_endpoint($endpoint) {
        global $wpdb;
        $table = self::get_table_name();

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE endpoint = %s",
            $endpoint
        ), ARRAY_A);
    }
}
