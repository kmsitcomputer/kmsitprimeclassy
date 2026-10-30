/**
 * GAP-A — AdminReturnsView: staff (admin/agen/super admin) dapat set/ubah jadwal pickup retur
 * langsung dari daftar retur yang ada. API tetap otoritas (branch scope + validasi di backend).
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import AdminReturnsView from '@/views/admin/AdminReturnsView.vue'

const returnsApi = vi.hoisted(() => ({
  listReturns: vi.fn(),
  reviewReturn: vi.fn(),
  markReturnItemRefunded: vi.fn(),
  finalizeReturn: vi.fn(),
  scheduleReturnPickup: vi.fn(),
}))
vi.mock('@/api/returns', () => ({ ...returnsApi }))

const auth = vi.hoisted(() => ({ user: { id: 1, role: 'admin' } as { id: number; role: string } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => auth }))

vi.mock('@/layouts/DashboardLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))
vi.mock('@/components/ui/AgentPicker.vue', () => ({ default: { template: '<div />' } }))

const item = (over = {}) => ({
  id: 11, order_item_id: 22, product_name: 'Kue Coklat', variation_label: null, sku: null,
  quantity_returned: 1, refund_amount: '100000', restock: true, status: 'approved', refund_status: 'pending',
  condition_note: null, pickup_scheduled_for: null, ...over,
})
const ret = (over = {}) => ({
  id: 5, order_id: 99, order_no: 'D-261030-00001', customer_name: 'Budi', reason: 'Rusak saat diterima',
  status: 'approved', total_refund_amount: '100000', items: [item()], created_at: '2026-10-30T01:00:00Z', ...over,
})

beforeEach(() => {
  Object.values(returnsApi).forEach((fn) => fn.mockReset())
  auth.user = { id: 1, role: 'admin' }
  returnsApi.listReturns.mockResolvedValue({ returns: [ret()], meta: { current_page: 1, last_page: 1, total: 1 } })
})

async function mountView() {
  const wrapper = mount(AdminReturnsView, { global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } })
  await flushPromises()
  return wrapper
}

describe('AdminReturnsView pickup scheduling (GAP-A)', () => {
  it('shows the current schedule and lets staff set it on a return still awaiting pickup', async () => {
    const wrapper = await mountView()
    expect(wrapper.text()).toContain('Jadwal pickup belum diatur')

    await wrapper.find('input[type="date"]').setValue('2026-11-02')
    returnsApi.scheduleReturnPickup.mockResolvedValue({ return_id: 5, pickup_scheduled_for: '2026-11-02', items: 1 })

    await wrapper.findAll('button').find((b) => b.text() === 'Simpan Jadwal')!.trigger('click')
    await flushPromises()

    expect(returnsApi.scheduleReturnPickup).toHaveBeenCalledWith(5, '2026-11-02')
    // Reload carries the new schedule into the item lines.
    expect(returnsApi.listReturns).toHaveBeenCalledTimes(2)
  })

  it('prefills caption with the scheduled date and disables saving until a date is chosen', async () => {
    returnsApi.listReturns.mockResolvedValue({ returns: [ret({ items: [item({ pickup_scheduled_for: '2026-11-01' })] })], meta: { current_page: 1, last_page: 1, total: 1 } })
    const wrapper = await mountView()

    expect(wrapper.text()).toContain('Jadwal pickup: 1 November 2026')
    const save = wrapper.findAll('button').find((b) => b.text() === 'Simpan Jadwal')!
    expect(save.attributes('disabled')).toBeDefined()
  })

  it('offers no scheduling for a fully-rejected/completed return', async () => {
    returnsApi.listReturns.mockResolvedValue({ returns: [ret({ status: 'completed', items: [item({ status: 'rejected' })] })], meta: { current_page: 1, last_page: 1, total: 1 } })
    const wrapper = await mountView()
    expect(wrapper.text()).not.toContain('Simpan Jadwal')
  })

  it('keeps the retry available and recovers after a failed save', async () => {
    const wrapper = await mountView()
    await wrapper.find('input[type="date"]').setValue('2026-11-02')
    returnsApi.scheduleReturnPickup.mockRejectedValueOnce(new Error('422'))
    await wrapper.findAll('button').find((b) => b.text() === 'Simpan Jadwal')!.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Gagal menyimpan jadwal')

    returnsApi.scheduleReturnPickup.mockResolvedValue({ return_id: 5, pickup_scheduled_for: '2026-11-02', items: 1 })
    await wrapper.findAll('button').find((b) => b.text() === 'Simpan Jadwal')!.trigger('click')
    await flushPromises()
    expect(returnsApi.scheduleReturnPickup).toHaveBeenCalledTimes(2)
  })
})
