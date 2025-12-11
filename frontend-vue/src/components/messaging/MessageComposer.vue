<template>
  <Teleport to="body">
    <Transition name="modal">
      <div v-if="show" class="modal-overlay" @click.self="handleClose">
        <div class="modal-container">
          <div class="modal-header">
            <h2 class="modal-title">Compose Email</h2>
            <button class="modal-close" @click="handleClose" :disabled="sending">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
              </svg>
            </button>
          </div>

          <div class="modal-body">
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
              @click="handleSend"
              :disabled="sending || !isValid"
            >
              <span v-if="sending" class="btn-spinner"></span>
              {{ sending ? 'Sending...' : 'Send Email' }}
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
 * Modal dialog for composing and sending emails.
 *
 * @package ShowAuthority
 * @since 5.0.0
 */

import { ref, reactive, computed, watch } from 'vue'
import TemplateSelector from './TemplateSelector.vue'

const props = defineProps({
  show: {
    type: Boolean,
    default: false
  },
  templates: {
    type: Array,
    default: () => []
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

const emit = defineEmits(['close', 'send'])

const form = reactive({
  templateId: null,
  toEmail: '',
  toName: '',
  subject: '',
  body: ''
})

// Validation
const isValid = computed(() => {
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

// Handle send
function handleSend() {
  if (!isValid.value || props.sending) return

  emit('send', {
    to_email: form.toEmail.trim(),
    to_name: form.toName.trim(),
    subject: form.subject.trim(),
    body: form.body.trim(),
    template_id: form.templateId
  })
}

// Reset form
function resetForm() {
  form.templateId = null
  form.toEmail = ''
  form.toName = ''
  form.subject = ''
  form.body = ''
}

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
</style>
