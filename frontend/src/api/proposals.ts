import { http } from './client'
import type { ApiEnvelope } from './client'

export type ProposalDecision = 'pending' | 'approved' | 'rejected'

export interface ProposalItem {
  id: number
  stock_request_item_id: number
  quantity: number
  /** Admin's per-product decision on Gudang's PROPOSED fulfilment (never the order demand itself). */
  decision_status: ProposalDecision
  decision_reason?: string | null
  decided_at?: string | null
  request_item?: {
    id: number
    product_id: number | null
    product_variation_id: number | null
    product_name?: string | null
    variation_label?: string | null
    sku?: string | null
    product_image_url?: string | null
    order_quantity?: number | null
    delivery_date?: string | null
    requested_qty: number
    fulfilled_qty: number
    remaining_qty: number
    current_stock?: { transit: number; shipping: number; reserved: number } | null
  } | null
}

export interface FulfillmentProposal {
  id: number
  stock_request_id: number
  status: 'pending' | 'partial' | 'approved' | 'rejected'
  rejection_reason?: string | null
  requester?: { id: number; name: string } | null
  created_at?: string | null
  items: ProposalItem[]
  request?: { id: number; order_id: number; request_number: string; status: string; order?: { id: number; order_no: string; status: string } | null } | null
}

export async function listProposals(params: { status?: string; stock_request_id?: number; scope?: 'mine'; page?: number } = {}) {
  const { data } = await http.get<ApiEnvelope<FulfillmentProposal[]>>('/warehouse/fulfillment-proposals', { params: { per_page: 15, ...params } })
  return { proposals: data.data, meta: data.meta as unknown as { current_page: number; last_page: number; total: number } }
}

export async function proposeFulfillment(requestId: number, items: { item_id: number; quantity: number }[]) {
  const { data } = await http.post<ApiEnvelope<FulfillmentProposal>>(`/warehouse/stock-requests/${requestId}/proposals`, { items })
  return data.data
}

export async function approveProposal(id: number) {
  const { data } = await http.post<ApiEnvelope<FulfillmentProposal>>(`/warehouse/fulfillment-proposals/${id}/approve`)
  return data.data
}

export async function rejectProposal(id: number, reason: string) {
  const { data } = await http.post<ApiEnvelope<FulfillmentProposal>>(`/warehouse/fulfillment-proposals/${id}/reject`, { reason })
  return data.data
}

/** Per-product approval: only this proposal line executes; the other lines are untouched. */
export async function approveProposalItem(proposalId: number, itemId: number) {
  const { data } = await http.post<ApiEnvelope<FulfillmentProposal>>(`/warehouse/fulfillment-proposals/${proposalId}/items/${itemId}/approve`)
  return data.data
}

/** Per-product rejection of the PROPOSED fulfilment; the order demand (requested/remaining) is unchanged. */
export async function rejectProposalItem(proposalId: number, itemId: number, reason: string) {
  const { data } = await http.post<ApiEnvelope<FulfillmentProposal>>(`/warehouse/fulfillment-proposals/${proposalId}/items/${itemId}/reject`, { reason })
  return data.data
}
