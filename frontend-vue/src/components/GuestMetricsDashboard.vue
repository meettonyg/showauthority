<template>
  <div class="metrics-dashboard">
    <div class="dashboard-header">
      <h2>Guest Intelligence Metrics</h2>
      <p class="subtitle">Overview of your guest database and network</p>
    </div>

    <!-- Summary Stats -->
    <div class="summary-grid">
      <div class="summary-card">
        <div class="summary-icon guests">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
            <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/>
          </svg>
        </div>
        <div class="summary-content">
          <div class="summary-value">{{ metrics.totalGuests }}</div>
          <div class="summary-label">Total Guests</div>
        </div>
        <div class="summary-trend positive" v-if="metrics.newGuestsThisMonth > 0">
          +{{ metrics.newGuestsThisMonth }} this month
        </div>
      </div>

      <div class="summary-card">
        <div class="summary-icon verified">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
            <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
          </svg>
        </div>
        <div class="summary-content">
          <div class="summary-value">{{ metrics.verifiedGuests }}</div>
          <div class="summary-label">Verified Profiles</div>
        </div>
        <div class="summary-percentage">
          {{ verifiedPercentage }}% of total
        </div>
      </div>

      <div class="summary-card">
        <div class="summary-icon appearances">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
            <path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm-1 1.93c-3.94-.49-7-3.85-7-7.93h2c0 2.76 2.24 5 5 5s5-2.24 5-5h2c0 4.08-3.06 7.44-7 7.93V20h4v2H8v-2h4v-4.07z"/>
          </svg>
        </div>
        <div class="summary-content">
          <div class="summary-value">{{ metrics.totalAppearances }}</div>
          <div class="summary-label">Total Appearances</div>
        </div>
        <div class="summary-avg">
          {{ avgAppearances }} avg per guest
        </div>
      </div>

      <div class="summary-card">
        <div class="summary-icon topics">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
            <path d="M21.41 11.58l-9-9C12.05 2.22 11.55 2 11 2H4c-1.1 0-2 .9-2 2v7c0 .55.22 1.05.59 1.42l9 9c.36.36.86.58 1.41.58.55 0 1.05-.22 1.41-.59l7-7c.37-.36.59-.86.59-1.41 0-.55-.23-1.06-.59-1.42zM5.5 7C4.67 7 4 6.33 4 5.5S4.67 4 5.5 4 7 4.67 7 5.5 6.33 7 5.5 7z"/>
          </svg>
        </div>
        <div class="summary-content">
          <div class="summary-value">{{ metrics.totalTopics }}</div>
          <div class="summary-label">Expertise Topics</div>
        </div>
      </div>
    </div>

    <!-- Charts Row -->
    <div class="charts-row">
      <!-- Status Distribution -->
      <div class="chart-card">
        <h3>Guest Status Distribution</h3>
        <div class="status-chart">
          <div
            v-for="(count, status) in metrics.byStatus"
            :key="status"
            class="status-bar-wrapper"
          >
            <div class="status-label">{{ formatStatus(status) }}</div>
            <div class="status-bar-container">
              <div
                class="status-bar"
                :class="`status-${status}`"
                :style="{ width: getStatusWidth(count) + '%' }"
              ></div>
            </div>
            <div class="status-count">{{ count }}</div>
          </div>
        </div>
      </div>

      <!-- Data Quality -->
      <div class="chart-card">
        <h3>Data Quality Overview</h3>
        <div class="quality-metrics">
          <div class="quality-item">
            <div class="quality-ring">
              <svg viewBox="0 0 36 36">
                <path
                  class="quality-bg"
                  d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                />
                <path
                  class="quality-fill enriched"
                  :stroke-dasharray="`${enrichedPercentage}, 100`"
                  d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                />
              </svg>
              <div class="quality-value">{{ enrichedPercentage }}%</div>
            </div>
            <div class="quality-label">Enriched Profiles</div>
          </div>

          <div class="quality-item">
            <div class="quality-ring">
              <svg viewBox="0 0 36 36">
                <path
                  class="quality-bg"
                  d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                />
                <path
                  class="quality-fill linkedin"
                  :stroke-dasharray="`${linkedinPercentage}, 100`"
                  d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                />
              </svg>
              <div class="quality-value">{{ linkedinPercentage }}%</div>
            </div>
            <div class="quality-label">With LinkedIn</div>
          </div>

          <div class="quality-item">
            <div class="quality-ring">
              <svg viewBox="0 0 36 36">
                <path
                  class="quality-bg"
                  d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                />
                <path
                  class="quality-fill email"
                  :stroke-dasharray="`${emailPercentage}, 100`"
                  d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                />
              </svg>
              <div class="quality-value">{{ emailPercentage }}%</div>
            </div>
            <div class="quality-label">With Email</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Top Lists Row -->
    <div class="lists-row">
      <!-- Top Topics -->
      <div class="list-card">
        <h3>Top Expertise Topics</h3>
        <div class="topic-list">
          <div
            v-for="(topic, index) in metrics.topTopics"
            :key="topic.id"
            class="topic-item"
          >
            <span class="topic-rank">{{ index + 1 }}</span>
            <span class="topic-name">{{ topic.name }}</span>
            <span class="topic-count">{{ topic.guest_count }} guests</span>
          </div>
          <div v-if="!metrics.topTopics || metrics.topTopics.length === 0" class="empty-list">
            No topics data available
          </div>
        </div>
      </div>

      <!-- Top Companies -->
      <div class="list-card">
        <h3>Top Companies</h3>
        <div class="company-list">
          <div
            v-for="(company, index) in metrics.topCompanies"
            :key="company.name"
            class="company-item"
          >
            <span class="company-rank">{{ index + 1 }}</span>
            <span class="company-name">{{ company.name }}</span>
            <span class="company-count">{{ company.count }} guests</span>
          </div>
          <div v-if="!metrics.topCompanies || metrics.topCompanies.length === 0" class="empty-list">
            No company data available
          </div>
        </div>
      </div>

      <!-- Recent Activity -->
      <div class="list-card">
        <h3>Recent Guests</h3>
        <div class="recent-list">
          <router-link
            v-for="guest in metrics.recentGuests"
            :key="guest.id"
            :to="`/guests/${guest.id}`"
            class="recent-item"
          >
            <div class="recent-avatar">
              {{ getInitials(guest.full_name) }}
            </div>
            <div class="recent-info">
              <span class="recent-name">{{ guest.full_name }}</span>
              <span class="recent-date">{{ formatDate(guest.created_at) }}</span>
            </div>
          </router-link>
          <div v-if="!metrics.recentGuests || metrics.recentGuests.length === 0" class="empty-list">
            No recent guests
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  metrics: {
    type: Object,
    default: () => ({
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
  }
})

