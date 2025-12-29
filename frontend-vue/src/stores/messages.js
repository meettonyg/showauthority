import { defineStore } from 'pinia'
import outreachApi from '../services/outreach'

/**
 * Messages Store
 *
 * Pinia store for managing email messaging state via Guestify Outreach integration.
 * Handles templates, message history, sending emails, tracking stats, and campaigns.
 *
 * @package ShowAuthority
 * @since 5.0.0
 * @updated 5.1.0 - Added campaign management support
 */
export const useMessagesStore = defineStore('messages', {
  state: () => ({
    // Integration status
    available: false,
    configured: false,
    statusChecked: false,

    // Extended status (v2.0+)
    hasApi: false,
    version: null,
    apiVersion: null,
    features: {
      send_email: false,
      templates: false,
      campaigns: false,
      tracking: false
    },

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

    // Campaigns (per appearance)
    campaignsByAppearance: {}, // { [appearanceId]: Campaign[] }
    campaignsLoading: false,
    campaignsError: null,

    // Campaign actions
    campaignActionLoading: false,
    campaignActionError: null,

    // Sequences (global - available to all appearances)
    sequences: [],
    sequencesLoading: false,
    sequencesError: null,

    // Unified stats (per appearance - combines single emails + campaigns)
    unifiedStatsByAppearance: {}, // { [appearanceId]: UnifiedStats }
    unifiedStatsLoading: false,

    // Sending state
    sending: false,
    sendError: null,
    lastSentResult: null,

    // Template CRUD state (v5.4.0+)
    templateSaving: false,
    templateSaveError: null,

    // Drafts (per appearance, v5.4.0+)
    draftsByAppearance: {}, // { [appearanceId]: Draft[] }
    draftsLoading: false,
    draftsError: null,
    draftSaving: false,
    draftSaveError: null
  }),

  getters: {
    /**
     * Check if integration is ready to use
     */
    isReady: (state) => state.available && state.configured,

    /**
     * Check if campaigns feature is available (v2.0+)
     */
    hasCampaigns: (state) => state.hasApi && state.features.campaigns,

    /**
     * Check if sequences feature is available (v2.0+ with campaigns)
     */
    hasSequences: (state) => state.hasApi && state.features.campaigns,

    /**
     * Get active sequences only
     */
    activeSequences: (state) => state.sequences.filter(s => s.is_active),

    /**
     * Get sequence by ID
     */
    getSequenceById: (state) => (id) => {
      return state.sequences.find(s => s.id === id)
    },

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
     * Get unified stats for a specific appearance (single emails + campaigns)
     */
    getUnifiedStatsForAppearance: (state) => (appearanceId) => {
      return state.unifiedStatsByAppearance[appearanceId] || {
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
      }
    },

    /**
     * Get campaigns for a specific appearance
     */
    getCampaignsForAppearance: (state) => (appearanceId) => {
      return state.campaignsByAppearance[appearanceId] || []
    },

    /**
     * Get active campaigns for a specific appearance
     */
    getActiveCampaignsForAppearance: (state) => (appearanceId) => {
      const campaigns = state.campaignsByAppearance[appearanceId] || []
      return campaigns.filter(c => c.status === 'active' || c.status === 'running')
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
    },

    /**
     * Get drafts for a specific appearance
     */
    getDraftsForAppearance: (state) => (appearanceId) => {
      return state.draftsByAppearance[appearanceId] || []
    },

    /**
     * Get active drafts (status = 'draft') for an appearance
     */
    getActiveDraftsForAppearance: (state) => (appearanceId) => {
      const drafts = state.draftsByAppearance[appearanceId] || []
      return drafts.filter(d => d.status === 'draft')
    },

    /**
     * Get sent drafts (status = 'marked_sent') for an appearance
     */
    getMarkedSentForAppearance: (state) => (appearanceId) => {
      const drafts = state.draftsByAppearance[appearanceId] || []
      return drafts.filter(d => d.status === 'marked_sent')
    },

    /**
     * Get draft count for an appearance (active drafts only)
     */
    getDraftCountForAppearance: (state) => (appearanceId) => {
      const drafts = state.draftsByAppearance[appearanceId] || []
      return drafts.filter(d => d.status === 'draft').length
    }
  },

  actions: {
    /**
     * Helper to handle API errors consistently
     * @private
     * @param {Error} error - The error object
     * @param {string} stateProperty - The state property to set error on
     * @param {string} defaultMessage - Default error message
     * @returns {{success: boolean, message: string}}
     */
    _handleApiError(error, stateProperty, defaultMessage) {
      console.error(`${defaultMessage}:`, error)
      const errorMessage = error.response?.data?.message || error.message || defaultMessage
      this[stateProperty] = errorMessage
      return { success: false, message: errorMessage }
    },

    /**
     * Find the appearanceId for a given campaignId from state
     * @private
     * @param {number} campaignId - The campaign ID
     * @returns {number|null} The appearance ID or null if not found
     */
    _findAppearanceIdForCampaign(campaignId) {
      for (const [appearanceId, campaigns] of Object.entries(this.campaignsByAppearance)) {
        if (campaigns.some(c => c.id === campaignId)) {
          return parseInt(appearanceId, 10)
        }
      }
      return null
    },

    /**
     * Check if Outreach plugin is available and configured
     * Uses extended status to get version and feature info
     */
    async checkStatus() {
      try {
        // Try extended status first (v2.0+)
        try {
          const extended = await outreachApi.getExtendedStatus()
          this.available = extended.available
          this.configured = extended.configured
          this.hasApi = extended.has_api || false
          this.version = extended.version || null
          this.apiVersion = extended.api_version || null
          this.features = extended.features || {
            send_email: extended.available,
            templates: extended.available,
            campaigns: extended.has_api,
            tracking: extended.configured
          }
          this.statusChecked = true
          return extended
        } catch (error) {
          console.warn('Failed to get extended status, falling back to basic status check.', error)
          // Fallback to basic status
          const result = await outreachApi.getStatus()
          this.available = result.available
          this.configured = result.configured
          this.hasApi = false
          this.features = {
            send_email: result.available,
            templates: result.available,
            campaigns: false,
            tracking: result.configured
          }
          this.statusChecked = true
          return result
        }
      } catch (error) {
        console.error('Failed to check Outreach status:', error)
        this.available = false
        this.configured = false
        this.hasApi = false
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
        return this._handleApiError(error, 'sendError', 'Failed to send email')
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
        const loads = [
          this.loadTemplates(),
          this.loadMessages(appearanceId),
          this.loadStats(appearanceId),
          this.loadUnifiedStats(appearanceId),
          this.loadDrafts(appearanceId)
        ]

        // Load campaigns and sequences if v2.0 API is available
        if (this.hasCampaigns) {
          loads.push(this.loadCampaigns(appearanceId))
          loads.push(this.loadSequences())
        }

        await Promise.all(loads)
      }
    },

    /**
     * Clear messages cache for an appearance
     * @param {number} appearanceId
     */
    clearMessagesCache(appearanceId) {
      delete this.messagesByAppearance[appearanceId]
      delete this.statsByAppearance[appearanceId]
      delete this.campaignsByAppearance[appearanceId]
      delete this.unifiedStatsByAppearance[appearanceId]
      delete this.draftsByAppearance[appearanceId]
    },

    // =========================================================================
    // Campaign Management (v2.0+ API)
    // =========================================================================

    /**
     * Load campaigns for an appearance
     * @param {number} appearanceId
     * @param {boolean} force - Force reload even if cached
     */
    async loadCampaigns(appearanceId, force = false) {
      if (!this.hasCampaigns) return

      // Skip if already loaded and not forcing
      if (!force && this.campaignsByAppearance[appearanceId]) {
        return
      }

      this.campaignsLoading = true
      this.campaignsError = null

      try {
        const result = await outreachApi.getCampaigns(appearanceId)
        this.campaignsByAppearance[appearanceId] = result.data || []
      } catch (error) {
        console.error('Failed to load campaigns:', error)
        this.campaignsError = error.message || 'Failed to load campaigns'
      } finally {
        this.campaignsLoading = false
      }
    },

    /**
     * Start a new campaign
     * @param {number} appearanceId
     * @param {Object} campaignData
     * @returns {Promise<{success: boolean, message: string, campaign_id?: number}>}
     */
    async startCampaign(appearanceId, campaignData) {
      if (!this.hasCampaigns) {
        return {
          success: false,
          message: 'Campaign management requires Guestify Outreach v2.0 or later'
        }
      }

      this.campaignActionLoading = true
      this.campaignActionError = null

      try {
        const result = await outreachApi.startCampaign(appearanceId, campaignData)

        if (result.success) {
          // Reload campaigns to reflect the new campaign
          await this.loadCampaigns(appearanceId, true)
        }

        return result
      } catch (error) {
        return this._handleApiError(error, 'campaignActionError', 'Failed to start campaign')
      } finally {
        this.campaignActionLoading = false
      }
    },

    /**
     * Pause a campaign
     * @param {number} campaignId
     * @param {number} [appearanceId] - Optional, will be auto-detected from state if not provided
     * @returns {Promise<{success: boolean, message: string}>}
     */
    async pauseCampaign(campaignId, appearanceId = null) {
      if (!this.hasCampaigns) {
        return {
          success: false,
          message: 'Campaign management requires Guestify Outreach v2.0 or later'
        }
      }

      this.campaignActionLoading = true
      this.campaignActionError = null

      try {
        const result = await outreachApi.pauseCampaign(campaignId)

        if (result.success) {
          const resolvedAppearanceId = appearanceId || this._findAppearanceIdForCampaign(campaignId)
          if (resolvedAppearanceId) {
            await this.loadCampaigns(resolvedAppearanceId, true)
          }
        }

        return result
      } catch (error) {
        return this._handleApiError(error, 'campaignActionError', 'Failed to pause campaign')
      } finally {
        this.campaignActionLoading = false
      }
    },

    /**
     * Resume a paused campaign
     * @param {number} campaignId
     * @param {number} [appearanceId] - Optional, will be auto-detected from state if not provided
     * @returns {Promise<{success: boolean, message: string}>}
     */
    async resumeCampaign(campaignId, appearanceId = null) {
      if (!this.hasCampaigns) {
        return {
          success: false,
          message: 'Campaign management requires Guestify Outreach v2.0 or later'
        }
      }

      this.campaignActionLoading = true
      this.campaignActionError = null

      try {
        const result = await outreachApi.resumeCampaign(campaignId)

        if (result.success) {
          const resolvedAppearanceId = appearanceId || this._findAppearanceIdForCampaign(campaignId)
          if (resolvedAppearanceId) {
            await this.loadCampaigns(resolvedAppearanceId, true)
          }
        }

        return result
      } catch (error) {
        return this._handleApiError(error, 'campaignActionError', 'Failed to resume campaign')
      } finally {
        this.campaignActionLoading = false
      }
    },

    /**
     * Cancel a campaign
     * @param {number} campaignId
     * @param {number} [appearanceId] - Optional, will be auto-detected from state if not provided
     * @returns {Promise<{success: boolean, message: string}>}
     */
    async cancelCampaign(campaignId, appearanceId = null) {
      if (!this.hasCampaigns) {
        return {
          success: false,
          message: 'Campaign management requires Guestify Outreach v2.0 or later'
        }
      }

      this.campaignActionLoading = true
      this.campaignActionError = null

      try {
        const result = await outreachApi.cancelCampaign(campaignId)

        if (result.success) {
          const resolvedAppearanceId = appearanceId || this._findAppearanceIdForCampaign(campaignId)
          if (resolvedAppearanceId) {
            await this.loadCampaigns(resolvedAppearanceId, true)
          }
        }

        return result
      } catch (error) {
        return this._handleApiError(error, 'campaignActionError', 'Failed to cancel campaign')
      } finally {
        this.campaignActionLoading = false
      }
    },

    // =========================================================================
    // Sequence-Based Campaigns (v5.2.0+)
    // =========================================================================

    /**
     * Load available sequences
     * @param {boolean} force - Force reload even if cached
     */
    async loadSequences(force = false) {
      if (!this.hasSequences) return

      // Skip if already loaded and not forcing
      if (!force && this.sequences.length > 0) {
        return
      }

      this.sequencesLoading = true
      this.sequencesError = null

      try {
        const result = await outreachApi.getSequences()
        this.sequences = result.data || []
      } catch (error) {
        console.error('Failed to load sequences:', error)
        this.sequencesError = error.message || 'Failed to load sequences'
        this.sequences = []
      } finally {
        this.sequencesLoading = false
      }
    },

    /**
     * Start a sequence-based campaign
     * @param {number} appearanceId - The appearance/opportunity ID
     * @param {Object} campaignData
     * @param {number} campaignData.sequence_id - The sequence ID to use
     * @param {string} campaignData.recipient_email - Recipient email
     * @param {string} [campaignData.recipient_name] - Recipient name
     * @returns {Promise<{success: boolean, message: string, campaign_id?: number}>}
     */
    async startSequenceCampaign(appearanceId, campaignData) {
      if (!this.hasSequences) {
        return {
          success: false,
          message: 'Sequence campaigns require Guestify Outreach v2.0 or later'
        }
      }

      this.campaignActionLoading = true
      this.campaignActionError = null

      try {
        const result = await outreachApi.startSequenceCampaign(appearanceId, campaignData)

        if (result.success) {
          // Reload campaigns and unified stats to reflect the new campaign
          await Promise.all([
            this.loadCampaigns(appearanceId, true),
            this.loadUnifiedStats(appearanceId)
          ])
        }

        return result
      } catch (error) {
        return this._handleApiError(error, 'campaignActionError', 'Failed to start sequence campaign')
      } finally {
        this.campaignActionLoading = false
      }
    },

    /**
     * Load unified stats for an appearance (single emails + campaigns)
     * @param {number} appearanceId
     */
    async loadUnifiedStats(appearanceId) {
      if (!this.available) return

      this.unifiedStatsLoading = true

      try {
        const result = await outreachApi.getUnifiedStats(appearanceId)
        this.unifiedStatsByAppearance[appearanceId] = result.data || {
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
        }
      } catch (error) {
        console.error('Failed to load unified stats:', error)
        // Don't set error - stats are non-critical
      } finally {
        this.unifiedStatsLoading = false
      }
    },

    // =========================================================================
    // Template CRUD (v5.4.0+)
    // =========================================================================

    /**
     * Create a new email template
     * @param {Object} templateData
     * @param {string} templateData.name - Template name
     * @param {string} templateData.subject - Email subject
     * @param {string} templateData.body - Email body HTML
     * @param {string} [templateData.description] - Description
     * @param {string} [templateData.category] - Category
     * @returns {Promise<{success: boolean, message: string, template_id?: number}>}
     */
    async createTemplate(templateData) {
      if (!this.available) {
        return {
          success: false,
          message: 'Email integration is not available'
        }
      }

      this.templateSaving = true
      this.templateSaveError = null

      try {
        const result = await outreachApi.createTemplate(templateData)

        if (result.success) {
          // Reload templates to include the new one
          await this.loadTemplates()
        }

        return result
      } catch (error) {
        return this._handleApiError(error, 'templateSaveError', 'Failed to create template')
      } finally {
        this.templateSaving = false
      }
    },

    /**
     * Update an existing email template
     * @param {number} templateId - Template ID
     * @param {Object} templateData - Updated data
     * @returns {Promise<{success: boolean, message: string}>}
     */
    async updateTemplate(templateId, templateData) {
      if (!this.available) {
        return {
          success: false,
          message: 'Email integration is not available'
        }
      }

      this.templateSaving = true
      this.templateSaveError = null

      try {
        const result = await outreachApi.updateTemplate(templateId, templateData)

        if (result.success) {
          // Reload templates to reflect changes
          await this.loadTemplates()
        }

        return result
      } catch (error) {
        return this._handleApiError(error, 'templateSaveError', 'Failed to update template')
      } finally {
        this.templateSaving = false
      }
    },

    // =========================================================================
    // Draft Management (v5.4.0+)
    // =========================================================================

    /**
     * Load drafts for an appearance
     * @param {number} appearanceId
     * @param {boolean} force - Force reload even if cached
     */
    async loadDrafts(appearanceId, force = false) {
      if (!this.available) return

      // Skip if already loaded and not forcing
      if (!force && this.draftsByAppearance[appearanceId]) {
        return
      }

      this.draftsLoading = true
      this.draftsError = null

      try {
        const result = await outreachApi.getDrafts(appearanceId)
        this.draftsByAppearance[appearanceId] = result.data || []
      } catch (error) {
        console.error('Failed to load drafts:', error)
        this.draftsError = error.message || 'Failed to load drafts'
      } finally {
        this.draftsLoading = false
      }
    },

    /**
     * Save a draft email
     * @param {number} appearanceId
     * @param {Object} draftData
     * @returns {Promise<{success: boolean, message: string, draft_id?: number}>}
     */
    async saveDraft(appearanceId, draftData) {
      if (!this.available) {
        return {
          success: false,
          message: 'Email integration is not available'
        }
      }

      this.draftSaving = true
      this.draftSaveError = null

      try {
        const result = await outreachApi.saveDraft(appearanceId, draftData)

        if (result.success) {
          // Reload drafts to reflect changes
          await this.loadDrafts(appearanceId, true)
        }

        return result
      } catch (error) {
        return this._handleApiError(error, 'draftSaveError', 'Failed to save draft')
      } finally {
        this.draftSaving = false
      }
    },

    /**
     * Mark an email as manually sent
     * @param {number} appearanceId
     * @param {Object} emailData
     * @returns {Promise<{success: boolean, message: string, draft_id?: number}>}
     */
    async markAsSent(appearanceId, emailData) {
      if (!this.available) {
        return {
          success: false,
          message: 'Email integration is not available'
        }
      }

      this.draftSaving = true
      this.draftSaveError = null

      try {
        const result = await outreachApi.markAsSent(appearanceId, emailData)

        if (result.success) {
          // Reload drafts and messages to reflect changes
          await Promise.all([
            this.loadDrafts(appearanceId, true),
            this.loadMessages(appearanceId, true)
          ])
        }

        return result
      } catch (error) {
        return this._handleApiError(error, 'draftSaveError', 'Failed to mark as sent')
      } finally {
        this.draftSaving = false
      }
    },

    /**
     * Delete a draft
     * @param {number} draftId
     * @param {number} [appearanceId] - If provided, refreshes drafts for this appearance
     * @returns {Promise<{success: boolean, message: string}>}
     */
    async deleteDraft(draftId, appearanceId = null) {
      if (!this.available) {
        return {
          success: false,
          message: 'Email integration is not available'
        }
      }

      try {
        const result = await outreachApi.deleteDraft(draftId)

        if (result.success && appearanceId) {
          // Reload drafts if appearanceId provided
          await this.loadDrafts(appearanceId, true)
        }

        return result
      } catch (error) {
        console.error('Failed to delete draft:', error)
        return {
          success: false,
          message: error.response?.data?.message || error.message || 'Failed to delete draft'
        }
      }
    },

    /**
     * Reset the entire store
     */
    reset() {
      this.available = false
      this.configured = false
      this.statusChecked = false
      this.hasApi = false
      this.version = null
      this.apiVersion = null
      this.features = {
        send_email: false,
        templates: false,
        campaigns: false,
        tracking: false
      }
      this.templates = []
      this.messagesByAppearance = {}
      this.statsByAppearance = {}
      this.campaignsByAppearance = {}
      this.unifiedStatsByAppearance = {}
      this.sequences = []
      this.sequencesLoading = false
      this.sequencesError = null
      this.unifiedStatsLoading = false
      this.sending = false
      this.sendError = null
      this.lastSentResult = null
      this.campaignActionLoading = false
      this.campaignActionError = null
      // Template CRUD state
      this.templateSaving = false
      this.templateSaveError = null
      // Draft state
      this.draftsByAppearance = {}
      this.draftsLoading = false
      this.draftsError = null
      this.draftSaving = false
      this.draftSaveError = null
    }
  }
})
