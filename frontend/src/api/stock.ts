import { http } from './client'
import type { ApiEnvelope } from './client'
import type { PaginationMeta } from './types'

export interface ProductStockRow {
  id: number
  agent_id: number
  product_id: number
  product_name: string
  /** Product SKU; null for simple products created before the SKU backfill. */
  sku: string | null
  quantity_on_hand: number
  quantity_reserved: number
  quantity_available: number
  updated_at: string
}

export interface ProductVariationStockRow {
  id: number
  agent_id: number
  product_variation_id: number
  sku: string | null
  variation_label: string | null
  quantity_on_hand: number
  quantity_reserved: number
  quantity_available: number
  updated_at: string
}

export async function listProductStocks(page = 1, agentId?: number) {
  const { data } = await http.get<ApiEnvelope<ProductStockRow[]>>('/stock/products', {
    params: { page, agent_id: agentId },
  })
  return { rows: data.data, meta: data.meta as unknown as PaginationMeta }
}

export async function listVariationStocks(page = 1, agentId?: number) {
  const { data } = await http.get<ApiEnvelope<ProductVariationStockRow[]>>('/stock/variations', {
    params: { page, agent_id: agentId },
  })
  return { rows: data.data, meta: data.meta as unknown as PaginationMeta }
}

export async function adjustStock(payload: {
  product_id?: number
  product_variation_id?: number
  delta: number
  reason: string
  agent_id?: number
}) {
  const { data } = await http.post<ApiEnvelope<ProductStockRow | ProductVariationStockRow>>('/stock/adjust', payload)
  return data.data
}
