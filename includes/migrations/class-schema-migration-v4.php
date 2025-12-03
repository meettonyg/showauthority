<?php
/**
 * Migration v4.0: Global Guest Directory & CRM/Intelligence Separation
 * 
 * This migration implements the four-layer architecture:
 * - Layer 0a: pit_guests (Global) - Public guest profiles with claiming
 * - Layer 0b: pit_guest_private_contacts (User-owned) - Private contact info
 * - Layer 1: pit_opportunities (User-owned) - CRM pipeline
 * - Layer 2: pit_engagements (Global) - Public speaking records
 * - Layer 3: pit_speaking_credits (Global) - Guest-engagement links
 * 
 * Also creates pit_claim_requests for identity verification workflow.
 *
 * @package PodcastInfluenceTracker
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Schema_Migration_V4 {

    /**
     * New database version after migration
     */
    const NEW_VERSION = '4.0.0';

    /**
     * Status mapping from old to new
     */
    const STATUS_MAPPING = [
        'potential'   => 'lead',
        'active'      => 'pitched',
        'aired'       => 'aired',
        'convert'     => 'promoted',
        'on_hold'     => 'on_hold',
        'cancelled'   => 'cancelled',
        'unqualified' => 'unqualified',
    ];

    /**
     * Run the complete migration
     * 
     * @param bool $dry_run If true, only report what would be done
     * @return array Migration results
     */
    public static function run($dry_run = true) {
        global $wpdb;

        $results = [
            'dry_run'                    => $dry_run,
            'version'                    => self::NEW_VERSION,
            'steps_completed'            => [],
            'errors'                     => [],
            'guests_modified'            => 0,
            'private_contacts_migrated'  => 0,
            'opportunities_created'      => 0,
            'engagements_created'        => 0,
            'speaking_credits_created'   => 0,
            'duplicates_found'           => 0,
        ];

        try {
            // Step 1: Modify pit_guests table (add claiming columns)
            $results['steps_completed'][] = self::step_1_modify_guests_table($dry_run);

            // Step 2: Create pit_guest_private_contacts table
            $results['steps_completed'][] = self::step_2_create_private_contacts_table($dry_run);

            // Step 3: Migrate private data from pit_guests to pit_guest_private_contacts
            $step3 = self::step_3_migrate_private_contacts($dry_run);
            $results['private_contacts_migrated'] = $step3['count'] ?? 0;
            $results['steps_completed'][] = $step3;

            // Step 4: Create pit_claim_requests table
            $results['steps_completed'][] = self::step_4_create_claim_requests_table($dry_run);

            // Step 5: Create pit_opportunities table
            $results['steps_completed'][] = self::step_5_create_opportunities_table($dry_run);

            // Step 6: Create pit_engagements table
            $results['steps_completed'][] = self::step_6_create_engagements_table($dry_run);

            // Step 7: Create pit_speaking_credits table
            $results['steps_completed'][] = self::step_7_create_speaking_credits_table($dry_run);

            // Step 8: Migrate pit_guest_appearances data
            $step8 = self::step_8_migrate_appearances_data($dry_run);
            $results['opportunities_created'] = $step8['opportunities'] ?? 0;
            $results['engagements_created'] = $step8['engagements'] ?? 0;
            $results['speaking_credits_created'] = $step8['speaking_credits'] ?? 0;
            $results['steps_completed'][] = $step8;

            // Step 9: Find and report duplicates (for manual review)
            $step9 = self::step_9_find_duplicates($dry_run);
            $results['duplicates_found'] = $step9['count'] ?? 0;
            $results['duplicate_groups'] = $step9['groups'] ?? [];
            $results['steps_completed'][] = $step9;

            // Update version if not dry run
            if (!$dry_run) {
                update_option('pit_db_version', self::NEW_VERSION);
            }

        } catch (Exception $e) {
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Step 1: Add claiming columns to pit_guests and rename user_id
     * Creates the table first if it doesn't exist.
     */
    private static function step_1_modify_guests_table($dry_run) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guests';

        // First check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;

        if (!$table_exists) {
            // Table doesn't exist - need to create it first
            if ($dry_run) {
                return [
                    'step' => 'step_1_modify_guests_table',
                    'status' => 'would_run',
                    'message' => 'Table pit_guests does not exist - will create it with new schema',
                ];
            }

            // Create the table with the new schema (including claiming columns)
            $created = self::create_guests_table_v4();
            
            return [
                'step' => 'step_1_modify_guests_table',
                'status' => $created ? 'completed' : 'failed',
                'message' => $created ? 'Created pit_guests table with v4 schema' : 'Failed to create pit_guests table',
                'error' => !$created ? $wpdb->last_error : null,
            ];
        }

        $changes = [];

        // Check if columns already exist
        $columns = $wpdb->get_col("SHOW COLUMNS FROM $table");

        // Add claimed_by_user_id if not exists
        if (!in_array('claimed_by_user_id', $columns)) {
            $changes[] = "ADD COLUMN claimed_by_user_id bigint(20) UNSIGNED DEFAULT NULL AFTER id";
        }

        // Add claim_status if not exists
        if (!in_array('claim_status', $columns)) {
            $changes[] = "ADD COLUMN claim_status ENUM('unclaimed', 'pending', 'verified', 'rejected') DEFAULT 'unclaimed' AFTER claimed_by_user_id";
        }

        // Add claim_verified_at if not exists
        if (!in_array('claim_verified_at', $columns)) {
            $changes[] = "ADD COLUMN claim_verified_at DATETIME DEFAULT NULL AFTER claim_status";
        }

        // Add claim_verification_method if not exists
        if (!in_array('claim_verification_method', $columns)) {
            $changes[] = "ADD COLUMN claim_verification_method VARCHAR(50) DEFAULT NULL AFTER claim_verified_at";
        }

        // Rename user_id to created_by_user_id if needed
        if (in_array('user_id', $columns) && !in_array('created_by_user_id', $columns)) {
            $changes[] = "CHANGE COLUMN user_id created_by_user_id bigint(20) UNSIGNED DEFAULT NULL";
        }

        // Add indexes
        $indexes = $wpdb->get_results("SHOW INDEX FROM $table WHERE Key_name = 'claimed_by_user_id_idx'");
        if (empty($indexes)) {
            $changes[] = "ADD KEY claimed_by_user_id_idx (claimed_by_user_id)";
        }

        $indexes = $wpdb->get_results("SHOW INDEX FROM $table WHERE Key_name = 'claim_status_idx'");
        if (empty($indexes)) {
            $changes[] = "ADD KEY claim_status_idx (claim_status)";
        }

        if (empty($changes)) {
            return [
                'step' => 'step_1_modify_guests_table',
                'status' => 'skipped',
                'message' => 'All columns already exist',
            ];
        }

        if ($dry_run) {
            return [
                'step' => 'step_1_modify_guests_table',
                'status' => 'would_run',
                'changes' => $changes,
                'sql' => "ALTER TABLE $table " . implode(", ", $changes),
            ];
        }

        // Execute changes
        $sql = "ALTER TABLE $table " . implode(", ", $changes);
        $result = $wpdb->query($sql);

        return [
            'step' => 'step_1_modify_guests_table',
            'status' => $result !== false ? 'completed' : 'failed',
            'changes' => $changes,
            'error' => $result === false ? $wpdb->last_error : null,
        ];
    }

    /**
     * Step 2: Create pit_guest_private_contacts table
     */
    private static function step_2_create_private_contacts_table($dry_run) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guest_private_contacts';
        $charset_collate = $wpdb->get_charset_collate();

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            return [
                'step' => 'step_2_create_private_contacts_table',
                'status' => 'skipped',
                'message' => 'Table already exists',
            ];
        }

        $sql = "CREATE TABLE $table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            
            -- Ownership
            user_id bigint(20) UNSIGNED NOT NULL,
            guest_id bigint(20) UNSIGNED NOT NULL,
            
            -- Private Contact Info
            personal_email VARCHAR(255) DEFAULT NULL,
            secondary_email VARCHAR(255) DEFAULT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            mobile_phone VARCHAR(50) DEFAULT NULL,
            assistant_name VARCHAR(255) DEFAULT NULL,
            assistant_email VARCHAR(255) DEFAULT NULL,
            assistant_phone VARCHAR(50) DEFAULT NULL,
            
            -- Private Notes
            private_notes TEXT DEFAULT NULL,
            relationship_notes TEXT DEFAULT NULL,
            last_contact_date DATE DEFAULT NULL,
            preferred_contact_method VARCHAR(50) DEFAULT NULL,
            
            -- Source Tracking
            source VARCHAR(100) DEFAULT NULL,
            verified_at DATETIME DEFAULT NULL,
            
            -- Timestamps
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            PRIMARY KEY (id),
            KEY user_id_idx (user_id),
            KEY guest_id_idx (guest_id),
            UNIQUE KEY user_guest_unique (user_id, guest_id)
        ) $charset_collate;";

        if ($dry_run) {
            return [
                'step' => 'step_2_create_private_contacts_table',
                'status' => 'would_run',
                'sql' => $sql,
            ];
        }

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        $created = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;

        return [
            'step' => 'step_2_create_private_contacts_table',
            'status' => $created ? 'completed' : 'failed',
            'error' => !$created ? $wpdb->last_error : null,
        ];
    }

    /**
     * Step 3: Migrate private contact data from pit_guests to pit_guest_private_contacts
     */
    private static function step_3_migrate_private_contacts($dry_run) {
        global $wpdb;

        $guests_table = $wpdb->prefix . 'pit_guests';
        $private_table = $wpdb->prefix . 'pit_guest_private_contacts';

        // Check if source columns exist
        $columns = $wpdb->get_col("SHOW COLUMNS FROM $guests_table");
        
        // Determine the user_id column name (might be renamed already)
        $user_col = in_array('created_by_user_id', $columns) ? 'created_by_user_id' : 'user_id';
        
        $has_personal_email = in_array('personal_email', $columns);
        $has_phone = in_array('phone', $columns);

        if (!$has_personal_email && !$has_phone) {
            return [
                'step' => 'step_3_migrate_private_contacts',
                'status' => 'skipped',
                'message' => 'No private contact columns to migrate',
                'count' => 0,
            ];
        }

        // Build the migration query
        $select_parts = [];
        if ($has_personal_email) {
            $select_parts[] = 'personal_email';
        }
        if ($has_phone) {
            $select_parts[] = 'phone';
        }

        // Count guests with private data
        $where_conditions = [];
        if ($has_personal_email) {
            $where_conditions[] = "personal_email IS NOT NULL AND personal_email != ''";
        }
        if ($has_phone) {
            $where_conditions[] = "phone IS NOT NULL AND phone != ''";
        }

        $where_clause = "(" . implode(" OR ", $where_conditions) . ") AND $user_col IS NOT NULL";

        $count = $wpdb->get_var("SELECT COUNT(*) FROM $guests_table WHERE $where_clause");

        if ($dry_run) {
            return [
                'step' => 'step_3_migrate_private_contacts',
                'status' => 'would_run',
                'count' => (int) $count,
                'message' => "Would migrate $count guest private contact records",
            ];
        }

        // Get existing private contacts to avoid duplicates
        $existing = $wpdb->get_col("SELECT CONCAT(user_id, '-', guest_id) FROM $private_table");

        // Get guests with private data
        $guests = $wpdb->get_results(
            "SELECT id, $user_col as user_id, " . implode(', ', $select_parts) . " 
             FROM $guests_table 
             WHERE $where_clause"
        );

        $migrated = 0;
        foreach ($guests as $guest) {
            $key = $guest->user_id . '-' . $guest->id;
            if (in_array($key, $existing)) {
                continue;
            }

            $data = [
                'user_id' => $guest->user_id,
                'guest_id' => $guest->id,
                'source' => 'migration_v4',
                'created_at' => current_time('mysql'),
            ];

            if ($has_personal_email && !empty($guest->personal_email)) {
                $data['personal_email'] = $guest->personal_email;
            }
            if ($has_phone && !empty($guest->phone)) {
                $data['phone'] = $guest->phone;
            }

            $wpdb->insert($private_table, $data);
            $migrated++;
        }

        return [
            'step' => 'step_3_migrate_private_contacts',
            'status' => 'completed',
            'count' => $migrated,
            'message' => "Migrated $migrated private contact records",
        ];
    }

    /**
     * Step 4: Create pit_claim_requests table
     */
    private static function step_4_create_claim_requests_table($dry_run) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_claim_requests';
        $charset_collate = $wpdb->get_charset_collate();

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            return [
                'step' => 'step_4_create_claim_requests_table',
                'status' => 'skipped',
                'message' => 'Table already exists',
            ];
        }

        $sql = "CREATE TABLE $table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            
            user_id bigint(20) UNSIGNED NOT NULL,
            guest_id bigint(20) UNSIGNED NOT NULL,
            
            status ENUM('pending', 'approved', 'rejected', 'auto_approved') DEFAULT 'pending',
            
            verification_method VARCHAR(50) DEFAULT NULL,
            verification_data TEXT DEFAULT NULL,
            
            reviewed_by_user_id bigint(20) UNSIGNED DEFAULT NULL,
            reviewed_at DATETIME DEFAULT NULL,
            review_notes TEXT DEFAULT NULL,
            rejection_reason TEXT DEFAULT NULL,
            
            claim_reason TEXT DEFAULT NULL,
            proof_url TEXT DEFAULT NULL,
            
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            PRIMARY KEY (id),
            KEY user_id_idx (user_id),
            KEY guest_id_idx (guest_id),
            KEY status_idx (status),
            UNIQUE KEY user_guest_unique (user_id, guest_id)
        ) $charset_collate;";

        if ($dry_run) {
            return [
                'step' => 'step_4_create_claim_requests_table',
                'status' => 'would_run',
                'sql' => $sql,
            ];
        }

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        $created = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;

        return [
            'step' => 'step_4_create_claim_requests_table',
            'status' => $created ? 'completed' : 'failed',
        ];
    }

    /**
     * Step 5: Create pit_opportunities table
     */
    private static function step_5_create_opportunities_table($dry_run) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_opportunities';
        $charset_collate = $wpdb->get_charset_collate();

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            return [
                'step' => 'step_5_create_opportunities_table',
                'status' => 'skipped',
                'message' => 'Table already exists',
            ];
        }

        $sql = "CREATE TABLE $table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            
            -- User Ownership
            user_id bigint(20) UNSIGNED NOT NULL,
            
            -- References
            guest_id bigint(20) UNSIGNED DEFAULT NULL,
            guest_profile_id bigint(20) UNSIGNED DEFAULT NULL,
            engagement_id bigint(20) UNSIGNED DEFAULT NULL,
            podcast_id bigint(20) UNSIGNED DEFAULT NULL,
            
            -- Legacy reference for migration tracking
            legacy_appearance_id bigint(20) UNSIGNED DEFAULT NULL,
            
            -- CRM Workflow
            status ENUM(
                'lead', 'researching', 'outreach', 'pitched', 'negotiating',
                'scheduled', 'recorded', 'editing', 'aired', 'promoted',
                'on_hold', 'cancelled', 'unqualified'
            ) DEFAULT 'lead',
            priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
            source VARCHAR(100) DEFAULT NULL,
            is_archived TINYINT(1) DEFAULT 0,
            
            -- Notes
            notes TEXT DEFAULT NULL,
            internal_notes TEXT DEFAULT NULL,
            
            -- Milestone Dates
            lead_date DATE DEFAULT NULL,
            outreach_date DATE DEFAULT NULL,
            response_date DATE DEFAULT NULL,
            pitch_date DATE DEFAULT NULL,
            scheduled_date DATE DEFAULT NULL,
            record_date DATE DEFAULT NULL,
            air_date DATE DEFAULT NULL,
            promotion_date DATE DEFAULT NULL,
            
            -- Business Metrics
            estimated_value DECIMAL(10,2) DEFAULT NULL,
            actual_value DECIMAL(10,2) DEFAULT NULL,
            commission DECIMAL(10,2) DEFAULT NULL,
            
            -- Timestamps
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            PRIMARY KEY (id),
            KEY user_id_idx (user_id),
            KEY guest_id_idx (guest_id),
            KEY engagement_id_idx (engagement_id),
            KEY podcast_id_idx (podcast_id),
            KEY status_idx (status),
            KEY priority_idx (priority),
            KEY is_archived_idx (is_archived),
            KEY legacy_appearance_id_idx (legacy_appearance_id)
        ) $charset_collate;";

        if ($dry_run) {
            return [
                'step' => 'step_5_create_opportunities_table',
                'status' => 'would_run',
                'sql' => $sql,
            ];
        }

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        $created = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;

        return [
            'step' => 'step_5_create_opportunities_table',
            'status' => $created ? 'completed' : 'failed',
        ];
    }

    /**
     * Step 6: Create pit_engagements table
     */
    private static function step_6_create_engagements_table($dry_run) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_engagements';
        $charset_collate = $wpdb->get_charset_collate();

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            return [
                'step' => 'step_6_create_engagements_table',
                'status' => 'skipped',
                'message' => 'Table already exists',
            ];
        }

        $sql = "CREATE TABLE $table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            
            -- Uniqueness Identification
            episode_guid VARCHAR(255) DEFAULT NULL,
            uniqueness_hash CHAR(32) DEFAULT NULL,
            canonical_url TEXT DEFAULT NULL,
            
            -- Engagement Type
            engagement_type ENUM(
                'podcast', 'youtube', 'webinar', 'conference', 'summit',
                'panel', 'interview', 'livestream', 'fireside_chat', 'workshop',
                'ama', 'roundtable', 'keynote', 'twitter_space', 'linkedin_live',
                'clubhouse', 'other'
            ) DEFAULT 'podcast',
            
            -- Platform Reference
            podcast_id bigint(20) UNSIGNED DEFAULT NULL,
            
            -- Details
            title VARCHAR(500) NOT NULL,
            description TEXT DEFAULT NULL,
            episode_number INT(11) DEFAULT NULL,
            season_number INT(11) DEFAULT NULL,
            
            -- URLs
            url TEXT DEFAULT NULL,
            embed_url TEXT DEFAULT NULL,
            audio_url TEXT DEFAULT NULL,
            video_url TEXT DEFAULT NULL,
            thumbnail_url TEXT DEFAULT NULL,
            transcript_url TEXT DEFAULT NULL,
            
            -- Timing
            engagement_date DATE DEFAULT NULL,
            published_date DATE DEFAULT NULL,
            duration_seconds INT(11) DEFAULT NULL,
            
            -- Content Analysis
            topics TEXT DEFAULT NULL,
            key_quotes TEXT DEFAULT NULL,
            summary TEXT DEFAULT NULL,
            ai_summary TEXT DEFAULT NULL,
            
            -- Event Info
            event_name VARCHAR(255) DEFAULT NULL,
            event_location VARCHAR(255) DEFAULT NULL,
            event_url TEXT DEFAULT NULL,
            
            -- Metrics
            view_count INT(11) DEFAULT NULL,
            like_count INT(11) DEFAULT NULL,
            comment_count INT(11) DEFAULT NULL,
            share_count INT(11) DEFAULT NULL,
            
            -- Verification
            is_verified TINYINT(1) DEFAULT 0,
            verified_by_user_id bigint(20) UNSIGNED DEFAULT NULL,
            verified_at DATETIME DEFAULT NULL,
            
            -- Discovery
            discovered_by_user_id bigint(20) UNSIGNED DEFAULT NULL,
            discovery_source VARCHAR(50) DEFAULT NULL,
            
            -- Timestamps
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            PRIMARY KEY (id),
            KEY engagement_type_idx (engagement_type),
            KEY podcast_id_idx (podcast_id),
            KEY engagement_date_idx (engagement_date),
            KEY published_date_idx (published_date),
            KEY is_verified_idx (is_verified),
            UNIQUE KEY episode_guid_unique (episode_guid),
            UNIQUE KEY uniqueness_hash_unique (uniqueness_hash)
        ) $charset_collate;";

        if ($dry_run) {
            return [
                'step' => 'step_6_create_engagements_table',
                'status' => 'would_run',
                'sql' => $sql,
            ];
        }

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        $created = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;

        return [
            'step' => 'step_6_create_engagements_table',
            'status' => $created ? 'completed' : 'failed',
        ];
    }

    /**
     * Step 7: Create pit_speaking_credits table
     */
    private static function step_7_create_speaking_credits_table($dry_run) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_speaking_credits';
        $charset_collate = $wpdb->get_charset_collate();

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            return [
                'step' => 'step_7_create_speaking_credits_table',
                'status' => 'skipped',
                'message' => 'Table already exists',
            ];
        }

        $sql = "CREATE TABLE $table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            
            guest_id bigint(20) UNSIGNED NOT NULL,
            engagement_id bigint(20) UNSIGNED NOT NULL,
            
            role ENUM(
                'guest', 'host', 'co_host', 'panelist', 'moderator',
                'speaker', 'interviewer', 'interviewee', 'contributor'
            ) DEFAULT 'guest',
            
            is_primary TINYINT(1) DEFAULT 1,
            credit_order TINYINT(4) DEFAULT 1,
            
            ai_confidence_score INT(11) DEFAULT 0,
            manually_verified TINYINT(1) DEFAULT 0,
            verified_by_user_id bigint(20) UNSIGNED DEFAULT NULL,
            verified_at DATETIME DEFAULT NULL,
            
            discovered_by_user_id bigint(20) UNSIGNED DEFAULT NULL,
            extraction_method VARCHAR(50) DEFAULT NULL,
            
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            
            PRIMARY KEY (id),
            KEY guest_id_idx (guest_id),
            KEY engagement_id_idx (engagement_id),
            KEY role_idx (role),
            UNIQUE KEY guest_engagement_role_unique (guest_id, engagement_id, role)
        ) $charset_collate;";

        if ($dry_run) {
            return [
                'step' => 'step_7_create_speaking_credits_table',
                'status' => 'would_run',
                'sql' => $sql,
            ];
        }

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        $created = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;

        return [
            'step' => 'step_7_create_speaking_credits_table',
            'status' => $created ? 'completed' : 'failed',
        ];
    }

    /**
     * Step 8: Migrate data from pit_guest_appearances to new tables
     */
    private static function step_8_migrate_appearances_data($dry_run) {
        global $wpdb;

        $appearances_table = $wpdb->prefix . 'pit_guest_appearances';
        $opportunities_table = $wpdb->prefix . 'pit_opportunities';
        $engagements_table = $wpdb->prefix . 'pit_engagements';
        $credits_table = $wpdb->prefix . 'pit_speaking_credits';

        // Check if source table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$appearances_table'") !== $appearances_table) {
            return [
                'step' => 'step_8_migrate_appearances_data',
                'status' => 'skipped',
                'message' => 'Source table pit_guest_appearances does not exist',
                'opportunities' => 0,
                'engagements' => 0,
                'speaking_credits' => 0,
            ];
        }

        // Get all appearances
        $appearances = $wpdb->get_results("SELECT * FROM $appearances_table ORDER BY id ASC");

        $stats = [
            'opportunities' => 0,
            'engagements' => 0,
            'speaking_credits' => 0,
        ];

        if ($dry_run) {
            // Count what would be created
            $total = count($appearances);
            $aired_count = 0;
            foreach ($appearances as $app) {
                if (in_array($app->status, ['aired', 'convert'])) {
                    $aired_count++;
                }
            }

            return [
                'step' => 'step_8_migrate_appearances_data',
                'status' => 'would_run',
                'opportunities' => $total,
                'engagements' => $aired_count,
                'speaking_credits' => $aired_count,
                'message' => "Would migrate $total appearances, create $aired_count engagements and $aired_count speaking credits",
            ];
        }

        // Check for already migrated (by legacy_appearance_id)
        $migrated_ids = $wpdb->get_col("SELECT legacy_appearance_id FROM $opportunities_table WHERE legacy_appearance_id IS NOT NULL");

        foreach ($appearances as $app) {
            // Skip if already migrated
            if (in_array($app->id, $migrated_ids)) {
                continue;
            }

            $engagement_id = null;

            // For aired/convert status, create engagement first
            if (in_array($app->status, ['aired', 'convert'])) {
                $engagement_id = self::create_engagement_from_appearance($app, $dry_run);
                if ($engagement_id) {
                    $stats['engagements']++;

                    // Create speaking credit if we have a guest_id
                    if ($app->guest_id) {
                        $credit_created = self::create_speaking_credit($app->guest_id, $engagement_id, $app, $dry_run);
                        if ($credit_created) {
                            $stats['speaking_credits']++;
                        }
                    }
                }
            }

            // Create opportunity (for all statuses)
            $opportunity_created = self::create_opportunity_from_appearance($app, $engagement_id, $dry_run);
            if ($opportunity_created) {
                $stats['opportunities']++;
            }
        }

        return [
            'step' => 'step_8_migrate_appearances_data',
            'status' => 'completed',
            'opportunities' => $stats['opportunities'],
            'engagements' => $stats['engagements'],
            'speaking_credits' => $stats['speaking_credits'],
        ];
    }

    /**
     * Create engagement record from appearance
     */
    private static function create_engagement_from_appearance($appearance, $dry_run) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_engagements';

        // Check if already exists by episode_guid
        if (!empty($appearance->episode_guid)) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE episode_guid = %s",
                $appearance->episode_guid
            ));
            if ($existing) {
                return (int) $existing;
            }
        }

        // Generate uniqueness hash
        $hash = self::generate_engagement_hash([
            'podcast_id' => $appearance->podcast_id,
            'engagement_date' => $appearance->episode_date ?: $appearance->air_date,
            'episode_number' => $appearance->episode_number,
            'title' => $appearance->episode_title,
        ]);

        // Check by hash
        if ($hash) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE uniqueness_hash = %s",
                $hash
            ));
            if ($existing) {
                return (int) $existing;
            }
        }

        if ($dry_run) {
            return 999999; // Fake ID for dry run
        }

        $data = [
            'episode_guid' => $appearance->episode_guid ?: null,
            'uniqueness_hash' => $hash,
            'engagement_type' => 'podcast',
            'podcast_id' => $appearance->podcast_id,
            'title' => $appearance->episode_title ?: 'Untitled Episode',
            'episode_number' => $appearance->episode_number,
            'url' => $appearance->episode_url,
            'engagement_date' => $appearance->episode_date ?: $appearance->air_date,
            'published_date' => $appearance->air_date,
            'duration_seconds' => $appearance->episode_duration,
            'topics' => $appearance->topics_discussed,
            'key_quotes' => $appearance->key_quotes,
            'is_verified' => $appearance->manually_verified,
            'discovered_by_user_id' => $appearance->user_id,
            'discovery_source' => 'migration_v4',
            'created_at' => $appearance->created_at ?: current_time('mysql'),
        ];

        $wpdb->insert($table, $data);

        return $wpdb->insert_id ?: null;
    }

    /**
     * Create speaking credit
     */
    private static function create_speaking_credit($guest_id, $engagement_id, $appearance, $dry_run) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_speaking_credits';

        // Determine role
        $role = $appearance->is_host ? 'host' : 'guest';

        // Check if exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE guest_id = %d AND engagement_id = %d AND role = %s",
            $guest_id, $engagement_id, $role
        ));

        if ($existing) {
            return false;
        }

        if ($dry_run) {
            return true;
        }

        $data = [
            'guest_id' => $guest_id,
            'engagement_id' => $engagement_id,
            'role' => $role,
            'is_primary' => 1,
            'ai_confidence_score' => $appearance->ai_confidence_score ?: 0,
            'manually_verified' => $appearance->manually_verified,
            'discovered_by_user_id' => $appearance->user_id,
            'extraction_method' => 'migration_v4',
            'created_at' => $appearance->created_at ?: current_time('mysql'),
        ];

        return $wpdb->insert($table, $data) !== false;
    }

    /**
     * Create opportunity from appearance
     */
    private static function create_opportunity_from_appearance($appearance, $engagement_id, $dry_run) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_opportunities';

        if ($dry_run) {
            return true;
        }

        // Map old status to new
        $new_status = self::STATUS_MAPPING[$appearance->status] ?? 'lead';

        $data = [
            'user_id' => $appearance->user_id,
            'guest_id' => $appearance->guest_id,
            'guest_profile_id' => $appearance->guest_profile_id,
            'engagement_id' => $engagement_id,
            'podcast_id' => $appearance->podcast_id,
            'legacy_appearance_id' => $appearance->id,
            'status' => $new_status,
            'priority' => $appearance->priority ?: 'medium',
            'source' => $appearance->source,
            'is_archived' => $appearance->is_archived,
            'record_date' => $appearance->record_date,
            'air_date' => $appearance->air_date,
            'promotion_date' => $appearance->promotion_date,
            'created_at' => $appearance->created_at ?: current_time('mysql'),
            'updated_at' => $appearance->updated_at ?: current_time('mysql'),
        ];

        return $wpdb->insert($table, $data) !== false;
    }

    /**
     * Generate uniqueness hash for engagement
     */
    private static function generate_engagement_hash($data) {
        if (!empty($data['podcast_id']) && !empty($data['engagement_date'])) {
            return md5(sprintf('podcast:%d|date:%s|ep:%s',
                $data['podcast_id'],
                $data['engagement_date'],
                $data['episode_number'] ?? ''
            ));
        }

        if (!empty($data['title']) && !empty($data['engagement_date'])) {
            return md5(sprintf('title:%s|date:%s',
                strtolower(trim($data['title'])),
                $data['engagement_date']
            ));
        }

        return null;
    }

    /**
     * Create pit_guests table with v4 schema (including claiming columns)
     */
    private static function create_guests_table_v4() {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guests';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,

            -- Identity Claiming (v4)
            claimed_by_user_id bigint(20) UNSIGNED DEFAULT NULL,
            claim_status ENUM('unclaimed', 'pending', 'verified', 'rejected') DEFAULT 'unclaimed',
            claim_verified_at DATETIME DEFAULT NULL,
            claim_verification_method VARCHAR(50) DEFAULT NULL,

            -- Provenance (v4 renamed from user_id)
            created_by_user_id bigint(20) UNSIGNED DEFAULT NULL,

            -- Identity
            full_name varchar(255) NOT NULL,
            first_name varchar(100) DEFAULT NULL,
            last_name varchar(100) DEFAULT NULL,

            -- Deduplication Keys
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

            -- Public Contact (Private contact goes to pit_guest_private_contacts)
            twitter_handle varchar(100) DEFAULT NULL,
            instagram_handle varchar(100) DEFAULT NULL,
            youtube_channel varchar(255) DEFAULT NULL,
            website_url text DEFAULT NULL,

            -- Social Proof
            linkedin_connections int(11) DEFAULT NULL,
            twitter_followers int(11) DEFAULT NULL,
            instagram_followers int(11) DEFAULT NULL,
            youtube_subscribers int(11) DEFAULT NULL,
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
            is_verified tinyint(1) DEFAULT 0,
            verification_count int(11) DEFAULT 0,
            last_verified_by_user_id bigint(20) UNSIGNED DEFAULT NULL,
            last_verified_at datetime DEFAULT NULL,
            verification_notes text DEFAULT NULL,

            -- Deduplication
            is_merged tinyint(1) DEFAULT 0,
            merged_into_guest_id bigint(20) UNSIGNED DEFAULT NULL,
            merge_history text DEFAULT NULL,

            -- Source
            discovery_source varchar(50) DEFAULT NULL,
            source_podcast_id bigint(20) UNSIGNED DEFAULT NULL,

            -- Timestamps
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            KEY claimed_by_user_id_idx (claimed_by_user_id),
            KEY claim_status_idx (claim_status),
            KEY created_by_user_id_idx (created_by_user_id),
            KEY full_name_idx (full_name),
            KEY email_hash_idx (email_hash),
            KEY linkedin_url_hash_idx (linkedin_url_hash),
            KEY current_company_idx (current_company),
            KEY is_verified_idx (is_verified),
            KEY is_merged_idx (is_merged)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        return $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
    }

    /**
     * Step 9: Find duplicate guests for manual review
     */
    private static function step_9_find_duplicates($dry_run) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guests';

        $groups = [];

        // Find duplicates by email_hash
        $email_dupes = $wpdb->get_results(
            "SELECT email_hash, GROUP_CONCAT(id ORDER BY data_quality_score DESC) as ids, COUNT(*) as cnt
             FROM $table
             WHERE email_hash IS NOT NULL AND email_hash != '' AND (is_merged IS NULL OR is_merged = 0)
             GROUP BY email_hash
             HAVING cnt > 1"
        );

        foreach ($email_dupes as $dupe) {
            $groups[] = [
                'type' => 'email',
                'hash' => $dupe->email_hash,
                'ids' => $dupe->ids,
                'count' => $dupe->cnt,
            ];
        }

        // Find duplicates by linkedin_url_hash
        $linkedin_dupes = $wpdb->get_results(
            "SELECT linkedin_url_hash, GROUP_CONCAT(id ORDER BY data_quality_score DESC) as ids, COUNT(*) as cnt
             FROM $table
             WHERE linkedin_url_hash IS NOT NULL AND linkedin_url_hash != '' AND (is_merged IS NULL OR is_merged = 0)
             GROUP BY linkedin_url_hash
             HAVING cnt > 1"
        );

        foreach ($linkedin_dupes as $dupe) {
            $groups[] = [
                'type' => 'linkedin',
                'hash' => $dupe->linkedin_url_hash,
                'ids' => $dupe->ids,
                'count' => $dupe->cnt,
            ];
        }

        return [
            'step' => 'step_9_find_duplicates',
            'status' => 'completed',
            'count' => count($groups),
            'groups' => $groups,
            'message' => count($groups) . ' duplicate groups found for manual review',
        ];
    }

    /**
     * Get migration status
     */
    public static function get_status() {
        global $wpdb;

        $status = [
            'current_version' => get_option('pit_db_version', 'unknown'),
            'target_version' => self::NEW_VERSION,
            'needs_migration' => version_compare(get_option('pit_db_version', '0'), self::NEW_VERSION, '<'),
            'tables' => [],
        ];

        // Check each table
        $tables_to_check = [
            'pit_guests' => ['claimed_by_user_id', 'claim_status'],
            'pit_guest_private_contacts' => null,
            'pit_claim_requests' => null,
            'pit_opportunities' => null,
            'pit_engagements' => null,
            'pit_speaking_credits' => null,
        ];

        foreach ($tables_to_check as $table => $columns) {
            $full_table = $wpdb->prefix . $table;
            $exists = $wpdb->get_var("SHOW TABLES LIKE '$full_table'") === $full_table;

            $table_status = [
                'exists' => $exists,
                'row_count' => 0,
            ];

            if ($exists) {
                $table_status['row_count'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $full_table");

                if ($columns) {
                    $existing_cols = $wpdb->get_col("SHOW COLUMNS FROM $full_table");
                    $table_status['has_new_columns'] = count(array_intersect($columns, $existing_cols)) === count($columns);
                }
            }

            $status['tables'][$table] = $table_status;
        }

        // Count appearances to migrate
        $appearances_table = $wpdb->prefix . 'pit_guest_appearances';
        if ($wpdb->get_var("SHOW TABLES LIKE '$appearances_table'") === $appearances_table) {
            $status['appearances_to_migrate'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $appearances_table");
        }

        return $status;
    }

    /**
     * Rollback migration (for emergencies)
     */
    public static function rollback($dry_run = true) {
        global $wpdb;

        $results = [
            'dry_run' => $dry_run,
            'actions' => [],
        ];

        $tables_to_drop = [
            'pit_guest_private_contacts',
            'pit_claim_requests',
            'pit_opportunities',
            'pit_engagements',
            'pit_speaking_credits',
        ];

        foreach ($tables_to_drop as $table) {
            $full_table = $wpdb->prefix . $table;
            $exists = $wpdb->get_var("SHOW TABLES LIKE '$full_table'") === $full_table;

            if ($exists) {
                if ($dry_run) {
                    $results['actions'][] = "Would drop table: $full_table";
                } else {
                    $wpdb->query("DROP TABLE IF EXISTS $full_table");
                    $results['actions'][] = "Dropped table: $full_table";
                }
            }
        }

        // Revert pit_guests columns
        $guests_table = $wpdb->prefix . 'pit_guests';
        $columns = $wpdb->get_col("SHOW COLUMNS FROM $guests_table");

        if (in_array('created_by_user_id', $columns) && !in_array('user_id', $columns)) {
            if ($dry_run) {
                $results['actions'][] = "Would rename created_by_user_id back to user_id";
            } else {
                $wpdb->query("ALTER TABLE $guests_table CHANGE COLUMN created_by_user_id user_id bigint(20) UNSIGNED DEFAULT NULL");
                $results['actions'][] = "Renamed created_by_user_id back to user_id";
            }
        }

        $claim_columns = ['claimed_by_user_id', 'claim_status', 'claim_verified_at', 'claim_verification_method'];
        foreach ($claim_columns as $col) {
            if (in_array($col, $columns)) {
                if ($dry_run) {
                    $results['actions'][] = "Would drop column: $col";
                } else {
                    $wpdb->query("ALTER TABLE $guests_table DROP COLUMN $col");
                    $results['actions'][] = "Dropped column: $col";
                }
            }
        }

        return $results;
    }
}
