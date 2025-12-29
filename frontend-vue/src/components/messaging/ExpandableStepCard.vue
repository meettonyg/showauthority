<template>
  <div class="step-card" :class="{ expanded: expanded }">
    <!-- Collapsed Header -->
    <button class="step-header" @click="$emit('toggle')">
      <div class="step-badge">{{ stepNumber }}</div>
      <div class="step-info">
        <span class="step-name">{{ stepName }}</span>
        <span v-if="step.delay_days || step.delay" class="step-delay">
          {{ formatDelay(step.delay_days || step.delay) }}
        </span>
      </div>
      <svg
        class="expand-icon"
        :class="{ rotated: expanded }"
        width="16"
        height="16"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
      >
        <polyline points="6 9 12 15 18 9"></polyline>
      </svg>
    </button>

    <!-- Expanded Content with CSS Grid transition for natural height -->
    <div class="step-content-wrapper" :class="{ expanded: expanded }">
      <div class="step-content">
        <!-- Step Toolbar -->
        <div class="step-toolbar">
          <!-- Preview/Edit Toggle -->
          <div class="preview-toggle">
            <button
              :class="{ active: localPreviewMode }"
              @click="localPreviewMode = true"
            >
              Preview
            </button>
            <button
              :class="{ active: !localPreviewMode }"
              @click="localPreviewMode = false"
            >
              Template
            </button>
          </div>

          <!-- Edit Button -->
          <button
            v-if="editable && !isEditing"
            class="edit-btn"
            @click="startEditing"
          >
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
              <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
            </svg>
            Edit Step
          </button>
        </div>

        <!-- Subject & Body -->
        <div class="step-email">
          <!-- Subject -->
          <div class="email-field">
            <label class="field-label">Subject</label>
            <template v-if="isEditing">
              <input
                v-model="editedSubject"
                type="text"
                class="field-input"
                placeholder="Email subject..."
              />
            </template>
            <template v-else>
              <div class="field-display">
                {{ localPreviewMode ? resolvedSubject : step.subject }}
              </div>
            </template>
          </div>

          <!-- Body -->
          <div class="email-field">
            <label class="field-label">Body</label>
            <template v-if="isEditing">
              <textarea
                v-model="editedBody"
                class="field-textarea"
                rows="8"
                placeholder="Email body..."
              ></textarea>
            </template>
            <template v-else>
              <div class="field-display body-display">
                {{ localPreviewMode ? resolvedBody : step.body_html }}
              </div>
            </template>
          </div>
        </div>

        <!-- Edit Mode Actions -->
        <div v-if="isEditing" class="step-actions">
          <button class="action-btn cancel-btn" @click="cancelEditing">
            Cancel
          </button>
          <button class="action-btn ai-btn" @click="showAIPanel = true">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="10"></circle>
              <path d="M12 16v-4"></path>
              <path d="M12 8h.01"></path>
            </svg>
            Refine with AI
          </button>
          <button class="action-btn save-btn" @click="openSaveModal('update')">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
              <polyline points="17 21 17 13 7 13 7 21"></polyline>
              <polyline points="7 3 7 8 15 8"></polyline>
            </svg>
            Save to Template
          </button>
          <button class="action-btn new-btn" @click="openSaveModal('new')">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
              <polyline points="14 2 14 8 20 8"></polyline>
              <line x1="12" y1="18" x2="12" y2="12"></line>
              <line x1="9" y1="15" x2="15" y2="15"></line>
            </svg>
            Save as New
          </button>
        </div>

        <!-- Local Edit Notice -->
        <div v-if="isEditing" class="edit-notice">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="16" x2="12" y2="12"></line>
            <line x1="12" y1="8" x2="12.01" y2="8"></line>
          </svg>
          <span>Changes apply to this campaign only.</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * ExpandableStepCard Component
 *
 * Individual campaign step card with expand/collapse and edit capabilities.
 * Supports preview mode for variable resolution and inline editing.
 *
 * Design Decision: Edits are LOCAL to this campaign only (Option A from plan).
 * Users must explicitly "Save as New Template" to create reusable templates.
 *
 * @package ShowAuthority
 * @since 5.4.0
 */

