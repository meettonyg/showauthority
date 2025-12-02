<?php
/**
 * Database Schema Management
 *
 * Handles creation and migration of all database tables.
 * Unified schema consolidating pit_* and guestify_* tables.
 *
 * @package PodcastInfluenceTracker
 * @subpackage Core
 */

// Note: Not using namespaces for WordPress compatibility with legacy code

if (!defined('ABSPATH')) {
    exit;
}

class Database_Schema {

    /**
     * Database version for migrations
     */
    const DB_VERSION = '3.3.0';

    /**
     * Create all database tables
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        self::create_user_tables($charset_collate);
        self::create_podcast_tables($charset_collate);
        self::create_guest_tables($charset_collate);
        self::create_social_metrics_tables($charset_collate);
        self::create_job_tables($charset_collate);

        update_option('pit_db_version', self::DB_VERSION);
    }

    /**
     * Create user/multi-tenancy tables
     */
    private static function create_user_tables($charset_collate) {
        global $wpdb;

        // User limits and usage tracking
        $table_limits = $wpdb->prefix . 'pit_user_limits';
        $sql_limits = "CREATE TABLE $table_limits (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,

            user_id bigint(20) UNSIGNED NOT NULL,

            -- Plan Info
            plan_type enum('free', 'starter', 'pro', 'enterprise') DEFAULT 'free',
            plan_started_at datetime DEFAULT NULL,
            plan_expires_at datetime DEFAULT NULL,

            -- Limits
            max_tracked_podcasts int(11) DEFAULT 10,
            max_guests int(11) DEFAULT 100,
            max_api_calls_month int(11) DEFAULT 500,
            max_exports_month int(11) DEFAULT 10,

            -- Current Usage (reset monthly)
            current_tracked_podcasts int(11) DEFAULT 0,
            current_guests int(11) DEFAULT 0,
            current_api_calls int(11) DEFAULT 0,
            current_exports int(11) DEFAULT 0,

            -- Billing cycle
            billing_cycle_start date DEFAULT NULL,
            last_usage_reset datetime DEFAULT NULL,

            -- Metadata
            stripe_customer_id varchar(100) DEFAULT NULL,
            stripe_subscription_id varchar(100) DEFAULT NULL,

            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            UNIQUE KEY user_id_unique (user_id),
            KEY plan_type_idx (plan_type)
        ) $charset_collate;";

        dbDelta($sql_limits);

        // User podcast tracking (many-to-many: which podcasts each user tracks)
        $table_user_podcasts = $wpdb->prefix . 'pit_user_podcasts';
        $sql_user_podcasts = "CREATE TABLE $table_user_podcasts (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,

            user_id bigint(20) UNSIGNED NOT NULL,
            podcast_id bigint(20) UNSIGNED NOT NULL,

            -- User-specific tracking status
            is_tracked tinyint(1) DEFAULT 1,
            tracking_status enum('not_tracked', 'queued', 'processing', 'tracked', 'failed') DEFAULT 'queued',

            -- User notes/tags
            user_notes text DEFAULT NULL,
            user_tags varchar(500) DEFAULT NULL,
            is_favorite tinyint(1) DEFAULT 0,

