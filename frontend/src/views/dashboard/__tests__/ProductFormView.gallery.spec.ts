/** REM-02/03 in the real form: per-variation galleries are separate, create-mode variant images go to the NEW variant id, saving never touches images. */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const api = vi.hoisted(() => ({
  detail: vi.fn(), updateProduct: vi.fn(), createProduct: vi.fn(), createVariation: vi.fn(), uploadProductImage: vi.fn(),
  deleteProductImage: vi.fn(), setPrimaryProductImage: vi.fn(), reorderProductImages: vi.fn(),
}))
vi.mock('@/api/catalog', () => ({
  listCategories: vi.fn().mockResolvedValue([]), getManagedProduct: api.detail, updateVariation: vi.fn(), updateProduct: api.updateProduct,
  createProduct: api.createProduct, createVariation: api.createVariation, deleteVariation: vi.fn(), uploadProductImage: api.uploadProductImage,
  deleteProductImage: api.deleteProductImage, setPrimaryProductImage: api.setPrimaryProductImage, reorderProductImages: api.reorderProductImages,
  getProductFees: vi.fn(), setProductFees: vi.fn(), getVariationFees: vi.fn(), setVariationFees: vi.fn(),
}))
vi.mock('@/layouts/DashboardLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))
vi.mock('@/components/ui/RichTextEditor.vue', () => ({ default: { template: '<div />' } }))
import ProductFormView from '../ProductFormView.vue'

const im = (id: number, v: number | null, primary = false, order = 0) => ({ id, product_id: 1, product_variation_id: v, url: `/i/${id}.jpg`, is_primary: primary, sort_order: order })
const product = () => ({
  id: 1, name: 'Cake', slug: 'cake', has_variations: true, status: 'active', category_id: null, description: 'desc', short_description: null,
  images: [im(1, null, true), im(10, 11, true, 0), im(11, 11, false, 1), im(20, 12, true, 0)],
  variations: [
    { id: 11, sku: 'A', price: '1000', weight_grams: 100, is_active: true, label: 'Merah', attributes: { W: 'Merah' } },
    { id: 12, sku: 'B', price: '2000', weight_grams: 100, is_active: true, label: 'Biru', attributes: { W: 'Biru' } },
  ],
})
async function mountForm(path: string, role = 'agen') {
  const pinia = createPinia()
  useAuthStore(pinia).user = { id: 1, role } as never
  const router = createRouter({ history: createMemoryHistory(), routes: [
    { path: '/list', name: 'product-management', component: { template: '<div />' } },
    { path: '/edit/:id', name: 'product-edit', component: { template: '<div />' } }, { path: '/new', component: { template: '<div />' } },
  ] })
  await router.push(path)
  const w = mount(ProductFormView, { global: { plugins: [pinia, router], stubs: { AppIcon: true, PrimeFileUpload: true } } })
  await flushPromises()
  return w
}
const file = () => new File(['x'], 'a.jpg', { type: 'image/jpeg' })
async function pickIn(input: ReturnType<ReturnType<typeof mount>['get']>, files: File[]) {
  Object.defineProperty(input.element, 'files', { value: files, configurable: true })
  await input.trigger('change')
  await flushPromises()
}

beforeEach(() => {
  vi.clearAllMocks()
  api.detail.mockImplementation(async () => product())
  Object.values(api).forEach((f) => f !== api.detail && f.mockResolvedValue({}))
})

describe('edit mode: one gallery per variation', () => {
  it('renders the parent gallery and each variation gallery with ONLY their own images', async () => {
    const w = await mountForm('/edit/1')
    const ids = (key: string) => w.findAll(`[data-testid="gallery-${key}"] [data-testid="gallery-item"]`).map((i) => i.attributes('data-image-id'))
    expect(ids('parent')).toEqual(['1'])
    expect(ids('11')).toEqual(['10', '11'])
    expect(ids('12')).toEqual(['20'])
  })

  it('uploading to variation B passes variation B id and reloads the persisted state; A is not called', async () => {
    const w = await mountForm('/edit/1')
    await pickIn(w.get('[data-testid="gallery-12"] [data-testid="gallery-input"]'), [file()])
    expect(api.uploadProductImage).toHaveBeenCalledExactlyOnceWith(1, expect.any(File), { product_variation_id: 12, is_primary: false })
    expect(api.detail).toHaveBeenCalledTimes(2)
  })

  it('reordering variation A sends only A ids', async () => {
    const w = await mountForm('/edit/1')
    await w.get('[data-testid="gallery-11"] [data-testid="gallery-right"]').trigger('click')
    await flushPromises()
    expect(api.reorderProductImages).toHaveBeenCalledExactlyOnceWith(1, 11, [11, 10])
  })

  it('saving the product form never calls any image endpoint (unchanged images stay untouched)', async () => {
    const w = await mountForm('/edit/1')
    api.updateProduct.mockResolvedValue(product())
    await w.get('form').trigger('submit')
    await flushPromises()
    for (const f of [api.uploadProductImage, api.deleteProductImage, api.setPrimaryProductImage, api.reorderProductImages]) expect(f).not.toHaveBeenCalled()
  })
})

describe('create mode: variant images land in the new variant gallery', () => {
  it('uploads each staged variant image to the id returned by the server, first = primary', async () => {
    api.createProduct.mockResolvedValue({ id: 50 })
    api.createVariation.mockResolvedValueOnce({ id: 501 }).mockResolvedValueOnce({ id: 502 })
    const w = await mountForm('/new')
    await w.get('input[type="text"]').setValue('Roti')
    // variant A with 2 images, variant B with 1 image
    for (const [sku, files] of [['VA', [file(), file()]], ['VB', [file()]]] as const) {
      const btn = w.findAll('button').find((b) => b.text().includes('Tambah Varian'))!
      if (!w.find('#new-variant-images').exists()) await btn.trigger('click')
      const inputs = w.findAll('input[type="text"]')
      await inputs.find((i) => i.element.getAttribute('placeholder') === null && (i.element as HTMLInputElement).value === '' && i.element.closest('.rounded-xl.bg-stone-50'))!.setValue(sku)
      await w.find('input[placeholder="Nama atribut"]').setValue('Rasa')
      await w.find('input[placeholder="Nilai"]').setValue(sku)
      await pickIn(w.get('#new-variant-images'), [...files])
      await w.findAll('button').find((b) => b.text() === 'Tambahkan ke Daftar')!.trigger('click')
    }
    await w.get('form').trigger('submit')
    await flushPromises()
    const calls = api.uploadProductImage.mock.calls.map((c) => [c[2].product_variation_id, c[2].is_primary])
    expect(calls).toEqual([[501, true], [501, false], [502, true]])
  })
})
