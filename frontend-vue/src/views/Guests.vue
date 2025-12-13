<template>
  <div class="container">
    <div class="page-header">
      <h1>Guest Directory</h1>
      <p class="subtitle">Browse and discover podcast guests</p>
    </div>

    <div class="filters">
      <div class="search-wrapper">
        <svg class="search-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="11" cy="11" r="8"/>
          <path d="M21 21l-4.35-4.35"/>
        </svg>
        <input
          v-model="searchQuery"
          type="text"
          placeholder="Search guests by name, company, or expertise..."
          @input="handleSearch"
          class="search-input"
        />
      </div>

      <div class="filter-row">
        <label class="filter-checkbox">
          <input
            type="checkbox"
            v-model="verifiedOnly"
            @change="handleFilterChange"
          />
          <span>Verified only</span>
        </label>

        <select
          v-model="selectedTopic"
          @change="handleFilterChange"
          class="filter-select"
        >
          <option value="">All Topics</option>
          <option v-for="topic in topics" :key="topic.id" :value="topic.id">
            {{ topic.name }}
          </option>
        </select>
      </div>
    </div>

    <div v-if="loading" class="loading">
      <div class="loading-spinner"></div>
      <p>Loading guests...</p>
    </div>

    <div v-else-if="error" class="error">
      <p>{{ error }}</p>
      <button @click="retryFetch" class="retry-btn">Try Again</button>
    </div>

    <div v-else>
      <div class="results-info">
        <span>{{ pagination.total }} guest{{ pagination.total !== 1 ? 's' : '' }} found</span>
      </div>

      <div class="guest-grid">
        <GuestCard
          v-for="guest in guests"
          :key="guest.id"
          :guest="guest"
        />
      </div>

      <div v-if="guests.length === 0" class="no-results">
        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
          <circle cx="9" cy="7" r="4"/>
          <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
          <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
        </svg>
        <h3>No guests found</h3>
        <p v-if="searchQuery || verifiedOnly || selectedTopic">
          Try adjusting your filters
        </p>
        <button v-if="searchQuery || verifiedOnly || selectedTopic" @click="clearFilters" class="clear-btn">
          Clear Filters
        </button>
      </div>

      <div v-if="pagination.totalPages > 1" class="pagination">
        <button
          @click="prevPage"
          :disabled="currentPage === 1"
          class="pagination-btn"
        >
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M15 18l-6-6 6-6"/>
          </svg>
          Previous
        </button>
        <div class="pagination-pages">
          <button
            v-for="page in visiblePages"
            :key="page"
            @click="goToPage(page)"
            :class="['page-btn', { active: page === currentPage }]"
          >
            {{ page }}
          </button>
        </div>
        <span class="pagination-info">
          Page {{ currentPage }} of {{ pagination.totalPages }}
        </span>
        <button
          @click="nextPage"
          :disabled="currentPage === pagination.totalPages"
          class="pagination-btn"
        >
          Next
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M9 18l6-6-6-6"/>
          </svg>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useGuestStore } from '../stores/guests'
import GuestCard from '../components/GuestCard.vue'

const guestStore = useGuestStore()

const searchQuery = ref('')
const verifiedOnly = ref(false)
const selectedTopic = ref('')

const guests = computed(() => guestStore.guests)
const topics = computed(() => guestStore.topics)
const loading = computed(() => guestStore.loading)
const error = computed(() => guestStore.error)
const pagination = computed(() => guestStore.pagination)
const currentPage = computed(() => guestStore.pagination.page)

const visiblePages = computed(() => {
  const total = pagination.value.totalPages
  const current = currentPage.value
  const pages = []

  let start = Math.max(1, current - 2)
  let end = Math.min(total, current + 2)

  if (end - start < 4) {
    if (start === 1) {
      end = Math.min(total, 5)
    } else {
      start = Math.max(1, total - 4)
    }
  }

  for (let i = start; i <= end; i++) {
    pages.push(i)
  }

  return pages
})

let searchTimeout = null

function handleSearch() {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    guestStore.setFilter('search', searchQuery.value)
    guestStore.fetchGuests()
  }, 300)
}

function handleFilterChange() {
  guestStore.setFilter('verified_only', verifiedOnly.value)
  guestStore.setFilter('topic', selectedTopic.value)
  guestStore.fetchGuests()
}

