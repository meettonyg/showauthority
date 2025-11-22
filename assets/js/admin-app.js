/**
 * Podcast Influence Tracker - Vue 3 Admin App
 *
 * Progressive loading admin interface with real-time updates
 */

const { createApp } = Vue;
const { createPinia, defineStore } = Pinia;

// Guest Store (Guest Intelligence)
const useGuestStore = defineStore('guests', {
    state: () => ({
        guests: [],
        currentGuest: null,
        loading: false,
        pagination: {
            page: 1,
            perPage: 20,
            total: 0,
        },
        filters: {
            search: '',
            verified: '',
            topic: '',
            company_stage: '',
        },
        topics: [],
        duplicates: [],
    }),

    actions: {
        async fetchGuests() {
            this.loading = true;
            try {
                const params = new URLSearchParams({
                    page: this.pagination.page,
                    per_page: this.pagination.perPage,
                    search: this.filters.search,
                    verified: this.filters.verified,
                    topic: this.filters.topic,
                    company_stage: this.filters.company_stage,
                });

                const response = await fetch(`${pitData.apiUrl}/guests?${params}`, {
                    headers: { 'X-WP-Nonce': pitData.nonce },
                });

                const data = await response.json();
                this.guests = data.guests || [];
                this.pagination.total = data.total || 0;
            } catch (error) {
                console.error('Failed to fetch guests:', error);
            } finally {
                this.loading = false;
            }
        },

        async fetchGuest(id) {
            try {
                const response = await fetch(`${pitData.apiUrl}/guests/${id}`, {
                    headers: { 'X-WP-Nonce': pitData.nonce },
                });
                this.currentGuest = await response.json();
                return this.currentGuest;
            } catch (error) {
                console.error('Failed to fetch guest:', error);
                throw error;
            }
        },

        async createGuest(guestData) {
            try {
                const response = await fetch(`${pitData.apiUrl}/guests`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': pitData.nonce,
                    },
                    body: JSON.stringify(guestData),
                });

                if (!response.ok) {
                    const error = await response.json();
                    throw new Error(error.message || 'Failed to create guest');
                }

                const data = await response.json();
                await this.fetchGuests();
                return data;
            } catch (error) {
                throw error;
            }
        },

        async updateGuest(id, guestData) {
            try {
                const response = await fetch(`${pitData.apiUrl}/guests/${id}`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': pitData.nonce,
                    },
                    body: JSON.stringify(guestData),
                });

                if (!response.ok) {
                    const error = await response.json();
                    throw new Error(error.message || 'Failed to update guest');
                }

                const data = await response.json();
                await this.fetchGuests();
                return data;
            } catch (error) {
                throw error;
            }
        },

        async deleteGuest(id) {
            try {
                await fetch(`${pitData.apiUrl}/guests/${id}`, {
                    method: 'DELETE',
                    headers: { 'X-WP-Nonce': pitData.nonce },
                });
                await this.fetchGuests();
            } catch (error) {
                throw error;
            }
        },

        async verifyGuest(id, status, feedback = '') {
            try {
                const response = await fetch(`${pitData.apiUrl}/guests/${id}/verify`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': pitData.nonce,
                    },
                    body: JSON.stringify({ status, feedback }),
                });

                if (!response.ok) {
                    const error = await response.json();
                    throw new Error(error.message || 'Failed to verify guest');
                }

                return await response.json();
            } catch (error) {
                throw error;
            }
        },

        async fetchTopics() {
            try {
                const response = await fetch(`${pitData.apiUrl}/topics`, {
                    headers: { 'X-WP-Nonce': pitData.nonce },
                });
                this.topics = await response.json();
            } catch (error) {
                console.error('Failed to fetch topics:', error);
            }
        },

        async fetchGuestAppearances(guestId) {
            try {
                const response = await fetch(`${pitData.apiUrl}/guests/${guestId}/appearances`, {
                    headers: { 'X-WP-Nonce': pitData.nonce },
                });
                return await response.json();
            } catch (error) {
                console.error('Failed to fetch appearances:', error);
                return [];
            }
        },

        async addGuestAppearance(guestId, appearanceData) {
            try {
                const response = await fetch(`${pitData.apiUrl}/guests/${guestId}/appearances`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': pitData.nonce,
                    },
                    body: JSON.stringify(appearanceData),
                });

                if (!response.ok) {
                    const error = await response.json();
                    throw new Error(error.message || 'Failed to add appearance');
                }

                return await response.json();
            } catch (error) {
                throw error;
            }
        },

        async fetchDuplicates() {
            try {
                const response = await fetch(`${pitData.apiUrl}/guests/duplicates`, {
                    headers: { 'X-WP-Nonce': pitData.nonce },
                });
                this.duplicates = await response.json();
            } catch (error) {
                console.error('Failed to fetch duplicates:', error);
            }
        },

        async mergeGuests(sourceId, targetId) {
            try {
                const response = await fetch(`${pitData.apiUrl}/guests/merge`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': pitData.nonce,
                    },
                    body: JSON.stringify({ source_id: sourceId, target_id: targetId }),
                });

                if (!response.ok) {
                    const error = await response.json();
                    throw new Error(error.message || 'Failed to merge guests');
                }

                await this.fetchGuests();
                await this.fetchDuplicates();
                return await response.json();
            } catch (error) {
                throw error;
            }
        },

        async fetchGuestNetwork(guestId) {
            try {
                const response = await fetch(`${pitData.apiUrl}/guests/${guestId}/network`, {
                    headers: { 'X-WP-Nonce': pitData.nonce },
                });
                return await response.json();
            } catch (error) {
                console.error('Failed to fetch network:', error);
                return { first_degree: [], second_degree: [] };
            }
        },
    },
});

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

