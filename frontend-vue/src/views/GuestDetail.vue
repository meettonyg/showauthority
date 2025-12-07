<template>
  <div class="container">
    <!-- Loading State -->
    <div v-if="loading" class="loading">
      <div class="spinner"></div>
      <span>Loading guest profile...</span>
    </div>

    <!-- Error State -->
    <div v-else-if="error" class="error">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="currentColor">
        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
      </svg>
      <p>{{ error }}</p>
      <router-link to="/guests" class="btn-back">Back to Guests</router-link>
    </div>

    <!-- Guest Profile -->
    <div v-else-if="guest" class="guest-profile">
      <!-- Back Navigation -->
      <router-link to="/guests" class="back-link">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
          <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/>
        </svg>
        Back to Guest Directory
      </router-link>

      <!-- Profile Header -->
      <div class="profile-header">
        <div class="profile-avatar">
          <img v-if="guest.avatar_url" :src="guest.avatar_url" :alt="guest.full_name" />
          <div v-else class="avatar-placeholder">
            {{ initials }}
          </div>
          <span v-if="guest.is_verified" class="verified-badge" title="Verified Profile">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
              <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
            </svg>
          </span>
        </div>

        <div class="profile-info">
          <h1 class="profile-name">{{ guest.full_name }}</h1>

          <div v-if="guest.title || guest.company" class="profile-title">
            <span v-if="guest.title">{{ guest.title }}</span>
            <span v-if="guest.title && guest.company"> at </span>
            <strong v-if="guest.company">{{ guest.company }}</strong>
          </div>

          <div v-if="guest.location || guest.city" class="profile-location">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
              <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
            </svg>
            {{ guest.location || [guest.city, guest.state, guest.country].filter(Boolean).join(', ') }}
          </div>

          <div class="profile-social">
            <a v-if="guest.linkedin_url" :href="guest.linkedin_url" target="_blank" class="social-btn linkedin">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                <path d="M19 3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h14m-.5 15.5v-5.3a3.26 3.26 0 0 0-3.26-3.26c-.85 0-1.84.52-2.32 1.3v-1.11h-2.79v8.37h2.79v-4.93c0-.77.62-1.4 1.39-1.4a1.4 1.4 0 0 1 1.4 1.4v4.93h2.79M6.88 8.56a1.68 1.68 0 0 0 1.68-1.68c0-.93-.75-1.69-1.68-1.69a1.69 1.69 0 0 0-1.69 1.69c0 .93.76 1.68 1.69 1.68m1.39 9.94v-8.37H5.5v8.37h2.77z"/>
              </svg>
              LinkedIn
            </a>
            <a v-if="guest.twitter_url" :href="guest.twitter_url" target="_blank" class="social-btn twitter">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
              </svg>
              Twitter/X
            </a>
            <a v-if="guest.website_url" :href="guest.website_url" target="_blank" class="social-btn website">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/>
              </svg>
              Website
            </a>
          </div>

          <div v-if="guest.status" class="profile-status">
            <span :class="['status-badge', `status-${guest.status}`]">
              {{ formatStatus(guest.status) }}
            </span>
            <span v-if="guest.priority" class="priority-badge">
              Priority: {{ guest.priority }}
            </span>
          </div>
        </div>

        <div class="profile-actions">
          <button @click="verifyGuest" v-if="!guest.is_verified" class="btn-action verify">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
              <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
            </svg>
            Verify Profile
          </button>
          <button @click="showEditModal = true" class="btn-action edit">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
              <path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/>
            </svg>
            Edit
          </button>
        </div>
      </div>

      <!-- Stats Cards -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-value">{{ guest.appearances?.length || 0 }}</div>
          <div class="stat-label">Podcast Appearances</div>
        </div>
        <div class="stat-card">
          <div class="stat-value">{{ guest.topics?.length || 0 }}</div>
          <div class="stat-label">Expertise Topics</div>
        </div>
        <div class="stat-card">
          <div class="stat-value">{{ networkCount }}</div>
          <div class="stat-label">Network Connections</div>
        </div>
        <div class="stat-card">
          <div class="stat-value">{{ guest.data_quality_score || 'N/A' }}</div>
          <div class="stat-label">Data Quality Score</div>
        </div>
      </div>

      <!-- Content Tabs -->
      <div class="content-tabs">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          @click="activeTab = tab.id"
          :class="['tab-btn', { active: activeTab === tab.id }]"
        >
          {{ tab.label }}
          <span v-if="tab.count !== undefined" class="tab-count">{{ tab.count }}</span>
        </button>
      </div>

      <!-- Tab Content -->
      <div class="tab-content">
        <!-- About Tab -->
        <div v-if="activeTab === 'about'" class="tab-panel">
          <div class="info-section" v-if="guest.bio">
            <h3>Bio</h3>
            <p class="bio-text">{{ guest.bio }}</p>
          </div>

          <div class="info-grid">
            <div class="info-section" v-if="guest.expertise_areas">
              <h3>Expertise Areas</h3>
              <div class="tag-list">
                <span v-for="area in parseArray(guest.expertise_areas)" :key="area" class="tag">
                  {{ area }}
                </span>
              </div>
            </div>

            <div class="info-section" v-if="guest.industry">
              <h3>Industry</h3>
              <p>{{ guest.industry }}</p>
            </div>

            <div class="info-section" v-if="guest.past_companies">
              <h3>Past Companies</h3>
              <div class="tag-list">
                <span v-for="company in parseArray(guest.past_companies)" :key="company" class="tag company">
                  {{ company }}
                </span>
              </div>
            </div>

            <div class="info-section" v-if="guest.education">
              <h3>Education</h3>
              <p>{{ guest.education }}</p>
            </div>
          </div>

          <div class="info-section" v-if="guest.linkedin_connections || guest.twitter_followers">
            <h3>Social Reach</h3>
            <div class="social-stats">
              <div v-if="guest.linkedin_connections" class="social-stat">
                <strong>{{ formatNumber(guest.linkedin_connections) }}</strong>
                <span>LinkedIn Connections</span>
              </div>
              <div v-if="guest.twitter_followers" class="social-stat">
                <strong>{{ formatNumber(guest.twitter_followers) }}</strong>
                <span>Twitter Followers</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Appearances Tab -->
        <div v-if="activeTab === 'appearances'" class="tab-panel">
          <div v-if="!guest.appearances || guest.appearances.length === 0" class="empty-tab">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="currentColor">
              <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
            </svg>
            <p>No podcast appearances recorded yet</p>
          </div>

          <div v-else class="appearances-list">
            <div
              v-for="appearance in guest.appearances"
              :key="appearance.id"
              class="appearance-card"
            >
              <div class="appearance-podcast">
                <img
                  v-if="appearance.podcast_artwork"
                  :src="appearance.podcast_artwork"
                  :alt="appearance.podcast_title"
                  class="podcast-thumb"
                />
                <div v-else class="podcast-thumb-placeholder">
                  <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm-1 1.93c-3.94-.49-7-3.85-7-7.93h2c0 2.76 2.24 5 5 5s5-2.24 5-5h2c0 4.08-3.06 7.44-7 7.93V20h4v2H8v-2h4v-4.07z"/>
                  </svg>
                </div>
                <div class="appearance-info">
                  <router-link
                    v-if="appearance.podcast_id"
                    :to="`/podcasts/${appearance.podcast_id}`"
                    class="podcast-name"
                  >
                    {{ appearance.podcast_title || 'Unknown Podcast' }}
                  </router-link>
                  <span v-else class="podcast-name">{{ appearance.podcast_title || 'Unknown Podcast' }}</span>
                  <span v-if="appearance.episode_title" class="episode-title">
                    {{ appearance.episode_title }}
                  </span>
                </div>
              </div>
              <div class="appearance-meta">
                <span v-if="appearance.appearance_date" class="appearance-date">
                  {{ formatDate(appearance.appearance_date) }}
                </span>
                <span v-if="appearance.role" class="appearance-role">
                  {{ appearance.role }}
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- Topics Tab -->
        <div v-if="activeTab === 'topics'" class="tab-panel">
          <div v-if="!guest.topics || guest.topics.length === 0" class="empty-tab">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="currentColor">
              <path d="M21.41 11.58l-9-9C12.05 2.22 11.55 2 11 2H4c-1.1 0-2 .9-2 2v7c0 .55.22 1.05.59 1.42l9 9c.36.36.86.58 1.41.58.55 0 1.05-.22 1.41-.59l7-7c.37-.36.59-.86.59-1.41 0-.55-.23-1.06-.59-1.42zM5.5 7C4.67 7 4 6.33 4 5.5S4.67 4 5.5 4 7 4.67 7 5.5 6.33 7 5.5 7z"/>
            </svg>
            <p>No expertise topics assigned yet</p>
          </div>

          <div v-else class="topics-grid">
            <div
              v-for="topic in guest.topics"
              :key="topic.id"
              class="topic-card"
            >
              <div class="topic-header">
                <span class="topic-name">{{ topic.name }}</span>
                <span v-if="topic.category" class="topic-category">{{ topic.category }}</span>
              </div>
              <div class="topic-meta">
                <div v-if="topic.confidence" class="confidence-bar">
                  <div class="confidence-fill" :style="{ width: topic.confidence + '%' }"></div>
                </div>
                <span class="confidence-label">{{ topic.confidence || 100 }}% confidence</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Network Tab -->
        <div v-if="activeTab === 'network'" class="tab-panel">
          <div v-if="!network || network.length === 0" class="empty-tab">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="currentColor">
              <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/>
            </svg>
            <p>No network connections found</p>
          </div>

          <div v-else>
            <NetworkGraph
              :connections="network"
              :centerGuest="guest"
              class="network-visualization"
            />

            <div class="network-list">
              <h4>Connected Guests</h4>
              <div
                v-for="connection in network"
                :key="connection.id"
                class="connection-card"
              >
                <router-link :to="`/guests/${connection.connected_guest_id}`" class="connection-link">
                  <div class="connection-avatar">
                    {{ getInitials(connection.connected_guest_name) }}
                  </div>
                  <div class="connection-info">
                    <span class="connection-name">{{ connection.connected_guest_name }}</span>
                    <span class="connection-type">
                      {{ connection.degree === 1 ? '1st degree' : '2nd degree' }} -
                      {{ connection.connection_type }}
                    </span>
                  </div>
                </router-link>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Metadata Footer -->
      <div class="profile-footer">
        <div class="meta-info">
          <span v-if="guest.source">Source: {{ guest.source }}</span>
          <span v-if="guest.created_at">Added: {{ formatDate(guest.created_at) }}</span>
          <span v-if="guest.updated_at">Updated: {{ formatDate(guest.updated_at) }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useGuestStore } from '../stores/guests'
