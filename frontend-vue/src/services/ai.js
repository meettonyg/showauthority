/**
 * AI Service
 *
 * Handles AI-powered email refinement and generation.
 * All AI operations are tracked for cost management.
 *
 * @package ShowAuthority
 * @since 5.4.0
 */

import { createApiClient } from '../utils/apiConfig'

// Create API client using shared configuration
const api = createApiClient()

export default {
  /**
   * Refine email content using AI
   *
   * @param {Object} payload - Refinement request
   * @param {string} payload.subject - Current email subject
   * @param {string} payload.body - Current email body
   * @param {string} payload.instruction - Refinement instruction
   * @param {string} [payload.tone] - Desired tone (professional, friendly, casual, enthusiastic)
   * @param {string} [payload.length] - Desired length (short, medium, long)
   * @param {number} [payload.appearance_id] - Appearance ID for context/tracking
   * @returns {Promise<{success: boolean, data?: {subject: string, body: string}, message?: string}>}
   */
  async refineEmail(payload) {
    const response = await api.post('/pit-ai/refine', {
      subject: payload.subject,
      body: payload.body,
      instruction: payload.instruction,
      tone: payload.tone || 'professional',
      length: payload.length || 'medium',
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
