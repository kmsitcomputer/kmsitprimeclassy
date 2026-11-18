/**
 * Optional hook (IMP-009): after the visitor switches language, the Nuxt storefront rewrites the address bar to the
 * matching `?lang=` URL (history.replaceState — no navigation, no refetch) so a copied/shared link opens in that
 * language. The Vue/Vite SPA registers nothing, so its behaviour is unchanged.
 */
let sync: ((code: string) => void) | null = null

export function setLocaleUrlSync(fn: ((code: string) => void) | null): void {
  sync = fn
}

export function syncLocaleUrl(code: string): void {
  sync?.(code)
}
