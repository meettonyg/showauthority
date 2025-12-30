# ShowAuthority Message Tab Redesign - Implementation Plan
## Revised v1.1 (Incorporating Architecture Review)

## Executive Summary

This document outlines a phased approach to implementing the redesigned Message Tab for the ShowAuthority WordPress plugin. The redesign transforms the current modal-based email composer into an **inline composition experience** with AI refinement, template management, expandable campaign steps, and enhanced action buttons.

> ⚠️ **Architecture Note:** This plan has been revised to adhere to the **Bridge Pattern** used by ShowAuthority. All template/message operations route through `PIT_Guestify_Outreach_Bridge`, maintaining separation between ShowAuthority and the Guestify Outreach plugin.

---

## Architecture Overview

### Bridge Pattern (Critical)

ShowAuthority uses a **Bridge Pattern** to decouple from Guestify Outreach:

```
Vue Components → Pinia Store → outreach.js → REST API → Bridge → Guestify Outreach
```

**Key Files:**
- `includes/integrations/class-guestify-outreach-bridge.php` - Abstraction layer
- `includes/API/class-rest-guestify-bridge.php` - REST endpoints

**Rules:**
1. ✅ Extend the Bridge for template/message operations
2. ❌ Never create direct REST controllers for Outreach functionality
3. ✅ Use `pit_*` tables for ShowAuthority-owned data (drafts, AI logs)
4. ❌ Never ALTER `guestify_*` tables (owned by Outreach plugin)

### Database Ownership

| Table Prefix | Owner | Can Modify? |
|--------------|-------|-------------|
| `guestify_*` | Guestify Outreach Plugin | ❌ No |
| `pit_*` | ShowAuthority | ✅ Yes |

---

## Current State Analysis

### Existing Infrastructure ✅

**Vue Components** (`/frontend-vue/src/components/messaging/`):
- `MessageTab.vue` - Main container (modal-based composer)
- `MessageComposer.vue` - Modal dialog for composing emails
- `MessageList.vue` - Message history display
- `MessageStats.vue` - Email statistics
- `TemplateSelector.vue` - Template dropdown
- `SequenceSelector.vue` - Campaign sequence selection
- `VariableSidebar.vue` - Variable insertion panel
- `CampaignManager.vue` - Active campaign management

**Pinia Store** (`/frontend-vue/src/stores/messages.js`):
- Template loading/management
- Message sending via Brevo integration
- Campaign/sequence management
- Statistics tracking

**REST API Bridge** (`/includes/API/class-rest-guestify-bridge.php`):
- Status, templates, messages, stats
- Campaign start/pause/resume/cancel
- Sequence-based campaigns
- Unified stats

**Cost Tracking** (`/includes/class-cost-tracker.php`):
- `pit_cost_log` table with `action_type` enum
- Budget monitoring and forecasting

### Key Changes Required

| Current | New Design |
|---------|------------|
| Modal-based composer | Inline composer (no modal) |
| Static template preview | Preview vs Edit toggle |
| Basic variable sidebar | Collapsible variable sections |
| No AI assistance | AI refinement panel with quick actions |
| Single "Send Email" button | 4 action buttons (Open/Copy/Draft/Sent) |
| Static campaign steps | Expandable/editable steps with inline editing |
| No template save flow | Save to Template / Save as New modals |

---

## Phase 1: Inline Compose Architecture (3-4 days)

### Goal
Replace the modal-based composer with an inline composition experience embedded directly in the Message tab.

### Tasks

#### 1.1 Create InlineComposer.vue
Replace the modal with an inline panel that appears below the header.

**New Component:** `/frontend-vue/src/components/messaging/InlineComposer.vue`

```vue
<!-- Core structure -->
<template>
  <div class="inline-composer" v-if="isComposing">
    <!-- Two-column layout: Compose + Variables sidebar -->
    <div class="composer-layout">
      <div class="composer-main">
        <!-- Mode toggle, form fields, action buttons -->
      </div>
      <VariableSidebar v-if="showSidebar" ... />
    </div>
  </div>
</template>
```

**Key Props:**
- `appearanceId` - Current opportunity ID
- `defaultEmail` - Pre-filled recipient email
- `defaultName` - Pre-filled recipient name
- `templates` - Available email templates
- `sequences` - Available campaign sequences

**Key Events:**
- `@email-sent` - Email was marked as sent
- `@draft-saved` - Draft was saved
- `@cancel` - User cancelled composition
- `@mode-switched` - User toggled between Single Email ↔ Campaign *(new)*

> 💡 **Refinement (from review):** The `@mode-switched` event is critical for parent component reactivity. When switching from "Single Email" to "Campaign", the UI needs to hide the body input and show the sequence selector smoothly.

#### 1.2 Update MessageTab.vue
Modify to use inline composer instead of modal.

**Changes:**
```diff
- <MessageComposer :show="showComposer" ...modal props... />
+ <InlineComposer 
+   v-if="isComposing"
+   :appearance-id="appearanceId"
+   @cancel="isComposing = false"
+   @email-sent="handleEmailSent"
+ />

- <button @click="showComposer = true">Compose Email</button>
+ <button @click="isComposing = true">Compose Email</button>
```

#### 1.3 Add Action Buttons Bar
Add the 4 action buttons at bottom of composer.

**New Component:** `/frontend-vue/src/components/messaging/ActionButtonsBar.vue`

