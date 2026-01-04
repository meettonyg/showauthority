# Changelog

All notable changes to the Guestify Interview Tracker plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2025-11-22

### Major Restructure

Complete codebase restructuring for production readiness.

### Changed

#### Architecture
- **Domain-based organization** - Reorganized from phase-based (`layer-1/`, `layer-2/`, `layer-3/`) to domain-based (`Podcasts/`, `Guests/`, `SocialMetrics/`, `Jobs/`)
- **Repository pattern** - Clean separation of data access from business logic
- **Split REST controllers** - Monolithic controller split into domain-specific controllers (`REST_Podcasts`, `REST_Guests`, `REST_Export`)
- **Unified database schema** - Consolidated `pit_*` and `guestify_*` tables into single unified schema

#### Database
- New unified schema with proper foreign keys and indexes
- Added `pit_guest_appearances` table
- Added `pit_topics` and `pit_guest_topics` tables
- Added `pit_guest_network` table for network connections
- Added `pit_content_analysis` table
- Added `pit_podcast_contacts` table

#### New Features
- Guest network analysis (1st and 2nd degree connections)
- Topic taxonomy for guest expertise
- Guest verification workflow
- Improved deduplication (LinkedIn URL hash, email hash)
- CSV and JSON export for guests, podcasts, and network data

### Removed
- Obsolete implementation documentation files (190KB total)
- Phase-based folder naming

### Fixed
- Export queries using wrong column names
- Missing metrics table in database creation
- Inconsistent permission callbacks in REST API

---

## [1.0.0] - 2025-11-20

### Initial Release

Complete implementation of the Podcast Influence Tracking System with hybrid "just-in-time" strategy.

### Added

#### Core Features
- **Three-Layer Architecture**
  - Layer 1: Immediate, free social link discovery from RSS and homepage
  - Layer 2: On-demand metrics fetching with async job queue
  - Layer 3: Automatic weekly background refresh for tracked podcasts

#### Database Schema
- `pit_podcasts` - Core podcast information storage
- `pit_social_links` - Discovered social media profiles
- `pit_metrics` - Collected metrics data with 7-day caching
- `pit_jobs` - Async job queue with retry logic
- `pit_cost_log` - Detailed cost tracking and analytics

#### Discovery Engine (Layer 1)
- RSS feed parser supporting RSS 2.0 and Atom formats
- Homepage scraper with multiple detection methods:
  - HTML link pattern matching
  - Open Graph meta tag parsing
  - JSON-LD structured data extraction
- Support for 8 platforms: Twitter, Instagram, Facebook, YouTube, LinkedIn, TikTok, Spotify, Apple Podcasts
- Social link normalization and deduplication
- < 3 second discovery time
- $0 cost

#### Job Queue System (Layer 2)
- WordPress Cron-based async processing
- Priority-based job execution
- Automatic retry with exponential backoff (max 3 attempts)
- Real-time progress tracking
- Job status polling from frontend
- Concurrent job support

#### Background Refresh (Layer 3)
- Weekly automatic refresh for tracked podcasts
- Budget-aware execution with automatic stopping
- Platform-specific refresh logic
- Manual refresh option
- Respects cache expiry (7 days)

#### API Integrations
- **YouTube Data API v3**
  - Free tier (10,000 quota units/day)
  - Channel statistics (subscribers, views, videos)
  - Engagement metrics from recent videos
  - Automatic channel ID resolution
- **Apify Platform**
  - Twitter profile scraping
  - Instagram profile scraping
  - Facebook page scraping
  - LinkedIn profile scraping
  - TikTok profile scraping
  - Actor run management with polling
  - Dataset fetching and parsing

#### REST API
- Complete REST API with 15 endpoints
- Podcast management (CRUD operations)
- Tracking operations (track, untrack, refresh)
- Social link management
- Metrics retrieval
- Job status and control
- Statistics and analytics
- Settings management
- Nonce-based authentication
- Permission checks

