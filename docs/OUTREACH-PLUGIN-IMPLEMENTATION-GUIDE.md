# Guestify Outreach Plugin v2.0 Implementation Guide

This guide describes the changes needed in the Guestify Email Outreach plugin to support proper decoupling from Formidable Forms and enable clean integration with ShowAuthority (and future third-party plugins).

## Overview

**Current State (v1.x):**
- Database uses `interview_entry_id` column (assumes Formidable entry IDs)
- No public API - integrations query the database directly
- ShowAuthority has to "know" the database schema

**Target State (v2.0):**
- Database uses generic `entity_id` + `entity_type` columns
- Public API class for third-party integrations
- Version constant for compatibility checking
- Clean separation of concerns

---

## 1. Version Constants

Add these constants to your main plugin file (e.g., `guestify-email-outreach.php`):

```php
<?php
/**
 * Plugin Name: Guestify Email Outreach
 * Version: 2.0.0
 */

// Version constants for third-party integration compatibility
define('GUESTIFY_OUTREACH_VERSION', '2.0.0');
define('GUESTIFY_OUTREACH_API_VERSION', '1');
define('GUESTIFY_OUTREACH_MIN_PHP', '7.4');
define('GUESTIFY_OUTREACH_MIN_WP', '5.8');
```

---

## 2. Database Schema Migration

### 2.1 Messages Table

**Current Schema:**
```sql
CREATE TABLE {prefix}guestify_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    interview_entry_id BIGINT UNSIGNED,  -- Legacy: Formidable-specific
    -- ... other columns
);
```

**New Schema (v2.0):**
```sql
CREATE TABLE {prefix}guestify_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Generic entity reference (replaces interview_entry_id)
    entity_id BIGINT UNSIGNED NOT NULL,
    entity_type VARCHAR(50) NOT NULL DEFAULT 'formidable_entry',

    -- Core message data
    recipient_email VARCHAR(255) NOT NULL,
    recipient_name VARCHAR(255),
    sender_email VARCHAR(255),
    sender_name VARCHAR(255),
    subject VARCHAR(500) NOT NULL,
    body_html LONGTEXT,
    body_text LONGTEXT,

    -- Template reference
    template_id BIGINT UNSIGNED,

    -- Campaign tracking (null for ad-hoc emails)
    campaign_id BIGINT UNSIGNED,
    campaign_step INT UNSIGNED,

    -- Status and tracking
    status ENUM('queued','sent','failed','bounced','complained') DEFAULT 'queued',
    brevo_message_id VARCHAR(255),
    tracking_id VARCHAR(64),

    -- Timestamps
    queued_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    -- Indexes
    INDEX idx_entity (entity_id, entity_type),
    INDEX idx_status (status),
    INDEX idx_sent_at (sent_at),
    INDEX idx_tracking (tracking_id),
    INDEX idx_campaign (campaign_id, campaign_step)
);
```

### 2.2 Entity Types

Define supported entity types:

| `entity_type` | Description | Source |
|---------------|-------------|--------|
| `formidable_entry` | Formidable Forms entry ID | Guestify core |
| `appearance` | ShowAuthority appearance/opportunity ID | ShowAuthority |
| `contact` | Future CRM contact ID | Future |
| `lead` | Future lead ID | Future |

### 2.3 Migration Script

Create a migration to rename the column and add entity_type:

```php
<?php
/**
 * Database migration: v1.x to v2.0
 *
 * File: includes/migrations/class-migration-v2.php
 */

class Guestify_Outreach_Migration_V2 {

    public static function run(): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_messages';

        // Check if migration is needed
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table}");
        $column_names = array_column($columns, 'Field');

        // Already migrated?
        if (in_array('entity_id', $column_names)) {
            return true;
        }

        // Step 1: Add new columns
        $wpdb->query("ALTER TABLE {$table}
            ADD COLUMN entity_id BIGINT UNSIGNED AFTER id,
            ADD COLUMN entity_type VARCHAR(50) DEFAULT 'formidable_entry' AFTER entity_id");

        // Step 2: Copy data from old column
        if (in_array('interview_entry_id', $column_names)) {
            $wpdb->query("UPDATE {$table}
                SET entity_id = interview_entry_id,
                    entity_type = 'formidable_entry'
                WHERE entity_id IS NULL");
        }

        // Step 3: Make entity_id NOT NULL
        $wpdb->query("ALTER TABLE {$table}
            MODIFY COLUMN entity_id BIGINT UNSIGNED NOT NULL");

        // Step 4: Add index
        $wpdb->query("ALTER TABLE {$table}
            ADD INDEX idx_entity (entity_id, entity_type)");

        // Step 5: Drop old column (optional - keep for rollback safety)
        // $wpdb->query("ALTER TABLE {$table} DROP COLUMN interview_entry_id");

        // Update version option
        update_option('guestify_outreach_db_version', '2.0.0');

        return true;
    }
}
```

