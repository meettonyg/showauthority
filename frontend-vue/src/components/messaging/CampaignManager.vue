<template>
  <div class="campaign-manager">
    <!-- Loading State -->
    <div v-if="loading" class="loading-state">
      <div class="spinner"></div>
      <span>Loading campaigns...</span>
    </div>

    <!-- Not Available State -->
    <div v-else-if="!hasCampaigns" class="not-available">
      <svg class="icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
        <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path>
      </svg>
      <span>Campaign management requires Guestify Outreach v2.0+</span>
    </div>

    <!-- Campaigns List -->
    <div v-else class="campaigns-content">
      <!-- Header -->
      <div class="campaigns-header">
        <h4 class="section-title">Campaigns</h4>
        <button
          v-if="campaigns.length === 0 || allowMultiple"
          class="btn btn-sm btn-secondary"
          @click="showCreateModal = true"
          :disabled="actionLoading"
        >
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="12" y1="5" x2="12" y2="19"></line>
            <line x1="5" y1="12" x2="19" y2="12"></line>
          </svg>
          New Campaign
        </button>
      </div>

      <!-- Empty State -->
      <div v-if="campaigns.length === 0" class="empty-state">
        <svg class="empty-icon" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path>
        </svg>
        <p>No campaigns yet</p>
        <span class="empty-hint">Create a campaign to automate your outreach emails.</span>
      </div>

      <!-- Campaign Cards -->
      <div v-else class="campaigns-list">
        <div
          v-for="campaign in campaigns"
          :key="campaign.id"
          class="campaign-card"
          :class="{ 'campaign-active': isActive(campaign) }"
        >
          <div class="campaign-header">
            <div class="campaign-info">
              <span class="campaign-name">{{ campaign.name }}</span>
              <span class="campaign-status" :class="'status-' + campaign.status">
                {{ formatStatus(campaign.status) }}
              </span>
            </div>
            <div class="campaign-actions">
              <button
                v-if="campaign.status === 'paused'"
                class="action-btn action-resume"
                @click="handleResume(campaign.id)"
                :disabled="actionLoading"
                title="Resume campaign"
              >
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <polygon points="5 3 19 12 5 21 5 3"></polygon>
                </svg>
              </button>
              <button
                v-if="isActive(campaign)"
                class="action-btn action-pause"
                @click="handlePause(campaign.id)"
                :disabled="actionLoading"
                title="Pause campaign"
              >
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <rect x="6" y="4" width="4" height="16"></rect>
                  <rect x="14" y="4" width="4" height="16"></rect>
                </svg>
              </button>
              <button
                v-if="canCancel(campaign)"
                class="action-btn action-cancel"
                @click="handleCancel(campaign.id)"
                :disabled="actionLoading"
                title="Cancel campaign"
              >
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <line x1="18" y1="6" x2="6" y2="18"></line>
                  <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
              </button>
            </div>
          </div>

          <div class="campaign-meta">
            <span v-if="campaign.created_at" class="meta-item">
              Created {{ formatDate(campaign.created_at) }}
            </span>
            <span v-if="campaign.emails_sent" class="meta-item">
              {{ campaign.emails_sent }} emails sent
            </span>
          </div>

          <div v-if="campaign.current_step" class="campaign-progress">
            <div class="progress-bar">
              <div
                class="progress-fill"
                :style="{ width: getProgress(campaign) + '%' }"
              ></div>
            </div>
            <span class="progress-text">
              Step {{ campaign.current_step }} of {{ campaign.total_steps || '?' }}
            </span>
          </div>
        </div>
      </div>

      <!-- Error State -->
      <div v-if="error" class="error-message">
        {{ error }}
      </div>
    </div>

    <!-- Create Campaign Modal -->
    <Teleport to="body">
      <Transition name="modal">
        <div v-if="showCreateModal" class="modal-overlay" @click.self="closeCreateModal">
          <div class="modal-container">
            <div class="modal-header">
              <h3 class="modal-title">Create Campaign</h3>
              <button class="modal-close" @click="closeCreateModal" :disabled="actionLoading">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <line x1="18" y1="6" x2="6" y2="18"></line>
                  <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
              </button>
            </div>

            <div class="modal-body">
              <div class="form-group">
                <label class="form-label">Campaign Name <span class="required">*</span></label>
                <input
                  v-model="newCampaign.name"
                  type="text"
                  class="form-input"
                  placeholder="e.g., Initial Outreach"
                  :disabled="actionLoading"
                />
              </div>

              <div v-if="templates.length > 0" class="form-group">
                <label class="form-label">Email Template</label>
                <select
                  v-model="newCampaign.template_id"
                  class="form-input"
                  :disabled="actionLoading"
                >
                  <option :value="null">Select a template (optional)</option>
                  <option
                    v-for="template in templates"
                    :key="template.id"
                    :value="template.id"
                  >
                    {{ template.name }}
                  </option>
                </select>
              </div>
            </div>

            <div class="modal-footer">
              <button
                class="btn btn-secondary"
                @click="closeCreateModal"
                :disabled="actionLoading"
              >
                Cancel
              </button>
              <button
                class="btn btn-primary"
                @click="handleCreateCampaign"
                :disabled="actionLoading || !newCampaign.name.trim()"
              >
                <span v-if="actionLoading" class="btn-spinner"></span>
                {{ actionLoading ? 'Creating...' : 'Create Campaign' }}
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script setup>
/**
 * CampaignManager Component
 *
 * Displays and manages email campaigns for an appearance.
 * Supports creating, pausing, resuming, and cancelling campaigns.
 *
 * @package ShowAuthority
 * @since 5.1.0
 */

