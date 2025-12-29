<template>
  <div class="inline-composer">
    <!-- Header with close button -->
    <div class="composer-header">
      <h3 class="composer-title">{{ composerTitle }}</h3>
      <button
        class="close-btn"
        @click="handleCancel"
        :disabled="loading"
        title="Close composer"
      >
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <line x1="18" y1="6" x2="6" y2="18"></line>
          <line x1="6" y1="6" x2="18" y2="18"></line>
        </svg>
      </button>
    </div>

    <div class="composer-layout">
      <!-- Main Composer Area -->
      <div class="composer-main">
        <!-- Mode Toggle (only show if sequences available) -->
        <div v-if="hasSequences" class="mode-toggle">
          <button
            class="mode-btn"
            :class="{ active: mode === 'single' }"
            @click="switchMode('single')"
            :disabled="loading"
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
            @click="switchMode('campaign')"
            :disabled="loading"
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
              <polyline points="22 4 12 14.01 9 11.01"></polyline>
            </svg>
            Start Campaign
          </button>
        </div>

        <!-- Preview/Edit Toggle (Single Email Mode Only) -->
        <div v-if="mode === 'single'" class="preview-toggle-wrapper">
          <div class="preview-toggle">
            <button
              :class="{ active: previewMode }"
              @click="previewMode = true"
              :disabled="loading"
            >
              Preview
            </button>
            <button
              :class="{ active: !previewMode }"
              @click="previewMode = false"
              :disabled="loading"
            >
              Template
            </button>
          </div>
        </div>

        <!-- SINGLE EMAIL MODE -->
        <template v-if="mode === 'single'">
          <!-- Template Selector -->
          <TemplateSelector
            v-if="templates.length > 0"
            v-model="form.templateId"
            :templates="templates"
            :disabled="loading"
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
              :disabled="loading"
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
              :disabled="loading"
            />
          </div>

          <!-- Subject -->
          <div class="form-group">
            <label class="form-label">
              Subject <span class="required">*</span>
            </label>
            <input
              ref="subjectInputRef"
              v-model="form.subject"
              type="text"
              class="form-input"
              placeholder="Subject line..."
              :disabled="loading"
              required
              @focus="handleInputFocus('subject')"
            />
            <!-- Preview display -->
            <div v-if="previewMode && hasVariables" class="preview-display subject-preview">
              {{ resolvedSubject }}
            </div>
          </div>

          <!-- Body -->
          <div class="form-group">
            <label class="form-label">
              Message <span class="required">*</span>
            </label>
            <textarea
              v-if="!previewMode"
              ref="bodyInputRef"
              v-model="form.body"
              class="form-input form-textarea"
              rows="8"
              placeholder="Write your message here..."
              :disabled="loading"
              required
              @focus="handleInputFocus('body')"
            ></textarea>
            <!-- Preview display -->
            <div v-else class="preview-display body-preview">
              {{ resolvedBody }}
            </div>
          </div>
        </template>

        <!-- CAMPAIGN MODE -->
        <template v-else-if="mode === 'campaign'">
          <!-- Sequence Selector -->
          <SequenceSelector
            v-model="form.sequenceId"
            :sequences="sequences"
            :disabled="loading"
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
              :disabled="loading"
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
              :disabled="loading"
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

          <!-- Campaign Steps Preview (placeholder for Phase 3) -->
          <CampaignStepsList
            v-if="selectedSequence && selectedSequence.steps"
            :sequence="selectedSequence"
            :variables="variablesData"
            :preview-mode="true"
          />
        </template>

        <!-- Error Message -->
        <div v-if="error" class="form-error">
          {{ error }}
        </div>

        <!-- Action Buttons -->
        <ActionButtonsBar
          :mode="mode"
          :to-email="form.toEmail"
          :to-name="form.toName"
          :subject="form.subject"
          :body="form.body"
          :sequence-id="form.sequenceId"
          :disabled="loading"
          :loading="loading"
          @open-in-email="handleOpenInEmail"
          @copy-body="handleCopyBody"
          @save-draft="handleSaveDraft"
          @mark-as-sent="handleMarkAsSent"
          @start-campaign="handleStartCampaign"
        />
      </div>

      <!-- Variable Sidebar (Single Email Mode Only) -->
      <VariableSidebar
        v-if="showSidebar"
        :variables-data="variablesData"
        :loading="variablesLoading"
        :subject="form.subject"
        :body="form.body"
        @insert="handleInsertVariable"
      />
    </div>
  </div>
