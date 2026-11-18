/**
 * Request-scoped context hook (IMP-009, Nuxt storefront SSR only).
 *
 * On the Nuxt SERVER many requests render concurrently inside one Node process, so anything locale-dependent
 * must come from the CURRENT request, never from a module-level singleton. The Nuxt plugin registers a resolver
 * (backed by Nuxt's async context); the shared code asks it. In the browser and in the Vue/Vite SPA no resolver
 * is registered and every helper falls back to its normal single-user behaviour — nothing changes there.
 */
export interface RequestContext {
  /** Locale resolved for this request (validated against the supported + backend-active set). */
  locale: string
  /** The request's own vue-i18n composer instance. */
  i18n: unknown
}

type Resolver = () => RequestContext | undefined

let resolver: Resolver | null = null

export function setRequestContextResolver(fn: Resolver | null): void {
  resolver = fn
}

export function currentRequestContext(): RequestContext | undefined {
  if (!resolver) return undefined
  try {
    return resolver()
  } catch {
    return undefined // outside a request (e.g. module init): behave like the single-user default
  }
}
