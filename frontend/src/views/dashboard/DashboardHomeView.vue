<script setup lang="ts">
/**
 * PC dashboard home — composed exactly like design/index.html (MASTER MOCKDESIGN FINAL):
 *   header (eyebrow / title / subtitle / tools)
 *   -> 4 KPI cards
 *   -> panels   [chart 1.35fr | distribution 1fr]
 *   -> lower    [table 1.45fr | queue 1fr]
 * Every figure is read from an existing, role-authorized endpoint. A panel with no
 * validated data source keeps its place and shows a labelled UNAVAILABLE / empty state —
 * never synthetic numbers (spec §3 KPI data contract). Navigation shortcuts live in the
 * "Antrean & akses cepat" queue panel, not as the page body.
 *   /dashboard/summary         -> super_admin, agen, admin, korsal, sales, SKS
 *   /reports/finance-*         -> super_admin, agen, admin, keuangan
 *   /reports/transactions      -> super_admin, agen, admin, korsal
 *   /reports/my-customers      -> sales, sales-kurir-sub
 *   /warehouse/fulfillment-proposals -> admin, gudang
 *   /warehouse/orders/diproses -> gudang
 *   /dispatch, /dispatch/couriers    -> agen, admin, koordinator-kurir
 *     (super_admin requires an explicit agent scope, unavailable on this summary)
 *   /kurir/orders              -> kurir, SKS, koordinator-kurir
 */
import { computed, onMounted, ref } from 'vue'
import DashboardLayout from '@/layouts/DashboardLayout.vue'
import AppIcon from '@/components/ui/AppIcon.vue'
import PcBarChart from '@/components/dashboard/PcBarChart.vue'
import { useAuthStore } from '@/stores/auth'
import { flattenNavItems } from '@/dashboard/navConfig'
import {
  getCrmVisitsReport,
  getDashboardKpis,
  getDashboardSummary,
  getFinanceOrders,
  getFinanceSummary,
  getOrdersReport,
  getSalesCustomersReport,
  type CrmFunnelReport,
  type DashboardKpis,
  type DashboardSummary,
  type FinanceOrderRow,
  type FinanceSummary,
  type SalesCustomerReportRow,
  type TransactionReportRow,
} from '@/api/reports'
import { listFulfillmentProposals, listWarehouseDiprosesOrders } from '@/api/warehouseFulfillment'
import { listDispatchCouriers, listDispatchQueue } from '@/api/dispatch'
import { listCourierOrders, listCourierReturns } from '@/api/courier'
import { listReturns, listWarehouseReturns } from '@/api/returns'
import { mySubStock } from '@/api/subStock'
import { commissionSummary, type CommissionSummary } from '@/api/commissions'
import { formatDate, formatRupiah, orderStatusLabel, paymentStatusLabel } from '@/utils/format'

const auth = useAuthStore()
const role = computed(() => auth.user?.role ?? '')

const ROLE_LABELS: Record<string, string> = {
  super_admin: 'Super Admin',
  agen: 'Agen',
  korsal: 'Korsal',
  sales: 'Sales',
  admin: 'Admin',
  keuangan: 'Keuangan',
  kurir: 'Kurir',
  gudang: 'Gudang',
  'koordinator-kurir': 'Koordinator Kurir',
  'sales-kurir-sub': 'Sales-Kurir-Sub',
  konsumen: 'Konsumen',
}

// Per design/roles.json — title + the three section names (chart / distribution / table).
const ROLE_DESIGN: Record<string, { title: string; sections: [string, string, string] }> = {
  super_admin: { title: 'Global Command Center', sections: ['Order Terbaru', 'Performa Agent', 'Risiko & Audit'] },
  agen: { title: 'Agent Business Center', sections: ['Target Cabang', 'Performa Sales', 'Order Cabang'] },
  admin: { title: 'Operations Control', sections: ['Antrean Order', 'Approval & Proposal', 'Fulfillment'] },
  keuangan: { title: 'Finance Workbench', sections: ['Antrean Bukti', 'Posisi Pembayaran', 'Refund & Rekonsiliasi'] },
  gudang: { title: 'Warehouse Operations', sections: ['Permintaan Gudang', 'Stok Kritis', 'Mutasi Barang'] },
  kurir: { title: 'Delivery Workspace', sections: ['Tugas Terjadwal', 'Status Pengiriman', 'Riwayat Tugas'] },
  'koordinator-kurir': { title: 'Dispatch Center', sections: ['Antrean Dispatch', 'Performa Kurir', 'Masalah Pengiriman'] },
  korsal: { title: 'Sales Team Hub', sections: ['Pipeline Tim', 'Follow-up', 'Performa Sales'] },
  sales: { title: 'Sales CRM Workspace', sections: ['Daftar Customer', 'Pipeline Penjualan', 'Aktivitas Hari Ini'] },
  'sales-kurir-sub': { title: 'Sales & Sub Delivery', sections: ['CRM Saya', 'Sub Stock', 'Tugas Pengiriman'] },
}
const design = computed(() => ROLE_DESIGN[role.value] ?? { title: 'Dashboard', sections: ['Aktivitas', 'Distribusi', 'Aktivitas Terbaru'] as [string, string, string] })

