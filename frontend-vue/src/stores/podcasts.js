import { defineStore } from 'pinia'
import api from '../services/api'

export const usePodcastStore = defineStore('podcasts', {
  state: () => ({
    podcasts: [],
    currentPodcast: null,
    loading: false,
    error: null,
    pagination: {
      page: 1,
      perPage: 20,
      total: 0,
      totalPages: 0
    }
  }),

  actions: {
    async fetchPodcasts(params = {}) {
      this.loading = true
      this.error = null

      try {
        const data = await api.getPodcasts({
          page: this.pagination.page,
          per_page: this.pagination.perPage,
          ...params
        })

        this.podcasts = data.podcasts || []
        this.pagination.total = data.total || 0
        this.pagination.totalPages = data.total_pages || 0
      } catch (error) {
        this.error = error.message
        console.error('Failed to fetch podcasts:', error)
      } finally {
        this.loading = false
      }
    },

    async fetchPodcast(id) {
      this.loading = true
      this.error = null

      try {
        const data = await api.getPodcast(id)
        this.currentPodcast = data.podcast
      } catch (error) {
        this.error = error.message
        console.error('Failed to fetch podcast:', error)
      } finally {
        this.loading = false
      }
    },

    setPage(page) {
      this.pagination.page = page
    }
  }
})
