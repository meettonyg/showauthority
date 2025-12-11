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
  }
}
