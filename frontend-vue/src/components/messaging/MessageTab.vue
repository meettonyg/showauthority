<template>
  <div class="message-tab">
    <!-- Not Available State -->
    <div v-if="!messagesStore.available" class="tab-empty">
      <svg class="empty-icon" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
        <polyline points="22,6 12,13 2,6"></polyline>
      </svg>
      <h3 class="empty-title">Email Integration Required</h3>
      <p class="empty-text">Install and activate the Guestify Outreach plugin to send emails from here.</p>
    </div>

    <!-- Not Configured State -->
    <div v-else-if="!messagesStore.configured" class="tab-empty">
      <svg class="empty-icon" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
        <circle cx="12" cy="12" r="10"></circle>
        <line x1="12" y1="8" x2="12" y2="12"></line>
        <line x1="12" y1="16" x2="12.01" y2="16"></line>
      </svg>
      <h3 class="empty-title">Email Not Configured</h3>
      <p class="empty-text">Please configure your Brevo API key in Guestify Outreach settings.</p>
    </div>

    <!-- Email Interface -->
    <div v-else class="email-interface">
      <!-- Stats -->
      <MessageStats
        :stats="stats"
        :unified-stats="unifiedStats"
        :unified="useUnifiedStats"
        :active-campaigns="activeCampaignsCount"
        :show-campaign-stats="messagesStore.hasCampaigns"
        :version="messagesStore.version"
        :api-version="messagesStore.apiVersion"
        :show-version="true"
      />

      <!-- Campaign Manager (v2.0+) -->
      <CampaignManager
        v-if="messagesStore.hasCampaigns"
        ref="campaignManagerRef"
        :appearance-id="appearanceId"
        @campaign-started="handleCampaignStarted"
        @campaign-updated="handleCampaignUpdated"
      />

      <!-- Header with Compose Button -->
      <div class="interface-header">
        <h3 class="section-title">Messages</h3>
        <button class="btn btn-primary" @click="showComposer = true">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="12" y1="5" x2="12" y2="19"></line>
            <line x1="5" y1="12" x2="19" y2="12"></line>
          </svg>
          Compose Email
        </button>
      </div>

      <!-- Message List -->
      <MessageList
        :messages="messages"
        :loading="messagesStore.messagesLoading"
      />
    </div>

    <!-- Compose Modal -->
    <MessageComposer
      ref="composerRef"
      :show="showComposer"
      :appearance-id="appearanceId"
      :templates="messagesStore.templates"
      :sequences="messagesStore.sequences"
      :sequences-loading="messagesStore.sequencesLoading"
      :default-email="defaultRecipientEmail"
      :default-name="defaultRecipientName"
      :sending="messagesStore.sending || messagesStore.campaignActionLoading"
      :error="messagesStore.sendError || messagesStore.campaignActionError"
      @close="handleComposerClose"
      @send="handleSendEmail"
      @start-campaign="handleStartSequenceCampaign"
    />
  </div>
</template>

<script setup>
/**
 * MessageTab Component
 *
 * Main container for the messaging tab in the interview detail view.
 * Orchestrates the messages store and child components.
 *
 * @package ShowAuthority
 * @since 5.0.0
 * @updated 5.1.0 - Added campaign management support
 */

import { ref, computed, onMounted, watch } from 'vue'
import { useMessagesStore } from '../../stores/messages'
import MessageStats from './MessageStats.vue'
import MessageList from './MessageList.vue'
import MessageComposer from './MessageComposer.vue'
import CampaignManager from './CampaignManager.vue'

const props = defineProps({
  /**
   * The appearance/opportunity ID
   */
  appearanceId: {
    type: Number,
    required: true
  },
  /**
   * Default recipient email (e.g., host email from interview data)
   */
  defaultRecipientEmail: {
    type: String,
    default: ''
  },
  /**
   * Default recipient name (e.g., host name from interview data)
   */
  defaultRecipientName: {
    type: String,
    default: ''
  }
})

const emit = defineEmits(['email-sent', 'campaign-started', 'campaign-updated'])

// Store
const messagesStore = useMessagesStore()

// UI state
const showComposer = ref(false)
const composerRef = ref(null)
const campaignManagerRef = ref(null)

// Computed
const messages = computed(() => {
  return messagesStore.getMessagesForAppearance(props.appearanceId)
})

const stats = computed(() => {
  return messagesStore.getStatsForAppearance(props.appearanceId)
})

const unifiedStats = computed(() => {
  return messagesStore.getUnifiedStatsForAppearance(props.appearanceId)
})

const activeCampaignsCount = computed(() => {
  const campaigns = messagesStore.getActiveCampaignsForAppearance(props.appearanceId)
  return campaigns.length
})

// Use unified stats if campaigns/sequences are available
const useUnifiedStats = computed(() => {
  return messagesStore.hasCampaigns
})

// Initialize on mount
onMounted(async () => {
  await messagesStore.initialize(props.appearanceId)
})

// Re-initialize if appearanceId changes
watch(() => props.appearanceId, async (newId, oldId) => {
  if (newId !== oldId) {
    await messagesStore.initialize(newId)
  }
})

// Handle composer close
function handleComposerClose() {
  showComposer.value = false
}

// Handle send email
async function handleSendEmail(emailData) {
  const result = await messagesStore.sendEmail(props.appearanceId, emailData)

  if (result.success) {
    showComposer.value = false
    composerRef.value?.resetForm()
    emit('email-sent', result)
  }
}

// Handle start sequence campaign (from MessageComposer)
async function handleStartSequenceCampaign(campaignData) {
  const result = await messagesStore.startSequenceCampaign(props.appearanceId, campaignData)

  if (result.success) {
    showComposer.value = false
    composerRef.value?.resetForm()
    emit('campaign-started', result)
  }
}

// Handle campaign events (from CampaignManager)
function handleCampaignStarted(result) {
  emit('campaign-started', result)
  // Refresh stats to reflect new campaign
  messagesStore.loadStats(props.appearanceId)
  messagesStore.loadUnifiedStats(props.appearanceId)
}

function handleCampaignUpdated(event) {
  emit('campaign-updated', event)
  // Refresh stats when campaign status changes
  messagesStore.loadStats(props.appearanceId)
}

// Expose methods for parent components
defineExpose({
  refresh: () => messagesStore.initialize(props.appearanceId),
  refreshCampaigns: () => campaignManagerRef.value?.refresh()
})
</script>

<style scoped>
.message-tab {
  height: 100%;
  display: flex;
  flex-direction: column;
}

/* Empty States */
.tab-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 60px 20px;
  text-align: center;
  flex: 1;
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
  max-width: 320px;
}

/* Email Interface */
.email-interface {
  display: flex;
  flex-direction: column;
  height: 100%;
  padding: 20px;
}

.interface-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}

.section-title {
  font-size: 16px;
  font-weight: 600;
  color: var(--color-text-primary, #1a1a1a);
  margin: 0;
}

/* Button */
.btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 16px;
  font-size: 14px;
  font-weight: 500;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  transition: background 0.2s;
}

.btn-primary {
  background: var(--color-primary, #6366f1);
  color: white;
}

.btn-primary:hover {
  background: var(--color-primary-dark, #4f46e5);
}

.btn-primary svg {
  flex-shrink: 0;
}
</style>
