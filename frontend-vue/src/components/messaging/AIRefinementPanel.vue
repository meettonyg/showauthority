<template>
  <div class="ai-panel">
    <div class="ai-panel-header">
      <div class="ai-header-left">
        <span class="ai-icon">✨</span>
<<<<<<< Updated upstream
<<<<<<< Updated upstream
        <h3 class="ai-label">Refine with AI</h3>
=======
        <span class="ai-label">Refine with AI</span>
>>>>>>> Stashed changes
=======
        <span class="ai-label">Refine with AI</span>
>>>>>>> Stashed changes
      </div>
      <button class="close-btn" @click="$emit('close')" title="Close AI panel">✕</button>
    </div>

<<<<<<< Updated upstream
<<<<<<< Updated upstream
    <!-- Quick Actions -->
=======
    <!-- Quick Actions with Emojis -->
>>>>>>> Stashed changes
=======
    <!-- Quick Actions with Emojis -->
>>>>>>> Stashed changes
    <div class="quick-actions-section">
      <label class="section-label">Quick actions:</label>
      <div class="quick-actions">
        <button
          v-for="action in quickActions"
          :key="action.id"
          class="quick-action-btn"
          :disabled="loading || !hasContent"
          @click="handleQuickAction(action)"
        >
<<<<<<< Updated upstream
<<<<<<< Updated upstream
          <span class="action-icon">{{ action.icon }}</span>
=======
          <span class="action-emoji">{{ action.emoji }}</span>
>>>>>>> Stashed changes
=======
          <span class="action-emoji">{{ action.emoji }}</span>
>>>>>>> Stashed changes
          {{ action.label }}
        </button>
      </div>
    </div>

    <!-- Custom Instruction Input -->
<<<<<<< Updated upstream
<<<<<<< Updated upstream
    <div class="custom-instruction-section">
=======
    <div class="custom-section">
>>>>>>> Stashed changes
=======
    <div class="custom-section">
>>>>>>> Stashed changes
      <label class="section-label">Or describe what you want:</label>
      <textarea
        v-model="customInstruction"
        class="instruction-input"
        placeholder="e.g., Write a warm intro that mentions their recent episode about marketing..."
        :disabled="loading"
        rows="2"
<<<<<<< Updated upstream
<<<<<<< Updated upstream
        @keydown.enter.meta="handleCustomInstruction"
        @keydown.enter.ctrl="handleCustomInstruction"
      ></textarea>
    </div>

    <!-- Tone & Length Options -->
    <div class="options-row">
      <div class="option-group">
        <label class="option-label">Tone:</label>
        <select v-model="selectedTone" class="option-select" :disabled="loading">
=======
      ></textarea>
    </div>

=======
      ></textarea>
    </div>

>>>>>>> Stashed changes
    <!-- Tone & Length Selectors -->
    <div class="options-row">
      <div class="option-group">
        <label class="option-label">Tone:</label>
        <select v-model="tone" class="option-select" :disabled="loading">
<<<<<<< Updated upstream
>>>>>>> Stashed changes
=======
>>>>>>> Stashed changes
          <option value="professional">Professional</option>
          <option value="friendly">Friendly</option>
          <option value="casual">Casual</option>
          <option value="enthusiastic">Enthusiastic</option>
        </select>
      </div>
      <div class="option-group">
        <label class="option-label">Length:</label>
<<<<<<< Updated upstream
<<<<<<< Updated upstream
        <select v-model="selectedLength" class="option-select" :disabled="loading">
=======
        <select v-model="length" class="option-select" :disabled="loading">
>>>>>>> Stashed changes
=======
        <select v-model="length" class="option-select" :disabled="loading">
>>>>>>> Stashed changes
          <option value="short">Short</option>
          <option value="medium">Medium</option>
          <option value="long">Detailed</option>
        </select>
      </div>
    </div>

    <!-- Generate Button -->
    <button
      class="generate-btn"
<<<<<<< Updated upstream
<<<<<<< Updated upstream
      :disabled="loading || (!customInstruction.trim() && !lastQuickAction) || !hasContent"
      @click="handleGenerate"
    >
      <span v-if="loading" class="spinner">⏳</span>
      <span v-else>✨</span>
      {{ loading ? 'Generating...' : 'Generate Email' }}
