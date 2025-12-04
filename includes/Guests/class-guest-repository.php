<?php
/**
 * Guest Repository
 *
 * Handles all database operations for guests.
 *
 * In v4.0, guests are GLOBAL records with:
 * - created_by_user_id: Who created this record (provenance)
 * - claimed_by_user_id: Who this person IS (identity claiming)
 *
 * @package PodcastInfluenceTracker
 * @subpackage Guests
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Guest_Repository {

    /**
     * Get guest by ID (with optional user scoping)
     *
     * @param int $guest_id Guest ID
     * @param int|null $user_id User ID for creator check (null for admin)
     * @return object|null Guest object or null
     */
    public static function get($guest_id, $user_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guests';

        $guest = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND is_merged = 0",
            $guest_id
        ));

        // Check creator ownership if user_id provided
        if ($guest && $user_id !== null && !PIT_User_Context::is_admin()) {
            if ((int) $guest->created_by_user_id !== (int) $user_id) {
                return null;
            }
        }

        return $guest;
    }

    /**
     * Check if user created a guest record
     *
     * @param int $guest_id Guest ID
     * @param int $user_id User ID
     * @return bool
     */
    public static function user_owns($guest_id, $user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guests';

        $creator_id = $wpdb->get_var($wpdb->prepare(
            "SELECT created_by_user_id FROM $table WHERE id = %d",
            $guest_id
        ));

        return $creator_id !== null && (int) $creator_id === (int) $user_id;
    }

    /**
     * Get guest by LinkedIn URL (scoped to creator)
     *
     * @param string $linkedin_url LinkedIn URL
     * @param int|null $user_id User ID for scoping
     * @return object|null Guest object or null
     */
    public static function get_by_linkedin($linkedin_url, $user_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guests';
        $hash = md5($linkedin_url);

        $query = "SELECT * FROM $table WHERE linkedin_url_hash = %s AND is_merged = 0";
        $params = [$hash];

        if ($user_id !== null && !PIT_User_Context::is_admin()) {
            $query .= " AND created_by_user_id = %d";
            $params[] = $user_id;
        }

        return $wpdb->get_row($wpdb->prepare($query, ...$params));
    }

    /**
     * Get guest by email (scoped to creator)
     *
     * @param string $email Email address
     * @param int|null $user_id User ID for scoping
     * @return object|null Guest object or null
     */
    public static function get_by_email($email, $user_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guests';
        $hash = md5(strtolower(trim($email)));

        $query = "SELECT * FROM $table WHERE email_hash = %s AND is_merged = 0";
        $params = [$hash];

        if ($user_id !== null && !PIT_User_Context::is_admin()) {
            $query .= " AND created_by_user_id = %d";
            $params[] = $user_id;
        }

        return $wpdb->get_row($wpdb->prepare($query, ...$params));
    }

    /**
     * Create a new guest
     *
     * @param array $data Guest data
     * @return int|false Guest ID or false on failure
     */
    public static function create($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guests';

        // Set created_by_user_id if not provided
        if (!isset($data['created_by_user_id'])) {
            $data['created_by_user_id'] = PIT_User_Context::get_user_id();
        }

        // Generate hashes for deduplication
        $data = self::add_hashes($data);

        $wpdb->insert($table, $data);

        $guest_id = $wpdb->insert_id ?: false;

        // Increment user's guest count
        if ($guest_id && isset($data['created_by_user_id']) && $data['created_by_user_id'] > 0) {
            PIT_User_Limits_Repository::increment_guests($data['created_by_user_id']);
        }

        return $guest_id;
    }

    /**
     * Update a guest
     *
     * @param int $guest_id Guest ID
     * @param array $data Data to update
     * @return bool Success
     */
    public static function update($guest_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guests';

        // Regenerate hashes if relevant fields changed
        $data = self::add_hashes($data);

        unset($data['created_at']);

        return $wpdb->update($table, $data, ['id' => $guest_id]) !== false;
    }

    /**
     * Insert or update guest (upsert with deduplication)
     * Scoped to user - each user has their own guest records
     *
     * Deduplication priority:
     * 1. LinkedIn URL (highest confidence)
     * 2. Email address (high confidence)
     * 3. Create new (never match by name alone)
     *
     * @param array $data Guest data
     * @param int|null $user_id User ID for scoping (defaults to current user)
     * @return int Guest ID
     */
    public static function upsert($data, $user_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guests';

        // Set created_by_user_id
        if ($user_id === null) {
            $user_id = PIT_User_Context::get_user_id();
        }
        $data['created_by_user_id'] = $user_id;
        $data = self::add_hashes($data);

        $existing_id = null;

        // Priority 1: LinkedIn URL (scoped to creator)
        if (!empty($data['linkedin_url_hash'])) {
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE linkedin_url_hash = %s AND created_by_user_id = %d AND is_merged = 0",
                $data['linkedin_url_hash'],
                $user_id
            ));
        }

        // Priority 2: Email (scoped to creator)
        if (!$existing_id && !empty($data['email_hash'])) {
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE email_hash = %s AND created_by_user_id = %d AND is_merged = 0",
                $data['email_hash'],
                $user_id
            ));
        }

        if ($existing_id) {
            self::update($existing_id, $data);
            return $existing_id;
        }

        return self::create($data);
    }

    /**
     * Delete a guest (hard delete)
     *
     * @param int $guest_id Guest ID
     * @return bool Success
     */
    public static function delete($guest_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guests';

        return $wpdb->delete($table, ['id' => $guest_id], ['%d']) !== false;
    }

    /**
     * List guests with filtering and pagination (scoped to user)
     *
     * @param array $args Query arguments
     * @return array Results with guests, total, and pages
     */
    public static function list($args = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guests';

        $defaults = [
            'page' => 1,
            'per_page' => 20,
            'orderby' => 'created_at',
            'order' => 'DESC',
            'search' => '',
            'company' => '',
            'industry' => '',
            'verified_only' => false,
            'enriched_only' => false,
            'exclude_merged' => true,
            'user_id' => null, // User scoping
        ];

        $args = wp_parse_args($args, $defaults);

        $where = [];
        $prepare_args = [];

        // User scoping - non-admins can only see guests they created
        if (!PIT_User_Context::is_admin()) {
            $user_id = $args['user_id'] ?? PIT_User_Context::get_user_id();
            $where[] = 'created_by_user_id = %d';
            $prepare_args[] = $user_id;
        } elseif (!empty($args['user_id'])) {
            // Admin filtering by specific user
            $where[] = 'created_by_user_id = %d';
            $prepare_args[] = $args['user_id'];
        }

        if ($args['exclude_merged']) {
            $where[] = 'is_merged = 0';
        }

        if (!empty($args['search'])) {
            $where[] = '(full_name LIKE %s OR current_company LIKE %s OR email LIKE %s)';
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $prepare_args[] = $search;
            $prepare_args[] = $search;
            $prepare_args[] = $search;
        }

        if (!empty($args['company'])) {
            $where[] = 'current_company LIKE %s';
            $prepare_args[] = '%' . $wpdb->esc_like($args['company']) . '%';
        }

        if (!empty($args['industry'])) {
            $where[] = 'industry = %s';
            $prepare_args[] = $args['industry'];
        }

        if ($args['verified_only']) {
            $where[] = 'manually_verified = 1';
        }

        if ($args['enriched_only']) {
            $where[] = 'enrichment_provider IS NOT NULL';
        }

        $where_clause = !empty($where) ? implode(' AND ', $where) : '1=1';

        $offset = ($args['page'] - 1) * $args['per_page'];

        $allowed_orderby = ['created_at', 'updated_at', 'full_name', 'current_company'];
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'created_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $sql = "SELECT * FROM $table WHERE $where_clause ORDER BY $orderby $order LIMIT %d OFFSET %d";
        $prepare_args[] = $args['per_page'];
        $prepare_args[] = $offset;

        $results = $wpdb->get_results($wpdb->prepare($sql, $prepare_args));

        // Count query
        $count_sql = "SELECT COUNT(*) FROM $table WHERE $where_clause";
        if (count($prepare_args) > 2) {
            $count_args = array_slice($prepare_args, 0, -2);
            $total = $wpdb->get_var($wpdb->prepare($count_sql, $count_args));
        } else {
            $total = $wpdb->get_var($count_sql);
        }

        return [
            'guests' => $results,
            'total' => (int) $total,
            'pages' => ceil($total / $args['per_page']),
        ];
    }

    /**
     * Find potential duplicates (scoped to user)
     *
     * @param int|null $user_id User ID (null for admin to see all)
     * @return array Duplicate pairs
     */
    public static function find_duplicates($user_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guests';

        $duplicates = [];

        // Build user clause (scoped to creator)
        $user_clause = '';
        if (!PIT_User_Context::is_admin()) {
            $user_id = $user_id ?? PIT_User_Context::get_user_id();
            $user_clause = $wpdb->prepare(" AND g1.created_by_user_id = %d AND g2.created_by_user_id = %d", $user_id, $user_id);
        } elseif ($user_id !== null) {
            $user_clause = $wpdb->prepare(" AND g1.created_by_user_id = %d AND g2.created_by_user_id = %d", $user_id, $user_id);
        }

        // LinkedIn URL duplicates
        $linkedin_dups = $wpdb->get_results("
            SELECT g1.id as id1, g2.id as id2,
                   g1.full_name as name1, g1.current_company as company1,
                   g2.full_name as name2, g2.current_company as company2
            FROM {$table} g1
            INNER JOIN {$table} g2 ON g1.linkedin_url_hash = g2.linkedin_url_hash
            WHERE g1.id < g2.id
              AND g1.linkedin_url_hash IS NOT NULL
              AND g1.linkedin_url_hash != ''
              AND g1.is_merged = 0
              AND g2.is_merged = 0
              {$user_clause}
        ");

        foreach ($linkedin_dups as $dup) {
            $duplicates[] = [
                'match_type' => 'linkedin',
                'guest1_id' => (int) $dup->id1,
                'guest1_name' => $dup->name1,
                'guest1_company' => $dup->company1,
                'guest2_id' => (int) $dup->id2,
                'guest2_name' => $dup->name2,
                'guest2_company' => $dup->company2,
            ];
        }

        // Email duplicates (excluding already found by LinkedIn)
        $email_dups = $wpdb->get_results("
            SELECT g1.id as id1, g2.id as id2,
                   g1.full_name as name1, g1.current_company as company1,
                   g2.full_name as name2, g2.current_company as company2
            FROM {$table} g1
            INNER JOIN {$table} g2 ON g1.email_hash = g2.email_hash
            WHERE g1.id < g2.id
              AND g1.email_hash IS NOT NULL
              AND g1.email_hash != ''
              AND g1.is_merged = 0
              AND g2.is_merged = 0
              AND (g1.linkedin_url_hash IS NULL OR g1.linkedin_url_hash != g2.linkedin_url_hash)
              {$user_clause}
        ");

        foreach ($email_dups as $dup) {
            $duplicates[] = [
                'match_type' => 'email',
                'guest1_id' => (int) $dup->id1,
                'guest1_name' => $dup->name1,
                'guest1_company' => $dup->company1,
                'guest2_id' => (int) $dup->id2,
                'guest2_name' => $dup->name2,
                'guest2_company' => $dup->company2,
            ];
        }

        return $duplicates;
    }

    /**
     * Merge two guests
     *
     * @param int $source_id Guest to merge from (will be deleted)
     * @param int $target_id Guest to merge into (will be kept)
     * @return bool Success
     */
    public static function merge($source_id, $target_id) {
        global $wpdb;

        if ($source_id === $target_id) {
            return false;
        }

        $guests_table = $wpdb->prefix . 'pit_guests';
        $appearances_table = $wpdb->prefix . 'pit_guest_appearances';
        $topics_table = $wpdb->prefix . 'pit_guest_topics';
        $network_table = $wpdb->prefix . 'pit_guest_network';

        // Move appearances
        $wpdb->update($appearances_table, ['guest_id' => $target_id], ['guest_id' => $source_id]);

        // Move topics (ignore duplicates)
        $wpdb->query($wpdb->prepare(
            "UPDATE IGNORE $topics_table SET guest_id = %d WHERE guest_id = %d",
            $target_id, $source_id
        ));
        $wpdb->delete($topics_table, ['guest_id' => $source_id]);

        // Update network connections
        $wpdb->update($network_table, ['guest_id' => $target_id], ['guest_id' => $source_id]);
        $wpdb->update($network_table, ['connected_guest_id' => $target_id], ['connected_guest_id' => $source_id]);

        // Delete source guest
        $wpdb->delete($guests_table, ['id' => $source_id]);

        return true;
    }

    /**
     * Verify a guest
     *
     * @param int $guest_id Guest ID
     * @param bool $verified Verification status
     * @param string $notes Optional notes
     * @return bool Success
     */
    public static function verify($guest_id, $verified = true, $notes = '') {
        return self::update($guest_id, [
            'manually_verified' => $verified ? 1 : 0,
            'verified_by_user_id' => get_current_user_id(),
            'verified_at' => current_time('mysql'),
            'verification_notes' => $notes,
        ]);
    }

    /**
     * Add hash fields to data array
     *
     * @param array $data Guest data
     * @return array Data with hashes
     */
    private static function add_hashes($data) {
        if (isset($data['linkedin_url'])) {
            $data['linkedin_url_hash'] = !empty($data['linkedin_url'])
                ? md5($data['linkedin_url'])
                : null;
        }

        if (isset($data['email'])) {
            $data['email_hash'] = !empty($data['email'])
                ? md5(strtolower(trim($data['email'])))
                : null;
        }

        return $data;
    }

    /**
     * Get statistics (scoped to user)
     *
     * @param int|null $user_id User ID (null for admin to see all)
     * @return array Statistics
     */
    public static function get_statistics($user_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guests';

        // Build user clause (scoped to creator)
        $user_clause = '';
        if (!PIT_User_Context::is_admin()) {
            $user_id = $user_id ?? PIT_User_Context::get_user_id();
            $user_clause = $wpdb->prepare(" AND created_by_user_id = %d", $user_id);
        } elseif ($user_id !== null) {
            $user_clause = $wpdb->prepare(" AND created_by_user_id = %d", $user_id);
        }

        return [
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE is_merged = 0 {$user_clause}"),
            'verified' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE manually_verified = 1 AND is_merged = 0 {$user_clause}"),
            'enriched' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE enrichment_provider IS NOT NULL AND is_merged = 0 {$user_clause}"),
        ];
    }
}