            -- User-specific metrics preferences
            platforms_to_track text DEFAULT NULL,
            refresh_frequency enum('daily', 'weekly', 'monthly', 'manual') DEFAULT 'weekly',

            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            UNIQUE KEY user_podcast_unique (user_id, podcast_id),
            KEY user_id_idx (user_id),
            KEY podcast_id_idx (podcast_id),
            KEY is_tracked_idx (is_tracked)
        ) $charset_collate;";

        dbDelta($sql_user_podcasts);

        // Formidable Forms Entry <-> Podcast Links (many-to-one)
        // Multiple Formidable entries can reference the same podcast
        // Example: Multiple users want to be guests on "The Tim Ferriss Show"
        $table_formidable_links = $wpdb->prefix . 'pit_formidable_podcast_links';
        $sql_formidable_links = "CREATE TABLE $table_formidable_links (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,

            formidable_entry_id bigint(20) UNSIGNED NOT NULL,
            podcast_id bigint(20) UNSIGNED NOT NULL,
            user_id bigint(20) UNSIGNED DEFAULT NULL,

            -- Sync metadata
            synced_at datetime DEFAULT CURRENT_TIMESTAMP,
            sync_status enum('synced', 'pending', 'failed') DEFAULT 'synced',
            sync_error text DEFAULT NULL,

            -- Optional: RSS URL at time of sync (for debugging)
            rss_url_at_sync text DEFAULT NULL,

            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            UNIQUE KEY entry_unique (formidable_entry_id),
            KEY podcast_id_idx (podcast_id),
            KEY user_id_idx (user_id),
            KEY sync_status_idx (sync_status)
        ) $charset_collate;";

        dbDelta($sql_formidable_links);

        // API rate limiting
        $table_rate_limits = $wpdb->prefix . 'pit_rate_limits';
        $sql_rate_limits = "CREATE TABLE $table_rate_limits (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,

            user_id bigint(20) UNSIGNED NOT NULL,
            endpoint varchar(100) NOT NULL,

            request_count int(11) DEFAULT 1,
            window_start datetime NOT NULL,
            window_seconds int(11) DEFAULT 60,

            PRIMARY KEY (id),
            UNIQUE KEY user_endpoint_window (user_id, endpoint, window_start),
            KEY user_id_idx (user_id)
        ) $charset_collate;";

        dbDelta($sql_rate_limits);
    }

    /**
     * Create podcast-related tables
     */
    private static function create_podcast_tables($charset_collate) {
        global $wpdb;

        // Main podcasts table
        $table_podcasts = $wpdb->prefix . 'pit_podcasts';
        $sql_podcasts = "CREATE TABLE $table_podcasts (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,

            -- Basic Info
            title varchar(500) NOT NULL,
            slug varchar(200) DEFAULT NULL,
            description text DEFAULT NULL,
            author varchar(255) DEFAULT NULL,
            email varchar(255) DEFAULT NULL,

            -- URLs
            rss_feed_url text DEFAULT NULL,
            website_url text DEFAULT NULL,
            artwork_url text DEFAULT NULL,

            -- Metadata
            category varchar(100) DEFAULT NULL,
            language varchar(10) DEFAULT 'en',
            explicit_rating varchar(20) DEFAULT 'clean',
            copyright text DEFAULT NULL,
            episode_count int(11) DEFAULT 0,
            frequency varchar(50) DEFAULT NULL,
            average_duration int(11) DEFAULT NULL,
            founded_date date DEFAULT NULL,
            last_episode_date date DEFAULT NULL,

            -- External IDs (for deduplication)
            itunes_id varchar(50) DEFAULT NULL,
            spotify_id varchar(50) DEFAULT NULL,
            podcast_index_id bigint(20) DEFAULT NULL,
            podcast_index_guid varchar(255) DEFAULT NULL,

            -- Tracking Status
            is_tracked tinyint(1) DEFAULT 0,
            tracking_status enum('not_tracked', 'queued', 'processing', 'tracked', 'failed') DEFAULT 'not_tracked',

            -- Discovery Flags
            homepage_scraped tinyint(1) DEFAULT 0,
            last_rss_check datetime DEFAULT NULL,
            social_links_discovered tinyint(1) DEFAULT 0,
            metrics_enriched tinyint(1) DEFAULT 0,
            last_enriched_at datetime DEFAULT NULL,
            metadata_updated_at datetime DEFAULT NULL,

            -- Quality Scores
            data_quality_score int(11) DEFAULT 0,
            relevance_score int(11) DEFAULT 0,

            -- Location
            city varchar(100) DEFAULT NULL,
            state_region varchar(100) DEFAULT NULL,
            country varchar(100) DEFAULT NULL,
            timezone varchar(50) DEFAULT NULL,

            -- Source tracking
            source varchar(50) DEFAULT NULL,
            feed_migration_history text DEFAULT NULL,

            -- Status
            is_active tinyint(1) DEFAULT 1,

            -- Timestamps
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            UNIQUE KEY slug_unique (slug),
            UNIQUE KEY itunes_id_unique (itunes_id),
            UNIQUE KEY rss_feed_url_unique (rss_feed_url(191)),
            KEY title_idx (title(191)),
            KEY is_tracked_idx (is_tracked),
            KEY tracking_status_idx (tracking_status),
            KEY source_idx (source)
        ) $charset_collate;";

        dbDelta($sql_podcasts);

        // Podcast contacts (hosts, producers) - CROWDSOURCED
        $table_contacts = $wpdb->prefix . 'pit_podcast_contacts';
        $sql_contacts = "CREATE TABLE $table_contacts (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,

            -- Ownership & Visibility (Crowdsourcing)
            created_by_user_id bigint(20) UNSIGNED DEFAULT NULL,
            is_public tinyint(1) DEFAULT 1,
            visibility enum('public', 'private', 'verified_only') DEFAULT 'public',

            -- Community Verification
            community_verified tinyint(1) DEFAULT 0,
            verification_count int(11) DEFAULT 0,
            last_verified_at datetime DEFAULT NULL,
            last_verified_by bigint(20) UNSIGNED DEFAULT NULL,
            report_count int(11) DEFAULT 0,

            -- Identity
            full_name varchar(255) NOT NULL,
            first_name varchar(100) DEFAULT NULL,
            last_name varchar(100) DEFAULT NULL,

            -- Contact Info
            email varchar(255) DEFAULT NULL,
            personal_email varchar(255) DEFAULT NULL,
            phone varchar(50) DEFAULT NULL,

            -- Professional
            role varchar(100) DEFAULT NULL,
            company varchar(255) DEFAULT NULL,
            title varchar(255) DEFAULT NULL,

            -- Social URLs
            linkedin_url text DEFAULT NULL,
            twitter_url text DEFAULT NULL,
            website_url text DEFAULT NULL,

            -- Location
            city varchar(100) DEFAULT NULL,
            state_region varchar(100) DEFAULT NULL,
            country varchar(100) DEFAULT NULL,
            timezone varchar(50) DEFAULT NULL,

            -- Enrichment
            enrichment_provider varchar(50) DEFAULT NULL,
            enriched_at datetime DEFAULT NULL,
            data_quality_score int(11) DEFAULT 0,
            source varchar(50) DEFAULT NULL,

            -- Contact preferences
            preferred_contact_method varchar(50) DEFAULT NULL,
            notes text DEFAULT NULL,

            -- Timestamps
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            KEY full_name_idx (full_name),
            KEY email_idx (email),
            KEY created_by_user_id_idx (created_by_user_id),
            KEY is_public_idx (is_public),
            KEY community_verified_idx (community_verified)
        ) $charset_collate;";

        dbDelta($sql_contacts);

        // Podcast-Contact relationships
        $table_relationships = $wpdb->prefix . 'pit_podcast_contact_relationships';
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
            KEY podcast_id_idx (podcast_id),
            KEY contact_id_idx (contact_id),
            UNIQUE KEY unique_podcast_contact_role (podcast_id, contact_id, role)
        ) $charset_collate;";

        dbDelta($sql_relationships);

        // Content analysis
        $table_content = $wpdb->prefix . 'pit_content_analysis';
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

            ai_analyzed tinyint(1) DEFAULT 0,
            ai_analyzed_at datetime DEFAULT NULL,
            ai_model varchar(50) DEFAULT NULL,
            ai_cost decimal(10,4) DEFAULT 0,

            cache_expires_at datetime DEFAULT NULL,

            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            UNIQUE KEY podcast_id_unique (podcast_id)
        ) $charset_collate;";

        dbDelta($sql_content);
    }

    /**
     * Create guest-related tables
     */
    private static function create_guest_tables($charset_collate) {
        global $wpdb;

        // Guests table - USER OWNED
        $table_guests = $wpdb->prefix . 'pit_guests';
        $sql_guests = "CREATE TABLE $table_guests (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,

            -- User Ownership
            user_id bigint(20) UNSIGNED NOT NULL,

            -- Identity
            full_name varchar(255) NOT NULL,
            first_name varchar(100) DEFAULT NULL,
            last_name varchar(100) DEFAULT NULL,

            -- Deduplication Keys (hashed for fast lookup)
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

            -- Background (JSON)
            expertise_areas text DEFAULT NULL,
            past_companies text DEFAULT NULL,
            education text DEFAULT NULL,
            notable_achievements text DEFAULT NULL,

            -- Additional Contact
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
            timezone varchar(50) DEFAULT NULL,

            -- Enrichment
            enrichment_provider varchar(50) DEFAULT NULL,
            enrichment_level varchar(50) DEFAULT NULL,
            enriched_at datetime DEFAULT NULL,
            enrichment_cost decimal(10,4) DEFAULT 0,
            data_quality_score int(11) DEFAULT 0,

            -- Verification
            manually_verified tinyint(1) DEFAULT 0,
            verified_by_user_id bigint(20) UNSIGNED DEFAULT NULL,
            verified_at datetime DEFAULT NULL,
            verification_notes text DEFAULT NULL,

            -- Deduplication
            is_merged tinyint(1) DEFAULT 0,
            merged_into_guest_id bigint(20) UNSIGNED DEFAULT NULL,
            merge_history text DEFAULT NULL,

            -- Source
            source varchar(50) DEFAULT NULL,
            source_podcast_id bigint(20) UNSIGNED DEFAULT NULL,

            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            KEY user_id_idx (user_id),
            KEY full_name_idx (full_name),
            KEY email_hash_idx (email_hash),
            KEY linkedin_url_hash_idx (linkedin_url_hash),
            KEY current_company_idx (current_company),
            KEY manually_verified_idx (manually_verified),
            KEY is_merged_idx (is_merged)
        ) $charset_collate;";

        dbDelta($sql_guests);

        // Guest appearances
        $table_appearances = $wpdb->prefix . 'pit_guest_appearances';
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

            -- Verification
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
            KEY guest_id_idx (guest_id),
            KEY podcast_id_idx (podcast_id),
            KEY episode_date_idx (episode_date),
            UNIQUE KEY unique_guest_episode (guest_id, podcast_id, episode_guid)
        ) $charset_collate;";

        dbDelta($sql_appearances);

        // Topics taxonomy
        $table_topics = $wpdb->prefix . 'pit_topics';
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
            UNIQUE KEY slug_unique (slug),
            KEY category_idx (category),
            KEY usage_count_idx (usage_count)
        ) $charset_collate;";

        dbDelta($sql_topics);

        // Guest-Topic relationships
        $table_guest_topics = $wpdb->prefix . 'pit_guest_topics';
        $sql_guest_topics = "CREATE TABLE $table_guest_topics (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,

            guest_id bigint(20) UNSIGNED NOT NULL,
            topic_id bigint(20) UNSIGNED NOT NULL,

            confidence_score int(11) DEFAULT 100,
            mention_count int(11) DEFAULT 1,
            source varchar(50) DEFAULT NULL,

            created_at datetime DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            KEY guest_id_idx (guest_id),
            KEY topic_id_idx (topic_id),
            UNIQUE KEY guest_topic_unique (guest_id, topic_id)
        ) $charset_collate;";

        dbDelta($sql_guest_topics);

        // Guest network connections
        $table_network = $wpdb->prefix . 'pit_guest_network';
        $sql_network = "CREATE TABLE $table_network (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,

            guest_id bigint(20) UNSIGNED NOT NULL,
            connected_guest_id bigint(20) UNSIGNED NOT NULL,

            connection_type varchar(50) DEFAULT NULL,
            connection_degree int(11) NOT NULL DEFAULT 1,
            connection_strength int(11) DEFAULT 0,

            common_podcasts text DEFAULT NULL,
            connection_path text DEFAULT NULL,

            last_calculated datetime DEFAULT NULL,
            cache_expires_at datetime DEFAULT NULL,

            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            KEY guest_id_idx (guest_id),
            KEY connected_guest_id_idx (connected_guest_id),
            KEY connection_degree_idx (connection_degree),
            UNIQUE KEY guest_connection_unique (guest_id, connected_guest_id)
        ) $charset_collate;";

        dbDelta($sql_network);
    }

    /**
     * Create social metrics tables
     */
    private static function create_social_metrics_tables($charset_collate) {
        global $wpdb;

        // Social links/accounts
        $table_social = $wpdb->prefix . 'pit_social_links';
        $sql_social = "CREATE TABLE $table_social (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,

            podcast_id bigint(20) UNSIGNED NOT NULL,

            platform varchar(50) NOT NULL,
            profile_url text NOT NULL,
            profile_handle varchar(255) DEFAULT NULL,
            display_name varchar(255) DEFAULT NULL,

            -- Discovery
            discovery_source enum('rss', 'homepage', 'manual', 'api') DEFAULT 'manual',
            discovered_at datetime DEFAULT CURRENT_TIMESTAMP,
            is_verified tinyint(1) DEFAULT 0,

            -- Cached metrics (latest values)
            followers_count int(11) DEFAULT NULL,
            engagement_rate decimal(5,2) DEFAULT NULL,
            post_frequency varchar(50) DEFAULT NULL,
            last_post_date date DEFAULT NULL,

            -- Enrichment status
            metrics_enriched tinyint(1) DEFAULT 0,
            enriched_at datetime DEFAULT NULL,
            enrichment_cost_cents int(11) DEFAULT NULL,

            active tinyint(1) DEFAULT 1,

            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            KEY podcast_id_idx (podcast_id),
            KEY platform_idx (platform),
            UNIQUE KEY unique_podcast_platform (podcast_id, platform)
        ) $charset_collate;";

        dbDelta($sql_social);

        // Metrics history
        $table_metrics = $wpdb->prefix . 'pit_metrics';
        $sql_metrics = "CREATE TABLE $table_metrics (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,

            social_link_id bigint(20) UNSIGNED NOT NULL,
            podcast_id bigint(20) UNSIGNED NOT NULL,
            platform varchar(50) NOT NULL,

            -- Metrics
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

            -- Raw data
            api_response longtext DEFAULT NULL,

            -- Cost tracking
            cost_usd decimal(10,4) DEFAULT 0.0000,
            fetch_duration_seconds decimal(8,2) DEFAULT 0.00,
            fetch_method enum('api', 'scrape', 'manual') DEFAULT 'api',
            data_quality_score int(11) DEFAULT 100,

            -- Timestamps
            fetched_at datetime DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime DEFAULT NULL,

            PRIMARY KEY (id),
            KEY social_link_id_idx (social_link_id),
            KEY podcast_id_idx (podcast_id),
            KEY platform_idx (platform),
            KEY fetched_at_idx (fetched_at)
        ) $charset_collate;";

        dbDelta($sql_metrics);
    }

    /**
     * Create job and cost tracking tables
     */
    private static function create_job_tables($charset_collate) {
        global $wpdb;

        // Job queue - USER OWNED
        $table_jobs = $wpdb->prefix . 'pit_jobs';
        $sql_jobs = "CREATE TABLE $table_jobs (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,

            -- User Ownership
            user_id bigint(20) UNSIGNED NOT NULL,

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

            PRIMARY KEY (id),
            KEY user_id_idx (user_id),
            KEY podcast_id_idx (podcast_id),
            KEY status_idx (status),
            KEY priority_idx (priority),
            KEY created_at_idx (created_at)
        ) $charset_collate;";

        dbDelta($sql_jobs);

        // Cost log - USER OWNED
        $table_costs = $wpdb->prefix . 'pit_cost_log';
        $sql_costs = "CREATE TABLE $table_costs (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,

            -- User Ownership
            user_id bigint(20) UNSIGNED NOT NULL,

            podcast_id bigint(20) UNSIGNED DEFAULT NULL,
            job_id bigint(20) UNSIGNED DEFAULT NULL,

            action_type enum('discovery', 'enrichment', 'refresh', 'manual') NOT NULL,
            platform varchar(50) DEFAULT NULL,
            cost_usd decimal(10,4) NOT NULL,

            api_provider enum('youtube', 'apify', 'apollo', 'hunter', 'other') DEFAULT NULL,
            api_calls_made int(11) DEFAULT 1,
            success tinyint(1) DEFAULT 1,

            metadata longtext DEFAULT NULL,
            logged_at datetime DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            KEY user_id_idx (user_id),
            KEY podcast_id_idx (podcast_id),
            KEY job_id_idx (job_id),
            KEY action_type_idx (action_type),
            KEY logged_at_idx (logged_at)
        ) $charset_collate;";

        dbDelta($sql_costs);
    }

    /**
     * Check if database needs migration
     */
    public static function needs_migration() {
        $current_version = get_option('pit_db_version', '0.0.0');
        return version_compare($current_version, self::DB_VERSION, '<');
    }

    /**
     * Run database migrations
     */
    public static function migrate() {
        self::create_tables();
    }

    /**
     * Drop all plugin tables (for uninstall)
     */
    public static function drop_tables() {
        global $wpdb;

        $tables = [
            // User tables
            'pit_user_limits',
            'pit_user_podcasts',
            'pit_formidable_podcast_links',
            'pit_rate_limits',
            // Podcast tables
            'pit_podcasts',
            'pit_podcast_contacts',
            'pit_podcast_contact_relationships',
            'pit_content_analysis',
            // Guest tables
            'pit_guests',
            'pit_guest_appearances',
            'pit_topics',
            'pit_guest_topics',
            'pit_guest_network',
            // Social metrics tables
            'pit_social_links',
            'pit_metrics',
            // Job tables
            'pit_jobs',
            'pit_cost_log',
        ];

        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
        }

        delete_option('pit_db_version');
    }
}
