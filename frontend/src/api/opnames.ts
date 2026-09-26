import { http } from './client'
import type { ApiEnvelope } from './client'

export interface StockOpnameItem {
  id: number
  product_id: number | null
  product_variation_id: number | null
  system_quantity: number
  counted_quantity: number | null
  difference: number | null
  // Product-oriented identity (PBR-003) — nested objects from eager-loaded relations.
  product?: { id?: number; name: string; sku?: string | null } | null
  variation?: { id?: number; label: string; sku: string } | null
}
export interface StockOpname { id: number; opname_number: string; opname_type: string; stock_type: string | null; status: string; created_at?: string | null; items: StockOpnameItem[] }
export interface OpnamePaginationMeta { current_page: number; last_page: number; per_page: number; total: number }
export async function listOpnames(page = 1) {
  const { data } = await http.get<ApiEnvelope<StockOpname[], OpnamePaginationMeta>>('/warehouse/opnames', { params: { page, per_page: 15 } })
  const meta: OpnamePaginationMeta = data.meta ?? { current_page: 1, last_page: 1, per_page: 15, total: data.data.length }
  return { opnames: data.data, meta }
}
export async function createOpname(payload: { opname_type: string; stock_type: string; sub_location_id?: number; items: { product_id?: number; product_variation_id?: number }[] }) { return (await http.post<ApiEnvelope<StockOpname>>('/warehouse/opnames', payload)).data.data }
export async function countOpname(id: number, counts: { item_id: number; counted_quantity: number }[]) { return (await http.patch<ApiEnvelope<StockOpname>>(`/warehouse/opnames/${id}/count`, { counts })).data.data }
export async function submitOpname(id: number) { return (await http.post<ApiEnvelope<StockOpname>>(`/warehouse/opnames/${id}/submit`)).data.data }
export async function approveOpname(id: number) { return (await http.post<ApiEnvelope<StockOpname>>(`/warehouse/opnames/${id}/approve`)).data.data }
export async function rejectOpname(id: number, reason: string) { return (await http.post<ApiEnvelope<StockOpname>>(`/warehouse/opnames/${id}/reject`, { reason })).data.data }
export async function cancelOpname(id: number) { return (await http.post<ApiEnvelope<StockOpname>>(`/warehouse/opnames/${id}/cancel`)).data.data }