=======
=======
>>>>>>> Stashed changes
      :disabled="loading || !hasContent"
      @click="handleGenerate"
    >
      <span v-if="loading" class="btn-content">
        <span class="spinner-icon">⏳</span> Generating...
      </span>
      <span v-else class="btn-content">
        <span>✨</span> Generate Email
      </span>
<<<<<<< Updated upstream
>>>>>>> Stashed changes
=======
>>>>>>> Stashed changes
    </button>

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
 * Includes quick actions, tone/length options, and custom instruction input.
 *
 * Design based on message-tab-complete.jsx reference.
 *
 * @package ShowAuthority
 * @since 5.4.0
 * @updated 2025-12-30
 */

import { ref, computed } from 'vue'
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
  'loading', // Emitted when loading state changes
<<<<<<< Updated upstream
<<<<<<< Updated upstream
  'close' // Emitted when close button is clicked
])

// Quick action definitions with emoji icons
const quickActions = [
  { id: 'shorten', label: 'Make it shorter', icon: '📏', instruction: 'Make the email more concise by removing unnecessary words and phrases. Keep the key points but reduce length by about 30%.' },
  { id: 'personal', label: 'More personal', icon: '💬', instruction: 'Make this email more personal and conversational. Add warmth and connection while keeping it professional.' },
  { id: 'social-proof', label: 'Add social proof', icon: '⭐', instruction: 'Add social proof and credibility markers. Include references to achievements, testimonials, or notable results.' },
  { id: 'cta', label: 'Stronger CTA', icon: '🎯', instruction: 'Strengthen the call to action. Make it clearer, more compelling, and create a sense of why they should respond.' },
  { id: 'episode', label: 'Reference episode', icon: '🎙️', instruction: 'Add a specific reference to their recent episode. Show that you have listened to their podcast and appreciate their content.' },
  { id: 'urgency', label: 'Add urgency', icon: '⏰', instruction: 'Add appropriate urgency without being pushy. Create a reason for them to respond sooner rather than later.' }
=======
  'close' // Emitted when panel should close
])

// Quick action definitions with emojis matching the JSX design
const quickActions = [
=======
  'close' // Emitted when panel should close
])

// Quick action definitions with emojis matching the JSX design
const quickActions = [
>>>>>>> Stashed changes
  { id: 'shorten', label: 'Make it shorter', emoji: '📏', instruction: 'Make the email more concise by removing unnecessary words and phrases. Keep the key points but reduce length by about 30%.' },
  { id: 'personal', label: 'Make it more personal', emoji: '💬', instruction: 'Make this more personal and conversational, referencing specific details about the recipient.' },
  { id: 'social', label: 'Add social proof', emoji: '⭐', instruction: 'Add social proof and credibility markers to make the pitch more compelling.' },
  { id: 'cta', label: 'Stronger CTA', emoji: '🎯', instruction: 'Strengthen the call to action to be more compelling and clear.' },
  { id: 'episode', label: 'Reference recent episode', emoji: '🎙️', instruction: 'Add a reference to their recent episode to show genuine interest.' },
  { id: 'urgency', label: 'Add urgency', emoji: '⏰', instruction: 'Add appropriate urgency without being pushy.' }
<<<<<<< Updated upstream
>>>>>>> Stashed changes
=======
>>>>>>> Stashed changes
]

// State
const loading = ref(false)
const error = ref(null)
const customInstruction = ref('')
const suggestion = ref(null)
<<<<<<< Updated upstream
<<<<<<< Updated upstream
const selectedTone = ref('professional')
const selectedLength = ref('medium')
const lastQuickAction = ref(null)
=======
const tone = ref('professional')
const length = ref('medium')
>>>>>>> Stashed changes
=======
const tone = ref('professional')
const length = ref('medium')
>>>>>>> Stashed changes

// Check if there's content to refine
const hasContent = computed(() => {
  return (props.subject?.trim() || props.body?.trim())
})

/**
 * Handle quick action button click - sets the instruction and triggers generate
 */
async function handleQuickAction(action) {
  lastQuickAction.value = action
  customInstruction.value = action.instruction
  await handleGenerate()
}

/**
<<<<<<< Updated upstream
<<<<<<< Updated upstream
 * Handle custom instruction submission (Enter key)
 */
