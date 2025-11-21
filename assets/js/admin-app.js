/**
 * Podcast Influence Tracker - Vue 3 Admin App
 *
 * Progressive loading admin interface with real-time updates
 */

const { createApp } = Vue;
const { createPinia, defineStore } = Pinia;

// Main Store
const usePodcastStore = defineStore('podcasts', {
    state: () => ({
        podcasts: [],
        currentPodcast: null,
        loading: false,
        stats: null,
        costStats: null,
        pagination: {
            page: 1,
            perPage: 20,
            total: 0,
        },
        filters: {
            search: '',
            trackingStatus: '',
        },
    }),

    actions: {
        async fetchPodcasts() {
            this.loading = true;
            try {
                const params = new URLSearchParams({
                    page: this.pagination.page,
                    per_page: this.pagination.perPage,
                    search: this.filters.search,
                    tracking_status: this.filters.trackingStatus,
                });

                const response = await fetch(`${pitData.apiUrl}/podcasts?${params}`, {
                    headers: { 'X-WP-Nonce': pitData.nonce },
                });

                const data = await response.json();
                this.podcasts = data.podcasts;
                this.pagination.total = data.total;
            } catch (error) {
                console.error('Failed to fetch podcasts:', error);
            } finally {
                this.loading = false;
            }
        },

        async addPodcast(rssUrl) {
            try {
                const response = await fetch(`${pitData.apiUrl}/podcasts`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': pitData.nonce,
                    },
                    body: JSON.stringify({ rss_url: rssUrl }),
                });

                if (!response.ok) {
                    const error = await response.json();
                    throw new Error(error.message || 'Failed to add podcast');
                }

                const data = await response.json();
                await this.fetchPodcasts();
                return data;
            } catch (error) {
                throw error;
            }
        },

        async trackPodcast(podcastId, platforms = []) {
            try {
                const response = await fetch(`${pitData.apiUrl}/podcasts/${podcastId}/track`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': pitData.nonce,
                    },
                    body: JSON.stringify({ platforms }),
                });

                const data = await response.json();

                // Start polling for job status
                if (data.job_id) {
                    this.pollJobStatus(data.job_id, podcastId);
                }

                return data;
            } catch (error) {
                throw error;
            }
        },

        async pollJobStatus(jobId, podcastId) {
            const pollInterval = setInterval(async () => {
                try {
                    const response = await fetch(`${pitData.apiUrl}/jobs/${jobId}`, {
                        headers: { 'X-WP-Nonce': pitData.nonce },
                    });

                    const job = await response.json();

                    // Update podcast in list
                    const podcast = this.podcasts.find(p => p.id == podcastId);
                    if (podcast) {
                        podcast.tracking_status = job.status;
                        podcast.progress_percent = job.progress_percent;
                    }

                    // Stop polling if completed or failed
                    if (job.status === 'completed' || job.status === 'failed') {
                        clearInterval(pollInterval);
                        await this.fetchPodcasts(); // Refresh to get metrics
                    }
                } catch (error) {
                    console.error('Polling error:', error);
                    clearInterval(pollInterval);
                }
            }, 2000); // Poll every 2 seconds
        },

        async deletePodcast(podcastId) {
            try {
                await fetch(`${pitData.apiUrl}/podcasts/${podcastId}`, {
                    method: 'DELETE',
                    headers: { 'X-WP-Nonce': pitData.nonce },
                });

                await this.fetchPodcasts();
            } catch (error) {
                throw error;
            }
        },

        async fetchStats() {
            try {
                const response = await fetch(`${pitData.apiUrl}/stats/overview`, {
                    headers: { 'X-WP-Nonce': pitData.nonce },
                });

                this.stats = await response.json();
            } catch (error) {
                console.error('Failed to fetch stats:', error);
            }
        },

        async fetchCostStats() {
            try {
                const response = await fetch(`${pitData.apiUrl}/stats/costs`, {
                    headers: { 'X-WP-Nonce': pitData.nonce },
                });

                this.costStats = await response.json();
            } catch (error) {
                console.error('Failed to fetch cost stats:', error);
            }
        },
    },
});

