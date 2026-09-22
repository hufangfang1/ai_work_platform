import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import { deepseekPlugin } from './server/deepseek.js'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  return {
    plugins: [vue(), deepseekPlugin(env)],
    server: { port: 6173 },
  }
})
