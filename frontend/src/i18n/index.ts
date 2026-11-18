import { createI18n } from 'vue-i18n'
import { currentRequestContext } from '@/utils/requestContext'
import id from './locales/id'
import en from './locales/en'
import zh from './locales/zh'
import ar from './locales/ar'

export const SUPPORTED_LOCALES = [
  { code: 'id', name: 'Bahasa Indonesia', dir: 'ltr' },
  { code: 'en', name: 'English', dir: 'ltr' },
  { code: 'zh', name: '中文', dir: 'ltr' },
  { code: 'ar', name: 'العربية', dir: 'rtl' },
] as const

export type LocaleCode = (typeof SUPPORTED_LOCALES)[number]['code']

export function localeDir(code: string): 'ltr' | 'rtl' {
  return SUPPORTED_LOCALES.find((l) => l.code === code)?.dir ?? 'ltr'
}

export function isSupportedLocale(code: unknown): code is LocaleCode {
  return typeof code === 'string' && SUPPORTED_LOCALES.some((l) => l.code === code)
}

/** Builds a vue-i18n instance with the shared catalogues. Used per request on the Nuxt server. */
export function createAppI18n(locale: LocaleCode = 'id') {
  return createI18n({
    legacy: false,
    locale,
    fallbackLocale: 'id',
    messages: { id, en, zh, ar },
  })
}

/** The browser / SPA instance (process-wide singleton, mutated only by the locale store on the client). */
export const i18n = createAppI18n('id')

/**
 * The instance non-component code (formatters, connectivity messages) must use: the current request's own
 * instance while server-rendering in Nuxt, otherwise the singleton above.
 */
export function currentI18n(): typeof i18n {
  return (currentRequestContext()?.i18n as typeof i18n | undefined) ?? i18n
}