// Dashboard Component
const Dashboard = {
    template: `
        <div class="pit-dashboard">
            <div class="pit-stats-grid">
                <div class="pit-stat-card">
                    <h3>Total Podcasts</h3>
                    <div class="stat-value">{{ stats?.discovery?.total_podcasts || 0 }}</div>
                </div>
                <div class="pit-stat-card">
                    <h3>Tracked Podcasts</h3>
                    <div class="stat-value">{{ stats?.discovery?.podcasts_with_links || 0 }}</div>
                </div>
                <div class="pit-stat-card">
                    <h3>This Week's Cost</h3>
                    <div class="stat-value">\${{ costStats?.this_week?.toFixed(2) || '0.00' }}</div>
                </div>
                <div class="pit-stat-card">
                    <h3>This Month's Cost</h3>
                    <div class="stat-value">\${{ costStats?.this_month?.toFixed(2) || '0.00' }}</div>
                </div>
            </div>

            <div class="pit-quick-actions">
                <h2>Quick Actions</h2>
                <button @click="showAddModal = true" class="button button-primary">Add New Podcast</button>
            </div>

            <add-podcast-modal v-if="showAddModal" @close="showAddModal = false"></add-podcast-modal>
        </div>
    `,
    data() {
        return {
            showAddModal: false,
        };
    },
    computed: {
        stats() {
            return this.store.stats;
        },
        costStats() {
            return this.store.costStats;
        },
    },
    setup() {
        const store = usePodcastStore();
        return { store };
    },
    mounted() {
        this.store.fetchStats();
        this.store.fetchCostStats();
    },
};

// Podcasts List Component
const PodcastsList = {
    template: `
        <div class="pit-podcasts">
            <div class="pit-toolbar">
                <input
                    type="text"
                    v-model="filters.search"
                    @input="onSearchChange"
                    placeholder="Search podcasts..."
                    class="search-input"
                />
                <button @click="showAddModal = true" class="button button-primary">Add Podcast</button>
            </div>

            <div v-if="loading" class="pit-loading">Loading podcasts...</div>

            <table class="wp-list-table widefat fixed striped" v-else>
                <thead>
                    <tr>
                        <th>Podcast Name</th>
                        <th>Social Links</th>
                        <th>Status</th>
                        <th>Metrics</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="podcast in podcasts" :key="podcast.id">
                        <td>
                            <strong>{{ podcast.podcast_name }}</strong><br>
                            <small>{{ podcast.author }}</small>
                        </td>
                        <td>
                            <div class="social-icons">
                                <span v-for="link in podcast.social_links" :key="link.id" :title="link.platform">
                                    {{ getPlatformIcon(link.platform) }}
                                </span>
                            </div>
                            <small>{{ podcast.social_links_count }} platforms</small>
                        </td>
                        <td>
                            <span :class="'status-badge status-' + podcast.tracking_status">
                                {{ podcast.tracking_status }}
                            </span>
                            <div v-if="podcast.tracking_status === 'processing'" class="progress-bar">
                                <div class="progress-fill" :style="{width: podcast.progress_percent + '%'}"></div>
                            </div>
                        </td>
                        <td>
                            <div v-if="podcast.metrics && podcast.metrics.length > 0">
                                <div v-for="metric in podcast.metrics" :key="metric.id" class="metric-summary">
                                    {{ metric.platform }}: {{ formatNumber(metric.followers_count) }} followers
                                </div>
                            </div>
                            <small v-else>No metrics yet</small>
                        </td>
                        <td>
                            <button
                                v-if="!podcast.is_tracked"
                                @click="trackPodcast(podcast.id)"
                                class="button button-small"
                            >
                                Track
                            </button>
                            <button
                                @click="deletePodcast(podcast.id)"
                                class="button button-small button-link-delete"
                            >
                                Delete
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <add-podcast-modal v-if="showAddModal" @close="showAddModal = false"></add-podcast-modal>
        </div>
    `,
    data() {
        return {
            showAddModal: false,
        };
    },
    computed: {
        podcasts() {
            return this.store.podcasts;
        },
        loading() {
            return this.store.loading;
        },
        filters() {
            return this.store.filters;
        },
    },
    setup() {
        const store = usePodcastStore();
        return { store };
    },
    methods: {
        onSearchChange() {
            clearTimeout(this.searchTimeout);
            this.searchTimeout = setTimeout(() => {
                this.store.fetchPodcasts();
            }, 500);
        },
        trackPodcast(podcastId) {
            if (confirm('Start tracking metrics for this podcast? This will incur API costs.')) {
                this.store.trackPodcast(podcastId);
            }
        },
        deletePodcast(podcastId) {
            if (confirm('Delete this podcast?')) {
                this.store.deletePodcast(podcastId);
            }
        },
        getPlatformIcon(platform) {
            const icons = {
                twitter: '🐦',
                instagram: '📷',
                facebook: '👍',
                youtube: '▶️',
                linkedin: '💼',
                tiktok: '🎵',
                spotify: '🎧',
                apple_podcasts: '🎙️',
            };
            return icons[platform] || '🔗';
        },
        formatNumber(num) {
            if (num >= 1000000) {
                return (num / 1000000).toFixed(1) + 'M';
            } else if (num >= 1000) {
                return (num / 1000).toFixed(1) + 'K';
            }
            return num;
        },
    },
    mounted() {
        this.store.fetchPodcasts();
    },
};

