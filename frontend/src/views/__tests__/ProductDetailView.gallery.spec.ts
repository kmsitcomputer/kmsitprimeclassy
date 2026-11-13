import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useCartStore } from '@/stores/cart'
import { i18n } from '@/i18n'
const { getProduct, listRelatedProducts } = vi.hoisted(() => ({ getProduct: vi.fn(), listRelatedProducts: vi.fn() }))
vi.mock('@/api/catalog', () => ({ getProduct, listRelatedProducts }))
vi.mock('@/layouts/ShopLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))
vi.mock('@/components/shop/StockSourceSelector.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/shop/ProductCard.vue', () => ({ default: { template: '<div />' } }))
import ProductDetailView from '../ProductDetailView.vue'

// Test-only access to <script setup> internals; the shape is intentionally open.
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type SetupState = Record<string, any>

const VARIATION = (id: number, label: string) => ({ id, sku: `SKU-${id}`, label, price: '15000', weight_grams: 100, is_active: true, stock: { available: 5, in_stock: true, login_to_view: false } })

function productWith(images: Array<Partial<Record<string, unknown>>>) {
  return {
    id: 1,
    name: 'Cake Box',
    slug: 'cake-box',
    description: null,
    short_description: null,
    category: { id: 9, name: 'Kue', slug: 'kue' },
    has_variations: true,
    base_price: null,
    weight_grams: 200,
    status: 'published',
    images: images.map((m, i) => ({ id: i + 1, product_id: 1, url: `/img/${i + 1}.jpg`, is_primary: m.isPrimary ?? false, sort_order: i, ...m })),
    variations: [VARIATION(11, 'Merah'), VARIATION(12, 'Biru')],
    stock: { available: 5, in_stock: true, login_to_view: false },
  }
}

async function setup(product: unknown) {
  getProduct.mockResolvedValue(product)
  listRelatedProducts.mockResolvedValue([])
  const pinia = createPinia()
  const auth = useAuthStore(pinia)
  auth.user = { id: 7, role: 'konsumen', name: 'Uji' } as typeof auth.user
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }] })
  await router.push('/')
  const w = mount(ProductDetailView, {
    props: { slug: String((product as { slug: string }).slug) },
    global: { plugins: [pinia, router, i18n], stubs: { AppIcon: true, StockBadge: true, AppButton: true, RouterLink: true } },
  })
  await flushPromises()
  return { w, cart: useCartStore(pinia) }
}

beforeEach(() => {
  localStorage.clear()
  sessionStorage.clear()
  getProduct.mockReset()
  listRelatedProducts.mockReset()
})

