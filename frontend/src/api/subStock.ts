import { http } from './client'
import type { ApiEnvelope } from './client'

/** R-02: Sales-Kurir-Sub stock requests. Sub requests, Admin approves, Gudang executes, Sub confirms receipt. */
export type SubStockDirection = 'replenish' | 'return'
export type SubStockRequestStatus = 'requested' | 'approved' | 'rejected' | 'cancelled' | 'executed' | 'received'

export interface SubStockRequestItem {
  id: number
  product_id: number | null
  product_variation_id: number | null
  quantity: number
}

export interface SubStockRequest {
  id: number
  request_number: string
  direction: SubStockDirection
  status: SubStockRequestStatus
  sub_location_id: number
  note: string | null
  items: SubStockRequestItem[]
}

export interface SubStockRow {
  product_id: number | null
  product_variation_id: number | null
  physical: number
  reserved: number
  sellable: number
}

export async function listSubStockRequests(params: { status?: string; direction?: string; page?: number } = {}) {
  const { data } = await http.get<ApiEnvelope<SubStockRequest[]>>('/sub-stock/requests', { params })
  return data.data
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
  const { data } = await http.get<ApiEnvelope<{ sub_location: { id: number; code: string; name: string }; stocks: SubStockRow[] }>>('/sub-stock/my')
  return data.data
}
