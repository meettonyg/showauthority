# Podcast Intelligence Database Documentation

## Overview

The Podcast Intelligence Database is a foundational architecture that stores podcast metadata, contacts, and social accounts in a centralized, structured format. This eliminates the need to repeatedly parse RSS feeds, scrape websites, or call enrichment APIs for the same information.

## Architecture

### Core Principle: Single Source of Truth

Instead of scattering podcast data across Formidable Forms entries, transient RSS parses, and API calls, the system maintains one centralized database that:

1. Stores podcast data ONCE
2. Links to Formidable entries via relationships
3. Progressively enriches over time
4. Provides fast lookups without re-parsing

### Database Schema (5 Tables)

#### 1. `wp_guestify_podcasts` - Core Show Information

**Purpose:** Store comprehensive podcast metadata

```sql
CREATE TABLE wp_guestify_podcasts (
    id bigint(20) UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    -- Basic Info
    title varchar(500) NOT NULL,
    slug varchar(200),
    description text,

    -- RSS/Feed Data
    rss_feed_url text,
    website_url text,
    homepage_scraped tinyint(1) DEFAULT 0,
    last_rss_check datetime,

    -- Show Metadata
    category varchar(100),
    language varchar(10) DEFAULT 'en',
    episode_count int DEFAULT 0,
    frequency varchar(50),
    average_duration int,

    -- Tracking Status
    is_tracked tinyint(1) DEFAULT 0,
    tracked_at datetime,

    -- Enrichment Status
    social_links_discovered tinyint(1) DEFAULT 0,
    metrics_enriched tinyint(1) DEFAULT 0,
    last_enriched_at datetime,

    -- Quality Scores
    data_quality_score int DEFAULT 0,
    relevance_score int DEFAULT 0,

    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY slug (slug)
);
```

**Data Quality Score:**
- 0-20: Manual entry, minimal data
- 30-40: RSS parsed, basic info
- 50-70: Social links discovered
- 80-90: Metrics enriched
- 90-100: Clay enriched with contacts

#### 2. `wp_guestify_podcast_social_accounts` - Social Media Links

**Purpose:** Store discovered social media accounts (Layer 1 - Free)

```sql
CREATE TABLE wp_guestify_podcast_social_accounts (
    id bigint(20) UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    podcast_id bigint(20) UNSIGNED NOT NULL,
    platform varchar(50) NOT NULL, -- 'twitter', 'youtube', etc.

    -- Account Details
    profile_url text NOT NULL,
    username varchar(255),
    display_name varchar(255),

    -- Discovery Method
    discovery_method varchar(50), -- 'rss', 'homepage', 'manual'
    discovered_at datetime DEFAULT CURRENT_TIMESTAMP,

    -- Metrics (Layer 2 - Paid enrichment)
    followers_count int,
    engagement_rate decimal(5,2),
    post_frequency varchar(50),
    last_post_date date,

    metrics_enriched tinyint(1) DEFAULT 0,
    enriched_at datetime,
    enrichment_cost_cents int,

    verified tinyint(1) DEFAULT 0,
    active tinyint(1) DEFAULT 1,

    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_podcast_platform (podcast_id, platform)
);
```

**Supported Platforms:**
- twitter
- instagram
- youtube
- linkedin
- facebook
- tiktok

#### 3. `wp_guestify_podcast_contacts` - Contact Database

**Purpose:** Store hosts, producers, assistants, and other contacts

```sql
CREATE TABLE wp_guestify_podcast_contacts (
    id bigint(20) UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    -- Identity
    full_name varchar(255) NOT NULL,
    first_name varchar(100),
    last_name varchar(100),

    -- Contact Details
    email varchar(255),
    personal_email varchar(255),
    phone varchar(50),

    -- Professional Info
    role varchar(100), -- 'host', 'producer', 'guest'
    company varchar(255),
    title varchar(255),

    -- Social Links
    linkedin_url text,
    twitter_url text,
    website_url text,

    -- Enrichment Status
    clay_enriched tinyint(1) DEFAULT 0,
    clay_enriched_at datetime,
    enrichment_source varchar(50),
    data_quality_score int DEFAULT 0,

    -- Contact Preferences
    preferred_contact_method varchar(50),
    best_contact_time varchar(100),
    response_rate_percentage int,

    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY email (email)
);
```

