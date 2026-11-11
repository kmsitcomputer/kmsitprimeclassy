/**
 * F08 — Koordinator Kurir logistics panel: RajaOngkir list + readiness, request to Gudang, handover form,
 * whole-shipment dispatch, pagination, loading/error states, and receipt count validation.
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

const logistics = vi.hoisted(() => ({
  listExpeditions: vi.fn(), listRefusals: vi.fn(), receiveRefusal: vi.fn(), disposeRefusal: vi.fn(), requestFulfillment: vi.fn(),
}))
vi.mock('@/api/koordinatorLogistics', () => ({ ...logistics }))
const shipments = vi.hoisted(() => ({ recordExpeditionHandover: vi.fn(), updateShipmentStatus: vi.fn() }))
vi.mock('@/api/shipments', () => ({ ...shipments }))
const warehouse = vi.hoisted(() => ({ listFulfillmentProposals: vi.fn(), receiveFulfillmentProposal: vi.fn() }))
vi.mock('@/api/warehouseFulfillment', () => ({ ...warehouse }))

import KoordinatorLogisticsPanel from '@/components/dispatch/KoordinatorLogisticsPanel.vue'

const row = (over: Record<string, unknown> = {}) => ({
  shipment_id: 5, order_id: 50, order_no: 'ORD-50', recipient_name: 'Budi', carrier: 'jne', service: 'REG',
  tracking_number: null, handover_recorded: false, fulfillment_outstanding_qty: 0, coordinator_requested_at: null,
  ready: { paid_in_full: true, goods_complete: true, single_shipment: true }, ...over,
})
const meta = (last = 1) => ({ current_page: 1, last_page: last, total: last })

beforeEach(() => {
  Object.values({ ...logistics, ...shipments, ...warehouse }).forEach((fn) => fn.mockReset())
})

async function mountPanel(section: 'receipts' | 'refusals' | 'expeditions') {
  const wrapper = mount(KoordinatorLogisticsPanel, { props: { section } })
  await flushPromises()
  return wrapper
}

describe('KoordinatorLogisticsPanel — expeditions', () => {
  it('lists expedition shipments with readiness gates and only offers dispatch after the handover is recorded', async () => {
    logistics.listExpeditions.mockResolvedValue({ rows: [row(), row({ shipment_id: 6, order_no: 'ORD-60', handover_recorded: true, tracking_number: 'JNE123456' })], meta: meta() })
    const w = await mountPanel('expeditions')
    expect(w.text()).toContain('ORD-50')
    expect(w.text()).toContain('Lunas penuh')
    const dispatchButtons = w.findAll('button').filter((b) => b.text().includes('Tandai Dikirim'))
    expect(dispatchButtons).toHaveLength(1) // only ORD-60 (handover recorded)
    expect(w.text()).toContain('JNE123456')
  })

  it('disables dispatch while a readiness gate is open', async () => {
    logistics.listExpeditions.mockResolvedValue({ rows: [row({ handover_recorded: true, ready: { paid_in_full: false, goods_complete: true, single_shipment: true } })], meta: meta() })
    const w = await mountPanel('expeditions')
    const btn = w.findAll('button').find((b) => b.text().includes('Tandai Dikirim'))!
    expect(btn.attributes('disabled')).toBeDefined()
  })

  it('requests fulfilment from Gudang and reloads', async () => {
    logistics.listExpeditions.mockResolvedValue({ rows: [row({ fulfillment_outstanding_qty: 4 })], meta: meta() })
    logistics.requestFulfillment.mockResolvedValue({ id: 1 })
    const w = await mountPanel('expeditions')
    await w.findAll('button').find((b) => b.text().includes('Minta ke Gudang'))!.trigger('click')
    await flushPromises()
    expect(logistics.requestFulfillment).toHaveBeenCalledWith(50)
    expect(logistics.listExpeditions).toHaveBeenCalledTimes(2)
    expect(w.text()).toContain('Permintaan pemenuhan dikirim ke Gudang')
  })

  it('dispatches the WHOLE shipment through the shipment status endpoint and surfaces server refusals', async () => {
    logistics.listExpeditions.mockResolvedValue({ rows: [row({ handover_recorded: true, tracking_number: 'JNE123456' })], meta: meta() })
    shipments.updateShipmentStatus.mockRejectedValueOnce(new ApiError('Pengiriman ekspedisi tidak boleh parsial.'))
    const w = await mountPanel('expeditions')
    await w.findAll('button').find((b) => b.text().includes('Tandai Dikirim'))!.trigger('click')
    await flushPromises()
    expect(shipments.updateShipmentStatus).toHaveBeenCalledWith(5, 'dikirim')
    expect(w.text()).toContain('tidak boleh parsial')
  })

  it('paginates the list', async () => {
    logistics.listExpeditions.mockResolvedValue({ rows: [row()], meta: meta(3) })
    const w = await mountPanel('expeditions')
    expect(w.text()).toContain('Halaman 1 / 3')
    await w.findAll('button').find((b) => b.text() === 'Next')!.trigger('click')
    await flushPromises()
    expect(logistics.listExpeditions).toHaveBeenLastCalledWith(2)
  })

  it('shows an error state when loading fails', async () => {
    logistics.listExpeditions.mockRejectedValue(new ApiError('Gagal ambil data'))
    const w = await mountPanel('expeditions')
    expect(w.text()).toContain('Gagal ambil data')
  })
})

describe('KoordinatorLogisticsPanel — receipts', () => {
  const proposal = { id: 9, stock_request_id: 3, status: 'pending', created_at: null, items: [{ id: 91, quantity: 10 }], request: { order: { order_no: 'ORD-9' } } }

  it('only confirms a receipt when accepted + short + damaged equals the prepared quantity', async () => {
    warehouse.listFulfillmentProposals.mockResolvedValue({ proposals: [proposal], meta: meta() })
    warehouse.receiveFulfillmentProposal.mockResolvedValue({})
    const w = await mountPanel('receipts')
    const confirm = () => w.findAll('button').find((b) => b.text().includes('Konfirmasi Penerimaan'))!
    expect(confirm().attributes('disabled')).toBeUndefined() // defaults to accepted = prepared
    const [accepted, short, damaged] = w.findAll('input[type="number"]')
    await accepted!.setValue(6)
    await short!.setValue(3)
    await damaged!.setValue(0) // 9 ≠ 10
    expect(confirm().attributes('disabled')).toBeDefined()
    await damaged!.setValue(1)
    await confirm().trigger('click')
    await flushPromises()
    expect(warehouse.receiveFulfillmentProposal).toHaveBeenCalledWith(9, [{ item_id: 91, accepted: 6, short: 3, damaged: 1 }], undefined)
  })
})

describe('KoordinatorLogisticsPanel — refusals', () => {
  it('walks received refusals through the disposition choices', async () => {
    logistics.listRefusals.mockResolvedValue({ rows: [{ id: 1, status: 'received', quantity: 2, reason: 'tolak', order_id: 1, order_no: 'ORD-1', product_name: 'Kue', shipment_id: 1, recorded_at: null, handed_back_at: null, received_at: null, disposition_note: null }], meta: meta() })
    logistics.disposeRefusal.mockResolvedValue({})
    const w = await mountPanel('refusals')
    await w.findAll('button').find((b) => b.text() === 'Kembalikan ke Gudang')!.trigger('click')
    await flushPromises()
    expect(logistics.disposeRefusal).toHaveBeenCalledWith(1, 'return_to_warehouse')
  })
})
