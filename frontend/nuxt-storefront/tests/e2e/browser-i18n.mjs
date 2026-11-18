import { chromium } from 'playwright-core'
const B = process.env.NUXT_URL || 'http://localhost:3000', MOCK = process.env.MOCK_URL || 'http://localhost:8000'
const out = []; const check = (n, ok, d = '') => out.push(`${ok ? 'PASS' : 'FAIL'}  ${n}${d ? ' — ' + d : ''}`)
const browser = await chromium.launch()
async function open(path, { cookies = [], init } = {}) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } })
  if (cookies.length) await ctx.addCookies(cookies.map((c) => ({ ...c, url: B })))
  if (init) await ctx.addInitScript(init)
  const page = await ctx.newPage(); const msgs = [], errs = [], api = []
  page.on('console', (m) => msgs.push(`${m.type()}: ${m.text()}`)); page.on('pageerror', (e) => errs.push(String(e)))
  await page.route('**/api/v1/**', async (route) => { const u = new URL(route.request().url()); api.push(`${route.request().method()} ${u.pathname}${u.search}`); await route.continue({ url: `${MOCK}${u.pathname}${u.search}` }) })
  await page.route('**/sanctum/**', (r) => r.fulfill({ status: 204, body: '' }))
  await page.goto(B + path, { waitUntil: 'networkidle' }); await page.waitForTimeout(700)
  return { ctx, page, msgs, errs, api, hyd: () => msgs.filter((m) => /hydrat|mismatch/i.test(m)) }
}
// 1. hydration clean in every language, on every kind of page
for (const lang of ['id', 'en', 'zh', 'ar']) {
  for (const p of ['/', '/products?page=2', '/articles/tips', '/toko-kami']) {
    const s = await open(`${p}${p.includes('?') ? '&' : '?'}lang=${lang}`)
    const htmlLang = await s.page.evaluate(() => [document.documentElement.lang, document.documentElement.dir])
    check(`hydrates cleanly: ${p} lang=${lang}`, s.hyd().length === 0 && s.errs.length === 0, s.hyd()[0]?.slice(0, 160) ?? s.errs[0]?.slice(0, 120))
    if (p === '/') check(`  html lang/dir after hydration (${lang})`, htmlLang[0] === (['id', 'en', 'zh'].includes(lang) ? lang : 'id') && (lang !== 'ar' || htmlLang[1] === 'ltr'), htmlLang.join('/'))
    await s.ctx.close()
  }
}
// ar is not an ACTIVE backend language in the mock → must fall back to id (not advertised)
// 2. explicit ?lang is persisted; the cookie then carries it to later (cookie-only) visits
let s = await open('/articles/tips?lang=en')
const stored = await s.page.evaluate(() => [localStorage.getItem('pc-locale'), document.cookie])
check('?lang=en persisted to localStorage and mirrored in the pc-locale cookie', stored[0] === 'en' && /pc-locale=en/.test(stored[1]), JSON.stringify(stored))
await s.page.goto(B + '/articles/tips', { waitUntil: 'networkidle' }); await s.page.waitForTimeout(500)
check('next visit without ?lang: server renders EN from the cookie (no flash back to id)', (await s.page.evaluate(() => document.documentElement.lang)) === 'en' && s.hyd().length === 0)
// 3. language switcher: UI language, URL, <head> canonical/title follow reactively, no reload
await s.page.evaluate(() => { window.__alive = 1 })
await s.page.goto(B + '/articles/tips', { waitUntil: 'networkidle' }); await s.page.evaluate(() => { window.__alive = 1 })
const head = () => s.page.evaluate(() => ({ lang: document.documentElement.lang, title: document.title, canonical: document.querySelector('link[rel=canonical]')?.href, url: location.pathname + location.search, alt: document.querySelectorAll('link[rel=alternate][hreflang]').length }))
const before = await head()
const sw = s.page.locator('button[aria-label], button').filter({ hasText: /Bahasa|English|Language|语言/ }).first()
let switched = false
for (const label of ['Language', 'Bahasa', '语言', 'اللغة']) {
  const b = s.page.getByRole('button', { name: new RegExp(label, 'i') }).first()
  if (await b.count()) { await b.click(); switched = true; break }
}
check('language switcher found in the header', switched)
if (switched) {
  await s.page.getByText('Bahasa Indonesia', { exact: true }).first().click()
  await s.page.waitForTimeout(600)
  const afterId = await head()
  check('switch → id: lang, URL without ?lang, canonical default, title = id translation', afterId.lang === 'id' && !afterId.url.includes('lang=') && /Tips Kue/.test(afterId.title) && afterId.canonical.endsWith('/articles/tips'), JSON.stringify(afterId))
  const b = s.page.getByRole('button', { name: /Bahasa|Language/i }).first(); await b.click()
  await s.page.getByText('English', { exact: true }).first().click(); await s.page.waitForTimeout(600)
  const afterEn = await head()
  check('switch → en: lang=en, URL gets ?lang=en, canonical self, title = en translation, hreflang cluster kept', afterEn.lang === 'en' && afterEn.url.endsWith('?lang=en') && /Cake Tips/.test(afterEn.title) && afterEn.canonical.endsWith('/articles/tips?lang=en') && afterEn.alt === 3, JSON.stringify(afterEn))
  check('switching languages is in-app (no document reload)', (await s.page.evaluate(() => window.__alive)) === 1)
  check('no hydration/console errors while switching', s.hyd().length === 0 && s.errs.length === 0)
}
await s.ctx.close()
// 4. pagination in the browser: links navigate in-app, URL keeps page, back button works
s = await open('/products?page=2')
await s.page.evaluate(() => { window.__alive = 1 })
const next = s.page.locator('a[rel=next]').first()
check('listing page 2: next/prev are real anchors', (await next.count()) === 1 && (await s.page.locator('a[rel=prev]').count()) === 1)
await next.click(); await s.page.waitForURL('**/products?page=3'); await s.page.waitForTimeout(600)
check('click next → URL ?page=3, in-app navigation, page-3 products shown', (await s.page.evaluate(() => window.__alive)) === 1 && (await s.page.locator('body').innerText()).includes('Produk 25'))
// (the listing has always used router.replace for paging — kept as is — so history entries are not added per page)
await s.ctx.close()
s = await open('/articles?page=2')
check('articles ?page=2 hydrates, anchors present', s.hyd().length === 0 && (await s.page.locator('a[rel=prev]').count()) === 1)
await s.page.locator('a[rel=next]').click(); await s.page.waitForURL('**/articles?page=3'); await s.page.waitForTimeout(500)
check('articles next → ?page=3 in the URL; back returns to page 2', /page=3/.test(s.page.url()) && (await (async () => { await s.page.goBack(); await s.page.waitForTimeout(500); return /page=2/.test(s.page.url()) })()))
await s.ctx.close()
// 5. store locator: SSR is sanitized, the client restores the full directory (phone + referral) right after hydration
s = await open('/toko-kami')
const text = await s.page.locator('body').innerText()
check('store locator after hydration shows phone + referral code (existing behaviour preserved)', /081234567890/.test(text) && /AG-MAWAR9/.test(text), '')
check('store locator hydrates cleanly with the sanitized SSR subset', s.hyd().length === 0 && s.errs.length === 0)
check('client loaded the full directory from the API', s.api.some((c) => c.endsWith('/api/v1/agents')))
await s.ctx.close()
console.log(out.join('\n')); console.log(`\n${out.filter((l) => l.startsWith('PASS')).length} passed, ${out.filter((l) => l.startsWith('FAIL')).length} failed`)
await browser.close()
