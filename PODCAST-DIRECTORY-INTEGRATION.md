# Podcast Directory Integration Guide

## Overview

The Podcast Intelligence Database supports external IDs from **Taddy** and **Podcast Index**, preventing duplicates and enabling data synchronization.

## Unique Identifiers

### Primary Key (Internal)
```
id - Auto-increment internal ID
```

### External IDs (From Podcast Directories)
```sql
podcast_index_id      bigint(20)      -- Podcast Index feedId
podcast_index_guid    varchar(255)    -- Podcast Index podcastGuid
taddy_podcast_uuid    varchar(255)    -- Taddy podcast UUID
source                varchar(50)     -- 'podcast_index', 'taddy', 'manual'
```

All external IDs have **UNIQUE indexes** to prevent duplicates.

## Data Sources

### Podcast Index
**API:** https://podcastindex.org/
**Identifiers:**
- `feedId` (numeric) → `podcast_index_id`
- `podcastGuid` (UUID string) → `podcast_index_guid`

**Example Response:**
```json
{
  "id": 920666,
  "podcastGuid": "9b024349-ccf0-5f69-a609-6b82873eab3c",
  "title": "Podcast Mark",
  "url": "https://podcastmark.com/feed",
  "description": "...",
  "author": "Mark de Grasse",
  "link": "https://podcastmark.com"
}
```

### Taddy
**API:** https://taddy.org/
**Identifiers:**
- `uuid` (UUID string) → `taddy_podcast_uuid`

**Example Response:**
```json
{
  "uuid": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "name": "Podcast Mark",
  "rssFeedUrl": "https://podcastmark.com/feed",
  "description": "...",
  "websiteUrl": "https://podcastmark.com"
}
```

## Integration Methods

### Method 1: Store IDs in Formidable Forms (Recommended)

Add hidden fields to your Interview Tracker form to store external IDs:

**Formidable Form Fields:**
```
- podcast_name (text)
- podcast_index_id (hidden)
- podcast_index_guid (hidden)
- taddy_podcast_uuid (hidden)
- podcast_source (hidden) - 'podcast_index' or 'taddy'
- rss_feed (text/hidden)
- website (text/hidden)
```

**When user selects podcast from Podcast Index:**
```javascript
// In your podcast search interface
function selectPodcastFromIndex(podcast) {
  // Populate Formidable fields
  jQuery('[name="item_meta[podcast_name]"]').val(podcast.title);
  jQuery('[name="item_meta[podcast_index_id]"]').val(podcast.id);
  jQuery('[name="item_meta[podcast_index_guid]"]').val(podcast.podcastGuid);
  jQuery('[name="item_meta[podcast_source]"]').val('podcast_index');
  jQuery('[name="item_meta[rss_feed]"]').val(podcast.url);
  jQuery('[name="item_meta[website]"]').val(podcast.link);
}
```

**When user selects podcast from Taddy:**
```javascript
function selectPodcastFromTaddy(podcast) {
  jQuery('[name="item_meta[podcast_name]"]').val(podcast.name);
  jQuery('[name="item_meta[taddy_podcast_uuid]"]').val(podcast.uuid);
  jQuery('[name="item_meta[podcast_source]"]').val('taddy');
  jQuery('[name="item_meta[rss_feed]"]').val(podcast.rssFeedUrl);
  jQuery('[name="item_meta[website]"]').val(podcast.websiteUrl);
}
```

### Method 2: Update Formidable Bridge to Extract IDs

Update the bridge class to extract external IDs from form fields:

```php
// In class-formidable-podcast-bridge.php

public function auto_populate_podcast($entry_id) {
    // Get form data
    $podcast_name = $this->get_field_value($entry_id, 'podcast_name');
    $rss_feed = $this->get_field_value($entry_id, 'rss_feed');

    // Get external IDs from hidden fields
    $podcast_index_id = $this->get_field_value($entry_id, 'podcast_index_id');
    $podcast_index_guid = $this->get_field_value($entry_id, 'podcast_index_guid');
    $taddy_uuid = $this->get_field_value($entry_id, 'taddy_podcast_uuid');
    $source = $this->get_field_value($entry_id, 'podcast_source');

    $manager = PIT_Podcast_Intelligence_Manager::get_instance();

    // Create podcast with external IDs
    $podcast_data = [
        'title' => $podcast_name,
        'rss_feed_url' => $rss_feed,
        'podcast_index_id' => $podcast_index_id ?: null,
        'podcast_index_guid' => $podcast_index_guid ?: null,
        'taddy_podcast_uuid' => $taddy_uuid ?: null,
        'source' => $source ?: 'manual',
    ];

    // This will find existing podcast by external ID or create new
    $podcast_id = $manager->create_or_find_podcast($podcast_data);

    // ... rest of auto-populate logic
}
```

### Method 3: Direct API Integration

Fetch podcast data directly from APIs and create records:

