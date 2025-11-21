# Podcast Influence Tracker

> A WordPress plugin that automatically discovers and tracks social media metrics for podcasts using a hybrid "just-in-time" strategy for cost-effective influence tracking.

## 🎯 Overview

The Podcast Influence Tracker integrates with the Guestify Interview Tracker to provide valuable social media intelligence for guest outreach prioritization. Instead of expensive upfront scraping or slow on-demand fetching, it uses a three-layer architecture that optimizes both user experience and costs.

## 🌟 Key Features

### Three-Layer Architecture

1. **Layer 1: Discovery (Immediate, Free)**
   - Parse RSS feeds instantly
   - Scrape homepage for social media links
   - Display links within 2-3 seconds
   - Cost: $0

2. **Layer 2: Enrichment (On-Demand)**
   - User clicks "Track" → Queue async job
   - Fetch metrics progressively in background
   - Real-time status updates
   - Cost: $0.05-0.20 per podcast

3. **Layer 3: Background Refresh (Automatic)**
   - Weekly cron job for tracked podcasts
   - Keeps data fresh automatically
   - Budget-controlled execution
   - Cost: Predictable and controlled

### Cost Optimization

- **80-90% cost reduction** vs traditional "scrape everything" approach
- Only pay for podcasts users actually want to track
- Built-in budget management (daily/weekly/monthly limits)
- Detailed cost analytics and forecasting

### Platform Support

**Free Platforms:**
- YouTube (via YouTube Data API v3)
- Spotify (public data scraping)
- Apple Podcasts (public data scraping)

**Premium Platforms (via Apify):**
- Twitter/X
- Instagram
- Facebook
- LinkedIn
- TikTok

### Progressive UI Experience

1. **Import** → Social links appear immediately
2. **Browse** → See platforms with icons instantly
3. **Track** → Metrics load progressively with status updates
4. **Return** → Cached metrics display instantly

## 📦 Installation

### Prerequisites

- WordPress 5.8 or higher
- PHP 7.4 or higher
- MySQL 5.7 or higher

### Step 1: Install the Plugin

1. Upload the `podcast-influence-tracker` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to **Podcast Influence** in the admin menu

### Step 2: Configure API Keys

#### YouTube API Key (Free)

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select existing
3. Enable **YouTube Data API v3**
4. Create credentials → API Key
5. Copy the API key to plugin settings

**Cost:** FREE (10,000 quota units/day = ~98 channels/day)

#### Apify API Token (Paid)

