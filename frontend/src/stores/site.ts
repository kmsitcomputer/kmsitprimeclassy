import { defineStore } from 'pinia'
import { getPublicSettings } from '@/api/settings'

/**
 * index.html hard-codes <link rel="icon" href="/favicon.ico"> as a static
 * fallback (so the tab never shows a broken-image icon before this loads) —
 * this swaps it to the uploaded favicon_url once settings arrive. Reuses the
 * existing <link> tag rather than injecting a new one so the browser doesn't
 * end up with two conflicting icon links.
 */
function applyFavicon(url: string | null) {
  if (!url) return
  const link = document.querySelector<HTMLLinkElement>("link[rel~='icon']")
  if (link) link.href = url
}

/** Site identity (logo, name) — loaded once at app boot, read by the header/dashboard chrome instead of a hard-coded brand name/wordmark. */
export const useSiteStore = defineStore('site', {
  state: () => ({
    siteTitle: 'Prime Classy',
    logoUrl: null as string | null,
    faviconUrl: null as string | null,
    loaded: false,
  }),
  actions: {
    async load() {
      try {
        const settings = await getPublicSettings()
        this.siteTitle = settings.site_title || 'Prime Classy'
        this.logoUrl = settings.logo_url
        this.faviconUrl = settings.favicon_url
        applyFavicon(this.faviconUrl)
      } catch {
        // Non-fatal — the header/dashboard chrome falls back to the default text name.
      } finally {
        this.loaded = true
      }
    },
  },
})