const quickLinks = computed(() => flattenNavItems().filter((item) => item.show(auth)))
const reportLink = computed(() => quickLinks.value.find((i) => i.key === 'reports' || i.key === 'finance') ?? null)

/* ------------------------------------------------------------------ */
/* Data state                                                          */
/* ------------------------------------------------------------------ */

type Phase = 'loading' | 'ready' | 'error'

const summary = ref<DashboardSummary | null>(null)
const finance = ref<FinanceSummary | null>(null)
const txRows = ref<TransactionReportRow[]>([])
const txTotal = ref<number | null>(null)
const financeRows = ref<FinanceOrderRow[]>([])
const salesCustomers = ref<SalesCustomerReportRow[]>([])
const salesCustomersTotal = ref<number | null>(null)
// Work-queue counts with a source contract (see docs/PHASE2-CHECKPOINT.md KPI matrix).
// Owner-approved KPI payload (/dashboard/kpis) + CRM team funnel + SKS sub stock. null = unavailable (never 0).
const kpis = ref<DashboardKpis | null>(null)
const crmFunnel = ref<CrmFunnelReport | null>(null)
const subStockUnits = ref<number | null>(null)
const returnsAwaitingReview = ref<number | null>(null) // admin: /admin/returns?status=requested,under_review
const returnsToInspect = ref<number | null>(null) // gudang: /warehouse/returns (approved, not yet inspected)
const returnsToPickup = ref<number | null>(null) // kurir: /kurir/returns (pending pickup)
// GAP-B "Perlu Tindakan": CRM follow-up jatuh tempo (today) + terlambat, dari /dashboard/kpis (follow_ups).
// Lalu konteks lainnya mengikuti. null = kpi belum tersedia.
const followUpCounts = computed(() => kpis.value?.follow_ups ?? null)
const proposalsPending = ref<number | null>(null)
const warehouseQueue = ref<number | null>(null)
const dispatchQueue = ref<number | null>(null)
const couriersActive = ref<number | null>(null)
const courierShipping = ref<number | null>(null)
const courierDelivered = ref<number | null>(null)
const courierRows = ref<{ id: number; ref: string; who: string; status: string }[]>([])
const phase = ref<Phase>('loading')

const series = ref<{ labels: string[]; values: number[] } | null>(null)
const seriesTitle = ref('')

function isoDaysAgo(n: number): string {
  const d = new Date()
  d.setDate(d.getDate() - n)
  return d.toISOString().slice(0, 10)
}

/** Aggregate real report rows into a 14-day label/value series (days present only). */
function dailySeries(rows: { order_date: string | null }[], pick: (r: Record<string, unknown>) => number) {
  const byDay = new Map<string, number>()
  for (const r of rows) {
    const day = (r.order_date ?? '').slice(0, 10)
    if (!day) continue
    byDay.set(day, (byDay.get(day) ?? 0) + pick(r as unknown as Record<string, unknown>))
  }
  const days = [...byDay.keys()].sort().slice(-14)
  if (!days.length) return null
  return { labels: days.map((d) => d.slice(5)), values: days.map((d) => Math.round((byDay.get(d) ?? 0) * 100) / 100) }
}

const R = computed(() => role.value)
const hasSummary = computed(() => ['super_admin', 'agen', 'admin', 'korsal', 'sales', 'sales-kurir-sub'].includes(R.value))
const hasTx = computed(() => ['super_admin', 'agen', 'admin', 'korsal'].includes(R.value))
const hasFinance = computed(() => ['super_admin', 'agen', 'admin', 'keuangan'].includes(R.value))
const hasSalesCustomers = computed(() => ['sales', 'sales-kurir-sub'].includes(R.value))
const hasCourier = computed(() => ['kurir', 'sales-kurir-sub', 'koordinator-kurir'].includes(R.value))
const hasDispatch = computed(() => ['agen', 'admin', 'koordinator-kurir'].includes(R.value))

let failures = 0
async function safe<T>(p: Promise<T>): Promise<T | null> {
  try {
    return await p
  } catch {
    failures++
    return null
  }
}

