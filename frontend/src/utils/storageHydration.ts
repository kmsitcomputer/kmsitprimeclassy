/**
 * Browser-storage hydration switch (IMP-009, Nuxt storefront only).
 *
 * The Vue/Vite SPA never touches this: the flag stays `false` and the stores read localStorage when they are
 * created, exactly as before. The Nuxt storefront turns it ON before any store exists (on server AND client) so
 * the first client render is byte-identical to the server HTML, then turns it OFF after hydration and calls the
 * stores' hydrate/load actions to adopt the visitor's persisted theme, locale, cart and wishlist.
 */
import { currentRequestContext } from '@/utils/requestContext'

let deferred = false
let deferredLocale: string | null = null

/**
 * @param initialLocale the locale the server resolved for this request (URL `?lang=` > `pc-locale` cookie >
 *   default). While deferred, the locale store starts from it on BOTH server and client so the first render matches.
 */
export function deferStorageHydration(value: boolean, initialLocale: string | null = null): void {
  deferred = value
  deferredLocale = value ? initialLocale : null
}

export function deferredInitialLocale(): string | null {
  // Server: the CURRENT request's locale (many requests share this module; a module variable would leak between them).
  // Browser: the locale the server rendered, handed over through the payload.
  return currentRequestContext()?.locale ?? deferredLocale
}

export function isStorageHydrationDeferred(): boolean {
  return deferred
}
