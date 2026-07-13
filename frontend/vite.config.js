import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { VitePWA } from 'vite-plugin-pwa'
import { fileURLToPath, URL } from 'node:url'

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    // PWA: installable on mobile ("Add to Home Screen"), standalone display.
    // IMPORTANT: the service worker must NEVER cache or intercept /api (auth tokens,
    // SSE stream, live job data) — only the static app shell is precached.
    VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['favicon.svg'],
      manifest: {
        name: 'phpBorg',
        short_name: 'phpBorg',
        description: 'Enterprise backup management (BorgBackup)',
        theme_color: '#111827',
        background_color: '#111827',
        display: 'standalone',
        start_url: '/',
        icons: [
          { src: '/pwa-192x192.png', sizes: '192x192', type: 'image/png' },
          { src: '/pwa-512x512.png', sizes: '512x512', type: 'image/png' },
          { src: '/pwa-maskable-512x512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
      workbox: {
        // SPA navigation fallback, but NEVER for API routes (incl. the SSE stream)
        navigateFallbackDenylist: [/^\/api\//, /^\/downloads\//],
        globPatterns: ['**/*.{js,css,html,ico,png,svg,woff2}'],
        // A couple of view chunks exceed workbox's 2 MiB default precache limit
        maximumFileSizeToCacheInBytes: 4 * 1024 * 1024,
      },
    }),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    }
  },
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://localhost:8080',
        changeOrigin: true,
      }
    }
  }
})