// Komisi per status (Owner §8: Pending WAJIB terlihat di dashboard).
// Sumber /commissions/summary — server sudah memotong baris sesuai hak
// beneficiary (KomisiController::allowedBeneficiaryRoles); Admin tidak pernah
// menerima komponen fee agen di sini maupun di tempat lain.
const commissions = ref<CommissionSummary | null>(null)
const hasCommissionStrip = computed(() => !['gudang', 'konsumen'].includes(R.value))
const commissionByStatus = computed(() => {
  const base: Record<'pending' | 'approved' | 'paid' | 'reversed', { count: number; amount: number }> = {
    pending: { count: 0, amount: 0 },
    approved: { count: 0, amount: 0 },
    paid: { count: 0, amount: 0 },
    reversed: { count: 0, amount: 0 },
  }
  for (const row of commissions.value?.breakdown ?? []) {
    const k = (row.status ?? '').toLowerCase()
    if (k === 'pending' || k === 'approved' || k === 'paid' || k === 'reversed') {
      base[k].count += Number(row.count ?? 0)
      base[k].amount += Number(row.total_amount ?? 0)
    }
    // Unknown statuses are deliberately NOT silently folded into the four
    // canonical buckets; the ledger enum is fixed at exactly these values.
  }
  return base
})

onMounted(async () => {
  const jobs: Promise<void>[] = []
  if (hasSummary.value) jobs.push(safe(getDashboardSummary()).then((v) => void (summary.value = v)))
  if (hasFinance.value) {
    jobs.push(safe(getFinanceSummary({})).then((v) => void (finance.value = v)))
    jobs.push(
      safe(getFinanceOrders({ from: isoDaysAgo(13), to: isoDaysAgo(0) })).then((res) => {
        financeRows.value = res?.orders ?? []
      }),
    )
  }
  if (hasTx.value) {
    jobs.push(
      safe(getOrdersReport({ from: isoDaysAgo(13), to: isoDaysAgo(0), per_page: 200 })).then((res) => {
        txRows.value = res?.items ?? []
        txTotal.value = res?.total ?? null
      }),
    )
  }
  if (hasSalesCustomers.value) {
    jobs.push(
      safe(getSalesCustomersReport({ per_page: 5 })).then((res) => {
        if (res) {
          salesCustomers.value = res.items
          salesCustomersTotal.value = res.total
        }
      }),
    )
  }
  if (['admin', 'gudang'].includes(R.value)) {
    jobs.push(safe(listFulfillmentProposals({ status: 'pending' })).then((v) => void (proposalsPending.value = v?.meta.total ?? null)))
  }
  if (['agen', 'korsal', 'sales', 'sales-kurir-sub', 'gudang', 'kurir'].includes(R.value)) {
    jobs.push(safe(getDashboardKpis()).then((v) => void (kpis.value = v)))
  }
  if (R.value === 'korsal') {
    jobs.push(safe(getCrmVisitsReport({})).then((v) => void (crmFunnel.value = v)))
  }
  if (R.value === 'sales-kurir-sub') {
    jobs.push(safe(mySubStock()).then((v) => void (subStockUnits.value = v ? v.stocks.reduce((n, r) => n + Number(r.physical ?? 0), 0) : null)))
  }
  if (['admin', 'gudang'].includes(R.value)) {
    jobs.push(safe(listReturns(1, { status: 'requested,under_review' })).then((v) => void (returnsAwaitingReview.value = v?.meta.total ?? null)))
  }
  if (R.value === 'kurir') {
    jobs.push(safe(listCourierReturns(1)).then((v) => void (returnsToPickup.value = v?.meta.total ?? null)))
  }
  if (R.value === 'gudang') {
    jobs.push(safe(listWarehouseReturns(1)).then((v) => void (returnsToInspect.value = v?.meta.total ?? null)))
    jobs.push(safe(listWarehouseDiprosesOrders(1)).then((v) => void (warehouseQueue.value = v?.meta.total ?? null)))
  }
  if (hasDispatch.value) {
    jobs.push(safe(listDispatchQueue(1)).then((v) => void (dispatchQueue.value = v?.meta.total ?? null)))
    if (R.value === 'koordinator-kurir') {
      jobs.push(safe(listDispatchCouriers()).then((v) => void (couriersActive.value = v ? v.length : null)))
    }
  }
  if (hasCourier.value) {
    jobs.push(
      safe(listCourierOrders(1, 'dikirim')).then((v) => {
        courierShipping.value = v?.meta.total ?? null
        courierRows.value = (v?.orders ?? []).slice(0, 6).map((o) => ({
          id: o.id,
          ref: o.order_no,
          who: [o.village, o.district].filter(Boolean).join(', ') || '—',
          status: o.status,
        }))
      }),
    )
    jobs.push(safe(listCourierOrders(1, 'terkirim')).then((v) => void (courierDelivered.value = v?.meta.total ?? null)))
  }
  if (hasCommissionStrip.value) {
    jobs.push(safe(commissionSummary({})).then((v) => void (commissions.value = v)))
  }
  await Promise.all(jobs)

  if (hasFinance.value && R.value === 'keuangan') {
    const s = dailySeries(financeRows.value, (r) => Number(r.total_paid ?? 0))
    if (s) {
      series.value = s
      seriesTitle.value = 'Pembayaran diterima harian — 14 hari terakhir (laporan keuangan)'
    }
  } else if (hasTx.value) {
    const s = dailySeries(txRows.value, (r) => Number(r.subtotal ?? 0))
    if (s) {
      series.value = s
      seriesTitle.value = 'Pendapatan harian — 14 hari terakhir (baris laporan transaksi)'
    }
  }
  phase.value = failures > 0 && !series.value && !summary.value && !finance.value ? 'error' : 'ready'
})

