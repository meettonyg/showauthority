<?php
/**
 * Podcast Repository
 *
 * Handles all database operations for podcasts.
 *
 * @package PodcastInfluenceTracker
 * @subpackage Podcasts
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Podcast_Repository {

    /**
     * Get podcast by ID
     *
     * @param int $podcast_id Podcast ID
     * @return object|null Podcast object or null
     */
    public static function get($podcast_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcasts';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $podcast_id
        ));
    }

    /**
     * Get podcast by RSS URL
     *
     * @param string $rss_url RSS feed URL
     * @return object|null Podcast object or null
     */
    public static function get_by_rss($rss_url) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcasts';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE rss_feed_url = %s",
            $rss_url
        ));
    }

    /**
     * Get podcast by iTunes ID
     *
     * @param string $itunes_id iTunes/Apple Podcasts ID
     * @return object|null Podcast object or null
     */
    public static function get_by_itunes_id($itunes_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcasts';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE itunes_id = %s",
            $itunes_id
        ));
    }

    /**
     * Get podcast by slug
     *
     * @param string $slug URL slug
     * @return object|null Podcast object or null
     */
    public static function get_by_slug($slug) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcasts';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE slug = %s",
            $slug
        ));
    }

    /**
     * Find podcast by any external identifier
     *
     * Priority: iTunes ID > RSS URL > Podcast Index ID > Slug
     *
     * @param array $identifiers Array of identifiers to check
     * @return object|null Podcast object or null
     */
    public static function find_by_external_id($identifiers) {
        // iTunes ID is most stable (survives feed migrations)
        if (!empty($identifiers['itunes_id'])) {
            $podcast = self::get_by_itunes_id($identifiers['itunes_id']);
            if ($podcast) return $podcast;
        }

        // RSS URL is canonical identifier
        if (!empty($identifiers['rss_feed_url'])) {
            $podcast = self::get_by_rss($identifiers['rss_feed_url']);
            if ($podcast) return $podcast;
        }

        // Podcast Index ID
        if (!empty($identifiers['podcast_index_id'])) {
            global $wpdb;
            $table = $wpdb->prefix . 'pit_podcasts';
            $podcast = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table WHERE podcast_index_id = %d",
                $identifiers['podcast_index_id']
            ));
            if ($podcast) return $podcast;
        }

        // Slug as last resort
        if (!empty($identifiers['slug'])) {
            $podcast = self::get_by_slug($identifiers['slug']);
            if ($podcast) return $podcast;
        }

        return null;
    }

    /**
     * Create a new podcast
     *
     * @param array $data Podcast data
     * @return int|false Podcast ID or false on failure
     */
    public static function create($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcasts';

        // Generate slug if not provided
        if (empty($data['slug']) && !empty($data['title'])) {
            $data['slug'] = self::generate_unique_slug($data['title']);
        }

        $wpdb->insert($table, $data);

        return $wpdb->insert_id ?: false;
    }

    /**
     * Update a podcast
     *
     * @param int $podcast_id Podcast ID
     * @param array $data Data to update
     * @return bool Success
     */
    public static function update($podcast_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcasts';

        unset($data['created_at']); // Never update created_at

        return $wpdb->update($table, $data, ['id' => $podcast_id]) !== false;
    }

    /**
     * Insert or update podcast (upsert)
     *
     * Deduplication strategy:
     * 1. iTunes ID (most stable, survives feed migrations)
     * 2. RSS URL (canonical but can change)
     * 3. Slug (fallback)
     *
     * @param array $data Podcast data
     * @return int Podcast ID
     */
    public static function upsert($data) {
        $existing = self::find_by_external_id($data);

        if ($existing) {
            // Check for feed migration (iTunes ID matches but RSS URL differs)
            if (!empty($data['itunes_id']) && !empty($data['rss_feed_url']) &&
                $existing->rss_feed_url && $data['rss_feed_url'] !== $existing->rss_feed_url) {

                // Log the migration
                $history = $existing->feed_migration_history ? json_decode($existing->feed_migration_history, true) : [];
                if (!is_array($history)) $history = [];

                $history[] = [
                    'old_url' => $existing->rss_feed_url,
                    'new_url' => $data['rss_feed_url'],
                    'migrated_at' => current_time('mysql'),
                ];

                $data['feed_migration_history'] = json_encode($history);
            }

            self::update($existing->id, $data);
            return $existing->id;
        }

        return self::create($data);
    }

    /**
     * Delete a podcast
     *
     * @param int $podcast_id Podcast ID
     * @return bool Success
     */
    public static function delete($podcast_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcasts';

        return $wpdb->delete($table, ['id' => $podcast_id], ['%d']) !== false;
    }

    /**
     * List podcasts with filtering and pagination
     *
     * @param array $args Query arguments
     * @return array Results with podcasts, total, and pages
     */
    public static function list($args = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcasts';
        $social_table = $wpdb->prefix . 'pit_social_links';

        $defaults = [
            'per_page' => 20,
            'page' => 1,
            'search' => '',
            'tracking_status' => '',
            'is_tracked' => null,
            'orderby' => 'created_at',
            'order' => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);

        $where = ['1=1'];
        $prepare_args = [];

        if (!empty($args['search'])) {
            $where[] = '(p.title LIKE %s OR p.author LIKE %s OR p.description LIKE %s)';
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $prepare_args[] = $search_term;
            $prepare_args[] = $search_term;
            $prepare_args[] = $search_term;
        }

        if (!empty($args['tracking_status'])) {
            $where[] = 'p.tracking_status = %s';
            $prepare_args[] = $args['tracking_status'];
        }

        if ($args['is_tracked'] !== null) {
            $where[] = 'p.is_tracked = %d';
            $prepare_args[] = (int) $args['is_tracked'];
        }

        $where_clause = implode(' AND ', $where);

        $offset = ($args['page'] - 1) * $args['per_page'];

        // Sanitize orderby
        $allowed_orderby = ['created_at', 'updated_at', 'title', 'tracking_status'];
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'created_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $sql = "SELECT p.*, COUNT(sl.id) as social_links_count
                FROM $table p
                LEFT JOIN $social_table sl ON p.id = sl.podcast_id
                WHERE $where_clause
                GROUP BY p.id
                ORDER BY p.$orderby $order
                LIMIT %d OFFSET %d";

        $prepare_args[] = $args['per_page'];
        $prepare_args[] = $offset;

        $results = $wpdb->get_results($wpdb->prepare($sql, $prepare_args));

        // Get total count
        $count_sql = "SELECT COUNT(*) FROM $table p WHERE $where_clause";
        if (count($prepare_args) > 2) {
            $count_args = array_slice($prepare_args, 0, -2);
            $total = $wpdb->get_var($wpdb->prepare($count_sql, $count_args));
        } else {
            $total = $wpdb->get_var($count_sql);
        }

        return [
            'podcasts' => $results,
            'total' => (int) $total,
            'pages' => ceil($total / $args['per_page']),
        ];
    }

    /**
     * Get podcasts that need refresh
     *
     * @return array Podcasts needing refresh
     */
    public static function get_needing_refresh() {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcasts';

        return $wpdb->get_results(
            "SELECT * FROM $table
            WHERE is_tracked = 1
            AND tracking_status = 'tracked'
            AND (last_enriched_at IS NULL OR last_enriched_at < DATE_SUB(NOW(), INTERVAL 7 DAY))
            ORDER BY last_enriched_at ASC"
        );
    }

    /**
     * Update tracking status
     *
     * @param int $podcast_id Podcast ID
     * @param string $status New status
     * @return bool Success
     */
    public static function update_tracking_status($podcast_id, $status) {
        $data = ['tracking_status' => $status];

        if ($status === 'tracked') {
            $data['is_tracked'] = 1;
            $data['last_enriched_at'] = current_time('mysql');
        }

        return self::update($podcast_id, $data);
    }

    /**
     * Generate unique slug
     *
     * @param string $title Podcast title
     * @return string Unique slug
     */
    private static function generate_unique_slug($title) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcasts';

        $base_slug = sanitize_title($title);
        $slug = $base_slug;
        $counter = 1;

        while ($wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE slug = %s", $slug))) {
            $slug = $base_slug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Get statistics
     *
     * @return array Statistics
     */
    public static function get_statistics() {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcasts';

        return [
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table"),
            'tracked' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE is_tracked = 1"),
            'pending' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE tracking_status = 'queued'"),
            'failed' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE tracking_status = 'failed'"),
        ];
    }
}
