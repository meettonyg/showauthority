<?php
/**
 * Engagement Repository
 *
 * Handles all database operations for public speaking engagements.
 * Engagements are global records of speaking events.
 *
 * @package PodcastInfluenceTracker
 * @subpackage Engagements
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Engagement_Repository {

    /**
     * Get engagement by ID
     *
     * @param int $engagement_id Engagement ID
     * @return object|null Engagement object or null
     */
    public static function get($engagement_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_engagements';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $engagement_id
        ));
    }

    /**
     * Get engagement by episode GUID
     *
     * @param string $episode_guid Episode GUID
     * @return object|null Engagement object or null
     */
    public static function get_by_guid($episode_guid) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_engagements';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE episode_guid = %s",
            $episode_guid
        ));
    }

    /**
     * Get engagement by uniqueness hash
     *
     * @param string $hash Uniqueness hash
     * @return object|null Engagement object or null
     */
    public static function get_by_hash($hash) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_engagements';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE uniqueness_hash = %s",
            $hash
        ));
    }

    /**
     * Get engagements by podcast ID
     *
     * @param int $podcast_id Podcast ID
     * @param array $args Additional arguments
     * @return array Engagement objects
     */
    public static function get_by_podcast($podcast_id, $args = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_engagements';

        $defaults = [
            'limit' => 100,
            'orderby' => 'engagement_date',
            'order' => 'DESC',
        ];
        $args = wp_parse_args($args, $defaults);

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE podcast_id = %d ORDER BY {$args['orderby']} {$args['order']} LIMIT %d",
            $podcast_id, $args['limit']
        ));
    }

    /**
     * Create a new engagement
     *
     * @param array $data Engagement data
     * @return int|false Engagement ID or false on failure
     */
    public static function create($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_engagements';

        // Generate uniqueness hash if not provided
        if (empty($data['uniqueness_hash'])) {
            $data['uniqueness_hash'] = self::generate_hash($data);
        }

        // Set defaults
        if (!isset($data['engagement_type'])) {
            $data['engagement_type'] = 'podcast';
        }
        if (!isset($data['created_at'])) {
            $data['created_at'] = current_time('mysql');
        }
        if (!isset($data['updated_at'])) {
            $data['updated_at'] = current_time('mysql');
        }
        if (!isset($data['discovered_by_user_id'])) {
            $data['discovered_by_user_id'] = PIT_User_Context::get_user_id();
        }

        $wpdb->insert($table, $data);

        return $wpdb->insert_id ?: false;
    }

    /**
     * Create or find engagement (upsert with deduplication)
     *
     * @param array $data Engagement data
     * @return array ['id' => int, 'created' => bool]
     */
    public static function upsert($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_engagements';

        // Priority 1: Check by episode_guid
        if (!empty($data['episode_guid'])) {
            $existing = self::get_by_guid($data['episode_guid']);
            if ($existing) {
                return ['id' => (int) $existing->id, 'created' => false];
            }
        }

        // Priority 2: Check by uniqueness_hash
        $hash = self::generate_hash($data);
        if ($hash) {
            $existing = self::get_by_hash($hash);
            if ($existing) {
                return ['id' => (int) $existing->id, 'created' => false];
            }
        }

        // No match - create new
        $data['uniqueness_hash'] = $hash;
        $id = self::create($data);

        return ['id' => $id, 'created' => true];
    }

    /**
     * Update an engagement
     *
     * @param int $engagement_id Engagement ID
     * @param array $data Data to update
     * @return bool Success
     */
    public static function update($engagement_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_engagements';

        unset($data['created_at']);
        $data['updated_at'] = current_time('mysql');

        // Regenerate hash if relevant fields changed
        if (isset($data['podcast_id']) || isset($data['engagement_date']) || isset($data['episode_number']) || isset($data['title'])) {
            $existing = self::get($engagement_id);
            $merged = array_merge((array) $existing, $data);
            $data['uniqueness_hash'] = self::generate_hash($merged);
        }

        return $wpdb->update($table, $data, ['id' => $engagement_id]) !== false;
    }

    /**
     * Delete an engagement
     *
     * @param int $engagement_id Engagement ID
     * @return bool Success
     */
    public static function delete($engagement_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_engagements';
        $credits_table = $wpdb->prefix . 'pit_speaking_credits';

        // Delete associated speaking credits first
        $wpdb->delete($credits_table, ['engagement_id' => $engagement_id], ['%d']);

        return $wpdb->delete($table, ['id' => $engagement_id], ['%d']) !== false;
    }

    /**
     * List engagements with filtering and pagination
     *
     * @param array $args Query arguments
     * @return array Results with engagements, total, and pages
     */
    public static function list($args = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_engagements';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';

        $defaults = [
            'page' => 1,
            'per_page' => 20,
            'orderby' => 'engagement_date',
            'order' => 'DESC',
            'engagement_type' => '',
            'podcast_id' => null,
            'search' => '',
            'verified_only' => false,
            'date_from' => '',
            'date_to' => '',
        ];

        $args = wp_parse_args($args, $defaults);

        $where = [];
        $prepare_args = [];

        // Type filter
        if (!empty($args['engagement_type'])) {
            $where[] = 'e.engagement_type = %s';
            $prepare_args[] = $args['engagement_type'];
        }

        // Podcast filter
        if (!empty($args['podcast_id'])) {
            $where[] = 'e.podcast_id = %d';
            $prepare_args[] = $args['podcast_id'];
        }

        // Verified filter
        if ($args['verified_only']) {
            $where[] = 'e.is_verified = 1';
        }

        // Date range
        if (!empty($args['date_from'])) {
            $where[] = 'e.engagement_date >= %s';
            $prepare_args[] = $args['date_from'];
        }
        if (!empty($args['date_to'])) {
            $where[] = 'e.engagement_date <= %s';
            $prepare_args[] = $args['date_to'];
        }

        // Search
        if (!empty($args['search'])) {
            $where[] = '(e.title LIKE %s OR e.description LIKE %s OR p.title LIKE %s)';
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $prepare_args[] = $search;
            $prepare_args[] = $search;
            $prepare_args[] = $search;
        }

        $where_clause = !empty($where) ? implode(' AND ', $where) : '1=1';

        $offset = ($args['page'] - 1) * $args['per_page'];

        $allowed_orderby = ['created_at', 'updated_at', 'engagement_date', 'published_date', 'title'];
        $orderby = in_array($args['orderby'], $allowed_orderby) ? 'e.' . $args['orderby'] : 'e.engagement_date';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $sql = "SELECT e.*, p.title as podcast_name, p.artwork_url as podcast_image
                FROM $table e
                LEFT JOIN $podcasts_table p ON e.podcast_id = p.id
                WHERE $where_clause
                ORDER BY $orderby $order
                LIMIT %d OFFSET %d";
        $prepare_args[] = $args['per_page'];
        $prepare_args[] = $offset;

        $results = $wpdb->get_results($wpdb->prepare($sql, $prepare_args));

        // Count query
        $count_sql = "SELECT COUNT(*) FROM $table e
                      LEFT JOIN $podcasts_table p ON e.podcast_id = p.id
                      WHERE $where_clause";
        if (count($prepare_args) > 2) {
            $count_args = array_slice($prepare_args, 0, -2);
            $total = $wpdb->get_var($wpdb->prepare($count_sql, $count_args));
        } else {
            $total = $wpdb->get_var($count_sql);
        }

        return [
            'engagements' => $results,
            'total' => (int) $total,
            'pages' => ceil($total / $args['per_page']),
        ];
    }

    /**
     * Get engagements for a guest (via speaking credits)
     *
     * @param int $guest_id Guest ID
     * @param array $args Additional arguments
     * @return array Engagement objects with role info
     */
    public static function get_for_guest($guest_id, $args = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_engagements';
        $credits_table = $wpdb->prefix . 'pit_speaking_credits';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';

        $defaults = [
            'limit' => 100,
            'orderby' => 'engagement_date',
            'order' => 'DESC',
        ];
        $args = wp_parse_args($args, $defaults);

        return $wpdb->get_results($wpdb->prepare(
            "SELECT e.*, sc.role, sc.is_primary, p.title as podcast_name, p.artwork_url as podcast_image
             FROM $table e
             INNER JOIN $credits_table sc ON e.id = sc.engagement_id
             LEFT JOIN $podcasts_table p ON e.podcast_id = p.id
             WHERE sc.guest_id = %d
             ORDER BY e.{$args['orderby']} {$args['order']}
             LIMIT %d",
            $guest_id, $args['limit']
        ));
    }

    /**
     * Verify an engagement
     *
     * @param int $engagement_id Engagement ID
     * @param bool $verified Verification status
     * @return bool Success
     */
    public static function verify($engagement_id, $verified = true) {
        return self::update($engagement_id, [
            'is_verified' => $verified ? 1 : 0,
            'verified_by_user_id' => get_current_user_id(),
            'verified_at' => current_time('mysql'),
        ]);
    }

    /**
     * Generate uniqueness hash for engagement
     *
     * @param array $data Engagement data
     * @return string|null Hash or null
     */
    public static function generate_hash($data) {
        // For podcasts: podcast_id + date + episode_number
        if (!empty($data['podcast_id']) && !empty($data['engagement_date'])) {
            return md5(sprintf('podcast:%d|date:%s|ep:%s',
                $data['podcast_id'],
                $data['engagement_date'],
                $data['episode_number'] ?? ''
            ));
        }

        // For events: event_name + date + location
        if (!empty($data['event_name']) && !empty($data['engagement_date'])) {
            return md5(sprintf('event:%s|date:%s|loc:%s',
                strtolower(trim($data['event_name'])),
                $data['engagement_date'],
                strtolower(trim($data['event_location'] ?? ''))
            ));
        }

        // Fallback: URL-based
        if (!empty($data['url'])) {
            return md5('url:' . strtolower(trim($data['url'])));
        }

        // Last resort: title + date + type
        if (!empty($data['title'])) {
            return md5(sprintf('type:%s|title:%s|date:%s',
                $data['engagement_type'] ?? 'podcast',
                strtolower(trim($data['title'])),
                $data['engagement_date'] ?? ''
            ));
        }

        return null;
    }

    /**
     * Get statistics
     *
     * @return array Statistics
     */
    public static function get_statistics() {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_engagements';

        $stats = [
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table"),
            'verified' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE is_verified = 1"),
            'by_type' => [],
        ];

        // Get counts by type
        $type_counts = $wpdb->get_results("SELECT engagement_type, COUNT(*) as count FROM $table GROUP BY engagement_type");
        foreach ($type_counts as $row) {
            $stats['by_type'][$row->engagement_type] = (int) $row->count;
        }

        return $stats;
    }
}
