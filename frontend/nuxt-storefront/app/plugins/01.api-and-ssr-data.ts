import { configureServerApi } from '@/api/client'
import { SSR_DATA, type SsrDataProvider, type SsrFailure } from '@/utils/ssrData'
import { deferStorageHydration } from '@/utils/storageHydration'

/**
 * 1. Server only: point the shared axios client at the internal Laravel origin (private runtime config).
 * 2. Provide the SSR data seam (see src/utils/ssrData.ts) backed by the Nuxt payload.
 */
export default defineNuxtPlugin((nuxtApp) => {
  // Stores must start from server-identical defaults on BOTH sides; the client adopts browser storage after
  // hydration (plugins/90.client-init.client.ts).
  deferStorageHydration(true)
  if (import.meta.server) {
    configureServerApi(String(useRuntimeConfig().apiInternalBase))
  }

  // Per-request (plugin runs once per request on the server) — nothing here is shared between visitors.
  const inflight = new Map<string, Promise<unknown>>()
  const failures = new Map<string, unknown>() // original error objects (server only, never serialized)
  const failureKey = (key: string) => `${key}#failed`
  const data = nuxtApp.payload.data as Record<string, unknown>

  const provider: SsrDataProvider = {
    get<T>(key: string): T | undefined {
      // Only while hydrating: the first client render must equal the server HTML. Afterwards views fetch normally.
      return nuxtApp.isHydrating ? (data[key] as T | undefined) : undefined
    },
    failed(key: string): SsrFailure | undefined {
      return nuxtApp.isHydrating ? (data[failureKey(key)] as SsrFailure | undefined) : undefined
    },
    load<T>(key: string, fetcher: () => Promise<T>): Promise<T> {
      if (import.meta.client && !nuxtApp.isHydrating) return fetcher()
      if (failures.has(key)) return Promise.reject(failures.get(key))
      if (data[key] !== undefined) return Promise.resolve(data[key] as T)
      let pending = inflight.get(key) as Promise<T> | undefined
      if (!pending) {
        pending = fetcher()
          .then((value) => {
            data[key] = value
            return value
          })
          .catch((error: unknown) => {
            // Remember the failure so a second consumer of the same key (wrapper + view) neither refetches nor
            // disagrees, and so hydration can first-render the same error state the server rendered.
            failures.set(key, error)
            const status = Number((error as { status?: number })?.status ?? 0)
            data[failureKey(key)] = { status, message: String((error as Error)?.message ?? '') } satisfies SsrFailure
            throw error
          })
          .finally(() => inflight.delete(key))
        inflight.set(key, pending)
      }
      return pending
    },
  }

  nuxtApp.vueApp.provide(SSR_DATA, provider)
})
