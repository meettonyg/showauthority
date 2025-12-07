<template>
  <router-link :to="`/guests/${guest.id}`" class="guest-card">
    <div class="guest-avatar">
      <img v-if="guest.avatar_url" :src="guest.avatar_url" :alt="guest.full_name" />
      <div v-else class="avatar-placeholder">
        {{ initials }}
      </div>
      <span v-if="guest.is_verified" class="verified-badge" title="Verified">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
          <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
        </svg>
      </span>
    </div>

    <div class="guest-content">
      <h3 class="guest-name">{{ guest.full_name }}</h3>

      <div v-if="guest.title || guest.company" class="guest-title">
        <span v-if="guest.title">{{ guest.title }}</span>
        <span v-if="guest.title && guest.company"> at </span>
        <span v-if="guest.company" class="company">{{ guest.company }}</span>
      </div>

      <div v-if="guest.bio" class="guest-bio">
        {{ truncate(guest.bio, 100) }}
      </div>

      <div class="guest-meta">
        <span v-if="guest.appearances_count" class="meta-item appearances">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
          </svg>
          {{ guest.appearances_count }} appearances
        </span>

        <span v-if="guest.topics && guest.topics.length" class="meta-item topics">
          {{ guest.topics.length }} topics
        </span>

        <span v-if="guest.status" :class="['status-badge', `status-${guest.status}`]">
          {{ formatStatus(guest.status) }}
        </span>
      </div>

      <div v-if="guest.topics && guest.topics.length" class="guest-topics">
        <span
          v-for="topic in guest.topics.slice(0, 3)"
          :key="topic.id"
          class="topic-tag"
        >
          {{ topic.name }}
        </span>
        <span v-if="guest.topics.length > 3" class="topic-more">
          +{{ guest.topics.length - 3 }} more
        </span>
      </div>
    </div>

    <div class="guest-social">
      <a v-if="guest.linkedin_url" :href="guest.linkedin_url" target="_blank" @click.stop class="social-link linkedin" title="LinkedIn">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
          <path d="M19 3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h14m-.5 15.5v-5.3a3.26 3.26 0 0 0-3.26-3.26c-.85 0-1.84.52-2.32 1.3v-1.11h-2.79v8.37h2.79v-4.93c0-.77.62-1.4 1.39-1.4a1.4 1.4 0 0 1 1.4 1.4v4.93h2.79M6.88 8.56a1.68 1.68 0 0 0 1.68-1.68c0-.93-.75-1.69-1.68-1.69a1.69 1.69 0 0 0-1.69 1.69c0 .93.76 1.68 1.69 1.68m1.39 9.94v-8.37H5.5v8.37h2.77z"/>
        </svg>
      </a>
      <a v-if="guest.twitter_url" :href="guest.twitter_url" target="_blank" @click.stop class="social-link twitter" title="Twitter/X">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
          <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
        </svg>
      </a>
    </div>
  </router-link>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  guest: {
    type: Object,
    required: true
  }
})

const initials = computed(() => {
  const name = props.guest.full_name || ''
  const parts = name.split(' ')
  if (parts.length >= 2) {
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
  }
  return name.substring(0, 2).toUpperCase()
})

function truncate(text, length) {
  if (!text) return ''
  return text.length > length ? text.substring(0, length) + '...' : text
}

function formatStatus(status) {
  const statusMap = {
    potential: 'Potential',
    active: 'Active',
    contacted: 'Contacted',
    scheduled: 'Scheduled',
    aired: 'Aired',
    declined: 'Declined'
  }
  return statusMap[status] || status
}
</script>

<style scoped>
.guest-card {
  display: flex;
  gap: 1rem;
  padding: 1.25rem;
  border: 1px solid #e0e0e0;
  border-radius: 12px;
  background: white;
  text-decoration: none;
  color: inherit;
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.guest-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

.guest-avatar {
  position: relative;
  flex-shrink: 0;
}

.guest-avatar img {
  width: 64px;
  height: 64px;
  border-radius: 50%;
  object-fit: cover;
}

.avatar-placeholder {
  width: 64px;
  height: 64px;
  border-radius: 50%;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 600;
  font-size: 1.25rem;
}

.verified-badge {
  position: absolute;
  bottom: 0;
  right: 0;
  width: 20px;
  height: 20px;
  background: #10b981;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
  border: 2px solid white;
}

.guest-content {
  flex: 1;
  min-width: 0;
}

.guest-name {
  font-size: 1.1rem;
  font-weight: 600;
  margin: 0 0 0.25rem 0;
  color: #1a1a1a;
}

.guest-title {
  font-size: 0.9rem;
  color: #666;
  margin-bottom: 0.5rem;
}

.guest-title .company {
  font-weight: 500;
  color: #444;
}

.guest-bio {
  font-size: 0.85rem;
  color: #666;
  line-height: 1.4;
  margin-bottom: 0.75rem;
}

.guest-meta {
  display: flex;
  align-items: center;
  gap: 1rem;
  font-size: 0.8rem;
  color: #888;
  margin-bottom: 0.5rem;
}

.meta-item {
  display: flex;
  align-items: center;
  gap: 0.25rem;
}

.meta-item svg {
  opacity: 0.7;
}

.status-badge {
  padding: 0.2rem 0.5rem;
  border-radius: 4px;
  font-size: 0.75rem;
  font-weight: 500;
  text-transform: uppercase;
}

.status-potential { background: #fef3c7; color: #92400e; }
.status-active { background: #d1fae5; color: #065f46; }
.status-contacted { background: #dbeafe; color: #1e40af; }
.status-scheduled { background: #e0e7ff; color: #3730a3; }
.status-aired { background: #d1fae5; color: #065f46; }
.status-declined { background: #fee2e2; color: #991b1b; }

.guest-topics {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.topic-tag {
  padding: 0.2rem 0.6rem;
  background: #f3f4f6;
  border-radius: 12px;
  font-size: 0.75rem;
  color: #4b5563;
}

.topic-more {
  font-size: 0.75rem;
  color: #9ca3af;
}

.guest-social {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  flex-shrink: 0;
}

.social-link {
  width: 32px;
  height: 32px;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.2s ease;
}

.social-link.linkedin {
  color: #0a66c2;
  background: #e8f4f8;
}

.social-link.linkedin:hover {
  background: #0a66c2;
  color: white;
}

.social-link.twitter {
  color: #1d9bf0;
  background: #e8f4fc;
}

.social-link.twitter:hover {
  background: #1d9bf0;
  color: white;
}

@media (max-width: 600px) {
  .guest-card {
    flex-direction: column;
    align-items: flex-start;
  }

  .guest-social {
    flex-direction: row;
    margin-top: 0.5rem;
  }
}
</style>
