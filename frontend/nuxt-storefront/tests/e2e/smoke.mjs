const B = 'http://127.0.0.1:3000'
const out = []
const check = (name, ok, detail = '') => { out.push(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`) }
async function get(path, headers = {}) { const r = await fetch(B + path, { headers, redirect: 'manual' }); return { r, t: await r.text() } }
const pages = [
  ['/', 200], ['/products', 200], ['/products?category=kue', 200], ['/products/red-velvet', 200], ['/categories', 200],
  ['/articles', 200], ['/articles/tips', 200], ['/toko-kami', 200],
]
for (const [p, st] of pages) {
  const { r, t } = await get(p, { cookie: 'laravel_session=SECRET-SESSION; XSRF-TOKEN=SECRET-XSRF' })
  check(`${p} status ${st}`, r.status === st, `got ${r.status}`)
  check(`${p} has <title>`, /<title>[^<]+<\/title>/.test(t), (t.match(/<title>([^<]*)/) || [])[1])
  check(`${p} canonical + og:title`, /rel="canonical"/.test(t) && /property="og:title"/.test(t))
  check(`${p} marker header x-pc-renderer`, r.headers.get('x-pc-renderer') === 'nuxt')
  check(`${p} no session cookie echoed in HTML`, !t.includes('SECRET-SESSION') && !t.includes('SECRET-XSRF'))
}
let r = await get('/products/red-velvet')
check('product SSR contains name+price content', r.t.includes('Red Velvet'))
check('product JSON-LD Product/IDR', /"@type":"Product"/.test(r.t) && /"priceCurrency":"IDR"/.test(r.t))
check('product payload seeds hydration (product:red-velvet)', r.t.includes('product:red-velvet'))
r = await get('/articles/tips'); check('article SSR contains title', r.t.includes('Tips Kue'))
check('article JSON-LD', /"@type":"Article"/.test(r.t))
r = await get('/products/no-such'); check('missing product → 404 + noindex', r.r.status === 404 && /noindex/.test(r.t), `status ${r.r.status}`)
r = await get('/products?search=cake&sort=price_asc'); check('filtered listing noindex', /name="robots" content="noindex/.test(r.t))
r = await get('/no-such-page-xyz'); check('unknown path → 404 (not-found view)', r.r.status === 404, `status ${r.r.status}`)
r = await get('/cart'); check('SPA-owned /cart not rendered by Nuxt (fail closed)', r.r.status === 404 || r.r.status >= 300, `status ${r.r.status}`)
r = await get('/dashboard'); check('SPA-owned /dashboard not rendered (fail closed)', r.r.status === 404 && !/<h1|dashboard/i.test(r.t.replace(/<script[\s\S]*?<\/script>/g,'')) || r.r.status === 404, `status ${r.r.status}`)
check('SPA-owned (misrouted) paths are never shared-cacheable', /no-cache|no-store/.test(r.r.headers.get('cache-control') ?? ''), r.r.headers.get('cache-control'))
r = await get('/sitemap.xml'); check('sitemap.xml lists product+article', r.t.includes('/products/red-velvet') && r.t.includes('/articles/tips') && r.r.headers.get('content-type').includes('xml'))
r = await get('/robots.txt'); check('robots.txt disallows dashboard + lists sitemap', r.t.includes('Disallow: /dashboard') && r.t.includes('Sitemap:'))
const log = await (await fetch('http://127.0.0.1:8001/')).json()
check('backend saw ONLY GET requests', log.every((e) => e.method === 'GET'), `${log.length} requests`)
check('backend never received a cookie/Authorization header from SSR', log.every((e) => !e.cookie && !e.auth))
check('SSR never called /auth/me', !log.some((e) => e.path.includes('/auth/')))
console.log(out.join('\n'))
console.log(`\n${out.filter((l) => l.startsWith('PASS')).length} passed, ${out.filter((l) => l.startsWith('FAIL')).length} failed`)
