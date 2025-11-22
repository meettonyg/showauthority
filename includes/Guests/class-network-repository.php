<?php
/**
 * Network Repository
 *
 * Handles database operations for guest network connections.
 *
 * @package PodcastInfluenceTracker
 * @subpackage Guests
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Network_Repository {

    /**
     * Get connections for a guest
     *
     * @param int $guest_id Guest ID
     * @param int $max_degree Max connection degree (1 or 2)
     * @return array Connections grouped by degree
     */
    public static function get_connections($guest_id, $max_degree = 1) {
        global $wpdb;
        $guests_table = $wpdb->prefix . 'pit_guests';
        $appearances_table = $wpdb->prefix . 'pit_guest_appearances';
        $podcasts_table = $wpdb->prefix . 'pit_podcasts';

        $result = [
            'first_degree' => [],
            'second_degree' => [],
        ];

        // First degree: Guests who appeared on the same podcast
        $first_degree = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT
                g.id, g.full_name, g.current_company, g.current_role,
                g.linkedin_url, g.manually_verified,
                GROUP_CONCAT(DISTINCT p.title SEPARATOR ', ') as shared_podcasts,
                COUNT(DISTINCT a2.podcast_id) as connection_strength
            FROM {$appearances_table} a1
            INNER JOIN {$appearances_table} a2 ON a1.podcast_id = a2.podcast_id AND a1.guest_id != a2.guest_id
            INNER JOIN {$guests_table} g ON a2.guest_id = g.id
            INNER JOIN {$podcasts_table} p ON a1.podcast_id = p.id
            WHERE a1.guest_id = %d AND g.is_merged = 0
            GROUP BY g.id
            ORDER BY connection_strength DESC, g.full_name ASC
        ", $guest_id));

        $result['first_degree'] = $first_degree;
        $first_degree_ids = array_column($first_degree, 'id');

        // Second degree: Friends of friends (if requested)
        if ($max_degree >= 2 && !empty($first_degree_ids)) {
            $ids_placeholder = implode(',', array_fill(0, count($first_degree_ids), '%d'));

            $second_degree = $wpdb->get_results($wpdb->prepare("
                SELECT DISTINCT
                    g.id, g.full_name, g.current_company, g.current_role,
                    g.linkedin_url, g.manually_verified,
                    g2.full_name as connected_via_name,
                    GROUP_CONCAT(DISTINCT p.title SEPARATOR ', ') as shared_podcasts,
                    COUNT(DISTINCT a2.podcast_id) as connection_strength
                FROM {$appearances_table} a1
                INNER JOIN {$appearances_table} a2 ON a1.podcast_id = a2.podcast_id AND a1.guest_id != a2.guest_id
                INNER JOIN {$guests_table} g ON a2.guest_id = g.id
                INNER JOIN {$guests_table} g2 ON a1.guest_id = g2.id
                INNER JOIN {$podcasts_table} p ON a1.podcast_id = p.id
                WHERE a1.guest_id IN ({$ids_placeholder})
                    AND a2.guest_id != %d
                    AND a2.guest_id NOT IN ({$ids_placeholder})
                    AND g.is_merged = 0
                GROUP BY g.id
                ORDER BY connection_strength DESC, g.full_name ASC
                LIMIT 50
            ", ...array_merge($first_degree_ids, [$guest_id], $first_degree_ids)));

            $result['second_degree'] = $second_degree;
        }

        return $result;
    }

    /**
     * Calculate and cache network connections for a guest
     *
     * @param int $guest_id Guest ID
     */
    public static function calculate_network($guest_id) {
        global $wpdb;
        $network_table = $wpdb->prefix . 'pit_guest_network';
        $appearances_table = $wpdb->prefix . 'pit_guest_appearances';

        // Clear existing connections
        $wpdb->delete($network_table, ['guest_id' => $guest_id], ['%d']);

        // Calculate first-degree connections
        $connections = $wpdb->get_results($wpdb->prepare("
            SELECT
                a2.guest_id as connected_guest_id,
                'co_appearance' as connection_type,
                1 as connection_degree,
                COUNT(DISTINCT a1.podcast_id) as connection_strength,
                GROUP_CONCAT(DISTINCT a1.podcast_id) as common_podcasts
            FROM {$appearances_table} a1
            INNER JOIN {$appearances_table} a2 ON a1.podcast_id = a2.podcast_id AND a1.guest_id != a2.guest_id
            WHERE a1.guest_id = %d
            GROUP BY a2.guest_id
        ", $guest_id));

        $now = current_time('mysql');
        $expires = date('Y-m-d H:i:s', strtotime('+7 days'));

        foreach ($connections as $conn) {
            $wpdb->insert($network_table, [
                'guest_id' => $guest_id,
                'connected_guest_id' => $conn->connected_guest_id,
                'connection_type' => $conn->connection_type,
                'connection_degree' => $conn->connection_degree,
                'connection_strength' => $conn->connection_strength,
                'common_podcasts' => $conn->common_podcasts,
                'last_calculated' => $now,
                'cache_expires_at' => $expires,
            ]);
        }
    }

    /**
     * Get network statistics
     *
     * @return array
     */
    public static function get_statistics() {
        global $wpdb;
        $appearances_table = $wpdb->prefix . 'pit_guest_appearances';
        $guests_table = $wpdb->prefix . 'pit_guests';

        // Count guests with network connections
        $guests_with_connections = $wpdb->get_var("
            SELECT COUNT(DISTINCT a1.guest_id)
            FROM {$appearances_table} a1
            INNER JOIN {$appearances_table} a2 ON a1.podcast_id = a2.podcast_id AND a1.guest_id != a2.guest_id
            INNER JOIN {$guests_table} g ON a1.guest_id = g.id
            WHERE g.is_merged = 0
        ");

        return [
            'guests_with_connections' => (int) $guests_with_connections,
        ];
    }
}
