import { createI18n } from 'vue-i18n'
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

export const i18n = createI18n({
  legacy: false,
  locale: 'id',
  fallbackLocale: 'id',
  messages: { id, en, zh, ar },
})
