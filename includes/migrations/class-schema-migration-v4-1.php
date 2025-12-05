<?php
/**
 * Migration v4.1: Add stage_id foreign key to opportunities
 * 
 * This migration adds stage_id column to pit_opportunities table,
 * replacing the hardcoded status string with a reference to pit_pipeline_stages.
 * 
 * Benefits:
 * - Referential integrity between opportunities and pipeline stages
 * - Supports user-customizable pipeline stages
 * - Status name changes don't break existing records
 *
 * @package PodcastInfluenceTracker
 * @since 4.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Schema_Migration_V4_1 {

    /**
     * New database version after migration
     */
    const NEW_VERSION = '4.1.0';

    /**
     * Run the complete migration
     * 
     * @param bool $dry_run If true, only report what would be done
     * @return array Migration results
     */
    public static function run($dry_run = true) {
        global $wpdb;

        $results = [
            'dry_run'             => $dry_run,
            'version'             => self::NEW_VERSION,
            'steps_completed'     => [],
            'errors'              => [],
            'records_updated'     => 0,
        ];

        try {
            // Step 1: Add stage_id column to pit_opportunities
            $results['steps_completed'][] = self::step_1_add_stage_id_column($dry_run);

            // Step 2: Backfill stage_id from existing status values
            $step2 = self::step_2_backfill_stage_ids($dry_run);
            $results['records_updated'] = $step2['count'] ?? 0;
            $results['steps_completed'][] = $step2;

            // Step 3: Add index on stage_id
            $results['steps_completed'][] = self::step_3_add_stage_id_index($dry_run);

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
     * Step 1: Add stage_id column to pit_opportunities
     */
    private static function step_1_add_stage_id_column($dry_run) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_opportunities';

        // Check if column already exists
        $columns = $wpdb->get_col("SHOW COLUMNS FROM $table");

        if (in_array('stage_id', $columns)) {
            return [
                'step'    => 'step_1_add_stage_id_column',
                'status'  => 'skipped',
                'message' => 'Column stage_id already exists',
            ];
        }

        if ($dry_run) {
            return [
                'step'   => 'step_1_add_stage_id_column',
                'status' => 'would_run',
                'sql'    => "ALTER TABLE $table ADD COLUMN stage_id bigint(20) UNSIGNED DEFAULT NULL AFTER status",
            ];
        }

        // Add column after status
        $result = $wpdb->query(
            "ALTER TABLE $table ADD COLUMN stage_id bigint(20) UNSIGNED DEFAULT NULL AFTER status"
        );

        return [
            'step'   => 'step_1_add_stage_id_column',
            'status' => $result !== false ? 'completed' : 'failed',
            'error'  => $result === false ? $wpdb->last_error : null,
        ];
    }

    /**
     * Step 2: Backfill stage_id from existing status values
     */
    private static function step_2_backfill_stage_ids($dry_run) {
        global $wpdb;
        
        $opportunities_table = $wpdb->prefix . 'pit_opportunities';
        $stages_table = $wpdb->prefix . 'pit_pipeline_stages';

        // Get all system stages (user_id IS NULL)
        $stages = $wpdb->get_results(
            "SELECT id, stage_key FROM $stages_table WHERE user_id IS NULL OR is_system = 1",
            OBJECT_K
        );

        // Build stage_key => id mapping
        $stage_map = [];
        foreach ($stages as $stage) {
            $stage_map[$stage->stage_key] = $stage->id;
        }

        // Count records needing update
        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM $opportunities_table WHERE stage_id IS NULL AND status IS NOT NULL"
        );

        if ($dry_run) {
            return [
                'step'    => 'step_2_backfill_stage_ids',
                'status'  => $count > 0 ? 'would_run' : 'skipped',
                'count'   => (int) $count,
                'message' => "Would update $count opportunities with stage_id",
                'mapping' => $stage_map,
            ];
        }

        // Update each status to its corresponding stage_id
        $updated = 0;
        foreach ($stage_map as $stage_key => $stage_id) {
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE $opportunities_table SET stage_id = %d WHERE status = %s AND stage_id IS NULL",
                $stage_id,
                $stage_key
            ));
            if ($result > 0) {
                $updated += $result;
            }
        }

        return [
            'step'    => 'step_2_backfill_stage_ids',
            'status'  => 'completed',
            'count'   => $updated,
            'message' => "Updated $updated opportunities with stage_id",
        ];
    }

    /**
     * Step 3: Add index on stage_id column
     */
    private static function step_3_add_stage_id_index($dry_run) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_opportunities';

        // Check if index already exists
        $indexes = $wpdb->get_results("SHOW INDEX FROM $table WHERE Key_name = 'stage_id_idx'");

        if (!empty($indexes)) {
            return [
                'step'    => 'step_3_add_stage_id_index',
                'status'  => 'skipped',
                'message' => 'Index stage_id_idx already exists',
            ];
        }

        if ($dry_run) {
            return [
                'step'   => 'step_3_add_stage_id_index',
                'status' => 'would_run',
                'sql'    => "ALTER TABLE $table ADD KEY stage_id_idx (stage_id)",
            ];
        }

        $result = $wpdb->query("ALTER TABLE $table ADD KEY stage_id_idx (stage_id)");

        return [
            'step'   => 'step_3_add_stage_id_index',
            'status' => $result !== false ? 'completed' : 'failed',
            'error'  => $result === false ? $wpdb->last_error : null,
        ];
    }

    /**
     * Get the default stage ID for new opportunities
     * 
     * @param int|null $user_id Optional user ID for user-specific stages
     * @return int|null Stage ID or null if not found
     */
    public static function get_default_stage_id($user_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_pipeline_stages';

        // First try user-specific stage with sort_order = 1
        if ($user_id) {
            $stage_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table 
                 WHERE user_id = %d AND is_active = 1 
                 ORDER BY sort_order ASC LIMIT 1",
                $user_id
            ));
            if ($stage_id) {
                return (int) $stage_id;
            }
        }

        // Fall back to system default (sort_order = 1, is_system = 1)
        $stage_id = $wpdb->get_var(
            "SELECT id FROM $table 
             WHERE (user_id IS NULL OR is_system = 1) AND is_active = 1 
             ORDER BY sort_order ASC LIMIT 1"
        );

        return $stage_id ? (int) $stage_id : null;
    }

    /**
     * Get stage ID by stage_key
     * 
     * @param string   $stage_key The stage key (e.g., 'potential', 'active')
     * @param int|null $user_id   Optional user ID for user-specific stages
     * @return int|null Stage ID or null if not found
     */
    public static function get_stage_id_by_key($stage_key, $user_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_pipeline_stages';

        // First try user-specific stage
        if ($user_id) {
            $stage_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE user_id = %d AND stage_key = %s AND is_active = 1",
                $user_id,
                $stage_key
            ));
            if ($stage_id) {
                return (int) $stage_id;
            }
        }

        // Fall back to system stage
        $stage_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table 
             WHERE (user_id IS NULL OR is_system = 1) AND stage_key = %s AND is_active = 1",
            $stage_key
        ));

        return $stage_id ? (int) $stage_id : null;
    }

    /**
     * Get migration status
     */
    public static function get_status() {
        global $wpdb;

        $current_version = get_option('pit_db_version', 'unknown');
        $opportunities_table = $wpdb->prefix . 'pit_opportunities';

        // Check if stage_id column exists
        $columns = $wpdb->get_col("SHOW COLUMNS FROM $opportunities_table");
        $has_stage_id = in_array('stage_id', $columns);

        // Count records with/without stage_id
        $total = 0;
        $with_stage_id = 0;
        $without_stage_id = 0;

        if ($has_stage_id) {
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $opportunities_table");
            $with_stage_id = (int) $wpdb->get_var("SELECT COUNT(*) FROM $opportunities_table WHERE stage_id IS NOT NULL");
            $without_stage_id = $total - $with_stage_id;
        }

        return [
            'current_version'   => $current_version,
            'target_version'    => self::NEW_VERSION,
            'needs_migration'   => !$has_stage_id || $without_stage_id > 0,
            'has_stage_id_col'  => $has_stage_id,
            'total_records'     => $total,
            'with_stage_id'     => $with_stage_id,
            'without_stage_id'  => $without_stage_id,
        ];
    }
}
