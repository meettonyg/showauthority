import axios from 'axios'

// Configure API base URL
// In production, set this to your WordPress site URL
const API_BASE = import.meta.env.VITE_API_URL || '/api'

const api = axios.create({
  baseURL: API_BASE,
  headers: {
    'Content-Type': 'application/json'
  }
})

export default {
  // Podcasts
  async getPodcasts(params = {}) {
    const response = await api.get('/podcasts', { params })
    return response.data
  },

  async getPodcast(id) {
    const response = await api.get(`/podcasts/${id}`)
    return response.data
  },

  async getPodcastEpisodes(podcastId) {
    const response = await api.get(`/podcasts/${podcastId}/episodes`)
    return response.data
  },

  async getPodcastMetrics(podcastId) {
    const response = await api.get(`/podcasts/${podcastId}/metrics`)
    return response.data
  },

  // Guests
  async getGuests(params = {}) {
    const response = await api.get('/guests', { params })
    return response.data
  },

  async getGuest(id) {
    const response = await api.get(`/guests/${id}`)
    return response.data
  },

  async createGuest(data) {
    const response = await api.post('/guests', data)
    return response.data
  },

  async updateGuest(id, data) {
    const response = await api.put(`/guests/${id}`, data)
    return response.data
  },

  async deleteGuest(id) {
    const response = await api.delete(`/guests/${id}`)
    return response.data
  },

  async getGuestAppearances(id) {
    const response = await api.get(`/guests/${id}/appearances`)
    return response.data
  },

  async addGuestAppearance(guestId, data) {
    const response = await api.post(`/guests/${guestId}/appearances`, data)
    return response.data
  },

  async getGuestTopics(id) {
    const response = await api.get(`/guests/${id}/topics`)
    return response.data
  },

  async assignGuestTopic(guestId, data) {
    const response = await api.post(`/guests/${guestId}/topics`, data)
    return response.data
  },

  async getGuestNetwork(id, maxDegree = 2) {
    const response = await api.get(`/guests/${id}/network`, { params: { max_degree: maxDegree } })
    return response.data
  },

  async verifyGuest(id, data) {
    const response = await api.post(`/guests/${id}/verify`, data)
    return response.data
  },

  async getTopics(params = {}) {
    const response = await api.get('/topics', { params })
    return response.data
  },

  async createTopic(data) {
    const response = await api.post('/topics', data)
    return response.data
  },

  // Guest Metrics
  async getGuestMetrics() {
    const response = await api.get('/guests/metrics')
    return response.data
  },

  // Search
  async search(query, type = 'all') {
    const response = await api.get('/search', {
      params: { q: query, type }
    })
    return response.data
  }
}