import NetworkGraph from '../components/NetworkGraph.vue'

const route = useRoute()
const guestStore = useGuestStore()

const activeTab = ref('about')
const showEditModal = ref(false)
const network = ref([])

const guest = computed(() => guestStore.currentGuest)
const loading = computed(() => guestStore.loading)
const error = computed(() => guestStore.error)

const initials = computed(() => {
  const name = guest.value?.full_name || ''
  const parts = name.split(' ')
  if (parts.length >= 2) {
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
  }
  return name.substring(0, 2).toUpperCase()
})

const networkCount = computed(() => {
  return network.value?.length || guest.value?.network?.length || 0
})

const tabs = computed(() => [
  { id: 'about', label: 'About' },
  { id: 'appearances', label: 'Appearances', count: guest.value?.appearances?.length || 0 },
  { id: 'topics', label: 'Topics', count: guest.value?.topics?.length || 0 },
  { id: 'network', label: 'Network', count: networkCount.value }
])

function formatStatus(status) {
  const statusMap = {
    potential: 'Potential Guest',
    active: 'Active',
    contacted: 'Contacted',
    scheduled: 'Scheduled',
    aired: 'Aired',
    declined: 'Declined'
  }
  return statusMap[status] || status
}

function formatDate(dateStr) {
  if (!dateStr) return ''
  const date = new Date(dateStr)
  return date.toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric'
  })
}

