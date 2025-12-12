<template>
  <div class="message-list">
    <!-- Loading State -->
    <div v-if="loading" class="list-loading">
      <div class="spinner"></div>
      <span>Loading messages...</span>
    </div>

    <!-- Empty State -->
    <div v-else-if="messages.length === 0" class="list-empty">
      <svg class="empty-icon" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
        <polyline points="22,6 12,13 2,6"></polyline>
      </svg>
      <h3 class="empty-title">No Messages Yet</h3>
      <p class="empty-text">Click "Compose Email" to send your first message to this contact.</p>
    </div>

    <!-- Messages -->
    <div v-else class="messages">
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
</template>

<script setup>
/**
 * MessageList Component
 *
 * Displays a list of sent messages with status badges.
 *
 * @package ShowAuthority
 * @since 5.0.0
 */

defineProps({
  messages: {
    type: Array,
    default: () => []
  },
  loading: {
    type: Boolean,
    default: false
  }
})
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

/* Messages */
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
</style>
