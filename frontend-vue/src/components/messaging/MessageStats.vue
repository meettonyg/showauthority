<template>
  <div class="message-stats" :class="{ 'unified-mode': unified }">
    <!-- Simple Stats (legacy mode) -->
    <template v-if="!unified">
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
    </template>

    <!-- Unified Stats (new enhanced mode) -->
    <template v-else>
      <!-- Delivery Row -->
      <div class="stats-row">
        <h4 class="row-title">Delivery</h4>
        <div class="stats-group">
          <div class="stat-item">
            <span class="stat-number">{{ unifiedStats.total_sent }}</span>
            <span class="stat-label">Total Sent</span>
          </div>
          <div class="stat-item">
            <span class="stat-number stat-secondary">{{ unifiedStats.emails_sent }}</span>
            <span class="stat-label">Single Emails</span>
          </div>
          <div class="stat-item">
            <span class="stat-number stat-secondary">{{ unifiedStats.campaign_emails_sent }}</span>
            <span class="stat-label">Campaign Emails</span>
          </div>
          <div v-if="unifiedStats.bounced > 0" class="stat-item">
            <span class="stat-number stat-danger">{{ unifiedStats.bounced }}</span>
            <span class="stat-label">Bounced ({{ formatRate(unifiedStats.bounce_rate) }})</span>
          </div>
        </div>
      </div>

      <!-- Engagement Row -->
      <div class="stats-row">
        <h4 class="row-title">Engagement</h4>
        <div class="stats-group">
          <div class="stat-item">
            <span class="stat-number">{{ unifiedStats.opened }}</span>
            <span class="stat-label">Opened ({{ formatRate(unifiedStats.open_rate) }})</span>
          </div>
          <div class="stat-item">
            <span class="stat-number">{{ unifiedStats.clicked }}</span>
            <span class="stat-label">Clicked ({{ formatRate(unifiedStats.click_rate) }})</span>
          </div>
          <div class="stat-item">
            <span class="stat-number stat-success">{{ unifiedStats.replied }}</span>
            <span class="stat-label">Replied ({{ formatRate(unifiedStats.reply_rate) }})</span>
          </div>
        </div>
      </div>

      <!-- Campaigns Row -->
      <div v-if="unifiedStats.active_campaigns > 0 || unifiedStats.completed_campaigns > 0" class="stats-row">
        <h4 class="row-title">Campaigns</h4>
        <div class="stats-group">
          <div class="stat-item">
            <span class="stat-number stat-campaign">{{ unifiedStats.active_campaigns }}</span>
            <span class="stat-label">Active</span>
          </div>
          <div class="stat-item">
            <span class="stat-number stat-success">{{ unifiedStats.completed_campaigns }}</span>
            <span class="stat-label">Completed</span>
          </div>
        </div>
      </div>
    </template>

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
 * Supports two modes:
 * - Legacy mode: Simple stats (total_sent, opened, clicked)
 * - Unified mode: Full dashboard matching Guestify Outreach analytics
 *
 * @package ShowAuthority
 * @since 5.0.0
 * @updated 5.1.0 - Added campaign stats and version display
 * @updated 5.2.0 - Added unified stats mode for campaign integration
 */

import { computed } from 'vue'

const props = defineProps({
  // Legacy simple stats
  stats: {
    type: Object,
    default: () => ({
      total_sent: 0,
      opened: 0,
      clicked: 0
    })
  },
  // Unified stats (full dashboard data)
  unifiedStats: {
    type: Object,
    default: () => ({
      total_sent: 0,
      emails_sent: 0,
      campaign_emails_sent: 0,
      opened: 0,
      open_rate: 0,
      clicked: 0,
      click_rate: 0,
      replied: 0,
      reply_rate: 0,
      bounced: 0,
      bounce_rate: 0,
      active_campaigns: 0,
      completed_campaigns: 0
    })
  },
  // Enable unified stats mode
  unified: {
    type: Boolean,
    default: false
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

// Format rate as percentage
function formatRate(rate) {
  if (rate === null || rate === undefined) return '0%'
  const num = parseFloat(rate)
  if (isNaN(num)) return '0%'
  return `${num.toFixed(1)}%`
}
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

/* Unified Mode Styles */
.message-stats.unified-mode {
  flex-direction: column;
  gap: 16px;
  padding: 20px;
}

.stats-row {
  width: 100%;
}

.row-title {
  margin: 0 0 10px 0;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: var(--color-text-tertiary, #9ca3af);
}

.unified-mode .stats-group {
  flex-wrap: wrap;
}

.unified-mode .stat-item {
  min-width: 80px;
}

.stat-number.stat-secondary {
  font-size: 20px;
  color: var(--color-text-secondary, #6b7280);
}

.stat-number.stat-success {
  color: var(--color-success, #10b981);
}

.stat-number.stat-danger {
  color: var(--color-error, #ef4444);
}

/* Stats row divider for unified mode */
.unified-mode .stats-row + .stats-row {
  padding-top: 16px;
  border-top: 1px solid var(--color-border-light, #f0f0f0);
}
</style>
