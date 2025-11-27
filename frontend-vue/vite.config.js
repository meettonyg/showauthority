import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  server: {
    port: 3000,
    proxy: {
      '/api': {
        target: 'https://your-wordpress-site.com',
        changeOrigin: true,
        rewrite: (path) => path.replace(/^\/api/, '/wp-json/podcast-influence/v1/public')
      }
    }
  }
})
