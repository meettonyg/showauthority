<?php
/**
 * REST API: Formidable Forms Sync Controller
 *
 * Handles syncing Interview Tracker entries with podcast database.
 *
 * @package PodcastInfluenceTracker
 * @subpackage API
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_REST_Formidable {

    /**
     * Namespace
     */
    const NAMESPACE = 'podcast-influence/v1';

    /**
     * Register REST routes
     */
    public static function register_routes() {
        // POST /formidable/sync - Trigger bulk sync
        register_rest_route(
            self::NAMESPACE,
            '/formidable/sync',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [__CLASS__, 'sync_entries'],
                'permission_callback' => [__CLASS__, 'check_admin_permission'],
            ]
        );

        // GET /formidable/status - Get sync status
        register_rest_route(
            self::NAMESPACE,
            '/formidable/status',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [__CLASS__, 'get_sync_status'],
                'permission_callback' => [__CLASS__, 'check_admin_permission'],
            ]
        );

        // GET /formidable/entries - Get entries needing sync
        register_rest_route(
            self::NAMESPACE,
            '/formidable/entries',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [__CLASS__, 'get_entries_needing_sync'],
                'permission_callback' => [__CLASS__, 'check_admin_permission'],
            ]
        );

        // POST /formidable/retry - Retry failed syncs
        register_rest_route(
            self::NAMESPACE,
            '/formidable/retry',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [__CLASS__, 'retry_failed'],
                'permission_callback' => [__CLASS__, 'check_admin_permission'],
            ]
        );
    }

    /**
     * Check if user has admin permission
     *
     * @param WP_REST_Request $request
     * @return bool
     */
    public static function check_admin_permission($request) {
        return current_user_can('manage_options');
    }

    /**
     * Trigger bulk sync of all Interview Tracker entries
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function sync_entries($request) {
        // Check if Formidable Integration class exists
        if (!class_exists('PIT_Formidable_Integration')) {
            return new WP_Error(
                'integration_not_available',
                'Formidable Forms integration is not available',
                ['status' => 500]
            );
        }

        // Get settings
        $tracker_form_id = PIT_Settings::get('tracker_form_id', 0);
        $rss_field_id = PIT_Settings::get('rss_field_id', '');

        if (!$tracker_form_id) {
            return new WP_Error(
                'not_configured',
                'Interview Tracker Form ID is not configured. Please set it in Settings.',
                ['status' => 400]
            );
        }

        if (!$rss_field_id) {
            return new WP_Error(
                'not_configured',
                'RSS Feed Field ID is not configured. Please set it in Settings.',
                ['status' => 400]
            );
        }

        // Get all entries from the Interview Tracker form
        $entries = self::get_form_entries($tracker_form_id);

        if (empty($entries)) {
            return rest_ensure_response([
                'success'          => true,
                'message'          => 'No entries found in Interview Tracker form',
                'synced'           => 0,
                'podcasts_created' => 0,
                'failed'           => 0,
            ]);
        }

        $synced = 0;
        $podcasts_created = 0;
        $failed = 0;
        $errors = [];

        foreach ($entries as $entry) {
            try {
                // Check if already linked
                $existing_link = PIT_Formidable_Integration::get_link_for_entry($entry->id);
                
                if ($existing_link && $existing_link->sync_status === 'synced') {
                    // Already synced successfully, skip
                    continue;
                }

                // Get RSS URL from entry
                $rss_url = self::get_entry_field_value($entry->id, $rss_field_id);

                if (empty($rss_url) || !filter_var($rss_url, FILTER_VALIDATE_URL)) {
                    $failed++;
                    $errors[] = "Entry {$entry->id}: Invalid or missing RSS URL";
                    continue;
                }

                // Check if podcast already exists
                $existing_podcast = PIT_Podcast_Repository::get_by_rss($rss_url);
                $is_new_podcast = !$existing_podcast;

                // Trigger sync for this entry
                PIT_Formidable_Integration::sync_entry($entry->id, $tracker_form_id);

                $synced++;
                if ($is_new_podcast) {
                    $podcasts_created++;
                }

            } catch (Exception $e) {
                $failed++;
                $errors[] = "Entry {$entry->id}: " . $e->getMessage();
            }
        }

        return rest_ensure_response([
            'success'          => true,
            'message'          => "Sync completed. {$synced} entries synced, {$podcasts_created} new podcasts created.",
            'synced'           => $synced,
            'podcasts_created' => $podcasts_created,
            'failed'           => $failed,
            'total_entries'    => count($entries),
            'errors'           => $failed > 0 ? array_slice($errors, 0, 10) : [],
        ]);
    }

    /**
     * Get sync status
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function get_sync_status($request) {
        if (!class_exists('PIT_Formidable_Integration')) {
            return rest_ensure_response([
                'total_entries'   => 0,
                'unique_podcasts' => 0,
                'failed_entries'  => 0,
                'last_sync'       => null,
                'configured'      => false,
            ]);
        }

        $status = PIT_Formidable_Integration::get_sync_status();
        $status['configured'] = !empty(PIT_Settings::get('tracker_form_id', 0));

        return rest_ensure_response($status);
    }

    /**
     * Get entries that need syncing
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function get_entries_needing_sync($request) {
        if (!class_exists('PIT_Formidable_Integration')) {
            return rest_ensure_response([
                'entries' => [],
                'count'   => 0,
            ]);
        }

        $entries = PIT_Formidable_Integration::get_entries_needing_sync();

        return rest_ensure_response([
            'entries' => $entries,
            'count'   => count($entries),
        ]);
    }

    /**
     * Retry failed sync entries
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function retry_failed($request) {
        if (!class_exists('PIT_Formidable_Integration')) {
            return new WP_Error(
                'integration_not_available',
                'Formidable Forms integration is not available',
                ['status' => 500]
            );
        }

        $retried = PIT_Formidable_Integration::retry_failed_syncs();

        return rest_ensure_response([
            'success' => true,
            'retried' => $retried,
            'message' => "{$retried} entries retried",
        ]);
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
            "SELECT id, user_id, created_at FROM $table WHERE form_id = %d AND is_draft = 0",
            $form_id
        ));
    }

    /**
     * Get field value from entry
     *
     * @param int $entry_id
     * @param int|string $field_id
     * @return string|null
     */
    private static function get_entry_field_value($entry_id, $field_id) {
        // Method 1: Use Formidable Pro helper
        if (class_exists('FrmProEntryMetaHelper')) {
            $value = FrmProEntryMetaHelper::get_post_or_meta_value($entry_id, $field_id);
            if ($value) {
                return $value;
            }
        }

        // Method 2: Use Formidable EntryMeta
        if (class_exists('FrmEntryMeta')) {
            $value = FrmEntryMeta::get_entry_meta_by_field($entry_id, $field_id);
            if ($value) {
                return $value;
            }
        }

        // Method 3: Direct database lookup
        global $wpdb;
        $table = $wpdb->prefix . 'frm_item_metas';

        return $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM $table WHERE item_id = %d AND field_id = %d",
            $entry_id,
            $field_id
        ));
    }
}
