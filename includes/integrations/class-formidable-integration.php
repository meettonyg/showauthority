<?php
/**
 * Formidable Forms Integration
 *
 * Syncs Interview Tracker form entries with the podcast database.
 * Uses a separate link table (pit_formidable_podcast_links) for many-to-one relationships.
 * Multiple users can track the same podcast from different Formidable entries.
 *
 * @package PodcastInfluenceTracker
 * @subpackage Integrations
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Formidable_Integration {

    /**
     * Initialize integration
     */
    public static function init() {
        // Hook into Formidable form submissions
        add_action('frm_after_create_entry', [__CLASS__, 'sync_entry'], 30, 2);
        add_action('frm_after_update_entry', [__CLASS__, 'sync_entry'], 30, 2);

        // Bulk sync action (via admin or cron)
        add_action('pit_formidable_bulk_sync', [__CLASS__, 'bulk_sync_entries']);

        // Schedule daily sync if not already scheduled
        if (!wp_next_scheduled('pit_formidable_bulk_sync')) {
            wp_schedule_event(time(), 'daily', 'pit_formidable_bulk_sync');
        }
    }

    /**
     * Sync a single Formidable entry to podcasts table
     *
     * @param int $entry_id Formidable entry ID
     * @param int $form_id Formidable form ID
     */
    public static function sync_entry($entry_id, $form_id) {
        // Get configured form ID from settings
        $tracker_form_id = PIT_Settings::get('tracker_form_id', 0);

        if (!$tracker_form_id || (int) $form_id !== (int) $tracker_form_id) {
            return; // Not the interview tracker form
        }

        // Get RSS field configuration
        $rss_field_id = PIT_Settings::get('rss_field_id', '');

        if (!$rss_field_id) {
            self::log_sync_error($entry_id, 'RSS field not configured');
            return;
        }

        // Extract RSS URL from entry
        $rss_url = self::get_entry_field_value($entry_id, $rss_field_id);
        
        if (!$rss_url || !filter_var($rss_url, FILTER_VALIDATE_URL)) {
            self::log_sync_error($entry_id, 'Invalid or missing RSS URL: ' . ($rss_url ?: '(empty)'));
            return;
        }

        try {
            // Find or create podcast
            $podcast_id = self::find_or_create_podcast($rss_url);

            if (!$podcast_id) {
                self::log_sync_error($entry_id, 'Failed to find or create podcast for RSS: ' . $rss_url);
                return;
            }

            // Create/update link between entry and podcast
            self::link_entry_to_podcast($entry_id, $podcast_id, $rss_url);

            // Queue discovery job if not already processed
            self::maybe_queue_discovery($podcast_id);

            do_action('pit_formidable_entry_synced', $podcast_id, $entry_id);

        } catch (Exception $e) {
            self::log_sync_error($entry_id, $e->getMessage());
        }
    }

    /**
     * Find existing podcast or create new one from RSS
     *
     * @param string $rss_url
     * @return int|false Podcast ID
     */
    private static function find_or_create_podcast($rss_url) {
        // Check if podcast already exists by RSS URL
        $existing = PIT_Podcast_Repository::get_by_rss($rss_url);

        if ($existing) {
            error_log("PIT Formidable: Found existing podcast ID {$existing->id} for RSS: $rss_url");
            return $existing->id;
        }

        // Create new podcast using Discovery Engine
        if (class_exists('PIT_Discovery_Engine')) {
            try {
                $result = PIT_Discovery_Engine::discover_from_rss($rss_url);

                // Check if result is a WP_Error
                if (is_wp_error($result)) {
                    error_log('PIT Formidable Integration - Discovery failed: ' . $result->get_error_message());
                    // Fall through to fallback below
                } elseif (is_array($result) && !empty($result['podcast_id'])) {
                    error_log("PIT Formidable: Created podcast ID {$result['podcast_id']} via Discovery Engine");
                    return $result['podcast_id'];
                }
            } catch (Exception $e) {
                error_log('PIT Formidable Integration - Discovery exception: ' . $e->getMessage());
            }
        }

        // Fallback: Create basic podcast entry manually
        error_log("PIT Formidable: Using fallback for RSS: $rss_url");
        
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcasts';
        
        // Check table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if (!$table_exists) {
            error_log("PIT Formidable: podcasts table does not exist!");
            return false;
        }

        $podcast_data = [
            'title'           => self::extract_podcast_name_from_url($rss_url),
            'rss_feed_url'    => $rss_url,
            'tracking_status' => 'not_tracked',
            'source'          => 'formidable_import',
            'created_at'      => current_time('mysql'),
            'updated_at'      => current_time('mysql'),
        ];
        
        // Generate unique slug
        $base_slug = sanitize_title($podcast_data['title']);
        $slug = $base_slug;
        $counter = 1;
        while ($wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE slug = %s", $slug))) {
            $slug = $base_slug . '-' . $counter;
            $counter++;
        }
        $podcast_data['slug'] = $slug;

        $result = $wpdb->insert($table, $podcast_data);
        
        if ($result === false) {
            error_log("PIT Formidable: Failed to insert podcast - DB Error: " . $wpdb->last_error);
            return false;
        }
        
        $podcast_id = $wpdb->insert_id;
        error_log("PIT Formidable: Created podcast ID $podcast_id via fallback");
        
        return $podcast_id ?: false;
    }

    /**
     * Create or update link between Formidable entry and podcast
     *
     * @param int $entry_id
     * @param int $podcast_id
     * @param string $rss_url
     */
    private static function link_entry_to_podcast($entry_id, $podcast_id, $rss_url) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_formidable_podcast_links';

        // Check if table exists first
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if (!$table_exists) {
            error_log('PIT: pit_formidable_podcast_links table does not exist. Please deactivate and reactivate the plugin.');
            return;
        }

        $user_id = get_current_user_id() ?: 0;

        // Check if link already exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table WHERE formidable_entry_id = %d",
            $entry_id
        ));

        $data = [
            'formidable_entry_id' => $entry_id,
            'podcast_id'          => $podcast_id,
            'user_id'             => $user_id,
            'synced_at'           => current_time('mysql'),
            'sync_status'         => 'synced',
            'sync_error'          => null,
            'rss_url_at_sync'     => $rss_url,
            'updated_at'          => current_time('mysql'),
        ];

        if ($existing) {
            $wpdb->update($table, $data, ['id' => $existing->id]);
        } else {
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($table, $data);
        }
    }

    /**
     * Log sync error for an entry
     *
     * @param int $entry_id
     * @param string $error_message
     */
    private static function log_sync_error($entry_id, $error_message) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_formidable_podcast_links';

        // Check if table exists first
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if (!$table_exists) {
            error_log("PIT Formidable Sync Error [Entry $entry_id]: $error_message (Note: link table doesn't exist)");
            return;
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table WHERE formidable_entry_id = %d",
            $entry_id
        ));

        $data = [
            'sync_status' => 'failed',
            'sync_error'  => $error_message,
            'updated_at'  => current_time('mysql'),
        ];

        if ($existing) {
            $wpdb->update($table, $data, ['id' => $existing->id]);
        } else {
            $data['formidable_entry_id'] = $entry_id;
            $data['user_id'] = get_current_user_id() ?: 0;
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($table, $data);
        }

        error_log("PIT Formidable Sync Error [Entry $entry_id]: $error_message");
    }

    /**
     * Queue discovery job if podcast hasn't been processed
     *
     * @param int $podcast_id
     */
    private static function maybe_queue_discovery($podcast_id) {
        // Check if podcast has already been enriched
        $podcast = PIT_Podcast_Repository::get($podcast_id);

        if (!$podcast) {
            return;
        }

        // Skip if already discovered or tracked
        if (!empty($podcast->social_links_discovered) || $podcast->tracking_status === 'tracked') {
            return;
        }

        // Check if job already queued
        if (class_exists('PIT_Job_Queue') && method_exists('PIT_Job_Queue', 'has_pending_job')) {
            if (PIT_Job_Queue::has_pending_job($podcast_id, 'initial_tracking')) {
                return;
            }
        }

        // Queue enrichment job
        $user_id = get_current_user_id() ?: 1;

        if (class_exists('PIT_Job_Queue') && method_exists('PIT_Job_Queue', 'add')) {
            PIT_Job_Queue::add([
                'user_id'            => $user_id,
                'podcast_id'         => $podcast_id,
                'job_type'           => 'initial_tracking',
                'platforms_to_fetch' => json_encode(['twitter', 'instagram', 'youtube', 'linkedin', 'facebook']),
                'status'             => 'queued',
                'priority'           => 50,
            ]);
        }
    }

    /**
     * Bulk sync all entries from the Interview Tracker form
     */
    public static function bulk_sync_entries() {
        $tracker_form_id = PIT_Settings::get('tracker_form_id', 0);

        if (!$tracker_form_id) {
            return 0;
        }

        // Get all entries for this form
        global $wpdb;
        $table = $wpdb->prefix . 'frm_items';

        $entries = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM $table WHERE form_id = %d AND is_draft = 0",
            $tracker_form_id
        ));

        $synced = 0;
        foreach ($entries as $entry) {
            self::sync_entry($entry->id, $tracker_form_id);
            $synced++;
        }

        return $synced;
    }

    /**
     * Get podcast ID for a Formidable entry
     *
     * @param int $entry_id
     * @return int|null
     */
    public static function get_podcast_for_entry($entry_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_formidable_podcast_links';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if (!$table_exists) {
            return null;
        }

        return $wpdb->get_var($wpdb->prepare(
            "SELECT podcast_id FROM $table WHERE formidable_entry_id = %d AND sync_status = 'synced'",
            $entry_id
        ));
    }

    /**
     * Get all entry IDs linked to a podcast
     *
     * @param int $podcast_id
     * @return array
     */
    public static function get_entries_for_podcast($podcast_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_formidable_podcast_links';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if (!$table_exists) {
            return [];
        }

        return $wpdb->get_col($wpdb->prepare(
            "SELECT formidable_entry_id FROM $table WHERE podcast_id = %d AND sync_status = 'synced'",
            $podcast_id
        ));
    }

    /**
     * Get full link record for an entry
     *
     * @param int $entry_id
     * @return object|null
     */
    public static function get_link_for_entry($entry_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_formidable_podcast_links';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if (!$table_exists) {
            return null;
        }

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE formidable_entry_id = %d",
            $entry_id
        ));
    }

    /**
     * Get entries that need syncing (no successful link)
     *
     * @return array
     */
    public static function get_entries_needing_sync() {
        global $wpdb;

        $tracker_form_id = PIT_Settings::get('tracker_form_id', 0);
        if (!$tracker_form_id) {
            return [];
        }

        $entries_table = $wpdb->prefix . 'frm_items';
        $links_table = $wpdb->prefix . 'pit_formidable_podcast_links';

        // Check if links table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$links_table'");
        if (!$table_exists) {
            // Return all entries if table doesn't exist yet
            return $wpdb->get_results($wpdb->prepare(
                "SELECT id, created_at, NULL as sync_status, NULL as sync_error
                 FROM $entries_table
                 WHERE form_id = %d AND is_draft = 0
                 ORDER BY created_at DESC",
                $tracker_form_id
            ));
        }

        // Find entries without a successful link
        return $wpdb->get_results($wpdb->prepare(
            "SELECT e.id, e.created_at, l.sync_status, l.sync_error
             FROM $entries_table e
             LEFT JOIN $links_table l ON e.id = l.formidable_entry_id
             WHERE e.form_id = %d 
               AND e.is_draft = 0
               AND (l.id IS NULL OR l.sync_status != 'synced')
             ORDER BY e.created_at DESC",
            $tracker_form_id
        ));
    }

    /**
     * Retry all failed sync entries
     *
     * @return int Number of entries retried
     */
    public static function retry_failed_syncs() {
        $tracker_form_id = PIT_Settings::get('tracker_form_id', 0);
        if (!$tracker_form_id) {
            return 0;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pit_formidable_podcast_links';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if (!$table_exists) {
            return 0;
        }

        $failed = $wpdb->get_col(
            "SELECT formidable_entry_id FROM $table WHERE sync_status = 'failed'"
        );

        foreach ($failed as $entry_id) {
            self::sync_entry($entry_id, $tracker_form_id);
        }

        return count($failed);
    }

    /**
     * Get sync status statistics
     *
     * @return array
     */
    public static function get_sync_status() {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_formidable_podcast_links';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        
        if (!$table_exists) {
            return [
                'total_entries'   => 0,
                'unique_podcasts' => 0,
                'failed_entries'  => 0,
                'last_sync'       => null,
                'table_exists'    => false,
            ];
        }

        $total = $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $unique_podcasts = $wpdb->get_var("SELECT COUNT(DISTINCT podcast_id) FROM $table WHERE podcast_id IS NOT NULL");
        $failed = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE sync_status = 'failed'");
        $last_sync = $wpdb->get_var("SELECT MAX(synced_at) FROM $table WHERE sync_status = 'synced'");

        return [
            'total_entries'   => (int) $total,
            'unique_podcasts' => (int) $unique_podcasts,
            'failed_entries'  => (int) $failed,
            'last_sync'       => $last_sync,
            'table_exists'    => true,
        ];
    }

    /**
     * Get field value from Formidable entry
     *
     * @param int $entry_id
     * @param int|string $field_id
     * @return string|null
     */
    private static function get_entry_field_value($entry_id, $field_id) {
        // Method 1: Direct database lookup (most reliable)
        global $wpdb;
        $table = $wpdb->prefix . 'frm_item_metas';

        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM $table WHERE item_id = %d AND field_id = %d",
            $entry_id,
            $field_id
        ));

        if ($value) {
            return $value;
        }

        // Method 2: Use Formidable EntryMeta (if available)
        if (class_exists('FrmEntryMeta')) {
            $value = FrmEntryMeta::get_entry_meta_by_field($entry_id, $field_id);
            if ($value) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Extract podcast name from RSS URL
     *
     * @param string $rss_url
     * @return string
     */
    private static function extract_podcast_name_from_url($rss_url) {
        $parsed = parse_url($rss_url);
        $host = $parsed['host'] ?? 'Unknown Podcast';

        // Remove www. prefix
        $host = preg_replace('/^www\./i', '', $host);

        return ucwords(str_replace(['.', '-', '_'], ' ', $host));
    }
}
