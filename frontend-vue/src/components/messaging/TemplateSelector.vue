<template>
  <div class="template-selector">
    <label class="selector-label">Template (optional)</label>
    <select
      :value="modelValue"
      @change="$emit('update:modelValue', $event.target.value ? Number($event.target.value) : null)"
      class="selector-input"
      :disabled="disabled || templates.length === 0"
    >
      <option :value="null">-- No Template --</option>
      <template v-if="groupedTemplates">
        <optgroup
          v-for="(categoryTemplates, category) in groupedTemplates"
          :key="category"
          :label="category"
        >
          <option
            v-for="template in categoryTemplates"
            :key="template.id"
            :value="template.id"
          >
            {{ template.name }}
          </option>
        </optgroup>
      </template>
      <template v-else>
        <option
          v-for="template in templates"
          :key="template.id"
          :value="template.id"
        >
          {{ template.name }}
        </option>
      </template>
    </select>
  </div>
</template>

<script setup>
/**
 * TemplateSelector Component
 *
 * Dropdown for selecting email templates, optionally grouped by category.
 *
 * @package ShowAuthority
 * @since 5.0.0
 */

import { computed } from 'vue'

const props = defineProps({
  modelValue: {
    type: [Number, null],
    default: null
  },
  templates: {
    type: Array,
    default: () => []
  },
  grouped: {
    type: Boolean,
    default: true
  },
  disabled: {
    type: Boolean,
    default: false
  }
})

defineEmits(['update:modelValue'])

const groupedTemplates = computed(() => {
  if (!props.grouped || props.templates.length === 0) {
    return null
  }

  const groups = {}
  for (const template of props.templates) {
    const category = template.category || 'General'
    if (!groups[category]) {
      groups[category] = []
    }
    groups[category].push(template)
  }

  // Only return groups if we have more than one category
  if (Object.keys(groups).length <= 1) {
    return null
  }

  return groups
})
</script>

<style scoped>
.template-selector {
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
</style>
