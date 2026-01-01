<?php
/**
 * REST API: Calendar Events
 * 
 * Provides CRUD endpoints for calendar events with support for
 * filtering by appearance, date range, and event type.
 *
 * @package Podcast_Influence_Tracker
 * @since 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_REST_Calendar_Events {

    /**
     * Namespace for REST routes
     */
    const NAMESPACE = 'pit/v1';

    /**
     * Base route
     */
    const BASE = 'calendar-events';

    /**
     * Register REST routes
     */
    public static function register_routes() {
        // GET /calendar-events - List events
        register_rest_route(self::NAMESPACE, '/' . self::BASE, [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [__CLASS__, 'get_events'],
                'permission_callback' => [__CLASS__, 'check_permission'],
                'args'                => self::get_collection_params(),
            ],
            // POST /calendar-events - Create event
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [__CLASS__, 'create_event'],
                'permission_callback' => [__CLASS__, 'check_permission'],
                'args'                => self::get_event_params(true),
            ],
        ]);

        // GET/PATCH/DELETE /calendar-events/{id}
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [__CLASS__, 'get_event'],
                'permission_callback' => [__CLASS__, 'check_permission'],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [__CLASS__, 'update_event'],
                'permission_callback' => [__CLASS__, 'check_permission'],
                'args'                => self::get_event_params(false),
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [__CLASS__, 'delete_event'],
                'permission_callback' => [__CLASS__, 'check_permission'],
            ],
        ]);

        // GET /calendar-events/by-appearance/{appearance_id}
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/by-appearance/(?P<appearance_id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'get_events_by_appearance'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);

        // GET /calendar-events/types - Get event types
        register_rest_route(self::NAMESPACE, '/' . self::BASE . '/types', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'get_event_types'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);
    }

    /**
     * Check if user has permission
     */
    public static function check_permission($request) {
        return is_user_logged_in();
    }

    /**
     * Get collection parameters
     */
    private static function get_collection_params() {
        return [
            'per_page' => [
                'default'           => 50,
                'sanitize_callback' => 'absint',
                'validate_callback' => function($value) {
                    return $value >= 1 && $value <= 100;
                },
            ],
            'page' => [
                'default'           => 1,
                'sanitize_callback' => 'absint',
            ],
            'appearance_id' => [
                'sanitize_callback' => 'absint',
            ],
            'podcast_id' => [
                'sanitize_callback' => 'absint',
            ],
            'event_type' => [
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'start_date' => [
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'end_date' => [
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'sync_status' => [
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'orderby' => [
                'default'           => 'start_datetime',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'order' => [
                'default'           => 'ASC',
                'sanitize_callback' => function($value) {
                    return strtoupper($value) === 'DESC' ? 'DESC' : 'ASC';
                },
            ],
        ];
    }

    /**
     * Get event creation/update parameters
     */
    private static function get_event_params($required = false) {
        return [
            'appearance_id' => [
                'sanitize_callback' => 'absint',
            ],
            'podcast_id' => [
                'sanitize_callback' => 'absint',
            ],
            'event_type' => [
                'required'          => $required,
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => function($value) {
                    $valid_types = array_keys(PIT_Calendar_Events_Schema::get_event_types());
                    return in_array($value, $valid_types);
                },
            ],
            'title' => [
                'required'          => $required,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'description' => [
                'sanitize_callback' => 'sanitize_textarea_field',
            ],
            'location' => [
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'start_datetime' => [
                'required'          => $required,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'end_datetime' => [
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'is_all_day' => [
                'sanitize_callback' => function($value) {
                    return $value ? 1 : 0;
                },
            ],
            'timezone' => [
                'default'           => 'America/Chicago',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'sync_enabled' => [
                'sanitize_callback' => function($value) {
                    return $value ? 1 : 0;
                },
            ],
            'reminders' => [
                'sanitize_callback' => function($value) {
                    if (is_string($value)) {
                        $decoded = json_decode($value, true);
                        return json_last_error() === JSON_ERROR_NONE ? $value : null;
                    }
                    return is_array($value) ? wp_json_encode($value) : null;
                },
            ],
        ];
    }

    /**
     * GET /calendar-events - List events
     */
    public static function get_events($request) {
        global $wpdb;

        $table = PIT_Calendar_Events_Schema::get_table_name();
        $user_id = get_current_user_id();

        // Build query
        $where = ['user_id = %d'];
        $params = [$user_id];

        // Filter by appearance
        if ($appearance_id = $request->get_param('appearance_id')) {
            $where[] = 'appearance_id = %d';
            $params[] = $appearance_id;
        }

        // Filter by podcast
        if ($podcast_id = $request->get_param('podcast_id')) {
            $where[] = 'podcast_id = %d';
            $params[] = $podcast_id;
        }

        // Filter by event type
        if ($event_type = $request->get_param('event_type')) {
            $where[] = 'event_type = %s';
            $params[] = $event_type;
        }

        // Filter by date range
        if ($start_date = $request->get_param('start_date')) {
            $where[] = 'start_datetime >= %s';
            $params[] = $start_date . ' 00:00:00';
        }

        if ($end_date = $request->get_param('end_date')) {
            $where[] = 'start_datetime <= %s';
            $params[] = $end_date . ' 23:59:59';
        }

        // Filter by sync status
        if ($sync_status = $request->get_param('sync_status')) {
            $where[] = 'sync_status = %s';
            $params[] = $sync_status;
        }

        $where_clause = implode(' AND ', $where);

        // Ordering
        $orderby = $request->get_param('orderby');
        $order = $request->get_param('order');
        $allowed_orderby = ['start_datetime', 'created_at', 'updated_at', 'title', 'event_type'];
        if (!in_array($orderby, $allowed_orderby)) {
            $orderby = 'start_datetime';
        }

        // Pagination
        $per_page = $request->get_param('per_page');
        $page = $request->get_param('page');
        $offset = ($page - 1) * $per_page;

        // Get total count
        $count_sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE $where_clause",
            ...$params
        );
        $total = (int) $wpdb->get_var($count_sql);

        // Get events
        $sql = $wpdb->prepare(
            "SELECT * FROM $table WHERE $where_clause ORDER BY $orderby $order LIMIT %d OFFSET %d",
            array_merge($params, [$per_page, $offset])
        );
        $events = $wpdb->get_results($sql, ARRAY_A);

        // Format events
        $events = array_map([__CLASS__, 'format_event'], $events);

        return rest_ensure_response([
            'success' => true,
            'data'    => $events,
            'meta'    => [
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $per_page,
                'total_pages' => ceil($total / $per_page),
            ],
        ]);
    }

    /**
     * GET /calendar-events/{id} - Get single event
     */
    public static function get_event($request) {
        global $wpdb;

        $table = PIT_Calendar_Events_Schema::get_table_name();
        $id = (int) $request->get_param('id');
        $user_id = get_current_user_id();

        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND user_id = %d",
            $id,
            $user_id
        ), ARRAY_A);

        if (!$event) {
            return new WP_Error('not_found', 'Event not found', ['status' => 404]);
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => self::format_event($event),
        ]);
    }

    /**
     * POST /calendar-events - Create event
     */
    public static function create_event($request) {
        global $wpdb;

        $table = PIT_Calendar_Events_Schema::get_table_name();
        $user_id = get_current_user_id();

        // Check if user has sync_enabled explicitly set, otherwise auto-enable if they have a connected calendar
        $sync_enabled = $request->get_param('sync_enabled');
        if ($sync_enabled === null) {
            // Auto-enable sync if user has an active Google or Outlook calendar connection
            $sync_enabled = self::user_has_active_calendar_connection($user_id) ? 1 : 0;
        } else {
            $sync_enabled = $sync_enabled ? 1 : 0;
        }

        $data = [
            'user_id'        => $user_id,
            'appearance_id'  => $request->get_param('appearance_id') ?: null,
            'podcast_id'     => $request->get_param('podcast_id') ?: null,
            'event_type'     => $request->get_param('event_type'),
            'title'          => $request->get_param('title'),
            'description'    => $request->get_param('description') ?: null,
            'location'       => $request->get_param('location') ?: null,
            'start_datetime' => $request->get_param('start_datetime'),
            'end_datetime'   => $request->get_param('end_datetime') ?: null,
            'is_all_day'     => $request->get_param('is_all_day') ? 1 : 0,
            'timezone'       => $request->get_param('timezone') ?: 'America/Chicago',
            'sync_enabled'   => $sync_enabled,
            'sync_status'    => $sync_enabled ? 'local_only' : null,
            'reminders'      => $request->get_param('reminders'),
            'created_at'     => current_time('mysql'),
            'updated_at'     => current_time('mysql'),
        ];

        $inserted = $wpdb->insert($table, $data);

        if ($inserted === false) {
            return new WP_Error('insert_failed', 'Failed to create event: ' . $wpdb->last_error, ['status' => 500]);
        }

        $event_id = $wpdb->insert_id;

        // Sync to Google Calendar if connected
        if (class_exists('PIT_Calendar_Sync_Service')) {
            PIT_Calendar_Sync_Service::sync_event_to_google($event_id);
        }

        // Fetch the created event
        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $event_id
        ), ARRAY_A);

        return rest_ensure_response([
            'success' => true,
            'message' => 'Event created successfully',
            'data'    => self::format_event($event),
        ]);
    }

    /**
     * PATCH /calendar-events/{id} - Update event
     */
    public static function update_event($request) {
        global $wpdb;

        $table = PIT_Calendar_Events_Schema::get_table_name();
        $id = (int) $request->get_param('id');
        $user_id = get_current_user_id();

        // Check ownership
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE id = %d AND user_id = %d",
            $id,
            $user_id
        ));

        if (!$existing) {
            return new WP_Error('not_found', 'Event not found', ['status' => 404]);
        }

        // Build update data
        $update_fields = [
            'appearance_id', 'podcast_id', 'event_type', 'title', 'description',
            'location', 'start_datetime', 'end_datetime', 'is_all_day', 'timezone',
            'sync_enabled', 'reminders', 'google_calendar_id', 'google_event_id',
            'outlook_calendar_id', 'outlook_event_id', 'sync_status', 'sync_error_message',
        ];

        $data = [];
        foreach ($update_fields as $field) {
            $value = $request->get_param($field);
            if ($value !== null) {
                $data[$field] = $value;
            }
        }

        if (empty($data)) {
            return new WP_Error('no_data', 'No fields to update', ['status' => 400]);
        }

        $data['updated_at'] = current_time('mysql');

        // Mark as pending sync if synced previously
        $has_google_id = $wpdb->get_var($wpdb->prepare(
            "SELECT google_event_id FROM $table WHERE id = %d",
            $id
        ));
        if ($has_google_id && !isset($data['sync_status'])) {
            $data['sync_status'] = 'pending_sync';
        }

        $updated = $wpdb->update($table, $data, ['id' => $id]);

        if ($updated === false) {
            return new WP_Error('update_failed', 'Failed to update event: ' . $wpdb->last_error, ['status' => 500]);
        }

        // Sync to Google Calendar if connected
        if (class_exists('PIT_Calendar_Sync_Service')) {
            PIT_Calendar_Sync_Service::sync_event_to_google($id);
        }

        // Fetch updated event
        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $id
        ), ARRAY_A);

        return rest_ensure_response([
            'success' => true,
            'message' => 'Event updated successfully',
            'data'    => self::format_event($event),
        ]);
    }

    /**
     * DELETE /calendar-events/{id} - Delete event
     */
    public static function delete_event($request) {
        global $wpdb;

        $table = PIT_Calendar_Events_Schema::get_table_name();
        $id = (int) $request->get_param('id');
        $user_id = get_current_user_id();

        // Check ownership
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, google_event_id, outlook_event_id FROM $table WHERE id = %d AND user_id = %d",
            $id,
            $user_id
        ), ARRAY_A);

        if (!$existing) {
            return new WP_Error('not_found', 'Event not found', ['status' => 404]);
        }

        // Get full event data for sync
        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $id
        ), ARRAY_A);

        // Delete from Google Calendar if synced
        $google_deleted = false;
        if (!empty($existing['google_event_id']) && class_exists('PIT_Calendar_Sync_Service')) {
            $result = PIT_Calendar_Sync_Service::delete_event_from_google($event);
            $google_deleted = !is_wp_error($result);
        }

        $deleted = $wpdb->delete($table, ['id' => $id]);

        if ($deleted === false) {
            return new WP_Error('delete_failed', 'Failed to delete event', ['status' => 500]);
        }

        return rest_ensure_response([
            'success' => true,
            'message' => 'Event deleted successfully',
            'deleted_external' => [
                'google' => $google_deleted,
                'outlook' => false,
            ],
        ]);
    }

    /**
     * GET /calendar-events/by-appearance/{appearance_id}
     */
    public static function get_events_by_appearance($request) {
        global $wpdb;

        $table = PIT_Calendar_Events_Schema::get_table_name();
        $appearance_id = (int) $request->get_param('appearance_id');
        $user_id = get_current_user_id();

        $events = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table 
             WHERE appearance_id = %d AND user_id = %d 
             ORDER BY start_datetime ASC",
            $appearance_id,
            $user_id
        ), ARRAY_A);

        $events = array_map([__CLASS__, 'format_event'], $events);

        return rest_ensure_response([
            'success' => true,
            'data'    => $events,
            'meta'    => [
                'appearance_id' => $appearance_id,
                'total'         => count($events),
            ],
        ]);
    }

    /**
     * GET /calendar-events/types - Get event types
     */
    public static function get_event_types($request) {
        return rest_ensure_response([
            'success' => true,
            'data'    => PIT_Calendar_Events_Schema::get_event_types(),
        ]);
    }

    /**
     * Format event for API response
     */
    private static function format_event($event) {
        if (!$event) {
            return null;
        }

        // Decode JSON fields
        if (!empty($event['reminders']) && is_string($event['reminders'])) {
            $event['reminders'] = json_decode($event['reminders'], true);
        }

        // Cast numeric fields
        $event['id'] = (int) $event['id'];
        $event['user_id'] = (int) $event['user_id'];
        $event['appearance_id'] = $event['appearance_id'] ? (int) $event['appearance_id'] : null;
        $event['podcast_id'] = $event['podcast_id'] ? (int) $event['podcast_id'] : null;
        $event['is_all_day'] = (bool) $event['is_all_day'];
        $event['sync_enabled'] = (bool) $event['sync_enabled'];

        // Add event type label
        $event_types = PIT_Calendar_Events_Schema::get_event_types();
        $event['event_type_label'] = $event_types[$event['event_type']] ?? $event['event_type'];

        return $event;
    }

    /**
     * Check if user has an active calendar connection (Google or Outlook)
     *
     * @param int $user_id
     * @return bool
     */
    private static function user_has_active_calendar_connection($user_id) {
        global $wpdb;

        $connections_table = $wpdb->prefix . 'pit_calendar_connections';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$connections_table'") !== $connections_table) {
            return false;
        }

        // Check for any active connection with sync enabled and a calendar selected
        $has_connection = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $connections_table
             WHERE user_id = %d
               AND sync_enabled = 1
               AND calendar_id IS NOT NULL
               AND calendar_id != ''",
            $user_id
        ));

        return (int) $has_connection > 0;
    }
}

// Register routes on REST API init
add_action('rest_api_init', ['PIT_REST_Calendar_Events', 'register_routes']);
