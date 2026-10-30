/**
 * GAP-A — KurirDashboardView: KPI "pickup retur hari ini" hanya dimuat untuk role kurir
 * (endpoint /kurir/returns/pickup-today), dan kegagalannya tidak memblokir dashboard.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import KurirDashboardView from '@/views/dashboard/KurirDashboardView.vue'

const courierApi = vi.hoisted(() => ({
  listCourierOrders: vi.fn(),
  listCourierReturns: vi.fn(),
  pickupReturnItem: vi.fn(),
  confirmReturnItem: vi.fn(),
  courierDeliveredReport: vi.fn(),
  courierReturnPickupsToday: vi.fn(),
}))
vi.mock('@/api/courier', () => ({ ...courierApi }))
vi.mock('@/api/shipments', () => ({ updateShipmentItemStatus: vi.fn() }))

const auth = vi.hoisted(() => ({ user: { id: 1, role: 'kurir' } as { id: number; role: string } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => auth }))

vi.mock('@/layouts/DashboardLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))
vi.mock('@/components/ui/PrimeFileUpload.vue', () => ({ default: { template: '<div />' } }))

const emptyMeta = { current_page: 1, last_page: 1, total: 0 }

beforeEach(() => {
  Object.values(courierApi).forEach((fn) => fn.mockReset())
  auth.user = { id: 1, role: 'kurir' }
  courierApi.listCourierOrders.mockResolvedValue({ orders: [], meta: emptyMeta })
})

async function mountView() {
  const wrapper = mount(KurirDashboardView, { global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } })
  await flushPromises()
  return wrapper
}

describe('KurirDashboardView GAP-A KPI', () => {
  it('loads and renders the scheduled pickups KPI for the kurir role', async () => {
    courierApi.courierReturnPickupsToday.mockResolvedValue({ date: '2026-10-30', count: 2 })
    const wrapper = await mountView()
    expect(courierApi.courierReturnPickupsToday).toHaveBeenCalledTimes(1)
    expect(wrapper.text()).toContain('Pickup Retur Hari Ini')
    expect(wrapper.text()).toContain('Ada penjemputan retur yang dijadwalkan hari ini')
  })

  it('does not call the kurir-only endpoint for executor-only roles', async () => {
    auth.user = { id: 2, role: 'sales-kurir-sub' }
    await mountView()
    expect(courierApi.courierReturnPickupsToday).not.toHaveBeenCalled()
  })

  it('renders the zero state and silently survives a failed KPI read', async () => {
    courierApi.courierReturnPickupsToday.mockRejectedValue(new Error('network'))
    const wrapper = await mountView()
    expect(wrapper.text()).not.toContain('Pickup Retur Hari Ini')
    expect(wrapper.text()).toContain('Order')

    courierApi.courierReturnPickupsToday.mockResolvedValue({ date: '2026-10-30', count: 0 })
    const ok = await mountView()
    expect(ok.text()).toContain('Tidak ada penjemputan retur yang dijadwalkan hari ini.')
  })
})
