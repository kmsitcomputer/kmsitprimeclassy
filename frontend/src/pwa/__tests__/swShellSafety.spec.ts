import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import vm from 'node:vm'
import { describe, expect, it } from 'vitest'

/** Loads the real service-worker template into a sandbox and drives its fetch handler. */
function loadWorker() {
  const source = readFileSync(join(__dirname, '../../../src-pwa/sw.js'), 'utf8')
    .replace("'__SHELL_VERSION__'", "'test'")
    .replace('__PRECACHE_URLS__', '[]')
  const listeners: Record<string, (e: unknown) => void> = {}
  const puts: string[] = []
  const cache = { put: async (key: string) => { puts.push(key) }, match: async () => undefined, addAll: async () => undefined }
  const sandbox = {
    self: { location: { origin: 'https://shop.test', href: 'https://shop.test/' }, addEventListener: (t: string, fn: (e: unknown) => void) => { listeners[t] = fn }, skipWaiting() {} },
    caches: { open: async () => cache, keys: async () => [], delete: async () => true, match: async () => undefined },
    URL, Response, console,
    fetch: async () => { throw new Error('network') },
  }
  vm.createContext(sandbox)
  vm.runInContext(source, sandbox)
  return { listeners, puts, sandbox }
}

function navigation(url: string) {
  let responded: Promise<Response> | undefined
  const event = { request: { method: 'GET', url, mode: 'navigate' }, respondWith: (p: Promise<Response>) => { responded = p } }
  return { event, get done() { return responded } }
}

function htmlResponse(url: string, headers: Record<string, string>) {
  const r = new Response('<html></html>', { status: 200, headers: { 'content-type': 'text/html; charset=utf-8', ...headers } })
  Object.defineProperty(r, 'url', { value: url })
  Object.defineProperty(r, 'type', { value: 'basic' })
  return r
}

describe('service worker vs. the Nuxt storefront (IMP-009)', () => {
  it('still caches a genuine SPA shell navigation as /index.html', async () => {
    const w = loadWorker()
    ;(w.sandbox as { fetch: unknown }).fetch = async () => htmlResponse('https://shop.test/dashboard', {})
    const nav = navigation('https://shop.test/dashboard')
    w.listeners.fetch!(nav.event)
    await nav.done
    await new Promise((r) => setTimeout(r, 0))
    expect(w.puts).toEqual(['/index.html'])
  })

  it('NEVER caches a server-rendered Nuxt page (x-pc-renderer: nuxt) as the offline shell', async () => {
    const w = loadWorker()
    ;(w.sandbox as { fetch: unknown }).fetch = async () => htmlResponse('https://shop.test/products/red-velvet', { 'x-pc-renderer': 'nuxt' })
    const nav = navigation('https://shop.test/products/red-velvet')
    w.listeners.fetch!(nav.event)
    await nav.done
    await new Promise((r) => setTimeout(r, 0))
    expect(w.puts).toEqual([])
  })

  it('leaves Nuxt build assets (/_nuxt/*) to the HTTP cache — the worker does not intercept them', () => {
    const w = loadWorker()
    let intercepted = false
    w.listeners.fetch!({ request: { method: 'GET', url: 'https://shop.test/_nuxt/entry.abc123.js', mode: 'no-cors' }, respondWith: () => { intercepted = true } })
    expect(intercepted).toBe(false)
  })
})