describe('REM-02 storefront variation gallery switching', () => {
  it('shows the auto-selected variation own gallery, not the parent or sibling gallery', async () => {
    const product = productWith([
      { product_variation_id: null, isPrimary: true },
      { product_variation_id: null },
      { product_variation_id: 11, isPrimary: true },
      { product_variation_id: 12 },
    ])
    const { w } = await setup(product)
    // First active variation (Merah, id 11) is auto-selected on load.
    expect(w.vm.$).toBeTruthy()
    expect(w.findAll('[data-testid="gallery-thumb"]').length).toBe(0) // single own image -> no thumbnails
    expect(w.find('img[alt="Cake Box"]').attributes('src')).toBe('/img/3.jpg')
  })

  it('switches the gallery when another variation is selected and resets the viewer index', async () => {
    const product = productWith([
      { product_variation_id: null, isPrimary: true },
      { product_variation_id: 11, isPrimary: true },
      { product_variation_id: 11 },
      { product_variation_id: 12 },
    ])
    const { w } = await setup(product)
    // Merah has two own images; view the second thumbnail.
    const thumbs = w.findAll('[data-testid="gallery-thumb"]')
    expect(thumbs.length).toBe(2)
    await thumbs[1]!.trigger('click')
    expect(w.find('img[alt="Cake Box"]').attributes('src')).toBe('/img/3.jpg')
    // Switching variation must reset the index, never keep a stale offset.
    const variationButtons = w.findAll('button').filter((b) => b.text() === 'Biru')
    await variationButtons[0]!.trigger('click')
    expect(w.find('img[alt="Cake Box"]').attributes('src')).toBe('/img/4.jpg')
    // And back: first image of Merah again, not the previously viewed second.
    const merah = w.findAll('button').filter((b) => b.text() === 'Merah')
    await merah[0]!.trigger('click')
    expect(w.find('img[alt="Cake Box"]').attributes('src')).toBe('/img/2.jpg')
  })

  it('falls back to the parent gallery when the selected variation has no own images', async () => {
    const product = productWith([
      { product_variation_id: null, isPrimary: true },
      { product_variation_id: null },
      { product_variation_id: 11, isPrimary: true },
    ])
    const { w } = await setup(product)
    expect(w.find('img[alt="Cake Box"]').attributes('src')).toBe('/img/3.jpg')
    const biru = w.findAll('button').filter((b) => b.text() === 'Biru')
    await biru[0]!.trigger('click')
    expect(w.find('img[alt="Cake Box"]').attributes('src')).toBe('/img/1.jpg')
    expect(w.findAll('[data-testid="gallery-thumb"]').length).toBe(2)
  })

  it('shows the empty state when the selected variation has neither own nor parent images', async () => {
    const product = productWith([
      { product_variation_id: 11, isPrimary: true },
      { product_variation_id: 11 },
    ])
    const { w } = await setup(product)
    const biru = w.findAll('button').filter((b) => b.text() === 'Biru')
    await biru[0]!.trigger('click')
    expect(w.find('img[alt="Cake Box"]').exists()).toBe(false)
    expect(w.findAll('[data-testid="gallery-thumb"]').length).toBe(0)
  })

  it('uses the variation gallery image for the cart line thumbnail', async () => {
    const product = productWith([
      { product_variation_id: null, isPrimary: true },
      { product_variation_id: 12, isPrimary: true },
    ])
    const { w, cart } = await setup(product)
    const biru = w.findAll('button').filter((b) => b.text() === 'Biru')
    await biru[0]!.trigger('click')
    const state = (w.vm as unknown as { $: { setupState: SetupState } }).$.setupState
    state.addToCart()
    expect(cart.lines).toHaveLength(1)
    expect(cart.lines[0]!.image).toBe('/img/2.jpg')
    expect(cart.lines[0]!.variationId).toBe(12)
  })
})

