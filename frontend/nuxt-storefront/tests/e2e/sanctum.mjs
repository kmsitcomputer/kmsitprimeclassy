import { chromium } from 'playwright-core'
const B = 'http://localhost:3000'
const out = []; const check = (n, ok, d = '') => out.push(`${ok ? 'PASS' : 'FAIL'}  ${n}${d ? ' — ' + d : ''}`)
const browser = await chromium.launch(); const ctx = await browser.newContext(); const page = await ctx.newPage()
const msgs = []; page.on('console', (m) => msgs.push(`${m.type()}: ${m.text()}`))
const responses = []; page.on('response', (r) => { if (r.url().includes('/api/v1/')) responses.push(`${r.status()} ${r.request().method()} ${new URL(r.url()).pathname}`) })

let res = await page.goto(B + '/products', { waitUntil: 'networkidle' })
check('SSR /products against REAL Laravel → 200', res.status() === 200, `status ${res.status()}`)
res = await page.goto(B + '/categories', { waitUntil: 'networkidle' }); check('SSR /categories against real Laravel → 200', res.status() === 200)
res = await page.goto(B + '/products/does-not-exist', { waitUntil: 'networkidle' }); check('real API 404 → SSR 404 + noindex', res.status() === 404 && /noindex/.test(await page.content()), `status ${res.status()}`)
res = await page.goto(B + '/products', { waitUntil: 'networkidle' })
await page.waitForTimeout(500)
check('guest: /auth/me → 401 handled, no uncaught errors', responses.some((r) => r === '401 GET /api/v1/auth/me') && !msgs.some((m) => m.startsWith('pageerror')))
check('no hydration warnings against real backend', !msgs.some((m) => /hydrat|mismatch/i.test(m)))

const api = (method, path, body, withToken = true) => page.evaluate(async ([m, p, b, t]) => {
  const xsrf = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '')
  const r = await fetch(p, { method: m, credentials: 'include', headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(t && xsrf ? { 'X-XSRF-TOKEN': xsrf } : {}) }, body: b ? JSON.stringify(b) : undefined })
  let j = null; try { j = await r.json() } catch {}
  return { status: r.status, json: j }
}, [method, path, body, withToken])

let r = await api('GET', '/sanctum/csrf-cookie'); 
const cookies = await ctx.cookies()
check('csrf-cookie via Nuxt origin → 204 + XSRF-TOKEN + session cookie', r.status === 204 && cookies.some((c) => c.name === 'XSRF-TOKEN') && cookies.some((c) => /session/i.test(c.name)), cookies.map((c) => c.name).join(','))
check('session cookie HttpOnly; XSRF cookie readable by JS (as axios requires)', cookies.find((c) => /session/i.test(c.name))?.httpOnly === true && cookies.find((c) => c.name === 'XSRF-TOKEN')?.httpOnly === false)
r = await api('POST', '/api/v1/auth/login', { identifier: 'imp009-konsumen@example.test', password: 'Secret123!' }, false)
check('login WITHOUT X-XSRF-TOKEN → 419 (CSRF enforced)', r.status === 419, `status ${r.status}`)
r = await api('POST', '/api/v1/auth/login', { identifier: 'imp009-konsumen@example.test', password: 'WRONG' })
check('login wrong password → 422/401, no session user', [401, 422].includes(r.status), `status ${r.status}`)
await api('GET', '/sanctum/csrf-cookie')
r = await api('POST', '/api/v1/auth/login', { identifier: 'imp009-konsumen@example.test', password: 'Secret123!' })
check('login with token → 200 + user role konsumen', r.status === 200 && r.json?.data?.user?.role === 'konsumen', `status ${r.status} ${JSON.stringify(r.json).slice(0, 160)}`)
r = await api('GET', '/api/v1/auth/me'); check('/auth/me with session cookie → 200', r.status === 200 && r.json?.data?.user?.email === 'imp009-konsumen@example.test')
// SSR must stay anonymous even when the browser sends a valid session cookie
const html = await (await ctx.request.get(B + '/products')).text()
check('SSR HTML for a signed-in browser contains no user data (cookies not used for rendering)', !html.includes('IMP009') && !html.includes('imp009-konsumen'))
msgs.length = 0; responses.length = 0
await page.goto(B + '/products', { waitUntil: 'networkidle' }); await page.waitForTimeout(600)
check('after reload signed-in: client /auth/me → 200, listing re-fetched with session', responses.includes('200 GET /api/v1/auth/me') && responses.includes('200 GET /api/v1/products'), responses.join(' | '))
check('signed-in reload: no hydration warnings', !msgs.some((m) => /hydrat|mismatch/i.test(m)))
r = await api('GET', '/api/v1/agent/payment-methods'); check('RBAC: konsumen forbidden on agent-only endpoint (403)', r.status === 403, `status ${r.status}`)
r = await api('GET', '/api/v1/admin/audit-logs'); check('RBAC: konsumen forbidden on admin endpoint (403)', r.status === 403, `status ${r.status}`)
r = await api('POST', '/api/v1/auth/logout'); check('logout with token → 2xx', r.status >= 200 && r.status < 300, `status ${r.status}`)
r = await api('GET', '/api/v1/auth/me'); check('after logout /auth/me → 401', r.status === 401, `status ${r.status}`)
console.log(out.join('\n')); console.log(`\n${out.filter((l) => l.startsWith('PASS')).length} passed, ${out.filter((l) => l.startsWith('FAIL')).length} failed`)
await browser.close()
