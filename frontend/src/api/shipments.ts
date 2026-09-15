import { http } from './client'
import type { ApiEnvelope } from './client'
import type { Order } from './types'

/**
 * Per-shipment delivery progress — "satu order bisa beberapa kurir" (an
 * order can have more than one shipment once an item is rescheduled), so
 * this always targets a specific shipment_id (see OrderItem.shipment_id),
 * never the order as a whole. Marking 'terkirim' as a kurir requires a
 * delivery proof photo — the backend enforces this regardless of what's sent.
 */
export async function updateShipmentStatus(shipmentId: number, status: 'dikirim' | 'terkirim', proof?: File) {
  const form = new FormData()
  form.append('status', status)
  if (proof) form.append('proof', proof)
  form.append('_method', 'PATCH')

  const { data } = await http.post<ApiEnvelope<Order>>(`/shipments/${shipmentId}/status`, form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

/** Office-only (admin/agen/super_admin) proactive assignment — a kurir self-assigns instead by marking 'dikirim'. */
export async function assignCourier(shipmentId: number, courierId: number) {
  const { data } = await http.patch<ApiEnvelope<Order>>(`/shipments/${shipmentId}/courier`, { courier_id: courierId })
  return data.data
}

export interface ShipmentReceiptItem {
  sku: string | null
  product_name: string
  variation_label: string | null
  quantity: number
}

export interface ShipmentReceiptPayment {
  is_cod: boolean
  cod_amount_due: string | number | null
  is_down_payment: boolean
  dp_paid_amount: string | number | null
  dp_outstanding_amount: string | number | null
  is_fully_paid: boolean
}

/**
 * Thermal shipping-receipt data — pure read. `mode` (pre_pickup/post_pickup)
 * is derived server-side from the shipment's actual state, never something
 * the caller picks; reprinting is just calling this again.
 */
export interface ShipmentReceipt {
  shipment_id: number
  mode: 'pre_pickup' | 'post_pickup'
  order_no: string
  order_date: string
  recipient_name: string
  recipient_phone: string
  address_line: string
  village: string | null
  district: string | null
  regency: string | null
  province: string | null
  delivery_date: string | null
  shipping_method_code: string | null
  shipping_method_label: string | null
  is_official_carrier_label: boolean
  courier_name: string | null
  picked_up_at: string | null
  items: ShipmentReceiptItem[]
  total_item_count: number
  notes: string | null
  payment: ShipmentReceiptPayment
}

export async function getShipmentReceipt(shipmentId: number) {
  const { data } = await http.get<ApiEnvelope<ShipmentReceipt>>(`/shipments/${shipmentId}/receipt`)
  return data.data
}
