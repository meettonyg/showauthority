<?php
/**
 * Opportunity Repository
 *
 * Handles all database operations for CRM opportunities.
 * Opportunities are user-owned records that track the CRM pipeline.
 *
 * @package PodcastInfluenceTracker
 * @subpackage Guests
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Opportunity_Repository {

    /**
     * Get opportunity by ID
     *
     * @param int $opportunity_id Opportunity ID
     * @param int|null $user_id User ID for ownership check (null for admin)
     * @return object|null Opportunity object or null
     */
    public static function get($opportunity_id, $user_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_opportunities';

        $query = "SELECT * FROM $table WHERE id = %d";
        $params = [$opportunity_id];

        if ($user_id !== null && !PIT_User_Context::is_admin()) {
            $query .= " AND user_id = %d";
            $params[] = $user_id;
        }

        return $wpdb->get_row($wpdb->prepare($query, ...$params));
    }

    /**
     * Check if user owns an opportunity record
     *
     * @param int $opportunity_id Opportunity ID
     * @param int $user_id User ID
     * @return bool
     */
    public static function user_owns($opportunity_id, $user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_opportunities';

        $owner_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM $table WHERE id = %d",
            $opportunity_id
        ));

        return $owner_id !== null && (int) $owner_id === (int) $user_id;
    }

    /**
     * Get opportunities by guest ID
     *
     * @param int $guest_id Guest ID
     * @param int|null $user_id User ID for scoping
     * @return array Opportunity objects
     */
    public static function get_by_guest($guest_id, $user_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_opportunities';

        $query = "SELECT * FROM $table WHERE guest_id = %d";
        $params = [$guest_id];

        if ($user_id !== null && !PIT_User_Context::is_admin()) {
            $query .= " AND user_id = %d";
            $params[] = $user_id;
        }

        $query .= " ORDER BY created_at DESC";

        return $wpdb->get_results($wpdb->prepare($query, ...$params));
    }

    /**
     * Get opportunities by engagement ID
     *
     * @param int $engagement_id Engagement ID
     * @param int|null $user_id User ID for scoping
     * @return array Opportunity objects
     */
    public static function get_by_engagement($engagement_id, $user_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_opportunities';

        $query = "SELECT * FROM $table WHERE engagement_id = %d";
        $params = [$engagement_id];

        if ($user_id !== null && !PIT_User_Context::is_admin()) {
            $query .= " AND user_id = %d";
            $params[] = $user_id;
        }

        return $wpdb->get_results($wpdb->prepare($query, ...$params));
    }

    /**
     * Create a new opportunity
     *
     * @param array $data Opportunity data
     * @return int|false Opportunity ID or false on failure
     */
    public static function create($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_opportunities';

        // Set user_id if not provided
        if (!isset($data['user_id'])) {
            $data['user_id'] = PIT_User_Context::get_user_id();
        }

        // Set defaults
        if (!isset($data['status'])) {
            $data['status'] = 'lead';
        }
        if (!isset($data['priority'])) {
            $data['priority'] = 'medium';
        }
        if (!isset($data['created_at'])) {
            $data['created_at'] = current_time('mysql');
        }
        if (!isset($data['updated_at'])) {
            $data['updated_at'] = current_time('mysql');
        }

        $wpdb->insert($table, $data);

        return $wpdb->insert_id ?: false;
    }

    /**
     * Update an opportunity
     *
     * @param int $opportunity_id Opportunity ID
     * @param array $data Data to update
     * @return bool Success
     */
    public static function update($opportunity_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_opportunities';

        unset($data['created_at']);
        $data['updated_at'] = current_time('mysql');

        return $wpdb->update($table, $data, ['id' => $opportunity_id]) !== false;
    }

    /**
     * Delete an opportunity
     *
     * @param int $opportunity_id Opportunity ID
     * @return bool Success
     */
    public static function delete($opportunity_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_opportunities';

        return $wpdb->delete($table, ['id' => $opportunity_id], ['%d']) !== false;
    }

    /**
     * List opportunities with filtering and pagination
     *
     * @param array $args Query arguments
     * @return array Results with opportunities, total, and pages
     */
    public static function list($args = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_opportunities';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';
        $guests_table = $wpdb->prefix . 'pit_guests';

        $defaults = [
            'page' => 1,
            'per_page' => 20,
            'orderby' => 'updated_at',
            'order' => 'DESC',
            'status' => '',
            'priority' => '',
            'source' => '',
            'search' => '',
            'show_archived' => false,
            'user_id' => null,
            'guest_id' => null,
            'podcast_id' => null,
        ];

        $args = wp_parse_args($args, $defaults);

        $where = [];
        $prepare_args = [];

        // User scoping
        if (!PIT_User_Context::is_admin()) {
            $user_id = $args['user_id'] ?? PIT_User_Context::get_user_id();
            $where[] = 'o.user_id = %d';
            $prepare_args[] = $user_id;
        } elseif (!empty($args['user_id'])) {
            $where[] = 'o.user_id = %d';
            $prepare_args[] = $args['user_id'];
        }

        // Status filter
        if (!empty($args['status'])) {
            $where[] = 'o.status = %s';
            $prepare_args[] = $args['status'];
        }

        // Priority filter
        if (!empty($args['priority'])) {
            $where[] = 'o.priority = %s';
            $prepare_args[] = $args['priority'];
        }

        // Source filter
        if (!empty($args['source'])) {
            $where[] = 'o.source = %s';
            $prepare_args[] = $args['source'];
        }

        // Guest filter
        if (!empty($args['guest_id'])) {
            $where[] = 'o.guest_id = %d';
            $prepare_args[] = $args['guest_id'];
        }

        // Podcast filter
        if (!empty($args['podcast_id'])) {
            $where[] = 'o.podcast_id = %d';
            $prepare_args[] = $args['podcast_id'];
        }

        // Archived filter
        if (!$args['show_archived']) {
            $where[] = '(o.is_archived = 0 OR o.is_archived IS NULL)';
        }

        // Search
        if (!empty($args['search'])) {
            $where[] = '(p.title LIKE %s OR g.full_name LIKE %s)';
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $prepare_args[] = $search;
            $prepare_args[] = $search;
        }

        $where_clause = !empty($where) ? implode(' AND ', $where) : '1=1';

        $offset = ($args['page'] - 1) * $args['per_page'];

        $allowed_orderby = ['created_at', 'updated_at', 'status', 'priority', 'air_date'];
        $orderby = in_array($args['orderby'], $allowed_orderby) ? 'o.' . $args['orderby'] : 'o.updated_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $sql = "SELECT o.*, p.title as podcast_name, p.artwork_url as podcast_image, g.full_name as guest_name
                FROM $table o
                LEFT JOIN $podcasts_table p ON o.podcast_id = p.id
                LEFT JOIN $guests_table g ON o.guest_id = g.id
                WHERE $where_clause
                ORDER BY $orderby $order
                LIMIT %d OFFSET %d";
        $prepare_args[] = $args['per_page'];
        $prepare_args[] = $offset;

        $results = $wpdb->get_results($wpdb->prepare($sql, $prepare_args));

        // Count query
        $count_sql = "SELECT COUNT(*) FROM $table o
                      LEFT JOIN $podcasts_table p ON o.podcast_id = p.id
                      LEFT JOIN $guests_table g ON o.guest_id = g.id
                      WHERE $where_clause";
        if (count($prepare_args) > 2) {
            $count_args = array_slice($prepare_args, 0, -2);
            $total = $wpdb->get_var($wpdb->prepare($count_sql, $count_args));
        } else {
            $total = $wpdb->get_var($count_sql);
        }

        return [
            'opportunities' => $results,
            'total' => (int) $total,
            'pages' => ceil($total / $args['per_page']),
        ];
    }

    /**
     * Update status
     *
     * @param int $opportunity_id Opportunity ID
     * @param string $status New status
     * @return bool Success
     */
    public static function update_status($opportunity_id, $status) {
        return self::update($opportunity_id, ['status' => $status]);
    }

    /**
     * Archive opportunity
     *
     * @param int $opportunity_id Opportunity ID
     * @param bool $archived Archive status
     * @return bool Success
     */
    public static function archive($opportunity_id, $archived = true) {
        return self::update($opportunity_id, ['is_archived' => $archived ? 1 : 0]);
    }

    /**
     * Get statistics
     *
     * @param int|null $user_id User ID (null for admin to see all)
     * @return array Statistics
     */
    public static function get_statistics($user_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_opportunities';

        // Build user clause
        $user_clause = '';
        if (!PIT_User_Context::is_admin()) {
            $user_id = $user_id ?? PIT_User_Context::get_user_id();
            $user_clause = $wpdb->prepare(" AND user_id = %d", $user_id);
        } elseif ($user_id !== null) {
            $user_clause = $wpdb->prepare(" AND user_id = %d", $user_id);
        }

        $stats = [
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE 1=1 {$user_clause}"),
            'active' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE (is_archived = 0 OR is_archived IS NULL) {$user_clause}"),
            'by_status' => [],
            'by_priority' => [],
        ];

        // Get counts by status
        $status_counts = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM $table WHERE (is_archived = 0 OR is_archived IS NULL) {$user_clause} GROUP BY status"
        );
        foreach ($status_counts as $row) {
            $stats['by_status'][$row->status] = (int) $row->count;
        }

        // Get counts by priority
        $priority_counts = $wpdb->get_results(
            "SELECT priority, COUNT(*) as count FROM $table WHERE (is_archived = 0 OR is_archived IS NULL) {$user_clause} GROUP BY priority"
        );
        foreach ($priority_counts as $row) {
            $stats['by_priority'][$row->priority] = (int) $row->count;
        }

        return $stats;
    }

    /**
     * Link opportunity to engagement
     *
     * @param int $opportunity_id Opportunity ID
     * @param int $engagement_id Engagement ID
     * @return bool Success
     */
    public static function link_engagement($opportunity_id, $engagement_id) {
        return self::update($opportunity_id, ['engagement_id' => $engagement_id]);
    }
}
