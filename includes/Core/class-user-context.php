<?php
/**
 * User Context Service
 *
 * Manages user context for multi-tenancy support.
 * Provides user ID injection for all queries and ownership validation.
 *
 * @package PodcastInfluenceTracker
 * @subpackage Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_User_Context {

    /**
     * @var int|null Override user ID (for admin impersonation)
     */
    private static $override_user_id = null;

    /**
     * Get current user ID
     *
     * @return int User ID (0 if not logged in)
     */
    public static function get_user_id() {
        if (self::$override_user_id !== null) {
            return self::$override_user_id;
        }

        return get_current_user_id();
    }

    /**
     * Check if user is logged in
     *
     * @return bool
     */
    public static function is_logged_in() {
        return self::get_user_id() > 0;
    }

    /**
     * Check if current user is admin
     *
     * @return bool
     */
    public static function is_admin() {
        return current_user_can('manage_options');
    }

    /**
     * Override user ID (for admin operations)
     *
     * @param int|null $user_id User ID to impersonate, or null to clear
     */
    public static function set_override($user_id) {
        if (self::is_admin()) {
            self::$override_user_id = $user_id;
        }
    }

    /**
     * Clear user override
     */
    public static function clear_override() {
        self::$override_user_id = null;
    }

    /**
     * Require logged in user
     *
     * @throws Exception If not logged in
     */
    public static function require_login() {
        if (!self::is_logged_in()) {
            throw new Exception('Authentication required', 401);
        }
    }

    /**
     * Validate ownership of a record
     *
     * @param int $record_user_id User ID from the record
     * @return bool True if current user owns the record or is admin
     */
    public static function owns_record($record_user_id) {
        if (self::is_admin()) {
            return true;
        }

        return self::get_user_id() === (int) $record_user_id;
    }

    /**
     * Add user_id to query args if not admin
     *
     * @param array $args Query arguments
     * @param bool $allow_public Include public records
     * @return array Modified arguments
     */
    public static function scope_query($args, $allow_public = false) {
        if (self::is_admin()) {
            return $args;
        }

        $args['user_id'] = self::get_user_id();

        if ($allow_public) {
            $args['include_public'] = true;
        }

        return $args;
    }

    /**
     * Add user_id to data for insert/update
     *
     * @param array $data Record data
     * @return array Modified data with user_id
     */
    public static function stamp_record($data) {
        if (!isset($data['user_id'])) {
            $data['user_id'] = self::get_user_id();
        }

        return $data;
    }

    /**
     * Get user display info
     *
     * @param int|null $user_id User ID (defaults to current)
     * @return array User display info
     */
    public static function get_user_info($user_id = null) {
        $user_id = $user_id ?? self::get_user_id();
        $user = get_userdata($user_id);

        if (!$user) {
            return [
                'id' => 0,
                'name' => 'Anonymous',
                'email' => '',
            ];
        }

        return [
            'id' => $user->ID,
            'name' => $user->display_name,
            'email' => $user->user_email,
        ];
    }
}