// Analytics Component
const Analytics = {
    template: `
        <div class="pit-analytics">
            <div class="pit-stats-grid">
                <div class="pit-stat-card">
                    <h3>This Week</h3>
                    <div class="stat-value">\${{ costStats?.this_week?.toFixed(2) || '0.00' }}</div>
                    <small>API costs</small>
                </div>
                <div class="pit-stat-card">
                    <h3>This Month</h3>
                    <div class="stat-value">\${{ costStats?.this_month?.toFixed(2) || '0.00' }}</div>
                    <small>API costs</small>
                </div>
                <div class="pit-stat-card">
                    <h3>Budget Status</h3>
                    <div class="stat-value" :class="'budget-' + (budgetStatus?.status || 'healthy')">
                        {{ budgetStatus?.status || 'Healthy' }}
                    </div>
                    <small>{{ budgetStatus?.remaining || 'N/A' }} remaining</small>
                </div>
                <div class="pit-stat-card">
                    <h3>Efficiency</h3>
                    <div class="stat-value">{{ efficiency }}%</div>
                    <small>Successful requests</small>
                </div>
            </div>

            <div class="analytics-grid">
                <div class="analytics-card">
                    <h3>Cost by Platform</h3>
                    <table class="widefat striped">
                        <thead>
                            <tr><th>Platform</th><th>Cost</th><th>Requests</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="(data, platform) in platformCosts" :key="platform">
                                <td>{{ platform }}</td>
                                <td>\${{ data.cost?.toFixed(4) || '0.00' }}</td>
                                <td>{{ data.count || 0 }}</td>
                            </tr>
                            <tr v-if="Object.keys(platformCosts).length === 0">
                                <td colspan="3" style="text-align:center">No cost data yet</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="analytics-card">
                    <h3>Recent Activity</h3>
                    <table class="widefat striped">
                        <thead>
                            <tr><th>Date</th><th>Action</th><th>Platform</th><th>Cost</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="activity in recentActivity" :key="activity.id">
                                <td>{{ formatDate(activity.logged_at) }}</td>
                                <td>{{ activity.action_type }}</td>
                                <td>{{ activity.platform || '-' }}</td>
                                <td>\${{ parseFloat(activity.cost_usd).toFixed(4) }}</td>
                            </tr>
                            <tr v-if="recentActivity.length === 0">
                                <td colspan="4" style="text-align:center">No recent activity</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="analytics-grid" style="margin-top:20px">
                <div class="analytics-card">
                    <h3>Discovery Stats</h3>
                    <ul style="list-style:none;padding:0;margin:0">
                        <li style="padding:8px 0;border-bottom:1px solid #eee">
                            <strong>Total Podcasts:</strong> {{ stats?.discovery?.total_podcasts || 0 }}
                        </li>
                        <li style="padding:8px 0;border-bottom:1px solid #eee">
                            <strong>With Social Links:</strong> {{ stats?.discovery?.podcasts_with_links || 0 }}
                        </li>
                        <li style="padding:8px 0;border-bottom:1px solid #eee">
                            <strong>Total Social Links:</strong> {{ stats?.discovery?.total_social_links || 0 }}
                        </li>
                        <li style="padding:8px 0">
                            <strong>Links per Podcast:</strong> {{ stats?.discovery?.avg_links_per_podcast || 0 }}
                        </li>
                    </ul>
                </div>

                <div class="analytics-card">
                    <h3>Enrichment Stats</h3>
                    <ul style="list-style:none;padding:0;margin:0">
                        <li style="padding:8px 0;border-bottom:1px solid #eee">
                            <strong>Tracked Podcasts:</strong> {{ stats?.enrichment?.tracked_podcasts || 0 }}
                        </li>
                        <li style="padding:8px 0;border-bottom:1px solid #eee">
                            <strong>Total Metrics:</strong> {{ stats?.enrichment?.total_metrics || 0 }}
                        </li>
                        <li style="padding:8px 0;border-bottom:1px solid #eee">
                            <strong>Jobs Queued:</strong> {{ stats?.jobs?.queued || 0 }}
                        </li>
                        <li style="padding:8px 0">
                            <strong>Jobs Completed:</strong> {{ stats?.jobs?.completed || 0 }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    `,
    data() {
        return {
            costStats: null,
            stats: null,
            budgetStatus: null,
            platformCosts: {},
            recentActivity: [],
        };
    },
    computed: {
        efficiency() {
            if (!this.stats?.jobs) return 100;
            const total = (this.stats.jobs.completed || 0) + (this.stats.jobs.failed || 0);
            if (total === 0) return 100;
            return Math.round((this.stats.jobs.completed / total) * 100);
        },
    },
    methods: {
        formatDate(dateStr) {
            const date = new Date(dateStr);
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        },
        async fetchData() {
            try {
                // Fetch stats
                const statsRes = await fetch(`${pitData.apiUrl}/stats/overview`, {
                    headers: { 'X-WP-Nonce': pitData.nonce },
                });
                this.stats = await statsRes.json();

                // Fetch cost stats
                const costRes = await fetch(`${pitData.apiUrl}/stats/costs`, {
                    headers: { 'X-WP-Nonce': pitData.nonce },
                });
                const costData = await costRes.json();
                this.costStats = costData;
                this.budgetStatus = costData.budget_status;
                this.platformCosts = costData.by_platform || {};
                this.recentActivity = costData.recent || [];
            } catch (error) {
                console.error('Failed to fetch analytics:', error);
            }
        },
    },
    mounted() {
        this.fetchData();
    },
};

// Settings Component
const Settings = {
    template: `
        <div class="pit-settings">
            <form @submit.prevent="saveSettings">
                <div class="settings-section">
                    <h3>API Configuration</h3>

                    <div class="setting-row">
                        <label for="youtube_api_key">YouTube API Key</label>
                        <input type="text" id="youtube_api_key" v-model="settings.youtube_api_key"
                            placeholder="AIza..." class="regular-text">
                        <span class="description">Free tier: 10,000 quota units/day (~98 channels)</span>
                    </div>

                    <div class="setting-row">
                        <label for="apify_api_token">Apify API Token</label>
                        <input type="text" id="apify_api_token" v-model="settings.apify_api_token"
                            placeholder="apify_api_..." class="regular-text">
                        <span class="description">Required for Twitter, Instagram, Facebook, LinkedIn, TikTok</span>
                    </div>
                </div>

                <div class="settings-section">
                    <h3>Budget Limits</h3>

                    <div class="setting-row">
                        <label for="weekly_budget">Weekly Budget (USD)</label>
                        <input type="number" id="weekly_budget" v-model="settings.weekly_budget"
                            min="0" step="0.01" class="small-text">
                        <span class="description">Maximum spend per week. Processing stops when exceeded.</span>
                    </div>

                    <div class="setting-row">
                        <label for="monthly_budget">Monthly Budget (USD)</label>
                        <input type="number" id="monthly_budget" v-model="settings.monthly_budget"
                            min="0" step="0.01" class="small-text">
                        <span class="description">Maximum spend per month. Processing stops when exceeded.</span>
                    </div>
                </div>

                <div class="settings-section">
                    <h3>Tracking Configuration</h3>

                    <div class="setting-row">
                        <label for="cache_duration">Cache Duration (days)</label>
                        <input type="number" id="cache_duration" v-model="settings.cache_duration"
                            min="1" max="30" class="small-text">
                        <span class="description">How long to cache metrics before refreshing (default: 7)</span>
                    </div>

                    <div class="setting-row">
                        <label for="auto_refresh">
                            <input type="checkbox" id="auto_refresh" v-model="settings.auto_refresh">
                            Enable automatic weekly refresh
                        </label>
                        <span class="description">Automatically refresh tracked podcasts every week</span>
                    </div>

                    <div class="setting-row">
                        <label for="default_platforms">Default Platforms to Track</label>
                        <div style="display:flex;flex-wrap:wrap;gap:15px;margin-top:5px">
                            <label v-for="platform in availablePlatforms" :key="platform" style="font-weight:normal">
                                <input type="checkbox" :value="platform" v-model="settings.default_platforms">
                                {{ formatPlatform(platform) }}
                            </label>
                        </div>
                    </div>
                </div>

                <div class="settings-section">
                    <h3>Formidable Forms Integration</h3>

                    <div class="setting-row">
                        <label for="tracker_form_id">Interview Tracker Form ID</label>
                        <input type="number" id="tracker_form_id" v-model="settings.tracker_form_id"
                            min="0" class="small-text">
                        <span class="description">The Formidable form ID for the Interview Tracker</span>
                    </div>

                    <div class="setting-row">
                        <label for="rss_field_id">RSS Feed Field ID</label>
                        <input type="number" id="rss_field_id" v-model="settings.rss_field_id"
                            min="0" class="small-text">
                        <span class="description">Field ID that contains the RSS feed URL</span>
                    </div>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary" :disabled="saving">
                        {{ saving ? 'Saving...' : 'Save Settings' }}
                    </button>
                    <span v-if="saved" class="success-message" style="margin-left:10px">Settings saved!</span>
                    <span v-if="error" class="error-message" style="margin-left:10px">{{ error }}</span>
                </p>
            </form>
        </div>
    `,
    data() {
        return {
            settings: {
                youtube_api_key: '',
                apify_api_token: '',
                weekly_budget: 50,
                monthly_budget: 200,
                cache_duration: 7,
                auto_refresh: true,
                default_platforms: ['youtube', 'twitter', 'instagram'],
                tracker_form_id: '',
                rss_field_id: '',
            },
            availablePlatforms: ['youtube', 'twitter', 'instagram', 'facebook', 'linkedin', 'tiktok', 'spotify', 'apple_podcasts'],
            saving: false,
            saved: false,
            error: null,
        };
    },
    methods: {
        formatPlatform(platform) {
            const names = {
                youtube: 'YouTube',
                twitter: 'Twitter/X',
                instagram: 'Instagram',
                facebook: 'Facebook',
                linkedin: 'LinkedIn',
                tiktok: 'TikTok',
                spotify: 'Spotify',
                apple_podcasts: 'Apple Podcasts',
            };
            return names[platform] || platform;
        },
        async fetchSettings() {
            try {
                const response = await fetch(`${pitData.apiUrl}/settings`, {
                    headers: { 'X-WP-Nonce': pitData.nonce },
                });
                const data = await response.json();
                if (data) {
                    this.settings = { ...this.settings, ...data };
                }
            } catch (error) {
                console.error('Failed to fetch settings:', error);
            }
        },
        async saveSettings() {
            this.saving = true;
            this.saved = false;
            this.error = null;

            try {
                const response = await fetch(`${pitData.apiUrl}/settings`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': pitData.nonce,
                    },
                    body: JSON.stringify(this.settings),
                });

                if (!response.ok) {
                    const data = await response.json();
                    throw new Error(data.message || 'Failed to save');
                }

                this.saved = true;
                setTimeout(() => { this.saved = false; }, 3000);
            } catch (error) {
                this.error = error.message;
            } finally {
                this.saving = false;
            }
        },
    },
    mounted() {
        // Load initial settings from pitData if available
        if (pitData.settings) {
            this.settings = { ...this.settings, ...pitData.settings };
        }
        this.fetchSettings();
    },
};

