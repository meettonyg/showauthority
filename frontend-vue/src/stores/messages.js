import { defineStore } from 'pinia'
import outreachApi from '../services/outreach'

/**
 * Messages Store
 *
 * Pinia store for managing email messaging state via Guestify Outreach integration.
 * Handles templates, message history, sending emails, and tracking stats.
 *
 * @package ShowAuthority
 * @since 5.0.0
 */
export const useMessagesStore = defineStore('messages', {
  state: () => ({
    // Integration status
    available: false,
    configured: false,
    statusChecked: false,

    // Templates
    templates: [],
    templatesLoading: false,
    templatesError: null,

    // Messages (per appearance)
    messagesByAppearance: {}, // { [appearanceId]: Message[] }
    messagesLoading: false,
    messagesError: null,

    // Stats (per appearance)
    statsByAppearance: {}, // { [appearanceId]: Stats }
    statsLoading: false,

    // Sending state
    sending: false,
    sendError: null,
    lastSentResult: null
  }),

  getters: {
    /**
     * Check if integration is ready to use
     */
    isReady: (state) => state.available && state.configured,

    /**
     * Get messages for a specific appearance
     */
    getMessagesForAppearance: (state) => (appearanceId) => {
      return state.messagesByAppearance[appearanceId] || []
    },

    /**
     * Get stats for a specific appearance
     */
    getStatsForAppearance: (state) => (appearanceId) => {
      return state.statsByAppearance[appearanceId] || {
        total_sent: 0,
        opened: 0,
        clicked: 0
      }
    },

    /**
     * Get template by ID
     */
    getTemplateById: (state) => (id) => {
      return state.templates.find(t => t.id === id)
    },

    /**
     * Group templates by category
     */
    templatesByCategory: (state) => {
      const grouped = {}
      for (const template of state.templates) {
        const category = template.category || 'General'
        if (!grouped[category]) {
          grouped[category] = []
        }
        grouped[category].push(template)
      }
      return grouped
    }
  },

  actions: {
    /**
     * Check if Outreach plugin is available and configured
     */
    async checkStatus() {
      try {
        const result = await outreachApi.getStatus()
        this.available = result.available
        this.configured = result.configured
        this.statusChecked = true
        return result
      } catch (error) {
        console.error('Failed to check Outreach status:', error)
        this.available = false
        this.configured = false
        this.statusChecked = true
        return { available: false, configured: false }
      }
    },

    /**
     * Load email templates
     */
    async loadTemplates() {
      if (!this.available) return

      this.templatesLoading = true
      this.templatesError = null

      try {
        const result = await outreachApi.getTemplates()
        this.templates = result.data || []
      } catch (error) {
        console.error('Failed to load templates:', error)
        this.templatesError = error.message || 'Failed to load templates'
        this.templates = []
      } finally {
        this.templatesLoading = false
      }
    },

    /**
     * Load messages for an appearance
     * @param {number} appearanceId
     * @param {boolean} force - Force reload even if cached
     */
    async loadMessages(appearanceId, force = false) {
      if (!this.available) return

      // Skip if already loaded and not forcing
      if (!force && this.messagesByAppearance[appearanceId]) {
        return
      }

      this.messagesLoading = true
      this.messagesError = null

      try {
        const result = await outreachApi.getMessages(appearanceId)
        this.messagesByAppearance[appearanceId] = result.data || []
      } catch (error) {
        console.error('Failed to load messages:', error)
        this.messagesError = error.message || 'Failed to load messages'
      } finally {
        this.messagesLoading = false
      }
    },

    /**
     * Load stats for an appearance
     * @param {number} appearanceId
     */
    async loadStats(appearanceId) {
      if (!this.available) return

      this.statsLoading = true

      try {
        const result = await outreachApi.getStats(appearanceId)
        this.statsByAppearance[appearanceId] = result.data || {
          total_sent: 0,
          opened: 0,
          clicked: 0
        }
      } catch (error) {
        console.error('Failed to load stats:', error)
        // Don't set error - stats are non-critical
      } finally {
        this.statsLoading = false
      }
    },

    /**
     * Send an email
     * @param {number} appearanceId
     * @param {Object} emailData
     * @returns {Promise<{success: boolean, message: string}>}
     */
    async sendEmail(appearanceId, emailData) {
      if (!this.isReady) {
        return {
          success: false,
          message: 'Email integration is not available'
        }
      }

      this.sending = true
      this.sendError = null
      this.lastSentResult = null

      try {
        const result = await outreachApi.sendEmail(appearanceId, emailData)
        this.lastSentResult = result

        if (result.success) {
          // Reload messages and stats to reflect the new email
          await Promise.all([
            this.loadMessages(appearanceId, true),
            this.loadStats(appearanceId)
          ])
        }

        return result
      } catch (error) {
        console.error('Failed to send email:', error)
        const errorMessage = error.response?.data?.message || error.message || 'Failed to send email'
        this.sendError = errorMessage
        return {
          success: false,
          message: errorMessage
        }
      } finally {
        this.sending = false
      }
    },

    /**
     * Initialize the store for a specific appearance
     * Checks status and loads initial data if available
     * @param {number} appearanceId
     */
    async initialize(appearanceId) {
      // Check status first if not already checked
      if (!this.statusChecked) {
        await this.checkStatus()
      }

      // If available, load data in parallel
      if (this.available) {
        await Promise.all([
          this.loadTemplates(),
          this.loadMessages(appearanceId),
          this.loadStats(appearanceId)
        ])
      }
    },

    /**
     * Clear messages cache for an appearance
     * @param {number} appearanceId
     */
    clearMessagesCache(appearanceId) {
      delete this.messagesByAppearance[appearanceId]
      delete this.statsByAppearance[appearanceId]
    },

    /**
     * Reset the entire store
     */
    reset() {
      this.available = false
      this.configured = false
      this.statusChecked = false
      this.templates = []
      this.messagesByAppearance = {}
      this.statsByAppearance = {}
      this.sending = false
      this.sendError = null
      this.lastSentResult = null
    }
  }
})
