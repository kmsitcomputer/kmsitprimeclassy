import { http } from './client'
import type { ApiEnvelope } from './client'
import type { PaginationMeta } from './types'

export interface ReturnItemLine {
  id: number
  order_item_id: number
  product_name: string
  variation_label: string | null
  /** SKU snapshot of the product/variant on this order item; null when historical data predates it. */
  sku: string | null
  quantity_returned: number
  refund_amount: string
  restock: boolean
  status: 'pending' | 'approved' | 'rejected'
  refund_status: 'not_required' | 'pending' | 'processed' | 'failed'
  condition_note: string | null
}

export interface ReturnRequestRecord {
  id: number
  order_id: number
  order_no: string
  customer_name: string
  reason: string
  evidence_url: string | null
  status: 'requested' | 'under_review' | 'approved' | 'rejected' | 'processing' | 'completed'
  total_refund_amount: string
  requested_by: string | null
  reviewed_by: string | null
  reviewed_at: string | null
  items?: ReturnItemLine[]
  created_at: string
}

export interface RequestReturnLine {
  order_item_id: number
  quantity: number
  restock?: boolean
}

/** `evidence` is mandatory — "Konsumen ketika merubah status terkirim ke request refund produk harus memberikan bukti foto produk." */
export async function requestReturn(orderId: number, reason: string, items: RequestReturnLine[], evidence: File) {
  const form = new FormData()
  form.append('reason', reason)
  items.forEach((line, i) => {
    form.append(`items[${i}][order_item_id]`, String(line.order_item_id))
    form.append(`items[${i}][quantity]`, String(line.quantity))
    if (line.restock !== undefined) form.append(`items[${i}][restock]`, line.restock ? '1' : '0')
  })
  form.append('evidence', evidence)

  const { data } = await http.post<ApiEnvelope<ReturnRequestRecord>>(`/orders/${orderId}/returns`, form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

export async function listReturns(page = 1, filters: { agent_id?: number } = {}) {
  const { data } = await http.get<ApiEnvelope<ReturnRequestRecord[]>>('/admin/returns', { params: { page, ...filters } })
  return { returns: data.data, meta: data.meta as unknown as PaginationMeta }
}

export async function getReturn(id: number) {
  const { data } = await http.get<ApiEnvelope<ReturnRequestRecord>>(`/admin/returns/${id}`)
  return data.data
}

export async function reviewReturn(id: number, approved: boolean, note?: string) {
  const { data } = await http.patch<ApiEnvelope<ReturnRequestRecord>>(`/admin/returns/${id}/review`, { approved, note })
  return data.data
}

export async function markReturnItemRefunded(itemId: number) {
  const { data } = await http.patch<ApiEnvelope<ReturnItemLine>>(`/admin/return-items/${itemId}/refund`, {})
  return data.data
}
