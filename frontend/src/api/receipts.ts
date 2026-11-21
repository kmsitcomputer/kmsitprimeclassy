import { http } from './client'
import type { ApiEnvelope } from './client'
import { secureUuid } from '@/utils/uuid'

/**
 * Per-item receipts (REM-07) and return-item receipt allocations.
 *
 * A line can be received in parts: every `order_item_receipts` row is one receiving event, and the running
 * total is what moves the item's fulfilment forward. `POST` is idempotent through the `Idempotency-Key` header
 * (8–100 chars) — reuse the SAME key when retrying one logical submission, generate a NEW one per submission.
 */

export interface OrderItemReceipt {
  id: number
  order_item_id: number
  quantity: number
  /** `YYYY-MM-DD` — the business date of the receipt. */
  received_on: string
  /** ISO timestamp when it was recorded; null on historical rows. */
  received_at: string | null
  verified_by: number
  verified_at: string | null
}

/** GET /order-items/{item}/receipts — the receiving history of one order line. */
export async function listOrderItemReceipts(orderItemId: number) {
  const { data } = await http.get<ApiEnvelope<OrderItemReceipt[]>>(`/order-items/${orderItemId}/receipts`)
  return data.data
}

export interface CreateOrderItemReceiptPayload {
  /** How many units arrived now; must be ≥ 1. */
  quantity: number
  /** Business date, `YYYY-MM-DD` (required — the backend rejects anything else). */
  received_on: string
  /** Optional exact timestamp; `received_on` stays the business date. */
  received_at?: string
  /** Media id from `POST /media` — upload the proof first, then pass its id. */
  evidence_media_id?: number
  evidence_note?: string
}

/** POST /order-items/{item}/receipts — records one receiving event and advances the line's fulfilment. */
export async function createOrderItemReceipt(
  orderItemId: number,
  payload: CreateOrderItemReceiptPayload,
  idempotencyKey: string = secureUuid(),
) {
  const { data } = await http.post<ApiEnvelope<OrderItemReceipt>>(`/order-items/${orderItemId}/receipts`, payload, {
    headers: { 'Idempotency-Key': idempotencyKey },
  })
  return data.data
}

export interface ReturnReceiptAllocation {
  receipt_id: number
  quantity: number
}

export interface ReturnReceiptAllocationResult {
  return_item_id: number
  allocations?: { receipt_id: number; quantity: number }[]
  [key: string]: unknown
}

/**
 * POST /return-items/{item}/receipt-allocations — says which physical receipts the returned units came from,
 * so the returned goods keep their provenance. Up to 50 allocations per call.
 */
export async function allocateReturnReceipts(
  returnItemId: number,
  allocations: ReturnReceiptAllocation[],
  evidenceNote?: string,
) {
  const { data } = await http.post<ApiEnvelope<ReturnReceiptAllocationResult>>(
    `/return-items/${returnItemId}/receipt-allocations`,
    { allocations, evidence_note: evidenceNote ?? undefined },
  )
  return data.data
}