**Enrichment Sources:**
- `manual` - User entered
- `rss` - Extracted from RSS feed
- `clay` - Enriched via Clay API
- `hunter` - Hunter.io lookup
- `apollo` - Apollo.io lookup

#### 4. `wp_guestify_podcast_contact_relationships` - Bridge Table

**Purpose:** Link podcasts to contacts with role information

```sql
CREATE TABLE wp_guestify_podcast_contact_relationships (
    id bigint(20) UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    podcast_id bigint(20) UNSIGNED NOT NULL,
    contact_id bigint(20) UNSIGNED NOT NULL,

    role varchar(100) NOT NULL, -- 'host', 'co-host', 'producer'
    is_primary tinyint(1) DEFAULT 0,

    active tinyint(1) DEFAULT 1,
    start_date date,
    end_date date,

    notes text,

    created_at datetime DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY unique_podcast_contact_role (podcast_id, contact_id, role)
);
```

**Common Roles:**
- `host` - Primary host
- `co-host` - Secondary host
- `producer` - Producer/Editor
- `assistant` - Assistant/Scheduler
- `guest` - Past guest (for tracking)

#### 5. `wp_guestify_interview_tracker_podcasts` - Formidable Bridge

**Purpose:** Link Interview Tracker entries to podcast records

```sql
CREATE TABLE wp_guestify_interview_tracker_podcasts (
    id bigint(20) UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    formidable_entry_id bigint(20) UNSIGNED NOT NULL,
    podcast_id bigint(20) UNSIGNED NOT NULL,

    outreach_status varchar(50), -- 'researching', 'drafted', 'sent', 'replied'
    primary_contact_id bigint(20) UNSIGNED,

    first_contact_date datetime,
    last_contact_date datetime,

    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_entry_podcast (formidable_entry_id, podcast_id)
);
```

**Outreach Statuses:**
- `researching` - Finding contact info
- `drafted` - Email drafted but not sent
- `sent` - Initial email sent
- `replied` - Host replied
- `scheduled` - Interview scheduled
- `completed` - Interview completed
- `declined` - Host declined

## Data Flow

### Scenario 1: User Creates New Interview Tracker Entry

```
1. User fills out Formidable form
   ↓
2. Auto-populate hook fires
   ↓
3. System checks: Does podcast exist in guestify_podcasts?
   ├─ YES → Link entry to existing podcast_id
   └─ NO → Create new podcast record
       ├─ Parse RSS for basic info (Layer 1 - Free)
       ├─ Scrape homepage for social links (Layer 1 - Free)
       ├─ Store in guestify_podcasts
       └─ Store social links in guestify_podcast_social_accounts
   ↓
4. System checks: Do we have contact?
   ├─ YES → Link to existing contact_id
   └─ NO → Create contact record
       ├─ Extract from RSS or form fields
       ├─ Store in guestify_podcast_contacts
       └─ Create relationship in guestify_podcast_contact_relationships
   ↓
5. Create bridge record in guestify_interview_tracker_podcasts
   - formidable_entry_id = 123
   - podcast_id = 456
   - primary_contact_id = 789
   - outreach_status = 'researching'
```

### Scenario 2: Email Integration Looks Up Contact

```
1. [guestify_email entry_id="123"] shortcode renders
   ↓
2. Email Integration checks sources in priority order:

   Priority 1: Formidable field (direct entry)
   ├─ Check form field 'host_email'
   └─ If found, use immediately (100% confidence)

   Priority 2: Podcast contacts table
   ├─ entry_id → podcast_id → primary_contact_id
   ├─ Get contact from guestify_podcast_contacts
   └─ If found, use (90% confidence)

   Priority 3: Any contact for this podcast
   ├─ Get all contacts for podcast_id where role='host'
   └─ If found, use first (80% confidence)

   Priority 4: Suggest Clay enrichment
   ├─ Have name but no email
   └─ Show "Enrich with Clay" button

   Priority 5: Manual entry
   └─ Show form to manually enter contact
   ↓
3. Email interface renders with contact info + confidence score
```

### Scenario 3: User Tracks a Show (Layer 2 - Paid)