import { ref, computed, watch } from 'vue'
import { resolveVariables } from '../../utils/variableResolver'

const props = defineProps({
  /**
   * Step data object
   */
  step: {
    type: Object,
    required: true
  },
  /**
   * Step number (1-indexed)
   */
  stepNumber: {
    type: Number,
    required: true
  },
  /**
   * Variables data for preview resolution
   */
  variables: {
    type: Object,
    default: () => ({})
  },
  /**
   * Whether this step is expanded
   */
  expanded: {
    type: Boolean,
    default: false
  },
  /**
   * Whether in preview mode (from parent)
   */
  previewMode: {
    type: Boolean,
    default: false
  },
  /**
   * Whether editing is allowed
   */
  editable: {
    type: Boolean,
    default: true
  }
})

const emit = defineEmits([
  'toggle',
  'edit',
  'save-template'
])

// Local state
const isEditing = ref(false)
const localPreviewMode = ref(true)
const showAIPanel = ref(false)

// Edited content
const editedSubject = ref('')
const editedBody = ref('')

// Step name fallback
const stepName = computed(() => {
  return props.step.name || props.step.step_name || `Step ${props.stepNumber}`
})

// Resolved subject with variables replaced
const resolvedSubject = computed(() => {
  const subject = isEditing.value ? editedSubject.value : props.step.subject
  return resolveVariables(subject || '', props.variables)
})

// Resolved body with variables replaced
const resolvedBody = computed(() => {
  const body = isEditing.value ? editedBody.value : props.step.body_html
  return resolveVariables(body || '', props.variables)
})

// Sync local preview mode with parent prop
watch(() => props.previewMode, (newVal) => {
  localPreviewMode.value = newVal
}, { immediate: true })

// Start editing this step
function startEditing() {
  editedSubject.value = props.step.subject || ''
  editedBody.value = props.step.body_html || ''
  isEditing.value = true
}

// Cancel editing and reset
function cancelEditing() {
  isEditing.value = false
  editedSubject.value = ''
  editedBody.value = ''
}

// Open save template modal
function openSaveModal(type) {
  emit('save-template', {
    type, // 'update' or 'new'
    stepNumber: props.stepNumber,
    subject: editedSubject.value,
    body_html: editedBody.value,
    original_template_id: props.step.template_id
  })
}

// Format delay for display
function formatDelay(delay) {
  if (!delay) return ''

  // Handle numeric days
  if (typeof delay === 'number') {
    if (delay === 0) return 'Immediate'
    if (delay === 1) return '1 day later'
    return `${delay} days later`
  }

  // Handle string format (e.g., "3 days")
  if (typeof delay === 'string') {
    if (delay === '0' || delay === 'immediate') return 'Immediate'
    return delay
  }

  return ''
}
</script>

