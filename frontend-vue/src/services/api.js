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

  async getGuestAppearances(guestId) {
    const response = await api.get(`/guests/${guestId}/appearances`)
    return response.data
  },

  async getGuestNetwork(guestId, maxDegree = 2) {
    const response = await api.get(`/guests/${guestId}/network`, {
      params: { max_degree: maxDegree }
    })
    return response.data
  },

  // Topics
  async getTopics(params = {}) {
    const response = await api.get('/topics', { params })
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