---

## 3. Public API Class

Create `includes/class-public-api.php`:

```php
<?php
/**
 * Public API for third-party integrations
 *
 * This class provides a stable interface for other plugins to interact
 * with Guestify Outreach without directly accessing the database.
 *
 * @package Guestify_Outreach
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Guestify_Outreach_Public_API {

    /**
     * Check if the plugin is properly configured
     */
    public static function is_configured(): bool {
        $settings = get_option('guestify_outreach_settings', []);
        return !empty($settings['brevo_api_key']);
    }

    /**
     * Send an email
     *
     * @param array $args {
     *     @type int    $entity_id    Required. The entity ID to link this message to.
     *     @type string $entity_type  Required. Entity type (e.g., 'appearance', 'formidable_entry').
     *     @type string $to_email     Required. Recipient email address.
     *     @type string $to_name      Optional. Recipient name.
     *     @type string $subject      Required. Email subject.
     *     @type string $html_content Required. Email HTML body.
     *     @type int    $template_id  Optional. Template ID to record.
     *     @type int    $campaign_id  Optional. Campaign ID if part of a sequence.
     *     @type int    $campaign_step Optional. Step number in campaign.
     * }
     * @return array {
     *     @type bool   $success     Whether the email was sent successfully.
     *     @type string $message     Status message.
     *     @type int    $message_id  Database ID of the message record.
     *     @type string $tracking_id Unique tracking ID for this message.
     * }
     */
    public static function send_email(array $args): array {
        // Validate required fields
        $required = ['entity_id', 'entity_type', 'to_email', 'subject', 'html_content'];
        foreach ($required as $field) {
            if (empty($args[$field])) {
                return [
                    'success' => false,
                    'message' => "Missing required field: {$field}",
                ];
            }
        }

        // Validate entity_type
        $valid_types = apply_filters('guestify_outreach_entity_types', [
            'formidable_entry',
            'appearance',
            'contact',
            'lead',
        ]);

        if (!in_array($args['entity_type'], $valid_types)) {
            return [
                'success' => false,
                'message' => "Invalid entity_type: {$args['entity_type']}",
            ];
        }

        // Use the existing sender class
        $sender = new Guestify_Outreach_Email_Sender();

        return $sender->send_email([
            'entity_id'     => absint($args['entity_id']),
            'entity_type'   => sanitize_key($args['entity_type']),
            'to_email'      => sanitize_email($args['to_email']),
            'to_name'       => sanitize_text_field($args['to_name'] ?? ''),
            'subject'       => sanitize_text_field($args['subject']),
            'html_content'  => wp_kses_post($args['html_content']),
            'template_id'   => absint($args['template_id'] ?? 0) ?: null,
            'campaign_id'   => absint($args['campaign_id'] ?? 0) ?: null,
            'campaign_step' => absint($args['campaign_step'] ?? 0) ?: null,
        ]);
    }

    /**
     * Get messages for an entity
     *
     * @param int    $entity_id   The entity ID.
     * @param string $entity_type The entity type.
     * @param array  $args        Optional. Query arguments.
     * @return array Array of message objects with tracking data.
     */
    public static function get_messages(int $entity_id, string $entity_type, array $args = []): array {
        global $wpdb;

        $table = $wpdb->prefix . 'guestify_messages';
        $events_table = $wpdb->prefix . 'guestify_message_events';

        $limit = absint($args['limit'] ?? 50);
        $offset = absint($args['offset'] ?? 0);

        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT
                id,
                recipient_email,
                recipient_name,
                subject,
                status,
                sent_at,
                brevo_message_id,
                tracking_id
             FROM {$table}
             WHERE entity_id = %d AND entity_type = %s
             ORDER BY sent_at DESC
             LIMIT %d OFFSET %d",
            $entity_id,
            $entity_type,
            $limit,
            $offset
        ));

        if (!$messages) {
            return [];
        }

        // Get events for all messages in one query
        $message_ids = array_column($messages, 'id');
        $ids_placeholder = implode(',', array_fill(0, count($message_ids), '%d'));

        $events = $wpdb->get_results($wpdb->prepare(
            "SELECT message_id, event_type, event_timestamp
             FROM {$events_table}
             WHERE message_id IN ({$ids_placeholder})
             ORDER BY event_timestamp ASC",
            ...$message_ids
        ));

        // Group events by message_id
        $events_by_message = [];
        foreach ($events as $event) {
            $events_by_message[$event->message_id][] = $event;
        }

        // Format response
        $formatted = [];
        foreach ($messages as $msg) {
            $msg_events = $events_by_message[$msg->id] ?? [];
            $open_count = 0;
            $first_opened = null;
            $clicked = false;

            foreach ($msg_events as $event) {
                if ($event->event_type === 'open') {
                    $open_count++;
                    if (!$first_opened) {
                        $first_opened = $event->event_timestamp;
                    }
                }
                if ($event->event_type === 'click') {
                    $clicked = true;
                }
            }

            $formatted[] = [
                'id'              => (int) $msg->id,
                'to_email'        => $msg->recipient_email,
                'to_name'         => $msg->recipient_name,
                'subject'         => $msg->subject,
                'status'          => $msg->status,
                'sent_at'         => $msg->sent_at,
                'sent_at_human'   => $msg->sent_at ? human_time_diff(strtotime($msg->sent_at)) . ' ago' : null,
                'tracking_id'     => $msg->tracking_id,
                'is_opened'       => $open_count > 0,
                'open_count'      => $open_count,
                'first_opened_at' => $first_opened,
                'is_clicked'      => $clicked,
            ];
        }

        return $formatted;
    }

    /**
     * Get email statistics for an entity
     *
     * @param int    $entity_id   The entity ID.
     * @param string $entity_type The entity type.
     * @return array Stats with total_sent, opened, clicked counts.
     */
    public static function get_stats(int $entity_id, string $entity_type): array {
        global $wpdb;

        $table = $wpdb->prefix . 'guestify_messages';
        $events_table = $wpdb->prefix . 'guestify_message_events';

        $total_sent = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE entity_id = %d AND entity_type = %s",
            $entity_id,
            $entity_type
        ));

        $opened = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT m.id)
             FROM {$table} m
             INNER JOIN {$events_table} e ON m.id = e.message_id
             WHERE m.entity_id = %d
               AND m.entity_type = %s
               AND e.event_type = 'open'",
            $entity_id,
            $entity_type
        ));

        $clicked = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT m.id)
             FROM {$table} m
             INNER JOIN {$events_table} e ON m.id = e.message_id
             WHERE m.entity_id = %d
               AND m.entity_type = %s
               AND e.event_type = 'click'",
            $entity_id,
            $entity_type
        ));

        return [
            'total_sent' => $total_sent,
            'opened'     => $opened,
            'clicked'    => $clicked,
        ];
    }

    /**
     * Get email templates available to a user
     *
     * @param int|null $user_id Optional. User ID. Defaults to current user.
     * @return array Array of template objects.
     */
    public static function get_templates(?int $user_id = null): array {
        global $wpdb;

        $table = $wpdb->prefix . 'guestify_email_templates';
        $user_id = $user_id ?? get_current_user_id();

        $templates = $wpdb->get_results($wpdb->prepare(
            "SELECT
                id,
                template_name,
                category,
                subject,
                body_html,
                variables_schema,
                is_default
             FROM {$table}
             WHERE (user_id = %d OR user_id = 0)
               AND is_active = 1
             ORDER BY is_default DESC, template_name ASC",
            $user_id
        ));

        if (!$templates) {
            return [];
        }

        return array_map(function($t) {
            return [
                'id'         => (int) $t->id,
                'name'       => $t->template_name,
                'category'   => $t->category,
                'subject'    => $t->subject,
                'body_html'  => $t->body_html,
                'variables'  => json_decode($t->variables_schema, true) ?: [],
                'is_default' => (bool) $t->is_default,
            ];
        }, $templates);
    }

    /**
     * Get a single message by ID
     *
     * @param int $message_id The message ID.
     * @return array|null Message data or null if not found.
     */
    public static function get_message(int $message_id): ?array {
        global $wpdb;

        $table = $wpdb->prefix . 'guestify_messages';

        $message = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $message_id
        ), ARRAY_A);

        return $message ?: null;
    }

    /**
     * Get message events (opens, clicks)
     *
     * @param int $message_id The message ID.
     * @return array Array of event objects.
     */
    public static function get_message_events(int $message_id): array {
        global $wpdb;

        $table = $wpdb->prefix . 'guestify_message_events';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT event_type, event_timestamp, event_data
             FROM {$table}
             WHERE message_id = %d
             ORDER BY event_timestamp ASC",
            $message_id
        ), ARRAY_A) ?: [];
    }
}
```

