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

            -- Feed migration tracking (for deduplication auditing)
            feed_migration_history text DEFAULT NULL,

            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            UNIQUE KEY itunes_id_unique (itunes_id),
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
     * Create Guest Intelligence Database tables
     *
     * Phase 1 of the Unified Intelligence Platform:
     * 1. guestify_content_analysis - AI-powered content analysis
     * 2. guestify_guests - Episode guests (different from podcast contacts)
     * 3. guestify_guest_appearances - Links guests to podcast episodes
     * 4. guestify_topics - Master topic taxonomy
     * 5. guestify_guest_topics - Pivot table for guest expertise
     * 6. guestify_guest_network - Network connections between guests
     */
    public static function create_guest_intelligence_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Table 1: CONTENT_ANALYSIS (AI-powered podcast content analysis)
        $table_content = $wpdb->prefix . 'guestify_content_analysis';
        $sql_content = "CREATE TABLE $table_content (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,

            podcast_id bigint(20) UNSIGNED NOT NULL,

            -- Content Intelligence (JSON)
            title_patterns text DEFAULT NULL,
            topic_clusters text DEFAULT NULL,
            keywords text DEFAULT NULL,
            recent_episodes text DEFAULT NULL,

            -- Publishing patterns
            publishing_frequency varchar(50) DEFAULT NULL,
            publishing_day varchar(20) DEFAULT NULL,
            average_duration int(11) DEFAULT NULL,
            format_type varchar(50) DEFAULT NULL,

            -- Analysis Metadata
            episodes_analyzed int(11) DEFAULT 0,
            episodes_total int(11) DEFAULT 0,
            backlog_warning tinyint(1) DEFAULT 0,

            ai_analyzed tinyint(1) DEFAULT 0,
            ai_analyzed_at datetime DEFAULT NULL,
            ai_model varchar(50) DEFAULT NULL,
            ai_cost decimal(10,4) DEFAULT 0,

            -- Cache
            cache_expires_at datetime DEFAULT NULL,

            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            UNIQUE KEY podcast_id_unique (podcast_id),
            KEY ai_analyzed (ai_analyzed),
            KEY cache_expires_at (cache_expires_at)
        ) $charset_collate;";

        dbDelta($sql_content);

        // Table 2: GUESTS (Episode guests - distinct from podcast contacts/owners)
        $table_guests = $wpdb->prefix . 'guestify_guests';
        $sql_guests = "CREATE TABLE $table_guests (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,

            -- Identity
            full_name varchar(255) NOT NULL,
            first_name varchar(100) DEFAULT NULL,
            last_name varchar(100) DEFAULT NULL,

            -- CRITICAL: Unique Identifiers for Deduplication
            linkedin_url text DEFAULT NULL,
            linkedin_url_hash char(32) DEFAULT NULL,
            email varchar(255) DEFAULT NULL,
            email_hash char(32) DEFAULT NULL,

            -- Professional Info
            current_company varchar(255) DEFAULT NULL,
            current_role varchar(255) DEFAULT NULL,
            company_stage varchar(50) DEFAULT NULL,
            company_revenue varchar(50) DEFAULT NULL,
            industry varchar(100) DEFAULT NULL,

            -- Background & Expertise (JSON)
            expertise_areas text DEFAULT NULL,
            past_companies text DEFAULT NULL,
            education text DEFAULT NULL,
            notable_achievements text DEFAULT NULL,

            -- Contact Information (enrichment data)
            personal_email varchar(255) DEFAULT NULL,
            phone varchar(50) DEFAULT NULL,
            twitter_handle varchar(100) DEFAULT NULL,
            website_url text DEFAULT NULL,

            -- Social Proof
            linkedin_connections int(11) DEFAULT NULL,
            twitter_followers int(11) DEFAULT NULL,
            verified_accounts text DEFAULT NULL,

            -- Location
            city varchar(100) DEFAULT NULL,
            state_region varchar(100) DEFAULT NULL,
            country varchar(100) DEFAULT NULL,
            country_code varchar(10) DEFAULT NULL,
            timezone varchar(50) DEFAULT NULL,
            location_display varchar(255) DEFAULT NULL,

            -- Enrichment Status
            enrichment_provider varchar(50) DEFAULT NULL,
            enrichment_level varchar(50) DEFAULT NULL,
            enriched_at datetime DEFAULT NULL,
            enrichment_cost decimal(10,4) DEFAULT 0,
            data_quality_score int(11) DEFAULT 0,

            -- Manual Verification
            manually_verified tinyint(1) DEFAULT 0,
            verified_by_user_id bigint(20) UNSIGNED DEFAULT NULL,
            verified_at datetime DEFAULT NULL,
            verification_notes text DEFAULT NULL,

            -- Deduplication Status
            is_merged tinyint(1) DEFAULT 0,
            merged_into_guest_id bigint(20) UNSIGNED DEFAULT NULL,
            merge_history text DEFAULT NULL,

            -- Source tracking
            source varchar(50) DEFAULT NULL,
            source_podcast_id bigint(20) UNSIGNED DEFAULT NULL,

            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            KEY full_name (full_name),
            KEY email_hash (email_hash),
            KEY linkedin_url_hash (linkedin_url_hash),
            KEY current_company (current_company),
            KEY enrichment_provider (enrichment_provider),
            KEY manually_verified (manually_verified),
            KEY is_merged (is_merged),
            KEY source (source)
        ) $charset_collate;";

        dbDelta($sql_guests);

        // Table 3: GUEST_APPEARANCES (Links guests to podcast episodes)
        $table_appearances = $wpdb->prefix . 'guestify_guest_appearances';
        $sql_appearances = "CREATE TABLE $table_appearances (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,

            guest_id bigint(20) UNSIGNED NOT NULL,
            podcast_id bigint(20) UNSIGNED NOT NULL,

            -- Episode Details
            episode_number int(11) DEFAULT NULL,
            episode_title varchar(500) DEFAULT NULL,
            episode_date date DEFAULT NULL,
            episode_url text DEFAULT NULL,
            episode_duration int(11) DEFAULT NULL,
            episode_guid varchar(255) DEFAULT NULL,

            -- Content Analysis (JSON)
            topics_discussed text DEFAULT NULL,
            key_quotes text DEFAULT NULL,
            conversation_style varchar(50) DEFAULT NULL,

            -- Verification (AI can hallucinate)
            ai_confidence_score int(11) DEFAULT 0,
            manually_verified tinyint(1) DEFAULT 0,
            is_host tinyint(1) DEFAULT 0,
            verification_notes text DEFAULT NULL,

            -- Source
            extraction_method varchar(50) DEFAULT NULL,
            extraction_cost decimal(10,4) DEFAULT 0,

            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            KEY guest_id (guest_id),
            KEY podcast_id (podcast_id),
            KEY episode_date (episode_date),
            KEY manually_verified (manually_verified),
            KEY is_host (is_host),
            KEY episode_guid (episode_guid),
            UNIQUE KEY unique_guest_episode (guest_id, podcast_id, episode_guid)
        ) $charset_collate;";

        dbDelta($sql_appearances);

        // Table 4: TOPICS (Master topic taxonomy)
        $table_topics = $wpdb->prefix . 'guestify_topics';
        $sql_topics = "CREATE TABLE $table_topics (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,

            name varchar(100) NOT NULL,
            slug varchar(100) NOT NULL,
            category varchar(50) DEFAULT NULL,
            description text DEFAULT NULL,
            parent_id bigint(20) UNSIGNED DEFAULT NULL,

            usage_count int(11) DEFAULT 0,

            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY category (category),
            KEY parent_id (parent_id),
            KEY usage_count (usage_count)
        ) $charset_collate;";

        dbDelta($sql_topics);

        // Table 5: GUEST_TOPICS (Pivot table for guest expertise)
        $table_guest_topics = $wpdb->prefix . 'guestify_guest_topics';
        $sql_guest_topics = "CREATE TABLE $table_guest_topics (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,

            guest_id bigint(20) UNSIGNED NOT NULL,
            topic_id bigint(20) UNSIGNED NOT NULL,

            -- Metadata
            confidence_score int(11) DEFAULT 100,
            mention_count int(11) DEFAULT 1,
            source varchar(50) DEFAULT NULL,

            created_at datetime DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            KEY guest_id (guest_id),
            KEY topic_id (topic_id),
            UNIQUE KEY guest_topic (guest_id, topic_id)
        ) $charset_collate;";

        dbDelta($sql_guest_topics);

        // Table 6: GUEST_NETWORK (Network connections - 1st and 2nd degree only)
        $table_network = $wpdb->prefix . 'guestify_guest_network';
        $sql_network = "CREATE TABLE $table_network (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,

            guest_id bigint(20) UNSIGNED NOT NULL,
            connected_guest_id bigint(20) UNSIGNED NOT NULL,

            connection_type varchar(50) DEFAULT NULL,
            connection_degree int(11) NOT NULL DEFAULT 1,
            connection_strength int(11) DEFAULT 0,

            -- Network Data (JSON)
            common_podcasts text DEFAULT NULL,
            connection_path text DEFAULT NULL,

            -- Performance Optimization
            last_calculated datetime DEFAULT NULL,
            cache_expires_at datetime DEFAULT NULL,

            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            KEY guest_id (guest_id),
            KEY connected_guest_id (connected_guest_id),
            KEY connection_degree (connection_degree),
            KEY connection_strength (connection_strength),
            KEY cache_expires_at (cache_expires_at),
            UNIQUE KEY guest_connection (guest_id, connected_guest_id)
        ) $charset_collate;";

        dbDelta($sql_network);
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
     * DEDUPLICATION STRATEGY (Updated based on feed migration analysis):
     *
     * PRIORITY 1: iTunes ID (apple_collection_id) - MOST STABLE
     *   - Remains constant even when podcasters change hosting providers
     *   - Feed migrations (e.g., Anchor → Transistor) change RSS URL but not iTunes ID
     *
     * PRIORITY 2: Provider-specific IDs (podcast_index_id, taddy_uuid)
     *   - Directory systems that track feed redirects
     *   - Each provider has their own ID for the same podcast
     *
     * PRIORITY 3: RSS Feed URL - FALLBACK
     *   - Still useful for shows not yet on Apple Podcasts
     *   - Can change when podcaster migrates hosting
     *
     * FEED URL UPDATE: When iTunes ID matches but RSS URL differs, we UPDATE
     * the stored RSS URL to reflect the new hosting location.
     */
    public static function upsert_guestify_podcast($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_podcasts';

        // Generate slug if not provided
        if (empty($data['slug']) && !empty($data['title'])) {
            $data['slug'] = sanitize_title($data['title']);
        }

        $existing_id = null;
        $matched_by = null;
        $existing_rss_url = null;

        // PRIORITY 1: iTunes ID - MOST STABLE identifier
        // Remains constant across feed migrations (Anchor → Transistor, etc.)
        if (!$existing_id && !empty($data['itunes_id'])) {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, rss_feed_url FROM $table WHERE itunes_id = %s",
                $data['itunes_id']
            ));
            if ($existing) {
                $existing_id = $existing->id;
                $existing_rss_url = $existing->rss_feed_url;
                $matched_by = 'itunes_id';
            }
        }

        // PRIORITY 2: Provider-specific IDs
        // These directories typically follow feed redirects
        if (!$existing_id && !empty($data['podcast_index_id'])) {
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE podcast_index_id = %d",
                $data['podcast_index_id']
            ));
            if ($existing_id) {
                $matched_by = 'podcast_index_id';
            }
        }

        if (!$existing_id && !empty($data['podcast_index_guid'])) {
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE podcast_index_guid = %s",
                $data['podcast_index_guid']
            ));
            if ($existing_id) {
                $matched_by = 'podcast_index_guid';
            }
        }

        if (!$existing_id && !empty($data['taddy_podcast_uuid'])) {
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE taddy_podcast_uuid = %s",
                $data['taddy_podcast_uuid']
            ));
            if ($existing_id) {
                $matched_by = 'taddy_uuid';
            }
        }

        // PRIORITY 3: RSS URL - FALLBACK for shows not on Apple Podcasts
        if (!$existing_id && !empty($data['rss_feed_url'])) {
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE rss_feed_url = %s",
                $data['rss_feed_url']
            ));
            if ($existing_id) {
                $matched_by = 'rss_feed_url';
            }
        }

        // Last resort: check slug (least reliable)
        if (!$existing_id && !empty($data['slug'])) {
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE slug = %s",
                $data['slug']
            ));
            if ($existing_id) {
                $matched_by = 'slug';
            }
        }

        if ($existing_id) {
            // Handle feed migration detection
            // If matched by iTunes ID and RSS URL differs, log and update
            if ($matched_by === 'itunes_id' &&
                !empty($data['rss_feed_url']) &&
                !empty($existing_rss_url) &&
                $data['rss_feed_url'] !== $existing_rss_url) {

                // Log the feed migration for debugging/auditing
                error_log(sprintf(
                    'PIT: Feed migration detected for iTunes ID %s - Old: %s, New: %s',
                    $data['itunes_id'],
                    $existing_rss_url,
                    $data['rss_feed_url']
                ));

                // Store the old URL for reference (optional metadata)
                if (!isset($data['feed_migration_history'])) {
                    $existing_history = $wpdb->get_var($wpdb->prepare(
                        "SELECT feed_migration_history FROM $table WHERE id = %d",
                        $existing_id
                    ));
                    $history = $existing_history ? json_decode($existing_history, true) : [];
                    if (!is_array($history)) {
                        $history = [];
                    }
                    $history[] = [
                        'old_url' => $existing_rss_url,
                        'new_url' => $data['rss_feed_url'],
                        'migrated_at' => current_time('mysql'),
                    ];
                    $data['feed_migration_history'] = json_encode($history);
                }
            }

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

    // ==================== GUEST INTELLIGENCE CRUD ====================

    /**
     * Get guest by ID
     */
    public static function get_guest($guest_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_guests';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND is_merged = 0",
            $guest_id
        ));
    }

    /**
     * Get guest by LinkedIn URL hash
     */
    public static function get_guest_by_linkedin($linkedin_url) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_guests';
        $hash = md5($linkedin_url);

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE linkedin_url_hash = %s AND is_merged = 0",
            $hash
        ));
    }

    /**
     * Get guest by email hash
     */
    public static function get_guest_by_email($email) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_guests';
        $hash = md5(strtolower(trim($email)));

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE email_hash = %s AND is_merged = 0",
            $hash
        ));
    }

    /**
     * Upsert guest with deduplication
     *
     * DEDUPLICATION PRIORITY:
     * 1. LinkedIn URL (highest confidence)
     * 2. Email address (high confidence)
     * 3. Create new record (do NOT merge by name alone)
     */
    public static function upsert_guest($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_guests';

        // Generate hashes for deduplication
        if (!empty($data['linkedin_url'])) {
            $data['linkedin_url_hash'] = md5($data['linkedin_url']);
        }
        if (!empty($data['email'])) {
            $data['email_hash'] = md5(strtolower(trim($data['email'])));
        }

        $existing_id = null;

        // Priority 1: LinkedIn URL match
        if (!$existing_id && !empty($data['linkedin_url_hash'])) {
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE linkedin_url_hash = %s AND is_merged = 0",
                $data['linkedin_url_hash']
            ));
        }

        // Priority 2: Email match
        if (!$existing_id && !empty($data['email_hash'])) {
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE email_hash = %s AND is_merged = 0",
                $data['email_hash']
            ));
        }

        // DO NOT match by name alone - too risky for deduplication

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
     * Create guest (no deduplication check)
     */
    public static function create_guest($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_guests';

        // Generate hashes
        if (!empty($data['linkedin_url'])) {
            $data['linkedin_url_hash'] = md5($data['linkedin_url']);
        }
        if (!empty($data['email'])) {
            $data['email_hash'] = md5(strtolower(trim($data['email'])));
        }

        $wpdb->insert($table, $data);
        return $wpdb->insert_id;
    }

    /**
     * Update guest
     */
    public static function update_guest($guest_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_guests';

        // Regenerate hashes if URLs changed
        if (isset($data['linkedin_url'])) {
            $data['linkedin_url_hash'] = !empty($data['linkedin_url']) ? md5($data['linkedin_url']) : null;
        }
        if (isset($data['email'])) {
            $data['email_hash'] = !empty($data['email']) ? md5(strtolower(trim($data['email']))) : null;
        }

        unset($data['created_at']);
        return $wpdb->update($table, $data, ['id' => $guest_id]);
    }

    /**
     * Delete guest (soft delete via merge flag)
     */
    public static function delete_guest($guest_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_guests';

        return $wpdb->delete($table, ['id' => $guest_id]);
    }

    /**
     * List guests with filtering
     */
    public static function list_guests($args = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_guests';

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
        ];

        $args = wp_parse_args($args, $defaults);

        $where = [];
        $prepare_args = [];

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
        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']) ?: 'created_at DESC';

        $sql = "SELECT * FROM $table WHERE $where_clause ORDER BY $orderby LIMIT %d OFFSET %d";
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

    // ==================== GUEST APPEARANCES ====================

    /**
     * Get appearance by ID
     */
    public static function get_appearance($appearance_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_guest_appearances';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $appearance_id
        ));
    }

    /**
     * Get appearances for a guest
     */
    public static function get_guest_appearances($guest_id) {
        global $wpdb;
        $appearances = $wpdb->prefix . 'guestify_guest_appearances';
        $podcasts = $wpdb->prefix . 'guestify_podcasts';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, p.title as podcast_title, p.artwork_url as podcast_artwork
            FROM $appearances a
            LEFT JOIN $podcasts p ON a.podcast_id = p.id
            WHERE a.guest_id = %d
            ORDER BY a.episode_date DESC",
            $guest_id
        ));
    }

    /**
     * Get appearances for a podcast
     */
    public static function get_podcast_guest_appearances($podcast_id) {
        global $wpdb;
        $appearances = $wpdb->prefix . 'guestify_guest_appearances';
        $guests = $wpdb->prefix . 'guestify_guests';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, g.full_name, g.current_company, g.current_role, g.linkedin_url
            FROM $appearances a
            LEFT JOIN $guests g ON a.guest_id = g.id
            WHERE a.podcast_id = %d AND g.is_merged = 0
            ORDER BY a.episode_date DESC",
            $podcast_id
        ));
    }

    /**
     * Create guest appearance
     */
    public static function create_appearance($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_guest_appearances';

        $wpdb->insert($table, $data);
        return $wpdb->insert_id;
    }

    /**
     * Update guest appearance
     */
    public static function update_appearance($appearance_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_guest_appearances';

        unset($data['created_at']);
        return $wpdb->update($table, $data, ['id' => $appearance_id]);
    }

    /**
     * Delete guest appearance
     */
    public static function delete_appearance($appearance_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_guest_appearances';

        return $wpdb->delete($table, ['id' => $appearance_id]);
    }

    // ==================== TOPICS ====================

    /**
     * Get topic by ID
     */
    public static function get_topic($topic_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_topics';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $topic_id
        ));
    }

    /**
     * Get topic by slug
     */
    public static function get_topic_by_slug($slug) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_topics';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE slug = %s",
            $slug
        ));
    }

    /**
     * Get or create topic
     */
    public static function get_or_create_topic($name, $category = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_topics';

        $slug = sanitize_title($name);

        $existing = self::get_topic_by_slug($slug);
        if ($existing) {
            return $existing->id;
        }

        $wpdb->insert($table, [
            'name' => $name,
            'slug' => $slug,
            'category' => $category,
        ]);

        return $wpdb->insert_id;
    }

    /**
     * List all topics
     */
    public static function list_topics($args = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_topics';

        $defaults = [
            'category' => '',
            'orderby' => 'usage_count',
            'order' => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);

        $where = '1=1';
        $prepare_args = [];

        if (!empty($args['category'])) {
            $where .= ' AND category = %s';
            $prepare_args[] = $args['category'];
        }

        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']) ?: 'usage_count DESC';

        $sql = "SELECT * FROM $table WHERE $where ORDER BY $orderby";

        if (!empty($prepare_args)) {
            return $wpdb->get_results($wpdb->prepare($sql, $prepare_args));
        }

        return $wpdb->get_results($sql);
    }

    /**
     * Assign topic to guest
     */
    public static function assign_topic_to_guest($guest_id, $topic_id, $confidence = 100, $source = 'manual') {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_guest_topics';
        $topics_table = $wpdb->prefix . 'guestify_topics';

        // Check if exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE guest_id = %d AND topic_id = %d",
            $guest_id, $topic_id
        ));

        if ($existing) {
            $wpdb->update($table, [
                'confidence_score' => $confidence,
                'mention_count' => $wpdb->get_var($wpdb->prepare(
                    "SELECT mention_count + 1 FROM $table WHERE id = %d",
                    $existing
                )),
            ], ['id' => $existing]);
            return $existing;
        }

        $wpdb->insert($table, [
            'guest_id' => $guest_id,
            'topic_id' => $topic_id,
            'confidence_score' => $confidence,
            'source' => $source,
        ]);

        // Update usage count
        $wpdb->query($wpdb->prepare(
            "UPDATE $topics_table SET usage_count = usage_count + 1 WHERE id = %d",
            $topic_id
        ));

        return $wpdb->insert_id;
    }

    /**
     * Get topics for a guest
     */
    public static function get_guest_topics($guest_id) {
        global $wpdb;
        $pivot = $wpdb->prefix . 'guestify_guest_topics';
        $topics = $wpdb->prefix . 'guestify_topics';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT t.*, gt.confidence_score, gt.mention_count
            FROM $topics t
            INNER JOIN $pivot gt ON t.id = gt.topic_id
            WHERE gt.guest_id = %d
            ORDER BY gt.confidence_score DESC",
            $guest_id
        ));
    }

    // ==================== CONTENT ANALYSIS ====================

    /**
     * Get content analysis for podcast
     */
    public static function get_content_analysis($podcast_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_content_analysis';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE podcast_id = %d",
            $podcast_id
        ));
    }

    /**
     * Upsert content analysis
     */
    public static function upsert_content_analysis($podcast_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_content_analysis';

        $data['podcast_id'] = $podcast_id;

        // Check if exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE podcast_id = %d",
            $podcast_id
        ));

        if ($existing) {
            unset($data['created_at']);
            $wpdb->update($table, $data, ['id' => $existing]);
            return $existing;
        }

        $wpdb->insert($table, $data);
        return $wpdb->insert_id;
    }

    // ==================== GUEST NETWORK ====================

    /**
     * Get network connections for a guest
     */
    public static function get_guest_network($guest_id, $max_degree = 2) {
        global $wpdb;
        $network = $wpdb->prefix . 'guestify_guest_network';
        $guests = $wpdb->prefix . 'guestify_guests';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT n.*, g.full_name, g.current_company, g.current_role
            FROM $network n
            INNER JOIN $guests g ON n.connected_guest_id = g.id
            WHERE n.guest_id = %d AND n.connection_degree <= %d AND g.is_merged = 0
            ORDER BY n.connection_strength DESC",
            $guest_id,
            $max_degree
        ));
    }

    /**
     * Create or update network connection
     */
    public static function upsert_network_connection($guest_id, $connected_guest_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_guest_network';

        $data['guest_id'] = $guest_id;
        $data['connected_guest_id'] = $connected_guest_id;

        // Check if exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE guest_id = %d AND connected_guest_id = %d",
            $guest_id, $connected_guest_id
        ));

        if ($existing) {
            unset($data['created_at']);
            $wpdb->update($table, $data, ['id' => $existing]);
            return $existing;
        }

        $wpdb->insert($table, $data);
        return $wpdb->insert_id;
    }

    /**
     * Clear network cache for guest
     */
    public static function clear_guest_network_cache($guest_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_guest_network';

        return $wpdb->delete($table, ['guest_id' => $guest_id]);
    }
}