const verifiedPercentage = computed(() => {
  if (!props.metrics.totalGuests) return 0
  return Math.round((props.metrics.verifiedGuests / props.metrics.totalGuests) * 100)
})

const enrichedPercentage = computed(() => {
  if (!props.metrics.totalGuests) return 0
  return Math.round((props.metrics.enrichedGuests / props.metrics.totalGuests) * 100)
})

const linkedinPercentage = computed(() => {
  if (!props.metrics.totalGuests) return 0
  return Math.round((props.metrics.guestsWithLinkedin / props.metrics.totalGuests) * 100)
})

const emailPercentage = computed(() => {
  if (!props.metrics.totalGuests) return 0
  return Math.round((props.metrics.guestsWithEmail / props.metrics.totalGuests) * 100)
})

const avgAppearances = computed(() => {
  if (!props.metrics.totalGuests) return 0
  return (props.metrics.totalAppearances / props.metrics.totalGuests).toFixed(1)
})

const maxStatusCount = computed(() => {
  if (!props.metrics.byStatus) return 1
  return Math.max(...Object.values(props.metrics.byStatus), 1)
})

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

function getStatusWidth(count) {
  return (count / maxStatusCount.value) * 100
}

function getInitials(name) {
  if (!name) return '??'
  const parts = name.split(' ')
  if (parts.length >= 2) {
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
  }
  return name.substring(0, 2).toUpperCase()
}

function formatDate(dateStr) {
  if (!dateStr) return ''
  const date = new Date(dateStr)
  const now = new Date()
  const diffDays = Math.floor((now - date) / (1000 * 60 * 60 * 24))

  if (diffDays === 0) return 'Today'
  if (diffDays === 1) return 'Yesterday'
  if (diffDays < 7) return `${diffDays} days ago`

  return date.toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric'
  })
}
</script>

<style scoped>
.metrics-dashboard {
  padding: 1.5rem;
}

.dashboard-header {
  margin-bottom: 1.5rem;
}

.dashboard-header h2 {
  font-size: 1.5rem;
  font-weight: 700;
  margin: 0 0 0.25rem 0;
  color: #1a1a1a;
}

.subtitle {
  color: #666;
  margin: 0;
  font-size: 0.9rem;
}

/* Summary Grid */
.summary-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 1rem;
  margin-bottom: 1.5rem;
}

