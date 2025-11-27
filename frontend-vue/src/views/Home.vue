<template>
  <div class="container">
    <div class="hero">
      <h1>Discover Influential Podcasts</h1>
      <p>Track, analyze, and connect with podcast hosts and guests</p>
    </div>

    <div class="home-sections">
      <section class="home-section">
        <h2>Featured Podcasts</h2>
        <div v-if="loading" class="loading">Loading...</div>
        <div v-else-if="error" class="error">{{ error }}</div>
        <div v-else class="podcast-grid">
          <PodcastCard
            v-for="podcast in podcasts"
            :key="podcast.id"
            :podcast="podcast"
          />
        </div>
        <router-link to="/podcasts" class="view-all-link">
          View All Podcasts →
        </router-link>
      </section>
    </div>
  </div>
</template>

<script setup>
import { onMounted, computed } from 'vue'
import { usePodcastStore } from '../stores/podcasts'
import PodcastCard from '../components/PodcastCard.vue'

const podcastStore = usePodcastStore()

const podcasts = computed(() => podcastStore.podcasts.slice(0, 6))
const loading = computed(() => podcastStore.loading)
const error = computed(() => podcastStore.error)

onMounted(() => {
  podcastStore.fetchPodcasts({ per_page: 6 })
})
</script>

<style scoped>
.hero {
  text-align: center;
  padding: 4rem 0;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
  border-radius: 12px;
  margin-bottom: 3rem;
}

.hero h1 {
  font-size: 2.5rem;
  margin: 0 0 1rem 0;
}

.hero p {
  font-size: 1.2rem;
  opacity: 0.9;
}

.home-section {
  margin-bottom: 3rem;
}

.home-section h2 {
  font-size: 2rem;
  margin-bottom: 1.5rem;
}

.podcast-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 2rem;
  margin-bottom: 2rem;
}

.view-all-link {
  display: inline-block;
  color: #667eea;
  text-decoration: none;
  font-weight: 600;
  font-size: 1.1rem;
}

.view-all-link:hover {
  text-decoration: underline;
}

.loading,
.error {
  padding: 2rem;
  text-align: center;
}

.error {
  color: #dc3545;
}
</style>
