import { http } from './client'
import type { ApiEnvelope } from './client'
import type { OrderItem, PaginationMeta } from './types'

export async function adjustItemFulfillment(
  orderId: number,
  itemId: number,
  fulfilledQuantity: number,
  reason: string,
  additionalPaymentMethod?: 'transfer' | 'cod',
) {
  const { data } = await http.patch<ApiEnvelope<OrderItem>>(`/orders/${orderId}/items/${itemId}/fulfillment`, {
    fulfilled_quantity: fulfilledQuantity,
    reason,
    additional_payment_method: additionalPaymentMethod,
  })
  return data.data
}

export interface OrderRefund {
  id: number
  order_id: number
  order_no: string
  customer_name: string
  item: string
  variation_label: string | null
  /** SKU snapshot of the product/variant on this order item; null when historical data predates it. */
  sku: string | null
  quantity_reduced: number
  refund_amount: string
  reason: string
  refund_status: 'pending' | 'processed' | 'failed'
  adjusted_by: string | null
  created_at: string
}

/**
 * Office-only (super_admin/agen/admin), only while the order is 'diproses' — see OrderFulfillmentController::reschedule.
 * `quantity` optionally moves only part of the line onto the new date — the moved units become a new OrderItem (own
 * shipment, own date) while the rest stays on the original item/date. Omit it (or pass the item's full
 * fulfilled_quantity) to reschedule the whole line in place, as before.
 */
export async function rescheduleOrderItem(orderId: number, itemId: number, requestedDeliveryDate: string, reason: string, quantity?: number) {
  const { data } = await http.patch<ApiEnvelope<OrderItem>>(`/orders/${orderId}/items/${itemId}/reschedule`, {
    requested_delivery_date: requestedDeliveryDate,
    reason,
    quantity,
  })
  return data.data
}

export async function listOrderRefunds(page = 1, filters: { agent_id?: number } = {}) {
  const { data } = await http.get<ApiEnvelope<OrderRefund[]>>('/admin/order-refunds', { params: { page, ...filters } })
  return { refunds: data.data, meta: data.meta as unknown as PaginationMeta }
}

export async function markOrderRefundStatus(adjustmentId: number, refundStatus: 'pending' | 'processed' | 'failed') {
  const { data } = await http.patch<ApiEnvelope<OrderRefund>>(`/admin/order-refunds/${adjustmentId}/status`, { refund_status: refundStatus })
  return data.data
}

export interface AdditionalPayment {
  id: number
  order_id: number
  order_no: string
  customer_name: string
  method: 'transfer' | 'cod'
  amount: string
  reason: string | null
  status: 'pending' | 'paid' | 'failed' | 'cancelled'
  requested_by: string | null
  payment_instructions: Record<string, unknown> | null
  created_at: string
}

export async function listAdditionalPayments(page = 1, filters: { agent_id?: number } = {}) {
  const { data } = await http.get<ApiEnvelope<AdditionalPayment[]>>('/admin/additional-payments', { params: { page, ...filters } })
  return { payments: data.data, meta: data.meta as unknown as PaginationMeta }
}

export async function markAdditionalPaymentStatus(paymentId: number, paid: boolean) {
  const { data } = await http.patch<ApiEnvelope<AdditionalPayment>>(`/admin/additional-payments/${paymentId}/status`, { paid })
  return data.data
}
