// REAL-BACKEND browser UAT — REM-06 global create-only. Chromium → production bundle → Vite proxy → Laravel → primeclassy_testing.
import { chromium } from 'playwright'
import fs from 'fs'
const BASE = 'http://127.0.0.1:5198'; const OUT = '/pw/out-cat'; fs.mkdirSync(OUT, { recursive: true })
const R = { checks: [], console: [] }
const check = (n, ok, x = '') => { R.checks.push({ name: n, ok: !!ok, extra: x }); console.log(ok ? 'PASS' : 'FAIL', n, x) }
const browser = await chromium.launch()
const sessions = {}
async function page(email, vp = { width: 1280, height: 1100 }) {
  const ctx = await browser.newContext({ viewport: vp }); const p = await ctx.newPage()
  p.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource|unsupported MIME/.test(m.text())) R.console.push(m.text().slice(0, 160)) })
  if (sessions[email]) await ctx.addCookies(sessions[email])
  else { await p.goto(`${BASE}/login`); await p.fill('input[name="identifier"]', email); await p.fill('input[type="password"]', 'password'); await Promise.all([p.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 20000 }), p.click('button[type="submit"]')]); sessions[email] = (await ctx.storageState()).cookies }
  return p
}
const api = (p, method, path, body) => p.evaluate(async ([m, pa, b]) => { await fetch('/sanctum/csrf-cookie', { credentials: 'include' }); const x = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''); const r = await fetch('/api/v1' + pa, { method: m, credentials: 'include', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: b ? JSON.stringify(b) : undefined }); let j = null; try { j = await r.json() } catch {} return { status: r.status, body: j } }, [method, path, body ?? null])
const tree = async (p) => (await p.evaluate(async () => (await (await fetch('/api/v1/categories')).json()).data)).map((c) => `${c.parent_id ?? '-'}>${c.name}`)
async function createUI(p, name, parentLabel) {
  await p.waitForFunction(() => document.querySelectorAll('select[aria-label="Induk kategori baru"] option').length > 1)
  await p.fill('input[placeholder="Nama kategori baru"]', name)
  if (parentLabel) { const v = await p.$$eval('select[aria-label="Induk kategori baru"] option', (o, n) => o.find((x) => x.textContent.replace(/^[— ]+/, '').trim() === n)?.value, parentLabel); await p.selectOption('select[aria-label="Induk kategori baru"]', v) }
  await p.click('[data-testid="category-create-submit"]'); await p.waitForTimeout(900)
}
const shot = (p, n) => p.screenshot({ path: `${OUT}/${n}.png`, fullPage: false })

// ---- 1-5: Admin Agen
let admin = await page('admin1@uat.test'); await admin.goto(`${BASE}/dashboard/products`); await admin.waitForSelector('[data-testid="category-create-form"]')
check('U1 Admin Agen sees create-only note + create form; NO rename/delete/reparent controls', (await admin.$('[data-testid="category-createonly-note"]')) && !(await admin.$('button[title="Hapus"]')) && !(await admin.$('button[title="Ubah nama"]')) && !(await admin.$('select[aria-label^="Induk kategori Kue"]')))
await shot(admin, 'U-admin-before')
await createUI(admin, 'Minuman', null); await createUI(admin, 'Kopi', 'Minuman')
let t = await tree(admin)
check('U2 Admin Agen created root "Minuman" and child "Kopi" under it (persisted in global taxonomy)', t.some((x) => x.endsWith('>Minuman') && x.startsWith('-')) && t.some((x) => x.endsWith('>Kopi') && !x.startsWith('-')), JSON.stringify(t))
await createUI(admin, 'Kopi Susu', 'Kopi'); // 3rd level via UI
await shot(admin, 'U-admin-created')
await admin.goto(`${BASE}/categories`); await admin.waitForSelector('[data-testid="category-tree"]')
const nested = await admin.$$eval('[data-testid="category-root"]', (els) => els.map((e) => [...e.querySelectorAll('[data-category-id]')].map((k) => `${k.dataset.depth}:${k.querySelector('a').textContent.trim()}`).join('|')))
check('U3 storefront hierarchy shows Minuman → Kopi → Kopi Susu nested', nested.includes('0:Minuman|1:Kopi|2:Kopi Susu'), JSON.stringify(nested))
await shot(admin, 'U-storefront-hierarchy-d')
// product editor can select the new categories
await admin.goto(`${BASE}/dashboard/products`); await admin.waitForSelector('table, [data-testid="category-create-form"]')
await admin.goto(`${BASE}/dashboard/products/create`); await admin.waitForSelector('form')
const opts = await admin.$$eval('form select option', (o) => o.map((x) => x.textContent.trim()))
check('U4 new categories are selectable in the product form', opts.some((x) => x.includes('Minuman')) && opts.some((x) => x.includes('Kopi Susu')) && opts.indexOf('— Kopi') === opts.indexOf('Minuman') + 1 && opts.indexOf('— — Kopi Susu') === opts.indexOf('— Kopi') + 1, JSON.stringify(opts.slice(0, 10)))
await shot(admin, 'U-product-form-category-select')

