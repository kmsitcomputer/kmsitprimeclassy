// REAL-BACKEND browser UAT (Playwright/Chromium → production bundle → Vite proxy → Laravel → primeclassy_testing DB).
// No API interception except serving /storage files from the nginx container (static image bytes).
import { chromium } from 'playwright'
import { PNG } from 'pngjs'
import fs from 'fs'

const BASE = 'http://127.0.0.1:5198'
const OUT = '/pw/out-uat'; fs.mkdirSync(OUT, { recursive: true }); fs.mkdirSync('/pw/fx', { recursive: true })
const R = { checks: [], console: [], notes: [] }
const check = (name, ok, extra = '') => { R.checks.push({ name, ok: !!ok, extra }); console.log(ok ? 'PASS' : 'FAIL', name, extra) }
const png = (file, rgb) => { const p = new PNG({ width: 400, height: 400 }); for (let i = 0; i < 400 * 400; i++) { p.data[i * 4] = rgb[0]; p.data[i * 4 + 1] = rgb[1]; p.data[i * 4 + 2] = rgb[2]; p.data[i * 4 + 3] = 255 } fs.writeFileSync(file, PNG.sync.write(p)); return file }
const fx = { a: png('/pw/fx/new-a.png', [255, 200, 0]), b: png('/pw/fx/new-b.png', [0, 200, 200]), c: png('/pw/fx/new-c.png', [200, 0, 200]), d: png('/pw/fx/new-d.png', [10, 10, 10]) }

const browser = await chromium.launch()
async function newPage(viewport, label) {
  const ctx = await browser.newContext({ viewport }); const page = await ctx.newPage()
  await page.route('**/storage/**', async (route) => { const u = new URL(route.request().url()); const r = await route.fetch({ url: `http://primeclassy-web${u.pathname}` }); await route.fulfill({ response: r }) })
  page.on('console', (m) => { if (['error'].includes(m.type()) && !/Failed to load resource/.test(m.text())) R.console.push(`[${label}] ${m.text().slice(0, 160)}`) })
  page.on('pageerror', (e) => R.console.push(`[${label}] pageerror ${String(e).slice(0, 160)}`))
  return page
}
const sessions = {}
async function login(page, email) {
  // One real UI login per account (login is rate limited); later pages reuse the authenticated session cookies.
  if (sessions[email]) { await page.context().addCookies(sessions[email]); return }
  await page.goto(`${BASE}/login`); await page.fill('input[name="identifier"]', email); await page.fill('input[type="password"]', 'password')
  await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 20000 }), page.click('button[type="submit"]')])
  sessions[email] = (await page.context().storageState()).cookies
}
const api = (page, path) => page.evaluate(async (p) => (await (await fetch(`/api/v1${p}`, { credentials: 'include', headers: { Accept: 'application/json' } })).json()).data, path)
const shot = async (page, name) => { await page.waitForTimeout(400); await page.screenshot({ path: `${OUT}/${name}.png` }); const o = await page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]); if (o[0] > o[1] + 1) check(`no horizontal overflow: ${name}`, false, `${o}`) }
const galleryIds = async (page, key) => (await page.$$eval(`[data-testid="gallery-${key}"] [data-testid="gallery-item"]`, (els) => els.map((e) => Number(e.getAttribute('data-image-id')))))
const sel = async (page) => page.$$eval('button[aria-pressed="true"]', (b) => b.map((x) => x.textContent.trim()))
const mainSrc = (page) => page.$eval('img[alt="Kue Warna-Warni"]', (i) => i.getAttribute('src')).catch(() => null)

