import { http } from './client'
import { uploadConfig } from './payments'
import type { ApiEnvelope } from './client'
import type { Order, PaginationMeta } from './types'

/**
 * The Gudang "Order Diproses" work queue and the Gudang → Koordinator Kurir handover.
 *
 * Flow (Owner decision): Koordinator Kurir asks Gudang → Gudang approves what it can hand over, records a
 * reason for every short line and attaches the handover evidence → the Koordinator confirms receipt and assigns
 * the courier. The Admin takes no part in this routine flow; the Admin only decides on the REMAINDER of a
 * reported shortage on the Order page. The backend endpoints are the canonical persistence
 * (StockRequest / StockRequestProposal) — this module never re-implements business logic.
 */

// ---------- Gudang queue ----------

/** The Koordinator Kurir's request behind a queue order — real values only (null/false when never requested). */
export interface QueueFulfillmentRequest {
  stock_request_id: number
  request_number: string
  status: string
  requested_by_coordinator: boolean
  coordinator: { id: number; name: string | null } | null
  requested_at: string | null
  note: string | null
  requested_qty: number
  handed_over_qty: number
  /** Handed over by Gudang, still awaiting the Koordinator's receipt confirmation. */
  awaiting_receipt_qty: number
  outstanding_qty: number
}
export type QueueOrder = Order & { fulfillment_request?: QueueFulfillmentRequest | null }

/** Server-side scoped: status === 'diproses' AND no courier assigned yet. `requestedOnly` = only what the Koordinator asked for. */
export async function listWarehouseDiprosesOrders(page = 1, requestedOnly = false) {
  const { data } = await http.get<ApiEnvelope<QueueOrder[]>>('/warehouse/orders/diproses', {
    params: { page, ...(requestedOnly ? { requested: 1 } : {}) },
  })
  return { orders: data.data, meta: data.meta as unknown as PaginationMeta & { requested_total?: number } }
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
  /** REM-002: set when the Koordinator Kurir confirmed physical receipt. */
  received_by?: number | null
  received_at?: string | null
  receipt_note?: string | null
  requester?: { id: number; name: string } | null
  requested_by?: number | null
  created_at?: string | null
  /** Gudang's handover evidence (image URL), when attached. */
  handover_proof_url?: string | null
  /** What Gudang recorded as NOT handed over in this submission, with the reason. */
  shortages?: { stock_request_item_id: number; reason: string | null; handed_over_qty: number; short_qty: number }[]
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

/** One line of Gudang's handover: what it can hand over now, and why it is less than outstanding (when it is). */
export interface HandoverLine {
  item_id: number
  quantity: number
  shortage_reason?: string
}

/**
 * Gudang approves what it can hand over for the Koordinator Kurir's request.
 * - `items`: lines handed over now (quantity >= 1); a reason is required when quantity < outstanding.
 * - `shortages`: lines with NOTHING available, each with a reason.
 * - `proof`: handover evidence (required whenever goods are handed over).
 * With no `items` only the shortage is reported (no proposal, no stock movement).
 */
export async function submitWarehouseHandover(
  requestId: number,
  payload: { items: HandoverLine[]; shortages?: { item_id: number; reason: string }[]; proof?: File | null },
) {
  const form = new FormData()
  payload.items.forEach((line, i) => {
    form.append(`items[${i}][item_id]`, String(line.item_id))
    form.append(`items[${i}][quantity]`, String(line.quantity))
    if (line.shortage_reason) form.append(`items[${i}][shortage_reason]`, line.shortage_reason)
  })
  ;(payload.shortages ?? []).forEach((line, i) => {
    form.append(`shortages[${i}][item_id]`, String(line.item_id))
    form.append(`shortages[${i}][reason]`, line.reason)
  })
  if (payload.proof) form.append('proof', payload.proof)
  const { data } = await http.post<ApiEnvelope<WarehouseFulfillmentProposal | { shortages: unknown[] }>>(
    `/warehouse/stock-requests/${requestId}/proposals`,
    form,
    uploadConfig(),
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

/** Handover submissions of the branch (Gudang and Koordinator Kurir read them; nobody approves them). */
export async function listFulfillmentProposals(params: { status?: string; page?: number } = {}) {
  const { data } = await http.get<ApiEnvelope<WarehouseFulfillmentProposal[]>>('/warehouse/fulfillment-proposals', {
    params: { per_page: 15, ...params },
  })
  return { proposals: data.data, meta: data.meta as unknown as PaginationMeta }
}

/** REM-002: one prepared item as the Koordinator Kurir counted it — accepted + short + damaged = prepared quantity. */
export interface FulfillmentReceiptLine {
  /** The proposal item id (WarehouseProposalItem.id). */
  item_id: number
  accepted: number
  short: number
  damaged: number
}

/** REM-002: Koordinator Kurir confirms the physical receipt of what Gudang handed over (routine settlement). */
export async function receiveFulfillmentProposal(id: number, items: FulfillmentReceiptLine[], note?: string) {
  const { data } = await http.post<ApiEnvelope<WarehouseFulfillmentProposal>>(`/warehouse/fulfillment-proposals/${id}/receive`, { items, note })
  return data.data
}
