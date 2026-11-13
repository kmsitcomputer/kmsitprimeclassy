import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useCartStore } from '@/stores/cart'
import { i18n } from '@/i18n'
import { ApiError } from '@/api/client'
const { detail, listing } = vi.hoisted(() => ({ detail: vi.fn(), listing: vi.fn() }))
vi.mock('@/api/catalog', () => ({ getProduct: detail, listRelatedProducts: vi.fn().mockResolvedValue([]), listCategories: vi.fn().mockResolvedValue([]), listProducts: listing }))
vi.mock('@/layouts/ShopLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))
import ProductDetailView from '../ProductDetailView.vue'
import ProductListingView from '../ProductListingView.vue'

// Test-only access to <script setup> internals; the shape is intentionally open.
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type SetupState = Record<string, any>
const product = (available: number) => ({ id: 1, slug: 'cake', name: 'Cake', status: 'active', has_variations: false, base_price: '1000', weight_grams: 10, images: [], variations: [], stock: { available, in_stock: available > 0 } })
async function setup(list = false) {
  const pinia = createPinia()
  const auth = useAuthStore(pinia)
  auth.user = { id: 3, role: 'sales-kurir-sub' } as typeof auth.user
  const cart = useCartStore(pinia)
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', name: 'products', component: { template: '<div />' } }] })
  await router.push('/')
  const global = { plugins: [pinia, router, i18n], stubs: { AppIcon: true, ProductCard: true } }
  const w = list ? mount(ProductListingView, { global }) : mount(ProductDetailView, { props: { slug: 'cake' }, global })
  await flushPromises()
  return { w, cart, state: (w.vm as unknown as { $: { setupState: SetupState } }).$.setupState }
}
beforeEach(() => {
  localStorage.clear(); sessionStorage.clear(); vi.clearAllMocks()
  detail.mockImplementation(async (_slug, source) => product(source === 'sub' ? 10 : 0))
  listing.mockImplementation(async (params) => ({ products: [product(params.stock_source === 'sub' ? 10 : 0)], meta: { total: 1, current_page: 1, last_page: 1 } }))
})
describe('REM-09 source-aware catalogue', () => {
  it('allows Sub-only inventory into the same cart and removes obsolete Agent ceilings on source switch', async () => {
    const { w, cart, state } = await setup()
    expect(state.canAddToCart).toBe(false)
    await w.find('input[value="sub"]').setValue()
    await flushPromises()
    expect(detail).toHaveBeenLastCalledWith('cake', 'sub')
    expect(state.canAddToCart).toBe(true)
    state.quantity = 7
    state.addToCart()
    expect(cart.lines[0]).toMatchObject({ quantity: 7, stockLimit: 10 })
    await w.find('input[value="agent"]').setValue()
    expect(cart.lines[0]!.stockLimit).toBeNull()
    expect(cart.lines[0]!.quantity).toBe(7)
  })
  it('ignores a late response for a former stock source', async () => {
    const { w, state } = await setup()
    let resolveSub!: (value: ReturnType<typeof product>) => void
    detail.mockImplementationOnce(() => new Promise(resolve => { resolveSub = resolve }))
    await w.find('input[value="sub"]').setValue()
    await w.find('input[value="agent"]').setValue()
    await flushPromises()
    resolveSub(product(99))
    await flushPromises()
    expect(state.stockQuantity).toBe(0)
    expect(state.canAddToCart).toBe(false)
  })
  it('surfaces source errors without reusing availability from the previous source', async () => {
    const { w, state } = await setup()
    detail.mockRejectedValueOnce(new ApiError('Sub Location tidak tersedia.', 422, null))
    await w.find('input[value="sub"]').setValue()
    await flushPromises()
    expect(w.text()).toContain('Sub Location tidak tersedia.')
    expect(state.canAddToCart).toBe(false)
  })
  it('refreshes list availability using the same order-wide source', async () => {
    const { w } = await setup(true)
    await w.find('input[value="sub"]').setValue()
    await flushPromises()
    expect(listing.mock.lastCall![0]).toMatchObject({ stock_source: 'sub', page: 1 })
  })
})
