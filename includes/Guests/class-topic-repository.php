<?php
/**
 * Topic Repository
 *
 * Handles database operations for topics taxonomy and guest-topic relationships.
 *
 * @package PodcastInfluenceTracker
 * @subpackage Guests
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Topic_Repository {

    /**
     * Get topic by ID
     *
     * @param int $topic_id Topic ID
     * @return object|null
     */
    public static function get($topic_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_topics';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $topic_id
        ));
    }

    /**
     * Get topic by slug
     *
     * @param string $slug Topic slug
     * @return object|null
     */
    public static function get_by_slug($slug) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_topics';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE slug = %s",
            $slug
        ));
    }

    /**
     * Get or create topic by name
     *
     * @param string $name Topic name
     * @param string|null $category Topic category
     * @return int Topic ID
     */
    public static function get_or_create($name, $category = null) {
        $slug = sanitize_title($name);
        $existing = self::get_by_slug($slug);

        if ($existing) {
            return $existing->id;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pit_topics';

        $wpdb->insert($table, [
            'name' => $name,
            'slug' => $slug,
            'category' => $category,
            'usage_count' => 0,
        ]);

        return $wpdb->insert_id;
    }

    /**
     * List topics
     *
     * @param array $args Query arguments
     * @return array
     */
    public static function list($args = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_topics';

        $defaults = [
            'category' => '',
            'orderby' => 'usage_count',
            'order' => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);

        $where = '1=1';
        $params = [];

        if (!empty($args['category'])) {
            $where .= ' AND category = %s';
            $params[] = $args['category'];
        }

        $allowed_orderby = ['usage_count', 'name', 'created_at'];
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'usage_count';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $sql = "SELECT * FROM $table WHERE $where ORDER BY $orderby $order";

        if (!empty($params)) {
            return $wpdb->get_results($wpdb->prepare($sql, $params));
        }

        return $wpdb->get_results($sql);
    }

    /**
     * Get topics for a guest
     *
     * @param int $guest_id Guest ID
     * @return array
     */
    public static function get_for_guest($guest_id) {
        global $wpdb;
        $topics_table = $wpdb->prefix . 'pit_topics';
        $guest_topics_table = $wpdb->prefix . 'pit_guest_topics';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT t.*, gt.confidence_score, gt.mention_count, gt.source
            FROM $topics_table t
            INNER JOIN $guest_topics_table gt ON t.id = gt.topic_id
            WHERE gt.guest_id = %d
            ORDER BY gt.confidence_score DESC, gt.mention_count DESC",
            $guest_id
        ));
    }

    /**
     * Assign topic to guest
     *
     * @param int $guest_id Guest ID
     * @param int $topic_id Topic ID
     * @param int $confidence Confidence score (0-100)
     * @param string $source Source of assignment
     * @return int|false Guest-topic relationship ID or false
     */
    public static function assign_to_guest($guest_id, $topic_id, $confidence = 100, $source = 'manual') {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guest_topics';

        // Check if already exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE guest_id = %d AND topic_id = %d",
            $guest_id, $topic_id
        ));

        if ($existing) {
            // Update mention count
            $wpdb->update($table, [
                'mention_count' => $existing->mention_count + 1,
                'confidence_score' => max($existing->confidence_score, $confidence),
            ], ['id' => $existing->id]);

            return $existing->id;
        }

        $wpdb->insert($table, [
            'guest_id' => $guest_id,
            'topic_id' => $topic_id,
            'confidence_score' => $confidence,
            'mention_count' => 1,
            'source' => $source,
        ]);

        // Update usage count
        if ($wpdb->insert_id) {
            self::increment_usage_count($topic_id);
        }

        return $wpdb->insert_id ?: false;
    }

    /**
     * Remove topic from guest
     *
     * @param int $guest_id Guest ID
     * @param int $topic_id Topic ID
     * @return bool
     */
    public static function remove_from_guest($guest_id, $topic_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guest_topics';

        $result = $wpdb->delete($table, [
            'guest_id' => $guest_id,
            'topic_id' => $topic_id,
        ], ['%d', '%d']);

        if ($result) {
            self::decrement_usage_count($topic_id);
        }

        return $result !== false;
    }

    /**
     * Increment topic usage count
     *
     * @param int $topic_id Topic ID
     */
    private static function increment_usage_count($topic_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_topics';

        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET usage_count = usage_count + 1 WHERE id = %d",
            $topic_id
        ));
    }

    /**
     * Decrement topic usage count
     *
     * @param int $topic_id Topic ID
     */
    private static function decrement_usage_count($topic_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_topics';

        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET usage_count = GREATEST(usage_count - 1, 0) WHERE id = %d",
            $topic_id
        ));
    }
}
