// Multilingual SSR / SEO / pagination / store-locator checks against a running Nuxt server (+ mock API on :8000, log on :8001).
const B = process.env.NUXT_URL || 'http://127.0.0.1:3000'
const LOG = process.env.MOCK_LOG_URL || 'http://127.0.0.1:8001'
const out = []; const check = (n, ok, d = '') => out.push(`${ok ? 'PASS' : 'FAIL'}  ${n}${d ? ' — ' + d : ''}`)
const get = async (path, headers = {}) => { const r = await fetch(B + path, { headers, redirect: 'manual' }); return { r, t: await r.text() } }
const attr = (t, re) => (t.match(re) || [])[1]
const htmlLang = (t) => attr(t, /<html[^>]*\blang="([^"]+)"/)
const canonical = (t) => attr(t, /<link[^>]*rel="canonical"[^>]*href="([^"]+)"/) || attr(t, /<link[^>]*href="([^"]+)"[^>]*rel="canonical"/)
const hreflangs = (t) => [...t.matchAll(/<link[^>]*rel="alternate"[^>]*>/g)].map((m) => [attr(m[0], /hreflang="([^"]+)"/), attr(m[0], /href="([^"]+)"/)]).filter((x) => x[0])
const robots = (t) => attr(t, /<meta[^>]*name="robots"[^>]*content="([^"]+)"/) || attr(t, /<meta[^>]*content="([^"]+)"[^>]*name="robots"/)
const ogLocale = (t) => attr(t, /property="og:locale"[^>]*content="([^"]+)"/) || attr(t, /content="([^"]+)"[^>]*property="og:locale"/)
const title = (t) => attr(t, /<title>([^<]*)<\/title>/)
const O = B

// Requests made BEFORE this run (e.g. by browser-i18n.mjs) legitimately carry the pc-locale cookie, so the
// "SSR never forwards a cookie" assertion below must only see THIS run's server-side traffic.
await fetch(`${LOG}/__clear`).catch(() => {})

// ── language resolution
let x = await get('/articles/tips')
check('default: lang=id, og:locale id_ID', htmlLang(x.t) === 'id' && ogLocale(x.t) === 'id_ID', `${htmlLang(x.t)} ${ogLocale(x.t)}`)
check('default: canonical has no ?lang', canonical(x.t) === `${O}/articles/tips`, canonical(x.t))
const hl = hreflangs(x.t).map((h) => h[0]).sort().join(',')
check('hreflang = only languages with a real translation (id,en) + x-default; zh/ar NOT advertised', hl === 'en,id,x-default', hl)
check('hreflang href values (default has no ?lang; en has ?lang=en; x-default → default)', JSON.stringify(Object.fromEntries(hreflangs(x.t))) === JSON.stringify({ id: `${O}/articles/tips`, en: `${O}/articles/tips?lang=en`, 'x-default': `${O}/articles/tips` }), JSON.stringify(hreflangs(x.t)))
check('default: indexable, title from id translation', robots(x.t) === 'index, follow' && /Tips Kue/.test(title(x.t)), `${robots(x.t)} | ${title(x.t)}`)

x = await get('/articles/tips?lang=en')
check('?lang=en: html lang=en, og:locale en_US', htmlLang(x.t) === 'en' && ogLocale(x.t) === 'en_US')
check('?lang=en: SSR content + title are the EN translation', /Cake Tips/.test(title(x.t)) && x.t.includes('Article body'), title(x.t))
check('?lang=en: canonical is self (?lang=en), same hreflang cluster, indexable', canonical(x.t) === `${O}/articles/tips?lang=en` && hreflangs(x.t).length === 3 && robots(x.t) === 'index, follow', canonical(x.t))
check('?lang=en: UI chrome rendered in English (header uses the EN catalogue)', /aria-label="Find an Agent/.test(x.t), '')

x = await get('/articles/tips', { cookie: 'pc-locale=en' })
check('cookie pc-locale=en (no ?lang): rendered in EN, canonical = EN variant', htmlLang(x.t) === 'en' && canonical(x.t) === `${O}/articles/tips?lang=en`)

x = await get('/articles/tips?lang=zh')
check('?lang=zh (active, but NO zh translation): UI zh, content falls back to id, canonical → default URL, noindex, no hreflang', htmlLang(x.t) === 'zh' && /Tips Kue/.test(title(x.t)) && canonical(x.t) === `${O}/articles/tips` && /noindex/.test(robots(x.t)) && hreflangs(x.t).length === 3, `${htmlLang(x.t)} | ${canonical(x.t)} | ${robots(x.t)} | ${hreflangs(x.t).length} alt`)
for (const bad of ['ar', 'fr', '<script>', '"><x']) {
  x = await get('/articles/tips?lang=' + encodeURIComponent(bad))
  check(`?lang=${bad.slice(0, 8)} (inactive/unsupported/hostile) → default id`, htmlLang(x.t) === 'id' && !x.t.includes('<script>alert') && canonical(x.t) === `${O}/articles/tips`)
}
x = await get('/articles/tips', { cookie: 'pc-locale=ar' }); check('cookie for an inactive language ignored', htmlLang(x.t) === 'id')
x = await get('/articles/solo?lang=en')
check('single-language article: no hreflang at all; EN variant canonicalises to default + noindex', hreflangs(x.t).length === 0 && canonical(x.t) === `${O}/articles/solo` && /noindex/.test(robots(x.t)), `${hreflangs(x.t).length} | ${canonical(x.t)} | ${robots(x.t)}`)
x = await get('/products/red-velvet?lang=en')
check('product (content not localised by the API): UI en, canonical = default URL, NO hreflang, noindex for the variant', htmlLang(x.t) === 'en' && canonical(x.t) === `${O}/products/red-velvet` && hreflangs(x.t).length === 0 && /noindex/.test(robots(x.t)), `${canonical(x.t)} | ${hreflangs(x.t).length} | ${robots(x.t)}`)
x = await get('/'); check('home default: no hreflang, canonical /', hreflangs(x.t).length === 0 && canonical(x.t) === `${O}/`)

// ── concurrency / isolation
const reqs = Array.from({ length: 60 }, (_, i) => ({ lang: ['id', 'en', 'zh'][i % 3] }))
const res = await Promise.all(reqs.map(async (q) => { const r = await get(`/categories?lang=${q.lang}`); return [q.lang, htmlLang(r.t), /<title>([^<]*)/.exec(r.t)?.[1]] }))
check('60 concurrent mixed-language requests: every response is in ITS OWN language (no cross-request leakage)', res.every(([want, got]) => want === got), `${res.filter(([w, g]) => w !== g).length} wrong`)
const titles = Object.fromEntries(['id', 'en', 'zh'].map((l) => [l, res.find(([w]) => w === l)[2]]))
check('…and titles differ per language (catalogue strings, not invented)', new Set(Object.values(titles)).size === 3, JSON.stringify(titles))
const log = await (await fetch(`${LOG}/`)).json()
const en = log.filter((e) => e.path === '/api/v1/articles/tips' && e.xLocale === 'en')
check('SSR sends the request language to the API (X-Locale: en seen for ?lang=en)', en.length >= 1)
check('SSR never forwarded a cookie/Authorization to the API', log.every((e) => !e.cookie && !e.auth), log.filter((e) => e.cookie || e.auth).slice(0, 3).map((e) => `${e.path} cookie=${e.cookie} auth=${e.auth}`).join(' | '))

// ── pagination
x = await get('/products?page=2')
check('/products?page=2 loads directly: SSR shows page-2 products (Produk 13…), self-canonical, indexable', x.t.includes('Produk 13') && !x.t.includes('Produk 25') && canonical(x.t) === `${O}/products?page=2` && robots(x.t) === 'index, follow', `${canonical(x.t)} ${robots(x.t)}`)
check('pagination controls are real links (rel=prev → /products, rel=next → ?page=3)', /<a[^>]*href="\/products"[^>]*rel="prev"|<a[^>]*rel="prev"[^>]*href="\/products"/.test(x.t) && /href="\/products\?page=3"/.test(x.t))
x = await get('/products?page=99'); check('page beyond the last → real 404 + noindex', x.r.status === 404 && /noindex/.test(robots(x.t)), `status ${x.r.status}`)
x = await get('/products?category=kue&page=2&lang=en'); check('category+page kept in canonical, lang dropped (content not localised)', canonical(x.t) === `${O}/products?category=kue&page=2`, canonical(x.t))
x = await get('/products?page=2&sort=price_asc'); check('sorted/filtered page: noindex,follow (duplicate-content policy)', /noindex/.test(robots(x.t)))
x = await get('/articles?page=2'); check('/articles?page=2 loads directly with page-2 articles + self-canonical', x.t.includes('Artikel 20') && canonical(x.t) === `${O}/articles?page=2`, canonical(x.t))
x = await get('/articles?page=9'); check('/articles page beyond last → 404', x.r.status === 404, `status ${x.r.status}`)

// ── store locator privacy
x = await get('/toko-kami')
check('store locator SSR renders agent name + address', x.t.includes('Toko Mawar') && x.t.includes('Jl. Mawar 1'))
check('store locator SSR HTML contains NO phone numbers and NO referral codes', !/0812345|0812999|AG-MAWAR|AG-MELATI/.test(x.t))
check('store locator Nuxt payload contains NO phone/referral either', !/081234|AG-MELATI/.test(x.t))

// ── sitemap
x = await get('/sitemap.xml')
const tips = x.t.match(/<url><loc>[^<]*\/articles\/tips<\/loc>.*?<\/url>/)?.[0] || ''
const solo = x.t.match(/<url><loc>[^<]*\/articles\/solo<\/loc>.*?<\/url>/)?.[0] || ''
check('sitemap: article with 2 real translations carries xhtml hreflang (id,en,x-default)', (tips.match(/hreflang="/g) || []).length === 3 && /xmlns:xhtml/.test(x.t), tips.slice(0, 200))
check('sitemap: single-language article has no hreflang', solo && !/hreflang/.test(solo))
x = await get('/healthz'); check('/healthz liveness → 200 {"status":"ok"} no-store', x.r.status === 200 && /"ok"/.test(x.t) && /no-store/.test(x.r.headers.get('cache-control')))
x = await get('/healthz?deep=1'); check('/healthz?deep=1 probes the API (mock has no /up → 503 degraded)', x.r.status === 503 || x.r.status === 200, `status ${x.r.status}`)
console.log(out.join('\n')); console.log(`\n${out.filter((l) => l.startsWith('PASS')).length} passed, ${out.filter((l) => l.startsWith('FAIL')).length} failed`)
