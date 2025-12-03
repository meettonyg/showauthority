# Database Architecture Refactoring Plan

## Executive Summary

This document outlines the refactoring of the Podcast Intelligence Tracker (PIT) database schema to:

1. **Separate CRM workflow** (user-specific) from **public facts** (global intelligence)
2. **Create a Global Guest Directory** where guests are shared entities, not private address book entries
3. **Enable Identity Claiming** so real people can claim and manage their own profiles
4. **Protect Private Data** by separating public vs private contact information

### The Problems

**Problem 1: Semantic Confusion**
The current `pit_guest_appearances` table conflates:
- **User Workflow**: "I am working to get this speaking opportunity" (CRM pipeline)
- **Public Fact**: "This speaking engagement exists/happened" (global record)

This creates issues like "Cancelled Appearance" (confusing) vs "Cancelled Opportunity" (clear).

**Problem 2: Private Address Book Model**
The current `pit_guests` table uses `user_id` as **Ownership** ("who created this record"), not **Identity** ("who this person IS"). This means:
- Each user has their own private copy of "Seth Godin"
- No shared intelligence across users
- No way for Seth Godin to claim and manage his own profile

**Problem 3: Private Data Leak Risk**
In a global directory, User A's private contact info (celebrity's cell phone) would leak to User B.

### The Solution

A **five-layer architecture** with **global guest directory**, **claiming system**, and **private contact separation**:

| Layer | Table | Scope | Purpose |
|-------|-------|-------|---------|
| 0a | `pit_guests` (modified) | **Global** | Public guest profile (no private contacts) |
| 0b | `pit_guest_private_contacts` | **User-owned** | Private contact info per user |
| 1 | `pit_opportunities` | User-owned | CRM pipeline workflow |
| 2 | `pit_engagements` | Global | Public record of speaking events |
| 3 | `pit_speaking_credits` | Global | Links guests to engagements |
| - | `pit_claim_requests` | User-owned | Claim verification workflow |

### Key Architectural Shift

```
BEFORE (Private Address Book):
┌─────────────────────────────────────────┐
│ User A's Account                        │
│   └── pit_guests (user_id = A)          │
│        └── "Seth Godin" (ID: 1)         │
│             phone: 555-1234  ← PRIVATE  │
├─────────────────────────────────────────┤
│ User B's Account                        │
│   └── pit_guests (user_id = B)          │
│        └── "Seth Godin" (ID: 2)  ← DUPE │
└─────────────────────────────────────────┘

AFTER (Global Directory + Private Contacts):
┌─────────────────────────────────────────┐
│ Global Guest Directory                  │
│   └── pit_guests (PUBLIC info only)     │
│        └── "Seth Godin" (ID: 1)         │
│             linkedin_url: /in/sethgodin │
│             claimed_by_user_id = 500    │
├─────────────────────────────────────────┤
│ User A's Private Contacts               │
│   └── pit_guest_private_contacts        │
│        └── guest_id=1, user_id=A        │
│             phone: 555-1234  ← PRIVATE  │
├─────────────────────────────────────────┤
│ User B's Private Contacts               │
│   └── pit_guest_private_contacts        │
│        └── guest_id=1, user_id=B        │
│             phone: (empty) ← CAN'T SEE A's │
└─────────────────────────────────────────┘
```

---

## Critical Implementation Risks & Mitigations

### Risk A: Private Data Leak in Global Directory

**The Problem:**
In a global directory, contact info becomes visible to all users. If User A adds a celebrity's private cell phone to their CRM, User B (a stranger) could see it.

**The Solution:**
Separate **Public Contact Info** (global) from **Private Contact Info** (user-owned).

#### Fields Distribution

| Field | Location | Visibility |
|-------|----------|------------|
| `linkedin_url` | pit_guests (Global) | Everyone |
| `twitter_handle` | pit_guests (Global) | Everyone |
| `website_url` | pit_guests (Global) | Everyone |
| `email` | pit_guests (Global) | Everyone (business/agent email) |
| `personal_email` | pit_guest_private_contacts | Owner only |
| `phone` | pit_guest_private_contacts | Owner only |
| `mobile_phone` | pit_guest_private_contacts | Owner only |
| `private_notes` | pit_guest_private_contacts | Owner only |

#### New Table: `pit_guest_private_contacts`

