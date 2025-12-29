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
// Supports multiple config object names used across different pages
const getApiBase = () => {
  // Check for WordPress REST URL in window config (try multiple names)
  const config = window.guestifyDetailData || window.pitConfig || window.guestifyData
  if (config?.restUrl) {
    return config.restUrl.replace(/\/$/, '')
  }
  // Fallback for development
  return import.meta.env.VITE_API_URL || '/wp-json/guestify/v1'
}

const getNonce = () => {
  const config = window.guestifyDetailData || window.pitConfig || window.guestifyData
  return config?.nonce || ''
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
  },

  // =========================================================================
  // Personalization Variables (v5.3.0+)
  // =========================================================================

  /**
   * Get personalization variables for an appearance
   * Returns template variables with their actual populated values from podcast/guest data
   * @param {number} appearanceId - The appearance/opportunity ID
   * @returns {Promise<{success: boolean, data: {categories: Array<{name: string, variables: Array<{tag: string, label: string, value: string}>}>}}>}
   */
  async getVariables(appearanceId) {
    const response = await api.get(`/appearances/${appearanceId}/variables`)
    return response.data
  },

  // =========================================================================
  // Template CRUD (v5.4.0+)
  // =========================================================================

  /**
   * Get a single template by ID
   * @param {number} templateId - The template ID
   * @returns {Promise<{success: boolean, data: Object}>}
   */
  async getTemplate(templateId) {
    const response = await api.get(`/pit-bridge/templates/${templateId}`)
    return response.data
  },

  /**
   * Create a new email template
   * Routes through Bridge to Guestify Outreach
   * @param {Object} payload - Template data
   * @param {string} payload.name - Template name
   * @param {string} payload.subject - Email subject
   * @param {string} payload.body - Email body HTML
   * @param {string} [payload.description] - Template description
   * @param {string} [payload.category] - Template category
   * @returns {Promise<{success: boolean, message: string, template_id?: number}>}
   */
  async createTemplate(payload) {
    const response = await api.post('/pit-bridge/templates', payload)
    return response.data
  },

  /**
   * Update an existing email template
   * Routes through Bridge to Guestify Outreach
   * @param {number} templateId - The template ID
   * @param {Object} payload - Updated template data
   * @param {string} [payload.name] - Template name
   * @param {string} [payload.subject] - Email subject
   * @param {string} [payload.body] - Email body HTML
   * @param {string} [payload.description] - Template description
   * @param {string} [payload.category] - Template category
   * @returns {Promise<{success: boolean, message: string}>}
   */
  async updateTemplate(templateId, payload) {
    const response = await api.put(`/pit-bridge/templates/${templateId}`, payload)
    return response.data
  },

  // =========================================================================
  // Draft Management (v5.4.0+)
  // =========================================================================

  /**
   * Get drafts for an appearance
   * @param {number} appearanceId - The appearance ID
   * @returns {Promise<{success: boolean, data: Array}>}
   */
  async getDrafts(appearanceId) {
    const response = await api.get(`/pit-bridge/appearances/${appearanceId}/drafts`)
    return response.data
  },

  /**
   * Save a draft email
   * Creates or updates a draft for the appearance
   * @param {number} appearanceId - The appearance ID
   * @param {Object} payload - Draft data
   * @param {number} [payload.draft_id] - Existing draft ID (for updates)
   * @param {string} [payload.draft_type] - 'single_email' or 'campaign_step'
   * @param {number} [payload.sequence_id] - Sequence ID (for campaign steps)
   * @param {number} [payload.step_number] - Step number (for campaign steps)
   * @param {string} [payload.recipient_email] - Recipient email
   * @param {string} [payload.recipient_name] - Recipient name
   * @param {string} [payload.subject] - Email subject
   * @param {string} [payload.body_html] - Email body HTML
   * @param {number} [payload.template_id] - Template ID used
   * @returns {Promise<{success: boolean, message: string, draft_id?: number}>}
   */
  async saveDraft(appearanceId, payload) {
    const response = await api.post(`/pit-bridge/appearances/${appearanceId}/drafts`, payload)
    return response.data
  },

  /**
   * Mark an email as manually sent
   * Creates a record without actually sending through the system
   * @param {number} appearanceId - The appearance ID
   * @param {Object} payload - Email data to record
   * @param {string} payload.recipient_email - Recipient email
   * @param {string} [payload.recipient_name] - Recipient name
   * @param {string} payload.subject - Email subject
   * @param {string} payload.body_html - Email body HTML
   * @param {number} [payload.draft_id] - Draft ID to convert to marked_sent
   * @returns {Promise<{success: boolean, message: string, draft_id?: number}>}
   */
  async markAsSent(appearanceId, payload) {
    const response = await api.post(`/pit-bridge/appearances/${appearanceId}/mark-sent`, payload)
    return response.data
  },

  /**
   * Delete a draft
   * @param {number} draftId - The draft ID
   * @returns {Promise<{success: boolean, message: string}>}
   */
  async deleteDraft(draftId) {
    const response = await api.delete(`/pit-bridge/drafts/${draftId}`)
    return response.data
  }
}
