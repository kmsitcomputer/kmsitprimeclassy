/**
 * Phase 2 UI recovery — the Google Sheets screen must show the dataset catalog the API sends:
 * the 47-column transaction worksheet is selectable first, columns carry SYSTEM/DERIVED/MANUAL,
 * the header preview follows the chosen order and Admin never sees the Agent-commission dataset.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import GoogleSheetsView from '@/views/dashboard/GoogleSheetsView.vue'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }))
vi.mock('@/api/client', () => ({
  http,
  ApiError: class ApiError extends Error {},
}))
const auth = vi.hoisted(() => ({ user: { id: 1, role: 'agen' } as { id: number; role: string } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => auth }))
vi.mock('@/layouts/DashboardLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))
vi.mock('@/components/ui/AgentPicker.vue', () => ({ default: { template: '<div />' } }))

const FIELDS = ['no', 'invoice_no', 'dp', 'money_position', 'settlement_note']
const LABELS: Record<string, string> = {
  no: 'No',
  invoice_no: 'No Faktur',
  dp: 'DP',
  money_position: 'Posisi Uang (Saat Transaksi)',
  settlement_note: 'Keterangan Pelunasan',
}
const KLASS: Record<string, string> = {
  no: 'SYSTEM',
  invoice_no: 'SYSTEM',
  dp: 'DERIVED',
  money_position: 'MANUAL',
  settlement_note: 'MANUAL',
}

function payload(extra: Record<string, unknown> = {}, withFinancial = true) {
  const datasets: Record<string, string[]> = {
    products: ['id', 'sku'],
    transaction_worksheet: FIELDS,
  }
  const catalog: Record<string, unknown> = {
    products: {
      key: 'products', name: 'Produk', description: 'Master produk.', column_count: 2,
      default_column_count: 0, columns: [], filters: [], legacy_alias: false,
      suggested_tabs: {}, protected_columns: [], restricted_for_admin: false,
    },
    transaction_worksheet: {
      key: 'transaction_worksheet',
      name: 'Laporan C - Worksheet Transaksi Staf (47 kolom)',
      description: 'Format worksheet staf SimpleV2.',
      column_count: 47, default_column_count: 5,
      columns: FIELDS.map((field) => ({ field, label: LABELS[field], classification: KLASS[field] })),
      filters: ['period', 'from', 'to', 'category_group'], legacy_alias: false,
      suggested_tabs: { COOKIES_2026: 'cookies', CAKE_2026: 'cake' },
      protected_columns: ['money_position', 'settlement_note'], restricted_for_admin: false,
    },
  }
  if (withFinancial) {
    datasets.financial_summary = ['agent_fee']
    catalog.financial_summary = { key: 'financial_summary', name: 'Ringkasan Keuangan', description: '', column_count: 1, default_column_count: 0, columns: [], filters: [], legacy_alias: false, suggested_tabs: {}, protected_columns: [], restricted_for_admin: true }
  }
  return {
    data: {
      data: {
        destinations: [{ id: 1, spreadsheet_id: 'abc', agent_id: 9, spreadsheet_title: null, connection_status: 'not_checked', last_tested_at: null, last_error_code: null }],
        configs: [], logs: [], datasets, dataset_catalog: catalog,
        money_positions: ['konsumen', 'keuangan', 'sales', 'korsal'],
        dataset_defaults: { transaction_worksheet: FIELDS.map((field) => ({ field, label: LABELS[field] })) },
        dataset_filters: { transaction_worksheet: ['period', 'from', 'to', 'category_group'] },
        enabled: true, service_account_email: 'svc@example.test',
        ...extra,
      },
    },
  }
}

async function mountView() {
  const wrapper = mount(GoogleSheetsView)
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  Object.values(http).forEach((fn) => fn.mockReset())
  auth.user = { id: 1, role: 'agen' }
})

describe('GoogleSheetsView', () => {
  it('selects the transaction worksheet by default and shows its catalog details', async () => {
    http.get.mockResolvedValue(payload())
    const wrapper = await mountView()

    const info = wrapper.get('[data-testid="dataset-info"]').text()
    expect(info).toContain('Laporan C - Worksheet Transaksi Staf (47 kolom)')
    expect(info).toContain('47 kolom tersedia')
    expect(info).toContain('5 dikirim')
    expect(info).toContain('MANUAL 2')
    expect(info).toContain('DERIVED 1')
    // Owner's four money-position categories, nothing else.
    expect(info).toContain('konsumen · keuangan · sales · korsal')
    expect(info).toContain('COOKIES_2026')
    expect(info).toContain('CAKE_2026')
    // Friendly names, not raw keys, in the picker.
    const options = wrapper.findAll('select')[1]!.findAll('option').map((o) => o.text())
    expect(options.some((t) => t.includes('Worksheet Transaksi Staf'))).toBe(true)
  })

  it('previews the header in the chosen order with each column classified', async () => {
    http.get.mockResolvedValue(payload())
    const wrapper = await mountView()

    const header = wrapper.get('[data-testid="header-preview"]').text()
    expect(header).toContain('1. No')
    expect(header).toContain('3. DP')
    expect(header).toContain('4. Posisi Uang (Saat Transaksi)')
    expect(wrapper.text()).toContain('MANUAL')
  })

  it('blocks saving a protected dataset without its identity column', async () => {
    http.get.mockResolvedValue(payload())
    const wrapper = await mountView()

    const noBox = wrapper.findAll('input[type="checkbox"]').find((el) => el.element.parentElement?.textContent?.includes('No') && !el.element.parentElement?.textContent?.includes('Faktur'))
    await noBox!.setValue(false)
    expect(wrapper.text()).toContain('Kolom "No" wajib dikirim')
    expect(wrapper.get('form:last-of-type button[type="submit"], form:last-of-type button:not([type])').attributes('disabled')).toBeDefined()
  })

  it('shows configuration status, row count and the sync error of a saved config', async () => {
    http.get.mockResolvedValue(payload({
      configs: [{ id: 5, name: 'Cookies 2026', destination_id: 1, tab: 'COOKIES_2026', dataset: 'transaction_worksheet', columns: FIELDS.map((f) => ({ field: f, label: LABELS[f] })), filters: { category_group: 'cookies' } }],
      logs: [{ id: 1, config_id: 5, dataset: 'transaction_worksheet', tab: 'COOKIES_2026', status: 'failed', rows_success: 0, rows_failed: 3, started_at: '2026-10-29T01:00:00Z', error_summary: 'Invalid mapping' }],
    }))
    const wrapper = await mountView()

    const status = wrapper.get('[data-testid="config-status"]').text()
    expect(status).toContain('Gagal')
    expect(wrapper.text()).toContain('Kesalahan sinkronisasi: Invalid mapping')
    expect(wrapper.text()).toContain('5 kolom')
    expect(wrapper.text()).toContain('COOKIES_2026')
  })

  it('does not offer the Agent-commission dataset when the API withholds it (Admin)', async () => {
    auth.user = { id: 2, role: 'admin' }
    http.get.mockResolvedValue(payload({}, false))
    const wrapper = await mountView()

    expect(wrapper.text()).not.toContain('Ringkasan Keuangan')
    expect(wrapper.text()).not.toContain('agent_fee')
  })
})
