# Shortcodes and Integrations Reference

Comprehensive documentation for all WordPress shortcodes and third-party integrations available in the Podcast Influence Tracker plugin.

## Table of Contents

- [Shortcodes](#shortcodes)
  - [Podcast Shortcodes](#podcast-shortcodes)
  - [Guest Shortcodes](#guest-shortcodes)
  - [Social Platform Shortcodes](#social-platform-shortcodes)
  - [Contact Shortcodes](#contact-shortcodes)
- [Integrations](#integrations)
  - [Calendar Integrations](#calendar-integrations)
  - [Social Media APIs](#social-media-apis)
  - [Form Integrations](#form-integrations)
  - [Enrichment Providers](#enrichment-providers)

---

## Shortcodes

### Podcast Shortcodes

#### `[podcast_card]`
Displays a single podcast card with artwork, title, author, and description.

**Attributes:**
| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `id` | int | 0 | Podcast ID from the database |
| `rss` | string | '' | RSS feed URL to identify the podcast |

**Examples:**
```html
[podcast_card id="123"]
[podcast_card rss="https://feeds.example.com/podcast"]
```

**Output:** A styled card with:
- Podcast artwork
- Title
- Author
- Description (truncated to 30 words)
- Category badge
- Website link

---

#### `[podcast_list]`
Displays a list of podcast cards.

**Attributes:**
| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `limit` | int | 10 | Maximum podcasts to display (max: 50) |
| `category` | string | '' | Filter by category |
| `search` | string | '' | Search term for filtering |

**Examples:**
```html
[podcast_list limit="5"]
[podcast_list category="Technology" limit="10"]
[podcast_list search="marketing"]
```

---

#### `[podcast_metrics]`
Displays social media metrics for a specific podcast.

**Attributes:**
| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `id` | int | required | Podcast ID |

**Example:**
```html
[podcast_metrics id="123"]
```

**Output:** A grid showing:
- Platform name (YouTube, Twitter, etc.)
- Followers count
- Subscribers count
- Views count

---

### Guest Shortcodes

#### `[guest_card]`
Displays a single guest profile card.

**Attributes:**
| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `id` | int | required | Guest ID from the database |

**Example:**
```html
[guest_card id="456"]
```

**Output:** A styled card with:
- Guest photo or initials
- Full name with verified badge (if applicable)
- Title and company
- Bio (truncated to 30 words)
- LinkedIn and Twitter links

---

#### `[guest_list]`
Displays a list of guest cards.

**Attributes:**
| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `limit` | int | 10 | Maximum guests to display (max: 50) |
| `verified` | bool | '' | Filter by verification status (`1` or `0`) |
| `search` | string | '' | Search term for filtering |

**Examples:**
```html
[guest_list limit="20"]
[guest_list verified="1" limit="10"]
[guest_list search="CEO"]
```

---

### Social Platform Shortcodes

These shortcodes display links to specific social media platforms for a podcast.

#### Common Attributes (all platform shortcodes)

| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `podcast_id` | int | 0 | Podcast ID |
| `rss` | string | '' | RSS feed URL to identify podcast |
| `layout` | string | 'button' | Display style (see below) |
| `class` | string | '' | Additional CSS classes |
| `target` | string | '_blank' | Link target |
| `show_handle` | string | 'yes' | Show @handle if available |
| `show_count` | string | 'yes' | Show follower/subscriber count |
| `fallback` | string | '' | Text to show if link not found |

**Layout Options:**
| Layout | Description |
|--------|-------------|
| `button` | Styled button with platform color |
| `link` | Simple text link |
| `icon` | Icon only (when available) |
| `url_only` | Returns just the URL (no HTML) |
| `metrics` | Link with subscriber/follower count |
| `count_only` | Just the count (e.g., "58K") |

---

#### `[podcast_youtube]`
YouTube channel link with subscriber count.

```html
[podcast_youtube]
[podcast_youtube layout="metrics"]
[podcast_youtube podcast_id="123" layout="button"]
```

---

#### `[podcast_twitter]`
Twitter/X profile link.

```html
[podcast_twitter]
[podcast_twitter layout="link" show_handle="yes"]
```

---

#### `[podcast_linkedin]`
LinkedIn page/profile link.

```html
[podcast_linkedin]
[podcast_linkedin layout="button"]
```

---

#### `[podcast_facebook]`
Facebook page link.

```html
[podcast_facebook]
[podcast_facebook layout="icon"]
```

---

#### `[podcast_instagram]`
Instagram profile link.

```html
[podcast_instagram]
[podcast_instagram layout="metrics"]
```

---

#### `[podcast_tiktok]`
TikTok profile link.

```html
[podcast_tiktok]
[podcast_tiktok layout="button"]
```

---

#### `[podcast_spotify]`
Spotify podcast link.

```html
[podcast_spotify]
[podcast_spotify layout="button"]
```

---

#### `[podcast_apple]`
Apple Podcasts link.

```html
[podcast_apple]
[podcast_apple layout="button"]
```

---

#### `[podcast_social_links]`
Displays all social links for a podcast in a single shortcode.

**Additional Attributes:**
| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `platforms` | string | '' | Comma-separated list (empty = all) |
| `separator` | string | ' ' | HTML separator between links |

**Examples:**
```html
[podcast_social_links]
[podcast_social_links layout="icons"]
[podcast_social_links layout="buttons" platforms="youtube,twitter,linkedin"]
[podcast_social_links layout="list"]
```

---

### Contact Shortcodes

#### `[podcast_contacts]`
Displays contacts associated with a podcast. Integrates with Formidable Forms RSS field for automatic podcast detection.

**Attributes:**
| Attribute | Type | Default | Description |
|-----------|------|---------|-------------|
| `rss` | string | '' | RSS feed URL |
| `podcast_id` | int | 0 | Direct podcast ID |
| `layout` | string | 'cards' | Display style: `cards`, `list`, `inline` |
| `roles` | string | '' | Filter by roles (comma-separated) |
| `limit` | int | 10 | Max contacts to show |

**Examples:**
```html
[podcast_contacts]
[podcast_contacts rss="https://feeds.example.com/podcast"]
[podcast_contacts podcast_id="123" layout="list"]
[podcast_contacts roles="host,producer" limit="5"]
```

**Auto-Detection:**
When used inside a Formidable Forms view, the shortcode automatically detects the podcast from the entry's RSS field.

---

## Integrations

### Calendar Integrations

The plugin supports two-way calendar synchronization with Google Calendar and Microsoft Outlook.

#### Google Calendar Integration

**Setup:**
1. Create a project in [Google Cloud Console](https://console.cloud.google.com/)
2. Enable Google Calendar API
3. Configure OAuth 2.0 credentials
4. Add Client ID and Client Secret in plugin settings

**Features:**
- OAuth 2.0 authentication flow
- List user's calendars
- Create, update, delete events
- Two-way sync with configurable direction
- Delta sync for efficient updates

**API Endpoints:**
| Endpoint | Method | Description |
|----------|--------|-------------|
| `/calendar-sync/google/auth` | GET | Get OAuth URL |
| `/calendar-sync/google/callback` | GET | OAuth callback |
| `/calendar-sync/google/calendars` | GET | List calendars |
| `/calendar-sync/google/select-calendar` | POST | Select calendar |
| `/calendar-sync/google/disconnect` | POST | Disconnect |
| `/calendar-sync/google/sync` | POST | Trigger sync |

---

#### Microsoft Outlook Calendar Integration

**Setup:**
1. Register an application in [Azure Portal](https://portal.azure.com/)
2. Add Microsoft Graph API permissions: `Calendars.ReadWrite`, `User.Read`
3. Configure redirect URI: `{site_url}/wp-json/pit/v1/calendar-sync/outlook/callback`
4. Add Client ID and Client Secret in plugin settings

**Features:**
- OAuth 2.0 with Microsoft identity platform
- Access to all user calendars
- Event CRUD operations
- Extended properties for tracking
- Color mapping from Outlook categories

**API Endpoints:**
| Endpoint | Method | Description |
|----------|--------|-------------|
| `/calendar-sync/outlook/auth` | GET | Get OAuth URL |
| `/calendar-sync/outlook/callback` | GET | OAuth callback |
| `/calendar-sync/outlook/calendars` | GET | List calendars |
| `/calendar-sync/outlook/select-calendar` | POST | Select calendar |
| `/calendar-sync/outlook/disconnect` | POST | Disconnect |
| `/calendar-sync/outlook/sync` | POST | Trigger sync |

**Event Types Synced:**
- Podcast recordings
- Guest interviews
- Follow-up tasks
- Publication dates

---

### Social Media APIs

#### YouTube Data API

**Setup:**
1. Enable YouTube Data API v3 in Google Cloud Console
2. Create an API key
3. Add to plugin settings

**Features:**
- Channel statistics (subscribers, views, video count)
- Video metrics
- Channel search
- Rate limit handling

**Cost:** Free (10,000 quota units/day)

---

#### Apify Integration

**Setup:**
1. Sign up at [apify.com](https://apify.com)
2. Get API token from Settings > Integrations
3. Add to plugin settings

**Supported Platforms:**
- Twitter/X profile scraping
- Instagram profile metrics
- LinkedIn company/profile data
- TikTok profile statistics

**Features:**
- Actor-based scraping
- Automatic retries
- Result caching

---

#### iTunes/Apple Podcasts Resolver

**Features:**
- Podcast search by name
- RSS feed URL resolution
- Artwork and metadata extraction
- No API key required

---

### Form Integrations

#### Formidable Forms Integration

Enables automatic podcast linking from Formidable Forms entries.

**Setup:**
1. Configure RSS field ID in plugin settings
2. Use `[podcast_contacts]` shortcode in views

**Features:**
- Auto-detect podcast from entry RSS field
- Link entries to tracked podcasts
- Support for entry_id URL parameters
- Global `$entry` object support

**Linked Tables:**
- `pit_formidable_podcast_links` - Links Formidable entries to podcasts

---

### Enrichment Providers

The plugin uses a provider-based architecture for data enrichment.

#### Enrichment Manager

Coordinates enrichment across multiple providers with fallback support.

**Features:**
- Priority-based provider selection
- Automatic fallback on failure
- Rate limiting
- Result caching

---

#### Apify Provider

Uses Apify actors for social media enrichment.

**Capabilities:**
- Profile URL extraction
- Follower/subscriber counts
- Engagement metrics

---

#### ScrapingDog Provider

Alternative enrichment using ScrapingDog API.

**Setup:**
1. Sign up at [scrapingdog.com](https://www.scrapingdog.com/)
2. Add API key to settings

**Features:**
- LinkedIn profile scraping
- Company data extraction
- Contact information

---

## CSS Classes Reference

All shortcodes use consistent CSS classes for styling:

### Podcast Classes
- `.pit-podcast-card` - Card container
- `.pit-podcast-artwork` - Artwork wrapper
- `.pit-podcast-content` - Content area
- `.pit-podcast-title` - Title text
- `.pit-podcast-author` - Author text
- `.pit-podcast-description` - Description text
- `.pit-podcast-category` - Category badge
- `.pit-podcast-link` - Website link

### Guest Classes
- `.pit-guest-card` - Card container
- `.pit-guest-photo` - Photo wrapper
- `.pit-guest-content` - Content area
- `.pit-guest-name` - Name text
- `.pit-guest-title` - Title text
- `.pit-guest-bio` - Bio text
- `.pit-guest-social` - Social links area
- `.pit-verified-badge` - Verified indicator

### Social Link Classes
- `.pit-social-button` - Button style
- `.pit-social-link` - Link style
- `.pit-social-icon` - Icon style
- `.pit-social-{platform}` - Platform-specific (e.g., `.pit-social-youtube`)
- `.pit-social-metrics` - Metrics display
- `.pit-social-count` - Count display

### Contact Classes
- `.pit-contacts-grid` - Cards grid
- `.pit-contact-card` - Individual card
- `.pit-contact-header` - Header area
- `.pit-contact-avatar` - Avatar/initials
- `.pit-contact-info` - Info area
- `.pit-contact-name` - Name text
- `.pit-contact-role` - Role badge
- `.pit-contact-details` - Details section
- `.pit-contacts-list` - List layout
- `.pit-contacts-inline` - Inline layout

### Utility Classes
- `.pit-error` - Error message
- `.pit-no-results` - Empty state
- `.pit-no-contacts` - No contacts state
- `.pit-metrics-grid` - Metrics grid
- `.pit-metric-card` - Metric card
- `.pit-metric-stat` - Individual stat

---

## Changelog

### Version 4.3.0
- Added Microsoft Outlook Calendar integration
- Added generic provider methods for calendar sync
- Improved timezone handling (uses WordPress timezone)
- Added custom confirmation modals

### Version 4.2.0
- Added Guest Intelligence frontend views
- Added podcast metrics display
- Created shared formatters utility

### Version 4.1.0
- Added platform-specific social shortcodes
- Added `podcast_social_links` combined shortcode
- Added metrics display layouts

### Version 4.0.0
- Added Google Calendar integration
- Added contact shortcodes
- Added Formidable Forms integration
