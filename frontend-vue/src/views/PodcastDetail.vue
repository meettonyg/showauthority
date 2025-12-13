<template>
  <div class="container">
    <div v-if="loading" class="loading">
      <div class="loading-spinner"></div>
      <p>Loading podcast...</p>
    </div>

    <div v-else-if="error" class="error">
      <p>{{ error }}</p>
      <router-link to="/podcasts" class="back-link">Back to Podcasts</router-link>
    </div>

    <div v-else-if="podcast" class="podcast-detail">
      <!-- Back Navigation -->
      <router-link to="/podcasts" class="back-nav">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M19 12H5M12 19l-7-7 7-7"/>
        </svg>
        Back to Podcasts
      </router-link>

      <!-- Header Section -->
      <div class="podcast-header">
        <img
          v-if="podcast.artwork_url"
          :src="podcast.artwork_url"
          :alt="podcast.title"
          class="podcast-artwork-large"
        />
        <div class="podcast-info">
          <h1>{{ podcast.title }}</h1>
          <p v-if="podcast.author" class="author">By {{ podcast.author }}</p>
          <p v-if="podcast.description" class="description">
            {{ podcast.description }}
          </p>
          <div class="meta">
            <span v-if="podcast.category" class="meta-tag">{{ podcast.category }}</span>
            <span v-if="podcast.episode_count" class="meta-item">
              {{ podcast.episode_count }} episodes
            </span>
          </div>
          <div class="actions">
            <a
              v-if="podcast.website_url"
              :href="podcast.website_url"
              target="_blank"
              rel="noopener noreferrer"
              class="action-btn primary"
            >
              Visit Website
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                <polyline points="15 3 21 3 21 9"/>
                <line x1="10" y1="14" x2="21" y2="3"/>
              </svg>
            </a>
            <a
              v-if="podcast.rss_url"
              :href="podcast.rss_url"
              target="_blank"
              rel="noopener noreferrer"
              class="action-btn secondary"
            >
              RSS Feed
            </a>
          </div>
        </div>
      </div>

      <!-- Social Metrics Section -->
      <div v-if="metrics && metrics.length" class="section">
        <h2>Social Metrics</h2>
        <div class="metrics-grid">
          <div v-for="metric in metrics" :key="metric.platform" class="metric-card" :class="metric.platform.toLowerCase()">
            <div class="metric-content">
              <div class="metric-platform">{{ metric.platform }}</div>
              <div class="metric-stats">
                <div v-if="metric.followers" class="metric-stat">
                  <span class="stat-value">{{ formatNumber(metric.followers) }}</span>
                  <span class="stat-label">Followers</span>
                </div>
                <div v-if="metric.subscribers" class="metric-stat">
                  <span class="stat-value">{{ formatNumber(metric.subscribers) }}</span>
                  <span class="stat-label">Subscribers</span>
                </div>
                <div v-if="metric.views" class="metric-stat">
                  <span class="stat-value">{{ formatNumber(metric.views) }}</span>
                  <span class="stat-label">Views</span>
                </div>
                <div v-if="metric.engagement_rate" class="metric-stat">
                  <span class="stat-value">{{ metric.engagement_rate.toFixed(1) }}%</span>
                  <span class="stat-label">Engagement</span>
                </div>
              </div>
            </div>
            <a v-if="metric.profile_url" :href="metric.profile_url" target="_blank" class="metric-link">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                <polyline points="15 3 21 3 21 9"/>
                <line x1="10" y1="14" x2="21" y2="3"/>
              </svg>
            </a>
          </div>
        </div>
        <p v-if="metricsLastUpdated" class="metrics-updated">
          Last updated: {{ formatDateShort(metricsLastUpdated) }}
        </p>
      </div>

      <!-- Social Links Section -->
      <div v-if="podcast.social_links && podcast.social_links.length" class="section">
        <h2>Connect</h2>
        <div class="social-links">
          <a
            v-for="link in podcast.social_links"
            :key="link.platform"
            :href="link.url"
            target="_blank"
            rel="noopener noreferrer"
            class="social-link"
            :class="link.platform.toLowerCase()"
          >
            {{ link.platform }}
          </a>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { usePodcastStore } from '../stores/podcasts'
import { formatNumber, formatDateShort } from '../utils/formatters'
import api from '../services/api'

const route = useRoute()
const podcastStore = usePodcastStore()

const metrics = ref([])
const metricsLoading = ref(false)
const metricsLastUpdated = ref(null)

const podcast = computed(() => podcastStore.currentPodcast)
const loading = computed(() => podcastStore.loading)
const error = computed(() => podcastStore.error)

async function fetchMetrics() {
  if (!route.params.id) return
  metricsLoading.value = true
  try {
    const data = await api.getPodcastMetrics(route.params.id)
    metrics.value = data.metrics || []
    metricsLastUpdated.value = data.last_updated || null
  } catch (err) {
    console.error('Failed to fetch metrics:', err)
  } finally {
    metricsLoading.value = false
  }
}