```sql
CREATE TABLE {prefix}pit_guest_private_contacts (
    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    
    -- ═══════════════════════════════════════════════════════════════
    -- OWNERSHIP
    -- ═══════════════════════════════════════════════════════════════
    user_id bigint(20) UNSIGNED NOT NULL,      -- Who owns this private data
    guest_id bigint(20) UNSIGNED NOT NULL,     -- Which guest it's for
    
    -- ═══════════════════════════════════════════════════════════════
    -- PRIVATE CONTACT INFO
    -- ═══════════════════════════════════════════════════════════════
    personal_email VARCHAR(255) DEFAULT NULL,
    secondary_email VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(50) DEFAULT NULL,
    mobile_phone VARCHAR(50) DEFAULT NULL,
    assistant_name VARCHAR(255) DEFAULT NULL,
    assistant_email VARCHAR(255) DEFAULT NULL,
    assistant_phone VARCHAR(50) DEFAULT NULL,
    
    -- ═══════════════════════════════════════════════════════════════
    -- PRIVATE NOTES
    -- ═══════════════════════════════════════════════════════════════
    private_notes TEXT DEFAULT NULL,
    relationship_notes TEXT DEFAULT NULL,
    last_contact_date DATE DEFAULT NULL,
    preferred_contact_method VARCHAR(50) DEFAULT NULL,
    
    -- ═══════════════════════════════════════════════════════════════
    -- SOURCE TRACKING
    -- ═══════════════════════════════════════════════════════════════
    source VARCHAR(100) DEFAULT NULL,
    verified_at DATETIME DEFAULT NULL,
    
    -- ═══════════════════════════════════════════════════════════════
    -- TIMESTAMPS
    -- ═══════════════════════════════════════════════════════════════
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (id),
    KEY user_id_idx (user_id),
    KEY guest_id_idx (guest_id),
    UNIQUE KEY user_guest_unique (user_id, guest_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### Data Access Pattern

```php
// Get guest with private contacts for current user
function get_guest_with_private_contacts($guest_id, $user_id) {
    global $wpdb;
    
    return $wpdb->get_row($wpdb->prepare(
        "SELECT g.*, 
                pc.personal_email AS private_email,
                pc.phone AS private_phone,
                pc.mobile_phone AS private_mobile,
                pc.private_notes
         FROM {$wpdb->prefix}pit_guests g
         LEFT JOIN {$wpdb->prefix}pit_guest_private_contacts pc 
              ON g.id = pc.guest_id AND pc.user_id = %d
         WHERE g.id = %d",
        $user_id,
        $guest_id
    ));
}
```

---

### Risk B: Deduplication Merge Logic

**The Problem:**
When merging duplicates, we must NOT overwrite good data with empty data.

**Scenario:**
- User A has "Seth Godin" (enriched: email, company, LinkedIn, metrics)
- User B has "Seth Godin" (empty: only has name)
- ❌ WRONG: Merge overwrites User A's data with empty fields
- ✅ RIGHT: Merge fills blanks, keeps best data from all sources

**The Solution: Smart Merge Algorithm**

```php
/**
 * Smart merge: Fill blanks, never overwrite good data with empty
 */
function smart_merge_guest($master, $duplicate) {
    $updated_fields = [];
    
    // Text fields: Only fill if master is empty AND duplicate has data
    $text_fields = [
        'first_name', 'last_name',
        'linkedin_url', 'email',
        'current_company', 'current_role', 'industry',
        'twitter_handle', 'instagram_handle', 'youtube_channel', 'website_url',
        'city', 'state_region', 'country', 'timezone',
    ];
    
    foreach ($text_fields as $field) {
        if (empty($master->$field) && !empty($duplicate->$field)) {
            $updated_fields[$field] = $duplicate->$field;
        }
    }
    
    // JSON fields: Merge arrays, don't replace
    $json_fields = ['expertise_areas', 'past_companies', 'verified_accounts'];
    foreach ($json_fields as $field) {
        if (!empty($master->$field) && !empty($duplicate->$field)) {
            $master_arr = json_decode($master->$field, true) ?: [];
            $dupe_arr = json_decode($duplicate->$field, true) ?: [];
            $merged = array_unique(array_merge($master_arr, $dupe_arr));
            if (count($merged) > count($master_arr)) {
                $updated_fields[$field] = json_encode($merged);
            }
        } elseif (empty($master->$field) && !empty($duplicate->$field)) {
            $updated_fields[$field] = $duplicate->$field;
        }
    }
    
    // Numeric fields: Take higher values
    $numeric_fields = ['linkedin_connections', 'twitter_followers', 'data_quality_score'];
    foreach ($numeric_fields as $field) {
        if (($duplicate->$field ?? 0) > ($master->$field ?? 0)) {
            $updated_fields[$field] = $duplicate->$field;
        }
    }
    
    // Take most recent enrichment
    if (!empty($duplicate->enriched_at) && 
        (empty($master->enriched_at) || $duplicate->enriched_at > $master->enriched_at)) {
        $updated_fields['enriched_at'] = $duplicate->enriched_at;
        $updated_fields['enrichment_provider'] = $duplicate->enrichment_provider;
    }
    
    return $updated_fields;
}
```

**Deduplication Process:**

```sql
-- Find duplicates by email (ordered by quality for master selection)
SELECT 
    email_hash,
    GROUP_CONCAT(id ORDER BY data_quality_score DESC) as ids_by_quality,
    COUNT(*) as duplicate_count
