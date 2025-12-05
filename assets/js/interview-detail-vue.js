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

    const { createApp, ref, reactive, computed, onMounted, watch } = Vue;
    const { createPinia, defineStore } = Pinia;

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
            
            // Config from WordPress
            config: {
                restUrl: '',
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
        },

        actions: {
            initConfig(data) {
                this.config = { ...this.config, ...data };
            },

            async api(endpoint, options = {}) {
                const url = this.config.restUrl + endpoint;
                const response = await fetch(url, {
                    ...options,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': this.config.nonce,
                        ...options.headers,
                    },
                });
                
                if (!response.ok) {
                    const error = await response.json();
                    throw new Error(error.message || 'API request failed');
                }
                
                return response.json();
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
                    
                    await Promise.all([
                        this.loadTasks(),
                        this.loadNotes(),
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
             * Load episodes from RSS feed via podcast-influence API
             * Uses the podcast_id from the interview/appearance record
             */
            async loadEpisodes(refresh = false) {
                // Need podcast_id from interview
                if (!this.interview?.podcast_id) {
                    this.episodesError = 'No podcast linked to this interview';
                    return;
                }

                this.episodesLoading = true;
                this.episodesError = null;

                try {
                    // Build API URL - note: uses podcast-influence namespace
                    const baseUrl = this.config.restUrl.replace('guestify/v1/', 'podcast-influence/v1/');
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
                await this.loadEpisodes(true);
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
                        date: episode.date,
                        date_iso: episode.date_iso,
                        url: episode.episode_url || episode.audio_url,
                        duration: episode.duration_display,
                        thumbnail: episode.thumbnail_url,
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
                                            {{ profile.name }}
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

                                    <!-- Collaboration Section -->
                                    <div class="panel">
                                        <div class="panel-header">
                                            <h3 class="panel-title">Collaboration Details</h3>
                                        </div>
                                        <div class="panel-content">
                                            <ul class="collab-list">
                                                <li class="collab-item">
                                                    <svg class="collab-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                                        <circle cx="9" cy="7" r="4"></circle>
                                                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                                                    </svg>
                                                    <div class="collab-content">
                                                        <div class="collab-label">Audience</div>
                                                        <div class="collab-value">{{ interview?.audience || 'Not specified' }}</div>
                                                    </div>
                                                </li>
                                                <li class="collab-item">
                                                    <svg class="collab-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <line x1="12" y1="1" x2="12" y2="23"></line>
                                                        <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                                                    </svg>
                                                    <div class="collab-content">
                                                        <div class="collab-label">Commission</div>
                                                        <div class="collab-value">{{ interview?.commission || 'Not specified' }}</div>
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

                                    <!-- Empty State -->
                                    <div v-else-if="episodes.length === 0" class="notes-empty">
                                        <svg class="notes-empty-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                            <circle cx="12" cy="12" r="10"></circle>
                                            <polygon points="10 8 16 12 10 16 10 8"></polygon>
                                        </svg>
                                        <h3 class="notes-empty-title">No Episodes Found</h3>
                                        <p class="notes-empty-text">Episodes from the RSS feed will appear here. Click Refresh to load them.</p>
                                        <button class="button outline-button" @click="refreshEpisodes">Load Episodes</button>
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

                                                <!-- Already Linked Indicator -->
                                                <span
                                                    v-else-if="linkedEpisode?.title === episode.title || interview?.engagement_id"
                                                    class="linked-badge"
                                                    title="This episode is linked to this interview">
                                                    <svg class="button-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                                                    </svg>
                                                    Linked
                                                </span>
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
                                        <div class="episodes-meta">
                                            <span>Showing {{ episodes.length }} of {{ episodesMeta.totalAvailable }} episodes</span>
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
                                <div class="notes-empty">
                                    <svg class="notes-empty-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                                        <polyline points="22,6 12,13 2,6"></polyline>
                                    </svg>
                                    <h3 class="notes-empty-title">Pitch Templates Coming Soon</h3>
                                    <p class="notes-empty-text">Email templates and pitch generation will be available in a future update.</p>
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

                <!-- Date Modal -->
                <div id="dateModal" class="custom-modal" :class="{ active: showDateModal }">
                    <div class="custom-modal-content">
                        <div class="custom-modal-header">
                            <h2 id="modal-title">
                                {{ dateModalType === 'record' ? 'Set Record Date' : 
                                   dateModalType === 'air' ? 'Set Air Date' : 
                                   'Set Promotion Date(s)' }}
                            </h2>
                            <span class="custom-modal-close" @click="closeDateModal">&times;</span>
                        </div>
                        <div class="custom-modal-body">
                            <p style="margin-bottom: 12px;">
                                {{ dateModalType === 'record' ? 'When will this interview be recorded?' : 
                                   dateModalType === 'air' ? 'When will this episode air?' :
                                   'When will this episode be promoted?' }}
                            </p>
                            <div style="margin-bottom: 16px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 500;">Date</label>
                                <input v-model="dateModalValue" type="date" class="field-input">
                            </div>
                            <div class="custom-modal-actions">
                                <button type="button" class="cancel-button" @click="closeDateModal">Cancel</button>
                                <button type="button" class="confirm-button" style="background-color: #0ea5e9;" @click="saveDateModal" :disabled="!dateModalValue">
                                    {{ dateModalValue && (dateModalType === 'record' ? interview?.record_date : dateModalType === 'air' ? interview?.air_date : interview?.promotion_date) ? 'Update Date' : 'Set Date' }}
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
            const dateModalType = ref(''); // 'record' or 'air'
            const dateModalValue = ref('');
            
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
            
            const getInitials = (name) => {
                if (!name) return '?';
                return name.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();
            };
            
            const getProfileInitials = (name) => {
                if (!name) return '?';
                return name.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();
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
            
            const openDateModal = (type) => {
                // Set up simple date modal (not event modal)
                dateModalType.value = type; // 'record', 'air', or 'promotion'
                
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
                
                showDateModal.value = true;
            };

            const closeDateModal = () => {
                showDateModal.value = false;
                dateModalValue.value = '';
                dateModalType.value = '';
            };

            const saveDateModal = async () => {
                if (!dateModalValue.value) {
                    showErrorMessage('Please select a date');
                    return;
                }

                try {
                    // Determine which field to update based on modal type
                    const fieldMap = {
                        'record': 'record_date',
                        'air': 'air_date',
                        'promotion': 'promotion_date'
                    };

                    const field = fieldMap[dateModalType.value];
                    if (!field) {
                        throw new Error('Invalid date type');
                    }

                    // Update the interview field directly
                    await store.updateInterview(field, dateModalValue.value);

                    showSuccessMessage(`${dateModalType.value === 'record' ? 'Record' : dateModalType.value === 'air' ? 'Air' : 'Promotion'} date updated successfully`);
                    closeDateModal();
                } catch (err) {
                    console.error('Failed to save date:', err);
                    showErrorMessage(err.message || 'Failed to save date');
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
            
            // Lifecycle
            onMounted(() => {
                if (typeof guestifyDetailData !== 'undefined') {
                    store.initConfig(guestifyDetailData);
                }
                store.loadInterview();
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
                linkedEpisode: computed(() => store.linkedEpisode),
                linkingEpisode: computed(() => store.linkingEpisode),
                unlinkingEpisode: computed(() => store.unlinkingEpisode),
                hasLinkedEpisode: computed(() => store.hasLinkedEpisode),

                // Local state
                showTaskModal,
                showNoteModal,
                showDeleteModal,
                showProfileModal,
                showDateModal,
                dateModalType,
                dateModalValue,
                toastMessage,
                toastType,
                showToast,
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
                descriptionContent,
                showDescriptionToggle,

                // Methods
                formatDate,
                formatDateShort,
                formatTaskType,
                formatFoundedDate,
                formatContentRating,
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

                // Podcast metadata refresh
                refreshPodcastMetadata: () => store.refreshPodcastMetadata(),
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