1. Sign up at [Apify.com](https://apify.com/)
2. Go to Settings → Integrations → API Tokens
3. Create a new token
4. Copy the token to plugin settings

**Cost:** $49/month for $49 credit (~$0.05-0.20 per profile)

### Step 3: Set Budget Limits

Configure your spending limits in Settings:

- **Weekly Budget:** Recommended $50
- **Monthly Budget:** Recommended $200

The plugin will automatically stop processing when budgets are reached.

## 🚀 Usage

### Adding a Podcast

1. Go to **Podcast Influence → Podcasts**
2. Click **Add Podcast**
3. Enter the RSS feed URL
4. Click **Add**

**Result:** Within 2-3 seconds, you'll see:
- Podcast name and details
- Discovered social media links with platform icons
- No API costs incurred yet!

### Tracking Metrics

1. Click **Track** next to any podcast
2. Watch the progressive status updates:
   - Queued → Processing → Tracked
   - Progress percentage indicator
   - Platform-by-platform updates
3. Metrics appear as they're fetched (5-20 seconds total)

**Cost:** $0.05-0.20 per podcast (only charged when you click Track)

### Viewing Analytics

Go to **Podcast Influence → Analytics** to see:

- Total costs by period (day/week/month/year)
- Cost breakdown by platform
- Cost breakdown by action type
- Budget status and forecasts
- Top spending podcasts
- Cost efficiency metrics
- Savings vs traditional approaches

### Managing Budgets

The plugin automatically:
- Tracks all API costs in real-time
- Shows budget status with health indicators
- Stops processing when budgets are reached
- Provides cost forecasts
- Exports cost data to CSV

## 🏗️ Architecture

### Database Tables

1. **pit_podcasts** - Core podcast data
2. **pit_social_links** - Discovered social media links
3. **pit_metrics** - Collected metrics with timestamps
4. **pit_jobs** - Job queue for async processing
5. **pit_cost_log** - Detailed cost tracking

### API Integrations

**YouTube Data API v3**
- Quota: 10,000 units/day (free)
- Cost per channel: ~102 units
- Max channels/day: ~98
- Provides: Subscribers, views, engagement

**Apify Platform**
- Pricing: Pay-as-you-go ($49/month minimum)
- Cost per profile: $0.05-0.20
- Platforms: Twitter, Instagram, Facebook, LinkedIn, TikTok
- Provides: Followers, engagement rate, post metrics

### Job Queue System

Uses WordPress Cron for async processing:
- Jobs queued when user clicks "Track"
- Processes one job per minute
- Automatic retries (max 3 attempts)
- Real-time status updates via polling
- Priority-based execution

### Caching Strategy

- Metrics cached for 7 days
- Instant display from cache
- Background refresh for tracked podcasts
- Manual refresh option available

## 📊 Cost Analysis

### Example Scenario

**1,000 podcasts imported:**
- Layer 1 Discovery: $0 (social links discovered)
- Users track 100 podcasts: 100 × $0.20 = $20
- Weekly refresh (50 podcasts): 4 × (50 × $0.20) = $40/month
- **Total monthly cost: $60**

**Traditional approach:**
- Scrape all 1,000 upfront: 1,000 × $0.20 = $200
- Weekly refresh: Same $40/month
- **Total monthly cost: $240**

**Savings: $180/month (75% reduction)**

### Budget Recommendations

| Usage Level | Weekly Budget | Monthly Budget | Podcasts Tracked |
|-------------|---------------|----------------|------------------|
| Light | $10 | $40 | ~50 |
| Medium | $25 | $100 | ~200 |
| Heavy | $50 | $200 | ~500 |
| Enterprise | $100+ | $400+ | 1,000+ |

## 🔧 Development

### File Structure

```
podcast-influence-tracker/
├── podcast-influence-tracker.php   # Main plugin file
├── includes/
│   ├── class-database.php         # Database schema & queries
│   ├── class-cost-tracker.php     # Cost tracking & analytics
│   ├── layer-1/                   # Discovery Engine
│   │   ├── class-rss-parser.php
│   │   ├── class-homepage-scraper.php
│   │   └── class-discovery-engine.php
│   ├── layer-2/                   # Job Queue System
│   │   ├── class-job-queue.php
│   │   └── class-metrics-fetcher.php
│   ├── layer-3/                   # Background Refresh
│   │   └── class-background-refresh.php
│   ├── integrations/              # API Integrations
│   │   ├── class-youtube-api.php
│   │   └── class-apify-client.php
│   ├── api/                       # REST API
│   │   └── class-rest-controller.php
│   └── admin/                     # Admin Interface
│       ├── class-admin-page.php
│       └── class-settings.php
├── assets/
│   ├── js/
│   │   └── admin-app.js          # Vue 3 frontend
│   └── css/
│       └── admin-styles.css      # Admin styles
├── README.md                      # This file
└── PODCAST-INFLUENCE-TRACKING.md # Implementation plan
```

### REST API Endpoints

```
GET    /podcast-influence/v1/podcasts
POST   /podcast-influence/v1/podcasts
GET    /podcast-influence/v1/podcasts/{id}
DELETE /podcast-influence/v1/podcasts/{id}
POST   /podcast-influence/v1/podcasts/{id}/track
POST   /podcast-influence/v1/podcasts/{id}/untrack
POST   /podcast-influence/v1/podcasts/{id}/refresh
GET    /podcast-influence/v1/podcasts/{id}/social-links
POST   /podcast-influence/v1/podcasts/{id}/social-links
GET    /podcast-influence/v1/podcasts/{id}/metrics
GET    /podcast-influence/v1/jobs/{id}
POST   /podcast-influence/v1/jobs/{id}/cancel
GET    /podcast-influence/v1/stats/overview
GET    /podcast-influence/v1/stats/costs
GET    /podcast-influence/v1/settings
POST   /podcast-influence/v1/settings
```

## 🤝 Integration with Guestify

This plugin is designed to integrate seamlessly with the Guestify Interview Tracker:

1. **Import podcasts** from your guest research
2. **Discover social links** automatically
3. **Track metrics** for prioritization
4. **Export data** for email outreach
5. **Maintain relationships** with one-to-one approach

The metrics help you prioritize which podcasts to reach out to while maintaining the personal touch that makes Guestify effective.

## ❓ FAQ

### Q: Why not scrape all metrics immediately?

**A:** Wasteful! Most podcasts won't be relevant for outreach. By discovering links first (free), users can browse and decide which podcasts are worth tracking (paid).

### Q: Why not fetch on-demand when viewing?

**A:** Slow UX! Waiting 20+ seconds to see metrics for each podcast is frustrating. Our hybrid approach shows links instantly, then fetches metrics in the background.

### Q: How accurate are the metrics?

**A:** Very accurate! YouTube uses official API, premium platforms use Apify's maintained scrapers. Data is as fresh as the last fetch (max 7 days old for tracked podcasts).

### Q: What happens if I exceed my budget?

**A:** The plugin automatically stops processing new jobs when your budget limit is reached. You'll see a notice in the dashboard and can increase limits or wait for the next period.

### Q: Can I track podcasts manually without API costs?

**A:** Yes! Layer 1 always discovers social links for free. You can browse these links manually without ever clicking "Track" if you want to avoid costs entirely.

## 📝 License

GPL v2 or later

## 👥 Credits

- Developed for Guestify Interview Tracker
- Built with WordPress, PHP, Vue 3, and Pinia
- Integrates with YouTube Data API and Apify platform

## 🔗 Links

- [Original Implementation Plan](PODCAST-INFLUENCE-TRACKING.md)
- [GitHub Repository](https://github.com/meettonyg/showauthority)

---

**Need help?** Open an issue on GitHub or contact the Guestify team.
