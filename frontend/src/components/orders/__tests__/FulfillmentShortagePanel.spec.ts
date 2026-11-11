/**
 * PEMENUHAN KURANG panel (Admin Order detail): server figures per product, the reason Gudang gave, the handover
 * evidence, and the Admin's two decisions on the REMAINDER only.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FulfillmentShortagePanel from '../FulfillmentShortagePanel.vue'
import type { FulfillmentShortage, FulfillmentShortageLine } from '@/api/types'

function line(over: Partial<FulfillmentShortageLine> = {}): FulfillmentShortageLine {
  return {
    stock_request_item_id: 1, order_item_id: 11, product_name: 'Kue A', variation_label: null, sku: 'SKU-A', unit: null,
    ordered_qty: 10, handed_over_qty: 6, awaiting_receipt_qty: 0, short_qty: 4, reason: 'Produksi baru 6 unit',
    reported_at: '2026-11-11T03:00:00Z', reported_by: { id: 5, name: 'Gudang Budi' }, status: 'pending_decision', decision: null, ...over,
  }
}

function shortage(items: FulfillmentShortageLine[], over: Partial<FulfillmentShortage> = {}): FulfillmentShortage {
  const short = items.reduce((n, l) => n + l.short_qty, 0)
  return {
    order_no: 'ORD-1', fulfillment_status: 'partial', has_open_shortage: short > 0, open_lines: items.filter((l) => l.short_qty > 0).length,
    ordered_qty: items.reduce((n, l) => n + l.ordered_qty, 0), handed_over_qty: items.reduce((n, l) => n + l.handed_over_qty, 0), short_qty: short,
    last_reported_at: '2026-11-11T03:00:00Z', coordinator: { id: 9, name: 'Koord Rina' }, coordinator_requested_at: null,
    proofs: [{ proposal_id: 3, url: 'https://x/proof.jpg', at: '2026-11-11T03:00:00Z', gudang: { id: 5, name: 'Gudang Budi' }, received_at: null, koordinator: null, status: 'pending' }],
    items, ...over,
  }
}

describe('FulfillmentShortagePanel', () => {
  it('shows the alert, the totals, the officers and the evidence', () => {
    const w = mount(FulfillmentShortagePanel, { props: { shortage: shortage([line(), line({ stock_request_item_id: 2, order_item_id: 12, product_name: 'Kue B', ordered_qty: 5, handed_over_qty: 0, short_qty: 5, reason: 'Bahan habis' })]), canDecide: true } })
    expect(w.text()).toContain('PEMENUHAN KURANG')
    expect(w.text()).toContain('ORD-1')
    expect(w.get('[data-testid="shortage-ordered"]').text()).toBe('15')
    expect(w.get('[data-testid="shortage-handed"]').text()).toBe('6')
    expect(w.get('[data-testid="shortage-short"]').text()).toBe('9')
    expect(w.text()).toContain('Gudang Budi')
    expect(w.text()).toContain('Koord Rina')
    expect(w.get('a[href="https://x/proof.jpg"]').exists()).toBe(true)
  })

  it('lists every product with ordered / handed over / short, the reason and the handling status', () => {
    const w = mount(FulfillmentShortagePanel, { props: { shortage: shortage([line(), line({ stock_request_item_id: 2, order_item_id: 12, product_name: 'Kue B', ordered_qty: 5, handed_over_qty: 0, short_qty: 5, reason: 'Bahan habis' })]), canDecide: true } })
    const a = w.get('[data-testid="shortage-line-1"]').text()
    expect(a).toContain('Kue A')
    expect(a).toContain('SKU SKU-A')
    expect(a).toContain('Produksi baru 6 unit')
    expect(a).toContain('Menunggu keputusan Admin')
    const b = w.get('[data-testid="shortage-line-2"]').text()
    expect(b).toContain('Kue B')
    expect(b).toContain('Bahan habis')
  })

  it('offers reschedule and cancel for the remainder only and emits the order line', async () => {
    const w = mount(FulfillmentShortagePanel, { props: { shortage: shortage([line(), line({ stock_request_item_id: 2, order_item_id: 12, short_qty: 0, handed_over_qty: 10, status: 'resolved' })]), canDecide: true } })
    await w.get('[data-testid="shortage-reschedule-11"]').trigger('click')
    await w.get('[data-testid="shortage-cancel-11"]').trigger('click')
    expect(w.emitted('reschedule')).toEqual([[11]])
    expect(w.emitted('cancel')).toEqual([[11]])
    expect(w.find('[data-testid="shortage-reschedule-12"]').exists()).toBe(false)
  })

  it('hides the decisions from a viewer who cannot decide', () => {
    const w = mount(FulfillmentShortagePanel, { props: { shortage: shortage([line()]), canDecide: false } })
    expect(w.find('[data-testid="shortage-reschedule-11"]').exists()).toBe(false)
    expect(w.find('[data-testid="shortage-cancel-11"]').exists()).toBe(false)
  })

  it('shows the recorded decision and drops the alert once nothing is short', () => {
    const decided = line({ short_qty: 0, status: 'cancelled', decision: { action: 'cancel_remainder', reason: 'x', quantity: 4, date: null, by: { id: 2, name: 'Admin Sari' }, at: '2026-11-11T04:00:00Z' } })
    const w = mount(FulfillmentShortagePanel, { props: { shortage: shortage([decided]), canDecide: true } })
    expect(w.text()).not.toContain('PEMENUHAN KURANG')
    expect(w.text()).toContain('Penanganan kekurangan selesai')
    expect(w.text()).toContain('Sisa dibatalkan')
    expect(w.text()).toContain('4 unit dibatalkan')
    expect(w.text()).toContain('Admin Sari')
  })

  it('shows the rescheduled date and says so when no goods were handed over (no evidence)', () => {
    const rescheduled = line({ status: 'rescheduled', handed_over_qty: 0, decision: { action: 'reschedule', reason: 'tunggu', quantity: null, date: '2026-12-01', by: { id: 2, name: 'Admin Sari' }, at: '2026-11-11T04:00:00Z' } })
    const w = mount(FulfillmentShortagePanel, { props: { shortage: shortage([rescheduled], { proofs: [] }), canDecide: true } })
    expect(w.text()).toContain('Dijadwalkan ulang')
    expect(w.text()).toContain('Belum ada barang yang diserahkan')
  })
})
