import { http } from './client'
import type { ApiEnvelope } from './client'
import type { PaginationMeta } from './types'

/** IMP-002 discounts (product/variation) — agent-admin scoped, server-authoritative pricing. */
export interface ProductDiscountRow {
  id: number
  agent_id: number
  name: string
  product_id: number | null
  product_variation_id: number | null
  percentage: number
  is_active: boolean
  starts_at: string | null
  ends_at: string | null
  product?: { id: number; name: string; sku: string } | null
  variation?: { id: number; sku: string } | null
}

export interface VoucherRow {
  id: number
  agent_id: number
  code: string
  name: string
  type: 'percentage' | 'fixed'
  value: number
  is_active: boolean
  valid_from: string | null
  valid_until: string | null
  product_id: number | null
  product_variation_id: number | null
  max_uses: number | null
  used_count: number
  product?: { id: number; name: string; sku: string } | null
  variation?: { id: number; sku: string } | null
}

export async function listDiscounts(agentId?: number, page = 1) {
  const { data } = await http.get<ApiEnvelope<ProductDiscountRow[]>>('/promo/discounts', {
    params: { page, ...(agentId ? { agent_id: agentId } : {}) },
  })
  return { rows: data.data, meta: data.meta as unknown as PaginationMeta }
}

/** A1-18: super_admin passes an explicit agent_id on every write too. */
export async function createDiscount(payload: Partial<ProductDiscountRow>, agentId?: number) {
  const { data } = await http.post<ApiEnvelope<ProductDiscountRow>>('/promo/discounts', payload, {
    params: agentId ? { agent_id: agentId } : {},
  })
  return data.data
}

export async function updateDiscount(id: number, payload: Partial<ProductDiscountRow>, agentId?: number) {
  const { data } = await http.patch<ApiEnvelope<ProductDiscountRow>>(`/promo/discounts/${id}`, payload, {
    params: agentId ? { agent_id: agentId } : {},
  })
  return data.data
}

export async function deleteDiscount(id: number, agentId?: number) {
  await http.delete(`/promo/discounts/${id}`, {
    params: agentId ? { agent_id: agentId } : {},
  })
}

export async function listVouchers(agentId?: number, page = 1) {
  const { data } = await http.get<ApiEnvelope<VoucherRow[]>>('/promo/vouchers', {
    params: { page, ...(agentId ? { agent_id: agentId } : {}) },
  })
  return { rows: data.data, meta: data.meta as unknown as PaginationMeta }
}

export async function createVoucher(payload: Partial<VoucherRow>, agentId?: number) {
  const { data } = await http.post<ApiEnvelope<VoucherRow>>('/promo/vouchers', payload, {
    params: agentId ? { agent_id: agentId } : {},
  })
  return data.data
}

export async function updateVoucher(id: number, payload: Partial<VoucherRow>, agentId?: number) {
  const { data } = await http.patch<ApiEnvelope<VoucherRow>>(`/promo/vouchers/${id}`, payload, {
    params: agentId ? { agent_id: agentId } : {},
  })
  return data.data
}

export async function deleteVoucher(id: number, agentId?: number) {
  await http.delete(`/promo/vouchers/${id}`, {
    params: agentId ? { agent_id: agentId } : {},
  })
}