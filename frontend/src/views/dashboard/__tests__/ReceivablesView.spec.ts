import { describe, expect, it, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import ReceivablesView from '@/views/dashboard/ReceivablesView.vue'

/** Improvement 10 / §8B centralized Piutang menu: rows, search, and the
 * Belum Lunas / Jatuh Tempo / Terlambat filters operate on the finance-orders
 * source; rows navigate to Detail Pesanan. */
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

function rows() {
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
  ]
}

async function mountView() {
  const w = mount(ReceivablesView, { global: { plugins: [i18n] } })
  await flushPromises()
  return w
}

describe('ReceivablesView', () => {
  beforeEach(() => {
    reports.getFinanceOrders.mockReset().mockResolvedValue({ orders: rows(), meta: { current_page: 1, last_page: 1, total: 3 } })
    push.mockClear()
  })

  it('lists finance-order rows with outstanding and due date', async () => {
    const w = await mountView()
    expect(w.text()).toContain('D-1')
    expect(w.text()).toContain('Budi')
    expect(w.text()).toContain('Siti')
  })

  it('Belum Lunas hides fully paid orders', async () => {
    const w = await mountView()
    await w.find('[data-testid="receivables-filter-unpaid"]').trigger('click')
    expect(w.text()).toContain('D-1')
    expect(w.text()).toContain('D-2')
    expect(w.text()).not.toContain('D-3')
  })

  it('Terlambat keeps only overdue outstanding orders', async () => {
    const w = await mountView()
    await w.find('[data-testid="receivables-filter-overdue"]').trigger('click')
    expect(w.text()).not.toContain('D-1')
    expect(w.text()).toContain('D-2')
    expect(w.text()).not.toContain('D-3')
  })

  it('Jatuh Tempo keeps only outstanding orders due today', async () => {
    const w = await mountView()
    await w.find('[data-testid="receivables-filter-due"]').trigger('click')
    expect(w.text()).toContain('D-1')
    expect(w.text()).not.toContain('D-2')
    expect(w.text()).not.toContain('D-3')
  })

  it('search matches order number or customer name', async () => {
    const w = await mountView()
    await w.find('[data-testid="receivables-search"]').setValue('siti')
    expect(w.text()).toContain('D-2')
    expect(w.text()).not.toContain('D-1')
    await w.find('[data-testid="receivables-search"]').setValue('D-3')
    expect(w.text()).toContain('Paid Customer')
    expect(w.text()).not.toContain('Siti')
  })

  it('clicking a row navigates to the order detail page', async () => {
    const w = await mountView()
    await w.find('[data-testid="receivables-row-2"]').trigger('click')
    expect(push).toHaveBeenCalledWith('/orders/2')
  })
})
