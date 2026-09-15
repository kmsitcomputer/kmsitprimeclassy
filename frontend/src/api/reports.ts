import { http } from './client'
import type { ApiEnvelope } from './client'

export interface TransactionReportRow {
  order_id: number
  order_item_id: number
  order_no: string
  agent_id: number
  order_date: string
  sku: string | null
  product: string
  unit_price: number
  quantity: number
  item_status: string
  subtotal: number
  customer: string
  delivery_date: string
  courier: string
  order_status: string
  sales: string
  korsal: string
}

export interface ReportFilters {
  from?: string
  to?: string
  /** super_admin only — narrows an otherwise all-agents view to one agent; ignored (never a bypass) for every other role. */
  agent_id?: number
  sales_id?: number
  korsal_id?: number
  courier_id?: number
  status?: string
  item_status?: string
  order_status?: string
  delivery_date_from?: string
  delivery_date_to?: string
  search?: string
  page?: number
  per_page?: number
}

function reportUrl(path: string, filters: ReportFilters, exportXlsx: boolean): string {
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(filters)) {
    if (value !== undefined && value !== null && value !== '') params.set(key, String(value))
  }
  if (exportXlsx) params.set('export', 'xlsx')
  const qs = params.toString()
  return `/reports/${path}${qs ? `?${qs}` : ''}`
}

export async function getTransactionsReport(filters: ReportFilters = {}) {
  const { data } = await http.get<ApiEnvelope<TransactionReportRow[]>>(reportUrl('transactions', filters, false))
  return data.data
}

export interface SalesKorsalFeeRow {
  sales_id?: number
  sales_name?: string
  korsal_id?: number
  korsal_name?: string
  agent_id: number
  total_fee: string
  transaction_count: number
  /** Only present on korsal rows — distinct count of sales under this korsal who sold, never a transaction accumulation. */
  active_sales_count?: number
}

export async function getSalesKorsalFeeReport(filters: ReportFilters = {}) {
  const { data } = await http.get<ApiEnvelope<{ sales: SalesKorsalFeeRow[]; korsal: SalesKorsalFeeRow[] }>>(
    reportUrl('fees/sales-korsal', filters, false),
  )
  return data.data
}

export interface CancellationsRefundsReport {
  cancellations: Record<string, unknown>[]
  adjustments: Record<string, unknown>[]
  returns: Record<string, unknown>[]
}

export async function getCancellationsRefundsReport(filters: ReportFilters = {}) {
  const { data } = await http.get<ApiEnvelope<CancellationsRefundsReport>>(
    reportUrl('cancellations-refunds', filters, false),
  )
  return data.data
}

export interface CourierFeeRow {
  courier_id: number
  courier_name: string
  agent_id: number
  total_fee: string
  delivery_count: number
}

export async function getCourierFeeReport(filters: ReportFilters = {}) {
  const { data } = await http.get<ApiEnvelope<CourierFeeRow[]>>(reportUrl('fees/courier', filters, false))
  return data.data
}

export interface AgentFeeRow {
  agent_id: number
  agent_name: string
  total_fee: string
  transaction_count: number
  /** Distinct count of this agent's own sales-role users who sold in range — never a transaction accumulation. */
  active_sales_count: number
}

export async function getAgentFeeReport(filters: ReportFilters = {}) {
  const { data } = await http.get<ApiEnvelope<AgentFeeRow[]>>(reportUrl('fees/agent', filters, false))
  return data.data
}

export interface PaymentStatusRow {
  agent_id: number | null
  agent_name: string | null
  payment_status: 'unpaid' | 'pending_verification' | 'paid' | 'partially_refunded' | 'refunded' | 'failed'
  order_count: number
  total_amount: string
}

export async function getPaymentStatusReport(filters: ReportFilters = {}) {
  const { data } = await http.get<ApiEnvelope<PaymentStatusRow[]>>(reportUrl('payment-status', filters, false))
  return data.data
}

export interface FinanceSummary {
  total_orders: number
  /** Value of fully-completed ("Lunas") orders only — excludes still-partial DP orders. */
  total_transactions: number
  /** Actual cash collected so far across every payment status, DP included (canonical: Order.paid_amount). */
  total_received: number
  total_refunds: number
  /** Still owed (unpaid / pending verification / DP partial). */
  total_outstanding: number
  /** The subset of total_outstanding that's specifically an unsettled DP balance. */
  total_dp_outstanding: number
}

export async function getFinanceSummary(filters: ReportFilters = {}) {
  const { data } = await http.get<ApiEnvelope<FinanceSummary>>(reportUrl('finance-summary', filters, false))
  return data.data
}

export interface CourierPerAgentRow {
  agent_id: number
  agent_name: string
  courier_count: number
}

export async function getCouriersPerAgentReport(filters: ReportFilters = {}) {
  const { data } = await http.get<ApiEnvelope<CourierPerAgentRow[]>>(reportUrl('couriers-per-agent', filters, false))
  return data.data
}

