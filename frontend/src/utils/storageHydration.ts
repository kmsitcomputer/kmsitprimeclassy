/**
 * Browser-storage hydration switch (IMP-009, Nuxt storefront only).
 *
 * The Vue/Vite SPA never touches this: the flag stays `false` and the stores read localStorage when they are
 * created, exactly as before. The Nuxt storefront turns it ON before any store exists (on server AND client) so
 * the first client render is byte-identical to the server HTML, then turns it OFF after hydration and calls the
 * stores' hydrate/load actions to adopt the visitor's persisted theme, locale, cart and wishlist.
 */
let deferred = false

export function deferStorageHydration(value: boolean): void {
  deferred = value
}

export function isStorageHydrationDeferred(): boolean {
  return deferred
}