// Guest Card Component
const GuestCard = {
    template: `
        <div class="pit-guest-card" :class="{ 'verified': guest.manually_verified }">
            <div class="guest-header">
                <div class="guest-avatar">{{ getInitials(guest.full_name) }}</div>
                <div class="guest-info">
                    <h4>{{ guest.full_name }}</h4>
                    <p class="guest-title">{{ guest.current_role }} at {{ guest.current_company }}</p>
                </div>
                <span v-if="guest.manually_verified" class="verified-badge" title="Verified">&#10003;</span>
            </div>
            <div class="guest-details">
                <div v-if="guest.company_stage" class="detail-item">
                    <span class="detail-label">Stage:</span>
                    <span class="detail-value">{{ guest.company_stage }}</span>
                </div>
                <div v-if="guest.industry" class="detail-item">
                    <span class="detail-label">Industry:</span>
                    <span class="detail-value">{{ guest.industry }}</span>
                </div>
                <div v-if="guest.appearances_count" class="detail-item">
                    <span class="detail-label">Appearances:</span>
                    <span class="detail-value">{{ guest.appearances_count }}</span>
                </div>
            </div>
            <div class="guest-links">
                <a v-if="guest.linkedin_url" :href="guest.linkedin_url" target="_blank" class="social-link linkedin">LinkedIn</a>
                <a v-if="guest.twitter_handle" :href="'https://twitter.com/' + guest.twitter_handle" target="_blank" class="social-link twitter">Twitter</a>
                <a v-if="guest.email" :href="'mailto:' + guest.email" class="social-link email">Email</a>
            </div>
            <div class="guest-actions">
                <button @click="$emit('view', guest)" class="button button-small">View</button>
                <button @click="$emit('edit', guest)" class="button button-small">Edit</button>
                <button @click="$emit('delete', guest)" class="button button-small button-link-delete">Delete</button>
            </div>
        </div>
    `,
    props: ['guest'],
    emits: ['view', 'edit', 'delete'],
    methods: {
        getInitials(name) {
            if (!name) return '?';
            return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2);
        },
    },
};