// ===================== UAT-A: product editor (agen) =====================
{
  const page = await newPage({ width: 1280, height: 1400 }, 'A'); await login(page, 'agen@uat.test')
  await page.goto(`${BASE}/dashboard/products/1/edit`); await page.waitForSelector('[data-testid="gallery-1"]')
  const before = { 1: await galleryIds(page, 1), 2: await galleryIds(page, 2), 3: await galleryIds(page, 3), parent: await galleryIds(page, 'parent') }
  check('A1 editor shows one gallery per variation + parent with seeded images', before[1].length === 2 && before[2].length === 2 && before[3].length === 2 && before.parent.length === 1, JSON.stringify(before))
  await shot(page, 'A-editor-galleries-before')
  // upload 2 images to variation Biru (id 2) only
  await page.setInputFiles('[data-testid="gallery-2"] [data-testid="gallery-input"]', [fx.a, fx.b]); await page.waitForFunction(() => document.querySelectorAll('[data-testid="gallery-2"] [data-testid="gallery-item"]').length === 4)
  const afterUp = { 1: await galleryIds(page, 1), 2: await galleryIds(page, 2), 3: await galleryIds(page, 3), parent: await galleryIds(page, 'parent') }
  check('A2 upload to Biru adds 2 images to Biru only; Merah/Hijau/parent untouched', afterUp[2].length === 4 && JSON.stringify(afterUp[1]) === JSON.stringify(before[1]) && JSON.stringify(afterUp[3]) === JSON.stringify(before[3]) && JSON.stringify(afterUp.parent) === JSON.stringify(before.parent))
  // reorder Merah: move first right
  await page.click('[data-testid="gallery-1"] [data-testid="gallery-item"]:nth-child(1) [data-testid="gallery-right"]'); await page.waitForTimeout(700)
  const merahOrder = await galleryIds(page, 1)
  check('A3 reorder (Merah) swaps its two images', merahOrder[0] === before[1][1] && merahOrder[1] === before[1][0], JSON.stringify(merahOrder))
  // primary: make Biru 2nd image primary
  const biruIds = await galleryIds(page, 2)
  await page.click(`[data-testid="gallery-2"] [data-image-id="${biruIds[1]}"] [data-testid="gallery-make-primary"]`); await page.waitForTimeout(700)
  // remove one Biru image (the 4th)
  await page.click(`[data-testid="gallery-2"] [data-image-id="${biruIds[3]}"] [data-testid="gallery-remove"]`); await page.waitForTimeout(700)
  await shot(page, 'A-editor-galleries-after')
  // reload + persisted state via UI and via API
  await page.reload(); await page.waitForSelector('[data-testid="gallery-2"]')
  const persisted = { 1: await galleryIds(page, 1), 2: await galleryIds(page, 2), 3: await galleryIds(page, 3), parent: await galleryIds(page, 'parent') }
  check('A4 persisted after reload: Merah reordered, Biru has 3 (one removed), Hijau+parent unchanged', JSON.stringify(persisted[1]) === JSON.stringify(merahOrder) && persisted[2].length === 3 && !persisted[2].includes(biruIds[3]) && JSON.stringify(persisted[3]) === JSON.stringify(before[3]) && JSON.stringify(persisted.parent) === JSON.stringify(before.parent), JSON.stringify(persisted))
  const primaryBiru = await page.$eval(`[data-testid="gallery-2"] [data-testid="gallery-item"]:has([data-testid="gallery-primary"])`, (e) => Number(e.getAttribute('data-image-id')))
  check('A5 the chosen Biru image is the (single) primary, other galleries keep their own primary', primaryBiru === biruIds[1] && (await page.$$('[data-testid="gallery-1"] [data-testid="gallery-primary"]')).length === 1 && (await page.$$('[data-testid="gallery-3"] [data-testid="gallery-primary"]')).length === 1)
  const apiImgs = (await api(page, '/products/kue-warna-warni')).images
  const byVar = (v) => apiImgs.filter((i) => i.product_variation_id === v).map((i) => i.id)
  check('A6 storefront API returns exactly the saved galleries in saved order', JSON.stringify(byVar(1)) === JSON.stringify(persisted[1]) && byVar(2).length === 3 && JSON.stringify(byVar(3)) === JSON.stringify(persisted[3]))
  // REM-03: change only the title, save; images must be byte-identical
  const snapBefore = JSON.stringify((await api(page, '/products/kue-warna-warni')).images.map((i) => [i.id, i.url, i.is_primary, i.sort_order, i.product_variation_id]))
  await page.fill('input[type="text"] >> nth=0', 'Kue Warna-Warni'); // unchanged value, then change description-agnostic title below
  const nameInput = page.locator('form input[type="text"]').first(); const orig = await nameInput.inputValue(); await nameInput.fill(orig + ' X'); await page.click('button[type="submit"]'); await page.waitForTimeout(1500)
  const slug = (await api(page, '/manage/products/1')).slug
  const snapAfter = JSON.stringify((await api(page, `/products/${slug}`)).images.map((i) => [i.id, i.url, i.is_primary, i.sort_order, i.product_variation_id]))
  check('A7 saving the product (title changed) leaves every image identical (ids, files, primary, order, owner)', snapBefore === snapAfter)
  // restore title
  await page.goto(`${BASE}/dashboard/products/1/edit`); await page.waitForSelector('form input[type="text"]'); const ni = page.locator('form input[type="text"]').first(); await ni.fill((await ni.inputValue()).replace(/ X$/, '')); await page.click('button[type="submit"]'); await page.waitForTimeout(1200)
  // mobile editor
  const m = await newPage({ width: 390, height: 844 }, 'A-m'); await login(m, 'agen@uat.test'); await m.goto(`${BASE}/dashboard/products/1/edit`); await m.waitForSelector('[data-testid="gallery-1"]'); await shot(m, 'A-editor-mobile')
}