---

## 4. Update Email Sender Class

Modify your existing `Guestify_Outreach_Email_Sender` class to use the new schema:

```php
<?php
// In class-email-sender.php

public function send_email(array $args): array {
    global $wpdb;

    // Generate tracking ID
    $tracking_id = wp_generate_uuid4();

    // Insert message record with new schema
    $inserted = $wpdb->insert(
        $wpdb->prefix . 'guestify_messages',
        [
            'entity_id'       => $args['entity_id'],
            'entity_type'     => $args['entity_type'],
            'recipient_email' => $args['to_email'],
            'recipient_name'  => $args['to_name'],
            'subject'         => $args['subject'],
            'body_html'       => $args['html_content'],
            'template_id'     => $args['template_id'],
            'campaign_id'     => $args['campaign_id'],
            'campaign_step'   => $args['campaign_step'],
            'status'          => 'queued',
            'tracking_id'     => $tracking_id,
            'queued_at'       => current_time('mysql'),
        ],
        ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s']
    );

    if (!$inserted) {
        return [
            'success' => false,
            'message' => 'Failed to create message record',
        ];
    }

    $message_id = $wpdb->insert_id;

    // ... rest of sending logic (add tracking pixel, call Brevo API, etc.)

    return [
        'success'     => true,
        'message'     => 'Email sent successfully',
        'message_id'  => $message_id,
        'tracking_id' => $tracking_id,
    ];
}
```

