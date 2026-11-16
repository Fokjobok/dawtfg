import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'
import { apiDocs } from './dev/api-docs.js'

export default defineConfig({
  plugins: [react(), apiDocs()],
  test: {
    environment: 'jsdom',
    env: { VITE_API_BASE_URL: '/api/v1' },
    unstubGlobals: true,
  },
  server: {
    host: process.env.VITE_HOST || '127.0.0.1',
    port: 5173,
    strictPort: true,
    watch: {
      usePolling: process.env.VITE_USE_POLLING === 'true',
    },
    hmr: {
      clientPort: Number(process.env.VITE_HMR_CLIENT_PORT || 5173),
    },
    proxy: {
      '/api': {
        target: process.env.VITE_API_PROXY_TARGET || 'http://127.0.0.1:8000',
        changeOrigin: false,
      },
      '/sanctum': {
        target: process.env.VITE_API_PROXY_TARGET || 'http://127.0.0.1:8000',
        changeOrigin: false,
      },
    },
  },
  preview: {
    host: '127.0.0.1',
    port: 4173,
    strictPort: true,
  },
})