```
1. User clicks "Track This Show" on podcast detail page
   ↓
2. Social links already visible (Layer 1 - discovered for free)
   - Twitter: @podcastmark
   - YouTube: /podcastmark
   ↓
3. Button changes to spinner
   ↓
4. System queues job via Action Scheduler
   - Fetch Twitter metrics (Apify - $0.05)
   - Fetch YouTube metrics (YouTube API - free)
   - Fetch Instagram metrics (Apify - $0.05)
   ↓
5. UI polls /jobs/{id} endpoint every 2 seconds
   ↓
6. Metrics appear progressively as they're fetched
   ↓
7. Data stored in guestify_podcast_social_accounts
   - followers_count = 12500
   - engagement_rate = 4.2
   - metrics_enriched = 1
   - enrichment_cost_cents = 10
   ↓
8. Podcast marked as tracked
   - is_tracked = 1
   - metrics_enriched = 1
```

## PHP Classes

### 1. `PIT_Podcast_Intelligence_Manager`

**Location:** `includes/podcast-intelligence/class-podcast-intelligence-manager.php`

**Purpose:** High-level orchestration for podcast intelligence operations

**Key Methods:**

```php
// Create or find podcast
$podcast_id = $manager->create_or_find_podcast([
    'title' => 'Podcast Mark',
    'rss_feed_url' => 'https://example.com/feed',
    'website_url' => 'https://podcastmark.com'
]);

// Create or find contact
$contact_id = $manager->create_or_find_contact([
    'full_name' => 'Mark de Grasse',
    'email' => 'mark@example.com',
    'role' => 'host'
]);

// Link podcast and contact
$manager->link_podcast_contact($podcast_id, $contact_id, 'host', true);

// Get complete podcast data for entry
$data = $manager->get_entry_podcast_data($entry_id);
// Returns: ['podcast' => ..., 'contacts' => ..., 'social_accounts' => ...]

// Get contact email for email integration
$email = $manager->get_entry_contact_email($entry_id);
```

### 2. `PIT_Formidable_Podcast_Bridge`

**Location:** `includes/podcast-intelligence/class-formidable-podcast-bridge.php`

**Purpose:** Automatic integration with Formidable Forms

**Auto-Population:**
- Hooks into `frm_after_create_entry`
- Automatically creates podcast and contact records
- Links entry to podcast via bridge table

**Manual Methods:**

```php
$bridge = PIT_Formidable_Podcast_Bridge::get_instance();

// Get podcast for entry
$podcast = $bridge->get_podcast_for_entry($entry_id);

// Get contact for entry
$contact = $bridge->get_contact_for_entry($entry_id);

// Update outreach status
$bridge->update_outreach_status($entry_id, 'sent');

// Mark first contact
$bridge->mark_first_contact($entry_id);
```

### 3. `PIT_Email_Integration`

**Location:** `includes/podcast-intelligence/class-email-integration.php`

**Purpose:** Provide contact info for email sending

**Shortcode Usage:**

```php
// In Formidable Forms email view or anywhere
[guestify_email entry_id="123"]
```

**Manual Lookup:**

```php
$integration = PIT_Email_Integration::get_instance();

$contact = $integration->get_contact_from_all_sources($entry_id);
// Returns:
// [
//     'email' => 'mark@example.com',
//     'name' => 'Mark de Grasse',
//     'podcast_name' => 'Podcast Mark',
//     'source' => 'podcast_database',
//     'confidence' => 90,
//     'additional_info' => [
//         'role' => 'host',
//         'linkedin' => 'https://linkedin.com/in/markdegrasse',
//         'clay_enriched' => true
//     ]
// ]
```

### 4. `PIT_Database` (Extended)

**Location:** `includes/class-database.php`

**New Methods:**

```php
// Podcasts
$podcast = PIT_Database::get_guestify_podcast($podcast_id);
$podcast = PIT_Database::get_podcast_by_rss($rss_url);
$podcast_id = PIT_Database::upsert_guestify_podcast($data);

// Social Accounts
$accounts = PIT_Database::get_podcast_social_accounts($podcast_id);
$account_id = PIT_Database::upsert_social_account($data);

// Contacts
$contact = PIT_Database::get_contact($contact_id);
$contact = PIT_Database::get_contact_by_email($email);
$contact_id = PIT_Database::upsert_contact($data);

// Relationships
$contacts = PIT_Database::get_podcast_contacts($podcast_id, 'host');
$primary = PIT_Database::get_primary_contact($podcast_id, 'host');
$rel_id = PIT_Database::create_podcast_contact_relationship($data);

// Entry Bridge
$link_id = PIT_Database::link_entry_to_podcast($entry_id, $podcast_id);
$podcast = PIT_Database::get_entry_podcast($entry_id);
$contact = PIT_Database::get_entry_contact($entry_id);
```

