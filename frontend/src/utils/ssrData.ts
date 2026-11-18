import { inject, type InjectionKey } from 'vue'

/**
 * SSR data seam (IMP-009).
 *
 * Public storefront views load their data in onMounted — fine for the Vue/Vite SPA, but a
 * crawler (or a first paint) then sees only a skeleton. In the Nuxt storefront the views must be
 * rendered WITH data on the server and hydrate with the same data. This tiny provider is the only
 * coupling: the SPA never provides it (every call falls through to a plain fetch, behaviour
 * unchanged); the Nuxt storefront provides an implementation backed by the Nuxt payload.
 *
 * - `get(key)`  → data the server already loaded, available synchronously ONLY while hydrating
 *                 (so the first client render is identical to the server HTML). Otherwise undefined.
 * - `failed(key)` → the server's load of `key` FAILED (e.g. 404); same hydration-only rule, so the client first-renders
 *                 the same "not found / error" state the server rendered instead of a loading skeleton.
 * - `load(key, fn)` → run `fn`; on the Nuxt server the result is stored in the payload (and de-duplicated
 *                 per request when several components/wrappers ask for the same key).
 *
 * Only PUBLIC, anonymous data may ever go through this seam: the server has no cookies and never
 * receives the visitor's session.
 */
export interface SsrDataProvider {
  get<T>(key: string): T | undefined
  failed(key: string): SsrFailure | undefined
  load<T>(key: string, fetcher: () => Promise<T>): Promise<T>
}

/** What survives in the payload about a failed server-side load. */
export interface SsrFailure {
  status: number
  message: string
}

export const SSR_DATA: InjectionKey<SsrDataProvider> = Symbol('pc-ssr-data')

const passthrough: SsrDataProvider = {
  get: () => undefined,
  failed: () => undefined,
  load: (_key, fetcher) => fetcher(),
}

export function useSsrData(): SsrDataProvider {
  return inject(SSR_DATA, passthrough)
}
