import { http } from './client'
import type { ApiEnvelope } from './client'
import type { PaginationMeta } from './types'

export interface CourierOrderItem {
  id: number
  /** Null only for historical rows created before per-item shipments — the action buttons below must be hidden then. */
  shipment_id: number | null
  product_name: string
  variation_label: string | null
  /** Product/variant SKU snapshot at order time; null for historical rows without one. */
  sku: string | null
  quantity: number
  status: string
  requested_delivery_date: string | null
}

export interface CourierOrder {
  id: number
  order_no: string
  status: string
  recipient_name: string
  recipient_phone: string
  address: string
  village: string
  district: string
  regency: string
  province: string
  latitude: string | number | null
  longitude: string | number | null
  items: CourierOrderItem[]
  created_at: string
  /** Only present on the "Selesai" (delivered report) listing — this kurir's own fee earned from this order. */
  fee_amount?: number
}

export async function listCourierOrders(page = 1) {
  const { data } = await http.get<ApiEnvelope<CourierOrder[]>>('/kurir/orders', { params: { page } })
  return { orders: data.data, meta: data.meta as unknown as PaginationMeta }
}

export interface CourierReturnItem {
  id: number
  order_item_id: number
  product_name: string
  variation_label: string | null
  sku: string | null
  quantity_returned: number
  status: string
  condition_note: string | null
  picked_up_by_me: boolean
}

export interface CourierReturn {
  id: number
  order_id: number
  order_no: string
  reason: string
  items: CourierReturnItem[]
  created_at: string
}

export async function listCourierReturns(page = 1) {
  const { data } = await http.get<ApiEnvelope<CourierReturn[]>>('/kurir/returns', { params: { page } })
  return { returns: data.data, meta: data.meta as unknown as PaginationMeta }
}

export async function pickupReturnItem(itemId: number, note?: string) {
  const { data } = await http.patch<ApiEnvelope<unknown>>(`/kurir/returns/${itemId}/pickup`, note ? { note } : {})
  return data.data
}

export async function confirmReturnItem(itemId: number, received: boolean) {
  const { data } = await http.patch<ApiEnvelope<unknown>>(`/kurir/returns/${itemId}/confirm`, { received })
  return data.data
}

export async function courierDeliveredReport(page = 1, from?: string, to?: string) {
  const { data } = await http.get<ApiEnvelope<CourierOrder[]>>('/kurir/reports/delivered', {
    params: { page, from, to },
  })
  return { orders: data.data, meta: data.meta as unknown as PaginationMeta }
}
