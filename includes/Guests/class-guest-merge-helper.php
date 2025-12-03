<?php
/**
 * Guest Merge Helper
 * 
 * Handles smart merging of duplicate guest records.
 * Fills blanks, never overwrites good data with empty data.
 *
 * @package PodcastInfluenceTracker
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Guest_Merge_Helper {

    /**
     * Text fields that can be merged (fill if empty)
     */
    const TEXT_FIELDS = [
        'first_name', 'last_name',
        'linkedin_url', 'email',
        'current_company', 'current_role', 'industry',
        'company_stage', 'company_revenue',
        'twitter_handle', 'instagram_handle', 'youtube_channel', 'website_url',
        'city', 'state_region', 'country', 'timezone',
        'enrichment_provider', 'enrichment_level',
    ];

    /**
     * JSON fields that should be merged (combine arrays)
     */
    const JSON_FIELDS = [
        'expertise_areas',
        'past_companies',
        'education',
        'notable_achievements',
        'verified_accounts',
    ];

    /**
     * Numeric fields where higher value wins
     */
    const NUMERIC_FIELDS = [
        'linkedin_connections',
        'twitter_followers',
        'instagram_followers',
        'youtube_subscribers',
        'data_quality_score',
        'verification_count',
    ];

    /**
     * Smart merge: Fill blanks, never overwrite good data with empty
     * 
     * @param object $master The record to keep (highest quality score)
     * @param object $duplicate The record being merged in
     * @return array Fields that should be updated on master
     */
    public static function smart_merge($master, $duplicate) {
        $updates = [];

        // Text fields: Only fill if master is empty AND duplicate has data
        foreach (self::TEXT_FIELDS as $field) {
            if (self::is_empty($master->$field ?? null) && !self::is_empty($duplicate->$field ?? null)) {
                $updates[$field] = $duplicate->$field;
            }
        }

        // JSON fields: Merge arrays
        foreach (self::JSON_FIELDS as $field) {
            $master_val = $master->$field ?? null;
            $dupe_val = $duplicate->$field ?? null;

            if (!self::is_empty($master_val) && !self::is_empty($dupe_val)) {
                // Both have data - merge arrays
                $master_arr = self::parse_json_array($master_val);
                $dupe_arr = self::parse_json_array($dupe_val);
                $merged = array_values(array_unique(array_merge($master_arr, $dupe_arr)));
                
                if (count($merged) > count($master_arr)) {
                    $updates[$field] = json_encode($merged);
                }
            } elseif (self::is_empty($master_val) && !self::is_empty($dupe_val)) {
                // Master empty, duplicate has data
                $updates[$field] = $dupe_val;
            }
        }

        // Numeric fields: Take higher values
        foreach (self::NUMERIC_FIELDS as $field) {
            $master_val = (int) ($master->$field ?? 0);
            $dupe_val = (int) ($duplicate->$field ?? 0);
            
            if ($dupe_val > $master_val) {
                $updates[$field] = $dupe_val;
            }
        }

        // Take most recent enrichment date
        $master_enriched = $master->enriched_at ?? null;
        $dupe_enriched = $duplicate->enriched_at ?? null;
        
        if (!empty($dupe_enriched) && (empty($master_enriched) || $dupe_enriched > $master_enriched)) {
            $updates['enriched_at'] = $dupe_enriched;
            if (!empty($duplicate->enrichment_provider)) {
                $updates['enrichment_provider'] = $duplicate->enrichment_provider;
            }
            if (!empty($duplicate->enrichment_level)) {
                $updates['enrichment_level'] = $duplicate->enrichment_level;
            }
        }

        // Hash fields - update if master is missing
        if (self::is_empty($master->email_hash ?? null) && !self::is_empty($duplicate->email_hash ?? null)) {
            $updates['email_hash'] = $duplicate->email_hash;
        }
        if (self::is_empty($master->linkedin_url_hash ?? null) && !self::is_empty($duplicate->linkedin_url_hash ?? null)) {
            $updates['linkedin_url_hash'] = $duplicate->linkedin_url_hash;
        }

        return $updates;
    }

    /**
     * Execute a full guest merge
     * 
     * @param int $master_id The guest ID to keep
     * @param int $duplicate_id The guest ID to merge and mark as merged
     * @param bool $dry_run If true, only return what would happen
     * @return array Result of merge operation
     */
    public static function execute_merge($master_id, $duplicate_id, $dry_run = false) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guests';

        // Get both records
        $master = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $master_id));
        $duplicate = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $duplicate_id));

        if (!$master || !$duplicate) {
            return [
                'success' => false,
                'error' => 'One or both guest records not found',
            ];
        }

        // Check if duplicate is already merged
        if ($duplicate->is_merged) {
            return [
                'success' => false,
                'error' => 'Duplicate record is already merged',
            ];
        }

        // Get field updates
        $updates = self::smart_merge($master, $duplicate);

        $result = [
            'success' => true,
            'master_id' => $master_id,
            'duplicate_id' => $duplicate_id,
            'fields_updated' => array_keys($updates),
            'foreign_keys_updated' => [],
        ];

        if ($dry_run) {
            $result['dry_run'] = true;
            $result['would_update'] = $updates;
            return $result;
        }

        // Update master record with merged fields
        if (!empty($updates)) {
            $updates['updated_at'] = current_time('mysql');
            $wpdb->update($table, $updates, ['id' => $master_id]);
        }

        // Update foreign key references in related tables
        $result['foreign_keys_updated'] = self::update_foreign_keys($master_id, $duplicate_id);

        // Mark duplicate as merged
        $merge_history = [
            'merged_at' => current_time('mysql'),
            'merged_into' => $master_id,
            'fields_transferred' => array_keys($updates),
            'foreign_keys_migrated' => $result['foreign_keys_updated'],
        ];

        $wpdb->update($table, [
            'is_merged' => 1,
            'merged_into_guest_id' => $master_id,
            'merge_history' => json_encode($merge_history),
            'updated_at' => current_time('mysql'),
        ], ['id' => $duplicate_id]);

        // Recalculate master's data quality score
        self::recalculate_quality_score($master_id);

        return $result;
    }

    /**
     * Update foreign key references from duplicate to master
     */
    private static function update_foreign_keys($master_id, $duplicate_id) {
        global $wpdb;

        $tables_with_guest_id = [
            'pit_opportunities',
            'pit_speaking_credits',
            'pit_guest_private_contacts',
            'pit_guest_topics',
            'pit_guest_appearances', // Legacy table
        ];

        $updates = [];

        foreach ($tables_with_guest_id as $table) {
            $full_table = $wpdb->prefix . $table;
            
            // Check if table exists
            if ($wpdb->get_var("SHOW TABLES LIKE '$full_table'") !== $full_table) {
                continue;
            }

            // Count and update
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $full_table WHERE guest_id = %d",
                $duplicate_id
            ));

            if ($count > 0) {
                $wpdb->update(
                    $full_table,
                    ['guest_id' => $master_id],
                    ['guest_id' => $duplicate_id]
                );
                $updates[$table] = (int) $count;
            }
        }

        return $updates;
    }

    /**
     * Recalculate data quality score for a guest
     */
    public static function recalculate_quality_score($guest_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guests';

        $guest = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $guest_id));
        if (!$guest) {
            return 0;
        }

        $score = 0;

        // Basic identity (20 points)
        if (!self::is_empty($guest->full_name)) $score += 5;
        if (!self::is_empty($guest->first_name) && !self::is_empty($guest->last_name)) $score += 5;
        if (!self::is_empty($guest->email)) $score += 10;

        // Professional info (25 points)
        if (!self::is_empty($guest->current_company)) $score += 10;
        if (!self::is_empty($guest->current_role)) $score += 10;
        if (!self::is_empty($guest->industry)) $score += 5;

        // Contact/Social (25 points)
        if (!self::is_empty($guest->linkedin_url)) $score += 15;
        if (!self::is_empty($guest->twitter_handle)) $score += 5;
        if (!self::is_empty($guest->website_url)) $score += 5;

        // Location (10 points)
        if (!self::is_empty($guest->city)) $score += 3;
        if (!self::is_empty($guest->state_region)) $score += 3;
        if (!self::is_empty($guest->country)) $score += 4;

        // Enrichment (10 points)
        if (!self::is_empty($guest->enriched_at)) $score += 10;

        // Verification (10 points)
        if ($guest->manually_verified) $score += 5;
        if ($guest->claim_status === 'verified') $score += 5;

        // Update the score
        $wpdb->update($table, ['data_quality_score' => $score], ['id' => $guest_id]);

        return $score;
    }

    /**
     * Find duplicate groups for review
     * 
     * @return array Groups of duplicate guests
     */
    public static function find_duplicates() {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guests';

        $groups = [];

        // By email
        $email_dupes = $wpdb->get_results(
            "SELECT email_hash, email, 
                    GROUP_CONCAT(id ORDER BY data_quality_score DESC) as ids,
                    GROUP_CONCAT(full_name ORDER BY data_quality_score DESC SEPARATOR ' | ') as names,
                    COUNT(*) as cnt
             FROM $table
             WHERE email_hash IS NOT NULL AND email_hash != '' 
                   AND (is_merged IS NULL OR is_merged = 0)
             GROUP BY email_hash
             HAVING cnt > 1
             ORDER BY cnt DESC"
        );

        foreach ($email_dupes as $dupe) {
            $ids = explode(',', $dupe->ids);
            $groups[] = [
                'type' => 'email',
                'key' => $dupe->email,
                'ids' => $ids,
                'names' => $dupe->names,
                'count' => (int) $dupe->cnt,
                'suggested_master' => $ids[0], // First ID has highest quality score
            ];
        }

        // By LinkedIn
        $linkedin_dupes = $wpdb->get_results(
            "SELECT linkedin_url_hash, linkedin_url,
                    GROUP_CONCAT(id ORDER BY data_quality_score DESC) as ids,
                    GROUP_CONCAT(full_name ORDER BY data_quality_score DESC SEPARATOR ' | ') as names,
                    COUNT(*) as cnt
             FROM $table
             WHERE linkedin_url_hash IS NOT NULL AND linkedin_url_hash != ''
                   AND (is_merged IS NULL OR is_merged = 0)
             GROUP BY linkedin_url_hash
             HAVING cnt > 1
             ORDER BY cnt DESC"
        );

        foreach ($linkedin_dupes as $dupe) {
            // Check if this group already exists (by email)
            $ids = explode(',', $dupe->ids);
            $already_grouped = false;
            
            foreach ($groups as $existing) {
                if (count(array_intersect($existing['ids'], $ids)) > 0) {
                    $already_grouped = true;
                    break;
                }
            }

            if (!$already_grouped) {
                $groups[] = [
                    'type' => 'linkedin',
                    'key' => $dupe->linkedin_url,
                    'ids' => $ids,
                    'names' => $dupe->names,
                    'count' => (int) $dupe->cnt,
                    'suggested_master' => $ids[0],
                ];
            }
        }

        return $groups;
    }

    /**
     * Auto-merge obvious duplicates (same email AND same LinkedIn)
     * 
     * @param bool $dry_run
     * @return array Results
     */
    public static function auto_merge_obvious_duplicates($dry_run = true) {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_guests';

        $results = [
            'dry_run' => $dry_run,
            'merged' => 0,
            'details' => [],
        ];

        // Find guests with both same email AND same LinkedIn
        $obvious_dupes = $wpdb->get_results(
            "SELECT email_hash, linkedin_url_hash,
                    GROUP_CONCAT(id ORDER BY data_quality_score DESC) as ids,
                    COUNT(*) as cnt
             FROM $table
             WHERE email_hash IS NOT NULL AND email_hash != ''
                   AND linkedin_url_hash IS NOT NULL AND linkedin_url_hash != ''
                   AND (is_merged IS NULL OR is_merged = 0)
             GROUP BY email_hash, linkedin_url_hash
             HAVING cnt > 1"
        );

        foreach ($obvious_dupes as $dupe) {
            $ids = explode(',', $dupe->ids);
            $master_id = (int) $ids[0];

            for ($i = 1; $i < count($ids); $i++) {
                $duplicate_id = (int) $ids[$i];
                
                $merge_result = self::execute_merge($master_id, $duplicate_id, $dry_run);
                
                $results['details'][] = [
                    'master_id' => $master_id,
                    'duplicate_id' => $duplicate_id,
                    'result' => $merge_result,
                ];

                if ($merge_result['success']) {
                    $results['merged']++;
                }
            }
        }

        return $results;
    }

    /**
     * Check if a value is empty
     */
    private static function is_empty($value) {
        return $value === null || $value === '' || $value === '[]' || $value === '{}';
    }

    /**
     * Parse JSON to array safely
     */
    private static function parse_json_array($value) {
        if (is_array($value)) {
            return $value;
        }
        
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        
        return [];
    }
}