// Add Guest Modal Component
const AddGuestModal = {
    template: `
        <div class="pit-modal-overlay" @click="close">
            <div class="pit-modal pit-modal-large" @click.stop>
                <h2>{{ editMode ? 'Edit Guest' : 'Add New Guest' }}</h2>
                <div class="modal-content">
                    <div class="form-section">
                        <h3>Basic Information</h3>
                        <div class="form-row">
                            <div class="form-field">
                                <label for="full_name">Full Name *</label>
                                <input type="text" id="full_name" v-model="form.full_name" required class="widefat">
                            </div>
                        </div>
                        <div class="form-row form-row-2">
                            <div class="form-field">
                                <label for="first_name">First Name</label>
                                <input type="text" id="first_name" v-model="form.first_name" class="widefat">
                            </div>
                            <div class="form-field">
                                <label for="last_name">Last Name</label>
                                <input type="text" id="last_name" v-model="form.last_name" class="widefat">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>Professional Details</h3>
                        <div class="form-row form-row-2">
                            <div class="form-field">
                                <label for="current_company">Current Company</label>
                                <input type="text" id="current_company" v-model="form.current_company" class="widefat">
                            </div>
                            <div class="form-field">
                                <label for="current_role">Current Role</label>
                                <input type="text" id="current_role" v-model="form.current_role" class="widefat">
                            </div>
                        </div>
                        <div class="form-row form-row-3">
                            <div class="form-field">
                                <label for="company_stage">Company Stage</label>
                                <select id="company_stage" v-model="form.company_stage" class="widefat">
                                    <option value="">Select...</option>
                                    <option value="pre-seed">Pre-Seed</option>
                                    <option value="seed">Seed</option>
                                    <option value="series-a">Series A</option>
                                    <option value="series-b">Series B</option>
                                    <option value="series-c+">Series C+</option>
                                    <option value="scaleup">Scaleup</option>
                                    <option value="public">Public</option>
                                    <option value="bootstrapped">Bootstrapped</option>
                                    <option value="post-exit">Post-Exit</option>
                                </select>
                            </div>
                            <div class="form-field">
                                <label for="company_revenue">Company Revenue</label>
                                <input type="text" id="company_revenue" v-model="form.company_revenue" placeholder="e.g., $10M ARR" class="widefat">
                            </div>
                            <div class="form-field">
                                <label for="industry">Industry</label>
                                <input type="text" id="industry" v-model="form.industry" class="widefat">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-field">
                                <label for="expertise_areas">Expertise Areas (comma-separated)</label>
                                <input type="text" id="expertise_areas" v-model="form.expertise_areas" placeholder="AI, SaaS, Product Management" class="widefat">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-field">
                                <label for="past_companies">Past Companies (comma-separated)</label>
                                <input type="text" id="past_companies" v-model="form.past_companies" placeholder="Google, Meta, Stripe" class="widefat">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>Contact Information</h3>
                        <div class="form-row form-row-2">
                            <div class="form-field">
                                <label for="linkedin_url">LinkedIn URL</label>
                                <input type="url" id="linkedin_url" v-model="form.linkedin_url" placeholder="https://linkedin.com/in/..." class="widefat">
                            </div>
                            <div class="form-field">
                                <label for="email">Email</label>
                                <input type="email" id="email" v-model="form.email" class="widefat">
                            </div>
                        </div>
                        <div class="form-row form-row-2">
                            <div class="form-field">
                                <label for="twitter_handle">Twitter Handle</label>
                                <input type="text" id="twitter_handle" v-model="form.twitter_handle" placeholder="@username" class="widefat">
                            </div>
                            <div class="form-field">
                                <label for="website_url">Website</label>
                                <input type="url" id="website_url" v-model="form.website_url" class="widefat">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>Additional Information</h3>
                        <div class="form-row">
                            <div class="form-field">
                                <label for="notable_achievements">Notable Achievements</label>
                                <textarea id="notable_achievements" v-model="form.notable_achievements" rows="3" class="widefat"></textarea>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-field">
                                <label for="bio">Bio</label>
                                <textarea id="bio" v-model="form.bio" rows="3" class="widefat"></textarea>
                            </div>
                        </div>
                    </div>

                    <div v-if="error" class="error-message">{{ error }}</div>
                    <div v-if="success" class="success-message">{{ success }}</div>
                </div>
                <div class="modal-actions">
                    <button @click="close" class="button">Cancel</button>
                    <button @click="saveGuest" class="button button-primary" :disabled="loading">
                        {{ loading ? 'Saving...' : (editMode ? 'Update Guest' : 'Add Guest') }}
                    </button>
                </div>
            </div>
        </div>
    `,
    props: {
        guest: { type: Object, default: null },
    },
    data() {
        return {
            form: {
                full_name: '',
                first_name: '',
                last_name: '',
                current_company: '',
                current_role: '',
                company_stage: '',
                company_revenue: '',
                industry: '',
                expertise_areas: '',
                past_companies: '',
                linkedin_url: '',
                email: '',
                twitter_handle: '',
                website_url: '',
                notable_achievements: '',
                bio: '',
            },
            loading: false,
            error: null,
            success: null,
        };
    },
    computed: {
        editMode() {
            return this.guest !== null;
        },
    },
    setup() {
        const store = useGuestStore();
        return { store };
    },
    mounted() {
        if (this.guest) {
            this.form = { ...this.form, ...this.guest };
        }
    },
    methods: {
        async saveGuest() {
            if (!this.form.full_name) {
                this.error = 'Full name is required';
                return;
            }

            this.loading = true;
            this.error = null;
            this.success = null;

            try {
                if (this.editMode) {
                    await this.store.updateGuest(this.guest.id, this.form);
                    this.success = 'Guest updated successfully';
                } else {
                    await this.store.createGuest(this.form);
                    this.success = 'Guest added successfully';
                }

                setTimeout(() => {
                    this.close();
                }, 1500);
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

// Guest Directory Component
const GuestDirectory = {
    template: `
        <div class="pit-guests">
            <div class="pit-toolbar">
                <div class="toolbar-left">
                    <input
                        type="text"
                        v-model="filters.search"
                        @input="onSearchChange"
                        placeholder="Search guests..."
                        class="search-input"
                    />
                    <select v-model="filters.verified" @change="applyFilters" class="filter-select">
                        <option value="">All Verification</option>
                        <option value="1">Verified Only</option>
                        <option value="0">Unverified Only</option>
                    </select>
                    <select v-model="filters.company_stage" @change="applyFilters" class="filter-select">
                        <option value="">All Stages</option>
                        <option value="pre-seed">Pre-Seed</option>
                        <option value="seed">Seed</option>
                        <option value="series-a">Series A</option>
                        <option value="series-b">Series B</option>
                        <option value="series-c+">Series C+</option>
                        <option value="scaleup">Scaleup</option>
                        <option value="post-exit">Post-Exit</option>
                    </select>
                </div>
                <div class="toolbar-right">
                    <button @click="showAddModal = true" class="button button-primary">Add Guest</button>
                </div>
            </div>

            <div v-if="loading" class="pit-loading">Loading guests...</div>

            <div v-else-if="guests.length === 0" class="pit-empty">
                <p>No guests found. Add your first guest to get started.</p>
                <button @click="showAddModal = true" class="button button-primary">Add Guest</button>
            </div>

            <div v-else class="pit-guests-grid">
                <guest-card
                    v-for="guest in guests"
                    :key="guest.id"
                    :guest="guest"
                    @view="viewGuest"
                    @edit="editGuest"
                    @delete="deleteGuest"
                ></guest-card>
            </div>

            <div v-if="pagination.total > pagination.perPage" class="pit-pagination">
                <button
                    @click="prevPage"
                    :disabled="pagination.page <= 1"
                    class="button button-small"
                >Previous</button>
                <span class="pagination-info">
                    Page {{ pagination.page }} of {{ totalPages }}
                    ({{ pagination.total }} guests)
                </span>
                <button
                    @click="nextPage"
                    :disabled="pagination.page >= totalPages"
                    class="button button-small"
                >Next</button>
            </div>

            <add-guest-modal
                v-if="showAddModal"
                :guest="editingGuest"
                @close="closeModal"
            ></add-guest-modal>

            <guest-detail-modal
                v-if="showDetailModal"
                :guest="viewingGuest"
                @close="showDetailModal = false"
                @edit="editGuest"
            ></guest-detail-modal>
        </div>
    `,
    data() {
        return {
            showAddModal: false,
            showDetailModal: false,
            editingGuest: null,
            viewingGuest: null,
        };
    },
    computed: {
        guests() {
            return this.store.guests;
        },
        loading() {
            return this.store.loading;
        },
        filters() {
            return this.store.filters;
        },
        pagination() {
            return this.store.pagination;
        },
        totalPages() {
            return Math.ceil(this.pagination.total / this.pagination.perPage);
        },
    },
    setup() {
        const store = useGuestStore();
        return { store };
    },
    methods: {
        onSearchChange() {
            clearTimeout(this.searchTimeout);
            this.searchTimeout = setTimeout(() => {
                this.store.fetchGuests();
            }, 500);
        },
        applyFilters() {
            this.store.pagination.page = 1;
            this.store.fetchGuests();
        },
        viewGuest(guest) {
            this.viewingGuest = guest;
            this.showDetailModal = true;
        },
        editGuest(guest) {
            this.editingGuest = guest;
            this.showAddModal = true;
        },
        deleteGuest(guest) {
            if (confirm(`Delete guest "${guest.full_name}"? This cannot be undone.`)) {
                this.store.deleteGuest(guest.id);
            }
        },
        closeModal() {
            this.showAddModal = false;
            this.editingGuest = null;
        },
        prevPage() {
            if (this.pagination.page > 1) {
                this.store.pagination.page--;
                this.store.fetchGuests();
            }
        },
        nextPage() {
            if (this.pagination.page < this.totalPages) {
                this.store.pagination.page++;
                this.store.fetchGuests();
            }
        },
    },
    mounted() {
        this.store.fetchGuests();
    },
};

// Guest Detail Modal Component
const GuestDetailModal = {
    template: `
        <div class="pit-modal-overlay" @click="$emit('close')">
            <div class="pit-modal pit-modal-large" @click.stop>
                <div class="guest-detail-header">
                    <div class="guest-avatar-large">{{ getInitials(guest.full_name) }}</div>
                    <div class="guest-header-info">
                        <h2>{{ guest.full_name }}</h2>
                        <p class="guest-title">{{ guest.current_role }} at {{ guest.current_company }}</p>
                        <div class="guest-badges">
                            <span v-if="guest.manually_verified" class="badge badge-verified">Verified</span>
                            <span v-if="guest.company_stage" class="badge badge-stage">{{ guest.company_stage }}</span>
                            <span v-if="guest.industry" class="badge badge-industry">{{ guest.industry }}</span>
                        </div>
                    </div>
                    <button @click="$emit('close')" class="close-button">&times;</button>
                </div>

                <div class="guest-detail-tabs">
                    <button
                        :class="{ active: activeTab === 'overview' }"
                        @click="activeTab = 'overview'"
                    >Overview</button>
                    <button
                        :class="{ active: activeTab === 'appearances' }"
                        @click="activeTab = 'appearances'"
                    >Appearances</button>
                    <button
                        :class="{ active: activeTab === 'network' }"
                        @click="activeTab = 'network'"
                    >Network</button>
                </div>

                <div class="guest-detail-content">
                    <!-- Overview Tab -->
                    <div v-if="activeTab === 'overview'" class="tab-content">
                        <div class="info-section">
                            <h3>Contact Information</h3>
                            <div class="info-grid">
                                <div v-if="guest.email" class="info-item">
                                    <label>Email</label>
                                    <a :href="'mailto:' + guest.email">{{ guest.email }}</a>
                                </div>
                                <div v-if="guest.linkedin_url" class="info-item">
                                    <label>LinkedIn</label>
                                    <a :href="guest.linkedin_url" target="_blank">View Profile</a>
                                </div>
                                <div v-if="guest.twitter_handle" class="info-item">
                                    <label>Twitter</label>
                                    <a :href="'https://twitter.com/' + guest.twitter_handle" target="_blank">@{{ guest.twitter_handle }}</a>
                                </div>
                                <div v-if="guest.website_url" class="info-item">
                                    <label>Website</label>
                                    <a :href="guest.website_url" target="_blank">{{ guest.website_url }}</a>
                                </div>
                            </div>
                        </div>

                        <div v-if="guest.expertise_areas" class="info-section">
                            <h3>Expertise Areas</h3>
                            <div class="tags">
                                <span v-for="area in parseList(guest.expertise_areas)" :key="area" class="tag">{{ area }}</span>
                            </div>
                        </div>

                        <div v-if="guest.past_companies" class="info-section">
                            <h3>Past Companies</h3>
                            <div class="tags">
                                <span v-for="company in parseList(guest.past_companies)" :key="company" class="tag">{{ company }}</span>
                            </div>
                        </div>

                        <div v-if="guest.notable_achievements" class="info-section">
                            <h3>Notable Achievements</h3>
                            <p>{{ guest.notable_achievements }}</p>
                        </div>

                        <div v-if="guest.bio" class="info-section">
                            <h3>Bio</h3>
                            <p>{{ guest.bio }}</p>
                        </div>
                    </div>

                    <!-- Appearances Tab -->
                    <div v-if="activeTab === 'appearances'" class="tab-content">
                        <div v-if="loadingAppearances" class="loading">Loading appearances...</div>
                        <div v-else-if="appearances.length === 0" class="empty">
                            No podcast appearances recorded yet.
                        </div>
                        <div v-else class="appearances-list">
                            <div v-for="appearance in appearances" :key="appearance.id" class="appearance-item">
                                <div class="appearance-podcast">{{ appearance.podcast_name }}</div>
                                <div class="appearance-episode">Episode {{ appearance.episode_number }}: {{ appearance.episode_title }}</div>
                                <div class="appearance-date">{{ formatDate(appearance.episode_date) }}</div>
                                <div v-if="appearance.topics_discussed" class="appearance-topics">
                                    <span v-for="topic in parseList(appearance.topics_discussed)" :key="topic" class="tag-small">{{ topic }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Network Tab -->
                    <div v-if="activeTab === 'network'" class="tab-content">
                        <div v-if="loadingNetwork" class="loading">Calculating network...</div>
                        <div v-else>
                            <div class="network-stats">
                                <div class="stat-item">
                                    <div class="stat-value">{{ network.first_degree?.length || 0 }}</div>
                                    <div class="stat-label">1st Degree Connections</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value">{{ network.second_degree?.length || 0 }}</div>
                                    <div class="stat-label">2nd Degree Connections</div>
                                </div>
                            </div>
                            <div v-if="network.first_degree?.length > 0" class="network-section">
                                <h4>1st Degree (Same Podcast Appearances)</h4>
                                <div class="connection-list">
                                    <div v-for="conn in network.first_degree" :key="conn.id" class="connection-item">
                                        <span class="connection-name">{{ conn.full_name }}</span>
                                        <span class="connection-info">{{ conn.current_company }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-actions">
                    <button @click="$emit('close')" class="button">Close</button>
                    <button @click="$emit('edit', guest)" class="button button-primary">Edit Guest</button>
                </div>
            </div>
        </div>
    `,
    props: ['guest'],
    emits: ['close', 'edit'],
    data() {
        return {
            activeTab: 'overview',
            appearances: [],
            loadingAppearances: false,
            network: { first_degree: [], second_degree: [] },
            loadingNetwork: false,
        };
    },
    setup() {
        const store = useGuestStore();
        return { store };
    },
    watch: {
        activeTab(newTab) {
            if (newTab === 'appearances' && this.appearances.length === 0) {
                this.loadAppearances();
            } else if (newTab === 'network' && !this.network.first_degree?.length) {
                this.loadNetwork();
            }
        },
    },
    methods: {
        getInitials(name) {
            if (!name) return '?';
            return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2);
        },
        parseList(str) {
            if (!str) return [];
            return str.split(',').map(s => s.trim()).filter(s => s);
        },
        formatDate(dateStr) {
            if (!dateStr) return '';
            const date = new Date(dateStr);
            return date.toLocaleDateString();
        },
        async loadAppearances() {
            this.loadingAppearances = true;
            try {
                this.appearances = await this.store.fetchGuestAppearances(this.guest.id);
            } finally {
                this.loadingAppearances = false;
            }
        },
        async loadNetwork() {
            this.loadingNetwork = true;
            try {
                this.network = await this.store.fetchGuestNetwork(this.guest.id);
            } finally {
                this.loadingNetwork = false;
            }
        },
    },
};

// Content Analysis Store
const useContentStore = defineStore('content', {
    state: () => ({
        analysis: null,
        loading: false,
    }),

    actions: {
        async fetchAnalysis(podcastId) {
            this.loading = true;
            try {
                const response = await fetch(`${pitData.apiUrl}/intelligence/podcasts/${podcastId}/content-analysis`, {
                    headers: { 'X-WP-Nonce': pitData.nonce },
                });
                this.analysis = await response.json();
                return this.analysis;
            } catch (error) {
                console.error('Failed to fetch content analysis:', error);
                return null;
            } finally {
                this.loading = false;
            }
        },

        async saveAnalysis(podcastId, data) {
            try {
                const response = await fetch(`${pitData.apiUrl}/intelligence/podcasts/${podcastId}/content-analysis`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': pitData.nonce,
                    },
                    body: JSON.stringify(data),
                });

                if (!response.ok) {
                    const error = await response.json();
                    throw new Error(error.message || 'Failed to save analysis');
                }

                return await response.json();
            } catch (error) {
                throw error;
            }
        },
    },
});

// Podcast Detail Component (Multi-Tab)
const PodcastDetail = {
    template: `
        <div class="pit-podcast-detail">
            <div v-if="loading" class="pit-loading">Loading podcast details...</div>

            <template v-else-if="podcast">
                <div class="podcast-header">
                    <div class="podcast-info">
                        <h2>{{ podcast.podcast_name }}</h2>
                        <p class="podcast-author">By {{ podcast.author }}</p>
                        <div class="podcast-badges">
                            <span class="badge" :class="'badge-' + podcast.tracking_status">{{ podcast.tracking_status }}</span>
                            <span v-if="podcast.itunes_id" class="badge badge-itunes">iTunes</span>
                        </div>
                    </div>
                    <div class="podcast-actions">
                        <button @click="goBack" class="button">Back to List</button>
                        <button v-if="!podcast.is_tracked" @click="trackPodcast" class="button button-primary">Track Metrics</button>
                    </div>
                </div>

                <div class="detail-tabs">
                    <button
                        v-for="tab in tabs"
                        :key="tab.id"
                        :class="{ active: activeTab === tab.id }"
                        @click="activeTab = tab.id"
                    >{{ tab.label }}</button>
                </div>

                <div class="detail-content">
                    <!-- Overview Tab -->
                    <div v-if="activeTab === 'overview'" class="tab-content">
                        <div class="overview-grid">
                            <div class="info-card">
                                <h3>Podcast Information</h3>
                                <div class="info-list">
                                    <div class="info-row">
                                        <span class="label">RSS Feed:</span>
                                        <a :href="podcast.rss_url" target="_blank" class="value">{{ podcast.rss_url }}</a>
                                    </div>
                                    <div v-if="podcast.homepage_url" class="info-row">
                                        <span class="label">Homepage:</span>
                                        <a :href="podcast.homepage_url" target="_blank" class="value">{{ podcast.homepage_url }}</a>
                                    </div>
                                    <div v-if="podcast.itunes_id" class="info-row">
                                        <span class="label">iTunes ID:</span>
                                        <span class="value">{{ podcast.itunes_id }}</span>
                                    </div>
                                    <div class="info-row">
                                        <span class="label">Added:</span>
                                        <span class="value">{{ formatDate(podcast.created_at) }}</span>
                                    </div>
                                </div>
                            </div>

                            <div class="info-card">
                                <h3>Quick Stats</h3>
                                <div class="stats-mini">
                                    <div class="stat-mini">
                                        <div class="stat-mini-value">{{ podcast.social_links_count || 0 }}</div>
                                        <div class="stat-mini-label">Social Accounts</div>
                                    </div>
                                    <div class="stat-mini">
                                        <div class="stat-mini-value">{{ podcast.metrics_count || 0 }}</div>
                                        <div class="stat-mini-label">Metrics Tracked</div>
                                    </div>
                                    <div class="stat-mini">
                                        <div class="stat-mini-value">{{ podcast.guests_count || 0 }}</div>
                                        <div class="stat-mini-label">Guests</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div v-if="podcast.description" class="description-card">
                            <h3>Description</h3>
                            <p>{{ podcast.description }}</p>
                        </div>
                    </div>

                    <!-- Social Metrics Tab -->
                    <div v-if="activeTab === 'social'" class="tab-content">
                        <div class="social-header">
                            <h3>Social Accounts & Metrics</h3>
                            <button @click="showAddSocialModal = true" class="button button-small">Add Social Account</button>
                        </div>

                        <div v-if="socialLinks.length === 0" class="empty-state">
                            <p>No social accounts linked yet.</p>
                            <button @click="showAddSocialModal = true" class="button button-primary">Add Social Account</button>
                        </div>

                        <div v-else class="social-cards">
                            <div v-for="link in socialLinks" :key="link.id" class="social-card">
                                <div class="social-card-header">
                                    <span class="platform-badge" :class="'platform-' + link.platform">
                                        {{ getPlatformIcon(link.platform) }} {{ formatPlatform(link.platform) }}
                                    </span>
                                    <button @click="showAddMetricsModal = link" class="button button-small">Add Metrics</button>
                                </div>
                                <div class="social-card-url">
                                    <a :href="link.url" target="_blank">{{ link.url }}</a>
                                </div>
                                <div v-if="link.latest_metrics" class="social-card-metrics">
                                    <div class="metric-item">
                                        <span class="metric-value">{{ formatNumber(link.latest_metrics.followers_count) }}</span>
                                        <span class="metric-label">Followers</span>
                                    </div>
                                    <div v-if="link.latest_metrics.following_count" class="metric-item">
                                        <span class="metric-value">{{ formatNumber(link.latest_metrics.following_count) }}</span>
                                        <span class="metric-label">Following</span>
                                    </div>
                                    <div v-if="link.latest_metrics.posts_count" class="metric-item">
                                        <span class="metric-value">{{ formatNumber(link.latest_metrics.posts_count) }}</span>
                                        <span class="metric-label">Posts</span>
                                    </div>
                                    <div v-if="link.latest_metrics.engagement_rate" class="metric-item">
                                        <span class="metric-value">{{ link.latest_metrics.engagement_rate }}%</span>
                                        <span class="metric-label">Engagement</span>
                                    </div>
                                </div>
                                <div v-else class="social-card-empty">
                                    No metrics yet. <a href="#" @click.prevent="showAddMetricsModal = link">Add manually</a>
                                </div>
                                <div v-if="link.latest_metrics" class="social-card-footer">
                                    Last updated: {{ formatDate(link.latest_metrics.fetched_at) }}
                                    <span v-if="link.latest_metrics.data_source" class="data-source">
                                        via {{ link.latest_metrics.data_source }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Guests Tab -->
                    <div v-if="activeTab === 'guests'" class="tab-content">
                        <div class="guests-header">
                            <h3>Podcast Guests</h3>
                            <button @click="showAddGuestLink = true" class="button button-small">Link Guest</button>
                        </div>

                        <div v-if="guests.length === 0" class="empty-state">
                            <p>No guests linked to this podcast yet.</p>
                            <button @click="showAddGuestLink = true" class="button button-primary">Link Guest</button>
                        </div>

                        <div v-else class="guests-list">
                            <div v-for="guest in guests" :key="guest.id" class="guest-list-item">
                                <div class="guest-list-avatar">{{ getInitials(guest.full_name) }}</div>
                                <div class="guest-list-info">
                                    <div class="guest-list-name">{{ guest.full_name }}</div>
                                    <div class="guest-list-role">{{ guest.current_role }} at {{ guest.current_company }}</div>
                                    <div v-if="guest.episode_title" class="guest-list-episode">
                                        Episode {{ guest.episode_number }}: {{ guest.episode_title }}
                                    </div>
                                </div>
                                <div class="guest-list-actions">
                                    <span v-if="guest.manually_verified" class="verified-badge">Verified</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Content Analysis Tab -->
                    <div v-if="activeTab === 'content'" class="tab-content">
                        <div class="content-header">
                            <h3>Content Analysis</h3>
                            <button @click="showContentAnalysisModal = true" class="button button-small">
                                {{ contentAnalysis ? 'Edit Analysis' : 'Add Analysis' }}
                            </button>
                        </div>

                        <div v-if="!contentAnalysis" class="empty-state">
                            <p>No content analysis yet. Add analysis manually to track topics and keywords.</p>
                            <button @click="showContentAnalysisModal = true" class="button button-primary">Add Analysis</button>
                        </div>

                        <template v-else>
                            <div class="content-grid">
                                <div class="content-card">
                                    <h4>Topic Clusters</h4>
                                    <div v-if="contentAnalysis.topic_clusters" class="topic-bars">
                                        <div v-for="topic in parsedTopics" :key="topic.name" class="topic-bar">
                                            <div class="topic-bar-label">
                                                <span class="topic-name">{{ topic.name }}</span>
                                                <span class="topic-percent">{{ topic.percentage }}%</span>
                                            </div>
                                            <div class="topic-bar-track">
                                                <div class="topic-bar-fill" :style="{ width: topic.percentage + '%', backgroundColor: topic.color }"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="content-card">
                                    <h4>Publishing Pattern</h4>
                                    <div class="pattern-info">
                                        <div v-if="contentAnalysis.publishing_frequency" class="pattern-item">
                                            <span class="pattern-label">Frequency:</span>
                                            <span class="pattern-value">{{ contentAnalysis.publishing_frequency }}</span>
                                        </div>
                                        <div v-if="contentAnalysis.avg_episode_length" class="pattern-item">
                                            <span class="pattern-label">Avg Length:</span>
                                            <span class="pattern-value">{{ contentAnalysis.avg_episode_length }} min</span>
                                        </div>
                                        <div v-if="contentAnalysis.format_type" class="pattern-item">
                                            <span class="pattern-label">Format:</span>
                                            <span class="pattern-value">{{ contentAnalysis.format_type }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div v-if="contentAnalysis.keywords" class="keywords-section">
                                <h4>Top Keywords</h4>
                                <div class="keywords-cloud">
                                    <span v-for="keyword in parsedKeywords" :key="keyword" class="keyword-tag">
                                        {{ keyword }}
                                    </span>
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Export Tab -->
                    <div v-if="activeTab === 'export'" class="tab-content">
                        <h3>Export Data</h3>
                        <div class="export-options">
                            <div class="export-option">
                                <h4>Social Metrics</h4>
                                <p>Export all social metrics history for this podcast</p>
                                <button @click="exportData('social-metrics', 'csv')" class="button">Export CSV</button>
                                <button @click="exportData('social-metrics', 'json')" class="button">Export JSON</button>
                            </div>
                            <div class="export-option">
                                <h4>Guest Directory</h4>
                                <p>Export all guest information for this podcast</p>
                                <button @click="exportData('guests', 'csv')" class="button">Export CSV</button>
                                <button @click="exportData('guests', 'json')" class="button">Export JSON</button>
                            </div>
                            <div class="export-option">
                                <h4>Content Analysis</h4>
                                <p>Export content analysis data</p>
                                <button @click="exportData('content-analysis', 'json')" class="button">Export JSON</button>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <add-social-link-modal
                v-if="showAddSocialModal"
                :podcast-id="podcastId"
                @close="showAddSocialModal = false"
                @saved="refreshSocialLinks"
            ></add-social-link-modal>

            <add-metrics-modal
                v-if="showAddMetricsModal"
                :social-link="showAddMetricsModal"
                @close="showAddMetricsModal = null"
                @saved="refreshSocialLinks"
            ></add-metrics-modal>

            <content-analysis-modal
                v-if="showContentAnalysisModal"
                :podcast-id="podcastId"
                :existing="contentAnalysis"
                @close="showContentAnalysisModal = false"
                @saved="refreshContentAnalysis"
            ></content-analysis-modal>
        </div>
    `,
    props: ['podcastId'],
    data() {
        return {
            podcast: null,
            loading: true,
            activeTab: 'overview',
            tabs: [
                { id: 'overview', label: 'Overview' },
                { id: 'social', label: 'Social Metrics' },
                { id: 'guests', label: 'Guests' },
                { id: 'content', label: 'Content Analysis' },
                { id: 'export', label: 'Export' },
            ],
            socialLinks: [],
            guests: [],
            contentAnalysis: null,
            showAddSocialModal: false,
            showAddMetricsModal: null,
            showAddGuestLink: false,
            showContentAnalysisModal: false,
        };
    },
    computed: {
        parsedTopics() {
            if (!this.contentAnalysis?.topic_clusters) return [];
            try {
                const topics = JSON.parse(this.contentAnalysis.topic_clusters);
                return Array.isArray(topics) ? topics : [];
            } catch {
                return [];
            }
        },
        parsedKeywords() {
            if (!this.contentAnalysis?.keywords) return [];
            return this.contentAnalysis.keywords.split(',').map(k => k.trim()).filter(k => k);
        },
    },
    async mounted() {
        await this.loadPodcast();
    },
    methods: {
        async loadPodcast() {
            this.loading = true;
            try {
                const response = await fetch(`${pitData.apiUrl}/podcasts/${this.podcastId}`, {
                    headers: { 'X-WP-Nonce': pitData.nonce },
                });
                this.podcast = await response.json();

                // Load related data
                await Promise.all([
                    this.loadSocialLinks(),
                    this.loadGuests(),
                    this.loadContentAnalysis(),
                ]);
            } catch (error) {
                console.error('Failed to load podcast:', error);
            } finally {
                this.loading = false;
            }
        },
        async loadSocialLinks() {
            try {
                const response = await fetch(`${pitData.apiUrl}/podcasts/${this.podcastId}/social-links`, {
                    headers: { 'X-WP-Nonce': pitData.nonce },
                });
                this.socialLinks = await response.json();
            } catch (error) {
                console.error('Failed to load social links:', error);
            }
        },
        async loadGuests() {
            try {
                const response = await fetch(`${pitData.apiUrl}/podcasts/${this.podcastId}/guests`, {
                    headers: { 'X-WP-Nonce': pitData.nonce },
                });
                this.guests = await response.json();
            } catch (error) {
                console.error('Failed to load guests:', error);
            }
        },
        async loadContentAnalysis() {
            try {
                const response = await fetch(`${pitData.apiUrl}/intelligence/podcasts/${this.podcastId}/content-analysis`, {
                    headers: { 'X-WP-Nonce': pitData.nonce },
                });
                if (response.ok) {
                    this.contentAnalysis = await response.json();
                }
            } catch (error) {
                console.error('Failed to load content analysis:', error);
            }
        },
        refreshSocialLinks() {
            this.loadSocialLinks();
        },
        refreshContentAnalysis() {
            this.loadContentAnalysis();
            this.showContentAnalysisModal = false;
        },
        goBack() {
            window.location.href = window.location.href.replace(/&podcast_id=\d+/, '');
        },
        trackPodcast() {
            if (confirm('Start tracking metrics for this podcast? This will incur API costs.')) {
                // Implement tracking
            }
        },
        async exportData(type, format) {
            try {
                const response = await fetch(
                    `${pitData.apiUrl}/podcasts/${this.podcastId}/export/${type}?format=${format}`,
                    { headers: { 'X-WP-Nonce': pitData.nonce } }
                );
                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `${this.podcast.podcast_name}-${type}.${format}`;
                a.click();
            } catch (error) {
                alert('Export failed: ' + error.message);
            }
        },
        formatDate(dateStr) {
            if (!dateStr) return '';
            return new Date(dateStr).toLocaleDateString();
        },
        formatNumber(num) {
            if (!num) return '0';
            if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
            if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
            return num.toString();
        },
        formatPlatform(platform) {
            const names = {
                youtube: 'YouTube', twitter: 'Twitter', instagram: 'Instagram',
                facebook: 'Facebook', linkedin: 'LinkedIn', tiktok: 'TikTok',
                spotify: 'Spotify', apple_podcasts: 'Apple Podcasts'
            };
            return names[platform] || platform;
        },
        getPlatformIcon(platform) {
            const icons = {
                youtube: '▶️', twitter: '🐦', instagram: '📷',
                facebook: '👍', linkedin: '💼', tiktok: '🎵',
                spotify: '🎧', apple_podcasts: '🎙️'
            };
            return icons[platform] || '🔗';
        },
        getInitials(name) {
            if (!name) return '?';
            return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2);
        },
    },
};

