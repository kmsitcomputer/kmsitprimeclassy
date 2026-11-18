import { chromium } from 'playwright-core'
const B = process.env.NUXT_URL || 'http://127.0.0.1:3000'
const MOCK = process.env.MOCK_URL || 'http://127.0.0.1:8000'
const out = []
const check = (n, ok, d = '') => out.push(`${ok ? 'PASS' : 'FAIL'}  ${n}${d ? ' — ' + d : ''}`)
const browser = await chromium.launch()
const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } })
await ctx.addInitScript(() => {
  if (!sessionStorage.getItem('seeded')) {
    sessionStorage.setItem('seeded', '1')
    localStorage.setItem('pc-locale', 'en')
    localStorage.setItem('pc-theme', 'dark')
    localStorage.setItem('pc-cart', JSON.stringify([{ key: '1:base', productId: 1, variationId: null, productSlug: 'red-velvet', name: 'Red Velvet', variationLabel: null, unitPrice: 45000, image: null, quantity: 2, stockLimit: 5 }]))
  }
})
const page = await ctx.newPage()
const consoleMsgs = []
const pageErrors = []
const apiCalls = []
const docRequests = []
page.on('console', (m) => consoleMsgs.push(`${m.type()}: ${m.text()}`))
page.on('pageerror', (e) => pageErrors.push(String(e)))
page.on('request', (r) => { if (r.isNavigationRequest()) docRequests.push(r.url()) })
// Production layout is same-origin: the front proxy sends /api to Laravel. Emulate that with the mock.
await page.route('**/api/v1/**', async (route) => {
  const u = new URL(route.request().url())
  apiCalls.push(`${route.request().method()} ${u.pathname}`)
  await route.continue({ url: `${MOCK}${u.pathname}${u.search}` })
})
await page.route('**/sanctum/**', (route) => route.fulfill({ status: 204, headers: { 'set-cookie': 'XSRF-TOKEN=t; Path=/' }, body: '' }))
await page.goto(B + '/products/red-velvet', { waitUntil: 'networkidle' })
await page.waitForTimeout(800)
const hydrationWarn = consoleMsgs.filter((m) => /hydrat|mismatch/i.test(m))
check('no hydration mismatch warnings (product page)', hydrationWarn.length === 0, hydrationWarn.slice(0, 2).join(' | '))
check('no uncaught page errors', pageErrors.length === 0, pageErrors.slice(0, 2).join(' | '))
check('product content visible', (await page.locator('body').innerText()).includes('Red Velvet'))
check('stored locale applied after mount (html lang=en)', (await page.evaluate(() => document.documentElement.lang)) === 'en')
check('stored theme applied (html.dark)', await page.evaluate(() => document.documentElement.classList.contains('dark')))
check('install status + auth/me requested from browser after mount', apiCalls.some((c) => c.endsWith('/install/status')) && apiCalls.some((c) => c.endsWith('/auth/me')), apiCalls.join(','))
check('no product refetch for guest (seeded from SSR payload)', apiCalls.filter((c) => c.includes('/products/red-velvet')).length === 0, apiCalls.filter((c) => c.includes('/products')).join(','))
const cartLs = await page.evaluate(() => localStorage.getItem('pc-cart'))
check('cart in localStorage preserved (not overwritten by SSR empty state)', cartLs && cartLs.includes('Red Velvet'))
// client-side navigation between Nuxt-owned routes stays in-app
await page.evaluate(() => { window.__marker = 'alive' })
const before = docRequests.length
await page.goto(B + '/', { waitUntil: 'networkidle' })
// SPA hand-off: clicking a link to an SPA-owned route does a FULL document navigation
await page.goto(B + '/products', { waitUntil: 'networkidle' })
await page.evaluate(() => { window.__marker = 'alive' })
const cardLink = page.locator('a[href^="/products/"]').first()
if (await cardLink.count()) {
  await cardLink.click()
  await page.waitForURL('**/products/**')
  check('client-side nav between owned routes (no document reload)', (await page.evaluate(() => window.__marker)) === 'alive')
} else check('product card link present on listing', false)
await page.evaluate(() => { window.__marker = 'alive' })
const reqBefore = docRequests.length
const spaLink = page.locator('a[href="/cart"], a[href="/login"], a[href="/wishlist"]').first()
if (await spaLink.count()) {
  const href = await spaLink.getAttribute('href')
  await spaLink.click()
  await page.waitForTimeout(1200)
  const handedOver = docRequests.slice(reqBefore).some((u) => u.endsWith(href))
  check(`link to SPA-owned ${href} triggers a full-page navigation (hand-off)`, handedOver, docRequests.slice(reqBefore).join(','))
} else check('SPA-owned link present in header', false)
check('no hydration warnings overall', consoleMsgs.filter((m) => /hydrat|mismatch/i.test(m)).length === 0)
console.log(out.join('\n'))
console.log(`\n${out.filter((l) => l.startsWith('PASS')).length} passed, ${out.filter((l) => l.startsWith('FAIL')).length} failed`)
console.log('--- console (non-info), first 12:'); console.log(consoleMsgs.filter((m) => !m.startsWith('log') && !m.startsWith('info')).slice(0, 40).join('\n'))
await browser.close()
