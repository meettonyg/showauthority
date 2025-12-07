<template>
  <div class="container">
    <router-link to="/guests" class="back-link">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
        <path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/>
      </svg>
      Back to Guest Directory
    </router-link>

    <div v-if="loading" class="loading">
      <div class="spinner"></div>
      <span>Loading metrics...</span>
    </div>

    <div v-else-if="error" class="error">
      <p>{{ error }}</p>
      <button @click="fetchMetrics" class="btn-retry">Retry</button>
    </div>

    <GuestMetricsDashboard v-else :metrics="metrics" />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../services/api'
import GuestMetricsDashboard from '../components/GuestMetricsDashboard.vue'

const loading = ref(true)
const error = ref(null)
const metrics = ref({
  totalGuests: 0,
  verifiedGuests: 0,
  enrichedGuests: 0,
  guestsWithLinkedin: 0,
  guestsWithEmail: 0,
  totalAppearances: 0,
  totalTopics: 0,
  newGuestsThisMonth: 0,
  byStatus: {},
  topTopics: [],
  topCompanies: [],
  recentGuests: []
})

async function fetchMetrics() {
  loading.value = true
  error.value = null

  try {
    const data = await api.getGuestMetrics()
    metrics.value = data
  } catch (err) {
    console.error('Failed to fetch guest metrics:', err)
    error.value = err.message || 'Failed to load metrics'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchMetrics()
})
</script>

<style scoped>
.container {
  max-width: 1400px;
  margin: 0 auto;
  padding: 2rem;
}

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

.error {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 4rem 2rem;
  text-align: center;
  color: #dc3545;
}

.btn-retry {
  margin-top: 1rem;
  padding: 0.625rem 1.5rem;
  background: #667eea;
  color: white;
  border: none;
  border-radius: 6px;
  cursor: pointer;
}

.btn-retry:hover {
  background: #5568d3;
}
</style>