**Podcast Index Example:**
```php
function import_from_podcast_index($feed_id) {
    // Call Podcast Index API
    $response = wp_remote_get(
        "https://api.podcastindex.org/api/1.0/podcasts/byfeedid?id={$feed_id}",
        [
            'headers' => [
                'X-Auth-Key' => PODCAST_INDEX_API_KEY,
                'X-Auth-Date' => time(),
                'Authorization' => generate_podcast_index_auth_header(),
                'User-Agent' => 'YourApp/1.0',
            ],
        ]
    );

    $data = json_decode(wp_remote_retrieve_body($response), true);
    $podcast_data = $data['feed'];

    // Create in database
    $manager = PIT_Podcast_Intelligence_Manager::get_instance();
    $podcast_id = $manager->create_or_find_podcast([
        'title' => $podcast_data['title'],
        'description' => $podcast_data['description'],
        'rss_feed_url' => $podcast_data['url'],
        'website_url' => $podcast_data['link'],
        'podcast_index_id' => $podcast_data['id'],
        'podcast_index_guid' => $podcast_data['podcastGuid'],
        'source' => 'podcast_index',
        'data_quality_score' => 70, // API data is good quality
    ]);

    return $podcast_id;
}
```

**Taddy Example:**
```php
function import_from_taddy($uuid) {
    // Call Taddy API
    $response = wp_remote_post(
        'https://api.taddy.org',
        [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-USER-ID' => TADDY_USER_ID,
                'X-API-KEY' => TADDY_API_KEY,
            ],
            'body' => json_encode([
                'query' => '{
                    getPodcastSeries(uuid: "' . $uuid . '") {
                        uuid
                        name
                        description
                        rssFeedUrl
                        websiteUrl
                    }
                }'
            ]),
        ]
    );

    $data = json_decode(wp_remote_retrieve_body($response), true);
    $podcast_data = $data['data']['getPodcastSeries'];

    // Create in database
    $manager = PIT_Podcast_Intelligence_Manager::get_instance();
    $podcast_id = $manager->create_or_find_podcast([
        'title' => $podcast_data['name'],
        'description' => $podcast_data['description'],
        'rss_feed_url' => $podcast_data['rssFeedUrl'],
        'website_url' => $podcast_data['websiteUrl'],
        'taddy_podcast_uuid' => $podcast_data['uuid'],
        'source' => 'taddy',
        'data_quality_score' => 70,
    ]);

    return $podcast_id;
}
```

## Lookup Priority

When calling `upsert_guestify_podcast()`, the system checks for existing podcasts in this order:

1. **Podcast Index ID** (most reliable)
2. **Podcast Index GUID**
3. **Taddy UUID**
4. **RSS Feed URL** (fallback)
5. **Slug** (last resort)

This prevents duplicates when data comes from multiple sources.

## Usage Examples

### Example 1: User Selects from Podcast Index

```javascript
// Frontend JavaScript - Podcast search results
function handlePodcastSelect(result) {
  // From Podcast Index API
  const podcastData = {
    source: 'podcast_index',
    podcast_index_id: result.id,           // 920666
    podcast_index_guid: result.podcastGuid, // "9b024..."
    title: result.title,
    rss_feed_url: result.url,
    website_url: result.link,
  };

  // Store in Formidable hidden fields
  populateFormidableFields(podcastData);
}

// When form submits, Formidable Bridge auto-populates
// System checks: Does podcast_index_id 920666 exist?
// - YES: Links entry to existing podcast
// - NO: Creates new podcast with all IDs
```

### Example 2: User Selects from Taddy

```javascript
function handleTaddySelect(result) {
  const podcastData = {
    source: 'taddy',
    taddy_podcast_uuid: result.uuid,      // "a1b2c3d4..."
    title: result.name,
    rss_feed_url: result.rssFeedUrl,
    website_url: result.websiteUrl,
  };

  populateFormidableFields(podcastData);
}

// System checks: Does taddy_podcast_uuid exist?
// Prevents duplicate even if same podcast was added via Podcast Index
```

### Example 3: Bulk Import from Podcast Index

```php
// Import top 100 business podcasts
function bulk_import_business_podcasts() {
    $podcasts = fetch_podcast_index_category('Business');

    $imported = 0;
    foreach ($podcasts as $podcast) {
        $podcast_id = PIT_Database::upsert_guestify_podcast([
            'title' => $podcast['title'],
            'description' => $podcast['description'],
            'rss_feed_url' => $podcast['url'],
            'website_url' => $podcast['link'],
            'podcast_index_id' => $podcast['id'],
            'podcast_index_guid' => $podcast['podcastGuid'],
            'source' => 'podcast_index',
            'category' => 'Business',
            'data_quality_score' => 70,
        ]);

        if ($podcast_id) {
            $imported++;
        }
    }

    return "Imported {$imported} podcasts (duplicates skipped)";
}
```

## Preventing Duplicates

### Scenario: Same Podcast from Multiple Sources

