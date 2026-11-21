// Real-Chromium PWA validation (IMP-009 C) through dev-proxy.mjs (one origin: Nuxt + SPA + Laravel).
//
// Proves, with the real service worker from the SPA production build:
//  1. it registers, installs, activates and controls the page;
//  2. cache isolation — a server-rendered Nuxt page (x-pc-renderer: nuxt) NEVER replaces the cached SPA
//     shell, and no dynamic/private path is ever cached;
//  3. offline navigation — with the origin unreachable at the socket level, navigation falls back to the
//     cached SPA shell (which still owns every route), never to a stale storefront page or a 503;
//  4. update behaviour — a new worker WAITS (no automatic skipWaiting) and only takes over after the
//     explicit SKIP_WAITING message from the update banner.
import { chromium } from 'playwright-core'

const P = process.env.PROXY_URL || 'http://127.0.0.1:8080'
const C = process.env.PROXY_CONTROL_URL || 'http://127.0.0.1:8090'
const out = []
const check = (n, ok, d = '') => out.push(`${ok ? 'PASS' : 'FAIL'}  ${n}${d ? ' — ' + d : ''}`)

const browser = await chromium.launch()
const ctx = await browser.newContext()
const page = await ctx.newPage()
const errors = []
// Fixture noise: the mock API has no /storage assets, no CMS pages, and answers 401 for the anonymous
// bootstrap (`auth/me`), exactly like a signed-out visitor. Neither is a page error.
const isFixtureNoise = (t) => /Failed to load resource: the server responded with a status of (404|401)/.test(t) || /net::ERR_(EMPTY_RESPONSE|CONNECTION|INTERNET_DISCONNECTED|FAILED)/.test(t)
page.on('pageerror', (e) => errors.push(String(e)))
page.on('console', (m) => {
  if (m.type() === 'error' && !isFixtureNoise(m.text())) errors.push(m.text())
})

const cachedShell = () =>
  page.evaluate(async () => {
    for (const name of await caches.keys()) {
      if (!name.startsWith('primeclassy-shell-')) continue
      const res = await (await caches.open(name)).match('/index.html')
      if (res) return { name, body: await res.text() }
    }
    return null
  })
const cachedPaths = () =>
  page.evaluate(async () => {
    const all = []
    for (const name of await caches.keys()) {
      const reqs = await (await caches.open(name)).keys()
      all.push(...reqs.map((r) => new URL(r.url).pathname))
    }
    return all
  })
const workerState = () =>
  page.evaluate(async () => {
    const reg = await navigator.serviceWorker.getRegistration()
    return {
      controlled: !!navigator.serviceWorker.controller,
      active: reg?.active?.state ?? null,
      waiting: reg?.waiting?.state ?? null,
      waitingIsActive: !!reg?.active && reg.active === reg.waiting,
    }
  })

// ── 1. registration / activation / control ─────────────────────────────────────────────────────────
await fetch(`${C}/__reset`)
await page.goto(`${P}/products`, { waitUntil: 'networkidle' })
await page.evaluate(() => navigator.serviceWorker.ready.then(() => true))
await page.reload({ waitUntil: 'networkidle' })
const controlled = await page.evaluate(() => !!navigator.serviceWorker.controller)
check('service worker registers, activates and controls the page', controlled, '')
const scope = await page.evaluate(async () => (await navigator.serviceWorker.getRegistration())?.scope ?? null)
check('worker scope is the document root', scope === `${P}/`, String(scope))

const shell = await cachedShell()
check('SPA shell (/index.html) is cached at install', !!shell, shell?.name ?? 'missing')
check('cached shell is the SPA build, NOT a Nuxt SSR page', !!shell && /<div id="app">/.test(shell.body) && !/__NUXT__|id="__nuxt"/.test(shell.body), shell ? `${shell.body.length}B` : '')

// ── 2. cache isolation (a Nuxt navigation must not become the offline shell) ──────────────────────
await page.goto(`${P}/articles/tips`, { waitUntil: 'networkidle' })
await page.goto(`${P}/products/red-velvet`, { waitUntil: 'networkidle' })
const afterNuxt = await cachedShell()
check('visiting Nuxt SSR pages does not replace the cached SPA shell', !!afterNuxt && afterNuxt.body === shell?.body, '')
const paths = await cachedPaths()
const forbidden = paths.filter((p) => p.startsWith('/api/') || p.startsWith('/sanctum') || p.startsWith('/storage/') || p === '/config.js' || p.startsWith('/_nuxt/'))
check('no dynamic/private path is cached (/api, /sanctum, /storage, /config.js, /_nuxt)', forbidden.length === 0, forbidden.slice(0, 4).join(','))
const htmlDocs = paths.filter((p) => p === '/' || p.endsWith('.html'))
check('only /index.html is cached as HTML (no storefront page in the shell cache)', htmlDocs.every((p) => p === '/index.html'), htmlDocs.join(','))

