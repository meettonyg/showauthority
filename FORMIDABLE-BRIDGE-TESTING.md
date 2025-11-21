# Formidable Bridge Testing Guide

## Overview

This guide helps you test the Formidable Bridge integration to verify that podcast deduplication works correctly across different data sources (Podcast Index, Taddy, RSS, manual entry).

## Prerequisites

1. **Configure Tracker Form ID** (required):
```php
// In WordPress admin or via code
update_option('pit_tracker_form_id', YOUR_FORM_ID);
```

2. **Add Taddy UUID Field** (recommended):
   - Add a hidden field to your Formidable form for Taddy UUID
   - Note the field key (e.g., `taddy_uuid` or similar)
   - Update line 100 in `class-formidable-podcast-bridge.php` if needed

3. **Verify Database Tables Exist**:
   - Go to WordPress admin
   - The plugin should auto-create tables on activation
   - Check that these tables exist:
     - `{prefix}_guestify_podcasts`
     - `{prefix}_guestify_contacts`
     - `{prefix}_guestify_social_accounts`
     - `{prefix}_guestify_podcast_contacts`
     - `{prefix}_guestify_interview_tracker_podcasts`

## Field Mapping Reference

The bridge automatically extracts these fields from your Formidable form:

| Field Key | Field ID | Purpose | Database Column |
|-----------|----------|---------|-----------------|
| `kb1pc` | 8111 | Podcast Name | `title` |
| `e69un` | 9928 | RSS Feed | `rss_feed_url` |
| `osjpb` | 9930 | Podcast Index ID | `podcast_index_id` |
| `aho2u` | 9931 | Podcast Index GUID | `podcast_index_guid` |
| `sa8as` | 9929 | iTunes ID | *(stored but not used for deduplication)* |
| `dvolk` | 9011 | Website | `website_url` |
| `e4lmu` | 8112 | Description | `description` |
| `mbu0g` | 8115 | Host Name | Contact table |
| `j44t3` | 8277 | Email | Contact table |

## Test Scenarios

### Test 1: Create First Entry with Podcast Index Data

**Goal**: Verify a podcast is created when it doesn't exist.

1. **Create Formidable Entry**:
   - Podcast Name: "Podcast Mark"
   - RSS Feed: "https://podcastmark.com/feed"
   - PodID (osjpb): `920666`
   - PodGuid (aho2u): `9b024349-ccf0-5f69-a609-6b82873eab3c`
   - Host Name: "Mark de Grasse"
   - Email: "mark@example.com"

2. **Expected Result**:
   - New podcast created in `guestify_podcasts` table
   - `podcast_index_id` = 920666
   - `podcast_index_guid` = 9b024349-ccf0-5f69-a609-6b82873eab3c
   - `source` = 'podcast_index'
   - `data_quality_score` = 90 (has RSS + external ID + website)
   - New contact created in `guestify_contacts` table
   - Link created in `guestify_podcast_contacts` (is_primary = 1)
   - Entry linked in `guestify_interview_tracker_podcasts`
   - Meta stored: `_guestify_podcast_id` in Formidable entry meta

3. **Verification**:
```sql
-- Check podcast was created
SELECT id, title, podcast_index_id, source, data_quality_score
FROM wp_guestify_podcasts
WHERE podcast_index_id = 920666;

-- Check contact was created
SELECT id, full_name, email, role
FROM wp_guestify_contacts
WHERE email = 'mark@example.com';

-- Check bridge table
SELECT entry_id, podcast_id, primary_contact_id
FROM wp_guestify_interview_tracker_podcasts
WHERE entry_id = YOUR_ENTRY_ID;
```

### Test 2: Create Second Entry with SAME Podcast (Same Podcast Index ID)

**Goal**: Verify deduplication - no duplicate podcast created.

1. **Create Second Formidable Entry**:
   - Podcast Name: "Podcast Mark" (same)
   - RSS Feed: "https://podcastmark.com/feed" (same)
   - PodID (osjpb): `920666` (SAME ID)
   - Host Name: "Mark de Grasse"
   - Email: "mark@example.com"

2. **Expected Result**:
   - **NO new podcast created** (existing one used)
   - New entry in `guestify_interview_tracker_podcasts` bridge table
   - Both entries now reference the SAME `podcast_id`
   - Contact reused (not duplicated)

3. **Verification**:
```sql
-- Should return ONLY 1 podcast (not 2!)
SELECT COUNT(*) as podcast_count
FROM wp_guestify_podcasts
WHERE podcast_index_id = 920666;
-- Expected: 1

-- Should return 2 bridge records (both entries reference same podcast)
SELECT entry_id, podcast_id
FROM wp_guestify_interview_tracker_podcasts
WHERE podcast_id = (
    SELECT id FROM wp_guestify_podcasts WHERE podcast_index_id = 920666
);
-- Expected: 2 rows with SAME podcast_id
```

