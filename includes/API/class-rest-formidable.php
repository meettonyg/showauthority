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

        // GET /formidable/failed - Get failed entries with details
        register_rest_route(
            self::NAMESPACE,
            '/formidable/failed',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [__CLASS__, 'get_failed_entries'],
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

        // GET /formidable/diagnose/(?P<entry_id>\d+) - Diagnose specific entry
        register_rest_route(
            self::NAMESPACE,
            '/formidable/diagnose/(?P<entry_id>\d+)',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [__CLASS__, 'diagnose_entry'],
                'permission_callback' => [__CLASS__, 'check_admin_permission'],
                'args'                => [
                    'entry_id' => [
                        'required' => true,
                        'type'     => 'integer',
                    ],
                ],
            ]
        );

        // POST /contacts/backfill - Backfill contacts from RSS for existing podcasts
        register_rest_route(
            self::NAMESPACE,
            '/contacts/backfill',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [__CLASS__, 'backfill_contacts'],
                'permission_callback' => [__CLASS__, 'check_admin_permission'],
            ]
        );

        // POST /podcasts/(?P<podcast_id>\d+)/rediscover - Rediscover social links and contacts for a podcast
        register_rest_route(
            self::NAMESPACE,
            '/podcasts/(?P<podcast_id>\d+)/rediscover',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [__CLASS__, 'rediscover_podcast'],
                'permission_callback' => [__CLASS__, 'check_admin_permission'],
                'args'                => [
                    'podcast_id' => [
                        'required' => true,
                        'type'     => 'integer',
                    ],
                ],
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
     * Backfill contacts for existing podcasts that don't have any
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function backfill_contacts($request) {
        if (!class_exists('PIT_Discovery_Engine')) {
            return new WP_Error(
                'discovery_engine_not_available',
                'Discovery Engine is not available',
                ['status' => 500]
            );
        }

        $limit = $request->get_param('limit') ?: 100;
        $results = PIT_Discovery_Engine::backfill_contacts($limit);

        return rest_ensure_response([
            'success' => true,
            'message' => "{$results['contacts_created']} contacts created from {$results['processed']} podcasts processed",
            'processed' => $results['processed'],
            'contacts_created' => $results['contacts_created'],
            'skipped' => $results['skipped'],
            'errors' => $results['errors'],
        ]);
    }

    /**
     * Rediscover social links and contacts for a specific podcast
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function rediscover_podcast($request) {
        $podcast_id = (int) $request->get_param('podcast_id');

        if (!class_exists('PIT_Discovery_Engine')) {
            return new WP_Error(
                'discovery_engine_not_available',
                'Discovery Engine is not available',
                ['status' => 500]
            );
        }

        $result = PIT_Discovery_Engine::rediscover($podcast_id);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response([
            'success' => true,
            'message' => "Rediscovered podcast {$podcast_id}",
            'podcast_id' => $result['podcast_id'],
            'social_links_found' => $result['social_links_found'],
            'contact_created' => $result['contact_created'] ?? false,
        ]);
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
        $skipped = 0;
        $podcasts_created = 0;
        $failed = 0;
        $errors = [];

        foreach ($entries as $entry) {
            try {
                // Check if already linked
                $existing_link = PIT_Formidable_Integration::get_link_for_entry($entry->id);
                
                if ($existing_link && $existing_link->sync_status === 'synced') {
                    // Already synced successfully, skip
                    $skipped++;
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
            'skipped'          => $skipped,
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
     * Get failed entries with details
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function get_failed_entries($request) {
        global $wpdb;

        $links_table = $wpdb->prefix . 'pit_formidable_podcast_links';
        $entries_table = $wpdb->prefix . 'frm_items';
        $metas_table = $wpdb->prefix . 'frm_item_metas';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$links_table'");
        if (!$table_exists) {
            return rest_ensure_response([
                'failed_entries' => [],
                'count'          => 0,
                'message'        => 'Link table does not exist',
            ]);
        }

        // Get RSS field ID from settings
        $rss_field_id = PIT_Settings::get('rss_field_id', '');
        $podcast_name_field_id = 9926; // Podcast field in Interview Tracker

        // Get failed entries with their error messages
        $failed = $wpdb->get_results(
            "SELECT 
                l.id,
                l.formidable_entry_id,
                l.sync_status,
                l.sync_error,
                l.rss_url_at_sync,
                l.created_at,
                l.updated_at,
                e.created_at as entry_created_at
             FROM $links_table l
             LEFT JOIN $entries_table e ON l.formidable_entry_id = e.id
             WHERE l.sync_status = 'failed'
             ORDER BY l.updated_at DESC"
        );

        // Enrich with podcast name and RSS URL from Formidable
        foreach ($failed as &$entry) {
            // Get podcast name from Formidable entry
            $podcast_name = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM $metas_table WHERE item_id = %d AND field_id = %d",
                $entry->formidable_entry_id,
                $podcast_name_field_id
            ));
            $entry->podcast_name = $podcast_name ?: '(Unknown)';

            // Get RSS URL from Formidable entry if not stored
            if (empty($entry->rss_url_at_sync) && $rss_field_id) {
                $rss_url = $wpdb->get_var($wpdb->prepare(
                    "SELECT meta_value FROM $metas_table WHERE item_id = %d AND field_id = %d",
                    $entry->formidable_entry_id,
                    $rss_field_id
                ));
                $entry->rss_url = $rss_url ?: '(No RSS URL)';
            } else {
                $entry->rss_url = $entry->rss_url_at_sync ?: '(No RSS URL)';
            }

            // Add edit link
            $entry->edit_url = admin_url("admin.php?page=formidable-entries&frm_action=edit&id={$entry->formidable_entry_id}");
        }

        return rest_ensure_response([
            'failed_entries' => $failed,
            'count'          => count($failed),
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
     * Diagnose a specific Formidable entry
     * Shows link status, podcast status, and any issues
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function diagnose_entry($request) {
        global $wpdb;

        $entry_id = (int) $request->get_param('entry_id');
        
        $links_table = $wpdb->prefix . 'pit_formidable_podcast_links';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';
        $entries_table = $wpdb->prefix . 'frm_items';
        $metas_table = $wpdb->prefix . 'frm_item_metas';

        $diagnosis = [
            'entry_id' => $entry_id,
            'issues' => [],
            'status' => 'unknown',
        ];

        // 1. Check if Formidable entry exists
        $entry = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $entries_table WHERE id = %d",
            $entry_id
        ));

        if (!$entry) {
            $diagnosis['issues'][] = "Formidable entry #$entry_id does not exist";
            $diagnosis['status'] = 'error';
            return rest_ensure_response($diagnosis);
        }

        $diagnosis['formidable_entry'] = [
            'id' => $entry->id,
            'form_id' => $entry->form_id,
            'is_draft' => $entry->is_draft,
            'created_at' => $entry->created_at,
            'user_id' => $entry->user_id,
        ];

        // 2. Get RSS URL from entry
        $rss_field_id = PIT_Settings::get('rss_field_id', '');
        $rss_url = $rss_field_id ? $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM $metas_table WHERE item_id = %d AND field_id = %d",
            $entry_id,
            $rss_field_id
        )) : null;

        $diagnosis['rss_url'] = $rss_url ?: '(not found)';
        $diagnosis['rss_field_id'] = $rss_field_id;

        if (!$rss_url) {
            $diagnosis['issues'][] = "No RSS URL found in field $rss_field_id";
        }

        // 3. Check link table
        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $links_table WHERE formidable_entry_id = %d",
            $entry_id
        ));

        if (!$link) {
            $diagnosis['link_record'] = null;
            $diagnosis['issues'][] = "No link record exists in pit_formidable_podcast_links";
        } else {
            $diagnosis['link_record'] = [
                'id' => $link->id,
                'podcast_id' => $link->podcast_id,
                'sync_status' => $link->sync_status,
                'sync_error' => $link->sync_error,
                'rss_url_at_sync' => $link->rss_url_at_sync,
                'synced_at' => $link->synced_at,
                'created_at' => $link->created_at,
            ];

            if ($link->sync_status === 'failed') {
                $diagnosis['issues'][] = "Link status is 'failed': " . ($link->sync_error ?: 'Unknown error');
            }

            if (empty($link->podcast_id)) {
                $diagnosis['issues'][] = "Link record has NULL podcast_id";
            }
        }

        // 4. Check podcast table
        if ($link && !empty($link->podcast_id)) {
            $podcast = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $podcasts_table WHERE id = %d",
                $link->podcast_id
            ));

            if (!$podcast) {
                $diagnosis['podcast_record'] = null;
                $diagnosis['issues'][] = "CRITICAL: Podcast ID {$link->podcast_id} does NOT exist in pit_podcasts table!";
            } else {
                $diagnosis['podcast_record'] = [
                    'id' => $podcast->id,
                    'title' => $podcast->title,
                    'rss_feed_url' => $podcast->rss_feed_url,
                    'tracking_status' => $podcast->tracking_status,
                    'source' => $podcast->source,
                    'created_at' => $podcast->created_at,
                ];
            }
        }

        // 5. Check if podcast exists by RSS URL (maybe different ID)
        if ($rss_url) {
            $podcast_by_rss = $wpdb->get_row($wpdb->prepare(
                "SELECT id, title, rss_feed_url FROM $podcasts_table WHERE rss_feed_url = %s",
                $rss_url
            ));

            $diagnosis['podcast_by_rss'] = $podcast_by_rss ? [
                'id' => $podcast_by_rss->id,
                'title' => $podcast_by_rss->title,
            ] : null;

            if (!$podcast_by_rss) {
                $diagnosis['issues'][] = "No podcast found with RSS URL: $rss_url";
            }

            if ($podcast_by_rss && $link && $link->podcast_id != $podcast_by_rss->id) {
                $diagnosis['issues'][] = "MISMATCH: Link points to podcast_id {$link->podcast_id}, but RSS URL matches podcast_id {$podcast_by_rss->id}";
            }
        }

        // 6. Determine final status
        if (empty($diagnosis['issues'])) {
            $diagnosis['status'] = 'healthy';
        } elseif (count($diagnosis['issues']) === 1 && strpos($diagnosis['issues'][0], 'failed') !== false) {
            $diagnosis['status'] = 'sync_failed';
        } else {
            $diagnosis['status'] = 'error';
        }

        // 7. Suggest fix
        if ($diagnosis['status'] !== 'healthy') {
            $diagnosis['suggested_fix'] = 'Re-run sync by clicking "Sync Now" in Settings, or check error logs for details';
        }

        return rest_ensure_response($diagnosis);
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
        // Direct database lookup (most reliable)
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

        // Fallback: Use Formidable EntryMeta
        if (class_exists('FrmEntryMeta')) {
            $value = FrmEntryMeta::get_entry_meta_by_field($entry_id, $field_id);
            if ($value) {
                return $value;
            }
        }

        return null;
    }
}
