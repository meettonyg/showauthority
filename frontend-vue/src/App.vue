<template>
  <div id="app">
    <header class="app-header">
      <div class="container">
        <h1>Guestify Interview Tracker</h1>
        <nav>
          <router-link to="/">Home</router-link>
          <router-link to="/podcasts">Podcasts</router-link>
          <router-link to="/guests">Guests</router-link>
        </nav>
      </div>
    </header>

    <main class="app-main">
      <router-view />
    </main>

    <footer class="app-footer">
      <div class="container">
        <p>&copy; 2024 Guestify Interview Tracker</p>
      </div>
    </footer>
  </div>
</template>

<script setup>
import { onMounted } from 'vue'

onMounted(() => {
  if (import.meta.env.DEV) {
    console.log('Guestify Interview Tracker loaded')
  }
})
</script>

<style>
.app-header {
  background: #1a1a1a;
  color: #fff;
  padding: 1.5rem 0;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.app-header h1 {
  margin: 0 0 1rem 0;
  font-size: 1.8rem;
}

.app-header nav {
  display: flex;
  gap: 1.5rem;
}

.app-header nav a {
  color: #fff;
  text-decoration: none;
  padding: 0.5rem 1rem;
  border-radius: 4px;
  transition: background 0.3s ease;
}

.app-header nav a:hover,
.app-header nav a.router-link-active {
  background: rgba(255, 255, 255, 0.1);
}

.app-main {
  min-height: calc(100vh - 200px);
  padding: 2rem 0;
}

.app-footer {
  background: #f5f5f5;
  padding: 2rem 0;
  margin-top: 4rem;
  text-align: center;
  color: #666;
}

.container {
  max-width: 1200px;
  margin: 0 auto;
  padding: 0 1rem;
}
</style>