function formatNumber(num) {
  if (!num) return '0'
  if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M'
  if (num >= 1000) return (num / 1000).toFixed(1) + 'K'
  return num.toString()
}

function parseArray(value) {
  if (!value) return []
  if (Array.isArray(value)) return value
  if (typeof value === 'string') {
    try {
      return JSON.parse(value)
    } catch {
      return value.split(',').map(s => s.trim())
    }
  }
  return []
}

function getInitials(name) {
  if (!name) return '??'
  const parts = name.split(' ')
  if (parts.length >= 2) {
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
  }
  return name.substring(0, 2).toUpperCase()
}

async function verifyGuest() {
  const success = await guestStore.verifyGuest(guest.value.id)
  if (success) {
    // Refresh guest data
    await guestStore.fetchGuest(route.params.id)
  }
}

async function loadNetwork() {
  if (guest.value?.id) {
    const networkData = await guestStore.fetchGuestNetwork(guest.value.id)
    network.value = networkData || guest.value?.network || []
  }
}

onMounted(async () => {
  await guestStore.fetchGuest(route.params.id)
  await loadNetwork()
})

watch(() => route.params.id, async (newId) => {
  if (newId) {
    await guestStore.fetchGuest(newId)
    await loadNetwork()
    activeTab.value = 'about'
  }
})
</script>

