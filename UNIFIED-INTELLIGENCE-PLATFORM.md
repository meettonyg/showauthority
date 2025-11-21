# Guestify Unified Intelligence Platform
## Complete Podcast & Guest Intelligence System

**Version:** 2.0 - Unified Architecture  
**Last Updated:** November 21, 2025

---

## 🎯 Executive Vision

Build a **comprehensive podcast intelligence platform** that provides BOTH:

1. **Podcast-Level Intelligence** (Influence Tracking)
   - Social media metrics and growth tracking
   - Platform-specific analytics (YouTube, Twitter, LinkedIn)
   - Audience size and engagement patterns
   
2. **Guest-Level Intelligence** (Guest Analysis)
   - Complete guest profiles with contact information
   - Guest network mapping and referral paths
   - Topic analysis and conversation patterns

**Core Innovation:** Single RSS parse feeds TWO intelligence systems, creating the most comprehensive podcast research platform available.

---

## 🏗️ Unified Architecture

```
┌──────────────────────────────────────────────────────────┐
│        GUESTIFY UNIFIED INTELLIGENCE PLATFORM             │
│              (WordPress Plugin + Vue 3)                   │
└──────────────────────────────────────────────────────────┘
                            │
                            ▼
                  ┌──────────────────┐
                  │   RSS PARSER     │
                  │   (SimplePie)    │
                  │                  │
                  │ Single Parse →   │
                  │ Dual Intelligence│
                  └──────────────────┘
                            │
            ┌───────────────┴───────────────┐
            │                               │
            ▼                               ▼
┌─────────────────────┐         ┌─────────────────────┐
│  PODCAST LEVEL      │         │   GUEST LEVEL       │
│  INTELLIGENCE       │         │   INTELLIGENCE      │
│                     │         │                     │
│  Layer 1: Social    │         │  Layer 1: Content   │
│  Discovery (Free)   │         │  Analysis (Free)    │
│  • Homepage scraping│         │  • Topic clusters   │
│  • RSS link parsing │         │  • Keyword analysis │
│  • Auto-population  │         │  • Publishing       │
│                     │         │    patterns         │
│  Layer 2: Metrics   │         │                     │
│  (Paid On-Demand)   │         │  Layer 2: Guest     │
│  • YouTube API      │         │  Extraction (AI)    │
│  • Apify Twitter    │         │  • GPT-4 profiles   │
│  • Apify LinkedIn   │         │  • Companies/roles  │
│  • Follower counts  │         │  • Topics per guest │
│  • Engagement rates │         │  • Achievements     │
│                     │         │                     │
│  Layer 3: Auto      │         │  Layer 3: Contact   │
│  Refresh (Weekly)   │         │  Enrichment (Clay)  │
│  • Tracked shows    │         │  • Verified emails  │
│  • Budget tracking  │         │  • LinkedIn profiles│
│  • Cost monitoring  │         │  • Social handles   │
│                     │         │  • Network mapping  │
└─────────────────────┘         └─────────────────────┘
            │                               │
            └───────────┬───────────────────┘
                        ▼
              ┌──────────────────┐
              │  UNIFIED         │
              │  DATABASE        │
              │                  │
              │ • Podcasts       │
              │ • Social Links   │
              │ • Metrics        │
              │ • Guests         │
              │ • Contacts       │
              │ • Network        │
              └──────────────────┘
                        │
                        ▼
              ┌──────────────────┐
              │  UNIFIED UI      │
              │  (Vue 3)         │
              │                  │
              │ Multi-Tab        │
              │ Dashboard        │
              └──────────────────┘
```

---

## 💾 Unified Database Schema

### Core Tables (Shared Foundation)

#### 1. Master Podcasts Table
```sql
CREATE TABLE pit_podcasts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    -- Identity
    rss_url VARCHAR(2048) NOT NULL,
    rss_url_hash CHAR(32) NOT NULL, -- MD5 for deduplication
    title VARCHAR(255),
    author VARCHAR(255),
    description TEXT,
    image_url TEXT,
    homepage_url TEXT,
    
    -- Metadata
    episode_count INT DEFAULT 0,
    publishing_frequency VARCHAR(50), -- 'daily', 'weekly', 'bi-weekly'
    language VARCHAR(10),
    categories JSON, -- iTunes categories
    
    -- Analysis Status
    social_discovered BOOLEAN DEFAULT FALSE,
    social_discovered_at DATETIME,
    metrics_tracked BOOLEAN DEFAULT FALSE,
    guests_analyzed BOOLEAN DEFAULT FALSE,
    guests_analyzed_at DATETIME,
    
    -- Cache Management
    rss_fetched_at DATETIME,
    cache_expires_at DATETIME,
    
    -- Timestamps
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes
    UNIQUE KEY rss_url_hash (rss_url_hash),
    KEY social_discovered (social_discovered),
    KEY metrics_tracked (metrics_tracked),
    KEY guests_analyzed (guests_analyzed),
    KEY cache_expires_at (cache_expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Podcast-Level Intelligence Tables

#### 2. Social Links Table (Influence Tracker)
```sql
CREATE TABLE pit_social_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    podcast_id BIGINT UNSIGNED NOT NULL,
    
    -- Platform & Identity
    platform VARCHAR(50) NOT NULL, -- 'youtube', 'twitter', 'linkedin', 'facebook', 'instagram'
    url TEXT NOT NULL,
    handle VARCHAR(255), -- @username or channel name
    
    -- Discovery Source
    discovered_from VARCHAR(50), -- 'rss', 'homepage', 'manual'
    discovered_at DATETIME,
    
    -- Verification
    verified BOOLEAN DEFAULT FALSE,
    last_verified_at DATETIME,
    
    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    KEY podcast_id (podcast_id),
    KEY platform (platform),
    KEY is_active (is_active),
    
    FOREIGN KEY (podcast_id) REFERENCES pit_podcasts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### 3. Social Metrics Table (Influence Tracker)
