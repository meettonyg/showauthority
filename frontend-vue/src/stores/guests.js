import { defineStore } from 'pinia'
import api from '../services/api'

export const useGuestStore = defineStore('guests', {
  state: () => ({
    guests: [],
    currentGuest: null,
    topics: [],
    loading: false,
    error: null,
    filters: {
      search: '',
      company: '',
      industry: '',
      status: '',
      verified_only: false,
      enriched_only: false
    },
    pagination: {
      page: 1,
      perPage: 20,
      total: 0,
      totalPages: 0
    }
  }),

  getters: {
    verifiedGuests: (state) => state.guests.filter(g => g.is_verified),
    guestsByStatus: (state) => (status) => state.guests.filter(g => g.status === status),
    hasFiltersApplied: (state) => {
      return state.filters.search ||
             state.filters.company ||
             state.filters.industry ||
             state.filters.status ||
             state.filters.verified_only ||
             state.filters.enriched_only
    }
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
          company: this.filters.company,
          industry: this.filters.industry,
          status: this.filters.status,
          verified_only: this.filters.verified_only ? 1 : 0,
          enriched_only: this.filters.enriched_only ? 1 : 0,
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
        return data
      } catch (error) {
        this.error = error.message
        console.error('Failed to fetch guest:', error)
        return null
      } finally {
        this.loading = false
      }
    },

    async fetchTopics() {
      try {
        const data = await api.getTopics()
        this.topics = data || []
        return data
      } catch (error) {
        console.error('Failed to fetch topics:', error)
        return []
      }
    },

    async createGuest(guestData) {
      this.loading = true
      this.error = null

      try {
        const data = await api.createGuest(guestData)
        this.guests.unshift(data)
        return data
      } catch (error) {
        this.error = error.message
        console.error('Failed to create guest:', error)
        return null
      } finally {
        this.loading = false
      }
    },

    async updateGuest(id, guestData) {
      this.loading = true
      this.error = null

      try {
        const data = await api.updateGuest(id, guestData)
        const index = this.guests.findIndex(g => g.id === id)
        if (index !== -1) {
          this.guests[index] = data
        }
        if (this.currentGuest && this.currentGuest.id === id) {
          this.currentGuest = data
        }
        return data
      } catch (error) {
        this.error = error.message
        console.error('Failed to update guest:', error)
        return null
      } finally {
        this.loading = false
      }
    },

    async verifyGuest(id, status = 'correct', notes = '') {
      try {
        await api.verifyGuest(id, { status, notes })
        if (this.currentGuest && this.currentGuest.id === id) {
          this.currentGuest.is_verified = true
        }
        const guest = this.guests.find(g => g.id === id)
        if (guest) {
          guest.is_verified = true
        }
        return true
      } catch (error) {
        console.error('Failed to verify guest:', error)
        return false
      }
    },

    async fetchGuestNetwork(id, maxDegree = 2) {
      try {
        const data = await api.getGuestNetwork(id, maxDegree)
        return data
      } catch (error) {
        console.error('Failed to fetch guest network:', error)
        return null
      }
    },

    async fetchGuestAppearances(id) {
      try {
        const data = await api.getGuestAppearances(id)
        return data
      } catch (error) {
        console.error('Failed to fetch guest appearances:', error)
        return []
      }
    },

    setFilter(key, value) {
      this.filters[key] = value
      this.pagination.page = 1
    },

    clearFilters() {
      this.filters = {
        search: '',
        company: '',
        industry: '',
        status: '',
        verified_only: false,
        enriched_only: false
      }
      this.pagination.page = 1
    },

    setPage(page) {
      this.pagination.page = page
    },

    clearCurrentGuest() {
      this.currentGuest = null
    }
  }
})
