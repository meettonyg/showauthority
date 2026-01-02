/**
 * Interview Detail Vue.js Application
 * 
 * Full detail view for a single interview/appearance with tabs:
 * - About: Podcast info, status, dates
 * - Listen: Episode player, recent episodes
 * - Contact: Podcast contacts
 * - Message: Email integration (future)
 * - Tasks: Task management
 * - Notes: Notes management
 * 
 * Refactored to match provided design specifications
 * 
 * @package Podcast_Influence_Tracker
 * @since 3.2.0
 */

(function() {
    'use strict';

    const { createApp, ref, reactive, computed, onMounted, onUnmounted, watch } = Vue;
    const { createPinia, defineStore } = Pinia;

    // ==========================================================================
    // CONSTANTS
    // ==========================================================================
    const TAG_COLORS = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16'];
    const VALID_TABS = ['about', 'listen', 'contact', 'message', 'tasks', 'notes'];

    // ==========================================================================
    // PINIA STORE
    // ==========================================================================
    const useDetailStore = defineStore('interviewDetail', {
        state: () => ({
            // Core data
            interview: null,
            podcast: null,
            tasks: [],
            notes: [],
            contacts: [],
            episodes: [],
            guestProfiles: [],
            stages: [], // Pipeline stages from database

            // Episodes metadata
            episodesMeta: {
                totalAvailable: 0,
                hasMore: false,
                cached: false,
                cacheExpires: null,
                offset: 0,
            },

            // Episode search filter
            episodeSearchTerm: '',

            // Linked episode (engagement)
            linkedEpisode: null,
            linkingEpisode: false,
            unlinkingEpisode: false,

            // UI state
            activeTab: 'about',
            loading: true,
            saving: false,
            error: null,
            episodesLoading: false,
            episodesError: null,
            profilesLoading: false,
            profilesError: null,

            // Email integration state (Guestify Outreach Bridge)
            emailAvailable: false,
            emailConfigured: false,
            emailHasApi: false,
            emailFeatures: {
                send_email: false,
                templates: false,
                campaigns: false,
                tracking: false,
            },
            emailTemplates: [],
            messages: [],
            messagesLoading: false,
            sendingEmail: false,
            emailStats: {
                total_sent: 0,
                opened: 0,
                clicked: 0,
            },

            // Campaign sequences (v2.0+ Guestify Outreach)
            sequences: [],
            sequencesLoading: false,
            campaigns: [],
            campaignsLoading: false,
            startingCampaign: false,

            // Tags (v3.4.0)
            tags: [],
            availableTags: [],
            tagsLoading: false,
            tagInput: '',
            showTagDropdown: false,

            // Config from WordPress
            config: {
                restUrl: '',
                calendarRestUrl: '',
                nonce: '',
                interviewId: 0,
                userId: 0,
                isAdmin: false,
                boardUrl: '/app/interview/board/',
            }
        }),

        getters: {
            podcastName: (state) => state.interview?.podcast_name || 'Unknown Podcast',
            podcastImage: (state) => state.interview?.podcast_image || '',
            status: (state) => state.interview?.status || 'potential',
            priority: (state) => state.interview?.priority || 'medium',
            
            pendingTasks: (state) => state.tasks.filter(t => !t.is_done),
            completedTasks: (state) => state.tasks.filter(t => t.is_done),
            overdueTasks: (state) => state.tasks.filter(t => t.is_overdue && !t.is_done),
            
            pinnedNotes: (state) => state.notes.filter(n => n.is_pinned),
            unpinnedNotes: (state) => state.notes.filter(n => !n.is_pinned),

            // Check if an episode is linked to this opportunity
            hasLinkedEpisode: (state) => !!state.interview?.engagement_id,

            // Filter episodes by search term (searches title and description)
            filteredEpisodes: (state) => {
                const searchTerm = (state.episodeSearchTerm || '').trim();
                if (!searchTerm) {
                    return state.episodes;
                }
                const searchLower = searchTerm.toLowerCase();
                return state.episodes.filter(episode => {
                    const title = episode.title || '';
                    const description = episode.description || '';
                    return title.toLowerCase().includes(searchLower) ||
                           description.toLowerCase().includes(searchLower);
                });
            },
        },

        actions: {
            initConfig(data) {
                this.config = { ...this.config, ...data };
                // Initialize shared API client with config
                this._apiClient = GuestifyApi.createClient({
                    restUrl: this.config.restUrl,
                    nonce: this.config.nonce,
                });
            },

            /**
             * Generate a unique storage key for tab persistence (namespaced by interview ID)
             */
            _getTabStorageKey() {
                // Use explicit null check to handle interviewId of 0 correctly
                return this.config.interviewId != null
                    ? `pit_interview_detail_tab_${this.config.interviewId}`
                    : 'pit_interview_detail_tab';
            },

            /**
             * Load active tab from localStorage
             */
            loadActiveTabFromStorage() {
                const savedTab = localStorage.getItem(this._getTabStorageKey());

                if (savedTab && VALID_TABS.includes(savedTab)) {
                    this.setActiveTab(savedTab);
                }
            },

            async api(endpoint, options = {}) {
                // Use shared API client for consistent request handling
                return this._apiClient.request(endpoint, options);
            },

            async loadStages() {
                try {
                    const response = await this.api('pipeline-stages');
                    this.stages = response.data || [];
                } catch (err) {
                    console.error('Failed to load pipeline stages:', err);
                    // Fallback to hardcoded stages if API fails
                    this.stages = [
                        { key: 'potential', label: 'Potential', color: '#94a3b8' },
                        { key: 'active', label: 'Active', color: '#f59e0b' },
                        { key: 'aired', label: 'Aired', color: '#10b981' },
                        { key: 'convert', label: 'Convert', color: '#8b5cf6' },
                    ];
                }
            },

            async loadInterview() {
                this.loading = true;
                this.error = null;
                
                try {
                    // Load stages first
                    await this.loadStages();
                    
                    const response = await this.api(`appearances/${this.config.interviewId}`);
                    
                    // DEBUG: Log initial interview load
                    console.log('Initial interview load:', response);
                    console.log('Categories from initial load:', response.categories);
                    console.log('Category string from initial load:', response.category);
                    
                    this.interview = response;
                    
                    // Load linked episode details if exists
                    if (response.engagement_id) {
                        await this.loadLinkedEpisode();
                        // Also load episodes from RSS so we can enrich the linked episode data
                        this.loadEpisodes();
                    }

                    await Promise.all([
                        this.loadTasks(),
                        this.loadNotes(),
                        this.loadTags(),
                        this.loadAvailableTags(),
                    ]);
                } catch (err) {
                    this.error = err.message;
                    console.error('Failed to load interview:', err);
                } finally {
                    this.loading = false;
                }
            },

            async loadGuestProfiles(force = false) {
                if (this.profilesLoading || (this.guestProfiles.length && !force)) {
                    return;
                }

                this.profilesLoading = true;
                this.profilesError = null;

                try {
                    const response = await this.api('guest-profiles');
                    const profiles = response.data || [];

                    // Backend already filters by user, but double-check with parseInt for consistency
                    this.guestProfiles = profiles.filter(profile => {
                        if (!profile.author_id || !this.config.userId) {
                            return true;
                        }
                        return parseInt(profile.author_id) === parseInt(this.config.userId);
                    });
                } catch (err) {
                    console.error('Failed to load guest profiles:', err);
                    this.profilesError = err.message;
                } finally {
                    this.profilesLoading = false;
                }
            },

            async updateInterview(field, value) {
                this.saving = true;
                try {
                    await this.api(`appearances/${this.config.interviewId}`, {
                        method: 'PATCH',
                        body: JSON.stringify({ [field]: value }),
                    });
                    this.interview[field] = value;
                } catch (err) {
                    this.error = err.message;
                    throw err;
                } finally {
                    this.saving = false;
                }
            },

            async loadTasks() {
                try {
                    const response = await this.api(`appearances/${this.config.interviewId}/tasks`);
                    this.tasks = response.data || [];
                } catch (err) {
                    console.error('Failed to load tasks:', err);
                }
            },

            async createTask(taskData) {
                this.saving = true;
                try {
                    const response = await this.api(`appearances/${this.config.interviewId}/tasks`, {
                        method: 'POST',
                        body: JSON.stringify(taskData),
                    });
                    this.tasks.unshift(response.data);
                    return response.data;
                } catch (err) {
                    this.error = err.message;
                    throw err;
                } finally {
                    this.saving = false;
                }
            },

            async updateTask(taskId, updates) {
                this.saving = true;
                try {
                    const response = await this.api(`appearances/${this.config.interviewId}/tasks/${taskId}`, {
                        method: 'PATCH',
                        body: JSON.stringify(updates),
                    });
                    const index = this.tasks.findIndex(t => t.id === taskId);
                    if (index !== -1) {
                        this.tasks[index] = response.data;
                    }
                    return response.data;
                } catch (err) {
                    this.error = err.message;
                    throw err;
                } finally {
                    this.saving = false;
                }
            },

            async toggleTask(taskId) {
                try {
                    const response = await this.api(`appearances/${this.config.interviewId}/tasks/${taskId}/toggle`, {
                        method: 'POST',
                    });
                    const index = this.tasks.findIndex(t => t.id === taskId);
                    if (index !== -1) {
                        this.tasks[index] = response.data;
                    }
                    return response.data;
                } catch (err) {
                    this.error = err.message;
                    throw err;
                }
            },

            async deleteTask(taskId) {
                try {
                    await this.api(`appearances/${this.config.interviewId}/tasks/${taskId}`, {
                        method: 'DELETE',
                    });
                    this.tasks = this.tasks.filter(t => t.id !== taskId);
                } catch (err) {
                    this.error = err.message;
                    throw err;
                }
            },

            async loadNotes() {
                try {
                    const response = await this.api(`appearances/${this.config.interviewId}/notes`);
                    this.notes = response.data || [];
                } catch (err) {
                    console.error('Failed to load notes:', err);
                }
            },

            async createNote(noteData) {
                this.saving = true;
                try {
                    const response = await this.api(`appearances/${this.config.interviewId}/notes`, {
                        method: 'POST',
                        body: JSON.stringify(noteData),
                    });
                    this.notes.unshift(response.data);
                    return response.data;
                } catch (err) {
                    this.error = err.message;
                    throw err;
                } finally {
                    this.saving = false;
                }
            },

            async updateNote(noteId, updates) {
                this.saving = true;
                try {
                    const response = await this.api(`appearances/${this.config.interviewId}/notes/${noteId}`, {
                        method: 'PATCH',
                        body: JSON.stringify(updates),
                    });
                    const index = this.notes.findIndex(n => n.id === noteId);
                    if (index !== -1) {
                        this.notes[index] = response.data;
                    }
                    return response.data;
                } catch (err) {
                    this.error = err.message;
                    throw err;
                } finally {
                    this.saving = false;
                }
            },

            async toggleNotePin(noteId) {
                try {
                    const response = await this.api(`appearances/${this.config.interviewId}/notes/${noteId}/pin`, {
                        method: 'POST',
                    });
                    const index = this.notes.findIndex(n => n.id === noteId);
                    if (index !== -1) {
                        this.notes[index] = response.data;
                    }
                    this.notes.sort((a, b) => {
                        if (a.is_pinned && !b.is_pinned) return -1;
                        if (!a.is_pinned && b.is_pinned) return 1;
                        return new Date(b.created_at) - new Date(a.created_at);
                    });
                    return response.data;
                } catch (err) {
                    this.error = err.message;
                    throw err;
                }
            },

            async deleteNote(noteId) {
                try {
                    await this.api(`appearances/${this.config.interviewId}/notes/${noteId}`, {
                        method: 'DELETE',
                    });
                    this.notes = this.notes.filter(n => n.id !== noteId);
                } catch (err) {
                    this.error = err.message;
                    throw err;
                }
            },

            /**
             * Load linked episode details if opportunity has engagement_id
             */
            async loadLinkedEpisode() {
                if (!this.interview?.engagement_id) {
                    this.linkedEpisode = null;
                    return;
                }

                try {
                    // Fetch engagement details
                    const baseUrl = this.config.restUrl.replace('guestify/v1/', 'guestify/v1/');
                    const response = await fetch(
                        `${baseUrl}engagements/${this.interview.engagement_id}`,
                        {
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': this.config.nonce,
                            },
                        }
                    );

                    if (!response.ok) {
                        console.warn('Failed to load linked episode details');
                        return;
                    }

                    const data = await response.json();
                    
                    if (data) {
                        this.linkedEpisode = {
                            id: data.id,
                            title: data.title,
                            guid: data.episode_guid,
                            date: data.engagement_date,
                            url: data.episode_url,
                            duration: data.duration_seconds ? this.formatDuration(data.duration_seconds) : null,
                            thumbnail: data.thumbnail_url,
                            description: data.description,
                            audio_url: data.audio_url,
                        };
                    }
                } catch (err) {
                    console.warn('Error loading linked episode:', err);
                }
            },

            /**
             * Format seconds to display duration
             */
            formatDuration(seconds) {
                if (!seconds) return null;
                const mins = Math.floor(seconds / 60);
                return `${mins} min`;
            },

            /**
             * Load episodes from RSS feed via podcast-influence API
             * Uses the podcast_id from the interview/appearance record
             */
            async loadEpisodes(refresh = false) {
                // Don't load unfiltered episodes if a search is active (unless refreshing)
                if (this.episodeSearchTerm && !refresh) {
                    return;
                }

                // Need podcast_id from interview
                if (!this.interview?.podcast_id) {
                    this.episodesError = 'No podcast linked to this interview';
                    return;
                }

                this.episodesLoading = true;
                this.episodesError = null;

                try {
                    // Build API URL - note: uses podcast-influence namespace
                    // Handle both 'guestify/v1/' and 'guestify/v1' formats
                    let baseUrl = this.config.restUrl.replace('guestify/v1/', 'podcast-influence/v1/');
                    baseUrl = baseUrl.replace('guestify/v1', 'podcast-influence/v1');
                    // Ensure trailing slash
                    if (!baseUrl.endsWith('/')) {
                        baseUrl += '/';
                    }

                    const params = new URLSearchParams({
                        offset: refresh ? 0 : this.episodesMeta.offset,
                        limit: 10,
                        refresh: refresh ? 'true' : 'false',
                    });

                    const response = await fetch(
                        `${baseUrl}podcasts/${this.interview.podcast_id}/episodes?${params}`,
                        {
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': this.config.nonce,
                            },
                        }
                    );

                    if (!response.ok) {
                        const error = await response.json();
                        throw new Error(error.message || 'Failed to load episodes');
                    }

                    const data = await response.json();

                    // If refreshing, replace episodes; otherwise append
                    if (refresh || this.episodesMeta.offset === 0) {
                        this.episodes = data.episodes || [];
                    } else {
                        this.episodes = [...this.episodes, ...(data.episodes || [])];
                    }

                    // Update metadata
                    this.episodesMeta = {
                        totalAvailable: data.total_available || 0,
                        hasMore: data.has_more || false,
                        cached: data.cached || false,
                        cacheExpires: data.cache_expires || null,
                        offset: this.episodes.length,
                    };
                } catch (err) {
                    this.episodesError = err.message;
                    console.error('Failed to load episodes:', err);
                } finally {
                    this.episodesLoading = false;
                }
            },

            /**
             * Load more episodes (pagination)
             */
            async loadMoreEpisodes() {
                if (this.episodesLoading || !this.episodesMeta.hasMore) return;
                await this.loadEpisodes(false);
            },

            /**
             * Force refresh episodes (bypass cache)
             */
            async refreshEpisodes() {
                this.episodesMeta.offset = 0;
                this.episodeSearchTerm = ''; // Clear search when refreshing
                await this.loadEpisodes(true);
            },

            /**
             * Search episodes across the entire RSS feed
             * Makes an API call with search parameter to search all cached episodes
             *
             * @param {string} searchTerm The search term
             */
            async searchEpisodes(searchTerm) {
                if (!this.interview?.podcast_id) {
                    return;
                }

                // If search term is empty, reload normal episodes
                if (!searchTerm || searchTerm.trim() === '') {
                    this.episodeSearchTerm = '';
                    this.episodesMeta.offset = 0;
                    await this.loadEpisodes(false);
                    return;
                }

                this.episodesLoading = true;
                this.episodesError = null;
                this.episodeSearchTerm = searchTerm;

                try {
                    // Build API URL with search parameter
                    // Handle both 'guestify/v1/' and 'guestify/v1' formats
                    let baseUrl = this.config.restUrl.replace('guestify/v1/', 'podcast-influence/v1/');
                    baseUrl = baseUrl.replace('guestify/v1', 'podcast-influence/v1');
                    // Ensure trailing slash
                    if (!baseUrl.endsWith('/')) {
                        baseUrl += '/';
                    }

                    const params = new URLSearchParams({
                        offset: 0,
                        limit: 50, // Get more results when searching
                        search: searchTerm.trim(),
                    });

                    console.log('Searching episodes:', { url: `${baseUrl}podcasts/${this.interview.podcast_id}/episodes`, search: searchTerm.trim() });

                    const response = await fetch(
                        `${baseUrl}podcasts/${this.interview.podcast_id}/episodes?${params}`,
                        {
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': this.config.nonce,
                            },
                        }
                    );

                    if (!response.ok) {
                        const error = await response.json();
                        throw new Error(error.message || 'Failed to search episodes');
                    }

                    const data = await response.json();

                    console.log('Search response:', {
                        searchTerm: data.search_term,
                        totalAvailable: data.total_available,
                        totalInFeed: data.total_in_feed,
                        episodesCount: data.episodes?.length
                    });

                    // Replace episodes with search results
                    this.episodes = data.episodes || [];

                    // Update metadata
                    this.episodesMeta = {
                        totalAvailable: data.total_available || 0,
                        totalInFeed: data.total_in_feed || 0,
                        hasMore: data.has_more || false,
                        cached: data.cached || false,
                        cacheExpires: data.cache_expires || null,
                        offset: this.episodes.length,
                    };
                } catch (err) {
                    this.episodesError = err.message;
                    console.error('Failed to search episodes:', err);
                } finally {
                    this.episodesLoading = false;
                }
            },

            /**
             * Link an episode to this opportunity
             * Creates an engagement record and links it
             *
             * @param {Object} episode Episode data from RSS feed
             */
            async linkEpisode(episode) {
                if (this.linkingEpisode) return;

                this.linkingEpisode = true;
                try {
                    const response = await this.api(`appearances/${this.config.interviewId}/link-episode`, {
                        method: 'POST',
                        body: JSON.stringify({
                            episode_title: episode.title,
                            episode_date: episode.date_iso,
                            episode_url: episode.episode_url || episode.audio_url,
                            episode_guid: episode.guid,
                            episode_duration: episode.duration_seconds,
                            episode_description: episode.description,
                            episode_thumbnail: episode.thumbnail_url,
                            episode_audio_url: episode.audio_url,
                        }),
                    });

                    // Update local state
                    this.interview.engagement_id = response.engagement_id;
                    this.interview.status = response.new_status || 'aired';
                    this.interview.air_date = episode.date_iso || this.interview.air_date;

                    // Store linked episode info for display
                    this.linkedEpisode = {
                        id: response.engagement_id,
                        title: episode.title,
                        guid: episode.guid,
                        date: episode.date,
                        date_iso: episode.date_iso,
                        url: episode.episode_url || episode.audio_url,
                        duration: episode.duration_display,
                        thumbnail: episode.thumbnail_url,
                        description: episode.description,
                        audio_url: episode.audio_url,
                    };

                    return response;
                } catch (err) {
                    this.error = err.message;
                    throw err;
                } finally {
                    this.linkingEpisode = false;
                }
            },

            /**
             * Unlink episode from this opportunity
             */
            async unlinkEpisode() {
                if (this.unlinkingEpisode) return;

                this.unlinkingEpisode = true;
                try {
                    await this.api(`appearances/${this.config.interviewId}/unlink-episode`, {
                        method: 'POST',
                    });

                    // Update local state
                    this.interview.engagement_id = null;
                    this.linkedEpisode = null;

                    return true;
                } catch (err) {
                    this.error = err.message;
                    throw err;
                } finally {
                    this.unlinkingEpisode = false;
                }
            },

            /**
             * Refresh podcast metadata from RSS
             * Updates episode count, frequency, dates, etc.
             */
            async refreshPodcastMetadata() {
                if (!this.interview?.podcast_id) {
                    this.error = 'No podcast linked to this interview';
                    return;
                }

                this.saving = true;
                try {
                    const baseUrl = this.config.restUrl.replace('guestify/v1/', 'podcast-influence/v1/');
                    const response = await fetch(
                        `${baseUrl}podcasts/${this.interview.podcast_id}/refresh-metadata`,
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': this.config.nonce,
                            },
                        }
                    );

                    if (!response.ok) {
                        const error = await response.json();
                        throw new Error(error.message || 'Failed to refresh metadata');
                    }

                    const data = await response.json();
                    
                    // DEBUG: Log what refresh returns
                    console.log('Refresh metadata response:', data);
                    console.log('Podcast data:', data.podcast);
                    console.log('Category field:', data.podcast?.category);
                    
                    // Update interview with refreshed podcast data
                    if (data.podcast) {
                        this.interview.episode_count = data.podcast.episode_count;
                        this.interview.frequency = data.podcast.frequency;
                        this.interview.average_duration = data.podcast.average_duration;
                        this.interview.founded_date = data.podcast.founded_date;
                        this.interview.last_episode_date = data.podcast.last_episode_date;
                        this.interview.content_rating = data.podcast.explicit_rating;
                        this.interview.explicit_rating = data.podcast.explicit_rating;
                        this.interview.description = data.podcast.description;
                        this.interview.podcast_image = data.podcast.artwork_url;
                        // Update categories from refreshed metadata
                        if (data.podcast.category) {
                            this.interview.categories = data.podcast.category.split(',').map(c => c.trim());
                        }
                    }

                    return data;
                } catch (err) {
                    this.error = err.message;
                    throw err;
                } finally {
                    this.saving = false;
                }
            },

            setActiveTab(tab) {
                this.activeTab = tab;
                // Persist tab selection to localStorage
                localStorage.setItem(this._getTabStorageKey(), tab);
                // Load episodes when Listen tab is first accessed
                if (tab === 'listen' && this.episodes.length === 0 && !this.episodesLoading) {
                    this.loadEpisodes();
                }
            },

            /**
             * Archive the interview
             */
            async archiveInterview() {
                this.saving = true;
                try {
                    await this.api(`appearances/${this.config.interviewId}`, {
                        method: 'PATCH',
                        body: JSON.stringify({ is_archived: true }),
                    });
                    this.interview.is_archived = true;
                } catch (err) {
                    this.error = err.message;
                    throw err;
                } finally {
                    this.saving = false;
                }
            },

            /**
             * Restore the interview from archive
             */
            async restoreInterview() {
                this.saving = true;
                try {
                    await this.api(`appearances/${this.config.interviewId}`, {
                        method: 'PATCH',
                        body: JSON.stringify({ is_archived: false }),
                    });
                    this.interview.is_archived = false;
                } catch (err) {
                    this.error = err.message;
                    throw err;
                } finally {
                    this.saving = false;
                }
            },

            /**
             * Delete the interview permanently
             */
            async deleteInterview() {
                this.saving = true;
                try {
                    await this.api(`appearances/${this.config.interviewId}`, {
                        method: 'DELETE',
                    });
                    // Redirect to board after successful deletion
                    window.location.href = this.config.boardUrl;
                } catch (err) {
                    this.error = err.message;
                    throw err;
                } finally {
                    this.saving = false;
                }
            },

            // =================================================================
            // EMAIL INTEGRATION (Guestify Outreach Bridge)
            // =================================================================

            /**
             * Check if email integration is available
             * Uses extended status to get version and feature info
             */
            async checkEmailIntegration() {
                try {
                    // Try extended status first (v2.0+)
                    try {
                        const response = await this.api('pit-bridge/status/extended');
                        this.emailAvailable = response.available;
                        this.emailConfigured = response.configured;
                        this.emailHasApi = response.has_api || false;
                        this.emailFeatures = response.features || {
                            send_email: response.available,
                            templates: response.available,
                            campaigns: response.has_api,
                            tracking: response.configured,
                        };
                    } catch (extErr) {
                        // Fallback to basic status
                        console.warn('Extended status not available, using basic:', extErr);
                        const response = await this.api('pit-bridge/status');
                        this.emailAvailable = response.available;
                        this.emailConfigured = response.configured;
                        this.emailHasApi = false;
                        this.emailFeatures = {
                            send_email: response.available,
                            templates: response.available,
                            campaigns: false,
                            tracking: response.configured,
                        };
                    }
                } catch (err) {
                    console.warn('Email integration not available:', err);
                    this.emailAvailable = false;
                    this.emailConfigured = false;
                    this.emailHasApi = false;
                    this.emailFeatures = {
                        send_email: false,
                        templates: false,
                        campaigns: false,
                        tracking: false,
                    };
                }
            },

            /**
             * Load email templates from Guestify Outreach
             */
            async loadTemplates() {
                if (!this.emailAvailable) return;
                try {
                    const response = await this.api('pit-bridge/templates');
                    this.emailTemplates = response.data || [];
                } catch (err) {
                    console.error('Failed to load templates:', err);
                }
            },

            /**
             * Load messages for this appearance
             */
            async loadMessages() {
                if (!this.emailAvailable) return;
                this.messagesLoading = true;
                try {
                    const response = await this.api(`pit-bridge/appearances/${this.config.interviewId}/messages`);
                    this.messages = response.data || [];
                } catch (err) {
                    console.error('Failed to load messages:', err);
                } finally {
                    this.messagesLoading = false;
                }
            },

            /**
             * Load email stats for this appearance
             */
            async loadEmailStats() {
                if (!this.emailAvailable) return;
                try {
                    const response = await this.api(`pit-bridge/appearances/${this.config.interviewId}/stats`);
                    this.emailStats = response.data || { total_sent: 0, opened: 0, clicked: 0 };
                } catch (err) {
                    console.error('Failed to load email stats:', err);
                }
            },

            /**
             * Send an email via Guestify Outreach
             */
            async sendEmail(payload) {
                this.sendingEmail = true;
                try {
                    const response = await this.api(`pit-bridge/appearances/${this.config.interviewId}/send`, {
                        method: 'POST',
                        body: JSON.stringify(payload),
                    });
                    if (response.success) {
                        // Refresh messages and notes (activity feed)
                        await Promise.all([
                            this.loadMessages(),
                            this.loadEmailStats(),
                            this.loadNotes(),
                        ]);
                    }
                    return response;
                } catch (err) {
                    console.error('Failed to send email:', err);
                    throw err;
                } finally {
                    this.sendingEmail = false;
                }
            },

            // =================================================================
            // CAMPAIGN SEQUENCES (v2.0+ Guestify Outreach)
            // =================================================================

            /**
             * Check if campaigns feature is available
             */
            hasCampaigns() {
                return this.emailHasApi && this.emailFeatures.campaigns;
            },

            /**
             * Load available sequences from Guestify Outreach
             */
            async loadSequences() {
                if (!this.hasCampaigns()) return;
                this.sequencesLoading = true;
                try {
                    const response = await this.api('pit-bridge/sequences');
                    this.sequences = response.data || [];
                } catch (err) {
                    console.error('Failed to load sequences:', err);
                    this.sequences = [];
                } finally {
                    this.sequencesLoading = false;
                }
            },

            /**
             * Load campaigns for this appearance
             */
            async loadCampaigns() {
                if (!this.hasCampaigns()) return;
                this.campaignsLoading = true;
                try {
                    const response = await this.api(`pit-bridge/appearances/${this.config.interviewId}/campaigns`);
                    this.campaigns = response.data || [];
                } catch (err) {
                    console.error('Failed to load campaigns:', err);
                    this.campaigns = [];
                } finally {
                    this.campaignsLoading = false;
                }
            },

            /**
             * Start a sequence-based campaign
             */
            async startSequenceCampaign(payload) {
                this.startingCampaign = true;
                try {
                    const response = await this.api(`pit-bridge/appearances/${this.config.interviewId}/campaigns/sequence`, {
                        method: 'POST',
                        body: JSON.stringify(payload),
                    });
                    if (response.success) {
                        // Refresh campaigns and messages
                        await Promise.all([
                            this.loadCampaigns(),
                            this.loadMessages(),
                            this.loadEmailStats(),
                            this.loadNotes(),
                        ]);
                    }
                    return response;
                } catch (err) {
                    console.error('Failed to start campaign:', err);
                    throw err;
                } finally {
                    this.startingCampaign = false;
                }
            },

            // =================================================================
            // TAGS (v3.4.0)
            // =================================================================

            /**
             * Load tags for this appearance
             */
            async loadTags() {
                this.tagsLoading = true;
                try {
                    const response = await this.api(`appearances/${this.config.interviewId}/tags`);
                    this.tags = response.data || [];
                } catch (err) {
                    console.error('Failed to load tags:', err);
                } finally {
                    this.tagsLoading = false;
                }
            },

            /**
             * Load all available tags for the user
             */
            async loadAvailableTags() {
                try {
                    const response = await this.api('tags');
                    this.availableTags = response.data || [];
                } catch (err) {
                    console.error('Failed to load available tags:', err);
                }
            },

            /**
             * Add a tag to this appearance
             * Can use existing tag_id or create new tag with name
             */
            async addTag(tagData) {
                this.tagsLoading = true;
                try {
                    const response = await this.api(`appearances/${this.config.interviewId}/tags`, {
                        method: 'POST',
                        body: JSON.stringify(tagData),
                    });
                    // Update local tags with the returned list
                    this.tags = response.all_tags || [];
                    // Refresh available tags (new tag may have been created)
                    await this.loadAvailableTags();
                    this.tagInput = '';
                    this.showTagDropdown = false;
                    return response.data;
                } catch (err) {
                    this.error = err.message;
                    throw err;
                } finally {
                    this.tagsLoading = false;
                }
            },

            /**
             * Remove a tag from this appearance
             */
            async removeTag(tagId) {
                this.tagsLoading = true;
                try {
                    const response = await this.api(`appearances/${this.config.interviewId}/tags/${tagId}`, {
                        method: 'DELETE',
                    });
                    // Update local tags with the returned list
                    this.tags = response.all_tags || [];
                } catch (err) {
                    this.error = err.message;
                    throw err;
                } finally {
                    this.tagsLoading = false;
                }
            },

            /**
             * Create a new tag (master list)
             */
            async createTag(tagData) {
                try {
                    const response = await this.api('tags', {
                        method: 'POST',
                        body: JSON.stringify(tagData),
                    });
                    await this.loadAvailableTags();
                    return response.data;
                } catch (err) {
                    this.error = err.message;
                    throw err;
                }
            },

            /**
             * Delete a tag from master list
             */
            async deleteTag(tagId) {
                try {
                    await this.api(`tags/${tagId}`, {
                        method: 'DELETE',
                    });
                    await this.loadAvailableTags();
                    // Also refresh appearance tags in case removed tag was applied
                    await this.loadTags();
                } catch (err) {
                    this.error = err.message;
                    throw err;
                }
            },

            /**
             * Get filtered available tags based on search input
             */
            getFilteredTags() {
                const appliedIds = new Set(this.tags.map(t => t.id));
                const search = this.tagInput?.toLowerCase() || '';

                return this.availableTags.filter(t => {
                    if (appliedIds.has(t.id)) return false;
                    if (search && !t.name.toLowerCase().includes(search)) return false;
                    return true;
                });
            },

            /**
             * Check if current input matches an existing tag
             */
            tagInputMatchesExisting() {
                if (!this.tagInput) return false;
                const search = this.tagInput.toLowerCase().trim();
                return this.availableTags.some(t => t.name.toLowerCase() === search);
            }
        }
    });

    // ==========================================================================
    // MAIN APP COMPONENT
    // ==========================================================================
    const InterviewDetailApp = {
        template: `
            <div class="container">
                <!-- Back Button -->
                <a :href="boardUrl" class="back-button">
                    <svg class="back-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M19 12H5"></path>
                        <path d="M12 19l-7-7 7-7"></path>
                    </svg>
                    Back to Interviews
                </a>

                <!-- Loading State -->
                <div v-if="loading" class="pit-loading">
                    <div class="pit-loading-spinner"></div>
                    <p>Loading interview details...</p>
                </div>

                <!-- Error State -->
                <div v-else-if="error" class="pit-error">
                    <p>{{ error }}</p>
                    <a :href="boardUrl">Return to Interview Tracker</a>
                </div>

                <!-- Main Content -->
                <template v-else>
                    <!-- Podcast Header -->
                    <div class="podcast-header">
                        <img v-if="interview?.podcast_image"
                             :alt="interview.podcast_name"
                             class="podcast-artwork"
                             :src="interview.podcast_image"
                             @error="handleImageError">
                        <div v-else class="podcast-artwork" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center; color: white; font-size: 36px; font-weight: bold;">
                            {{ initials }}
                        </div>
                        
                        <div class="podcast-info">
                            <div class="podcast-title-row">
                                <h1 class="podcast-title">{{ interview?.podcast_name || 'Unknown Podcast' }}</h1>
                                <div class="priority-badge" :class="interview?.priority || 'medium'">
                                    <span class="priority-indicator"></span>
                                    {{ capitalize(interview?.priority || 'medium') }}
                                </div>
                            </div>
                            
                            <div class="podcast-meta">
                                <span>{{ interview?.host_name || 'Unknown Host' }}</span> •
                                <span>Last release: {{ formatDate(interview?.last_episode_date) }}</span> •
                                <span>{{ interview?.language || 'English' }}</span>
                            </div>
                            
                            <div class="tag-container">
                                <span v-for="tag in (interview?.categories || [])" :key="tag" class="tag">{{ tag }}</span>
                            </div>
                        </div>
                        
                        <!-- Profile Section -->
                        <div class="profile-section">
                            <div class="profile-label">Connected Profile</div>
                            <div class="profile-name">{{ interview?.guest_profile_name || 'Not Connected' }}</div>
                            <div class="profile-actions">
                                <a v-if="interview?.guest_profile_id" :href="interview?.guest_profile_link || ('/app/profiles/guest/profile/?id=' + interview.guest_profile_id)" target="_blank" class="button primary-button">
                                    View
                                </a>
                                <button class="button secondary-button" @click="openProfileModal">Edit Profile</button>
                            </div>
                        </div>
                    </div>

                    <!-- Profile Modal -->
                    <div v-if="showProfileModal" class="modal-overlay">
                        <div class="modal-content connect-profile-section" role="dialog" aria-modal="true" style="max-width: 500px;">
                            <div class="modal-header">
                                <h2>Connect Profile</h2>
                                <button class="close-button" @click="closeProfileModal" aria-label="Close">&times;</button>
                            </div>
                            <div class="modal-body">
                                <p class="description">Link this interview to one of your profiles.</p>
                                <div class="profile-selection">
                                    <label for="guest-profile-select">Profile</label>
                                    <select 
                                        id="guest-profile-select" 
                                        class="profile-dropdown"
                                        v-model="selectedProfileId" 
                                        :disabled="profilesLoading">
                                        <option value="">Not Connected</option>
                                        <option v-for="profile in availableProfiles" :key="profile.id" :value="profile.id">
                                            {{ formatProfileDropdownLabel(profile) }}
                                        </option>
                                    </select>
                                    <p v-if="profilesError" class="error-text">{{ profilesError }}</p>
                                </div>
                                
                                <!-- Show connected profile preview -->
                                <div v-if="selectedProfileId && selectedProfile" class="profile-info">
                                    <div class="profile-avatar">{{ getProfileInitials(selectedProfile.name) }}</div>
                                    <div class="profile-details">
                                        <div class="profile-name">{{ selectedProfile.name }}</div>
                                    </div>
                                    <span class="connected-profile-badge">Connected</span>
                                </div>
                            </div>
                            <div class="modal-actions button-group">
                                <button class="cancel-button" @click="closeProfileModal">Cancel</button>
                                <button class="save-button" :disabled="profileSaving" @click="saveProfileSelection">
                                    {{ profileSaving ? 'Saving...' : 'Save Connection' }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Tabs -->
                    <div class="tabs">
                        <input type="radio" name="tabs" id="tab-about" data-tab="about" :checked="activeTab === 'about'" @change="setTab('about')">
                        <input type="radio" name="tabs" id="tab-listen" data-tab="listen" :checked="activeTab === 'listen'" @change="setTab('listen')">
                        <input type="radio" name="tabs" id="tab-contact" data-tab="contact" :checked="activeTab === 'contact'" @change="setTab('contact')">
                        <input type="radio" name="tabs" id="tab-message" data-tab="message" :checked="activeTab === 'message'" @change="setTab('message')">
                        <input type="radio" name="tabs" id="tab-tasks" data-tab="tasks" :checked="activeTab === 'tasks'" @change="setTab('tasks')">
                        <input type="radio" name="tabs" id="tab-notes" data-tab="notes" :checked="activeTab === 'notes'" @change="setTab('notes')">

                        <div class="tabs-header">
                            <label for="tab-about">About</label>
                            <label for="tab-listen">Listen</label>
                            <label for="tab-contact">Contact</label>
                            <label for="tab-message">Message</label>
                            <label for="tab-tasks">Tasks</label>
                            <label for="tab-notes">Notes</label>
                        </div>

                        <!-- About Tab -->
                        <div class="tab-content about" :style="{ display: activeTab === 'about' ? 'block' : 'none' }">
                            <div class="about-layout">
                                <div class="about-main">
                                    <!-- Description Section -->
                                    <div class="content-section">
                                        <h2 class="section-heading">About the Show</h2>
                                        <div class="description-text">
                                            <div v-if="descriptionContent" v-html="descriptionContent"></div>
                                            <p v-else>No description available.</p>
                                            <button
                                                v-if="showDescriptionToggle"
                                                type="button"
                                                class="description-toggle"
                                                @click="toggleDescription">
                                                {{ isDescriptionExpanded ? 'Show less' : 'Read more' }}
                                            </button>
                                        </div>

                                        <div class="show-quick-info">
                                            <div class="quick-info-item">
                                                <div class="quick-info-label">Episodes</div>
                                                <div class="quick-info-value">{{ interview?.episode_count || 'N/A' }}</div>
                                            </div>
                                            <div class="quick-info-item">
                                                <div class="quick-info-label">Founded</div>
                                                <div class="quick-info-value">{{ formatFoundedDate(interview?.founded_date) }}</div>
                                            </div>
                                            <div class="quick-info-item">
                                                <div class="quick-info-label">Content Rating</div>
                                                <div class="quick-info-value">{{ formatContentRating(interview?.content_rating) }}</div>
                                            </div>
                                            <div class="quick-info-item">
                                                <div class="quick-info-label">Frequency</div>
                                                <div class="quick-info-value">{{ interview?.frequency || 'N/A' }}</div>
                                            </div>
                                        </div>
                                        
                                        <!-- Refresh Metadata Button -->
                                        <div class="refresh-metadata-section" style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #e2e8f0;">
                                            <button 
                                                class="button outline-button small" 
                                                @click="refreshPodcastMetadata"
                                                :disabled="saving"
                                                style="font-size: 12px;">
                                                <svg v-if="saving" class="button-icon spinning" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M21 12a9 9 0 1 1-6.219-8.56"></path>
                                                </svg>
                                                <svg v-else class="button-icon" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <polyline points="23 4 23 10 17 10"></polyline>
                                                    <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                                                </svg>
                                                {{ saving ? 'Refreshing...' : 'Refresh Show Data' }}
                                            </button>
                                            <span v-if="interview?.metadata_updated_at" style="font-size: 11px; color: #64748b; margin-left: 8px;">
                                                Last updated: {{ formatDate(interview.metadata_updated_at) }}
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Linked Episode Section (only shown when an episode is linked) -->
                                    <div v-if="linkedEpisode" class="panel linked-episode-panel">
                                        <div class="panel-header">
                                            <h3 class="panel-title">Linked Episode</h3>
                                            <div class="linked-actions">
                                                <span class="linked-badge" title="This episode is linked to this interview">
                                                    <svg class="button-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                                                    </svg>
                                                    Linked
                                                </span>
                                                <button
                                                    class="button small outline-button unlink-btn"
                                                    @click="handleUnlinkEpisode"
                                                    :disabled="unlinkingEpisode"
                                                    title="Unlink this episode from the interview">
                                                    <svg v-if="unlinkingEpisode" class="button-icon spinning" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M21 12a9 9 0 1 1-6.219-8.56"></path>
                                                    </svg>
                                                    <svg v-else class="button-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M18 6L6 18M6 6l12 12"></path>
                                                    </svg>
                                                    {{ unlinkingEpisode ? 'Unlinking...' : 'Unlink' }}
                                                </button>
                                            </div>
                                        </div>
                                        <div class="panel-content">
                                            <div class="linked-episode-card">
                                                <img
                                                    v-if="linkedEpisode.thumbnail || interview?.podcast_image"
                                                    :src="linkedEpisode.thumbnail || interview?.podcast_image"
                                                    :alt="linkedEpisode.title"
                                                    class="episode-thumbnail"
                                                    loading="lazy"
                                                    @error="$event.target.style.display='none'">
                                                <div v-else class="episode-thumbnail-placeholder">
                                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                                        <circle cx="12" cy="12" r="10"></circle>
                                                        <polygon points="10 8 16 12 10 16 10 8"></polygon>
                                                    </svg>
                                                </div>
                                                <div class="linked-episode-info">
                                                    <div class="linked-episode-meta">
                                                        <span class="linked-episode-date">{{ formatDate(linkedEpisode.date) }}</span>
                                                        <span v-if="linkedEpisode.duration" class="linked-episode-duration">
                                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                                <circle cx="12" cy="12" r="10"></circle>
                                                                <polyline points="12 6 12 12 16 14"></polyline>
                                                            </svg>
                                                            {{ linkedEpisode.duration }}
                                                        </span>
                                                    </div>
                                                    <h4 class="linked-episode-title">{{ linkedEpisode.title }}</h4>
                                                    <p v-if="linkedEpisode.description" class="linked-episode-description">
                                                        {{ truncateDescription(linkedEpisode.description) }}
                                                    </p>
                                                    <div v-if="linkedEpisode.audio_url" class="episode-player">
                                                        <audio controls preload="none">
                                                            <source :src="linkedEpisode.audio_url" type="audio/mpeg">
                                                            Your browser does not support audio.
                                                        </audio>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Collaboration Section -->
                                    <div class="panel">
                                        <div class="panel-header">
                                            <h3 class="panel-title">Collaboration Details</h3>
                                        </div>
                                        <div class="panel-content">
                                            <ul class="collab-list">
                                                <li class="collab-item collab-item-editable" @click="openCollabModal('audience')">
                                                    <svg class="collab-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                                        <circle cx="9" cy="7" r="4"></circle>
                                                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                                                    </svg>
                                                    <div class="collab-content">
                                                        <div class="collab-label">Audience</div>
                                                        <div class="collab-value" :class="{ 'not-set': !interview?.audience }">{{ interview?.audience || 'Not specified' }}</div>
                                                    </div>
                                                    <div class="edit-button-container">
                                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                                        </svg>
                                                    </div>
                                                </li>
                                                <li class="collab-item collab-item-editable" @click="openCollabModal('commission')">
                                                    <svg class="collab-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <line x1="12" y1="1" x2="12" y2="23"></line>
                                                        <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                                                    </svg>
                                                    <div class="collab-content">
                                                        <div class="collab-label">Commission</div>
                                                        <div class="collab-value" :class="{ 'not-set': !interview?.commission }">{{ interview?.commission || 'Not specified' }}</div>
                                                    </div>
                                                    <div class="edit-button-container">
                                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                                        </svg>
                                                    </div>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>

                                <!-- Sidebar -->
                                <div class="about-sidebar">
                                    
                                    <!-- Archive Banner -->
                                    <div v-if="interview?.is_archived" class="archive-banner" id="archive-banner">
                                        <div class="archive-message">
                                            <svg class="archive-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <polyline points="21 8 21 21 3 21 3 8"></polyline>
                                                <rect x="1" y="3" width="22" height="5"></rect>
                                                <line x1="10" y1="12" x2="14" y2="12"></line>
                                            </svg>
                                            This interview is archived.
                                        </div>
                                        <button type="button" class="restore-btn action-btn" @click="handleRestore" :disabled="saving">
                                            <svg class="action-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path>
                                                <path d="M3 3v5h5"></path>
                                            </svg>
                                            {{ saving ? 'Restoring...' : 'Restore' }}
                                        </button>
                                    </div>

                                    <!-- Milestones Card -->
                                    <div class="sidebar-card">
                                        <div class="sidebar-header">
                                            <h3 class="sidebar-title">Milestones</h3>
                                        </div>
                                        <div class="sidebar-content">
                                            <div class="milestone-track">
                                                <div v-for="step in milestones" 
                                                     :key="step.id"
                                                     class="milestone"
                                                     :class="{ current: interview?.status === step.id }"
                                                     @click="setStatus(step.id)">
                                                    {{ step.label }}
                                                </div>
                                            </div>
                                            
                                            <div class="divider"></div>
                                            
                                            <div class="view-actions">
                                                <!-- Archive Button (shown when not archived) -->
                                                <button v-if="!interview?.is_archived" class="archive-btn action-btn" @click="handleArchive" :disabled="saving">
                                                    <svg class="action-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <polyline points="21 8 21 21 3 21 3 8"></polyline>
                                                        <rect x="1" y="3" width="22" height="5"></rect>
                                                        <line x1="10" y1="12" x2="14" y2="12"></line>
                                                    </svg>
                                                    {{ saving ? 'Archiving...' : 'Archive' }}
                                                </button>
                                                
                                                <!-- Restore Button (shown when archived) -->
                                                <button v-if="interview?.is_archived" class="restore-btn action-btn" @click="handleRestore" :disabled="saving">
                                                    <svg class="action-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path>
                                                        <path d="M3 3v5h5"></path>
                                                    </svg>
                                                    {{ saving ? 'Restoring...' : 'Restore' }}
                                                </button>
                                                
                                                <!-- Delete Button (only shown when archived) -->
                                                <button v-if="interview?.is_archived" class="action-btn open-delete-modal" @click="showDeleteModal = true">
                                                    <svg class="action-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <polyline points="3 6 5 6 21 6"></polyline>
                                                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                                        <line x1="10" y1="11" x2="10" y2="17"></line>
                                                        <line x1="14" y1="11" x2="14" y2="17"></line>
                                                    </svg>
                                                    Delete
                                                </button>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Important Dates Card -->
                                    <div class="sidebar-card">
                                        <div class="sidebar-header">
                                            <h3 class="sidebar-title">Important Dates</h3>
                                        </div>
                                        <div class="sidebar-content">
                                            <div class="date-wrapper" @click="openDateModal('record')" style="cursor: pointer; padding: 8px 0;">
                                                <div class="date-content" style="padding: 0;">
                                                    <svg class="date-icon" style="color: #64748b;" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                                        <line x1="16" y1="2" x2="16" y2="6"></line>
                                                        <line x1="8" y1="2" x2="8" y2="6"></line>
                                                        <line x1="3" y1="10" x2="21" y2="10"></line>
                                                    </svg>
                                                    <div class="date-info" style="margin-right: 8px;">
                                                        <div class="date-label" :style="{ color: interview?.record_date ? '#0ea5e9' : '#64748b' }">Record Date</div>
                                                        <div class="date-value" :style="{ color: interview?.record_date ? '#334155' : '#94a3b8', fontStyle: interview?.record_date ? 'normal' : 'italic' }">
                                                            {{ interview?.record_date ? formatDate(interview.record_date) : 'Not set' }}
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="edit-button-container">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                                    </svg>
                                                </div>
                                            </div>
                                            
                                            <div class="date-wrapper" @click="openDateModal('air')" style="cursor: pointer; padding: 8px 0;">
                                                <div class="date-content" style="padding: 0;">
                                                    <svg class="date-icon" style="color: #64748b;" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                                        <line x1="16" y1="2" x2="16" y2="6"></line>
                                                        <line x1="8" y1="2" x2="8" y2="6"></line>
                                                        <line x1="3" y1="10" x2="21" y2="10"></line>
                                                        <polyline points="20 7 12 15 8 11"></polyline>
                                                    </svg>
                                                    <div class="date-info" style="margin-right: 8px;">
                                                        <div class="date-label" :style="{ color: interview?.air_date ? '#0ea5e9' : '#64748b' }">Air Date</div>
                                                        <div class="date-value" :style="{ color: interview?.air_date ? '#334155' : '#94a3b8', fontStyle: interview?.air_date ? 'normal' : 'italic' }">
                                                            {{ interview?.air_date ? formatDate(interview.air_date) : 'Not set' }}
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="edit-button-container">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                                    </svg>
                                                </div>
                                            </div>
                                            
                                            <div class="date-wrapper" @click="openDateModal('promotion')" style="cursor: pointer; padding: 8px 0;">
                                                <div class="date-content" style="padding: 0;">
                                                    <svg class="date-icon" style="color: #64748b;" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                                        <line x1="16" y1="2" x2="16" y2="6"></line>
                                                        <line x1="8" y1="2" x2="8" y2="6"></line>
                                                        <line x1="3" y1="10" x2="21" y2="10"></line>
                                                        <path d="M8 14h.01"></path>
                                                        <path d="M12 14h.01"></path>
                                                        <path d="M16 14h.01"></path>
                                                    </svg>
                                                    <div class="date-info" style="margin-right: 8px;">
                                                        <div class="date-label" :style="{ color: interview?.promotion_date ? '#0ea5e9' : '#64748b' }">Promotion Date(s)</div>
                                                        <div class="date-value" :style="{ color: interview?.promotion_date ? '#334155' : '#94a3b8', fontStyle: interview?.promotion_date ? 'normal' : 'italic' }">
                                                            {{ interview?.promotion_date ? formatDate(interview.promotion_date) : 'Not set' }}
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="edit-button-container">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                                    </svg>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Interview Details Card -->
                                    <div class="sidebar-card">
                                        <div class="sidebar-header">
                                            <h3 class="sidebar-title">Interview Details</h3>
                                        </div>
                                        <div class="sidebar-content marketing-section">
                                            <h4>Episode Title</h4>
                                            <div class="tag-content">
                                                <p>{{ interview?.episode_title || 'Not set' }}</p>
                                            </div>
                                            <h4>Episode Number</h4>
                                            <div class="tag-content">
                                                <p>{{ interview?.episode_number || 'Not set' }}</p>
                                            </div>
                                            <h4>Interview Topic</h4>
                                            <div class="tag-content">
                                                <p>{{ interview?.interview_topic || 'Not set' }}</p>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Interview Source Card -->
                                    <div class="sidebar-card">
                                        <div class="sidebar-header">
                                            <h3 class="sidebar-title">Interview Source</h3>
                                        </div>
                                        <div class="sidebar-content marketing-section">
                                            <h4>Source</h4>
                                            <div class="tag-content">
                                                <p>{{ interview?.source || 'Not specified' }}</p>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Tags Card -->
                                    <div class="sidebar-card">
                                        <div class="sidebar-header">
                                            <h3 class="sidebar-title">Tags</h3>
                                        </div>
                                        <div class="sidebar-content">
                                            <!-- Applied Tags -->
                                            <div class="applied-tags">
                                                <span
                                                    v-for="tag in tags"
                                                    :key="tag.id"
                                                    class="appearance-tag"
                                                    :style="{ backgroundColor: tag.color + '20', color: tag.color, borderColor: tag.color }">
                                                    {{ tag.name }}
                                                    <button
                                                        type="button"
                                                        @click="handleRemoveTag(tag.id)"
                                                        class="appearance-tag-remove"
                                                        :style="{ color: tag.color }"
                                                        title="Remove tag">
                                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <line x1="18" y1="6" x2="6" y2="18"></line>
                                                            <line x1="6" y1="6" x2="18" y2="18"></line>
                                                        </svg>
                                                    </button>
                                                </span>
                                                <span v-if="tags.length === 0 && !tagsLoading" class="tags-empty-text">
                                                    No tags applied
                                                </span>
                                            </div>

                                            <!-- Tag Input -->
                                            <div class="tag-input-wrapper">
                                                <div class="tag-input-row">
                                                    <input
                                                        type="text"
                                                        v-model="tagInput"
                                                        placeholder="Add a tag..."
                                                        class="tag-input"
                                                        @focus="showTagDropdown = true"
                                                        @keydown.enter.prevent="handleTagInputEnter"
                                                        @keydown.escape="showTagDropdown = false">
                                                    <button
                                                        v-if="tagInput && !tagInputMatchesExisting"
                                                        type="button"
                                                        @click="handleCreateAndAddTag"
                                                        class="button small"
                                                        :disabled="tagsLoading">
                                                        Create
                                                    </button>
                                                </div>

                                                <!-- Tag Dropdown -->
                                                <div
                                                    v-if="showTagDropdown && filteredTags.length > 0"
                                                    class="tag-dropdown">
                                                    <div
                                                        v-for="tag in filteredTags"
                                                        :key="tag.id"
                                                        @click="handleSelectTag(tag)"
                                                        class="tag-dropdown-item">
                                                        <span
                                                            class="tag-color-dot"
                                                            :style="{ backgroundColor: tag.color }"></span>
                                                        <span class="tag-dropdown-name">{{ tag.name }}</span>
                                                        <span class="tag-dropdown-count">{{ tag.usage_count }} uses</span>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Close dropdown when clicking outside -->
                                            <div
                                                v-if="showTagDropdown"
                                                @click="showTagDropdown = false"
                                                class="tag-dropdown-backdrop"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Listen Tab -->
                        <div class="tab-content listen" :style="{ display: activeTab === 'listen' ? 'block' : 'none' }">
                            <div class="listen-layout">
                                <div class="listen-main">
                                    <!-- Header with Refresh Button -->
                                    <div class="section-header">
                                        <h2 class="section-heading">Recent Episodes</h2>
                                        <button
                                            class="button outline-button small"
                                            @click="refreshEpisodes"
                                            :disabled="episodesLoading">
                                            <svg v-if="episodesLoading" class="button-icon spinning" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M21 12a9 9 0 1 1-6.219-8.56"></path>
                                            </svg>
                                            <svg v-else class="button-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <polyline points="23 4 23 10 17 10"></polyline>
                                                <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                                            </svg>
                                            {{ episodesLoading ? 'Loading...' : 'Refresh' }}
                                        </button>
                                    </div>

                                    <!-- Episode Search Filter -->
                                    <div v-if="episodes.length > 0 || searchInputValue" class="episode-search-filter">
                                        <div class="search-input-wrapper">
                                            <svg class="search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="11" cy="11" r="8"></circle>
                                                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                            </svg>
                                            <input
                                                type="text"
                                                :value="searchInputValue"
                                                @input="handleSearchInput"
                                                placeholder="Search all episodes by name or keyword..."
                                                class="episode-search-input"
                                                @keypress.enter.prevent>
                                            <svg v-if="episodesLoading && searchInputValue" class="search-spinner spinning" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M21 12a9 9 0 1 1-6.219-8.56"></path>
                                            </svg>
                                            <button
                                                v-else-if="searchInputValue"
                                                class="search-clear-btn"
                                                @click="clearSearch"
                                                title="Clear search">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <line x1="18" y1="6" x2="6" y2="18"></line>
                                                    <line x1="6" y1="6" x2="18" y2="18"></line>
                                                </svg>
                                            </button>
                                        </div>
                                        <div v-if="episodeSearchTerm && !episodesLoading" class="search-results-count">
                                            Found {{ episodesMeta.totalAvailable }} episodes matching "{{ episodeSearchTerm }}"
                                            <span v-if="episodesMeta.totalInFeed"> ({{ episodesMeta.totalInFeed }} total in feed)</span>
                                        </div>
                                    </div>

                                    <!-- Loading State -->
                                    <div v-if="episodesLoading && episodes.length === 0" class="episodes-loading">
                                        <div class="pit-loading-spinner"></div>
                                        <p>Loading episodes from RSS feed...</p>
                                    </div>

                                    <!-- Error State -->
                                    <div v-else-if="episodesError && episodes.length === 0" class="notes-empty">
                                        <svg class="notes-empty-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                            <circle cx="12" cy="12" r="10"></circle>
                                            <line x1="12" y1="8" x2="12" y2="12"></line>
                                            <line x1="12" y1="16" x2="12.01" y2="16"></line>
                                        </svg>
                                        <h3 class="notes-empty-title">Unable to Load Episodes</h3>
                                        <p class="notes-empty-text">{{ episodesError }}</p>
                                        <button class="button outline-button" @click="refreshEpisodes">Try Again</button>
                                    </div>

                                    <!-- Empty State (no episodes and not searching) -->
                                    <div v-else-if="episodes.length === 0 && !episodeSearchTerm" class="notes-empty">
                                        <svg class="notes-empty-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                            <circle cx="12" cy="12" r="10"></circle>
                                            <polygon points="10 8 16 12 10 16 10 8"></polygon>
                                        </svg>
                                        <h3 class="notes-empty-title">No Episodes Found</h3>
                                        <p class="notes-empty-text">Episodes from the RSS feed will appear here. Click Refresh to load them.</p>
                                        <button class="button outline-button" @click="refreshEpisodes">Load Episodes</button>
                                    </div>

                                    <!-- No Search Results -->
                                    <div v-else-if="episodes.length === 0 && episodeSearchTerm" class="notes-empty">
                                        <svg class="notes-empty-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                            <circle cx="11" cy="11" r="8"></circle>
                                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                        </svg>
                                        <h3 class="notes-empty-title">No Matching Episodes</h3>
                                        <p class="notes-empty-text">No episodes match "{{ episodeSearchTerm }}" in the entire feed. Try a different search term.</p>
                                        <button class="button outline-button" @click="clearSearch">Clear Search</button>
                                    </div>

                                    <!-- Episodes List -->
                                    <div v-else class="episodes-list">
                                        <div v-for="(episode, index) in episodes" :key="episode.guid || index" class="episode-card">
                                            <!-- Episode Thumbnail -->
                                            <img 
                                                v-if="episode.thumbnail_url" 
                                                :src="episode.thumbnail_url" 
                                                :alt="episode.title"
                                                class="episode-thumbnail"
                                                loading="lazy"
                                                @error="$event.target.style.display='none'">
                                            <div v-else class="episode-thumbnail-placeholder">
                                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                                    <circle cx="12" cy="12" r="10"></circle>
                                                    <polygon points="10 8 16 12 10 16 10 8"></polygon>
                                                </svg>
                                            </div>

                                            <!-- Episode Info -->
                                            <div class="episode-info">
                                                <div class="episode-date">{{ episode.date }}</div>
                                                <h3 class="episode-title">{{ episode.title }}</h3>
                                                
                                                <!-- Expandable Description -->
                                                <div v-if="episode.description" class="episode-expand">
                                                    <input :id="'ep-toggle-' + index" type="checkbox" class="episode-toggle-input">
                                                    <label :for="'ep-toggle-' + index" class="episode-toggle-label">
                                                        <span class="toggle-show">Show description</span>
                                                        <span class="toggle-hide">Hide description</span>
                                                        <svg class="toggle-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <polyline points="6 9 12 15 18 9"></polyline>
                                                        </svg>
                                                    </label>
                                                    <div class="episode-description-content">
                                                        <p>{{ episode.description }}</p>
                                                    </div>
                                                </div>

                                                <!-- Audio Player -->
                                                <div v-if="episode.audio_url" class="episode-player">
                                                    <audio controls preload="none">
                                                        <source :src="episode.audio_url" type="audio/mpeg">
                                                        Your browser does not support audio.
                                                    </audio>
                                                </div>
                                            </div>

                                            <!-- Episode Actions -->
                                            <div class="episode-actions">
                                                <!-- Duration Badge -->
                                                <div v-if="episode.duration_display" class="episode-duration">
                                                    <svg class="duration-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <circle cx="12" cy="12" r="10"></circle>
                                                        <polyline points="12 6 12 12 16 14"></polyline>
                                                    </svg>
                                                    {{ episode.duration_display }}
                                                </div>

                                                <!-- Link Episode Button -->
                                                <button
                                                    v-if="!hasLinkedEpisode"
                                                    class="button small link-episode-btn"
                                                    @click="handleLinkEpisode(episode)"
                                                    :disabled="linkingEpisode"
                                                    :title="'Link this episode to mark interview as aired'">
                                                    <svg v-if="linkingEpisode" class="button-icon spinning" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M21 12a9 9 0 1 1-6.219-8.56"></path>
                                                    </svg>
                                                    <svg v-else class="button-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                                                        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                                                    </svg>
                                                    {{ linkingEpisode ? 'Linking...' : 'Link Episode' }}
                                                </button>

                                                <!-- Already Linked - Show Badge + Unlink Button -->
                                                <div v-else-if="isEpisodeLinked(episode)" class="linked-actions" style="display: flex; align-items: center; gap: 8px;">
                                                    <span
                                                        class="linked-badge"
                                                        title="This episode is linked to this interview">
                                                        <svg class="button-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                                            <polyline points="22 4 12 14.01 9 11.01"></polyline>
                                                        </svg>
                                                        Linked
                                                    </span>
                                                    <button
                                                        class="button small outline-button unlink-btn"
                                                        @click="handleUnlinkEpisode"
                                                        :disabled="unlinkingEpisode"
                                                        title="Unlink this episode from the interview"
                                                        style="color: #dc2626; border-color: #fecaca;">
                                                        <svg v-if="unlinkingEpisode" class="button-icon spinning" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <path d="M21 12a9 9 0 1 1-6.219-8.56"></path>
                                                        </svg>
                                                        <svg v-else class="button-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <path d="M18 6L6 18M6 6l12 12"></path>
                                                        </svg>
                                                        {{ unlinkingEpisode ? 'Unlinking...' : 'Unlink' }}
                                                    </button>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Load More Button -->
                                        <div v-if="episodesMeta.hasMore" class="load-more">
                                            <button 
                                                class="button outline-button" 
                                                @click="loadMoreEpisodes"
                                                :disabled="episodesLoading">
                                                <svg v-if="episodesLoading" class="button-icon spinning" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M21 12a9 9 0 1 1-6.219-8.56"></path>
                                                </svg>
                                                <svg v-else class="button-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <polyline points="7 13 12 18 17 13"></polyline>
                                                    <polyline points="7 6 12 11 17 6"></polyline>
                                                </svg>
                                                {{ episodesLoading ? 'Loading...' : 'Load More Episodes' }}
                                            </button>
                                        </div>

                                        <!-- Episodes Count -->
                                        <div v-if="episodes.length > 0" class="episodes-meta">
                                            <span v-if="episodeSearchTerm">Showing {{ episodes.length }} of {{ episodesMeta.totalAvailable }} matching episodes</span>
                                            <span v-else>Showing {{ episodes.length }} of {{ episodesMeta.totalAvailable }} episodes</span>
                                            <span v-if="episodesMeta.cached" class="cache-indicator" :title="'Cached until ' + episodesMeta.cacheExpires">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <circle cx="12" cy="12" r="10"></circle>
                                                    <polyline points="12 6 12 12 16 14"></polyline>
                                                </svg>
                                                Cached
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Sidebar -->
                                <div class="listen-sidebar">
                                    <div class="sidebar-card">
                                        <div class="sidebar-header">
                                            <h3 class="sidebar-title">Websites</h3>
                                        </div>
                                        <div class="sidebar-content">
                                            <div class="social-links-list">
                                                <a v-if="interview?.website" :href="interview.website" target="_blank" class="social-link-item">
                                                    <svg class="social-icon website" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <circle cx="12" cy="12" r="10"></circle>
                                                        <line x1="2" y1="12" x2="22" y2="12"></line>
                                                        <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
                                                    </svg>
                                                    <span class="social-link-text">{{ interview.website }}</span>
                                                </a>
                                                <p v-else class="no-links-text">No website configured</p>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Podcast Info Card -->
                                    <div class="sidebar-card">
                                        <div class="sidebar-header">
                                            <h3 class="sidebar-title">Podcast Info</h3>
                                        </div>
                                        <div class="sidebar-content">
                                            <div class="podcast-quick-stats">
                                                <div class="stat-item">
                                                    <span class="stat-label">Total Episodes</span>
                                                    <span class="stat-value">{{ episodesMeta.totalAvailable || 'N/A' }}</span>
                                                </div>
                                                <div class="stat-item">
                                                    <span class="stat-label">Latest Release</span>
                                                    <span class="stat-value">{{ episodes[0]?.date || 'N/A' }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Contact Tab -->
                        <div class="tab-content contact" :style="{ display: activeTab === 'contact' ? 'block' : 'none' }">
                            <div class="contact-layout">
                                <div class="contact-main">
                                    <div class="section-header">
                                        <h2 class="section-heading">Contact Information</h2>
                                        <button class="button outline-button small">
                                            <svg class="button-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <line x1="12" y1="5" x2="12" y2="19"></line>
                                                <line x1="5" y1="12" x2="19" y2="12"></line>
                                            </svg>
                                            Add Contact
                                        </button>
                                    </div>
                                    <div class="contacts-grid">
                                        <div v-if="interview?.host_name" class="contact-card">
                                            <div class="contact-card-header">
                                                <div class="contact-avatar">{{ getInitials(interview.host_name) }}</div>
                                                <div>
                                                    <h3 class="contact-name">{{ interview.host_name }}</h3>
                                                    <span class="contact-role">Host</span>
                                                </div>
                                            </div>
                                            <div class="contact-details">
                                                <div v-if="interview.host_email" class="contact-detail-item">
                                                    <svg class="contact-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                                                        <polyline points="22,6 12,13 2,6"></polyline>
                                                    </svg>
                                                    <a :href="'mailto:' + interview.host_email" class="contact-detail-text">{{ interview.host_email }}</a>
                                                </div>
                                                <div class="contact-actions">
                                                    <button class="contact-action-button">
                                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                                                            <polyline points="22,6 12,13 2,6"></polyline>
                                                        </svg>
                                                        Email
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="contact-sidebar">
                                    <div class="sidebar-card">
                                        <div class="sidebar-header">
                                            <h3 class="sidebar-title">Quick Actions</h3>
                                        </div>
                                        <div class="sidebar-content">
                                            <div class="quick-action-buttons">
                                                <button class="quick-action-button">
                                                    <svg class="quick-action-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                                                        <polyline points="22,6 12,13 2,6"></polyline>
                                                    </svg>
                                                    <span>Send Email</span>
                                                </button>
                                                <button class="quick-action-button">
                                                    <svg class="quick-action-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                                        <line x1="16" y1="2" x2="16" y2="6"></line>
                                                        <line x1="8" y1="2" x2="8" y2="6"></line>
                                                        <line x1="3" y1="10" x2="21" y2="10"></line>
                                                    </svg>
                                                    <span>Schedule</span>
                                                </button>
                                                <button class="quick-action-button" @click="setTab('notes')">
                                                    <svg class="quick-action-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                                        <polyline points="14 2 14 8 20 8"></polyline>
                                                        <line x1="16" y1="13" x2="8" y2="13"></line>
                                                        <line x1="16" y1="17" x2="8" y2="17"></line>
                                                    </svg>
                                                    <span>Add Note</span>
                                                </button>
                                                <button class="quick-action-button">
                                                    <svg class="quick-action-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                                    </svg>
                                                    <span>Edit</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Message Tab -->
                        <div class="tab-content message" :style="{ display: activeTab === 'message' ? 'block' : 'none' }">
                            <div class="tab-content-full">
                                <!-- Not Available State -->
                                <div v-if="!emailAvailable" class="notes-empty">
                                    <svg class="notes-empty-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                                        <polyline points="22,6 12,13 2,6"></polyline>
                                    </svg>
                                    <h3 class="notes-empty-title">Email Integration Required</h3>
                                    <p class="notes-empty-text">Install and activate the Guestify Outreach plugin to send emails from here.</p>
                                </div>

                                <!-- Not Configured State -->
                                <div v-else-if="!emailConfigured" class="notes-empty">
                                    <svg class="notes-empty-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <circle cx="12" cy="12" r="10"></circle>
                                        <line x1="12" y1="8" x2="12" y2="12"></line>
                                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                                    </svg>
                                    <h3 class="notes-empty-title">Email Not Configured</h3>
                                    <p class="notes-empty-text">Please configure your Brevo API key in Guestify Outreach settings.</p>
                                </div>

                                <!-- Email Interface -->
                                <div v-else class="email-interface">
                                    <!-- Email Stats -->
                                    <div class="email-stats-bar">
                                        <div class="email-stat">
                                            <span class="email-stat-number">{{ emailStats.total_sent }}</span>
                                            <span class="email-stat-label">Sent</span>
                                        </div>
                                        <div class="email-stat">
                                            <span class="email-stat-number">{{ emailStats.opened }}</span>
                                            <span class="email-stat-label">Opened</span>
                                        </div>
                                        <div class="email-stat">
                                            <span class="email-stat-number">{{ emailStats.clicked }}</span>
                                            <span class="email-stat-label">Clicked</span>
                                        </div>
                                    </div>

                                    <!-- Compose Email Header -->
                                    <div class="email-actions-header" v-if="!showComposeModal">
                                        <h3 class="section-heading">Messages & Campaigns</h3>
                                        <button class="button add-button" @click="openComposeModal">
                                            <span style="margin-right: 6px;">+</span>
                                            Compose Email
                                        </button>
                                    </div>

                                    <!-- Inline Composer (when composing) -->
                                    <div v-if="showComposeModal" class="inline-composer">
                                        <div class="inline-composer-header">
                                            <h3 class="inline-composer-title">{{ composeMode === 'campaign' ? 'Start Campaign' : 'Compose Email' }}</h3>
                                            <button class="inline-composer-close" @click="closeComposeModal">✕</button>
                                        </div>

                                        <div class="inline-composer-layout">
                                            <div class="inline-composer-main">
                                                <!-- Mode Toggle -->
                                                <div class="compose-mode-toggle" v-if="emailFeatures.campaigns && sequences.length > 0">
                                                    <button class="mode-btn" :class="{ active: composeMode === 'single' }" @click="composeMode = 'single'; showAIPanel = false;">
                                                        ✉️ Single Email
                                                    </button>
                                                    <button class="mode-btn" :class="{ active: composeMode === 'campaign' }" @click="composeMode = 'campaign'; showAIPanel = false;">
                                                        👥 Start Campaign
                                                    </button>
                                                </div>

                                                <!-- SINGLE EMAIL MODE -->
                                                <template v-if="composeMode === 'single'">
                                                    <!-- AI Refinement Panel -->
                                                    <div v-if="showAIPanel" class="ai-refinement-panel">
                                                        <div class="ai-panel-header">
                                                            <div class="ai-header-left">
                                                                <span>✨</span>
                                                                <h4>Refine with AI</h4>
                                                            </div>
                                                            <button class="ai-panel-close" @click="showAIPanel = false">✕</button>
                                                        </div>

                                                        <div class="ai-quick-actions">
                                                            <label class="ai-section-label">Quick actions:</label>
                                                            <div class="ai-action-buttons">
                                                                <button v-for="action in aiQuickActions" :key="action.id" class="ai-quick-btn" @click="handleAIQuickAction(action)" :disabled="aiGenerating">
                                                                    <span>{{ action.icon }}</span> {{ action.label }}
                                                                </button>
                                                            </div>
                                                        </div>

                                                        <div class="ai-custom-prompt">
                                                            <label class="ai-section-label">Or describe what you want:</label>
                                                            <textarea v-model="aiPrompt" class="ai-prompt-input" rows="2" placeholder="e.g., Write a warm intro that mentions their recent episode about marketing..."></textarea>
                                                        </div>

                                                        <div class="ai-options-row">
                                                            <div class="ai-option">
                                                                <label>Tone:</label>
                                                                <select v-model="aiTone">
                                                                    <option value="professional">Professional</option>
                                                                    <option value="friendly">Friendly</option>
                                                                    <option value="casual">Casual</option>
                                                                    <option value="enthusiastic">Enthusiastic</option>
                                                                </select>
                                                            </div>
                                                            <div class="ai-option">
                                                                <label>Length:</label>
                                                                <select v-model="aiLength">
                                                                    <option value="short">Short</option>
                                                                    <option value="medium">Medium</option>
                                                                    <option value="long">Detailed</option>
                                                                </select>
                                                            </div>
                                                        </div>

                                                        <button class="ai-generate-btn" @click="handleAIGenerate" :disabled="aiGenerating">
                                                            <span v-if="aiGenerating">⏳ Generating...</span>
                                                            <span v-else>✨ Generate Email</span>
                                                        </button>
                                                    </div>

                                                    <!-- Preview/Template Toggle -->
                                                    <div class="step-preview-header single-email-toggle">
                                                        <div class="preview-toggle">
                                                            <button :class="{ active: singlePreviewMode }" @click="singlePreviewMode = true">👁️ Preview</button>
                                                            <button :class="{ active: !singlePreviewMode }" @click="singlePreviewMode = false">{ } Template</button>
                                                        </div>
                                                        <span v-if="singlePreviewMode" class="variables-resolved">✓ All variables resolved</span>
                                                    </div>

                                                    <!-- PREVIEW MODE -->
                                                    <template v-if="singlePreviewMode">
                                                        <div class="form-group">
                                                            <label class="form-label">Subject:</label>
                                                            <p class="step-field-value">{{ resolveVariables(composeEmail.subject) || '(no subject)' }}</p>
                                                        </div>
                                                        <div class="form-group">
                                                            <label class="form-label">Message:</label>
                                                            <pre class="step-body-preview preview-mode">{{ resolveVariables(composeEmail.body) || '(no message)' }}</pre>
                                                        </div>
                                                        <div class="preview-edit-link">
                                                            <button class="btn btn-link" @click="singlePreviewMode = false">✏️ Edit message</button>
                                                        </div>
                                                    </template>

                                                    <!-- TEMPLATE/EDIT MODE -->
                                                    <template v-else>
                                                        <!-- Template Selector -->
                                                        <div class="form-group" v-if="emailTemplates.length > 0">
                                                            <label class="form-label">Template (optional)</label>
                                                            <select v-model="composeEmail.templateId" @change="applyTemplate" class="field-input">
                                                                <option :value="null">-- No Template --</option>
                                                                <option v-for="t in emailTemplates" :key="t.id" :value="t.id">{{ t.name }}</option>
                                                            </select>
                                                        </div>

                                                        <div class="form-group">
                                                            <label class="form-label">To <span class="required">*</span></label>
                                                            <input type="email" v-model="composeEmail.toEmail" class="field-input" placeholder="recipient@example.com" />
                                                        </div>

                                                        <div class="form-group">
                                                            <label class="form-label">Recipient Name</label>
                                                            <input type="text" v-model="composeEmail.toName" class="field-input" placeholder="John Doe" />
                                                        </div>

                                                        <div class="form-group">
                                                            <label class="form-label">Subject <span class="required">*</span></label>
                                                            <input ref="subjectInputRef" type="text" v-model="composeEmail.subject" class="field-input" placeholder="Subject line..." @focus="handleFieldFocus('subject')" />
                                                        </div>

                                                        <div class="form-group">
                                                            <div class="form-label-row">
                                                                <label class="form-label">Message <span class="required">*</span></label>
                                                                <button type="button" class="ai-toggle-btn" :class="{ active: showAIPanel }" @click="showAIPanel = !showAIPanel">
                                                                    ✨ Refine with AI
                                                                </button>
                                                            </div>
                                                            <textarea ref="bodyInputRef" v-model="composeEmail.body" class="field-input email-body-textarea" rows="10" placeholder="Write your message here..." @focus="handleFieldFocus('body')"></textarea>
                                                        </div>
                                                    </template>

                                                    <!-- Action Buttons Bar -->
                                                    <div class="action-buttons-bar">
                                                        <div class="action-buttons-left">
                                                            <button class="btn btn-outline" @click="handleOpenInEmail" :disabled="!isComposeValid">
                                                                📧 Open in Email
                                                            </button>
                                                            <button class="btn btn-outline" :class="{ 'btn-copied': copiedBody }" @click="handleCopyBody" :disabled="!composeEmail.body">
                                                                {{ copiedBody ? '✓ Copied!' : '📋 Copy Body' }}
                                                            </button>
                                                            <button class="btn btn-outline" @click="handleSaveDraft" :disabled="!composeEmail.subject && !composeEmail.body">
                                                                💾 Save Draft
                                                            </button>
                                                        </div>
                                                        <div class="action-buttons-right">
                                                            <button class="btn btn-outline" @click="closeComposeModal">Cancel</button>
                                                            <button class="btn btn-outline" @click="handleMarkAsSent" :disabled="sendingEmail || !isComposeValid">
                                                                ✓ Mark as Sent
                                                            </button>
                                                            <button class="btn btn-send" @click="handleSendEmail" :disabled="sendingEmail || !isComposeValid">
                                                                <span v-if="sendingEmail">Sending...</span>
                                                                <span v-else>📤 Send Email</span>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </template>

                                                <!-- CAMPAIGN MODE -->
                                                <template v-else>
                                                    <div class="form-group">
                                                        <label class="form-label">Select Sequence <span class="required">*</span></label>
                                                        <select v-model="composeEmail.sequenceId" class="field-input" @change="handleSequenceChange">
                                                            <option :value="null">-- Choose a sequence --</option>
                                                            <option v-for="seq in activeSequences" :key="seq.id" :value="seq.id">
                                                                {{ seq.sequence_name }} ({{ seq.total_steps }} steps)
                                                            </option>
                                                        </select>
                                                    </div>

                                                    <!-- Campaign Steps Preview -->
                                                    <div v-if="selectedSequence && selectedSequence.steps" class="campaign-steps-container">
                                                        <div class="campaign-steps-header">
                                                            <span class="campaign-steps-title">Campaign Steps</span>
                                                            <span class="campaign-steps-hint">Click step to preview</span>
                                                        </div>
                                                        <div class="campaign-steps-list">
                                                            <div v-for="(step, idx) in selectedSequence.steps" :key="step.id || idx" class="campaign-step">
                                                                <button class="campaign-step-header" @click="toggleCampaignStep(idx)" :class="{ expanded: expandedCampaignStep === idx }">
                                                                    <div class="step-badge" :class="{ active: expandedCampaignStep === idx }">{{ idx + 1 }}</div>
                                                                    <div class="step-info">
                                                                        <span class="step-name">{{ step.step_name || step.name || 'Step ' + (idx + 1) }}</span>
                                                                        <span v-if="step.delay_value > 0 || step.delay" class="step-delay">+{{ step.delay_value || step.delay }} {{ step.delay_unit || 'days' }}</span>
                                                                        <span v-if="stepEdits[idx]" class="step-customized">(customized)</span>
                                                                    </div>
                                                                    <span class="step-chevron" :class="{ rotated: expandedCampaignStep === idx }">▼</span>
                                                                </button>

                                                                <!-- Expanded Step Content -->
                                                                <div v-if="expandedCampaignStep === idx" class="campaign-step-content">
                                                                    <div class="step-content-inner">
                                                                        <template v-if="editingCampaignStep === idx">
                                                                            <!-- Edit Mode -->
                                                                            <div v-if="showAIPanel" class="ai-refinement-panel compact">
                                                                                <div class="ai-panel-header">
                                                                                    <span>✨ Refine with AI</span>
                                                                                    <button @click="showAIPanel = false">✕</button>
                                                                                </div>
                                                                                <div class="ai-action-buttons">
                                                                                    <button v-for="action in aiQuickActions.slice(0,4)" :key="action.id" class="ai-quick-btn" @click="handleAIQuickAction(action)">
                                                                                        {{ action.icon }} {{ action.label }}
                                                                                    </button>
                                                                                </div>
                                                                                <textarea v-model="aiPrompt" placeholder="Or describe what you want..." rows="2" class="ai-prompt-input"></textarea>
                                                                                <button class="ai-generate-btn compact" @click="handleAIGenerate" :disabled="aiGenerating">
                                                                                    {{ aiGenerating ? '⏳ Generating...' : '✨ Generate' }}
                                                                                </button>
                                                                            </div>

                                                                            <div class="step-edit-header">
                                                                                <label>Subject:</label>
                                                                                <button class="ai-toggle-btn small" @click="showAIPanel = !showAIPanel">✨ Refine with AI</button>
                                                                            </div>
                                                                            <input type="text" v-model="stepEdits[idx].subject" class="field-input" />

                                                                            <label class="step-edit-label">Message:</label>
                                                                            <textarea v-model="stepEdits[idx].body" rows="8" class="field-input step-body-textarea"></textarea>

                                                                            <div class="step-edit-actions">
                                                                                <button class="btn btn-outline small" @click="cancelEditingStep">Cancel</button>
                                                                                <button class="btn btn-primary small" @click="saveStepEdit(idx)">✓ Save for this recipient only</button>
                                                                                <button class="btn btn-outline small danger" @click="resetStepEdit(idx)">Reset</button>
                                                                            </div>

                                                                            <div class="step-template-actions">
                                                                                <span>Save to template:</span>
                                                                                <button class="btn btn-outline small" @click="openSaveTemplateModal('update', idx)">💾 Update "{{ step.step_name }}"</button>
                                                                                <button class="btn btn-outline small" @click="openSaveTemplateModal('new', idx)">➕ Save as New Template</button>
                                                                            </div>
                                                                        </template>
                                                                        <template v-else>
                                                                            <!-- Preview Mode -->
                                                                            <div class="step-preview-header">
                                                                                <div class="preview-toggle">
                                                                                    <button :class="{ active: stepPreviewMode }" @click="stepPreviewMode = true">👁️ Preview</button>
                                                                                    <button :class="{ active: !stepPreviewMode }" @click="stepPreviewMode = false">{ } Template</button>
                                                                                </div>
                                                                                <span v-if="stepPreviewMode" class="variables-resolved">✓ All variables resolved</span>
                                                                            </div>

                                                                            <div class="step-field">
                                                                                <label>Subject:</label>
                                                                                <p class="step-field-value" :class="{ 'empty-content': !getStepContent(idx).subject }">{{ (stepPreviewMode ? resolveVariables(getStepContent(idx).subject) : getStepContent(idx).subject) || '(no subject - template not configured)' }}</p>
                                                                            </div>
                                                                            <div class="step-field">
                                                                                <label>Message:</label>
                                                                                <pre class="step-body-preview" :class="{ 'preview-mode': stepPreviewMode, 'empty-content': !getStepContent(idx).body }">{{ (stepPreviewMode ? resolveVariables(getStepContent(idx).body) : getStepContent(idx).body) || '(no message - template not configured)' }}</pre>
                                                                            </div>

                                                                            <div class="step-preview-actions">
                                                                                <button class="btn btn-outline small" @click="startEditingStep(idx)">✏️ Customize for this recipient</button>
                                                                                <span class="divider">|</span>
                                                                                <button class="btn btn-link small">Edit template →</button>
                                                                            </div>
                                                                        </template>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div class="form-group">
                                                        <label class="form-label">Recipient Email <span class="required">*</span></label>
                                                        <input type="email" v-model="composeEmail.toEmail" class="field-input" placeholder="recipient@example.com" />
                                                    </div>

                                                    <div class="form-group">
                                                        <label class="form-label">Recipient Name</label>
                                                        <input type="text" v-model="composeEmail.toName" class="field-input" placeholder="John Doe" />
                                                    </div>

                                                    <!-- Campaign Action Buttons -->
                                                    <div class="action-buttons-bar">
                                                        <div class="action-buttons-left">
                                                            <button class="btn btn-outline" @click="handleSaveDraft">
                                                                💾 Save Draft
                                                            </button>
                                                        </div>
                                                        <div class="action-buttons-right">
                                                            <button class="btn btn-outline" @click="closeComposeModal">Cancel</button>
                                                            <button class="btn btn-primary" @click="handleStartCampaign" :disabled="startingCampaign || !isCampaignValid">
                                                                <span v-if="startingCampaign">Starting...</span>
                                                                <span v-else>
                                                                    ▶ Start Campaign
                                                                    <span v-if="Object.keys(stepEdits).length > 0" class="customized-badge">{{ Object.keys(stepEdits).length }} customized</span>
                                                                </span>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>

                                            <!-- Variable Sidebar -->
                                            <div v-if="composeMode === 'single' || expandedCampaignStep !== null" class="variable-sidebar inline">
                                                <div class="sidebar-header">
                                                    <h3 class="sidebar-title">Personalization</h3>
                                                    <p class="sidebar-subtitle">Click to insert variable tag</p>
                                                </div>

                                                <div v-if="composeMode === 'campaign' && editingCampaignStep === null && expandedCampaignStep !== null" class="sidebar-hint info">
                                                    💡 Click "Customize" to edit and insert variables
                                                </div>
                                                <div v-if="composeMode === 'campaign' && editingCampaignStep !== null" class="sidebar-hint success">
                                                    ✏️ Editing Step {{ editingCampaignStep + 1 }} — click to insert
                                                </div>

                                                <div class="search-wrapper">
                                                    <span class="search-icon">🔍</span>
                                                    <input v-model="variablesSearchQuery" type="text" class="search-input" placeholder="Search variables..." />
                                                </div>

                                                <div v-if="variablesLoading" class="sidebar-loading">
                                                    <div class="loading-spinner"></div>
                                                    <span>Loading variables...</span>
                                                </div>

                                                <div v-else-if="filteredVariableCategories.length > 0" class="variables-list">
                                                    <div v-for="category in filteredVariableCategories" :key="category.name" class="variable-category">
                                                        <button class="category-header" @click="toggleVariableCategory(category.name)">
                                                            <span class="category-chevron" :class="{ expanded: expandedCategories.includes(category.name) }">▼</span>
                                                            <span class="category-name">{{ category.name }}</span>
                                                            <span class="category-count">{{ category.variables.length }}</span>
                                                        </button>
                                                        <div v-if="expandedCategories.includes(category.name)" class="category-variables">
                                                            <div v-for="variable in category.variables" :key="variable.tag" class="variable-item" :class="{ 'is-used': isVariableUsed(variable.tag), 'disabled': composeMode === 'campaign' && editingCampaignStep === null }" @click="insertVariable(variable.tag)">
                                                                <div class="variable-info">
                                                                    <span class="variable-label">{{ variable.label }}</span>
                                                                    <code class="variable-tag">{{ variable.tag }}</code>
                                                                    <span v-if="variable.value" class="variable-value">{{ truncateVariableValue(variable.value) }}</span>
                                                                    <span v-else class="variable-empty">(empty)</span>
                                                                </div>
                                                                <button v-if="variable.value" class="copy-btn" @click.stop="copyVariableValue(variable.value, variable.tag)" :title="'Copy: ' + variable.value">
                                                                    {{ copiedVariableTag === variable.tag ? '✓' : '📋' }}
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div v-else class="sidebar-empty">
                                                    <p>No variables available</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Messages List -->
                                    <div v-if="messagesLoading" class="email-loading">
                                        <div class="pit-loading-spinner"></div>
                                        <span>Loading messages...</span>
                                    </div>

                                    <div v-else-if="messages.length === 0" class="notes-empty" style="padding: 40px 20px;">
                                        <svg class="notes-empty-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                                            <polyline points="22,6 12,13 2,6"></polyline>
                                        </svg>
                                        <h3 class="notes-empty-title">No Messages Yet</h3>
                                        <p class="notes-empty-text">Click "Compose Email" to send your first message to this contact.</p>
                                    </div>

                                    <div v-else class="messages-list">
                                        <div v-for="msg in messages" :key="msg.id" class="message-item">
                                            <div class="message-header">
                                                <div class="message-recipient">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                                                        <polyline points="22,6 12,13 2,6"></polyline>
                                                    </svg>
                                                    <span>{{ msg.to_email }}</span>
                                                </div>
                                                <span class="message-time">{{ msg.sent_at_human }}</span>
                                            </div>
                                            <div class="message-subject">{{ msg.subject }}</div>
                                            <div class="message-status-badges">
                                                <span class="message-badge sent">Sent</span>
                                                <span v-if="msg.is_opened" class="message-badge opened">
                                                    Opened {{ msg.open_count > 1 ? '(' + msg.open_count + 'x)' : '' }}
                                                </span>
                                                <span v-if="msg.is_clicked" class="message-badge clicked">Clicked</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Tasks Tab -->
                        <div class="tab-content tasks" :style="{ display: activeTab === 'tasks' ? 'block' : 'none' }">
                            <div class="tab-content-full">
                                <!-- Empty State -->
                                <div v-if="tasks.length === 0" class="frm_no_entries">
                                    <div class="notes-empty">
                                        <svg class="notes-empty-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                            <polyline points="14 2 14 8 20 8"></polyline>
                                            <line x1="16" y1="13" x2="8" y2="13"></line>
                                            <line x1="16" y1="17" x2="8" y2="17"></line>
                                            <polyline points="10 9 9 9 8 9"></polyline>
                                        </svg>
                                        <h3 class="notes-empty-title">No Tasks Yet</h3>
                                        <p class="notes-empty-text">Add and manage tasks like research, outreach, scheduling, and follow-up for this interview here.</p>
                                        <button class="button add-button" @click="showTaskModal = true">
                                            <i class="fas fa-plus" aria-hidden="true" style="margin-right: 6px;"></i> 
                                            Add Task
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Task Table -->
                                <template v-else>
                                    <div class="tasks-header">
                                        <h2 class="section-heading" style="margin-bottom: 0;">Tasks</h2>
                                        <button class="button add-button" @click="showTaskModal = true">
                                            <i class="fas fa-plus" aria-hidden="true" style="margin-right: 6px;"></i>
                                            Add Task
                                        </button>
                                    </div>
                                    
                                    <table class="tasks-table">
                                        <thead>
                                            <tr>
                                                <th style="width: 40px;"></th>
                                                <th>Task Description</th>
                                                <th>Due Date</th>
                                                <th>Priority</th>
                                                <th style="width: 80px;">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="task in tasks" :key="task.id" :class="{ 'task-completed': task.is_done }">
                                                <!-- Status Icon -->
                                                <td>
                                                    <svg v-if="task.is_done" 
                                                         class="status-icon complete" 
                                                         width="18" height="18" 
                                                         viewBox="0 0 24 24" 
                                                         fill="none" 
                                                         stroke="currentColor" 
                                                         stroke-width="2" 
                                                         stroke-linecap="round" 
                                                         stroke-linejoin="round"
                                                         @click="toggleTask(task.id)"
                                                         style="cursor: pointer;">
                                                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                                                    </svg>
                                                    <svg v-else 
                                                         class="status-icon incomplete" 
                                                         width="18" height="18" 
                                                         viewBox="0 0 24 24" 
                                                         fill="none" 
                                                         stroke="currentColor" 
                                                         stroke-width="2" 
                                                         stroke-linecap="round" 
                                                         stroke-linejoin="round"
                                                         @click="toggleTask(task.id)"
                                                         style="cursor: pointer;">
                                                        <circle cx="12" cy="12" r="10"></circle>
                                                    </svg>
                                                </td>
                                                
                                                <!-- Task Description + Type (Expandable) -->
                                                <td>
                                                    <div class="shared-expand simple-expand task-desc">
                                                        <input :id="'togglecontent-' + task.id" type="checkbox" class="toggle-input">
                                                        <div class="task-header">
                                                            <span class="task-title" :class="{ 'task-done': task.is_done }">{{ task.title }}</span>
                                                            <span class="task-type">({{ formatTaskType(task.task_type) }})</span>
                                                            <label v-if="task.description" :for="'togglecontent-' + task.id" class="expand-toggle"></label>
                                                        </div>
                                                        <div class="expandcontent task-details" v-if="task.description">
                                                            {{ task.description }}
                                                        </div>
                                                    </div>
                                                </td>
                                                
                                                <!-- Due Date -->
                                                <td>
                                                    <span :class="{ 'overdue': isOverdue(task.due_date) && !task.is_done }">
                                                        {{ task.due_date ? formatDateShort(task.due_date) : '—' }}
                                                    </span>
                                                </td>
                                                
                                                <!-- Priority Indicator -->
                                                <td>
                                                    <span class="priority-indicator" :class="task.priority || 'medium'"></span>
                                                    {{ capitalize(task.priority || 'medium') }}
                                                </td>
                                                
                                                <!-- Actions -->
                                                <td>
                                                    <button class="task-action" @click="editTask(task)" title="Edit">
                                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                                            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                                        </svg>
                                                    </button>
                                                    <button class="task-action" @click="deleteTask(task.id)" title="Delete">
                                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                            <polyline points="3 6 5 6 21 6"></polyline>
                                                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                                        </svg>
                                                    </button>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </template>
                            </div>
                        </div>

                        <!-- Notes Tab -->
                        <div class="tab-content notes" :style="{ display: activeTab === 'notes' ? 'block' : 'none' }">
                            <div class="tab-content-full">
                                <!-- Empty State -->
                                <div v-if="notes.length === 0" class="frm_no_entries">
                                    <div class="notes-empty">
                                        <svg class="notes-empty-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                            <polyline points="14 2 14 8 20 8"></polyline>
                                            <line x1="16" y1="13" x2="8" y2="13"></line>
                                            <line x1="16" y1="17" x2="8" y2="17"></line>
                                            <polyline points="10 9 9 9 8 9"></polyline>
                                        </svg>
                                        <h3 class="notes-empty-title">No Notes Yet</h3>
                                        <p class="notes-empty-text">Keep track of conversations, research, or any other details related to this interview prospect here.</p>
                                        <button class="button add-button" @click="showNoteModal = true">
                                            <i class="fas fa-plus" aria-hidden="true" style="margin-right: 6px;"></i> 
                                            Add Note
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Notes List -->
                                <div v-else>
                                    <div class="notes-header">
                                        <h2 class="section-heading">Notes</h2>
                                        <button class="button add-button" @click="showNoteModal = true">
                                            <i class="fas fa-plus" aria-hidden="true" style="margin-right: 6px;"></i> 
                                            Add Note
                                        </button>
                                    </div>
                                    
                                    <div v-for="note in notes" :key="note.id" class="note-card">
                                        <div class="note-header">
                                            <h3>{{ note.title || 'Untitled Note' }}</h3>
                                            <div class="note-meta">
                                                <span class="note-date">{{ note.time_ago }}</span>
                                            </div>
                                        </div>
                                        <div class="note-content" v-html="note.content"></div>
                                        <div class="note-tags" v-if="note.note_type">
                                            <span class="tag">{{ note.note_type }}</span>
                                        </div>
                                        <div class="note-actions">
                                            <button class="note-action" @click="toggleNotePin(note.id)">
                                                {{ note.is_pinned ? '⭐ Unpin' : '☆ Pin' }}
                                            </button>
                                            <button class="note-action" @click="deleteNote(note.id)">🗑️ Delete</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- Task Modal -->
                <div id="taskModal" class="custom-modal" :class="{ active: showTaskModal }">
                    <div class="custom-modal-content">
                        <div class="custom-modal-header">
                            <h2 id="modal-title">{{ newTask.id ? 'Edit Task' : 'Add Task' }}</h2>
                            <span class="custom-modal-close" @click="closeTaskModal">&times;</span>
                        </div>
                        <div class="custom-modal-body">
                            <div style="margin-bottom: 16px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 500;">Task Title</label>
                                <input v-model="newTask.title" type="text" class="field-input" placeholder="What needs to be done?">
                            </div>
                            <div style="margin-bottom: 16px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 500;">Description (optional)</label>
                                <textarea v-model="newTask.description" class="field-input" rows="3" placeholder="Add details..." style="resize: vertical;"></textarea>
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                                <div>
                                    <label style="display: block; margin-bottom: 6px; font-weight: 500;">Type</label>
                                    <select v-model="newTask.task_type" class="field-input">
                                        <option value="todo">To-do</option>
                                        <option value="email">Email</option>
                                        <option value="message">Message</option>
                                        <option value="research">Research</option>
                                        <option value="follow_up">Follow Up</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 6px; font-weight: 500;">Priority</label>
                                    <select v-model="newTask.priority" class="field-input">
                                        <option value="low">Low</option>
                                        <option value="medium">Medium</option>
                                        <option value="high">High</option>
                                    </select>
                                </div>
                            </div>
                            <div style="margin-bottom: 16px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 500;">Due Date</label>
                                <input v-model="newTask.due_date" type="date" class="field-input">
                            </div>
                            <div class="custom-modal-actions">
                                <button type="button" class="cancel-button" @click="closeTaskModal">Cancel</button>
                                <button type="button" class="confirm-button" style="background-color: #0ea5e9;" @click="saveTask" :disabled="!newTask.title">{{ newTask.id ? 'Update Task' : 'Add Task' }}</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Note Modal -->
                <div id="noteModal" class="custom-modal" :class="{ active: showNoteModal }">
                    <div class="custom-modal-content">
                        <div class="custom-modal-header">
                            <h2 id="modal-title">Add Note</h2>
                            <span class="custom-modal-close" @click="showNoteModal = false">&times;</span>
                        </div>
                        <div class="custom-modal-body">
                            <div style="margin-bottom: 16px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 500;">Title (optional)</label>
                                <input v-model="newNote.title" type="text" class="field-input" placeholder="Note title">
                            </div>
                            <div style="margin-bottom: 16px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 500;">Content</label>
                                <textarea v-model="newNote.content" class="field-input" rows="4" placeholder="Write your note..." style="resize: vertical;"></textarea>
                            </div>
                            <div style="margin-bottom: 16px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 500;">Type</label>
                                <select v-model="newNote.note_type" class="field-input">
                                    <option value="general">General</option>
                                    <option value="contact">Contact</option>
                                    <option value="research">Research</option>
                                    <option value="meeting">Meeting</option>
                                    <option value="follow_up">Follow Up</option>
                                    <option value="pitch">Pitch</option>
                                    <option value="feedback">Feedback</option>
                                </select>
                            </div>
                            <div class="custom-modal-actions">
                                <button type="button" class="cancel-button" @click="showNoteModal = false">Cancel</button>
                                <button type="button" class="confirm-button" style="background-color: #0ea5e9;" @click="createNote" :disabled="!newNote.content">Add Note</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Date/Event Modal -->
                <div id="dateModal" class="custom-modal" :class="{ active: showDateModal }">
                    <div class="custom-modal-content" style="max-width: 480px;">
                        <div class="custom-modal-header">
                            <h2 id="modal-title">
                                {{ dateModalType === 'record' ? 'Schedule Recording' :
                                   dateModalType === 'air' ? 'Set Air Date' :
                                   'Schedule Promotion' }}
                            </h2>
                            <span class="custom-modal-close" @click="closeDateModal">&times;</span>
                        </div>
                        <div class="custom-modal-body">
                            <p style="margin-bottom: 16px; color: #64748b;">
                                {{ dateModalType === 'record' ? 'When will this interview be recorded?' :
                                   dateModalType === 'air' ? 'When will this episode air?' :
                                   'When will promotion activities begin?' }}
                            </p>

                            <!-- Date Field -->
                            <div style="margin-bottom: 16px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 500;">Date</label>
                                <input v-model="dateModalValue" type="date" class="field-input">
                            </div>

                            <!-- All Day Toggle -->
                            <div style="margin-bottom: 16px;">
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                    <input type="checkbox" v-model="dateModalAllDay" style="width: 16px; height: 16px;">
                                    <span style="font-weight: 500;">All day event</span>
                                </label>
                            </div>

                            <!-- Time Fields (hidden when all-day) -->
                            <div v-if="!dateModalAllDay" style="display: flex; gap: 16px; margin-bottom: 16px;">
                                <div style="flex: 1;">
                                    <label style="display: block; margin-bottom: 6px; font-weight: 500;">Start Time</label>
                                    <input v-model="dateModalStartTime" type="time" class="field-input">
                                </div>
                                <div style="flex: 1;">
                                    <label style="display: block; margin-bottom: 6px; font-weight: 500;">End Time</label>
                                    <input v-model="dateModalEndTime" type="time" class="field-input">
                                </div>
                            </div>

                            <div class="custom-modal-actions">
                                <button type="button" class="cancel-button" @click="closeDateModal">Cancel</button>
                                <button type="button" class="confirm-button" style="background-color: #0ea5e9;" @click="saveDateModal" :disabled="!dateModalValue || dateModalSaving">
                                    {{ dateModalSaving ? 'Saving...' : (dateModalEventId ? 'Update Event' : 'Create Event') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Collaboration Modal -->
                <div id="collabModal" class="custom-modal" :class="{ active: showCollabModal }">
                    <div class="custom-modal-content">
                        <div class="custom-modal-header">
                            <h2 id="modal-title">
                                {{ collabModalType === 'audience' ? 'Edit Audience' : 'Edit Commission' }}
                            </h2>
                            <span class="custom-modal-close" @click="closeCollabModal">&times;</span>
                        </div>
                        <div class="custom-modal-body">
                            <p style="margin-bottom: 12px;">
                                {{ collabModalType === 'audience' ? 'Describe the target audience for this podcast.' : 'Specify any commission or payment terms.' }}
                            </p>
                            <div style="margin-bottom: 16px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 500;">
                                    {{ collabModalType === 'audience' ? 'Audience' : 'Commission' }}
                                </label>
                                <input v-model="collabModalValue" type="text" class="field-input"
                                    :placeholder="collabModalType === 'audience' ? 'e.g., Entrepreneurs, Marketing professionals' : 'e.g., 10% affiliate, $500 flat fee'">
                            </div>
                            <div class="custom-modal-actions">
                                <button type="button" class="cancel-button" @click="closeCollabModal">Cancel</button>
                                <button type="button" class="confirm-button" style="background-color: #0ea5e9;" @click="saveCollabModal">
                                    Save
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Delete Modal -->
                <div id="deleteModal" class="custom-modal delete-modal" :class="{ active: showDeleteModal }">
                    <div class="custom-modal-content small">
                        <div class="custom-modal-header">
                            <h2 id="modal-title">Delete Interview</h2>
                            <span class="custom-modal-close" @click="showDeleteModal = false">&times;</span>
                        </div>
                        <div class="custom-modal-body">
                            <p>Are you sure you want to delete this interview? This action cannot be undone.</p>
                            <div class="custom-modal-actions">
                                <button class="cancel-button" @click="showDeleteModal = false">Cancel</button>
                                <button class="confirm-button" @click="confirmDelete">Delete</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Toast Notification -->
                <transition name="toast">
                    <div v-if="showToast" class="toast-notification" :class="toastType">
                        <svg v-if="toastType === 'success'" class="toast-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                            <polyline points="22 4 12 14.01 9 11.01"></polyline>
                        </svg>
                        <svg v-else class="toast-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="8" x2="12" y2="12"></line>
                            <line x1="12" y1="16" x2="12.01" y2="16"></line>
                        </svg>
                        <span class="toast-message">{{ toastMessage }}</span>
                    </div>
                </transition>
            </div>
        `,
        
        setup() {
            const store = useDetailStore();

            // Modal states
            const showTaskModal = ref(false);
            const showNoteModal = ref(false);
            const showDeleteModal = ref(false);
            const showProfileModal = ref(false);
            const showDateModal = ref(false);
            const dateModalType = ref(''); // 'record', 'air', or 'promotion'
            const dateModalValue = ref('');
            const dateModalStartTime = ref('09:00');
            const dateModalEndTime = ref('10:00');
            const dateModalAllDay = ref(false);
            const dateModalEventId = ref(null); // Existing calendar event ID if editing
            const dateModalSaving = ref(false);

            // Collaboration modal state
            const showCollabModal = ref(false);
            const collabModalType = ref(''); // 'audience' or 'commission'
            const collabModalValue = ref('');

            // Compose email modal state
            const showComposeModal = ref(false);
            const composeMode = ref('single'); // 'single' or 'campaign'
            const composeEmail = reactive({
                templateId: null,
                toEmail: '',
                toName: '',
                subject: '',
                body: '',
                sequenceId: null, // For campaign mode
            });

            // AI Refinement Panel state
            const showAIPanel = ref(false);
            const aiPrompt = ref('');
            const aiTone = ref('professional');
            const aiLength = ref('medium');
            const aiGenerating = ref(false);
            const aiQuickActions = ref([
                { id: 'shorter', label: 'Make it shorter', icon: '📏' },
                { id: 'personal', label: 'Make it more personal', icon: '💬' },
                { id: 'proof', label: 'Add social proof', icon: '⭐' },
                { id: 'cta', label: 'Stronger CTA', icon: '🎯' },
                { id: 'episode', label: 'Reference recent episode', icon: '🎙️' },
                { id: 'urgency', label: 'Add urgency', icon: '⏰' },
            ]);

            // Campaign step editing state
            const expandedCampaignStep = ref(null);
            const editingCampaignStep = ref(null);
            const stepPreviewMode = ref(true);
            const singlePreviewMode = ref(false); // false = edit/template mode, true = preview mode
            const stepEdits = reactive({});

            // Action button states
            const copiedBody = ref(false);

            // Save Template Modal state
            const showSaveTemplateModal = ref(false);
            const saveTemplateType = ref('update'); // 'update' or 'new'
            const saveTemplateStepIdx = ref(null);
            const newTemplateName = ref('');

            // Variable sidebar state
            const variablesData = ref({});
            const variablesLoading = ref(false);
            const variablesSearchQuery = ref('');
            const expandedCategories = ref(['Messaging & Positioning', 'Topics', 'Podcast Information', 'Guest Information']);
            const copiedVariableTag = ref(null);
            const lastFocusedField = ref('body'); // 'subject' or 'body'

            // Refs for input elements (for cursor position insertion)
            const subjectInputRef = ref(null);
            const bodyInputRef = ref(null);

            // Show sidebar only in single email mode
            const showVariableSidebar = computed(() => {
                return composeMode.value === 'single';
            });

            // Filtered variable categories based on search
            const filteredVariableCategories = computed(() => {
                if (!variablesData.value?.categories) return [];
                const query = variablesSearchQuery.value.toLowerCase().trim();
                if (!query) return variablesData.value.categories;
                return variablesData.value.categories
                    .map(cat => ({
                        ...cat,
                        variables: cat.variables.filter(v =>
                            v.label.toLowerCase().includes(query) ||
                            v.tag.toLowerCase().includes(query) ||
                            (v.value && v.value.toLowerCase().includes(query))
                        )
                    }))
                    .filter(cat => cat.variables.length > 0);
            });

            // Check if a variable is used in subject or body
            const isVariableUsed = (tag) => {
                const text = (composeEmail.subject + ' ' + composeEmail.body).toLowerCase();
                return text.includes(tag.toLowerCase());
            };

            // Toggle category expansion
            const toggleVariableCategory = (categoryName) => {
                const idx = expandedCategories.value.indexOf(categoryName);
                if (idx === -1) {
                    expandedCategories.value.push(categoryName);
                } else {
                    expandedCategories.value.splice(idx, 1);
                }
            };

            // Truncate long values
            const truncateVariableValue = (value, max = 25) => {
                if (!value || value.length <= max) return value;
                return value.substring(0, max) + '...';
            };

            // Handle input focus
            const handleFieldFocus = (field) => {
                lastFocusedField.value = field;
            };

            // Insert variable tag at cursor
            const insertVariable = (tag) => {
                const field = lastFocusedField.value;
                const inputEl = field === 'subject' ? subjectInputRef.value : bodyInputRef.value;

                if (!inputEl) {
                    composeEmail.body += tag;
                    return;
                }

                const start = inputEl.selectionStart || 0;
                const end = inputEl.selectionEnd || 0;
                const current = composeEmail[field];
                composeEmail[field] = current.substring(0, start) + tag + current.substring(end);

                // Restore cursor position
                const newPos = start + tag.length;
                setTimeout(() => {
                    inputEl.focus();
                    inputEl.setSelectionRange(newPos, newPos);
                }, 0);
            };

            // Copy variable value to clipboard
            const copyVariableValue = async (value, tag) => {
                try {
                    await navigator.clipboard.writeText(value);
                    copiedVariableTag.value = tag;
                    setTimeout(() => { copiedVariableTag.value = null; }, 2000);
                } catch (err) {
                    console.error('Failed to copy:', err);
                }
            };

            // Fetch personalization variables
            const fetchVariables = async () => {
                // Get interview ID from store or fallback to global config
                const interviewId = store.config?.interviewId ||
                    (typeof guestifyDetailData !== 'undefined' ? guestifyDetailData.interviewId : null);

                if (!interviewId) {
                    console.warn('fetchVariables: No interview ID available');
                    return;
                }

                console.log('fetchVariables: Fetching for interview', interviewId);
                variablesLoading.value = true;
                try {
                    const response = await store.api(`appearances/${interviewId}/variables`);
                    console.log('fetchVariables: Response', response);
                    variablesData.value = response?.data || {};
                } catch (error) {
                    console.error('Failed to fetch variables:', error);
                    variablesData.value = {};
                } finally {
                    variablesLoading.value = false;
                }
            };

            // Computed for compose validation
            const isComposeValid = computed(() => {
                return composeEmail.toEmail && composeEmail.subject && composeEmail.body;
            });

            // Computed for campaign validation
            const isCampaignValid = computed(() => {
                return composeEmail.toEmail && composeEmail.sequenceId;
            });

            // Computed for active sequences (from store)
            // Note: Backend already filters by is_active=1, so all returned sequences are active
            const activeSequences = computed(() => {
                return store.sequences;
            });

            // Computed for selected sequence details
            const selectedSequence = computed(() => {
                if (!composeEmail.sequenceId) return null;
                return store.sequences.find(s => s.id === composeEmail.sequenceId);
            });

            // Episode search with debounce
            const searchInputValue = ref('');
            let searchDebounceTimer = null;

            const handleSearchInput = (event) => {
                const value = event.target.value;
                searchInputValue.value = value;

                // Clear existing timer
                if (searchDebounceTimer) {
                    clearTimeout(searchDebounceTimer);
                }

                // Debounce: wait 300ms after user stops typing
                searchDebounceTimer = setTimeout(() => {
                    store.searchEpisodes(value);
                }, 300);
            };

            const clearSearch = () => {
                searchInputValue.value = '';
                if (searchDebounceTimer) {
                    clearTimeout(searchDebounceTimer);
                }
                store.searchEpisodes('');
            };

            // Writable computed for episodeSearchTerm (avoids exposing entire store)
            const episodeSearchTerm = computed({
                get: () => store.episodeSearchTerm,
                set: (value) => { store.episodeSearchTerm = value; }
            });

            // Toast notification state
            const toastMessage = ref('');
            const toastType = ref('success'); // 'success' or 'error'
            const showToast = ref(false);
            
            // Toast notification methods
            const showSuccessMessage = (message) => {
                toastMessage.value = message;
                toastType.value = 'success';
                showToast.value = true;
                setTimeout(() => {
                    showToast.value = false;
                }, 3000);
            };
            
            const showErrorMessage = (message) => {
                toastMessage.value = message;
                toastType.value = 'error';
                showToast.value = true;
                setTimeout(() => {
                    showToast.value = false;
                }, 3000);
            };

            // Description state
            const isDescriptionExpanded = ref(false);
            const DESCRIPTION_PREVIEW_LENGTH = 320;

            // Profile state
            const selectedProfileId = ref('');
            const profileSaving = ref(false);
            
            // Form data
            const newTask = reactive({
                id: null,
                title: '',
                description: '',
                task_type: 'todo',
                priority: 'medium',
                due_date: ''
            });
            
            const newNote = reactive({
                title: '',
                content: '',
                note_type: 'general'
            });
            
            // Computed milestones from database stages
            const milestones = computed(() => {
                return store.stages.map(stage => ({
                    id: stage.key,
                    label: stage.label
                }));
            });
            
            // Computed
            const initials = computed(() => {
                const name = store.interview?.podcast_name || '';
                return name.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();
            });

            const availableProfiles = computed(() => store.guestProfiles || []);

            const selectedProfile = computed(() => {
                if (!selectedProfileId.value) return null;
                return store.guestProfiles.find(p => p.id === parseInt(selectedProfileId.value));
            });

            const stripHtml = (text) => {
                if (!text) return '';
                return text.replace(/<[^>]*>/g, '').trim();
            };

            const escapeHtml = (text) => {
                if (!text) return '';
                return text
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;');
            };

            const strippedDescription = computed(() => stripHtml(store.interview?.description));

            const truncatedDescription = computed(() => {
                const text = strippedDescription.value;
                if (!text) return '';
                if (text.length <= DESCRIPTION_PREVIEW_LENGTH) return text;
                return `${text.slice(0, DESCRIPTION_PREVIEW_LENGTH)}...`;
            });

            const showDescriptionToggle = computed(() => strippedDescription.value.length > DESCRIPTION_PREVIEW_LENGTH);

            const descriptionContent = computed(() => {
                if (isDescriptionExpanded.value || !showDescriptionToggle.value) {
                    return store.interview?.description || '';
                }

                return escapeHtml(truncatedDescription.value);
            });
            
            // Methods
            const formatDate = (dateStr) => {
                if (!dateStr) return 'N/A';
                try {
                    return new Date(dateStr).toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric'
                    });
                } catch {
                    return dateStr;
                }
            };
            
            const formatFoundedDate = (dateStr) => {
                if (!dateStr) return 'N/A';
                try {
                    const date = new Date(dateStr);
                    return date.toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'short'
                    });
                } catch {
                    return dateStr;
                }
            };
            
            const formatContentRating = (rating) => {
                if (!rating) return 'N/A';
                const ratingMap = {
                    'clean': 'Clean',
                    'explicit': 'Explicit',
                    'yes': 'Explicit',
                    'no': 'Clean',
                    'true': 'Explicit',
                    'false': 'Clean'
                };
                return ratingMap[rating.toLowerCase()] || rating;
            };

            const truncateDescription = (text, maxLength = 200) => {
                if (!text) return '';
                // Strip HTML tags using DOMParser for robust handling
                const plainText = (new DOMParser()).parseFromString(text, 'text/html').body.textContent || '';
                if (plainText.length <= maxLength) return plainText;
                return plainText.substring(0, maxLength).trim() + '...';
            };

            const getInitials = (name) => {
                if (!name) return '?';
                return name.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();
            };
            
            const getProfileInitials = (name) => {
                if (!name) return '?';
                return name.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();
            };

            /**
             * Format profile label for dropdown display.
             * Shows: "Name — Tagline" or "Name (#ID)" as fallback
             */
            const formatProfileDropdownLabel = (profile) => {
                if (!profile) return '';
                const name = profile.name || `Profile #${profile.id}`;

                // Prefer tagline if available
                if (profile.tagline && profile.tagline.trim()) {
                    return `${name} — ${profile.tagline}`;
                }

                // Fall back to showing ID in parentheses
                return `${name} (#${profile.id})`;
            };

            const handleImageError = (e) => {
                e.target.style.display = 'none';
            };

            const toggleDescription = () => {
                isDescriptionExpanded.value = !isDescriptionExpanded.value;
            };
            
            const setTab = (tab) => {
                store.setActiveTab(tab);
            };
            
            const setStatus = async (status) => {
                try {
                    await store.updateInterview('status', status);
                    // Show success feedback
                    showSuccessMessage('Status updated successfully');
                } catch (err) {
                    console.error('Failed to update status:', err);
                    showErrorMessage('Failed to update status');
                }
            };

            const openProfileModal = async () => {
                selectedProfileId.value = store.interview?.guest_profile_id || '';
                showProfileModal.value = true;
                await store.loadGuestProfiles();
            };

            const closeProfileModal = () => {
                showProfileModal.value = false;
            };

            const saveProfileSelection = async () => {
                profileSaving.value = true;
                try {
                    const profileId = selectedProfileId.value ? parseInt(selectedProfileId.value) : 0;
                    await store.updateInterview('guest_profile_id', profileId);

                    const selectedProfile = store.guestProfiles.find(p => p.id === profileId);
                    if (store.interview) {
                        store.interview.guest_profile_id = profileId;
                        store.interview.guest_profile_name = selectedProfile ? selectedProfile.name : '';
                        store.interview.guest_profile_link = selectedProfile ? selectedProfile.permalink : '';
                    }

                    showSuccessMessage('Profile connected successfully');
                    closeProfileModal();
                } catch (err) {
                    console.error('Failed to save profile selection:', err);
                    showErrorMessage('Failed to save profile connection');
                } finally {
                    profileSaving.value = false;
                }
            };
            
            const openDateModal = async (type) => {
                // Set up event modal
                dateModalType.value = type; // 'record', 'air', or 'promotion'
                dateModalEventId.value = null;
                dateModalStartTime.value = '09:00';
                dateModalEndTime.value = '10:00';
                dateModalAllDay.value = false;

                // Map type to event_type for calendar API
                const eventTypeMap = {
                    'record': 'recording',
                    'air': 'air_date',
                    'promotion': 'promotion'
                };
                const eventType = eventTypeMap[type];

                // Pre-populate with existing date if available
                const fieldMap = {
                    'record': store.interview?.record_date,
                    'air': store.interview?.air_date,
                    'promotion': store.interview?.promotion_date
                };

                // Format date for input (YYYY-MM-DD)
                const existingDate = fieldMap[type];
                if (existingDate) {
                    try {
                        const d = new Date(existingDate);
                        dateModalValue.value = d.toISOString().split('T')[0];
                    } catch {
                        dateModalValue.value = '';
                    }
                } else {
                    dateModalValue.value = '';
                }

                // Try to fetch existing calendar event for this appearance/type
                if (store.config.calendarRestUrl && store.config.interviewId) {
                    try {
                        const response = await fetch(
                            `${store.config.calendarRestUrl}calendar-events/by-appearance/${store.config.interviewId}`,
                            {
                                headers: {
                                    'X-WP-Nonce': store.config.nonce,
                                },
                            }
                        );
                        if (response.ok) {
                            const data = await response.json();
                            const events = data.data || [];
                            // Find event matching this type
                            const existingEvent = events.find(e => e.event_type === eventType);
                            if (existingEvent) {
                                dateModalEventId.value = existingEvent.id;
                                dateModalAllDay.value = !!existingEvent.is_all_day;
                                // Parse times from datetime
                                if (existingEvent.start_datetime && !existingEvent.is_all_day) {
                                    const startParts = existingEvent.start_datetime.split(' ');
                                    if (startParts[1]) {
                                        dateModalStartTime.value = startParts[1].substring(0, 5);
                                    }
                                }
                                if (existingEvent.end_datetime && !existingEvent.is_all_day) {
                                    const endParts = existingEvent.end_datetime.split(' ');
                                    if (endParts[1]) {
                                        dateModalEndTime.value = endParts[1].substring(0, 5);
                                    }
                                }
                            }
                        }
                    } catch (err) {
                        console.error('Failed to fetch existing calendar event:', err);
                    }
                }

                showDateModal.value = true;
            };

            const closeDateModal = () => {
                showDateModal.value = false;
                dateModalValue.value = '';
                dateModalType.value = '';
                dateModalStartTime.value = '09:00';
                dateModalEndTime.value = '10:00';
                dateModalAllDay.value = false;
                dateModalEventId.value = null;
                dateModalSaving.value = false;
            };

            const saveDateModal = async () => {
                if (!dateModalValue.value) {
                    showErrorMessage('Please select a date');
                    return;
                }

                dateModalSaving.value = true;

                try {
                    // Map modal type to API fields
                    const fieldMap = {
                        'record': 'record_date',
                        'air': 'air_date',
                        'promotion': 'promotion_date'
                    };
                    const eventTypeMap = {
                        'record': 'recording',
                        'air': 'air_date',
                        'promotion': 'promotion'
                    };
                    const eventLabelMap = {
                        'record': 'Recording',
                        'air': 'Air Date',
                        'promotion': 'Promotion'
                    };

                    const field = fieldMap[dateModalType.value];
                    const eventType = eventTypeMap[dateModalType.value];
                    const eventLabel = eventLabelMap[dateModalType.value];

                    if (!field) {
                        throw new Error('Invalid date type');
                    }

                    // Build datetime strings
                    const startTime = dateModalAllDay.value ? '00:00:00' : (dateModalStartTime.value + ':00');
                    const endTime = dateModalAllDay.value ? '23:59:59' : (dateModalEndTime.value + ':00');
                    const startDatetime = dateModalValue.value + ' ' + startTime;
                    const endDatetime = dateModalValue.value + ' ' + endTime;

                    // Build event title
                    const podcastName = store.interview?.podcast_name || 'Interview';
                    const eventTitle = `${eventLabel}: ${podcastName}`;

                    // Create or update calendar event
                    const eventData = {
                        title: eventTitle,
                        event_type: eventType,
                        start_datetime: startDatetime,
                        end_datetime: endDatetime,
                        is_all_day: dateModalAllDay.value ? 1 : 0,
                        appearance_id: store.config.interviewId,
                        podcast_id: store.interview?.podcast_id || null,
                        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                    };

                    if (dateModalEventId.value) {
                        // Update existing event
                        const response = await fetch(
                            `${store.config.calendarRestUrl}calendar-events/${dateModalEventId.value}`,
                            {
                                method: 'PATCH',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-WP-Nonce': store.config.nonce,
                                },
                                body: JSON.stringify(eventData),
                            }
                        );
                        if (!response.ok) {
                            throw new Error('Failed to update calendar event');
                        }
                    } else {
                        // Create new event
                        const response = await fetch(
                            `${store.config.calendarRestUrl}calendar-events`,
                            {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-WP-Nonce': store.config.nonce,
                                },
                                body: JSON.stringify(eventData),
                            }
                        );
                        if (!response.ok) {
                            throw new Error('Failed to create calendar event');
                        }
                    }

                    // Also update the interview date field
                    await store.updateInterview(field, dateModalValue.value);

                    showSuccessMessage(`${eventLabel} event ${dateModalEventId.value ? 'updated' : 'created'} successfully`);
                    closeDateModal();
                } catch (err) {
                    console.error('Failed to save event:', err);
                    showErrorMessage(err.message || 'Failed to save event');
                } finally {
                    dateModalSaving.value = false;
                }
            };

            // Collaboration modal functions
            const openCollabModal = (type) => {
                collabModalType.value = type; // 'audience' or 'commission'

                // Pre-populate with existing value
                const fieldMap = {
                    'audience': store.interview?.audience,
                    'commission': store.interview?.commission
                };

                collabModalValue.value = fieldMap[type] || '';
                showCollabModal.value = true;
            };

            const closeCollabModal = () => {
                showCollabModal.value = false;
                collabModalValue.value = '';
                collabModalType.value = '';
            };

            const saveCollabModal = async () => {
                try {
                    const field = collabModalType.value; // 'audience' or 'commission'
                    if (!field) {
                        throw new Error('Invalid field type');
                    }

                    await store.updateInterview(field, collabModalValue.value);

                    const label = collabModalType.value === 'audience' ? 'Audience' : 'Commission';
                    showSuccessMessage(`${label} updated successfully`);
                    closeCollabModal();
                } catch (err) {
                    console.error('Failed to save collaboration detail:', err);
                    showErrorMessage(err.message || 'Failed to save');
                }
            };

            const resetTaskForm = () => {
                newTask.id = null;
                newTask.title = '';
                newTask.description = '';
                newTask.task_type = 'todo';
                newTask.priority = 'medium';
                newTask.due_date = '';
            };
            
            const closeTaskModal = () => {
                showTaskModal.value = false;
                resetTaskForm();
            };
            
            const saveTask = async () => {
                if (!newTask.title) return;
                try {
                    if (newTask.id) {
                        // Update existing task
                        await store.updateTask(newTask.id, {
                            title: newTask.title,
                            description: newTask.description,
                            task_type: newTask.task_type,
                            priority: newTask.priority,
                            due_date: newTask.due_date
                        });
                        showSuccessMessage('Task updated successfully');
                    } else {
                        // Create new task
                        await store.createTask({ ...newTask });
                        showSuccessMessage('Task created successfully');
                    }
                    closeTaskModal();
                } catch (err) {
                    console.error('Failed to save task:', err);
                    showErrorMessage('Failed to save task');
                }
            };
            
            const createTask = async () => {
                if (!newTask.title) return;
                await store.createTask({ ...newTask });
                closeTaskModal();
            };
            
            const toggleTask = async (taskId) => {
                try {
                    await store.toggleTask(taskId);
                    showSuccessMessage('Task updated');
                } catch (err) {
                    console.error('Failed to toggle task:', err);
                    showErrorMessage('Failed to update task');
                }
            };
            
            const deleteTask = async (taskId) => {
                if (confirm('Delete this task?')) {
                    try {
                        await store.deleteTask(taskId);
                        showSuccessMessage('Task deleted successfully');
                    } catch (err) {
                        console.error('Failed to delete task:', err);
                        showErrorMessage('Failed to delete task');
                    }
                }
            };
            
            const createNote = async () => {
                if (!newNote.content) return;
                try {
                    await store.createNote({ ...newNote });
                    showSuccessMessage('Note created successfully');
                    showNoteModal.value = false;
                    // Reset form
                    newNote.title = '';
                    newNote.content = '';
                    newNote.note_type = 'general';
                } catch (err) {
                    console.error('Failed to create note:', err);
                    showErrorMessage('Failed to create note');
                }
            };
            
            const toggleNotePin = async (noteId) => {
                try {
                    await store.toggleNotePin(noteId);
                    showSuccessMessage('Note updated');
                } catch (err) {
                    console.error('Failed to toggle note pin:', err);
                    showErrorMessage('Failed to update note');
                }
            };
            
            const deleteNote = async (noteId) => {
                if (confirm('Delete this note?')) {
                    try {
                        await store.deleteNote(noteId);
                        showSuccessMessage('Note deleted successfully');
                    } catch (err) {
                        console.error('Failed to delete note:', err);
                        showErrorMessage('Failed to delete note');
                    }
                }
            };
            
            const handleArchive = async () => {
                if (!confirm('Are you sure you want to archive this interview? You can restore it later.')) {
                    return;
                }
                try {
                    await store.archiveInterview();
                    showSuccessMessage('Interview archived successfully');
                } catch (err) {
                    console.error('Failed to archive interview:', err);
                    showErrorMessage('Failed to archive interview');
                }
            };

            const handleRestore = async () => {
                try {
                    await store.restoreInterview();
                    showSuccessMessage('Interview restored successfully');
                } catch (err) {
                    console.error('Failed to restore interview:', err);
                    showErrorMessage('Failed to restore interview');
                }
            };
            
            const confirmDelete = async () => {
                try {
                    await store.deleteInterview();
                    // The store method redirects to board after deletion
                } catch (err) {
                    console.error('Failed to delete interview:', err);
                    showErrorMessage('Failed to delete interview');
                    showDeleteModal.value = false;
                }
            };

            // =================================================================
            // TAG METHODS (v3.4.0)
            // =================================================================

            const handleRemoveTag = async (tagId) => {
                try {
                    await store.removeTag(tagId);
                    showSuccessMessage('Tag removed');
                } catch (err) {
                    console.error('Failed to remove tag:', err);
                    showErrorMessage('Failed to remove tag');
                }
            };

            const handleSelectTag = async (tag) => {
                try {
                    await store.addTag({ tag_id: tag.id });
                    showSuccessMessage('Tag added');
                } catch (err) {
                    console.error('Failed to add tag:', err);
                    showErrorMessage('Failed to add tag');
                }
            };

            const handleTagInputEnter = async () => {
                const trimmedInput = store.tagInput.trim();
                if (!trimmedInput) return;

                // Check if input matches an existing tag
                const existingTag = store.availableTags.find(
                    t => t.name.toLowerCase() === trimmedInput.toLowerCase()
                );

                if (existingTag) {
                    // Add existing tag
                    await handleSelectTag(existingTag);
                } else {
                    // Create new tag and add it
                    await handleCreateAndAddTag();
                }
            };

            const handleCreateAndAddTag = async () => {
                const trimmedInput = store.tagInput.trim();
                if (!trimmedInput) return;

                try {
                    // Generate a random color from the palette constant
                    const randomColor = TAG_COLORS[Math.floor(Math.random() * TAG_COLORS.length)];

                    await store.addTag({
                        name: trimmedInput,
                        color: randomColor,
                    });
                    showSuccessMessage('Tag created and added');
                } catch (err) {
                    console.error('Failed to create tag:', err);
                    showErrorMessage('Failed to create tag');
                }
            };

            // =================================================================
            // EMAIL COMPOSE METHODS
            // =================================================================

            const openComposeModal = () => {
                // Pre-fill recipient from host email if available
                if (store.interview?.host_email) {
                    composeEmail.toEmail = store.interview.host_email;
                }
                if (store.interview?.host_name) {
                    composeEmail.toName = store.interview.host_name;
                }
                showComposeModal.value = true;
                // Fetch personalization variables
                fetchVariables();
            };

            const closeComposeModal = () => {
                showComposeModal.value = false;
                // Reset form
                composeMode.value = 'single';
                composeEmail.templateId = null;
                composeEmail.toEmail = '';
                composeEmail.toName = '';
                composeEmail.subject = '';
                composeEmail.body = '';
                composeEmail.sequenceId = null;
                // Reset variables state
                variablesData.value = {};
                variablesSearchQuery.value = '';
                lastFocusedField.value = 'body';
            };

            const applyTemplate = () => {
                if (!composeEmail.templateId) return;
                const template = store.emailTemplates.find(t => t.id === composeEmail.templateId);
                if (template) {
                    composeEmail.subject = template.subject || '';
                    // Convert HTML body to plain text for textarea
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = template.body_html || '';
                    composeEmail.body = tempDiv.textContent || tempDiv.innerText || '';
                }
            };

            const handleSendEmail = async () => {
                if (!isComposeValid.value) return;

                try {
                    const result = await store.sendEmail({
                        to_email: composeEmail.toEmail,
                        to_name: composeEmail.toName,
                        subject: composeEmail.subject,
                        body: composeEmail.body,
                        template_id: composeEmail.templateId,
                    });

                    if (result.success) {
                        showSuccessMessage('Email sent successfully!');
                        closeComposeModal();
                    } else {
                        showErrorMessage(result.message || 'Failed to send email');
                    }
                } catch (err) {
                    console.error('Failed to send email:', err);
                    showErrorMessage('Failed to send email: ' + err.message);
                }
            };

            const handleStartCampaign = async () => {
                if (!isCampaignValid.value) return;

                try {
                    // Include step customizations if any
                    const customizations = Object.keys(stepEdits).length > 0 ? stepEdits : null;

                    const result = await store.startSequenceCampaign({
                        sequence_id: composeEmail.sequenceId,
                        recipient_email: composeEmail.toEmail,
                        recipient_name: composeEmail.toName || '',
                        step_customizations: customizations,
                    });

                    if (result.success) {
                        showSuccessMessage('Campaign started successfully!');
                        closeComposeModal();
                    } else {
                        showErrorMessage(result.message || 'Failed to start campaign');
                    }
                } catch (err) {
                    console.error('Failed to start campaign:', err);
                    showErrorMessage('Failed to start campaign: ' + err.message);
                }
            };

            // =================================================================
            // AI REFINEMENT METHODS
            // =================================================================

            const handleAIQuickAction = async (action) => {
                aiPrompt.value = action.label;
                await handleAIGenerate();
            };

            const handleAIGenerate = async () => {
                if (aiGenerating.value) return;
                aiGenerating.value = true;

                try {
                    // Build context for AI
                    const context = {
                        action: aiPrompt.value,
                        tone: aiTone.value,
                        length: aiLength.value,
                        current_subject: composeMode.value === 'single'
                            ? composeEmail.subject
                            : (editingCampaignStep.value !== null && stepEdits[editingCampaignStep.value]?.subject) || '',
                        current_body: composeMode.value === 'single'
                            ? composeEmail.body
                            : (editingCampaignStep.value !== null && stepEdits[editingCampaignStep.value]?.body) || '',
                        podcast_info: {
                            name: store.interview?.podcast_name || '',
                            host: store.interview?.host_name || '',
                        },
                    };

                    // Call AI endpoint if available
                    const response = await store.api('ai/refine-email', {
                        method: 'POST',
                        body: JSON.stringify(context),
                    });

                    if (response?.data?.subject || response?.data?.body) {
                        if (composeMode.value === 'single') {
                            if (response.data.subject) composeEmail.subject = response.data.subject;
                            if (response.data.body) composeEmail.body = response.data.body;
                        } else if (editingCampaignStep.value !== null) {
                            if (response.data.subject) stepEdits[editingCampaignStep.value].subject = response.data.subject;
                            if (response.data.body) stepEdits[editingCampaignStep.value].body = response.data.body;
                        }
                        showSuccessMessage('Email refined with AI!');
                    }
                } catch (err) {
                    console.error('AI generation failed:', err);
                    showErrorMessage('AI generation failed. Please try again.');
                } finally {
                    aiGenerating.value = false;
                    showAIPanel.value = false;
                    aiPrompt.value = '';
                }
            };

            // =================================================================
            // CAMPAIGN STEP METHODS
            // =================================================================

            const toggleCampaignStep = (idx) => {
                if (expandedCampaignStep.value === idx) {
                    expandedCampaignStep.value = null;
                    editingCampaignStep.value = null;
                    showAIPanel.value = false;
                } else {
                    expandedCampaignStep.value = idx;
                }
            };

            const handleSequenceChange = () => {
                // Reset step states when sequence changes
                expandedCampaignStep.value = null;
                editingCampaignStep.value = null;
                Object.keys(stepEdits).forEach(key => delete stepEdits[key]);
                showAIPanel.value = false;
            };

            const startEditingStep = (idx) => {
                const step = selectedSequence.value?.steps?.[idx];
                if (!step) return;

                editingCampaignStep.value = idx;
                stepPreviewMode.value = false;

                // Initialize edit state if not already
                if (!stepEdits[idx]) {
                    stepEdits[idx] = {
                        subject: step.subject || step.email_subject || '',
                        body: step.body || step.email_body || '',
                    };
                }
            };

            const cancelEditingStep = () => {
                editingCampaignStep.value = null;
                stepPreviewMode.value = true;
                showAIPanel.value = false;
            };

            const saveStepEdit = (idx) => {
                // Step edit is already stored in stepEdits reactive object
                editingCampaignStep.value = null;
                stepPreviewMode.value = true;
                showAIPanel.value = false;
                showSuccessMessage('Step customized for this recipient');
            };

            const resetStepEdit = (idx) => {
                delete stepEdits[idx];
                editingCampaignStep.value = null;
                stepPreviewMode.value = true;
                showAIPanel.value = false;
            };

            const getStepContent = (idx) => {
                if (stepEdits[idx]) return stepEdits[idx];
                const step = selectedSequence.value?.steps?.[idx];
                if (!step) {
                    return { subject: '', body: '' };
                }
                // Try multiple possible field names for subject and body
                // Database returns: subject, body_html (from template LEFT JOIN)
                const subject = step.subject || step.email_subject || step.template_subject || '';
                const body = step.body_html || step.body || step.email_body || step.template_body || step.content || '';
                return { subject, body };
            };

            const resolveVariables = (text) => {
                if (!text) return '';
                let resolved = text;

                // Build resolved values from variablesData
                if (variablesData.value?.categories) {
                    variablesData.value.categories.forEach(cat => {
                        cat.variables.forEach(v => {
                            if (v.value) {
                                const regex = new RegExp(v.tag.replace(/[{}]/g, '\\$&'), 'g');
                                resolved = resolved.replace(regex, v.value);
                            }
                        });
                    });
                }
                return resolved;
            };

            const openSaveTemplateModal = (type, idx) => {
                saveTemplateType.value = type;
                saveTemplateStepIdx.value = idx;
                newTemplateName.value = '';
                showSaveTemplateModal.value = true;
            };

            // =================================================================
            // ACTION BUTTON METHODS
            // =================================================================

            const handleOpenInEmail = () => {
                const resolvedSubject = encodeURIComponent(resolveVariables(composeEmail.subject));
                const resolvedBody = encodeURIComponent(resolveVariables(composeEmail.body));
                const mailtoLink = `mailto:${composeEmail.toEmail}?subject=${resolvedSubject}&body=${resolvedBody}`;
                window.open(mailtoLink, '_blank');
            };

            const handleCopyBody = async () => {
                try {
                    const resolvedBody = resolveVariables(composeEmail.body);
                    await navigator.clipboard.writeText(resolvedBody);
                    copiedBody.value = true;
                    setTimeout(() => { copiedBody.value = false; }, 2000);
                } catch (err) {
                    console.error('Failed to copy:', err);
                    showErrorMessage('Failed to copy to clipboard');
                }
            };

            const handleSaveDraft = () => {
                // TODO: Implement draft saving
                showSuccessMessage('Draft saved!');
            };

            const handleMarkAsSent = async () => {
                if (!isComposeValid.value) return;

                try {
                    // Record the email as sent (manual tracking)
                    const result = await store.recordSentEmail({
                        to_email: composeEmail.toEmail,
                        to_name: composeEmail.toName,
                        subject: composeEmail.subject,
                        body: composeEmail.body,
                        template_id: composeEmail.templateId,
                        sent_manually: true,
                    });

                    if (result.success) {
                        showSuccessMessage('Email marked as sent and added to message history.');
                        closeComposeModal();
                    } else {
                        showErrorMessage(result.message || 'Failed to record email');
                    }
                } catch (err) {
                    console.error('Failed to mark as sent:', err);
                    showErrorMessage('Failed to record email: ' + err.message);
                }
            };

            // Check if a specific episode is the linked one
            const isEpisodeLinked = (episode) => {
                // If we just linked an episode in this session, check by title/guid
                if (store.linkedEpisode) {
                    if (episode.guid && store.linkedEpisode.guid === episode.guid) return true;
                    if (store.linkedEpisode.title === episode.title) return true;
                }
                return false;
            };

            // Episode linking handlers
            const handleLinkEpisode = async (episode) => {
                if (!confirm(`Link "${episode.title}" to this interview? This will mark the interview as "aired".`)) {
                    return;
                }
                try {
                    await store.linkEpisode(episode);
                    showSuccessMessage('Episode linked successfully! Interview marked as aired.');
                } catch (err) {
                    console.error('Failed to link episode:', err);
                    showErrorMessage('Failed to link episode: ' + err.message);
                }
            };

            const handleUnlinkEpisode = async () => {
                if (!confirm('Unlink this episode from the interview?')) {
                    return;
                }
                try {
                    await store.unlinkEpisode();
                    showSuccessMessage('Episode unlinked successfully.');
                } catch (err) {
                    console.error('Failed to unlink episode:', err);
                    showErrorMessage('Failed to unlink episode: ' + err.message);
                }
            };

            // Task table helper methods
            const formatTaskType = (type) => {
                if (!type) return 'General';
                const typeMap = {
                    'todo': 'To-do',
                    'email': 'Email',
                    'message': 'Message',
                    'research': 'Research',
                    'call': 'Call',
                    'follow_up': 'Follow-up',
                    'scheduling': 'Scheduling',
                    'outreach': 'Outreach',
                    'general': 'General'
                };
                return typeMap[type.toLowerCase()] || type.charAt(0).toUpperCase() + type.slice(1);
            };
            
            const formatDateShort = (dateStr) => {
                if (!dateStr) return '';
                try {
                    const date = new Date(dateStr);
                    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                } catch {
                    return dateStr;
                }
            };
            
            const isOverdue = (dateStr) => {
                if (!dateStr) return false;
                const dueDate = new Date(dateStr);
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                return dueDate < today;
            };
            
            const capitalize = (str) => {
                if (!str) return '';
                return str.charAt(0).toUpperCase() + str.slice(1).toLowerCase();
            };
            
            const editTask = (task) => {
                // Populate modal with task data for editing
                newTask.id = task.id;
                newTask.title = task.title;
                newTask.description = task.description || '';
                newTask.task_type = task.task_type || 'todo';
                newTask.priority = task.priority || 'medium';
                newTask.due_date = task.due_date || '';
                showTaskModal.value = true;
            };
            
            // Handle Escape key to close modals
            const handleKeydown = (e) => {
                if (e.key === 'Escape') {
                    if (showComposeModal.value) {
                        closeComposeModal();
                    } else if (showTaskModal.value) {
                        showTaskModal.value = false;
                    } else if (showNoteModal.value) {
                        showNoteModal.value = false;
                    }
                }
            };

            // Lifecycle
            onMounted(async () => {
                if (typeof guestifyDetailData !== 'undefined') {
                    store.initConfig(guestifyDetailData);
                }

                // Load persisted tab state (must be after initConfig so interviewId is available)
                store.loadActiveTabFromStorage();

                store.loadInterview();

                // Initialize email integration (non-blocking)
                store.checkEmailIntegration().then(() => {
                    if (store.emailAvailable && store.emailConfigured) {
                        store.loadTemplates();
                        store.loadMessages();
                        store.loadEmailStats();
                        // Load sequences and campaigns if v2.0+ API available
                        if (store.hasCampaigns()) {
                            store.loadSequences();
                            store.loadCampaigns();
                        }
                    }
                });

                // Add keyboard listener for Escape key
                window.addEventListener('keydown', handleKeydown);
            });

            onUnmounted(() => {
                window.removeEventListener('keydown', handleKeydown);
            });
            
            return {
                // Store state
                loading: computed(() => store.loading),
                error: computed(() => store.error),
                saving: computed(() => store.saving),
                interview: computed(() => store.interview),
                tasks: computed(() => store.tasks),
                notes: computed(() => store.notes),
                episodes: computed(() => store.episodes),
                episodesMeta: computed(() => store.episodesMeta),
                episodesLoading: computed(() => store.episodesLoading),
                episodesError: computed(() => store.episodesError),
                activeTab: computed(() => store.activeTab),
                boardUrl: computed(() => store.config.boardUrl),
                profilesLoading: computed(() => store.profilesLoading),
                profilesError: computed(() => store.profilesError),
                stages: computed(() => store.stages),

                // Episode linking state
                // Enrich linkedEpisode with RSS feed data when available
                linkedEpisode: computed(() => {
                    const linked = store.linkedEpisode;
                    if (!linked) return null;

                    // Try to find matching episode from RSS feed for full data
                    const rssEpisode = store.episodes.find(ep => {
                        if (ep.guid && linked.guid && ep.guid === linked.guid) return true;
                        if (ep.title === linked.title) return true;
                        return false;
                    });

                    // Merge RSS data with linked episode data (RSS takes priority for media)
                    if (rssEpisode) {
                        return {
                            ...linked,
                            thumbnail: rssEpisode.thumbnail_url || linked.thumbnail,
                            audio_url: rssEpisode.audio_url || linked.audio_url,
                            description: rssEpisode.description || linked.description,
                            duration: rssEpisode.duration_display || linked.duration,
                        };
                    }

                    return linked;
                }),
                linkingEpisode: computed(() => store.linkingEpisode),
                unlinkingEpisode: computed(() => store.unlinkingEpisode),
                hasLinkedEpisode: computed(() => store.hasLinkedEpisode),
                filteredEpisodes: computed(() => store.filteredEpisodes),

                // Local state
                showTaskModal,
                showNoteModal,
                showDeleteModal,
                showProfileModal,
                showDateModal,
                dateModalType,
                dateModalValue,
                dateModalStartTime,
                dateModalEndTime,
                dateModalAllDay,
                dateModalEventId,
                dateModalSaving,
                showCollabModal,
                collabModalType,
                collabModalValue,
                toastMessage,
                toastType,
                showToast,
                searchInputValue,
                handleSearchInput,
                clearSearch,
                episodeSearchTerm,
                isDescriptionExpanded,
                newTask,
                newNote,
                milestones,
                initials,
                availableProfiles,
                selectedProfile,
                selectedProfileId,
                profileSaving,
                getProfileInitials,
                formatProfileDropdownLabel,
                descriptionContent,
                showDescriptionToggle,

                // Methods
                formatDate,
                formatDateShort,
                formatTaskType,
                formatFoundedDate,
                formatContentRating,
                truncateDescription,
                isOverdue,
                capitalize,
                getInitials,
                handleImageError,
                toggleDescription,
                setTab,
                setStatus,
                openDateModal,
                closeDateModal,
                saveDateModal,
                openCollabModal,
                closeCollabModal,
                saveCollabModal,
                openProfileModal,
                closeProfileModal,
                saveProfileSelection,
                createTask,
                saveTask,
                closeTaskModal,
                toggleTask,
                deleteTask,
                editTask,
                createNote,
                toggleNotePin,
                deleteNote,
                handleArchive,
                handleRestore,
                confirmDelete,
                
                // Episode methods
                loadEpisodes: () => store.loadEpisodes(),
                loadMoreEpisodes: () => store.loadMoreEpisodes(),
                refreshEpisodes: () => store.refreshEpisodes(),

                // Episode linking methods
                handleLinkEpisode,
                handleUnlinkEpisode,
                isEpisodeLinked,

                // Podcast metadata refresh
                refreshPodcastMetadata: () => store.refreshPodcastMetadata(),

                // Email integration state
                emailAvailable: computed(() => store.emailAvailable),
                emailConfigured: computed(() => store.emailConfigured),
                emailFeatures: computed(() => store.emailFeatures),
                emailTemplates: computed(() => store.emailTemplates),
                messages: computed(() => store.messages),
                messagesLoading: computed(() => store.messagesLoading),
                sendingEmail: computed(() => store.sendingEmail),
                emailStats: computed(() => store.emailStats),

                // Campaign/Sequences state
                sequences: computed(() => store.sequences),
                sequencesLoading: computed(() => store.sequencesLoading),
                campaigns: computed(() => store.campaigns),
                campaignsLoading: computed(() => store.campaignsLoading),
                startingCampaign: computed(() => store.startingCampaign),
                activeSequences,
                selectedSequence,

                // Email compose modal state
                showComposeModal,
                composeMode,
                composeEmail,
                isComposeValid,
                isCampaignValid,

                // AI Refinement state
                showAIPanel,
                aiPrompt,
                aiTone,
                aiLength,
                aiGenerating,
                aiQuickActions,

                // Campaign step state
                expandedCampaignStep,
                editingCampaignStep,
                stepPreviewMode,
                singlePreviewMode,
                stepEdits,
                copiedBody,

                // Save Template Modal state
                showSaveTemplateModal,
                saveTemplateType,
                saveTemplateStepIdx,
                newTemplateName,

                // Variable sidebar state
                variablesData,
                variablesLoading,
                variablesSearchQuery,
                expandedCategories,
                copiedVariableTag,
                lastFocusedField,
                subjectInputRef,
                bodyInputRef,
                showVariableSidebar,
                filteredVariableCategories,
                isVariableUsed,
                toggleVariableCategory,
                truncateVariableValue,
                handleFieldFocus,
                insertVariable,
                copyVariableValue,

                // Email methods
                openComposeModal,
                closeComposeModal,
                applyTemplate,
                handleSendEmail,
                handleStartCampaign,

                // AI Refinement methods
                handleAIQuickAction,
                handleAIGenerate,

                // Campaign step methods
                toggleCampaignStep,
                handleSequenceChange,
                startEditingStep,
                cancelEditingStep,
                saveStepEdit,
                resetStepEdit,
                getStepContent,
                resolveVariables,
                openSaveTemplateModal,

                // Action button methods
                handleOpenInEmail,
                handleCopyBody,
                handleSaveDraft,
                handleMarkAsSent,

                // Tags state (v3.4.0)
                tags: computed(() => store.tags),
                availableTags: computed(() => store.availableTags),
                tagsLoading: computed(() => store.tagsLoading),
                tagInput: computed({
                    get: () => store.tagInput,
                    set: (val) => store.tagInput = val,
                }),
                showTagDropdown: computed({
                    get: () => store.showTagDropdown,
                    set: (val) => store.showTagDropdown = val,
                }),
                filteredTags: computed(() => store.getFilteredTags()),
                tagInputMatchesExisting: computed(() => store.tagInputMatchesExisting()),

                // Tag methods
                handleRemoveTag,
                handleSelectTag,
                handleTagInputEnter,
                handleCreateAndAddTag,
            };
        }
    };

    // ==========================================================================
    // INITIALIZATION
    // ==========================================================================
    document.addEventListener('DOMContentLoaded', function() {
        const container = document.getElementById('interview-detail-app');
        if (!container) return;

        const pinia = createPinia();
        const app = createApp(InterviewDetailApp);
        app.use(pinia);
        app.mount('#interview-detail-app');
    });

})();
