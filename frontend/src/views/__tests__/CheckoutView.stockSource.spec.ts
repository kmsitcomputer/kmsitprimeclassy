import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useCartStore } from '@/stores/cart'
import { i18n } from '@/i18n'
const { create } = vi.hoisted(() => ({ create: vi.fn() }))
vi.mock('@/api/orders', () => ({ createOrder: create, submitBankTransferProof: vi.fn(), getOrder: vi.fn() }))
vi.mock('@/api/checkout', () => ({ getCheckoutSteps: vi.fn().mockResolvedValue({ steps: [{ key: 'review', active: true }], payment_methods: [], on_behalf_of_konsumen: false }), quoteCheckout: vi.fn().mockResolvedValue({ warnings: [], items: [], shipping: null, subtotal: 2000, total: 2000 }), getCourierOptions: vi.fn().mockResolvedValue([]), locateByGps: vi.fn() }))
vi.mock('@/api/addresses', () => ({ listAddresses: vi.fn().mockResolvedValue([]), createAddress: vi.fn() }))
vi.mock('@/api/regions', () => ({ listProvinces: vi.fn().mockResolvedValue([]), listRegencies: vi.fn().mockResolvedValue([]), listDistricts: vi.fn().mockResolvedValue([]), listVillages: vi.fn().mockResolvedValue([]) }))
vi.mock('@/api/users', () => ({ listUsers: vi.fn().mockResolvedValue({ items: [] }) }))
vi.mock('@/layouts/ShopLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))
vi.mock('@/components/checkout/AddressMapPicker.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/maps/GoogleMapView.vue', () => ({ default: { template: '<div />' } }))
import CheckoutView from '../CheckoutView.vue'

// Test-only access to <script setup> internals; the shape is intentionally open.
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type SetupState = Record<string, any>

async function setup(role = 'sales-kurir-sub') {
  const pinia = createPinia()
  const auth = useAuthStore(pinia)
  auth.user = { id: 3, role } as typeof auth.user
  const cart = useCartStore(pinia)
  cart.add({ key: '1:base', productId: 1, variationId: null, productSlug: 'cake', name: 'Cake', variationLabel: null, unitPrice: 1000, image: null, stockLimit: null }, 2)
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }] })
  await router.push('/')
  const w = mount(CheckoutView, { global: { plugins: [pinia, router, i18n], stubs: { AppIcon: true } } })
  await flushPromises()
  // Setup-state access follows the existing CMS component tests; source selection itself uses the rendered radios.
  const state = (w.vm as unknown as { $: { setupState: SetupState } }).$.setupState
  state.selectedPaymentCode = 'cod'
  return { w, cart, state }
}
beforeEach(() => { localStorage.clear(); sessionStorage.clear(); create.mockReset() })
describe('REM-09 order-wide cart and checkout source', () => {
  it.each(['agent', 'sub'])('submits the selected %s source and preserves it on failure', async source => {
    const { w, cart, state } = await setup()
    await w.find(`input[type="radio"][value="${source}"]`).setValue()
    create.mockRejectedValue(new Error('Stock unavailable'))
    await state.placeOrder()
    expect(create.mock.calls[0]![0].stockSource).toBe(source)
    expect(create.mock.calls[0]![0].items).toEqual([{ product_id: 1, product_variation_id: null, quantity: 2 }])
    expect(cart.stockSourceFor(3)).toBe(source)
    expect(cart.lines).toHaveLength(1)
    expect(cart.stockSourceFor(4)).toBe('agent')
  })
  it('restores one source for the same account across checkout mounts and clears it with the cart', async () => {
    const { w, cart } = await setup()
    await w.find('input[type="radio"][value="sub"]').setValue()
    const restored = useCartStore(createPinia())
    expect(restored.stockSourceFor(3)).toBe('sub')
    cart.clear()
    expect(cart.stockSourceFor(3)).toBe('agent')
    expect(sessionStorage.getItem('pc-cart-stock-source')).toBeNull()
  })
  it('hides the selector for other roles and omits a source override', async () => {
    const { w, state } = await setup('konsumen')
    expect(w.find('[data-testid="stock-source"]').exists()).toBe(false)
    create.mockRejectedValue(new Error('test'))
    await state.placeOrder()
    expect(create.mock.calls[0]![0].stockSource).toBeNull()
  })
})
