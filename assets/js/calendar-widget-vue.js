/**
 * Calendar Widget Vue.js Application
 *
 * Compact dashboard widget showing upcoming events.
 * Features:
 * - Shows next 5 upcoming events
 * - Mini month calendar (optional)
 * - Quick navigation to full calendar
 *
 * @package Podcast_Influence_Tracker
 * @since 3.3.0
 */

(function () {
    'use strict';

    const { createApp, ref, reactive, computed, onMounted } = Vue;
    const { createPinia, defineStore } = Pinia;

    // ==========================================================================
    // EVENT TYPE COLORS
    // ==========================================================================
    const eventTypeColors = {
        recording: { bg: '#3b82f6', dot: '#3b82f6' },
        air_date: { bg: '#10b981', dot: '#10b981' },
        prep_call: { bg: '#8b5cf6', dot: '#8b5cf6' },
        follow_up: { bg: '#f59e0b', dot: '#f59e0b' },
        promotion: { bg: '#ec4899', dot: '#ec4899' },
        deadline: { bg: '#ef4444', dot: '#ef4444' },
        podrec: { bg: '#06b6d4', dot: '#06b6d4' },
        other: { bg: '#6b7280', dot: '#6b7280' },
    };

    // ==========================================================================
    // PINIA STORE
    // ==========================================================================
    const useWidgetStore = defineStore('calendarWidget', {
        state: () => ({
            events: [],
            loading: true,
            error: null,
            config: {
                restUrl: '',
                nonce: '',
                eventTypes: {},
            },
        }),

        getters: {
            upcomingEvents: (state) => {
                const now = new Date();
                now.setHours(0, 0, 0, 0);

                return state.events
                    .filter(e => new Date(e.start_datetime) >= now)
                    .sort((a, b) => new Date(a.start_datetime) - new Date(b.start_datetime))
                    .slice(0, 5);
            },

            todayEvents: (state) => {
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                const tomorrow = new Date(today);
                tomorrow.setDate(tomorrow.getDate() + 1);

                return state.events.filter(e => {
                    const eventDate = new Date(e.start_datetime);
                    return eventDate >= today && eventDate < tomorrow;
                });
            },

            thisWeekEvents: (state) => {
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                const weekEnd = new Date(today);
                weekEnd.setDate(weekEnd.getDate() + 7);

                return state.events.filter(e => {
                    const eventDate = new Date(e.start_datetime);
                    return eventDate >= today && eventDate < weekEnd;
                });
            },
        },

        actions: {
            initConfig(data) {
                this.config = { ...this.config, ...data };
            },

            async fetchEvents() {
                this.loading = true;
                this.error = null;

                try {
                    // Fetch events for the next 30 days
                    const today = new Date();
                    const endDate = new Date();
                    endDate.setDate(endDate.getDate() + 30);

                    const params = new URLSearchParams({
                        start_date: today.toISOString().split('T')[0],
                        end_date: endDate.toISOString().split('T')[0],
                        per_page: '20',
                        orderby: 'start_datetime',
                        order: 'ASC',
                    });

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
        },
    });

    // ==========================================================================
    // WIDGET COMPONENT
    // ==========================================================================
    const CalendarWidget = {
        template: `
            <div class="calendar-widget">
                <!-- Widget Header -->
                <div class="widget-header">
                    <h3 class="widget-title">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                            <line x1="16" y1="2" x2="16" y2="6"></line>
                            <line x1="8" y1="2" x2="8" y2="6"></line>
                            <line x1="3" y1="10" x2="21" y2="10"></line>
                        </svg>
                        Upcoming Events
                    </h3>
                    <a href="/app/calendar/" class="widget-link">View All</a>
                </div>

                <!-- Loading State -->
                <div v-if="loading" class="widget-loading">
                    <div class="widget-spinner"></div>
                </div>

                <!-- Error State -->
                <div v-else-if="error" class="widget-error">
                    <p>{{ error }}</p>
                    <button @click="refresh" class="widget-retry">Retry</button>
                </div>

                <!-- Empty State -->
                <div v-else-if="upcomingEvents.length === 0" class="widget-empty">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                    <p>No upcoming events</p>
                    <a href="/app/calendar/" class="widget-add-link">Add Event</a>
                </div>

                <!-- Events List -->
                <div v-else class="widget-events">
                    <div
                        v-for="event in upcomingEvents"
                        :key="event.id"
                        class="widget-event"
                        @click="goToEvent(event)">
                        <div class="event-dot" :style="{ backgroundColor: getEventColor(event.event_type) }"></div>
                        <div class="event-content">
                            <div class="event-title">{{ event.title }}</div>
                            <div class="event-datetime">
                                <span class="event-date">{{ formatDate(event.start_datetime) }}</span>
                                <span v-if="!event.is_all_day" class="event-time">{{ formatTime(event.start_datetime) }}</span>
                                <span v-else class="event-allday">All Day</span>
                            </div>
                        </div>
                        <div class="event-type-indicator" :title="event.event_type_label">
                            <span class="type-badge" :style="{ backgroundColor: getEventColor(event.event_type) }">
                                {{ getEventTypeShort(event.event_type) }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="widget-stats" v-if="!loading && !error">
                    <div class="stat-item">
                        <span class="stat-value">{{ todayEvents.length }}</span>
                        <span class="stat-label">Today</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-value">{{ thisWeekEvents.length }}</span>
                        <span class="stat-label">This Week</span>
                    </div>
                </div>
            </div>
        `,

        setup() {
            const store = useWidgetStore();

            // Computed
            const loading = computed(() => store.loading);
            const error = computed(() => store.error);
            const upcomingEvents = computed(() => store.upcomingEvents);
            const todayEvents = computed(() => store.todayEvents);
            const thisWeekEvents = computed(() => store.thisWeekEvents);

            // Methods
            const getEventColor = (type) => {
                return eventTypeColors[type]?.dot || eventTypeColors.other.dot;
            };

            const getEventTypeShort = (type) => {
                const shortLabels = {
                    recording: 'REC',
                    air_date: 'AIR',
                    prep_call: 'PREP',
                    follow_up: 'FUP',
                    promotion: 'PROMO',
                    deadline: 'DL',
                    podrec: 'POD',
                    other: 'OTH',
                };
                return shortLabels[type] || 'OTH';
            };

            const formatDate = (datetime) => {
                const date = new Date(datetime);
                const today = new Date();
                today.setHours(0, 0, 0, 0);

                const tomorrow = new Date(today);
                tomorrow.setDate(tomorrow.getDate() + 1);

                const eventDate = new Date(date);
                eventDate.setHours(0, 0, 0, 0);

                if (eventDate.getTime() === today.getTime()) {
                    return 'Today';
                } else if (eventDate.getTime() === tomorrow.getTime()) {
                    return 'Tomorrow';
                }

                return date.toLocaleDateString('en-US', {
                    month: 'short',
                    day: 'numeric',
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

            const goToEvent = (event) => {
                if (event.appearance_id) {
                    window.location.href = `/app/interview/detail/?id=${event.appearance_id}`;
                } else {
                    window.location.href = '/app/calendar/';
                }
            };

            const refresh = () => {
                store.fetchEvents();
            };

            // Lifecycle
            onMounted(() => {
                if (typeof pitCalendarData !== 'undefined') {
                    store.initConfig(pitCalendarData);
                }
                store.fetchEvents();
            });

            return {
                loading,
                error,
                upcomingEvents,
                todayEvents,
                thisWeekEvents,
                getEventColor,
                getEventTypeShort,
                formatDate,
                formatTime,
                goToEvent,
                refresh,
            };
        },
    };

    // ==========================================================================
    // INITIALIZATION
    // ==========================================================================
    document.addEventListener('DOMContentLoaded', function () {
        const container = document.getElementById('calendar-widget-app');
        if (!container) return;

        const pinia = createPinia();
        const app = createApp(CalendarWidget);
        app.use(pinia);
        app.mount('#calendar-widget-app');
    });
})();
