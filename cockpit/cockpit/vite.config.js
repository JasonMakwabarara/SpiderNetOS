import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'path'

export default defineConfig({
  plugins: [vue()],
  base: '/cockpit/',
  server: {
    host: '0.0.0.0',
    port: 3009,
    strictPort: false,
    hmr: {
      protocol: 'ws',
      host: 'localhost',
      port: 3009
    },
    watch: {
      usePolling: true,
      interval: 1000,
    },
  },
  preview: {
    host: '0.0.0.0',
    port: 3009,
  },
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
})
