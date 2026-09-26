import { http } from './client'
import type { ApiEnvelope } from './client'

export interface StockRequestItem {
  id: number
  product_id: number | null
  product_variation_id: number | null
  // Product-oriented identity (PBR-003) — flat human-readable fields from
  // eager-loaded relations; variation.label mirrors backend ProductVariation::label().
  product_name: string | null
  variation_label: string | null
  sku: string | null
  sku_snapshot: string | null
  product_image_url: string | null
  requested_qty: number
  fulfilled_qty: number
  remaining_qty: number
  current_stock?: { transit: number; shipping: number; reserved: number } | null
  product?: { id?: number; name: string; sku?: string | null } | null
  variation?: { id?: number; label: string; sku: string } | null
}
export interface StockRequest { id: number; request_number: string; order_id: number; status: string; items: StockRequestItem[]; order?: { id: number; order_no: string; status: string } | null }
export async function listStockRequests(params: { status?: string; search?: string; page?: number } = {}) {
  const { data } = await http.get<ApiEnvelope<StockRequest[]>>('/warehouse/stock-requests', { params: { per_page: 15, ...params } })
  return { requests: data.data, meta: data.meta as unknown as { current_page: number; last_page: number; total: number } }
}