```vue
<template>
  <div class="action-buttons-bar">
    <!-- Single Email Mode -->
    <template v-if="mode === 'single'">
      <button @click="openInEmail" class="btn btn-outline">
        📧 Open in Email
      </button>
      <button @click="copyBody" class="btn btn-outline">
        📋 {{ copied ? '✓ Copied!' : 'Copy Body' }}
      </button>
      <button @click="saveDraft" class="btn btn-outline">
        💾 Save Draft
      </button>
      <button @click="markAsSent" class="btn btn-primary">
        ✓ Mark as Sent
      </button>
    </template>
    
    <!-- Campaign Mode -->
    <template v-else>
      <button @click="saveDraft" class="btn btn-outline">
        💾 Save Draft
      </button>
      <button @click="startCampaign" class="btn btn-primary">
        ▶ Start Campaign
      </button>
    </template>
  </div>
</template>
```

### Deliverables
- [ ] `InlineComposer.vue` component
- [ ] `ActionButtonsBar.vue` component
- [ ] Updated `MessageTab.vue`
- [ ] CSS styling for inline layout

---

## Phase 2: Preview/Edit Toggle & Variable Improvements (2-3 days)

### Goal
Add ability to toggle between seeing resolved variables (Preview) and editing raw templates (Edit), plus collapsible variable sections.

### Tasks

#### 2.1 Add Preview Toggle to InlineComposer
**UI:** Toggle switch between "Preview" and "Template"

```vue
<div class="preview-toggle">
  <button 
    :class="{ active: previewMode }" 
    @click="previewMode = true"
  >
    Preview
  </button>
  <button 
    :class="{ active: !previewMode }" 
    @click="previewMode = false"
  >
    Template
  </button>
</div>
```

**Logic:**
- Preview mode: Display resolved variables (e.g., "Hi Dan" instead of "Hi {{host_name}}")
- Template mode: Show raw template with `{{variable}}` syntax

#### 2.2 Create Variable Resolution Utility
**New Utility:** `/frontend-vue/src/utils/variableResolver.js`

```javascript
/**
 * Resolve template variables with actual values
 * 
 * @param {string} text - Text containing {{variable}} placeholders
 * @param {object} apiResponse - Variable data from get_appearance_variables API
 * @returns {string} Resolved text
 */
export function resolveVariables(text, apiResponse) {
  if (!text) return '';
  let resolved = text;
  
  // Build flat map from API's categorized structure
  const flatVars = flattenVariables(apiResponse);
  
  Object.entries(flatVars).forEach(([key, value]) => {
    // Match both {{key}} and {key} formats
    resolved = resolved.replace(
      new RegExp(`\\{\\{${key}\\}\\}`, 'g'), 
      value || `{{${key}}}`
    );
  });
  return resolved;
}

/**
 * Flatten the categorized variable structure from get_appearance_variables API
 * 
 * API Response Shape:
 * {
 *   "categories": [
 *     {
 *       "name": "Messaging & Positioning",
 *       "variables": [
 *         { "tag": "{{authority_hook}}", "label": "Your Authority Hook", "value": "..." }
 *       ]
 *     }
 *   ]
 * }
 * 
 * @param {object} apiResponse - Response from get_appearance_variables
 * @returns {object} Flat map of { variable_name: value }
 */
function flattenVariables(apiResponse) {
  const flat = {};
  
  if (!apiResponse?.categories) {
    return flat;
  }
  
  // Iterate over each category
  apiResponse.categories.forEach(category => {
    if (!category.variables) return;
    
    // Iterate over variables within each category
    category.variables.forEach(variable => {
      // Extract key from tag: "{{authority_hook}}" -> "authority_hook"
      const tag = variable.tag || '';
      const key = tag.replace(/^\{\{|\}\}$/g, '');
      
      if (key && variable.value !== undefined) {
        flat[key] = variable.value;
      }
    });
  });
  
  return flat;
}

/**
 * Get list of unresolved variables in text
 * Useful for showing warnings about missing data
 * 
 * @param {string} text - Text to check
 * @param {object} apiResponse - Variable data
 * @returns {string[]} List of unresolved variable names
 */
export function getUnresolvedVariables(text, apiResponse) {
  if (!text) return [];
  
  const flatVars = flattenVariables(apiResponse);
  const matches = text.match(/\{\{(\w+)\}\}/g) || [];
  
  return matches
    .map(match => match.replace(/^\{\{|\}\}$/g, ''))
    .filter(key => !flatVars[key] || flatVars[key] === '');
}
```

> 💡 **Note (from final review):** The `flattenVariables` function now correctly handles the actual API response structure from `get_appearance_variables()`, which returns categories containing variables arrays with `{tag, label, value}` objects.

#### 2.3 Enhance VariableSidebar.vue
Update to use collapsible sections for variable categories.

```vue
<div class="variable-section">
  <button @click="toggleSection('messaging')" class="section-header">
    <span>Your Messaging</span>
    <span>{{ expandedSections.messaging ? '▼' : '▶' }}</span>
  </button>
  <div v-if="expandedSections.messaging" class="section-content">
    <!-- Variable chips -->
  </div>
</div>
```

**Variable Categories:**
1. **Your Messaging** - authority_hook, impact_intro, who_you_help, etc.
2. **Podcast Info** - podcast_name, host_name, episode_count, recent_episode
3. **Contact Details** - contact_email, contact_first_name, booking_link

### Deliverables
- [ ] Preview/Edit toggle in `InlineComposer.vue`
- [ ] `variableResolver.js` utility
- [ ] Enhanced `VariableSidebar.vue` with collapsible sections

