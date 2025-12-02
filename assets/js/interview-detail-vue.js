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
            
            // Episodes metadata
            episodesMeta: {
                totalAvailable: 0,
                hasMore: false,
                cached: false,
                cacheExpires: null,
                offset: 0,
            },
            
            // UI state
            activeTab: 'about',
            loading: true,
            saving: false,
            error: null,
            episodesLoading: false,
            episodesError: null,
            
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

            async loadInterview() {
                this.loading = true;
                this.error = null;
                
                try {
                    const response = await this.api(`appearances/${this.config.interviewId}`);
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
                            <div class="profile-name">{{ interview?.guest_name || 'Not Connected' }}</div>
                            <div class="profile-actions">
                                <a v-if="interview?.guest_id" :href="'/app/profiles/guest/profile/?id=' + interview.guest_id" target="_blank" class="button primary-button">
                                    View
                                </a>
                                <button class="button secondary-button">Edit Profile</button>
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
                                                <button class="archive-btn action-btn">
                                                    <svg class="action-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <polyline points="21 8 21 21 3 21 3 8"></polyline>
                                                        <rect x="1" y="3" width="22" height="5"></rect>
                                                        <line x1="10" y1="12" x2="14" y2="12"></line>
                                                    </svg>
                                                    Archive
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
                                            <div class="date-item">
                                                <div class="frm_no_entries">
                                                    <div class="date-item">
                                                        <i class="fas fa-calendar-plus"></i>
                                                        <span class="custom-modal-button" @click="openDateModal('record')">
                                                            {{ interview?.record_date ? formatDate(interview.record_date) : 'Add Record Date' }}
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="date-item">
                                                <div class="frm_no_entries">
                                                    <div class="date-item">
                                                        <i class="fas fa-calendar-check"></i>
                                                        <span class="custom-modal-button" @click="openDateModal('air')">
                                                            {{ interview?.air_date ? formatDate(interview.air_date) : 'Add Air Date' }}
                                                        </span>
                                                    </div>
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

                                            <!-- Duration Badge -->
                                            <div v-if="episode.duration_display" class="episode-duration">
                                                <svg class="duration-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <circle cx="12" cy="12" r="10"></circle>
                                                    <polyline points="12 6 12 12 16 14"></polyline>
                                                </svg>
                                                {{ episode.duration_display }}
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

                <!-- Date Event Modal -->
                <div id="dateModal" class="custom-modal" :class="{ active: showDateModal }">
                    <div class="custom-modal-content">
                        <div class="custom-modal-header">
                            <h2 id="modal-title">{{ newEvent.event_type === 'recording' ? 'Add Record Date' : 'Add Air Date' }}</h2>
                            <span class="custom-modal-close" @click="closeDateModal">&times;</span>
                        </div>
                        <div class="custom-modal-body">
                            <div v-if="eventError" style="background: #fee2e2; border: 1px solid #ef4444; color: #b91c1c; padding: 12px; border-radius: 6px; margin-bottom: 16px;">
                                {{ eventError }}
                            </div>
                            <div style="margin-bottom: 16px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 500;">Event Title</label>
                                <input v-model="newEvent.title" type="text" class="field-input" placeholder="Event title">
                            </div>
                            <div style="margin-bottom: 16px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 500;">Date</label>
                                <input v-model="newEvent.start_datetime" type="date" class="field-input" required>
                            </div>
                            <div style="margin-bottom: 16px;">
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                    <input v-model="newEvent.is_all_day" type="checkbox" style="width: auto;">
                                    <span>All day event</span>
                                </label>
                            </div>
                            <div v-if="!newEvent.is_all_day" style="margin-bottom: 16px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 500;">End Date/Time (optional)</label>
                                <input v-model="newEvent.end_datetime" type="datetime-local" class="field-input">
                            </div>
                            <div style="margin-bottom: 16px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 500;">Description (optional)</label>
                                <textarea v-model="newEvent.description" class="field-input" rows="3" placeholder="Add notes about this event..." style="resize: vertical;"></textarea>
                            </div>
                            <div class="custom-modal-actions">
                                <button type="button" class="cancel-button" @click="closeDateModal">Cancel</button>
                                <button type="button" class="confirm-button" style="background-color: #0ea5e9;" @click="saveEvent" :disabled="eventSaving || !newEvent.start_datetime">
                                    {{ eventSaving ? 'Saving...' : 'Save Event' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `,
        
        setup() {
            const store = useDetailStore();
            
            // Modal states
            const showTaskModal = ref(false);
            const showNoteModal = ref(false);
            const showDeleteModal = ref(false);
            const showDateModal = ref(false);

            // Description state
            const isDescriptionExpanded = ref(false);
            const DESCRIPTION_PREVIEW_LENGTH = 320;
            
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

            const newEvent = reactive({
                event_type: 'recording',
                title: '',
                description: '',
                start_datetime: '',
                end_datetime: '',
                is_all_day: false,
                timezone: 'America/Chicago'
            });

            const eventSaving = ref(false);
            const eventError = ref(null);
            
            // Milestones configuration
            const milestones = [
                { id: 'potential', label: 'Potential' },
                { id: 'active', label: 'Active' },
                { id: 'aired', label: 'Aired' },
            ];
            
            // Computed
            const initials = computed(() => {
                const name = store.interview?.podcast_name || '';
                return name.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();
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
                await store.updateInterview('status', status);
            };
            
            const openDateModal = (type) => {
                // Reset form
                newEvent.event_type = type === 'record' ? 'recording' : 'air_date';
                newEvent.title = type === 'record'
                    ? `Recording: ${store.interview?.podcast_name || 'Interview'}`
                    : `Air Date: ${store.interview?.podcast_name || 'Interview'}`;
                newEvent.description = '';
                newEvent.start_datetime = '';
                newEvent.end_datetime = '';
                newEvent.is_all_day = type === 'air' ? true : false;
                newEvent.timezone = 'America/Chicago';
                eventError.value = null;
                showDateModal.value = true;
            };

            const closeDateModal = () => {
                showDateModal.value = false;
                eventError.value = null;
            };

            const saveEvent = async () => {
                if (!newEvent.start_datetime) {
                    eventError.value = 'Please select a date';
                    return;
                }

                eventSaving.value = true;
                eventError.value = null;

                try {
                    // Build API URL for calendar events (uses pit/v1 namespace)
                    const baseUrl = store.config.restUrl.replace('guestify/v1/', 'pit/v1/');

                    // Format datetime - if is_all_day, add time component
                    let startDatetime = newEvent.start_datetime;
                    if (newEvent.is_all_day && !startDatetime.includes('T') && !startDatetime.includes(' ')) {
                        startDatetime = startDatetime + ' 00:00:00';
                    } else if (!newEvent.is_all_day && !startDatetime.includes('T') && !startDatetime.includes(' ')) {
                        startDatetime = startDatetime + ' 09:00:00';
                    }

                    const eventData = {
                        appearance_id: store.config.interviewId,
                        podcast_id: store.interview?.podcast_id || null,
                        event_type: newEvent.event_type,
                        title: newEvent.title,
                        description: newEvent.description || null,
                        start_datetime: startDatetime,
                        end_datetime: newEvent.end_datetime || null,
                        is_all_day: newEvent.is_all_day ? 1 : 0,
                        timezone: newEvent.timezone
                    };

                    const response = await fetch(`${baseUrl}calendar-events`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': store.config.nonce,
                        },
                        body: JSON.stringify(eventData),
                    });

                    if (!response.ok) {
                        const error = await response.json();
                        throw new Error(error.message || 'Failed to create event');
                    }

                    const result = await response.json();

                    // Update interview record with the date
                    if (newEvent.event_type === 'recording') {
                        await store.updateInterview('record_date', newEvent.start_datetime);
                    } else if (newEvent.event_type === 'air_date') {
                        await store.updateInterview('air_date', newEvent.start_datetime);
                    }

                    closeDateModal();
                } catch (err) {
                    console.error('Failed to save event:', err);
                    eventError.value = err.message || 'Failed to save event';
                } finally {
                    eventSaving.value = false;
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
                if (newTask.id) {
                    // Update existing task
                    await store.updateTask(newTask.id, {
                        title: newTask.title,
                        description: newTask.description,
                        task_type: newTask.task_type,
                        priority: newTask.priority,
                        due_date: newTask.due_date
                    });
                } else {
                    // Create new task
                    await store.createTask({ ...newTask });
                }
                closeTaskModal();
            };
            
            const createTask = async () => {
                if (!newTask.title) return;
                await store.createTask({ ...newTask });
                closeTaskModal();
            };
            
            const toggleTask = async (taskId) => {
                await store.toggleTask(taskId);
            };
            
            const deleteTask = async (taskId) => {
                if (confirm('Delete this task?')) {
                    await store.deleteTask(taskId);
                }
            };
            
            const createNote = async () => {
                if (!newNote.content) return;
                await store.createNote({ ...newNote });
                showNoteModal.value = false;
                // Reset form
                newNote.title = '';
                newNote.content = '';
                newNote.note_type = 'general';
            };
            
            const toggleNotePin = async (noteId) => {
                await store.toggleNotePin(noteId);
            };
            
            const deleteNote = async (noteId) => {
                if (confirm('Delete this note?')) {
                    await store.deleteNote(noteId);
                }
            };
            
            const confirmDelete = async () => {
                // TODO: Implement delete
                showDeleteModal.value = false;
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
                
                // Local state
                showTaskModal,
                showNoteModal,
                showDeleteModal,
                showDateModal,
                isDescriptionExpanded,
                newTask,
                newNote,
                newEvent,
                eventSaving,
                eventError,
                milestones,
                initials,
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
                saveEvent,
                createTask,
                saveTask,
                closeTaskModal,
                toggleTask,
                deleteTask,
                editTask,
                createNote,
                toggleNotePin,
                deleteNote,
                confirmDelete,
                
                // Episode methods
                loadEpisodes: () => store.loadEpisodes(),
                loadMoreEpisodes: () => store.loadMoreEpisodes(),
                refreshEpisodes: () => store.refreshEpisodes(),
                
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
