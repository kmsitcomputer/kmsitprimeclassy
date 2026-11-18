import { SUPPORTED_LOCALES, isSupportedLocale, type LocaleCode } from '@/i18n'

/**
 * Pure locale/SEO helpers shared by the Nuxt storefront (SSR) and the SPA — no framework, no I/O.
 *
 * URL policy (documented in docs/20): language variants of a page live at the SAME path with `?lang=<code>`;
 * the default language never carries the parameter. This works identically in the SPA (so rolling Nuxt back keeps
 * every indexed URL valid) and needs no new route table. `hreflang` is only emitted for pages whose primary
 * content genuinely exists per language (CMS articles/pages) — see `buildAlternates`.
 */
export const DEFAULT_LOCALE: LocaleCode = 'id'

export const OG_LOCALE: Record<LocaleCode, string> = { id: 'id_ID', en: 'en_US', zh: 'zh_CN', ar: 'ar_AR' }

export interface LocaleResolution {
  locale: LocaleCode
  /** true when the locale was chosen explicitly by `?lang=` on this request (it must then be persisted client-side). */
  fromQuery: boolean
}

function first(value: unknown): unknown {
  return Array.isArray(value) ? value[0] : value
}

/**
 * Request locale: `?lang=` > `pc-locale` cookie > default. A code is honoured only if the UI has a catalogue for it
 * (SUPPORTED_LOCALES) AND the backend lists it as an active language (`active`); anything else falls through.
 */
export function resolveRequestLocale(input: { query?: unknown; cookie?: string | null; active: readonly LocaleCode[] }): LocaleResolution {
  const allowed = (code: unknown): code is LocaleCode => isSupportedLocale(code) && input.active.includes(code)
  const q = first(input.query)
  if (allowed(q)) return { locale: q, fromQuery: true }
  if (allowed(input.cookie)) return { locale: input.cookie, fromQuery: false }
  return { locale: DEFAULT_LOCALE, fromQuery: false }
}

/** Intersection of the backend's active language codes and the UI catalogues, default first, order stable. */
export function activeSupportedLocales(backendCodes: readonly string[]): LocaleCode[] {
  const set = new Set(backendCodes)
  const list = SUPPORTED_LOCALES.map((l) => l.code).filter((c) => set.has(c))
  return list.includes(DEFAULT_LOCALE) ? [DEFAULT_LOCALE, ...list.filter((c) => c !== DEFAULT_LOCALE)] : [...list]
}

/** `/path?x=1` + locale → same URL with `lang` set (or removed for the default language). Other params are untouched. */
export function withLang(pathWithQuery: string, locale: LocaleCode): string {
  const [path = '', query = ''] = pathWithQuery.split('?')
  const params = new URLSearchParams(query)
  params.delete('lang')
  if (locale !== DEFAULT_LOCALE) params.set('lang', locale)
  const qs = params.toString()
  return qs ? `${path}?${qs}` : path
}

export interface Alternate {
  hreflang: string
  href: string
}

/**
 * hreflang cluster for a page whose content exists in `contentLocales`. Needs at least two languages (a single-language
 * page has no alternates to declare). Includes a self-reference for every member and `x-default` → the default-language
 * URL (or the first available language when the default has no content).
 */
export function buildAlternates(origin: string, pathWithQuery: string, contentLocales: readonly LocaleCode[]): Alternate[] {
  const unique = [...new Set(contentLocales)]
  if (unique.length < 2) return []
  const url = (l: LocaleCode) => `${origin}${withLang(pathWithQuery, l)}`
  const links: Alternate[] = unique.map((l) => ({ hreflang: l, href: url(l) }))
  const xDefault = unique.includes(DEFAULT_LOCALE) ? DEFAULT_LOCALE : unique[0]!
  links.push({ hreflang: 'x-default', href: url(xDefault) })
  return links
}

/**
 * Canonical path for the requested locale. A language variant is canonical to itself ONLY when it has real content in
 * that language; otherwise it is a fallback copy of the default content and canonicalises to the default-language URL.
 */
export function canonicalPath(pathWithQuery: string, locale: LocaleCode, contentLocales: readonly LocaleCode[] | null): string {
  if (contentLocales && contentLocales.includes(locale)) return withLang(pathWithQuery, locale)
  return withLang(pathWithQuery, DEFAULT_LOCALE)
}