---

## Phase 3: Campaign Mode with Expandable Steps (3-4 days)

### Goal
Transform campaign mode to show expandable email steps with inline preview and editing capabilities.

> ⚠️ **Design Decision Required:** When a user clicks "Edit Step" on a campaign sequence:
> - **Option A (Recommended for V1):** Edits are LOCAL to this campaign only. Force "Save as New Template" if they want to reuse.
> - **Option B:** Edits update the GLOBAL sequence template, affecting all future campaigns.
> 
> **Recommendation:** Implement Option A for V1 to prevent accidental changes to shared sequences. Show clear UI messaging: "Changes apply to this campaign only."

### Tasks

#### 3.1 Create ExpandableStepCard.vue
**New Component:** `/frontend-vue/src/components/messaging/ExpandableStepCard.vue`

```vue
<template>
  <div class="step-card" :class="{ expanded: isExpanded }">
    <!-- Collapsed Header -->
    <div class="step-header" @click="toggle">
      <div class="step-badge">{{ stepNumber }}</div>
      <div class="step-info">
        <span class="step-name">{{ step.name }}</span>
        <span class="step-delay" v-if="step.delay">{{ step.delay }}</span>
      </div>
      <span class="expand-icon">{{ isExpanded ? '▼' : '▶' }}</span>
    </div>
    
    <!-- Expanded Content -->
    <div v-if="isExpanded" class="step-content">
      <!-- Preview/Edit Toggle -->
      <div class="step-toolbar">
        <div class="preview-toggle">...</div>
        <button v-if="!isEditing" @click="startEditing">
          ✏️ Edit Step
        </button>
      </div>
      
      <!-- Subject & Body (preview or edit mode) -->
      <div class="step-email">
        <div class="email-subject">
          <label>Subject</label>
          <template v-if="isEditing">
            <input v-model="editedSubject" />
          </template>
          <template v-else>
            <span>{{ resolvedSubject }}</span>
          </template>
        </div>
        <div class="email-body">
          <label>Body</label>
          <template v-if="isEditing">
            <textarea v-model="editedBody" rows="8" />
          </template>
          <template v-else>
            <div class="preview-text">{{ resolvedBody }}</div>
          </template>
        </div>
      </div>
      
      <!-- Edit Mode Actions -->
      <div v-if="isEditing" class="step-actions">
        <button @click="cancelEditing">Cancel</button>
        <button @click="showAIPanel = true">🤖 Refine with AI</button>
        <button @click="openSaveModal('update')">💾 Save to Template</button>
        <button @click="openSaveModal('new')">📝 Save as New</button>
      </div>
    </div>
  </div>
</template>
```

#### 3.2 Create CampaignStepsList.vue
Container for managing multiple expandable steps.

```vue
<template>
  <div class="campaign-steps">
    <div class="steps-header">
      <h4>{{ sequence.name }}</h4>
      <span class="steps-count">{{ sequence.steps.length }} emails</span>
    </div>
    
    <ExpandableStepCard
      v-for="(step, index) in sequence.steps"
      :key="index"
      :step="step"
      :step-number="index + 1"
      :variables="variables"
      :expanded="expandedStep === index"
      @toggle="handleStepToggle(index)"
      @edit="handleStepEdit(index, $event)"
      @save-template="handleSaveTemplate"
    />
  </div>
</template>
```

#### 3.3 Update InlineComposer for Campaign Mode
Integrate the expandable steps into campaign mode.

### Deliverables
- [ ] `ExpandableStepCard.vue` component
- [ ] `CampaignStepsList.vue` component
- [ ] Campaign mode integration in `InlineComposer.vue`

---

## Phase 4: Template Save/Update Flow (2-3 days)

### Goal
Implement the "Save to Template" and "Save as New Template" modals for both single email and campaign step editing.

> ⚠️ **Architecture Correction:** Route all template operations through the Bridge pattern. Do NOT create standalone `class-rest-templates.php`.

### Tasks

#### 4.1 Create SaveTemplateModal.vue
**New Component:** `/frontend-vue/src/components/messaging/SaveTemplateModal.vue`

```vue
<template>
  <Teleport to="body">
    <div v-if="show" class="modal-overlay">
      <div class="modal-container">
        <div class="modal-header">
          <h3>{{ isUpdate ? 'Update Template' : 'Save as New Template' }}</h3>
          <button @click="$emit('close')">✕</button>
        </div>
        
        <div class="modal-body">
          <!-- Update Mode: Warning about affecting future campaigns -->
          <div v-if="isUpdate" class="warning-box">
            ⚠️ This will update the <strong>"{{ templateName }}"</strong> 
            template for all future campaigns using this sequence.
          </div>
          
          <!-- New Template Mode: Name input -->
          <div v-else class="form-group">
            <label>New Template Name *</label>
            <input 
              v-model="newName" 
              placeholder="e.g., Book Author Intro v2"
              autofocus
            />
          </div>
        </div>
        
        <div class="modal-footer">
          <button @click="$emit('close')" class="btn-secondary">
            Cancel
          </button>
          <button 
            @click="handleSave" 
            :disabled="!isUpdate && !newName.trim()"
            class="btn-primary"
          >
            {{ isUpdate ? 'Update Template' : 'Create Template' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
```

#### 4.2 Extend the Bridge for Template CRUD (Backend)

**Modify:** `/includes/integrations/class-guestify-outreach-bridge.php`