/** REM-02 gallery → variation (bidirectional sync). Real DOM events; one image belongs to exactly one variation. */
describe('REM-02 gallery thumbnail selects its variation', () => {
  const V = (id: number, label: string, price: string, stock: number, active = true) => ({ id, sku: `SKU-${id}`, label, price, weight_grams: 100, is_active: active, stock: { available: stock, in_stock: stock > 0, login_to_view: false } })
  function three(images: Array<Record<string, unknown>>, variations = [V(11, 'Merah', '10000', 5), V(12, 'Biru', '20000', 5), V(13, 'Hijau', '30000', 0)]) {
    return { ...productWith(images), variations }
  }
  const sel = (w: ReturnType<typeof mount>) => w.findAll('button').filter((b) => b.attributes('aria-pressed') === 'true').map((b) => b.text())
  const main = (w: ReturnType<typeof mount>) => w.find('img[alt="Cake Box"]').attributes('src')

  it('offers one thumbnail per OTHER variation with images and selects it on click (A → B → C → A)', async () => {
    const { w } = await setup(three([
      { product_variation_id: 11, isPrimary: true }, { product_variation_id: 12, isPrimary: true }, { product_variation_id: 13, isPrimary: true },
    ]))
    expect(sel(w)).toEqual(['Merah'])
    expect(w.findAll('[data-testid^="variant-thumb-"]').map((t) => t.attributes('data-testid'))).toEqual(['variant-thumb-12', 'variant-thumb-13'])

    await w.get('[data-testid="variant-thumb-12"]').trigger('click')
    expect(sel(w)).toEqual(['Biru'])
    expect(main(w)).toBe('/img/2.jpg')
    expect(w.text()).toContain('20.000') // price follows the canonical selected variation
    await w.get('[data-testid="variant-thumb-13"]').trigger('click')
    expect(sel(w)).toEqual(['Hijau'])
    expect(main(w)).toBe('/img/3.jpg')
    await w.get('[data-testid="variant-thumb-11"]').trigger('click')
    expect(sel(w)).toEqual(['Merah'])
    expect(main(w)).toBe('/img/1.jpg')
    expect(w.text()).toContain('10.000')
  })

  it('add-to-cart uses the variation chosen through the gallery (id, label, price, own image)', async () => {
    const { w, cart } = await setup(three([{ product_variation_id: 11, isPrimary: true }, { product_variation_id: 12, isPrimary: true }]))
    await w.get('[data-testid="variant-thumb-12"]').trigger('click')
    ;(w.vm as unknown as { $: { setupState: SetupState } }).$.setupState.addToCart()
    expect(cart.lines).toHaveLength(1)
    expect(cart.lines[0]).toMatchObject({ variationId: 12, variationLabel: 'Biru', unitPrice: 20000, image: '/img/2.jpg' })
  })

  it('an out-of-stock variation is selectable via its image but cannot be added (no fabricated stock)', async () => {
    const { w, cart } = await setup(three([{ product_variation_id: 11, isPrimary: true }, { product_variation_id: 13, isPrimary: true }]))
    await w.get('[data-testid="variant-thumb-13"]').trigger('click')
    expect(sel(w)).toEqual(['Hijau'])
    ;(w.vm as unknown as { $: { setupState: SetupState } }).$.setupState.addToCart()
    expect(cart.lines).toHaveLength(0)
  })

  it('rapid switching never leaves stale state: the final selection, gallery and price always agree', async () => {
    const { w } = await setup(three([
      { product_variation_id: 11, isPrimary: true }, { product_variation_id: 12, isPrimary: true }, { product_variation_id: 13, isPrimary: true },
    ]))
    for (const id of [12, 13, 11, 13, 12, 11, 12]) {
      await w.get(`[data-testid="variant-thumb-${id}"]`).trigger('click').catch(async () => w.get(`[data-testid="variation-${id}"]`).trigger('click'))
    }
    expect(sel(w)).toEqual(['Biru'])
    expect(main(w)).toBe('/img/2.jpg')
    expect(w.text()).toContain('20.000')
  })

  it('variation buttons and thumbnails stay in sync in both directions', async () => {
    const { w } = await setup(three([{ product_variation_id: 11, isPrimary: true }, { product_variation_id: 12, isPrimary: true }]))
    await w.get('[data-testid="variation-12"]').trigger('click')
    expect(main(w)).toBe('/img/2.jpg')
    expect(w.find('[data-testid="variant-thumb-12"]').exists()).toBe(false) // the active variation is shown in the main viewer
    expect(w.find('[data-testid="variant-thumb-11"]').exists()).toBe(true)
    await w.get('[data-testid="variant-thumb-11"]').trigger('click')
    expect(sel(w)).toEqual(['Merah'])
  })

  it('shows the primary image of the selected variation first, even when it is not first in order', async () => {
    const { w } = await setup(three([{ product_variation_id: 11 }, { product_variation_id: 11, isPrimary: true }]))
    expect(main(w)).toBe('/img/2.jpg')
  })

  it('does not offer thumbnails for variations without images or inactive variations', async () => {
    const { w } = await setup(three([{ product_variation_id: 11, isPrimary: true }, { product_variation_id: 13, isPrimary: true }],
      [V(11, 'Merah', '10000', 5), V(12, 'Biru', '20000', 5), V(13, 'Hijau', '30000', 5, false)]))
    expect(w.findAll('[data-testid^="variant-thumb-"]')).toHaveLength(0)
  })

  it('products without variations keep the plain gallery (no variation logic)', async () => {
    const simple = { ...productWith([{ product_variation_id: null, isPrimary: true }, { product_variation_id: null }]), has_variations: false, variations: [], base_price: '9000' }
    const { w } = await setup(simple)
    expect(w.findAll('[data-testid^="variant-thumb-"]')).toHaveLength(0)
    expect(w.findAll('[data-testid="gallery-thumb"]')).toHaveLength(2)
  })
})
