/**
 * Routing boundary between the Vue/Vite SPA and the Nuxt storefront (IMP-009).
 *
 * The Nuxt storefront owns ONLY these public, SEO-relevant routes (server-side
 * rendered). EVERYTHING else — dashboard, admin, auth, checkout, cart,
 * profile, orders, installer, PWA install page, print — stays in the SPA and
 * its single guard in ./index.ts. Route names are the contract; paths come
 * from ./routeDefs.ts so there is one route table.
 *
 * Deployment must route exactly these paths to the Nuxt server (see
 * docs/20-IMP009-NUXT-STOREFRONT.md); the Nuxt app hands every other path
 * back to the SPA with a full-page navigation.
 */
export const NUXT_OWNED_ROUTE_NAMES = [
  'home',
  'products',
  'product-detail',
  'categories',
  'articles',
  'article-detail',
  'page-detail',
  'store-locator',
  'not-found',
] as const

export type NuxtOwnedRouteName = (typeof NUXT_OWNED_ROUTE_NAMES)[number]

export function isNuxtOwnedRouteName(name: unknown): name is NuxtOwnedRouteName {
  return typeof name === 'string' && (NUXT_OWNED_ROUTE_NAMES as readonly string[]).includes(name)
}
