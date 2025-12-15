<template>
  <div class="sequence-selector">
    <label class="selector-label">Campaign Sequence</label>
    <select
      :value="modelValue"
      @change="handleChange"
      class="selector-input"
      :disabled="disabled || sequences.length === 0"
    >
      <option :value="null">-- Select a Sequence --</option>
      <option
        v-for="sequence in activeSequences"
        :key="sequence.id"
        :value="sequence.id"
      >
        {{ sequence.sequence_name }} ({{ sequence.total_steps }} {{ sequence.total_steps === 1 ? 'step' : 'steps' }})
      </option>
    </select>

    <!-- Sequence Preview -->
    <div v-if="selectedSequence" class="sequence-preview">
      <div class="preview-header">
        <h4 class="preview-title">{{ selectedSequence.sequence_name }}</h4>
        <span class="preview-badge">{{ selectedSequence.total_steps }} {{ selectedSequence.total_steps === 1 ? 'email' : 'emails' }}</span>
      </div>

      <p v-if="selectedSequence.description" class="preview-description">
        {{ selectedSequence.description }}
      </p>

      <div v-if="selectedSequence.steps && selectedSequence.steps.length > 0" class="preview-steps">
        <div
          v-for="(step, index) in selectedSequence.steps"
          :key="step.id || index"
          class="preview-step"
        >
          <div class="step-number">{{ index + 1 }}</div>
          <div class="step-content">
            <div class="step-subject">{{ step.subject }}</div>
            <div class="step-timing">
              <template v-if="index === 0">
                Sends immediately
              </template>
              <template v-else>
                Sends {{ formatDelay(step.delay_days) }} after previous
              </template>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- No sequences message -->
    <p v-if="sequences.length === 0 && !loading" class="no-sequences">
      No campaign sequences available. Create sequences in Guestify Outreach.
    </p>

    <!-- Loading state -->
    <p v-if="loading" class="loading-message">
      Loading sequences...
    </p>
  </div>
</template>

<script setup>
/**
 * SequenceSelector Component
 *
 * Dropdown for selecting pre-built campaign sequences from Guestify Outreach.
 * Shows a preview of the selected sequence with step details.
 *
 * @package ShowAuthority
 * @since 5.2.0
 */

import { computed } from 'vue'

const props = defineProps({
  modelValue: {
    type: [Number, null],
    default: null
  },
  sequences: {
    type: Array,
    default: () => []
  },
  disabled: {
    type: Boolean,
    default: false
  },
  loading: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['update:modelValue', 'select'])

// Filter to only show active sequences
const activeSequences = computed(() => {
  return props.sequences.filter(s => s.is_active !== false)
})

// Get the currently selected sequence object
const selectedSequence = computed(() => {
  if (!props.modelValue) return null
  return props.sequences.find(s => s.id === props.modelValue) || null
})

// Handle selection change
function handleChange(event) {
  const value = event.target.value ? Number(event.target.value) : null
  emit('update:modelValue', value)
  emit('select', value ? selectedSequence.value : null)
}

// Format delay for display
function formatDelay(days) {
  if (!days || days === 0) return 'immediately'
  if (days === 1) return '1 day'
  if (days < 7) return `${days} days`
  if (days === 7) return '1 week'
  if (days < 30) return `${Math.round(days / 7)} weeks`
  return `${Math.round(days / 30)} month${days >= 60 ? 's' : ''}`
}
</script>

<style scoped>
.sequence-selector {
  margin-bottom: 16px;
}

.selector-label {
  display: block;
  font-size: 14px;
  font-weight: 500;
  color: var(--color-text-primary, #1a1a1a);
  margin-bottom: 6px;
}

.selector-input {
  width: 100%;
  padding: 10px 12px;
  font-size: 14px;
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 6px;
  background: var(--color-background, #fff);
  color: var(--color-text-primary, #1a1a1a);
  cursor: pointer;
  transition: border-color 0.2s, box-shadow 0.2s;
}

.selector-input:hover:not(:disabled) {
  border-color: var(--color-primary, #6366f1);
}

.selector-input:focus {
  outline: none;
  border-color: var(--color-primary, #6366f1);
  box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.selector-input:disabled {
  background: var(--color-surface, #f8f9fa);
  cursor: not-allowed;
  opacity: 0.6;
}

/* Sequence Preview */
.sequence-preview {
  margin-top: 12px;
  padding: 12px;
  background: var(--color-surface, #f8f9fa);
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 8px;
}

.preview-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}

.preview-title {
  margin: 0;
  font-size: 14px;
  font-weight: 600;
  color: var(--color-text-primary, #1a1a1a);
}

.preview-badge {
  font-size: 12px;
  padding: 2px 8px;
  background: var(--color-primary, #6366f1);
  color: white;
  border-radius: 12px;
  font-weight: 500;
}

.preview-description {
  font-size: 13px;
  color: var(--color-text-secondary, #6b7280);
  margin: 0 0 12px 0;
}

.preview-steps {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.preview-step {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 8px;
  background: var(--color-background, #fff);
  border-radius: 6px;
  border: 1px solid var(--color-border-light, #f0f0f0);
}

.step-number {
  flex-shrink: 0;
  width: 24px;
  height: 24px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-primary, #6366f1);
  color: white;
  border-radius: 50%;
  font-size: 12px;
  font-weight: 600;
}

.step-content {
  flex: 1;
  min-width: 0;
}

.step-subject {
  font-size: 13px;
  font-weight: 500;
  color: var(--color-text-primary, #1a1a1a);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.step-timing {
  font-size: 12px;
  color: var(--color-text-secondary, #6b7280);
  margin-top: 2px;
}

/* No sequences / Loading messages */
.no-sequences,
.loading-message {
  font-size: 13px;
  color: var(--color-text-secondary, #6b7280);
  margin: 8px 0 0 0;
  font-style: italic;
}
</style>
