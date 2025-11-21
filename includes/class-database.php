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

        // Create Podcast Intelligence Database tables first
        self::create_podcast_intelligence_tables();

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

    /**
     * Create Podcast Intelligence Database tables
     *
     * This creates the 5 foundational tables for the Podcast Intelligence system:
     * 1. guestify_podcasts - Core show information
     * 2. guestify_podcast_social_accounts - Social media accounts
     * 3. guestify_podcast_contacts - Contact database
     * 4. guestify_podcast_contact_relationships - Bridge between podcasts and contacts
     * 5. guestify_interview_tracker_podcasts - Bridge to Formidable entries
     */
    public static function create_podcast_intelligence_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Table 1: PODCASTS (Core show information)
        $table_podcasts = $wpdb->prefix . 'guestify_podcasts';
        $sql_podcasts = "CREATE TABLE $table_podcasts (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,

            title varchar(500) NOT NULL,
            slug varchar(200) DEFAULT NULL,
            description text DEFAULT NULL,

            rss_feed_url text DEFAULT NULL,
            website_url text DEFAULT NULL,
            homepage_scraped tinyint(1) DEFAULT 0,
            last_rss_check datetime DEFAULT NULL,

            category varchar(100) DEFAULT NULL,
            language varchar(10) DEFAULT 'en',
            episode_count int(11) DEFAULT 0,
            frequency varchar(50) DEFAULT NULL,
            average_duration int(11) DEFAULT NULL,

            is_tracked tinyint(1) DEFAULT 0,
            tracked_at datetime DEFAULT NULL,

            social_links_discovered tinyint(1) DEFAULT 0,
            metrics_enriched tinyint(1) DEFAULT 0,
            last_enriched_at datetime DEFAULT NULL,

            data_quality_score int(11) DEFAULT 0,
            relevance_score int(11) DEFAULT 0,

            podcast_index_id bigint(20) DEFAULT NULL,
            podcast_index_guid varchar(255) DEFAULT NULL,
            taddy_podcast_uuid varchar(255) DEFAULT NULL,
            itunes_id varchar(50) DEFAULT NULL,
            source varchar(50) DEFAULT NULL,

            -- Location fields
            city varchar(100) DEFAULT NULL,
            state_region varchar(100) DEFAULT NULL,
            country varchar(100) DEFAULT NULL,
            country_code varchar(10) DEFAULT NULL,
            timezone varchar(50) DEFAULT NULL,
            location_display varchar(255) DEFAULT NULL,

            -- Artwork
            artwork_url text DEFAULT NULL,
            is_active tinyint(1) DEFAULT 1,

            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            UNIQUE KEY rss_feed_url (rss_feed_url(500)),
            KEY title (title(191)),
            KEY slug (slug),
            KEY itunes_id (itunes_id),
            KEY is_tracked (is_tracked),
            KEY rss_feed_url (rss_feed_url(191)),
            KEY podcast_index_id (podcast_index_id),
            KEY podcast_index_guid (podcast_index_guid),
            KEY taddy_podcast_uuid (taddy_podcast_uuid),
            KEY source (source),
            UNIQUE KEY slug_unique (slug),
            UNIQUE KEY podcast_index_id_unique (podcast_index_id),
            UNIQUE KEY podcast_index_guid_unique (podcast_index_guid),
            UNIQUE KEY taddy_uuid_unique (taddy_podcast_uuid)
        ) $charset_collate;";

        dbDelta($sql_podcasts);

        // Table 2: PODCAST_SOCIAL_ACCOUNTS (Layer 1 - Free discovery)
        $table_social = $wpdb->prefix . 'guestify_podcast_social_accounts';
        $sql_social = "CREATE TABLE $table_social (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,

            podcast_id bigint(20) UNSIGNED NOT NULL,

            platform varchar(50) NOT NULL,

            profile_url text NOT NULL,
            username varchar(255) DEFAULT NULL,
            display_name varchar(255) DEFAULT NULL,

            discovery_method varchar(50) DEFAULT NULL,
            discovered_at datetime DEFAULT CURRENT_TIMESTAMP,

            followers_count int(11) DEFAULT NULL,
            engagement_rate decimal(5,2) DEFAULT NULL,
            post_frequency varchar(50) DEFAULT NULL,
            last_post_date date DEFAULT NULL,

            metrics_enriched tinyint(1) DEFAULT 0,
            enriched_at datetime DEFAULT NULL,
            enrichment_cost_cents int(11) DEFAULT NULL,

            verified tinyint(1) DEFAULT 0,
            active tinyint(1) DEFAULT 1,

            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            KEY podcast_id (podcast_id),
            KEY platform (platform),
            KEY metrics_enriched (metrics_enriched),
            UNIQUE KEY unique_podcast_platform (podcast_id, platform)
        ) $charset_collate;";

        dbDelta($sql_social);

        // Table 3: PODCAST_CONTACTS (Hosts, producers, guests)
        $table_contacts = $wpdb->prefix . 'guestify_podcast_contacts';
        $sql_contacts = "CREATE TABLE $table_contacts (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,

            full_name varchar(255) NOT NULL,
            first_name varchar(100) DEFAULT NULL,
            last_name varchar(100) DEFAULT NULL,

            email varchar(255) DEFAULT NULL,
            personal_email varchar(255) DEFAULT NULL,
            phone varchar(50) DEFAULT NULL,

            role varchar(100) DEFAULT NULL,
            company varchar(255) DEFAULT NULL,
            title varchar(255) DEFAULT NULL,

            linkedin_url text DEFAULT NULL,
            twitter_url text DEFAULT NULL,
            website_url text DEFAULT NULL,

            -- Location fields
            city varchar(100) DEFAULT NULL,
            state_region varchar(100) DEFAULT NULL,
            country varchar(100) DEFAULT NULL,
            country_code varchar(10) DEFAULT NULL,
            timezone varchar(50) DEFAULT NULL,
            location_display varchar(255) DEFAULT NULL,

            clay_enriched tinyint(1) DEFAULT 0,
            clay_enriched_at datetime DEFAULT NULL,
            enrichment_source varchar(50) DEFAULT NULL,
            data_quality_score int(11) DEFAULT 0,
            source varchar(50) DEFAULT NULL,

            preferred_contact_method varchar(50) DEFAULT NULL,
            best_contact_time varchar(100) DEFAULT NULL,
            response_rate_percentage int(11) DEFAULT NULL,
            notes text DEFAULT NULL,

            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            KEY full_name (full_name),
            KEY email (email),
            KEY role (role),
            KEY city (city),
            KEY country (country),
            KEY clay_enriched (clay_enriched)
        ) $charset_collate;";

        dbDelta($sql_contacts);

        // Table 4: PODCAST_CONTACT_RELATIONSHIPS (Bridge table)
        $table_relationships = $wpdb->prefix . 'guestify_podcast_contact_relationships';
        $sql_relationships = "CREATE TABLE $table_relationships (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,

            podcast_id bigint(20) UNSIGNED NOT NULL,
            contact_id bigint(20) UNSIGNED NOT NULL,

            role varchar(100) NOT NULL,
            is_primary tinyint(1) DEFAULT 0,

            active tinyint(1) DEFAULT 1,
            start_date date DEFAULT NULL,
            end_date date DEFAULT NULL,

            notes text DEFAULT NULL,

            created_at datetime DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            KEY podcast_id (podcast_id),
            KEY contact_id (contact_id),
            KEY role (role),
            KEY is_primary (is_primary),
            UNIQUE KEY unique_podcast_contact_role (podcast_id, contact_id, role)
        ) $charset_collate;";

        dbDelta($sql_relationships);

        // Table 5: INTERVIEW_TRACKER_PODCASTS (Bridge to Formidable)
        $table_tracker = $wpdb->prefix . 'guestify_interview_tracker_podcasts';
        $sql_tracker = "CREATE TABLE $table_tracker (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,

            formidable_entry_id bigint(20) UNSIGNED NOT NULL,
            podcast_id bigint(20) UNSIGNED NOT NULL,

            outreach_status varchar(50) DEFAULT NULL,
            primary_contact_id bigint(20) UNSIGNED DEFAULT NULL,

            first_contact_date datetime DEFAULT NULL,
            last_contact_date datetime DEFAULT NULL,

            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            KEY formidable_entry_id (formidable_entry_id),
            KEY podcast_id (podcast_id),
            KEY primary_contact_id (primary_contact_id),
            UNIQUE KEY unique_entry_podcast (formidable_entry_id, podcast_id)
        ) $charset_collate;";

        dbDelta($sql_tracker);
    }

    /**
     * CRUD Operations for Podcast Intelligence Tables
     */

    // ==================== PODCASTS ====================

    /**
     * Get podcast by ID from intelligence database
     */
    public static function get_guestify_podcast($podcast_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_podcasts';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $podcast_id
        ));
    }

    /**
     * Get podcast by RSS feed URL
     */
    public static function get_podcast_by_rss($rss_url) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_podcasts';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE rss_feed_url = %s",
            $rss_url
        ));
    }

    /**
     * Get podcast by Podcast Index ID
     */
    public static function get_podcast_by_podcast_index_id($podcast_index_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_podcasts';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE podcast_index_id = %d",
            $podcast_index_id
        ));
    }

    /**
     * Get podcast by Podcast Index GUID
     */
    public static function get_podcast_by_podcast_index_guid($guid) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_podcasts';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE podcast_index_guid = %s",
            $guid
        ));
    }

    /**
     * Get podcast by Taddy UUID
     */
    public static function get_podcast_by_taddy_uuid($uuid) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_podcasts';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE taddy_podcast_uuid = %s",
            $uuid
        ));
    }

    /**
     * Get podcast by any external ID
     *
     * IMPORTANT: RSS Feed URL is the PRIMARY unique identifier because it's
     * the same across all podcast directories (Podcast Index, Taddy, Apple, etc.)
     *
     * Podcast Index ID and Taddy UUID are DIFFERENT identifiers for the same podcast,
     * so they cannot be used to match podcasts across directories.
     *
     * @param array $identifiers Array of identifiers to check
     * @return object|null
     */
    public static function get_podcast_by_external_id($identifiers) {
        // RSS URL is the PRIMARY unique identifier - check FIRST
        // This is the canonical ID that's the same across ALL directories
        if (!empty($identifiers['rss_feed_url'])) {
            $podcast = self::get_podcast_by_rss($identifiers['rss_feed_url']);
            if ($podcast) return $podcast;
        }

        // iTunes ID is also universal (Apple Podcasts ID used by many directories)
        if (!empty($identifiers['itunes_id'])) {
            $podcast = self::get_podcast_by_itunes_id($identifiers['itunes_id']);
            if ($podcast) return $podcast;
        }

        // Directory-specific IDs as secondary lookups
        // (useful if RSS URL changed but we have the directory ID)
        if (!empty($identifiers['podcast_index_id'])) {
            $podcast = self::get_podcast_by_podcast_index_id($identifiers['podcast_index_id']);
            if ($podcast) return $podcast;
        }

        if (!empty($identifiers['podcast_index_guid'])) {
            $podcast = self::get_podcast_by_podcast_index_guid($identifiers['podcast_index_guid']);
            if ($podcast) return $podcast;
        }

        if (!empty($identifiers['taddy_podcast_uuid'])) {
            $podcast = self::get_podcast_by_taddy_uuid($identifiers['taddy_podcast_uuid']);
            if ($podcast) return $podcast;
        }

        return null;
    }

    /**
     * Get podcast by iTunes ID
     */
    public static function get_podcast_by_itunes_id($itunes_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_podcasts';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE itunes_id = %s",
            $itunes_id
        ));
    }

    /**
     * Insert or update podcast in intelligence database
     *
     * DEDUPLICATION STRATEGY:
     * RSS Feed URL is the PRIMARY unique identifier because it's the same
     * across ALL podcast directories (Podcast Index, Taddy, Apple, etc.)
     *
     * Podcast Index ID and Taddy UUID are DIFFERENT systems with DIFFERENT IDs
     * for the same podcast, so we check RSS URL FIRST.
     */
    public static function upsert_guestify_podcast($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_podcasts';

        // Generate slug if not provided
        if (empty($data['slug']) && !empty($data['title'])) {
            $data['slug'] = sanitize_title($data['title']);
        }

        $existing_id = null;

        // PRIORITY 1: RSS URL - PRIMARY unique identifier
        // Same across ALL directories (Podcast Index, Taddy, Apple, Spotify, etc.)
        if (!$existing_id && !empty($data['rss_feed_url'])) {
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE rss_feed_url = %s",
                $data['rss_feed_url']
            ));
        }

        // PRIORITY 2: iTunes ID - Also universal (Apple Podcasts ID)
        if (!$existing_id && !empty($data['itunes_id'])) {
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE itunes_id = %s",
                $data['itunes_id']
            ));
        }

        // PRIORITY 3+: Directory-specific IDs (secondary lookups)
        // Useful if RSS URL changed but we have a directory ID
        if (!$existing_id && !empty($data['podcast_index_id'])) {
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE podcast_index_id = %d",
                $data['podcast_index_id']
            ));
        }

        if (!$existing_id && !empty($data['podcast_index_guid'])) {
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE podcast_index_guid = %s",
                $data['podcast_index_guid']
            ));
        }

        if (!$existing_id && !empty($data['taddy_podcast_uuid'])) {
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE taddy_podcast_uuid = %s",
                $data['taddy_podcast_uuid']
            ));
        }

        // Last resort: check slug (least reliable)
        if (!$existing_id && !empty($data['slug'])) {
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE slug = %s",
                $data['slug']
            ));
        }

        if ($existing_id) {
            // Update existing podcast
            unset($data['created_at']); // Don't update created_at
            $wpdb->update($table, $data, ['id' => $existing_id]);
            return $existing_id;
        } else {
            // Insert new podcast
            $wpdb->insert($table, $data);
            return $wpdb->insert_id;
        }
    }

    // ==================== SOCIAL ACCOUNTS ====================

    /**
     * Get social accounts for a podcast
     */
    public static function get_podcast_social_accounts($podcast_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_podcast_social_accounts';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE podcast_id = %d AND active = 1 ORDER BY platform",
            $podcast_id
        ));
    }

    /**
     * Insert or update social account
     */
    public static function upsert_social_account($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_podcast_social_accounts';

        // Check if exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE podcast_id = %d AND platform = %s",
            $data['podcast_id'],
            $data['platform']
        ));

        if ($existing) {
            unset($data['created_at']);
            unset($data['discovered_at']);
            $wpdb->update($table, $data, ['id' => $existing]);
            return $existing;
        } else {
            $wpdb->insert($table, $data);
            return $wpdb->insert_id;
        }
    }

    // ==================== CONTACTS ====================

    /**
     * Get contact by ID
     */
    public static function get_contact($contact_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_podcast_contacts';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $contact_id
        ));
    }

    /**
     * Get contact by email
     */
    public static function get_contact_by_email($email) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_podcast_contacts';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE email = %s OR personal_email = %s",
            $email,
            $email
        ));
    }

    /**
     * Insert or update contact
     */
    public static function upsert_contact($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_podcast_contacts';

        // Check if exists by email
        $existing_id = null;
        if (!empty($data['email'])) {
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE email = %s",
                $data['email']
            ));
        }

        if ($existing_id) {
            unset($data['created_at']);
            $wpdb->update($table, $data, ['id' => $existing_id]);
            return $existing_id;
        } else {
            $wpdb->insert($table, $data);
            return $wpdb->insert_id;
        }
    }

    /**
     * Get contacts for a podcast (via relationships)
     */
    public static function get_podcast_contacts($podcast_id, $role = null) {
        global $wpdb;
        $contacts_table = $wpdb->prefix . 'guestify_podcast_contacts';
        $rel_table = $wpdb->prefix . 'guestify_podcast_contact_relationships';

        $sql = "SELECT c.*, r.role, r.is_primary, r.active as relationship_active
                FROM $contacts_table c
                INNER JOIN $rel_table r ON c.id = r.contact_id
                WHERE r.podcast_id = %d AND r.active = 1";

        $params = [$podcast_id];

        if ($role) {
            $sql .= " AND r.role = %s";
            $params[] = $role;
        }

        $sql .= " ORDER BY r.is_primary DESC, c.full_name";

        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    /**
     * Get primary contact for a podcast
     */
    public static function get_primary_contact($podcast_id, $role = 'host') {
        global $wpdb;
        $contacts_table = $wpdb->prefix . 'guestify_podcast_contacts';
        $rel_table = $wpdb->prefix . 'guestify_podcast_contact_relationships';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT c.*, r.role
            FROM $contacts_table c
            INNER JOIN $rel_table r ON c.id = r.contact_id
            WHERE r.podcast_id = %d AND r.role = %s AND r.is_primary = 1 AND r.active = 1
            LIMIT 1",
            $podcast_id,
            $role
        ));
    }

    // ==================== RELATIONSHIPS ====================

    /**
     * Create relationship between podcast and contact
     */
    public static function create_podcast_contact_relationship($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_podcast_contact_relationships';

        // Check if relationship already exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE podcast_id = %d AND contact_id = %d AND role = %s",
            $data['podcast_id'],
            $data['contact_id'],
            $data['role']
        ));

        if ($existing) {
            // Update existing relationship
            unset($data['created_at']);
            $wpdb->update($table, $data, ['id' => $existing]);
            return $existing;
        } else {
            $wpdb->insert($table, $data);
            return $wpdb->insert_id;
        }
    }

    /**
     * Link a contact to a podcast (convenience wrapper)
     *
     * @param int $podcast_id
     * @param int $contact_id
     * @param string $role
     * @param bool $is_primary
     * @param string $notes
     * @return int|false Relationship ID or false
     */
    public static function link_podcast_contact($podcast_id, $contact_id, $role = 'host', $is_primary = false, $notes = null) {
        return self::create_podcast_contact_relationship([
            'podcast_id' => $podcast_id,
            'contact_id' => $contact_id,
            'role' => $role,
            'is_primary' => $is_primary ? 1 : 0,
            'notes' => $notes,
            'active' => 1,
        ]);
    }

    // ==================== INTERVIEW TRACKER BRIDGE ====================

    /**
     * Link Formidable entry to podcast
     */
    public static function link_entry_to_podcast($formidable_entry_id, $podcast_id, $data = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_interview_tracker_podcasts';

        $insert_data = array_merge([
            'formidable_entry_id' => $formidable_entry_id,
            'podcast_id' => $podcast_id,
        ], $data);

        // Check if link exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE formidable_entry_id = %d AND podcast_id = %d",
            $formidable_entry_id,
            $podcast_id
        ));

        if ($existing) {
            unset($insert_data['created_at']);
            $wpdb->update($table, $insert_data, ['id' => $existing]);
            return $existing;
        } else {
            $wpdb->insert($table, $insert_data);
            return $wpdb->insert_id;
        }
    }

    /**
     * Get podcast for Formidable entry
     */
    public static function get_entry_podcast($formidable_entry_id) {
        global $wpdb;
        $podcasts_table = $wpdb->prefix . 'guestify_podcasts';
        $tracker_table = $wpdb->prefix . 'guestify_interview_tracker_podcasts';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, t.outreach_status, t.primary_contact_id
            FROM $podcasts_table p
            INNER JOIN $tracker_table t ON p.id = t.podcast_id
            WHERE t.formidable_entry_id = %d
            LIMIT 1",
            $formidable_entry_id
        ));
    }

    /**
     * Get contact for Formidable entry (via podcast bridge)
     */
    public static function get_entry_contact($formidable_entry_id) {
        global $wpdb;
        $contacts_table = $wpdb->prefix . 'guestify_podcast_contacts';
        $tracker_table = $wpdb->prefix . 'guestify_interview_tracker_podcasts';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT c.*
            FROM $contacts_table c
            INNER JOIN $tracker_table t ON c.id = t.primary_contact_id
            WHERE t.formidable_entry_id = %d
            LIMIT 1",
            $formidable_entry_id
        ));
    }
}
