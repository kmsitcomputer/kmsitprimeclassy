import { defineStore } from 'pinia'
import { isStorageHydrationDeferred } from '@/utils/storageHydration'
import { i18n, localeDir, SUPPORTED_LOCALES, type LocaleCode } from '@/i18n'

const STORAGE_KEY = 'pc-locale'

function isSupported(code: string | null): code is LocaleCode {
  return !!code && SUPPORTED_LOCALES.some((l) => l.code === code)
}

function readStoredLocale(): LocaleCode {
  if (typeof localStorage === 'undefined' || isStorageHydrationDeferred()) return 'id' // SSR: the server always renders the default locale
  try {
    const stored = localStorage.getItem(STORAGE_KEY)
    return isSupported(stored) ? stored : 'id'
  } catch {
    return 'id'
  }
}

function applyLocale(code: LocaleCode) {
  // The vue-i18n instance is a process-wide singleton: it must NEVER be mutated on the Nuxt
  // server (cross-request leakage). Every caller of applyLocale is client-only.
  if (typeof document === 'undefined') return
  ;(i18n.global.locale as { value: LocaleCode }).value = code
  document.documentElement.lang = code
  document.documentElement.dir = localeDir(code)
}

export const useLocaleStore = defineStore('locale', {
  state: () => ({
    locale: readStoredLocale(),
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
      // After SSR hydration the state holds the server default ('id'); adopt the persisted choice.
      this.locale = readStoredLocale()
      applyLocale(this.locale)
    },
  },
})
