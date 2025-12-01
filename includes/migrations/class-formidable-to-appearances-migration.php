<?php
/**
 * Migration: Formidable Forms Interview Tracker to pit_guest_appearances table
 * 
 * Migrates entries from Formidable Forms (Form 518) to the new custom table,
 * mapping field 8113 (Interview Status) to the status column.
 * 
 * Dates are stored in pit_calendar_events table (not appearances).
 *
 * @package Podcast_Influence_Tracker
 * @since 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Formidable_To_Appearances_Migration {

    /**
     * Formidable field IDs from Interview Tracker form
     */
    const FIELD_MAPPINGS = [
        'interview_status'  => 8113,  // Interview Status (Potential, Active, Aired, etc.)
        'rss_feed_url'      => 9928,  // RSS Feed URL field
        'podcast_name'      => 8111,  // Podcast Name
        'episode_title'     => 10393, // Episode Title
        'episode_date'      => 8115,  // Episode/Air Date -> goes to calendar_events
        'priority'          => 8278,  // Priority field
        'source'            => 8165,  // Source/How Found
        'notes'             => 9175,  // Notes field
    ];

    /**
     * Status value mapping from Formidable to database keys
     */
    const STATUS_MAPPING = [
        'Potential'   => 'potential',
        'potential'   => 'potential',
        'Active'      => 'active',
        'active'      => 'active',
        'Aired'       => 'aired',
        'aired'       => 'aired',
        'Convert'     => 'convert',
        'convert'     => 'convert',
        'On Hold'     => 'on_hold',
        'on hold'     => 'on_hold',
        'On hold'     => 'on_hold',
        'Cancelled'   => 'cancelled',
        'cancelled'   => 'cancelled',
        'Canceled'    => 'cancelled',
        'Unqualified' => 'unqualified',
        'unqualified' => 'unqualified',
        // Legacy statuses
        'Pitched'     => 'active',
        'Negotiating' => 'active',
        'Scheduled'   => 'active',
        'Recorded'    => 'aired',
        'Promoted'    => 'convert',
        'Rejected'    => 'cancelled',
    ];

    /**
     * Form ID for Interview Tracker
     */
    const INTERVIEW_TRACKER_FORM_ID = 518;

    /**
     * Run the migration
     * 
     * @param bool $dry_run If true, only report what would be done
     * @return array Migration results
     */
    public static function run($dry_run = true) {
        global $wpdb;

        $results = [
            'dry_run'              => $dry_run,
            'total_entries'        => 0,
            'migrated'             => 0,
            'skipped_existing'     => 0,
            'skipped_no_podcast'   => 0,
            'calendar_events_created' => 0,
            'errors'               => [],
            'details'              => [],
        ];

        // Check if Formidable is active
        if (!class_exists('FrmEntry')) {
            $results['errors'][] = 'Formidable Forms is not active';
            return $results;
        }

        // Check if target tables exist
        $appearances_table = $wpdb->prefix . 'pit_guest_appearances';
        if (!$wpdb->get_var("SHOW TABLES LIKE '$appearances_table'")) {
            $results['errors'][] = 'Target table pit_guest_appearances does not exist';
            return $results;
        }

        $calendar_table = $wpdb->prefix . 'pit_calendar_events';
        $calendar_table_exists = $wpdb->get_var("SHOW TABLES LIKE '$calendar_table'") === $calendar_table;
        if (!$calendar_table_exists) {
            $results['errors'][] = 'Warning: pit_calendar_events table does not exist - dates will not be migrated';
        }

        // Get all entries from Interview Tracker form
        $entries_table = $wpdb->prefix . 'frm_items';
        $entries = $wpdb->get_results($wpdb->prepare(
            "SELECT id, user_id, created_at, updated_at 
             FROM $entries_table 
             WHERE form_id = %d AND is_draft = 0
             ORDER BY id ASC",
            self::INTERVIEW_TRACKER_FORM_ID
        ));

        $results['total_entries'] = count($entries);

        foreach ($entries as $entry) {
            $migration_result = self::migrate_entry($entry, $dry_run, $calendar_table_exists);
            
            if ($migration_result['status'] === 'migrated') {
                $results['migrated']++;
                if (!empty($migration_result['calendar_event_created'])) {
                    $results['calendar_events_created']++;
                }
            } elseif ($migration_result['status'] === 'skipped_existing') {
                $results['skipped_existing']++;
            } elseif ($migration_result['status'] === 'skipped_no_podcast') {
                $results['skipped_no_podcast']++;
            } elseif ($migration_result['status'] === 'error') {
                $results['errors'][] = $migration_result['message'];
            }

            $results['details'][] = $migration_result;
        }

        return $results;
    }

    /**
     * Migrate a single Formidable entry
     * 
     * @param object $entry Formidable entry object
     * @param bool $dry_run
     * @param bool $calendar_table_exists
     * @return array Result of migration attempt
     */
    private static function migrate_entry($entry, $dry_run, $calendar_table_exists) {
        global $wpdb;

        $entry_id = $entry->id;
        $user_id = $entry->user_id ?: 0;

        $result = [
            'entry_id'               => $entry_id,
            'user_id'                => $user_id,
            'status'                 => 'unknown',
            'message'                => '',
            'calendar_event_created' => false,
        ];

        // Check if already migrated
        $appearances_table = $wpdb->prefix . 'pit_guest_appearances';
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $appearances_table WHERE formidable_entry_id = %d",
            $entry_id
        ));

        if ($existing) {
            $result['status'] = 'skipped_existing';
            $result['message'] = "Entry $entry_id already migrated to appearance ID $existing";
            return $result;
        }

        // Get field values from Formidable
        $field_values = self::get_entry_field_values($entry_id);
        
        // Get podcast_id from the links table or create from RSS
        $podcast_id = self::get_or_create_podcast_for_entry($entry_id, $field_values, $dry_run);
        
        if (!$podcast_id) {
            $result['status'] = 'skipped_no_podcast';
            $result['message'] = "Entry $entry_id has no valid podcast link or RSS URL";
            return $result;
        }

        // Get podcast name for calendar event
        $podcast_name = self::get_podcast_name($podcast_id, $field_values);

        // Map status value
        $raw_status = $field_values['interview_status'] ?? '';
        $mapped_status = self::map_status($raw_status);

        // Prepare data for insertion (NO episode_date - that goes to calendar)
        $appearance_data = [
            'user_id'              => $user_id,
            'podcast_id'           => $podcast_id,
            'formidable_entry_id'  => $entry_id,
            'status'               => $mapped_status,
            'priority'             => self::map_priority($field_values['priority'] ?? ''),
            'source'               => sanitize_text_field($field_values['source'] ?? 'formidable_migration'),
            'episode_title'        => sanitize_text_field($field_values['episode_title'] ?? ''),
            'is_archived'          => 0,
            'created_at'           => $entry->created_at ?: current_time('mysql'),
            'updated_at'           => $entry->updated_at ?: current_time('mysql'),
        ];

        // Parse episode date for calendar event
        $episode_date = self::parse_date($field_values['episode_date'] ?? '');

        $result['data'] = $appearance_data;
        $result['raw_status'] = $raw_status;
        $result['mapped_status'] = $mapped_status;
        $result['episode_date'] = $episode_date;

        if ($dry_run) {
            $result['status'] = 'migrated';
            $result['message'] = "Would migrate entry $entry_id with status '$raw_status' -> '$mapped_status'";
            if ($episode_date) {
                $result['message'] .= " + calendar event for $episode_date";
                $result['calendar_event_created'] = true;
            }
            return $result;
        }

        // Actually insert the appearance record
        $inserted = $wpdb->insert($appearances_table, $appearance_data);

        if ($inserted === false) {
            $result['status'] = 'error';
            $result['message'] = "Failed to insert entry $entry_id: " . $wpdb->last_error;
            return $result;
        }

        $appearance_id = $wpdb->insert_id;
        $result['appearance_id'] = $appearance_id;

        // Create calendar event if date exists
        if ($episode_date && $calendar_table_exists) {
            $calendar_created = self::create_calendar_event(
                $user_id,
                $appearance_id,
                $podcast_id,
                $podcast_name,
                $episode_date,
                $field_values['episode_title'] ?? ''
            );
            $result['calendar_event_created'] = $calendar_created;
            $result['calendar_event_id'] = $calendar_created;
        }

        $result['status'] = 'migrated';
        $result['message'] = "Migrated entry $entry_id to appearance ID $appearance_id";
        if ($result['calendar_event_created']) {
            $result['message'] .= " + calendar event ID " . $result['calendar_event_id'];
        }

        return $result;
    }

    /**
     * Create a calendar event for an air date
     * 
     * @param int $user_id
     * @param int $appearance_id
     * @param int $podcast_id
     * @param string $podcast_name
     * @param string $episode_date MySQL date format
     * @param string $episode_title
     * @return int|false Calendar event ID or false on failure
     */
    private static function create_calendar_event($user_id, $appearance_id, $podcast_id, $podcast_name, $episode_date, $episode_title = '') {
        global $wpdb;

        $calendar_table = $wpdb->prefix . 'pit_calendar_events';

        // Build event title
        $title = $podcast_name;
        if ($episode_title) {
            $title .= ': ' . $episode_title;
        }
        $title .= ' - Air Date';

        $event_data = [
            'user_id'        => $user_id,
            'appearance_id'  => $appearance_id,
            'podcast_id'     => $podcast_id,
            'event_type'     => 'air_date',
            'title'          => sanitize_text_field($title),
            'description'    => 'Episode air date (migrated from Formidable)',
            'start_datetime' => $episode_date . ' 00:00:00',
            'end_datetime'   => $episode_date . ' 23:59:59',
            'is_all_day'     => 1,
            'timezone'       => 'America/Chicago',
            'sync_enabled'   => 0,
            'sync_status'    => 'local_only',
            'created_at'     => current_time('mysql'),
            'updated_at'     => current_time('mysql'),
        ];

        $inserted = $wpdb->insert($calendar_table, $event_data);

        if ($inserted === false) {
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Get podcast name by ID or from field values
     * 
     * @param int $podcast_id
     * @param array $field_values
     * @return string
     */
    private static function get_podcast_name($podcast_id, $field_values) {
        global $wpdb;

        // Try to get from database
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';
        $name = $wpdb->get_var($wpdb->prepare(
            "SELECT title FROM $podcasts_table WHERE id = %d",
            $podcast_id
        ));

        if ($name) {
            return $name;
        }

        // Fall back to field value
        if (!empty($field_values['podcast_name'])) {
            return $field_values['podcast_name'];
        }

        return 'Unknown Podcast';
    }

    /**
     * Get all field values for an entry
     * 
     * @param int $entry_id
     * @return array Field name => value mapping
     */
    private static function get_entry_field_values($entry_id) {
        global $wpdb;
        
        $values = [];
        $metas_table = $wpdb->prefix . 'frm_item_metas';

        foreach (self::FIELD_MAPPINGS as $name => $field_id) {
            if (!$field_id) continue;
            
            $value = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM $metas_table WHERE item_id = %d AND field_id = %d",
                $entry_id,
                $field_id
            ));
            
            $values[$name] = $value;
        }

        return $values;
    }

    /**
     * Get or create podcast ID for an entry
     * 
     * @param int $entry_id
     * @param array $field_values
     * @param bool $dry_run
     * @return int|null
     */
    private static function get_or_create_podcast_for_entry($entry_id, $field_values, $dry_run = false) {
        global $wpdb;

        // First, check if there's already a link in pit_formidable_podcast_links
        $links_table = $wpdb->prefix . 'pit_formidable_podcast_links';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$links_table'");
        
        if ($table_exists) {
            $podcast_id = $wpdb->get_var($wpdb->prepare(
                "SELECT podcast_id FROM $links_table WHERE formidable_entry_id = %d AND sync_status = 'synced'",
                $entry_id
            ));
            
            if ($podcast_id) {
                return (int) $podcast_id;
            }
        }

        // If no link exists, try to find/create from RSS URL
        $rss_url = $field_values['rss_feed_url'] ?? '';
        
        if (!$rss_url || !filter_var($rss_url, FILTER_VALIDATE_URL)) {
            return null;
        }

        // Check if podcast already exists
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';
        $existing_podcast = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $podcasts_table WHERE rss_feed_url = %s",
            $rss_url
        ));

        if ($existing_podcast) {
            return (int) $existing_podcast;
        }

        // In dry run, return a fake ID
        if ($dry_run) {
            return 999999;
        }

        // Create new podcast entry
        $podcast_name = $field_values['podcast_name'] ?? self::extract_name_from_url($rss_url);
        
        $wpdb->insert($podcasts_table, [
            'title'           => $podcast_name,
            'slug'            => sanitize_title($podcast_name),
            'rss_feed_url'    => $rss_url,
            'tracking_status' => 'not_tracked',
            'source'          => 'formidable_migration',
            'created_at'      => current_time('mysql'),
            'updated_at'      => current_time('mysql'),
        ]);

        return $wpdb->insert_id ?: null;
    }

    /**
     * Map Formidable status value to database key
     * 
     * @param string $raw_status
     * @return string
     */
    private static function map_status($raw_status) {
        $raw_status = trim($raw_status);
        
        if (isset(self::STATUS_MAPPING[$raw_status])) {
            return self::STATUS_MAPPING[$raw_status];
        }

        return 'potential';
    }

    /**
     * Map priority value
     * 
     * @param string $raw_priority
     * @return string
     */
    private static function map_priority($raw_priority) {
        $raw_priority = strtolower(trim($raw_priority));
        
        if (in_array($raw_priority, ['high', 'medium', 'low'])) {
            return $raw_priority;
        }

        return 'medium';
    }

    /**
     * Parse date string to MySQL format
     * 
     * @param string $date_str
     * @return string|null
     */
    private static function parse_date($date_str) {
        if (empty($date_str)) {
            return null;
        }

        $timestamp = strtotime($date_str);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    /**
     * Extract podcast name from URL
     * 
     * @param string $url
     * @return string
     */
    private static function extract_name_from_url($url) {
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? 'Unknown Podcast';
        $host = preg_replace('/^www\./i', '', $host);
        return ucwords(str_replace(['.', '-', '_'], ' ', $host));
    }

    /**
     * Add formidable_entry_id column to appearances table if missing
     */
    public static function ensure_schema() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'pit_guest_appearances';
        
        // Check if column exists
        $column_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) 
             FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = %s 
             AND TABLE_NAME = %s 
             AND COLUMN_NAME = 'formidable_entry_id'",
            DB_NAME,
            $table
        ));

        if (!$column_exists) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN formidable_entry_id BIGINT UNSIGNED NULL AFTER guest_id");
            $wpdb->query("ALTER TABLE $table ADD INDEX idx_formidable_entry (formidable_entry_id)");
            
            return true;
        }

        return false;
    }

    /**
     * Get migration status summary
     * 
     * @return array
     */
    public static function get_status() {
        global $wpdb;

        $entries_table = $wpdb->prefix . 'frm_items';
        $appearances_table = $wpdb->prefix . 'pit_guest_appearances';
        $calendar_table = $wpdb->prefix . 'pit_calendar_events';

        // Total Formidable entries
        $total_entries = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $entries_table WHERE form_id = %d AND is_draft = 0",
            self::INTERVIEW_TRACKER_FORM_ID
        ));

        // Already migrated
        $migrated = $wpdb->get_var(
            "SELECT COUNT(*) FROM $appearances_table WHERE formidable_entry_id IS NOT NULL"
        );

        // Total in appearances table
        $total_appearances = $wpdb->get_var("SELECT COUNT(*) FROM $appearances_table");

        // Calendar events (if table exists)
        $calendar_events = 0;
        if ($wpdb->get_var("SHOW TABLES LIKE '$calendar_table'") === $calendar_table) {
            $calendar_events = (int) $wpdb->get_var("SELECT COUNT(*) FROM $calendar_table");
        }

        return [
            'total_formidable_entries' => (int) $total_entries,
            'migrated_entries'         => (int) $migrated,
            'remaining'                => (int) $total_entries - (int) $migrated,
            'total_appearances'        => (int) $total_appearances,
            'total_calendar_events'    => $calendar_events,
            'form_id'                  => self::INTERVIEW_TRACKER_FORM_ID,
        ];
    }
}