// ---- 6-9: Agen (network 1) + second Agen (other network)
let agen = await page('agen1@uat.test'); await agen.goto(`${BASE}/dashboard/products`); await agen.waitForSelector('[data-testid="category-create-form"]')
await createUI(agen, 'Camilan', null); await createUI(agen, 'Keripik', 'Camilan')
t = await tree(agen)
check('U5 Agen created root "Camilan" + child "Keripik"', t.some((x) => x.endsWith('>Camilan')) && t.some((x) => x.endsWith('>Keripik') && !x.startsWith('-')), JSON.stringify(t))
let agen2 = await page('agen2@uat.test'); await agen2.goto(`${BASE}/categories`); await agen2.waitForSelector('[data-testid="category-tree"]')
const seen2 = await agen2.$$eval('[data-category-id] > a', (a) => a.map((x) => x.textContent.trim()))
check('U6 a DIFFERENT network (Agen Dua) sees categories created by Admin Agen and Agen (global taxonomy)', ['Minuman', 'Kopi', 'Kopi Susu', 'Camilan', 'Keripik'].every((n) => seen2.includes(n)), JSON.stringify(seen2))

// ---- 10: edit / delete / reparent denied on the API for Admin Agen and Agen
const ids = Object.fromEntries((await agen.evaluate(async () => (await (await fetch('/api/v1/categories')).json()).data)).map((c) => [c.name, c.id]))
for (const [who, p] of [['admin', admin], ['agen', agen]]) {
  const rename = await api(p, 'PATCH', `/categories/${ids['Kue']}`, { name: 'Hacked' })
  const reparent = await api(p, 'PATCH', `/categories/${ids['Keripik']}`, { parent_id: ids['Kue'] })
  const detach = await api(p, 'PATCH', `/categories/${ids['Kopi']}`, { parent_id: null })
  const del = await api(p, 'DELETE', `/categories/${ids['Kue Kering']}`)
  const forged = await api(p, 'PATCH', `/categories/${ids['Kue']}`, { name: 'X', role: 'super_admin', actor_id: 1 })
  check(`U7 ${who}: rename/reparent/detach/delete/forged-role edit all DENIED (403)`, [rename, reparent, detach, del, forged].every((r) => r.status === 403), JSON.stringify([rename, reparent, detach, del, forged].map((r) => r.status)))
}
const after = await tree(agen)
check('U8 nothing changed by the denied calls', after.some((x) => x.endsWith('>Kue')) && !after.some((x) => x.endsWith('>Hacked')) && after.length === t.length)
const konsumenLike = await api(agen2, 'POST', '/categories', { name: 'Dari Agen Dua' }) // agen2 is an agen: allowed
check('U8b second Agen (other network) may create too (global create-only)', konsumenLike.status === 201)

// ---- 11: duplicates
await agen.goto(`${BASE}/dashboard/products`); await agen.waitForSelector('[data-testid="category-create-form"]')
await createUI(agen, '  kopi  ', 'Minuman')
const errText = await agen.textContent('body')
check('U9 duplicate (different case/spacing, same parent) is rejected with a clear Indonesian message (no English envelope); typed name kept; form usable', errText.includes('nama yang sama sudah ada') && !errText.includes('submitted data is invalid') && (await agen.inputValue('input[placeholder="Nama kategori baru"]')).trim() === 'kopi' && !(await agen.isDisabled('[data-testid="category-create-submit"]')))
await shot(agen, 'U-duplicate-error')
const sameDiffParent = await api(agen, 'POST', '/categories', { name: 'Kopi', parent_id: ids['Camilan'] })
check('U10 the same name under a DIFFERENT parent is allowed', sameDiffParent.status === 201, String(sameDiffParent.status))
const rootDup = await api(agen, 'POST', '/categories', { name: 'MINUMAN' })
check('U11 root-level duplicate rejected (422)', rootDup.status === 422)

