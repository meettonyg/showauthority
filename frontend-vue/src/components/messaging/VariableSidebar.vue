<template>
  <div class="variable-sidebar">
    <div class="sidebar-header">
      <h3 class="sidebar-title">Personalization</h3>
      <p class="sidebar-subtitle">Click to insert variable tag</p>
    </div>

    <!-- Search -->
    <div class="search-wrapper">
      <svg class="search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="11" cy="11" r="8"></circle>
        <path d="m21 21-4.35-4.35"></path>
      </svg>
      <input
        v-model="searchQuery"
        type="text"
        class="search-input"
        placeholder="Search variables..."
      />
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="sidebar-loading">
      <div class="loading-spinner"></div>
      <span>Loading variables...</span>
    </div>

    <!-- Variables List -->
    <div v-else-if="hasVariables" class="variables-list">
      <div
        v-for="category in filteredCategories"
        :key="category.name"
        class="variable-category"
      >
        <button
          class="category-header"
          @click="toggleCategory(category.name)"
        >
          <svg
            class="category-chevron"
            :class="{ expanded: expandedCategories.includes(category.name) }"
            width="16"
            height="16"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
          >
            <polyline points="9 18 15 12 9 6"></polyline>
          </svg>
          <span class="category-name">{{ category.name }}</span>
          <span class="category-count">{{ category.variables.length }}</span>
        </button>

        <Transition name="expand">
          <div
            v-if="expandedCategories.includes(category.name)"
            class="category-variables"
          >
            <div
              v-for="variable in category.variables"
              :key="variable.tag"
              class="variable-item"
              :class="{ 'is-used': isVariableUsed(variable.tag) }"
            >
              <button
                class="variable-main"
                @click="handleInsert(variable.tag)"
                :title="`Click to insert ${variable.tag}`"
              >
                <span class="variable-label">{{ variable.label }}</span>
                <span class="variable-tag">{{ variable.tag }}</span>
                <span v-if="variable.value" class="variable-value" :title="variable.value">
                  {{ truncateValue(variable.value) }}
                </span>
                <span v-else class="variable-empty">(empty)</span>
              </button>
              <button
                v-if="variable.value"
                class="copy-btn"
                @click.stop="handleCopy(variable.value)"
                :title="`Copy value: ${variable.value}`"
              >
                <svg v-if="copiedTag !== variable.tag" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                  <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                </svg>
                <svg v-else width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
              </button>
            </div>
          </div>
        </Transition>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else class="sidebar-empty">
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
        <polyline points="14 2 14 8 20 8"></polyline>
        <line x1="16" y1="13" x2="8" y2="13"></line>
        <line x1="16" y1="17" x2="8" y2="17"></line>
        <polyline points="10 9 9 9 8 9"></polyline>
      </svg>
      <p>No variables available</p>
    </div>
  </div>
</template>

<script setup>
/**
 * VariableSidebar Component
 *
 * Displays personalization variables that can be inserted into email templates.
 * Shows actual values from podcast/guest data with click-to-insert and copy functionality.
 *
 * @package ShowAuthority
 * @since 5.3.0
 */

import { ref, computed } from 'vue'

const props = defineProps({
  /**
   * Variables data from API containing categories and variables
   */
  variablesData: {
    type: Object,
    default: () => ({})
  },
  /**
   * Loading state
   */
  loading: {
    type: Boolean,
    default: false
  },
  /**
   * Current email subject (used to check if variables are used)
   */
  subject: {
    type: String,
    default: ''
  },
  /**
   * Current email body (used to check if variables are used)
   */
  body: {
    type: String,
    default: ''
  }
})

const emit = defineEmits(['insert'])

// State
const searchQuery = ref('')
const expandedCategories = ref(['Podcast Information', 'Guest Information'])
const copiedTag = ref(null)

// Check if we have variables
const hasVariables = computed(() => {
  return props.variablesData?.categories?.length > 0
})

// Filter categories based on search
const filteredCategories = computed(() => {
  if (!props.variablesData?.categories) return []

  const query = searchQuery.value.toLowerCase().trim()
  if (!query) return props.variablesData.categories

  return props.variablesData.categories
    .map(category => ({
      ...category,
      variables: category.variables.filter(v =>
        v.label.toLowerCase().includes(query) ||
        v.tag.toLowerCase().includes(query) ||
        (v.value && v.value.toLowerCase().includes(query))
      )
    }))
    .filter(category => category.variables.length > 0)
})

// Check if a variable tag is used in subject or body
function isVariableUsed(tag) {
  const combinedText = (props.subject + ' ' + props.body).toLowerCase()
  return combinedText.includes(tag.toLowerCase())
}

// Toggle category expansion
function toggleCategory(categoryName) {
  const index = expandedCategories.value.indexOf(categoryName)
  if (index === -1) {
    expandedCategories.value.push(categoryName)
  } else {
    expandedCategories.value.splice(index, 1)
  }
}

// Truncate long values for display
function truncateValue(value, maxLength = 30) {
  if (!value || value.length <= maxLength) return value
  return value.substring(0, maxLength) + '...'
}

// Handle variable insert
function handleInsert(tag) {
  emit('insert', tag)
}