/* ------------------------------------------------------------------ */
/* KPI composition — mockdesign labels; real values or UNAVAILABLE      */
/* ------------------------------------------------------------------ */

interface KpiCard {
  label: string
  value: string | null
  hint: string
  arCaveat?: boolean
}

const num = (v: number | null | undefined) => (v === null || v === undefined ? null : String(v))
const rp = (v: number | null | undefined) => (v === null || v === undefined ? null : formatRupiah(v))

const kpiCards = computed<KpiCard[]>(() => {
  const s = summary.value
  const f = finance.value
  switch (R.value) {
    case 'super_admin':
      return [
        { label: 'Total Penjualan', value: rp(s?.total_revenue), hint: 'Ringkasan dashboard' },
        { label: 'Total Order', value: num(s?.total_orders), hint: 'Ringkasan dashboard' },
        { label: 'Pembayaran Diterima', value: rp(f?.total_received), hint: 'Laporan keuangan' },
        { label: 'Refund', value: rp(f?.total_refunds), hint: 'Laporan keuangan' },
      ]
    case 'agen':
      return [
        { label: 'Penjualan Cabang', value: rp(s?.total_revenue), hint: 'Ringkasan cabang' },
        { label: 'Total Order', value: num(s?.total_orders), hint: 'Ringkasan cabang' },
        { label: 'Sales Aktif', value: num(s?.total_sales), hint: 'Ringkasan cabang' },
        { label: 'Komisi Cabang', value: rp(kpis.value?.own_fee?.total), hint: 'Fee pribadi Agen: pending + disetujui + dibayar (reversed tidak dihitung)' },
      ]
    case 'admin':
      return [
        { label: 'Total Order', value: num(s?.total_orders), hint: 'Ringkasan cabang' },
        { label: 'Approval Gudang', value: num(proposalsPending.value), hint: 'Proposal fulfillment pending' },
        { label: 'Belum Ditugaskan', value: num(dispatchQueue.value), hint: 'Antrean dispatch' },
        { label: 'Retur Tertunda', value: num(returnsAwaitingReview.value), hint: 'Retur menunggu review Admin' },
      ]
    case 'keuangan':
      return [
        { label: 'Pembayaran Diterima', value: rp(f?.total_received), hint: 'Laporan keuangan' },
        { label: 'Outstanding', value: rp(f?.total_outstanding), hint: 'Piutang kanonis (total − dibayar, batal = 0)' },
        { label: 'DP Outstanding', value: rp(f?.total_dp_outstanding), hint: 'Piutang kanonis order DP sebagian' },
        { label: 'Refund', value: rp(f?.total_refunds), hint: 'Laporan keuangan' },
      ]
    case 'gudang':
      return [
        { label: 'Stok Tersedia', value: num(kpis.value?.available_stock?.units), hint: 'Stok layak jual − reservasi (unit, seluruh produk cabang)' },
        { label: 'Proposal Pending', value: num(proposalsPending.value), hint: 'Proposal fulfillment' },
        { label: 'Order Diproses', value: num(warehouseQueue.value), hint: 'Antrean gudang' },
        { label: 'Retur Inspeksi', value: num(returnsToInspect.value), hint: 'Retur disetujui, menunggu inspeksi' },
      ]
    case 'kurir':
      return [
        { label: 'Tugas Hari Ini', value: num(kpis.value?.tasks_today?.deliveries), hint: 'Pengiriman terjadwal hari ini (WIB); pickup retur belum punya jadwal di data' },
        { label: 'Sedang Dikirim', value: num(courierShipping.value), hint: 'Tugas saya' },
        { label: 'Terkirim', value: num(courierDelivered.value), hint: 'Tugas saya' },
        { label: 'Retur Pickup', value: num(returnsToPickup.value), hint: 'Retur menunggu pickup' },
      ]
    case 'koordinator-kurir':
      return [
        { label: 'Belum Ditugaskan', value: num(dispatchQueue.value), hint: 'Antrean dispatch' },
        { label: 'Kurir Aktif', value: num(couriersActive.value), hint: 'Kurir cabang' },
        { label: 'Dalam Perjalanan', value: num(courierShipping.value), hint: 'Tugas saya' },
        { label: 'Terkirim', value: num(courierDelivered.value), hint: 'Tugas saya' },
      ]
    case 'korsal':
      return [
        { label: 'Penjualan Tim', value: rp(s?.total_revenue), hint: 'Ringkasan tim' },
        { label: 'Total Order', value: num(s?.total_orders), hint: 'Ringkasan tim' },
        { label: 'Sales Tim', value: num(s?.total_sales), hint: 'Ringkasan tim' },
        { label: 'Komisi Tim', value: rp(kpis.value?.team_commissions?.totals.total), hint: 'Fee Sales per anggota (rincian di bawah) — bukan fee Korsal' },
      ]
    case 'sales':
      return [
        { label: 'Customer Saya', value: num(salesCustomersTotal.value), hint: 'Konsumen saya' },
        { label: 'Total Order', value: num(s?.total_orders), hint: 'Ringkasan' },
        {
          label: 'Perlu Tindakan',
          value: kpis.value?.follow_ups ? String(kpis.value.follow_ups.due_today + kpis.value.follow_ups.overdue) : null,
          hint: kpis.value?.follow_ups ? `Follow-up (CRM) jatuh tempo hari ini ${kpis.value.follow_ups.due_today} · terlambat ${kpis.value.follow_ups.overdue}` : 'CRM follow-up',
        },
        { label: 'Deal & Komisi', value: kpis.value?.deals ? `${kpis.value.deals.count} deal` : null, hint: kpis.value?.commission ? `Komisi (ledger): ${formatRupiah(kpis.value.commission.total)} · nilai deal ${formatRupiah(kpis.value.deals?.value ?? 0)}` : 'Deal = order teratribusi; komisi dari ledger' },
      ]
    case 'sales-kurir-sub':
      return [
        { label: 'Customer Saya', value: num(salesCustomersTotal.value), hint: 'Konsumen saya' },
        { label: 'Total Order', value: num(s?.total_orders), hint: 'Ringkasan' },
        { label: 'Sedang Dikirim', value: num(courierShipping.value), hint: 'Tugas delivery' },
        { label: 'Stok Sub', value: num(subStockUnits.value), hint: 'Stok fisik teralokasi di Sub Location Anda (belum terjual/diretur)' },
      ]
    default:
      return []
  }
})

