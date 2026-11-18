import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { deferStorageHydration } from '../storageHydration'
import { useCartStore } from '@/stores/cart'
import { useLocaleStore } from '@/stores/locale'
import { useUiStore } from '@/stores/ui'
import { useWishlistStore } from '@/stores/wishlist'

const line = { key: '1:base', productId: 1, variationId: null, productSlug: 's', name: 'Red Velvet', variationLabel: null, unitPrice: 1000, image: null, quantity: 2, stockLimit: 5 }

describe('stores + browser storage (Nuxt hydration contract)', () => {
  beforeEach(() => {
    localStorage.clear()
    sessionStorage.clear()
    localStorage.setItem('pc-theme', 'dark')
    localStorage.setItem('pc-locale', 'en')
    localStorage.setItem('pc-cart', JSON.stringify([line]))
    localStorage.setItem('pc-wishlist', JSON.stringify([{ productId: 9 }]))
    setActivePinia(createPinia())
    deferStorageHydration(false)
  })

  it('SPA mode (default): stores read persisted state when created, exactly as before', () => {
    expect(useUiStore().theme).toBe('dark')
    expect(useLocaleStore().locale).toBe('en')
    expect(useCartStore().count).toBe(2)
    expect(useWishlistStore().count).toBe(1)
  })

  it('Nuxt mode: stores start from the server-identical defaults, then adopt storage via the hydrate actions', () => {
    deferStorageHydration(true)
    const ui = useUiStore(), locale = useLocaleStore(), cart = useCartStore(), wish = useWishlistStore()
    expect([ui.theme, locale.locale, cart.count, wish.count]).toEqual(['system', 'id', 0, 0])

    deferStorageHydration(false) // what plugins/90.client-init.client.ts does after hydration
    ui.loadStoredTheme(); locale.initLocale(); cart.hydrateFromStorage(); wish.hydrateFromStorage()
    expect([ui.theme, locale.locale, cart.count, wish.count]).toEqual(['dark', 'en', 2, 1])
    locale.setLocale('id') // restore the shared singleton for other specs
  })
})
