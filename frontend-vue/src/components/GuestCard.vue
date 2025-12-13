<template>
  <router-link :to="`/guests/${guest.id}`" class="guest-card">
    <div class="guest-avatar">
      <img
        v-if="guest.headshot_url"
        :src="guest.headshot_url"
        :alt="guest.full_name"
        class="avatar-image"
      />
      <div v-else class="avatar-placeholder">
        {{ initials }}
      </div>
      <span v-if="guest.is_verified" class="verified-badge" title="Verified">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
          <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
        </svg>
      </span>
    </div>

    <div class="guest-info">
      <h3 class="guest-name">{{ guest.full_name }}</h3>
      <p v-if="guest.title || guest.company" class="guest-title">
        {{ guest.title }}<span v-if="guest.title && guest.company"> at </span>{{ guest.company }}
      </p>

      <div v-if="guest.topics && guest.topics.length" class="guest-topics">
        <span
          v-for="topic in guest.topics.slice(0, 3)"
          :key="topic.id"
          class="topic-tag"
        >
          {{ topic.name }}
        </span>
        <span v-if="guest.topics.length > 3" class="topic-more">
          +{{ guest.topics.length - 3 }}
        </span>
      </div>

      <div class="guest-meta">
        <span v-if="guest.appearances_count" class="meta-item">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 2L2 7l10 5 10-5-10-5z"/>
            <path d="M2 17l10 5 10-5"/>
            <path d="M2 12l10 5 10-5"/>
          </svg>
          {{ guest.appearances_count }} appearance{{ guest.appearances_count !== 1 ? 's' : '' }}
        </span>
        <span v-if="guest.linkedin_url" class="meta-item social">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
            <path d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.762 0 5-2.239 5-5v-14c0-2.761-2.238-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.79-1.75-1.764s.784-1.764 1.75-1.764 1.75.79 1.75 1.764-.783 1.764-1.75 1.764zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z"/>
          </svg>
        </span>
      </div>
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
</script>

<style scoped>
.guest-card {
  display: flex;
  align-items: flex-start;
  gap: 1rem;
  padding: 1.25rem;
  background: white;
  border-radius: 12px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
  text-decoration: none;
  color: inherit;
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.guest-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.12);
}

.guest-avatar {
  position: relative;
  flex-shrink: 0;
}

.avatar-image {
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
  font-size: 1.25rem;
  font-weight: 600;
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

.guest-info {
  flex: 1;
  min-width: 0;
}

.guest-name {
  margin: 0 0 0.25rem 0;
  font-size: 1.1rem;
  font-weight: 600;
  color: #1a1a2e;
}

.guest-title {
  margin: 0 0 0.5rem 0;
  font-size: 0.9rem;
  color: #666;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.guest-topics {
  display: flex;
  flex-wrap: wrap;
  gap: 0.375rem;
  margin-bottom: 0.75rem;
}

.topic-tag {
  padding: 0.25rem 0.5rem;
  background: #f0f4ff;
  color: #667eea;
  border-radius: 4px;
  font-size: 0.75rem;
  font-weight: 500;
}

.topic-more {
  padding: 0.25rem 0.5rem;
  background: #e5e5e5;
  color: #666;
  border-radius: 4px;
  font-size: 0.75rem;
}

.guest-meta {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  font-size: 0.8rem;
  color: #888;
}

.meta-item {
  display: flex;
  align-items: center;
  gap: 0.25rem;
}

.meta-item.social svg {
  color: #0077b5;
}
</style>