#### Admin Interface
- Vue 3 + Pinia reactive frontend
- Progressive loading with skeleton states
- Real-time job status updates
- Dashboard with statistics overview
- Podcasts list with search and filtering
- Add podcast modal with validation
- Social platform icons and badges
- Status indicators with color coding
- Progress bars for active jobs
- Responsive design

#### Cost Management
- Detailed cost tracking by:
  - Platform
  - Action type
  - Provider
  - Time period
- Budget management:
  - Weekly budget limits
  - Monthly budget limits
  - Budget health indicators (healthy/warning/critical/exceeded)
  - Automatic processing stops when budget exceeded
- Cost analytics:
  - Cost breakdown charts
  - Daily trend analysis
  - Cost forecasts
  - Top spending podcasts
  - Efficiency metrics
  - Savings calculations vs traditional approaches
- CSV export for cost data

#### Settings
- API key configuration (YouTube, Apify)
- Budget limit settings
- Auto-tracking preferences
- Refresh frequency control
- Email notification settings
- Settings validation
- Schema-based form generation

### Documentation
- Comprehensive README with quick start guide
- Detailed installation instructions
- Budget recommendations
- Cost analysis examples
- Architecture documentation
- API endpoint documentation
- FAQ section
- Troubleshooting guide
- Performance optimization tips
- Scaling recommendations

### Performance
- Efficient database queries with proper indexing
- 7-day metric caching
- Optimized social link discovery
- Async processing for metrics fetching
- Progressive UI updates
- Minimal API calls

### Security
- Nonce-based request validation
- Permission checks on all endpoints
- SQL injection prevention via prepared statements
- XSS protection via output escaping
- CSRF protection
- Capability checks

### Developer Features
- Clean, well-documented code
- PSR-4 compatible class structure
- Hooks and filters for extensibility
- REST API for external integrations
- Database abstraction layer
- Error handling and logging

## Cost Savings

Compared to traditional "scrape everything upfront" approach:

| Metric | Traditional | Just-in-Time | Savings |
|--------|------------|--------------|---------|
| 1,000 podcasts imported | $200 | $0 | $200 (100%) |
| 100 tracked | Already paid | $20 | $180 (90%) |
| Monthly refresh | $40 | $40 | $0 |
| **Total Monthly** | **$240** | **$60** | **$180 (75%)** |

## Platform Support

| Platform | API | Cost | Metrics |
|----------|-----|------|---------|
| YouTube | YouTube Data API v3 | FREE | Subscribers, views, engagement |
| Twitter | Apify | $0.05 | Followers, tweets, engagement |
| Instagram | Apify | $0.05 | Followers, posts, engagement |
| Facebook | Apify | $0.05 | Likes, posts, engagement |
| LinkedIn | Apify | $0.05 | Followers, posts |
| TikTok | Apify | $0.05 | Followers, videos, views |
| Spotify | Public scraping | FREE | Episodes (limited) |
| Apple Podcasts | Public scraping | FREE | Ratings (limited) |

## Known Limitations

- WordPress Cron requires site traffic (use real cron for production)
- Apify costs vary by platform complexity
- Some platforms (Spotify, Apple) have limited metrics
- YouTube API has daily quota (10,000 units)
- Metrics refresh weekly by default

## Roadmap

### v1.1.0 (Planned)
- Formidable Forms integration
- Email templates for outreach
- Bulk import from CSV
- Advanced filtering and sorting
- Chart visualizations
- Export to Google Sheets

### v1.2.0 (Planned)
- Podcast ranking algorithm
- Influence score calculation
- Trending podcasts detection
- Competitor analysis
- Custom refresh schedules per podcast

### v2.0.0 (Future)
- Real-time WebSocket updates
- Advanced analytics dashboard
- Machine learning for podcast recommendations
- Multi-user support with roles
- White-label options

## Credits

- Developed for Guestify Interview Tracker
- Built with WordPress, PHP, Vue 3, and Pinia
- Integrates with YouTube Data API and Apify platform
- Inspired by the need for cost-effective podcast influence tracking

---

For more information, see [README.md](README.md).
