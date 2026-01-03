<template>
  <div class="action-buttons-bar">
    <!-- Single Email Mode -->
    <template v-if="mode === 'single'">
<<<<<<< Updated upstream
      <!-- Left Side Actions -->
      <div class="action-buttons-left">
=======
      <!-- Left Group: Open/Copy/Save -->
      <div class="buttons-left">
>>>>>>> Stashed changes
        <button
          @click="handleOpenInEmail"
          class="btn btn-outline"
          :disabled="disabled || !isValid"
          title="Open in your default email client"
        >
<<<<<<< Updated upstream
          <span class="btn-icon">📧</span>
          Open in Email
=======
          📧 Open in Email
>>>>>>> Stashed changes
        </button>

        <button
          @click="handleCopyBody"
          class="btn btn-outline"
<<<<<<< Updated upstream
          :class="{ 'btn-copied': copied }"
          :disabled="disabled || !body"
          title="Copy email body to clipboard"
        >
          <span class="btn-icon">{{ copied ? '✓' : '📋' }}</span>
          {{ copied ? 'Copied!' : 'Copy Body' }}
=======
          :class="{ 'btn-success': copied }"
          :disabled="disabled || !body"
          title="Copy email body to clipboard"
        >
          {{ copied ? '✓ Copied!' : '📋 Copy Body' }}
>>>>>>> Stashed changes
        </button>

        <button
          @click="handleSaveDraft"
          class="btn btn-outline"
          :disabled="disabled || !hasContent"
          title="Save as draft for later"
        >
<<<<<<< Updated upstream
          <span class="btn-icon">💾</span>
          Save Draft
        </button>
      </div>

      <!-- Right Side Actions -->
      <div class="action-buttons-right">
        <button
          @click="$emit('cancel')"
          class="btn btn-outline"
          :disabled="disabled"
          title="Cancel and close composer"
=======
          💾 Save Draft
        </button>
      </div>

      <!-- Right Group: Cancel/Mark as Sent -->
      <div class="buttons-right">
        <button
          @click="handleCancel"
          class="btn btn-outline"
          :disabled="disabled"
          title="Cancel and close"
>>>>>>> Stashed changes
        >
          Cancel
        </button>

        <button
          @click="handleMarkAsSent"
<<<<<<< Updated upstream
          class="btn btn-primary"
=======
          class="btn btn-primary btn-teal"
>>>>>>> Stashed changes
          :disabled="disabled || !isValid || loading"
          title="Mark this email as manually sent"
        >
          <span v-if="loading" class="btn-spinner"></span>
<<<<<<< Updated upstream
          <span v-else class="btn-icon">✓</span>
          Mark as Sent
=======
          <span v-else>✓ Mark as Sent</span>
>>>>>>> Stashed changes
        </button>
      </div>
    </template>

    <!-- Campaign Mode -->
    <template v-else>
<<<<<<< Updated upstream
      <!-- Left Side Actions -->
      <div class="action-buttons-left">
=======
      <!-- Left Group -->
      <div class="buttons-left">
>>>>>>> Stashed changes
        <button
          @click="handleSaveDraft"
          class="btn btn-outline"
          :disabled="disabled"
          title="Save campaign setup as draft"
        >
<<<<<<< Updated upstream
          <span class="btn-icon">💾</span>
          Save Draft
        </button>
      </div>

      <!-- Right Side Actions -->
      <div class="action-buttons-right">
        <button
          @click="$emit('cancel')"
          class="btn btn-outline"
          :disabled="disabled"
          title="Cancel and close composer"
=======
          💾 Save Draft
        </button>
      </div>

      <!-- Right Group -->
      <div class="buttons-right">
        <button
          @click="handleCancel"
          class="btn btn-outline"
          :disabled="disabled"
          title="Cancel and close"
>>>>>>> Stashed changes
        >
          Cancel
        </button>

        <button
          @click="handleStartCampaign"
<<<<<<< Updated upstream
          class="btn btn-primary"
=======
          class="btn btn-primary btn-teal"
>>>>>>> Stashed changes
          :disabled="disabled || !isCampaignValid || loading"
          title="Start the email campaign"
        >
          <span v-if="loading" class="btn-spinner"></span>
<<<<<<< Updated upstream
          <span v-else class="btn-icon">▶</span>
          Start Campaign
          <span v-if="customizedCount > 0" class="customized-badge">{{ customizedCount }} customized</span>
=======
          <span v-else>▶ Start Campaign</span>
>>>>>>> Stashed changes
        </button>
      </div>
    </template>
  </div>
</template>

