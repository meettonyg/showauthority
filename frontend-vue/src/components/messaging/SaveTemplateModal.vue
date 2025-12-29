<template>
  <Teleport to="body">
    <Transition name="modal">
      <div v-if="show" class="modal-overlay" @click.self="handleClose">
        <div class="modal-container">
          <div class="modal-header">
            <h3 class="modal-title">
              {{ isUpdate ? 'Update Template' : 'Save as New Template' }}
            </h3>
            <button class="modal-close" @click="handleClose" :disabled="saving">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
              </svg>
            </button>
          </div>

          <div class="modal-body">
            <!-- Update Mode: Warning about affecting future campaigns -->
            <div v-if="isUpdate" class="warning-box">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                <line x1="12" y1="9" x2="12" y2="13"></line>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
              </svg>
              <div class="warning-content">
                <p class="warning-title">This will update the original template</p>
                <p class="warning-text">
                  Changes will affect all future campaigns using the
                  <strong>"{{ templateName }}"</strong> template.
                </p>
              </div>
            </div>

            <!-- New Template Mode: Name and category input -->
            <template v-else>
              <div class="form-group">
                <label class="form-label">
                  Template Name <span class="required">*</span>
                </label>
                <input
                  ref="nameInputRef"
                  v-model="newName"
                  type="text"
                  class="form-input"
                  placeholder="e.g., Book Author Intro v2"
                  :disabled="saving"
                  @keyup.enter="handleSave"
                />
              </div>

              <div class="form-group">
                <label class="form-label">Category</label>
                <select v-model="category" class="form-input" :disabled="saving">
                  <option value="Custom">Custom</option>
                  <option value="Cold Outreach">Cold Outreach</option>
                  <option value="Follow Up">Follow Up</option>
                  <option value="Introduction">Introduction</option>
                  <option value="Thank You">Thank You</option>
                </select>
              </div>
            </template>

            <!-- Preview of what will be saved -->
            <div class="preview-section">
              <h4 class="preview-title">Preview</h4>
              <div class="preview-content">
                <div class="preview-field">
                  <span class="preview-label">Subject:</span>
                  <span class="preview-value">{{ subject || '(No subject)' }}</span>
                </div>
                <div class="preview-field preview-body">
                  <span class="preview-label">Body:</span>
                  <div class="preview-value">{{ truncatedBody }}</div>
                </div>
              </div>
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
              :disabled="saving"
            >
              Cancel
            </button>
            <button
              class="btn btn-primary"
              @click="handleSave"
              :disabled="saving || (!isUpdate && !newName.trim())"
            >
              <span v-if="saving" class="btn-spinner"></span>
              {{ isUpdate ? 'Update Template' : 'Create Template' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
/**
 * SaveTemplateModal Component
 *
 * Modal dialog for saving email templates.
 * Supports two modes:
 * - Update: Updates an existing template (with warning about affecting future campaigns)
 * - New: Creates a new template with custom name and category
 *
 * @package ShowAuthority
 * @since 5.4.0
 */

import { ref, computed, watch, nextTick } from 'vue'

const props = defineProps({
  /**
   * Whether the modal is visible
   */
  show: {
    type: Boolean,
    default: false
  },
  /**
   * Save mode: 'update' or 'new'
   */
  mode: {
    type: String,
    default: 'new',
    validator: (value) => ['update', 'new'].includes(value)
  },
  /**
   * Original template ID (for update mode)
   */
  templateId: {
    type: [Number, String],
    default: null
  },
  /**
   * Original template name (for update mode display)
   */
  templateName: {
    type: String,
    default: ''
  },
  /**
   * Email subject to save
   */
  subject: {
    type: String,
    default: ''
  },
  /**
   * Email body to save
   */
  bodyHtml: {
    type: String,
    default: ''
  },
  /**
   * Whether save is in progress
   */
  saving: {
    type: Boolean,
    default: false
  },
  /**
   * Error message to display
   */
  error: {
    type: String,
    default: null
  }
})

const emit = defineEmits(['close', 'save'])

// Form state
const newName = ref('')
const category = ref('Custom')
const nameInputRef = ref(null)

// Computed
const isUpdate = computed(() => props.mode === 'update')

const truncatedBody = computed(() => {
  if (!props.bodyHtml) return '(No body)'
  if (props.bodyHtml.length <= 200) return props.bodyHtml
  return props.bodyHtml.substring(0, 200) + '...'
})

// Focus name input when modal opens in 'new' mode
watch(() => props.show, async (isShown) => {
  if (isShown && !isUpdate.value) {
    newName.value = ''
    category.value = 'Custom'
    await nextTick()
    nameInputRef.value?.focus()
  }
})

// Handle close
function handleClose() {
  if (props.saving) return
  emit('close')
}

// Handle save
function handleSave() {
  if (props.saving) return

  if (isUpdate.value) {
    emit('save', {
      type: 'update',
      template_id: props.templateId,
      subject: props.subject,
      body_html: props.bodyHtml
    })
  } else {
    if (!newName.value.trim()) return

    emit('save', {
      type: 'new',
      name: newName.value.trim(),
      category: category.value,
      subject: props.subject,
      body_html: props.bodyHtml
    })
  }
}
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
  max-width: 480px;
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
  padding: 16px 20px;
  border-bottom: 1px solid var(--color-border, #e5e7eb);
}

.modal-title {
  font-size: 16px;
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
  padding: 20px;
}

/* Warning Box */
.warning-box {
  display: flex;
  gap: 12px;
  padding: 14px;
  background: var(--color-warning-bg, #fffbeb);
  border: 1px solid var(--color-warning-border, #fde68a);
  border-radius: 8px;
  margin-bottom: 16px;
}

.warning-box svg {
  flex-shrink: 0;
  color: var(--color-warning, #d97706);
}

.warning-content {
  flex: 1;
}

.warning-title {
  margin: 0 0 4px 0;
  font-size: 14px;
  font-weight: 600;
  color: var(--color-warning-dark, #92400e);
}

.warning-text {
  margin: 0;
  font-size: 13px;
  color: var(--color-warning, #d97706);
  line-height: 1.5;
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

/* Preview Section */
.preview-section {
  margin-top: 20px;
  padding-top: 16px;
  border-top: 1px solid var(--color-border, #e5e7eb);
}

.preview-title {
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: var(--color-text-secondary, #6b7280);
  margin: 0 0 12px 0;
}

.preview-content {
  background: var(--color-surface, #f8f9fa);
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 6px;
  padding: 12px;
}

.preview-field {
  margin-bottom: 8px;
}

.preview-field:last-child {
  margin-bottom: 0;
}

.preview-label {
  font-size: 12px;
  font-weight: 500;
  color: var(--color-text-secondary, #6b7280);
  display: block;
  margin-bottom: 4px;
}

.preview-value {
  font-size: 13px;
  color: var(--color-text-primary, #1a1a1a);
  line-height: 1.5;
}

.preview-body .preview-value {
  max-height: 100px;
  overflow-y: auto;
  white-space: pre-wrap;
  word-break: break-word;
}

/* Error */
.form-error {
  padding: 12px 16px;
  background: var(--color-error-bg, #fef2f2);
  border: 1px solid var(--color-error-border, #fecaca);
  border-radius: 6px;
  color: var(--color-error, #ef4444);
  font-size: 14px;
  margin-top: 16px;
}

/* Footer */
.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  padding: 16px 20px;
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
