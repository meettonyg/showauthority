<?php
/**
 * REST API Controller for Private Contacts
 *
 * Provides endpoints for managing user-owned private contact information.
 * Uses pit_guest_private_contacts table.
 *
 * @package Podcast_Influence_Tracker
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_REST_Private_Contacts {

    const NAMESPACE = 'guestify/v1';

    /**
     * Register REST routes
     */
    public static function register_routes() {
        // Get all private contacts for current user
        register_rest_route(self::NAMESPACE, '/private-contacts', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_all'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Get private contact for a specific guest
        register_rest_route(self::NAMESPACE, '/private-contacts/guest/(?P<guest_id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_for_guest'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Get guest with merged private contacts
        register_rest_route(self::NAMESPACE, '/private-contacts/guest/(?P<guest_id>\d+)/full', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_guest_with_private'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Create/update private contact (upsert)
        register_rest_route(self::NAMESPACE, '/private-contacts/guest/(?P<guest_id>\d+)', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'upsert'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Update private contact by ID
        register_rest_route(self::NAMESPACE, '/private-contacts/(?P<id>\d+)', [
            'methods' => 'PATCH',
            'callback' => [__CLASS__, 'update'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Delete private contact
        register_rest_route(self::NAMESPACE, '/private-contacts/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [__CLASS__, 'delete'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);

        // Update last contact date
        register_rest_route(self::NAMESPACE, '/private-contacts/guest/(?P<guest_id>\d+)/last-contact', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'update_last_contact'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
        ]);
    }

    public static function check_permissions() {
        return is_user_logged_in();
    }

    /**
     * Get all private contacts for current user
     */
    public static function get_all($request) {
        $user_id = get_current_user_id();
        $contacts = PIT_Private_Contact_Repository::get_all_for_user($user_id);

        return new WP_REST_Response([
            'data' => array_map([__CLASS__, 'format_contact'], $contacts),
            'count' => count($contacts),
        ], 200);
    }

    /**
     * Get private contact for a specific guest
     */
    public static function get_for_guest($request) {
        $guest_id = (int) $request->get_param('guest_id');
        $user_id = get_current_user_id();

        $contact = PIT_Private_Contact_Repository::get_for_guest($guest_id, $user_id);

        if (!$contact) {
            return new WP_REST_Response([
                'data' => null,
                'message' => 'No private contact data for this guest',
            ], 200);
        }

        return new WP_REST_Response([
            'data' => self::format_contact($contact),
        ], 200);
    }

    /**
     * Get guest with merged private contact data
     */
    public static function get_guest_with_private($request) {
        $guest_id = (int) $request->get_param('guest_id');
        $user_id = get_current_user_id();

        $guest = PIT_Private_Contact_Repository::get_guest_with_private($guest_id, $user_id);

        if (!$guest) {
            return new WP_Error('not_found', 'Guest not found', ['status' => 404]);
        }

        return new WP_REST_Response([
            'data' => self::format_guest_with_private($guest),
        ], 200);
    }

    /**
     * Create or update private contact
     */
    public static function upsert($request) {
        $guest_id = (int) $request->get_param('guest_id');
        $user_id = get_current_user_id();

        $data = self::extract_contact_data($request);

        $contact_id = PIT_Private_Contact_Repository::upsert($guest_id, $data, $user_id);

        if (!$contact_id) {
            return new WP_Error('upsert_failed', 'Failed to save private contact', ['status' => 500]);
        }

        return new WP_REST_Response([
            'id' => $contact_id,
            'message' => 'Private contact saved successfully',
        ], 200);
    }

    /**
     * Update private contact by ID
     */
    public static function update($request) {
        $id = (int) $request->get_param('id');
        $user_id = get_current_user_id();

        $contact = PIT_Private_Contact_Repository::get($id, $user_id);
        if (!$contact) {
            return new WP_Error('not_found', 'Private contact not found', ['status' => 404]);
        }

        // Verify ownership
        if ((int) $contact->user_id !== $user_id && !current_user_can('manage_options')) {
            return new WP_Error('forbidden', 'Not authorized', ['status' => 403]);
        }

        $data = self::extract_contact_data($request);

        if (empty($data)) {
            return new WP_Error('no_data', 'No valid fields to update', ['status' => 400]);
        }

        PIT_Private_Contact_Repository::update($id, $data);

        return new WP_REST_Response([
            'id' => $id,
            'message' => 'Private contact updated successfully',
        ], 200);
    }

    /**
     * Delete private contact
     */
    public static function delete($request) {
        $id = (int) $request->get_param('id');
        $user_id = get_current_user_id();

        $contact = PIT_Private_Contact_Repository::get($id, $user_id);
        if (!$contact) {
            return new WP_Error('not_found', 'Private contact not found', ['status' => 404]);
        }

        // Verify ownership
        if ((int) $contact->user_id !== $user_id && !current_user_can('manage_options')) {
            return new WP_Error('forbidden', 'Not authorized', ['status' => 403]);
        }

        PIT_Private_Contact_Repository::delete($id);

        return new WP_REST_Response(['message' => 'Private contact deleted'], 200);
    }

    /**
     * Update last contact date
     */
    public static function update_last_contact($request) {
        $guest_id = (int) $request->get_param('guest_id');
        $date = sanitize_text_field($request->get_param('date')) ?: date('Y-m-d');
        $user_id = get_current_user_id();

        PIT_Private_Contact_Repository::update_last_contact($guest_id, $date, $user_id);

        return new WP_REST_Response([
            'message' => 'Last contact date updated',
            'date' => $date,
        ], 200);
    }

    /**
     * Extract contact data from request
     */
    private static function extract_contact_data($request) {
        $allowed = [
            'personal_email', 'secondary_email', 'phone', 'mobile_phone',
            'assistant_name', 'assistant_email', 'assistant_phone',
            'private_notes', 'relationship_notes', 'last_contact_date',
            'preferred_contact_method', 'source',
        ];

        $data = [];
        foreach ($allowed as $field) {
            $value = $request->get_param($field);
            if ($value !== null) {
                if (in_array($field, ['personal_email', 'secondary_email', 'assistant_email'])) {
                    $data[$field] = sanitize_email($value);
                } elseif (in_array($field, ['private_notes', 'relationship_notes'])) {
                    $data[$field] = sanitize_textarea_field($value);
                } else {
                    $data[$field] = sanitize_text_field($value);
                }
            }
        }

        return $data;
    }

    /**
     * Format contact for API response
     */
    private static function format_contact($row) {
        return [
            'id' => (int) $row->id,
            'guest_id' => (int) $row->guest_id,
            'user_id' => (int) $row->user_id,
            'guest_name' => $row->full_name ?? null,
            'guest_company' => $row->current_company ?? null,
            'public_email' => $row->public_email ?? null,
            'personal_email' => $row->personal_email,
            'secondary_email' => $row->secondary_email,
            'phone' => $row->phone,
            'mobile_phone' => $row->mobile_phone,
            'assistant_name' => $row->assistant_name,
            'assistant_email' => $row->assistant_email,
            'assistant_phone' => $row->assistant_phone,
            'private_notes' => $row->private_notes,
            'relationship_notes' => $row->relationship_notes,
            'last_contact_date' => $row->last_contact_date,
            'preferred_contact_method' => $row->preferred_contact_method,
            'source' => $row->source,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /**
     * Format guest with private data merged
     */
    private static function format_guest_with_private($row) {
        return [
            'id' => (int) $row->id,
            'full_name' => $row->full_name,
            'first_name' => $row->first_name,
            'last_name' => $row->last_name,
            'email' => $row->email,
            'linkedin_url' => $row->linkedin_url,
            'current_company' => $row->current_company,
            'current_role' => $row->current_role,
            'industry' => $row->industry,
            // Private contact fields
            'private_email' => $row->private_email,
            'private_secondary_email' => $row->private_secondary_email,
            'private_phone' => $row->private_phone,
            'private_mobile' => $row->private_mobile,
            'private_assistant_name' => $row->private_assistant_name,
            'private_assistant_email' => $row->private_assistant_email,
            'private_assistant_phone' => $row->private_assistant_phone,
            'private_notes' => $row->private_notes,
            'relationship_notes' => $row->relationship_notes,
            'last_contact_date' => $row->last_contact_date,
            'preferred_contact_method' => $row->preferred_contact_method,
            // Claiming info
            'claimed_by_user_id' => $row->claimed_by_user_id ? (int) $row->claimed_by_user_id : null,
            'claim_status' => $row->claim_status ?? 'unclaimed',
            // Timestamps
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}
