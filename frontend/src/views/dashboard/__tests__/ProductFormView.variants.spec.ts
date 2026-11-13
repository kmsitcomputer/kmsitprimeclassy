import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { ApiError } from '@/api/client'
const { detail, update } = vi.hoisted(() => ({ detail: vi.fn(), update: vi.fn() }))
vi.mock('@/api/catalog', () => ({
  listCategories: vi.fn().mockResolvedValue([]), getManagedProduct: detail, updateVariation: update,
  createProduct: vi.fn(), updateProduct: vi.fn(), createVariation: vi.fn(), deleteVariation: vi.fn(),
  uploadProductImage: vi.fn(), deleteProductImage: vi.fn(), setPrimaryProductImage: vi.fn(), reorderProductImages: vi.fn(),
  getProductFees: vi.fn(), setProductFees: vi.fn(), getVariationFees: vi.fn(), setVariationFees: vi.fn(),
}))
vi.mock('@/layouts/DashboardLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))
vi.mock('@/components/ui/RichTextEditor.vue', () => ({ default: { template: '<div />' } }))
import ProductFormView from '../ProductFormView.vue'

const product = () => ({ id: 1, name: 'Cake', has_variations: true, status: 'active', images: [{ id: 90, url: '/parent.jpg', product_variation_id: null, is_primary: true, sort_order: 0 }, { id: 91, url: '/small.jpg', product_variation_id: 10, is_primary: true, sort_order: 0 }], variations: [
  { id: 10, sku: 'S', price: '1000', weight_grams: 100, is_active: true, label: 'S', attributes: { Size: 'S' }, images: [{ id: 91, url: '/small.jpg' }] },
  { id: 11, sku: 'M', price: '2000', weight_grams: 200, is_active: true, label: 'M', attributes: { Size: 'M' }, images: [] },
] })
async function setup() {
  const pinia = createPinia()
  const auth = useAuthStore(pinia)
  auth.user = { id: 1, role: 'admin' } as typeof auth.user
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/list', name: 'product-management', component: { template: '<div />' } }, { path: '/edit/:id', component: { template: '<div />' } }] })
  await router.push('/edit/1')
  const w = mount(ProductFormView, { global: { plugins: [pinia, router], stubs: { AppIcon: true } } })
  await flushPromises()
  return w
}
beforeEach(() => { vi.clearAllMocks(); detail.mockImplementation(async () => product()); update.mockResolvedValue({}) })
describe('REM-08 variant attribute editing', () => {
  it('sends only the selected variant, preserving sibling and gallery data on refresh', async () => {
    const w = await setup()
    await w.findAll('button').find(b => b.text() === 'Edit')!.trigger('click')
    await w.find('input[placeholder="Nilai"]').setValue('XL')
    await w.findAll('button').find(b => b.text() === 'Simpan')!.trigger('click')
    await flushPromises()
    expect(update).toHaveBeenCalledExactlyOnceWith(1, 10, { sku: 'S', price: 1000, weight_grams: 100, is_active: true, attributes: { Size: 'XL' } })
    expect(detail).toHaveBeenCalledTimes(2)
    expect(w.text()).toContain('(M)')
    expect(w.find('img[src="/parent.jpg"]').exists()).toBe(true)
  })
  it('rejects empty attributes locally and retains the editor on API validation failure', async () => {
    const w = await setup()
    await w.findAll('button').find(b => b.text() === 'Edit')!.trigger('click')
    await w.find('input[placeholder="Nilai"]').setValue('')
    await w.findAll('button').find(b => b.text() === 'Simpan')!.trigger('click')
    expect(update).not.toHaveBeenCalled()
    expect(w.text()).toContain('minimal satu atribut')
    await w.find('input[placeholder="Nilai"]').setValue('XL')
    update.mockRejectedValue(new ApiError('Atribut tidak valid', 422, null))
    await w.findAll('button').find(b => b.text() === 'Simpan')!.trigger('click')
    await flushPromises()
    expect(w.find('input[placeholder="Nilai"]').exists()).toBe(true)
    expect(w.text()).toContain('Atribut tidak valid')
  })
  it('unchanged save omits attributes and image mutation fields', async () => {
    const w = await setup()
    await w.findAll('button').find(b => b.text() === 'Edit')!.trigger('click')
    await w.findAll('button').find(b => b.text() === 'Simpan')!.trigger('click')
    await flushPromises()
    expect(update.mock.calls[0]![2]).not.toHaveProperty('attributes')
    expect(update.mock.calls[0]![2]).not.toHaveProperty('images')
  })
})
