<template>
  <div class="ai-panel">
    <div class="ai-panel-header">
      <div class="ai-icon">
        <Icon name="layers" :size="16" />
      </div>
      <span class="ai-label">AI Assistant</span>
    </div>

    <!-- Quick Actions -->
    <div class="quick-actions">
      <button
        v-for="action in quickActions"
        :key="action.id"
        class="quick-action-btn"
        :disabled="loading || !hasContent"
        @click="handleQuickAction(action)"
      >
        <Icon :name="action.icon" :size="14" />
        {{ action.label }}
      </button>
    </div>

    <!-- Custom Instruction Input -->
    <div class="custom-instruction">
      <textarea
        v-model="customInstruction"
        class="instruction-input"
        placeholder="Give custom instructions... (e.g., 'Add urgency', 'Include a P.S.')"
        :disabled="loading"
        @keydown.enter.meta="handleCustomInstruction"
        @keydown.enter.ctrl="handleCustomInstruction"
      ></textarea>
      <button
        class="apply-btn"
        :disabled="loading || !customInstruction.trim() || !hasContent"
        @click="handleCustomInstruction"
      >
        <svg v-if="loading" class="spinner" width="14" height="14" viewBox="0 0 24 24">
          <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" opacity="0.3"></circle>
          <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" stroke-width="3" fill="none" stroke-linecap="round"></path>
        </svg>
        <Icon v-else name="send" :size="14" />
        Apply
      </button>
    </div>

    <!-- Error Display -->
    <div v-if="error" class="error-message">
      {{ error }}
      <button class="dismiss-btn" @click="error = null">&times;</button>
    </div>

    <!-- AI Result Preview (when showing suggestion) -->
    <div v-if="suggestion" class="suggestion-preview">
      <div class="suggestion-header">
        <span>AI Suggestion</span>
        <div class="suggestion-actions">
          <button class="accept-btn" @click="acceptSuggestion">Accept</button>
          <button class="reject-btn" @click="rejectSuggestion">Reject</button>
        </div>
      </div>
      <!-- Render as plain text with CSS whitespace handling to prevent XSS -->
      <div class="suggestion-content">{{ suggestion?.body || '' }}</div>
    </div>
  </div>
</template>

<script setup>
/**
 * AIRefinementPanel Component
 *
 * Provides AI-powered email refinement capabilities.
 * Includes quick actions and custom instruction input.
 *
 * @package ShowAuthority
 * @since 5.4.0
 */

import { ref, computed } from 'vue'
import Icon from '../common/Icon.vue'
import aiService from '../../services/ai'

const props = defineProps({
  /**
   * Current email subject
   */
  subject: {
    type: String,
    default: ''
  },
  /**
   * Current email body content
   */
  body: {
    type: String,
    default: ''
  },
  /**
   * Appearance ID for cost tracking
   */
  appearanceId: {
    type: [Number, String],
    default: null
  }
})

const emit = defineEmits([
  'refine', // Emitted when content should be replaced with AI result
  'loading' // Emitted when loading state changes
])

// Quick action definitions - icon names match Icon component
const quickActions = [
  { id: 'polish', label: 'Polish', icon: 'wand', instruction: 'Polish and improve the email while maintaining the core message. Fix grammar, improve flow, and make it more engaging.' },
  { id: 'shorten', label: 'Shorten', icon: 'compress', instruction: 'Make the email more concise by removing unnecessary words and phrases. Keep the key points but reduce length by about 30%.' },
  { id: 'expand', label: 'Expand', icon: 'expand', instruction: 'Expand the email with more detail and context. Add persuasive elements and make the message more compelling.' },
  { id: 'formal', label: 'Formal', icon: 'formal', instruction: 'Rewrite the email in a more formal, professional tone suitable for business communication.' },
  { id: 'casual', label: 'Casual', icon: 'casual', instruction: 'Rewrite the email in a more casual, friendly tone while keeping it professional.' }
]

// State
const loading = ref(false)
const error = ref(null)
const customInstruction = ref('')
const suggestion = ref(null)

// Check if there's content to refine
const hasContent = computed(() => {
  return (props.subject?.trim() || props.body?.trim())
})

/**
 * Handle quick action button click
 */
async function handleQuickAction(action) {
  await refineContent(action.instruction)
}

/**
 * Handle custom instruction submission
 */
async function handleCustomInstruction() {
  if (!customInstruction.value.trim()) return
  await refineContent(customInstruction.value)
  customInstruction.value = ''
}

/**
 * Call AI service to refine content
 */