```php
// User finds "Podcast Mark" via Podcast Index
PIT_Database::upsert_guestify_podcast([
    'title' => 'Podcast Mark',
    'podcast_index_id' => 920666,
    'podcast_index_guid' => '9b024349-ccf0-5f69-a609-6b82873eab3c',
    'rss_feed_url' => 'https://podcastmark.com/feed',
    'source' => 'podcast_index',
]);
// Creates podcast with id=1

// Later, user finds same podcast via Taddy
PIT_Database::upsert_guestify_podcast([
    'title' => 'Podcast Mark',
    'taddy_podcast_uuid' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
    'rss_feed_url' => 'https://podcastmark.com/feed', // Same RSS
    'source' => 'taddy',
]);
// Finds existing by RSS URL, updates with Taddy UUID
// Still id=1 (no duplicate!)

// Now podcast has BOTH identifiers:
// - podcast_index_id: 920666
// - podcast_index_guid: 9b024349-ccf0-5f69-a609-6b82873eab3c
// - taddy_podcast_uuid: a1b2c3d4-e5f6-7890-abcd-ef1234567890
// - source: 'taddy' (last updated source)
```

## Database Queries

### Find by Podcast Index ID
```php
$podcast = PIT_Database::get_podcast_by_podcast_index_id(920666);
```

### Find by Taddy UUID
```php
$podcast = PIT_Database::get_podcast_by_taddy_uuid('a1b2c3d4-...');
```

### Find by Any External ID
```php
$podcast = PIT_Database::get_podcast_by_external_id([
    'podcast_index_id' => 920666,
    'taddy_podcast_uuid' => 'a1b2c3d4-...',
    'rss_feed_url' => 'https://example.com/feed',
]);
// Checks all IDs, returns first match
```

### Get All Podcasts from Podcast Index
```php
global $wpdb;
$table = $wpdb->prefix . 'guestify_podcasts';

$podcasts = $wpdb->get_results(
    "SELECT * FROM $table WHERE source = 'podcast_index' AND podcast_index_id IS NOT NULL"
);
```

## Re-Syncing Data

Since you have external IDs, you can re-sync podcast data:

```php
function resync_podcast_from_source($podcast_id) {
    $podcast = PIT_Database::get_guestify_podcast($podcast_id);

    if (!$podcast) {
        return false;
    }

    // Re-sync from original source
    if ($podcast->source === 'podcast_index' && $podcast->podcast_index_id) {
        $fresh_data = fetch_from_podcast_index($podcast->podcast_index_id);

        // Update with fresh data
        PIT_Database::upsert_guestify_podcast([
            'id' => $podcast_id,
            'title' => $fresh_data['title'],
            'description' => $fresh_data['description'],
            // ... other fields
        ]);
    } elseif ($podcast->source === 'taddy' && $podcast->taddy_podcast_uuid) {
        $fresh_data = fetch_from_taddy($podcast->taddy_podcast_uuid);

        PIT_Database::upsert_guestify_podcast([
            'id' => $podcast_id,
            'title' => $fresh_data['name'],
            'description' => $fresh_data['description'],
            // ... other fields
        ]);
    }

    return true;
}
```

## REST API

Added support for querying by external IDs:

```javascript
// Get podcast by Podcast Index ID
GET /wp-json/podcast-influence/v1/podcasts?podcast_index_id=920666

// Get podcast by Taddy UUID
GET /wp-json/podcast-influence/v1/podcasts?taddy_uuid=a1b2c3d4-...

// Create with external IDs
POST /wp-json/podcast-influence/v1/intelligence/podcasts
{
  "title": "Podcast Mark",
  "podcast_index_id": 920666,
  "podcast_index_guid": "9b024349-ccf0-5f69-a609-6b82873eab3c",
  "source": "podcast_index"
}
```

## Migration: Adding External IDs to Existing Podcasts

If you have existing podcasts without external IDs:

```php
function backfill_external_ids() {
    $podcasts = PIT_Database::get_podcasts(['per_page' => 1000]);

    foreach ($podcasts['podcasts'] as $podcast) {
        if (!$podcast->podcast_index_id && $podcast->rss_feed_url) {
            // Look up in Podcast Index by RSS URL
            $result = search_podcast_index_by_rss($podcast->rss_feed_url);

            if ($result) {
                PIT_Database::upsert_guestify_podcast([
                    'id' => $podcast->id,
                    'podcast_index_id' => $result['id'],
                    'podcast_index_guid' => $result['podcastGuid'],
                    'source' => 'podcast_index',
                ]);
            }
        }
    }
}
```

## Benefits Summary

✅ **No Duplicates** - Same podcast from different sources = 1 record
✅ **Data Sync** - Re-fetch fresh data using original source ID
✅ **Source Tracking** - Know where podcast data came from
✅ **Better Matching** - External IDs more reliable than RSS URLs
✅ **Multi-Source** - Store IDs from both Taddy AND Podcast Index

## Support

For questions:
- Database Schema: `/PODCAST-INTELLIGENCE-DATABASE.md`
- Installation: `/INSTALLATION.md`
- GitHub: https://github.com/meettonyg/showauthority