### Test 3: Add SAME Podcast via Taddy UUID (Cross-Source Deduplication)

**Goal**: Verify different sources don't create duplicates if RSS matches.

1. **Create Third Formidable Entry**:
   - Podcast Name: "Podcast Mark"
   - RSS Feed: "https://podcastmark.com/feed" (SAME RSS)
   - PodID: *(leave empty)*
   - Taddy UUID: `a1b2c3d4-e5f6-7890-abcd-ef1234567890` (new field)
   - Host Name: "Mark de Grasse"

2. **Expected Result**:
   - **NO new podcast created**
   - Existing podcast found by `rss_feed_url` match
   - Podcast record UPDATED with `taddy_podcast_uuid`
   - Now podcast has BOTH Podcast Index ID AND Taddy UUID
   - New bridge entry created for this entry

3. **Verification**:
```sql
-- Should still be only 1 podcast, but now with Taddy UUID added
SELECT id, podcast_index_id, taddy_podcast_uuid, source
FROM wp_guestify_podcasts
WHERE rss_feed_url = 'https://podcastmark.com/feed';
-- Expected: 1 row with BOTH podcast_index_id AND taddy_podcast_uuid filled

-- Should return 3 bridge records now
SELECT COUNT(*) as entry_count
FROM wp_guestify_interview_tracker_podcasts
WHERE podcast_id = (
    SELECT id FROM wp_guestify_podcasts WHERE rss_feed_url = 'https://podcastmark.com/feed'
);
-- Expected: 3
```

### Test 4: Manual Entry Without External IDs

**Goal**: Verify manual entries work and use RSS/slug for matching.

1. **Create Fourth Formidable Entry**:
   - Podcast Name: "Another Podcast"
   - RSS Feed: "https://anotherpodcast.com/feed"
   - PodID: *(leave empty)*
   - PodGuid: *(leave empty)*
   - Taddy UUID: *(leave empty)*
   - Host Name: "Jane Doe"
   - Email: "jane@example.com"

2. **Expected Result**:
   - New podcast created (RSS URL not found in database)
   - `source` = 'formidable'
   - `data_quality_score` = 50 (has RSS but no external IDs)
   - New contact created

3. **Verification**:
```sql
SELECT id, title, source, data_quality_score, podcast_index_id
FROM wp_guestify_podcasts
WHERE rss_feed_url = 'https://anotherpodcast.com/feed';
-- Expected: 1 row with source='formidable', podcast_index_id=NULL
```

### Test 5: Update Entry with Additional Data

**Goal**: Verify updating entry enriches podcast data.

1. **Edit First Entry** (from Test 1):
   - Add Website: "https://podcastmark.com"
   - Add Description: "A podcast about marketing"

2. **Expected Result**:
   - Podcast record updated with website and description
   - `data_quality_score` increases (more complete data)
   - No duplicate created

3. **Verification**:
```sql
SELECT website_url, description, data_quality_score
FROM wp_guestify_podcasts
WHERE podcast_index_id = 920666;
-- Expected: website and description now filled
```

### Test 6: Multiple Users, Same Podcast (Real-World Scenario)

**Goal**: Verify multiple Interview Tracker users can track same podcast.

1. **User A creates entry**:
   - Podcast: "Lex Fridman Podcast"
   - PodID: `123456`
   - Status: "researching"

2. **User B creates entry**:
   - Podcast: "Lex Fridman Podcast"
   - PodID: `123456` (SAME)
   - Status: "pitched"

3. **Expected Result**:
   - Only 1 podcast record for "Lex Fridman Podcast"
   - 2 bridge table entries (one per user)
   - Each user's entry has independent `outreach_status`

4. **Verification**:
```sql
-- Should return 2 entries, SAME podcast_id, different outreach_status
SELECT entry_id, podcast_id, outreach_status
FROM wp_guestify_interview_tracker_podcasts
WHERE podcast_id = (
    SELECT id FROM wp_guestify_podcasts WHERE podcast_index_id = 123456
);
```

## Data Quality Score Testing

The quality score is calculated based on:
- Base: 30 points
- RSS feed: +20 points
- Description: +10 points
- External ID: +30 points
- Website: +10 points
- **Max: 100 points**

| Data Present | Expected Score |
|--------------|----------------|
| Name only | 30 |
| Name + RSS | 50 |
| Name + RSS + Website | 60 |
| Name + RSS + Podcast Index ID | 80 |
| Name + RSS + Podcast Index ID + Website + Description | 100 |

