import { defineStore } from 'pinia'
import { i18n, localeDir, SUPPORTED_LOCALES, type LocaleCode } from '@/i18n'

const STORAGE_KEY = 'pc-locale'

function isSupported(code: string | null): code is LocaleCode {
  return !!code && SUPPORTED_LOCALES.some((l) => l.code === code)
}

function applyLocale(code: LocaleCode) {
  ;(i18n.global.locale as { value: LocaleCode }).value = code
  document.documentElement.lang = code
  document.documentElement.dir = localeDir(code)
}

export const useLocaleStore = defineStore('locale', {
  state: () => ({
    locale: (isSupported(localStorage.getItem(STORAGE_KEY)) ? localStorage.getItem(STORAGE_KEY) : 'id') as LocaleCode,
  }),
  actions: {
    setLocale(code: LocaleCode) {
      this.locale = code
      try {
        localStorage.setItem(STORAGE_KEY, code)
      } catch {
        /* non-fatal */
      }
      applyLocale(code)
    },
    initLocale() {
      applyLocale(this.locale)
    },
  },
})
