import { fileURLToPath, URL } from 'node:url'

import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')

  // Single-domain dev baseline: in production the SPA and the Laravel API are
  // served from ONE origin (see deploy/). To mirror that in dev, Vite proxies
  // the API paths to the backend so the browser only ever talks to
  // http://localhost:5173 — no CORS, same-origin session/CSRF cookies, exactly
  // like production. `changeOrigin: false` keeps the Host header as
  // localhost:5173 so it matches SANCTUM_STATEFUL_DOMAINS in backend/.env.
  const apiTarget = env.VITE_DEV_PROXY_TARGET || 'http://localhost:8000'

  return {
    plugins: [vue(), vueDevTools(), tailwindcss()],
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url)),
      },
    },
    server: {
      port: 5173,
      proxy: {
        '/api': { target: apiTarget, changeOrigin: false },
        '/sanctum': { target: apiTarget, changeOrigin: false },
        '/storage': { target: apiTarget, changeOrigin: false },
        '/up': { target: apiTarget, changeOrigin: false },
      },
    },
  }
})
