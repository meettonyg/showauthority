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
