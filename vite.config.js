import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  server: {
    proxy: {
      '/api': {
        target: 'http://l-ai-work-platform.orangevip.com',
        changeOrigin: true,
      },
    },
  },
})