**Test**:
```sql
SELECT title,
       CASE WHEN rss_feed_url IS NOT NULL THEN 'Yes' ELSE 'No' END as has_rss,
       CASE WHEN description IS NOT NULL THEN 'Yes' ELSE 'No' END as has_desc,
       CASE WHEN podcast_index_id IS NOT NULL THEN 'Yes' ELSE 'No' END as has_external_id,
       CASE WHEN website_url IS NOT NULL THEN 'Yes' ELSE 'No' END as has_website,
       data_quality_score
FROM wp_guestify_podcasts
ORDER BY data_quality_score DESC;
```

## Troubleshooting

### Problem: Podcasts Not Auto-Populating

**Check**:
1. Is `pit_tracker_form_id` option set?
```php
get_option('pit_tracker_form_id'); // Should return your form ID
```

2. Are hooks firing?
```php
// Add to functions.php temporarily
add_action('frm_after_create_entry', function($entry_id, $form_id) {
    error_log("Entry created: {$entry_id}, Form: {$form_id}");
}, 5, 2);
```

3. Check for PHP errors in debug log:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
// Check wp-content/debug.log
```

### Problem: Duplicates Still Being Created

**Check**:
1. Verify external IDs are actually being stored:
```sql
SELECT id, title, podcast_index_id, podcast_index_guid, taddy_podcast_uuid, rss_feed_url
FROM wp_guestify_podcasts;
-- If all NULL, field mapping might be wrong
```

2. Check field keys match your form:
   - Go to Formidable → Forms → Edit your form
   - Hover over each field, check "field_key" in the URL
   - Compare to field keys in `class-formidable-podcast-bridge.php` lines 86-96

### Problem: Contact Not Created

**Check**:
1. Is host name or email present in entry?
```sql
SELECT * FROM wp_frm_item_metas
WHERE item_id = YOUR_ENTRY_ID
AND field_id IN (8115, 8277); -- Host name and email fields
```

2. Check contacts table:
```sql
SELECT * FROM wp_guestify_contacts
WHERE enrichment_source = 'formidable'
ORDER BY created_at DESC
LIMIT 5;
```

## REST API Testing

You can also test via REST API:

```bash
# Get entry's podcast
curl -X GET "https://yoursite.com/wp-json/podcast-influence/v1/entries/123/podcast" \
  -H "X-WP-Nonce: YOUR_NONCE"

# Get entry's contact
curl -X GET "https://yoursite.com/wp-json/podcast-influence/v1/entries/123/contact-email" \
  -H "X-WP-Nonce: YOUR_NONCE"

# Get all podcasts from Podcast Index
curl -X GET "https://yoursite.com/wp-json/podcast-influence/v1/intelligence/podcasts?source=podcast_index"
```

## Migration: Backfill Existing Entries

If you have existing Formidable entries before implementing this, run this one-time migration:

```php
// Add to functions.php temporarily, then remove after running once
add_action('init', function() {
    if (!isset($_GET['backfill_podcasts'])) return;

    global $wpdb;
    $form_id = get_option('pit_tracker_form_id');

    // Get all entries for this form
    $entries = $wpdb->get_results($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}frm_items WHERE form_id = %d",
        $form_id
    ));

    $bridge = PIT_Formidable_Podcast_Bridge::get_instance();
    $processed = 0;

    foreach ($entries as $entry) {
        $bridge->auto_populate_podcast($entry->id);
        $processed++;
    }

    wp_die("Backfilled {$processed} entries. Remove this code from functions.php.");
});

// Visit: https://yoursite.com/?backfill_podcasts=1
```

## Success Criteria

✅ **Deduplication Working**:
- Same Podcast Index ID → 1 podcast record, multiple bridge entries
- Same RSS URL → 1 podcast record (even with different external IDs)
- Multiple users can add entries for same podcast independently

✅ **Progressive Enrichment Working**:
- Adding Taddy UUID to existing Podcast Index podcast → updates record
- Adding description/website later → updates record
- Data quality scores reflect completeness

✅ **Fast Lookups Working**:
- `_guestify_podcast_id` meta stored in Formidable entries
- Can query podcast data directly via `podcast_id` without joins

✅ **Contact Management Working**:
- Contacts created from host name/email fields
- Primary contact marked correctly
- `[guestify_contact]` shortcode displays contact info

## Next Steps

After verifying these tests pass:

1. **Phase 2**: Implement admin UI for manual podcast entry
2. **Phase 3**: Wire up RSS parser for auto-enrichment
3. **Phase 4**: Add Clay enrichment API integration
4. **Phase 5**: Build Vue 3 podcast library interface

## Support

- Database Schema: `/PODCAST-INTELLIGENCE-DATABASE.md`
- External IDs: `/PODCAST-DIRECTORY-INTEGRATION.md`
- Email Integration: `/EMAIL-PLUGIN-INTEGRATION.md`
- Installation: `/INSTALLATION.md`