// Add Social Link Modal
const AddSocialLinkModal = {
    template: `
        <div class="pit-modal-overlay" @click="$emit('close')">
            <div class="pit-modal" @click.stop>
                <h2>Add Social Account</h2>
                <div class="modal-content">
                    <div class="form-field">
                        <label for="platform">Platform *</label>
                        <select id="platform" v-model="form.platform" class="widefat">
                            <option value="">Select platform...</option>
                            <option value="youtube">YouTube</option>
                            <option value="twitter">Twitter / X</option>
                            <option value="instagram">Instagram</option>
                            <option value="facebook">Facebook</option>
                            <option value="linkedin">LinkedIn</option>
                            <option value="tiktok">TikTok</option>
                            <option value="spotify">Spotify</option>
                            <option value="apple_podcasts">Apple Podcasts</option>
                        </select>
                    </div>
                    <div class="form-field">
                        <label for="url">URL *</label>
                        <input type="url" id="url" v-model="form.url" placeholder="https://..." class="widefat">
                    </div>
                    <div class="form-field">
                        <label for="handle">Handle / Username</label>
                        <input type="text" id="handle" v-model="form.handle" placeholder="@username" class="widefat">
                    </div>
                    <div v-if="error" class="error-message">{{ error }}</div>
                </div>
                <div class="modal-actions">
                    <button @click="$emit('close')" class="button">Cancel</button>
                    <button @click="save" class="button button-primary" :disabled="loading">
                        {{ loading ? 'Saving...' : 'Add Account' }}
                    </button>
                </div>
            </div>
        </div>
    `,
    props: ['podcastId'],
    emits: ['close', 'saved'],
    data() {
        return {
            form: { platform: '', url: '', handle: '' },
            loading: false,
            error: null,
        };
    },
    methods: {
        async save() {
            if (!this.form.platform || !this.form.url) {
                this.error = 'Platform and URL are required';
                return;
            }
            this.loading = true;
            this.error = null;
            try {
                const response = await fetch(`${pitData.apiUrl}/podcasts/${this.podcastId}/social-links`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': pitData.nonce },
                    body: JSON.stringify(this.form),
                });
                if (!response.ok) throw new Error('Failed to add social account');
                this.$emit('saved');
                this.$emit('close');
            } catch (error) {
                this.error = error.message;
            } finally {
                this.loading = false;
            }
        },
    },
};