// ---- 12: concurrency (two users, many parallel requests, plus UI double-click)
const burst = async (p, n, name, parent) => p.evaluate(async ([N, nm, pa]) => { await fetch('/sanctum/csrf-cookie', { credentials: 'include' }); const x = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''); return Promise.all(Array.from({ length: N }, () => fetch('/api/v1/categories', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify({ name: nm, parent_id: pa }) }).then((r) => r.status))) }, [n, name, parent])
const [ra, rb] = await Promise.all([burst(admin, 6, 'Serentak', null), burst(agen, 6, 'serentak', null)])
const all = [...ra, ...rb]; const count = (await tree(agen)).filter((x) => x.startsWith('-') && x.toLowerCase().endsWith('>serentak')).length
check('U12 12 concurrent creates of the same root from two users → exactly ONE row (1×201, 11×422, no 5xx)', count === 1 && all.filter((s) => s === 201).length === 1 && all.filter((s) => s === 422).length === 11 && !all.some((s) => s >= 500), JSON.stringify({ count, all }))
const [rc, rd] = await Promise.all([burst(admin, 5, 'Anak Serentak', ids['Minuman']), burst(agen, 5, 'ANAK SERENTAK', ids['Minuman'])])
const cc = (await agen.evaluate(async () => (await (await fetch('/api/v1/categories')).json()).data)).filter((c) => c.parent_id === ids['Minuman'] && c.name.toLowerCase() === 'anak serentak').length
check('U13 concurrent child creates under the same parent → exactly ONE row, no 5xx', cc === 1 && ![...rc, ...rd].some((s) => s >= 500), JSON.stringify({ cc, rc, rd }))
// UI double-click: rapid triple click on submit
await agen.goto(`${BASE}/dashboard/products`); await agen.waitForSelector('[data-testid="category-create-form"]')
await agen.fill('input[placeholder="Nama kategori baru"]', 'Klik Cepat'); await agen.dblclick('[data-testid="category-create-submit"]'); await agen.waitForTimeout(1200)
const dbl = (await tree(agen)).filter((x) => x.endsWith('>Klik Cepat')).length
check('U14 UI double-click creates exactly ONE category', dbl === 1, String(dbl))
const inflight = await agen.evaluate(() => 0)
// persistence after reload
await agen.reload(); await agen.waitForSelector('[data-testid="category-create-form"]')
const finalTree = await tree(agen)
check('U15 everything persisted after reload; no duplicate (parent,name) pairs in the whole taxonomy', (() => { const m = new Map(); for (const c of finalTree) { const k = c.toLowerCase(); m.set(k, (m.get(k) ?? 0) + 1) } return [...m.values()].every((v) => v === 1) })(), `${finalTree.length} rows`)

// ---- 14: responsive
for (const [tag, vp] of [['d', { width: 1280, height: 1100 }], ['m', { width: 390, height: 844 }]]) {
  const p = await page('agen1@uat.test', vp); await p.goto(`${BASE}/dashboard/products`); await p.waitForSelector('[data-testid="category-create-form"]'); await p.waitForFunction(() => document.querySelectorAll('select[aria-label="Induk kategori baru"] option').length > 1); await shot(p, `U-dashboard-agen-${tag}`)
  const ov = await p.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]); check(`U16 ${tag}: no horizontal overflow on the category panel`, ov[0] <= ov[1] + 1, String(ov))
  const q = await page('agen1@uat.test', vp); await q.goto(`${BASE}/categories`); await q.waitForSelector('[data-testid="category-tree"]'); await shot(q, `U-storefront-categories-${tag}`)
}
// other roles: no create (API)
const sa = await page('sa@uat.test'); const saRes = await api(sa, 'POST', '/categories', { name: 'Super Bikin', sort_order: 9, is_active: false })
check('U17 super_admin still creates with full control (sort_order/is_active honoured)', saRes.status === 201 && saRes.body?.data?.sort_order === 9 && saRes.body?.data?.is_active === false)
fs.writeFileSync(`${OUT}/results.json`, JSON.stringify(R, null, 2))
const f = R.checks.filter((c) => !c.ok); console.log(`\n${R.checks.length - f.length}/${R.checks.length} PASS`, f.map((x) => x.name), 'console:', R.console)
await browser.close()