// ===================== UAT-A2: create product with two variants + images =====================
{
  const page = await newPage({ width: 1280, height: 1600 }, 'A2'); await login(page, 'agen@uat.test')
  await page.goto(`${BASE}/dashboard/products/create`); await page.waitForSelector('form')
  await page.locator('form input[type="text"]').first().fill('Brownies Uji Dua Varian')
  async function addVariant(sku, attrVal, files) {
    if (!(await page.$('#new-variant-images'))) await page.click('text=+ Tambah Varian'); await page.waitForSelector('#new-variant-images')
    const box = page.locator('div.rounded-xl.bg-stone-50')
    await box.locator('input[type="text"]').first().fill(sku)
    await box.locator('input[type="number"]').first().fill('30000'); await box.locator('input[type="number"]').nth(1).fill('250')
    await box.locator('input[placeholder="Nama atribut"]').first().fill('Rasa'); await box.locator('input[placeholder="Nilai"]').first().fill(attrVal)
    await page.setInputFiles('#new-variant-images', files)
    await page.click('text=Tambahkan ke Daftar')
  }
  await addVariant('BR-COK', 'Coklat', [fx.a, fx.b]); await addVariant('BR-KEJ', 'Keju', [fx.c])
  await shot(page, 'A2-create-two-variants')
  await page.click('button[type="submit"]'); await page.waitForURL(/\/dashboard\/products$/, { timeout: 20000 }).catch(() => {})
  const list = await api(page, '/manage/products?per_page=50'); const prod = (list?.products ?? list)?.find?.((p) => p.name === 'Brownies Uji Dua Varian')
  const detail = prod ? await api(page, `/manage/products/${prod.id}`) : null
  const gal = detail ? Object.fromEntries(detail.variations.map((v) => [v.sku, detail.images.filter((i) => i.product_variation_id === v.id).length])) : {}
  check('A8 create flow: variant images land in THEIR OWN variation (Coklat=2, Keju=1, parent=0)', gal['BR-COK'] === 2 && gal['BR-KEJ'] === 1 && detail.images.filter((i) => i.product_variation_id === null).length === 0, JSON.stringify(gal))
}

