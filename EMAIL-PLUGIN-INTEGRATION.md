# Email Plugin Integration Guide

## Overview

The Podcast Intelligence Database provides **contact data only**. It does NOT send emails. This separation allows you to use your preferred email solution while still benefiting from the centralized contact database.

## What This Plugin Provides

### 1. Contact Data Retrieval

**Priority-based lookup system:**
1. Formidable field (direct entry) - 100% confidence
2. Podcast contacts table - 90% confidence
3. Secondary contacts - 80% confidence
4. RSS feed data - varies
5. Clay enrichment - high quality
6. Manual entry - user-verified

### 2. Display Options

**Shortcode for Formidable Views:**
```php
[guestify_contact entry_id="[id]"]
// Legacy shortcode also supported:
[guestify_email entry_id="[id]"]
```

**Displays:**
- Email address with "Copy" button
- Contact name
- Podcast name
- Data source and confidence score
- Additional info (LinkedIn, Twitter, phone)
- Manual contact entry form (if no email found)

### 3. Programmatic Access

**REST API:**
```javascript
// Get complete contact data
GET /wp-json/podcast-influence/v1/entries/{entry_id}/contact-email

Response:
{
  "email": "mark@example.com",
  "name": "Mark de Grasse",
  "podcast_name": "Podcast Mark",
  "source": "podcast_database",
  "confidence": 90,
  "additional_info": {
    "role": "host",
    "linkedin": "https://linkedin.com/in/markdegrasse",
    "twitter": "https://twitter.com/markdegrasse",
    "clay_enriched": true
  }
}
```

**Direct PHP:**
```php
$integration = PIT_Email_Integration::get_instance();
$contact = $integration->get_contact_from_all_sources($entry_id);

// Returns same structure as REST API
echo $contact['email']; // mark@example.com
echo $contact['name']; // Mark de Grasse
echo $contact['confidence']; // 90
```

## Integration Methods

### Method 1: Use Shortcode in Email Template

If your email plugin supports Formidable shortcodes, you can display contact data directly:

```html
<div class="podcast-contact">
  [guestify_contact entry_id="[id]"]
</div>
```

This displays the contact info with a "Copy" button for easy email copying.

### Method 2: REST API Integration (JavaScript)

For custom email interfaces:

```javascript
// In your email plugin's JavaScript
async function getContactEmail(entryId) {
  const response = await fetch(
    `/wp-json/podcast-influence/v1/entries/${entryId}/contact-email`,
    {
      headers: {
        'X-WP-Nonce': wpApiSettings.nonce
      }
    }
  );

  const data = await response.json();

  if (data.email) {
    // Populate your email form
    document.getElementById('to-email').value = data.email;
    document.getElementById('to-name').value = data.name;

    // Show confidence indicator
    if (data.confidence >= 90) {
      showConfidenceBadge('High confidence', 'green');
    } else if (data.confidence >= 70) {
      showConfidenceBadge('Medium confidence', 'yellow');
    } else {
      showConfidenceBadge('Low confidence - verify email', 'red');
    }
  }

  return data;
}
```

### Method 3: Direct PHP Integration

For WordPress email plugins that use PHP:

```php
// In your email plugin
function send_podcast_outreach_email($entry_id) {
  // Get contact data
  $integration = PIT_Email_Integration::get_instance();
  $contact = $integration->get_contact_from_all_sources($entry_id);

  if (empty($contact['email'])) {
    return new WP_Error('no_email', 'No email found for this entry');
  }

  // Use your email sending logic
  $to = $contact['email'];
  $subject = "Interview Request for {$contact['podcast_name']}";
  $message = get_email_template($contact);

  // Send via your preferred method
  // (SendGrid, Mailgun, WP Mail SMTP, etc.)
  $result = your_email_sender()->send($to, $subject, $message);

  // Update outreach status
  if ($result) {
    $bridge = PIT_Formidable_Podcast_Bridge::get_instance();
    $bridge->mark_first_contact($entry_id);
  }

  return $result;
}
```

### Method 4: Formidable Forms Email Action

Use Formidable's built-in email action with dynamic fields:

