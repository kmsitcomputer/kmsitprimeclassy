// Minimal stand-in for the Laravel public API (fixtures only) — records every request so the smoke test can
// prove SSR never forwards cookies/credentials and only issues GETs.
import http from 'node:http'
export const log = []
const env = (data, meta = null) => JSON.stringify({ success: true, message: 'ok', data, errors: null, meta })
const product = (id, slug, name) => ({ id, sku: `SKU${id}`, name, slug, description: `<p>Deskripsi ${name} yang <b>lezat</b></p>`, short_description: `Ringkas ${name}`, category: { id: 1, name: 'Kue', slug: 'kue' }, has_variations: false, base_price: '45000', weight_grams: 300, images: [{ id: id, product_id: id, product_variation_id: null, url: `/storage/products/${slug}.jpg`, is_primary: true, sort_order: 0 }], variations: [], status: 'active', stock: { available: 5, in_stock: true, login_to_view: false } })
const art = (slug, trs) => ({ id: slug.length, type: 'article', slug, status: 'published', published_at: '2026-11-01T00:00:00Z', cover_image_url: null, author_name: 'Admin', translations: trs.map(([language_id, title, excerpt, body]) => ({ language_id, title, excerpt, body })), created_at: '2026-11-01T00:00:00Z', updated_at: '2026-11-02T00:00:00Z' })
const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://x')
  log.push({ method: req.method, path: url.pathname, query: url.search, cookie: req.headers.cookie ?? null, auth: req.headers.authorization ?? null, xLocale: req.headers['x-locale'] ?? null })
  res.setHeader('content-type', 'application/json')
  const p = url.pathname
  let body
  if (p === '/api/v1/settings') body = env({ site_title: 'Prime Classy', logo_url: '/storage/logo.png', favicon_url: null })
  else if (p === '/api/v1/install/status') body = env({ installed: true })
  else if (p === '/api/v1/homepage') body = env([{ id: 1, type: 'unknown-block', content: {} }])
  else if (p === '/api/v1/categories') body = env([{ id: 1, parent_id: null, name: 'Kue', slug: 'kue', is_active: true, sort_order: 1 }])
  else if (p === '/api/v1/languages') body = env([{ id: 1, code: 'id', name: 'Indonesia', is_default: true }, { id: 2, code: 'en', name: 'English', is_default: false }, { id: 3, code: 'zh', name: 'Chinese', is_default: false }])
  else if (p === '/api/v1/products') {
    const per = Number(url.searchParams.get('per_page') || 15), pg = Number(url.searchParams.get('page') || 1), total = 30, last = Math.ceil(total / per)
    const items = pg > last ? [] : Array.from({ length: Math.min(per, total - (pg - 1) * per) }, (_, i) => { const n = (pg - 1) * per + i + 1; return product(n, n === 1 ? 'red-velvet' : `produk-${n}`, n === 1 ? 'Red Velvet' : `Produk ${n}`) })
    body = env(items, { current_page: pg, last_page: last, total, per_page: per })
  }
  else if (p === '/api/v1/products/red-velvet') body = env(product(1, 'red-velvet', 'Red Velvet'))
  else if (p === '/api/v1/articles') {
    const per = Number(url.searchParams.get('per_page') || 15), pg = Number(url.searchParams.get('page') || 1)
    const all = [art('tips', [[1, 'Tips Kue', 'Ringkasan', '<p>Isi artikel</p>'], [2, 'Cake Tips', 'Summary', '<p>Article body</p>']]), art('solo', [[1, 'Artikel Tunggal', 'Hanya id', '<p>Hanya Indonesia</p>']]), ...Array.from({ length: 38 }, (_, i) => art(`a${i + 3}`, [[1, `Artikel ${i + 3}`, 'x', '<p>x</p>']]))]
    const last = Math.ceil(all.length / per)
    body = env(pg > last ? [] : all.slice((pg - 1) * per, pg * per), { current_page: pg, last_page: last, total: all.length })
  }
  else if (p.startsWith('/api/v1/articles/')) { const slug = p.split('/').pop(); const a = { tips: art('tips', [[1, 'Tips Kue', 'Ringkasan', '<p>Isi artikel</p>'], [2, 'Cake Tips', 'Summary', '<p>Article body</p>']]), solo: art('solo', [[1, 'Artikel Tunggal', 'Hanya id', '<p>Hanya Indonesia</p>']]) }[slug]; if (a) body = env(a); else { res.statusCode = 404; body = JSON.stringify({ success: false, message: 'Not found', data: null, errors: null, meta: null }) } }
  else if (p === '/api/v1/agents') body = env([{ name: 'Toko Mawar', address: 'Jl. Mawar 1, Bandung', phone: '081234567890', latitude: -6.9, longitude: 107.6, referral_code: 'AG-MAWAR9' }, { name: 'Toko Melati', address: 'Jl. Melati 2, Jakarta', phone: '081299998888', latitude: -6.2, longitude: 106.8, referral_code: 'AG-MELATI7' }])
  else if (p === '/api/v1/auth/me') { res.statusCode = 401; body = JSON.stringify({ success: false, message: 'Unauthenticated.', data: null, errors: null, meta: null }) }
  else { res.statusCode = 404; body = JSON.stringify({ success: false, message: 'Not found', data: null, errors: null, meta: null }) }
  res.end(body)
})
server.listen(8000, '0.0.0.0')
server.on('request', () => {})
http.createServer((q, r) => {
  // Test hygiene: a long-lived mock accumulates requests from previous runs (browser runs legitimately send
  // the pc-locale cookie). A consumer that asserts on the WHOLE log must clear it first.
  if ((q.url || '').startsWith('/__clear')) {
    log.length = 0
    r.setHeader('content-type', 'application/json')
    r.end('{"ok":true}')
    return
  }
  r.setHeader('content-type', 'application/json')
  r.end(JSON.stringify(log))
}).listen(8001, '0.0.0.0')
