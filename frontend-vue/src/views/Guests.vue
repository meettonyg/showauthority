<template>
  <div class="container">
    <div class="page-header">
      <div class="header-content">
        <h1>Guest Directory</h1>
        <p class="subtitle">Browse and discover podcast guests</p>
      </div>
      <router-link to="/guests/metrics" class="metrics-btn">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
          <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/>
        </svg>
        View Metrics
      </router-link>
    </div>

    <!-- Search and Filters -->
    <div class="filters-section">
      <div class="search-row">
        <input
          v-model="searchQuery"
          type="text"
          placeholder="Search guests by name, company, or expertise..."
          @input="handleSearch"
          class="search-input"
        />
        <button @click="toggleFilters" class="filter-toggle" :class="{ active: showFilters }">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/>
          </svg>
          Filters
          <span v-if="hasFiltersApplied" class="filter-count">{{ activeFilterCount }}</span>
        </button>
      </div>

      <transition name="slide">
        <div v-if="showFilters" class="filters-panel">
          <div class="filter-group">
            <label>Company</label>
            <input
              v-model="filters.company"
              type="text"
              placeholder="Filter by company..."
              @input="debouncedFetch"
            />
          </div>

          <div class="filter-group">
            <label>Industry</label>
            <input
              v-model="filters.industry"
              type="text"
              placeholder="Filter by industry..."
              @input="debouncedFetch"
            />
          </div>

          <div class="filter-group">
            <label>Status</label>
            <select v-model="filters.status" @change="applyFilters">
              <option value="">All Statuses</option>
              <option value="potential">Potential</option>
              <option value="active">Active</option>
              <option value="contacted">Contacted</option>
              <option value="scheduled">Scheduled</option>
              <option value="aired">Aired</option>
              <option value="declined">Declined</option>
            </select>
          </div>

          <div class="filter-group checkboxes">
            <label class="checkbox-label">
              <input type="checkbox" v-model="filters.verified_only" @change="applyFilters" />
              Verified only
            </label>
            <label class="checkbox-label">
              <input type="checkbox" v-model="filters.enriched_only" @change="applyFilters" />
              Enriched profiles only
            </label>
          </div>

          <div class="filter-actions">
            <button @click="clearFilters" class="btn-clear">Clear All</button>
          </div>
        </div>
      </transition>
    </div>

    <!-- Stats Bar -->
    <div class="stats-bar" v-if="!loading">
      <span class="stat">
        <strong>{{ pagination.total }}</strong> guests
      </span>
      <span v-if="hasFiltersApplied" class="stat filtered">
        (filtered)
      </span>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="loading">
      <div class="spinner"></div>
      <span>Loading guests...</span>
    </div>

    <!-- Error State -->
    <div v-else-if="error" class="error">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="currentColor">
        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
      </svg>
      <p>{{ error }}</p>
      <button @click="fetchGuests" class="btn-retry">Retry</button>
    </div>

    <!-- Guest List -->
    <div v-else>
      <div class="guest-list">
        <GuestCard
          v-for="guest in guests"
          :key="guest.id"
          :guest="guest"
        />
      </div>

      <!-- Empty State -->
      <div v-if="guests.length === 0" class="empty-state">
        <svg width="64" height="64" viewBox="0 0 24 24" fill="currentColor" class="empty-icon">
          <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/>
        </svg>
        <h3>No guests found</h3>
        <p v-if="hasFiltersApplied">Try adjusting your filters or search query</p>
        <p v-else>Start by adding your first guest</p>
      </div>

      <!-- Pagination -->
      <div v-if="pagination.totalPages > 1" class="pagination">
        <button
          @click="prevPage"
          :disabled="pagination.page === 1"
          class="pagination-btn"
        >
          <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
            <path d="M15.41 7.41L14 6l-6 6 6 6 1.41-1.41L10.83 12z"/>
          </svg>
          Previous
        </button>

        <div class="pagination-pages">
          <button
            v-for="page in visiblePages"
            :key="page"
            @click="goToPage(page)"
            :class="['page-btn', { active: page === pagination.page }]"
          >
            {{ page }}
          </button>
        </div>

        <span class="pagination-info">
          Page {{ pagination.page }} of {{ pagination.totalPages }}
        </span>

        <button
          @click="nextPage"
          :disabled="pagination.page === pagination.totalPages"
          class="pagination-btn"
        >
          Next
          <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
            <path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/>
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
const showFilters = ref(false)
const filters = ref({
  company: '',
  industry: '',
  status: '',
  verified_only: false,
  enriched_only: false
})

