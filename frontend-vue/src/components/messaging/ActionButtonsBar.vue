<template>
  <div class="action-buttons-bar">
    <!-- Single Email Mode -->
    <template v-if="mode === 'single'">
      <button
        @click="handleOpenInEmail"
        class="btn btn-outline"
        :disabled="disabled || !isValid"
        title="Open in your default email client"
      >
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
          <polyline points="22,6 12,13 2,6"></polyline>
        </svg>
        Open in Email
      </button>

      <button
        @click="handleCopyBody"
        class="btn btn-outline"
        :disabled="disabled || !body"
        title="Copy email body to clipboard"
      >
        <svg v-if="!copied" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
          <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
        </svg>
        <svg v-else width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="20 6 9 17 4 12"></polyline>
        </svg>
        {{ copied ? 'Copied!' : 'Copy Body' }}
      </button>

      <button
        @click="handleSaveDraft"
        class="btn btn-outline"
        :disabled="disabled || !hasContent"
        title="Save as draft for later"
      >
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
          <polyline points="17 21 17 13 7 13 7 21"></polyline>
          <polyline points="7 3 7 8 15 8"></polyline>
        </svg>
        Save Draft
      </button>

      <button
        @click="handleMarkAsSent"
        class="btn btn-primary"
        :disabled="disabled || !isValid || loading"
        title="Mark this email as manually sent"
      >
        <span v-if="loading" class="btn-spinner"></span>
        <svg v-else width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="20 6 9 17 4 12"></polyline>
        </svg>
        Mark as Sent
      </button>
    </template>

    <!-- Campaign Mode -->
    <template v-else>
      <button
        @click="handleSaveDraft"
        class="btn btn-outline"
        :disabled="disabled"
        title="Save campaign setup as draft"
      >
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
          <polyline points="17 21 17 13 7 13 7 21"></polyline>
          <polyline points="7 3 7 8 15 8"></polyline>
        </svg>
        Save Draft
      </button>

      <button
        @click="handleStartCampaign"
        class="btn btn-primary"
        :disabled="disabled || !isCampaignValid || loading"
        title="Start the email campaign"
      >
        <span v-if="loading" class="btn-spinner"></span>
        <svg v-else width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polygon points="5 3 19 12 5 21 5 3"></polygon>
        </svg>
        Start Campaign
      </button>
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
 * @package ShowAuthority
 * @since 5.4.0
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
  }
})

const emit = defineEmits([
  'open-in-email',
  'copy-body',
  'save-draft',
  'mark-as-sent',
  'start-campaign'
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
  gap: 12px;
  padding: 16px 0;
  border-top: 1px solid var(--color-border, #e5e7eb);
  flex-wrap: wrap;
}

.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 10px 16px;
  font-size: 14px;
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
  background: var(--color-primary, #6366f1);
  color: white;
  margin-left: auto;
}

.btn-primary:hover:not(:disabled) {
  background: var(--color-primary-dark, #4f46e5);
}

.btn svg {
  flex-shrink: 0;
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

  .btn {
    justify-content: center;
  }

  .btn-primary {
    margin-left: 0;
    margin-top: 8px;
  }
}
</style>
