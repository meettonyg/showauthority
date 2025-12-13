<template>
  <div class="container">
    <div v-if="loading" class="loading">
      <div class="loading-spinner"></div>
      <p>Loading guest profile...</p>
    </div>

    <div v-else-if="error" class="error">
      <p>{{ error }}</p>
      <router-link to="/guests" class="back-link">Back to Guest Directory</router-link>
    </div>

    <div v-else-if="guest" class="guest-detail">
      <!-- Back Navigation -->
      <router-link to="/guests" class="back-nav">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M19 12H5M12 19l-7-7 7-7"/>
        </svg>
        Back to Guest Directory
      </router-link>

      <!-- Header Section -->
      <div class="guest-header">
        <div class="guest-avatar-large">
          <img
            v-if="guest.headshot_url"
            :src="guest.headshot_url"
            :alt="guest.full_name"
            class="avatar-image"
          />
          <div v-else class="avatar-placeholder">
            {{ initials }}
          </div>
          <span v-if="guest.is_verified" class="verified-badge" title="Verified Guest">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
              <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
            </svg>
          </span>
        </div>

        <div class="guest-info">
          <h1>{{ guest.full_name }}</h1>
          <p v-if="guest.title || guest.company" class="guest-title">
            {{ guest.title }}<span v-if="guest.title && guest.company"> at </span>{{ guest.company }}
          </p>

          <div v-if="guest.bio" class="guest-bio">
            {{ guest.bio }}
          </div>

          <!-- Social Links -->
          <div class="social-links">
            <a
              v-if="guest.linkedin_url"
              :href="guest.linkedin_url"
              target="_blank"
              rel="noopener noreferrer"
              class="social-link linkedin"
            >
              <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                <path d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.762 0 5-2.239 5-5v-14c0-2.761-2.238-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.79-1.75-1.764s.784-1.764 1.75-1.764 1.75.79 1.75 1.764-.783 1.764-1.75 1.764zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z"/>
              </svg>
              LinkedIn
            </a>
            <a
              v-if="guest.twitter_url"
              :href="guest.twitter_url"
              target="_blank"
              rel="noopener noreferrer"
              class="social-link twitter"
            >
              <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
              </svg>
              Twitter/X
            </a>
            <a
              v-if="guest.website_url"
              :href="guest.website_url"
              target="_blank"
              rel="noopener noreferrer"
              class="social-link website"
            >
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
              </svg>
              Website
            </a>
            <a
              v-if="guest.email && !guest.email_private"
              :href="`mailto:${guest.email}`"
              class="social-link email"
            >
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                <polyline points="22,6 12,13 2,6"/>
              </svg>
              Email
            </a>
          </div>
        </div>
      </div>

      <!-- Topics Section -->
      <div v-if="guest.topics && guest.topics.length" class="section">
        <h2>Expertise & Topics</h2>
        <div class="topics-list">
          <span v-for="topic in guest.topics" :key="topic.id" class="topic-tag">
            {{ topic.name }}
            <span v-if="topic.confidence" class="topic-confidence">{{ topic.confidence }}%</span>
          </span>
        </div>
      </div>

      <!-- Appearances Section -->
      <div v-if="guest.appearances && guest.appearances.length" class="section">
        <h2>Podcast Appearances</h2>
        <div class="appearances-list">
          <div v-for="appearance in guest.appearances" :key="appearance.id" class="appearance-card">
            <div class="appearance-podcast">
              <img
                v-if="appearance.podcast_artwork"
                :src="appearance.podcast_artwork"
                :alt="appearance.podcast_title"
                class="podcast-artwork"
              />
              <div class="podcast-info">
                <h3>{{ appearance.podcast_title || 'Unknown Podcast' }}</h3>
                <p v-if="appearance.episode_title" class="episode-title">{{ appearance.episode_title }}</p>
              </div>
            </div>
            <div class="appearance-meta">
              <span v-if="appearance.appearance_date" class="appearance-date">
                {{ formatDate(appearance.appearance_date) }}
              </span>
              <span v-if="appearance.role" class="appearance-role">{{ appearance.role }}</span>
            </div>
            <a
              v-if="appearance.episode_url"
              :href="appearance.episode_url"
              target="_blank"
              rel="noopener noreferrer"
              class="listen-btn"
            >
              Listen
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                <polyline points="15 3 21 3 21 9"/>
                <line x1="10" y1="14" x2="21" y2="3"/>
              </svg>
            </a>
          </div>
        </div>
      </div>

      <!-- Network Section -->
      <div v-if="guest.network && guest.network.length" class="section">
        <h2>Network Connections</h2>
        <p class="section-description">Other guests who have appeared on the same podcasts</p>
        <div class="network-grid">
          <router-link
            v-for="connection in guest.network.slice(0, 6)"
            :key="connection.id"
            :to="`/guests/${connection.id}`"
            class="network-card"
          >
            <div class="network-avatar">
              <img
                v-if="connection.headshot_url"
                :src="connection.headshot_url"
                :alt="connection.full_name"
              />
              <div v-else class="avatar-placeholder-small">
                {{ getInitials(connection.full_name) }}
              </div>
            </div>
            <div class="network-info">
              <span class="network-name">{{ connection.full_name }}</span>
              <span class="network-meta">{{ connection.shared_podcasts }} shared podcast{{ connection.shared_podcasts !== 1 ? 's' : '' }}</span>
            </div>
          </router-link>
        </div>
        <router-link
          v-if="guest.network.length > 6"
          :to="`/guests/${guest.id}/network`"
          class="view-all-link"
        >
          View all {{ guest.network.length }} connections
        </router-link>
      </div>

      <!-- Guest Meta Info -->
      <div class="meta-section">
        <div class="meta-item">
          <span class="meta-label">Added</span>
          <span class="meta-value">{{ formatDate(guest.created_at) }}</span>
        </div>
        <div v-if="guest.source" class="meta-item">
          <span class="meta-label">Source</span>
          <span class="meta-value">{{ guest.source }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useGuestStore } from '../stores/guests'
