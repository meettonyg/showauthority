/**
 * Interview Tracker Vue.js Application
 * 
 * Provides Kanban and Table views for managing podcast guest appearances.
 * Pipeline stages loaded from database for customization support.
 * 
 * @package Podcast_Influence_Tracker
 * @since 3.0.0
 * @updated 4.0.0 - Pipeline stages from database
 */

(function() {
    'use strict';

    const { createApp, ref, computed, onMounted, watch } = Vue;
    const { createPinia, defineStore } = Pinia;

    // =====================================================
    // PINIA STORE
    // =====================================================
    const useInterviewStore = defineStore('interviews', {
        state: () => ({
            interviews: [],
            loading: false,
            stagesLoading: false,
            error: null,
            currentView: 'kanban',
            selectedIds: [],
            showBulkPanel: false,
            filters: {
                search: '',
                status: '',
                priority: '',
                source: '',
                guestProfileId: '',
                showArchived: false,
                tags: [], // Selected tag IDs for filtering
            },
            // Tags (v3.4.0)
            availableTags: [],
            appearanceTags: {}, // Map of appearance_id -> tags array
            guestProfiles: [],
            // Pipeline stages loaded from database
            statusColumns: [],
            stagesAreCustom: false,
            // Portfolio state (Phase 5)
            portfolio: [],
            portfolioLoading: false,
            portfolioTotal: 0,
            portfolioPage: 1,
            portfolioPerPage: 20,
            portfolioPodcasts: [],
            portfolioFilters: {
                search: '',
                podcast_id: '',
                date_from: '',
                date_to: '',
            },
        }),

        getters: {
            filteredInterviews: (state) => {
                let result = [...state.interviews];

                if (state.filters.search) {
                    const search = state.filters.search.toLowerCase();
                    result = result.filter(i => 
                        (i.podcast_name || '').toLowerCase().includes(search) ||
                        (i.episode_title || '').toLowerCase().includes(search)
                    );
                }

                if (state.filters.status) {
                    result = result.filter(i => i.status === state.filters.status);
                }

                if (state.filters.priority) {
                    result = result.filter(i => i.priority === state.filters.priority);
                }

                if (state.filters.source) {
                    result = result.filter(i => i.source === state.filters.source);
                }

                if (state.filters.guestProfileId) {
                    const profileId = parseInt(state.filters.guestProfileId);
                    result = result.filter(i => i.guest_profile_id === profileId);
                }

                if (!state.filters.showArchived) {
                    result = result.filter(i => !i.is_archived);
                }

                // Tag filter (v3.4.0)
                if (state.filters.tags && state.filters.tags.length > 0) {
                    result = result.filter(i => {
                        const interviewTags = state.appearanceTags[i.id] || [];
                        const interviewTagIds = interviewTags.map(t => t.id);
                        // Check if any selected filter tag is applied to this interview
                        return state.filters.tags.some(tagId => interviewTagIds.includes(tagId));
                    });
                }

                return result;
            },

            interviewsByStatus: (state) => {
                const grouped = {};
                state.statusColumns.forEach(col => {
                    grouped[col.key] = [];
                });

                const filtered = state.filteredInterviews || [];
                filtered.forEach(interview => {
                    const status = interview.status || 'potential';
                    if (grouped[status]) {
                        grouped[status].push(interview);
                    }
                });

                return grouped;
            },

            // Row 1: Main flow stages (row_group = 1)
            row1Columns: (state) => {
                return state.statusColumns.filter(c => c.row_group === 1);
            },

            // Row 2: Terminal states (row_group = 2)
            row2Columns: (state) => {
                return state.statusColumns.filter(c => c.row_group === 2);
            },

            allSelected: (state) => {
                const filtered = state.filteredInterviews || [];
                return filtered.length > 0 && state.selectedIds.length === filtered.length;
            },

            someSelected: (state) => {
                return state.selectedIds.length > 0;
            },

            uniqueSources: (state) => {
                const sources = new Set();
                state.interviews.forEach(i => {
                    if (i.source) sources.add(i.source);
                });
                return Array.from(sources).sort();
            },
        },

        actions: {
            async fetchPipelineStages() {
                if (this.statusColumns.length > 0) return;
                
                this.stagesLoading = true;
                
                try {
                    const response = await fetch(
                        `${guestifyData.restUrl}pipeline-stages`,
                        {
                            headers: {
                                'X-WP-Nonce': guestifyData.nonce,
                            },
                        }
                    );

                    if (response.ok) {
                        const data = await response.json();
                        this.statusColumns = data.data || [];
                        this.stagesAreCustom = data.is_custom || false;
                    } else {
                        throw new Error('Failed to load stages');
                    }
                } catch (err) {
                    console.error('Failed to fetch pipeline stages:', err);
                    // Fallback to defaults if API fails
                    this.statusColumns = [
                        { key: 'potential', label: 'Potential', color: '#6b7280', row_group: 1 },
                        { key: 'active', label: 'Active', color: '#3b82f6', row_group: 1 },
                        { key: 'aired', label: 'Aired', color: '#10b981', row_group: 1 },
                        { key: 'convert', label: 'Convert', color: '#059669', row_group: 1 },
                        { key: 'on_hold', label: 'On Hold', color: '#f59e0b', row_group: 2 },
                        { key: 'cancelled', label: 'Cancelled', color: '#ef4444', row_group: 2 },
                        { key: 'unqualified', label: 'Unqualified', color: '#9ca3af', row_group: 2 },
                    ];
                } finally {
                    this.stagesLoading = false;
                }
            },

            async fetchInterviews() {
                this.loading = true;
                this.error = null;

                try {
                    const params = new URLSearchParams({
                        per_page: '100',
                    });

                    // Use filterUserId if set (admin viewing other user's data)
                    if (guestifyData.filterUserId && guestifyData.filterUserId !== guestifyData.userId) {
                        params.append('user_id', guestifyData.filterUserId);
                    }

                    if (this.filters.showArchived) {
                        params.append('show_archived', 'true');
                    }

                    const response = await fetch(
                        `${guestifyData.restUrl}appearances?${params}`,
                        {
                            headers: {
                                'X-WP-Nonce': guestifyData.nonce,
                            },
                        }
                    );

                    if (!response.ok) {
                        throw new Error('Failed to fetch interviews');
                    }

                    const data = await response.json();
                    this.interviews = data.data || [];
                } catch (err) {
                    this.error = err.message;
                    console.error('Fetch error:', err);
                } finally {
                    this.loading = false;
                }
            },

            async fetchGuestProfiles() {
                if (this.guestProfiles.length) return;

                try {
                    const response = await fetch(
                        `${guestifyData.restUrl}guest-profiles`,
                        {
                            headers: {
                                'X-WP-Nonce': guestifyData.nonce,
                            },
                        }
                    );

                    if (response.ok) {
                        const data = await response.json();
                        const profiles = data.data || [];
                        this.guestProfiles = profiles.filter(profile => {
                            if (!profile.author_id || !guestifyData.userId) return true;
                            return parseInt(profile.author_id) === parseInt(guestifyData.userId);
                        });
                    }
                } catch (err) {
                    console.error('Failed to fetch guest profiles:', err);
                }
            },

            // Tag methods (v3.4.0)
            async fetchAvailableTags() {
                try {
                    const response = await fetch(
                        `${guestifyData.restUrl}tags`,
                        {
                            headers: {
                                'X-WP-Nonce': guestifyData.nonce,
                            },
                        }
                    );

                    if (response.ok) {
                        const data = await response.json();
                        this.availableTags = data.data || [];
                    }
                } catch (err) {
                    console.error('Failed to fetch available tags:', err);
                }
            },

            async fetchAppearanceTags() {
                // Get all appearance IDs
                const appearanceIds = this.interviews.map(i => i.id);
                if (appearanceIds.length === 0) return;

                try {
                    // Use batch endpoint to fetch all tags in a single request
                    const response = await fetch(
                        `${guestifyData.restUrl}appearances/tags/batch`,
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': guestifyData.nonce,
                            },
                            body: JSON.stringify({ appearance_ids: appearanceIds }),
                        }
                    );

                    if (response.ok) {
                        const result = await response.json();
                        // The API returns { data: { appearance_id: [tags], ... } }
                        this.appearanceTags = result.data || {};
                    }
                } catch (err) {
                    console.error('Failed to fetch appearance tags:', err);
                }
            },

            toggleTagFilter(tagId) {
                const index = this.filters.tags.indexOf(tagId);
                if (index === -1) {
                    this.filters.tags.push(tagId);
                } else {
                    this.filters.tags.splice(index, 1);
                }
            },

            clearTagFilters() {
                this.filters.tags = [];
            },

            getTagsForAppearance(appearanceId) {
                return this.appearanceTags[appearanceId] || [];
            },

            // Portfolio methods (Phase 5)
            async fetchPortfolio(page = 1) {
                this.portfolioLoading = true;

                try {
                    const params = new URLSearchParams({
                        page: page.toString(),
                        per_page: this.portfolioPerPage.toString(),
                    });

                    // Apply filters
                    if (this.portfolioFilters.search) {
                        params.append('search', this.portfolioFilters.search);
                    }
                    if (this.portfolioFilters.podcast_id) {
                        params.append('podcast_id', this.portfolioFilters.podcast_id);
                    }
                    if (this.portfolioFilters.date_from) {
                        params.append('date_from', this.portfolioFilters.date_from);
                    }
                    if (this.portfolioFilters.date_to) {
                        params.append('date_to', this.portfolioFilters.date_to);
                    }

                    const response = await fetch(
                        `${guestifyData.restUrl}portfolio?${params}`,
                        {
                            headers: {
                                'X-WP-Nonce': guestifyData.nonce,
                            },
                        }
                    );

                    if (!response.ok) {
                        throw new Error('Failed to fetch portfolio');
                    }

                    const data = await response.json();
                    this.portfolio = data.data || [];
                    this.portfolioTotal = data.total || 0;
                    this.portfolioPage = data.page || 1;
                    this.portfolioPodcasts = data.podcasts || [];
                } catch (err) {
                    console.error('Failed to fetch portfolio:', err);
                } finally {
                    this.portfolioLoading = false;
                }
            },

            setPortfolioFilter(key, value) {
                this.portfolioFilters[key] = value;
            },

            applyPortfolioFilters() {
                this.fetchPortfolio(1);
            },

            async exportPortfolio() {
                try {
                    const response = await fetch(
                        `${guestifyData.restUrl}portfolio/export`,
                        {
                            headers: {
                                'X-WP-Nonce': guestifyData.nonce,
                            },
                        }
                    );

                    if (!response.ok) {
                        throw new Error('Failed to export portfolio');
                    }

                    const data = await response.json();

                    // Create and download the CSV file
                    const blob = new Blob([data.content], { type: 'text/csv' });
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = data.filename;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    window.URL.revokeObjectURL(url);
                } catch (err) {
                    console.error('Failed to export portfolio:', err);
                    alert('Failed to export portfolio: ' + err.message);
                }
            },

            async updateStatus(id, newStatus) {
                const interview = this.interviews.find(i => i.id === id);
                if (!interview) return;

                const oldStatus = interview.status;
                interview.status = newStatus; // Optimistic update

                try {
                    const response = await fetch(
                        `${guestifyData.restUrl}appearances/${id}`,
                        {
                            method: 'PATCH',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': guestifyData.nonce,
                            },
                            body: JSON.stringify({ status: newStatus }),
                        }
                    );

                    if (!response.ok) {
                        throw new Error('Failed to update status');
                    }
                } catch (err) {
                    interview.status = oldStatus; // Rollback
                    this.error = err.message;
                    console.error('Update error:', err);
                }
            },

            async bulkUpdate(updates) {
                if (this.selectedIds.length === 0) return;

                try {
                    const response = await fetch(
                        `${guestifyData.restUrl}appearances/bulk`,
                        {
                            method: 'PATCH',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': guestifyData.nonce,
                            },
                            body: JSON.stringify({
                                ids: this.selectedIds,
                                updates: updates,
                            }),
                        }
                    );

                    if (!response.ok) {
                        throw new Error('Failed to bulk update');
                    }

                    // Refresh data
                    await this.fetchInterviews();
                    this.selectedIds = [];
                    this.showBulkPanel = false;
                } catch (err) {
                    this.error = err.message;
                    console.error('Bulk update error:', err);
                }
            },

            toggleSelection(id) {
                const index = this.selectedIds.indexOf(id);
                if (index === -1) {
                    this.selectedIds.push(id);
                } else {
                    this.selectedIds.splice(index, 1);
                }
            },

            selectAll() {
                if (this.allSelected) {
                    this.selectedIds = [];
                } else {
                    this.selectedIds = this.filteredInterviews.map(i => i.id);
                }
            },

            clearSelection() {
                this.selectedIds = [];
            },

            setView(view) {
                this.currentView = view;
                // Fetch portfolio data when switching to portfolio view
                if (view === 'portfolio' && this.portfolio.length === 0) {
                    this.fetchPortfolio();
                }
            },

            setFilter(key, value) {
                this.filters[key] = value;
            },
        },
    });

    // =====================================================
    // COMPONENTS
    // =====================================================

    // Filter Bar Component
    const FilterBar = {
        template: `
            <div class="pit-filter-bar" style="display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; align-items: center;">
                <input 
                    type="text" 
                    v-model="searchQuery"
                    placeholder="Search podcasts..."
                    style="padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 4px; min-width: 200px;"
                >
                
                <select v-model="statusFilter" style="padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 4px;">
                    <option value="">All Statuses</option>
                    <option v-for="col in store.statusColumns" :key="col.key" :value="col.key">
                        {{ col.label }}
                    </option>
                </select>
                
                <select v-model="priorityFilter" style="padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 4px;">
                    <option value="">All Priorities</option>
                    <option value="high">High</option>
                    <option value="medium">Medium</option>
                    <option value="low">Low</option>
                </select>
                
                <select v-model="sourceFilter" style="padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 4px;">
                    <option value="">All Sources</option>
                    <option v-for="source in store.uniqueSources" :key="source" :value="source">
                        {{ source }}
                    </option>
                </select>

                <select v-model="guestProfileFilter" style="padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 4px;">
                    <option value="">All Profiles</option>
                    <option v-for="profile in store.guestProfiles" :key="profile.id" :value="profile.id">
                        {{ profile.name }}
                    </option>
                </select>
                
                <!-- Tag Filter (v3.4.0) -->
                <div class="tag-filter-wrapper" style="position: relative;">
                    <button
                        type="button"
                        @click="showTagDropdown = !showTagDropdown"
                        style="padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 4px; background: white; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path>
                            <line x1="7" y1="7" x2="7.01" y2="7"></line>
                        </svg>
                        Tags
                        <span v-if="selectedTagCount > 0" style="background: #3b82f6; color: white; padding: 1px 6px; border-radius: 10px; font-size: 11px;">
                            {{ selectedTagCount }}
                        </span>
                    </button>

                    <div
                        v-if="showTagDropdown"
                        style="position: absolute; top: 100%; left: 0; background: white; border: 1px solid #d1d5db; border-radius: 6px; margin-top: 4px; min-width: 200px; max-height: 300px; overflow-y: auto; z-index: 100; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
                        <div v-if="store.availableTags.length === 0" style="padding: 12px; color: #94a3b8; font-size: 13px;">
                            No tags created yet
                        </div>
                        <div v-else>
                            <div
                                v-for="tag in store.availableTags"
                                :key="tag.id"
                                @click="toggleTag(tag.id)"
                                style="padding: 8px 12px; cursor: pointer; display: flex; align-items: center; gap: 8px;"
                                :style="{ background: isTagSelected(tag.id) ? '#f0f9ff' : 'white' }"
                                @mouseenter="$event.target.style.background = isTagSelected(tag.id) ? '#e0f2fe' : '#f1f5f9'"
                                @mouseleave="$event.target.style.background = isTagSelected(tag.id) ? '#f0f9ff' : 'white'">
                                <input
                                    type="checkbox"
                                    :checked="isTagSelected(tag.id)"
                                    style="pointer-events: none;">
                                <span
                                    style="width: 12px; height: 12px; border-radius: 3px; flex-shrink: 0;"
                                    :style="{ backgroundColor: tag.color }"></span>
                                <span style="font-size: 13px; flex: 1;">{{ tag.name }}</span>
                                <span style="font-size: 11px; color: #94a3b8;">{{ tag.usage_count }}</span>
                            </div>
                            <div v-if="selectedTagCount > 0" style="padding: 8px 12px; border-top: 1px solid #e2e8f0;">
                                <button
                                    type="button"
                                    @click="clearTags"
                                    style="width: 100%; padding: 6px; background: #f1f5f9; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; color: #64748b;">
                                    Clear All Tags
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Backdrop to close dropdown -->
                    <div
                        v-if="showTagDropdown"
                        @click="showTagDropdown = false"
                        style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 99;"></div>
                </div>

                <label style="display: flex; align-items: center; gap: 4px;">
                    <input type="checkbox" v-model="showArchived">
                    Show Archived
                </label>
            </div>
        `,
        setup() {
            const store = useInterviewStore();

            const searchQuery = computed({
                get: () => store.filters.search,
                set: (val) => store.setFilter('search', val),
            });

            const statusFilter = computed({
                get: () => store.filters.status,
                set: (val) => store.setFilter('status', val),
            });

            const priorityFilter = computed({
                get: () => store.filters.priority,
                set: (val) => store.setFilter('priority', val),
            });

            const sourceFilter = computed({
                get: () => store.filters.source,
                set: (val) => store.setFilter('source', val),
            });

            const guestProfileFilter = computed({
                get: () => store.filters.guestProfileId,
                set: (val) => store.setFilter('guestProfileId', val),
            });

            const showArchived = computed({
                get: () => store.filters.showArchived,
                set: (val) => {
                    store.setFilter('showArchived', val);
                    store.fetchInterviews();
                },
            });

            // Tag filter state (v3.4.0)
            const showTagDropdown = ref(false);

            const selectedTagCount = computed(() => store.filters.tags.length);

            const isTagSelected = (tagId) => store.filters.tags.includes(tagId);

            const toggleTag = (tagId) => {
                store.toggleTagFilter(tagId);
            };

            const clearTags = () => {
                store.clearTagFilters();
                showTagDropdown.value = false;
            };

            onMounted(() => {
                store.fetchGuestProfiles();
            });

            return {
                store,
                searchQuery,
                statusFilter,
                priorityFilter,
                sourceFilter,
                guestProfileFilter,
                showArchived,
                // Tag filter (v3.4.0)
                showTagDropdown,
                selectedTagCount,
                isTagSelected,
                toggleTag,
                clearTags,
            };
        },
    };

    // View Toggle Component
    const ViewToggle = {
        template: `
            <div class="pit-view-toggle">
                <button
                    :class="{ active: store.currentView === 'kanban' }"
                    @click="store.setView('kanban')"
                >
                    Kanban
                </button>
                <button
                    :class="{ active: store.currentView === 'table' }"
                    @click="store.setView('table')"
                >
                    Table
                </button>
                <button
                    :class="{ active: store.currentView === 'portfolio' }"
                    @click="store.setView('portfolio')"
                    title="View all episodes where you appeared as a guest"
                >
                    Portfolio
                </button>
            </div>
        `,
        setup() {
            const store = useInterviewStore();
            return { store };
        },
    };

    // Kanban Card Component
    const KanbanCard = {
        props: ['interview'],
        template: `
            <div 
                class="pit-kanban-card"
                :class="['priority-' + interview.priority]"
                draggable="true"
                @dragstart="onDragStart"
                @dragend="onDragEnd"
                @click="openDetail"
                style="background: white; border-radius: 8px; padding: 12px; margin-bottom: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); cursor: pointer;"
            >
                <div style="display: flex; align-items: flex-start; gap: 8px;">
                    <img 
                        v-if="interview.podcast_image" 
                        :src="interview.podcast_image" 
                        style="width: 40px; height: 40px; border-radius: 4px; object-fit: cover;"
                    >
                    <div style="flex: 1; min-width: 0;">
                        <div style="font-weight: 600; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            {{ interview.podcast_name || 'Unknown Podcast' }}
                        </div>
                        <div v-if="interview.episode_title" style="font-size: 12px; color: #6b7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            {{ interview.episode_title }}
                        </div>
                    </div>
                </div>
                <div style="display: flex; justify-content: space-between; margin-top: 8px; font-size: 11px; color: #9ca3af;">
                    <span v-if="interview.source">{{ interview.source }}</span>
                    <span v-if="interview.episode_date">{{ formatDate(interview.episode_date) }}</span>
                </div>
                <!-- Tags (v3.4.0) -->
                <div v-if="interviewTags.length > 0" style="display: flex; flex-wrap: wrap; gap: 4px; margin-top: 8px;">
                    <span
                        v-for="tag in interviewTags.slice(0, 3)"
                        :key="tag.id"
                        :style="{ backgroundColor: tag.color + '20', color: tag.color, borderColor: tag.color }"
                        style="display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 10px; border: 1px solid;">
                        {{ tag.name }}
                    </span>
                    <span v-if="interviewTags.length > 3" style="font-size: 10px; color: #94a3b8; padding: 2px 4px;">
                        +{{ interviewTags.length - 3 }}
                    </span>
                </div>
            </div>
        `,
        setup(props) {
            const store = useInterviewStore();

            const interviewTags = computed(() => store.getTagsForAppearance(props.interview.id));

            const formatDate = (dateStr) => {
                if (!dateStr) return '';
                const date = new Date(dateStr);
                return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            };

            const onDragStart = (e) => {
                e.dataTransfer.setData('text/plain', props.interview.id);
                e.target.classList.add('dragging');
            };

            const onDragEnd = (e) => {
                e.target.classList.remove('dragging');
            };

            const openDetail = (e) => {
                // Don't navigate if we're dragging
                if (e.target.classList.contains('dragging')) return;
                window.location.href = '/app/interview/detail/?id=' + props.interview.id;
            };

            return { formatDate, onDragStart, onDragEnd, openDetail, interviewTags };
        },
    };

    // Kanban Column Component
    const KanbanColumn = {
        props: ['column', 'interviews'],
        components: { KanbanCard },
        template: `
            <div 
                class="pit-kanban-column"
                @dragover.prevent="onDragOver"
                @dragleave="onDragLeave"
                @drop="onDrop"
            >
                <div class="pit-column-header">
                    <span class="pit-column-title">{{ column.label }}</span>
                    <span class="pit-column-count">Total: {{ interviews.length }}</span>
                </div>
                <div class="pit-column-cards">
                    <KanbanCard 
                        v-for="interview in interviews" 
                        :key="interview.id" 
                        :interview="interview"
                    />
                </div>
            </div>
        `,
        setup(props) {
            const store = useInterviewStore();

            const onDragOver = (e) => {
                e.currentTarget.classList.add('drag-over');
            };

            const onDragLeave = (e) => {
                e.currentTarget.classList.remove('drag-over');
            };

            const onDrop = (e) => {
                e.currentTarget.classList.remove('drag-over');
                const id = parseInt(e.dataTransfer.getData('text/plain'));
                if (id) {
                    store.updateStatus(id, props.column.key);
                }
            };

            return { onDragOver, onDragLeave, onDrop };
        },
    };

    // Kanban Board Component
    const KanbanBoard = {
        components: { KanbanColumn },
        template: `
            <div class="pit-kanban-board">
                <div style="display: flex; gap: 12px; margin-bottom: 16px; overflow-x: auto;">
                    <KanbanColumn 
                        v-for="col in store.row1Columns" 
                        :key="col.key" 
                        :column="col"
                        :interviews="store.interviewsByStatus[col.key] || []"
                    />
                </div>
                <div style="display: flex; gap: 12px; overflow-x: auto;">
                    <KanbanColumn 
                        v-for="col in store.row2Columns" 
                        :key="col.key" 
                        :column="col"
                        :interviews="store.interviewsByStatus[col.key] || []"
                    />
                </div>
            </div>
        `,
        setup() {
            const store = useInterviewStore();
            return { store };
        },
    };

    // Table Row Component
    const TableRow = {
        props: ['interview'],
        template: `
            <tr :class="['priority-' + interview.priority]" style="border-bottom: 1px solid #e5e7eb; cursor: pointer;" @click="onRowClick">
                <td style="padding: 12px;" @click.stop>
                    <input 
                        type="checkbox" 
                        :checked="isSelected"
                        @change="toggleSelect"
                    >
                </td>
                <td style="padding: 12px; text-align: center;">
                    <span :style="{ color: '#f59e0b', fontSize: '18px' }" :title="interview.priority || 'No priority'">{{ priorityStar }}</span>
                </td>
                <td style="padding: 12px;">
                    <div style="display: flex; flex-direction: column;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <img 
                                v-if="interview.podcast_image" 
                                :src="interview.podcast_image" 
                                style="width: 32px; height: 32px; border-radius: 4px;"
                            >
                            <span style="font-weight: 500;">{{ interview.podcast_name || 'Unknown' }}</span>
                        </div>
                        <span v-if="interview.episode_title" style="font-size: 12px; color: #6b7280; margin-left: 40px;">{{ interview.episode_title }}</span>
                    </div>
                </td>
                <td style="padding: 12px;">
                    <span 
                        :style="{ background: statusColor, color: 'white', padding: '2px 8px', borderRadius: '4px', fontSize: '12px' }"
                    >
                        {{ statusLabel }}
                    </span>
                </td>
                <td style="padding: 12px;">{{ interview.source || '-' }}</td>
                <td style="padding: 12px;">
                    <!-- Tags (v3.4.0) -->
                    <div v-if="interviewTags.length > 0" style="display: flex; flex-wrap: wrap; gap: 4px;">
                        <span
                            v-for="tag in interviewTags.slice(0, 2)"
                            :key="tag.id"
                            :style="{ backgroundColor: tag.color + '20', color: tag.color, borderColor: tag.color }"
                            style="display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 10px; border: 1px solid;">
                            {{ tag.name }}
                        </span>
                        <span v-if="interviewTags.length > 2" style="font-size: 10px; color: #94a3b8; padding: 2px 4px;">
                            +{{ interviewTags.length - 2 }}
                        </span>
                    </div>
                    <span v-else style="color: #94a3b8;">-</span>
                </td>
                <td style="padding: 12px;">{{ formatDate(interview.updated_at) }}</td>
            </tr>
        `,
        setup(props) {
            const store = useInterviewStore();

            const isSelected = computed(() => store.selectedIds.includes(props.interview.id));
            const interviewTags = computed(() => store.getTagsForAppearance(props.interview.id));

            const toggleSelect = () => {
                store.toggleSelection(props.interview.id);
            };

            const priorityStar = computed(() => {
                const p = (props.interview.priority || '').toLowerCase();
                if (p === 'high') return '★';      // Full star
                if (p === 'medium') return '⯪';   // Half star
                return '☆';                        // Empty star (low or none)
            });

            const statusColor = computed(() => {
                const col = store.statusColumns.find(c => c.key === props.interview.status);
                return col ? col.color : '#6b7280';
            });

            const statusLabel = computed(() => {
                const col = store.statusColumns.find(c => c.key === props.interview.status);
                return col ? col.label : props.interview.status;
            });

            const formatDate = (dateStr) => {
                if (!dateStr) return '-';
                const date = new Date(dateStr);
                return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            };

            const onRowClick = () => {
                window.location.href = '/app/interview/detail/?id=' + props.interview.id;
            };

            return { store, isSelected, toggleSelect, priorityStar, statusColor, statusLabel, formatDate, onRowClick, interviewTags };
        },
    };

    // Table View Component
    const TableView = {
        components: { TableRow },
        template: `
            <div class="pit-table-view" style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden;">
                    <thead style="background: #f9fafb;">
                        <tr>
                            <th style="padding: 12px; text-align: left; width: 40px;">
                                <input 
                                    type="checkbox" 
                                    :checked="store.allSelected"
                                    @change="store.selectAll"
                                >
                            </th>
                            <th style="padding: 12px; text-align: center; width: 60px;">Priority</th>
                            <th style="padding: 12px; text-align: left;">Podcast Name</th>
                            <th style="padding: 12px; text-align: left;">Status</th>
                            <th style="padding: 12px; text-align: left;">Source</th>
                            <th style="padding: 12px; text-align: left;">Tags</th>
                            <th style="padding: 12px; text-align: left;">Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <TableRow 
                            v-for="interview in store.filteredInterviews" 
                            :key="interview.id"
                            :interview="interview"
                        />
                    </tbody>
                </table>
            </div>
        `,
        setup() {
            const store = useInterviewStore();
            return { store };
        },
    };

    // Portfolio View Component (Phase 5)
    const PortfolioView = {
        template: `
            <div class="pit-portfolio-view">
                <!-- Portfolio Toolbar -->
                <div class="pit-portfolio-toolbar" style="display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; align-items: center; background: white; padding: 16px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <input
                        type="text"
                        v-model="searchQuery"
                        placeholder="Search episodes..."
                        @keyup.enter="store.applyPortfolioFilters()"
                        style="padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 8px; min-width: 200px; flex: 1; max-width: 300px;"
                    >

                    <select v-model="podcastFilter" style="padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 8px;">
                        <option value="">All Podcasts</option>
                        <option v-for="podcast in store.portfolioPodcasts" :key="podcast.id" :value="podcast.id">
                            {{ podcast.title }}
                        </option>
                    </select>

                    <input
                        type="date"
                        v-model="dateFrom"
                        style="padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 8px;"
                        title="From date"
                    >
                    <span style="color: #64748b;">to</span>
                    <input
                        type="date"
                        v-model="dateTo"
                        style="padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 8px;"
                        title="To date"
                    >

                    <button
                        @click="store.applyPortfolioFilters()"
                        style="padding: 10px 20px; background: #3b9edd; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500;"
                    >
                        Filter
                    </button>

                    <button
                        @click="store.exportPortfolio()"
                        style="padding: 10px 20px; background: #10b981; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 500; margin-left: auto;"
                        title="Export to CSV"
                    >
                        Export CSV
                    </button>
                </div>

                <!-- Stats Summary -->
                <div style="margin-bottom: 20px; color: #64748b; font-size: 14px;">
                    {{ store.portfolioTotal }} episode{{ store.portfolioTotal !== 1 ? 's' : '' }} in your portfolio
                </div>

                <!-- Loading State -->
                <div v-if="store.portfolioLoading" class="pit-loading">
                    <p>Loading your portfolio...</p>
                </div>

                <!-- Empty State -->
                <div v-else-if="store.portfolio.length === 0" style="text-align: center; padding: 60px 40px; background: white; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <svg style="width: 64px; height: 64px; color: #cbd5e1; margin-bottom: 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                    </svg>
                    <h3 style="margin: 0 0 8px; color: #1f2937; font-size: 18px;">No episodes in your portfolio yet</h3>
                    <p style="margin: 0; color: #6b7280;">Link episodes to your interviews to build your speaking portfolio.</p>
                </div>

                <!-- Portfolio Cards Grid -->
                <div v-else class="pit-portfolio-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px;">
                    <div
                        v-for="item in store.portfolio"
                        :key="item.id"
                        class="pit-portfolio-card"
                        style="background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); transition: box-shadow 0.2s, transform 0.2s;"
                        @mouseenter="$event.target.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)'"
                        @mouseleave="$event.target.style.boxShadow = '0 1px 3px rgba(0,0,0,0.1)'"
                    >
                        <!-- Card Header with Podcast Image -->
                        <div style="display: flex; align-items: center; gap: 12px; padding: 16px; border-bottom: 1px solid #f1f5f9;">
                            <img
                                :src="item.podcast_image || 'https://via.placeholder.com/60?text=🎙️'"
                                :alt="item.podcast_name"
                                style="width: 60px; height: 60px; border-radius: 8px; object-fit: cover;"
                            >
                            <div style="flex: 1; min-width: 0;">
                                <div style="font-weight: 600; color: #1f2937; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                    {{ item.podcast_name || 'Unknown Podcast' }}
                                </div>
                                <div style="font-size: 12px; color: #64748b; margin-top: 2px;">
                                    {{ formatDate(item.engagement_date) }}
                                    <span v-if="item.episode_number" style="margin-left: 8px;">• Ep {{ item.episode_number }}</span>
                                </div>
                            </div>
                            <span
                                v-if="item.is_verified"
                                style="background: #dcfce7; color: #16a34a; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 500;"
                                title="Verified appearance"
                            >
                                ✓ Verified
                            </span>
                        </div>

                        <!-- Card Body -->
                        <div style="padding: 16px;">
                            <h4 style="margin: 0 0 8px; font-size: 15px; color: #1f2937; line-height: 1.4;">
                                {{ item.episode_title || 'Untitled Episode' }}
                            </h4>

                            <div style="display: flex; gap: 16px; font-size: 13px; color: #64748b; margin-bottom: 12px;">
                                <span v-if="item.duration_display" style="display: flex; align-items: center; gap: 4px;">
                                    <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10"/>
                                        <polyline points="12 6 12 12 16 14"/>
                                    </svg>
                                    {{ item.duration_display }}
                                </span>
                                <span style="display: flex; align-items: center; gap: 4px; text-transform: capitalize;">
                                    <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
                                        <circle cx="12" cy="7" r="4"/>
                                    </svg>
                                    {{ item.role }}
                                </span>
                            </div>

                            <!-- Action Buttons -->
                            <div style="display: flex; gap: 8px; margin-top: 12px;">
                                <a
                                    v-if="item.episode_url"
                                    :href="item.episode_url"
                                    target="_blank"
                                    style="flex: 1; padding: 8px 12px; background: #f1f5f9; color: #475569; border-radius: 6px; text-align: center; text-decoration: none; font-size: 13px; font-weight: 500; transition: background 0.2s;"
                                    @mouseenter="$event.target.style.background = '#e2e8f0'"
                                    @mouseleave="$event.target.style.background = '#f1f5f9'"
                                >
                                    Listen
                                </a>
                                <a
                                    v-if="item.opportunity_id"
                                    :href="'/app/interview/detail/?id=' + item.opportunity_id"
                                    style="flex: 1; padding: 8px 12px; background: #3b9edd; color: white; border-radius: 6px; text-align: center; text-decoration: none; font-size: 13px; font-weight: 500; transition: background 0.2s;"
                                    @mouseenter="$event.target.style.background = '#2b8ecd'"
                                    @mouseleave="$event.target.style.background = '#3b9edd'"
                                >
                                    View Details
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pagination -->
                <div v-if="store.portfolioTotal > store.portfolioPerPage" style="display: flex; justify-content: center; gap: 8px; margin-top: 24px;">
                    <button
                        v-for="page in Math.ceil(store.portfolioTotal / store.portfolioPerPage)"
                        :key="page"
                        @click="store.fetchPortfolio(page)"
                        :style="{
                            padding: '8px 14px',
                            border: 'none',
                            borderRadius: '6px',
                            cursor: 'pointer',
                            fontWeight: '500',
                            background: page === store.portfolioPage ? '#3b9edd' : '#f1f5f9',
                            color: page === store.portfolioPage ? 'white' : '#475569',
                        }"
                    >
                        {{ page }}
                    </button>
                </div>
            </div>
        `,
        setup() {
            const store = useInterviewStore();

            const searchQuery = computed({
                get: () => store.portfolioFilters.search,
                set: (val) => store.setPortfolioFilter('search', val),
            });

            const podcastFilter = computed({
                get: () => store.portfolioFilters.podcast_id,
                set: (val) => store.setPortfolioFilter('podcast_id', val),
            });

            const dateFrom = computed({
                get: () => store.portfolioFilters.date_from,
                set: (val) => store.setPortfolioFilter('date_from', val),
            });

            const dateTo = computed({
                get: () => store.portfolioFilters.date_to,
                set: (val) => store.setPortfolioFilter('date_to', val),
            });

            const formatDate = (dateStr) => {
                if (!dateStr) return 'No date';
                const date = new Date(dateStr);
                return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
            };

            return { store, searchQuery, podcastFilter, dateFrom, dateTo, formatDate };
        },
    };

    // Selection Bar Component
    const SelectionBar = {
        template: `
            <div v-if="store.someSelected" class="pit-selection-bar">
                <span>{{ store.selectedIds.length }} selected</span>
                <button @click="store.showBulkPanel = true">Edit</button>
                <button class="cancel" @click="store.clearSelection">Cancel</button>
            </div>
        `,
        setup() {
            const store = useInterviewStore();
            return { store };
        },
    };

    // Bulk Edit Panel Component
    const BulkEditPanel = {
        template: `
            <div v-if="store.showBulkPanel" class="pit-bulk-panel">
                <h3>Edit {{ store.selectedIds.length }} Items</h3>
                
                <div class="form-group">
                    <label>Interview Profile</label>
                    <select v-model="bulkGuestProfileId">
                        <option value="">Don't change</option>
                        <option v-for="profile in guestProfiles" :key="profile.id" :value="profile.id">
                            {{ profile.name }}
                        </option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Status</label>
                    <select v-model="bulkStatus">
                        <option value="">Don't change</option>
                        <option v-for="col in store.statusColumns" :key="col.key" :value="col.key">
                            {{ col.label }}
                        </option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Priority</label>
                    <select v-model="bulkPriority">
                        <option value="">Don't change</option>
                        <option value="high">High</option>
                        <option value="medium">Medium</option>
                        <option value="low">Low</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Source</label>
                    <select v-model="bulkSource">
                        <option value="">Don't change</option>
                        <option v-for="source in sourceOptions" :key="source" :value="source">
                            {{ source }}
                        </option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="checkbox-label" style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" v-model="bulkArchive" @change="archiveChanged = true">
                        Archive
                    </label>
                </div>
                
                <div class="actions">
                    <button class="btn-secondary" @click="cancelBulkEdit">Cancel</button>
                    <button class="btn-primary" @click="applyBulkEdit">Apply Changes</button>
                </div>
            </div>
        `,
        setup() {
            const store = useInterviewStore();
            const bulkGuestProfileId = ref('');
            const bulkStatus = ref('');
            const bulkPriority = ref('');
            const bulkSource = ref('');
            const bulkArchive = ref(false);
            const archiveChanged = ref(false);

            const sourceOptions = [
                'Direct Outreach',
                'Referral / Introduction',
                'Podcast Agency / Network',
                'Event Connection',
                'Online Platform',
                'Internal Database',
                'Media / Press Opportunity',
                'Joint Venture / Strategic Partnership',
                'Inbound Request',
                'Personal Network',
                'Other',
            ];

            // Fetch profiles when panel opens
            watch(() => store.showBulkPanel, (isOpen) => {
                if (isOpen && store.guestProfiles.length === 0) {
                    store.fetchGuestProfiles();
                }
            });

            const applyBulkEdit = () => {
                const updates = {};
                if (bulkGuestProfileId.value) updates.guest_profile_id = parseInt(bulkGuestProfileId.value);
                if (bulkStatus.value) updates.status = bulkStatus.value;
                if (bulkPriority.value) updates.priority = bulkPriority.value;
                if (bulkSource.value) updates.source = bulkSource.value;
                if (archiveChanged.value) updates.is_archived = bulkArchive.value ? 1 : 0;

                if (Object.keys(updates).length > 0) {
                    store.bulkUpdate(updates);
                }

                resetForm();
            };

            const cancelBulkEdit = () => {
                store.showBulkPanel = false;
                resetForm();
            };

            const resetForm = () => {
                bulkGuestProfileId.value = '';
                bulkStatus.value = '';
                bulkPriority.value = '';
                bulkSource.value = '';
                bulkArchive.value = false;
                archiveChanged.value = false;
            };

            return { 
                store, 
                bulkGuestProfileId,
                bulkStatus, 
                bulkPriority, 
                bulkSource,
                bulkArchive,
                archiveChanged,
                guestProfiles: computed(() => store.guestProfiles),
                sourceOptions,
                applyBulkEdit,
                cancelBulkEdit,
            };
        },
    };

    // =====================================================
    // MAIN APP
    // =====================================================
    const App = {
        components: {
            FilterBar,
            ViewToggle,
            KanbanBoard,
            TableView,
            PortfolioView,
            SelectionBar,
            BulkEditPanel,
        },
        template: `
            <div class="pit-interview-tracker">
                <div v-if="store.error" class="pit-error">
                    {{ store.error }}
                    <button @click="store.error = null" style="margin-left: 8px;">×</button>
                </div>
                
                <!-- Search Toolbar (Prospector-style) -->
                <div class="pit-toolbar">
                    <div class="pit-search-wrapper">
                        <svg class="pit-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>
                        </svg>
                        <input
                            type="text"
                            v-model="searchQuery"
                            placeholder="Enter name to find podcasts..."
                            class="pit-search-input"
                        >
                    </div>

                    <button
                        class="pit-filter-btn"
                        :class="{ 'is-active': showFilters }"
                        @click="showFilters = !showFilters"
                    >
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="4" y1="6" x2="20" y2="6"></line>
                            <line x1="8" y1="12" x2="16" y2="12"></line>
                            <line x1="10" y1="18" x2="14" y2="18"></line>
                        </svg>
                        Filters
                    </button>

                    <ViewToggle />
                </div>

                <!-- Filter Panel (expandable) -->
                <div v-if="showFilters" class="pit-filter-panel">
                    <div class="pit-filter-grid">
                        <div class="pit-filter-field">
                            <label class="pit-filter-label">Status</label>
                            <select v-model="statusFilter" class="pit-select">
                                <option value="">All Statuses</option>
                                <option v-for="col in store.statusColumns" :key="col.key" :value="col.key">
                                    {{ col.label }}
                                </option>
                            </select>
                        </div>
                        <div class="pit-filter-field">
                            <label class="pit-filter-label">Priority</label>
                            <select v-model="priorityFilter" class="pit-select">
                                <option value="">All Priorities</option>
                                <option value="high">High</option>
                                <option value="medium">Medium</option>
                                <option value="low">Low</option>
                            </select>
                        </div>
                        <div class="pit-filter-field">
                            <label class="pit-filter-label">Source</label>
                            <select v-model="sourceFilter" class="pit-select">
                                <option value="">All Sources</option>
                                <option v-for="source in store.uniqueSources" :key="source" :value="source">
                                    {{ source }}
                                </option>
                            </select>
                        </div>
                        <div class="pit-filter-field">
                            <label class="pit-filter-label">Profile</label>
                            <select v-model="guestProfileFilter" class="pit-select">
                                <option value="">All Profiles</option>
                                <option v-for="profile in store.guestProfiles" :key="profile.id" :value="profile.id">
                                    {{ profile.name }}
                                </option>
                            </select>
                        </div>
                    </div>
                    <div class="pit-filter-footer">
                        <label class="pit-checkbox-label">
                            <input type="checkbox" v-model="showArchived">
                            <span>Show Archived</span>
                        </label>
                        <button class="pit-reset-filters" @click="resetFilters">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg>
                            Reset Filters
                        </button>
                    </div>
                </div>
                
                <div v-if="store.loading || store.stagesLoading" class="pit-loading">
                    <p>Loading...</p>
                </div>
                
                <template v-else>
                    <KanbanBoard v-if="store.currentView === 'kanban'" />
                    <TableView v-else-if="store.currentView === 'table'" />
                    <PortfolioView v-else-if="store.currentView === 'portfolio'" />
                </template>
                
                <SelectionBar />
                <BulkEditPanel />
            </div>
        `,
        setup() {
            const store = useInterviewStore();
            const showFilters = ref(false);

            const searchQuery = computed({
                get: () => store.filters.search,
                set: (val) => store.setFilter('search', val),
            });
            const statusFilter = computed({
                get: () => store.filters.status,
                set: (val) => store.setFilter('status', val),
            });
            const priorityFilter = computed({
                get: () => store.filters.priority,
                set: (val) => store.setFilter('priority', val),
            });
            const sourceFilter = computed({
                get: () => store.filters.source,
                set: (val) => store.setFilter('source', val),
            });
            const guestProfileFilter = computed({
                get: () => store.filters.guestProfileId,
                set: (val) => store.setFilter('guestProfileId', val),
            });
            const showArchived = computed({
                get: () => store.filters.showArchived,
                set: (val) => {
                    store.setFilter('showArchived', val);
                    store.fetchInterviews();
                },
            });

            const resetFilters = () => {
                store.setFilter('search', '');
                store.setFilter('status', '');
                store.setFilter('priority', '');
                store.setFilter('source', '');
                store.setFilter('guestProfileId', '');
                store.setFilter('showArchived', false);
                store.fetchInterviews();
            };

            onMounted(async () => {
                // Load pipeline stages first (required for columns)
                await store.fetchPipelineStages();

                // Then load other data
                store.fetchGuestProfiles();
                store.fetchAvailableTags(); // Load available tags (v3.4.0)

                // Check localStorage for saved view preference
                const savedView = localStorage.getItem('pit_interview_view');
                if (savedView && (savedView === 'kanban' || savedView === 'table' || savedView === 'portfolio')) {
                    store.setView(savedView);
                } else {
                    // Fallback to data attribute
                    const appEl = document.getElementById('interview-tracker-app');
                    if (appEl && appEl.dataset.initialView) {
                        store.setView(appEl.dataset.initialView);
                    }
                }

                // Fetch interviews
                await store.fetchInterviews();

                // Load tags for appearances (v3.4.0)
                store.fetchAppearanceTags();
            });

            // Watch for view changes and save to localStorage
            watch(() => store.currentView, (newView) => {
                localStorage.setItem('pit_interview_view', newView);
            });

            return { store, searchQuery, statusFilter, priorityFilter, sourceFilter, guestProfileFilter, showArchived, showFilters, resetFilters };
        },
    };

    // =====================================================
    // INITIALIZE APP
    // =====================================================
    document.addEventListener('DOMContentLoaded', function() {
        const appEl = document.getElementById('interview-tracker-app');
        if (!appEl) return;

        const app = createApp(App);
        const pinia = createPinia();
        app.use(pinia);
        app.mount('#interview-tracker-app');
    });

})();
