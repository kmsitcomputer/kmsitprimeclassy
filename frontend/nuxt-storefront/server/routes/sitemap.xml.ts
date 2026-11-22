/**
 * /sitemap.xml — public storefront URLs only (home, listing, categories, products, articles). Built from
 * the same public Go API endpoints the pages use, with no cookies. Static CMS pages (/page/:slug) are not
 * listed: the backend exposes no public page index (see docs/20-IMP009-NUXT-STOREFRONT.md).
 */
import { activeSupportedLocales, buildAlternates } from '@/utils/localeSeo'

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

  type Entry = { loc: string; lastmod?: string; alternates?: { hreflang: string; href: string }[] }
  const urls: Entry[] = [
    { loc: `${origin}/` },
    { loc: `${origin}/products` },
    { loc: `${origin}/categories` },
    { loc: `${origin}/articles` },
    { loc: `${origin}/toko-kami` },
  ]

  try {
    const [products, articles, categories, languages] = await Promise.all([
      collect<{ slug: string; updated_at?: string }>(base, '/products'),
      collect<{ slug: string; updated_at?: string; translations?: { language_id: number }[] }>(base, '/articles'),
      $fetch<Envelope<{ slug: string }[]>>(`${base}/api/v1/categories`, { retry: 0, timeout: 8000 }).then((r) => r.data),
      $fetch<Envelope<{ id: number; code: string }[]>>(`${base}/api/v1/languages`, { retry: 0, timeout: 8000 }).then((r) => r.data),
    ])
    // Languages the storefront may serve: backend-active ∩ UI catalogues. hreflang only for articles that really have rows in ≥2 of them.
    const served = new Set(activeSupportedLocales(languages.map((l) => l.code)))
    const codeById = new Map(languages.filter((l) => served.has(l.code as never)).map((l) => [l.id, l.code]))
    for (const p of products) urls.push({ loc: `${origin}/products/${encodeURIComponent(p.slug)}`, lastmod: p.updated_at })
    for (const a of articles) {
      const path = `/articles/${encodeURIComponent(a.slug)}`
      const locales = [...new Set((a.translations ?? []).map((t) => codeById.get(t.language_id)).filter((c): c is string => !!c))]
      urls.push({ loc: `${origin}${path}`, lastmod: a.updated_at, alternates: buildAlternates(origin, path, locales as never) })
    }
    for (const c of categories) urls.push({ loc: `${origin}/products?category=${encodeURIComponent(c.slug)}` })
  } catch {
    // Backend unreachable: serve the static entries only (never an error page as a sitemap), and do not let shared caches keep it.
    setResponseHeader(event, 'cache-control', 'no-store')
  }

  setResponseHeader(event, 'content-type', 'application/xml; charset=utf-8')
  const body = urls
    .map((u) => {
      const lastmod = u.lastmod ? `<lastmod>${xmlEscape(new Date(u.lastmod).toISOString())}</lastmod>` : ''
      const alt = (u.alternates ?? [])
        .map((a) => `<xhtml:link rel="alternate" hreflang="${xmlEscape(a.hreflang)}" href="${xmlEscape(a.href)}"/>`)
        .join('')
      return `<url><loc>${xmlEscape(u.loc)}</loc>${lastmod}${alt}</url>`
    })
    .join('')
  return `<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">${body}</urlset>`
})