import { formatDate, getInitials } from '../utils/formatters'

const route = useRoute()
const guestStore = useGuestStore()

const guest = computed(() => guestStore.currentGuest)
const loading = computed(() => guestStore.loading)
const error = computed(() => guestStore.error)

const initials = computed(() => getInitials(guest.value?.full_name || ''))

onMounted(() => {
  guestStore.fetchGuest(route.params.id)
})
</script>

<style scoped>
.guest-detail {
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

.guest-header {
  display: grid;
  grid-template-columns: 150px 1fr;
  gap: 2rem;
  margin-bottom: 3rem;
  padding-bottom: 2rem;
  border-bottom: 1px solid #eee;
}

.guest-avatar-large {
  position: relative;
}

.guest-avatar-large .avatar-image {
  width: 150px;
  height: 150px;
  border-radius: 50%;
  object-fit: cover;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.guest-avatar-large .avatar-placeholder {
  width: 150px;
  height: 150px;
  border-radius: 50%;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 3rem;
  font-weight: 600;
}

.guest-avatar-large .verified-badge {
  position: absolute;
  bottom: 8px;
  right: 8px;
  width: 32px;
  height: 32px;
  background: #10b981;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
  border: 3px solid white;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.guest-info h1 {
  margin: 0 0 0.5rem 0;
  font-size: 2rem;
  color: #1a1a2e;
}

.guest-title {
  margin: 0 0 1rem 0;
  font-size: 1.1rem;
  color: #666;
}

.guest-bio {
  margin-bottom: 1.5rem;
  line-height: 1.6;
  color: #444;
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
  border-radius: 6px;
  text-decoration: none;
  font-size: 0.9rem;
  font-weight: 500;
  transition: all 0.2s ease;
}

.social-link.linkedin {
  background: #e8f4fc;
  color: #0077b5;
}

.social-link.linkedin:hover {
  background: #0077b5;
  color: white;
}

.social-link.twitter {
  background: #f0f0f0;
  color: #1a1a1a;
}

.social-link.twitter:hover {
  background: #1a1a1a;
  color: white;
}

.social-link.website {
  background: #f0f4ff;
  color: #667eea;
}

.social-link.website:hover {
  background: #667eea;
  color: white;
}

.social-link.email {
  background: #fef3e7;
  color: #d97706;
}

.social-link.email:hover {
  background: #d97706;
  color: white;
}

.section {
  margin-bottom: 2.5rem;
}

.section h2 {
  margin: 0 0 0.5rem 0;
  font-size: 1.25rem;
  color: #1a1a2e;
}

.section-description {
  margin: 0 0 1rem 0;
  color: #666;
  font-size: 0.95rem;
}

.topics-list {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.topic-tag {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 1rem;
  background: #f0f4ff;
  color: #667eea;
  border-radius: 20px;
  font-size: 0.9rem;
  font-weight: 500;
}

.topic-confidence {
  background: #667eea;
  color: white;
  padding: 0.125rem 0.375rem;
  border-radius: 10px;
  font-size: 0.75rem;
}

.appearances-list {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.appearance-card {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1rem;
  background: white;
  border: 1px solid #eee;
  border-radius: 10px;
  transition: box-shadow 0.2s ease;
}

.appearance-card:hover {
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.appearance-podcast {
  display: flex;
  align-items: center;
  gap: 1rem;
  flex: 1;
}

.podcast-artwork {
  width: 60px;
  height: 60px;
  border-radius: 8px;
  object-fit: cover;
}

.podcast-info h3 {
  margin: 0 0 0.25rem 0;
  font-size: 1rem;
}

.episode-title {
  margin: 0;
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
  color: #666;
}

.appearance-role {
  font-size: 0.8rem;
  padding: 0.125rem 0.5rem;
  background: #e5e5e5;
  border-radius: 4px;
  text-transform: capitalize;
}

.listen-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  padding: 0.5rem 1rem;
  background: #667eea;
  color: white;
  text-decoration: none;
  border-radius: 6px;
  font-size: 0.85rem;
  font-weight: 500;
  transition: background 0.2s ease;
}

.listen-btn:hover {
  background: #5568d3;
}

.network-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 1rem;
}

.network-card {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem;
  background: white;
  border: 1px solid #eee;
  border-radius: 8px;
  text-decoration: none;
  color: inherit;
  transition: all 0.2s ease;
}

.network-card:hover {
  border-color: #667eea;
  box-shadow: 0 2px 8px rgba(102, 126, 234, 0.1);
}

.network-avatar img,
.avatar-placeholder-small {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  object-fit: cover;
}

.avatar-placeholder-small {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.875rem;
  font-weight: 600;
}

.network-info {
  display: flex;
  flex-direction: column;
}

.network-name {
  font-weight: 500;
  font-size: 0.9rem;
}

.network-meta {
  font-size: 0.8rem;
  color: #666;
}

.view-all-link {
  display: inline-block;
  margin-top: 1rem;
  color: #667eea;
  text-decoration: none;
  font-size: 0.95rem;
}

.view-all-link:hover {
  text-decoration: underline;
}

.meta-section {
  display: flex;
  gap: 2rem;
  padding-top: 2rem;
  border-top: 1px solid #eee;
  color: #888;
  font-size: 0.85rem;
}

.meta-item {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.meta-label {
  text-transform: uppercase;
  font-size: 0.75rem;
  letter-spacing: 0.5px;
}

.meta-value {
  color: #444;
}

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
  .guest-header {
    grid-template-columns: 1fr;
    text-align: center;
  }

  .guest-avatar-large {
    display: flex;
    justify-content: center;
  }

  .social-links {
    justify-content: center;
  }

  .appearance-card {
    flex-direction: column;
    align-items: flex-start;
  }

  .appearance-meta {
    flex-direction: row;
    align-items: center;
  }
}
</style>
