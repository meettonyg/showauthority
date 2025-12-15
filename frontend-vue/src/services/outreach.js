import axios from 'axios'

/**
 * Guestify Outreach API Service
 *
 * Handles all communication with the Guestify Outreach plugin's REST API.
 * This service calls the bridge endpoints which delegate to the Outreach
 * plugin's Public API.
 *
 * @package ShowAuthority
 * @since 5.0.0
 * @updated 5.1.0 - Added campaign management methods
 */

// WordPress REST API configuration
const getApiBase = () => {
  // Check for WordPress REST URL in window config
  if (window.pitConfig?.restUrl) {
    return window.pitConfig.restUrl.replace(/\/$/, '')
  }
  // Fallback for development
  return import.meta.env.VITE_API_URL || '/wp-json/guestify/v1'
}

const getNonce = () => {
  return window.pitConfig?.nonce || ''
}

const api = axios.create({
  headers: {
    'Content-Type': 'application/json'
  }
})

// Add nonce to all requests
api.interceptors.request.use(config => {
  config.baseURL = getApiBase()
  const nonce = getNonce()
  if (nonce) {
    config.headers['X-WP-Nonce'] = nonce
  }
  return config
})

export default {
  /**
   * Check if Outreach plugin is available and configured
   * @returns {Promise<{available: boolean, configured: boolean}>}
   */
  async getStatus() {
    const response = await api.get('/pit-bridge/status')
    return response.data
  },

  /**
   * Get email templates available to the current user
   * @returns {Promise<{data: Array}>}
   */
  async getTemplates() {
    const response = await api.get('/pit-bridge/templates')
    return response.data
  },

  /**
   * Get message history for an appearance/opportunity
   * @param {number} appearanceId - The appearance ID
   * @returns {Promise<{data: Array}>}
   */
  async getMessages(appearanceId) {
    const response = await api.get(`/pit-bridge/appearances/${appearanceId}/messages`)
    return response.data
  },

  /**
   * Get email statistics for an appearance/opportunity
   * @param {number} appearanceId - The appearance ID
   * @returns {Promise<{data: {total_sent: number, opened: number, clicked: number}}>}
   */
  async getStats(appearanceId) {
    const response = await api.get(`/pit-bridge/appearances/${appearanceId}/stats`)
    return response.data
  },

  /**
   * Send an email via Outreach plugin
   * @param {number} appearanceId - The appearance ID to link this email to
   * @param {Object} payload - Email data
   * @param {string} payload.to_email - Recipient email
   * @param {string} [payload.to_name] - Recipient name
   * @param {string} payload.subject - Email subject
   * @param {string} payload.body - Email body (plain text or HTML)
   * @param {number} [payload.template_id] - Optional template ID
   * @returns {Promise<{success: boolean, message: string, tracking_id?: string}>}
   */
  async sendEmail(appearanceId, payload) {
    const response = await api.post(`/pit-bridge/appearances/${appearanceId}/send`, payload)
    return response.data
  },

  // =========================================================================
  // Campaign Management (v2.0+ API)
  // =========================================================================

  /**
   * Get extended status with version info
   * @returns {Promise<{available: boolean, configured: boolean, has_api: boolean, version: string, api_version: number, features: Object}>}
   */
  async getExtendedStatus() {
    const response = await api.get('/pit-bridge/status/extended')
    return response.data
  },

  /**
   * Get campaigns for an appearance
   * @param {number} appearanceId - The appearance ID
   * @returns {Promise<{success: boolean, data: Array}>}
   */
  async getCampaigns(appearanceId) {
    const response = await api.get(`/pit-bridge/appearances/${appearanceId}/campaigns`)
    return response.data
  },

  /**
   * Start a new campaign for an appearance
   * @param {number} appearanceId - The appearance ID
   * @param {Object} payload - Campaign data
   * @param {string} payload.name - Campaign name
   * @param {number} [payload.template_id] - Template ID
   * @param {Array} [payload.steps] - Campaign steps
   * @returns {Promise<{success: boolean, message: string, campaign_id?: number}>}
   */
  async startCampaign(appearanceId, payload) {
    const response = await api.post(`/pit-bridge/appearances/${appearanceId}/campaigns`, payload)
    return response.data
  },

  /**
   * Pause a campaign
   * @param {number} campaignId - The campaign ID
   * @returns {Promise<{success: boolean, message: string}>}
   */
  async pauseCampaign(campaignId) {
    const response = await api.post(`/pit-bridge/campaigns/${campaignId}/pause`)
    return response.data
  },

  /**
   * Resume a paused campaign
   * @param {number} campaignId - The campaign ID
   * @returns {Promise<{success: boolean, message: string}>}
   */
  async resumeCampaign(campaignId) {
    const response = await api.post(`/pit-bridge/campaigns/${campaignId}/resume`)
    return response.data
  },

  /**
   * Cancel a campaign
   * @param {number} campaignId - The campaign ID
   * @returns {Promise<{success: boolean, message: string}>}
   */
  async cancelCampaign(campaignId) {
    const response = await api.post(`/pit-bridge/campaigns/${campaignId}/cancel`)
    return response.data
  },

  // =========================================================================
  // Sequence-Based Campaigns (v5.2.0+)
  // =========================================================================

  /**
   * Get all available sequences
   * @returns {Promise<{success: boolean, data: Array<{id: number, sequence_name: string, description: string, total_steps: number, is_active: boolean, steps: Array}>}>}
   */
  async getSequences() {
    const response = await api.get('/pit-bridge/sequences')
    return response.data
  },

  /**
   * Get a single sequence with steps
   * @param {number} sequenceId - The sequence ID
   * @returns {Promise<{success: boolean, data: {id: number, sequence_name: string, description: string, total_steps: number, is_active: boolean, steps: Array}}>}
   */
  async getSequence(sequenceId) {
    const response = await api.get(`/pit-bridge/sequences/${sequenceId}`)
    return response.data
  },

  /**
   * Start a sequence-based campaign for an appearance
   * Uses pre-built sequences from Guestify Outreach with template variable injection
   * @param {number} appearanceId - The appearance/opportunity ID
   * @param {Object} payload - Campaign data
   * @param {number} payload.sequence_id - The sequence ID to use
   * @param {string} payload.recipient_email - Recipient email address
   * @param {string} [payload.recipient_name] - Recipient name
   * @returns {Promise<{success: boolean, message: string, campaign_id?: number}>}
   */
  async startSequenceCampaign(appearanceId, payload) {
    const response = await api.post(`/pit-bridge/appearances/${appearanceId}/campaigns/sequence`, payload)
    return response.data
  },

  /**
   * Get unified stats for an appearance (single emails + campaigns combined)
   * Matches the analytics format from Guestify Outreach dashboard
   * @param {number} appearanceId - The appearance/opportunity ID
   * @returns {Promise<{success: boolean, data: {total_sent: number, emails_sent: number, campaign_emails_sent: number, opened: number, open_rate: number, clicked: number, click_rate: number, replied: number, reply_rate: number, bounced: number, bounce_rate: number, active_campaigns: number, completed_campaigns: number}}>}
   */
  async getUnifiedStats(appearanceId) {
    const response = await api.get(`/pit-bridge/appearances/${appearanceId}/unified-stats`)
    return response.data
  }
}
