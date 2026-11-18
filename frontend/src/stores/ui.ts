import { defineStore } from 'pinia'
import { isStorageHydrationDeferred } from '@/utils/storageHydration'

type ThemePreference = 'light' | 'dark' | 'system'

const STORAGE_KEY = 'pc-theme'

function readStoredTheme(): ThemePreference | null {
  if (typeof localStorage === 'undefined' || isStorageHydrationDeferred()) return null // SSR / pre-hydration (Nuxt storefront): no storage on the server
  try {
    return localStorage.getItem(STORAGE_KEY) as ThemePreference | null
  } catch {
    return null
  }
}

function applyTheme(pref: ThemePreference) {
  if (typeof window === 'undefined') return
  const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches
  const shouldBeDark = pref === 'dark' || (pref === 'system' && prefersDark)
  document.documentElement.classList.toggle('dark', shouldBeDark)
}

export const useUiStore = defineStore('ui', {
  state: () => ({
    theme: (readStoredTheme() ?? 'system') as ThemePreference,
    mobileFilterOpen: false,
    mobileMenuOpen: false,
  }),
  actions: {
    setTheme(pref: ThemePreference) {
      this.theme = pref
      try {
        localStorage.setItem(STORAGE_KEY, pref)
      } catch {
        /* non-fatal */
      }
      applyTheme(pref)
    },
    toggleTheme() {
      const effectiveIsDark = document.documentElement.classList.contains('dark')
      this.setTheme(effectiveIsDark ? 'light' : 'dark')
    },
    /** Re-reads the persisted preference (used after SSR hydration, where state starts at the server default). */
    loadStoredTheme() {
      const stored = readStoredTheme()
      if (stored) this.theme = stored
    },
    initTheme() {
      applyTheme(this.theme)
    },
  },
})
