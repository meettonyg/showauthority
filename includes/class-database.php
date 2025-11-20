<?php
/**
 * Database Schema Management
 *
 * Creates and manages the 5 tables needed for podcast influence tracking:
 * 1. pit_podcasts - Core podcast data
 * 2. pit_social_links - Discovered social media links (Layer 1)
 * 3. pit_metrics - Collected metrics data (Layer 2)
 * 4. pit_jobs - Job queue for async processing
 * 5. pit_cost_log - Cost tracking and analytics
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Database {

    /**
     * Create all database tables
     */
    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Table 1: Podcasts
        $table_podcasts = $wpdb->prefix . 'pit_podcasts';
        $sql_podcasts = "CREATE TABLE $table_podcasts (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            podcast_name varchar(255) NOT NULL,
            rss_feed_url varchar(500) NOT NULL,
            homepage_url varchar(500) DEFAULT NULL,
            description text DEFAULT NULL,
            author varchar(255) DEFAULT NULL,
            email varchar(255) DEFAULT NULL,
            category varchar(100) DEFAULT NULL,
            language varchar(50) DEFAULT NULL,
            artwork_url varchar(500) DEFAULT NULL,
            is_tracked tinyint(1) DEFAULT 0,
            tracking_status enum('not_tracked', 'queued', 'processing', 'tracked', 'failed') DEFAULT 'not_tracked',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY rss_feed_url (rss_feed_url),
            KEY is_tracked (is_tracked),
            KEY tracking_status (tracking_status)
        ) $charset_collate;";

        dbDelta($sql_podcasts);

        // Table 2: Social Links (Layer 1 - Discovery)
        $table_social = $wpdb->prefix . 'pit_social_links';
        $sql_social = "CREATE TABLE $table_social (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            podcast_id bigint(20) UNSIGNED NOT NULL,
            platform enum('twitter', 'instagram', 'facebook', 'youtube', 'linkedin', 'tiktok', 'spotify', 'apple_podcasts') NOT NULL,
            profile_url varchar(500) NOT NULL,
            profile_handle varchar(255) DEFAULT NULL,
            discovery_source enum('rss', 'homepage', 'manual') DEFAULT 'homepage',
            is_verified tinyint(1) DEFAULT 0,
            discovered_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY podcast_id (podcast_id),
            KEY platform (platform),
            UNIQUE KEY podcast_platform (podcast_id, platform)
        ) $charset_collate;";

        dbDelta($sql_social);

        // Table 3: Metrics (Layer 2 - Enrichment)
        $table_metrics = $wpdb->prefix . 'pit_metrics';
        $sql_metrics = "CREATE TABLE $table_metrics (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            podcast_id bigint(20) UNSIGNED NOT NULL,
            platform enum('twitter', 'instagram', 'facebook', 'youtube', 'linkedin', 'tiktok', 'spotify', 'apple_podcasts') NOT NULL,
            followers_count int(11) DEFAULT 0,
            following_count int(11) DEFAULT 0,
            posts_count int(11) DEFAULT 0,
            engagement_rate decimal(5,2) DEFAULT 0.00,
            avg_likes int(11) DEFAULT 0,
            avg_comments int(11) DEFAULT 0,
            avg_shares int(11) DEFAULT 0,
            total_views bigint(20) DEFAULT 0,
            subscriber_count int(11) DEFAULT 0,
            video_count int(11) DEFAULT 0,
            api_response longtext DEFAULT NULL,
            cost_usd decimal(10,4) DEFAULT 0.0000,
            fetch_duration_seconds decimal(8,2) DEFAULT 0.00,
            fetched_at datetime DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY podcast_id (podcast_id),
            KEY platform (platform),
            KEY fetched_at (fetched_at),
            UNIQUE KEY podcast_platform_fetch (podcast_id, platform, fetched_at)
        ) $charset_collate;";

        dbDelta($sql_metrics);

        // Table 4: Job Queue (Layer 2 - Async Processing)
        $table_jobs = $wpdb->prefix . 'pit_jobs';
        $sql_jobs = "CREATE TABLE $table_jobs (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            podcast_id bigint(20) UNSIGNED NOT NULL,
            job_type enum('initial_tracking', 'background_refresh', 'manual_refresh') DEFAULT 'initial_tracking',
            platforms_to_fetch text NOT NULL,
            status enum('queued', 'processing', 'completed', 'failed') DEFAULT 'queued',
            priority tinyint(4) DEFAULT 50,
            attempts tinyint(4) DEFAULT 0,
            max_attempts tinyint(4) DEFAULT 3,
            error_message text DEFAULT NULL,
            progress_percent tinyint(4) DEFAULT 0,
            estimated_cost_usd decimal(10,4) DEFAULT 0.0000,
            actual_cost_usd decimal(10,4) DEFAULT 0.0000,
            started_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY podcast_id (podcast_id),
            KEY status (status),
            KEY job_type (job_type),
            KEY priority (priority),
            KEY created_at (created_at)
        ) $charset_collate;";

        dbDelta($sql_jobs);

        // Table 5: Cost Log (Analytics & Budget Control)
        $table_costs = $wpdb->prefix . 'pit_cost_log';
        $sql_costs = "CREATE TABLE $table_costs (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            podcast_id bigint(20) UNSIGNED DEFAULT NULL,
            job_id bigint(20) UNSIGNED DEFAULT NULL,
            action_type enum('discovery', 'enrichment', 'refresh', 'manual') NOT NULL,
            platform varchar(50) DEFAULT NULL,
            cost_usd decimal(10,4) NOT NULL,
            api_provider enum('youtube', 'apify', 'other') DEFAULT NULL,
            api_calls_made int(11) DEFAULT 1,
            success tinyint(1) DEFAULT 1,
            metadata longtext DEFAULT NULL,
            logged_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY podcast_id (podcast_id),
            KEY job_id (job_id),
            KEY action_type (action_type),
            KEY logged_at (logged_at)
        ) $charset_collate;";

        dbDelta($sql_costs);

        // Save database version
        update_option('pit_db_version', PIT_VERSION);
    }

    /**
     * Get podcast by ID
     */
    public static function get_podcast($podcast_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcasts';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $podcast_id
        ));
    }

    /**
     * Insert or update podcast
     */
    public static function upsert_podcast($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcasts';

        // Check if exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE rss_feed_url = %s",
            $data['rss_feed_url']
        ));

        if ($existing) {
            $wpdb->update(
                $table,
                $data,
                ['id' => $existing],
                null,
                ['%d']
            );
            return $existing;
        } else {
            $wpdb->insert($table, $data);
            return $wpdb->insert_id;
        }
    }

    /**
     * Get social links for a podcast
     */
    public static function get_social_links($podcast_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_social_links';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE podcast_id = %d ORDER BY platform",
            $podcast_id
        ));
    }

    /**
     * Insert social link
     */
    public static function insert_social_link($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_social_links';

        // Use INSERT ... ON DUPLICATE KEY UPDATE
        $wpdb->replace($table, $data);

        return $wpdb->insert_id;
    }

    /**
     * Get latest metrics for a podcast
     */
    public static function get_latest_metrics($podcast_id, $platform = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_metrics';

        if ($platform) {
            return $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table
                WHERE podcast_id = %d AND platform = %s
                ORDER BY fetched_at DESC LIMIT 1",
                $podcast_id,
                $platform
            ));
        } else {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT m1.* FROM $table m1
                INNER JOIN (
                    SELECT platform, MAX(fetched_at) as max_fetched
                    FROM $table
                    WHERE podcast_id = %d
                    GROUP BY platform
                ) m2 ON m1.platform = m2.platform AND m1.fetched_at = m2.max_fetched
                WHERE m1.podcast_id = %d",
                $podcast_id,
                $podcast_id
            ));
        }
    }

    /**
     * Insert metrics
     */
    public static function insert_metrics($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_metrics';

        $wpdb->insert($table, $data);

        return $wpdb->insert_id;
    }

    /**
     * Create job
     */
    public static function create_job($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_jobs';

        $defaults = [
            'status' => 'queued',
            'priority' => 50,
            'attempts' => 0,
            'max_attempts' => 3,
            'progress_percent' => 0,
        ];

        $data = wp_parse_args($data, $defaults);

        $wpdb->insert($table, $data);

        return $wpdb->insert_id;
    }

    /**
     * Update job
     */
    public static function update_job($job_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_jobs';

        return $wpdb->update(
            $table,
            $data,
            ['id' => $job_id],
            null,
            ['%d']
        );
    }

    /**
     * Get next queued job
     */
    public static function get_next_job() {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_jobs';

        return $wpdb->get_row(
            "SELECT * FROM $table
            WHERE status = 'queued'
            ORDER BY priority DESC, created_at ASC
            LIMIT 1"
        );
    }

    /**
     * Log cost
     */
    public static function log_cost($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_cost_log';

        $wpdb->insert($table, $data);

        return $wpdb->insert_id;
    }

    /**
     * Get total costs
     */
    public static function get_total_costs($period = 'month') {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_cost_log';

        $date_condition = '';
        switch ($period) {
            case 'day':
                $date_condition = 'DATE(logged_at) = CURDATE()';
                break;
            case 'week':
                $date_condition = 'YEARWEEK(logged_at) = YEARWEEK(NOW())';
                break;
            case 'month':
                $date_condition = 'YEAR(logged_at) = YEAR(NOW()) AND MONTH(logged_at) = MONTH(NOW())';
                break;
            case 'year':
                $date_condition = 'YEAR(logged_at) = YEAR(NOW())';
                break;
            default:
                $date_condition = '1=1';
        }

        return $wpdb->get_var(
            "SELECT COALESCE(SUM(cost_usd), 0) FROM $table WHERE $date_condition"
        );
    }

    /**
     * Get podcasts list with pagination
     */
    public static function get_podcasts($args = []) {
        global $wpdb;
        $table_podcasts = $wpdb->prefix . 'pit_podcasts';
        $table_social = $wpdb->prefix . 'pit_social_links';

        $defaults = [
            'per_page' => 20,
            'page' => 1,
            'search' => '',
            'tracking_status' => '',
            'orderby' => 'created_at',
            'order' => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);

        $where = ['1=1'];
        $prepare_args = [];

        if (!empty($args['search'])) {
            $where[] = '(podcast_name LIKE %s OR author LIKE %s OR description LIKE %s)';
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $prepare_args[] = $search_term;
            $prepare_args[] = $search_term;
            $prepare_args[] = $search_term;
        }

        if (!empty($args['tracking_status'])) {
            $where[] = 'tracking_status = %s';
            $prepare_args[] = $args['tracking_status'];
        }

        $where_clause = implode(' AND ', $where);

        $offset = ($args['page'] - 1) * $args['per_page'];

        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);
        if (!$orderby) {
            $orderby = 'created_at DESC';
        }

        $sql = "SELECT p.*,
                (SELECT COUNT(*) FROM $table_social WHERE podcast_id = p.id) as social_links_count
                FROM $table_podcasts p
                WHERE $where_clause
                ORDER BY $orderby
                LIMIT %d OFFSET %d";

        $prepare_args[] = $args['per_page'];
        $prepare_args[] = $offset;

        if (count($prepare_args) > 0) {
            $sql = $wpdb->prepare($sql, $prepare_args);
        }

        $results = $wpdb->get_results($sql);

        // Get total count
        $count_sql = "SELECT COUNT(*) FROM $table_podcasts p WHERE $where_clause";
        if (count($prepare_args) > 2) {
            $count_args = array_slice($prepare_args, 0, -2);
            $count_sql = $wpdb->prepare($count_sql, $count_args);
        }
        $total = $wpdb->get_var($count_sql);

        return [
            'podcasts' => $results,
            'total' => (int) $total,
            'pages' => ceil($total / $args['per_page']),
        ];
    }
}