// CRM Tim (Korsal): stage semantics are the funnel's own (CrmVisitReportService): customers at their LATEST visit status.
// Prospect = still-open stages 0–6; Deal = stage 7; Nolak = N (reported apart, never a prospect). "Perlu Tindakan (Tim)"
// = follow-up due/overdue (GAP-B, /dashboard/kpis follow_ups). Scope = Korsal's own + authorized downline, server-side.
const crmTeam = computed(() => {
  const f = crmFunnel.value
  if (!f) return null
  const b = f.totals.buckets
  const prospect = ['0', '1', '2', '3', '4', '5', '6'].reduce((n, k) => n + Number(b[k] ?? 0), 0)
  return { prospect, deal: Number(b['7'] ?? 0), nolak: Number(b['N'] ?? 0), customers: f.totals.customers }
})
const teamMembers = computed(() => kpis.value?.team_commissions?.members ?? [])

const KPI_SYMBOLS = ['▥', '◈', '▣', '◇']
const showArNotice = computed(() => R.value === 'keuangan' || R.value === 'super_admin')

/* ------------------------------------------------------------------ */
/* Distribution panel (mockdesign .progressrow) — real rows only         */
/* ------------------------------------------------------------------ */

interface Dist {
  label: string
  count: number
  pct: number
}

function toDist(counts: Map<string, number>): Dist[] {
  const total = [...counts.values()].reduce((a, b) => a + b, 0)
  if (!total) return []
  return [...counts.entries()]
    .sort((a, b) => b[1] - a[1])
    .slice(0, 6)
    .map(([label, count]) => ({ label, count, pct: Math.round((count / total) * 100) }))
}

const distribution = computed<{ rows: Dist[]; basis: string } | null>(() => {
  if (R.value === 'keuangan' || (hasFinance.value && !hasTx.value && financeRows.value.length)) {
    const m = new Map<string, number>()
    for (const o of financeRows.value) {
      const k = paymentStatusLabel(o.payment_status)
      m.set(k, (m.get(k) ?? 0) + 1)
    }
    const rows = toDist(m)
    return rows.length ? { rows, basis: `Status pembayaran · ${financeRows.value.length} order (14 hari)` } : null
  }
  if (hasTx.value && txRows.value.length) {
    const byOrder = new Map<number, string>()
    for (const r of txRows.value) byOrder.set(r.order_id, r.order_status)
    const m = new Map<string, number>()
    for (const st of byOrder.values()) {
      const k = orderStatusLabel(st)
      m.set(k, (m.get(k) ?? 0) + 1)
    }
    const truncated = txTotal.value !== null && txTotal.value > txRows.value.length
    return {
      rows: toDist(m),
      basis: `Status order · ${byOrder.size} order (14 hari)${truncated ? ' · sampel 200 baris pertama' : ''}`,
    }
  }
  return null
})

