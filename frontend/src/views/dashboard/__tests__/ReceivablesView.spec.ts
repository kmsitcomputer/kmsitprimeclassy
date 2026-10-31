import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import ReceivablesView from '@/views/dashboard/ReceivablesView.vue'
import type { FinanceOrderRow, ReportFilters } from '@/api/reports'

/** Improvement 10 / §8B centralized Piutang menu: search, the Belum Lunas /
 * Jatuh Tempo / Terlambat filters and pagination are applied server-side by
 * the finance-orders source (receivable / search / due_filter / page), which
 * also returns the meta.summary totals; rows navigate to Detail Pesanan. */
const reports = vi.hoisted(() => ({
  getFinanceOrders: vi.fn(),
}))

vi.mock('@/api/reports', () => ({
  getFinanceOrders: reports.getFinanceOrders,
}))

const push = vi.fn()
vi.mock('vue-router', () => ({
  useRouter: () => ({ push }),
}))

const i18n = createI18n({ legacy: false, locale: 'id', messages: { id: { receivables: {
  title: 'Piutang',
  loadError: 'Gagal memuat daftar piutang.',
  searchPlaceholder: 'Cari...',
  filterAll: 'Semua', filterUnpaid: 'Belum Lunas', filterDue: 'Jatuh Tempo', filterOverdue: 'Terlambat',
  summary: '{count} pesanan · sisa piutang {amount}',
  empty: 'Tidak ada pesanan yang cocok.',
  colOrder: 'No', colCustomer: 'Pelanggan', colTotal: 'Total', colPaid: 'Terbayar',
  colOutstanding: 'Sisa', colDue: 'Jatuh Tempo', colStatus: 'Status',
}, common: { loading: 'Memuat...' } } },
})

const today = new Date().toISOString().slice(0, 10)

function rows(): FinanceOrderRow[] {
  return [
    { order_id: 1, order_no: 'D-1', order_date: today, customer: 'Budi', payment_method: 'COD', payment_method_type: 'cod',
      grand_total: 300000, dp_paid: 0, dp_submitted: 0, verification_status: null, has_pending_proof: false,
      total_paid: 100000, remaining: 200000, payment_status: 'partially_paid', due_date: today, is_overdue: false,
      refund: 0, additional_payment: 0, fees: {} },
    { order_id: 2, order_no: 'D-2', order_date: today, customer: 'Siti', payment_method: 'COD', payment_method_type: 'cod',
      grand_total: 150000, dp_paid: 0, dp_submitted: 0, verification_status: null, has_pending_proof: false,
      total_paid: 0, remaining: 150000, payment_status: 'unpaid', due_date: '2026-01-01', is_overdue: true,
      refund: 0, additional_payment: 0, fees: {} },
    { order_id: 3, order_no: 'D-3', order_date: today, customer: 'Paid Customer', payment_method: 'COD', payment_method_type: 'cod',
      grand_total: 50000, dp_paid: 0, dp_submitted: 0, verification_status: null, has_pending_proof: false,
      total_paid: 50000, remaining: 0, payment_status: 'paid', due_date: null, is_overdue: false,
      refund: 0, additional_payment: 0, fees: {} },
  ] as FinanceOrderRow[]
}

/** Mirrors ReportService::financeOrders filtering so the view is exercised against the real contract. */
function serve(params: ReportFilters & Record<string, unknown> = {}) {
  let list = rows()
  if (params.receivable) list = list.filter((r) => r.remaining > 0)
  if (typeof params.search === 'string' && params.search) {
    const q = params.search.toLowerCase()
    list = list.filter((r) => r.order_no.toLowerCase().includes(q) || (r.customer ?? '').toLowerCase().includes(q))
  }
  if (params.due_filter === 'due') list = list.filter((r) => r.due_date === today)
  if (params.due_filter === 'overdue') list = list.filter((r) => r.is_overdue)
  return {
    orders: list,
    meta: {
      current_page: 1, last_page: 1, total: list.length, per_page: 15,
      summary: { count: list.length, outstanding: list.reduce((sum, r) => sum + r.remaining, 0) },
    },
  }
}

function lastParams() {
  return reports.getFinanceOrders.mock.calls.at(-1)?.[0]
}

async function mountView() {
  const w = mount(ReceivablesView, { global: { plugins: [i18n] } })
  await flushPromises()
  return w
}

