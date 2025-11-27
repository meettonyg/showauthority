<template>
  <div class="container">
    <div v-if="loading" class="loading">Loading...</div>
    <div v-else-if="error" class="error">{{ error }}</div>
    <div v-else-if="podcast" class="podcast-detail">
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
            <span v-if="podcast.category">{{ podcast.category }}</span>
            <span v-if="podcast.episode_count">
              {{ podcast.episode_count }} episodes
            </span>
          </div>
          <a
            v-if="podcast.website_url"
            :href="podcast.website_url"
            target="_blank"
            class="website-link"
          >
            Visit Website →
          </a>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, computed } from 'vue'
import { useRoute } from 'vue-router'
import { usePodcastStore } from '../stores/podcasts'

const route = useRoute()
const podcastStore = usePodcastStore()

const podcast = computed(() => podcastStore.currentPodcast)
const loading = computed(() => podcastStore.loading)
const error = computed(() => podcastStore.error)

onMounted(() => {
  podcastStore.fetchPodcast(route.params.id)
})
</script>

<style scoped>
.podcast-detail {
  max-width: 900px;
  margin: 0 auto;
}

.podcast-header {
  display: grid;
  grid-template-columns: 300px 1fr;
  gap: 2rem;
  margin-bottom: 2rem;
}

.podcast-artwork-large {
  width: 100%;
  border-radius: 12px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.podcast-info h1 {
  margin: 0 0 0.5rem 0;
  font-size: 2rem;
}

.author {
  color: #666;
  margin-bottom: 1rem;
}

.description {
  line-height: 1.6;
  margin-bottom: 1rem;
}

.meta {
  display: flex;
  gap: 1rem;
  margin-bottom: 1rem;
  font-size: 0.9rem;
  color: #666;
}

.website-link {
  display: inline-block;
  padding: 0.75rem 1.5rem;
  background: #667eea;
  color: white;
  text-decoration: none;
  border-radius: 6px;
  transition: background 0.3s ease;
}

.website-link:hover {
  background: #5568d3;
}

@media (max-width: 768px) {
  .podcast-header {
    grid-template-columns: 1fr;
  }
}
</style>
