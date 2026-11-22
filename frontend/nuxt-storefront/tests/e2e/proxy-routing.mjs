// Local deployment-readiness checks (IMP-009 C/D) through dev-proxy.mjs.
//
// Proves the documented routing boundary on ONE origin: Nuxt storefront paths, SPA paths, Go API
// paths, static shell files, the health check, header forwarding (x-pc-renderer) and the rollback drill
// (NUXT_ENABLED=0 → every URL is served by the unchanged SPA build).
const P = process.env.PROXY_URL || 'http://127.0.0.1:8080'
const C = process.env.PROXY_CONTROL_URL || 'http://127.0.0.1:8090'
const R = process.env.ROLLBACK_URL || 'http://127.0.0.1:8081'
const out = []
const check = (n, ok, d = '') => out.push(`${ok ? 'PASS' : 'FAIL'}  ${n}${d ? ' — ' + d : ''}`)
const get = async (base, path, init = {}) => {
  const r = await fetch(base + path, { redirect: 'manual', ...init })
  return { r, t: await r.text() }
}

await fetch(`${C}/__reset`)

// ── Nuxt-owned paths (SSR) ──────────────────────────────────────────────────────────────────────────
for (const p of ['/', '/products', '/products?page=2', '/products/red-velvet', '/categories', '/articles', '/articles/tips', '/toko-kami']) {
  const { r, t } = await get(P, p)
  check(`nuxt ${p}: 200 + x-pc-renderer forwarded by the proxy`, r.status === 200 && r.headers.get('x-pc-renderer') === 'nuxt', `status ${r.status} renderer ${r.headers.get('x-pc-renderer')}`)
  check(`nuxt ${p}: server-rendered HTML (not the SPA shell)`, /<html/.test(t) && !/<div id="app">/.test(t), '')
}
{
  // /page/* is Nuxt-owned; the mock has no CMS page, so the storefront must answer a REAL 404 (still SSR + marker).
  const { r, t } = await get(P, '/page/foo')
  check('nuxt /page/foo: missing content → real 404 with the SSR marker', r.status === 404 && r.headers.get('x-pc-renderer') === 'nuxt' && /<html/.test(t), `status ${r.status} renderer ${r.headers.get('x-pc-renderer')}`)
}
for (const p of ['/sitemap.xml', '/robots.txt', '/healthz']) {
  const { r } = await get(P, p)
  check(`nuxt ${p}: 200 via proxy`, r.status === 200, `status ${r.status}`)
}

// ── SPA-owned paths (unchanged) ─────────────────────────────────────────────────────────────────────
for (const p of ['/dashboard', '/dashboard/piutang', '/login', '/cart', '/checkout', '/profile']) {
  const { r, t } = await get(P, p)
  check(`spa ${p}: 200 SPA shell, no SSR marker`, r.status === 200 && /<div id="app">/.test(t) && !r.headers.get('x-pc-renderer'), `status ${r.status} marker ${r.headers.get('x-pc-renderer')}`)
}
{
  const { r, t } = await get(P, '/sw.js')
  check('/sw.js served by the SPA build (service worker present)', r.status === 200 && /primeclassy-shell-/.test(t), `status ${r.status}`)
  const cfg = await get(P, '/config.js')
  check('/config.js served by the SPA build (runtime-editable, never Nuxt)', cfg.r.status === 200 && /__APP_CONFIG__/.test(cfg.t))
  const man = await get(P, '/manifest.webmanifest')
  check('/manifest.webmanifest served from the document root', man.r.status === 200 && /"name"/.test(man.t))
  const html = (await get(P, '/')).t
  const asset = (html.match(/\/_nuxt\/[A-Za-z0-9._-]+\.js/) || [])[0]
  check('Nuxt page references /_nuxt/ assets', !!asset, asset ?? 'none')
  if (asset) {
    const a = await get(P, asset)
    check(`/_nuxt/* routed to Nuxt and served`, a.r.status === 200 && /javascript|ecmascript/.test(a.r.headers.get('content-type') ?? ''), `status ${a.r.status}`)
  }
}

// ── Go API paths ────────────────────────────────────────────────────────────────────────────────────
{
  const s = await get(P, '/api/v1/install/status')
  check('/api/* proxied to the Go API origin (JSON envelope)', s.r.status === 200 && /"data"/.test(s.t), `status ${s.r.status}`)
  const up = await get(P, '/up')
  check('/up proxied to Go (upstream 404 forwarded verbatim, mock has no /up)', up.r.status === 404, `status ${up.r.status}`)
}

// ── routing table recorded by the proxy (no path is served by the wrong runtime) ───────────────────
{
  const { routes } = await (await fetch(`${C}/__routes`)).json()
  const nuxtRendered = routes.filter((x) => x.renderer === 'nuxt').map((x) => x.path)
  const spaOwned = ['/dashboard', '/dashboard/piutang', '/login', '/cart', '/checkout', '/profile', '/sw.js', '/config.js', '/manifest.webmanifest']
  check('no SPA-owned/static path was rendered by Nuxt', !nuxtRendered.some((p) => spaOwned.includes(p)), nuxtRendered.filter((p) => spaOwned.includes(p)).join(','))
  const storefrontPaths = ['/', '/products', '/categories', '/articles', '/toko-kami', '/page/foo', '/sitemap.xml', '/robots.txt', '/healthz']
  const servedBySpa = routes.filter((x) => x.target === 'spa').map((x) => x.path)
  check('no storefront path was served by the SPA', !servedBySpa.some((p) => storefrontPaths.includes(p)), servedBySpa.filter((p) => storefrontPaths.includes(p)).join(','))
}

// ── rollback drill: NUXT_ENABLED=0 → the unchanged SPA owns every URL ──────────────────────────────
if (process.env.ROLLBACK_URL !== 'skip') {
  let reachable = true
  try {
    await get(R, '/')
  } catch {
    reachable = false
  }
  if (!reachable) {
    check(`rollback drill skipped (no proxy on ${R})`, false, 'start a second dev-proxy with NUXT_ENABLED=0')
  } else {
    for (const p of ['/', '/products', '/categories', '/articles/tips', '/toko-kami', '/dashboard']) {
      const { r, t } = await get(R, p)
      check(`rollback ${p}: SPA shell, no SSR marker`, r.status === 200 && /<div id="app">/.test(t) && !r.headers.get('x-pc-renderer'), `status ${r.status} marker ${r.headers.get('x-pc-renderer')}`)
    }
    const api = await get(R, '/api/v1/install/status')
    check('rollback: Go API still reachable', api.r.status === 200 && /"data"/.test(api.t), `status ${api.r.status}`)
  }
}

console.log(out.join('\n'))
console.log(`\n${out.filter((l) => l.startsWith('PASS')).length} passed, ${out.filter((l) => l.startsWith('FAIL')).length} failed`)
process.exit(out.some((l) => l.startsWith('FAIL')) ? 1 : 0)