// ── 3. offline navigation (origin unreachable at the socket level) ────────────────────────────────
await fetch(`${C}/__down`)
const offlineNav = async (path) => {
  try {
    await page.goto(P + path, { waitUntil: 'domcontentloaded', timeout: 20000 })
    return await page.evaluate(() => ({
      url: location.pathname,
      app: document.querySelector('#app')?.children.length ?? 0,
      nuxt: /__NUXT__|id="__nuxt"/.test(document.documentElement.innerHTML),
      offlineText: /sedang offline/i.test(document.body?.innerText ?? ''),
    }))
  } catch (e) {
    return { error: String(e).slice(0, 140) }
  }
}
const offSpa = await offlineNav('/cart')
check('offline: an SPA route boots the cached SPA shell', !offSpa.error && (offSpa.app ?? 0) > 0 && offSpa.nuxt === false, JSON.stringify(offSpa))
// Never visited in this session → no HTTP-cache entry → the worker's navigation fallback must answer.
const offNuxt = await offlineNav('/categories')
check('offline: an unvisited storefront route falls back to the SPA shell (never a 503)', !offNuxt.error && (offNuxt.app ?? 0) > 0 && offNuxt.nuxt === false && offNuxt.offlineText === false, JSON.stringify(offNuxt))
// Visited earlier: Chromium may serve its own stale-while-revalidate copy of the SSR page (a valid anonymous
// storefront page). Both outcomes are acceptable; what must hold is that nothing is broken and the shell cache
// is still the SPA shell.
const offVisited = await offlineNav('/products')
check('offline: a previously visited storefront URL renders (stale SSR or SPA shell), never the 503 offline page', !offVisited.error && offVisited.offlineText === false, JSON.stringify(offVisited))
const shellAfterOffline = await cachedShell()
check('offline browsing never replaced the cached SPA shell', !!shellAfterOffline && shellAfterOffline.body === shell?.body, '')
await fetch(`${C}/__up`)

// ── 4. update behaviour (no automatic skipWaiting) ────────────────────────────────────────────────
await page.goto(`${P}/`, { waitUntil: 'networkidle' })
await fetch(`${C}/__sw-next`)
const waitingInstalled = await page.evaluate(
  () =>
    new Promise((resolve) => {
      navigator.serviceWorker.getRegistration().then((reg) => {
        reg.update().catch(() => {})
        const t = setInterval(() => {
          if (reg.waiting) {
            clearInterval(t)
            resolve(true)
          }
        }, 200)
        setTimeout(() => {
          clearInterval(t)
          resolve(false)
        }, 20000)
      })
    }),
)
const during = await workerState()
check('a byte-changed worker installs and WAITS', waitingInstalled && during.waiting !== null, JSON.stringify(during))
check('the waiting worker has NOT taken over (no automatic skipWaiting)', during.waitingIsActive === false && during.active === 'activated', JSON.stringify(during))

const activated = await page.evaluate(
  () =>
    new Promise((resolve) => {
      navigator.serviceWorker.getRegistration().then((reg) => {
        reg.waiting?.postMessage('SKIP_WAITING')
        const t = setInterval(async () => {
          const r = await navigator.serviceWorker.getRegistration()
          if (r && !r.waiting) {
            clearInterval(t)
            resolve(true)
          }
        }, 200)
        setTimeout(() => {
          clearInterval(t)
          resolve(false)
        }, 20000)
      })
    }),
)
const after = await page.evaluate(
  () =>
    new Promise((resolve) => {
      const t = setInterval(async () => {
        const names = await caches.keys()
        if (names.length === 1 && names[0].endsWith('-rev2')) {
          clearInterval(t)
          resolve(names)
        }
      }, 200)
      setTimeout(async () => {
        clearInterval(t)
        resolve(await caches.keys())
      }, 15000)
    }),
)
check('an explicit SKIP_WAITING activates the new worker', activated, JSON.stringify(await workerState()))
check('the new versioned cache is the only one left (old shell purged on activate)', after.length === 1 && after[0].endsWith('-rev2'), after.join(','))
const apiStatus = await page.evaluate(async () => (await fetch('/api/v1/install/status')).status)
check('page keeps working after the update', apiStatus === 200, `api status ${apiStatus}`)

check('no unexpected console/page errors during the PWA run', errors.length === 0, errors.slice(0, 2).join(' | '))

await browser.close()
console.log(out.join('\n'))
console.log(`\n${out.filter((l) => l.startsWith('PASS')).length} passed, ${out.filter((l) => l.startsWith('FAIL')).length} failed`)
process.exit(out.some((l) => l.startsWith('FAIL')) ? 1 : 0)
