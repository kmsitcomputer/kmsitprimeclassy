import { http } from './client'
import type { ApiEnvelope } from './client'

/** R-02: Sales-Kurir-Sub stock requests. Sub requests, Admin approves, Gudang executes, Sub confirms receipt. */
export type SubStockDirection = 'replenish' | 'return'
export type SubStockRequestStatus = 'requested' | 'approved' | 'rejected' | 'cancelled' | 'executed' | 'received'

export interface SubStockProductRef {
  id: number
  name: string
  sku?: string | null
}

export interface SubStockVariationRef {
  id: number
  sku?: string | null
}

export interface SubStockRequestItem {
  id: number
  product_id: number | null
  product_variation_id: number | null
  quantity: number
  product?: SubStockProductRef | null
  variation?: SubStockVariationRef | null
}

export interface SubStockRequest {
  id: number
  request_number: string
  direction: SubStockDirection
  status: SubStockRequestStatus
  sub_location_id: number
  note: string | null
  items: SubStockRequestItem[]
  sub_location?: { id: number; code: string; name: string } | null
  requester?: { id: number; name: string } | null
  rejection_reason: string | null
  created_at?: string | null
  approved_at?: string | null
  rejected_at?: string | null
  cancelled_at?: string | null
  executed_at?: string | null
  received_at?: string | null
}

export interface SubStockRow {
  product_id: number | null
  product_variation_id: number | null
  physical: number
  reserved: number
  sellable: number
  product?: SubStockProductRef | null
  variation?: SubStockVariationRef | null
}

export interface SubStockTarget extends Omit<SubStockRow, 'physical' | 'reserved' | 'sellable'> {
  current_transit: number
}

export async function replenishmentTargets() {
  const { data } = await http.get<ApiEnvelope<SubStockTarget[]>>('/sub-stock/replenishment-targets')
  return data.data
}

export async function listSubStockRequests(params: { status?: string; direction?: string; page?: number } = {}) {
  const { data } = await http.get<ApiEnvelope<SubStockRequest[]>>('/sub-stock/requests', { params })
  return { requests: data.data, meta: data.meta as { current_page: number; last_page: number; total: number } }
}

export async function createSubStockRequest(
  direction: SubStockDirection,
  items: { product_id?: number; product_variation_id?: number; quantity: number }[],
  idempotencyKey: string,
  note?: string,
) {
  const { data } = await http.post<ApiEnvelope<SubStockRequest>>('/sub-stock/requests', { direction, items, note }, { headers: { 'Idempotency-Key': idempotencyKey } })
  return data.data
}

export async function subStockAction(id: number, action: 'approve' | 'reject' | 'cancel' | 'execute' | 'receive', body: { reason?: string } = {}) {
  const { data } = await http.post<ApiEnvelope<SubStockRequest>>(`/sub-stock/requests/${id}/${action}`, body)
  return data.data
}

export async function mySubStock() {
  const { data } = await http.get<ApiEnvelope<{ sub_location: { id: number; code: string; name: string; address?: string | null; contact_number?: string | null }; stocks: SubStockRow[] }>>('/sub-stock/my')
  return data.data
}