---

## 5. Backward Compatibility

For a smooth transition, the Email Sender should support both old and new field names during a deprecation period:

```php
public function send_email(array $args): array {
    // Support legacy field name
    if (isset($args['interview_entry_id']) && !isset($args['entity_id'])) {
        $args['entity_id'] = $args['interview_entry_id'];
        $args['entity_type'] = $args['entity_type'] ?? 'formidable_entry';

        // Log deprecation warning
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Guestify Outreach: interview_entry_id is deprecated. Use entity_id and entity_type instead.');
        }
    }

    // ... rest of method
}
```

---

## 6. Plugin Activation/Upgrade Hook

```php
<?php
// In main plugin file

register_activation_hook(__FILE__, 'guestify_outreach_activate');
add_action('plugins_loaded', 'guestify_outreach_check_upgrade');

function guestify_outreach_activate() {
    guestify_outreach_create_tables();
    guestify_outreach_run_migrations();
}

function guestify_outreach_check_upgrade() {
    $db_version = get_option('guestify_outreach_db_version', '1.0.0');

    if (version_compare($db_version, '2.0.0', '<')) {
        require_once plugin_dir_path(__FILE__) . 'includes/migrations/class-migration-v2.php';
        Guestify_Outreach_Migration_V2::run();
    }
}
```

---

## 7. File Structure

```
guestify-email-outreach/
├── guestify-email-outreach.php          # Main plugin file with constants
├── includes/
│   ├── class-public-api.php             # NEW: Public API class
│   ├── class-email-sender.php           # Updated sender class
│   ├── class-email-tracker.php          # Tracking pixel/webhooks
│   ├── class-template-manager.php       # Template CRUD
│   └── migrations/
│       └── class-migration-v2.php       # NEW: v2.0 migration
├── admin/
│   └── ...
└── assets/
    └── ...
```

---

## 8. Testing Checklist

After implementing these changes:

- [ ] New installs create tables with `entity_id` / `entity_type` columns
- [ ] Existing installs migrate data from `interview_entry_id`
- [ ] `GUESTIFY_OUTREACH_VERSION` constant is defined
- [ ] `Guestify_Outreach_Public_API` class exists and works
- [ ] ShowAuthority bridge detects v2.0 and uses Public API
- [ ] Legacy `interview_entry_id` still works (backward compat)
- [ ] Tracking pixels work with new schema
- [ ] Webhook events record correctly

---

## 9. Integration Verification

Once implemented, ShowAuthority's bridge will automatically detect the new API:

```php
// ShowAuthority will check:
PIT_Guestify_Outreach_Bridge::has_public_api(); // Returns true for v2.0+

// And use:
Guestify_Outreach_Public_API::send_email([
    'entity_id'   => $appearance_id,
    'entity_type' => 'appearance',  // ShowAuthority's entity type
    // ...
]);
```

---

## Questions?

If you have questions about this implementation, please reach out before starting to ensure alignment.
