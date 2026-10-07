import { http } from './client'
import type { ApiEnvelope } from './client'
import type { Order, PaginationMeta } from './types'

/**
 * IMP-001 UAT remediation (Gap 2) — the Gudang "Order Diproses" work queue
 * and the Admin review of fulfillment proposals.
 *
 * These are the ONLY user-facing surfaces for the warehouse fulfillment
 * workflow. The backend endpoints they call are the canonical internal
 * persistence (StockRequest / StockRequestProposal) — this module never
 * re-implements business logic; it only maps the UI to the existing
 * endpoints.
 */

// ---------- Gudang queue ----------

/** Server-side scoped: status === 'diproses' AND no courier assigned yet. */
export async function listWarehouseDiprosesOrders(page = 1) {
  const { data } = await http.get<ApiEnvelope<Order[]>>('/warehouse/orders/diproses', { params: { page } })
  return { orders: data.data, meta: data.meta as unknown as PaginationMeta }
}

// ---------- Fulfillment proposal (canonical stock-request proposal) ----------

export interface WarehouseProposalItem {
  id: number
  stock_request_item_id: number
  quantity: number
  request_item?: {
    id: number
    product_id: number | null
    product_variation_id: number | null
    product_name?: string | null
    variation_label?: string | null
    sku?: string | null
    product_image_url?: string | null
    requested_qty: number
    fulfilled_qty: number
    remaining_qty: number
    current_stock?: { transit: number; shipping: number; reserved: number } | null
  } | null
}

export interface WarehouseFulfillmentProposal {
  id: number
  stock_request_id: number
  status: 'pending' | 'approved' | 'rejected'
  rejection_reason?: string | null
  requester?: { id: number; name: string } | null
  requested_by?: number | null
  created_at?: string | null
  items: WarehouseProposalItem[]
  request?: {
    id: number
    order_id: number
    request_number: string
    status: string
    order?: { id: number; order_no: string; status: string } | null
  } | null
}

/** The internal stock request behind an order — what Gudang fills against. */
export interface WarehouseStockRequestItem {
  id: number
  /** Canonical OrderItem link — drives per-item Gudang proposals (B). */
  order_item_id: number | null
  product_id: number | null
  product_variation_id: number | null
  product_name: string | null
  variation_label: string | null
  sku: string | null
  sku_snapshot: string | null
  product_image_url: string | null
  requested_qty: number
  fulfilled_qty: number
  remaining_qty: number
  current_stock?: { transit: number; shipping: number; reserved: number } | null
}
export interface WarehouseStockRequest {
  id: number
  request_number: string
  order_id: number
  status: string
  items: WarehouseStockRequestItem[]
  order?: { id: number; order_no: string; status: string } | null
}

/** Gudang submits a fulfillment proposal for the order's internal stock request. */
export async function proposeWarehouseFulfillment(requestId: number, items: { item_id: number; quantity: number }[]) {
  const { data } = await http.post<ApiEnvelope<WarehouseFulfillmentProposal>>(
    `/warehouse/stock-requests/${requestId}/proposals`,
    { items },
  )
  return data.data
}

/**
 * The internal one-per-order stock request behind an eligible order — what
 * Gudang proposes against. Server-scoped to the warehouse-queue invariant
 * (status 'diproses' AND no courier assigned).
 */
export async function getWarehouseStockRequestByOrder(orderId: number) {
  const { data } = await http.get<ApiEnvelope<WarehouseStockRequest>>(`/warehouse/orders/${orderId}/stock-request`)
  return data.data
}

/** Admin reviews the pending proposals. */
export async function listFulfillmentProposals(params: { status?: string; page?: number } = {}) {
  const { data } = await http.get<ApiEnvelope<WarehouseFulfillmentProposal[]>>('/warehouse/fulfillment-proposals', {
    params: { per_page: 15, ...params },
  })
  return { proposals: data.data, meta: data.meta as unknown as PaginationMeta }
}

export async function approveFulfillmentProposal(id: number) {
  const { data } = await http.post<ApiEnvelope<WarehouseFulfillmentProposal>>(`/warehouse/fulfillment-proposals/${id}/approve`)
  return data.data
}

export async function rejectFulfillmentProposal(id: number, reason: string) {
  const { data } = await http.post<ApiEnvelope<WarehouseFulfillmentProposal>>(`/warehouse/fulfillment-proposals/${id}/reject`, { reason })
  return data.data
}