export type ReportKey =
  | 'transactions'
  | 'fees/sales-korsal'
  | 'cancellations-refunds'
  | 'fees/courier'
  | 'fees/agent'
  | 'payment-status'
  | 'customers'
  | 'korsal'
  | 'sales'
  | 'couriers'

/** Opens the .xlsx export in a new tab — the browser handles the download using the active session cookie. */
export function downloadReportXlsx(report: ReportKey, filters: ReportFilters = {}) {
  const apiBase = (window.__APP_CONFIG__?.API_URL ?? import.meta.env.VITE_API_URL ?? '') + '/api/v1'
  window.open(apiBase + reportUrl(report, filters, true), '_blank')
}

export interface PaginatedReport<T> {
  items: T[]
  currentPage: number
  lastPage: number
  total: number
}

async function getPaginated<T>(report: ReportKey, filters: ReportFilters): Promise<PaginatedReport<T>> {
  const { data } = await http.get<ApiEnvelope<T[]>>(reportUrl(report, filters, false))
  const meta = data.meta ?? {}
  return {
    items: data.data,
    currentPage: Number(meta.current_page ?? 1),
    lastPage: Number(meta.last_page ?? 1),
    total: Number(meta.total ?? data.data.length),
  }
}

/** "Total Konsumen yang melakukan transaksi" — every konsumen with >=1 order in the actor's branch. */
export interface CustomerReportRow {
  konsumen_id: number
  konsumen_name: string
  konsumen_phone: string
  order_count: number
  total_spent: string
  last_order_at: string
}

export function getCustomersReport(filters: ReportFilters = {}) {
  return getPaginated<CustomerReportRow>('customers', filters)
}

/** Full korsal roster — appears even with zero transactions in range. */
export interface KorsalReportRow {
  korsal_id: number
  korsal_name: string
  korsal_phone: string
  status: string
  active_sales_count: number
  transaction_count: number
  total_sales_fee: string
}

export function getKorsalReport(filters: ReportFilters = {}) {
  return getPaginated<KorsalReportRow>('korsal', filters)
}

/** Full sales roster — appears even with zero transactions in range. */
export interface SalesReportRow {
  sales_id: number
  sales_name: string
  sales_phone: string
  status: string
  korsal_name: string | null
  transaction_count: number
  total_fee: string
}

export function getSalesReport(filters: ReportFilters = {}) {
  return getPaginated<SalesReportRow>('sales', filters)
}

/** Full courier roster — delivery count, return count, and fee per courier. */
export interface CourierReportRow {
  courier_id: number
  courier_name: string
  is_active: boolean
  delivery_count: number
  return_count: number
  total_fee: string
}

export function getCourierReport(filters: ReportFilters = {}) {
  return getPaginated<CourierReportRow>('couriers', filters)
}

/** One row per order item — server-paginated, same as the roster reports above. */
export function getOrdersReport(filters: ReportFilters = {}) {
  return getPaginated<TransactionReportRow>('transactions', filters)
}

export interface DashboardSummary {
  total_orders: number
  total_revenue: number
  total_customers: number
  total_korsal: number
  total_sales: number
  total_kurir: number
}

export async function getDashboardSummary(): Promise<DashboardSummary> {
  const { data } = await http.get<ApiEnvelope<DashboardSummary>>('/dashboard/summary')
  return data.data
}

/** Sales-only — every konsumen this sales sold to, order count + fee earned from that konsumen. */
export interface SalesCustomerReportRow {
  konsumen_id: number
  konsumen_name: string
  konsumen_phone: string
  order_count: number
  total_fee: string
}

export interface SalesCustomersReport {
  items: SalesCustomerReportRow[]
  currentPage: number
  lastPage: number
  /** Distinct konsumen count (the grouped query's own row count — a summary stat, not a repeating column). */
  total: number
  /** Branch-wide (not just this page) total fee earned in range. */
  totalFee: number
}

export async function getSalesCustomersReport(filters: ReportFilters = {}): Promise<SalesCustomersReport> {
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(filters)) {
    if (value !== undefined && value !== null && value !== '') params.set(key, String(value))
  }
  const qs = params.toString()
  const { data } = await http.get<ApiEnvelope<SalesCustomerReportRow[]>>(`/reports/my-customers${qs ? `?${qs}` : ''}`)
  const meta = data.meta ?? {}
  return {
    items: data.data,
    currentPage: Number(meta.current_page ?? 1),
    lastPage: Number(meta.last_page ?? 1),
    total: Number(meta.total ?? data.data.length),
    totalFee: Number(meta.total_fee ?? 0),
  }
}

export function downloadSalesCustomersXlsx(filters: ReportFilters = {}) {
  const apiBase = (window.__APP_CONFIG__?.API_URL ?? import.meta.env.VITE_API_URL ?? '') + '/api/v1'
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(filters)) {
    if (value !== undefined && value !== null && value !== '') params.set(key, String(value))
  }
  params.set('export', 'xlsx')
  window.open(`${apiBase}/reports/my-customers?${params.toString()}`, '_blank')
}
