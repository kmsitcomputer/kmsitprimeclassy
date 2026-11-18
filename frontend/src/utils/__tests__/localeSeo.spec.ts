import { describe, expect, it } from 'vitest'
import { activeSupportedLocales, buildAlternates, canonicalPath, resolveRequestLocale, withLang } from '../localeSeo'

describe('request locale resolution (SSR)', () => {
  const active = ['id', 'en', 'zh'] as const
  it('?lang= wins over the cookie, and is flagged as explicit', () => {
    expect(resolveRequestLocale({ query: 'en', cookie: 'zh', active })).toEqual({ locale: 'en', fromQuery: true })
  })
  it('cookie is used when there is no valid ?lang=', () => {
    expect(resolveRequestLocale({ query: undefined, cookie: 'zh', active })).toEqual({ locale: 'zh', fromQuery: false })
    expect(resolveRequestLocale({ query: ['en', 'zh'], cookie: null, active })).toEqual({ locale: 'en', fromQuery: true })
  })
  it('never honours a locale the backend has not activated (ar) or one with no UI catalogue (fr)', () => {
    expect(resolveRequestLocale({ query: 'ar', cookie: null, active }).locale).toBe('id')
    expect(resolveRequestLocale({ query: 'fr', cookie: 'fr', active: ['id', 'en', 'zh', 'ar', 'fr' as never] }).locale).toBe('id')
  })
  it('falls back to the default language', () => {
    expect(resolveRequestLocale({ active })).toEqual({ locale: 'id', fromQuery: false })
    expect(resolveRequestLocale({ query: '<script>', cookie: '"; x', active }).locale).toBe('id')
  })
  it('active set = backend ∩ UI catalogues, default first', () => {
    expect(activeSupportedLocales(['en', 'fr', 'id', 'ar'])).toEqual(['id', 'en', 'ar'])
    expect(activeSupportedLocales(['en'])).toEqual(['en'])
  })
})

describe('language URLs', () => {
  it('default language never carries ?lang=; others do; other params untouched', () => {
    expect(withLang('/articles/tips', 'id')).toBe('/articles/tips')
    expect(withLang('/articles/tips', 'en')).toBe('/articles/tips?lang=en')
    expect(withLang('/products?category=kue&page=2&lang=zh', 'en')).toBe('/products?category=kue&page=2&lang=en')
    expect(withLang('/products?category=kue&lang=zh', 'id')).toBe('/products?category=kue')
  })
  it('hreflang cluster: self-references, x-default → default language, only with ≥2 languages', () => {
    const alt = buildAlternates('https://shop.test', '/articles/tips', ['id', 'en', 'zh'])
    expect(alt).toEqual([
      { hreflang: 'id', href: 'https://shop.test/articles/tips' },
      { hreflang: 'en', href: 'https://shop.test/articles/tips?lang=en' },
      { hreflang: 'zh', href: 'https://shop.test/articles/tips?lang=zh' },
      { hreflang: 'x-default', href: 'https://shop.test/articles/tips' },
    ])
    expect(buildAlternates('https://shop.test', '/articles/tips', ['en'])).toEqual([])
    expect(buildAlternates('https://shop.test', '/articles/tips', ['en', 'zh']).at(-1)).toEqual({ hreflang: 'x-default', href: 'https://shop.test/articles/tips?lang=en' })
  })
  it('canonical: own language when real content exists, else the default-language URL', () => {
    expect(canonicalPath('/articles/tips?lang=en', 'en', ['id', 'en'])).toBe('/articles/tips?lang=en')
    expect(canonicalPath('/articles/tips?lang=zh', 'zh', ['id', 'en'])).toBe('/articles/tips')
    expect(canonicalPath('/products?lang=en&page=2', 'en', null)).toBe('/products?page=2')
  })
})