<style scoped>
.container {
  max-width: 1000px;
  margin: 0 auto;
  padding: 2rem;
}

/* Loading & Error States */
.loading,
.error {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 4rem 2rem;
  text-align: center;
}

.spinner {
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
  color: #dc3545;
}

.error svg {
  opacity: 0.5;
  margin-bottom: 1rem;
}

.btn-back {
  margin-top: 1rem;
  padding: 0.625rem 1.5rem;
  background: #667eea;
  color: white;
  text-decoration: none;
  border-radius: 6px;
}

/* Back Link */
.back-link {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  color: #667eea;
  text-decoration: none;
  font-size: 0.9rem;
  margin-bottom: 1.5rem;
  transition: color 0.2s;
}

.back-link:hover {
  color: #5568d3;
}

/* Profile Header */
.profile-header {
  display: flex;
  gap: 2rem;
  padding: 2rem;
  background: white;
  border-radius: 16px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
  margin-bottom: 1.5rem;
}

.profile-avatar {
  position: relative;
  flex-shrink: 0;
}

.profile-avatar img {
  width: 120px;
  height: 120px;
  border-radius: 50%;
  object-fit: cover;
}

.avatar-placeholder {
  width: 120px;
  height: 120px;
  border-radius: 50%;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 600;
  font-size: 2.5rem;
}

.verified-badge {
  position: absolute;
  bottom: 4px;
  right: 4px;
  width: 32px;
  height: 32px;
  background: #10b981;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
  border: 3px solid white;
}

.profile-info {
  flex: 1;
}

.profile-name {
  font-size: 1.75rem;
  font-weight: 700;
  margin: 0 0 0.5rem 0;
  color: #1a1a1a;
}

.profile-title {
  font-size: 1.1rem;
  color: #666;
  margin-bottom: 0.5rem;
}

.profile-location {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.9rem;
  color: #888;
  margin-bottom: 1rem;
}

.profile-social {
  display: flex;
  gap: 0.75rem;
  margin-bottom: 1rem;
}

.social-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 1rem;
  border-radius: 6px;
  font-size: 0.85rem;
  text-decoration: none;
  transition: all 0.2s;
}

