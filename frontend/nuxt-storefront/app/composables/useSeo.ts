import { buildAlternates, canonicalPath, OG_LOCALE, DEFAULT_LOCALE } from '@/utils/localeSeo'
import { useLocaleStore } from '@/stores/locale'
import type { LocaleCode } from '@/i18n'

/**
 * Per-page SEO for the server-rendered storefront: title, description, canonical, hreflang, Open Graph / Twitter,
 * robots, JSON-LD and the HTTP status for missing content. Pure head/status management — it never touches data,
 * cookies or auth. Pass a GETTER: head tags then follow the language switcher and data changes reactively.
 */
export interface SeoOptions {
  title: string
  description?: string | null
  /** Path with the query that identifies the page itself (no `lang`), e.g. `/products?category=kue&page=2`. */
  path: string
  image?: string | null
  ogType?: 'website' | 'article'
  noindex?: boolean
  /** Respond 404 (soft-404s hurt crawling) and force noindex. */
  notFound?: boolean
  /**
   * Languages in which this page's PRIMARY CONTENT really exists (CMS articles/pages). Omit for pages whose content is
   * not per-language (home, catalogue, products): their language variants canonicalise to the default-language URL and
   * no hreflang is published — advertising alternates that serve identical content would be false.
   */
  contentLocales?: readonly LocaleCode[] | null
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

export function absoluteUrl(pathOrUrl: string | null | undefined, origin: string = siteOrigin()): string | undefined {
  if (!pathOrUrl) return undefined
  if (/^https?:\/\//i.test(pathOrUrl)) return pathOrUrl
  return `${origin}${pathOrUrl.startsWith('/') ? '' : '/'}${pathOrUrl}`
}

export function useSeo(getOptions: () => SeoOptions): void {
  const locale = useLocaleStore()
  const origin = siteOrigin()
  const o = computed(getOptions)
  const contentLocales = computed(() => o.value.contentLocales ?? null)
  // A variant is indexable only when it has real content in its own language (or the page is not per-language and we are on the default).
  const isFallbackVariant = computed(() => locale.locale !== DEFAULT_LOCALE && !(contentLocales.value ?? []).includes(locale.locale))
  const canonical = computed(() => `${origin}${canonicalPath(o.value.path, locale.locale, contentLocales.value)}`)
  const robots = computed(() =>
    o.value.noindex || o.value.notFound || isFallbackVariant.value ? 'noindex, follow' : 'index, follow',
  )
  // `origin` was resolved at setup: computeds run later, outside the Nuxt instance context (no composables in here).
  const image = computed(() => absoluteUrl(o.value.image, origin))
  const description = computed(() => (o.value.description ? stripHtml(o.value.description, 300) : undefined))

  if (o.value.notFound && import.meta.server) {
    const event = useRequestEvent()
    if (event) setResponseStatus(event, 404)
  }

  useSeoMeta({
    title: () => o.value.title,
    description: () => description.value,
    robots: () => robots.value,
    ogTitle: () => o.value.title,
    ogDescription: () => description.value,
    ogType: () => o.value.ogType ?? 'website',
    ogUrl: () => canonical.value,
    ogImage: () => image.value,
    ogSiteName: 'Prime Classy',
    ogLocale: () => OG_LOCALE[locale.locale],
    twitterCard: () => (image.value ? 'summary_large_image' : 'summary'),
    twitterTitle: () => o.value.title,
    twitterDescription: () => description.value,
    twitterImage: () => image.value,
  })

  useHead(
    computed(() => {
      const alternates = contentLocales.value ? buildAlternates(origin, o.value.path, contentLocales.value) : []
      return {
        link: [
          { rel: 'canonical', href: canonical.value },
          ...alternates.map((a) => ({ rel: 'alternate', hreflang: a.hreflang, href: a.href })),
        ] as never,
        meta: alternates
          .filter((a) => a.hreflang !== 'x-default' && a.hreflang !== locale.locale)
          .map((a) => ({ property: 'og:locale:alternate', content: OG_LOCALE[a.hreflang as LocaleCode] })),
        script: o.value.jsonLd
          ? [
              {
                type: 'application/ld+json',
                // `<` is escaped so content can never close the script element.
                innerHTML: JSON.stringify(o.value.jsonLd).replace(/</g, '\\u003c'),
              },
            ]
          : [],
      }
    }),
  )
}

/**
 * Locales in which a CMS item (article/page) REALLY has content: a translation row for a language that is both
 * backend-active (`useState('pc-locale-state').active`) and has a UI catalogue. Nothing is inferred or invented.
 */
export function contentLocalesFor(
  translations: { language_id: number }[] | undefined,
  languages: { id: number; code: string }[] | undefined,
): LocaleCode[] {
  const active = useState<{ active: LocaleCode[] }>('pc-locale-state').value?.active ?? [DEFAULT_LOCALE]
  const withRow = new Set((translations ?? []).map((t) => t.language_id))
  return (languages ?? []).filter((l) => withRow.has(l.id) && (active as string[]).includes(l.code)).map((l) => l.code as LocaleCode)
}
