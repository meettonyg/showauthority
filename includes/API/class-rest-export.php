<?php
/**
 * REST API Export Controller
 *
 * Handles data export endpoints.
 *
 * @package PodcastInfluenceTracker
 * @subpackage API
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_REST_Export extends PIT_REST_Base {

    /**
     * Register routes
     */
    public static function register_routes() {
        register_rest_route(self::NAMESPACE, '/export/guests', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'export_guests'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/export/podcasts', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'export_podcasts'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/export/network', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'export_network'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);
    }

    /**
     * Export guests
     */
    public static function export_guests($request) {
        global $wpdb;
        $format = $request->get_param('format') ?? 'csv';
        $verified = $request->get_param('verified');
        $company_stage = $request->get_param('company_stage');

        $table = $wpdb->prefix . 'pit_guests';
        $where = ['is_merged = 0'];
        $params = [];

        if ($verified !== null && $verified !== '') {
            $where[] = 'manually_verified = %d';
            $params[] = (int) $verified;
        }

        if (!empty($company_stage)) {
            $where[] = 'company_stage = %s';
            $params[] = $company_stage;
        }

        $where_clause = implode(' AND ', $where);
        $query = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY full_name ASC";

        $guests = empty($params)
            ? $wpdb->get_results($query)
            : $wpdb->get_results($wpdb->prepare($query, $params));

        if ($format === 'json') {
            return rest_ensure_response($guests);
        }

        // CSV export using fputcsv for proper RFC 4180 compliance
        $stream = fopen('php://memory', 'w');
        fputcsv($stream, ['ID', 'Full Name', 'Email', 'LinkedIn URL', 'Current Company', 'Current Role', 'Company Stage', 'Industry', 'Verified', 'Created At']);

        foreach ($guests as $guest) {
            fputcsv($stream, [
                $guest->id,
                $guest->full_name ?? '',
                $guest->email ?? '',
                $guest->linkedin_url ?? '',
                $guest->current_company ?? '',
                $guest->current_role ?? '',
                $guest->company_stage ?? '',
                $guest->industry ?? '',
                $guest->manually_verified ? 'Yes' : 'No',
                $guest->created_at ?? '',
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="guests.csv"');
        echo $csv;
        exit;
    }

    /**
     * Export podcasts
     */
    public static function export_podcasts($request) {
        global $wpdb;
        $format = $request->get_param('format') ?? 'csv';

        $table = $wpdb->prefix . 'pit_podcasts';
        $podcasts = $wpdb->get_results("SELECT * FROM {$table} ORDER BY title ASC");

        if ($format === 'json') {
            return rest_ensure_response($podcasts);
        }

        // CSV export using fputcsv for proper RFC 4180 compliance
        $stream = fopen('php://memory', 'w');
        fputcsv($stream, ['ID', 'Title', 'Author', 'RSS URL', 'Website URL', 'iTunes ID', 'Tracking Status', 'Created At']);

        foreach ($podcasts as $podcast) {
            fputcsv($stream, [
                $podcast->id,
                $podcast->title ?? '',
                $podcast->author ?? '',
                $podcast->rss_feed_url ?? '',
                $podcast->website_url ?? '',
                $podcast->itunes_id ?? '',
                $podcast->tracking_status ?? '',
                $podcast->created_at ?? '',
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="podcasts.csv"');
        echo $csv;
        exit;
    }

    /**
     * Export network connections
     */
    public static function export_network($request) {
        global $wpdb;
        $format = $request->get_param('format') ?? 'csv';

        $guests_table = $wpdb->prefix . 'pit_guests';
        $appearances_table = $wpdb->prefix . 'pit_guest_appearances';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';

        // Get all 1st degree connections
        $connections = $wpdb->get_results("
            SELECT DISTINCT
                g1.id as guest1_id, g1.full_name as guest1_name,
                g2.id as guest2_id, g2.full_name as guest2_name,
                p.title as podcast_title
            FROM {$appearances_table} a1
            INNER JOIN {$appearances_table} a2
                ON a1.podcast_id = a2.podcast_id AND a1.guest_id < a2.guest_id
            INNER JOIN {$guests_table} g1 ON a1.guest_id = g1.id
            INNER JOIN {$guests_table} g2 ON a2.guest_id = g2.id
            INNER JOIN {$podcasts_table} p ON a1.podcast_id = p.id
            WHERE g1.is_merged = 0 AND g2.is_merged = 0
            ORDER BY g1.full_name, g2.full_name
        ");

        if ($format === 'json') {
            return rest_ensure_response($connections);
        }

        // CSV export using fputcsv for proper RFC 4180 compliance
        $stream = fopen('php://memory', 'w');
        fputcsv($stream, ['Guest 1 ID', 'Guest 1 Name', 'Guest 2 ID', 'Guest 2 Name', 'Shared Podcast']);

        foreach ($connections as $conn) {
            fputcsv($stream, [
                $conn->guest1_id,
                $conn->guest1_name ?? '',
                $conn->guest2_id,
                $conn->guest2_name ?? '',
                $conn->podcast_title ?? '',
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="network.csv"');
        echo $csv;
        exit;
    }
}
