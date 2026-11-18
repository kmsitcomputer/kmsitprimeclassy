import { chromium } from 'playwright-core'
const B = process.env.NUXT_URL, MOCK = process.env.MOCK_URL
const out = []; const check = (n, ok, d = '') => out.push(`${ok ? 'PASS' : 'FAIL'}  ${n}${d ? ' — ' + d : ''}`)
const browser = await chromium.launch()
async function run(path, { user = false } = {}) {
  const ctx = await browser.newContext(); const page = await ctx.newPage()
  const msgs = [], errs = [], api = []
  page.on('console', (m) => msgs.push(`${m.type()}: ${m.text()}`)); page.on('pageerror', (e) => errs.push(String(e)))
  await page.route('**/api/v1/**', async (route) => {
    const u = new URL(route.request().url()); api.push(`${route.request().method()} ${u.pathname}`)
    if (user && u.pathname.endsWith('/auth/me')) return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, message: 'ok', data: { user: { id: 5, name: 'Budi', email: 'b@x.id', role: 'konsumen', agent_id: 1 }, permissions: [] }, errors: null, meta: null }) })
    await route.continue({ url: `${MOCK}${u.pathname}${u.search}` })
  })
  await page.goto(B + path, { waitUntil: 'networkidle' }); await page.waitForTimeout(700)
  const hyd = msgs.filter((m) => /hydrat|mismatch/i.test(m))
  await ctx.close(); return { hyd, errs, api }
}
for (const p of ['/', '/products', '/products?category=kue&page=1', '/categories', '/articles', '/articles/tips', '/toko-kami', '/products/red-velvet']) {
  const r = await run(p)
  check(`${p}: hydrates with 0 mismatches, 0 page errors`, r.hyd.length === 0 && r.errs.length === 0, [...r.hyd, ...r.errs].slice(0, 1).join(' ').slice(0, 200))
}
let r = await run('/products/red-velvet', { user: true })
check('signed-in visitor: product re-fetched after session known (actor-specific view)', r.api.filter((c) => c === 'GET /api/v1/products/red-velvet').length >= 1, r.api.join(','))
check('signed-in visitor: no hydration mismatch', r.hyd.length === 0)
r = await run('/products', { user: true })
check('signed-in visitor: listing re-fetched', r.api.filter((c) => c === 'GET /api/v1/products').length >= 1)
r = await run('/products/red-velvet')
check('guest: auth/me called exactly once, 401 handled (no page error)', r.api.filter((c) => c.endsWith('/auth/me')).length === 1 && r.errs.length === 0)
console.log(out.join('\n')); console.log(`\n${out.filter((l) => l.startsWith('PASS')).length} passed, ${out.filter((l) => l.startsWith('FAIL')).length} failed`)
await browser.close()
