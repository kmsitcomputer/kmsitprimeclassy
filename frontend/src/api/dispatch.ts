import { http } from './client'
import type { ApiEnvelope } from './client'
import type { PaginationMeta } from './types'

/**
 * IMP-003 — dispatch workspace (koordinator-kurir).
 *
 * The backend serves the SAME diproses/no-courier invariant as Gudang's
 * queue, scoped to one agent branch. These rows are operational: recipient
 * + destination + delivery date + a coarse `paid_in_full` boolean for
 * planning — never financial amounts.
 */
export interface DispatchRowItem {
  id: number
  product_name: string
  variation_label: string | null
  sku: string | null
  quantity: number
  status: string
  requested_delivery_date: string | null
}

export interface DispatchRow {
  id: number
  shipment_id: number
  order_id: number
  order_no: string
  status: string
  delivery_mode: string
  self_delivered_by_user_id: number | null
  courier_id: number | null
  // A1-01: explicit capability flag — a Koordinator-Kurir may assign this
  // shipment to THEMSELVES (becoming the actual executor). Never a financial
  // or cross-branch signal; the backend re-enforces it on the assign route.
  self_assignable: boolean
  courier: { id: number; name: string } | null
  recipient_name: string | null
  recipient_phone: string | null
  address_line: string | null
  village_name: string | null
  district_name: string | null
  regency_name: string | null
  province_name: string | null
  province_id: string | null
  regency_id: string | null
  district_id: string | null
  village_id: string | null
  delivery_date: string[]
  /** UAT-005: canonical items riding THIS shipment (product + qty + date + status). */
  items: DispatchRowItem[]
  paid_in_full: boolean
  created_at: string | null
}

export interface DispatchCourier {
  id: number
  name: string
}

export interface DispatchFilters {
  delivery_date?: string
  province_id?: string
  regency_id?: string
  district_id?: string
  village_id?: string
  paid?: 'paid' | 'unpaid'
  agent_id?: number
}

export interface DispatchRegionOption {
  id: string
  name: string
}

export interface DispatchRegionOptions {
  provinces: DispatchRegionOption[]
  regencies: DispatchRegionOption[]
  districts: DispatchRegionOption[]
  villages: DispatchRegionOption[]
}

export interface RegionOptionFilters {
  delivery_date?: string
  paid?: 'paid' | 'unpaid'
  province_id?: string
  regency_id?: string
  district_id?: string
  agent_id?: number
}

export async function listDispatchQueue(page = 1, filters: DispatchFilters = {}) {
  const { data } = await http.get<ApiEnvelope<DispatchRow[]>>('/dispatch', {
    params: { page, ...clean(filters) },
  })
  return { rows: data.data, meta: data.meta as unknown as PaginationMeta }
}

export async function listDispatchCouriers(agentId?: number) {
  const { data } = await http.get<ApiEnvelope<DispatchCourier[]>>('/dispatch/couriers', {
    params: agentId ? { agent_id: agentId } : {},
  })
  return data.data
}

/**
 * UAT consolidated Q–W — cascading region options derived ONLY from the current eligible
 * Dispatch queue (GET /dispatch/regions). The frontend never loads the Indonesian master.
 */
export async function listDispatchRegionOptions(filters: RegionOptionFilters = {}): Promise<DispatchRegionOptions> {
  const { data } = await http.get<ApiEnvelope<DispatchRegionOptions>>('/dispatch/regions', {
    params: { ...clean(filters) },
  })
  return data.data
}

function clean(filters: DispatchFilters | RegionOptionFilters): Record<string, string | number> {
  return Object.fromEntries(
    Object.entries(filters).filter(([, v]) => v !== undefined && v !== null && v !== ''),
  ) as Record<string, string | number>
}