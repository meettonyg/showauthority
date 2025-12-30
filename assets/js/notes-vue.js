/**
 * Notes Dashboard Vue.js Application
 *
 * Displays all notes across appearances with filtering and sorting.
 *
 * @package Podcast_Influence_Tracker
 * @since 3.5.0
 */

(function() {
    'use strict';

    const { createApp, ref, computed, onMounted, watch } = Vue;
    const { createPinia, defineStore } = Pinia;

    // =====================================================
    // API CLIENT
    // =====================================================
    const api = GuestifyApi.createClient({
        restUrl: pitNotesData.restUrl,
        nonce: pitNotesData.nonce,
    });

    // =====================================================
    // NOTE TYPE CONFIGURATION
    // =====================================================
    const noteTypes = {
        general:   { label: 'General',   icon: 'file-text',      color: '#6b7280' },
        contact:   { label: 'Contact',   icon: 'user',           color: '#3b82f6' },
        research:  { label: 'Research',  icon: 'search',         color: '#8b5cf6' },
        meeting:   { label: 'Meeting',   icon: 'calendar',       color: '#10b981' },
        follow_up: { label: 'Follow Up', icon: 'clock',          color: '#f59e0b' },
        pitch:     { label: 'Pitch',     icon: 'send',           color: '#ec4899' },
        feedback:  { label: 'Feedback',  icon: 'message-circle', color: '#14b8a6' },
    };

    // =====================================================
    // PINIA STORE
    // =====================================================
    const useNotesStore = defineStore('notes', {
        state: () => ({
            notes: [],
            stats: null,
            loading: false,
            statsLoading: false,
            error: null,
            currentView: pitNotesData.defaultView || 'list',
            filters: {
                search: '',
                note_type: pitNotesData.defaultNoteType || '',
                is_pinned: null,
            },
            sort: {
                orderby: 'created_at',
                order: 'DESC',
            },
            pagination: {
                page: 1,
                per_page: pitNotesData.defaultLimit || 50,
                total: 0,
                total_pages: 0,
            },
        }),

        getters: {
            filteredNotes: (state) => {
                // Client-side search filter for instant feedback
                let result = [...state.notes];

                if (state.filters.search) {
                    const search = state.filters.search.toLowerCase();
                    result = result.filter(n =>
                        (n.title || '').toLowerCase().includes(search) ||
                        (n.content || '').toLowerCase().includes(search) ||
                        (n.podcast_name || '').toLowerCase().includes(search)
                    );
                }

                return result;
            },

            hasFilters: (state) => {
                return state.filters.note_type ||
                       state.filters.is_pinned !== null ||
                       state.filters.search;
            },

            noteTypesList: () => {
                return Object.entries(noteTypes).map(([key, value]) => ({
                    key,
                    ...value,
                }));
            },
        },

        actions: {
            async fetchNotes() {
                this.loading = true;
                this.error = null;

                try {
                    const params = new URLSearchParams({
                        page: this.pagination.page.toString(),
                        per_page: this.pagination.per_page.toString(),
                        orderby: this.sort.orderby,
                        order: this.sort.order,
                    });

                    if (this.filters.note_type) {
                        params.append('note_type', this.filters.note_type);
                    }
                    if (this.filters.is_pinned !== null) {
                        params.append('is_pinned', this.filters.is_pinned ? '1' : '0');
                    }
                    if (this.filters.search) {
                        params.append('search', this.filters.search);
                    }

                    const result = await api.get(`notes?${params}`);

                    this.notes = result.data || [];
                    this.pagination.total = result.meta?.total || 0;
                    this.pagination.total_pages = result.meta?.total_pages || 0;
                } catch (err) {
                    console.error('Failed to fetch notes:', err);
                    this.error = 'Failed to load notes. Please try again.';
                } finally {
                    this.loading = false;
                }
            },

            async fetchStats() {
                this.statsLoading = true;

                try {
                    const result = await api.get('notes/stats');
                    this.stats = result.data || null;
                } catch (err) {
                    console.error('Failed to fetch note stats:', err);
                } finally {
                    this.statsLoading = false;
                }
            },

            async togglePin(note) {
                const previousPinned = note.is_pinned;
                note.is_pinned = !note.is_pinned;

                try {
                    await api.post(`appearances/${note.appearance_id}/notes/${note.id}/pin`);
                    // Refresh stats after toggle
                    this.fetchStats();
                } catch (err) {
                    console.error('Failed to toggle pin:', err);
                    // Revert on failure
                    note.is_pinned = previousPinned;
                }
            },

            setFilter(key, value) {
                this.filters[key] = value;
                this.pagination.page = 1;
                this.fetchNotes();
            },

            setNoteTypeFilter(type) {
                this.filters.note_type = this.filters.note_type === type ? '' : type;
                this.pagination.page = 1;
                this.fetchNotes();
            },

            setSort(orderby) {
                if (this.sort.orderby === orderby) {
                    this.sort.order = this.sort.order === 'ASC' ? 'DESC' : 'ASC';
                } else {
                    this.sort.orderby = orderby;
                    this.sort.order = 'DESC';
                }
                this.fetchNotes();
            },

            setPage(page) {
                this.pagination.page = page;
                this.fetchNotes();
            },

            setView(view) {
                this.currentView = view;
            },

            clearFilters() {
                this.filters = {
                    search: '',
                    note_type: '',
                    is_pinned: null,
                };
                this.pagination.page = 1;
                this.fetchNotes();
            },
        },
    });

    // =====================================================
    // MAIN APP COMPONENT
    // =====================================================
    const NotesApp = {
        setup() {
            const store = useNotesStore();
            const searchDebounce = ref(null);

            onMounted(() => {
                store.fetchNotes();
                store.fetchStats();
            });

            const handleSearch = (e) => {
                const value = e.target.value;
                store.filters.search = value;

                // Debounce API call
                if (searchDebounce.value) {
                    clearTimeout(searchDebounce.value);
                }
                searchDebounce.value = setTimeout(() => {
                    store.pagination.page = 1;
                    store.fetchNotes();
                }, 300);
            };

            const getInterviewUrl = (appearanceId) => {
                return `${pitNotesData.interviewDetailUrl}?id=${appearanceId}`;
            };

            const getNoteTypeConfig = (type) => {
                return noteTypes[type] || noteTypes.general;
            };

            const getTypeIcon = (type) => {
                const icons = {
                    'file-text': '<path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/>',
                    'user': '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
                    'search': '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
                    'calendar': '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
                    'clock': '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
                    'send': '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
                    'message-circle': '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
                };
                return icons[type] || icons['file-text'];
            };

            return {
                store,
                noteTypes,
                handleSearch,
                getInterviewUrl,
                getNoteTypeConfig,
                getTypeIcon,
            };
        },

        template: `
            <div class="pit-notes-dashboard">
                <!-- Header -->
                <div class="pit-notes-header">
                    <h1>Notes</h1>
                </div>

                <!-- Stats by Type -->
                <div class="pit-notes-stats" v-if="store.stats">
                    <div
                        class="pit-note-stat"
                        :class="{ active: store.filters.note_type === '' }"
                        @click="store.setNoteTypeFilter('')"
                    >
                        <div class="pit-note-stat-value">{{ store.stats.total || 0 }}</div>
                        <div class="pit-note-stat-label">All Notes</div>
                    </div>
                    <div
                        class="pit-note-stat"
                        :class="{ active: store.filters.is_pinned === true }"
                        @click="store.filters.is_pinned = store.filters.is_pinned === true ? null : true; store.fetchNotes()"
                    >
                        <div class="pit-note-stat-value">{{ store.stats.pinned || 0 }}</div>
                        <div class="pit-note-stat-label">Pinned</div>
                    </div>
                    <div
                        v-for="(config, type) in noteTypes"
                        :key="type"
                        class="pit-note-stat"
                        :class="{ active: store.filters.note_type === type }"
                        @click="store.setNoteTypeFilter(type)"
                    >
                        <div
                            class="pit-note-stat-icon"
                            :style="{ backgroundColor: config.color + '20' }"
                        >
                            <svg
                                width="18"
                                height="18"
                                viewBox="0 0 24 24"
                                fill="none"
                                :stroke="config.color"
                                stroke-width="2"
                                v-html="getTypeIcon(config.icon)"
                            />
                        </div>
                        <div class="pit-note-stat-value">{{ store.stats.by_type?.[type] || 0 }}</div>
                        <div class="pit-note-stat-label">{{ config.label }}</div>
                    </div>
                </div>

                <!-- Toolbar -->
                <div class="pit-notes-toolbar">
                    <!-- Search -->
                    <div class="pit-search-wrapper">
                        <svg class="pit-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"/>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                        </svg>
                        <input
                            type="text"
                            class="pit-search-input"
                            placeholder="Search notes..."
                            :value="store.filters.search"
                            @input="handleSearch"
                        />
                    </div>

                    <!-- Note Type Filter -->
                    <select class="pit-select" v-model="store.filters.note_type" @change="store.setFilter('note_type', $event.target.value)">
                        <option value="">All Types</option>
                        <option v-for="(config, type) in noteTypes" :key="type" :value="type">
                            {{ config.label }}
                        </option>
                    </select>

                    <!-- Sort -->
                    <select class="pit-select" @change="store.setSort($event.target.value)">
                        <option value="created_at">Newest First</option>
                        <option value="note_date">Note Date</option>
                        <option value="title">Title</option>
                    </select>

                    <!-- View Toggle -->
                    <div class="pit-view-toggle">
                        <button
                            :class="{ active: store.currentView === 'list' }"
                            @click="store.setView('list')"
                            title="List View"
                        >
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="8" y1="6" x2="21" y2="6"/>
                                <line x1="8" y1="12" x2="21" y2="12"/>
                                <line x1="8" y1="18" x2="21" y2="18"/>
                                <line x1="3" y1="6" x2="3.01" y2="6"/>
                                <line x1="3" y1="12" x2="3.01" y2="12"/>
                                <line x1="3" y1="18" x2="3.01" y2="18"/>
                            </svg>
                        </button>
                        <button
                            :class="{ active: store.currentView === 'grid' }"
                            @click="store.setView('grid')"
                            title="Grid View"
                        >
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="3" width="7" height="7"/>
                                <rect x="14" y="3" width="7" height="7"/>
                                <rect x="14" y="14" width="7" height="7"/>
                                <rect x="3" y="14" width="7" height="7"/>
                            </svg>
                        </button>
                    </div>

                    <!-- Clear Filters -->
                    <button v-if="store.hasFilters" class="pit-btn-link" @click="store.clearFilters()">
                        Clear Filters
                    </button>
                </div>

                <!-- Loading -->
                <div v-if="store.loading" class="pit-loading">
                    <div class="pit-loading-spinner"></div>
                    <p>Loading notes...</p>
                </div>

                <!-- Error -->
                <div v-else-if="store.error" class="pit-error">
                    <p>{{ store.error }}</p>
                    <button @click="store.fetchNotes()">Try Again</button>
                </div>

                <!-- List View -->
                <div v-else-if="store.currentView === 'list' && store.filteredNotes.length > 0" class="pit-notes-list">
                    <div
                        v-for="note in store.filteredNotes"
                        :key="note.id"
                        class="pit-note-item"
                        :class="{ pinned: note.is_pinned }"
                    >
                        <!-- Type Icon -->
                        <div
                            class="pit-note-type-icon"
                            :class="'type-' + note.note_type"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" v-html="getTypeIcon(getNoteTypeConfig(note.note_type).icon)"/>
                        </div>

                        <!-- Content -->
                        <div class="pit-note-content">
                            <div class="pit-note-header">
                                <div class="pit-note-title">
                                    {{ note.title || 'Untitled Note' }}
                                </div>
                                <svg v-if="note.is_pinned" class="pit-note-pin" width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M12 2L9.19 8.63 2 9.24l5.46 4.73L5.82 21 12 17.27 18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2z"/>
                                </svg>
                            </div>
                            <div class="pit-note-preview">{{ note.content_preview }}</div>
                            <div class="pit-note-meta">
                                <!-- Podcast -->
                                <div class="pit-note-podcast" v-if="note.podcast_name">
                                    <img v-if="note.podcast_artwork" :src="note.podcast_artwork" :alt="note.podcast_name" />
                                    <a :href="getInterviewUrl(note.appearance_id)">{{ note.podcast_name }}</a>
                                </div>

                                <!-- Type Badge -->
                                <span class="pit-note-type-badge" :class="'badge-' + note.note_type">
                                    {{ getNoteTypeConfig(note.note_type).label }}
                                </span>

                                <!-- Time -->
                                <span>{{ note.time_ago }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Pagination -->
                    <div v-if="store.pagination.total_pages > 1" class="pit-pagination">
                        <button
                            @click="store.setPage(store.pagination.page - 1)"
                            :disabled="store.pagination.page <= 1"
                        >
                            Previous
                        </button>
                        <span class="pit-pagination-info">
                            Page {{ store.pagination.page }} of {{ store.pagination.total_pages }}
                        </span>
                        <button
                            @click="store.setPage(store.pagination.page + 1)"
                            :disabled="store.pagination.page >= store.pagination.total_pages"
                        >
                            Next
                        </button>
                    </div>
                </div>

                <!-- Grid View -->
                <div v-else-if="store.currentView === 'grid' && store.filteredNotes.length > 0" class="pit-notes-grid">
                    <div
                        v-for="note in store.filteredNotes"
                        :key="note.id"
                        class="pit-note-card"
                        :class="{ pinned: note.is_pinned }"
                    >
                        <div class="pit-note-card-header">
                            <div
                                class="pit-note-type-icon"
                                :class="'type-' + note.note_type"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" v-html="getTypeIcon(getNoteTypeConfig(note.note_type).icon)"/>
                            </div>
                            <div style="flex: 1">
                                <div class="pit-note-title">{{ note.title || 'Untitled Note' }}</div>
                                <span class="pit-note-type-badge" :class="'badge-' + note.note_type">
                                    {{ getNoteTypeConfig(note.note_type).label }}
                                </span>
                            </div>
                            <svg v-if="note.is_pinned" class="pit-note-pin" width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2L9.19 8.63 2 9.24l5.46 4.73L5.82 21 12 17.27 18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2z"/>
                            </svg>
                        </div>
                        <div class="pit-note-card-content">{{ note.content_preview }}</div>
                        <div class="pit-note-meta">
                            <div class="pit-note-podcast" v-if="note.podcast_name">
                                <img v-if="note.podcast_artwork" :src="note.podcast_artwork" :alt="note.podcast_name" />
                                <a :href="getInterviewUrl(note.appearance_id)">{{ note.podcast_name }}</a>
                            </div>
                            <span>{{ note.time_ago }}</span>
                        </div>
                    </div>
                </div>

                <!-- Empty State -->
                <div v-else class="pit-empty-state">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/>
                        <polyline points="14 2 14 8 20 8"/>
                    </svg>
                    <p v-if="store.hasFilters">No notes match your filters.</p>
                    <p v-else>No notes yet. Notes will appear here when you add them to your appearances.</p>
                </div>
            </div>
        `,
    };

    // =====================================================
    // MOUNT APPLICATION
    // =====================================================
    document.addEventListener('DOMContentLoaded', () => {
        const container = document.getElementById('notes-app');
        if (!container) return;

        const app = createApp(NotesApp);
        const pinia = createPinia();

        app.use(pinia);
        app.mount('#notes-app');
    });
})();
