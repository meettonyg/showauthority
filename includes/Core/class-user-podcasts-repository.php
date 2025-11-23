<?php
/**
 * User Podcasts Repository
 *
 * Handles the many-to-many relationship between users and podcasts.
 * Podcasts are global/shared, but users track specific podcasts.
 *
 * @package PodcastInfluenceTracker
 * @subpackage Core
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_User_Podcasts_Repository {

    /**
     * Get user's tracked podcasts
     *
     * @param int $user_id User ID
     * @param array $args Query arguments
     * @return array
     */
    public static function get_for_user($user_id, $args = []) {
        global $wpdb;
        $user_podcasts_table = $wpdb->prefix . 'pit_user_podcasts';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';

        $defaults = [
            'page' => 1,
            'per_page' => 20,
            'tracked_only' => true,
            'favorites_only' => false,
            'orderby' => 'created_at',
            'order' => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);
        $offset = ($args['page'] - 1) * $args['per_page'];

        $where = ['up.user_id = %d'];
        $params = [$user_id];

        if ($args['tracked_only']) {
            $where[] = 'up.is_tracked = 1';
        }

        if ($args['favorites_only']) {
            $where[] = 'up.is_favorite = 1';
        }

        $where_clause = implode(' AND ', $where);

        $allowed_orderby = ['created_at', 'updated_at', 'title'];
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'up.created_at';
        if ($args['orderby'] === 'title') {
            $orderby = 'p.title';
        } else {
            $orderby = 'up.' . $args['orderby'];
        }
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $query = "SELECT p.*, up.is_tracked, up.tracking_status as user_tracking_status,
                         up.user_notes, up.user_tags, up.is_favorite, up.refresh_frequency,
                         up.created_at as user_added_at
                  FROM $user_podcasts_table up
                  INNER JOIN $podcasts_table p ON up.podcast_id = p.id
                  WHERE $where_clause
                  ORDER BY $orderby $order
                  LIMIT %d OFFSET %d";

        $params[] = $args['per_page'];
        $params[] = $offset;

        $results = $wpdb->get_results($wpdb->prepare($query, ...$params));

        // Count query
        $count_params = array_slice($params, 0, -2);
        $count_query = "SELECT COUNT(*) FROM $user_podcasts_table up WHERE $where_clause";
        $total = $wpdb->get_var($wpdb->prepare($count_query, ...$count_params));

        return [
            'podcasts' => $results,
            'total' => (int) $total,
            'pages' => ceil($total / $args['per_page']),
        ];
    }

    /**
     * Check if user is tracking a podcast
     *
     * @param int $user_id User ID
     * @param int $podcast_id Podcast ID
     * @return bool
     */
    public static function is_tracking($user_id, $podcast_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_user_podcasts';

        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT is_tracked FROM $table WHERE user_id = %d AND podcast_id = %d",
            $user_id,
            $podcast_id
        ));

        return $result !== null && (int) $result === 1;
    }

    /**
     * Get user-podcast relationship
     *
     * @param int $user_id User ID
     * @param int $podcast_id Podcast ID
     * @return object|null
     */
    public static function get($user_id, $podcast_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_user_podcasts';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d AND podcast_id = %d",
            $user_id,
            $podcast_id
        ));
    }

    /**
     * Start tracking a podcast for a user
     *
     * @param int $user_id User ID
     * @param int $podcast_id Podcast ID
     * @param array $data Additional data
     * @return int|false Relationship ID or false
     */
    public static function track($user_id, $podcast_id, $data = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_user_podcasts';

        // Check limits
        if (!PIT_User_Limits_Repository::can_track_podcast($user_id)) {
            return false;
        }

        // Check if already exists
        $existing = self::get($user_id, $podcast_id);

        if ($existing) {
            // Reactivate tracking
            $wpdb->update($table, array_merge([
                'is_tracked' => 1,
                'tracking_status' => 'queued',
            ], $data), [
                'user_id' => $user_id,
                'podcast_id' => $podcast_id,
            ]);

            // Only increment if was not tracked before
            if (!$existing->is_tracked) {
                PIT_User_Limits_Repository::increment_podcasts($user_id);
            }

            return $existing->id;
        }

        // Create new tracking
        $insert_data = array_merge([
            'user_id' => $user_id,
            'podcast_id' => $podcast_id,
            'is_tracked' => 1,
            'tracking_status' => 'queued',
        ], $data);

        $wpdb->insert($table, $insert_data);
        $id = $wpdb->insert_id ?: false;

        if ($id) {
            PIT_User_Limits_Repository::increment_podcasts($user_id);
        }

        return $id;
    }

    /**
     * Stop tracking a podcast for a user
     *
     * @param int $user_id User ID
     * @param int $podcast_id Podcast ID
     * @return bool
     */
    public static function untrack($user_id, $podcast_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_user_podcasts';

        $existing = self::get($user_id, $podcast_id);
        if (!$existing || !$existing->is_tracked) {
            return true; // Already not tracked
        }

        $result = $wpdb->update($table, [
            'is_tracked' => 0,
            'tracking_status' => 'not_tracked',
        ], [
            'user_id' => $user_id,
            'podcast_id' => $podcast_id,
        ]) !== false;

        if ($result) {
            PIT_User_Limits_Repository::decrement_podcasts($user_id);
        }

        return $result;
    }

    /**
     * Update user-podcast relationship
     *
     * @param int $user_id User ID
     * @param int $podcast_id Podcast ID
     * @param array $data Data to update
     * @return bool
     */
    public static function update($user_id, $podcast_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_user_podcasts';

        unset($data['user_id'], $data['podcast_id'], $data['created_at']);

        return $wpdb->update($table, $data, [
            'user_id' => $user_id,
            'podcast_id' => $podcast_id,
        ]) !== false;
    }

    /**
     * Toggle favorite status
     *
     * @param int $user_id User ID
     * @param int $podcast_id Podcast ID
     * @return bool New favorite status
     */
    public static function toggle_favorite($user_id, $podcast_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_user_podcasts';

        $existing = self::get($user_id, $podcast_id);
        if (!$existing) {
            return false;
        }

        $new_status = $existing->is_favorite ? 0 : 1;

        $wpdb->update($table, ['is_favorite' => $new_status], [
            'user_id' => $user_id,
            'podcast_id' => $podcast_id,
        ]);

        return (bool) $new_status;
    }

    /**
     * Update user notes for a podcast
     *
     * @param int $user_id User ID
     * @param int $podcast_id Podcast ID
     * @param string $notes Notes
     * @return bool
     */
    public static function update_notes($user_id, $podcast_id, $notes) {
        return self::update($user_id, $podcast_id, ['user_notes' => $notes]);
    }

    /**
     * Update user tags for a podcast
     *
     * @param int $user_id User ID
     * @param int $podcast_id Podcast ID
     * @param string|array $tags Tags (string or array)
     * @return bool
     */
    public static function update_tags($user_id, $podcast_id, $tags) {
        if (is_array($tags)) {
            $tags = implode(',', $tags);
        }

        return self::update($user_id, $podcast_id, ['user_tags' => $tags]);
    }

    /**
     * Get user IDs tracking a specific podcast
     *
     * @param int $podcast_id Podcast ID
     * @return array User IDs
     */
    public static function get_users_tracking($podcast_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_user_podcasts';

        return $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM $table WHERE podcast_id = %d AND is_tracked = 1",
            $podcast_id
        ));
    }

    /**
     * Get count of users tracking a podcast
     *
     * @param int $podcast_id Podcast ID
     * @return int
     */
    public static function get_tracking_count($podcast_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_user_podcasts';

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE podcast_id = %d AND is_tracked = 1",
            $podcast_id
        ));
    }

    /**
     * Get podcasts needing refresh for a user
     *
     * @param int $user_id User ID
     * @param string $frequency Refresh frequency (daily, weekly, monthly)
     * @return array Podcast IDs
     */
    public static function get_needing_refresh($user_id, $frequency = null) {
        global $wpdb;
        $user_podcasts_table = $wpdb->prefix . 'pit_user_podcasts';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';

        $where = ['up.user_id = %d', 'up.is_tracked = 1'];
        $params = [$user_id];

        if ($frequency) {
            $where[] = 'up.refresh_frequency = %s';
            $params[] = $frequency;
        }

        $where_clause = implode(' AND ', $where);

        // Get podcasts that need refresh based on their frequency
        $query = "SELECT up.podcast_id, up.refresh_frequency, p.last_enriched_at
                  FROM $user_podcasts_table up
                  INNER JOIN $podcasts_table p ON up.podcast_id = p.id
                  WHERE $where_clause
                  AND (
                      p.last_enriched_at IS NULL
                      OR (up.refresh_frequency = 'daily' AND p.last_enriched_at < DATE_SUB(NOW(), INTERVAL 1 DAY))
                      OR (up.refresh_frequency = 'weekly' AND p.last_enriched_at < DATE_SUB(NOW(), INTERVAL 1 WEEK))
                      OR (up.refresh_frequency = 'monthly' AND p.last_enriched_at < DATE_SUB(NOW(), INTERVAL 1 MONTH))
                  )";

        return $wpdb->get_col($wpdb->prepare($query, ...$params));
    }

    /**
     * Get statistics for a user
     *
     * @param int $user_id User ID
     * @return array
     */
    public static function get_user_stats($user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_user_podcasts';

        return [
            'tracked' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE user_id = %d AND is_tracked = 1",
                $user_id
            )),
            'favorites' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE user_id = %d AND is_favorite = 1",
                $user_id
            )),
            'total' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE user_id = %d",
                $user_id
            )),
        ];
    }
}
