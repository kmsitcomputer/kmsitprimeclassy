import { http, type ApiEnvelope } from './client'
import type { Order, PaginationMeta } from './types'

export interface FulfillmentChangeProposal {
  id: number
  order_id: number
  order_no: string | null
  order_item_id: number
  order_item: { id: number; product_name: string; variation_label: string | null; sku: string | null } | null
  status: 'pending' | 'approved' | 'rejected'
  current: { fulfilled_quantity: number; requested_delivery_date: string | null }
  proposed: { fulfilled_quantity: number; requested_delivery_date: string | null }
  reason: string | null
  decision_reason: string | null
  proposer?: { id: number; name: string }
  decider?: { id: number; name: string } | null
  created_at: string
  decided_at: string | null
}

export async function listDiprosesOrders(page = 1) {
  const { data } = await http.get<ApiEnvelope<Order[]>>('/warehouse/orders/diproses', { params: { page } })
  return { orders: data.data, meta: data.meta as unknown as PaginationMeta }
}

export async function getDiprosesOrder(orderId: number) {
  const { data } = await http.get<ApiEnvelope<Order>>(`/warehouse/orders/${orderId}`)
  return data.data
}

export async function proposeFulfillmentChange(orderId: number, itemId: number, payload: { fulfilled_quantity?: number; requested_delivery_date?: string | null; reason?: string }) {
  const { data } = await http.post<ApiEnvelope<FulfillmentChangeProposal>>(`/warehouse/orders/${orderId}/items/${itemId}/fulfillment-proposals`, payload)
  return data.data
}

export async function listFulfillmentChangeProposals(status = 'pending', page = 1) {
  const { data } = await http.get<ApiEnvelope<FulfillmentChangeProposal[]>>('/warehouse/fulfillment-change-proposals', { params: { status, page } })
  return { proposals: data.data, meta: data.meta as unknown as PaginationMeta }
}

export async function decideFulfillmentChange(id: number, decision: 'approve' | 'reject', reason?: string) {
  const { data } = await http.post<ApiEnvelope<FulfillmentChangeProposal>>(`/warehouse/fulfillment-change-proposals/${id}/${decision}`, { reason })
  return data.data
}

export async function downloadDeliveryGroupInvoice(orderId: number, deliveryDate: string) {
  const { data } = await http.get<Blob>(`/orders/${orderId}/delivery-groups/${deliveryDate}/invoice`, { responseType: 'blob' })
  const url = URL.createObjectURL(data)
  const link = document.createElement('a')
  link.href = url
  link.download = `invoice-${orderId}-${deliveryDate}.pdf`
  document.body.append(link)
  link.click()
  link.remove()
  window.setTimeout(() => URL.revokeObjectURL(url), 1000)
}