function clearFilters() {
  searchQuery.value = ''
  verifiedOnly.value = false
  selectedTopic.value = ''
  guestStore.clearFilters()
  guestStore.fetchGuests()
}

function retryFetch() {
  guestStore.fetchGuests()
}

function prevPage() {
  if (currentPage.value > 1) {
    guestStore.setPage(currentPage.value - 1)
    guestStore.fetchGuests()
  }
}

function nextPage() {
  if (currentPage.value < pagination.value.totalPages) {
    guestStore.setPage(currentPage.value + 1)
    guestStore.fetchGuests()
  }
}

function goToPage(page) {
  guestStore.setPage(page)
  guestStore.fetchGuests()
}

onMounted(() => {
  guestStore.fetchGuests()
  guestStore.fetchTopics()
})
</script>

<style scoped>
.page-header {
  margin-bottom: 2rem;
}

.page-header h1 {
  margin: 0 0 0.5rem 0;
  font-size: 2rem;
}

.subtitle {
  margin: 0;
  color: #666;
  font-size: 1.1rem;
}

.filters {
  margin-bottom: 2rem;
}

.search-wrapper {
  position: relative;
  margin-bottom: 1rem;
}

.search-icon {
  position: absolute;
  left: 1rem;
  top: 50%;
  transform: translateY(-50%);
  color: #999;
}

.search-input {
  width: 100%;
  padding: 0.875rem 1rem 0.875rem 3rem;
  font-size: 1rem;
  border: 2px solid #e0e0e0;
  border-radius: 10px;
  transition: border-color 0.3s ease, box-shadow 0.3s ease;
}

.search-input:focus {
  outline: none;
  border-color: #667eea;
  box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.filter-row {
  display: flex;
  align-items: center;
  gap: 1.5rem;
  flex-wrap: wrap;
}

.filter-checkbox {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  cursor: pointer;
  font-size: 0.95rem;
}

.filter-checkbox input[type="checkbox"] {
  width: 18px;
  height: 18px;
  accent-color: #667eea;
}

.filter-select {
  padding: 0.5rem 1rem;
  font-size: 0.95rem;
  border: 2px solid #e0e0e0;
  border-radius: 6px;
  background: white;
  cursor: pointer;
}

.filter-select:focus {
  outline: none;
  border-color: #667eea;
}

.results-info {
  margin-bottom: 1rem;
  color: #666;
  font-size: 0.95rem;
}

.guest-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
  gap: 1.5rem;
  margin-bottom: 2rem;
}

.pagination {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 1rem;
  margin-top: 2rem;
  flex-wrap: wrap;
}

.pagination-btn {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.625rem 1rem;
  background: #667eea;
  color: white;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  font-size: 0.9rem;
  transition: background 0.3s ease;
}

.pagination-btn:hover:not(:disabled) {
  background: #5568d3;
}

.pagination-btn:disabled {
  background: #ccc;
  cursor: not-allowed;
}

.pagination-pages {
  display: flex;
  gap: 0.25rem;
}

.page-btn {
  width: 36px;
  height: 36px;
  border: 1px solid #e0e0e0;
  background: white;
  border-radius: 6px;
  cursor: pointer;
  font-size: 0.9rem;
  transition: all 0.2s ease;
}

.page-btn:hover {
  border-color: #667eea;
  color: #667eea;
}

.page-btn.active {
  background: #667eea;
  color: white;
  border-color: #667eea;
}

.pagination-info {
  font-size: 0.9rem;
  color: #666;
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

.retry-btn,
.clear-btn {
  padding: 0.5rem 1rem;
  background: #667eea;
  color: white;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  margin-top: 1rem;
}

.no-results {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 4rem 2rem;
  text-align: center;
  color: #666;
}

.no-results svg {
  color: #ccc;
  margin-bottom: 1rem;
}

.no-results h3 {
  margin: 0 0 0.5rem 0;
  color: #333;
}

.no-results p {
  margin: 0;
}

@media (max-width: 640px) {
  .guest-grid {
    grid-template-columns: 1fr;
  }

  .pagination {
    flex-direction: column;
  }

  .pagination-pages {
    order: -1;
  }
}
</style>
