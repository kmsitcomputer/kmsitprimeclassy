/**
 * Gudang's handover form: the approval is Gudang's own (no Admin step), a reason is required for every short
 * line, evidence is required whenever goods are handed over, and nothing is sent when the form is invalid.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { ApiError } = vi.hoisted(() => {
  class ApiError extends Error {
    status: number
    errors: unknown
    constructor(message: string, status = 422, errors: unknown = null) {
      super(message)
      this.status = status
      this.errors = errors
    }
  }
  return { ApiError }
})
vi.mock('@/api/client', () => ({ ApiError }))
vi.mock('@/utils/apiError', () => ({ formatApiError: (e: Error) => e.message }))
vi.mock('vue-router', () => ({ useRouter: () => ({ push: vi.fn() }) }))

const api = vi.hoisted(() => ({ listWarehouseDiprosesOrders: vi.fn(), getWarehouseStockRequestByOrder: vi.fn(), submitWarehouseHandover: vi.fn() }))
vi.mock('@/api/warehouseFulfillment', () => ({ ...api }))

import OrderDiprosesView from '../OrderDiprosesView.vue'

const order = { id: 7, order_no: 'ORD-7', status: 'diproses', konsumen: { name: 'Budi' }, items: [{ id: 70, product_name: 'Kue A', fulfilled_quantity: 10, status: 'diproses', requested_delivery_date: null }] }
const stockRequest = { id: 5, request_number: 'SR-1', order_id: 7, status: 'pending', items: [
  { id: 50, order_item_id: 70, product_id: 1, product_variation_id: null, product_name: 'Kue A', variation_label: null, sku: 'A', sku_snapshot: 'A', product_image_url: null, requested_qty: 10, fulfilled_qty: 0, remaining_qty: 10, current_stock: null },
] }

const stubs = { DashboardLayout: { template: '<div><slot /></div>' }, AppIcon: true }

async function openDrawer() {
  const w = mount(OrderDiprosesView, { global: { stubs } })
  await flushPromises()
  await w.findAll('button').find((b) => b.text().includes('Setujui & Serahkan') && !b.text().includes('Koordinator'))!.trigger('click')
  await flushPromises()
  return w
}

function submitButton(w: Awaited<ReturnType<typeof openDrawer>>) {
  return w.findAll('button').find((b) => b.text().includes('Setujui & Serahkan ke Koordinator'))!
}

describe('OrderDiprosesView handover', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    api.listWarehouseDiprosesOrders.mockResolvedValue({ orders: [order], meta: { current_page: 1, last_page: 1, total: 1 } })
    api.getWarehouseStockRequestByOrder.mockResolvedValue(stockRequest)
    api.submitWarehouseHandover.mockResolvedValue({ id: 1 })
  })

  it('has no Admin approval wording anywhere', async () => {
    const w = await openDrawer()
    expect(w.text()).not.toMatch(/ke Admin|diproses Admin|oleh Admin|persetujuan Admin/i)
    expect(w.text()).toContain('Koordinator Kurir')
  })

  it('requires a reason when handing over less than outstanding', async () => {
    const w = await openDrawer()
    await w.get('input[type="number"]').setValue(6)
    await submitButton(w).trigger('click')
    expect(w.text()).toContain('Isi alasan kekurangan')
    expect(api.submitWarehouseHandover).not.toHaveBeenCalled()
    expect(w.find('input[aria-label^="Alasan kekurangan"]').exists()).toBe(true)
  })

  it('requires the handover evidence when goods are handed over', async () => {
    const w = await openDrawer()
    await w.get('input[type="number"]').setValue(6)
    await w.get('input[aria-label^="Alasan kekurangan"]').setValue('Produksi baru 6 unit')
    await submitButton(w).trigger('click')
    expect(w.text()).toContain('Lampirkan bukti serah terima')
    expect(api.submitWarehouseHandover).not.toHaveBeenCalled()
  })

  it('submits quantity, reason and evidence together', async () => {
    const w = await openDrawer()
    await w.get('input[type="number"]').setValue(6)
    await w.get('input[aria-label^="Alasan kekurangan"]').setValue('Produksi baru 6 unit')
    const file = new File(['x'], 'serah.jpg', { type: 'image/jpeg' })
    const input = w.get('input[type="file"]')
    Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
    await input.trigger('change')
    await submitButton(w).trigger('click')
    await flushPromises()
    expect(api.submitWarehouseHandover).toHaveBeenCalledWith(5, {
      items: [{ item_id: 50, quantity: 6, shortage_reason: 'Produksi baru 6 unit' }],
      shortages: [],
      proof: file,
    })
    expect(w.text()).toContain('Menunggu Koordinator Kurir')
  })

  it('reports "nothing available" (0 + reason) as a shortage without evidence', async () => {
    const w = await openDrawer()
    await w.get('input[type="number"]').setValue(0)
    await w.get('input[aria-label^="Alasan kekurangan"]').setValue('Bahan baku habis')
    await submitButton(w).trigger('click')
    await flushPromises()
    expect(api.submitWarehouseHandover).toHaveBeenCalledWith(5, { items: [], shortages: [{ item_id: 50, reason: 'Bahan baku habis' }], proof: null })
    expect(w.text()).toContain('Admin diberi tahu')
  })

  it('shows the server validation message when the request is refused', async () => {
    api.submitWarehouseHandover.mockRejectedValueOnce(new ApiError('Order tidak lagi dalam antrean Gudang', 422))
    const w = await openDrawer()
    await w.get('input[type="number"]').setValue(10)
    const input = w.get('input[type="file"]')
    Object.defineProperty(input.element, 'files', { value: [new File(['x'], 'a.jpg', { type: 'image/jpeg' })], configurable: true })
    await input.trigger('change')
    await submitButton(w).trigger('click')
    await flushPromises()
    expect(w.text()).toContain('Order tidak lagi dalam antrean Gudang')
  })

  describe('Koordinator request on the queue', () => {
    const requested = {
      ...order,
      fulfillment_request: {
        stock_request_id: 5, request_number: 'SR-1', status: 'pending', requested_by_coordinator: true,
        coordinator: { id: 9, name: 'Koord Andi' }, requested_at: '2026-11-11T05:00:00+00:00', note: 'Butuh besok pagi',
        requested_qty: 10, handed_over_qty: 0, awaiting_receipt_qty: 0, outstanding_qty: 10,
      },
    }

    it('shows who asked, when, the note and the real quantities', async () => {
      api.listWarehouseDiprosesOrders.mockResolvedValue({ orders: [requested], meta: { current_page: 1, last_page: 1, total: 1, requested_total: 1 } })
      const w = mount(OrderDiprosesView, { global: { stubs } })
      await flushPromises()
      const box = w.get('[data-testid="coordinator-request"]')
      expect(box.text()).toContain('Koord Andi')
      expect(box.text()).toContain('Butuh besok pagi')
      expect(box.text()).toContain('SR-1')
      expect(box.text()).toContain('Menunggu Gudang menyetujui & menyerahkan')
      expect(w.get('[data-testid="filter-requested"]').text()).toContain('(1)')
    })

    it('says so plainly when the Koordinator never asked, and invents nothing', async () => {
      const unrequested = { ...order, fulfillment_request: { ...requested.fulfillment_request, requested_by_coordinator: false, coordinator: null, requested_at: null, note: null } }
      api.listWarehouseDiprosesOrders.mockResolvedValue({ orders: [unrequested], meta: { current_page: 1, last_page: 1, total: 1, requested_total: 0 } })
      const w = mount(OrderDiprosesView, { global: { stubs } })
      await flushPromises()
      expect(w.find('[data-testid="coordinator-request"]').exists()).toBe(false)
      expect(w.get('[data-testid="no-coordinator-request"]').text()).toContain('Belum diminta Koordinator Kurir')
    })

    it('defaults to the Koordinator requests and can show every order', async () => {
      const w = mount(OrderDiprosesView, { global: { stubs } })
      await flushPromises()
      expect(api.listWarehouseDiprosesOrders).toHaveBeenLastCalledWith(1, true)
      await w.get('[data-testid="filter-all"]').trigger('click')
      await flushPromises()
      expect(api.listWarehouseDiprosesOrders).toHaveBeenLastCalledWith(1, false)
    })

    it('reports a handover that waits for the Koordinator receipt', async () => {
      const waiting = { ...requested, fulfillment_request: { ...requested.fulfillment_request, handed_over_qty: 0, awaiting_receipt_qty: 10, outstanding_qty: 10 } }
      api.listWarehouseDiprosesOrders.mockResolvedValue({ orders: [waiting], meta: { current_page: 1, last_page: 1, total: 1, requested_total: 1 } })
      const w = mount(OrderDiprosesView, { global: { stubs } })
      await flushPromises()
      expect(w.get('[data-testid="coordinator-request"]').text()).toContain('menunggu konfirmasi penerimaan Koordinator')
    })
  })

  describe('whole-order handover answers for every outstanding line', () => {
    const threeLines = { id: 5, request_number: 'SR-3', order_id: 7, status: 'pending', items: [
      { id: 50, order_item_id: 70, product_id: 1, product_variation_id: null, product_name: 'Produk P1', variation_label: null, sku: 'P1', sku_snapshot: 'P1', product_image_url: null, requested_qty: 1, fulfilled_qty: 0, remaining_qty: 1, current_stock: null },
      { id: 51, order_item_id: 71, product_id: 2, product_variation_id: null, product_name: 'Produk P2', variation_label: null, sku: 'P2', sku_snapshot: 'P2', product_image_url: null, requested_qty: 1, fulfilled_qty: 0, remaining_qty: 1, current_stock: null },
      { id: 52, order_item_id: 72, product_id: 3, product_variation_id: null, product_name: 'Produk P3', variation_label: null, sku: 'P3', sku_snapshot: 'P3', product_image_url: null, requested_qty: 1, fulfilled_qty: 0, remaining_qty: 1, current_stock: null },
    ] }

    async function openWholeOrder() {
      api.getWarehouseStockRequestByOrder.mockResolvedValue(threeLines)
      const w = mount(OrderDiprosesView, { global: { stubs } })
      await flushPromises()
      await w.findAll('button').find((b) => b.text() === 'Setujui & Serahkan')!.trigger('click')
      await flushPromises()
      return w
    }

    it('does not let a product be skipped silently: a blank line needs a reason and is reported', async () => {
      const w = await openWholeOrder()
      const qty = w.findAll('input[type="number"]')
      await qty[0]!.setValue(1)
      await qty[1]!.setValue(1)
      // P3 left blank -> a reason field appears for it and submit is refused without one.
      expect(w.find('input[placeholder^="Alasan kurang 1 unit"]').exists()).toBe(true)
      const file = new File(['x'], 'bukti.jpg', { type: 'image/jpeg' })
      const fileInput = w.get('input[type="file"]')
      Object.defineProperty(fileInput.element, 'files', { value: [file] })
      await fileInput.trigger('change')
      await w.findAll('button').find((b) => b.text().includes('Setujui & Serahkan ke Koordinator'))!.trigger('click')
      expect(w.text()).toContain('Isi alasan kekurangan untuk Produk P3')
      expect(api.submitWarehouseHandover).not.toHaveBeenCalled()

      await w.get('input[placeholder^="Alasan kurang 1 unit"]').setValue('Stok P3 habis')
      await w.findAll('button').find((b) => b.text().includes('Setujui & Serahkan ke Koordinator'))!.trigger('click')
      await flushPromises()
      expect(api.submitWarehouseHandover).toHaveBeenCalledTimes(1)
      const [, payload] = api.submitWarehouseHandover.mock.calls[0]!
      expect(payload.items).toEqual([{ item_id: 50, quantity: 1 }, { item_id: 51, quantity: 1 }])
      expect(payload.shortages).toEqual([{ item_id: 52, reason: 'Stok P3 habis' }])
    })
  })
})
