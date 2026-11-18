// Local, production-shaped reverse proxy for IMP-009 verification (test fixture — never deployed).
//
// It implements ONLY the routing contract documented in docs/20-IMP009-NUXT-STOREFRONT.md §8, so the
// same-origin behaviour of the real deployment (Nuxt storefront + Vue SPA + Laravel API on one origin)
// can be exercised locally in one browser origin:
//
//   Nuxt-owned     /  /products  /products/*  /categories  /articles  /articles/*  /page/*  /toko-kami
//                  /_nuxt/*  /sitemap.xml  /robots.txt  /healthz
//   Laravel        /api/*  /sanctum*  /storage/*  /up
//   SPA (static)   everything else, with index.html history fallback (/assets/*, /sw.js, /config.js, …)
//
// Response headers are passed through untouched, so `x-pc-renderer: nuxt` reaches the browser and the
// service worker exactly as in production.
//
// Env: PORT (8080) · NUXT_ORIGIN (http://127.0.0.1:3100) · LARAVEL_ORIGIN (http://127.0.0.1:8000)
//      SPA_DIR (/app/dist) · NUXT_ENABLED=0 → rollback drill: every route is served by the SPA build.
// Control (test only), on PORT+10 so it stays reachable while traffic is "down":
//   GET /__routes · GET /__reset · GET /__sw-next (byte-changed /sw.js) · GET /__down · GET /__up
import http from 'node:http'
import { existsSync, readFileSync, statSync } from 'node:fs'
import { extname, join, normalize, resolve, sep } from 'node:path'

const PORT = Number(process.env.PORT || 8080)
const CONTROL_PORT = PORT + 10
const NUXT = process.env.NUXT_ORIGIN || 'http://127.0.0.1:3100'
const LARAVEL = process.env.LARAVEL_ORIGIN || 'http://127.0.0.1:8000'
const SPA_DIR = resolve(process.env.SPA_DIR || '/app/dist')
const NUXT_ENABLED = process.env.NUXT_ENABLED !== '0'

const NUXT_EXACT = new Set(['/', '/products', '/categories', '/articles', '/toko-kami', '/sitemap.xml', '/robots.txt', '/healthz'])
const NUXT_PREFIXES = ['/products/', '/articles/', '/page/', '/_nuxt/']
const LARAVEL_PREFIXES = ['/api/', '/sanctum', '/storage/', '/up']

const routes = []
let swVariant = false
// "outage" drill: every traffic request fails at the socket level, exactly like an unreachable server, so the
// service worker's navigation fallback is exercised for real (Playwright's offline emulation does not cover
// fetches initiated by a service worker).
let down = false

function target(pathname) {
  if (LARAVEL_PREFIXES.some((p) => pathname === p.replace(/\/$/, '') || pathname.startsWith(p))) return 'laravel'
  if (NUXT_ENABLED && (NUXT_EXACT.has(pathname) || NUXT_PREFIXES.some((p) => pathname.startsWith(p)))) return 'nuxt'
  return 'spa'
}

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.webmanifest': 'application/manifest+json; charset=utf-8',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.svg': 'image/svg+xml',
  '.ico': 'image/x-icon',
  '.woff2': 'font/woff2',
  '.txt': 'text/plain; charset=utf-8',
  '.xml': 'application/xml; charset=utf-8',
}

const HOP = new Set(['connection', 'keep-alive', 'transfer-encoding', 'upgrade', 'proxy-authenticate', 'proxy-authorization', 'te', 'trailer', 'host'])

function passthroughHeaders(headers) {
  const out = {}
  for (const [k, v] of Object.entries(headers)) if (!HOP.has(k.toLowerCase()) && v !== undefined) out[k] = v
  return out
}

function proxy(req, res, baseUrl) {
  const url = new URL(req.url, baseUrl)
  const headers = { ...passthroughHeaders(req.headers), host: url.host }
  const upstream = http.request(
    { protocol: url.protocol, hostname: url.hostname, port: url.port, path: url.pathname + url.search, method: req.method, headers },
    (up) => {
      res.writeHead(up.statusCode || 502, passthroughHeaders(up.headers))
      routes.push({ path: req.url.split('?')[0], target: new URL(baseUrl).host, status: up.statusCode, renderer: up.headers['x-pc-renderer'] ?? null })
      up.pipe(res)
    },
  )
  upstream.on('error', (e) => {
    routes.push({ path: req.url.split('?')[0], target: new URL(baseUrl).host, status: 502, renderer: null })
    res.writeHead(502, { 'content-type': 'text/plain' }).end(`upstream error: ${e.code ?? e.message}`)
  })
  req.pipe(upstream)
}

function serveSpa(req, res, pathname) {
  const clean = normalize(decodeURIComponent(pathname)).replace(/^([/\\])+/, '')
  let file = join(SPA_DIR, clean)
  if (!file.startsWith(SPA_DIR + sep) && file !== SPA_DIR) file = join(SPA_DIR, 'index.html')
  if (pathname === '/' || !existsSync(file) || statSync(file).isDirectory()) file = join(SPA_DIR, 'index.html')
  let body = readFileSync(file)
  const headers = { 'content-type': MIME[extname(file)] ?? 'application/octet-stream', 'cache-control': 'no-store' }
  // Update-behaviour drill: the same URL, different bytes + a different shell version.
  if (pathname === '/sw.js' && swVariant) {
    body = Buffer.from(body.toString('utf8').replace(/const SHELL_VERSION = '([^']*)'/, "const SHELL_VERSION = '$1-rev2'"))
    headers['content-type'] = 'text/javascript; charset=utf-8'
  }
  routes.push({ path: pathname, target: 'spa', status: 200, renderer: null, file: file.slice(SPA_DIR.length) })
  res.writeHead(200, headers).end(body)
}

const server = http.createServer((req, res) => {
  if (down) {
    // A network-level failure (connection reset), not an HTTP error: what the service worker sees when the
    // server is unreachable.
    req.socket.destroy()
    return
  }
  const pathname = new URL(req.url, `http://127.0.0.1:${PORT}`).pathname

  const to = target(pathname)
  if (to === 'nuxt') return proxy(req, res, NUXT)
  if (to === 'laravel') return proxy(req, res, LARAVEL)
  serveSpa(req, res, pathname)
})

const control = http.createServer((req, res) => {
  const pathname = new URL(req.url, `http://127.0.0.1:${CONTROL_PORT}`).pathname
  const json = (value) => res.writeHead(200, { 'content-type': 'application/json', 'cache-control': 'no-store' }).end(JSON.stringify(value))
  if (pathname === '/__routes') return json({ routes, nuxtEnabled: NUXT_ENABLED, down })
  if (pathname === '/__reset') {
    routes.length = 0
    down = false
    swVariant = false
    return json({ ok: true })
  }
  if (pathname === '/__sw-next') {
    swVariant = true
    return json({ ok: true })
  }
  if (pathname === '/__down') {
    down = true
    return json({ ok: true })
  }
  if (pathname === '/__up') {
    down = false
    return json({ ok: true })
  }
  res.writeHead(404, { 'content-type': 'application/json' }).end('{"error":"unknown control"}')
})

server.listen(PORT, '0.0.0.0', () => {
  control.listen(CONTROL_PORT, '0.0.0.0')
  console.log(`[dev-proxy] :${PORT} (control :${CONTROL_PORT}) nuxt=${NUXT_ENABLED ? NUXT : 'disabled (rollback drill)'} laravel=${LARAVEL} spa=${SPA_DIR}`)
})