import { ref, computed, watch } from 'vue'
import { useMessagesStore } from '../../stores/messages'

const props = defineProps({
  /**
   * The appearance/opportunity ID
   */
  appearanceId: {
    type: Number,
    required: true
  },
  /**
   * Allow multiple campaigns (default false - one at a time)
   */
  allowMultiple: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['campaign-started', 'campaign-updated'])

// Store
const messagesStore = useMessagesStore()

// Local state
const showCreateModal = ref(false)
const newCampaign = ref({
  name: '',
  template_id: null
})

// Computed
const hasCampaigns = computed(() => messagesStore.hasCampaigns)
const loading = computed(() => messagesStore.campaignsLoading)
const actionLoading = computed(() => messagesStore.campaignActionLoading)
const error = computed(() => messagesStore.campaignActionError)
const templates = computed(() => messagesStore.templates)

const campaigns = computed(() => {
  return messagesStore.getCampaignsForAppearance(props.appearanceId)
})

// Watch for appearance changes
watch(() => props.appearanceId, async (newId) => {
  if (newId && hasCampaigns.value) {
    await messagesStore.loadCampaigns(newId)
  }
}, { immediate: true })

// Methods
function isActive(campaign) {
  return campaign.status === 'active' || campaign.status === 'running'
}

function canCancel(campaign) {
  return ['active', 'running', 'paused', 'scheduled'].includes(campaign.status)
}

function formatStatus(status) {
  const statusMap = {
    active: 'Active',
    running: 'Running',
    paused: 'Paused',
    completed: 'Completed',
    cancelled: 'Cancelled',
    scheduled: 'Scheduled',
    draft: 'Draft'
  }
  return statusMap[status] || status
}

function formatDate(dateString) {
  if (!dateString) return ''
  const date = new Date(dateString)
  const now = new Date()
  const diffMs = now - date
  const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24))

  if (diffDays === 0) return 'today'
  if (diffDays === 1) return 'yesterday'
  if (diffDays < 7) return `${diffDays} days ago`

  return date.toLocaleDateString()
}

function getProgress(campaign) {
  if (!campaign.current_step || !campaign.total_steps) return 0
  return Math.round((campaign.current_step / campaign.total_steps) * 100)
}

function closeCreateModal() {
  if (actionLoading.value) return
  showCreateModal.value = false
  newCampaign.value = { name: '', template_id: null }
}

async function handleCreateCampaign() {
  if (!newCampaign.value.name.trim()) return

  const result = await messagesStore.startCampaign(props.appearanceId, {
    name: newCampaign.value.name.trim(),
    template_id: newCampaign.value.template_id
  })

  if (result.success) {
    closeCreateModal()
    emit('campaign-started', result)
  }
}

