# Guestify Interview Tracker

A WordPress plugin for comprehensive interview and podcast guest management with intelligent tracking, social metrics, and network analysis.

## Overview

The Guestify Interview Tracker provides comprehensive podcast and guest management with intelligent deduplication, network analysis, and social media metrics tracking. It uses a three-layer architecture that optimizes both user experience and API costs.

## Key Features

### Podcast Management
- RSS feed parsing and automatic data extraction
- Social media link discovery from RSS feeds and homepages
- iTunes/Apple Podcasts integration for podcast lookup
- Content analysis and episode tracking

### Guest Intelligence
- Guest directory with deduplication (LinkedIn URL, Email)
- Podcast appearance tracking
- Topic taxonomy and expertise tagging
- Network connections (co-appearances on podcasts)
- Verification workflow for data quality

### Social Metrics Tracking
- YouTube metrics via YouTube Data API (free)
- Twitter, Instagram, LinkedIn, TikTok via Apify
- Spotify and Apple Podcasts public data
- Metrics caching with configurable expiry

### Export System
- CSV and JSON export for guests
- CSV and JSON export for podcasts
- Network connections export

## Installation

### Requirements
- WordPress 5.8+
- PHP 7.4+
- MySQL 5.7+

### Setup
1. Upload to `/wp-content/plugins/podcast-influence-tracker`
2. Activate through WordPress admin
3. Configure API keys in Settings

### API Keys

**YouTube Data API (Free)**
1. Create project in Google Cloud Console
2. Enable YouTube Data API v3
3. Create API key and add to settings

**Apify (Paid - Optional)**
1. Sign up at apify.com
2. Get API token from Settings > Integrations
3. Add to plugin settings

## Architecture

### File Structure

```
podcast-influence-tracker/
├── podcast-influence-tracker.php   # Main plugin entry point
├── includes/
│   ├── Core/                       # Core infrastructure
│   │   └── class-database-schema.php
│   ├── Podcasts/                   # Podcast domain
│   │   ├── class-podcast-repository.php
│   │   └── class-content-analysis-repository.php
│   ├── Guests/                     # Guest domain
│   │   ├── class-guest-repository.php
│   │   ├── class-appearance-repository.php
│   │   ├── class-topic-repository.php
│   │   └── class-network-repository.php
│   ├── SocialMetrics/              # Social metrics domain
│   │   ├── class-social-link-repository.php
│   │   └── class-metrics-repository.php
│   ├── Jobs/                       # Background job domain
│   │   └── class-job-repository.php
│   ├── API/                        # REST API controllers
│   │   ├── class-rest-base.php
│   │   ├── class-rest-podcasts.php
│   │   ├── class-rest-guests.php
│   │   └── class-rest-export.php
│   ├── integrations/               # External API clients
│   │   ├── class-youtube-api.php
│   │   ├── class-apify-client.php
│   │   └── class-itunes-resolver.php
│   ├── admin/                      # WordPress admin UI
│   │   ├── class-admin-page.php
│   │   ├── class-settings.php
│   │   └── class-admin-bulk-tools.php
│   └── layer-*/                    # Legacy layer classes
└── assets/
    ├── js/admin-app.js             # Vue 3 admin interface
    └── css/admin-styles.css        # Admin styles
```

### Database Schema

**Podcast Tables:**
- `pit_podcasts` - Core podcast data
- `pit_podcast_contacts` - Podcast hosts/producers
- `pit_content_analysis` - Episode analysis

**Guest Tables:**
- `pit_guests` - Guest directory
- `pit_guest_appearances` - Podcast appearances
- `pit_topics` - Topic taxonomy
- `pit_guest_topics` - Guest-topic relationships
- `pit_guest_network` - Network connections

**Social Tables:**
- `pit_social_links` - Social media profiles
- `pit_metrics` - Metrics history

**Job Tables:**
- `pit_jobs` - Background job queue
- `pit_cost_log` - API cost tracking

### REST API Endpoints

**Podcasts:**
```
GET    /podcast-influence/v1/podcasts
POST   /podcast-influence/v1/podcasts
GET    /podcast-influence/v1/podcasts/{id}
PUT    /podcast-influence/v1/podcasts/{id}
DELETE /podcast-influence/v1/podcasts/{id}
POST   /podcast-influence/v1/podcasts/{id}/track
POST   /podcast-influence/v1/podcasts/{id}/refresh
GET    /podcast-influence/v1/podcasts/{id}/social-links
GET    /podcast-influence/v1/podcasts/{id}/metrics
GET    /podcast-influence/v1/podcasts/{id}/guests
GET    /podcast-influence/v1/podcasts/{id}/content-analysis
```

**Guests:**
```
GET    /podcast-influence/v1/guests
POST   /podcast-influence/v1/guests
GET    /podcast-influence/v1/guests/{id}
PUT    /podcast-influence/v1/guests/{id}
DELETE /podcast-influence/v1/guests/{id}
GET    /podcast-influence/v1/guests/{id}/appearances
GET    /podcast-influence/v1/guests/{id}/network
POST   /podcast-influence/v1/guests/{id}/verify
GET    /podcast-influence/v1/guests/duplicates
POST   /podcast-influence/v1/guests/merge
```

**Export:**
```
GET    /podcast-influence/v1/export/guests
GET    /podcast-influence/v1/export/podcasts
GET    /podcast-influence/v1/export/network
```

## Cost Management

The plugin tracks all API costs and provides budget controls:

- Set weekly/monthly budget limits
- Automatic processing stops when limits reached
- Cost breakdown by platform and action type
- Cost forecasting and efficiency metrics

### Cost Estimates

| Platform | Cost per Profile |
|----------|------------------|
| YouTube | Free (API quota) |
| Twitter | ~$0.05 |
| Instagram | ~$0.05 |
| LinkedIn | ~$0.05 |
| TikTok | ~$0.05 |
| Spotify | Free (scraping) |
| Apple Podcasts | Free (scraping) |

## Development

### Version 2.0.0 Changes

- **Domain-based architecture** - Code organized by business domain instead of implementation layers
- **Repository pattern** - Clean separation of data access from business logic
- **Split REST controllers** - Smaller, focused API controllers per domain
- **Unified database schema** - Consolidated tables with better indexing
- **Backwards compatibility** - Legacy classes still work via aliases

### Contributing

1. Fork the repository
2. Create feature branch
3. Make changes
4. Submit pull request

## License

GPL v2 or later

## Links

- [GitHub Repository](https://github.com/meettonyg/showauthority)
