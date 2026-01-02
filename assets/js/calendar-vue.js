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

    // Defensive check for required dependencies
    if (typeof Vue === 'undefined' || typeof Pinia === 'undefined') {
        console.error('Calendar: Vue or Pinia not loaded');
        return;
    }

    // Defensive check for required data
    if (typeof pitCalendarData === 'undefined') {
        console.error('Calendar: pitCalendarData is not defined. Script may have loaded before localization.');
        return;
    }

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
        imported: { bg: '#94a3b8', border: '#64748b', text: '#ffffff' },
    };

    // ==========================================================================
    // EVENT TYPE ICONS (Feather-style stroke SVGs for consistency)
    // Interview-related events get distinct icons; imported events get a subtle indicator
    // ==========================================================================
    const eventTypeIcons = {
        // Microphone for recording sessions
        recording: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path><path d="M19 10v2a7 7 0 0 1-14 0v-2"></path><line x1="12" y1="19" x2="12" y2="23"></line><line x1="8" y1="23" x2="16" y2="23"></line></svg>',
        // TV/Monitor for air date
        air_date: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><rect x="2" y="7" width="20" height="15" rx="2" ry="2"></rect><polyline points="17 2 12 7 7 2"></polyline></svg>',
        // Phone for prep calls
        prep_call: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>',
        // Mail for follow up
        follow_up: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>',
        // Megaphone/Volume for promotion
        promotion: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon><path d="M19.07 4.93a10 10 0 0 1 0 14.14"></path><path d="M15.54 8.46a5 5 0 0 1 0 7.07"></path></svg>',
        // Clock for deadlines
        deadline: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>',
        // Headphones for podcast recording
        podrec: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><path d="M3 18v-6a9 9 0 0 1 18 0v6"></path><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"></path></svg>',
        // Calendar for other/generic
        other: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>',
        // Cloud for imported events (synced from external calendar)
        imported: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="12" height="12" opacity="0.7"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"></path></svg>',
    };

    /**
     * Get icon HTML for an event type
     * Returns empty string for imported events (they get styled differently via CSS)
     */
    const getEventIcon = (eventType, isImported) => {
        if (isImported) {
            return eventTypeIcons.imported || '';
        }
        return eventTypeIcons[eventType] || eventTypeIcons.other;
    };

    // ==========================================================================
    // IMPORTED EVENTS SECTION COMPONENT
    // ==========================================================================
    const ImportedEventsSection = {
        props: {
            provider: { type: String, required: true },
            count: { type: Number, required: true },
            deleting: { type: Boolean, default: false },
        },
        emits: ['delete'],
        template: `
            <div class="imported-events-section">
                <div class="imported-events-info">
                    <span class="imported-count">{{ count }} imported event{{ count !== 1 ? 's' : '' }}</span>
                    <p class="imported-desc">Events pulled from {{ providerName }} Calendar (not interview-linked)</p>
                </div>
                <button
                    class="btn-delete-imported"
                    @click="$emit('delete', provider)"
                    :disabled="deleting || count === 0">
                    {{ deleting ? 'Deleting...' : 'Delete Imported Events' }}
                </button>
            </div>
        `,
        computed: {
            providerName() {
                return this.provider === 'google' ? 'Google' : 'Outlook';
            }
        }
    };

    // ==========================================================================
    // MAIN APP COMPONENT
    // ==========================================================================
    const CalendarApp = {
        components: {
            ImportedEventsSection,
        },
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
                        <button class="sync-settings-btn" @click="showSyncModal = true" :class="{ connected: syncStatus.google?.connected }">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 12a9 9 0 0 1-9 9m9-9a9 9 0 0 0-9-9m9 9H3m9 9a9 9 0 0 1-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9"></path>
                            </svg>
                            {{ syncStatus.google?.connected ? 'Synced' : 'Sync' }}
                        </button>
                    </div>
                </div>

                <!-- Loading State -->
                <div v-show="loading && events.length === 0" class="calendar-loading">
                    <div class="pit-loading-spinner"></div>
                    <p>Loading events...</p>
                </div>

                <!-- Error State -->
                <div v-show="error && !loading" class="calendar-error">
                    <p>{{ error }}</p>
                    <button @click="refreshEvents" class="retry-btn">Try Again</button>
                </div>

                <!-- Calendar View -->
                <div v-show="!loading || events.length > 0">
                    <!-- FullCalendar View -->
                    <div v-show="currentView === 'calendar'" class="calendar-wrapper">
                        <div v-if="fullCalendarError" class="calendar-error">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="8" x2="12" y2="12"></line>
                                <line x1="12" y1="16" x2="12.01" y2="16"></line>
                            </svg>
                            <h3>Calendar Failed to Load</h3>
                            <p>The calendar library could not be loaded. This may be due to network restrictions or an ad blocker.</p>
                            <button @click="() => window.location.reload()" class="retry-btn">Reload Page</button>
                        </div>
                        <div v-else ref="calendarEl" class="fullcalendar-container"></div>
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
                                        :class="{ 'imported': isImportedEvent(event) }"
                                        :style="{ borderLeftColor: isImportedEvent(event) ? '#94a3b8' : getEventColor(event.event_type).bg }"
                                        @click="viewEvent(event)">
                                        <div class="event-time">
                                            <span v-if="event.is_all_day">All Day</span>
                                            <span v-else>{{ formatTime(event.start_datetime) }}</span>
                                        </div>
                                        <div class="event-details">
                                            <div class="event-title">
                                                <span class="event-icon" v-html="getEventIconHtml(event)"></span>
                                                {{ event.title }}
                                            </div>
                                            <div class="event-meta">
                                                <span class="event-type-badge" :style="{ backgroundColor: isImportedEvent(event) ? '#94a3b8' : getEventColor(event.event_type).bg }">
                                                    {{ isImportedEvent(event) ? 'Imported' : event.event_type_label }}
                                                </span>
                                                <span v-if="event.appearance_id" class="event-link interview-link">
                                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                                                        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                                                    </svg>
                                                    Linked to interview
                                                </span>
                                                <span v-else-if="isImportedEvent(event)" class="event-link imported-link">
                                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor">
                                                        <path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96z"/>
                                                    </svg>
                                                    Synced from calendar
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

                <!-- Generic Confirmation Modal -->
                <div class="calendar-modal confirm-modal" :class="{ active: showConfirmModal }">
                    <div class="calendar-modal-content small">
                        <div class="calendar-modal-header">
                            <h2>{{ confirmAction.title }}</h2>
                            <button class="modal-close" @click="cancelConfirm">&times;</button>
                        </div>
                        <div class="calendar-modal-body">
                            <p>{{ confirmAction.message }}</p>
                        </div>
                        <div class="calendar-modal-footer">
                            <button class="btn-cancel" @click="cancelConfirm">Cancel</button>
                            <button class="btn-primary" @click="executeConfirm">Confirm</button>
                        </div>
                    </div>
                </div>

                <!-- Sync Settings Modal -->
                <div class="calendar-modal sync-modal" :class="{ active: showSyncModal }">
                    <div class="calendar-modal-content">
                        <div class="calendar-modal-header">
                            <h2>Calendar Sync</h2>
                            <button class="modal-close" @click="showSyncModal = false">&times;</button>
                        </div>
                        <div class="calendar-modal-body">
                            <!-- Google Calendar Section -->
                            <div class="sync-provider">
                                <div class="sync-provider-header">
                                    <div class="sync-provider-icon google">
                                        <svg width="20" height="20" viewBox="0 0 24 24">
                                            <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                                            <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                                            <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                                            <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                                        </svg>
                                    </div>
                                    <div class="sync-provider-info">
                                        <h3>Google Calendar</h3>
                                        <p v-if="syncStatus.google?.connected">
                                            Connected as {{ syncStatus.google.email }}
                                        </p>
                                        <p v-else-if="!syncStatus.google?.configured">
                                            Not configured by administrator
                                        </p>
                                        <p v-else>Not connected</p>
                                    </div>
                                    <div class="sync-provider-action">
                                        <button
                                            v-if="syncStatus.google?.connected"
                                            class="btn-disconnect"
                                            @click="disconnectGoogle"
                                            :disabled="syncLoading">
                                            Disconnect
                                        </button>
                                        <button
                                            v-else-if="syncStatus.google?.configured"
                                            class="btn-connect"
                                            @click="connectGoogle"
                                            :disabled="syncLoading">
                                            Connect
                                        </button>
                                    </div>
                                </div>

                                <!-- Connected Settings -->
                                <div v-if="syncStatus.google?.connected" class="sync-settings">
                                    <div v-if="syncStatus.google.sync_error" class="sync-error">
                                        {{ syncStatus.google.sync_error }}
                                    </div>

                                    <div class="sync-setting-row">
                                        <label>Calendar</label>
                                        <select v-model="selectedCalendarId" class="form-input" @change="selectCalendar">
                                            <option value="">Select a calendar...</option>
                                            <option v-for="cal in googleCalendars" :key="cal.id" :value="cal.id">
                                                {{ cal.name }} {{ cal.primary ? '(Primary)' : '' }}
                                            </option>
                                        </select>
                                    </div>

                                    <div class="sync-setting-row">
                                        <label>Sync Direction</label>
                                        <select v-model="syncDirection" class="form-input" @change="updateSyncSettings">
                                            <option value="both">Two-way sync</option>
                                            <option value="push">Push to Google only</option>
                                            <option value="pull">Pull from Google only</option>
                                        </select>
                                    </div>

                                    <div class="sync-setting-row toggle">
                                        <label>
                                            <input type="checkbox" v-model="syncEnabled" @change="updateSyncSettings">
                                            <span>Enable automatic sync</span>
                                        </label>
                                    </div>

                                    <div class="sync-actions">
                                        <button class="btn-sync" @click="triggerSync" :disabled="syncLoading || !syncStatus.google.calendar_id">
                                            {{ syncLoading ? 'Syncing...' : 'Sync Now' }}
                                        </button>
                                        <span v-if="syncStatus.google.last_sync_at" class="last-sync">
                                            Last synced: {{ formatLastSync(syncStatus.google.last_sync_at) }}
                                        </span>
                                    </div>

                                    <!-- Delete Imported Events -->
                                    <imported-events-section
                                        provider="google"
                                        :count="googleImportedCount"
                                        :deleting="deletingImported"
                                        @delete="(p) => confirmDeleteImported(p)"
                                    />
                                </div>
                            </div>

                            <!-- Outlook Section - Commented out for future phase
                            <div class="sync-provider" :class="{ connected: syncStatus.outlook?.connected }">
                                <div class="sync-provider-header">
                                    <div class="sync-provider-icon outlook">
                                        <svg width="20" height="20" viewBox="0 0 24 24">
                                            <path fill="#0078D4" d="M24 7.387v10.478c0 .23-.08.424-.238.576-.158.152-.352.228-.582.228h-8.547v-6.036l1.387 1.004c.07.047.15.07.238.07.089 0 .168-.023.238-.07l6.768-4.907c.094-.07.165-.158.211-.262.047-.105.07-.211.07-.317v-.457c0-.212-.082-.388-.246-.527-.164-.14-.359-.175-.586-.106l-7.843 5.7-.879-.639V6.316h8.547c.23 0 .424.076.582.228.158.152.238.346.238.576v.267h-.157z"/>
                                            <path fill="#0078D4" d="M7.477 19.59c-2.063 0-3.809-.723-5.238-2.168C.813 15.977.098 14.195.098 12.078c0-2.118.715-3.9 2.145-5.348 1.43-1.449 3.176-2.173 5.238-2.173 2.063 0 3.809.724 5.238 2.173 1.43 1.448 2.145 3.23 2.145 5.348 0 2.117-.715 3.899-2.145 5.344-1.43 1.445-3.176 2.168-5.238 2.168zm0-2.46c1.266 0 2.34-.465 3.223-1.395.883-.93 1.324-2.106 1.324-3.528s-.441-2.598-1.324-3.528c-.883-.93-1.957-1.395-3.223-1.395s-2.34.465-3.223 1.395c-.883.93-1.324 2.106-1.324 3.528s.441 2.598 1.324 3.528c.883.93 1.957 1.395 3.223 1.395z"/>
                                        </svg>
                                    </div>
                                    <div class="sync-provider-info">
                                        <h3>Microsoft Outlook</h3>
                                        <p v-if="syncStatus.outlook?.connected" class="connected-email">{{ syncStatus.outlook.email }}</p>
                                        <p v-else-if="!syncStatus.outlook?.configured" class="not-configured">Not configured</p>
                                        <p v-else class="not-connected">Not connected</p>
                                    </div>
                                    <div class="sync-provider-actions">
                                        <button v-if="!syncStatus.outlook?.connected && syncStatus.outlook?.configured" class="btn-connect outlook" @click="connectOutlook">
                                            Connect Outlook
                                        </button>
                                        <button v-if="syncStatus.outlook?.connected" class="btn-disconnect" @click="disconnectOutlook">
                                            Disconnect
                                        </button>
                                    </div>
                                </div>

                                <div v-if="syncStatus.outlook?.connected" class="sync-provider-body">
                                    <div v-if="!syncStatus.outlook.calendar_id" class="calendar-selection">
                                        <label>Select calendar to sync:</label>
                                        <div class="calendar-list" v-if="outlookCalendars.length > 0">
                                            <div
                                                v-for="cal in outlookCalendars"
                                                :key="cal.id"
                                                class="calendar-item"
                                                :class="{ selected: selectedOutlookCalendarId === cal.id }"
                                                @click="selectedOutlookCalendarId = cal.id"
                                            >
                                                <span class="calendar-color" :style="{ background: cal.color }"></span>
                                                <span class="calendar-name">{{ cal.name }}</span>
                                                <span v-if="cal.primary" class="calendar-primary">Primary</span>
                                            </div>
                                        </div>
                                        <button class="btn-primary" @click="selectOutlookCalendar" :disabled="!selectedOutlookCalendarId">
                                            Use Selected Calendar
                                        </button>
                                    </div>

                                    <div v-else class="sync-status">
                                        <div class="selected-calendar">
                                            <span>Syncing with:</span>
                                            <strong>{{ syncStatus.outlook.calendar_name }}</strong>
                                        </div>

                                        <div v-if="syncStatus.outlook.sync_error" class="sync-error">
                                            <span class="error-icon">!</span>
                                            {{ syncStatus.outlook.sync_error }}
                                        </div>

                                        <div class="sync-actions">
                                            <button class="btn-sync" @click="triggerOutlookSync" :disabled="outlookSyncLoading || !syncStatus.outlook.calendar_id">
                                                {{ outlookSyncLoading ? 'Syncing...' : 'Sync Now' }}
                                            </button>
                                            <span v-if="syncStatus.outlook.last_sync_at" class="last-sync">
                                                Last synced: {{ formatLastSync(syncStatus.outlook.last_sync_at) }}
                                            </span>
                                        </div>

                                        <imported-events-section
                                            provider="outlook"
                                            :count="outlookImportedCount"
                                            :deleting="deletingImported"
                                            @delete="confirmDeleteImported"
                                        />
                                    </div>
                                </div>
                            </div>
                            End Outlook Section -->
                        </div>
                    </div>
                </div>
            </div>
        `,

        setup() {
            const store = useCalendarStore();
            const calendarEl = ref(null);
            const fullCalendarError = ref(false);
            let calendarInstance = null;

            // View state
            const currentView = ref('calendar');
            const filterType = ref('');

            // Modal states
            const showEventModal = ref(false);
            const showDetailModal = ref(false);
            const showDeleteModal = ref(false);
            const showSyncModal = ref(false);
            const showConfirmModal = ref(false);
            const isEditing = ref(false);
            const saving = ref(false);
            const deleting = ref(false);
            const modalError = ref(null);
            const eventToDelete = ref(null);
            const confirmAction = ref({ title: '', message: '', callback: null });

            // Sync state
            const syncStatus = ref({ google: null, outlook: null });
            const syncLoading = ref(false);
            const outlookSyncLoading = ref(false);
            const googleCalendars = ref([]);
            const outlookCalendars = ref([]);
            const selectedCalendarId = ref('');
            const selectedOutlookCalendarId = ref('');
            const syncDirection = ref('both');
            const syncEnabled = ref(true);
            const googleImportedCount = ref(0);
            const outlookImportedCount = ref(0);
            const deletingImported = ref(false);

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

            /**
             * Check if an event is imported (synced from external calendar, not interview-related)
             */
            const isImportedEvent = (event) => {
                return !event.appearance_id && event.sync_status !== 'local_only';
            };

            /**
             * Get the icon HTML for an event (used in list view)
             */
            const getEventIconHtml = (event) => {
                const isImported = isImportedEvent(event);
                return getEventIcon(event.event_type, isImported);
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
                if (view === 'calendar') {
                    nextTick(() => {
                        if (calendarInstance) {
                            calendarInstance.updateSize();
                        } else if (calendarEl.value && !store.loading) {
                            // Initialize calendar if not yet created
                            initCalendar();
                        }
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
                if (calendarInstance) return; // Already initialized

                if (!calendarEl.value) {
                    console.error('Calendar: calendarEl ref not found');
                    return;
                }
                if (!window.FullCalendar) {
                    console.error('Calendar: FullCalendar library not loaded');
                    fullCalendarError.value = true;
                    return;
                }

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

                        const fcEvents = filtered.map(event => {
                            // Determine if this is an imported event (no appearance_id = imported from external calendar)
                            const isImported = !event.appearance_id && event.sync_status !== 'local_only';
                            const colors = isImported ? eventTypeColors.imported : getEventColor(event.event_type);

                            return {
                                id: event.id,
                                title: event.title,
                                start: event.start_datetime,
                                end: event.end_datetime || undefined,
                                allDay: event.is_all_day,
                                backgroundColor: colors.bg,
                                borderColor: colors.border,
                                textColor: colors.text,
                                classNames: isImported ? ['fc-event-imported'] : ['fc-event-interview'],
                                extendedProps: {
                                    ...event,
                                    isImported,
                                },
                            };
                        });

                        successCallback(fcEvents);
                    },
                    // Custom event rendering with icons
                    eventContent: (arg) => {
                        const event = arg.event.extendedProps;
                        const isImported = event.isImported;
                        const icon = getEventIcon(event.event_type, isImported);
                        const timeText = arg.timeText || '';

                        // Build custom HTML with icon
                        const html = `
                            <div class="fc-event-custom ${isImported ? 'imported' : 'interview'}">
                                <span class="fc-event-icon">${icon}</span>
                                <span class="fc-event-time">${timeText}</span>
                                <span class="fc-event-title">${arg.event.title}</span>
                            </div>
                        `;

                        return { html };
                    },
                    eventClick: (info) => {
                        const event = {
                            ...info.event.extendedProps,
                            id: parseInt(info.event.id),
                        };
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
                        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
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

            // ==========================================================================
            // CONFIRMATION MODAL HELPERS
            // ==========================================================================

            const showConfirmation = (title, message, callback) => {
                confirmAction.value = { title, message, callback };
                showConfirmModal.value = true;
            };

            const executeConfirm = () => {
                if (confirmAction.value.callback) {
                    confirmAction.value.callback();
                }
                showConfirmModal.value = false;
                confirmAction.value = { title: '', message: '', callback: null };
            };

            const cancelConfirm = () => {
                showConfirmModal.value = false;
                confirmAction.value = { title: '', message: '', callback: null };
            };

            // ==========================================================================
            // GENERIC PROVIDER METHODS
            // ==========================================================================

            const disconnectProvider = async (provider) => {
                const loadingRef = provider === 'google' ? syncLoading : outlookSyncLoading;
                loadingRef.value = true;

                try {
                    await fetch(
                        `${store.config.restUrl}calendar-sync/${provider}/disconnect`,
                        {
                            method: 'POST',
                            headers: {
                                'X-WP-Nonce': store.config.nonce,
                            },
                        }
                    );

                    await fetchSyncStatus();

                    if (provider === 'google') {
                        googleCalendars.value = [];
                    } else {
                        outlookCalendars.value = [];
                    }
                } catch (err) {
                    console.error(`Failed to disconnect ${provider}:`, err);
                } finally {
                    loadingRef.value = false;
                }
            };

            const triggerProviderSync = async (provider) => {
                const loadingRef = provider === 'google' ? syncLoading : outlookSyncLoading;
                loadingRef.value = true;

                try {
                    const response = await fetch(
                        `${store.config.restUrl}calendar-sync/${provider}/sync`,
                        {
                            method: 'POST',
                            headers: {
                                'X-WP-Nonce': store.config.nonce,
                            },
                        }
                    );

                    if (response.ok) {
                        await fetchSyncStatus();
                        // Refresh events
                        const today = new Date();
                        const start = new Date(today.getFullYear(), today.getMonth(), 1);
                        const end = new Date(today.getFullYear(), today.getMonth() + 2, 0);
                        await store.fetchEvents(
                            start.toISOString().split('T')[0],
                            end.toISOString().split('T')[0]
                        );
                    }
                } catch (err) {
                    console.error(`Failed to sync ${provider}:`, err);
                } finally {
                    loadingRef.value = false;
                }
            };

            // ==========================================================================
            // SYNC METHODS
            // ==========================================================================

            const fetchSyncStatus = async () => {
                try {
                    const response = await fetch(
                        `${store.config.restUrl}calendar-sync/status`,
                        {
                            headers: {
                                'X-WP-Nonce': store.config.nonce,
                            },
                        }
                    );

                    if (response.ok) {
                        const data = await response.json();
                        syncStatus.value = data.data;

                        // Update local Google state from server
                        if (data.data.google) {
                            selectedCalendarId.value = data.data.google.calendar_id || '';
                            syncDirection.value = data.data.google.sync_direction || 'both';
                            syncEnabled.value = data.data.google.sync_enabled ?? true;

                            // Load Google calendars if connected but no calendar selected
                            if (data.data.google.connected && !data.data.google.calendar_id) {
                                loadGoogleCalendars();
                            }
                        }

                        // Update local Outlook state from server
                        if (data.data.outlook) {
                            selectedOutlookCalendarId.value = data.data.outlook.calendar_id || '';

                            // Load Outlook calendars if connected but no calendar selected
                            if (data.data.outlook.connected && !data.data.outlook.calendar_id) {
                                loadOutlookCalendars();
                            }
                        }
                    }
                } catch (err) {
                    console.error('Failed to fetch sync status:', err);
                }
            };

            const connectGoogle = async () => {
                syncLoading.value = true;
                try {
                    const response = await fetch(
                        `${store.config.restUrl}calendar-sync/google/auth`,
                        {
                            headers: {
                                'X-WP-Nonce': store.config.nonce,
                            },
                        }
                    );

                    if (response.ok) {
                        const data = await response.json();
                        // Redirect to Google OAuth
                        window.location.href = data.data.auth_url;
                    }
                } catch (err) {
                    console.error('Failed to get auth URL:', err);
                } finally {
                    syncLoading.value = false;
                }
            };

            const disconnectGoogle = () => {
                showConfirmation(
                    'Disconnect Google Calendar',
                    'Are you sure you want to disconnect Google Calendar? Your synced events will remain but future sync will stop.',
                    () => disconnectProvider('google')
                );
            };

            const loadGoogleCalendars = async () => {
                try {
                    const response = await fetch(
                        `${store.config.restUrl}calendar-sync/google/calendars`,
                        {
                            headers: {
                                'X-WP-Nonce': store.config.nonce,
                            },
                        }
                    );

                    if (response.ok) {
                        const data = await response.json();
                        googleCalendars.value = data.data || [];
                    }
                } catch (err) {
                    console.error('Failed to load calendars:', err);
                }
            };

            const selectCalendar = async () => {
                if (!selectedCalendarId.value) return;

                const calendar = googleCalendars.value.find(c => c.id === selectedCalendarId.value);

                syncLoading.value = true;
                try {
                    await fetch(
                        `${store.config.restUrl}calendar-sync/google/select-calendar`,
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': store.config.nonce,
                            },
                            body: JSON.stringify({
                                calendar_id: selectedCalendarId.value,
                                calendar_name: calendar?.name || 'Calendar',
                            }),
                        }
                    );

                    await fetchSyncStatus();
                } catch (err) {
                    console.error('Failed to select calendar:', err);
                } finally {
                    syncLoading.value = false;
                }
            };

            const updateSyncSettings = async () => {
                try {
                    await fetch(
                        `${store.config.restUrl}calendar-sync/settings`,
                        {
                            method: 'PATCH',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': store.config.nonce,
                            },
                            body: JSON.stringify({
                                sync_enabled: syncEnabled.value,
                                sync_direction: syncDirection.value,
                            }),
                        }
                    );
                } catch (err) {
                    console.error('Failed to update settings:', err);
                }
            };

            const triggerSync = () => {
                triggerProviderSync('google');
            };

            // =====================
            // Outlook Calendar Methods
            // =====================

            const connectOutlook = async () => {
                outlookSyncLoading.value = true;
                try {
                    const response = await fetch(
                        `${store.config.restUrl}calendar-sync/outlook/auth`,
                        {
                            headers: {
                                'X-WP-Nonce': store.config.nonce,
                            },
                        }
                    );

                    if (response.ok) {
                        const data = await response.json();
                        // Redirect to Microsoft OAuth
                        window.location.href = data.data.auth_url;
                    }
                } catch (err) {
                    console.error('Failed to get Outlook auth URL:', err);
                } finally {
                    outlookSyncLoading.value = false;
                }
            };

            const disconnectOutlook = () => {
                showConfirmation(
                    'Disconnect Outlook Calendar',
                    'Are you sure you want to disconnect Outlook Calendar? Your synced events will remain but future sync will stop.',
                    () => disconnectProvider('outlook')
                );
            };

            const loadOutlookCalendars = async () => {
                try {
                    const response = await fetch(
                        `${store.config.restUrl}calendar-sync/outlook/calendars`,
                        {
                            headers: {
                                'X-WP-Nonce': store.config.nonce,
                            },
                        }
                    );

                    if (response.ok) {
                        const data = await response.json();
                        outlookCalendars.value = data.data || [];

                        // Pre-select primary calendar
                        const primary = outlookCalendars.value.find(c => c.primary);
                        if (primary) {
                            selectedOutlookCalendarId.value = primary.id;
                        }
                    }
                } catch (err) {
                    console.error('Failed to load Outlook calendars:', err);
                }
            };

            const selectOutlookCalendar = async () => {
                if (!selectedOutlookCalendarId.value) return;

                outlookSyncLoading.value = true;
                try {
                    const selectedCal = outlookCalendars.value.find(
                        c => c.id === selectedOutlookCalendarId.value
                    );

                    const response = await fetch(
                        `${store.config.restUrl}calendar-sync/outlook/select-calendar`,
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': store.config.nonce,
                            },
                            body: JSON.stringify({
                                calendar_id: selectedOutlookCalendarId.value,
                                calendar_name: selectedCal?.name || 'Calendar',
                            }),
                        }
                    );

                    if (response.ok) {
                        await fetchSyncStatus();
                    }
                } catch (err) {
                    console.error('Failed to select Outlook calendar:', err);
                } finally {
                    outlookSyncLoading.value = false;
                }
            };

            const triggerOutlookSync = () => {
                triggerProviderSync('outlook');
            };

            const formatLastSync = (datetime) => {
                if (!datetime) return 'Never';
                const date = new Date(datetime);
                const now = new Date();
                const diffMs = now - date;
                const diffMins = Math.floor(diffMs / 60000);

                if (diffMins < 1) return 'Just now';
                if (diffMins < 60) return `${diffMins} min ago`;

                const diffHours = Math.floor(diffMins / 60);
                if (diffHours < 24) return `${diffHours} hour${diffHours > 1 ? 's' : ''} ago`;

                return date.toLocaleDateString();
            };

            // ==========================================================================
            // IMPORTED EVENTS MANAGEMENT
            // ==========================================================================

            const fetchImportedCounts = async () => {
                const fetchCount = async (provider, countRef) => {
                    if (syncStatus.value[provider]?.connected) {
                        try {
                            const response = await fetch(
                                `${store.config.restUrl}calendar-sync/${provider}/imported-events/count`,
                                { headers: { 'X-WP-Nonce': store.config.nonce } }
                            );
                            if (response.ok) {
                                const data = await response.json();
                                countRef.value = data.data?.count || 0;
                            } else {
                                countRef.value = 0;
                            }
                        } catch (err) {
                            console.error(`Failed to fetch ${provider} imported count:`, err);
                            countRef.value = 0;
                        }
                    }
                };

                await Promise.all([
                    fetchCount('google', googleImportedCount),
                    fetchCount('outlook', outlookImportedCount),
                ]);
            };

            const confirmDeleteImported = (provider) => {
                const count = provider === 'google' ? googleImportedCount.value : outlookImportedCount.value;
                const providerName = provider === 'google' ? 'Google' : 'Outlook';

                showConfirmation(
                    `Delete Imported Events`,
                    `Are you sure you want to delete ${count} imported event(s) from ${providerName} Calendar? This will only remove events that were imported from ${providerName}, not your interview-linked events. This action cannot be undone.`,
                    () => deleteImportedEvents(provider)
                );
            };

            const deleteImportedEvents = async (provider) => {
                deletingImported.value = true;
                const providerName = provider === 'google' ? 'Google' : 'Outlook';

                try {
                    const response = await fetch(
                        `${store.config.restUrl}calendar-sync/${provider}/imported-events`,
                        {
                            method: 'DELETE',
                            headers: {
                                'X-WP-Nonce': store.config.nonce,
                            },
                        }
                    );

                    if (response.ok) {
                        // Update the count
                        if (provider === 'google') {
                            googleImportedCount.value = 0;
                        } else {
                            outlookImportedCount.value = 0;
                        }

                        // Refresh events in calendar
                        const today = new Date();
                        const start = new Date(today.getFullYear(), today.getMonth(), 1);
                        const end = new Date(today.getFullYear(), today.getMonth() + 2, 0);
                        await store.fetchEvents(
                            start.toISOString().split('T')[0],
                            end.toISOString().split('T')[0]
                        );

                        if (calendarInstance) {
                            calendarInstance.refetchEvents();
                        }
                    } else {
                        const errorData = await response.json().catch(() => ({}));
                        const errorMsg = errorData.message || `Failed to delete imported events from ${providerName}`;
                        alert(errorMsg);
                    }
                } catch (err) {
                    console.error('Failed to delete imported events:', err);
                    alert(`Failed to delete imported events from ${providerName}. Please try again.`);
                } finally {
                    deletingImported.value = false;
                }
            };

            // Watch for sync modal to load calendars and counts
            watch(showSyncModal, async (isOpen) => {
                if (isOpen) {
                    if (syncStatus.value.google?.connected && googleCalendars.value.length === 0) {
                        await loadGoogleCalendars();
                    }
                    // Fetch imported event counts when modal opens
                    await fetchImportedCounts();
                }
            });

            // Watch for calendar element to become available and initialize
            watch(calendarEl, (el) => {
                if (el && currentView.value === 'calendar') {
                    initCalendar();
                }
            }, { immediate: true });

            // Watch for loading to complete and update calendar size
            // This is needed because v-show keeps elements in DOM but hidden,
            // and FullCalendar can't calculate dimensions while hidden
            watch(loading, (isLoading) => {
                if (!isLoading && calendarInstance) {
                    nextTick(() => {
                        calendarInstance.updateSize();
                    });
                }
            });

            // Watch start_date to sync end_date (standard calendar UX)
            // When start date changes, update end date to match if it's before start
            watch(() => eventForm.start_date, (newStartDate, oldStartDate) => {
                if (!newStartDate) return;

                // If end date is empty or before start date, set it to start date
                if (!eventForm.end_date || eventForm.end_date < newStartDate) {
                    eventForm.end_date = newStartDate;
                }
                // If there was a duration, maintain it when shifting start date
                else if (oldStartDate && eventForm.end_date) {
                    const oldStart = new Date(oldStartDate);
                    const oldEnd = new Date(eventForm.end_date);
                    const durationMs = oldEnd - oldStart;

                    // Only maintain duration if it was positive (valid)
                    if (durationMs > 0) {
                        const newEnd = new Date(new Date(newStartDate).getTime() + durationMs);
                        eventForm.end_date = newEnd.toISOString().split('T')[0];
                    }
                }
            });

            // Lifecycle
            onMounted(() => {
                if (typeof pitCalendarData !== 'undefined') {
                    store.initConfig(pitCalendarData);
                }

                // Fetch sync status
                fetchSyncStatus();

                // Check for OAuth callback messages
                const urlParams = new URLSearchParams(window.location.search);
                if (urlParams.get('calendar_connected')) {
                    // Clean up URL
                    window.history.replaceState({}, document.title, window.location.pathname);
                }

                // Initial fetch
                const today = new Date();
                const start = new Date(today.getFullYear(), today.getMonth(), 1);
                const end = new Date(today.getFullYear(), today.getMonth() + 2, 0);
                store.fetchEvents(
                    start.toISOString().split('T')[0],
                    end.toISOString().split('T')[0]
                );
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
                fullCalendarError,

                // State
                currentView,
                filterType,
                showEventModal,
                showDetailModal,
                showDeleteModal,
                showSyncModal,
                showConfirmModal,
                confirmAction,
                isEditing,
                saving,
                deleting,
                modalError,
                eventToDelete,
                eventForm,

                // Sync state
                syncStatus,
                syncLoading,
                outlookSyncLoading,
                googleCalendars,
                outlookCalendars,
                selectedCalendarId,
                selectedOutlookCalendarId,
                syncDirection,
                syncEnabled,

                // Computed
                loading,
                error,
                events,
                selectedEvent,
                eventTypeOptions,
                groupedEvents,

                // Methods
                getEventColor,
                isImportedEvent,
                getEventIconHtml,
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

                // Confirmation modal methods
                executeConfirm,
                cancelConfirm,

                // Google sync methods
                connectGoogle,
                disconnectGoogle,
                selectCalendar,
                updateSyncSettings,
                triggerSync,
                formatLastSync,

                // Outlook sync methods
                connectOutlook,
                disconnectOutlook,
                selectOutlookCalendar,
                triggerOutlookSync,

                // Imported events management
                googleImportedCount,
                outlookImportedCount,
                deletingImported,
                confirmDeleteImported,
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