.summary-card {
  background: white;
  border-radius: 12px;
  padding: 1.25rem;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.summary-icon {
  width: 48px;
  height: 48px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.summary-icon.guests {
  background: #e0e7ff;
  color: #667eea;
}

.summary-icon.verified {
  background: #d1fae5;
  color: #10b981;
}

.summary-icon.appearances {
  background: #fef3c7;
  color: #f59e0b;
}

.summary-icon.topics {
  background: #fce7f3;
  color: #ec4899;
}

.summary-value {
  font-size: 1.75rem;
  font-weight: 700;
  color: #1a1a1a;
}

.summary-label {
  font-size: 0.85rem;
  color: #666;
}

.summary-trend {
  font-size: 0.8rem;
  padding: 0.25rem 0.5rem;
  border-radius: 4px;
  width: fit-content;
}

.summary-trend.positive {
  background: #d1fae5;
  color: #065f46;
}

.summary-percentage,
.summary-avg {
  font-size: 0.8rem;
  color: #888;
}

/* Charts Row */
.charts-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
  margin-bottom: 1.5rem;
}

.chart-card {
  background: white;
  border-radius: 12px;
  padding: 1.25rem;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.chart-card h3 {
  font-size: 1rem;
  font-weight: 600;
  margin: 0 0 1rem 0;
  color: #444;
}

/* Status Chart */
.status-chart {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.status-bar-wrapper {
  display: grid;
  grid-template-columns: 80px 1fr 40px;
  align-items: center;
  gap: 0.75rem;
}

.status-label {
  font-size: 0.85rem;
  color: #666;
}

.status-bar-container {
  height: 8px;
  background: #f1f5f9;
  border-radius: 4px;
  overflow: hidden;
}

.status-bar {
  height: 100%;
  border-radius: 4px;
  transition: width 0.5s ease;
}

.status-potential { background: #fbbf24; }
.status-active { background: #10b981; }
.status-contacted { background: #3b82f6; }
.status-scheduled { background: #8b5cf6; }
.status-aired { background: #10b981; }
.status-declined { background: #ef4444; }

.status-count {
  font-size: 0.85rem;
  font-weight: 500;
  color: #444;
  text-align: right;
}

/* Quality Metrics */
.quality-metrics {
  display: flex;
  justify-content: space-around;
  padding: 1rem 0;
}

.quality-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
}

.quality-ring {
  position: relative;
  width: 80px;
  height: 80px;
}

.quality-ring svg {
  transform: rotate(-90deg);
}

.quality-bg {
  fill: none;
  stroke: #f1f5f9;
  stroke-width: 3;
}

.quality-fill {
  fill: none;
  stroke-width: 3;
  stroke-linecap: round;
  transition: stroke-dasharray 0.5s ease;
}

.quality-fill.enriched { stroke: #667eea; }
.quality-fill.linkedin { stroke: #0a66c2; }
.quality-fill.email { stroke: #10b981; }

.quality-value {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  font-size: 1.1rem;
  font-weight: 600;
  color: #1a1a1a;
}

.quality-label {
  font-size: 0.8rem;
  color: #666;
}

/* Lists Row */
.lists-row {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1rem;
}

.list-card {
  background: white;
  border-radius: 12px;
  padding: 1.25rem;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.list-card h3 {
  font-size: 1rem;
  font-weight: 600;
  margin: 0 0 1rem 0;
  color: #444;
}

/* Topic List */
.topic-list,
.company-list {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.topic-item,
.company-item {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.5rem;
  background: #f8f9fa;
  border-radius: 6px;
}

.topic-rank,
.company-rank {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: #e0e7ff;
  color: #667eea;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.75rem;
  font-weight: 600;
}

.topic-name,
.company-name {
  flex: 1;
  font-size: 0.9rem;
  color: #1a1a1a;
}

.topic-count,
.company-count {
  font-size: 0.8rem;
  color: #888;
}

/* Recent List */
.recent-list {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.recent-item {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.5rem;
  background: #f8f9fa;
  border-radius: 6px;
  text-decoration: none;
  transition: background 0.2s;
}

.recent-item:hover {
  background: #e5e7eb;
}

.recent-avatar {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.75rem;
  font-weight: 500;
}

.recent-info {
  flex: 1;
  display: flex;
  flex-direction: column;
}

.recent-name {
  font-size: 0.9rem;
  color: #1a1a1a;
}

.recent-date {
  font-size: 0.75rem;
  color: #888;
}

.empty-list {
  padding: 1rem;
  text-align: center;
  color: #888;
  font-size: 0.85rem;
}

/* Responsive */
@media (max-width: 1024px) {
  .summary-grid {
    grid-template-columns: repeat(2, 1fr);
  }

  .charts-row {
    grid-template-columns: 1fr;
  }

  .lists-row {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 600px) {
  .summary-grid {
    grid-template-columns: 1fr;
  }
}
</style>