```
To: [guestify_contact_email entry_id="[id]"]
Subject: Interview Request for [podcast_name]
From: your@email.com

Hi [guestify_contact_name entry_id="[id]"],

I'd love to be a guest on [podcast_name]...
```

You would need to create custom shortcodes:

```php
// In your theme's functions.php or custom plugin
add_shortcode('guestify_contact_email', function($atts) {
  $entry_id = intval($atts['entry_id'] ?? 0);
  if (!$entry_id) return '';

  $integration = PIT_Email_Integration::get_instance();
  $contact = $integration->get_contact_from_all_sources($entry_id);

  return $contact['email'] ?? '';
});

add_shortcode('guestify_contact_name', function($atts) {
  $entry_id = intval($atts['entry_id'] ?? 0);
  if (!$entry_id) return '';

  $integration = PIT_Email_Integration::get_instance();
  $contact = $integration->get_contact_from_all_sources($entry_id);

  return $contact['name'] ?? '';
});
```

## Example: FluentCRM Integration

```php
// Hook into Podcast Intelligence contact discovery
add_action('pit_podcast_auto_populated', function($entry_id, $podcast_id, $contact_id) {
  // Get contact data
  $contact = PIT_Database::get_contact($contact_id);

  if (!$contact || !$contact->email) return;

  // Add to FluentCRM
  $subscriber_data = [
    'email' => $contact->email,
    'first_name' => $contact->first_name,
    'last_name' => $contact->last_name,
    'status' => 'subscribed',
  ];

  // Add custom fields
  $custom_data = [
    'podcast_name' => PIT_Database::get_guestify_podcast($podcast_id)->title,
    'role' => $contact->role,
    'data_source' => 'podcast_intelligence',
    'confidence_score' => $contact->data_quality_score,
  ];

  FluentCrmApi('contacts')->createOrUpdate($subscriber_data, $custom_data);

  // Tag with podcast name
  $subscriber = FluentCrmApi('contacts')->getContact($contact->email);
  if ($subscriber) {
    $subscriber->attachTags(['podcast-outreach', 'host']);
  }
}, 10, 3);
```

## Example: Mailchimp Integration

```php
// Sync contacts to Mailchimp
function sync_podcast_contact_to_mailchimp($contact_id) {
  $contact = PIT_Database::get_contact($contact_id);

  if (!$contact || !$contact->email) return;

  $mailchimp = new MailChimp(MAILCHIMP_API_KEY);

  $result = $mailchimp->post("lists/" . MAILCHIMP_LIST_ID . "/members", [
    'email_address' => $contact->email,
    'status' => 'subscribed',
    'merge_fields' => [
      'FNAME' => $contact->first_name,
      'LNAME' => $contact->last_name,
      'PODCAST' => get_contact_podcast_name($contact_id),
      'ROLE' => $contact->role,
    ],
    'tags' => ['podcast-host', 'outreach-candidate'],
  ]);

  return $result;
}

// Hook to sync when contact is created
add_action('pit_podcast_auto_populated', function($entry_id, $podcast_id, $contact_id) {
  sync_podcast_contact_to_mailchimp($contact_id);
}, 10, 3);
```

## Example: WP Mail SMTP Integration

```php
// Use WP Mail SMTP with contact data
function send_interview_request($entry_id) {
  // Get contact
  $integration = PIT_Email_Integration::get_instance();
  $contact = $integration->get_contact_from_all_sources($entry_id);

  if (empty($contact['email'])) {
    return false;
  }

  // Setup email
  $to = $contact['email'];
  $subject = "Interview Request for {$contact['podcast_name']}";
  $headers = [
    'Content-Type: text/html; charset=UTF-8',
    'From: Your Name <your@email.com>',
  ];

  $message = "
    <html>
    <body>
      <p>Hi {$contact['name']},</p>
      <p>I'd love to be a guest on <strong>{$contact['podcast_name']}</strong>.</p>
      <p>I have insights about [your topic] that would be valuable for your audience.</p>
      <p>Would you be open to scheduling an interview?</p>
      <p>Best regards,<br>Your Name</p>
    </body>
    </html>
  ";

  // Send via WP Mail SMTP
  $sent = wp_mail($to, $subject, $message, $headers);

  if ($sent) {
    // Track that we sent the email
    $bridge = PIT_Formidable_Podcast_Bridge::get_instance();
    $bridge->mark_first_contact($entry_id);
  }

  return $sent;
}
```