/* ------------------------------------------------------------------ */
/* Lower table (mockdesign "Aktivitas Terbaru") — real rows only         */
/* ------------------------------------------------------------------ */

interface TableData {
  heads: [string, string, string, string]
  rows: { key: string | number; cells: [string, string, string]; status: string; tone: 'ok' | 'bad' | 'warn' }[]
}

function tone(status: string): 'ok' | 'bad' | 'warn' {
  if (['terkirim', 'selesai', 'paid', 'lunas', 'completed'].includes(status)) return 'ok'
  if (['dibatalkan', 'cancelled', 'ditolak', 'rejected', 'failed', 'unpaid'].includes(status)) return 'bad'
  return 'warn'
}

const tableData = computed<TableData | null>(() => {
  if (R.value === 'keuangan' && financeRows.value.length) {
    return {
      heads: ['No. Order', 'Customer', 'Tanggal', 'Pembayaran'],
      rows: financeRows.value.slice(0, 6).map((o) => ({
        key: o.order_id,
        cells: [o.order_no, o.customer ?? '—', formatDate(o.order_date)],
        status: paymentStatusLabel(o.payment_status),
        tone: tone(o.payment_status),
      })),
    }
  }
  if (hasTx.value && txRows.value.length) {
    const seen = new Set<number>()
    const rows: TableData['rows'] = []
    for (const r of [...txRows.value].sort((a, b) => (b.order_date ?? '').localeCompare(a.order_date ?? ''))) {
      if (seen.has(r.order_id)) continue
      seen.add(r.order_id)
      rows.push({
        key: r.order_id,
        cells: [r.order_no, r.customer || '—', formatDate(r.order_date)],
        status: orderStatusLabel(r.order_status),
        tone: tone(r.order_status),
      })
      if (rows.length >= 6) break
    }
    return { heads: ['No. Order', 'Customer', 'Tanggal', 'Status'], rows }
  }
  if (hasSalesCustomers.value && salesCustomers.value.length) {
    return {
      heads: ['Customer', 'Order', 'Fee', 'Status'],
      rows: salesCustomers.value.map((c) => ({
        key: c.konsumen_id,
        cells: [c.konsumen_name, String(c.order_count), formatRupiah(Number(c.total_fee || 0))],
        status: 'Aktif',
        tone: 'ok' as const,
      })),
    }
  }
  if (hasCourier.value && courierRows.value.length) {
    return {
      heads: ['No. Order', 'Tujuan', 'Tanggal', 'Status'],
      rows: courierRows.value.map((o) => ({
        key: o.id,
        cells: [o.ref, o.who, '—'],
        status: orderStatusLabel(o.status),
        tone: tone(o.status),
      })),
    }
  }
  return null
})

const unavailableReason = computed(() => {
  if (phase.value === 'loading') return null
  if (phase.value === 'error') return 'Gagal memuat data. Muat ulang halaman untuk mencoba lagi.'
  return 'Role ini belum memiliki sumber data terverifikasi untuk panel ini (menunggu kontrak metrik/paket bisnis). Tidak ada angka contoh yang ditampilkan.'
})

const loadingKpis = computed(() => phase.value === 'loading')
</script>

