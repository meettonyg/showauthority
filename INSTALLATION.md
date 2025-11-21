# Installation & Setup Guide

Complete guide to install and configure the Podcast Influence Tracker plugin.

## Quick Start (5 minutes)

### 1. Install Plugin

```bash
# Upload to WordPress plugins directory
cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/meettonyg/showauthority.git podcast-influence-tracker
```

Or manually:
1. Download the plugin ZIP
2. Upload via WordPress Admin → Plugins → Add New → Upload
3. Activate the plugin

### 2. Activate Plugin

In WordPress admin:
- Navigate to **Plugins**
- Find **Podcast Influence Tracker**
- Click **Activate**

The plugin will automatically:
- Create 5 database tables
- Schedule weekly background refresh cron
- Register REST API endpoints

### 3. Configure API Keys (Optional but Recommended)

#### Get YouTube API Key (FREE)

1. Visit [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project (e.g., "Podcast Tracker")
3. Enable APIs & Services → YouTube Data API v3
4. Credentials → Create Credentials → API Key
5. Copy the key

**In WordPress:**
- Go to **Podcast Influence → Settings**
- Paste key in "YouTube API Key" field
- Save Settings

**Quota:** 10,000 units/day = ~98 podcasts/day (FREE)

#### Get Apify API Token (PAID - $49/month)

1. Sign up at [apify.com](https://apify.com/)
2. Account → Integrations → API Tokens
3. Create new token
4. Copy the token

**In WordPress:**
- Go to **Podcast Influence → Settings**
- Paste token in "Apify API Token" field
- Save Settings

**Pricing:** $0.05-0.20 per profile scraped

### 4. Set Budget Limits

In **Settings**:

```
Weekly Budget: $50.00
Monthly Budget: $200.00
```

These limits prevent overspending. The plugin stops processing when limits are reached.

### 5. Add Your First Podcast

1. Go to **Podcast Influence → Podcasts**
2. Click **Add Podcast**
3. Enter RSS feed URL (e.g., `https://example.com/feed.xml`)
4. Click **Add**

**Result:** Within 3 seconds, you'll see:
- Podcast name, author, description
- Social media links discovered (Twitter, YouTube, etc.)
- Platform icons with clickable links
- **Cost: $0** (discovery is free!)

### 6. Track Metrics (Optional)

1. Click **Track** button next to podcast
2. Watch progressive status updates
3. Metrics appear in 5-20 seconds

**Cost:** $0.05-0.20 (only when you click Track)

## Advanced Configuration

### Cron Jobs

The plugin uses WordPress Cron for:

1. **Job Processing** - Every minute
   - Processes queued tracking jobs
   - Fetches metrics from APIs
   - Updates podcast status

2. **Background Refresh** - Weekly
   - Refreshes tracked podcasts
   - Respects budget limits
   - Keeps data fresh

**Manual Cron Trigger:**

```bash
wp cron event run pit_process_jobs
wp cron event run pit_background_refresh
```

### Database Tables

After activation, these tables are created:

```sql
wp_pit_podcasts          # Core podcast data
wp_pit_social_links      # Discovered social links
wp_pit_metrics           # Collected metrics
wp_pit_jobs              # Job queue
wp_pit_cost_log          # Cost tracking
```

**View table info:**

```sql
DESCRIBE wp_pit_podcasts;
SELECT COUNT(*) FROM wp_pit_podcasts;
```

### REST API Testing

Test endpoints with curl:

```bash
# Get podcasts list
curl -H "X-WP-Nonce: YOUR_NONCE" \
  http://yoursite.com/wp-json/podcast-influence/v1/podcasts

# Add podcast
curl -X POST \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -d '{"rss_url":"https://example.com/feed.xml"}' \
  http://yoursite.com/wp-json/podcast-influence/v1/podcasts

# Track podcast
curl -X POST \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -d '{"platforms":["youtube","twitter"]}' \
  http://yoursite.com/wp-json/podcast-influence/v1/podcasts/1/track
```

## Budget Management

### Setting Budgets

**Conservative (Light Use):**
```
Weekly: $10
Monthly: $40
```
*~50 podcasts tracked + weekly refreshes*

**Moderate (Medium Use):**
```
Weekly: $25
Monthly: $100
```
*~200 podcasts tracked + weekly refreshes*

**Aggressive (Heavy Use):**
```
Weekly: $50
Monthly: $200
```
*~500 podcasts tracked + weekly refreshes*

### Budget Alerts

The plugin shows budget status:
- 🟢 **Healthy** (0-75% used)
- 🟡 **Warning** (75-90% used)
- 🔴 **Critical** (90-100% used)
- ⛔ **Exceeded** (100%+ used)

When exceeded, processing stops automatically.

### Cost Tracking

View costs in **Analytics**:
- Today's costs
- This week's costs
- This month's costs
- Cost by platform
- Cost by action type
- Cost forecasts

**Export costs:**
```php
$csv = PIT_Cost_Tracker::export_csv('month');
file_put_contents('costs.csv', $csv);
```

## Performance Optimization

### Server Requirements

**Minimum:**
- PHP 7.4+
- Memory: 128MB
- MySQL 5.7+

**Recommended:**
- PHP 8.0+
- Memory: 256MB
- MySQL 8.0+
- WP Cron enabled

### Caching

The plugin uses built-in caching:
- Metrics cached for 7 days
- Social links cached permanently
- Job status cached during processing

**Clear cache:**

```php
// Clear all metrics for a podcast
PIT_Metrics_Fetcher::invalidate_cache($podcast_id);

// Clear specific platform
PIT_Metrics_Fetcher::invalidate_cache($podcast_id, 'youtube');
```

### Scaling

For high-volume installations:

1. **Use real cron instead of WP Cron:**

```bash
# Disable WP Cron in wp-config.php
define('DISABLE_WP_CRON', true);

# Add to server cron
* * * * * cd /path/to/wordpress && wp cron event run --due-now
```

2. **Increase processing frequency:**

```php
// Process jobs every 30 seconds instead of 60
add_filter('cron_schedules', function($schedules) {
    $schedules['every_30_seconds'] = [
        'interval' => 30,
        'display' => 'Every 30 Seconds'
    ];
    return $schedules;
});
```

3. **Database optimization:**

```sql
-- Add indexes for better performance
ALTER TABLE wp_pit_metrics ADD INDEX idx_podcast_platform (podcast_id, platform);
ALTER TABLE wp_pit_jobs ADD INDEX idx_status_priority (status, priority);
ALTER TABLE wp_pit_cost_log ADD INDEX idx_date (logged_at);
```

## Troubleshooting

### Issue: Jobs not processing

**Check:**
```bash
# Verify cron is running
wp cron event list

# Check for errors
wp cron event run pit_process_jobs
```

**Solution:**
- Ensure WP Cron is enabled
- Check for PHP errors in error log
- Verify API keys are valid

### Issue: API errors

**YouTube:**
- Verify API key is valid
- Check quota usage in Google Cloud Console
- Ensure YouTube Data API v3 is enabled

**Apify:**
- Verify token is valid
- Check account balance
- Review actor run logs in Apify dashboard

### Issue: High costs

**Review:**
1. Check **Analytics → Top Spenders**
2. Identify podcasts using most credits
3. Untrack low-priority podcasts
4. Adjust refresh frequency

**Reduce costs:**
```php
// Disable auto-tracking on import
update_option('pit_settings', [
    'auto_track_on_import' => false
]);

// Change refresh frequency
update_option('pit_settings', [
    'refresh_frequency' => 'monthly' // instead of weekly
]);
```

### Issue: Slow performance

**Optimize:**
1. Reduce podcasts per page: Settings → Per Page → 10
2. Enable object caching (Redis/Memcached)
3. Use real cron instead of WP Cron
4. Increase PHP memory limit

## Uninstallation

### Clean Uninstall

The plugin does NOT automatically delete data on deactivation (for safety).

**To completely remove:**

```sql
-- Delete tables
DROP TABLE wp_pit_podcasts;
DROP TABLE wp_pit_social_links;
DROP TABLE wp_pit_metrics;
DROP TABLE wp_pit_jobs;
DROP TABLE wp_pit_cost_log;

-- Delete options
DELETE FROM wp_options WHERE option_name LIKE 'pit_%';

-- Clear cron
wp cron event delete pit_process_jobs
wp cron event delete pit_background_refresh
```

Or use a plugin like "WP Reset" for clean slate.

## Support

- **Documentation:** [README.md](README.md)
- **Issues:** [GitHub Issues](https://github.com/meettonyg/showauthority/issues)
- **Email:** support@guestify.com

## Next Steps

After installation:

1. ✅ Configure API keys
2. ✅ Set budget limits
3. ✅ Add your first podcast
4. ✅ Track metrics for high-priority shows
5. ✅ Review analytics weekly
6. ✅ Integrate with Guestify workflow

Happy tracking! 🎙️
