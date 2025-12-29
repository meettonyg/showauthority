<template>
  <div class="message-list">
    <!-- Loading State -->
    <div v-if="loading" class="list-loading">
      <div class="spinner"></div>
      <span>Loading messages...</span>
    </div>

    <!-- Empty State (no messages and no drafts) -->
    <div v-else-if="messages.length === 0 && activeDrafts.length === 0" class="list-empty">
      <svg class="empty-icon" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
        <polyline points="22,6 12,13 2,6"></polyline>
      </svg>
      <h3 class="empty-title">No Messages Yet</h3>
      <p class="empty-text">Click "Compose Email" to send your first message to this contact.</p>
    </div>

    <!-- Content -->
    <div v-else class="list-content">
      <!-- Drafts Section -->
      <div v-if="activeDrafts.length > 0" class="drafts-section">
        <h4 class="section-title">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
          </svg>
          Drafts ({{ activeDrafts.length }})
        </h4>
        <div class="drafts-list">
          <div
            v-for="draft in activeDrafts"
            :key="`draft-${draft.id}`"
            class="draft-item"
          >
            <div class="draft-content">
              <div class="draft-subject">{{ draft.subject || '(No subject)' }}</div>
              <div class="draft-meta">
                <span v-if="draft.recipient_email" class="draft-recipient">
                  To: {{ draft.recipient_name || draft.recipient_email }}
                </span>
                <span class="draft-time">{{ formatDraftTime(draft.updated_at) }}</span>
              </div>
            </div>
            <div class="draft-actions">
              <button
                class="action-btn resume-btn"
                title="Resume editing"
                @click="$emit('resume-draft', draft)"
              >
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <polygon points="5 3 19 12 5 21 5 3"></polygon>
                </svg>
                Resume
              </button>
              <button
                class="action-btn delete-btn"
                title="Delete draft"
                @click="confirmDeleteDraft(draft)"
              >
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <polyline points="3 6 5 6 21 6"></polyline>
                  <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                </svg>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Messages Section -->
      <div v-if="messages.length > 0" class="messages-section">
        <h4 v-if="activeDrafts.length > 0" class="section-title">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
            <polyline points="22,6 12,13 2,6"></polyline>
          </svg>
          Sent Messages
        </h4>
        <div class="messages">
          <div
            v-for="message in messages"
            :key="message.id"
            class="message-item"
          >
            <div class="message-header">
              <div class="message-recipient">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                  <polyline points="22,6 12,13 2,6"></polyline>
                </svg>
                <span>{{ message.to_email }}</span>
              </div>
              <span class="message-time">{{ message.sent_at_human }}</span>
            </div>

            <div class="message-subject">{{ message.subject }}</div>

            <div class="message-badges">
              <span class="badge badge-sent">Sent</span>
              <span v-if="message.is_opened" class="badge badge-opened">
                Opened{{ message.open_count > 1 ? ` (${message.open_count}x)` : '' }}
              </span>
              <span v-if="message.is_clicked" class="badge badge-clicked">Clicked</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Marked as Sent Section -->
      <div v-if="markedSent.length > 0" class="marked-sent-section">
        <h4 class="section-title section-title-muted">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
            <polyline points="22 4 12 14.01 9 11.01"></polyline>
          </svg>
          Manually Marked as Sent ({{ markedSent.length }})
        </h4>
        <div class="marked-sent-list">
          <div
            v-for="item in markedSent"
            :key="`marked-${item.id}`"
            class="marked-sent-item"
          >
            <div class="marked-sent-subject">{{ item.subject || '(No subject)' }}</div>
            <div class="marked-sent-meta">
              <span v-if="item.recipient_email">To: {{ item.recipient_name || item.recipient_email }}</span>
              <span class="marked-sent-time">Marked sent {{ formatDraftTime(item.marked_sent_at) }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div v-if="draftToDelete" class="modal-overlay" @click.self="draftToDelete = null">
      <div class="modal-dialog">
        <h4 class="modal-title">Delete Draft?</h4>
        <p class="modal-text">
          Are you sure you want to delete this draft? This action cannot be undone.
        </p>
        <div class="modal-actions">
          <button class="btn btn-secondary" @click="draftToDelete = null">Cancel</button>
          <button class="btn btn-danger" @click="handleDeleteDraft">Delete</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * MessageList Component
 *
 * Displays a list of sent messages and drafts with status badges.
 * Supports draft resume and delete actions.
 *
 * @package ShowAuthority
 * @since 5.0.0
 * @updated 5.4.0 - Added draft support
 */

import { ref, computed } from 'vue'

const props = defineProps({
  messages: {
    type: Array,
    default: () => []
  },
  drafts: {
    type: Array,
    default: () => []
  },
  loading: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits([
  'resume-draft',
  'delete-draft'
])

// State
const draftToDelete = ref(null)

// Computed
const activeDrafts = computed(() => {
  return props.drafts.filter(d => d.status === 'draft')
})

const markedSent = computed(() => {
  return props.drafts.filter(d => d.status === 'marked_sent')
})

/**
 * Format draft timestamp for display
 */
function formatDraftTime(timestamp) {
  if (!timestamp) return ''

  const date = new Date(timestamp)
  const now = new Date()
  const diffMs = now - date
  const diffMins = Math.floor(diffMs / 60000)
  const diffHours = Math.floor(diffMs / 3600000)
  const diffDays = Math.floor(diffMs / 86400000)

  if (diffMins < 1) return 'Just now'
  if (diffMins < 60) return `${diffMins}m ago`
  if (diffHours < 24) return `${diffHours}h ago`
  if (diffDays < 7) return `${diffDays}d ago`

  return date.toLocaleDateString()
}

/**
 * Show delete confirmation
 */
function confirmDeleteDraft(draft) {
  draftToDelete.value = draft
}

/**
 * Handle draft deletion
 */
function handleDeleteDraft() {
  if (draftToDelete.value) {
    emit('delete-draft', draftToDelete.value)
    draftToDelete.value = null
  }
}
</script>

<style scoped>
.message-list {
  flex: 1;
  overflow-y: auto;
}

/* Loading State */
.list-loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 40px 20px;
  gap: 12px;
  color: var(--color-text-secondary, #6b7280);
}

.spinner {
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

/* Empty State */
.list-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 60px 20px;
  text-align: center;
}

.empty-icon {
  color: var(--color-text-tertiary, #9ca3af);
  margin-bottom: 16px;
}

.empty-title {
  font-size: 16px;
  font-weight: 600;
  color: var(--color-text-primary, #1a1a1a);
  margin: 0 0 8px;
}

.empty-text {
  font-size: 14px;
  color: var(--color-text-secondary, #6b7280);
  margin: 0;
  max-width: 280px;
}

/* List Content */
.list-content {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

/* Section Titles */
.section-title {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
  color: var(--color-text-secondary, #6b7280);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  margin: 0 0 12px;
}

.section-title-muted {
  color: var(--color-text-tertiary, #9ca3af);
}

/* Drafts Section */
.drafts-section {
  background: #fffbeb;
  border: 1px solid #fcd34d;
  border-radius: 8px;
  padding: 16px;
}

.drafts-section .section-title {
  color: #b45309;
}

.drafts-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.draft-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px;
  background: white;
  border: 1px solid #fcd34d;
  border-radius: 6px;
}

.draft-content {
  flex: 1;
  min-width: 0;
}

.draft-subject {
  font-size: 14px;
  font-weight: 500;
  color: var(--color-text-primary, #1a1a1a);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.draft-meta {
  display: flex;
  gap: 12px;
  margin-top: 4px;
  font-size: 12px;
  color: var(--color-text-tertiary, #9ca3af);
}

.draft-recipient {
  color: var(--color-text-secondary, #6b7280);
}

.draft-actions {
  display: flex;
  gap: 8px;
  margin-left: 12px;
}

.action-btn {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 6px 10px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.15s ease;
}

.resume-btn {
  background: #f59e0b;
  border: none;
  color: white;
}

.resume-btn:hover {
  background: #d97706;
}

.delete-btn {
  background: white;
  border: 1px solid #e5e7eb;
  color: var(--color-text-secondary, #6b7280);
  padding: 6px 8px;
}

.delete-btn:hover {
  background: #fef2f2;
  border-color: #fca5a5;
  color: #dc2626;
}

/* Messages Section */
.messages {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.message-item {
  padding: 16px;
  background: var(--color-background, #fff);
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 8px;
  transition: border-color 0.2s;
}

.message-item:hover {
  border-color: var(--color-border-hover, #d1d5db);
}

.message-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
}

.message-recipient {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: var(--color-text-secondary, #6b7280);
}

.message-recipient svg {
  flex-shrink: 0;
}

.message-time {
  font-size: 12px;
  color: var(--color-text-tertiary, #9ca3af);
}

.message-subject {
  font-size: 14px;
  font-weight: 500;
  color: var(--color-text-primary, #1a1a1a);
  margin-bottom: 12px;
  line-height: 1.4;
}

.message-badges {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.badge {
  display: inline-flex;
  align-items: center;
  padding: 4px 10px;
  font-size: 11px;
  font-weight: 500;
  border-radius: 9999px;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}

.badge-sent {
  background: var(--color-gray-100, #f3f4f6);
  color: var(--color-gray-700, #374151);
}

.badge-opened {
  background: var(--color-blue-100, #dbeafe);
  color: var(--color-blue-700, #1d4ed8);
}

.badge-clicked {
  background: var(--color-green-100, #dcfce7);
  color: var(--color-green-700, #15803d);
}

/* Marked Sent Section */
.marked-sent-section {
  padding-top: 12px;
  border-top: 1px solid var(--color-border, #e5e7eb);
}

.marked-sent-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.marked-sent-item {
  padding: 10px 12px;
  background: var(--color-surface, #f9fafb);
  border-radius: 6px;
}

.marked-sent-subject {
  font-size: 13px;
  color: var(--color-text-secondary, #6b7280);
}

.marked-sent-meta {
  display: flex;
  gap: 12px;
  margin-top: 4px;
  font-size: 11px;
  color: var(--color-text-tertiary, #9ca3af);
}

/* Modal */
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.modal-dialog {
  background: white;
  border-radius: 12px;
  padding: 24px;
  max-width: 400px;
  width: 90%;
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
}

.modal-title {
  font-size: 18px;
  font-weight: 600;
  color: var(--color-text-primary, #1a1a1a);
  margin: 0 0 12px;
}

.modal-text {
  font-size: 14px;
  color: var(--color-text-secondary, #6b7280);
  margin: 0 0 20px;
  line-height: 1.5;
}

.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
}

.btn {
  padding: 8px 16px;
  border-radius: 6px;
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.15s ease;
}

.btn-secondary {
  background: white;
  border: 1px solid var(--color-border, #d1d5db);
  color: var(--color-text-primary, #374151);
}

.btn-secondary:hover {
  background: var(--color-surface, #f9fafb);
}

.btn-danger {
  background: #dc2626;
  border: none;
  color: white;
}

.btn-danger:hover {
  background: #b91c1c;
}
</style>
