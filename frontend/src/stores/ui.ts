import { defineStore } from 'pinia'

type ThemePreference = 'light' | 'dark' | 'system'

const STORAGE_KEY = 'pc-theme'

function applyTheme(pref: ThemePreference) {
  const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches
  const shouldBeDark = pref === 'dark' || (pref === 'system' && prefersDark)
  document.documentElement.classList.toggle('dark', shouldBeDark)
}

export const useUiStore = defineStore('ui', {
  state: () => ({
    theme: (localStorage.getItem(STORAGE_KEY) as ThemePreference | null) ?? 'system',
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
    initTheme() {
      applyTheme(this.theme)
    },
  },
})