FROM pit_guests
WHERE email_hash IS NOT NULL AND is_merged = 0
GROUP BY email_hash
HAVING duplicate_count > 1;

-- First ID becomes master (highest quality), rest are duplicates
```

**Full Merge Execution:**

```php
function execute_guest_merge($master_id, $duplicate_id) {
    global $wpdb;
    
    // Step 1: Smart merge fields
    $master = get_guest($master_id);
    $duplicate = get_guest($duplicate_id);
    $updates = smart_merge_guest($master, $duplicate);
    
    if (!empty($updates)) {
        $wpdb->update($wpdb->prefix . 'pit_guests', $updates, ['id' => $master_id]);
    }
    
    // Step 2: Migrate all foreign key references
    $tables = ['pit_opportunities', 'pit_speaking_credits', 'pit_guest_private_contacts'];
    foreach ($tables as $table) {
        $wpdb->update($wpdb->prefix . $table, 
            ['guest_id' => $master_id], 
            ['guest_id' => $duplicate_id]
        );
    }
    
    // Step 3: Mark duplicate as merged (keep for audit)
    $wpdb->update($wpdb->prefix . 'pit_guests', [
        'is_merged' => 1,
        'merged_into_guest_id' => $master_id,
        'merge_history' => json_encode([
            'merged_at' => current_time('mysql'),
            'fields_transferred' => array_keys($updates),
        ]),
    ], ['id' => $duplicate_id]);
    
    return ['master_id' => $master_id, 'fields_merged' => array_keys($updates)];
}
```

---

### Risk C: Engagement Uniqueness

**The Problem:**
Two users track the same episode differently:
- User A: "Ep 55 - AI"
- User B: "Episode 55: Artificial Intelligence"

Without strong unique keys, we'd create duplicate engagement records.

**The Solution: Multi-Strategy Unique Identification**

#### Strategy 1: RSS Episode GUID (Best for Podcasts)

```sql
UNIQUE KEY episode_guid_unique (episode_guid)
```

#### Strategy 2: Composite Hash (Manual Entries)

```sql
ALTER TABLE pit_engagements
ADD COLUMN uniqueness_hash CHAR(32) DEFAULT NULL,
ADD UNIQUE KEY uniqueness_hash_unique (uniqueness_hash);
```

**Hash Generation:**

```php
function generate_engagement_hash($data) {
    // For podcasts: podcast_id + date + episode_number
    if (!empty($data['podcast_id']) && !empty($data['engagement_date'])) {
        return md5(sprintf('podcast:%d|date:%s|ep:%s',
            $data['podcast_id'],
            $data['engagement_date'],
            $data['episode_number'] ?? ''
        ));
    }
    
    // For events: event_name + date + location
    if (!empty($data['event_name']) && !empty($data['engagement_date'])) {
        return md5(sprintf('event:%s|date:%s|loc:%s',
            strtolower(trim($data['event_name'])),
            $data['engagement_date'],
            strtolower(trim($data['event_location'] ?? ''))
        ));
    }
    
    // Fallback: URL-based
    if (!empty($data['url'])) {
        return md5('url:' . normalize_url($data['url']));
    }
    
    // Last resort: title + date + type
    return md5(sprintf('type:%s|title:%s|date:%s',
        $data['engagement_type'],
        strtolower(trim($data['title'])),
        $data['engagement_date'] ?? ''
    ));
}
```

**Engagement Upsert with Dedup:**

```php
function create_or_find_engagement($data) {
    global $wpdb;
    $table = $wpdb->prefix . 'pit_engagements';
    
    // Priority 1: Check by episode_guid
    if (!empty($data['episode_guid'])) {
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE episode_guid = %s",
            $data['episode_guid']
        ));
        if ($existing) return ['id' => $existing, 'created' => false];
    }
    
    // Priority 2: Check by uniqueness_hash
    $hash = generate_engagement_hash($data);
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table WHERE uniqueness_hash = %s", $hash
    ));
    if ($existing) return ['id' => $existing, 'created' => false];
    
    // No match - create new
    $data['uniqueness_hash'] = $hash;
    $wpdb->insert($table, $data);
    
    return ['id' => $wpdb->insert_id, 'created' => true];
}
```

**Uniqueness Strategy Priority:**

| Source | Unique Key | Reliability |
|--------|------------|-------------|
| RSS Feed | `episode_guid` | ★★★★★ Perfect |
| YouTube | `video_id` in URL | ★★★★★ Perfect |
| Manual + Date | `podcast_id + date + ep#` | ★★★★☆ Very Good |
| Event | `event_name + date + location` | ★★★☆☆ Good |
| URL Only | Normalized URL hash | ★★★☆☆ Good |
| Title + Date | Fallback hash | ★★☆☆☆ Fair |