```php
/**
 * Create a new email template
 * Routes through Guestify Outreach Public API
 *
 * @param array $args Template data
 * @return array Result with success, message, template_id
 */
public static function create_template(array $args): array {
    if (!self::has_public_api()) {
        return [
            'success' => false,
            'message' => 'Template creation requires Guestify Outreach v2.0 or later.'
        ];
    }
    
    // Delegate to Outreach plugin
    if (method_exists('Guestify_Outreach_Public_API', 'create_template')) {
        return Guestify_Outreach_Public_API::create_template([
            'name'        => sanitize_text_field($args['name']),
            'subject'     => sanitize_text_field($args['subject']),
            'body_html'   => wp_kses_post($args['body_html']),
            'category'    => sanitize_text_field($args['category'] ?? 'Custom'),
            'user_id'     => get_current_user_id(),
        ]);
    }
    
    return ['success' => false, 'message' => 'Template API not available'];
}

/**
 * Update an existing template
 */
public static function update_template(int $template_id, array $args): array {
    if (!self::has_public_api()) {
        return [
            'success' => false,
            'message' => 'Template updates require Guestify Outreach v2.0 or later.'
        ];
    }
    
    if (method_exists('Guestify_Outreach_Public_API', 'update_template')) {
        return Guestify_Outreach_Public_API::update_template($template_id, [
            'subject'   => sanitize_text_field($args['subject'] ?? ''),
            'body_html' => wp_kses_post($args['body_html'] ?? ''),
        ]);
    }
    
    return ['success' => false, 'message' => 'Template API not available'];
}
```

#### 4.3 Add REST Endpoints via Bridge

**Modify:** `/includes/API/class-rest-guestify-bridge.php`

```php
// Add in register_routes()
register_rest_route(self::NAMESPACE, '/pit-bridge/templates', [
    [
        'methods'             => 'GET',
        'callback'            => [__CLASS__, 'get_templates'],
        'permission_callback' => [__CLASS__, 'check_permission'],
    ],
    [
        'methods'             => 'POST',
        'callback'            => [__CLASS__, 'create_template'],
        'permission_callback' => [__CLASS__, 'check_permission'],
        'args' => [
            'name'      => ['required' => true, 'type' => 'string'],
            'subject'   => ['required' => true, 'type' => 'string'],
            'body_html' => ['required' => true, 'type' => 'string'],
            'category'  => ['type' => 'string', 'default' => 'Custom'],
        ],
    ],
]);

register_rest_route(self::NAMESPACE, '/pit-bridge/templates/(?P<id>\d+)', [
    'methods'             => 'PUT',
    'callback'            => [__CLASS__, 'update_template'],
    'permission_callback' => [__CLASS__, 'check_permission'],
    'args' => [
        'id'        => ['required' => true, 'type' => 'integer'],
        'subject'   => ['type' => 'string'],
        'body_html' => ['type' => 'string'],
    ],
]);
```

#### 4.4 Update Pinia Store

```javascript
// messages.js - Add template CRUD actions
async createTemplate(templateData) {
  const result = await outreachApi.createTemplate(templateData);
  if (result.success) {
    await this.loadTemplates(); // Refresh list
  }
  return result;
},

async updateTemplate(templateId, templateData) {
  const result = await outreachApi.updateTemplate(templateId, templateData);
  if (result.success) {
    await this.loadTemplates(); // Refresh list
  }
  return result;
}
```

### Deliverables
- [ ] `SaveTemplateModal.vue` component
- [ ] Extended `PIT_Guestify_Outreach_Bridge` with template methods
- [ ] REST routes in `PIT_REST_Guestify_Bridge`
- [ ] Store actions for template CRUD

---

## Phase 5: AI Refinement Integration (3-4 days)

### Goal
Add AI-powered message refinement with quick actions and custom prompts.

> ⚠️ **Cost Tracking Required:** The plugin uses `pit_cost_log` for API expense monitoring. All AI calls MUST be logged with `action_type='ai_generation'` to appear in the Analytics tab.

### Tasks

#### 5.1 Create AIRefinementPanel.vue
**New Component:** `/frontend-vue/src/components/messaging/AIRefinementPanel.vue`

```vue
<template>
  <div class="ai-panel">
    <div class="ai-header">
      <h4>🤖 Refine with AI</h4>
      <button @click="$emit('close')">✕</button>
    </div>
    
    <!-- Quick Actions -->
    <div class="quick-actions">
      <button 
        v-for="action in quickActions" 
        :key="action.label"
        @click="applyQuickAction(action.prompt)"
        :disabled="generating"
      >
        {{ action.icon }} {{ action.label }}
      </button>
    </div>
    
    <!-- Custom Prompt -->
    <div class="custom-prompt">
      <label>Or describe what you want:</label>
      <textarea 
        v-model="customPrompt"
        placeholder="e.g., Make it more conversational and add a specific hook about their recent episode"
        rows="3"
      />
    </div>
    
    <!-- Generation Options -->
    <div class="ai-options">
      <div class="option-group">
        <label>Tone</label>
        <select v-model="tone">
          <option value="professional">Professional</option>
          <option value="casual">Casual</option>
          <option value="friendly">Friendly</option>
        </select>
      </div>
      <div class="option-group">
        <label>Length</label>
        <select v-model="length">
          <option value="shorter">Shorter</option>
          <option value="medium">Medium</option>
          <option value="longer">Longer</option>
        </select>
      </div>
    </div>
    
    <!-- Generate Button -->
    <button 
      @click="generate" 
      :disabled="generating || (!customPrompt && !selectedAction)"
      class="btn-generate"
    >
      <span v-if="generating">⏳ Generating...</span>
      <span v-else>✨ Generate</span>
    </button>
  </div>
</template>

<script setup>
const quickActions = [
  { label: 'Make it shorter', icon: '📏', prompt: 'Shorten this email while keeping key points' },
  { label: 'More personal', icon: '💬', prompt: 'Make this more personal and conversational' },
  { label: 'Add social proof', icon: '⭐', prompt: 'Add social proof and credibility markers' },
  { label: 'Stronger CTA', icon: '🎯', prompt: 'Strengthen the call to action' },
  { label: 'Reference episode', icon: '🎙️', prompt: 'Add a reference to their recent episode' },
  { label: 'Add urgency', icon: '⏰', prompt: 'Add appropriate urgency without being pushy' },
];
</script>
```