async function handlePause(campaignId) {
  const result = await messagesStore.pauseCampaign(campaignId, props.appearanceId)
  if (result.success) {
    emit('campaign-updated', { action: 'paused', campaignId })
  }
}

async function handleResume(campaignId) {
  const result = await messagesStore.resumeCampaign(campaignId, props.appearanceId)
  if (result.success) {
    emit('campaign-updated', { action: 'resumed', campaignId })
  }
}

async function handleCancel(campaignId) {
  if (!confirm('Are you sure you want to cancel this campaign? This cannot be undone.')) {
    return
  }

  const result = await messagesStore.cancelCampaign(campaignId, props.appearanceId)
  if (result.success) {
    emit('campaign-updated', { action: 'cancelled', campaignId })
  }
}

// Expose refresh method
defineExpose({
  refresh: () => messagesStore.loadCampaigns(props.appearanceId, true)
})
</script>

<style scoped>
.campaign-manager {
  padding: 16px 0;
}

/* Loading & Not Available */
.loading-state,
.not-available {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 16px;
  color: var(--color-text-secondary, #6b7280);
  font-size: 14px;
}

.spinner {
  width: 18px;
  height: 18px;
  border: 2px solid var(--color-border, #e5e7eb);
  border-top-color: var(--color-primary, #6366f1);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.icon {
  flex-shrink: 0;
}

/* Header */
.campaigns-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}

.section-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-text-primary, #1a1a1a);
  margin: 0;
}

/* Buttons */
.btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  font-size: 13px;
  font-weight: 500;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  transition: background 0.2s, opacity 0.2s;
}

.btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.btn-sm {
  padding: 6px 12px;
  font-size: 12px;
}