## Data Structure Reference

### Contact Data Object

```php
[
  'email' => 'mark@example.com',           // Contact email
  'name' => 'Mark de Grasse',              // Full name
  'podcast_name' => 'Podcast Mark',        // Podcast title
  'source' => 'podcast_database',          // Data source
  'confidence' => 90,                      // Confidence score (0-100)
  'additional_info' => [
    'role' => 'host',                      // Contact role
    'linkedin' => 'https://...',           // LinkedIn URL
    'twitter' => 'https://...',            // Twitter URL
    'phone' => '+1234567890',              // Phone number
    'clay_enriched' => true,               // Enriched via Clay?
    'is_primary' => true,                  // Primary contact?
  ],
  'suggest_enrichment' => false,           // Suggest Clay enrichment?
  'enrichment_data' => []                  // Data for enrichment
]
```

### Data Sources

| Source | Confidence | Description |
|--------|-----------|-------------|
| `formidable_direct` | 100% | User entered directly in form |
| `podcast_database` | 90% | From podcast intelligence database |
| `podcast_contact_secondary` | 80% | Secondary contact for podcast |
| `formidable_partial` | 30% | Only name from form, no email |
| `clay` | 90% | Enriched via Clay API |
| `manual` | 50% | Manually entered via interface |

## Hooks & Filters

### Actions

```php
// Fired when podcast is auto-populated
add_action('pit_podcast_auto_populated', function($entry_id, $podcast_id, $contact_id) {
  // Your code here
}, 10, 3);

// Fired when contact is manually saved
add_action('pit_contact_manually_saved', function($contact_id, $entry_id) {
  // Your code here
}, 10, 2);
```

### Filters

```php
// Filter contact data before display
add_filter('pit_contact_data', function($contact, $entry_id) {
  // Modify contact data
  return $contact;
}, 10, 2);

// Filter confidence score calculation
add_filter('pit_confidence_score', function($score, $source, $contact) {
  // Adjust confidence score
  return $score;
}, 10, 3);
```

## Best Practices

### 1. Always Check Confidence Score

```php
$contact = $integration->get_contact_from_all_sources($entry_id);

if ($contact['confidence'] < 70) {
  // Show warning to user
  echo "⚠️ Low confidence - please verify email before sending";
}
```

### 2. Handle Missing Emails Gracefully

```php
if (empty($contact['email'])) {
  // Redirect to manual entry
  // OR show enrichment option
  // OR skip this entry
}
```

### 3. Track Email Status

```php
// After sending, update the database
$bridge = PIT_Formidable_Podcast_Bridge::get_instance();
$bridge->update_outreach_status($entry_id, 'sent');
$bridge->mark_first_contact($entry_id);
```

### 4. Use Additional Info When Available

```php
// LinkedIn might be more reliable for some contacts
if (empty($contact['email']) && !empty($contact['additional_info']['linkedin'])) {
  // Suggest LinkedIn outreach instead
  echo "Consider reaching out via LinkedIn: {$contact['additional_info']['linkedin']}";
}
```

## Troubleshooting

### Contact Not Found

```php
// Debug contact lookup
$contact = $integration->get_contact_from_all_sources($entry_id);

if (empty($contact['email'])) {
  error_log("No contact found for entry {$entry_id}");
  error_log("Source: {$contact['source']}");
  error_log("Confidence: {$contact['confidence']}%");
  error_log("Suggest enrichment: " . ($contact['suggest_enrichment'] ? 'yes' : 'no'));
}
```

### REST API Not Working

```php
// Check if REST API is accessible
$test = wp_remote_get(rest_url('podcast-influence/v1/entries/123/contact-email'), [
  'headers' => [
    'X-WP-Nonce' => wp_create_nonce('wp_rest'),
  ],
]);

if (is_wp_error($test)) {
  error_log('REST API error: ' . $test->get_error_message());
}
```

## Support

For questions or issues:
- Main Documentation: `/PODCAST-INTELLIGENCE-DATABASE.md`
- Installation Guide: `/INSTALLATION.md`
- GitHub Issues: https://github.com/meettonyg/showauthority/issues