// Handle copy to clipboard
async function handleCopy(value) {
  try {
    await navigator.clipboard.writeText(value)
    // Find the tag for this value to show checkmark
    for (const category of props.variablesData?.categories || []) {
      const variable = category.variables.find(v => v.value === value)
      if (variable) {
        copiedTag.value = variable.tag
        setTimeout(() => {
          copiedTag.value = null
        }, 2000)
        break
      }
    }
  } catch (err) {
    console.error('Failed to copy:', err)
  }
}
</script>

<style scoped>
.variable-sidebar {
  width: 280px;
  min-width: 280px;
  background: var(--color-surface, #f8f9fa);
  border-left: 1px solid var(--color-border, #e5e7eb);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.sidebar-header {
  padding: 16px;
  border-bottom: 1px solid var(--color-border, #e5e7eb);
}

.sidebar-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-text-primary, #1a1a1a);
  margin: 0 0 4px;
}

.sidebar-subtitle {
  font-size: 12px;
  color: var(--color-text-secondary, #6b7280);
  margin: 0;
}

/* Search */
.search-wrapper {
  position: relative;
  padding: 12px 16px;
  border-bottom: 1px solid var(--color-border, #e5e7eb);
}

.search-icon {
  position: absolute;
  left: 26px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--color-text-tertiary, #9ca3af);
  pointer-events: none;
}

.search-input {
  width: 100%;
  padding: 8px 12px 8px 32px;
  font-size: 13px;
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 6px;
  background: var(--color-background, #fff);
  color: var(--color-text-primary, #1a1a1a);
  transition: border-color 0.2s, box-shadow 0.2s;
}

.search-input:focus {
  outline: none;
  border-color: var(--color-primary, #6366f1);
  box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.search-input::placeholder {
  color: var(--color-text-tertiary, #9ca3af);
}

/* Loading */
.sidebar-loading {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  color: var(--color-text-secondary, #6b7280);
  font-size: 13px;
}

.loading-spinner {
  width: 24px;
  height: 24px;
  border: 2px solid var(--color-border, #e5e7eb);
  border-top-color: var(--color-primary, #6366f1);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

/* Variables List */
.variables-list {
  flex: 1;
  overflow-y: auto;
  padding: 8px 0;
}

/* Category */
.variable-category {
  margin-bottom: 4px;
}

.category-header {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 16px;
  background: none;
  border: none;
  font-size: 13px;
  font-weight: 500;
  color: var(--color-text-primary, #1a1a1a);
  cursor: pointer;
  transition: background 0.2s;
}

.category-header:hover {
  background: var(--color-background-hover, rgba(0, 0, 0, 0.04));
}

.category-chevron {
  flex-shrink: 0;
  color: var(--color-text-tertiary, #9ca3af);
  transition: transform 0.2s;
}

.category-chevron.expanded {
  transform: rotate(90deg);
}

.category-name {
  flex: 1;
  text-align: left;
}

.category-count {
  font-size: 11px;
  font-weight: 500;
  color: var(--color-text-tertiary, #9ca3af);
  background: var(--color-background, #fff);
  padding: 2px 6px;
  border-radius: 10px;
}

/* Category Variables */
.category-variables {
  overflow: hidden;
}

.variable-item {
  display: flex;
  align-items: stretch;
  margin: 2px 8px;
  background: var(--color-background, #fff);
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 6px;
  transition: border-color 0.2s, box-shadow 0.2s;
}

.variable-item:hover {
  border-color: var(--color-primary, #6366f1);
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}

.variable-item.is-used {
  border-color: var(--color-success, #10b981);
  background: var(--color-success-bg, #ecfdf5);
}

.variable-main {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
  padding: 8px 10px;
  background: none;
  border: none;
  text-align: left;
  cursor: pointer;
  min-width: 0;
}

.variable-label {
  font-size: 12px;
  font-weight: 500;
  color: var(--color-text-primary, #1a1a1a);
}

.variable-tag {
  font-size: 11px;
  font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
  color: var(--color-primary, #6366f1);
}

.variable-value {
  font-size: 11px;
  color: var(--color-text-secondary, #6b7280);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 100%;
}

.variable-empty {
  font-size: 11px;
  font-style: italic;
  color: var(--color-text-tertiary, #9ca3af);
}

.copy-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  padding: 0;
  background: none;
  border: none;
  border-left: 1px solid var(--color-border, #e5e7eb);
  color: var(--color-text-tertiary, #9ca3af);
  cursor: pointer;
  transition: color 0.2s, background 0.2s;
}

.copy-btn:hover {
  color: var(--color-primary, #6366f1);
  background: var(--color-surface, #f8f9fa);
}

/* Empty State */
.sidebar-empty {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 20px;
  text-align: center;
  color: var(--color-text-tertiary, #9ca3af);
}

.sidebar-empty p {
  margin: 0;
  font-size: 13px;
}

/* Expand Transition */
.expand-enter-active,
.expand-leave-active {
  transition: all 0.2s ease;
}

.expand-enter-from,
.expand-leave-to {
  opacity: 0;
  max-height: 0;
}

.expand-enter-to,
.expand-leave-from {
  opacity: 1;
  max-height: 500px;
}
</style>