## REST API Endpoints

### Podcasts

```
GET    /podcast-influence/v1/intelligence/podcasts
POST   /podcast-influence/v1/intelligence/podcasts
GET    /podcast-influence/v1/intelligence/podcasts/{id}
```

### Contacts

```
GET    /podcast-influence/v1/contacts
POST   /podcast-influence/v1/contacts
GET    /podcast-influence/v1/contacts/{id}
PUT    /podcast-influence/v1/contacts/{id}
DELETE /podcast-influence/v1/contacts/{id}
```

### Relationships

```
GET    /podcast-influence/v1/podcasts/{id}/contacts
POST   /podcast-influence/v1/podcasts/{id}/contacts
```

### Entry Bridge

```
GET    /podcast-influence/v1/entries/{id}/podcast
GET    /podcast-influence/v1/entries/{id}/contact-email
```

## Usage Examples

### Example 1: Manual Podcast Creation

```php
$manager = PIT_Podcast_Intelligence_Manager::get_instance();

// Create podcast
$podcast_id = $manager->create_or_find_podcast([
    'title' => 'The Tech Show',
    'rss_feed_url' => 'https://example.com/feed.xml',
    'website_url' => 'https://thetechshow.com'
]);

// This automatically:
// 1. Creates podcast record
// 2. Parses RSS feed (Layer 1)
// 3. Scrapes homepage for social links (Layer 1)
// 4. Stores everything in database

// Create contact
$contact_id = $manager->create_or_find_contact([
    'full_name' => 'Sarah Johnson',
    'email' => 'sarah@thetechshow.com',
    'role' => 'host'
]);

// Link them
$manager->link_podcast_contact($podcast_id, $contact_id, 'host', true);
```

### Example 2: Email Lookup in Formidable View

In your Formidable Forms entry detail view:

```html
<div class="entry-email-section">
    [guestify_email entry_id="[id]"]
</div>
```

This renders a complete email interface with:
- Auto-discovered contact info
- Confidence score
- Source attribution
- Pre-filled email form
- Send functionality

### Example 3: REST API Usage (JavaScript)

```javascript
// Get podcast data for entry
fetch('/wp-json/podcast-influence/v1/entries/123/podcast')
    .then(r => r.json())
    .then(data => {
        console.log(data.podcast); // Podcast info
        console.log(data.contacts); // All contacts
        console.log(data.social_accounts); // Social media
        console.log(data.primary_contact); // Primary contact
    });

// Get contact email with source info
fetch('/wp-json/podcast-influence/v1/entries/123/contact-email')
    .then(r => r.json())
    .then(data => {
        console.log(data.email); // Email address
        console.log(data.source); // Where it came from
        console.log(data.confidence); // Confidence score (0-100)
    });
```

## Migration from Old System

If you have existing Interview Tracker entries:

```php
// Run this once to migrate existing entries
function migrate_existing_entries() {
    $entries = FrmEntry::getAll(['form_id' => YOUR_FORM_ID]);

    foreach ($entries as $entry) {
        $bridge = PIT_Formidable_Podcast_Bridge::get_instance();
        $bridge->process_new_entry($entry->id, $entry->form_id);
    }
}
```

## Benefits

1. **No Duplicate Parsing:** RSS feeds parsed once, data reused forever
2. **Fast Lookups:** Direct database queries instead of API calls
3. **Progressive Enrichment:** Start basic, enhance over time
4. **Cost Optimization:** Only pay for enrichment when needed
5. **Relationship Tracking:** Know which contacts work with which shows
6. **Historical Data:** Track changes over time
7. **Confidence Scores:** Know how reliable the data is

## Next Steps

1. **Layer 1 - Social Discovery:** Already implemented via RSS parser and homepage scraper
2. **Layer 2 - Metrics Enrichment:** Implement "Track This Show" feature
3. **Layer 3 - Background Refresh:** Scheduled updates for tracked shows
4. **Admin UI:** Vue 3 interface for managing podcasts and contacts
5. **Bulk Import:** CSV import for pre-populated podcast library

## Support

For questions or issues, refer to:
- Main README: `/README.md`
- Installation Guide: `/INSTALLATION.md`
- Podcast Influence Tracking: `/PODCAST-INFLUENCE-TRACKING.md`