#### 5.2 Create AI Service
**New Service:** `/frontend-vue/src/services/ai.js`

```javascript
import api from './api';

export default {
  async refineMessage(data) {
    const response = await api.post('/showauthority/v1/ai/refine', {
      content: data.content,
      prompt: data.prompt,
      tone: data.tone,
      length: data.length,
      context: data.context // podcast info, etc.
    });
    return response.data;
  }
};
```

#### 5.3 Add AI REST Endpoint with Cost Tracking

> 💡 **Note:** AI logic is ShowAuthority-specific (context-aware), so a dedicated controller is appropriate here (unlike template operations).

**New File:** `/includes/API/class-rest-ai.php`

```php
<?php
/**
 * REST API endpoints for AI-powered message refinement
 * 
 * IMPORTANT: All AI calls are logged to pit_cost_log for budget tracking
 *
 * @package ShowAuthority
 * @since 5.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_REST_AI {

    const NAMESPACE = 'showauthority/v1';

    public static function register_routes(): void {
        register_rest_route(self::NAMESPACE, '/ai/refine', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'refine_message'],
            'permission_callback' => [__CLASS__, 'check_permissions'],
            'args' => [
                'content' => ['required' => true, 'type' => 'string'],
                'prompt'  => ['required' => true, 'type' => 'string'],
                'tone'    => ['type' => 'string', 'default' => 'professional'],
                'length'  => ['type' => 'string', 'default' => 'medium'],
                'context' => ['type' => 'object', 'default' => []],
            ],
        ]);
    }

    public static function check_permissions(): bool {
        return current_user_can('edit_posts');
    }

    public static function refine_message(WP_REST_Request $request): WP_REST_Response {
        $content = sanitize_textarea_field($request['content']);
        $prompt = sanitize_text_field($request['prompt']);
        $tone = sanitize_text_field($request['tone'] ?? 'professional');
        $length = sanitize_text_field($request['length'] ?? 'medium');
        $context = $request['context'] ?? [];

        $start_time = microtime(true);
        
        try {
            // Call AI provider (OpenAI/Claude)
            $result = self::call_ai_api($content, $prompt, $tone, $length, $context);
            $duration = microtime(true) - $start_time;
            
            // Log cost to pit_cost_log for budget tracking
            self::log_ai_cost($result['cost_usd'] ?? 0.01, $duration, true);

            return new WP_REST_Response([
                'success' => true,
                'content' => $result['refined_content'],
                'tokens_used' => $result['tokens_used'] ?? 0,
            ]);
            
        } catch (Exception $e) {
            $duration = microtime(true) - $start_time;
            self::log_ai_cost(0, $duration, false);
            
            return new WP_REST_Response([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Log AI generation cost to pit_cost_log
     * Integrates with existing cost tracking infrastructure
     */
    private static function log_ai_cost(float $cost_usd, float $duration, bool $success): void {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_cost_log';

        $wpdb->insert($table, [
            'user_id'      => get_current_user_id(),
            'action_type'  => 'ai_generation', // NEW action type
            'platform'     => 'email_refinement',
            'cost_usd'     => $cost_usd,
            'api_provider' => 'openai', // or 'anthropic'
            'api_calls_made' => 1,
            'success'      => $success ? 1 : 0,
            'metadata'     => wp_json_encode([
                'duration_seconds' => round($duration, 2),
            ]),
            'logged_at'    => current_time('mysql'),
        ]);
    }

    private static function call_ai_api($content, $prompt, $tone, $length, $context): array {
        // Implementation depends on chosen AI provider
        // See separate AI integration documentation
        
        $api_key = get_option('pit_openai_api_key', '');
        if (empty($api_key)) {
            throw new Exception('AI API key not configured');
        }

        // OpenAI API call implementation...
        // Return: ['refined_content' => '...', 'cost_usd' => 0.002, 'tokens_used' => 150]
    }
}
```

#### 5.4 Update action_type Enum (Database Migration)

The `pit_cost_log.action_type` column needs to support `'ai_generation'`:

```sql
-- Check current enum values
SHOW COLUMNS FROM wp_pit_cost_log LIKE 'action_type';

-- If needed, alter to add new value
ALTER TABLE wp_pit_cost_log 
MODIFY COLUMN action_type ENUM('discovery', 'enrichment', 'refresh', 'manual', 'ai_generation') NOT NULL;
```

### Deliverables
- [ ] `AIRefinementPanel.vue` component
- [ ] AI service (`ai.js`)
- [ ] REST endpoint `class-rest-ai.php` with cost logging
- [ ] Database migration for `action_type` enum
- [ ] Quick action buttons
- [ ] Loading states and error handling

---