---

## Proposed Schema

### Layer 0a: `pit_guests` (Global Guest Directory)

Transform from private address book to global shared directory with claiming.
**NO private contact info** - that goes in `pit_guest_private_contacts`.

```sql
CREATE TABLE {prefix}pit_guests (
    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    
    -- ═══════════════════════════════════════════════════════════════
    -- IDENTITY CLAIMING
    -- ═══════════════════════════════════════════════════════════════
    claimed_by_user_id bigint(20) UNSIGNED DEFAULT NULL,
    claim_status ENUM('unclaimed', 'pending', 'verified', 'rejected') DEFAULT 'unclaimed',
    claim_verified_at DATETIME DEFAULT NULL,
    claim_verification_method VARCHAR(50) DEFAULT NULL,
    
    -- ═══════════════════════════════════════════════════════════════
    -- PROVENANCE
    -- ═══════════════════════════════════════════════════════════════
    created_by_user_id bigint(20) UNSIGNED DEFAULT NULL,
    
    -- ═══════════════════════════════════════════════════════════════
    -- IDENTITY
    -- ═══════════════════════════════════════════════════════════════
    full_name VARCHAR(255) NOT NULL,
    first_name VARCHAR(100) DEFAULT NULL,
    last_name VARCHAR(100) DEFAULT NULL,
    
    -- Deduplication Keys
    linkedin_url TEXT DEFAULT NULL,
    linkedin_url_hash CHAR(32) DEFAULT NULL,
    email VARCHAR(255) DEFAULT NULL,  -- Business/agent email (PUBLIC)
    email_hash CHAR(32) DEFAULT NULL,
    
    -- ═══════════════════════════════════════════════════════════════
    -- PROFESSIONAL INFO
    -- ═══════════════════════════════════════════════════════════════
    current_company VARCHAR(255) DEFAULT NULL,
    current_role VARCHAR(255) DEFAULT NULL,
    company_stage VARCHAR(50) DEFAULT NULL,
    company_revenue VARCHAR(50) DEFAULT NULL,
    industry VARCHAR(100) DEFAULT NULL,
    expertise_areas TEXT DEFAULT NULL,
    past_companies TEXT DEFAULT NULL,
    education TEXT DEFAULT NULL,
    notable_achievements TEXT DEFAULT NULL,
    
    -- ═══════════════════════════════════════════════════════════════
    -- PUBLIC CONTACT INFO (no private data here!)
    -- ═══════════════════════════════════════════════════════════════
    twitter_handle VARCHAR(100) DEFAULT NULL,
    instagram_handle VARCHAR(100) DEFAULT NULL,
    youtube_channel VARCHAR(255) DEFAULT NULL,
    website_url TEXT DEFAULT NULL,
    
    -- ═══════════════════════════════════════════════════════════════
    -- SOCIAL METRICS
    -- ═══════════════════════════════════════════════════════════════
    linkedin_connections INT(11) DEFAULT NULL,
    twitter_followers INT(11) DEFAULT NULL,
    instagram_followers INT(11) DEFAULT NULL,
    youtube_subscribers INT(11) DEFAULT NULL,
    verified_accounts TEXT DEFAULT NULL,
    
    -- ═══════════════════════════════════════════════════════════════
    -- LOCATION
    -- ═══════════════════════════════════════════════════════════════
    city VARCHAR(100) DEFAULT NULL,
    state_region VARCHAR(100) DEFAULT NULL,
    country VARCHAR(100) DEFAULT NULL,
    timezone VARCHAR(50) DEFAULT NULL,
    
    -- ═══════════════════════════════════════════════════════════════
    -- ENRICHMENT
    -- ═══════════════════════════════════════════════════════════════
    enrichment_provider VARCHAR(50) DEFAULT NULL,
    enrichment_level VARCHAR(50) DEFAULT NULL,
    enriched_at DATETIME DEFAULT NULL,
    enrichment_cost DECIMAL(10,4) DEFAULT 0,
    data_quality_score INT(11) DEFAULT 0,
    
    -- ═══════════════════════════════════════════════════════════════
    -- VERIFICATION
    -- ═══════════════════════════════════════════════════════════════
    is_verified TINYINT(1) DEFAULT 0,
    verification_count INT(11) DEFAULT 0,
    last_verified_by_user_id bigint(20) UNSIGNED DEFAULT NULL,
    last_verified_at DATETIME DEFAULT NULL,
    
    -- ═══════════════════════════════════════════════════════════════
    -- DEDUPLICATION
    -- ═══════════════════════════════════════════════════════════════
    is_merged TINYINT(1) DEFAULT 0,
    merged_into_guest_id bigint(20) UNSIGNED DEFAULT NULL,
    merge_history TEXT DEFAULT NULL,
    
    -- ═══════════════════════════════════════════════════════════════
    -- SOURCE
    -- ═══════════════════════════════════════════════════════════════
    discovery_source VARCHAR(50) DEFAULT NULL,
    source_podcast_id bigint(20) UNSIGNED DEFAULT NULL,
    
    -- ═══════════════════════════════════════════════════════════════
    -- TIMESTAMPS
    -- ═══════════════════════════════════════════════════════════════
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (id),
    KEY claimed_by_user_id_idx (claimed_by_user_id),
    KEY claim_status_idx (claim_status),
    KEY created_by_user_id_idx (created_by_user_id),
    KEY full_name_idx (full_name),
    KEY email_hash_idx (email_hash),
    KEY linkedin_url_hash_idx (linkedin_url_hash),
    KEY is_verified_idx (is_verified),
    KEY is_merged_idx (is_merged)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Key Changes from Current:**
- **REMOVED**: `user_id` (ownership) - replaced by `created_by_user_id` (provenance)
- **REMOVED**: `personal_email`, `phone` - moved to `pit_guest_private_contacts`
- **ADDED**: `claimed_by_user_id`, `claim_status`, `claim_verified_at`, `claim_verification_method`

---

### Layer 0b: `pit_guest_private_contacts` (User-Owned Private Data)

See Risk A section above for full schema.

---

### Claim Request Table: `pit_claim_requests`

```sql
CREATE TABLE {prefix}pit_claim_requests (
    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id bigint(20) UNSIGNED NOT NULL,
    guest_id bigint(20) UNSIGNED NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'auto_approved') DEFAULT 'pending',
    verification_method VARCHAR(50) DEFAULT NULL,
    verification_data TEXT DEFAULT NULL,
    reviewed_by_user_id bigint(20) UNSIGNED DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    review_notes TEXT DEFAULT NULL,
    rejection_reason TEXT DEFAULT NULL,
    claim_reason TEXT DEFAULT NULL,
    proof_url TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (id),
    KEY user_id_idx (user_id),
    KEY guest_id_idx (guest_id),
    KEY status_idx (status),
    UNIQUE KEY user_guest_unique (user_id, guest_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### Layer 1: `pit_opportunities` (CRM Workflow)

```sql
CREATE TABLE {prefix}pit_opportunities (
    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    
    -- USER OWNERSHIP
    user_id bigint(20) UNSIGNED NOT NULL,
    
    -- REFERENCES
    guest_id bigint(20) UNSIGNED DEFAULT NULL,
    guest_profile_id bigint(20) UNSIGNED DEFAULT NULL,
    engagement_id bigint(20) UNSIGNED DEFAULT NULL,
    podcast_id bigint(20) UNSIGNED DEFAULT NULL,
    
    -- CRM WORKFLOW
    status ENUM(
        'lead', 'researching', 'outreach', 'pitched', 'negotiating',
        'scheduled', 'recorded', 'editing', 'aired', 'promoted',
        'on_hold', 'cancelled', 'unqualified'
    ) DEFAULT 'lead',
    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    source VARCHAR(100) DEFAULT NULL,
    is_archived TINYINT(1) DEFAULT 0,
    
    -- NOTES
    notes TEXT DEFAULT NULL,
    internal_notes TEXT DEFAULT NULL,
    
    -- MILESTONE DATES
    lead_date DATE DEFAULT NULL,
    outreach_date DATE DEFAULT NULL,
    response_date DATE DEFAULT NULL,
    pitch_date DATE DEFAULT NULL,
    scheduled_date DATE DEFAULT NULL,
    record_date DATE DEFAULT NULL,
    air_date DATE DEFAULT NULL,
    promotion_date DATE DEFAULT NULL,
    
    -- BUSINESS METRICS
    estimated_value DECIMAL(10,2) DEFAULT NULL,
    actual_value DECIMAL(10,2) DEFAULT NULL,
    commission DECIMAL(10,2) DEFAULT NULL,
    
    -- TIMESTAMPS
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (id),
    KEY user_id_idx (user_id),
    KEY guest_id_idx (guest_id),
    KEY engagement_id_idx (engagement_id),
    KEY podcast_id_idx (podcast_id),
    KEY status_idx (status),
    KEY is_archived_idx (is_archived)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### Layer 2: `pit_engagements` (Public Record)

```sql
CREATE TABLE {prefix}pit_engagements (
    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    
    -- UNIQUENESS IDENTIFICATION
    episode_guid VARCHAR(255) DEFAULT NULL,
    uniqueness_hash CHAR(32) DEFAULT NULL,
    canonical_url TEXT DEFAULT NULL,
    
    -- ENGAGEMENT TYPE
    engagement_type ENUM(
        'podcast', 'youtube', 'webinar', 'conference', 'summit',
        'panel', 'interview', 'livestream', 'fireside_chat', 'workshop',
        'ama', 'roundtable', 'keynote', 'twitter_space', 'linkedin_live',
        'clubhouse', 'other'
    ) DEFAULT 'podcast',
    
    -- PLATFORM REFERENCE
    podcast_id bigint(20) UNSIGNED DEFAULT NULL,
    
    -- DETAILS
    title VARCHAR(500) NOT NULL,
    description TEXT DEFAULT NULL,
    episode_number INT(11) DEFAULT NULL,
    season_number INT(11) DEFAULT NULL,
    
    -- URLs
    url TEXT DEFAULT NULL,
    embed_url TEXT DEFAULT NULL,
    audio_url TEXT DEFAULT NULL,
    video_url TEXT DEFAULT NULL,
    thumbnail_url TEXT DEFAULT NULL,
    transcript_url TEXT DEFAULT NULL,
    
    -- TIMING
    engagement_date DATE DEFAULT NULL,
    published_date DATE DEFAULT NULL,
    duration_seconds INT(11) DEFAULT NULL,
    
    -- CONTENT ANALYSIS
    topics TEXT DEFAULT NULL,
    key_quotes TEXT DEFAULT NULL,
    summary TEXT DEFAULT NULL,
    ai_summary TEXT DEFAULT NULL,
    
    -- EVENT INFO
    event_name VARCHAR(255) DEFAULT NULL,
    event_location VARCHAR(255) DEFAULT NULL,
    event_url TEXT DEFAULT NULL,
    
    -- METRICS
    view_count INT(11) DEFAULT NULL,
    like_count INT(11) DEFAULT NULL,
    comment_count INT(11) DEFAULT NULL,
    share_count INT(11) DEFAULT NULL,
    
    -- VERIFICATION
    is_verified TINYINT(1) DEFAULT 0,
    verified_by_user_id bigint(20) UNSIGNED DEFAULT NULL,
    verified_at DATETIME DEFAULT NULL,
    
    -- DISCOVERY
    discovered_by_user_id bigint(20) UNSIGNED DEFAULT NULL,
    discovery_source VARCHAR(50) DEFAULT NULL,
    
    -- TIMESTAMPS
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    PRIMARY KEY (id),
    KEY engagement_type_idx (engagement_type),
    KEY podcast_id_idx (podcast_id),
    KEY engagement_date_idx (engagement_date),
    KEY is_verified_idx (is_verified),
    UNIQUE KEY episode_guid_unique (episode_guid),
    UNIQUE KEY uniqueness_hash_unique (uniqueness_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### Layer 3: `pit_speaking_credits` (Guest Credits)

```sql
CREATE TABLE {prefix}pit_speaking_credits (
    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    
    guest_id bigint(20) UNSIGNED NOT NULL,
    engagement_id bigint(20) UNSIGNED NOT NULL,
    
    role ENUM(
        'guest', 'host', 'co_host', 'panelist', 'moderator',
        'speaker', 'interviewer', 'interviewee', 'contributor'
    ) DEFAULT 'guest',
    
    is_primary TINYINT(1) DEFAULT 1,
    credit_order TINYINT(4) DEFAULT 1,
    
    ai_confidence_score INT(11) DEFAULT 0,
    manually_verified TINYINT(1) DEFAULT 0,
    verified_by_user_id bigint(20) UNSIGNED DEFAULT NULL,
    verified_at DATETIME DEFAULT NULL,
    
    discovered_by_user_id bigint(20) UNSIGNED DEFAULT NULL,
    extraction_method VARCHAR(50) DEFAULT NULL,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    PRIMARY KEY (id),
    KEY guest_id_idx (guest_id),
    KEY engagement_id_idx (engagement_id),
    UNIQUE KEY guest_engagement_role_unique (guest_id, engagement_id, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## Claiming System Architecture

### Claim Workflow

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. USER SIGNS UP → Goes to "My Profile" page                    │
├─────────────────────────────────────────────────────────────────┤
│ 2. SEARCH → User searches: "Tony Smith"                         │
│    System shows matching guests from global pit_guests          │
├─────────────────────────────────────────────────────────────────┤
│ 3. INITIATE CLAIM → User clicks "This is Me"                    │
│    System creates pit_claim_requests record                     │
├─────────────────────────────────────────────────────────────────┤
│ 4. VERIFICATION                                                 │
│    ├─ Method A: EMAIL MATCH (Auto-Approve)                      │
│    │   IF guest.email == user.email → auto-approve              │
│    ├─ Method B: LINKEDIN OAUTH (Auto-Approve)                   │
│    │   IF LinkedIn URL matches → auto-approve                   │
│    └─ Method C: MANUAL REVIEW (Admin)                           │
│        IF no auto-match → pending for admin review              │
├─────────────────────────────────────────────────────────────────┤
│ 5. CLAIM APPROVED                                               │
│    guest.claimed_by_user_id = user.id                           │
│    guest.claim_status = 'verified'                              │
│    User gains EDIT rights to their public profile               │
└─────────────────────────────────────────────────────────────────┘
```

### "My Appearances" Query

```sql
SELECT e.*, sc.role
FROM pit_engagements e
INNER JOIN pit_speaking_credits sc ON e.id = sc.engagement_id
INNER JOIN pit_guests g ON sc.guest_id = g.id
WHERE g.claimed_by_user_id = :current_user_id
ORDER BY e.engagement_date DESC;
```

---

## Entity Relationship Diagram

```
┌─────────────────┐      ┌─────────────────────────┐      ┌─────────────────┐
│   wp_users      │      │      pit_guests         │      │ pit_engagements │
│ (WordPress)     │      │      (Global)           │      │ (Global)        │
├─────────────────┤      ├─────────────────────────┤      ├─────────────────┤
│ id ─────────────┼──┬──►│ claimed_by_user_id      │      │ id              │
│ email           │  │   │ id ─────────────────────┼──┐   │ episode_guid    │
└─────────────────┘  │   │ full_name (PUBLIC)      │  │   │ uniqueness_hash │
                     │   │ email (PUBLIC/business) │  │   │ title           │
                     │   │ linkedin_url (PUBLIC)   │  │   └─────────────────┘
                     │   └─────────────────────────┘  │           │
                     │               │                │           │
                     │               ▼                │           │
                     │   ┌─────────────────────────┐  │           │
                     │   │pit_guest_private_contacts│ │           │
                     │   │     (User-owned)        │  │           │
                     │   ├─────────────────────────┤  │           │
                     └──►│ user_id                 │  │           │
                         │ guest_id ───────────────┼──┘           │
                         │ personal_email (PRIVATE)│              │
                         │ phone (PRIVATE)         │              │
                         └─────────────────────────┘              │
                                                                  │
┌─────────────────────────────────────────────────────────────────┼───────┐
│                                                                 │       │
│  ┌─────────────────────┐           ┌───────────────────────────┐│       │
│  │ pit_opportunities   │           │ pit_speaking_credits      ││       │
│  │ (User-owned CRM)    │           │ (Global link table)       ││       │
│  ├─────────────────────┤           ├───────────────────────────┤│       │
│  │ user_id (owner)     │           │ guest_id ─────────────────┼┼───────┘
│  │ guest_id ───────────┼───────────┼─► engagement_id ──────────┼┼─► pit_engagements
│  │ engagement_id ──────┼───────────┼───────────────────────────┤│
│  │ podcast_id          │           │ role                      ││
│  │ status              │           └───────────────────────────┘│
│  │ priority            │                                        │
│  └─────────────────────┘                                        │
└─────────────────────────────────────────────────────────────────┘
```

---

## Migration Strategy

### Phase 1: Schema Design (Day 1) ✅
- Finalize column definitions (this document)
- Document risk mitigations

### Phase 2: Create New Tables (Day 2)
- `pit_opportunities`
- `pit_engagements`
- `pit_speaking_credits`
- `pit_claim_requests`
- `pit_guest_private_contacts`
- Modify `pit_guests` (add claiming columns, rename user_id)

### Phase 3: Guest Deduplication & Globalization (Days 3-4)
- Identify duplicates by email_hash, linkedin_url_hash
- Smart merge (fill blanks, don't overwrite)
- Update foreign key references
- Migrate private contact info to new table

### Phase 4: Data Migration (Days 5-6)
- `pit_guest_appearances` → `pit_opportunities` + `pit_engagements`
- Create `uniqueness_hash` for existing engagements

### Phase 5: Code Updates (Days 7-9)
- New repository classes
- Updated REST APIs
- Claim workflow endpoints

### Phase 6: UI/UX Updates (Days 10-11)
- Claim profile flow
- "My Appearances" page
- Private contacts UI

### Phase 7: Testing & Cleanup (Day 12)
- Full test suite
- Drop/archive old tables

**Total: 12 days**

---

## Immediate Action Items

```sql
-- 1. Add claiming columns NOW (future-proof)
ALTER TABLE pit_guests 
ADD COLUMN claimed_by_user_id bigint(20) UNSIGNED DEFAULT NULL,
ADD COLUMN claim_status ENUM('unclaimed', 'pending', 'verified', 'rejected') DEFAULT 'unclaimed',
ADD COLUMN claim_verified_at DATETIME DEFAULT NULL,
ADD COLUMN claim_verification_method VARCHAR(50) DEFAULT NULL,
ADD KEY claimed_by_user_id_idx (claimed_by_user_id),
ADD KEY claim_status_idx (claim_status);

-- 2. Rename user_id for semantic clarity
ALTER TABLE pit_guests 
CHANGE COLUMN user_id created_by_user_id bigint(20) UNSIGNED DEFAULT NULL;

-- 3. Create private contacts table
CREATE TABLE pit_guest_private_contacts (
    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id bigint(20) UNSIGNED NOT NULL,
    guest_id bigint(20) UNSIGNED NOT NULL,
    personal_email VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(50) DEFAULT NULL,
    mobile_phone VARCHAR(50) DEFAULT NULL,
    private_notes TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY user_guest_unique (user_id, guest_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Migrate existing private data
INSERT INTO pit_guest_private_contacts (user_id, guest_id, personal_email, phone)
SELECT created_by_user_id, id, personal_email, phone
FROM pit_guests
WHERE (personal_email IS NOT NULL OR phone IS NOT NULL)
AND created_by_user_id IS NOT NULL;

-- 5. Remove private fields from global table
ALTER TABLE pit_guests
DROP COLUMN personal_email,
DROP COLUMN phone;
```