// ===================== UAT-B: storefront product detail (konsumen) =====================
for (const [tag, vp] of [['d', { width: 1280, height: 900 }], ['m', { width: 390, height: 844 }]]) {
  const page = await newPage(vp, `B-${tag}`); await login(page, 'konsumen@uat.test')
  await page.goto(`${BASE}/products/kue-warna-warni`); await page.waitForSelector('[data-testid="gallery-strip"], img[alt="Kue Warna-Warni"]')
  check(`B1 ${tag}: first variation (Merah) selected, its primary image shown`, (await sel(page)).includes('Merah') && /uat-merah1/.test((await mainSrc(page)) ?? ''), `${await sel(page)} ${await mainSrc(page)}`)
  await shot(page, `B-detail-merah-${tag}`)
  // variation button → gallery
  await page.click('[data-testid="variation-2"]'); await page.waitForTimeout(200)
  check(`B2 ${tag}: variation Biru → Biru gallery + price Rp25.000`, (await sel(page)).includes('Biru') && /uat-biru|new-|products\//.test((await mainSrc(page)) ?? '') && (await page.textContent('body')).includes('25.000'), `${await mainSrc(page)}`)
  await shot(page, `B-detail-biru-${tag}`)
  // gallery → variation (thumbnail of another variation)
  await page.click('[data-testid="variant-thumb-1"]'); await page.waitForTimeout(200)
  check(`B3 ${tag}: Merah thumbnail click selects Merah (selected state, image, price Rp15.000)`, (await sel(page)).includes('Merah') && /uat-merah1/.test((await mainSrc(page)) ?? '') && (await page.textContent('body')).includes('15.000'))
  await page.click('[data-testid="variant-thumb-3"]'); await page.waitForTimeout(200)
  check(`B4 ${tag}: third variation (Hijau, out of stock) selectable from gallery; add-to-cart unavailable`, (await sel(page)).includes('Hijau') && /uat-hijau1/.test((await mainSrc(page)) ?? ''))
  const addDisabled = await page.evaluate(() => { const b = [...document.querySelectorAll('button')].find((x) => /keranjang|cart/i.test(x.textContent)); return b ? b.disabled : null })
  check(`B5 ${tag}: out-of-stock variation cannot be added to cart`, addDisabled === true || addDisabled === null, `disabled=${addDisabled}`)
  await shot(page, `B-detail-hijau-outofstock-${tag}`)
  const biruFirst = await page.evaluate(async () => { const d = (await (await fetch('/api/v1/products/kue-warna-warni')).json()).data; const v = d.variations.find((x) => x.label === 'Biru'); const own = d.images.filter((i) => i.product_variation_id === v.id); return (own.find((i) => i.is_primary) ?? own[0]).url })
  // rapid switching
  for (const id of [2, 1, 3, 2, 1, 2]) { const t = await page.$(`[data-testid="variant-thumb-${id}"]`); if (t) await t.click(); else await page.click(`[data-testid="variation-${id}"]`) }
  await page.waitForTimeout(300)
  check(`B6 ${tag}: after rapid switching the final state is consistent (Biru, Biru image, Rp25.000)`, (await sel(page)).join() === 'Biru' && (await mainSrc(page)) === biruFirst && (await page.textContent('body')).includes('25.000'), `${await sel(page)} ${await mainSrc(page)}`)
  // add to cart Biru → localStorage payload
  await page.evaluate(() => localStorage.removeItem('pc-cart'))
  const add = [...(await page.$$('button'))]; let clicked = false
  for (const b of add) { const t = (await b.textContent()) ?? ''; if (/keranjang|cart/i.test(t) && !(await b.isDisabled())) { await b.click(); clicked = true; break } }
  await page.waitForTimeout(300)
  const cart = JSON.parse((await page.evaluate(() => localStorage.getItem('pc-cart'))) ?? '[]')
  check(`B7 ${tag}: add-to-cart payload uses the gallery-selected variation (id 2, Biru, price 25000)`, clicked && cart.length === 1 && cart[0].variationId === 2 && cart[0].variationLabel === 'Biru' && Number(cart[0].unitPrice) === 25000, JSON.stringify(cart[0] ?? null))
  if (tag === 'd') {
    // simple product
    await page.goto(`${BASE}/products/roti-sederhana`); await page.waitForSelector('img[alt="Roti Sederhana"]')
    check('B8 product without variations: plain gallery, no variant thumbnails', (await page.$$('[data-testid^="variant-thumb-"]')).length === 0 && (await page.$$('[data-testid="gallery-thumb"]')).length === 2)
    await shot(page, 'B-detail-simple-d')
  }
}

// ===================== UAT-C: categories =====================
{
  const page = await newPage({ width: 1280, height: 900 }, 'C'); await page.goto(`${BASE}/categories`); await page.waitForSelector('[data-testid="category-tree"]')
  const roots = await page.$$eval('[data-testid="category-root"]', (els) => els.map((e) => ({ name: e.querySelector('a').textContent.trim(), kids: [...e.querySelectorAll('[data-category-id]')].map((k) => `${k.getAttribute('data-depth')}:${k.querySelector('a').textContent.trim()}`) })))
  check('C1 storefront: parents on top, subcategories nested beneath their own parent (3 levels), no flat mix', roots.length === 2 && roots[0].kids.join('|') === '0:Kue|1:Kue Basah|1:Kue Kering|2:Nastar' && roots[1].kids.join('|') === '0:Roti|1:Roti Tawar', JSON.stringify(roots))
  await shot(page, 'C-storefront-categories-d')
  const mob = await newPage({ width: 390, height: 844 }, 'C-m'); await mob.goto(`${BASE}/categories`); await mob.waitForSelector('[data-testid="category-tree"]'); await shot(mob, 'C-storefront-categories-m')
  const lp = await newPage({ width: 1280, height: 900 }, 'C-l'); await lp.goto(`${BASE}/products`); await lp.waitForSelector('[data-depth]'); const depths = await lp.$$eval('button[data-depth]', (b) => b.map((x) => `${x.getAttribute('data-depth')}:${x.textContent.trim()}`)); check('C2 product listing filter shows hierarchy in order', depths.slice(0, 4).join('|') === '0:Kue|1:Kue Basah|1:Kue Kering|2:Nastar', depths.join('|'))
  // agen: read-only note, no write controls
  const ag = await newPage({ width: 1280, height: 900 }, 'C-a'); await login(ag, 'agen@uat.test'); await ag.goto(`${BASE}/dashboard/products`); await ag.waitForSelector('[data-testid="category-readonly-note"]')
  check('C3 Agen: hierarchy visible read-only; create controls absent; API write denied (403)', (await ag.$('input[placeholder="Nama kategori baru"]')) === null && (await ag.evaluate(async () => { await fetch('/sanctum/csrf-cookie'); const x = document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1]; return (await fetch('/api/v1/categories', { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(x ?? '') }, body: JSON.stringify({ name: 'X' }) })).status })) === 403)
  await shot(ag, 'C-dashboard-agen-readonly')
  // super admin: create parent, child, grandchild through the UI
  const su = await newPage({ width: 1280, height: 1000 }, 'C-s'); await login(su, 'sa@uat.test'); await su.goto(`${BASE}/dashboard/products`); await su.waitForSelector('input[placeholder="Nama kategori baru"]')
  const add = async (name, parentLabel) => { await su.fill('input[placeholder="Nama kategori baru"]', name); if (parentLabel) await su.selectOption('select[aria-label="Induk kategori baru"]', { label: parentLabel }); await su.click('button:has-text("Tambah")'); await su.waitForTimeout(900) }
  await add('Minuman', null); await add('Kopi', 'Minuman'); await add('Kopi Susu', '— Kopi')
  await shot(su, 'C-dashboard-super-created')
  await su.goto(`${BASE}/categories`); await su.waitForSelector('[data-testid="category-tree"]')
  const tree2 = await su.$$eval('[data-testid="category-root"]', (els) => els.map((e) => [...e.querySelectorAll('[data-category-id]')].map((k) => `${k.getAttribute('data-depth')}:${k.querySelector('a').textContent.trim()}`).join('|')))
  check('C4 super_admin created Minuman → Kopi → Kopi Susu; storefront nests them correctly', tree2.some((t) => t === '0:Minuman|1:Kopi|2:Kopi Susu'), JSON.stringify(tree2))
  // invalid parent / cycle through UI: set Kue's parent to its grandchild Nastar (option should be absent) 
  const opts = await su.$$eval('select[aria-label="Induk kategori Kue"] option', (o) => o.map((x) => x.textContent.trim()))
  check('C5 editor never offers a descendant as parent (cycle prevention in UI)', !opts.some((t) => /Nastar|Kue Kering|Kue Basah/.test(t)), JSON.stringify(opts))
}