## Phase 6: Draft Management & Message History (2-3 days)

### Goal
Implement draft saving/loading and enhance message history to include drafts and manually marked emails.

> ⚠️ **Architecture Correction:** Do NOT modify `guestify_messages` (owned by Outreach plugin). Create a new `pit_email_drafts` table owned by ShowAuthority.

### Tasks

#### 6.1 Create pit_email_drafts Table (ShowAuthority-Owned)

**Modify:** `/includes/Core/class-database-schema.php`

```php
/**
 * Create email drafts table (ShowAuthority-owned)
 */
private static function create_email_drafts_table($charset_collate) {
    global $wpdb;

    $table_drafts = $wpdb->prefix . 'pit_email_drafts';
    $sql_drafts = "CREATE TABLE $table_drafts (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,

        -- User Ownership
        user_id bigint(20) UNSIGNED NOT NULL,
        
        -- Context
        appearance_id bigint(20) UNSIGNED NOT NULL,
        
        -- Draft Type
        draft_type enum('single_email', 'campaign_step') DEFAULT 'single_email',
        sequence_id bigint(20) UNSIGNED DEFAULT NULL,
        step_number int(11) DEFAULT NULL,
        
        -- Content
        recipient_email varchar(255) DEFAULT NULL,
        recipient_name varchar(255) DEFAULT NULL,
        subject varchar(500) DEFAULT NULL,
        body_html text DEFAULT NULL,
        template_id bigint(20) UNSIGNED DEFAULT NULL,
        
        -- Status
        status enum('draft', 'marked_sent') DEFAULT 'draft',
        marked_sent_at datetime DEFAULT NULL,
        
        -- Timestamps
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

        PRIMARY KEY (id),
        KEY user_id_idx (user_id),
        KEY appearance_id_idx (appearance_id),
        KEY status_idx (status),
        KEY draft_type_idx (draft_type)
    ) $charset_collate;";

    dbDelta($sql_drafts);
}
```

#### 6.2 Add Draft REST Endpoints

**Modify:** `/includes/API/class-rest-guestify-bridge.php`

```php
// Add in register_routes()

// Save/Load drafts for an appearance
register_rest_route(self::NAMESPACE, '/pit-bridge/appearances/(?P<id>\d+)/drafts', [
    [
        'methods'             => 'GET',
        'callback'            => [__CLASS__, 'get_drafts'],
        'permission_callback' => [__CLASS__, 'check_permission'],
    ],
    [
        'methods'             => 'POST',
        'callback'            => [__CLASS__, 'save_draft'],
        'permission_callback' => [__CLASS__, 'check_permission'],
        'args' => [
            'draft_type'      => ['type' => 'string', 'default' => 'single_email'],
            'recipient_email' => ['type' => 'string'],
            'recipient_name'  => ['type' => 'string'],
            'subject'         => ['type' => 'string'],
            'body_html'       => ['type' => 'string'],
            'template_id'     => ['type' => 'integer'],
        ],
    ],
]);

// Mark as sent (creates record without actually sending)
register_rest_route(self::NAMESPACE, '/pit-bridge/appearances/(?P<id>\d+)/mark-sent', [
    'methods'             => 'POST',
    'callback'            => [__CLASS__, 'mark_as_sent'],
    'permission_callback' => [__CLASS__, 'check_permission'],
    'args' => [
        'recipient_email' => ['required' => true, 'type' => 'string'],
        'subject'         => ['required' => true, 'type' => 'string'],
        'body_html'       => ['type' => 'string'],
    ],
]);

// Delete a draft
register_rest_route(self::NAMESPACE, '/pit-bridge/drafts/(?P<draft_id>\d+)', [
    'methods'             => 'DELETE',
    'callback'            => [__CLASS__, 'delete_draft'],
    'permission_callback' => [__CLASS__, 'check_permission'],
]);
```