const guests = computed(() => guestStore.guests)
const loading = computed(() => guestStore.loading)
const error = computed(() => guestStore.error)
const pagination = computed(() => guestStore.pagination)

const hasFiltersApplied = computed(() => {
  return searchQuery.value ||
         filters.value.company ||
         filters.value.industry ||
         filters.value.status ||
         filters.value.verified_only ||
         filters.value.enriched_only
})

const activeFilterCount = computed(() => {
  let count = 0
  if (filters.value.company) count++
  if (filters.value.industry) count++
  if (filters.value.status) count++
  if (filters.value.verified_only) count++
  if (filters.value.enriched_only) count++
  return count
})

const visiblePages = computed(() => {
  const total = pagination.value.totalPages
  const current = pagination.value.page
  const pages = []

  if (total <= 7) {
    for (let i = 1; i <= total; i++) pages.push(i)
  } else {
    if (current <= 4) {
      for (let i = 1; i <= 5; i++) pages.push(i)
      pages.push('...')
      pages.push(total)
    } else if (current >= total - 3) {
      pages.push(1)
      pages.push('...')
      for (let i = total - 4; i <= total; i++) pages.push(i)
    } else {
      pages.push(1)
      pages.push('...')
      for (let i = current - 1; i <= current + 1; i++) pages.push(i)
      pages.push('...')
      pages.push(total)
    }
  }

  return pages.filter(p => p !== '...')
})

let searchTimeout = null
let filterTimeout = null

function handleSearch() {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    guestStore.setFilter('search', searchQuery.value)
    guestStore.setPage(1)
    fetchGuests()
  }, 300)
}

function debouncedFetch() {
  clearTimeout(filterTimeout)
  filterTimeout = setTimeout(applyFilters, 300)
}

function applyFilters() {
  Object.keys(filters.value).forEach(key => {
    guestStore.setFilter(key, filters.value[key])
  })
  guestStore.setPage(1)
  fetchGuests()
}

function clearFilters() {
  searchQuery.value = ''
  filters.value = {
    company: '',
    industry: '',
    status: '',
    verified_only: false,
    enriched_only: false
  }
  guestStore.clearFilters()
  fetchGuests()
}

function toggleFilters() {
  showFilters.value = !showFilters.value
}

function fetchGuests() {
  guestStore.fetchGuests({ search: searchQuery.value })
}

function prevPage() {
  if (pagination.value.page > 1) {
    guestStore.setPage(pagination.value.page - 1)
    fetchGuests()
    scrollToTop()
  }
}

function nextPage() {
  if (pagination.value.page < pagination.value.totalPages) {
    guestStore.setPage(pagination.value.page + 1)
    fetchGuests()
    scrollToTop()
  }
}

function goToPage(page) {
  if (page !== pagination.value.page && typeof page === 'number') {
    guestStore.setPage(page)
    fetchGuests()
    scrollToTop()
  }
}

function scrollToTop() {
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

onMounted(() => {
  fetchGuests()
})
</script>

<style scoped>
.container {
  max-width: 1200px;
  margin: 0 auto;
  padding: 2rem;
}

.page-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 2rem;
}

.header-content h1 {
  font-size: 2rem;
  font-weight: 700;
  margin: 0 0 0.5rem 0;
  color: #1a1a1a;
}

.subtitle {
  color: #666;
  margin: 0;
}

.metrics-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.625rem 1rem;
  background: #f3f4f6;
  color: #4b5563;
  border-radius: 8px;
  text-decoration: none;
  font-size: 0.9rem;
  transition: all 0.2s;
}

.metrics-btn:hover {
  background: #667eea;
  color: white;
}

/* Filters Section */
.filters-section {
  margin-bottom: 1.5rem;
}

.search-row {
  display: flex;
  gap: 1rem;
}

