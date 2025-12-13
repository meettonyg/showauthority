<template>
  <div class="message-stats">
    <!-- Email Stats -->
    <div class="stats-group">
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

    <!-- Campaign Stats (if available) -->
    <div v-if="showCampaignStats && activeCampaigns > 0" class="stats-group stats-campaign">
      <div class="stat-item">
        <span class="stat-number stat-campaign">{{ activeCampaigns }}</span>
        <span class="stat-label">Active Campaigns</span>
      </div>
    </div>

    <!-- Version Badge -->
    <div v-if="showVersion && version" class="version-badge" :title="versionTooltip">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path>
      </svg>
      v{{ version }}
    </div>
  </div>
</template>

<script setup>
/**
 * MessageStats Component
 *
 * Displays email statistics (sent, opened, clicked) for an appearance.
 * Enhanced to show campaign stats and version info.
 *
 * @package ShowAuthority
 * @since 5.0.0
 * @updated 5.1.0 - Added campaign stats and version display
 */

import { computed } from 'vue'

const props = defineProps({
  stats: {
    type: Object,
    default: () => ({
      total_sent: 0,
      opened: 0,
      clicked: 0
    })
  },
  activeCampaigns: {
    type: Number,
    default: 0
  },
  showCampaignStats: {
    type: Boolean,
    default: true
  },
  version: {
    type: String,
    default: null
  },
  apiVersion: {
    type: Number,
    default: null
  },
  showVersion: {
    type: Boolean,
    default: false
  }
})

const versionTooltip = computed(() => {
  if (props.apiVersion) {
    return `Guestify Outreach v${props.version} (API v${props.apiVersion})`
  }
  return `Guestify Outreach v${props.version}`
})
</script>

<style scoped>
.message-stats {
  display: flex;
  align-items: center;
  gap: 24px;
  padding: 16px 20px;
  background: var(--color-surface, #f8f9fa);
  border-radius: 8px;
  margin-bottom: 20px;
  position: relative;
}

.stats-group {
  display: flex;
  gap: 24px;
}

.stats-campaign {
  padding-left: 24px;
  border-left: 1px solid var(--color-border, #e5e7eb);
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

.stat-number.stat-campaign {
  color: var(--color-primary, #6366f1);
}

.stat-label {
  font-size: 12px;
  color: var(--color-text-secondary, #6b7280);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  white-space: nowrap;
}

.version-badge {
  position: absolute;
  top: 8px;
  right: 12px;
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 2px 8px;
  font-size: 10px;
  font-weight: 500;
  color: var(--color-text-tertiary, #9ca3af);
  background: var(--color-background, #fff);
  border-radius: 4px;
  cursor: help;
}

.version-badge svg {
  opacity: 0.7;
}
</style>
