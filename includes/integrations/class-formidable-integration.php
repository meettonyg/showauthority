<?php
/**
 * Formidable Forms Integration
 *
 * Syncs Interview Tracker form entries with the podcast database.
 * Pulls RSS feed URLs from form entries and triggers podcast discovery.
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

        // Get the entry
        $entry = self::get_entry($entry_id);
        if (!$entry) {
            return;
        }

        // Get RSS field configuration
        $rss_field_id = PIT_Settings::get('rss_field_id', '');
        $podcast_name_field_id = PIT_Settings::get('podcast_name_field_id', '');

        if (!$rss_field_id) {
            return; // RSS field not configured
        }

        // Extract RSS URL from entry
        $rss_url = self::get_field_value($entry, $rss_field_id);
        if (!$rss_url || !filter_var($rss_url, FILTER_VALIDATE_URL)) {
            return; // No valid RSS URL
        }

        // Get podcast name (optional)
        $podcast_name = $podcast_name_field_id
            ? self::get_field_value($entry, $podcast_name_field_id)
            : '';

        // Check if podcast already exists by RSS URL
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcasts';

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table WHERE rss_feed_url = %s",
            $rss_url
        ));

        if ($existing) {
            // Update existing podcast with Formidable entry link
            $wpdb->update(
                $table,
                [
                    'formidable_entry_id' => $entry_id,
                    'last_synced_at' => current_time('mysql'),
                ],
                ['id' => $existing->id]
            );

            $podcast_id = $existing->id;
        } else {
            // Create new podcast using Discovery Engine for full parsing
            $podcast_id = self::discover_and_create_podcast($rss_url, $podcast_name, $entry_id);
        }

        if ($podcast_id) {
            // Queue discovery job for this podcast
            self::queue_discovery($podcast_id, $rss_url);

            do_action('pit_formidable_entry_synced', $podcast_id, $entry_id);
        }
    }

    /**
     * Bulk sync all entries from the Interview Tracker form
     */
    public static function bulk_sync_entries() {
        $tracker_form_id = PIT_Settings::get('tracker_form_id', 0);

        if (!$tracker_form_id) {
            return;
        }

        // Get all entries for this form
        $entries = self::get_form_entries($tracker_form_id);

        foreach ($entries as $entry) {
            self::sync_entry($entry->id, $tracker_form_id);
        }
    }

    /**
     * Queue podcast discovery job
     *
     * @param int $podcast_id
     * @param string $rss_url
     */
    private static function queue_discovery($podcast_id, $rss_url) {
        // Queue enrichment job (contacts, social links, etc.)
        $user_id = get_current_user_id() ?: 1; // Default to admin if no user context

        PIT_Job_Queue::add([
            'user_id'           => $user_id,
            'podcast_id'        => $podcast_id,
            'job_type'          => 'initial_tracking',
            'platforms_to_fetch' => json_encode(['twitter', 'instagram', 'youtube', 'linkedin', 'facebook']),
            'status'            => 'queued',
            'priority'          => 50,
        ]);
    }

    /**
     * Discover and create a new podcast from RSS feed
     *
     * Uses the Discovery Engine to parse RSS and extract metadata.
     *
     * @param string $rss_url RSS feed URL
     * @param string $podcast_name Optional name override
     * @param int $entry_id Formidable entry ID
     * @return int|false Podcast ID or false on failure
     */
    private static function discover_and_create_podcast($rss_url, $podcast_name, $entry_id) {
        // Use Discovery Engine if available
        if (class_exists('PIT_Discovery_Engine')) {
            try {
                $result = PIT_Discovery_Engine::discover_from_rss($rss_url);

                if ($result && !empty($result['podcast_id'])) {
                    // Update with Formidable entry link
                    PIT_Podcast_Repository::update($result['podcast_id'], [
                        'formidable_entry_id' => $entry_id,
                        'last_synced_at' => current_time('mysql'),
                    ]);

                    return $result['podcast_id'];
                }
            } catch (Exception $e) {
                error_log('PIT Formidable Integration - Discovery failed: ' . $e->getMessage());
            }
        }

        // Fallback: Create basic podcast entry and queue for discovery
        $podcast_data = [
            'title' => $podcast_name ?: self::extract_podcast_name_from_url($rss_url),
            'rss_feed_url' => $rss_url,
            'formidable_entry_id' => $entry_id,
            'tracking_status' => 'queued',
            'last_synced_at' => current_time('mysql'),
        ];

        return PIT_Podcast_Repository::create($podcast_data);
    }

    /**
     * Get Formidable entry by ID
     *
     * @param int $entry_id
     * @return object|null
     */
    private static function get_entry($entry_id) {
        if (!class_exists('FrmEntry')) {
            return null;
        }

        return FrmEntry::getOne($entry_id, true);
    }

    /**
     * Get all entries for a form
     *
     * @param int $form_id
     * @return array
     */
    private static function get_form_entries($form_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'frm_items';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM $table WHERE form_id = %d AND is_draft = 0",
            $form_id
        ));
    }

    /**
     * Get field value from entry
     *
     * @param object $entry
     * @param string $field_id
     * @return string
     */
    private static function get_field_value($entry, $field_id) {
        if (!$entry || !isset($entry->metas)) {
            return '';
        }

        foreach ($entry->metas as $meta) {
            if ((string) $meta->field_id === (string) $field_id) {
                return $meta->meta_value;
            }
        }

        return '';
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

    /**
     * Get sync status for admin display
     *
     * @return array
     */
    public static function get_sync_status() {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_podcasts';

        $total_synced = $wpdb->get_var(
            "SELECT COUNT(*) FROM $table WHERE formidable_entry_id IS NOT NULL"
        );

        $last_sync = $wpdb->get_var(
            "SELECT MAX(last_synced_at) FROM $table WHERE formidable_entry_id IS NOT NULL"
        );

        return [
            'total_synced' => (int) $total_synced,
            'last_sync' => $last_sync,
        ];
    }
}