// Add Metrics Modal
const AddMetricsModal = {
    template: `
        <div class="pit-modal-overlay" @click="$emit('close')">
            <div class="pit-modal" @click.stop>
                <h2>Add Metrics</h2>
                <p class="modal-subtitle">{{ formatPlatform(socialLink.platform) }}: {{ socialLink.url }}</p>
                <div class="modal-content">
                    <div class="form-row form-row-2">
                        <div class="form-field">
                            <label>Followers / Subscribers *</label>
                            <input type="number" v-model="form.followers_count" class="widefat">
                        </div>
                        <div class="form-field">
                            <label>Following</label>
                            <input type="number" v-model="form.following_count" class="widefat">
                        </div>
                    </div>
                    <div class="form-row form-row-2">
                        <div class="form-field">
                            <label>Posts / Videos</label>
                            <input type="number" v-model="form.posts_count" class="widefat">
                        </div>
                        <div class="form-field">
                            <label>Total Views</label>
                            <input type="number" v-model="form.total_views" class="widefat">
                        </div>
                    </div>
                    <div class="form-row form-row-2">
                        <div class="form-field">
                            <label>Engagement Rate (%)</label>
                            <input type="number" step="0.01" v-model="form.engagement_rate" class="widefat">
                        </div>
                        <div class="form-field">
                            <label>Avg Likes</label>
                            <input type="number" v-model="form.avg_likes" class="widefat">
                        </div>
                    </div>
                    <div class="form-row form-row-2">
                        <div class="form-field">
                            <label>Fetched Date</label>
                            <input type="date" v-model="form.fetched_at" class="widefat">
                        </div>
                        <div class="form-field">
                            <label>Data Quality (0-100)</label>
                            <input type="number" min="0" max="100" v-model="form.data_quality_score" class="widefat">
                        </div>
                    </div>
                    <div v-if="error" class="error-message">{{ error }}</div>
                </div>
                <div class="modal-actions">
                    <button @click="$emit('close')" class="button">Cancel</button>
                    <button @click="save" class="button button-primary" :disabled="loading">
                        {{ loading ? 'Saving...' : 'Save Metrics' }}
                    </button>
                </div>
            </div>
        </div>
    `,
    props: ['socialLink'],
    emits: ['close', 'saved'],
    data() {
        return {
            form: {
                followers_count: '',
                following_count: '',
                posts_count: '',
                total_views: '',
                engagement_rate: '',
                avg_likes: '',
                fetched_at: new Date().toISOString().split('T')[0],
                data_quality_score: 90,
                data_source: 'manual',
            },
            loading: false,
            error: null,
        };
    },
    methods: {
        formatPlatform(platform) {
            const names = { youtube: 'YouTube', twitter: 'Twitter', instagram: 'Instagram', linkedin: 'LinkedIn' };
            return names[platform] || platform;
        },
        async save() {
            if (!this.form.followers_count) {
                this.error = 'Followers count is required';
                return;
            }
            this.loading = true;
            this.error = null;
            try {
                const response = await fetch(`${pitData.apiUrl}/social-links/${this.socialLink.id}/metrics`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': pitData.nonce },
                    body: JSON.stringify(this.form),
                });
                if (!response.ok) throw new Error('Failed to save metrics');
                this.$emit('saved');
                this.$emit('close');
            } catch (error) {
                this.error = error.message;
            } finally {
                this.loading = false;
            }
        },
    },
};

