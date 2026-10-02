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
  is_earliest_delivery_group: boolean
  initial_dp_amount: string | number | null
  initial_dp_credit: string | number | null
  order_has_multiple_delivery_groups: boolean
}

/**
 * Thermal shipping-receipt data — pure read. `mode` (pre_pickup/post_pickup)
 * is derived server-side from the shipment's actual state, never something
 * the caller picks; reprinting is just calling this again.
 */
export interface ShipmentReceipt {
  shipment_id: number
  mode: 'pre_pickup' | 'post_pickup'
  shipment_status: 'pending' | 'picked_up' | 'in_transit' | 'delivered' | 'failed'
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
  shipping_fee_amount: string
  shipping_method_code: string | null
  shipping_method_label: string | null
  is_official_carrier_label: boolean
  tracking_number: string | null
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

export interface DeliveryGroupReceipt {
  order_no: string
  delivery_date: string
  recipient_name: string
  recipient_phone: string
  address_line: string
  items: ShipmentReceiptItem[]
  total_item_count: number
  shipping_fee_amount: string
  shipping_methods: string[]
  courier_names: string[]
  tracking_numbers: string[]
  shipment_statuses: string[]
  payment: {
    is_cod: boolean
    cod_amount_due: string | number | null
    initial_dp_amount: string | number | null
    initial_dp_credit: string | number | null
    dp_credit_date: string | null
    order_has_multiple_delivery_groups: boolean
  }
}

export async function getDeliveryGroupReceipt(orderId: number, deliveryDate: string) {
  const { data } = await http.get<ApiEnvelope<DeliveryGroupReceipt>>(`/orders/${orderId}/delivery-groups/${deliveryDate}/receipt`)
  return data.data
}