async function handleCustomInstruction() {
  if (!customInstruction.value.trim()) return
  lastQuickAction.value = null
  await handleGenerate()
}

/**
 * Handle generate button click
 */
async function handleGenerate() {
  const instruction = customInstruction.value.trim() || lastQuickAction.value?.instruction
  if (!instruction) return
=======
 * Handle generate button click
 */
async function handleGenerate() {
  const instruction = customInstruction.value.trim() || 'Improve this email pitch'
>>>>>>> Stashed changes
=======
 * Handle generate button click
 */
async function handleGenerate() {
  const instruction = customInstruction.value.trim() || 'Improve this email pitch'
>>>>>>> Stashed changes
  await refineContent(instruction)
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
<<<<<<< Updated upstream
<<<<<<< Updated upstream
      tone: selectedTone.value,
      length: selectedLength.value,
      appearance_id: props.appearanceId
=======
      appearance_id: props.appearanceId,
      tone: tone.value,
      length: length.value
>>>>>>> Stashed changes
=======
      appearance_id: props.appearanceId,
      tone: tone.value,
      length: length.value
>>>>>>> Stashed changes
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
<<<<<<< Updated upstream
<<<<<<< Updated upstream
  background: linear-gradient(to right, #faf5ff, #eff6ff);
=======
  background: linear-gradient(135deg, #f5f3ff 0%, #eff6ff 100%);
>>>>>>> Stashed changes
=======
  background: linear-gradient(135deg, #f5f3ff 0%, #eff6ff 100%);
>>>>>>> Stashed changes
  border: 1px solid #c4b5fd;
  border-radius: 8px;
  padding: 16px;
  margin-bottom: 16px;
}

.ai-panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}

.ai-header-left {
  display: flex;
  align-items: center;
  gap: 8px;
}

.ai-icon {
  font-size: 18px;
}

.ai-label {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-text-primary, #1f2937);
  margin: 0;
}

.close-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border: none;
  background: transparent;
  border-radius: 6px;
  color: var(--color-text-tertiary, #9ca3af);
  cursor: pointer;
  transition: background 0.2s, color 0.2s;
  font-size: 16px;
}

.close-btn:hover {
  background: rgba(0, 0, 0, 0.05);
  color: var(--color-text-primary, #1a1a1a);
}

<<<<<<< Updated upstream
<<<<<<< Updated upstream
/* Section Labels */
=======
=======
>>>>>>> Stashed changes
.close-btn {
  background: none;
  border: none;
  font-size: 16px;
  color: var(--color-text-tertiary, #9ca3af);
  cursor: pointer;
  padding: 4px;
  line-height: 1;
}

.close-btn:hover {
  color: var(--color-text-primary, #1a1a1a);
}

/* Quick Actions Section */
.quick-actions-section {
  margin-bottom: 12px;
}

<<<<<<< Updated upstream
>>>>>>> Stashed changes
=======
>>>>>>> Stashed changes
.section-label {
  display: block;
  font-size: 12px;
  font-weight: 500;
  color: var(--color-text-secondary, #6b7280);
  margin-bottom: 8px;
}

<<<<<<< Updated upstream
<<<<<<< Updated upstream
/* Quick Actions */
.quick-actions-section {
  margin-bottom: 12px;
}

.quick-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
=======
.quick-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
>>>>>>> Stashed changes
=======
.quick-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
>>>>>>> Stashed changes
}

.quick-action-btn {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 6px 10px;
  background: white;
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 20px;
  font-size: 12px;
  font-weight: 400;
  color: var(--color-text-primary, #374151);
  cursor: pointer;
  transition: all 0.15s ease;
}

.quick-action-btn:hover:not(:disabled) {
<<<<<<< Updated upstream
<<<<<<< Updated upstream
  background: #f5f3ff;
  border-color: #a78bfa;
  color: #7c3aed;
=======
  border-color: #a78bfa;
  background: #faf5ff;
>>>>>>> Stashed changes
=======
  border-color: #a78bfa;
  background: #faf5ff;
>>>>>>> Stashed changes
}

.quick-action-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

<<<<<<< Updated upstream
<<<<<<< Updated upstream
.action-icon {
  font-size: 12px;
}

/* Custom Instruction */
.custom-instruction-section {
=======
=======
>>>>>>> Stashed changes
.action-emoji {
  font-size: 12px;
}

/* Custom Section */
.custom-section {
<<<<<<< Updated upstream
>>>>>>> Stashed changes
=======
>>>>>>> Stashed changes
  margin-bottom: 12px;
}

.instruction-input {
  width: 100%;
<<<<<<< Updated upstream
<<<<<<< Updated upstream
  padding: 10px 12px;
=======
  padding: 8px 12px;
>>>>>>> Stashed changes
=======
  padding: 8px 12px;
>>>>>>> Stashed changes
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 6px;
  font-size: 12px;
  font-family: inherit;
  resize: none;
  background: white;
}

.instruction-input:focus {
  outline: none;
<<<<<<< Updated upstream
<<<<<<< Updated upstream
  border-color: #8b5cf6;
  box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
=======
  border-color: #a78bfa;
  box-shadow: 0 0 0 3px rgba(167, 139, 250, 0.1);
>>>>>>> Stashed changes
=======
  border-color: #a78bfa;
  box-shadow: 0 0 0 3px rgba(167, 139, 250, 0.1);
>>>>>>> Stashed changes
}

.instruction-input:disabled {
  background: var(--color-surface, #f3f4f6);
  cursor: not-allowed;
}

/* Options Row */
.options-row {
<<<<<<< Updated upstream
<<<<<<< Updated upstream
=======
>>>>>>> Stashed changes
  display: flex;
  gap: 16px;
  margin-bottom: 12px;
}

.option-group {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.option-label {
  font-size: 12px;
  font-weight: 500;
  color: var(--color-text-secondary, #6b7280);
}

.option-select {
<<<<<<< Updated upstream
  padding: 6px 10px;
=======
  padding: 4px 8px;
>>>>>>> Stashed changes
  font-size: 12px;
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 4px;
  background: white;
<<<<<<< Updated upstream
  color: var(--color-text-primary, #1a1a1a);
  cursor: pointer;
}

.option-select:focus {
  outline: none;
  border-color: #8b5cf6;
}

=======
  cursor: pointer;
}

>>>>>>> Stashed changes
.option-select:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* Generate Button */
.generate-btn {
  width: 100%;
<<<<<<< Updated upstream
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 10px 16px;
  background: #8b5cf6;
  border: none;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 500;
  color: white;
  cursor: pointer;
  transition: background 0.15s ease;
}

.generate-btn:hover:not(:disabled) {
  background: #7c3aed;
}

.generate-btn:disabled {
  background: #d1d5db;
  color: #6b7280;
=======
  display: flex;
  gap: 16px;
  margin-bottom: 12px;
}

.option-group {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.option-label {
  font-size: 12px;
  font-weight: 500;
  color: var(--color-text-secondary, #6b7280);
}

.option-select {
  padding: 4px 8px;
  font-size: 12px;
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 4px;
  background: white;
  cursor: pointer;
}

.option-select:disabled {
  opacity: 0.5;
>>>>>>> Stashed changes
  cursor: not-allowed;
}

/* Generate Button */
.generate-btn {
  width: 100%;
=======
>>>>>>> Stashed changes
  padding: 10px 16px;
  background: #8b5cf6;
  border: none;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 500;
  color: white;
  cursor: pointer;
  transition: background 0.15s ease;
}

.generate-btn:hover:not(:disabled) {
  background: #7c3aed;
}

.generate-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.btn-content {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}

.spinner-icon {
  animation: spin 1s linear infinite;
  display: inline-block;
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

<<<<<<< Updated upstream
<<<<<<< Updated upstream
/* Error Message */
=======
/* Error */
>>>>>>> Stashed changes
=======
/* Error */
>>>>>>> Stashed changes
.error-message {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 12px;
<<<<<<< Updated upstream
<<<<<<< Updated upstream
  padding: 10px 12px;
=======
=======
>>>>>>> Stashed changes
  padding: 8px 12px;
>>>>>>> Stashed changes
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

/* Suggestion Preview */
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
  padding: 10px 12px;
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
  padding: 5px 14px;
  border-radius: 4px;
  font-size: 12px;
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
  white-space: pre-wrap;
  word-wrap: break-word;
}
</style>