// Add Podcast Modal Component
const AddPodcastModal = {
    template: `
        <div class="pit-modal-overlay" @click="close">
            <div class="pit-modal" @click.stop>
                <h2>Add New Podcast</h2>
                <div class="modal-content">
                    <label>RSS Feed URL</label>
                    <input
                        type="url"
                        v-model="rssUrl"
                        placeholder="https://example.com/feed.xml"
                        class="widefat"
                    />
                    <div v-if="error" class="error-message">{{ error }}</div>
                    <div v-if="success" class="success-message">
                        Podcast added! Found {{ success.social_links_found }} social links.
                    </div>
                </div>
                <div class="modal-actions">
                    <button @click="close" class="button">Cancel</button>
                    <button @click="addPodcast" class="button button-primary" :disabled="loading">
                        {{ loading ? 'Adding...' : 'Add Podcast' }}
                    </button>
                </div>
            </div>
        </div>
    `,
    data() {
        return {
            rssUrl: '',
            loading: false,
            error: null,
            success: null,
        };
    },
    setup() {
        const store = usePodcastStore();
        return { store };
    },
    methods: {
        async addPodcast() {
            if (!this.rssUrl) {
                this.error = 'Please enter an RSS URL';
                return;
            }

            this.loading = true;
            this.error = null;
            this.success = null;

            try {
                const result = await this.store.addPodcast(this.rssUrl);
                this.success = result;

                setTimeout(() => {
                    this.close();
                }, 2000);
            } catch (error) {
                this.error = error.message;
            } finally {
                this.loading = false;
            }
        },
        close() {
            this.$emit('close');
        },
    },
};

// Initialize Vue apps for each admin page
document.addEventListener('DOMContentLoaded', function() {
    const pinia = createPinia();

    // Dashboard
    if (document.getElementById('pit-app-dashboard')) {
        const app = createApp(Dashboard);
        app.use(pinia);
        app.component('add-podcast-modal', AddPodcastModal);
        app.mount('#pit-app-dashboard');
    }

    // Podcasts List
    if (document.getElementById('pit-app-podcasts')) {
        const app = createApp(PodcastsList);
        app.use(pinia);
        app.component('add-podcast-modal', AddPodcastModal);
        app.mount('#pit-app-podcasts');
    }

    // Analytics (placeholder)
    if (document.getElementById('pit-app-analytics')) {
        const app = createApp({
            template: '<div><h2>Analytics Dashboard Coming Soon</h2></div>',
        });
        app.use(pinia);
        app.mount('#pit-app-analytics');
    }

    // Settings (placeholder)
    if (document.getElementById('pit-app-settings')) {
        const app = createApp({
            template: '<div><h2>Settings Page Coming Soon</h2></div>',
        });
        app.use(pinia);
        app.mount('#pit-app-settings');
    }
});