/** Filter/search changes are debounced (250 ms) before reloading. */
async function settle() {
  await vi.advanceTimersByTimeAsync(300)
  await flushPromises()
}

describe('ReceivablesView', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    reports.getFinanceOrders.mockReset().mockImplementation(async (params) => serve(params))
    push.mockClear()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('requests only receivables and lists rows with the server summary', async () => {
    const w = await mountView()
    expect(lastParams()).toMatchObject({ receivable: true, page: 1, per_page: 15 })
    expect(lastParams().due_filter).toBeUndefined()
    expect(w.text()).toContain('D-1')
    expect(w.text()).toContain('Budi')
    expect(w.text()).toContain('Siti')
    expect(w.text()).not.toContain('D-3')
    expect(w.text()).toContain('2 pesanan')
  })

  it('renders the summary from meta.summary, not from the current page rows', async () => {
    reports.getFinanceOrders.mockImplementation(async (params) => {
      const result = serve(params)
      return { ...result, meta: { ...result.meta, total: 40, last_page: 3, summary: { count: 40, outstanding: 9000000 } } }
    })
    const w = await mountView()
    expect(w.text()).toContain('40 pesanan')
  })

  it('Belum Lunas keeps the receivable request without a due filter', async () => {
    const w = await mountView()
    await w.find('[data-testid="receivables-filter-unpaid"]').trigger('click')
    await settle()
    expect(lastParams()).toMatchObject({ receivable: true, page: 1 })
    expect(lastParams().due_filter).toBeUndefined()
    expect(w.text()).toContain('D-1')
    expect(w.text()).toContain('D-2')
    expect(w.text()).not.toContain('D-3')
  })

  it('Terlambat requests due_filter=overdue and shows only overdue orders', async () => {
    const w = await mountView()
    await w.find('[data-testid="receivables-filter-overdue"]').trigger('click')
    await settle()
    expect(lastParams()).toMatchObject({ receivable: true, due_filter: 'overdue' })
    expect(w.text()).not.toContain('D-1')
    expect(w.text()).toContain('D-2')
    expect(w.text()).not.toContain('D-3')
  })

  it('Jatuh Tempo requests due_filter=due and shows only orders due today', async () => {
    const w = await mountView()
    await w.find('[data-testid="receivables-filter-due"]').trigger('click')
    await settle()
    expect(lastParams()).toMatchObject({ receivable: true, due_filter: 'due' })
    expect(w.text()).toContain('D-1')
    expect(w.text()).not.toContain('D-2')
    expect(w.text()).not.toContain('D-3')
  })

  it('search is sent to the server and matches order number or customer name', async () => {
    const w = await mountView()
    await w.find('[data-testid="receivables-search"]').setValue('siti')
    await settle()
    expect(lastParams()).toMatchObject({ receivable: true, search: 'siti', page: 1 })
    expect(w.text()).toContain('D-2')
    expect(w.text()).not.toContain('D-1')

    await w.find('[data-testid="receivables-search"]').setValue('D-1')
    await settle()
    expect(w.text()).toContain('Budi')
    expect(w.text()).not.toContain('Siti')

    // A fully paid order is not a receivable, so the server never returns it.
    await w.find('[data-testid="receivables-search"]').setValue('D-3')
    await settle()
    expect(w.find('[data-testid="receivables-empty"]').exists()).toBe(true)
  })

  it('paginates through the server pages', async () => {
    reports.getFinanceOrders.mockImplementation(async (params) => {
      const result = serve(params)
      return { ...result, meta: { ...result.meta, current_page: params.page, last_page: 2 } }
    })
    const w = await mountView()
    const next = w.findAll('button').find((b) => b.text() === '›')
    expect(next).toBeDefined()
    await next!.trigger('click')
    await flushPromises()
    expect(lastParams()).toMatchObject({ receivable: true, page: 2 })
  })

  it('shows the load error when the request fails', async () => {
    reports.getFinanceOrders.mockRejectedValue(new Error('boom'))
    const w = await mountView()
    expect(w.text()).toContain('Gagal memuat daftar piutang.')
  })

  it('clicking a row navigates to the order detail page', async () => {
    const w = await mountView()
    await w.find('[data-testid="receivables-row-2"]').trigger('click')
    expect(push).toHaveBeenCalledWith('/orders/2')
  })
})
