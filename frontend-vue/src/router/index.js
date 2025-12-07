import { createRouter, createWebHistory } from 'vue-router'
import Home from '../views/Home.vue'
import Podcasts from '../views/Podcasts.vue'
import PodcastDetail from '../views/PodcastDetail.vue'
import Guests from '../views/Guests.vue'
import GuestDetail from '../views/GuestDetail.vue'
import GuestMetrics from '../views/GuestMetrics.vue'

const routes = [
  {
    path: '/',
    name: 'Home',
    component: Home
  },
  {
    path: '/podcasts',
    name: 'Podcasts',
    component: Podcasts
  },
  {
    path: '/podcasts/:id',
    name: 'PodcastDetail',
    component: PodcastDetail
  },
  {
    path: '/guests',
    name: 'Guests',
    component: Guests
  },
  {
    path: '/guests/metrics',
    name: 'GuestMetrics',
    component: GuestMetrics
  },
  {
    path: '/guests/:id',
    name: 'GuestDetail',
    component: GuestDetail
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

export default router
