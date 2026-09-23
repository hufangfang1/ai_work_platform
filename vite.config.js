import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  build: {
    rollupOptions: {
      input: { main: 'index.html', reconstruction: 'reconstruction.html', neural: 'neural.html', gaussian: 'gaussian.html' },
    },
  },
  server: { port: 6173 },
})