// Content Analysis Modal
const ContentAnalysisModal = {
    template: `
        <div class="pit-modal-overlay" @click="$emit('close')">
            <div class="pit-modal pit-modal-large" @click.stop>
                <h2>{{ existing ? 'Edit' : 'Add' }} Content Analysis</h2>
                <div class="modal-content">
                    <div class="form-section">
                        <h3>Topic Clusters</h3>
                        <div v-for="(topic, index) in form.topics" :key="index" class="topic-row">
                            <input type="text" v-model="topic.name" placeholder="Topic name" class="topic-name">
                            <input type="number" v-model="topic.percentage" placeholder="%" min="0" max="100" class="topic-percent">
                            <input type="color" v-model="topic.color" class="topic-color">
                            <button @click="removeTopic(index)" class="button button-small button-link-delete">X</button>
                        </div>
                        <button @click="addTopic" class="button button-small">+ Add Topic</button>
                    </div>

                    <div class="form-section">
                        <h3>Keywords</h3>
                        <div class="form-field">
                            <label>Top Keywords (comma-separated)</label>
                            <input type="text" v-model="form.keywords" placeholder="SaaS, Scaling, AI, Fundraising" class="widefat">
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>Publishing Pattern</h3>
                        <div class="form-row form-row-3">
                            <div class="form-field">
                                <label>Frequency</label>
                                <select v-model="form.publishing_frequency" class="widefat">
                                    <option value="">Select...</option>
                                    <option value="daily">Daily</option>
                                    <option value="twice-weekly">Twice Weekly</option>
                                    <option value="weekly">Weekly</option>
                                    <option value="bi-weekly">Bi-Weekly</option>
                                    <option value="monthly">Monthly</option>
                                    <option value="irregular">Irregular</option>
                                </select>
                            </div>
                            <div class="form-field">
                                <label>Avg Episode Length (min)</label>
                                <input type="number" v-model="form.avg_episode_length" class="widefat">
                            </div>
                            <div class="form-field">
                                <label>Format Type</label>
                                <select v-model="form.format_type" class="widefat">
                                    <option value="">Select...</option>
                                    <option value="interview">1-on-1 Interviews</option>
                                    <option value="panel">Panel Discussion</option>
                                    <option value="solo">Solo/Monologue</option>
                                    <option value="co-hosted">Co-hosted</option>
                                    <option value="mixed">Mixed Format</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div v-if="error" class="error-message">{{ error }}</div>
                </div>
                <div class="modal-actions">
                    <button @click="$emit('close')" class="button">Cancel</button>
                    <button @click="save" class="button button-primary" :disabled="loading">
                        {{ loading ? 'Saving...' : 'Save Analysis' }}
                    </button>
                </div>
            </div>
        </div>
    `,
    props: ['podcastId', 'existing'],
    emits: ['close', 'saved'],
    data() {
        return {
            form: {
                topics: [{ name: '', percentage: '', color: '#0073aa' }],
                keywords: '',
                publishing_frequency: '',
                avg_episode_length: '',
                format_type: '',
            },
            loading: false,
            error: null,
        };
    },
    mounted() {
        if (this.existing) {
            if (this.existing.topic_clusters) {
                try {
                    this.form.topics = JSON.parse(this.existing.topic_clusters);
                } catch {}
            }
            this.form.keywords = this.existing.keywords || '';
            this.form.publishing_frequency = this.existing.publishing_frequency || '';
            this.form.avg_episode_length = this.existing.avg_episode_length || '';
            this.form.format_type = this.existing.format_type || '';
        }
    },
    methods: {
        addTopic() {
            this.form.topics.push({ name: '', percentage: '', color: '#' + Math.floor(Math.random()*16777215).toString(16) });
        },
        removeTopic(index) {
            this.form.topics.splice(index, 1);
        },
        async save() {
            this.loading = true;
            this.error = null;
            try {
                const data = {
                    topic_clusters: JSON.stringify(this.form.topics.filter(t => t.name)),
                    keywords: this.form.keywords,
                    publishing_frequency: this.form.publishing_frequency,
                    avg_episode_length: this.form.avg_episode_length,
                    format_type: this.form.format_type,
                };
                const response = await fetch(`${pitData.apiUrl}/intelligence/podcasts/${this.podcastId}/content-analysis`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': pitData.nonce },
                    body: JSON.stringify(data),
                });
                if (!response.ok) throw new Error('Failed to save analysis');
                this.$emit('saved');
            } catch (error) {
                this.error = error.message;
            } finally {
                this.loading = false;
            }
        },
    },
};

