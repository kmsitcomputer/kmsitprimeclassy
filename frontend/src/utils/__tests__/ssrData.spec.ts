import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { describe, expect, it } from 'vitest'
import { SSR_DATA, useSsrData, type SsrDataProvider } from '../ssrData'
import { deferStorageHydration, isStorageHydrationDeferred } from '../storageHydration'

function probe(): { provider: SsrDataProvider } {
  let provider!: SsrDataProvider
  return { get provider() { return provider }, set provider(v) { provider = v } } as never
}

describe('SSR data seam', () => {
  it('without a provider (the SPA) it is a plain pass-through: no seed, no failure, fetcher always runs', async () => {
    const holder = probe()
    mount(defineComponent({ setup() { holder.provider = useSsrData(); return () => h('div') } }))
    expect(holder.provider.get('k')).toBeUndefined()
    expect(holder.provider.failed('k')).toBeUndefined()
    let calls = 0
    await holder.provider.load('k', async () => ++calls)
    await holder.provider.load('k', async () => ++calls)
    expect(calls).toBe(2)
  })

  it('uses the provider installed by the Nuxt storefront when present', () => {
    const holder = probe()
    const provided: SsrDataProvider = { get: () => 'seed' as never, failed: () => ({ status: 404, message: 'x' }), load: (_k, f) => f() }
    mount(defineComponent({ setup() { holder.provider = useSsrData(); return () => h('div') } }), { global: { provide: { [SSR_DATA as symbol]: provided } } })
    expect(holder.provider.get('k')).toBe('seed')
    expect(holder.provider.failed('k')?.status).toBe(404)
  })

  it('storage hydration is NOT deferred by default (SPA behaviour unchanged)', () => {
    expect(isStorageHydrationDeferred()).toBe(false)
    deferStorageHydration(true)
    expect(isStorageHydrationDeferred()).toBe(true)
    deferStorageHydration(false)
  })
})