</template>

<script setup>
/**
 * InlineComposer Component
 *
 * Inline email composition experience that replaces the modal-based composer.
 * Supports both single email and campaign modes with an embedded variables sidebar.
 *
 * Features:
 * - Inline layout (no modal)
 * - Preview/Edit toggle for variable resolution
 * - Mode switching between Single Email and Campaign
 * - Variable sidebar integration
 * - Action buttons for Open/Copy/Draft/Sent
 *
 * @package ShowAuthority
 * @since 5.4.0
 */

import { ref, reactive, computed, watch, onMounted } from 'vue'
import TemplateSelector from './TemplateSelector.vue'
import SequenceSelector from './SequenceSelector.vue'
import VariableSidebar from './VariableSidebar.vue'
import ActionButtonsBar from './ActionButtonsBar.vue'
import CampaignStepsList from './CampaignStepsList.vue'
import outreachService from '../../services/outreach'
import { resolveVariables } from '../../utils/variableResolver'

const props = defineProps({
  /**
   * Appearance/opportunity ID for fetching personalization variables
   */
  appearanceId: {
    type: Number,
    required: true
  },
  /**
   * Default recipient email
   */
  defaultEmail: {
    type: String,
    default: ''
  },
  /**
   * Default recipient name
   */
  defaultName: {
    type: String,
    default: ''
  },
  /**
   * Available email templates
   */
  templates: {
    type: Array,
    default: () => []
  },
  /**
   * Available campaign sequences
   */
  sequences: {
    type: Array,
    default: () => []
  },
  /**
   * Whether sequences are loading
   */
  sequencesLoading: {
    type: Boolean,
    default: false
  },
  /**
   * External error message to display
   */
  error: {
    type: String,
    default: null
  },
  /**
   * Draft data to resume from
   */
  draftData: {
    type: Object,
    default: null
  }
})

const emit = defineEmits([
  'cancel',
  'email-sent',
  'draft-saved',
  'mark-as-sent',
  'start-campaign',
  'mode-switched'
])

// Mode: 'single' or 'campaign'
const mode = ref('single')

// Preview mode (show resolved variables)
const previewMode = ref(false)

// Loading state
const loading = ref(false)

// Form data
const form = reactive({
  templateId: null,
  sequenceId: null,
  toEmail: '',
  toName: '',
  subject: '',
  body: ''
})

// Variables sidebar state
const variablesData = ref({})
const variablesLoading = ref(false)
const subjectInputRef = ref(null)
const bodyInputRef = ref(null)
const lastFocusedInput = ref(null)

// Check if sequences are available
const hasSequences = computed(() => {
  return props.sequences && props.sequences.length > 0
})

// Check if we have variables data
const hasVariables = computed(() => {
  return variablesData.value?.categories?.length > 0
})

// Show sidebar in single email mode with appearance ID
const showSidebar = computed(() => {
  return mode.value === 'single' && props.appearanceId
})

// Get selected sequence object
const selectedSequence = computed(() => {
  if (!form.sequenceId) return null
  return props.sequences.find(s => s.id === form.sequenceId) || null
})

// Dynamic composer title
const composerTitle = computed(() => {
  return mode.value === 'campaign' ? 'Start Campaign' : 'Compose Email'
})

