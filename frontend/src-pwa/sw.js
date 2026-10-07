/**
 * PrimeClassy PWA service worker (ZERO-DEPENDENCY V1, no Workbox).
 *
 * This file is a build template: scripts/build-pwa.mjs stamps the
 * production build's version and hashed asset list into the two marked
 * placeholders below and writes the result to `dist/sw.js`. It is never
 * served from source and never registered in development.
 *
 * HARD RULES (do not weaken without Human approval):
 * - NEVER cache /api/*, /sanctum*, /up, /storage/*, /config.js (runtime
 *   server-edited configuration), any non-GET request, or any
 *   cross-origin request. The fetch handler returns early for all of them,
 *   so they always hit the network directly.
 * - Cache Storage holds ONLY user-neutral app-shell/static assets.
 * - No automatic skipWaiting: a waiting worker only activates when the
 *   user explicitly chooses "Muat ulang" in the update banner.
 */

/* eslint-disable no-restricted-globals */
const SHELL_VERSION = '__SHELL_VERSION__'
const CACHE_NAME = `primeclassy-shell-${SHELL_VERSION}`
const PRECACHE_URLS = __PRECACHE_URLS__

// Anything under these same-origin paths is dynamic/server-authoritative
// and must never be served from or written to the cache. This includes
// /config.js: runtime production configuration edited on the deployed
// server without rebuilding — it must always load fresh from the network.
function isBypassedPath(pathname) {
  return (
    pathname === '/up' ||
    pathname === '/config.js' ||
    pathname.startsWith('/api/') ||
    pathname.startsWith('/sanctum') ||
    pathname.startsWith('/storage/')
  )
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches
      .open(CACHE_NAME)
      .then((cache) => cache.addAll(PRECACHE_URLS))
      .then(() => {
        // Ready and waiting — activation happens only via an explicit
        // user-approved SKIP_WAITING message (see below). No skipWaiting().
      }),
  )
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(
          keys
            .filter((key) => key.startsWith('primeclassy-shell-') && key !== CACHE_NAME)
            .map((key) => caches.delete(key)),
        ),
      ),
  )
})

self.addEventListener('message', (event) => {
  // Sent only from the update banner's explicit "Muat ulang" action.
  if (event.data === 'SKIP_WAITING') {
    self.skipWaiting()
  }
})

self.addEventListener('fetch', (event) => {
  const { request } = event

  // Rule 1: non-GET, cross-origin, and server-authoritative paths
  // bypass the service worker cache completely (network only).
  if (request.method !== 'GET') return
  const url = new URL(request.url)
  if (url.origin !== self.location.origin) return
  if (isBypassedPath(url.pathname)) return

  // Rule 2: navigations are network-first so users never sit indefinitely
  // on an old shell while online; the cached shell is only a genuine
  // offline fallback.
  //
  // F-04: the canonical cached /index.html shell is replaced ONLY when the
  // network response is proven to be a valid SPA HTML shell (see
  // isShellSafeResponse). Error pages, redirects/interstitials,
  // cross-origin finals, and non-HTML responses (manifest, sw.js,
  // robots.txt, JSON, ...) are returned to the browser normally but NEVER
  // overwrite the cached shell. Validation uses response metadata/headers
  // only — the body is never read/consumed for validation.
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((response) => {
          if (isShellSafeResponse(response)) {
            const copy = response.clone()
            caches.open(CACHE_NAME).then((cache) => cache.put('/index.html', copy))
          }
          return response
        })
        .catch(() =>
          // MINOR-1: resolve the fallback from THIS worker's versioned
          // cache only — a previous primeclassy-shell-* cache must never
          // provide the fallback for the current worker.
          caches.open(CACHE_NAME).then((cache) =>
            cache.match('/index.html').then(
              (cached) =>
                cached ??
                new Response('PrimeClassy sedang offline.', {
                  status: 503,
                  headers: { 'Content-Type': 'text/plain; charset=utf-8' },
                }),
            ),
          ),
        ),
    )
    return
  }

/**
 * F-04 shell-safety predicate: true only for a same-origin, non-redirected,
 * successful, basic HTML document — i.e. the actual SPA shell.
 */
function isShellSafeResponse(response) {
  if (!response || response.ok !== true) return false
  if (response.status !== 200) return false
  if (response.type !== 'basic') return false
  if (response.redirected === true) return false
  try {
    if (new URL(response.url, self.location.href).origin !== self.location.origin) return false
  } catch {
    return false
  }
  const contentType = response.headers ? response.headers.get('content-type') : null
  if (!contentType) return false
  return contentType.toLowerCase().split(';')[0].trim() === 'text/html'
}

  // Rule 3: same-origin static assets (hashed /assets/*, icons, favicon,
  // manifest) are content-addressed or version-pinned — cache-first with
  // network fill is safe because a new build ships a new CACHE_NAME.
  event.respondWith(
    caches.match(request).then(
      (cached) =>
        cached ??
        fetch(request).then((response) => {
          // Only cache successful reads; a new build ships a new CACHE_NAME
          // so version-pinned assets can never go stale inside one cache.
          if (response && response.status === 200) {
            const copy = response.clone()
            caches.open(CACHE_NAME).then((cache) => cache.put(request, copy))
          }
          return response
        }),
    ),
  )
})
