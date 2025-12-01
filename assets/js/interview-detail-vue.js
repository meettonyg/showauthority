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
 * @package Podcast_Influence_Tracker
 * @since 3.1.0
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
            
            // UI state
            activeTab: 'about',
            loading: true,
            saving: false,
            error: null,
            
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
            // Initialize config from WordPress
            initConfig(data) {
                this.config = { ...this.config, ...data };
            },

            // API helper
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

            // Load interview data
            async loadInterview() {
                this.loading = true;
                this.error = null;
                
                try {
                    const response = await this.api(`appearances/${this.config.interviewId}`);
                    this.interview = response;
                    
                    // Load tasks and notes in parallel
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

            // Update interview field
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

            // Tasks
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

            // Notes
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
                    // Re-sort notes (pinned first)
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

            setActiveTab(tab) {
                this.activeTab = tab;
            }
        }
    });

    // ==========================================================================
    // COMPONENTS
    // ==========================================================================

    // Back Button
    const BackButton = {
        template: `
            <a :href="boardUrl" class="pit-back-button">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 12H5M12 19l-7-7 7-7"/>
                </svg>
                Back to Interview Tracker
            </a>
        `,
        setup() {
            const store = useDetailStore();
            return {
                boardUrl: computed(() => store.config.boardUrl)
            };
        }
    };

    // Podcast Header
    const PodcastHeader = {
        template: `
            <div class="pit-detail-header">
                <img v-if="interview?.podcast_image" 
                     :src="interview.podcast_image" 
                     :alt="interview.podcast_name"
                     class="pit-podcast-artwork">
                <div v-else class="pit-podcast-artwork-placeholder">
                    {{ initials }}
                </div>
                <div class="pit-header-info">
                    <h1>{{ interview?.podcast_name || 'Loading...' }}</h1>
                    <div class="pit-header-meta">
                        <span class="pit-status-badge" :class="interview?.status">
                            {{ formatStatus(interview?.status) }}
                        </span>
                        <span class="pit-priority-badge" :class="interview?.priority" @click="cyclePriority">
                            {{ interview?.priority || 'medium' }} priority
                        </span>
                        <span v-if="interview?.source">
                            📍 {{ interview.source }}
                        </span>
                        <span v-if="interview?.episode_title">
                            🎙️ {{ interview.episode_title }}
                        </span>
                    </div>
                </div>
            </div>
        `,
        setup() {
            const store = useDetailStore();
            
            const initials = computed(() => {
                const name = store.interview?.podcast_name || '';
                return name.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();
            });
            
            const formatStatus = (status) => {
                const labels = {
                    potential: 'Potential',
                    pitched: 'Pitched',
                    negotiating: 'Negotiating',
                    scheduled: 'Scheduled',
                    recorded: 'Recorded',
                    aired: 'Aired',
                    promoted: 'Promoted',
                    rejected: 'Rejected'
                };
                return labels[status] || status;
            };
            
            const cyclePriority = async () => {
                const priorities = ['low', 'medium', 'high', 'urgent'];
                const current = store.interview?.priority || 'medium';
                const index = priorities.indexOf(current);
                const next = priorities[(index + 1) % priorities.length];
                await store.updateInterview('priority', next);
            };
            
            return {
                interview: computed(() => store.interview),
                initials,
                formatStatus,
                cyclePriority
            };
        }
    };

    // Tab Navigation
    const TabNavigation = {
        template: `
            <div class="pit-tabs">
                <button v-for="tab in tabs" 
                        :key="tab.id"
                        class="pit-tab"
                        :class="{ active: activeTab === tab.id }"
                        @click="setTab(tab.id)">
                    {{ tab.label }}
                    <span v-if="tab.count !== undefined" class="pit-tab-badge">{{ tab.count }}</span>
                </button>
            </div>
        `,
        setup() {
            const store = useDetailStore();
            
            const tabs = computed(() => [
                { id: 'about', label: 'About' },
                { id: 'listen', label: 'Listen' },
                { id: 'contact', label: 'Contact' },
                { id: 'message', label: 'Message' },
                { id: 'tasks', label: 'Tasks', count: store.pendingTasks.length },
                { id: 'notes', label: 'Notes', count: store.notes.length },
            ]);
            
            return {
                tabs,
                activeTab: computed(() => store.activeTab),
                setTab: (tab) => store.setActiveTab(tab)
            };
        }
    };

    // About Tab
    const AboutTab = {
        template: `
            <div class="pit-tab-content" :class="{ active: isActive }">
                <div class="pit-detail-layout">
                    <div class="pit-main-content">
                        <div class="pit-card">
                            <h3>About This Podcast</h3>
                            <p v-if="interview?.rss_url">
                                <strong>RSS Feed:</strong> 
                                <a :href="interview.rss_url" target="_blank">{{ interview.rss_url }}</a>
                            </p>
                            <p v-if="interview?.episode_title">
                                <strong>Episode:</strong> {{ interview.episode_title }}
                            </p>
                            <p v-if="interview?.episode_date">
                                <strong>Air Date:</strong> {{ interview.episode_date }}
                            </p>
                        </div>
                    </div>
                    <div class="pit-sidebar">
                        <div class="pit-card">
                            <h4>Status</h4>
                            <div class="pit-milestone-tracker">
                                <div v-for="step in milestones" 
                                     :key="step.id"
                                     class="pit-milestone-step"
                                     :class="{ 
                                         active: interview?.status === step.id,
                                         completed: isCompleted(step.id)
                                     }"
                                     @click="setStatus(step.id)">
                                    <div class="pit-milestone-dot"></div>
                                    <span class="pit-milestone-label">{{ step.label }}</span>
                                </div>
                            </div>
                        </div>
                        <div class="pit-card">
                            <h4>Details</h4>
                            <div class="pit-sidebar-field">
                                <div class="pit-sidebar-label">Source</div>
                                <div class="pit-sidebar-value">{{ interview?.source || 'Not set' }}</div>
                            </div>
                            <div class="pit-sidebar-field">
                                <div class="pit-sidebar-label">Created</div>
                                <div class="pit-sidebar-value">{{ formatDate(interview?.created_at) }}</div>
                            </div>
                            <div class="pit-sidebar-field">
                                <div class="pit-sidebar-label">Last Updated</div>
                                <div class="pit-sidebar-value">{{ formatDate(interview?.updated_at) }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `,
        setup() {
            const store = useDetailStore();
            
            const milestones = [
                { id: 'potential', label: 'Potential' },
                { id: 'pitched', label: 'Pitched' },
                { id: 'negotiating', label: 'Negotiating' },
                { id: 'scheduled', label: 'Scheduled' },
                { id: 'recorded', label: 'Recorded' },
                { id: 'aired', label: 'Aired' },
                { id: 'promoted', label: 'Promoted' },
            ];
            
            const statusOrder = milestones.map(m => m.id);
            
            const isCompleted = (stepId) => {
                const currentIndex = statusOrder.indexOf(store.interview?.status);
                const stepIndex = statusOrder.indexOf(stepId);
                return stepIndex < currentIndex;
            };
            
            const setStatus = async (status) => {
                await store.updateInterview('status', status);
            };
            
            const formatDate = (dateStr) => {
                if (!dateStr) return 'N/A';
                return new Date(dateStr).toLocaleDateString();
            };
            
            return {
                isActive: computed(() => store.activeTab === 'about'),
                interview: computed(() => store.interview),
                milestones,
                isCompleted,
                setStatus,
                formatDate
            };
        }
    };

    // Tasks Tab
    const TasksTab = {
        template: `
            <div class="pit-tab-content" :class="{ active: isActive }">
                <div class="pit-card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                        <h3 style="margin: 0;">Tasks</h3>
                        <button class="pit-btn pit-btn-primary pit-btn-sm" @click="showAddForm = true">
                            + Add Task
                        </button>
                    </div>
                    
                    <!-- Add Task Form -->
                    <div v-if="showAddForm" class="pit-card" style="background: #f9fafb; margin-bottom: 16px;">
                        <div class="pit-form-group">
                            <label>Task Title</label>
                            <input v-model="newTask.title" type="text" class="pit-input" placeholder="What needs to be done?">
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                            <div class="pit-form-group">
                                <label>Type</label>
                                <select v-model="newTask.task_type" class="pit-select">
                                    <option value="todo">To-do</option>
                                    <option value="email">Email</option>
                                    <option value="message">Message</option>
                                    <option value="research">Research</option>
                                    <option value="follow_up">Follow Up</option>
                                </select>
                            </div>
                            <div class="pit-form-group">
                                <label>Priority</label>
                                <select v-model="newTask.priority" class="pit-select">
                                    <option value="low">Low</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                        </div>
                        <div class="pit-form-group">
                            <label>Due Date</label>
                            <input v-model="newTask.due_date" type="date" class="pit-input">
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <button class="pit-btn pit-btn-primary" @click="createTask" :disabled="!newTask.title">
                                Add Task
                            </button>
                            <button class="pit-btn pit-btn-secondary" @click="showAddForm = false">
                                Cancel
                            </button>
                        </div>
                    </div>
                    
                    <!-- Task List -->
                    <div v-if="tasks.length === 0 && !showAddForm" class="pit-empty-state">
                        <p>No tasks yet. Add one to get started!</p>
                    </div>
                    
                    <div v-for="task in tasks" :key="task.id" 
                         class="pit-task-item"
                         :class="{ completed: task.is_done, overdue: task.is_overdue }">
                        <input type="checkbox" 
                               class="pit-task-checkbox"
                               :checked="task.is_done"
                               @change="toggleTask(task.id)">
                        <div class="pit-task-content">
                            <div class="pit-task-title">{{ task.title }}</div>
                            <div class="pit-task-meta">
                                <span class="pit-priority-badge" :class="task.priority">{{ task.priority }}</span>
                                <span v-if="task.due_date" :class="{ overdue: task.is_overdue }">
                                    📅 {{ task.due_date }}
                                </span>
                                <span>{{ task.task_type }}</span>
                            </div>
                        </div>
                        <button class="pit-icon-btn" @click="deleteTask(task.id)" title="Delete">
                            🗑️
                        </button>
                    </div>
                </div>
            </div>
        `,
        setup() {
            const store = useDetailStore();
            const showAddForm = ref(false);
            const newTask = reactive({
                title: '',
                task_type: 'todo',
                priority: 'medium',
                due_date: ''
            });
            
            const createTask = async () => {
                if (!newTask.title) return;
                await store.createTask({ ...newTask });
                newTask.title = '';
                newTask.task_type = 'todo';
                newTask.priority = 'medium';
                newTask.due_date = '';
                showAddForm.value = false;
            };
            
            const toggleTask = async (taskId) => {
                await store.toggleTask(taskId);
            };
            
            const deleteTask = async (taskId) => {
                if (confirm('Delete this task?')) {
                    await store.deleteTask(taskId);
                }
            };
            
            return {
                isActive: computed(() => store.activeTab === 'tasks'),
                tasks: computed(() => store.tasks),
                showAddForm,
                newTask,
                createTask,
                toggleTask,
                deleteTask
            };
        }
    };

    // Notes Tab
    const NotesTab = {
        template: `
            <div class="pit-tab-content" :class="{ active: isActive }">
                <div class="pit-card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                        <h3 style="margin: 0;">Notes</h3>
                        <button class="pit-btn pit-btn-primary pit-btn-sm" @click="showAddForm = true">
                            + Add Note
                        </button>
                    </div>
                    
                    <!-- Add Note Form -->
                    <div v-if="showAddForm" class="pit-card" style="background: #f9fafb; margin-bottom: 16px;">
                        <div class="pit-form-group">
                            <label>Title (optional)</label>
                            <input v-model="newNote.title" type="text" class="pit-input" placeholder="Note title">
                        </div>
                        <div class="pit-form-group">
                            <label>Content</label>
                            <textarea v-model="newNote.content" class="pit-textarea" placeholder="Write your note..."></textarea>
                        </div>
                        <div class="pit-form-group">
                            <label>Type</label>
                            <select v-model="newNote.note_type" class="pit-select">
                                <option value="general">General</option>
                                <option value="contact">Contact</option>
                                <option value="research">Research</option>
                                <option value="meeting">Meeting</option>
                                <option value="follow_up">Follow Up</option>
                                <option value="pitch">Pitch</option>
                                <option value="feedback">Feedback</option>
                            </select>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <button class="pit-btn pit-btn-primary" @click="createNote" :disabled="!newNote.content">
                                Add Note
                            </button>
                            <button class="pit-btn pit-btn-secondary" @click="showAddForm = false">
                                Cancel
                            </button>
                        </div>
                    </div>
                    
                    <!-- Notes List -->
                    <div v-if="notes.length === 0 && !showAddForm" class="pit-empty-state">
                        <p>No notes yet. Add one to keep track of important information!</p>
                    </div>
                    
                    <div v-for="note in notes" :key="note.id" 
                         class="pit-note-item"
                         :class="{ pinned: note.is_pinned }">
                        <div class="pit-note-header">
                            <div class="pit-note-title">{{ note.title || 'Untitled Note' }}</div>
                            <div class="pit-note-actions">
                                <button class="pit-icon-btn" 
                                        :class="{ pinned: note.is_pinned }"
                                        @click="togglePin(note.id)" 
                                        :title="note.is_pinned ? 'Unpin' : 'Pin'">
                                    {{ note.is_pinned ? '⭐' : '☆' }}
                                </button>
                                <button class="pit-icon-btn" @click="deleteNote(note.id)" title="Delete">
                                    🗑️
                                </button>
                            </div>
                        </div>
                        <div class="pit-note-content" v-html="note.content"></div>
                        <div class="pit-note-footer">
                            <span class="pit-note-type-badge">{{ note.note_type }}</span>
                            <span>{{ note.time_ago }}</span>
                        </div>
                    </div>
                </div>
            </div>
        `,
        setup() {
            const store = useDetailStore();
            const showAddForm = ref(false);
            const newNote = reactive({
                title: '',
                content: '',
                note_type: 'general'
            });
            
            const createNote = async () => {
                if (!newNote.content) return;
                await store.createNote({ ...newNote });
                newNote.title = '';
                newNote.content = '';
                newNote.note_type = 'general';
                showAddForm.value = false;
            };
            
            const togglePin = async (noteId) => {
                await store.toggleNotePin(noteId);
            };
            
            const deleteNote = async (noteId) => {
                if (confirm('Delete this note?')) {
                    await store.deleteNote(noteId);
                }
            };
            
            return {
                isActive: computed(() => store.activeTab === 'notes'),
                notes: computed(() => store.notes),
                showAddForm,
                newNote,
                createNote,
                togglePin,
                deleteNote
            };
        }
    };

    // Placeholder tabs
    const PlaceholderTab = {
        props: ['tabId', 'title'],
        template: `
            <div class="pit-tab-content" :class="{ active: isActive }">
                <div class="pit-card">
                    <div class="pit-empty-state">
                        <h3>{{ title }}</h3>
                        <p>Coming soon...</p>
                    </div>
                </div>
            </div>
        `,
        setup(props) {
            const store = useDetailStore();
            return {
                isActive: computed(() => store.activeTab === props.tabId)
            };
        }
    };

    // ==========================================================================
    // MAIN APP
    // ==========================================================================
    const InterviewDetailApp = {
        components: {
            BackButton,
            PodcastHeader,
            TabNavigation,
            AboutTab,
            TasksTab,
            NotesTab,
            PlaceholderTab
        },
        template: `
            <div class="pit-interview-detail">
                <BackButton />
                
                <div v-if="loading" class="pit-loading">
                    <div class="pit-loading-spinner"></div>
                    <p>Loading interview details...</p>
                </div>
                
                <div v-else-if="error" class="pit-error">
                    <p>{{ error }}</p>
                    <button class="pit-btn pit-btn-primary" @click="reload">Try Again</button>
                </div>
                
                <template v-else>
                    <PodcastHeader />
                    <TabNavigation />
                    
                    <AboutTab />
                    <PlaceholderTab tabId="listen" title="Listen" />
                    <PlaceholderTab tabId="contact" title="Contact" />
                    <PlaceholderTab tabId="message" title="Message" />
                    <TasksTab />
                    <NotesTab />
                </template>
            </div>
        `,
        setup() {
            const store = useDetailStore();
            
            onMounted(() => {
                // Get config from WordPress
                if (typeof guestifyDetailData !== 'undefined') {
                    store.initConfig(guestifyDetailData);
                }
                store.loadInterview();
            });
            
            return {
                loading: computed(() => store.loading),
                error: computed(() => store.error),
                reload: () => store.loadInterview()
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