.btn-secondary {
  background: var(--color-background, #fff);
  border: 1px solid var(--color-border, #e5e7eb);
  color: var(--color-text-primary, #1a1a1a);
}

.btn-secondary:hover:not(:disabled) {
  background: var(--color-surface, #f8f9fa);
}

.btn-primary {
  background: var(--color-primary, #6366f1);
  color: white;
}

.btn-primary:hover:not(:disabled) {
  background: var(--color-primary-dark, #4f46e5);
}

.btn-spinner {
  width: 12px;
  height: 12px;
  border: 2px solid rgba(255, 255, 255, 0.3);
  border-top-color: white;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

/* Empty State */
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 32px;
  text-align: center;
}

.empty-icon {
  color: var(--color-text-tertiary, #9ca3af);
  margin-bottom: 12px;
}

.empty-state p {
  font-size: 14px;
  font-weight: 500;
  color: var(--color-text-primary, #1a1a1a);
  margin: 0 0 4px;
}

.empty-hint {
  font-size: 13px;
  color: var(--color-text-secondary, #6b7280);
}

/* Campaigns List */
.campaigns-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

/* Campaign Card */
.campaign-card {
  padding: 14px;
  background: var(--color-background, #fff);
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 8px;
  transition: border-color 0.2s;
}

.campaign-card:hover {
  border-color: var(--color-border-hover, #d1d5db);
}

.campaign-card.campaign-active {
  border-color: var(--color-success, #22c55e);
}

.campaign-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 8px;
}

.campaign-info {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.campaign-name {
  font-size: 14px;
  font-weight: 500;
  color: var(--color-text-primary, #1a1a1a);
}

.campaign-status {
  font-size: 11px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.3px;
  padding: 2px 8px;
  border-radius: 9999px;
  width: fit-content;
}

.status-active,
.status-running {
  background: var(--color-green-100, #dcfce7);
  color: var(--color-green-700, #15803d);
}

.status-paused {
  background: var(--color-yellow-100, #fef9c3);
  color: var(--color-yellow-700, #a16207);
}

.status-completed {
  background: var(--color-blue-100, #dbeafe);
  color: var(--color-blue-700, #1d4ed8);
}

.status-cancelled {
  background: var(--color-gray-100, #f3f4f6);
  color: var(--color-gray-600, #4b5563);
}

.status-scheduled {
  background: var(--color-purple-100, #f3e8ff);
  color: var(--color-purple-700, #7c3aed);
}

.campaign-actions {
  display: flex;
  gap: 4px;
}

.action-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border: none;
  background: transparent;
  border-radius: 6px;
  cursor: pointer;
  transition: background 0.2s, color 0.2s;
}

.action-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.action-resume {
  color: var(--color-green-600, #16a34a);
}

.action-resume:hover:not(:disabled) {
  background: var(--color-green-50, #f0fdf4);
}

.action-pause {
  color: var(--color-yellow-600, #ca8a04);
}

.action-pause:hover:not(:disabled) {
  background: var(--color-yellow-50, #fefce8);
}

.action-cancel {
  color: var(--color-red-600, #dc2626);
}

.action-cancel:hover:not(:disabled) {
  background: var(--color-red-50, #fef2f2);
}

/* Campaign Meta */
.campaign-meta {
  display: flex;
  gap: 12px;
  font-size: 12px;
  color: var(--color-text-secondary, #6b7280);
}

.meta-item {
  display: flex;
  align-items: center;
  gap: 4px;
}

/* Progress Bar */
.campaign-progress {
  margin-top: 10px;
  padding-top: 10px;
  border-top: 1px solid var(--color-border-light, #f3f4f6);
}

.progress-bar {
  height: 4px;
  background: var(--color-gray-100, #f3f4f6);
  border-radius: 2px;
  overflow: hidden;
  margin-bottom: 4px;
}

.progress-fill {
  height: 100%;
  background: var(--color-primary, #6366f1);
  transition: width 0.3s ease;
}

.progress-text {
  font-size: 11px;
  color: var(--color-text-tertiary, #9ca3af);
}

/* Error */
.error-message {
  margin-top: 12px;
  padding: 10px 14px;
  background: var(--color-error-bg, #fef2f2);
  border: 1px solid var(--color-error-border, #fecaca);
  border-radius: 6px;
  color: var(--color-error, #ef4444);
  font-size: 13px;
}

/* Modal */
.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  z-index: 1000;
}

.modal-container {
  width: 100%;
  max-width: 420px;
  background: var(--color-background, #fff);
  border-radius: 12px;
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
  overflow: hidden;
}

.modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
  border-bottom: 1px solid var(--color-border, #e5e7eb);
}

.modal-title {
  font-size: 16px;
  font-weight: 600;
  color: var(--color-text-primary, #1a1a1a);
  margin: 0;
}

.modal-close {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border: none;
  background: transparent;
  border-radius: 6px;
  color: var(--color-text-secondary, #6b7280);
  cursor: pointer;
}

.modal-close:hover:not(:disabled) {
  background: var(--color-surface, #f8f9fa);
}

.modal-body {
  padding: 20px;
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  padding: 16px 20px;
  border-top: 1px solid var(--color-border, #e5e7eb);
  background: var(--color-surface, #f8f9fa);
}

/* Form */
.form-group {
  margin-bottom: 16px;
}

.form-group:last-child {
  margin-bottom: 0;
}

.form-label {
  display: block;
  font-size: 14px;
  font-weight: 500;
  color: var(--color-text-primary, #1a1a1a);
  margin-bottom: 6px;
}

.required {
  color: var(--color-error, #ef4444);
}

.form-input {
  width: 100%;
  padding: 10px 12px;
  font-size: 14px;
  border: 1px solid var(--color-border, #e5e7eb);
  border-radius: 6px;
  background: var(--color-background, #fff);
  color: var(--color-text-primary, #1a1a1a);
}

.form-input:focus {
  outline: none;
  border-color: var(--color-primary, #6366f1);
  box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.form-input:disabled {
  background: var(--color-surface, #f8f9fa);
  cursor: not-allowed;
}

/* Modal Transition */
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.2s ease;
}

.modal-enter-active .modal-container,
.modal-leave-active .modal-container {
  transition: transform 0.2s ease;
}

.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}

.modal-enter-from .modal-container,
.modal-leave-to .modal-container {
  transform: scale(0.95);
}
</style>
