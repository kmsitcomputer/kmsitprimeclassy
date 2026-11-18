/**
 * Per-page SEO for the server-rendered storefront: title, description, canonical, Open Graph / Twitter,
 * robots directive, optional JSON-LD, and the HTTP status for missing content. Pure head/status
 * management — it never touches data, cookies or auth.
 */
export interface SeoOptions {
  title: string
  description?: string | null
  /** Path (with optional allowed query) used for the canonical URL, e.g. `/products/red-velvet`. */
  path: string
  image?: string | null
  ogType?: 'website' | 'article' | 'product'
  noindex?: boolean
  /** Respond 404 (soft-404s hurt crawling) and force noindex. */
  notFound?: boolean
  jsonLd?: Record<string, unknown> | Record<string, unknown>[]
}

export function stripHtml(html: string | null | undefined, max = 160): string {
  const text = (html ?? '')
    .replace(/<[^>]*>/g, ' ')
    .replace(/&nbsp;/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/\s+/g, ' ')
    .trim()
  return text.length > max ? `${text.slice(0, max - 1).trimEnd()}…` : text
}

export function siteOrigin(): string {
  const configured = String(useRuntimeConfig().public.siteUrl || '').replace(/\/+$/, '')
  return configured || useRequestURL().origin
}

export function absoluteUrl(pathOrUrl: string | null | undefined): string | undefined {
  if (!pathOrUrl) return undefined
  if (/^https?:\/\//i.test(pathOrUrl)) return pathOrUrl
  return `${siteOrigin()}${pathOrUrl.startsWith('/') ? '' : '/'}${pathOrUrl}`
}

export function useSeo(options: SeoOptions): void {
  const canonical = absoluteUrl(options.path)
  const image = absoluteUrl(options.image)
  const description = options.description ? stripHtml(options.description, 300) : undefined
  const robots = options.noindex || options.notFound ? 'noindex, follow' : 'index, follow'

  if (options.notFound && import.meta.server) {
    const event = useRequestEvent()
    if (event) setResponseStatus(event, 404)
  }

  useSeoMeta({
    title: options.title,
    description,
    robots,
    ogTitle: options.title,
    ogDescription: description,
    ogType: options.ogType === 'product' ? 'website' : (options.ogType ?? 'website'),
    ogUrl: canonical,
    ogImage: image,
    ogSiteName: 'Prime Classy',
    twitterCard: image ? 'summary_large_image' : 'summary',
    twitterTitle: options.title,
    twitterDescription: description,
    twitterImage: image,
  })

  useHead({
    link: canonical ? [{ rel: 'canonical', href: canonical }] : [],
    script: options.jsonLd
      ? [
          {
            type: 'application/ld+json',
            // `<` is escaped so content can never close the script element.
            innerHTML: JSON.stringify(options.jsonLd).replace(/</g, '\\u003c'),
          },
        ]
      : [],
  })
}