// ===================== UAT-D: Social & Marketplace card =====================
for (const [tag, vp] of [['d', { width: 1280, height: 900 }], ['t', { width: 768, height: 900 }], ['m', { width: 390, height: 844 }], ['xs', { width: 320, height: 700 }]]) {
  const page = await newPage(vp, `D-${tag}`); await page.goto(`${BASE}/`); await page.waitForSelector('[data-testid="social-marketplace-card"]')
  const info = await page.evaluate(() => { const c = document.querySelector('[data-testid="social-marketplace-card"]'); const a = [...c.querySelectorAll('[data-testid="smc-icons"] a')]; const r = c.querySelector('[data-testid="smc-icons"]').getBoundingClientRect(); const tops = new Set(a.map((x) => Math.round(x.getBoundingClientRect().top))); return { cards: document.querySelectorAll('[data-testid="social-marketplace-card"]').length, titles: c.querySelectorAll('h2').length, order: a.map((x) => x.dataset.platform), hrefs: a.map((x) => x.href), sizes: a.map((x) => Math.round(x.getBoundingClientRect().width)), oneRow: tops.size === 1, bg: getComputedStyle(c.firstElementChild).backgroundColor, rel: a.every((x) => x.rel.includes('noopener') && x.target === '_blank'), centered: getComputedStyle(c.firstElementChild).textAlign, bodyOverflow: document.documentElement.scrollWidth > window.innerWidth, hasOld: !!document.querySelector('.bg-gradient-to-br') } })
  check(`D1 ${tag}: ONE card, ONE title, five icons in approved order, one row, white, centered`, info.cards === 1 && info.titles === 1 && info.order.join() === 'instagram,tiktok,facebook,tokopedia,shopee' && info.oneRow && info.bg === 'rgb(255, 255, 255)' && info.centered === 'center', JSON.stringify(info))
  check(`D2 ${tag}: links come from CMS and open safely; icon size ${info.sizes[0]}px; no overflow; no old brown tiles`, info.hrefs.join() === 'https://instagram.com/primeclassy,https://tiktok.com/@primeclassy,https://facebook.com/primeclassy,https://tokopedia.com/primeclassy,https://shopee.co.id/primeclassy' && info.rel && !info.bodyOverflow && !info.hasOld && info.sizes.every((s) => s >= 40 && s <= 52))
  await page.locator('[data-testid="social-marketplace-card"]').scrollIntoViewIfNeeded(); await page.locator('[data-testid="social-marketplace-card"]').screenshot({ path: `${OUT}/D-social-card-${tag}.png` })
}
fs.writeFileSync(`${OUT}/results.json`, JSON.stringify(R, null, 2))
const fails = R.checks.filter((c) => !c.ok); console.log(`\n${R.checks.length - fails.length}/${R.checks.length} PASS`, fails.map((f) => f.name)); console.log('console errors:', R.console)
await browser.close()
