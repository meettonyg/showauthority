<template>
  <Teleport to="body">
    <Transition name="modal">
      <div v-if="show" class="modal-overlay" @click.self="handleClose">
        <div class="modal-container">
          <div class="modal-header">
            <h2 class="modal-title">{{ modalTitle }}</h2>
            <button class="modal-close" @click="handleClose" :disabled="sending">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
              </svg>
            </button>
          </div>

          <div class="modal-body">
            <!-- Mode Toggle (only show if sequences available) -->
            <div v-if="hasSequences" class="mode-toggle">
              <button
                class="mode-btn"
                :class="{ active: mode === 'email' }"
                @click="mode = 'email'"
                :disabled="sending"
              >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                  <polyline points="22,6 12,13 2,6"></polyline>
                </svg>
                Single Email
              </button>
              <button
                class="mode-btn"
                :class="{ active: mode === 'campaign' }"
                @click="mode = 'campaign'"
                :disabled="sending"
              >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                  <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
                Start Campaign
              </button>
            </div>

            <!-- SINGLE EMAIL MODE -->
            <template v-if="mode === 'email'">
              <!-- Template Selector -->
              <TemplateSelector
                v-if="templates.length > 0"
                v-model="form.templateId"
                :templates="templates"
                :disabled="sending"
                @update:modelValue="applyTemplate"
              />

              <!-- To Email -->
              <div class="form-group">
                <label class="form-label">
                  To <span class="required">*</span>
                </label>
                <input
                  v-model="form.toEmail"
                  type="email"
                  class="form-input"
                  placeholder="recipient@example.com"
                  :disabled="sending"
                  required
                />
              </div>

              <!-- To Name -->
              <div class="form-group">
                <label class="form-label">Recipient Name</label>
                <input
                  v-model="form.toName"
                  type="text"
                  class="form-input"
                  placeholder="John Doe"
                  :disabled="sending"
                />
              </div>

              <!-- Subject -->
              <div class="form-group">
                <label class="form-label">
                  Subject <span class="required">*</span>
                </label>
                <input
                  v-model="form.subject"
                  type="text"
                  class="form-input"
                  placeholder="Subject line..."
                  :disabled="sending"
                  required
                />
              </div>

              <!-- Body -->
              <div class="form-group">
                <label class="form-label">
                  Message <span class="required">*</span>
                </label>
                <textarea
                  v-model="form.body"
                  class="form-input form-textarea"
                  rows="8"
                  placeholder="Write your message here..."
                  :disabled="sending"
                  required
                ></textarea>
              </div>
            </template>

            <!-- CAMPAIGN MODE -->
            <template v-else-if="mode === 'campaign'">
              <!-- Sequence Selector -->
              <SequenceSelector
                v-model="form.sequenceId"
                :sequences="sequences"
                :disabled="sending"
                :loading="sequencesLoading"
              />

              <!-- Recipient Email -->
              <div class="form-group">
                <label class="form-label">
                  Recipient Email <span class="required">*</span>
                </label>
                <input
                  v-model="form.toEmail"
                  type="email"
                  class="form-input"
                  placeholder="recipient@example.com"
                  :disabled="sending"
                  required
                />
              </div>

              <!-- Recipient Name -->
              <div class="form-group">
                <label class="form-label">Recipient Name</label>
                <input
                  v-model="form.toName"
                  type="text"
                  class="form-input"
                  placeholder="John Doe"
                  :disabled="sending"
                />
              </div>

              <!-- Campaign Info Box -->
              <div v-if="selectedSequence" class="campaign-info">
                <div class="info-icon">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="16" x2="12" y2="12"></line>
                    <line x1="12" y1="8" x2="12.01" y2="8"></line>
                  </svg>
                </div>
                <div class="info-content">
                  <p class="info-title">Campaign will send {{ selectedSequence.total_steps }} emails automatically</p>
                  <p class="info-text">
                    The first email sends immediately. Subsequent emails are scheduled based on the sequence timing.
                    Template variables like {podcast_name} and {host_name} will be replaced automatically.
                  </p>
                </div>
              </div>
            </template>

            <!-- Error Message -->
            <div v-if="error" class="form-error">
              {{ error }}
            </div>
          </div>

          <div class="modal-footer">
            <button
              class="btn btn-secondary"
              @click="handleClose"
              :disabled="sending"
            >
              Cancel
            </button>
            <button
              class="btn btn-primary"
              @click="handleAction"
              :disabled="sending || !isValid"
            >
              <span v-if="sending" class="btn-spinner"></span>
              {{ actionButtonText }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
/**
 * MessageComposer Component
 *
 * Modal dialog for composing and sending emails or starting campaigns.
 * Supports dual mode: Single Email or Campaign (sequence-based).
 *
 * @package ShowAuthority
 * @since 5.0.0
 * @updated 5.2.0 - Added dual mode for campaign/sequence support
 */

import { ref, reactive, computed, watch, onMounted, onUnmounted } from 'vue'
import TemplateSelector from './TemplateSelector.vue'
import SequenceSelector from './SequenceSelector.vue'

const props = defineProps({
  show: {
    type: Boolean,
    default: false
  },
  templates: {
    type: Array,
    default: () => []
  },
  sequences: {
    type: Array,
    default: () => []
  },
  sequencesLoading: {
    type: Boolean,
    default: false
  },
  defaultEmail: {
    type: String,
    default: ''
  },
  defaultName: {
    type: String,
    default: ''
  },
  sending: {
    type: Boolean,
    default: false
  },
  error: {
    type: String,
    default: null
  }
})

const emit = defineEmits(['close', 'send', 'start-campaign'])

// Mode: 'email' or 'campaign'
const mode = ref('email')

const form = reactive({
  templateId: null,
  sequenceId: null,
  toEmail: '',
  toName: '',
  subject: '',
  body: ''
})

// Check if sequences are available
const hasSequences = computed(() => {
  return props.sequences && props.sequences.length > 0
})

// Get selected sequence object
const selectedSequence = computed(() => {
  if (!form.sequenceId) return null
  return props.sequences.find(s => s.id === form.sequenceId) || null
})

// Dynamic modal title
const modalTitle = computed(() => {
  return mode.value === 'campaign' ? 'Start Campaign' : 'Compose Email'
})

// Dynamic action button text
const actionButtonText = computed(() => {
  if (props.sending) {
    return mode.value === 'campaign' ? 'Starting...' : 'Sending...'
  }
  return mode.value === 'campaign' ? 'Start Campaign' : 'Send Email'
})

// Validation
const isValid = computed(() => {
  if (mode.value === 'campaign') {
    return form.toEmail?.trim() && form.sequenceId
  }
  return form.toEmail?.trim() && form.subject?.trim() && form.body?.trim()
})

// Pre-fill defaults when modal opens
watch(() => props.show, (isShown) => {
  if (isShown) {
    form.toEmail = props.defaultEmail || ''
    form.toName = props.defaultName || ''
  }
})

// Apply template content
function applyTemplate(templateId) {
  if (!templateId) return

  const template = props.templates.find(t => t.id === templateId)
  if (template) {
    form.subject = template.subject || ''
    form.body = template.body_html || ''
  }
}

// Handle close
function handleClose() {
  if (props.sending) return
  resetForm()
  emit('close')
}

// Handle action (send email or start campaign)
function handleAction() {
  if (!isValid.value || props.sending) return

  if (mode.value === 'campaign') {
    emit('start-campaign', {
      sequence_id: form.sequenceId,
      recipient_email: form.toEmail.trim(),
      recipient_name: form.toName.trim()
    })
  } else {
    emit('send', {
      to_email: form.toEmail.trim(),
      to_name: form.toName.trim(),
      subject: form.subject.trim(),
      body: form.body.trim(),
      template_id: form.templateId
    })
  }
}

// Reset form
function resetForm() {
  mode.value = 'email'
  form.templateId = null
  form.sequenceId = null
  form.toEmail = ''
  form.toName = ''
  form.subject = ''
  form.body = ''
}

// Handle Escape key to close modal
function handleKeydown(e) {
  if (e.key === 'Escape' && props.show) {
    handleClose()
  }
}

onMounted(() => {
  window.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  window.removeEventListener('keydown', handleKeydown)
})

// Expose reset for parent
defineExpose({ resetForm })
</script>

<style scoped>
/* Modal Overlay */
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  z-index: 1000;
}

