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
  product?: { id?: number; name: string; sku?: string | null } | null
  variation?: { id?: number; label: string; sku: string } | null
}
export interface StockRequest { id: number; request_number: string; order_id: number; status: string; items: StockRequestItem[] }
export async function listStockRequests() { return (await http.get<ApiEnvelope<StockRequest[]>>('/warehouse/stock-requests')).data.data }
export async function fulfillStockRequest(id: number, idempotencyKey: string, items: { item_id: number; quantity: number }[]) { return (await http.post<ApiEnvelope<StockRequest>>(`/warehouse/stock-requests/${id}/fulfill`, { idempotency_key: idempotencyKey, items })).data.data }