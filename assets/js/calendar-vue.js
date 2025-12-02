/**
 * Calendar Vue.js Application
 *
 * Full calendar view with FullCalendar integration.
 * Features:
 * - Month/Week/Day views
 * - List view toggle
 * - Event filtering by type
 * - Click to view/edit events
 * - Create new events
 *
 * @package Podcast_Influence_Tracker
 * @since 3.3.0
 */

(function () {
    'use strict';

    const { createApp, ref, reactive, computed, onMounted, watch, nextTick } = Vue;
    const { createPinia, defineStore } = Pinia;

    // ==========================================================================
    // PINIA STORE
    // ==========================================================================
    const useCalendarStore = defineStore('calendar', {
        state: () => ({
            events: [],
            loading: true,
            error: null,
            selectedEvent: null,
            filters: {
                eventType: '',
                dateRange: {
                    start: null,
                    end: null,
                },
            },
            config: {
                restUrl: '',
                guestifyRestUrl: '',
                nonce: '',
                userId: 0,
                isAdmin: false,
                eventTypes: {},
            },
        }),

        getters: {
            filteredEvents: (state) => {
                let filtered = state.events;

                if (state.filters.eventType) {
                    filtered = filtered.filter(e => e.event_type === state.filters.eventType);
                }

                return filtered;
            },

            upcomingEvents: (state) => {
                const now = new Date();
                return state.events
                    .filter(e => new Date(e.start_datetime) >= now)
                    .sort((a, b) => new Date(a.start_datetime) - new Date(b.start_datetime))
                    .slice(0, 10);
            },

            eventTypeOptions: (state) => {
                return Object.entries(state.config.eventTypes).map(([value, label]) => ({
                    value,
                    label,
                }));
            },
        },

        actions: {
            initConfig(data) {
                this.config = { ...this.config, ...data };
            },

            async fetchEvents(start, end) {
                this.loading = true;
                this.error = null;

                try {
                    const params = new URLSearchParams();
                    if (start) params.append('start_date', start);
                    if (end) params.append('end_date', end);
                    params.append('per_page', '100');

                    const response = await fetch(
                        `${this.config.restUrl}calendar-events?${params}`,
                        {
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': this.config.nonce,
                            },
                        }
                    );

                    if (!response.ok) {
                        throw new Error('Failed to fetch events');
                    }

                    const data = await response.json();
                    this.events = data.data || [];
                } catch (err) {
                    this.error = err.message;
                    console.error('Failed to fetch events:', err);
                } finally {
                    this.loading = false;
                }
            },

            async createEvent(eventData) {
                try {
                    const response = await fetch(
                        `${this.config.restUrl}calendar-events`,
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': this.config.nonce,
                            },
                            body: JSON.stringify(eventData),
                        }
                    );

                    if (!response.ok) {
                        const error = await response.json();
                        throw new Error(error.message || 'Failed to create event');
                    }

                    const data = await response.json();
                    this.events.push(data.data);
                    return data.data;
                } catch (err) {
                    this.error = err.message;
                    throw err;
                }
            },

            async updateEvent(eventId, updates) {
                try {
                    const response = await fetch(
                        `${this.config.restUrl}calendar-events/${eventId}`,
                        {
                            method: 'PATCH',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': this.config.nonce,
                            },
                            body: JSON.stringify(updates),
                        }
                    );

                    if (!response.ok) {
                        const error = await response.json();
                        throw new Error(error.message || 'Failed to update event');
                    }

                    const data = await response.json();
                    const index = this.events.findIndex(e => e.id === eventId);
                    if (index !== -1) {
                        this.events[index] = data.data;
                    }
                    return data.data;
                } catch (err) {
                    this.error = err.message;
                    throw err;
                }
            },

            async deleteEvent(eventId) {
                try {
                    const response = await fetch(
                        `${this.config.restUrl}calendar-events/${eventId}`,
                        {
                            method: 'DELETE',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': this.config.nonce,
                            },
                        }
                    );

                    if (!response.ok) {
                        throw new Error('Failed to delete event');
                    }

                    this.events = this.events.filter(e => e.id !== eventId);
                } catch (err) {
                    this.error = err.message;
                    throw err;
                }
            },

            setSelectedEvent(event) {
                this.selectedEvent = event;
            },

            clearSelectedEvent() {
                this.selectedEvent = null;
            },

            setFilter(key, value) {
                this.filters[key] = value;
            },
        },
    });

    // ==========================================================================
    // EVENT TYPE COLORS
    // ==========================================================================
    const eventTypeColors = {
        recording: { bg: '#3b82f6', border: '#2563eb', text: '#ffffff' },
        air_date: { bg: '#10b981', border: '#059669', text: '#ffffff' },
        prep_call: { bg: '#8b5cf6', border: '#7c3aed', text: '#ffffff' },
        follow_up: { bg: '#f59e0b', border: '#d97706', text: '#ffffff' },
        promotion: { bg: '#ec4899', border: '#db2777', text: '#ffffff' },
        deadline: { bg: '#ef4444', border: '#dc2626', text: '#ffffff' },
        podrec: { bg: '#06b6d4', border: '#0891b2', text: '#ffffff' },
        other: { bg: '#6b7280', border: '#4b5563', text: '#ffffff' },
    };

    // ==========================================================================
    // MAIN APP COMPONENT
    // ==========================================================================
    const CalendarApp = {
        template: `
            <div class="pit-calendar-container">
                <!-- Header -->
                <div class="calendar-header">
                    <div class="calendar-header-left">
                        <h1 class="calendar-title">Calendar</h1>
                    </div>
                    <div class="calendar-header-right">
                        <div class="calendar-filters">
                            <select v-model="filterType" class="filter-select" @change="applyFilter">
                                <option value="">All Event Types</option>
                                <option v-for="type in eventTypeOptions" :key="type.value" :value="type.value">
                                    {{ type.label }}
                                </option>
                            </select>
                        </div>
                        <div class="view-toggle">
                            <button
                                class="view-btn"
                                :class="{ active: currentView === 'calendar' }"
                                @click="setView('calendar')"
                                title="Calendar View">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                    <line x1="16" y1="2" x2="16" y2="6"></line>
                                    <line x1="8" y1="2" x2="8" y2="6"></line>
                                    <line x1="3" y1="10" x2="21" y2="10"></line>
                                </svg>
                            </button>
                            <button
                                class="view-btn"
                                :class="{ active: currentView === 'list' }"
                                @click="setView('list')"
                                title="List View">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="8" y1="6" x2="21" y2="6"></line>
                                    <line x1="8" y1="12" x2="21" y2="12"></line>
                                    <line x1="8" y1="18" x2="21" y2="18"></line>
                                    <line x1="3" y1="6" x2="3.01" y2="6"></line>
                                    <line x1="3" y1="12" x2="3.01" y2="12"></line>
                                    <line x1="3" y1="18" x2="3.01" y2="18"></line>
                                </svg>
                            </button>
                        </div>
                        <button class="add-event-btn" @click="openNewEventModal">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="12" y1="5" x2="12" y2="19"></line>
                                <line x1="5" y1="12" x2="19" y2="12"></line>
                            </svg>
                            Add Event
                        </button>
                    </div>
                </div>

                <!-- Loading State -->
                <div v-if="loading && events.length === 0" class="calendar-loading">
                    <div class="pit-loading-spinner"></div>
                    <p>Loading events...</p>
                </div>

                <!-- Error State -->
                <div v-else-if="error" class="calendar-error">
                    <p>{{ error }}</p>
                    <button @click="refreshEvents" class="retry-btn">Try Again</button>
                </div>

                <!-- Calendar View -->
                <div v-else>
                    <!-- FullCalendar View -->
                    <div v-show="currentView === 'calendar'" class="calendar-wrapper">
                        <div ref="calendarEl" class="fullcalendar-container"></div>
                    </div>

                    <!-- List View -->
                    <div v-show="currentView === 'list'" class="list-view">
                        <div v-if="groupedEvents.length === 0" class="no-events">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                            </svg>
                            <h3>No Events Found</h3>
                            <p>Create your first event to get started.</p>
                        </div>

                        <div v-else>
                            <div v-for="group in groupedEvents" :key="group.date" class="event-group">
                                <div class="event-group-header">
                                    <span class="event-group-date">{{ formatGroupDate(group.date) }}</span>
                                    <span class="event-count">{{ group.events.length }} event{{ group.events.length !== 1 ? 's' : '' }}</span>
                                </div>
                                <div class="event-list">
                                    <div
                                        v-for="event in group.events"
                                        :key="event.id"
                                        class="event-list-item"
                                        :style="{ borderLeftColor: getEventColor(event.event_type).bg }"
                                        @click="viewEvent(event)">
                                        <div class="event-time">
                                            <span v-if="event.is_all_day">All Day</span>
                                            <span v-else>{{ formatTime(event.start_datetime) }}</span>
                                        </div>
                                        <div class="event-details">
                                            <div class="event-title">{{ event.title }}</div>
                                            <div class="event-meta">
                                                <span class="event-type-badge" :style="{ backgroundColor: getEventColor(event.event_type).bg }">
                                                    {{ event.event_type_label }}
                                                </span>
                                                <span v-if="event.appearance_id" class="event-link">
                                                    Linked to interview
                                                </span>
                                            </div>
                                        </div>
                                        <div class="event-actions">
                                            <button class="event-action-btn" @click.stop="editEvent(event)" title="Edit">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                                </svg>
                                            </button>
                                            <button class="event-action-btn delete" @click.stop="confirmDeleteEvent(event)" title="Delete">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <polyline points="3 6 5 6 21 6"></polyline>
                                                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Event Modal -->
                <div class="calendar-modal" :class="{ active: showEventModal }">
                    <div class="calendar-modal-content">
                        <div class="calendar-modal-header">
                            <h2>{{ isEditing ? 'Edit Event' : 'New Event' }}</h2>
                            <button class="modal-close" @click="closeEventModal">&times;</button>
                        </div>
                        <div class="calendar-modal-body">
                            <div v-if="modalError" class="modal-error">{{ modalError }}</div>

                            <div class="form-group">
                                <label>Title</label>
                                <input v-model="eventForm.title" type="text" class="form-input" placeholder="Event title" required>
                            </div>

                            <div class="form-group">
                                <label>Event Type</label>
                                <select v-model="eventForm.event_type" class="form-input">
                                    <option v-for="type in eventTypeOptions" :key="type.value" :value="type.value">
                                        {{ type.label }}
                                    </option>
                                </select>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Start Date</label>
                                    <input v-model="eventForm.start_date" type="date" class="form-input" required>
                                </div>
                                <div class="form-group" v-if="!eventForm.is_all_day">
                                    <label>Start Time</label>
                                    <input v-model="eventForm.start_time" type="time" class="form-input">
                                </div>
                            </div>

                            <div class="form-group checkbox">
                                <label>
                                    <input v-model="eventForm.is_all_day" type="checkbox">
                                    <span>All day event</span>
                                </label>
                            </div>

                            <div class="form-row" v-if="!eventForm.is_all_day">
                                <div class="form-group">
                                    <label>End Date</label>
                                    <input v-model="eventForm.end_date" type="date" class="form-input">
                                </div>
                                <div class="form-group">
                                    <label>End Time</label>
                                    <input v-model="eventForm.end_time" type="time" class="form-input">
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Description</label>
                                <textarea v-model="eventForm.description" class="form-input" rows="3" placeholder="Add notes..."></textarea>
                            </div>

                            <div class="form-group">
                                <label>Location</label>
                                <input v-model="eventForm.location" type="text" class="form-input" placeholder="e.g., Zoom, Studio, etc.">
                            </div>
                        </div>
                        <div class="calendar-modal-footer">
                            <button class="btn-cancel" @click="closeEventModal">Cancel</button>
                            <button class="btn-save" @click="saveEvent" :disabled="saving || !eventForm.title || !eventForm.start_date">
                                {{ saving ? 'Saving...' : (isEditing ? 'Update' : 'Create') }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Event Detail Modal -->
                <div class="calendar-modal" :class="{ active: showDetailModal }">
                    <div class="calendar-modal-content">
                        <div class="calendar-modal-header" :style="{ backgroundColor: selectedEvent ? getEventColor(selectedEvent.event_type).bg : '' }">
                            <h2 style="color: white;">{{ selectedEvent?.title }}</h2>
                            <button class="modal-close" style="color: white;" @click="closeDetailModal">&times;</button>
                        </div>
                        <div class="calendar-modal-body" v-if="selectedEvent">
                            <div class="detail-row">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                    <line x1="16" y1="2" x2="16" y2="6"></line>
                                    <line x1="8" y1="2" x2="8" y2="6"></line>
                                    <line x1="3" y1="10" x2="21" y2="10"></line>
                                </svg>
                                <span>{{ formatEventDateTime(selectedEvent) }}</span>
                            </div>
                            <div class="detail-row" v-if="selectedEvent.location">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                    <circle cx="12" cy="10" r="3"></circle>
                                </svg>
                                <span>{{ selectedEvent.location }}</span>
                            </div>
                            <div class="detail-row">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <polyline points="14 2 14 8 20 8"></polyline>
                                </svg>
                                <span class="event-type-badge" :style="{ backgroundColor: getEventColor(selectedEvent.event_type).bg }">
                                    {{ selectedEvent.event_type_label }}
                                </span>
                            </div>
                            <div class="detail-row" v-if="selectedEvent.description">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="17" y1="10" x2="3" y2="10"></line>
                                    <line x1="21" y1="6" x2="3" y2="6"></line>
                                    <line x1="21" y1="14" x2="3" y2="14"></line>
                                    <line x1="17" y1="18" x2="3" y2="18"></line>
                                </svg>
                                <span>{{ selectedEvent.description }}</span>
                            </div>
                            <div class="detail-row" v-if="selectedEvent.appearance_id">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                                    <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                                </svg>
                                <a :href="'/app/interview/detail/?id=' + selectedEvent.appearance_id" class="interview-link">
                                    View Interview Details
                                </a>
                            </div>
                        </div>
                        <div class="calendar-modal-footer" v-if="selectedEvent">
                            <button class="btn-delete" @click="confirmDeleteEvent(selectedEvent)">Delete</button>
                            <button class="btn-edit" @click="editEvent(selectedEvent)">Edit</button>
                        </div>
                    </div>
                </div>

                <!-- Delete Confirmation Modal -->
                <div class="calendar-modal delete-modal" :class="{ active: showDeleteModal }">
                    <div class="calendar-modal-content small">
                        <div class="calendar-modal-header">
                            <h2>Delete Event</h2>
                            <button class="modal-close" @click="showDeleteModal = false">&times;</button>
                        </div>
                        <div class="calendar-modal-body">
                            <p>Are you sure you want to delete "{{ eventToDelete?.title }}"?</p>
                            <p class="warning">This action cannot be undone.</p>
                        </div>
                        <div class="calendar-modal-footer">
                            <button class="btn-cancel" @click="showDeleteModal = false">Cancel</button>
                            <button class="btn-delete" @click="deleteEvent" :disabled="deleting">
                                {{ deleting ? 'Deleting...' : 'Delete' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `,

        setup() {
            const store = useCalendarStore();
            const calendarEl = ref(null);
            let calendarInstance = null;

            // View state
            const currentView = ref('calendar');
            const filterType = ref('');

            // Modal states
            const showEventModal = ref(false);
            const showDetailModal = ref(false);
            const showDeleteModal = ref(false);
            const isEditing = ref(false);
            const saving = ref(false);
            const deleting = ref(false);
            const modalError = ref(null);
            const eventToDelete = ref(null);

            // Event form
            const eventForm = reactive({
                id: null,
                title: '',
                event_type: 'other',
                start_date: '',
                start_time: '09:00',
                end_date: '',
                end_time: '10:00',
                is_all_day: false,
                description: '',
                location: '',
            });

            // Computed
            const loading = computed(() => store.loading);
            const error = computed(() => store.error);
            const events = computed(() => store.filteredEvents);
            const selectedEvent = computed(() => store.selectedEvent);
            const eventTypeOptions = computed(() => store.eventTypeOptions);

            const groupedEvents = computed(() => {
                const groups = {};
                const sortedEvents = [...events.value].sort(
                    (a, b) => new Date(a.start_datetime) - new Date(b.start_datetime)
                );

                sortedEvents.forEach(event => {
                    const date = event.start_datetime.split(' ')[0].split('T')[0];
                    if (!groups[date]) {
                        groups[date] = { date, events: [] };
                    }
                    groups[date].events.push(event);
                });

                return Object.values(groups).sort((a, b) => new Date(a.date) - new Date(b.date));
            });

            // Methods
            const getEventColor = (type) => {
                return eventTypeColors[type] || eventTypeColors.other;
            };

            const formatGroupDate = (dateStr) => {
                const date = new Date(dateStr + 'T00:00:00');
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                const tomorrow = new Date(today);
                tomorrow.setDate(tomorrow.getDate() + 1);

                if (date.getTime() === today.getTime()) {
                    return 'Today';
                } else if (date.getTime() === tomorrow.getTime()) {
                    return 'Tomorrow';
                }

                return date.toLocaleDateString('en-US', {
                    weekday: 'long',
                    month: 'long',
                    day: 'numeric',
                    year: 'numeric',
                });
            };

            const formatTime = (datetime) => {
                const date = new Date(datetime);
                return date.toLocaleTimeString('en-US', {
                    hour: 'numeric',
                    minute: '2-digit',
                    hour12: true,
                });
            };

            const formatEventDateTime = (event) => {
                const start = new Date(event.start_datetime);
                if (event.is_all_day) {
                    return start.toLocaleDateString('en-US', {
                        weekday: 'long',
                        month: 'long',
                        day: 'numeric',
                        year: 'numeric',
                    }) + ' (All Day)';
                }

                let result = start.toLocaleDateString('en-US', {
                    weekday: 'long',
                    month: 'long',
                    day: 'numeric',
                    year: 'numeric',
                });
                result += ' at ' + formatTime(event.start_datetime);

                if (event.end_datetime) {
                    result += ' - ' + formatTime(event.end_datetime);
                }

                return result;
            };

            const setView = (view) => {
                currentView.value = view;
                if (view === 'calendar' && calendarInstance) {
                    nextTick(() => {
                        calendarInstance.updateSize();
                    });
                }
            };

            const applyFilter = () => {
                store.setFilter('eventType', filterType.value);
                if (calendarInstance) {
                    calendarInstance.refetchEvents();
                }
            };

            const refreshEvents = () => {
                store.fetchEvents();
            };

            const initCalendar = () => {
                if (!calendarEl.value || !window.FullCalendar) return;

                calendarInstance = new FullCalendar.Calendar(calendarEl.value, {
                    initialView: 'dayGridMonth',
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: 'dayGridMonth,timeGridWeek,timeGridDay',
                    },
                    events: (info, successCallback, failureCallback) => {
                        const filtered = filterType.value
                            ? store.events.filter(e => e.event_type === filterType.value)
                            : store.events;

                        const fcEvents = filtered.map(event => ({
                            id: event.id,
                            title: event.title,
                            start: event.start_datetime,
                            end: event.end_datetime || undefined,
                            allDay: event.is_all_day,
                            backgroundColor: getEventColor(event.event_type).bg,
                            borderColor: getEventColor(event.event_type).border,
                            textColor: getEventColor(event.event_type).text,
                            extendedProps: {
                                ...event,
                            },
                        }));

                        successCallback(fcEvents);
                    },
                    eventClick: (info) => {
                        const event = info.event.extendedProps;
                        event.id = parseInt(info.event.id);
                        store.setSelectedEvent(event);
                        showDetailModal.value = true;
                    },
                    dateClick: (info) => {
                        resetEventForm();
                        eventForm.start_date = info.dateStr;
                        eventForm.end_date = info.dateStr;
                        showEventModal.value = true;
                    },
                    datesSet: (info) => {
                        const start = info.startStr.split('T')[0];
                        const end = info.endStr.split('T')[0];
                        store.fetchEvents(start, end);
                    },
                    height: 'auto',
                    eventTimeFormat: {
                        hour: 'numeric',
                        minute: '2-digit',
                        meridiem: 'short',
                    },
                });

                calendarInstance.render();
            };

            const resetEventForm = () => {
                eventForm.id = null;
                eventForm.title = '';
                eventForm.event_type = 'other';
                eventForm.start_date = '';
                eventForm.start_time = '09:00';
                eventForm.end_date = '';
                eventForm.end_time = '10:00';
                eventForm.is_all_day = false;
                eventForm.description = '';
                eventForm.location = '';
                isEditing.value = false;
                modalError.value = null;
            };

            const openNewEventModal = () => {
                resetEventForm();
                const today = new Date().toISOString().split('T')[0];
                eventForm.start_date = today;
                eventForm.end_date = today;
                showEventModal.value = true;
            };

            const closeEventModal = () => {
                showEventModal.value = false;
                resetEventForm();
            };

            const closeDetailModal = () => {
                showDetailModal.value = false;
                store.clearSelectedEvent();
            };

            const viewEvent = (event) => {
                store.setSelectedEvent(event);
                showDetailModal.value = true;
            };

            const editEvent = (event) => {
                showDetailModal.value = false;

                eventForm.id = event.id;
                eventForm.title = event.title;
                eventForm.event_type = event.event_type;
                eventForm.is_all_day = event.is_all_day;
                eventForm.description = event.description || '';
                eventForm.location = event.location || '';

                // Parse datetime
                const startParts = event.start_datetime.split(' ');
                eventForm.start_date = startParts[0];
                eventForm.start_time = startParts[1] ? startParts[1].substring(0, 5) : '09:00';

                if (event.end_datetime) {
                    const endParts = event.end_datetime.split(' ');
                    eventForm.end_date = endParts[0];
                    eventForm.end_time = endParts[1] ? endParts[1].substring(0, 5) : '10:00';
                } else {
                    eventForm.end_date = eventForm.start_date;
                    eventForm.end_time = '';
                }

                isEditing.value = true;
                showEventModal.value = true;
            };

            const saveEvent = async () => {
                if (!eventForm.title || !eventForm.start_date) {
                    modalError.value = 'Please fill in required fields';
                    return;
                }

                saving.value = true;
                modalError.value = null;

                try {
                    const startDatetime = eventForm.is_all_day
                        ? eventForm.start_date + ' 00:00:00'
                        : eventForm.start_date + ' ' + (eventForm.start_time || '09:00') + ':00';

                    let endDatetime = null;
                    if (!eventForm.is_all_day && eventForm.end_date && eventForm.end_time) {
                        endDatetime = eventForm.end_date + ' ' + eventForm.end_time + ':00';
                    }

                    const data = {
                        title: eventForm.title,
                        event_type: eventForm.event_type,
                        start_datetime: startDatetime,
                        end_datetime: endDatetime,
                        is_all_day: eventForm.is_all_day ? 1 : 0,
                        description: eventForm.description || null,
                        location: eventForm.location || null,
                        timezone: 'America/Chicago',
                    };

                    if (isEditing.value && eventForm.id) {
                        await store.updateEvent(eventForm.id, data);
                    } else {
                        await store.createEvent(data);
                    }

                    closeEventModal();

                    if (calendarInstance) {
                        calendarInstance.refetchEvents();
                    }
                } catch (err) {
                    modalError.value = err.message || 'Failed to save event';
                } finally {
                    saving.value = false;
                }
            };

            const confirmDeleteEvent = (event) => {
                eventToDelete.value = event;
                showDetailModal.value = false;
                showDeleteModal.value = true;
            };

            const deleteEvent = async () => {
                if (!eventToDelete.value) return;

                deleting.value = true;
                try {
                    await store.deleteEvent(eventToDelete.value.id);
                    showDeleteModal.value = false;
                    eventToDelete.value = null;

                    if (calendarInstance) {
                        calendarInstance.refetchEvents();
                    }
                } catch (err) {
                    console.error('Failed to delete event:', err);
                } finally {
                    deleting.value = false;
                }
            };

            // Lifecycle
            onMounted(() => {
                if (typeof pitCalendarData !== 'undefined') {
                    store.initConfig(pitCalendarData);
                }

                // Initial fetch
                const today = new Date();
                const start = new Date(today.getFullYear(), today.getMonth(), 1);
                const end = new Date(today.getFullYear(), today.getMonth() + 2, 0);
                store.fetchEvents(
                    start.toISOString().split('T')[0],
                    end.toISOString().split('T')[0]
                ).then(() => {
                    nextTick(() => {
                        initCalendar();
                    });
                });
            });

            // Watch for events changes to update calendar
            watch(events, () => {
                if (calendarInstance) {
                    calendarInstance.refetchEvents();
                }
            });

            return {
                // Refs
                calendarEl,

                // State
                currentView,
                filterType,
                showEventModal,
                showDetailModal,
                showDeleteModal,
                isEditing,
                saving,
                deleting,
                modalError,
                eventToDelete,
                eventForm,

                // Computed
                loading,
                error,
                events,
                selectedEvent,
                eventTypeOptions,
                groupedEvents,

                // Methods
                getEventColor,
                formatGroupDate,
                formatTime,
                formatEventDateTime,
                setView,
                applyFilter,
                refreshEvents,
                openNewEventModal,
                closeEventModal,
                closeDetailModal,
                viewEvent,
                editEvent,
                saveEvent,
                confirmDeleteEvent,
                deleteEvent,
            };
        },
    };

    // ==========================================================================
    // INITIALIZATION
    // ==========================================================================
    document.addEventListener('DOMContentLoaded', function () {
        const container = document.getElementById('calendar-app');
        if (!container) return;

        const pinia = createPinia();
        const app = createApp(CalendarApp);
        app.use(pinia);
        app.mount('#calendar-app');
    });
})();
