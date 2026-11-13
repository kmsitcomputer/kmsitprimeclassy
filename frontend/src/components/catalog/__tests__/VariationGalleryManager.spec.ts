/** REM-02/03: the gallery manager only touches its own scope, uses dedicated endpoints and re-syncs with persisted state. */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '@/api/errors'
import VariationGalleryManager from '@/components/catalog/VariationGalleryManager.vue'

const api = vi.hoisted(() => ({ uploadProductImage: vi.fn(), deleteProductImage: vi.fn(), setPrimaryProductImage: vi.fn(), reorderProductImages: vi.fn() }))
vi.mock('@/api/catalog', () => api)

const img = (id: number, variation: number | null, primary = false, order = id) => ({ id, product_id: 1, product_variation_id: variation, url: `/i/${id}.jpg`, is_primary: primary, sort_order: order })
const mountG = (variationId: number | null, images: ReturnType<typeof img>[]) =>
  mount(VariationGalleryManager, { props: { productId: 1, variationId, images, title: variationId ? 'Gambar varian Merah' : 'Galeri produk' }, global: { stubs: { AppIcon: true } } })
const file = (name = 'a.jpg') => new File(['x'], name, { type: 'image/jpeg' })
async function pick(w: ReturnType<typeof mount>, files: File[]) {
  const input = w.get('[data-testid="gallery-input"]')
  Object.defineProperty(input.element, 'files', { value: files, configurable: true })
  await input.trigger('change')
  await flushPromises()
}

beforeEach(() => { vi.clearAllMocks(); Object.values(api).forEach((f) => f.mockResolvedValue({})) })

describe('VariationGalleryManager', () => {
  it('uploads into ITS OWN variation gallery; the first image of an empty gallery becomes primary', async () => {
    const w = mountG(11, [])
    await pick(w, [file('1.jpg'), file('2.jpg')])
    expect(api.uploadProductImage).toHaveBeenNthCalledWith(1, 1, expect.any(File), { product_variation_id: 11, is_primary: true })
    expect(api.uploadProductImage).toHaveBeenNthCalledWith(2, 1, expect.any(File), { product_variation_id: 11, is_primary: false })
    expect(w.emitted('changed')).toHaveLength(1)
  })

  it('the parent gallery uploads with a null variation and never marks an existing gallery primary again', async () => {
    const w = mountG(null, [img(1, null, true)])
    await pick(w, [file()])
    expect(api.uploadProductImage).toHaveBeenCalledWith(1, expect.any(File), { product_variation_id: null, is_primary: false })
  })

  it('ignores non-image files', async () => {
    const w = mountG(11, [])
    await pick(w, [new File(['x'], 'a.pdf', { type: 'application/pdf' })])
    expect(api.uploadProductImage).not.toHaveBeenCalled()
  })

  it('reorders using only the ids of its own scope and sends the scope id', async () => {
    const w = mountG(11, [img(5, 11, true, 0), img(6, 11, false, 1), img(7, 11, false, 2)])
    await w.findAll('[data-testid="gallery-right"]')[0]!.trigger('click')
    await flushPromises()
    expect(api.reorderProductImages).toHaveBeenCalledWith(1, 11, [6, 5, 7])
    await w.findAll('[data-testid="gallery-left"]')[2]!.trigger('click')
    await flushPromises()
    expect(api.reorderProductImages).toHaveBeenLastCalledWith(1, 11, [5, 7, 6])
  })

  it('renders in saved order and disables impossible moves at the edges', () => {
    const w = mountG(11, [img(9, 11, false, 2), img(8, 11, true, 0), img(10, 11, false, 1)])
    expect(w.findAll('[data-testid="gallery-item"]').map((i) => i.attributes('data-image-id'))).toEqual(['8', '10', '9'])
    expect(w.findAll('[data-testid="gallery-left"]')[0]!.attributes('disabled')).toBeDefined()
    expect(w.findAll('[data-testid="gallery-right"]')[2]!.attributes('disabled')).toBeDefined()
  })

  it('make-primary and remove call the dedicated endpoints for that image only', async () => {
    const w = mountG(11, [img(5, 11, true), img(6, 11)])
    await w.get('[data-testid="gallery-make-primary"]').trigger('click')
    await flushPromises()
    expect(api.setPrimaryProductImage).toHaveBeenCalledWith(1, 6)
    await w.findAll('[data-testid="gallery-remove"]')[1]!.trigger('click')
    await flushPromises()
    expect(api.deleteProductImage).toHaveBeenCalledWith(1, 6)
    expect(w.findAll('[data-testid="gallery-primary"]')).toHaveLength(1)
  })

  it('a failed action shows the server message in context and still re-syncs from the server (no silent success)', async () => {
    api.deleteProductImage.mockRejectedValue(new ApiError('Gambar tidak dapat dihapus.', 422, null))
    const w = mountG(11, [img(5, 11, true)])
    await w.get('[data-testid="gallery-remove"]').trigger('click')
    await flushPromises()
    expect(w.get('[data-testid="gallery-error"]').text()).toContain('Gambar tidak dapat dihapus.')
    expect(w.emitted('changed')).toHaveLength(1)
    expect(w.findAll('[data-testid="gallery-item"]')).toHaveLength(1)
  })

  it('shows an empty state and keeps two managers independent', () => {
    expect(mountG(12, []).find('[data-testid="gallery-empty"]').exists()).toBe(true)
    const a = mountG(11, [img(1, 11, true)])
    const b = mountG(12, [img(2, 12, true)])
    expect(a.get('[data-testid="gallery-input"]').attributes('id')).not.toBe(b.get('[data-testid="gallery-input"]').attributes('id'))
  })
})