onMounted(() => {
  podcastStore.fetchPodcast(route.params.id)
  fetchMetrics()
})
</script>

<style scoped>
.podcast-detail {
  max-width: 900px;
  margin: 0 auto;
}

.back-nav {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  color: #667eea;
  text-decoration: none;
  font-size: 0.95rem;
  margin-bottom: 2rem;
  transition: color 0.2s ease;
}

.back-nav:hover {
  color: #5568d3;
}

.podcast-header {
  display: grid;
  grid-template-columns: 200px 1fr;
  gap: 2rem;
  margin-bottom: 3rem;
  padding-bottom: 2rem;
  border-bottom: 1px solid #eee;
}

.podcast-artwork-large {
  width: 100%;
  border-radius: 12px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.podcast-info h1 {
  margin: 0 0 0.5rem 0;
  font-size: 1.75rem;
  color: #1a1a2e;
}

.author {
  color: #666;
  margin-bottom: 1rem;
  font-size: 1rem;
}

.description {
  line-height: 1.6;
  margin-bottom: 1rem;
  color: #444;
}

.meta {
  display: flex;
  align-items: center;
  gap: 1rem;
  margin-bottom: 1.5rem;
}

.meta-tag {
  padding: 0.25rem 0.75rem;
  background: #f0f4ff;
  color: #667eea;
  border-radius: 20px;
  font-size: 0.85rem;
  font-weight: 500;
}

.meta-item {
  font-size: 0.9rem;
  color: #666;
}

.actions {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.action-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.625rem 1.25rem;
  border-radius: 8px;
  text-decoration: none;
  font-size: 0.9rem;
  font-weight: 500;
  transition: all 0.2s ease;
}

.action-btn.primary {
  background: #667eea;
  color: white;
}

.action-btn.primary:hover {
  background: #5568d3;
}

.action-btn.secondary {
  background: #f0f0f0;
  color: #444;
}

.action-btn.secondary:hover {
  background: #e0e0e0;
}

.section {
  margin-bottom: 2.5rem;
}

.section h2 {
  margin: 0 0 1rem 0;
  font-size: 1.25rem;
  color: #1a1a2e;
}

.metrics-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 1rem;
}

.metric-card {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  padding: 1rem;
  background: white;
  border: 1px solid #eee;
  border-radius: 10px;
  position: relative;
  transition: box-shadow 0.2s ease;
}

.metric-card:hover {
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.metric-card.youtube { border-left: 3px solid #ff0000; }
.metric-card.twitter { border-left: 3px solid #1da1f2; }
.metric-card.instagram { border-left: 3px solid #e1306c; }
.metric-card.linkedin { border-left: 3px solid #0077b5; }
.metric-card.facebook { border-left: 3px solid #1877f2; }
.metric-card.tiktok { border-left: 3px solid #000; }
.metric-card.spotify { border-left: 3px solid #1db954; }

.metric-content {
  flex: 1;
}

.metric-platform {
  font-weight: 600;
  font-size: 0.9rem;
  margin-bottom: 0.5rem;
  text-transform: capitalize;
}

.metric-stats {
  display: flex;
  gap: 1rem;
  flex-wrap: wrap;
}

.metric-stat {
  display: flex;
  flex-direction: column;
}

.stat-value {
  font-weight: 600;
  font-size: 1rem;
  color: #1a1a2e;
}

.stat-label {
  font-size: 0.75rem;
  color: #888;
}

.metric-link {
  position: absolute;
  top: 0.75rem;
  right: 0.75rem;
  color: #999;
  transition: color 0.2s ease;
}

.metric-link:hover {
  color: #667eea;
}

.metrics-updated {
  margin-top: 1rem;
  font-size: 0.85rem;
  color: #888;
}

.social-links {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
}

.social-link {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 1rem;
  background: #f0f0f0;
  color: #444;
  border-radius: 6px;
  text-decoration: none;
  font-size: 0.9rem;
  transition: all 0.2s ease;
}

.social-link:hover {
  background: #e0e0e0;
}

.social-link.youtube:hover { background: #ff0000; color: white; }
.social-link.twitter:hover { background: #1a1a1a; color: white; }
.social-link.instagram:hover { background: #e1306c; color: white; }
.social-link.linkedin:hover { background: #0077b5; color: white; }
.social-link.facebook:hover { background: #1877f2; color: white; }
.social-link.tiktok:hover { background: #000; color: white; }
.social-link.spotify:hover { background: #1db954; color: white; }

.loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 4rem 2rem;
  text-align: center;
}

.loading-spinner {
  width: 40px;
  height: 40px;
  border: 3px solid #e0e0e0;
  border-top-color: #667eea;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
  margin-bottom: 1rem;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.error {
  padding: 2rem;
  text-align: center;
  color: #dc3545;
}

.back-link {
  display: inline-block;
  margin-top: 1rem;
  color: #667eea;
}

@media (max-width: 768px) {
  .podcast-header {
    grid-template-columns: 1fr;
  }

  .podcast-artwork-large {
    max-width: 200px;
    margin: 0 auto;
  }
}
</style>