<template>
  <DashboardLayout>
    <!-- Header (mockdesign .header) -->
    <div class="pc-header">
      <div>
        <div class="pc-eyebrow">PRIMECLASSY / {{ ROLE_LABELS[role] ?? role }}</div>
        <h1 class="pc-h1">{{ design.title }}</h1>
        <p class="pc-subtitle">
          Halo, {{ auth.user?.name }} · Workspace {{ ROLE_LABELS[role] ?? role }} · ringkasan sesuai kewenangan role
        </p>
      </div>
      <div v-if="reportLink" class="pc-tools">
        <RouterLink :to="{ name: reportLink.routeName }" class="pc-btn pc-btn-primary">Lihat {{ reportLink.label.toLowerCase() }}</RouterLink>
      </div>
    </div>

    <!-- KPI row -->
    <section class="pc-kpis" aria-label="Indikator kinerja">
      <template v-if="loadingKpis">
        <article v-for="i in 4" :key="i" class="pc-card pc-kpi animate-pulse" aria-busy="true">
          <div class="pc-kpi-skel" />
        </article>
      </template>
      <template v-else>
        <article v-for="(card, i) in kpiCards" :key="card.label" class="pc-card pc-kpi">
          <span class="pc-kpi-symbol" aria-hidden="true">{{ KPI_SYMBOLS[i % 4] }}</span>
          <div class="pc-kpi-label">{{ card.label }}</div>
          <strong class="pc-kpi-value">{{ card.value ?? '—' }}</strong>
          <small :class="card.value === null ? 'pc-kpi-na' : 'pc-kpi-hint'">
            {{ card.value === null ? 'Belum tersedia · ' + card.hint : '● ' + card.hint }}
          </small>
        </article>
      </template>
    </section>

    <p v-if="showArNotice && !loadingKpis" class="pc-notice">
      Outstanding/DP memakai piutang kanonis (total − dibayar, order batal = 0) — sama dengan Laporan Keuangan.
      Seri grafik berasal dari baris laporan yang tersedia.
    </p>

    <!-- Korsal: CRM Tim + Komisi Tim per anggota. Fee hanya Sales fee tiap penerima; tidak ada fee peran Korsal. -->
    <template v-if="R === 'korsal'">
      <section class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="CRM tim" data-testid="crm-team">
        <div class="rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
          <p class="text-xs text-stone-500">Prospect Tim</p>
          <p class="font-display text-lg font-semibold">{{ crmTeam ? crmTeam.prospect : '—' }}</p>
          <p class="text-[11px] text-stone-400">Customer tahap 0–6 (aktif)</p>
        </div>
        <div class="rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
          <p class="text-xs text-stone-500">Perlu Tindakan (Tim)</p>
          <p class="font-display text-lg font-semibold">{{ followUpCounts ? followUpCounts.due_today + followUpCounts.overdue : '—' }}</p>
          <p class="text-[11px] text-stone-400">Follow-up jatuh tempo hari ini + terlambat (CRM tim)</p>
        </div>
        <div class="rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
          <p class="text-xs text-stone-500">Deal Tim</p>
          <p class="font-display text-lg font-semibold">{{ crmTeam ? crmTeam.deal : '—' }}</p>
          <p class="text-[11px] text-stone-400">Customer tahap 7 (Deal)</p>
        </div>
        <div class="rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
          <p class="text-xs text-stone-500">Nolak</p>
          <p class="font-display text-lg font-semibold">{{ crmTeam ? crmTeam.nolak : '—' }}</p>
          <p class="text-[11px] text-stone-400">Dipisah dari Prospect</p>
        </div>
      </section>

      <section class="mb-4 overflow-x-auto rounded-xl border border-stone-200 bg-white dark:border-stone-800 dark:bg-stone-900" aria-label="Komisi tim per anggota" data-testid="team-commissions">
        <p class="border-b border-stone-200 px-3 py-2 text-xs font-semibold text-stone-500 dark:border-stone-800">Komisi Tim per anggota (Sales fee, ledger)</p>
        <table class="w-full text-left text-sm">
          <thead class="text-xs text-stone-500">
            <tr>
              <th class="px-3 py-2">Anggota</th><th class="px-3 py-2">Peran</th><th class="px-3 py-2 text-right">Pending</th>
              <th class="px-3 py-2 text-right">Disetujui</th><th class="px-3 py-2 text-right">Dibayar</th><th class="px-3 py-2 text-right">Total</th>
              <th class="px-3 py-2 text-right">Order (jaringan)</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-stone-100 dark:divide-stone-800">
            <tr v-for="m in teamMembers" :key="m.user_id">
              <td class="px-3 py-2">{{ m.name }}<span v-if="m.is_self" class="ml-1 text-[11px] text-stone-400">(Anda — fee Sales sendiri)</span></td>
              <td class="px-3 py-2">{{ m.role }}</td>
              <td class="px-3 py-2 text-right">{{ formatRupiah(m.pending) }}</td>
              <td class="px-3 py-2 text-right">{{ formatRupiah(m.approved) }}</td>
              <td class="px-3 py-2 text-right">{{ formatRupiah(m.paid) }}</td>
              <td class="px-3 py-2 text-right font-medium">{{ formatRupiah(m.total) }}</td>
              <td class="px-3 py-2 text-right">{{ m.order_count }}</td>
            </tr>
            <tr v-if="!kpis"><td colspan="7" class="px-3 py-4 text-center text-stone-400">Data komisi tim tidak tersedia.</td></tr>
          </tbody>
        </table>
        <p class="px-3 py-2 text-[11px] text-stone-400">Total = pending + disetujui + dibayar; reversed tidak dihitung. Jumlah order adalah metrik jaringan terpisah dari komisi.</p>
      </section>
    </template>

    <!-- Komisi per status (Pending wajib tampil; Approved/Paid/Reversed
         terpisah — tidak pernah disamakan, settlement tetap eksternal) -->
    <div v-if="hasCommissionStrip && commissions" class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
      <div class="rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
        <p class="text-[11px] font-medium uppercase tracking-wide text-amber-600">Pending</p>
        <p class="font-display text-lg font-semibold text-stone-900 dark:text-stone-50">{{ formatRupiah(commissionByStatus.pending.amount) }}</p>
        <p class="text-[11px] text-stone-400">{{ commissionByStatus.pending.count }} entri</p>
      </div>
      <div class="rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
        <p class="text-[11px] font-medium uppercase tracking-wide text-sky-600">Approved</p>
        <p class="font-display text-lg font-semibold text-stone-900 dark:text-stone-50">{{ formatRupiah(commissionByStatus.approved.amount) }}</p>
        <p class="text-[11px] text-stone-400">{{ commissionByStatus.approved.count }} entri</p>
      </div>
      <div class="rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
        <p class="text-[11px] font-medium uppercase tracking-wide text-emerald-600">Paid</p>
        <p class="font-display text-lg font-semibold text-stone-900 dark:text-stone-50">{{ formatRupiah(commissionByStatus.paid.amount) }}</p>
        <p class="text-[11px] text-stone-400">{{ commissionByStatus.paid.count }} entri</p>
      </div>
      <div class="rounded-xl border border-stone-200 bg-white p-3 dark:border-stone-800 dark:bg-stone-900">
        <p class="text-[11px] font-medium uppercase tracking-wide text-red-600">Reversed</p>
        <p class="font-display text-lg font-semibold text-stone-900 dark:text-stone-50">{{ formatRupiah(commissionByStatus.reversed.amount) }}</p>
        <p class="text-[11px] text-stone-400">{{ commissionByStatus.reversed.count }} entri</p>
      </div>
    </div>

    <!-- Panels: chart + distribution -->
    <section class="pc-panels">
      <article class="pc-card">
        <div class="pc-panelhead">
          <h2>{{ design.sections[0] }} — tren</h2>
          <span class="pc-tag">14 hari</span>
        </div>
        <div v-if="phase === 'loading'" class="pc-empty" aria-busy="true">Memuat grafik…</div>
        <template v-else-if="series">
          <PcBarChart :labels="series.labels" :values="series.values" :figure-label="seriesTitle" value-format="rupiah" />
          <div class="pc-legend"><i /> {{ seriesTitle }}</div>
        </template>
        <div v-else class="pc-empty" role="status">
          <strong>Grafik belum tersedia</strong>
          <span>{{
            hasTx || R === 'keuangan'
              ? phase === 'error'
                ? unavailableReason
                : 'Tidak ada transaksi pada 14 hari terakhir.'
              : unavailableReason
          }}</span>
        </div>
      </article>

      <article class="pc-card">
        <div class="pc-panelhead">
          <h2>{{ design.sections[1] }}</h2>
          <span class="pc-tag">Ringkasan</span>
        </div>
        <div v-if="phase === 'loading'" class="pc-empty" aria-busy="true">Memuat…</div>
        <template v-else-if="distribution && distribution.rows.length">
          <div v-for="d in distribution.rows" :key="d.label" class="pc-progressrow">
            <span>{{ d.label }}</span>
            <div class="pc-track" role="img" :aria-label="`${d.label}: ${d.count} (${d.pct}%)`">
              <div class="pc-fill" :style="{ width: d.pct + '%' }" />
            </div>
            <span>{{ d.count }} · {{ d.pct }}%</span>
          </div>
          <div class="pc-legend">{{ distribution.basis }}</div>
        </template>
        <div v-else class="pc-empty" role="status">
          <strong>Distribusi belum tersedia</strong>
          <span>{{ unavailableReason ?? 'Tidak ada data pada periode ini.' }}</span>
        </div>
      </article>
    </section>

    <!-- Lower: table + queue -->
    <section class="pc-lower">
      <article class="pc-card">
        <div class="pc-panelhead">
          <h2>{{ design.sections[2] }}</h2>
          <span class="pc-tag">Terbaru</span>
        </div>
        <div v-if="phase === 'loading'" class="pc-empty" aria-busy="true">Memuat…</div>
        <div v-else-if="tableData" class="overflow-x-auto">
          <table class="pc-table">
            <thead>
              <tr>
                <th v-for="h in tableData.heads" :key="h">{{ h }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in tableData.rows" :key="row.key">
                <td v-for="(c, i) in row.cells" :key="i">{{ c }}</td>
                <td>
                  <span class="pc-pill" :class="'pc-pill-' + row.tone">
                    <span aria-hidden="true">{{ row.tone === 'ok' ? '✓' : row.tone === 'bad' ? '✕' : '●' }}</span>
                    {{ row.status }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="pc-empty" role="status">
          <strong>Belum ada data untuk ditampilkan</strong>
          <span>{{ unavailableReason ?? 'Belum ada aktivitas.' }}</span>
        </div>
      </article>

      <article class="pc-card">
        <div class="pc-panelhead">
          <h2>Antrean & akses cepat</h2>
          <span class="pc-tag">{{ quickLinks.length }} menu</span>
        </div>
        <ul class="pc-queue">
          <li v-for="item in quickLinks" :key="item.key">
            <RouterLink :to="{ name: item.routeName }" class="pc-queue-link">
              <span class="pc-queue-ico"><AppIcon :name="item.icon" :size="16" /></span>
              <span class="pc-queue-label">{{ item.label }}</span>
              <span class="pc-tag">Buka</span>
            </RouterLink>
          </li>
        </ul>
      </article>
    </section>
  </DashboardLayout>
</template>
