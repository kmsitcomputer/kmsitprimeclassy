/**
 * Dashboard KPI wiring (Phase 2): KPIs that have a source contract render the endpoint's number,
 * and Admin never receives an Agent-commission component. All API modules mocked (no network).
 */
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import DashboardHomeView from '@/views/dashboard/DashboardHomeView.vue'
import { useAuthStore } from '@/stores/auth'

const m = vi.hoisted(() => ({
  reports: {
    getDashboardSummary: vi.fn(), getFinanceOrders: vi.fn(), getFinanceSummary: vi.fn(),
    getOrdersReport: vi.fn(), getSalesCustomersReport: vi.fn(), getDashboardKpis: vi.fn(), getCrmVisitsReport: vi.fn(),
  },
  returns: { listReturns: vi.fn(), listWarehouseReturns: vi.fn() },
  courier: { listCourierOrders: vi.fn(), listCourierReturns: vi.fn() },
  crm: { listCrmFollowUps: vi.fn() },
  commissions: { commissionSummary: vi.fn() },
  dispatch: { listDispatchCouriers: vi.fn(), listDispatchQueue: vi.fn() },
  sub: { mySubStock: vi.fn() },
  wf: { listFulfillmentProposals: vi.fn(), listWarehouseDiprosesOrders: vi.fn() },
}))
vi.mock('@/api/reports', () => m.reports)
vi.mock('@/api/returns', () => m.returns)
vi.mock('@/api/courier', () => m.courier)
vi.mock('@/api/crm', () => m.crm)
vi.mock('@/api/commissions', () => m.commissions)
vi.mock('@/api/dispatch', () => m.dispatch)
vi.mock('@/api/warehouseFulfillment', () => m.wf)
vi.mock('@/api/subStock', () => m.sub)
vi.mock('@/layouts/DashboardLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

function mountAs(role: string) {
  setActivePinia(createPinia())
  const auth = useAuthStore()
  ;(auth as unknown as { user: unknown }).user = { id: 1, name: 'U', role }
  return mount(DashboardHomeView, {
    global: { stubs: { RouterLink: { template: '<a><slot /></a>' }, PcBarChart: true, AppIcon: true } },
  })
}

describe('DashboardHomeView KPI contracts', () => {
  beforeEach(() => {
    Object.values(m).forEach((g) =>
      Object.values(g).forEach((f) => (f as ReturnType<typeof vi.fn>).mockReset().mockResolvedValue(null)),
    )
    m.reports.getDashboardSummary.mockResolvedValue({ total_orders: 3, total_revenue: 0, total_customers: 1, total_korsal: 0, total_sales: 0, total_kurir: 0 })
    m.commissions.commissionSummary.mockResolvedValue({ breakdown: [] })
  })

  it('Admin "Retur Tertunda" = awaiting-review returns from /admin/returns?status=requested,under_review', async () => {
    m.returns.listReturns.mockResolvedValue({ returns: [], meta: { total: 4 } })
    const w = mountAs('admin')
    await flushPromises()
    expect(m.returns.listReturns).toHaveBeenCalledWith(1, { status: 'requested,under_review' })
    expect(w.text()).toMatch(/Retur Tertunda[\s\S]{0,80}4/)
  })

  it('Sales "Perlu Tindakan" = due + overdue CRM follow-ups from /dashboard/kpis follow_ups', async () => {
    m.reports.getDashboardKpis.mockResolvedValue({ follow_ups: { due_today: 2, overdue: 1 } })
    m.reports.getSalesCustomersReport.mockResolvedValue({ items: [], total: 0 })
    const w = mountAs('sales')
    await flushPromises()
    expect(w.text()).toMatch(/Perlu Tindakan[\s\S]{0,120}3/)
    expect(w.text()).toContain('terlambat 1')
  })

  it('Gudang and Kurir return KPIs read their queue totals', async () => {
    m.returns.listWarehouseReturns.mockResolvedValue({ returns: [], meta: { total: 7 } })
    m.courier.listCourierReturns.mockResolvedValue({ returns: [], meta: { total: 5 } })
    const gudang = mountAs('gudang')
    await flushPromises()
    expect(m.returns.listWarehouseReturns).toHaveBeenCalled()
    expect(gudang.text()).toMatch(/Retur Inspeksi[\s\S]{0,80}7/)
    const kurir = mountAs('kurir')
    await flushPromises()
    expect(m.courier.listCourierReturns).toHaveBeenCalled()
    expect(kurir.text()).toMatch(/Retur Pickup[\s\S]{0,80}5/)
  })

  it('Admin never shows an Agent-commission KPI', async () => {
    const w = mountAs('admin')
    await flushPromises()
    expect(w.text()).not.toContain('Komisi Cabang')
    expect(w.text()).not.toContain('Fee Agen')
  })
  it('Agen "Komisi Cabang" shows only the Agen personal fee from /dashboard/kpis', async () => {
    m.reports.getDashboardKpis.mockResolvedValue({ own_fee: { total: 1500, pending: 500, approved: 1000, paid: 0, reversed: 300, count: 2, by_role: { agent: 1500 } } })
    const w = mountAs('agen')
    await flushPromises()
    expect(m.reports.getDashboardKpis).toHaveBeenCalled()
    expect(w.text()).toMatch(/Komisi Cabang[\s\S]{0,40}1\.500/)
    expect(w.text()).toContain('reversed tidak dihitung')
  })

  it('Korsal sees the per-member Sales-fee table (own fee included, no Korsal-role fee) and CRM Tim tiles', async () => {
    m.crm.listCrmFollowUps.mockResolvedValue([{ id: 1 }])
    m.reports.getDashboardKpis.mockResolvedValue({
      team_commissions: {
        members: [
          { user_id: 1, name: 'Purwanti', role: 'korsal', is_self: true, account_status: 'active', pending: 0, approved: 4000, paid: 0, reversed: 0, total: 4000, order_count: 1, order_value: 100 },
          { user_id: 2, name: 'Melina', role: 'sales-kurir-sub', is_self: false, account_status: 'active', pending: 3000, approved: 0, paid: 0, reversed: 0, total: 3000, order_count: 2, order_value: 200 },
        ],
        totals: { total: 7000, pending: 3000, approved: 4000, paid: 0, reversed: 0 },
      },
      follow_ups: { due_today: 1, overdue: 2 },
    })
    m.reports.getCrmVisitsReport.mockResolvedValue({ bucket_labels: {}, rows: [], totals: { customers: 6, visits: 9, rk: 1, conversion_percent: 0, buckets: { '0': 1, '1': 1, '2': 1, '3': 0, '4': 0, '5': 0, '6': 0, '7': 2, N: 1 } } })
    const w = mountAs('korsal')
    await flushPromises()
    const table = w.find('[data-testid="team-commissions"]').text()
    expect(table).toContain('Purwanti')
    expect(table).toContain('Melina')
    expect(table).toContain('fee Sales sendiri')
    expect(w.text()).toMatch(/Komisi Tim[\s\S]{0,40}7\.000/)
    const crm = w.find('[data-testid="crm-team"]').text()
    expect(crm).toMatch(/Prospect Tim\s*3/)
    expect(crm).toMatch(/Deal Tim\s*2/)
    expect(crm).toMatch(/Nolak\s*1/)
    expect(crm).toMatch(/Perlu Tindakan \(Tim\)\s*3/)
  })

  it('Sales "Deal & Komisi" keeps deals and ledger commission as separate figures', async () => {
    m.reports.getDashboardKpis.mockResolvedValue({ deals: { count: 2, value: 270000 }, commission: { total: 1200, pending: 0, approved: 1200, paid: 0, reversed: 0, count: 1 } })
    m.reports.getSalesCustomersReport.mockResolvedValue({ items: [], total: 0 })
    const w = mountAs('sales')
    await flushPromises()
    expect(w.text()).toContain('2 deal')
    expect(w.text()).toMatch(/Komisi \(ledger\):[\s\S]{0,20}1\.200/)
  })

  it('Gudang Stok Tersedia, Kurir Tugas Hari Ini and SKS Stok Sub read their contracts; unavailable shows an em dash, not 0', async () => {
    m.reports.getDashboardKpis.mockResolvedValue({ available_stock: { units: 11, targets: 2 }, tasks_today: { date: '2026-10-30', deliveries: 3, return_pickups: null } })
    const gudang = mountAs('gudang')
    await flushPromises()
    expect(gudang.text()).toMatch(/Stok Tersedia[\s\S]{0,40}11/)
    const kurir = mountAs('kurir')
    await flushPromises()
    expect(kurir.text()).toMatch(/Tugas Hari Ini[\s\S]{0,40}3/)

    m.reports.getDashboardKpis.mockRejectedValue(new Error('down'))
    const down = mountAs('gudang')
    await flushPromises()
    expect(down.text()).not.toMatch(/Stok Tersedia[\s\S]{0,12}\b0\b/)

    m.sub.mySubStock.mockResolvedValue({ sub_location: { id: 1, code: 'S', name: 'S' }, stocks: [{ physical: 5 }, { physical: 2 }] })
    const sks = mountAs('sales-kurir-sub')
    await flushPromises()
    expect(sks.text()).toMatch(/Stok Sub[\s\S]{0,40}7/)
  })

  it('Koordinator has no invented "Perlu Tindakan" tile — it is CRM Sales/Korsal only (GAP-B)', async () => {
    const w = mountAs('koordinator-kurir')
    await flushPromises()
    expect(w.text()).not.toContain('Perlu Tindakan')
    // Operational koordinator tiles still render with real sources.
    expect(w.text()).toMatch(/Belum Ditugaskan/)
    expect(w.text()).not.toContain('Belum ada kontrak metrik')
  })
})