async function refineContent(instruction) {
  if (!hasContent.value) return

  loading.value = true
  error.value = null
  emit('loading', true)

  try {
    const result = await aiService.refineEmail({
      subject: props.subject,
      body: props.body,
      instruction: instruction,
      appearance_id: props.appearanceId
    })

    if (result.success) {
      // Show suggestion for user to accept/reject
      suggestion.value = {
        subject: result.data.subject,
        body: result.data.body
      }
    } else {
      error.value = result.message || 'Failed to refine email'
    }
  } catch (err) {
    console.error('AI refinement error:', err)
    error.value = err.response?.data?.message || err.message || 'Failed to connect to AI service'
  } finally {
    loading.value = false
    emit('loading', false)
  }
}

/**
 * Accept the AI suggestion
 */
function acceptSuggestion() {
  if (suggestion.value) {
    emit('refine', {
      subject: suggestion.value.subject,
      body: suggestion.value.body
    })
    suggestion.value = null
  }
}

/**
 * Reject the AI suggestion
 */
function rejectSuggestion() {
  suggestion.value = null
}
</script>

<style scoped>
.ai-panel {
  background: linear-gradient(135deg, #f0f4ff 0%, #e8f0fe 100%);
  border: 1px solid var(--color-border, #d1d9e6);
  border-radius: 8px;
  padding: 12px;
}

.ai-panel-header {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 12px;
}

.ai-icon {
  width: 24px;
  height: 24px;
  background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
}

.ai-label {
  font-size: 12px;
  font-weight: 600;
  color: var(--color-text-primary, #1a1a1a);
}

.quick-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 12px;
}

.quick-action-btn {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 6px 10px;
  background: white;
  border: 1px solid var(--color-border, #d1d9e6);
  border-radius: 6px;
  font-size: 12px;
  font-weight: 500;
  color: var(--color-text-primary, #374151);
  cursor: pointer;
  transition: all 0.15s ease;
}

.quick-action-btn:hover:not(:disabled) {
  background: var(--color-surface, #f8f9fa);
  border-color: #6366f1;
  color: #6366f1;
}

.quick-action-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.custom-instruction {
  display: flex;
  gap: 8px;
}

.instruction-input {
  flex: 1;
  min-height: 36px;
  max-height: 80px;
  padding: 8px 12px;
  border: 1px solid var(--color-border, #d1d9e6);
  border-radius: 6px;
  font-size: 13px;
  font-family: inherit;
  resize: vertical;
  background: white;
}

.instruction-input:focus {
  outline: none;
  border-color: #6366f1;
  box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.instruction-input:disabled {
  background: var(--color-surface, #f3f4f6);
  cursor: not-allowed;
}

.apply-btn {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 8px 14px;
  background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
  border: none;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
  color: white;
  cursor: pointer;
  transition: opacity 0.15s ease;
}

.apply-btn:hover:not(:disabled) {
  opacity: 0.9;
}

.apply-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.spinner {
  animation: spin 1s linear infinite;
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

.error-message {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 8px;
  padding: 8px 12px;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 6px;
  font-size: 12px;
  color: #dc2626;
}

.dismiss-btn {
  background: none;
  border: none;
  font-size: 16px;
  color: #dc2626;
  cursor: pointer;
  padding: 0 4px;
}

.suggestion-preview {
  margin-top: 12px;
  background: white;
  border: 1px solid #86efac;
  border-radius: 6px;
  overflow: hidden;
}

.suggestion-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 12px;
  background: #f0fdf4;
  border-bottom: 1px solid #86efac;
  font-size: 12px;
  font-weight: 600;
  color: #16a34a;
}

.suggestion-actions {
  display: flex;
  gap: 8px;
}

.accept-btn,
.reject-btn {
  padding: 4px 12px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
  cursor: pointer;
  transition: opacity 0.15s ease;
}

.accept-btn {
  background: #16a34a;
  border: none;
  color: white;
}

.accept-btn:hover {
  opacity: 0.9;
}

.reject-btn {
  background: white;
  border: 1px solid #d1d5db;
  color: var(--color-text-secondary, #6b7280);
}

.reject-btn:hover {
  background: var(--color-surface, #f9fafb);
}

.suggestion-content {
  padding: 12px;
  font-size: 13px;
  line-height: 1.5;
  color: var(--color-text-primary, #374151);
  max-height: 200px;
  overflow-y: auto;
  /* Preserve whitespace and newlines safely without using v-html */
  white-space: pre-wrap;
  word-wrap: break-word;
}
</style>
