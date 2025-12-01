/**
 * Interview Tracker Vue.js Application
 * 
 * Provides Kanban and Table views for managing podcast guest appearances.
 * 
 * @package Podcast_Influence_Tracker
 * @since 3.0.0
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
            error: null,
            currentView: 'kanban',
            selectedIds: [],
            showBulkPanel: false,
            filters: {
                search: '',
                status: '',
                priority: '',
                source: '',
                showArchived: false,
            },
            statusColumns: [
                { key: 'potential', label: 'Potential', color: '#6b7280' },
                { key: 'pitched', label: 'Pitched', color: '#3b82f6' },
                { key: 'negotiating', label: 'Negotiating', color: '#8b5cf6' },
                { key: 'scheduled', label: 'Scheduled', color: '#f59e0b' },
                { key: 'recorded', label: 'Recorded', color: '#10b981' },
                { key: 'aired', label: 'Aired', color: '#059669' },
                { key: 'promoted', label: 'Promoted', color: '#14b8a6' },
                { key: 'rejected', label: 'Rejected', color: '#ef4444' },
            ],
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

                if (!state.filters.showArchived) {
                    result = result.filter(i => !i.is_archived);
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

            row1Columns: (state) => {
                return state.statusColumns.filter(c => 
                    ['potential', 'pitched', 'negotiating', 'scheduled'].includes(c.key)
                );
            },

            row2Columns: (state) => {
                return state.statusColumns.filter(c => 
                    ['recorded', 'aired', 'promoted', 'rejected'].includes(c.key)
                );
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

            const showArchived = computed({
                get: () => store.filters.showArchived,
                set: (val) => {
                    store.setFilter('showArchived', val);
                    store.fetchInterviews();
                },
            });

            return { store, searchQuery, statusFilter, priorityFilter, sourceFilter, showArchived };
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
                    📋 Board
                </button>
                <button 
                    :class="{ active: store.currentView === 'table' }"
                    @click="store.setView('table')"
                >
                    📊 List
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
                style="background: white; border-radius: 8px; padding: 12px; margin-bottom: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); cursor: grab;"
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
            </div>
        `,
        setup(props) {
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

            return { formatDate, onDragStart, onDragEnd };
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
                style="background: #f3f4f6; border-radius: 8px; padding: 12px; min-height: 200px; flex: 1;"
            >
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <span style="font-weight: 600; font-size: 14px;">{{ column.label }}</span>
                    <span style="background: #e5e7eb; padding: 2px 8px; border-radius: 10px; font-size: 12px;">
                        {{ interviews.length }}
                    </span>
                </div>
                <KanbanCard 
                    v-for="interview in interviews" 
                    :key="interview.id" 
                    :interview="interview"
                />
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
            <tr :class="['priority-' + interview.priority]" style="border-bottom: 1px solid #e5e7eb;">
                <td style="padding: 12px;">
                    <input 
                        type="checkbox" 
                        :checked="isSelected"
                        @change="toggleSelect"
                    >
                </td>
                <td style="padding: 12px;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <img 
                            v-if="interview.podcast_image" 
                            :src="interview.podcast_image" 
                            style="width: 32px; height: 32px; border-radius: 4px;"
                        >
                        <span>{{ interview.podcast_name || 'Unknown' }}</span>
                    </div>
                </td>
                <td style="padding: 12px;">{{ interview.episode_title || '-' }}</td>
                <td style="padding: 12px;">
                    <span 
                        :style="{ background: statusColor, color: 'white', padding: '2px 8px', borderRadius: '4px', fontSize: '12px' }"
                    >
                        {{ interview.status }}
                    </span>
                </td>
                <td style="padding: 12px;">{{ interview.priority }}</td>
                <td style="padding: 12px;">{{ interview.source || '-' }}</td>
                <td style="padding: 12px;">{{ formatDate(interview.updated_at) }}</td>
            </tr>
        `,
        setup(props) {
            const store = useInterviewStore();

            const isSelected = computed(() => store.selectedIds.includes(props.interview.id));

            const toggleSelect = () => {
                store.toggleSelection(props.interview.id);
            };

            const statusColor = computed(() => {
                const col = store.statusColumns.find(c => c.key === props.interview.status);
                return col ? col.color : '#6b7280';
            });

            const formatDate = (dateStr) => {
                if (!dateStr) return '-';
                const date = new Date(dateStr);
                return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            };

            return { store, isSelected, toggleSelect, statusColor, formatDate };
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
                            <th style="padding: 12px; text-align: left;">Podcast</th>
                            <th style="padding: 12px; text-align: left;">Episode</th>
                            <th style="padding: 12px; text-align: left;">Status</th>
                            <th style="padding: 12px; text-align: left;">Priority</th>
                            <th style="padding: 12px; text-align: left;">Source</th>
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
                    <input type="text" v-model="bulkSource" placeholder="Don't change">
                </div>
                
                <div class="actions">
                    <button class="btn-secondary" @click="store.showBulkPanel = false">Cancel</button>
                    <button class="btn-primary" @click="applyBulkEdit">Apply Changes</button>
                </div>
            </div>
        `,
        setup() {
            const store = useInterviewStore();
            const bulkStatus = ref('');
            const bulkPriority = ref('');
            const bulkSource = ref('');

            const applyBulkEdit = () => {
                const updates = {};
                if (bulkStatus.value) updates.status = bulkStatus.value;
                if (bulkPriority.value) updates.priority = bulkPriority.value;
                if (bulkSource.value) updates.source = bulkSource.value;

                if (Object.keys(updates).length > 0) {
                    store.bulkUpdate(updates);
                }

                // Reset
                bulkStatus.value = '';
                bulkPriority.value = '';
                bulkSource.value = '';
            };

            return { store, bulkStatus, bulkPriority, bulkSource, applyBulkEdit };
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
            SelectionBar,
            BulkEditPanel,
        },
        template: `
            <div class="pit-interview-tracker">
                <div v-if="store.error" class="pit-error">
                    {{ store.error }}
                    <button @click="store.error = null" style="margin-left: 8px;">×</button>
                </div>
                
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <ViewToggle />
                    <div style="color: #6b7280; font-size: 14px;">
                        {{ store.filteredInterviews.length }} interviews
                    </div>
                </div>
                
                <FilterBar />
                
                <div v-if="store.loading" class="pit-loading">
                    <p>Loading interviews...</p>
                </div>
                
                <template v-else>
                    <KanbanBoard v-if="store.currentView === 'kanban'" />
                    <TableView v-else />
                </template>
                
                <SelectionBar />
                <BulkEditPanel />
            </div>
        `,
        setup() {
            const store = useInterviewStore();

            onMounted(() => {
                // Get initial view from data attribute
                const appEl = document.getElementById('interview-tracker-app');
                if (appEl && appEl.dataset.initialView) {
                    store.setView(appEl.dataset.initialView);
                }

                // Fetch interviews
                store.fetchInterviews();
            });

            return { store };
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
