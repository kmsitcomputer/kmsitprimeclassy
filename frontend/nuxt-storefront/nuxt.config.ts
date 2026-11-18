import { fileURLToPath } from 'node:url'
import tailwindcss from '@tailwindcss/vite'

/**
 * PrimeClassy Nuxt storefront (IMP-009) — an ADDITIVE server-side-rendering layer for the PUBLIC
 * storefront only. It re-uses the existing Vue 3 SPA source (components, Pinia stores, API modules,
 * i18n, Tailwind) through the `@` alias; it does not copy or fork any of it. The Vue/Vite SPA in
 * ../src remains the application of record for the dashboard and every non-public route.
 *
 * Routing boundary: ../src/router/boundary.ts. Deployment/rollback: docs/20-IMP009-NUXT-STOREFRONT.md.
 */
const spaSrc = fileURLToPath(new URL('../src', import.meta.url))
const spaRoot = fileURLToPath(new URL('..', import.meta.url))

// SPA-owned authenticated paths. The front proxy must never send them here; if it does, the boundary
// middleware answers 404 (fail closed — never a client-only shell, which would reload-loop) and this
// header guarantees no shared cache keeps even that response.
const noStore = { 'cache-control': 'private, no-store', 'x-pc-renderer': 'nuxt' }
// Anonymous server-rendered storefront pages: identical for every visitor (no cookie, no session is ever
// used while rendering), so a short shared-cache window is safe.
const publicSsr = {
  'cache-control': 'public, max-age=0, s-maxage=60, stale-while-revalidate=300',
  // The service worker uses this marker to avoid caching SSR HTML as the SPA offline shell.
  'x-pc-renderer': 'nuxt',
}

export default defineNuxtConfig({
  compatibilityDate: '2026-11-18',
  devtools: { enabled: false },
  telemetry: false,
  ssr: true,
  pages: true,

  alias: { '@': spaSrc },
  modules: ['@pinia/nuxt'],
  css: [fileURLToPath(new URL('./app/assets/storefront.css', import.meta.url))],

  // The SPA code base imports everything explicitly; do not let Nuxt auto-scan/auto-register it.
  components: { dirs: [] },
  imports: { scan: false },

  vite: {
    plugins: [tailwindcss()],
    // Diagnostics only: NUXT_DEBUG_HYDRATION=1 at build time makes Vue's production build print WHICH node mismatched.
    define: process.env.NUXT_DEBUG_HYDRATION ? { __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: true } : {},
    server: { fs: { allow: [spaRoot] } },
  },

  runtimeConfig: {
    // PRIVATE (server only, never exposed to the browser): where SSR reaches the Laravel API. NUXT_API_INTERNAL_BASE.
    apiInternalBase: 'http://localhost:8000',
    public: {
      // Absolute public origin used for canonical/OG/sitemap URLs. NUXT_PUBLIC_SITE_URL (falls back to the request origin).
      siteUrl: '',
    },
  },

  app: {
    // Distinct from the SPA's /assets/ so the two builds can share one document root without collisions.
    buildAssetsDir: '/_nuxt/',
    head: {
      htmlAttrs: { lang: 'id' },
      charset: 'utf-8',
      viewport: 'width=device-width, initial-scale=1.0',
      title: 'Prime Classy Cake & Cookies',
      link: [
        { rel: 'icon', href: '/favicon.ico' },
        { rel: 'manifest', href: '/manifest.webmanifest' },
        { rel: 'apple-touch-icon', href: '/icons/apple-touch-icon.png' },
        { rel: 'preconnect', href: 'https://fonts.googleapis.com' },
        { rel: 'preconnect', href: 'https://fonts.gstatic.com', crossorigin: '' },
        {
          rel: 'stylesheet',
          href: 'https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap',
        },
      ],
      meta: [
        { name: 'theme-color', content: '#8f1d3c' },
        { name: 'mobile-web-app-capable', content: 'yes' },
        { name: 'apple-mobile-web-app-capable', content: 'yes' },
        { name: 'apple-mobile-web-app-title', content: 'PrimeClassy' },
      ],
      script: [
        // Runtime config (backend API URL), same blocking script the SPA shell loads — deployed server-edited file.
        { src: '/config.js', tagPosition: 'head' },
        {
          // Same pre-paint theme application as the SPA's index.html (avoids a light flash).
          tagPosition: 'head',
          innerHTML:
            "try{var s=localStorage.getItem('pc-theme');var d=window.matchMedia('(prefers-color-scheme: dark)').matches;if(s==='dark'||(s!=='light'&&d)){document.documentElement.classList.add('dark')}}catch(e){}",
        },
      ],
    },
  },

  routeRules: {
    '/': { headers: publicSsr },
    '/products': { headers: publicSsr },
    '/products/**': { headers: publicSsr },
    '/categories': { headers: publicSsr },
    '/articles': { headers: publicSsr },
    '/articles/**': { headers: publicSsr },
    '/page/**': { headers: publicSsr },
    '/toko-kami': { headers: publicSsr },
    '/sitemap.xml': { headers: { 'cache-control': 'public, max-age=0, s-maxage=3600' } },
    '/dashboard/**': { headers: noStore },
    '/admin/**': { headers: noStore },
    '/profile': { headers: noStore },
    '/orders/**': { headers: noStore },
    '/account/**': { headers: noStore },
    '/print/**': { headers: noStore },
  },

  nitro: {
    // Shared static files (favicon, icons, manifest, runtime config.js) live in the SPA's public/ — one copy.
    publicAssets: [{ dir: fileURLToPath(new URL('../public', import.meta.url)), baseURL: '/' }],
    // Dev only: same-origin API like the SPA's Vite proxy (Host header preserved for SANCTUM_STATEFUL_DOMAINS).
    devProxy: {
      '/api': { target: 'http://localhost:8000/api', changeOrigin: false },
      '/sanctum': { target: 'http://localhost:8000/sanctum', changeOrigin: false },
      '/storage': { target: 'http://localhost:8000/storage', changeOrigin: false },
    },
  },

  // Lets request-scoped helpers (per-request locale/i18n) work across `await` on the server.
  experimental: { asyncContext: true },

  typescript: { strict: true },
})