<script setup>
/**
 * ActionButtonsBar Component
 *
 * Action buttons for email composition - supports both single email
 * and campaign modes with appropriate actions for each.
 *
 * Single Email Mode:
 * - Open in Email (mailto: link)
 * - Copy Body (clipboard)
 * - Save Draft
 * - Mark as Sent (manual tracking)
 *
 * Campaign Mode:
 * - Save Draft
 * - Start Campaign
 *
 * Design based on message-tab-complete.jsx reference.
 *
 * @package ShowAuthority
 * @since 5.4.0
 * @updated 2025-12-30
 */

import { ref, computed } from 'vue'

const props = defineProps({
  /**
   * Current mode: 'single' or 'campaign'
   */
  mode: {
    type: String,
    default: 'single',
    validator: (value) => ['single', 'campaign'].includes(value)
  },
  /**
   * Recipient email address
   */
  toEmail: {
    type: String,
    default: ''
  },
  /**
   * Recipient name
   */
  toName: {
    type: String,
    default: ''
  },
  /**
   * Email subject
   */
  subject: {
    type: String,
    default: ''
  },
  /**
   * Email body content
   */
  body: {
    type: String,
    default: ''
  },
  /**
   * Selected sequence ID (for campaign mode)
   */
  sequenceId: {
    type: [Number, String],
    default: null
  },
  /**
   * Whether buttons should be disabled
   */
  disabled: {
    type: Boolean,
    default: false
  },
  /**
   * Whether an action is in progress
   */
  loading: {
    type: Boolean,
    default: false
  },
  /**
   * Number of customized campaign steps
   */
  customizedCount: {
    type: Number,
    default: 0
  }
})

const emit = defineEmits([
  'open-in-email',
  'copy-body',
  'save-draft',
  'mark-as-sent',
  'start-campaign',
  'cancel'
])

// State for copy feedback
const copied = ref(false)

// Validation for single email
const isValid = computed(() => {
  return props.toEmail?.trim() && props.subject?.trim() && props.body?.trim()
})

// Check if there's any content worth saving
const hasContent = computed(() => {
  return props.subject?.trim() || props.body?.trim()
})

// Validation for campaign mode
const isCampaignValid = computed(() => {
  return props.toEmail?.trim() && props.sequenceId
})

// Handle Open in Email (mailto)
function handleOpenInEmail() {
  const subject = encodeURIComponent(props.subject || '')
  const body = encodeURIComponent(props.body || '')
  const mailto = `mailto:${props.toEmail}?subject=${subject}&body=${body}`
  // Use location.href for mailto to open email client without disrupting the app
  window.location.href = mailto
  emit('open-in-email')
}

// Handle Copy Body
async function handleCopyBody() {
  if (!props.body) return

  try {
    await navigator.clipboard.writeText(props.body)
    copied.value = true
    setTimeout(() => {
      copied.value = false
    }, 2000)
    emit('copy-body')
  } catch (err) {
    console.error('Failed to copy:', err)
  }
}

// Handle Save Draft
function handleSaveDraft() {
  emit('save-draft')
}

// Handle Mark as Sent
function handleMarkAsSent() {
  if (!isValid.value) return
  emit('mark-as-sent')
}

// Handle Start Campaign
function handleStartCampaign() {
  if (!isCampaignValid.value) return
  emit('start-campaign')
}
</script>

<style scoped>
.action-buttons-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 0;
  border-top: 1px solid var(--color-border, #e5e7eb);
  flex-wrap: wrap;
  gap: 12px;
}

.action-buttons-left,
.action-buttons-right {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 8px 14px;
  font-size: 13px;
  font-weight: 500;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.2s ease;
  white-space: nowrap;
}

.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.btn-outline {
  background: var(--color-background, #fff);
  border: 1px solid var(--color-border, #e5e7eb);
  color: var(--color-text-primary, #1a1a1a);
}

.btn-outline:hover:not(:disabled) {
  background: var(--color-surface, #f8f9fa);
  border-color: var(--color-text-tertiary, #9ca3af);
}

.btn-primary {
  background: #0d9488;
  color: white;
}

.btn-primary:hover:not(:disabled) {
  background: #0f766e;
}

.btn-icon {
  flex-shrink: 0;
}

.btn-copied {
  border-color: #86efac;
  background: #f0fdf4;
  color: #16a34a;
}

.customized-badge {
  margin-left: 6px;
  padding: 2px 8px;
  background: rgba(255, 255, 255, 0.2);
  border-radius: 4px;
  font-size: 11px;
}

.btn-spinner {
  width: 14px;
  height: 14px;
  border: 2px solid rgba(255, 255, 255, 0.3);
  border-top-color: white;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

/* Responsive adjustments */
@media (max-width: 640px) {
  .action-buttons-bar {
    flex-direction: column;
    align-items: stretch;
  }

  .action-buttons-left,
  .action-buttons-right {
    width: 100%;
    justify-content: center;
  }

  .btn {
    flex: 1;
    min-width: 0;
    justify-content: center;
  }
}
</style>