// Resolved subject with variables replaced
const resolvedSubject = computed(() => {
  if (!form.subject || !hasVariables.value) return form.subject
  return resolveVariables(form.subject, variablesData.value)
})

// Resolved body with variables replaced
const resolvedBody = computed(() => {
  if (!form.body || !hasVariables.value) return form.body
  return resolveVariables(form.body, variablesData.value)
})

// Initialize with defaults
onMounted(() => {
  form.toEmail = props.defaultEmail || ''
  form.toName = props.defaultName || ''

  // Resume from draft if provided
  if (props.draftData) {
    form.subject = props.draftData.subject || ''
    form.body = props.draftData.body_html || ''
    form.templateId = props.draftData.template_id || null
    if (props.draftData.draft_type === 'campaign_step') {
      mode.value = 'campaign'
      form.sequenceId = props.draftData.sequence_id || null
    }
  }

  // Fetch variables
  if (props.appearanceId) {
    fetchVariables()
  }
})

// Watch for appearance ID changes
watch(() => props.appearanceId, (newId) => {
  if (newId) {
    fetchVariables()
  }
})

// Handle mode switch
function switchMode(newMode) {
  if (mode.value === newMode) return
  mode.value = newMode
  emit('mode-switched', newMode)
}

// Fetch personalization variables
async function fetchVariables() {
  if (!props.appearanceId) return

  variablesLoading.value = true
  try {
    const response = await outreachService.getVariables(props.appearanceId)
    variablesData.value = response.data || {}
  } catch (error) {
    console.error('Failed to fetch variables:', error)
    variablesData.value = {}
  } finally {
    variablesLoading.value = false
  }
}

// Apply template content
function applyTemplate(templateId) {
  if (!templateId) return

  const template = props.templates.find(t => t.id === templateId)
  if (template) {
    form.subject = template.subject || ''
    form.body = template.body_html || ''
  }
}

// Handle input focus tracking
function handleInputFocus(inputType) {
  lastFocusedInput.value = inputType
}

// Insert variable tag at cursor position
function handleInsertVariable(tag) {
  const targetRef = lastFocusedInput.value === 'subject' ? subjectInputRef.value : bodyInputRef.value
  const targetField = lastFocusedInput.value === 'subject' ? 'subject' : 'body'

  if (!targetRef) {
    // Default to body if no field was focused
    form.body = form.body + tag
    return
  }

  const input = targetRef
  const start = input.selectionStart || 0
  const end = input.selectionEnd || 0
  const currentValue = form[targetField]

  // Insert tag at cursor position
  form[targetField] = currentValue.substring(0, start) + tag + currentValue.substring(end)

  // Set cursor position after inserted tag
  const newPos = start + tag.length
  setTimeout(() => {
    input.focus()
    input.setSelectionRange(newPos, newPos)
  }, 0)
}

// Handle cancel/close
function handleCancel() {
  if (loading.value) return
  resetForm()
  emit('cancel')
}

// Reset form state
function resetForm() {
  mode.value = 'single'
  previewMode.value = false
  form.templateId = null
  form.sequenceId = null
  form.toEmail = ''
  form.toName = ''
  form.subject = ''
  form.body = ''
  lastFocusedInput.value = null
}

// Handle Open in Email action
function handleOpenInEmail() {
  // Already handled in ActionButtonsBar, just emit for parent
  emit('email-sent', { type: 'opened_in_email' })
}

// Handle Copy Body action
function handleCopyBody() {
  // Already handled in ActionButtonsBar
}

// Handle Save Draft
async function handleSaveDraft() {
  loading.value = true
  try {
    const draftData = {
      draft_type: mode.value === 'campaign' ? 'campaign_step' : 'single_email',
      recipient_email: form.toEmail,
      recipient_name: form.toName,
      subject: form.subject,
      body_html: form.body,
      template_id: form.templateId,
      sequence_id: form.sequenceId
    }
    emit('draft-saved', draftData)
  } finally {
    loading.value = false
  }
}

