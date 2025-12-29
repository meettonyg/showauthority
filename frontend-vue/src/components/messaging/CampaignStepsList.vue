<template>
  <div class="campaign-steps">
    <div class="steps-header">
      <h4 class="steps-title">{{ sequence.sequence_name || sequence.name }}</h4>
      <span class="steps-count">{{ sequence.total_steps || sequence.steps?.length || 0 }} emails</span>
    </div>

    <div class="steps-list">
      <ExpandableStepCard
        v-for="(step, index) in steps"
        :key="step.id || index"
        :step="step"
        :step-number="index + 1"
        :variables="variables"
        :expanded="expandedStep === index"
        :preview-mode="previewMode"
        :editable="!previewMode"
        @toggle="handleStepToggle(index)"
        @edit="handleStepEdit(index, $event)"
        @save-template="handleSaveTemplate"
      />
    </div>

    <!-- Empty state -->
    <div v-if="!steps.length" class="steps-empty">
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
        <polyline points="22,6 12,13 2,6"></polyline>
      </svg>
      <p>No email steps in this sequence</p>
    </div>
  </div>
</template>

<script setup>
/**
 * CampaignStepsList Component
 *
 * Container for managing multiple expandable campaign steps.
 * Displays the sequence's email steps with expand/collapse functionality.
 *
 * @package ShowAuthority
 * @since 5.4.0
 */

import { ref, computed } from 'vue'
import ExpandableStepCard from './ExpandableStepCard.vue'

const props = defineProps({
  /**
   * The campaign sequence object
   */
  sequence: {
    type: Object,
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
   * Whether in preview mode (no editing allowed)
   */
  previewMode: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits([
  'step-edit',
  'save-template'
])

// Track which step is expanded
const expandedStep = ref(null)

// Get steps from sequence
const steps = computed(() => {
  return props.sequence?.steps || []
})

// Handle step toggle
function handleStepToggle(index) {
  if (expandedStep.value === index) {
    expandedStep.value = null
  } else {
    expandedStep.value = index
  }
}

// Handle step edit
function handleStepEdit(index, editData) {
  emit('step-edit', { index, ...editData })
}

// Handle save template request
function handleSaveTemplate(data) {
  emit('save-template', data)
}
</script>

<style scoped>
.campaign-steps {
  margin-top: 16px;
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 8px;
  overflow: hidden;
}

.steps-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  background: var(--color-surface, #f8f9fa);
  border-bottom: 1px solid var(--color-border, #e5e7eb);
}

.steps-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-text-primary, #1a1a1a);
  margin: 0;
}

.steps-count {
  font-size: 12px;
  color: var(--color-text-secondary, #6b7280);
  background: var(--color-background, #fff);
  padding: 2px 8px;
  border-radius: 10px;
}

.steps-list {
  background: var(--color-background, #fff);
}

.steps-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 24px;
  color: var(--color-text-tertiary, #9ca3af);
  text-align: center;
}

.steps-empty p {
  margin: 0;
  font-size: 13px;
}
</style>