.search-input {
  flex: 1;
  padding: 0.875rem 1rem;
  font-size: 1rem;
  border: 2px solid #e0e0e0;
  border-radius: 8px;
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.search-input:focus {
  outline: none;
  border-color: #667eea;
  box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.filter-toggle {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0 1.25rem;
  background: white;
  border: 2px solid #e0e0e0;
  border-radius: 8px;
  cursor: pointer;
  font-size: 0.95rem;
  color: #444;
  transition: all 0.2s ease;
}

.filter-toggle:hover {
  border-color: #667eea;
  color: #667eea;
}

.filter-toggle.active {
  background: #667eea;
  border-color: #667eea;
  color: white;
}

.filter-count {
  background: white;
  color: #667eea;
  padding: 0.125rem 0.5rem;
  border-radius: 10px;
  font-size: 0.8rem;
  font-weight: 600;
}

.filter-toggle.active .filter-count {
  background: rgba(255, 255, 255, 0.9);
}

/* Filters Panel */
.filters-panel {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 1rem;
  padding: 1.5rem;
  margin-top: 1rem;
  background: #f8f9fa;
  border-radius: 12px;
  border: 1px solid #e0e0e0;
}

.filter-group label {
  display: block;
  font-size: 0.85rem;
  font-weight: 500;
  color: #444;
  margin-bottom: 0.5rem;
}

.filter-group input[type="text"],
.filter-group select {
  width: 100%;
  padding: 0.625rem 0.75rem;
  font-size: 0.9rem;
  border: 1px solid #ddd;
  border-radius: 6px;
  background: white;
}

.filter-group input[type="text"]:focus,
.filter-group select:focus {
  outline: none;
  border-color: #667eea;
}

.filter-group.checkboxes {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  justify-content: center;
}

.checkbox-label {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  cursor: pointer;
  font-size: 0.9rem;
}

.checkbox-label input[type="checkbox"] {
  width: 18px;
  height: 18px;
  cursor: pointer;
}

.filter-actions {
  display: flex;
  align-items: flex-end;
}

.btn-clear {
  padding: 0.625rem 1rem;
  background: white;
  border: 1px solid #ddd;
  border-radius: 6px;
  cursor: pointer;
  font-size: 0.9rem;
  color: #666;
  transition: all 0.2s ease;
}

.btn-clear:hover {
  background: #f5f5f5;
  border-color: #ccc;
}

/* Transition */
.slide-enter-active,
.slide-leave-active {
  transition: all 0.3s ease;
}

.slide-enter-from,
.slide-leave-to {
  opacity: 0;
  transform: translateY(-10px);
}

/* Stats Bar */
.stats-bar {
  display: flex;
  gap: 0.5rem;
  margin-bottom: 1rem;
  font-size: 0.9rem;
  color: #666;
}

.stat.filtered {
  color: #667eea;
}

/* Guest List */
.guest-list {
  display: grid;
  gap: 1rem;
}

/* Loading */
.loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 4rem 2rem;
  color: #666;
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

/* Error */
.error {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 4rem 2rem;
  text-align: center;
  color: #dc3545;
}

.error svg {
  opacity: 0.5;
  margin-bottom: 1rem;
}

.btn-retry {
  margin-top: 1rem;
  padding: 0.625rem 1.5rem;
  background: #667eea;
  color: white;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  font-size: 0.9rem;
}

.btn-retry:hover {
  background: #5568d3;
}

/* Empty State */
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 4rem 2rem;
  text-align: center;
}

.empty-icon {
  color: #ccc;
  margin-bottom: 1rem;
}

.empty-state h3 {
  margin: 0 0 0.5rem 0;
  color: #444;
}

.empty-state p {
  margin: 0;
  color: #888;
}

/* Pagination */
.pagination {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 1rem;
  margin-top: 2rem;
  padding-top: 2rem;
  border-top: 1px solid #e0e0e0;
}

.pagination-btn {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.5rem 1rem;
  background: #667eea;
  color: white;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  font-size: 0.9rem;
  transition: background 0.2s ease;
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
  gap: 0.5rem;
}

.page-btn {
  width: 36px;
  height: 36px;
  background: white;
  border: 1px solid #e0e0e0;
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
  border-color: #667eea;
  color: white;
}

.pagination-info {
  font-size: 0.9rem;
  color: #666;
}

@media (max-width: 768px) {
  .container {
    padding: 1rem;
  }

  .search-row {
    flex-direction: column;
  }

  .filters-panel {
    grid-template-columns: 1fr;
  }

  .pagination {
    flex-wrap: wrap;
  }

  .pagination-pages {
    order: -1;
    width: 100%;
    justify-content: center;
    margin-bottom: 1rem;
  }
}
</style>
