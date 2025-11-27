<template>
  <div class="container">
    <h1>All Podcasts</h1>

    <div class="filters">
      <input
        v-model="searchQuery"
        type="text"
        placeholder="Search podcasts..."
        @input="handleSearch"
        class="search-input"
      />
    </div>

    <div v-if="loading" class="loading">Loading podcasts...</div>
    <div v-else-if="error" class="error">{{ error }}</div>
    <div v-else>
      <div class="podcast-grid">
        <PodcastCard
          v-for="podcast in podcasts"
          :key="podcast.id"
          :podcast="podcast"
        />
      </div>

      <div v-if="podcasts.length === 0" class="no-results">
        No podcasts found
      </div>

      <div v-if="totalPages > 1" class="pagination">
        <button
          @click="prevPage"
          :disabled="currentPage === 1"
          class="pagination-btn"
        >
          Previous
        </button>
        <span class="pagination-info">
          Page {{ currentPage }} of {{ totalPages }}
        </span>
        <button
          @click="nextPage"
          :disabled="currentPage === totalPages"
          class="pagination-btn"
        >
          Next
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { usePodcastStore } from '../stores/podcasts'
import PodcastCard from '../components/PodcastCard.vue'

const podcastStore = usePodcastStore()
const searchQuery = ref('')

const podcasts = computed(() => podcastStore.podcasts)
const loading = computed(() => podcastStore.loading)
const error = computed(() => podcastStore.error)
const currentPage = computed(() => podcastStore.pagination.page)
const totalPages = computed(() => podcastStore.pagination.totalPages)

let searchTimeout = null

function handleSearch() {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    podcastStore.setPage(1)
    podcastStore.fetchPodcasts({ search: searchQuery.value })
  }, 300)
}

function prevPage() {
  if (currentPage.value > 1) {
    podcastStore.setPage(currentPage.value - 1)
    podcastStore.fetchPodcasts({ search: searchQuery.value })
  }
}

function nextPage() {
  if (currentPage.value < totalPages.value) {
    podcastStore.setPage(currentPage.value + 1)
    podcastStore.fetchPodcasts({ search: searchQuery.value })
  }
}

onMounted(() => {
  podcastStore.fetchPodcasts()
})
</script>

<style scoped>
.filters {
  margin-bottom: 2rem;
}

.search-input {
  width: 100%;
  max-width: 500px;
  padding: 0.75rem 1rem;
  font-size: 1rem;
  border: 2px solid #e0e0e0;
  border-radius: 8px;
  transition: border-color 0.3s ease;
}

.search-input:focus {
  outline: none;
  border-color: #667eea;
}

.podcast-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 2rem;
  margin-bottom: 2rem;
}

.pagination {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 1rem;
  margin-top: 2rem;
}

.pagination-btn {
  padding: 0.5rem 1rem;
  background: #667eea;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  transition: background 0.3s ease;
}

.pagination-btn:hover:not(:disabled) {
  background: #5568d3;
}

.pagination-btn:disabled {
  background: #ccc;
  cursor: not-allowed;
}

.pagination-info {
  font-size: 1rem;
  color: #666;
}

.loading,
.error,
.no-results {
  padding: 2rem;
  text-align: center;
}

.error {
  color: #dc3545;
}
</style>
