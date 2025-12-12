<template>
  <div class="message-stats">
    <div class="stat-item">
      <span class="stat-number">{{ stats.total_sent }}</span>
      <span class="stat-label">Sent</span>
    </div>
    <div class="stat-item">
      <span class="stat-number">{{ stats.opened }}</span>
      <span class="stat-label">Opened</span>
    </div>
    <div class="stat-item">
      <span class="stat-number">{{ stats.clicked }}</span>
      <span class="stat-label">Clicked</span>
    </div>
  </div>
</template>

<script setup>
/**
 * MessageStats Component
 *
 * Displays email statistics (sent, opened, clicked) for an appearance.
 *
 * @package ShowAuthority
 * @since 5.0.0
 */

defineProps({
  stats: {
    type: Object,
    default: () => ({
      total_sent: 0,
      opened: 0,
      clicked: 0
    })
  }
})
</script>

<style scoped>
.message-stats {
  display: flex;
  gap: 24px;
  padding: 16px 20px;
  background: var(--color-surface, #f8f9fa);
  border-radius: 8px;
  margin-bottom: 20px;
}

.stat-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}

.stat-number {
  font-size: 24px;
  font-weight: 600;
  color: var(--color-text-primary, #1a1a1a);
}

.stat-label {
  font-size: 12px;
  color: var(--color-text-secondary, #6b7280);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
</style>
