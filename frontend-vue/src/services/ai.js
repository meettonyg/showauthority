import axios from 'axios'

/**
 * AI Service
 *
 * Handles AI-powered email refinement and generation.
 * All AI operations are tracked for cost management.
 *
 * @package ShowAuthority
 * @since 5.4.0
 */

// WordPress REST API configuration
const getApiBase = () => {
  const config = window.guestifyDetailData || window.pitConfig || window.guestifyData
  if (config?.restUrl) {
    return config.restUrl.replace(/\/$/, '')
  }
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
   * Refine email content using AI
   *
   * @param {Object} payload - Refinement request
   * @param {string} payload.subject - Current email subject
   * @param {string} payload.body - Current email body
   * @param {string} payload.instruction - Refinement instruction
   * @param {number} [payload.appearance_id] - Appearance ID for context/tracking
   * @returns {Promise<{success: boolean, data?: {subject: string, body: string}, message?: string}>}
   */
  async refineEmail(payload) {
    const response = await api.post('/pit-ai/refine', {
      subject: payload.subject,
      body: payload.body,
      instruction: payload.instruction,
      appearance_id: payload.appearance_id
    })
    return response.data
  },

  /**
   * Generate email content from scratch using AI
   *
   * @param {Object} payload - Generation request
   * @param {number} payload.appearance_id - Appearance ID for context
   * @param {string} payload.purpose - Purpose of the email (e.g., 'pitch', 'follow_up', 'thank_you')
   * @param {string} [payload.additional_context] - Additional context/instructions
   * @returns {Promise<{success: boolean, data?: {subject: string, body: string}, message?: string}>}
   */
  async generateEmail(payload) {
    const response = await api.post('/pit-ai/generate', {
      appearance_id: payload.appearance_id,
      purpose: payload.purpose,
      additional_context: payload.additional_context
    })
    return response.data
  },

  /**
   * Get AI usage stats for the current user
   *
   * @returns {Promise<{success: boolean, data?: {total_requests: number, total_cost: number, requests_this_month: number, cost_this_month: number}}>}
   */
  async getUsageStats() {
    const response = await api.get('/pit-ai/usage')
    return response.data
  }
}