```sql
CREATE TABLE pit_metrics (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    social_link_id BIGINT UNSIGNED NOT NULL,
    
    -- Metrics
    followers INT DEFAULT 0,
    following INT DEFAULT 0,
    posts_count INT DEFAULT 0,
    engagement_rate DECIMAL(5,2) DEFAULT 0,
    avg_likes INT DEFAULT 0,
    avg_comments INT DEFAULT 0,
    avg_shares INT DEFAULT 0,
    
    -- YouTube Specific
    subscribers INT DEFAULT 0,
    total_views BIGINT DEFAULT 0,
    video_count INT DEFAULT 0,
    
    -- Metadata
    fetched_at DATETIME NOT NULL,
    fetch_method VARCHAR(50), -- 'youtube_api', 'apify', 'manual'
    data_quality_score INT, -- 0-100 confidence
    
    -- Cost Tracking
    api_cost DECIMAL(10,4) DEFAULT 0,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    KEY social_link_id (social_link_id),
    KEY fetched_at (fetched_at),
    
    FOREIGN KEY (social_link_id) REFERENCES pit_social_links(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### 4. Metrics Jobs Queue (Influence Tracker)
```sql
CREATE TABLE pit_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    social_link_id BIGINT UNSIGNED NOT NULL,
    
    -- Job Details
    job_type VARCHAR(50) NOT NULL, -- 'initial', 'refresh', 'manual'
    status VARCHAR(50) DEFAULT 'pending', -- 'pending', 'running', 'completed', 'failed'
    priority INT DEFAULT 5, -- 1-10, higher = more urgent
    
    -- Execution
    attempts INT DEFAULT 0,
    max_attempts INT DEFAULT 3,
    started_at DATETIME,
    completed_at DATETIME,
    error_message TEXT,
    
    -- Scheduling
    scheduled_for DATETIME,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    KEY social_link_id (social_link_id),
    KEY status (status),
    KEY scheduled_for (scheduled_for),
    
    FOREIGN KEY (social_link_id) REFERENCES pit_social_links(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### 5. Cost Tracking Table (Influence Tracker)
```sql
CREATE TABLE pit_cost_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    -- Association
    podcast_id BIGINT UNSIGNED,
    social_link_id BIGINT UNSIGNED,
    
    -- Cost Details
    service VARCHAR(50) NOT NULL, -- 'youtube_api', 'apify_twitter', 'apify_linkedin'
    cost DECIMAL(10,4) NOT NULL,
    currency VARCHAR(3) DEFAULT 'USD',
    
    -- Context
    operation VARCHAR(50), -- 'initial_fetch', 'refresh', 'retry'
    success BOOLEAN DEFAULT TRUE,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    KEY podcast_id (podcast_id),
    KEY social_link_id (social_link_id),
    KEY service (service),
    KEY created_at (created_at),
    
    FOREIGN KEY (podcast_id) REFERENCES pit_podcasts(id) ON DELETE SET NULL,
    FOREIGN KEY (social_link_id) REFERENCES pit_social_links(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Guest-Level Intelligence Tables

#### 6. Content Analysis Table (Guest Intelligence)
```sql
CREATE TABLE guestify_content_analysis (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    podcast_id BIGINT UNSIGNED NOT NULL,
    
    -- Content Intelligence (JSON)
    title_patterns JSON, -- Episode naming conventions
    topic_clusters JSON, -- AI-detected topic groups with percentages
    keywords JSON, -- Top 50 keywords with frequencies
    recent_episodes JSON, -- Last 15 episodes with metadata
    
    -- Analysis Metadata
    episodes_analyzed INT DEFAULT 0,
    ai_analyzed BOOLEAN DEFAULT FALSE,
    ai_analyzed_at DATETIME,
    ai_cost DECIMAL(10,4) DEFAULT 0,
    
    -- Cache
    cache_expires_at DATETIME,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    KEY podcast_id (podcast_id),
    KEY ai_analyzed (ai_analyzed),
    KEY cache_expires_at (cache_expires_at),
    
    FOREIGN KEY (podcast_id) REFERENCES pit_podcasts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### 7. Guests Table (Guest Intelligence)
```sql
CREATE TABLE guestify_guests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    -- Identity
    full_name VARCHAR(255) NOT NULL,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    
    -- Professional Info
    current_company VARCHAR(255),
    current_role VARCHAR(255),
    company_stage VARCHAR(50), -- 'startup', 'scaleup', 'enterprise'
    company_revenue VARCHAR(50), -- e.g. "$2M ARR"
    industry VARCHAR(100),
    
    -- Background & Expertise
    expertise_areas JSON, -- ["AI", "SaaS Scaling"]
    past_companies JSON, -- ["Google", "YCombinator"]
    education JSON,
    notable_achievements TEXT,
    
    -- Contact Information (Clay enriched)
    email VARCHAR(255),
    personal_email VARCHAR(255),
    phone VARCHAR(50),
    linkedin_url TEXT,
    twitter_handle VARCHAR(100),
    website_url TEXT,
    
    -- Social Proof
    linkedin_connections INT,
    twitter_followers INT,
    verified_accounts JSON, -- {"linkedin": true, "twitter": true}
    
    -- Enrichment Status
    clay_enriched BOOLEAN DEFAULT FALSE,
    clay_enriched_at DATETIME,
    clay_cost DECIMAL(10,4) DEFAULT 0,
    data_quality_score INT, -- 0-100 confidence
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    KEY full_name (full_name),
    KEY email (email),
    KEY company (current_company),
    KEY clay_enriched (clay_enriched)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### 8. Guest Appearances Table (Guest Intelligence)
```sql
CREATE TABLE guestify_guest_appearances (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    guest_id BIGINT UNSIGNED NOT NULL,
    podcast_id BIGINT UNSIGNED NOT NULL,
    
    -- Episode Details
    episode_number INT,
    episode_title VARCHAR(500),
    episode_date DATE,
    episode_url TEXT,
    episode_duration INT, -- seconds
    
    -- Content Analysis
    topics_discussed JSON, -- ["AI Automation", "Scaling"]
    key_quotes JSON, -- Notable quotes
    conversation_style VARCHAR(50), -- 'interview', 'discussion', 'panel'
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    KEY guest_id (guest_id),
    KEY podcast_id (podcast_id),
    KEY episode_date (episode_date),
    
    FOREIGN KEY (guest_id) REFERENCES guestify_guests(id) ON DELETE CASCADE,
    FOREIGN KEY (podcast_id) REFERENCES pit_podcasts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### 9. Guest Network Table (Guest Intelligence)
```sql
CREATE TABLE guestify_guest_network (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    guest_id BIGINT UNSIGNED NOT NULL,
    connected_guest_id BIGINT UNSIGNED NOT NULL,
    
    connection_type VARCHAR(50), -- 'same_podcast', 'mutual_connection'
    connection_strength INT, -- 0-100 score
    
    -- Network Data
    common_podcasts JSON, -- Podcast IDs where both appeared
    linkedin_degree INT, -- 1st/2nd/3rd degree
    connection_path JSON, -- ["You", "Jane Smith", "Guest"]
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    KEY guest_id (guest_id),
    KEY connection_strength (connection_strength),
    
    FOREIGN KEY (guest_id) REFERENCES guestify_guests(id) ON DELETE CASCADE,
    FOREIGN KEY (connected_guest_id) REFERENCES guestify_guests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 🎨 Unified User Interface

### Main Dashboard Structure

```
┌────────────────────────────────────────────────────────┐
│  GUESTIFY INTELLIGENCE PLATFORM                        │
│  [Podcast Library] [Analytics] [Settings]             │
└────────────────────────────────────────────────────────┘

PODCAST LIBRARY PAGE
├─ Search & Filters
│  ├─ Search by name, host, category
│  ├─ Filter: Tracked only, Has guests analyzed, etc.
│  └─ Sort: Name, Date Added, Episode Count
│
└─ Podcast Grid/List
   ├─ Podcast Card #1
   │  ├─ Thumbnail + Title
   │  ├─ Quick stats: 120 episodes, 45 guests
   │  ├─ Status badges: 🟢 Tracked, ✅ Analyzed
   │  └─ Actions: View Details, Track, Analyze
   │
   ├─ Podcast Card #2
   └─ Podcast Card #3
```

### Podcast Detail Page (Unified Multi-Tab)

```
┌────────────────────────────────────────────────────────┐
│  PODCAST: The SaaS Breakthrough Show                   │
│  120 episodes • Weekly • Tech/Business                 │
│                                                        │
│  [Overview] [Social Metrics] [Guests] [Content] [Export] │
└────────────────────────────────────────────────────────┘

════════════════════════════════════════════════════════
TAB 1: OVERVIEW
════════════════════════════════════════════════════════

PODCAST INFORMATION
├─ Title: The SaaS Breakthrough Show
├─ Host: Mike Johnson
├─ Description: [Full description]
├─ RSS: https://feeds.example.com/saas
├─ Homepage: https://saasbreakthrough.com
└─ Categories: Technology, Business, Entrepreneurship

QUICK STATS
┌─────────────┬─────────────┬─────────────┬─────────────┐
│ 120         │ Weekly      │ 45          │ 3           │
│ Episodes    │ Frequency   │ Guests      │ Social Accts│
└─────────────┴─────────────┴─────────────┴─────────────┘

ANALYSIS STATUS
├─ ✅ Social accounts discovered (3 platforms)
├─ 🟢 Social metrics tracked (updated 2 days ago)
├─ ✅ Guests analyzed (45 profiles extracted)
└─ ⏳ Contact enrichment pending (click to start)

ACTIONS
[Track Social Metrics] [Analyze Guests] [Enrich Contacts]

════════════════════════════════════════════════════════
TAB 2: SOCIAL METRICS (Influence Tracker)
════════════════════════════════════════════════════════

SOCIAL ACCOUNTS DISCOVERED
┌─ YouTube ─────────────────────────────────────────────┐
│ 🔴 SaaS Breakthrough                                   │
│ 📊 8,500 subscribers (+15% monthly growth)            │
│ 📈 Avg 2,400 views/video                              │
│ 🎥 120 videos                                          │
│ 💰 Cost to track: $0.05/month (YouTube API)           │
│ [View Channel] [Update Metrics] [Stop Tracking]       │
└────────────────────────────────────────────────────────┘

┌─ Twitter ──────────────────────────────────────────────┐
│ 🐦 @saasbreakthrough                                   │
│ 📊 12,000 followers (+8% monthly growth)              │
│ 💬 3.2% engagement rate                                │
│ 📅 Last updated: 2 days ago                            │
│ 💰 Cost to track: $0.05/month (Apify)                 │
│ [View Profile] [Update Metrics] [Stop Tracking]       │
└────────────────────────────────────────────────────────┘

┌─ LinkedIn ─────────────────────────────────────────────┐
│ 💼 SaaS Breakthrough                                   │
│ 📊 4,200 followers                                     │
│ 📅 Last updated: 3 days ago                            │
│ 💰 Cost to track: $0.10/month (Apify)                 │
│ [View Page] [Update Metrics] [Stop Tracking]          │
└────────────────────────────────────────────────────────┘

METRICS HISTORY (Line Chart)
├─ Followers over time
├─ Growth rate trends
└─ Platform comparison

TOTAL TRACKING COST: $0.20/month
[Update All Metrics Now]

════════════════════════════════════════════════════════
TAB 3: GUESTS (Guest Intelligence)
════════════════════════════════════════════════════════

GUEST PROFILE BREAKDOWN
┌─────────────────────────────────────────────────────┐
│ 👤 Guest Types (45 total)                           │
│ ├─ Founders/CEOs (65%) ███████████░░░░░ 29 guests  │
│ ├─ Technical (25%)     ████░░░░░░░░░░░░ 11 guests  │
│ └─ VCs/Investors (10%) ██░░░░░░░░░░░░░░  5 guests  │
│                                                      │
│ 🏢 Company Stages                                    │
│ ├─ Post-Exit (30%)     ██████░░░░░░░░░░ 13 guests  │
│ ├─ $1M-$10M (45%)      █████████░░░░░░░ 20 guests  │
│ └─ Pre-$1M (25%)       █████░░░░░░░░░░░ 12 guests  │
│                                                      │
│ 🎓 Background Patterns                               │
│ ├─ YC Alumni: 8 guests                              │
│ ├─ FAANG Experience: 12 guests                      │
│ └─ Serial Entrepreneurs: 6 guests                   │
└─────────────────────────────────────────────────────┘

GUEST DIRECTORY
[Search guests...] [Filter: All ▾] [Sort: Name ▾]

┌─ Sarah Chen ──────────────────────────────────────────┐
│ CEO, TechCorp • $10M ARR • Scaleup                    │
│ Episode 47 (Jan 2024)                                 │
│ Topics: AI Automation, Scaling, Product-Market Fit    │
│                                                        │
│ 📧 sarah@techcorp.ai [Copy]                           │
│ 💼 linkedin.com/in/sarahchen [Open]                   │
│ 🐦 @sarahchen (12.5K followers) [Open]               │
│                                                        │
│ 🔗 Also appeared on:                                  │
│    • SaaS Breakthrough (EP 102)                       │
│    • Indie Hackers (EP 234)                          │
│                                                        │
│ 👥 Mutual connections: 12                             │
│    • Jane Smith (1st degree)                         │
│    • Tom Lee (2nd degree: You → Amy → Tom)           │
│                                                        │
│ [View Full Profile] [Export Contact] [Find Intro]    │
└────────────────────────────────────────────────────────┘

┌─ Mike Johnson ────────────────────────────────────────┐
│ CTO, DevTools Inc • $5M ARR                           │
│ Episode 46 (Dec 2023)                                 │
│ Topics: Engineering, Team Building, Technical Debt    │
│                                                        │
│ 📧 mike@devtools.com [Copy]                           │
│ 💼 linkedin.com/in/mikejohnson [Open]                 │
│                                                        │
│ [View Full Profile] [Export Contact]                  │
└────────────────────────────────────────────────────────┘

[Load More Guests...] (showing 2 of 45)

ENRICHMENT STATUS
├─ Basic profiles: 45/45 (100%)
├─ Contact enriched: 0/45 (0%)
└─ Network mapped: 0/45 (0%)

[Enrich All Contacts] ($12 via Clay API)
[Map Guest Network] (Free)

════════════════════════════════════════════════════════
TAB 4: CONTENT ANALYSIS (Guest Intelligence)
════════════════════════════════════════════════════════

TOPIC CLUSTERS (AI Analysis)
┌─────────────────────────────────────────────────────┐
│ AI & Automation (35%)  ███████░░░░░░░░░░░░░░░░      │
│ Founder Stories (25%)  █████░░░░░░░░░░░░░░░░░░      │
│ Scaling (20%)          ████░░░░░░░░░░░░░░░░░░░      │
│ Fundraising (20%)      ████░░░░░░░░░░░░░░░░░░░      │
└─────────────────────────────────────────────────────┘

TOP KEYWORDS
├─ "SaaS" (48 mentions)
├─ "Scaling" (32 mentions)
├─ "Product-market fit" (28 mentions)
├─ "AI" (24 mentions)
└─ [Show all 50 keywords]

RECENT EPISODES (Last 15)
├─ EP 47: Sarah Chen on AI Productivity Stack
│  Jan 2024 • 58 min • Topics: AI, Automation, Tools
│
├─ EP 46: Mike Johnson on Engineering Leadership
│  Dec 2023 • 62 min • Topics: Teams, Culture, Hiring
│
└─ EP 45: Jane Doe on Fundraising Strategy
   Dec 2023 • 54 min • Topics: VCs, Pitching, Series A

[Show All Episodes]

PUBLISHING PATTERNS
├─ Frequency: Weekly (Tuesdays)
├─ Length: Average 55 minutes
├─ Format: 1-on-1 interviews (90%), Panels (10%)
└─ Guest Pattern: Founders first, then technical deep-dive

════════════════════════════════════════════════════════
TAB 5: EXPORT & INTEGRATION
════════════════════════════════════════════════════════

EXPORT DATA
┌─ Export Social Metrics ───────────────────────────────┐
│ Download current metrics for all social platforms     │
│ Format: CSV, JSON, or Excel                           │
│ [Export Social Metrics]                                │
└────────────────────────────────────────────────────────┘

┌─ Export Guest Directory ──────────────────────────────┐
│ Download all guest profiles and contact information   │
│ Includes: Names, companies, emails, LinkedIn, topics  │
│ Format: CSV, JSON, or vCard                           │
│ [Export Guest Directory]                               │
└────────────────────────────────────────────────────────┘

┌─ Export Content Analysis ─────────────────────────────┐
│ Download topic clusters, keywords, episode data       │
│ Format: CSV or JSON                                   │
│ [Export Content Analysis]                              │
└────────────────────────────────────────────────────────┘

CRM INTEGRATION (Future)
├─ HubSpot: Import guests as contacts
├─ Salesforce: Sync guest data
└─ Zapier: Connect to 3,000+ apps

INTERVIEW TRACKER LINK
├─ Connect this podcast to Interview Tracker entry
└─ [Link to Entry #12345]
```

---

## 🚀 Unified Implementation Roadmap

### 8-Week Complete Implementation

```
WEEK 1-2: SHARED FOUNDATION
═══════════════════════════════════════════════════════

Goals:
✓ Single RSS parser serving both systems
✓ Unified database schema
✓ Link Influence Tracker to Guest Intelligence
✓ Basic podcast library UI

Backend Tasks:
□ Implement unified pit_podcasts table
□ Build RSS parser with SimplePie (12-hour cache)
□ Create podcast discovery/upsert logic
□ Database migrations for all 9 tables
□ Set up WordPress cron for background jobs

Frontend Tasks:
□ Create Vue 3 app shell
□ Podcast library page (grid/list view)
□ Podcast search and filtering
□ Basic podcast detail page structure
□ Navigation and routing

Deliverables:
✓ Working RSS ingestion
✓ Unified database
✓ Basic UI shell

─────────────────────────────────────────────────────────

WEEK 3: PODCAST-LEVEL INTELLIGENCE (Layer 1 & 2)
═══════════════════════════════════════════════════════

Goals:
✓ Social account discovery (free)
✓ Social metrics enrichment (paid on-demand)
✓ "Track This Show" functionality

Backend Tasks:
□ Homepage scraper for social links
□ RSS parser for social links
□ pit_social_links CRUD operations
□ YouTube Data API integration
□ Apify Twitter integration
□ Apify LinkedIn integration
□ pit_metrics storage and retrieval
□ pit_jobs queue system
□ Background worker for metrics fetching
□ Cost tracking in pit_cost_log

Frontend Tasks:
□ Social Metrics tab UI
□ Social account cards with metrics
□ "Track This Show" button
□ Metrics history charts (Chart.js or Recharts)
□ Loading states and progress indicators
□ Cost display per platform

API Endpoints:
POST /pit/v1/podcasts/{id}/discover-social
POST /pit/v1/podcasts/{id}/track-metrics
GET  /pit/v1/podcasts/{id}/social-metrics
POST /pit/v1/social-links/{id}/update-metrics

Deliverables:
✓ Social discovery working
✓ Metrics tracking functional
✓ Cost tracking operational

─────────────────────────────────────────────────────────

WEEK 4: GUEST-LEVEL INTELLIGENCE (Layer 1)
═══════════════════════════════════════════════════════

Goals:
✓ Content analysis (topics, keywords, patterns)
✓ Basic guest extraction (names from titles)
✓ Episode metadata

Backend Tasks:
□ Episode extraction from RSS
□ GPT-4 topic clustering (15 episodes max)
□ Keyword frequency analysis
□ Title pattern detection
□ Basic guest name extraction from titles
□ guestify_content_analysis table operations

Frontend Tasks:
□ Content Analysis tab UI
□ Topic clusters visualization
□ Keywords display (top 50)
□ Recent episodes list
□ Publishing patterns display

API Endpoints:
POST /guestify/v1/podcasts/{id}/analyze-content
GET  /guestify/v1/podcasts/{id}/content-analysis

Deliverables:
✓ Content analysis working
✓ Basic guest names extracted
✓ OpenAI integration functional

─────────────────────────────────────────────────────────

WEEK 5: GUEST PROFILES (Layer 2)
═══════════════════════════════════════════════════════

Goals:
✓ AI-powered guest profile extraction
✓ Complete guest database
✓ Guest-to-episode linking

Backend Tasks:
□ Enhanced GPT-4 guest extraction with structured JSON
□ guestify_guests CRUD operations
□ guestify_guest_appearances linking
□ Guest profile analysis (types, stages, patterns)
□ Guest deduplication logic
□ Guest search and filtering

Frontend Tasks:
□ Guests tab UI
□ Guest profile breakdown card
□ Guest directory with search/sort
□ Individual guest cards
□ Guest detail modal/page

API Endpoints:
POST /guestify/v1/podcasts/{id}/extract-guests
GET  /guestify/v1/podcasts/{id}/guests
GET  /guestify/v1/guests/{id}
GET  /guestify/v1/guests/search

Deliverables:
✓ Complete guest database
✓ Guest patterns analysis
✓ Searchable directory

─────────────────────────────────────────────────────────

WEEK 6: CONTACT ENRICHMENT (Layer 3)
═══════════════════════════════════════════════════════

Goals:
✓ Clay API integration
✓ Email/LinkedIn enrichment
✓ Contact verification

Backend Tasks:
□ Clay API integration class
□ Email enrichment logic
□ LinkedIn profile fetching
□ Social media enrichment
□ Contact validation
□ Data quality scoring
□ Cost tracking for Clay usage

Frontend Tasks:
□ Contact information display
□ Copy-to-clipboard buttons
□ Enrichment status indicators
□ Data quality scores
□ "Enrich Contacts" button
□ Cost preview before enrichment

API Endpoints:
POST /guestify/v1/guests/{id}/enrich
POST /guestify/v1/podcasts/{id}/enrich-all-guests
GET  /guestify/v1/guests/{id}/contact-info

Deliverables:
✓ Verified contact data
✓ LinkedIn profiles
✓ Social handles

─────────────────────────────────────────────────────────

WEEK 7: NETWORK INTELLIGENCE
═══════════════════════════════════════════════════════

Goals:
✓ Guest network mapping
✓ Referral opportunity scoring
✓ Multi-podcast guest tracking

Backend Tasks:
□ Network graph algorithm
□ Multi-podcast guest identification
□ Mutual connection finder (via Clay/LinkedIn)
□ Referral opportunity scoring
□ Connection path calculation
□ guestify_guest_network operations

Frontend Tasks:
□ Network visualization (D3.js)
□ Connection strength indicators
□ Referral opportunity cards
□ Multi-podcast guest highlighting
□ Network stats dashboard
□ "Find Intro Path" feature

API Endpoints:
POST /guestify/v1/podcasts/{id}/map-network
GET  /guestify/v1/guests/{id}/network
GET  /guestify/v1/guests/{id}/referral-paths

Deliverables:
✓ Visual network map
✓ Referral scoring
✓ Connection finder

─────────────────────────────────────────────────────────

WEEK 8: POLISH & OPTIMIZATION
═══════════════════════════════════════════════════════

Goals:
✓ Export functionality
✓ Performance optimization
✓ Testing and bug fixes

Backend Tasks:
□ Export endpoints (CSV, JSON, vCard)
□ Batch operations optimization
□ Database query optimization
□ Cache strategy refinement
□ Background job optimization
□ Error handling improvements

Frontend Tasks:
□ Export tab UI
□ Format selection (CSV/JSON/Excel)
□ Loading states polish
□ Error messaging improvements
□ Mobile responsive design
□ Accessibility improvements
□ Documentation

Testing:
□ End-to-end testing with 10 real podcasts
□ API testing (all endpoints)
□ Cost tracking verification
□ Performance testing
□ Security audit
□ Browser compatibility

Documentation:
□ API documentation
□ User guide
□ Admin documentation
□ Deployment guide

Deliverables:
✓ Production-ready system
✓ Complete documentation
✓ All features tested
```

---

## 💰 Complete Cost Analysis

### Development Costs

| Phase | Time | Dev Cost @ $100/hr |
|-------|------|-------------------|
| Week 1-2: Foundation | 80 hrs | $8,000 |
| Week 3: Podcast Intelligence | 40 hrs | $4,000 |
| Week 4: Content Analysis | 40 hrs | $4,000 |
| Week 5: Guest Profiles | 40 hrs | $4,000 |
| Week 6: Contact Enrichment | 40 hrs | $4,000 |
| Week 7: Network Intelligence | 40 hrs | $4,000 |
| Week 8: Polish & Testing | 40 hrs | $4,000 |
| **Total** | **320 hrs** | **$32,000** |

### Operating Costs (Per Podcast)

| Feature | Service | Cost |
|---------|---------|------|
| RSS Parsing | WordPress (free) | $0 |
| Social Discovery | Scraping (free) | $0 |
| **YouTube Metrics** | YouTube Data API | $0.05 |
| **Twitter Metrics** | Apify | $0.05 |
| **LinkedIn Metrics** | Apify | $0.10 |
| **Content Analysis** | OpenAI GPT-4 | $0.05 |
| **Guest Extraction** | OpenAI GPT-4 | $0 (same API call) |
| **Contact Enrichment** | Clay API | $12.00 |
| **Total per podcast** | | **$12.25** |

### Monthly Subscription Tiers

| Tier | Features | Podcasts/Mo | Cost | Price | Margin |
|------|----------|-------------|------|-------|--------|
| **Basic** | Content + Social Discovery | Unlimited | $0 | $49 | 100% |
| **Professional** | + Social Metrics + Guest Extraction | 4 podcasts | $1 | $99 | $98 |
| **Premium** | + Contact Enrichment | 4 podcasts | $50 | $199 | $149 |
| **Enterprise** | + Network Intelligence + 20 podcasts | 20 podcasts | $245 | $499 | $254 |

### Break-Even Analysis

**Professional Tier ($99/month):**
- Break-even: 1 customer (covers minimal API costs)
- Profit at 10 customers: $990 - $10 = $980/month

**Premium Tier ($199/month):**
- Break-even: 1 customer  
- Profit at 10 customers: $1,990 - $500 = $1,490/month

**Enterprise Tier ($499/month):**
- Break-even: 1 customer
- Profit at 5 customers: $2,495 - $1,225 = $1,270/month

### Annual Revenue Projections

**Conservative (Year 1):**
- 20 Basic @ $49 = $980/month
- 10 Professional @ $99 = $990/month  
- 5 Premium @ $199 = $995/month
- **Total: $2,965/month = $35,580/year**

**Moderate (Year 2):**
- 50 Basic @ $49 = $2,450/month
- 25 Professional @ $99 = $2,475/month
- 15 Premium @ $199 = $2,985/month
- 3 Enterprise @ $499 = $1,497/month
- **Total: $9,407/month = $112,884/year**

**Growth (Year 3):**
- 100 Basic @ $49 = $4,900/month
- 50 Professional @ $99 = $4,950/month
- 30 Premium @ $199 = $5,970/month
- 10 Enterprise @ $499 = $4,990/month
- **Total: $20,810/month = $249,720/year**

---

## 🎯 Value Proposition

### Complete Intelligence Package

**What Users Get in ONE Platform:**

#### Podcast-Level Intelligence
- ✅ Social account discovery (YouTube, Twitter, LinkedIn)
- ✅ Follower counts and growth tracking
- ✅ Engagement rate analysis
- ✅ Platform comparison metrics
- ✅ Weekly automatic updates
- ✅ Cost-optimized tracking ($0.20/podcast)

#### Guest-Level Intelligence  
- ✅ Complete guest database (30-40 guests per podcast)
- ✅ Professional profiles (company, role, revenue)
- ✅ Verified contact information (email, LinkedIn, phone)
- ✅ Topic analysis per guest
- ✅ Guest background patterns (YC, FAANG, etc.)
- ✅ Multi-podcast guest tracking

#### Network Intelligence
- ✅ Guest connection mapping
- ✅ Mutual LinkedIn connections
- ✅ Referral opportunity scoring
- ✅ Warm intro path finding
- ✅ Visual network graph
- ✅ Cross-podcast analysis

### Time Savings

| Research Task | Before | After | Savings |
|---------------|--------|-------|---------|
| Find podcast social accounts | 10-15 min | 0 min (auto) | **100%** |
| Track social metrics | 20-30 min/month | 0 min (auto) | **100%** |
| Analyze podcast content | 15-20 min | 0 min (auto) | **100%** |
| Research all guests | 300-400 min (40 guests × 10 min) | 0 min (auto) | **100%** |
| Find guest contact info | 15-20 min per guest | 0 min (auto) | **100%** |
| Map guest network | Impossible | 0 min (auto) | **∞** |
| **Total per podcast** | **6-10 hours** | **2-3 minutes** | **99%+** |

### Competitive Advantages

**No other tool provides ALL of:**
1. Social metrics tracking (Layer 1)
2. Guest profile extraction (Layer 2)
3. Contact information enrichment (Layer 3)
4. Network intelligence and referral paths (Layer 4)
5. Unified dashboard for everything (Layer 5)

**Barriers to Entry:**
- Complex multi-API integration (YouTube, Apify, OpenAI, Clay)
- Sophisticated guest extraction AI
- Network graph algorithms
- Cost optimization strategies
- Unified database architecture

---

## 🔧 Technical Implementation

### Unified RSS Analyzer

```php
class Guestify_Unified_Analyzer {
    
    private $podcast_id;
    private $rss_url;
    
    public function analyze_podcast($rss_url) {
        // 1. Parse RSS once
        $feed = fetch_feed($rss_url);
        
        if (is_wp_error($feed)) {
            return $this->handle_error($feed);
        }
        
        // 2. Create/update podcast record
        $this->podcast_id = $this->upsert_podcast($feed);
        
        // 3. Run BOTH intelligence systems in parallel
        $results = [
            'podcast_intelligence' => $this->run_podcast_intelligence($feed),
            'guest_intelligence' => $this->run_guest_intelligence($feed)
        ];
        
        return $results;
    }
    
    private function run_podcast_intelligence($feed) {
        // LAYER 1: Social Discovery (Free)
        $social_links = $this->discover_social_accounts($feed);
        $this->store_social_links($social_links);
        
        // LAYER 2: Metrics (Paid on-demand)
        if ($this->should_track_metrics()) {
            $this->queue_metrics_jobs($social_links);
        }
        
        // LAYER 3: Background refresh (Automatic)
        if ($this->is_tracked_podcast()) {
            $this->schedule_weekly_refresh();
        }
        
        return [
            'social_accounts' => count($social_links),
            'tracking_status' => $this->is_tracked_podcast(),
            'estimated_cost' => $this->calculate_tracking_cost()
        ];
    }
    
    private function run_guest_intelligence($feed) {
        // LAYER 1: Content Analysis
        $episodes = $this->extract_episodes($feed, 15); // Last 15 only
        $content_analysis = $this->analyze_content($episodes);
        $this->store_content_analysis($content_analysis);
        
        // LAYER 2: Guest Extraction
        $guests = $this->extract_guests($episodes);
        $this->store_guests($guests);
        
        // LAYER 3: Contact Enrichment (Paid)
        if ($this->should_enrich_contacts()) {
            $this->queue_enrichment_jobs($guests);
        }
        
        return [
            'guests_found' => count($guests),
            'content_analyzed' => true,
            'enrichment_status' => $this->get_enrichment_status(),
            'estimated_cost' => $this->calculate_enrichment_cost()
        ];
    }
}
```

### Social Discovery Engine

```php
class Social_Discovery_Engine {
    
    public function discover_social_accounts($feed) {
        $links = [];
        
        // 1. Check RSS feed links
        $links = array_merge($links, $this->parse_rss_links($feed));
        
        // 2. Scrape homepage
        $homepage = $feed->get_link();
        $links = array_merge($links, $this->scrape_homepage($homepage));
        
        // 3. Deduplicate and validate
        $links = $this->deduplicate_links($links);
        $links = $this->validate_links($links);
        
        return $links;
    }
    
    private function scrape_homepage($url) {
        $response = wp_remote_get($url);
        
        if (is_wp_error($response)) {
            return [];
        }
        
        $html = wp_remote_retrieve_body($response);
        
        // Find social links with regex
        $patterns = [
            'youtube' => '/youtube\.com\/(c|channel|user)\/([^"\s<]+)/',
            'twitter' => '/twitter\.com\/([^"\s<]+)/',
            'linkedin' => '/linkedin\.com\/(company|in)\/([^"\s<]+)/',
            'facebook' => '/facebook\.com\/([^"\s<]+)/',
            'instagram' => '/instagram\.com\/([^"\s<]+)/'
        ];
        
        $found_links = [];
        
        foreach ($patterns as $platform => $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[0] as $url) {
                    $found_links[] = [
                        'platform' => $platform,
                        'url' => $url,
                        'discovered_from' => 'homepage'
                    ];
                }
            }
        }
        
        return $found_links;
    }
}
```

### Guest Extraction with GPT-4

```php
class Guest_Extraction_Engine {
    
    public function extract_guest_from_episode($episode) {
        $prompt = $this->build_extraction_prompt($episode);
        
        $response = $this->call_openai($prompt);
        
        return json_decode($response, true);
    }
    
    private function build_extraction_prompt($episode) {
        return "Analyze this podcast episode and extract guest information:

Title: {$episode['title']}
Description: {$episode['description']}

Return JSON with:
{
  \"guest_name\": \"Full name\",
  \"first_name\": \"First\",
  \"last_name\": \"Last\",
  \"company\": \"Company name\",
  \"role\": \"Title/position\",
  \"company_stage\": \"startup/scaleup/enterprise\",
  \"company_revenue\": \"e.g. $2M ARR if mentioned\",
  \"industry\": \"Industry sector\",
  \"topics_discussed\": [\"Topic 1\", \"Topic 2\"],
  \"expertise_areas\": [\"Area 1\", \"Area 2\"],
  \"background\": \"Brief background (YC, FAANG, etc.)\",
  \"achievements\": \"Notable achievements mentioned\"
}

If no guest is identified, return null for guest_name.";
    }
}
```

### Metrics Fetching Queue

```php
class Metrics_Job_Processor {
    
    public function process_job($job_id) {
        $job = $this->get_job($job_id);
        $social_link = $this->get_social_link($job->social_link_id);
        
        $this->update_job_status($job_id, 'running');
        
        try {
            // Fetch metrics based on platform
            switch ($social_link->platform) {
                case 'youtube':
                    $metrics = $this->fetch_youtube_metrics($social_link);
                    break;
                case 'twitter':
                    $metrics = $this->fetch_twitter_metrics($social_link);
                    break;
                case 'linkedin':
                    $metrics = $this->fetch_linkedin_metrics($social_link);
                    break;
            }
            
            // Store metrics
            $this->store_metrics($social_link->id, $metrics);
            
            // Log cost
            $this->log_cost($social_link, $metrics['api_cost']);
            
            $this->update_job_status($job_id, 'completed');
            
        } catch (Exception $e) {
            $this->update_job_status($job_id, 'failed', $e->getMessage());
            $job->attempts++;
            
            if ($job->attempts < $job->max_attempts) {
                $this->reschedule_job($job_id);
            }
        }
    }
    
    private function fetch_youtube_metrics($social_link) {
        // Use existing YouTube API integration
        $youtube = new YouTube_API();
        $channel_id = $this->extract_channel_id($social_link->url);
        
        return $youtube->get_channel_stats($channel_id);
    }
    
    private function fetch_twitter_metrics($social_link) {
        // Use Apify Twitter scraper
        $apify = new Apify_Client();
        $handle = $this->extract_twitter_handle($social_link->url);
        
        return $apify->scrape_twitter_profile($handle);
    }
}
```

### Contact Enrichment with Clay

```php
class Contact_Enrichment_Engine {
    
    public function enrich_guest($guest_id) {
        $guest = $this->get_guest($guest_id);
        
        // Call Clay API
        $clay = new Clay_API();
        $enriched_data = $clay->enrich_person([
            'first_name' => $guest->first_name,
            'last_name' => $guest->last_name,
            'company' => $guest->current_company,
            'linkedin_url' => $guest->linkedin_url
        ]);
        
        if ($enriched_data) {
            // Update guest record
            $this->update_guest_contact_info($guest_id, $enriched_data);
            
            // Log cost
            $this->log_enrichment_cost($guest_id, 6); // 6 Clay credits
            
            return true;
        }
        
        return false;
    }
    
    public function batch_enrich_guests($guest_ids) {
        $results = [];
        
        foreach ($guest_ids as $guest_id) {
            $results[$guest_id] = $this->enrich_guest($guest_id);
            
            // Rate limiting (Clay API limits)
            usleep(100000); // 100ms delay between requests
        }
        
        return $results;
    }
}
```

---

## 📊 Success Metrics

### Technical Metrics
- [ ] RSS parsing success rate > 95%
- [ ] Social discovery success rate > 80% (3+ platforms)
- [ ] Metrics fetching success rate > 90%
- [ ] Guest extraction accuracy > 90%
- [ ] Contact enrichment success rate > 80%
- [ ] API response time < 3s
- [ ] Cache hit rate > 90%
- [ ] Background job completion rate > 95%

### Business Metrics
- [ ] User adoption rate > 60%
- [ ] Average podcasts analyzed per user: 5-10
- [ ] Social tracking adoption: > 40% of podcasts
- [ ] Guest enrichment adoption: > 30% of podcasts
- [ ] Customer retention: > 80% after 3 months
- [ ] Monthly recurring revenue growth: > 15%

### User Experience Metrics
- [ ] User satisfaction (NPS): > 50
- [ ] Feature usage: > 75% use social metrics
- [ ] Feature usage: > 70% use guest directory
- [ ] Data export frequency: > 40% export contacts
- [ ] Search usage: > 60% use search features
- [ ] Time savings reported: > 5 hours per week

---

## 🚦 Next Steps

### Immediate Actions (This Week)
1. ✅ Review unified strategy document
2. ✅ Approve combined architecture approach
3. ✅ Confirm unified database schema
4. ✅ Set up development environment
5. ✅ Create project repository structure

### Short Term (Week 1-2)
1. [ ] Implement unified pit_podcasts table
2. [ ] Build shared RSS parser
3. [ ] Create database migrations
4. [ ] Set up Vue 3 app shell
5. [ ] Build podcast library page

### Medium Term (Week 3-6)
1. [ ] Implement podcast-level intelligence (social metrics)
2. [ ] Implement guest-level intelligence (profiles + enrichment)
3. [ ] Build unified multi-tab UI
4. [ ] Test with 10 real podcasts
5. [ ] Optimize performance and costs

### Long Term (Week 7-8)
1. [ ] Complete network intelligence features
2. [ ] Add export functionality
3. [ ] Polish and optimize everything
4. [ ] Write complete documentation
5. [ ] Launch beta to test users

---

## 📚 Documentation Structure

This unified strategy document consolidates:
- ✅ Podcast Influence Tracker (Phases 3 & 5)
- ✅ Guest Intelligence Platform (all previous docs)
- ✅ Unified architecture and database schema
- ✅ Combined UI design and implementation roadmap
- ✅ Complete cost analysis and revenue model

**Single Source of Truth:** This document replaces all previous separate planning documents.

**Supporting Documents to Create:**
1. API-DOCUMENTATION.md (REST endpoints for both systems)
2. DATABASE-MIGRATION.md (SQL migration scripts)
3. INTEGRATION-GUIDES.md (YouTube, Apify, OpenAI, Clay setup)
4. FRONTEND-COMPONENTS.md (Vue component specifications)
5. TESTING-STRATEGY.md (QA and testing procedures)

---

## 🎉 Vision Summary

**What We're Building:**

The world's first **unified podcast intelligence platform** that provides complete intelligence at BOTH levels:

**Podcast Level:**
- Social account discovery and metrics tracking
- Follower counts, engagement rates, growth trends
- Platform-by-platform analysis
- Automated weekly updates

**Guest Level:**
- Complete guest profiles with professional details
- Verified contact information (email, LinkedIn, phone)
- Topic analysis and conversation patterns
- Guest network mapping with referral scoring

**Together:** A comprehensive intelligence system that answers:
- "How influential is this podcast?" (Influence Tracker)
- "Who are the guests and how can I reach them?" (Guest Intelligence)
- "How can I get a warm introduction?" (Network Intelligence)
- "What topics do they cover?" (Content Analysis)

**Timeline:** 8 weeks to full implementation  
**Investment:** $32K development + $150-500/month operating  
**Revenue Potential:** $49-$499/month per customer  
**Break-Even:** 2-3 customers at Professional tier  
**Profit at Scale:** $20K+/month with 100 customers

**This is the complete podcast intelligence platform the market has been waiting for.** 🚀

---

**Ready to build the future of podcast intelligence!**
