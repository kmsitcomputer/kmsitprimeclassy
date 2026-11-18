/**
 * /sitemap.xml — public storefront URLs only (home, listing, categories, products, articles). Built from
 * the same PUBLIC Laravel endpoints the pages use, with no cookies. Static CMS pages (/page/:slug) are not
 * listed: the backend exposes no public page index (see docs/20-IMP009-NUXT-STOREFRONT.md).
 */
type Envelope<T> = { data: T; meta?: { last_page?: number } | null }

const MAX_PAGES = 50 // hard ceiling: 50 × 100 URLs per collection

function xmlEscape(value: string): string {
  return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
}

async function collect<T>(base: string, path: string): Promise<T[]> {
  const out: T[] = []
  for (let page = 1; page <= MAX_PAGES; page++) {
    const res = await $fetch<Envelope<T[]>>(`${base}/api/v1${path}`, {
      query: { per_page: 100, page },
      headers: { Accept: 'application/json' },
      retry: 0,
      timeout: 8000,
    })
    out.push(...res.data)
    if (page >= Number(res.meta?.last_page ?? 1)) break
  }
  return out
}

export default defineEventHandler(async (event) => {
  const config = useRuntimeConfig(event)
  const base = String(config.apiInternalBase).replace(/\/+$/, '')
  const origin = (String(config.public.siteUrl || '').replace(/\/+$/, '') || getRequestURL(event).origin)

  const urls: { loc: string; lastmod?: string }[] = [
    { loc: `${origin}/` },
    { loc: `${origin}/products` },
    { loc: `${origin}/categories` },
    { loc: `${origin}/articles` },
    { loc: `${origin}/toko-kami` },
  ]

  try {
    const [products, articles, categories] = await Promise.all([
      collect<{ slug: string; updated_at?: string }>(base, '/products'),
      collect<{ slug: string; updated_at?: string }>(base, '/articles'),
      $fetch<Envelope<{ slug: string }[]>>(`${base}/api/v1/categories`, { retry: 0, timeout: 8000 }).then((r) => r.data),
    ])
    for (const p of products) urls.push({ loc: `${origin}/products/${encodeURIComponent(p.slug)}`, lastmod: p.updated_at })
    for (const a of articles) urls.push({ loc: `${origin}/articles/${encodeURIComponent(a.slug)}`, lastmod: a.updated_at })
    for (const c of categories) urls.push({ loc: `${origin}/products?category=${encodeURIComponent(c.slug)}` })
  } catch {
    // Backend unreachable: serve the static entries only (never an error page as a sitemap), and do not let shared caches keep it.
    setResponseHeader(event, 'cache-control', 'no-store')
  }

  setResponseHeader(event, 'content-type', 'application/xml; charset=utf-8')
  const body = urls
    .map((u) => {
      const lastmod = u.lastmod ? `<lastmod>${xmlEscape(new Date(u.lastmod).toISOString())}</lastmod>` : ''
      return `<url><loc>${xmlEscape(u.loc)}</loc>${lastmod}</url>`
    })
    .join('')
  return `<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">${body}</urlset>`
})
