// Minimal stand-in for the Laravel public API (fixtures only) — records every request so the smoke test can
// prove SSR never forwards cookies/credentials and only issues GETs.
import http from 'node:http'
export const log = []
const env = (data, meta = null) => JSON.stringify({ success: true, message: 'ok', data, errors: null, meta })
const product = (id, slug, name) => ({ id, sku: `SKU${id}`, name, slug, description: `<p>Deskripsi ${name} yang <b>lezat</b></p>`, short_description: `Ringkas ${name}`, category: { id: 1, name: 'Kue', slug: 'kue' }, has_variations: false, base_price: '45000', weight_grams: 300, images: [{ id: id, product_id: id, product_variation_id: null, url: `/storage/products/${slug}.jpg`, is_primary: true, sort_order: 0 }], variations: [], status: 'active', stock: { available: 5, in_stock: true, login_to_view: false } })
const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://x')
  log.push({ method: req.method, path: url.pathname, query: url.search, cookie: req.headers.cookie ?? null, auth: req.headers.authorization ?? null })
  res.setHeader('content-type', 'application/json')
  const p = url.pathname
  let body
  if (p === '/api/v1/settings') body = env({ site_title: 'Prime Classy', logo_url: '/storage/logo.png', favicon_url: null })
  else if (p === '/api/v1/install/status') body = env({ installed: true })
  else if (p === '/api/v1/homepage') body = env([{ id: 1, type: 'unknown-block', content: {} }])
  else if (p === '/api/v1/categories') body = env([{ id: 1, parent_id: null, name: 'Kue', slug: 'kue', is_active: true, sort_order: 1 }])
  else if (p === '/api/v1/languages') body = env([{ id: 1, code: 'id', name: 'Indonesia', is_default: true }])
  else if (p === '/api/v1/products') body = env([product(1, 'red-velvet', 'Red Velvet'), product(2, 'nastar', 'Nastar')], { current_page: 1, last_page: 1, total: 2, per_page: 12 })
  else if (p === '/api/v1/products/red-velvet') body = env(product(1, 'red-velvet', 'Red Velvet'))
  else if (p === '/api/v1/articles') body = env([{ id: 1, type: 'article', slug: 'tips', status: 'published', published_at: '2026-11-01T00:00:00Z', cover_image_url: null, author_name: 'Admin', translations: [{ language_id: 1, title: 'Tips Kue', excerpt: 'Ringkasan', body: '<p>Isi</p>' }], created_at: '2026-11-01T00:00:00Z', updated_at: '2026-11-02T00:00:00Z' }], { current_page: 1, last_page: 1, total: 1 })
  else if (p === '/api/v1/articles/tips') body = env({ id: 1, type: 'article', slug: 'tips', status: 'published', published_at: '2026-11-01T00:00:00Z', cover_image_url: null, author_name: 'Admin', translations: [{ language_id: 1, title: 'Tips Kue', excerpt: 'Ringkasan', body: '<p>Isi artikel</p>' }], created_at: '2026-11-01T00:00:00Z', updated_at: '2026-11-02T00:00:00Z' })
  else if (p === '/api/v1/agents') body = env([])
  else if (p === '/api/v1/auth/me') { res.statusCode = 401; body = JSON.stringify({ success: false, message: 'Unauthenticated.', data: null, errors: null, meta: null }) }
  else { res.statusCode = 404; body = JSON.stringify({ success: false, message: 'Not found', data: null, errors: null, meta: null }) }
  res.end(body)
})
server.listen(8000, '0.0.0.0')
server.on('request', () => {})
http.createServer((q, r) => { r.setHeader('content-type', 'application/json'); r.end(JSON.stringify(log)) }).listen(8001, '0.0.0.0')