/* Modal Container */
.modal-container {
  width: 100%;
  max-width: 560px;
  max-height: 90vh;
  background: var(--color-background, #fff);
  border-radius: 12px;
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

/* Header */
.modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 20px 24px;
  border-bottom: 1px solid var(--color-border, #e5e7eb);
}

.modal-title {
  font-size: 18px;
  font-weight: 600;
  color: var(--color-text-primary, #1a1a1a);
  margin: 0;
}

.modal-close {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border: none;
  background: transparent;
  border-radius: 6px;
  color: var(--color-text-secondary, #6b7280);
  cursor: pointer;
  transition: background 0.2s, color 0.2s;
}

.modal-close:hover:not(:disabled) {
  background: var(--color-surface, #f8f9fa);
  color: var(--color-text-primary, #1a1a1a);
}

.modal-close:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* Body */
.modal-body {
  flex: 1;
  overflow-y: auto;
  padding: 24px;
}

/* Form */
.form-group {
  margin-bottom: 16px;
}

.form-label {
  display: block;
  font-size: 14px;
  font-weight: 500;
  color: var(--color-text-primary, #1a1a1a);
  margin-bottom: 6px;
}

.required {
  color: var(--color-error, #ef4444);
}

.form-input {
  width: 100%;
  padding: 10px 12px;
  font-size: 14px;
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 6px;
  background: var(--color-background, #fff);
  color: var(--color-text-primary, #1a1a1a);
  transition: border-color 0.2s, box-shadow 0.2s;
}

.form-input:focus {
  outline: none;
  border-color: var(--color-primary, #6366f1);
  box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.form-input:disabled {
  background: var(--color-surface, #f8f9fa);
  cursor: not-allowed;
}

.form-textarea {
  resize: vertical;
  min-height: 150px;
  font-family: inherit;
  line-height: 1.5;
}

.form-error {
  padding: 12px 16px;
  background: var(--color-error-bg, #fef2f2);
  border: 1px solid var(--color-error-border, #fecaca);
  border-radius: 6px;
  color: var(--color-error, #ef4444);
  font-size: 14px;
}

/* Footer */
.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  padding: 16px 24px;
  border-top: 1px solid var(--color-border, #e5e7eb);
  background: var(--color-surface, #f8f9fa);
}

/* Buttons */
.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 10px 20px;
  font-size: 14px;
  font-weight: 500;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  transition: background 0.2s, opacity 0.2s;
}

.btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.btn-secondary {
  background: var(--color-background, #fff);
  border: 1px solid var(--color-border, #e5e7eb);
  color: var(--color-text-primary, #1a1a1a);
}

.btn-secondary:hover:not(:disabled) {
  background: var(--color-surface, #f8f9fa);
}

.btn-primary {
  background: var(--color-primary, #6366f1);
  color: white;
}

.btn-primary:hover:not(:disabled) {
  background: var(--color-primary-dark, #4f46e5);
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

/* Transition */
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.2s ease;
}

.modal-enter-active .modal-container,
.modal-leave-active .modal-container {
  transition: transform 0.2s ease;
}

.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}

.modal-enter-from .modal-container,
.modal-leave-to .modal-container {
  transform: scale(0.95);
}

/* Mode Toggle */
.mode-toggle {
  display: flex;
  gap: 8px;
  margin-bottom: 20px;
  padding: 4px;
  background: var(--color-surface, #f8f9fa);
  border-radius: 8px;
}

.mode-btn {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 10px 16px;
  font-size: 14px;
  font-weight: 500;
  border: none;
  border-radius: 6px;
  background: transparent;
  color: var(--color-text-secondary, #6b7280);
  cursor: pointer;
  transition: all 0.2s ease;
}

.mode-btn:hover:not(:disabled) {
  color: var(--color-text-primary, #1a1a1a);
}

.mode-btn.active {
  background: var(--color-background, #fff);
  color: var(--color-primary, #6366f1);
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.mode-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* Campaign Info Box */
.campaign-info {
  display: flex;
  gap: 12px;
  padding: 14px;
  background: var(--color-info-bg, #eff6ff);
  border: 1px solid var(--color-info-border, #bfdbfe);
  border-radius: 8px;
  margin-top: 16px;
}

.info-icon {
  flex-shrink: 0;
  color: var(--color-info, #3b82f6);
}

.info-content {
  flex: 1;
}

.info-title {
  margin: 0 0 4px 0;
  font-size: 14px;
  font-weight: 600;
  color: var(--color-info-dark, #1e40af);
}

.info-text {
  margin: 0;
  font-size: 13px;
  color: var(--color-info, #3b82f6);
  line-height: 1.5;
}
</style>
