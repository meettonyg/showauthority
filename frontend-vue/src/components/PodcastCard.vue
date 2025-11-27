<template>
  <router-link :to="`/podcasts/${podcast.id}`" class="podcast-card">
    <div v-if="podcast.artwork_url" class="podcast-artwork">
      <img :src="podcast.artwork_url" :alt="podcast.title" />
    </div>
    <div v-else class="podcast-artwork-placeholder">
      🎙️
    </div>

    <div class="podcast-content">
      <h3 class="podcast-title">{{ podcast.title }}</h3>

      <div v-if="podcast.author" class="podcast-author">
        By {{ podcast.author }}
      </div>

      <div v-if="podcast.description" class="podcast-description">
        {{ truncate(podcast.description, 120) }}
      </div>

      <div class="podcast-meta">
        <span v-if="podcast.category" class="podcast-category">
          {{ podcast.category }}
        </span>
        <span v-if="podcast.episode_count" class="podcast-episodes">
          {{ podcast.episode_count }} episodes
        </span>
      </div>
    </div>
  </router-link>
</template>

<script setup>
import { defineProps } from 'vue'

defineProps({
  podcast: {
    type: Object,
    required: true
  }
})

function truncate(text, length) {
  if (!text) return ''
  return text.length > length ? text.substring(0, length) + '...' : text
}
</script>

<style scoped>
.podcast-card {
  display: block;
  border: 1px solid #e0e0e0;
  border-radius: 12px;
  overflow: hidden;
  background: white;
  text-decoration: none;
  color: inherit;
  transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.podcast-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
}

.podcast-artwork,
.podcast-artwork-placeholder {
  width: 100%;
  aspect-ratio: 1;
  overflow: hidden;
  background: #f5f5f5;
}

.podcast-artwork img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.podcast-artwork-placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 4rem;
}

.podcast-content {
  padding: 1.25rem;
}

.podcast-title {
  font-size: 1.25rem;
  font-weight: 600;
  margin: 0 0 0.5rem 0;
  color: #1a1a1a;
}

.podcast-author {
  font-size: 0.9rem;
  color: #666;
  margin-bottom: 0.75rem;
}

.podcast-description {
  font-size: 0.9rem;
  color: #444;
  line-height: 1.5;
  margin-bottom: 1rem;
}

.podcast-meta {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
  font-size: 0.85rem;
}

.podcast-category {
  padding: 0.25rem 0.75rem;
  background: #f0f0f0;
  border-radius: 12px;
  color: #666;
}

.podcast-episodes {
  color: #999;
}
</style>