<style scoped>
.step-card {
  border-bottom: 1px solid var(--color-border, #e5e7eb);
}

.step-card:last-child {
  border-bottom: none;
}

/* Header */
.step-header {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 16px;
  background: none;
  border: none;
  cursor: pointer;
  text-align: left;
  transition: background 0.2s;
}

.step-header:hover {
  background: var(--color-surface, #f8f9fa);
}

.step-badge {
  width: 24px;
  height: 24px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-primary, #6366f1);
  color: white;
  font-size: 12px;
  font-weight: 600;
  border-radius: 50%;
  flex-shrink: 0;
}

.step-info {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}

.step-name {
  font-size: 14px;
  font-weight: 500;
  color: var(--color-text-primary, #1a1a1a);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.step-delay {
  font-size: 12px;
  color: var(--color-text-secondary, #6b7280);
  white-space: nowrap;
}

.expand-icon {
  color: var(--color-text-tertiary, #9ca3af);
  flex-shrink: 0;
  transition: transform 0.2s;
}

.expand-icon.rotated {
  transform: rotate(180deg);
}

/* Content */
.step-content {
  padding: 16px;
  background: var(--color-surface, #f8f9fa);
  border-top: 1px solid var(--color-border, #e5e7eb);
}

/* Toolbar */
.step-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}

.preview-toggle {
  display: inline-flex;
  background: var(--color-background, #fff);
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 6px;
  padding: 2px;
}

.preview-toggle button {
  padding: 4px 10px;
  font-size: 12px;
  font-weight: 500;
  border: none;
  border-radius: 4px;
  background: transparent;
  color: var(--color-text-secondary, #6b7280);
  cursor: pointer;
  transition: all 0.2s ease;
}

.preview-toggle button:hover {
  color: var(--color-text-primary, #1a1a1a);
}

.preview-toggle button.active {
  background: var(--color-primary, #6366f1);
  color: white;
}

.edit-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  font-size: 12px;
  font-weight: 500;
  background: var(--color-background, #fff);
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 6px;
  color: var(--color-text-primary, #1a1a1a);
  cursor: pointer;
  transition: all 0.2s;
}

.edit-btn:hover {
  background: var(--color-surface, #f8f9fa);
  border-color: var(--color-text-tertiary, #9ca3af);
}

/* Email Fields */
.step-email {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.email-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.field-label {
  font-size: 12px;
  font-weight: 500;
  color: var(--color-text-secondary, #6b7280);
}

.field-display {
  padding: 10px 12px;
  font-size: 14px;
  line-height: 1.5;
  background: var(--color-background, #fff);
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 6px;
  color: var(--color-text-primary, #1a1a1a);
}

.body-display {
  min-height: 120px;
  white-space: pre-wrap;
}

.field-input,
.field-textarea {
  width: 100%;
  padding: 10px 12px;
  font-size: 14px;
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 6px;
  background: var(--color-background, #fff);
  color: var(--color-text-primary, #1a1a1a);
  transition: border-color 0.2s, box-shadow 0.2s;
}

.field-input:focus,
.field-textarea:focus {
  outline: none;
  border-color: var(--color-primary, #6366f1);
  box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.field-textarea {
  resize: vertical;
  min-height: 120px;
  font-family: inherit;
  line-height: 1.5;
}

/* Edit Actions */
.step-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 16px;
  padding-top: 16px;
  border-top: 1px solid var(--color-border, #e5e7eb);
}

.action-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 12px;
  font-size: 13px;
  font-weight: 500;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.2s;
}

.cancel-btn {
  background: var(--color-background, #fff);
  border: 1px solid var(--color-border, #e5e7eb);
  color: var(--color-text-primary, #1a1a1a);
}

.cancel-btn:hover {
  background: var(--color-surface, #f8f9fa);
}

.ai-btn {
  background: var(--color-primary-light, #eef2ff);
  color: var(--color-primary, #6366f1);
}

.ai-btn:hover {
  background: var(--color-primary, #6366f1);
  color: white;
}

.save-btn {
  background: var(--color-background, #fff);
  border: 1px solid var(--color-primary, #6366f1);
  color: var(--color-primary, #6366f1);
}

.save-btn:hover {
  background: var(--color-primary, #6366f1);
  color: white;
}

.new-btn {
  background: var(--color-primary, #6366f1);
  color: white;
}

.new-btn:hover {
  background: var(--color-primary-dark, #4f46e5);
}

/* Edit Notice */
.edit-notice {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 12px;
  padding: 8px 12px;
  background: var(--color-warning-bg, #fffbeb);
  border: 1px solid var(--color-warning-border, #fde68a);
  border-radius: 6px;
  color: var(--color-warning, #d97706);
  font-size: 12px;
}

/* Expand Transition using CSS Grid for natural height animation */
.step-content-wrapper {
  display: grid;
  grid-template-rows: 0fr;
  transition: grid-template-rows 0.2s ease-out;
  overflow: hidden;
}

.step-content-wrapper.expanded {
  grid-template-rows: 1fr;
}

.step-content-wrapper > .step-content {
  min-height: 0;
  overflow: hidden;
}

.step-content-wrapper:not(.expanded) > .step-content {
  padding-top: 0;
  padding-bottom: 0;
  border-top-width: 0;
}
</style>
