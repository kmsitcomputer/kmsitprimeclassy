/**
 * Area D Phase 2 — NetworkSummaryView renders the consolidated network summary it
 * receives from `getNetworkSummary` (backend `ReportService::networkSummary`) and
 * never adds agent fee data for roles that must not see it (admin is not even a
 * route audience; keuangan's agent narrowing stays server-side). The backend
 * contract itself is pinned by NetworkAndFunnelReportTest.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '@/api/errors'
import NetworkSummaryView from '@/views/dashboard/NetworkSummaryView.vue'

const api = vi.hoisted(() => ({ getNetworkSummary: vi.fn() }))
vi.mock('@/api/reports', async (orig) => ({
  ...(await orig<typeof import('@/api/reports')>()) as object,
  getNetworkSummary: api.getNetworkSummary,
}))

const auth = vi.hoisted(() => ({ user: { id: 1, role: 'super_admin' } as { id: number; role: string } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => auth }))

vi.mock('@/layouts/DashboardLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))
vi.mock('@/components/ui/AppIcon.vue', () => ({ default: { template: '<svg />' } }))
vi.mock('@/components/ui/AgentPicker.vue', () => ({ default: { props: ['modelValue'], template: '<select />' } }))

const summary = () => ({
  korsal_count: 2,
  sales_count: 5,
  courier_count: 3,
  sales_with_sales_count: 4,
  totals: {
    order_count: 12,
    item_count: 30,
    total_amount: 1200000,
    paid_amount: 800000,
    outstanding_amount: 400000,
    refund_total: 50000,
    return_refund_total: 25000,
    additional_payment_total: 20000,
  },
  per_agen: [
    {
      agent_id: 9, agent_name: 'Toko QA', korsal_count: 2, sales_count: 5, active_sales_count: 4,
      sales_transaction_count: 9, korsal_transaction_count: 2, order_count: 12,
      total_amount: 1200000, paid_amount: 800000, outstanding_amount: 400000,
      stock_quantity: 77, total_fee: 150000,
    },
  ],
  products: [{ product_name_snapshot: 'Assisted Cake', sku_snapshot: 'TEST-1', quantity: '18', total_amount: '900000', order_count: 8 }],
  payment_methods: [{ method_code: 'cod', method_name: 'COD', method_type: 'cod', order_count: 7, total_amount: '500000' }],
  payment_status: [{ payment_status: 'paid', order_count: 9, total_amount: '800000' }],
})

async function mountView() {
  const wrapper = mount(NetworkSummaryView)
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  api.getNetworkSummary.mockReset()
  auth.user = { id: 1, role: 'super_admin' }
  api.getNetworkSummary.mockResolvedValue(summary())
})

describe('NetworkSummaryView', () => {
  it('renders totals, headcounts and the per-agen table from the service payload', async () => {
    const wrapper = await mountView()
    expect(api.getNetworkSummary).toHaveBeenCalledWith({ from: undefined, to: undefined, agent_id: undefined })
    expect(wrapper.text()).toContain('Toko QA')
    expect(wrapper.find('[data-testid="network-summary-per-agen"]').text()).toContain('150.000')
    expect(wrapper.find('[data-testid="network-summary-totals"]').text()).toContain('1.200.000')
    expect(wrapper.find('[data-testid="network-summary-headcounts"]').text()).toContain('4')
  })

  it('passes explicit filters on subsequent loads (super_admin agent narrowing included)', async () => {
    const wrapper = await mountView()
    api.getNetworkSummary.mockClear()
    // The Dari date input triggers @change="load()" itself.
    await wrapper.find('input[type="date"]').setValue('2026-10-01')
    await flushPromises()
    expect(api.getNetworkSummary).toHaveBeenCalledWith({ from: '2026-10-01', to: undefined, agent_id: undefined })
  })

  it('shows the notice error state when the backend rejects the call', async () => {
    api.getNetworkSummary.mockRejectedValue(new ApiError('Validasi gagal', 403, null))
    const wrapper = await mountView()
    expect(wrapper.find('[data-testid="network-summary-error"]').text()).toContain('Validasi gagal')
    expect(wrapper.text()).not.toContain('Toko QA')
  })
})