// Handle Mark as Sent
async function handleMarkAsSent() {
  loading.value = true
  try {
    const messageData = {
      to_email: form.toEmail.trim(),
      to_name: form.toName.trim(),
      subject: form.subject.trim(),
      body: form.body.trim(),
      template_id: form.templateId
    }
    emit('mark-as-sent', messageData)
  } finally {
    loading.value = false
  }
}

// Handle Start Campaign
async function handleStartCampaign() {
  if (!form.sequenceId || !form.toEmail) return

  loading.value = true
  try {
    const campaignData = {
      sequence_id: form.sequenceId,
      recipient_email: form.toEmail.trim(),
      recipient_name: form.toName.trim()
    }
    emit('start-campaign', campaignData)
  } finally {
    loading.value = false
  }
}

// Expose methods for parent components
defineExpose({
  resetForm,
  setLoading: (value) => { loading.value = value }
})
</script>

<style scoped>
.inline-composer {
  background: var(--color-background, #fff);
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 12px;
  margin-bottom: 20px;
  overflow: hidden;
}

/* Header */
.composer-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
  border-bottom: 1px solid var(--color-border, #e5e7eb);
  background: var(--color-surface, #f8f9fa);
}

.composer-title {
  font-size: 16px;
  font-weight: 600;
  color: var(--color-text-primary, #1a1a1a);
  margin: 0;
}

.close-btn {
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

.close-btn:hover:not(:disabled) {
  background: var(--color-background-hover, rgba(0, 0, 0, 0.04));
  color: var(--color-text-primary, #1a1a1a);
}

.close-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* Two-column layout */
.composer-layout {
  display: flex;
  min-height: 0;
}

.composer-main {
  flex: 1;
  padding: 20px;
  overflow-y: auto;
  min-width: 0;
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

/* Preview Toggle */
.preview-toggle-wrapper {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 16px;
}

.preview-toggle {
  display: inline-flex;
  background: var(--color-surface, #f8f9fa);
  border-radius: 6px;
  padding: 2px;
}

.preview-toggle button {
  padding: 6px 12px;
  font-size: 12px;
  font-weight: 500;
  border: none;
  border-radius: 4px;
  background: transparent;
  color: var(--color-text-secondary, #6b7280);
  cursor: pointer;
  transition: all 0.2s ease;
}

.preview-toggle button:hover:not(:disabled) {
  color: var(--color-text-primary, #1a1a1a);
}

.preview-toggle button.active {
  background: var(--color-background, #fff);
  color: var(--color-primary, #6366f1);
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
}

.preview-toggle button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
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

/* Preview Display */
.preview-display {
  padding: 10px 12px;
  font-size: 14px;
  line-height: 1.5;
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 6px;
  background: var(--color-surface, #f8f9fa);
  color: var(--color-text-primary, #1a1a1a);
}

.subject-preview {
  margin-top: 8px;
}

.body-preview {
  min-height: 150px;
  white-space: pre-wrap;
}

/* Error */
.form-error {
  padding: 12px 16px;
  background: var(--color-error-bg, #fef2f2);
  border: 1px solid var(--color-error-border, #fecaca);
  border-radius: 6px;
  color: var(--color-error, #ef4444);
  font-size: 14px;
  margin-bottom: 16px;
}

/* Campaign Info Box */
.campaign-info {
  display: flex;
  gap: 12px;
  padding: 14px;
  background: var(--color-info-bg, #eff6ff);
  border: 1px solid var(--color-info-border, #bfdbfe);
  border-radius: 8px;
  margin-bottom: 16px;
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

/* Responsive */
@media (max-width: 768px) {
  .composer-layout {
    flex-direction: column;
  }

  .variable-sidebar {
    border-left: none;
    border-top: 1px solid var(--color-border, #e5e7eb);
    width: 100%;
    min-width: 100%;
    max-height: 300px;
  }
}
</style>