// Add Appearance Modal Component
const AddAppearanceModal = {
    template: `
        <div class="pit-modal-overlay" @click="$emit('close')">
            <div class="pit-modal" @click.stop>
                <h2>Add Podcast Appearance</h2>
                <div class="modal-content">
                    <div class="form-field">
                        <label for="podcast_id">Podcast *</label>
                        <select id="podcast_id" v-model="form.podcast_id" class="widefat" required>
                            <option value="">Select a podcast...</option>
                            <option v-for="podcast in podcasts" :key="podcast.id" :value="podcast.id">
                                {{ podcast.podcast_name }}
                            </option>
                        </select>
                    </div>
                    <div class="form-row form-row-2">
                        <div class="form-field">
                            <label for="episode_number">Episode #</label>
                            <input type="number" id="episode_number" v-model="form.episode_number" class="widefat">
                        </div>
                        <div class="form-field">
                            <label for="episode_date">Episode Date</label>
                            <input type="date" id="episode_date" v-model="form.episode_date" class="widefat">
                        </div>
                    </div>
                    <div class="form-field">
                        <label for="episode_title">Episode Title</label>
                        <input type="text" id="episode_title" v-model="form.episode_title" class="widefat">
                    </div>
                    <div class="form-field">
                        <label for="episode_url">Episode URL</label>
                        <input type="url" id="episode_url" v-model="form.episode_url" class="widefat">
                    </div>
                    <div class="form-field">
                        <label for="topics_discussed">Topics Discussed (comma-separated)</label>
                        <input type="text" id="topics_discussed" v-model="form.topics_discussed" class="widefat">
                    </div>
                    <div class="form-field">
                        <label for="key_quotes">Key Quotes</label>
                        <textarea id="key_quotes" v-model="form.key_quotes" rows="3" class="widefat"></textarea>
                    </div>

                    <div v-if="error" class="error-message">{{ error }}</div>
                    <div v-if="success" class="success-message">{{ success }}</div>
                </div>
                <div class="modal-actions">
                    <button @click="$emit('close')" class="button">Cancel</button>
                    <button @click="saveAppearance" class="button button-primary" :disabled="loading">
                        {{ loading ? 'Saving...' : 'Add Appearance' }}
                    </button>
                </div>
            </div>
        </div>
    `,
    props: ['guestId'],
    emits: ['close', 'saved'],
    data() {
        return {
            form: {
                podcast_id: '',
                episode_number: '',
                episode_title: '',
                episode_date: '',
                episode_url: '',
                topics_discussed: '',
                key_quotes: '',
            },
            podcasts: [],
            loading: false,
            error: null,
            success: null,
        };
    },
    setup() {
        const guestStore = useGuestStore();
        const podcastStore = usePodcastStore();
        return { guestStore, podcastStore };
    },
    async mounted() {
        await this.podcastStore.fetchPodcasts();
        this.podcasts = this.podcastStore.podcasts;
    },
    methods: {
        async saveAppearance() {
            if (!this.form.podcast_id) {
                this.error = 'Please select a podcast';
                return;
            }

            this.loading = true;
            this.error = null;

            try {
                await this.guestStore.addGuestAppearance(this.guestId, this.form);
                this.success = 'Appearance added successfully';
                setTimeout(() => {
                    this.$emit('saved');
                    this.$emit('close');
                }, 1000);
            } catch (error) {
                this.error = error.message;
            } finally {
                this.loading = false;
            }
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

    // Podcast Detail View
    if (document.getElementById('pit-app-podcast-detail')) {
        const podcastId = document.getElementById('pit-app-podcast-detail').dataset.podcastId;
        const app = createApp(PodcastDetail, { podcastId: parseInt(podcastId) });
        app.use(pinia);
        app.component('add-social-link-modal', AddSocialLinkModal);
        app.component('add-metrics-modal', AddMetricsModal);
        app.component('content-analysis-modal', ContentAnalysisModal);
        app.mount('#pit-app-podcast-detail');
    }

    // Guest Directory
    if (document.getElementById('pit-app-guests')) {
        const app = createApp(GuestDirectory);
        app.use(pinia);
        app.component('guest-card', GuestCard);
        app.component('add-guest-modal', AddGuestModal);
        app.component('guest-detail-modal', GuestDetailModal);
        app.component('add-appearance-modal', AddAppearanceModal);
        app.mount('#pit-app-guests');
    }

    // Analytics
    if (document.getElementById('pit-app-analytics')) {
        const app = createApp(Analytics);
        app.use(pinia);
        app.mount('#pit-app-analytics');
    }

    // Settings
    if (document.getElementById('pit-app-settings')) {
        const app = createApp(Settings);
        app.use(pinia);
        app.mount('#pit-app-settings');
    }
});