```php
/**
 * Get drafts for an appearance
 */
public static function get_drafts(WP_REST_Request $request): WP_REST_Response {
    global $wpdb;
    $appearance_id = absint($request->get_param('id'));
    
    if (!self::verify_appearance_ownership($appearance_id)) {
        return new WP_REST_Response(['success' => false, 'message' => 'Unauthorized'], 403);
    }
    
    $table = $wpdb->prefix . 'pit_email_drafts';
    $user_id = get_current_user_id();
    
    $drafts = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} 
         WHERE appearance_id = %d AND user_id = %d AND status = 'draft'
         ORDER BY updated_at DESC",
        $appearance_id,
        $user_id
    ), ARRAY_A);
    
    return new WP_REST_Response(['success' => true, 'data' => $drafts]);
}

/**
 * Save a draft
 */
public static function save_draft(WP_REST_Request $request): WP_REST_Response {
    global $wpdb;
    $appearance_id = absint($request->get_param('id'));
    
    if (!self::verify_appearance_ownership($appearance_id)) {
        return new WP_REST_Response(['success' => false, 'message' => 'Unauthorized'], 403);
    }
    
    $table = $wpdb->prefix . 'pit_email_drafts';
    
    $wpdb->insert($table, [
        'user_id'         => get_current_user_id(),
        'appearance_id'   => $appearance_id,
        'draft_type'      => sanitize_text_field($request['draft_type'] ?? 'single_email'),
        'recipient_email' => sanitize_email($request['recipient_email'] ?? ''),
        'recipient_name'  => sanitize_text_field($request['recipient_name'] ?? ''),
        'subject'         => sanitize_text_field($request['subject'] ?? ''),
        'body_html'       => wp_kses_post($request['body_html'] ?? ''),
        'template_id'     => absint($request['template_id'] ?? 0) ?: null,
        'status'          => 'draft',
        'created_at'      => current_time('mysql'),
        'updated_at'      => current_time('mysql'),
    ]);
    
    return new WP_REST_Response([
        'success' => true,
        'draft_id' => $wpdb->insert_id,
        'message' => 'Draft saved'
    ], 201);
}

/**
 * Mark email as sent (without actually sending)
 * Creates a record in drafts table with status='marked_sent'
 */
public static function mark_as_sent(WP_REST_Request $request): WP_REST_Response {
    global $wpdb;
    $appearance_id = absint($request->get_param('id'));
    
    if (!self::verify_appearance_ownership($appearance_id)) {
        return new WP_REST_Response(['success' => false, 'message' => 'Unauthorized'], 403);
    }
    
    $table = $wpdb->prefix . 'pit_email_drafts';
    
    $wpdb->insert($table, [
        'user_id'         => get_current_user_id(),
        'appearance_id'   => $appearance_id,
        'draft_type'      => 'single_email',
        'recipient_email' => sanitize_email($request['recipient_email']),
        'subject'         => sanitize_text_field($request['subject']),
        'body_html'       => wp_kses_post($request['body_html'] ?? ''),
        'status'          => 'marked_sent',
        'marked_sent_at'  => current_time('mysql'),
        'created_at'      => current_time('mysql'),
        'updated_at'      => current_time('mysql'),
    ]);
    
    // Also log to activity feed
    $notes_table = $wpdb->prefix . 'pit_appearance_notes';
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $notes_table))) {
        $wpdb->insert($notes_table, [
            'appearance_id' => $appearance_id,
            'title'         => 'Email marked as sent: ' . wp_trim_words($request['subject'], 8),
            'content'       => sprintf('Sent to %s (manual tracking)', $request['recipient_email']),
            'note_type'     => 'email',
            'created_by'    => get_current_user_id(),
            'created_at'    => current_time('mysql'),
        ]);
    }
    
    return new WP_REST_Response([
        'success' => true,
        'message' => 'Email marked as sent'
    ], 201);
}
```

#### 6.3 Update Messages Store for Drafts

```javascript
// messages.js
state: () => ({
  // ...existing state
  draftsByAppearance: {}, // { [appearanceId]: Draft[] }
  draftsLoading: false,
}),

actions: {
  async loadDrafts(appearanceId) {
    this.draftsLoading = true;
    try {
      const result = await outreachApi.getDrafts(appearanceId);
      this.draftsByAppearance[appearanceId] = result.data || [];
    } finally {
      this.draftsLoading = false;
    }
  },
  
  async saveDraft(appearanceId, draftData) {
    const result = await outreachApi.saveDraft(appearanceId, draftData);
    if (result.success) {
      await this.loadDrafts(appearanceId);
    }
    return result;
  },
  
  async markAsSent(appearanceId, messageData) {
    const result = await outreachApi.markAsSent(appearanceId, messageData);
    if (result.success) {
      // Refresh both drafts and activity
      await this.loadMessages(appearanceId, true);
    }
    return result;
  },
  
  async deleteDraft(draftId, appearanceId) {
    const result = await outreachApi.deleteDraft(draftId);
    if (result.success) {
      await this.loadDrafts(appearanceId);
    }
    return result;
  }
},

getters: {
  getDraftsForAppearance: (state) => (appearanceId) => {
    return state.draftsByAppearance[appearanceId] || [];
  }
}
```

#### 6.4 Update MessageList to Show Drafts
Add visual distinction for drafts vs sent messages.

```vue
<template>
  <div class="message-list">
    <!-- Drafts Section -->
    <div v-if="drafts.length > 0" class="drafts-section">
      <h4>📝 Drafts</h4>
      <div 
        v-for="draft in drafts" 
        :key="'draft-' + draft.id" 
        class="message-item is-draft"
      >
        <div class="message-header">
          <span class="status-badge draft">Draft</span>
          <span class="date">{{ formatDate(draft.updated_at) }}</span>
        </div>
        <div class="message-subject">{{ draft.subject || '(No subject)' }}</div>
        <div class="message-actions">
          <button @click="$emit('resume-draft', draft)">Resume</button>
          <button @click="$emit('delete-draft', draft.id)">Delete</button>
        </div>
      </div>
    </div>
    
    <!-- Sent Messages -->
    <div v-for="msg in messages" :key="msg.id" class="message-item">
      <!-- existing message display -->
    </div>
  </div>
</template>
```

### Deliverables
- [ ] `pit_email_drafts` table in Database_Schema
- [ ] Draft REST endpoints in Bridge
- [ ] Store actions for draft management
- [ ] Updated `MessageList.vue` with draft support
- [ ] "Resume Draft" functionality in composer
- [ ] "Mark as Sent" workflow

---

## Phase 7: Polish & Integration Testing (2-3 days)

### Goal
Final polish, accessibility improvements, and comprehensive testing.

### Tasks

#### 7.1 Accessibility Audit
- [ ] Keyboard navigation for all interactive elements
- [ ] ARIA labels for buttons and form fields
- [ ] Focus management when switching modes
- [ ] Screen reader testing

#### 7.2 Responsive Design
- [ ] Mobile-friendly composer layout
- [ ] Collapsible sidebar on smaller screens
- [ ] Touch-friendly action buttons

