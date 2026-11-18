import { defineStore } from 'pinia'
import { deferredInitialLocale, isStorageHydrationDeferred } from '@/utils/storageHydration'
import { i18n, localeDir, SUPPORTED_LOCALES, type LocaleCode } from '@/i18n'

const STORAGE_KEY = 'pc-locale'
/** Mirrors the choice so the Nuxt storefront can render the right language on the server (read-only for the SPA). */
export const LOCALE_COOKIE = 'pc-locale'

function writeLocaleCookie(code: LocaleCode) {
  if (typeof document === 'undefined') return
  try {
    document.cookie = `${LOCALE_COOKIE}=${code}; Path=/; Max-Age=31536000; SameSite=Lax`
  } catch {
    /* cookies blocked — non-fatal, localStorage still holds the choice */
  }
}

function isSupported(code: string | null): code is LocaleCode {
  return !!code && SUPPORTED_LOCALES.some((l) => l.code === code)
}

function readStoredLocale(): LocaleCode {
  if (isStorageHydrationDeferred()) {
    // Nuxt: start from the locale the server resolved for this request (validated), never from browser storage.
    const initial = deferredInitialLocale()
    return isSupported(initial) ? initial : 'id'
  }
  if (typeof localStorage === 'undefined') return 'id' // SSR without a request context
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
      writeLocaleCookie(code)
      try {
        localStorage.setItem(STORAGE_KEY, code)
      } catch {
        /* non-fatal */
      }
      applyLocale(code)
    },
    /**
     * @param explicit a locale chosen by the URL (`?lang=`) on this very navigation: it wins over storage and is persisted.
     */
    initLocale(explicit?: LocaleCode) {
      // After SSR hydration the state holds the server-resolved locale; adopt the explicit URL choice, else the persisted one.
      if (explicit) {
        this.setLocale(explicit)
        return
      }
      this.locale = readStoredLocale()
      writeLocaleCookie(this.locale)
      applyLocale(this.locale)
    },
  },
})
