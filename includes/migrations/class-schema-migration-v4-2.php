<?php
/**
 * Migration v4.2: Add is_featured column to speaking_credits
 *
 * This migration adds is_featured and featured_at columns to pit_speaking_credits table,
 * allowing users to mark specific portfolio items as featured for their media kit.
 *
 * @package PodcastInfluenceTracker
 * @since 4.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Schema_Migration_V4_2 {

    /**
     * New database version after migration
     */
    const NEW_VERSION = '4.2.0';

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
        ];

        try {
            // Step 1: Add is_featured column to pit_speaking_credits
            $results['steps_completed'][] = self::step_1_add_is_featured_column($dry_run);

            // Step 2: Add featured_at column to pit_speaking_credits
            $results['steps_completed'][] = self::step_2_add_featured_at_column($dry_run);

            // Step 3: Add index on is_featured
            $results['steps_completed'][] = self::step_3_add_is_featured_index($dry_run);

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
     * Step 1: Add is_featured column to pit_speaking_credits
     */
    private static function step_1_add_is_featured_column($dry_run) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_speaking_credits';

        // Check if column already exists
        $columns = $wpdb->get_col("SHOW COLUMNS FROM $table");

        if (in_array('is_featured', $columns)) {
            return [
                'step'    => 'step_1_add_is_featured_column',
                'status'  => 'skipped',
                'message' => 'Column is_featured already exists',
            ];
        }

        if ($dry_run) {
            return [
                'step'   => 'step_1_add_is_featured_column',
                'status' => 'would_run',
                'sql'    => "ALTER TABLE $table ADD COLUMN is_featured TINYINT(1) DEFAULT 0 AFTER extraction_method",
            ];
        }

        // Add column after extraction_method
        $result = $wpdb->query(
            "ALTER TABLE $table ADD COLUMN is_featured TINYINT(1) DEFAULT 0 AFTER extraction_method"
        );

        return [
            'step'   => 'step_1_add_is_featured_column',
            'status' => $result !== false ? 'completed' : 'failed',
            'error'  => $result === false ? $wpdb->last_error : null,
        ];
    }

    /**
     * Step 2: Add featured_at column to pit_speaking_credits
     */
    private static function step_2_add_featured_at_column($dry_run) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_speaking_credits';

        // Check if column already exists
        $columns = $wpdb->get_col("SHOW COLUMNS FROM $table");

        if (in_array('featured_at', $columns)) {
            return [
                'step'    => 'step_2_add_featured_at_column',
                'status'  => 'skipped',
                'message' => 'Column featured_at already exists',
            ];
        }

        if ($dry_run) {
            return [
                'step'   => 'step_2_add_featured_at_column',
                'status' => 'would_run',
                'sql'    => "ALTER TABLE $table ADD COLUMN featured_at DATETIME DEFAULT NULL AFTER is_featured",
            ];
        }

        // Add column after is_featured
        $result = $wpdb->query(
            "ALTER TABLE $table ADD COLUMN featured_at DATETIME DEFAULT NULL AFTER is_featured"
        );

        return [
            'step'   => 'step_2_add_featured_at_column',
            'status' => $result !== false ? 'completed' : 'failed',
            'error'  => $result === false ? $wpdb->last_error : null,
        ];
    }

    /**
     * Step 3: Add index on is_featured column
     */
    private static function step_3_add_is_featured_index($dry_run) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_speaking_credits';

        // Check if index already exists
        $indexes = $wpdb->get_results("SHOW INDEX FROM $table WHERE Key_name = 'is_featured_idx'");

        if (!empty($indexes)) {
            return [
                'step'    => 'step_3_add_is_featured_index',
                'status'  => 'skipped',
                'message' => 'Index is_featured_idx already exists',
            ];
        }

        if ($dry_run) {
            return [
                'step'   => 'step_3_add_is_featured_index',
                'status' => 'would_run',
                'sql'    => "ALTER TABLE $table ADD KEY is_featured_idx (is_featured)",
            ];
        }

        $result = $wpdb->query("ALTER TABLE $table ADD KEY is_featured_idx (is_featured)");

        return [
            'step'   => 'step_3_add_is_featured_index',
            'status' => $result !== false ? 'completed' : 'failed',
            'error'  => $result === false ? $wpdb->last_error : null,
        ];
    }

    /**
     * Get migration status
     */
    public static function get_status() {
        global $wpdb;

        $current_version = get_option('pit_db_version', 'unknown');
        $credits_table = $wpdb->prefix . 'pit_speaking_credits';

        // Check if columns exist
        $columns = $wpdb->get_col("SHOW COLUMNS FROM $credits_table");
        $has_is_featured = in_array('is_featured', $columns);
        $has_featured_at = in_array('featured_at', $columns);

        // Count featured records if column exists
        $total_featured = 0;
        if ($has_is_featured) {
            $total_featured = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM $credits_table WHERE is_featured = 1"
            );
        }

        return [
            'current_version'   => $current_version,
            'target_version'    => self::NEW_VERSION,
            'needs_migration'   => !$has_is_featured || !$has_featured_at,
            'has_is_featured'   => $has_is_featured,
            'has_featured_at'   => $has_featured_at,
            'total_featured'    => $total_featured,
        ];
    }
}