.social-btn.linkedin {
  background: #e8f4f8;
  color: #0a66c2;
}

.social-btn.linkedin:hover {
  background: #0a66c2;
  color: white;
}

.social-btn.twitter {
  background: #e8f4fc;
  color: #1d9bf0;
}

.social-btn.twitter:hover {
  background: #1d9bf0;
  color: white;
}

.social-btn.website {
  background: #f3f4f6;
  color: #4b5563;
}

.social-btn.website:hover {
  background: #4b5563;
  color: white;
}

.profile-status {
  display: flex;
  gap: 0.75rem;
}

.status-badge {
  padding: 0.35rem 0.75rem;
  border-radius: 6px;
  font-size: 0.8rem;
  font-weight: 500;
}

.status-potential { background: #fef3c7; color: #92400e; }
.status-active { background: #d1fae5; color: #065f46; }
.status-contacted { background: #dbeafe; color: #1e40af; }
.status-scheduled { background: #e0e7ff; color: #3730a3; }
.status-aired { background: #d1fae5; color: #065f46; }
.status-declined { background: #fee2e2; color: #991b1b; }

.priority-badge {
  padding: 0.35rem 0.75rem;
  background: #f3f4f6;
  border-radius: 6px;
  font-size: 0.8rem;
  color: #666;
}

.profile-actions {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  flex-shrink: 0;
}

.btn-action {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.625rem 1rem;
  border: none;
  border-radius: 8px;
  font-size: 0.9rem;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-action.verify {
  background: #d1fae5;
  color: #065f46;
}

.btn-action.verify:hover {
  background: #10b981;
  color: white;
}

.btn-action.edit {
  background: #f3f4f6;
  color: #4b5563;
}

.btn-action.edit:hover {
  background: #e5e7eb;
}

/* Stats Grid */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 1rem;
  margin-bottom: 1.5rem;
}

.stat-card {
  background: white;
  padding: 1.25rem;
  border-radius: 12px;
  text-align: center;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.stat-value {
  font-size: 1.75rem;
  font-weight: 700;
  color: #667eea;
}

.stat-label {
  font-size: 0.85rem;
  color: #666;
  margin-top: 0.25rem;
}

/* Tabs */
.content-tabs {
  display: flex;
  gap: 0.5rem;
  margin-bottom: 1.5rem;
  border-bottom: 2px solid #e0e0e0;
  padding-bottom: 0;
}

.tab-btn {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.75rem 1.25rem;
  background: none;
  border: none;
  border-bottom: 2px solid transparent;
  margin-bottom: -2px;
  font-size: 0.95rem;
  color: #666;
  cursor: pointer;
  transition: all 0.2s;
}

.tab-btn:hover {
  color: #667eea;
}

.tab-btn.active {
  color: #667eea;
  border-bottom-color: #667eea;
  font-weight: 500;
}

.tab-count {
  background: #e5e7eb;
  padding: 0.125rem 0.5rem;
  border-radius: 10px;
  font-size: 0.8rem;
}

.tab-btn.active .tab-count {
  background: #667eea;
  color: white;
}

/* Tab Content */
.tab-content {
  background: white;
  border-radius: 12px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.tab-panel {
  padding: 1.5rem;
}

.empty-tab {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 3rem;
  color: #888;
}

.empty-tab svg {
  opacity: 0.3;
  margin-bottom: 1rem;
}

/* About Tab */
.info-section {
  margin-bottom: 1.5rem;
}

.info-section h3 {
  font-size: 1rem;
  font-weight: 600;
  color: #444;
  margin: 0 0 0.75rem 0;
}

.bio-text {
  line-height: 1.6;
  color: #555;
}

.info-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 1.5rem;
}

.tag-list {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.tag {
  padding: 0.35rem 0.75rem;
  background: #f3f4f6;
  border-radius: 6px;
  font-size: 0.85rem;
  color: #4b5563;
}

.tag.company {
  background: #e0e7ff;
  color: #3730a3;
}

.social-stats {
  display: flex;
  gap: 2rem;
}

.social-stat {
  display: flex;
  flex-direction: column;
}

.social-stat strong {
  font-size: 1.25rem;
  color: #1a1a1a;
}

.social-stat span {
  font-size: 0.85rem;
  color: #888;
}

/* Appearances Tab */
.appearances-list {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.appearance-card {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem;
  background: #f8f9fa;
  border-radius: 10px;
}

.appearance-podcast {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.podcast-thumb {
  width: 48px;
  height: 48px;
  border-radius: 8px;
  object-fit: cover;
}

.podcast-thumb-placeholder {
  width: 48px;
  height: 48px;
  border-radius: 8px;
  background: #e5e7eb;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #9ca3af;
}

.appearance-info {
  display: flex;
  flex-direction: column;
}

.podcast-name {
  font-weight: 500;
  color: #1a1a1a;
  text-decoration: none;
}

.podcast-name:hover {
  color: #667eea;
}

.episode-title {
  font-size: 0.85rem;
  color: #666;
}

.appearance-meta {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 0.25rem;
}

.appearance-date {
  font-size: 0.85rem;
  color: #888;
}

.appearance-role {
  font-size: 0.8rem;
  padding: 0.2rem 0.5rem;
  background: #e0e7ff;
  color: #3730a3;
  border-radius: 4px;
}

/* Topics Tab */
.topics-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 1rem;
}

.topic-card {
  padding: 1rem;
  background: #f8f9fa;
  border-radius: 10px;
}

.topic-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 0.75rem;
}

.topic-name {
  font-weight: 500;
  color: #1a1a1a;
}

.topic-category {
  font-size: 0.75rem;
  padding: 0.2rem 0.5rem;
  background: #e5e7eb;
  border-radius: 4px;
  color: #666;
}

.topic-meta {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.confidence-bar {
  height: 4px;
  background: #e5e7eb;
  border-radius: 2px;
  overflow: hidden;
}

.confidence-fill {
  height: 100%;
  background: #667eea;
  border-radius: 2px;
  transition: width 0.3s ease;
}

.confidence-label {
  font-size: 0.75rem;
  color: #888;
}

/* Network Tab */
.network-visualization {
  height: 300px;
  margin-bottom: 1.5rem;
  background: #f8f9fa;
  border-radius: 10px;
}

.network-list h4 {
  font-size: 1rem;
  margin: 0 0 1rem 0;
  color: #444;
}

.connection-card {
  margin-bottom: 0.75rem;
}

.connection-link {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.75rem;
  background: #f8f9fa;
  border-radius: 8px;
  text-decoration: none;
  transition: background 0.2s;
}

.connection-link:hover {
  background: #e5e7eb;
}

.connection-avatar {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 500;
  font-size: 0.85rem;
}

.connection-info {
  display: flex;
  flex-direction: column;
}

.connection-name {
  font-weight: 500;
  color: #1a1a1a;
}

.connection-type {
  font-size: 0.8rem;
  color: #888;
}

/* Profile Footer */
.profile-footer {
  margin-top: 2rem;
  padding-top: 1rem;
  border-top: 1px solid #e0e0e0;
}

.meta-info {
  display: flex;
  gap: 2rem;
  font-size: 0.8rem;
  color: #888;
}

/* Responsive */
@media (max-width: 768px) {
  .container {
    padding: 1rem;
  }

  .profile-header {
    flex-direction: column;
    align-items: center;
    text-align: center;
  }

  .profile-social {
    justify-content: center;
  }

  .profile-status {
    justify-content: center;
  }

  .profile-actions {
    flex-direction: row;
    width: 100%;
  }

  .btn-action {
    flex: 1;
    justify-content: center;
  }

  .stats-grid {
    grid-template-columns: repeat(2, 1fr);
  }

  .info-grid {
    grid-template-columns: 1fr;
  }

  .content-tabs {
    overflow-x: auto;
  }
}
</style>
