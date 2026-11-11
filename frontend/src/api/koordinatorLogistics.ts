import { http } from './client'
import type { ApiEnvelope } from './client'
import type { PaginationMeta } from './types'
import { uploadConfig } from './payments'

/**
 * REM-001…003 — Koordinator Kurir logistics surface: fulfilment request to Gudang, physical receipt
 * (accepted/short/damaged, in warehouseFulfillment.ts), delivery-refusal custody, RajaOngkir handover,
 * later delivery batches. Every call maps 1:1 to an existing backend route; the server re-enforces
 * role, branch and state.
 */

export interface FulfillmentState {
  status: string
  outstanding_qty: number
  accepted_qty: number
  coordinator_requested_at: string | null
}

export async function requestFulfillment(orderId: number, note?: string) {
  const { data } = await http.post<ApiEnvelope<{ id: number; coordinator_requested_at: string | null }>>(
    `/dispatch/orders/${orderId}/request-fulfillment`,
    { note },
  )
  return data.data
}

export async function splitDeliveryBatch(orderItemId: number, quantity: number, note?: string) {
  const { data } = await http.post<ApiEnvelope<{ order_item_id: number; shipment_id: number; quantity: number }>>(
    `/dispatch/order-items/${orderItemId}/split-batch`,
    { quantity, note },
  )
  return data.data
}

export interface RefusalRow {
  id: number
  status: 'with_courier' | 'handed_back' | 'received' | 'redelivery' | 'returned_to_warehouse'
  quantity: number
  reason: string
  order_id: number
  order_no: string | null
  product_name: string | null
  shipment_id: number
  recorded_at: string | null
  handed_back_at: string | null
  received_at: string | null
  disposition_note: string | null
}

export async function listRefusals(page = 1) {
  const { data } = await http.get<ApiEnvelope<RefusalRow[]>>('/dispatch/refusals', { params: { page } })
  return { rows: data.data, meta: data.meta as unknown as PaginationMeta }
}

export async function receiveRefusal(id: number) {
  const { data } = await http.post<ApiEnvelope<{ id: number; status: string }>>(`/delivery-refusals/${id}/receive`)
  return data.data
}

export async function disposeRefusal(id: number, action: 'redelivery' | 'return_to_warehouse', note?: string) {
  const { data } = await http.post<ApiEnvelope<{ id: number; status: string }>>(`/delivery-refusals/${id}/disposition`, { action, note })
  return data.data
}

/** Courier side: record a customer's refusal of an item out for delivery, then hand the goods back. */
export async function refuseDelivery(shipmentId: number, orderItemId: number, reason: string, quantity?: number) {
  const { data } = await http.post<ApiEnvelope<{ id: number; status: string; quantity: number }>>(
    `/shipments/${shipmentId}/items/${orderItemId}/refuse`,
    { reason, ...(quantity ? { quantity } : {}) },
  )
  return data.data
}

export async function handBackRefusal(id: number) {
  const { data } = await http.post<ApiEnvelope<{ id: number; status: string }>>(`/delivery-refusals/${id}/hand-back`)
  return data.data
}

export interface ExpeditionRow {
  shipment_id: number
  order_id: number
  order_no: string
  recipient_name: string | null
  carrier: string | null
  service: string | null
  tracking_number: string | null
  handover_recorded: boolean
  ready: { paid_in_full: boolean; goods_complete: boolean; single_shipment: boolean }
  fulfillment_outstanding_qty: number
  coordinator_requested_at: string | null
}

export async function listExpeditions(page = 1) {
  const { data } = await http.get<ApiEnvelope<ExpeditionRow[]>>('/dispatch/expeditions', { params: { page } })
  return { rows: data.data, meta: data.meta as unknown as PaginationMeta }
}

export { uploadConfig }