#### 7.3 Loading States & Error Handling
- [ ] Skeleton loaders for template loading
- [ ] Error toasts for failed operations
- [ ] Optimistic UI updates

#### 7.4 Integration Testing
- [ ] Test with Brevo integration
- [ ] Test campaign start/pause/resume
- [ ] Test template save/update flow
- [ ] Test AI refinement (if API configured)

### Deliverables
- [ ] Accessibility improvements
- [ ] Responsive CSS updates
- [ ] Error handling enhancements
- [ ] Test documentation

---

## File Changes Summary

### New Files to Create

| File | Purpose |
|------|---------|
| `InlineComposer.vue` | Main inline email composition |
| `ActionButtonsBar.vue` | Open/Copy/Draft/Sent buttons |
| `ExpandableStepCard.vue` | Campaign step with expand/edit |
| `CampaignStepsList.vue` | Container for step cards |
| `SaveTemplateModal.vue` | Save template dialog |
| `AIRefinementPanel.vue` | AI refinement UI |
| `variableResolver.js` | Variable substitution utility |
| `ai.js` | AI service client |
| `class-rest-ai.php` | AI refinement endpoint (ShowAuthority-owned) |

### Files to Modify

| File | Changes |
|------|---------|
| `MessageTab.vue` | Replace modal with inline composer |
| `VariableSidebar.vue` | Add collapsible sections, match API structure |
| `MessageList.vue` | Add draft status display, resume functionality |
| `messages.js` | Add draft actions, template CRUD, AI service |
| `outreach.js` | Add draft/template/AI API methods |
| `class-guestify-outreach-bridge.php` | Add template create/update methods *(Bridge pattern)* |
| `class-rest-guestify-bridge.php` | Add template & draft REST routes *(Bridge pattern)* |
| `class-database-schema.php` | Add `pit_email_drafts` table |

### Database Changes

| Table | Owner | Change |
|-------|-------|--------|
| `pit_email_drafts` | ShowAuthority | **NEW** - Draft storage |
| `pit_cost_log` | ShowAuthority | Add `'ai_generation'` to `action_type` enum |

> ⚠️ **NOT Modified:** `guestify_*` tables remain untouched (owned by Outreach plugin)

---

## Risk Assessment

| Risk | Mitigation |
|------|------------|
| Breaking existing modal composer | Keep `MessageComposer.vue` as fallback |
| AI API costs | Integrate with `PIT_Cost_Tracker`, budget alerts |
| Bridge pattern violation | All Outreach operations go through Bridge |
| Large refactor | Phased approach allows incremental deployment |
| Variable resolution mismatch | Match `get_appearance_variables()` API structure |
| Edit Step confusion | V1: Local edits only with clear UI messaging |
| Draft data ownership | Use `pit_email_drafts` (ShowAuthority-owned) |

---

## Architecture Compliance Checklist

Before merging each phase, verify:

- [ ] Template operations route through `PIT_Guestify_Outreach_Bridge`
- [ ] No direct SQL to `guestify_*` tables
- [ ] AI costs logged to `pit_cost_log` with proper `action_type`
- [ ] Drafts stored in `pit_email_drafts` (not `guestify_messages`)
- [ ] REST endpoints follow existing namespace patterns
- [ ] Vue components emit expected events (`mode-switched`, etc.)

---

## Timeline Summary

| Phase | Duration | Dependencies |
|-------|----------|--------------|
| Phase 1: Inline Composer | 3-4 days | None |
| Phase 2: Preview Toggle | 2-3 days | Phase 1 |
| Phase 3: Campaign Steps | 3-4 days | Phase 2 |
| Phase 4: Template Save | 2-3 days | Phase 3 |
| Phase 5: AI Refinement | 3-4 days | Phase 4 |
| Phase 6: Draft Management | 2-3 days | Phase 1 |
| Phase 7: Polish & Testing | 2-3 days | All |

**Total Estimated Time: 17-24 days**

---

## Getting Started

1. Review this plan and confirm priorities
2. Start with Phase 1 to establish the inline composer architecture
3. Each phase builds on the previous, but Phases 5-6 can be done in parallel
4. Maintain backward compatibility with existing modal during transition
5. **Run architecture compliance checklist before each phase merge**

---

## Revision History

| Version | Date | Changes |
|---------|------|---------|
| 1.0 | Dec 29, 2025 | Initial plan |
| 1.1 | Dec 29, 2025 | Incorporated architecture review feedback |
| 1.2 | Dec 29, 2025 | Fixed variable resolver to match actual API structure |

### v1.2 Changes (Final Review)
- ✅ Fixed `flattenVariables()` to handle actual API response shape:
  - Categories array with `name` and `variables[]`
  - Variables with `{tag, label, value}` structure
  - Extract key from `"{{authority_hook}}"` format
- ✅ Added `getUnresolvedVariables()` helper for UI warnings

### v1.1 Changes (Architecture Review)
- ✅ Updated to use Bridge pattern for template operations
- ✅ Fixed database table references (`guestify_*` vs `pit_*`)
- ✅ Added AI cost tracking to `pit_cost_log`
- ✅ Changed draft storage to `pit_email_drafts` (ShowAuthority-owned)
- ✅ Added `mode-switched` event for UI reactivity
- ✅ Clarified "Edit Step" behavior (local vs global)

---

*Document Version: 1.2*  
*Created: December 29, 2025*  
*Plugin: ShowAuthority v5.x*  
*Reviewed by: Gemini (Architecture Compliance)*  
*Status: ✅ Approved for Execution*
