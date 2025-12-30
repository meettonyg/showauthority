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

    const { createApp, ref, computed, onMounted } = Vue;
    const { createPinia, defineStore } = Pinia;

    // Defensive check for required data
    if (typeof pitNotesData === 'undefined') {
        console.error('Notes: pitNotesData is not defined. Script may have loaded before localization.');
        return;
    }

    // Translations helper
    const i18n = pitNotesData.i18n || {};
    const __ = (key) => i18n[key] || key;

    // =====================================================
    // API CLIENT
    // =====================================================
    const api = GuestifyApi.createClient({
        restUrl: pitNotesData.restUrl,
        nonce: pitNotesData.nonce,
    });

    // =====================================================
    // PINIA STORE
    // =====================================================
    const useNotesStore = defineStore('notes', {
        state: () => ({
            notes: [],
            stats: null,
            noteTypes: null,
            loading: false,
            statsLoading: false,
            noteTypesLoading: false,
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
            hasFilters: (state) => {
                return state.filters.note_type ||
                       state.filters.is_pinned !== null ||
                       state.filters.search;
            },

            noteTypesList: (state) => {
                if (!state.noteTypes) return [];
                return Object.entries(state.noteTypes).map(([key, value]) => ({
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
                    this.error = __('failedToLoad');
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

            async fetchNoteTypes() {
                this.noteTypesLoading = true;

                try {
                    const result = await api.get('notes/types');
                    this.noteTypes = result.data || null;
                } catch (err) {
                    console.error('Failed to fetch note types:', err);
                    // Fallback to default types if API fails
                    this.noteTypes = {
                        general:   { label: __('general'),  color: '#6b7280' },
                        contact:   { label: __('contact'),  color: '#3b82f6' },
                        research:  { label: __('research'), color: '#8b5cf6' },
                        meeting:   { label: __('meeting'),  color: '#10b981' },
                        follow_up: { label: __('followUp'), color: '#f59e0b' },
                        pitch:     { label: __('pitch'),    color: '#ec4899' },
                        feedback:  { label: __('feedback'), color: '#14b8a6' },
                    };
                } finally {
                    this.noteTypesLoading = false;
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

            togglePinnedFilter() {
                this.filters.is_pinned = this.filters.is_pinned === true ? null : true;
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
    // SVG ICON COMPONENT
    // =====================================================
    const NoteTypeIcon = {
        props: ['type', 'color'],
        computed: {
            iconPath() {
                const icons = {
                    'file-text': 'M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2zM14 2v6h6M16 13H8M16 17H8M10 9H8',
                    'user': 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
                    'search': 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.35-4.35',
                    'calendar': 'M3 6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6zM16 2v4M8 2v4M3 10h18',
                    'clock': 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 6v6l4 2',
                    'send': 'M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z',
                    'message-circle': 'M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z',
                };
                return icons[this.type] || icons['file-text'];
            },
        },
        template: `
            <svg viewBox="0 0 24 24" fill="none" :stroke="color || 'currentColor'" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path :d="iconPath"/>
            </svg>
        `,
    };

    // =====================================================
    // MAIN APP COMPONENT
    // =====================================================
    const NotesApp = {
        components: {
            NoteTypeIcon,
        },

        setup() {
            const store = useNotesStore();
            const searchDebounce = ref(null);

            // Translation helper - must be defined in setup to be available in template
            const translate = (key) => {
                const i18n = pitNotesData.i18n || {};
                return i18n[key] || key;
            };

            onMounted(() => {
                store.fetchNoteTypes();
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
                if (store.noteTypes && store.noteTypes[type]) {
                    return store.noteTypes[type];
                }
                return { label: type, color: '#6b7280' };
            };

            const getNoteTypeLabel = (type) => {
                const config = getNoteTypeConfig(type);
                return config.label || type;
            };

            const formatPageInfo = () => {
                return translate('pageOf')
                    .replace('%1$d', store.pagination.page)
                    .replace('%2$d', store.pagination.total_pages);
            };

            return {
                store,
                __: translate,
                handleSearch,
                getInterviewUrl,
                getNoteTypeConfig,
                getNoteTypeLabel,
                formatPageInfo,
            };
        },

        template: `
            <div class="pit-notes-dashboard">
                <!-- Header -->
                <div class="pit-notes-header">
                    <h1>{{ __('notes') }}</h1>
                </div>

                <!-- Stats by Type -->
                <div class="pit-notes-stats" v-if="store.stats && store.noteTypes">
                    <div
                        class="pit-note-stat"
                        :class="{ active: store.filters.note_type === '' && store.filters.is_pinned === null }"
                        @click="store.clearFilters()"
                    >
                        <div class="pit-note-stat-value">{{ store.stats.total || 0 }}</div>
                        <div class="pit-note-stat-label">{{ __('allNotes') }}</div>
                    </div>
                    <div
                        class="pit-note-stat"
                        :class="{ active: store.filters.is_pinned === true }"
                        @click="store.togglePinnedFilter()"
                    >
                        <div class="pit-note-stat-value">{{ store.stats.pinned || 0 }}</div>
                        <div class="pit-note-stat-label">{{ __('pinned') }}</div>
                    </div>
                    <div
                        v-for="noteType in store.noteTypesList"
                        :key="noteType.key"
                        class="pit-note-stat"
                        :class="{ active: store.filters.note_type === noteType.key }"
                        @click="store.setNoteTypeFilter(noteType.key)"
                    >
                        <div
                            class="pit-note-stat-icon"
                            :style="{ backgroundColor: noteType.color + '20' }"
                        >
                            <NoteTypeIcon
                                :type="noteType.icon || 'file-text'"
                                :color="noteType.color"
                                style="width: 18px; height: 18px;"
                            />
                        </div>
                        <div class="pit-note-stat-value">{{ store.stats.by_type?.[noteType.key] || 0 }}</div>
                        <div class="pit-note-stat-label">{{ noteType.label }}</div>
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
                            :placeholder="__('searchNotes')"
                            :value="store.filters.search"
                            @input="handleSearch"
                        />
                    </div>

                    <!-- Note Type Filter -->
                    <select class="pit-select" v-model="store.filters.note_type" @change="store.setFilter('note_type', $event.target.value)">
                        <option value="">{{ __('allTypes') }}</option>
                        <option v-for="noteType in store.noteTypesList" :key="noteType.key" :value="noteType.key">
                            {{ noteType.label }}
                        </option>
                    </select>

                    <!-- Sort -->
                    <select class="pit-select" @change="store.setSort($event.target.value)">
                        <option value="created_at">{{ __('newestFirst') }}</option>
                        <option value="note_date">{{ __('noteDate') }}</option>
                        <option value="title">{{ __('title') }}</option>
                    </select>

                    <!-- View Toggle -->
                    <div class="pit-view-toggle">
                        <button
                            :class="{ active: store.currentView === 'list' }"
                            @click="store.setView('list')"
                            :title="__('listView')"
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
                            :title="__('gridView')"
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
                        {{ __('clearFilters') }}
                    </button>
                </div>

                <!-- Loading -->
                <div v-if="store.loading" class="pit-loading">
                    <div class="pit-loading-spinner"></div>
                    <p>{{ __('loadingNotes') }}</p>
                </div>

                <!-- Error -->
                <div v-else-if="store.error" class="pit-error">
                    <p>{{ store.error }}</p>
                    <button @click="store.fetchNotes()">{{ __('tryAgain') }}</button>
                </div>

                <!-- List View -->
                <div v-else-if="store.currentView === 'list' && store.notes.length > 0" class="pit-notes-list">
                    <div
                        v-for="note in store.notes"
                        :key="note.id"
                        class="pit-note-item"
                        :class="{ pinned: note.is_pinned }"
                    >
                        <!-- Type Icon -->
                        <div
                            class="pit-note-type-icon"
                            :class="'type-' + note.note_type"
                        >
                            <NoteTypeIcon
                                :type="getNoteTypeConfig(note.note_type).icon || 'file-text'"
                                color="white"
                            />
                        </div>

                        <!-- Content -->
                        <div class="pit-note-content">
                            <div class="pit-note-header">
                                <div class="pit-note-title">
                                    {{ note.title || __('untitledNote') }}
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
                                    {{ getNoteTypeLabel(note.note_type) }}
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
                            {{ __('previous') }}
                        </button>
                        <span class="pit-pagination-info">
                            {{ formatPageInfo() }}
                        </span>
                        <button
                            @click="store.setPage(store.pagination.page + 1)"
                            :disabled="store.pagination.page >= store.pagination.total_pages"
                        >
                            {{ __('next') }}
                        </button>
                    </div>
                </div>

                <!-- Grid View -->
                <div v-else-if="store.currentView === 'grid' && store.notes.length > 0" class="pit-notes-grid">
                    <div
                        v-for="note in store.notes"
                        :key="note.id"
                        class="pit-note-card"
                        :class="{ pinned: note.is_pinned }"
                    >
                        <div class="pit-note-card-header">
                            <div
                                class="pit-note-type-icon"
                                :class="'type-' + note.note_type"
                            >
                                <NoteTypeIcon
                                    :type="getNoteTypeConfig(note.note_type).icon || 'file-text'"
                                    color="white"
                                />
                            </div>
                            <div style="flex: 1">
                                <div class="pit-note-title">{{ note.title || __('untitledNote') }}</div>
                                <span class="pit-note-type-badge" :class="'badge-' + note.note_type">
                                    {{ getNoteTypeLabel(note.note_type) }}
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
                    <p v-if="store.hasFilters">{{ __('noNotesMatch') }}</p>
                    <p v-else>{{ __('noNotesYet') }}</p>
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
