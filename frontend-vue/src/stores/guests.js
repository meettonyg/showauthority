import { defineStore } from 'pinia'
import api from '../services/api'

export const useGuestStore = defineStore('guests', {
  state: () => ({
    guests: [],
    currentGuest: null,
    topics: [],
    loading: false,
    error: null,
    pagination: {
      page: 1,
      perPage: 20,
      total: 0,
      totalPages: 0
    },
    filters: {
      search: '',
      verified_only: false,
      topic: '',
      company: ''
    }
  }),

  getters: {
    verifiedGuests: (state) => state.guests.filter(g => g.is_verified),
    guestCount: (state) => state.pagination.total
  },

  actions: {
    async fetchGuests(params = {}) {
      this.loading = true
      this.error = null

      try {
        const data = await api.getGuests({
          page: this.pagination.page,
          per_page: this.pagination.perPage,
          search: this.filters.search,
          verified_only: this.filters.verified_only ? 1 : 0,
          ...params
        })

        this.guests = data.guests || []
        this.pagination.total = data.total || 0
        this.pagination.totalPages = data.total_pages || 0
      } catch (error) {
        this.error = error.message
        console.error('Failed to fetch guests:', error)
      } finally {
        this.loading = false
      }
    },

    async fetchGuest(id) {
      this.loading = true
      this.error = null

      try {
        const data = await api.getGuest(id)
        this.currentGuest = data
      } catch (error) {
        this.error = error.message
        console.error('Failed to fetch guest:', error)
      } finally {
        this.loading = false
      }
    },

    async fetchTopics() {
      try {
        const response = await api.getTopics()
        this.topics = response || []
      } catch (error) {
        console.error('Failed to fetch topics:', error)
      }
    },

    setPage(page) {
      this.pagination.page = page
    },

    setFilter(key, value) {
      this.filters[key] = value
      this.pagination.page = 1
    },

    clearFilters() {
      this.filters = {
        search: '',
        verified_only: false,
        topic: '',
        company: ''
      }
      this.pagination.page = 1
    }
  }
})
