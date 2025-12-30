/**
 * Tasks Dashboard Vue.js Application
 *
 * Displays all tasks across appearances with filtering and sorting.
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
        restUrl: pitTasksData.restUrl,
        nonce: pitTasksData.nonce,
    });

    // =====================================================
    // PINIA STORE
    // =====================================================
    const useTasksStore = defineStore('tasks', {
        state: () => ({
            tasks: [],
            stats: null,
            loading: false,
            statsLoading: false,
            error: null,
            filters: {
                search: '',
                status: pitTasksData.defaultStatus || '',
                priority: '',
                is_overdue: false,
            },
            sort: {
                orderby: 'created_at',
                order: 'DESC',
            },
            pagination: {
                page: 1,
                per_page: pitTasksData.defaultLimit || 50,
                total: 0,
                total_pages: 0,
            },
        }),

        getters: {
            filteredTasks: (state) => {
                // Client-side search filter for instant feedback
                let result = [...state.tasks];

                if (state.filters.search) {
                    const search = state.filters.search.toLowerCase();
                    result = result.filter(t =>
                        (t.title || '').toLowerCase().includes(search) ||
                        (t.description || '').toLowerCase().includes(search) ||
                        (t.podcast_name || '').toLowerCase().includes(search)
                    );
                }

                return result;
            },

            hasFilters: (state) => {
                return state.filters.status ||
                       state.filters.priority ||
                       state.filters.is_overdue ||
                       state.filters.search;
            },
        },

        actions: {
            async fetchTasks() {
                this.loading = true;
                this.error = null;

                try {
                    const params = new URLSearchParams({
                        page: this.pagination.page.toString(),
                        per_page: this.pagination.per_page.toString(),
                        orderby: this.sort.orderby,
                        order: this.sort.order,
                    });

                    if (this.filters.status) {
                        params.append('status', this.filters.status);
                    }
                    if (this.filters.priority) {
                        params.append('priority', this.filters.priority);
                    }
                    if (this.filters.is_overdue) {
                        params.append('is_overdue', '1');
                    }
                    if (this.filters.search) {
                        params.append('search', this.filters.search);
                    }

                    const result = await api.get(`tasks?${params}`);

                    this.tasks = result.data || [];
                    this.pagination.total = result.meta?.total || 0;
                    this.pagination.total_pages = result.meta?.total_pages || 0;
                } catch (err) {
                    console.error('Failed to fetch tasks:', err);
                    this.error = 'Failed to load tasks. Please try again.';
                } finally {
                    this.loading = false;
                }
            },

            async fetchStats() {
                this.statsLoading = true;

                try {
                    const result = await api.get('tasks/stats');
                    this.stats = result.data || null;
                } catch (err) {
                    console.error('Failed to fetch task stats:', err);
                } finally {
                    this.statsLoading = false;
                }
            },

            async toggleTask(task) {
                const previousDone = task.is_done;
                task.is_done = !task.is_done;
                task.status = task.is_done ? 'completed' : 'pending';

                try {
                    await api.post(`appearances/${task.appearance_id}/tasks/${task.id}/toggle`);
                    // Refresh stats after toggle
                    this.fetchStats();
                } catch (err) {
                    console.error('Failed to toggle task:', err);
                    // Revert on failure
                    task.is_done = previousDone;
                    task.status = previousDone ? 'completed' : 'pending';
                }
            },

            setFilter(key, value) {
                this.filters[key] = value;
                this.pagination.page = 1;
                this.fetchTasks();
            },

            setSort(orderby) {
                if (this.sort.orderby === orderby) {
                    this.sort.order = this.sort.order === 'ASC' ? 'DESC' : 'ASC';
                } else {
                    this.sort.orderby = orderby;
                    this.sort.order = orderby === 'priority' ? 'ASC' : 'DESC';
                }
                this.fetchTasks();
            },

            setPage(page) {
                this.pagination.page = page;
                this.fetchTasks();
            },

            clearFilters() {
                this.filters = {
                    search: '',
                    status: '',
                    priority: '',
                    is_overdue: false,
                };
                this.pagination.page = 1;
                this.fetchTasks();
            },
        },
    });

    // =====================================================
    // MAIN APP COMPONENT
    // =====================================================
    const TasksApp = {
        setup() {
            const store = useTasksStore();
            const searchDebounce = ref(null);

            onMounted(() => {
                store.fetchTasks();
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
                    store.fetchTasks();
                }, 300);
            };

            const formatDate = (dateStr) => {
                if (!dateStr) return '';
                const date = new Date(dateStr);
                const today = new Date();
                today.setHours(0, 0, 0, 0);

                const taskDate = new Date(date);
                taskDate.setHours(0, 0, 0, 0);

                const diffDays = Math.floor((taskDate - today) / (1000 * 60 * 60 * 24));

                if (diffDays === 0) return 'Today';
                if (diffDays === 1) return 'Tomorrow';
                if (diffDays === -1) return 'Yesterday';
                if (diffDays < -1) return `${Math.abs(diffDays)} days ago`;
                if (diffDays < 7) return `In ${diffDays} days`;

                return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            };

            const getDueClass = (task) => {
                if (!task.due_date || task.is_done) return '';
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                const dueDate = new Date(task.due_date);
                dueDate.setHours(0, 0, 0, 0);

                if (dueDate < today) return 'overdue';
                if (dueDate.getTime() === today.getTime()) return 'today';
                return '';
            };

            const getInterviewUrl = (appearanceId) => {
                return `${pitTasksData.interviewDetailUrl}?id=${appearanceId}`;
            };

            return {
                store,
                handleSearch,
                formatDate,
                getDueClass,
                getInterviewUrl,
            };
        },

        template: `
            <div class="pit-tasks-dashboard">
                <!-- Header -->
                <div class="pit-tasks-header">
                    <h1>Tasks</h1>
                </div>

                <!-- Stats Cards -->
                <div class="pit-tasks-stats" v-if="store.stats">
                    <div class="pit-stat-card" @click="store.setFilter('status', '')">
                        <div class="pit-stat-value">{{ store.stats.total || 0 }}</div>
                        <div class="pit-stat-label">Total Tasks</div>
                    </div>
                    <div class="pit-stat-card" @click="store.setFilter('status', 'pending')">
                        <div class="pit-stat-value">{{ store.stats.by_status?.pending || 0 }}</div>
                        <div class="pit-stat-label">Pending</div>
                    </div>
                    <div class="pit-stat-card" @click="store.setFilter('status', 'in_progress')">
                        <div class="pit-stat-value">{{ store.stats.by_status?.in_progress || 0 }}</div>
                        <div class="pit-stat-label">In Progress</div>
                    </div>
                    <div class="pit-stat-card overdue" @click="store.filters.is_overdue = true; store.fetchTasks()">
                        <div class="pit-stat-value">{{ store.stats.overdue || 0 }}</div>
                        <div class="pit-stat-label">Overdue</div>
                    </div>
                    <div class="pit-stat-card" @click="store.setFilter('status', 'completed')">
                        <div class="pit-stat-value">{{ store.stats.by_status?.completed || 0 }}</div>
                        <div class="pit-stat-label">Completed</div>
                    </div>
                </div>

                <!-- Toolbar -->
                <div class="pit-tasks-toolbar">
                    <!-- Search -->
                    <div class="pit-search-wrapper">
                        <svg class="pit-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"/>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                        </svg>
                        <input
                            type="text"
                            class="pit-search-input"
                            placeholder="Search tasks..."
                            :value="store.filters.search"
                            @input="handleSearch"
                        />
                    </div>

                    <!-- Status Filter -->
                    <select class="pit-select" v-model="store.filters.status" @change="store.setFilter('status', $event.target.value)">
                        <option value="">All Statuses</option>
                        <option value="pending">Pending</option>
                        <option value="in_progress">In Progress</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>

                    <!-- Priority Filter -->
                    <select class="pit-select" v-model="store.filters.priority" @change="store.setFilter('priority', $event.target.value)">
                        <option value="">All Priorities</option>
                        <option value="urgent">Urgent</option>
                        <option value="high">High</option>
                        <option value="medium">Medium</option>
                        <option value="low">Low</option>
                    </select>

                    <!-- Sort -->
                    <select class="pit-select" @change="store.setSort($event.target.value)">
                        <option value="created_at">Newest First</option>
                        <option value="due_date">Due Date</option>
                        <option value="priority">Priority</option>
                    </select>

                    <!-- Clear Filters -->
                    <button v-if="store.hasFilters" class="pit-btn-link" @click="store.clearFilters()">
                        Clear Filters
                    </button>
                </div>

                <!-- Loading -->
                <div v-if="store.loading" class="pit-loading">
                    <div class="pit-loading-spinner"></div>
                    <p>Loading tasks...</p>
                </div>

                <!-- Error -->
                <div v-else-if="store.error" class="pit-error">
                    <p>{{ store.error }}</p>
                    <button @click="store.fetchTasks()">Try Again</button>
                </div>

                <!-- Task List -->
                <div v-else-if="store.filteredTasks.length > 0" class="pit-tasks-list">
                    <div
                        v-for="task in store.filteredTasks"
                        :key="task.id"
                        class="pit-task-item"
                        :class="{
                            completed: task.is_done,
                            overdue: task.is_overdue && !task.is_done
                        }"
                    >
                        <!-- Checkbox -->
                        <div
                            class="pit-task-checkbox"
                            :class="{ checked: task.is_done }"
                            @click="store.toggleTask(task)"
                        >
                            <svg v-if="task.is_done" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                <polyline points="20 6 9 17 4 12"/>
                            </svg>
                        </div>

                        <!-- Content -->
                        <div class="pit-task-content">
                            <div class="pit-task-title">{{ task.title }}</div>
                            <div class="pit-task-meta">
                                <!-- Podcast -->
                                <div class="pit-task-podcast" v-if="task.podcast_name">
                                    <img v-if="task.podcast_artwork" :src="task.podcast_artwork" :alt="task.podcast_name" />
                                    <a :href="getInterviewUrl(task.appearance_id)">{{ task.podcast_name }}</a>
                                </div>

                                <!-- Priority -->
                                <span class="pit-priority-badge" :class="task.priority">
                                    {{ task.priority }}
                                </span>

                                <!-- Due Date -->
                                <span v-if="task.due_date" class="pit-task-due" :class="getDueClass(task)">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10"/>
                                        <polyline points="12 6 12 12 16 14"/>
                                    </svg>
                                    {{ formatDate(task.due_date) }}
                                </span>
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

                <!-- Empty State -->
                <div v-else class="pit-empty-state">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                    </svg>
                    <p v-if="store.hasFilters">No tasks match your filters.</p>
                    <p v-else>No tasks yet. Tasks will appear here when you add them to your appearances.</p>
                </div>
            </div>
        `,
    };

    // =====================================================
    // MOUNT APPLICATION
    // =====================================================
    document.addEventListener('DOMContentLoaded', () => {
        const container = document.getElementById('tasks-app');
        if (!container) return;

        const app = createApp(TasksApp);
        const pinia = createPinia();

        app.use(pinia);
        app.mount('#tasks-app');
    });
})();
