import { http } from './client'
import type { ApiEnvelope } from './client'

export interface StockOpname { id: number; opname_number: string; opname_type: string; stock_type: string | null; status: string; items: { id: number; product_id: number | null; product_variation_id: number | null; system_quantity: number; counted_quantity: number | null; difference: number | null }[] }
export async function listOpnames() { return (await http.get<ApiEnvelope<StockOpname[]>>('/warehouse/opnames')).data.data }
export async function createOpname(payload: { opname_type: string; stock_type: string; sub_location_id?: number; items: { product_id?: number; product_variation_id?: number }[] }) { return (await http.post<ApiEnvelope<StockOpname>>('/warehouse/opnames', payload)).data.data }
export async function countOpname(id: number, counts: { item_id: number; counted_quantity: number }[]) { return (await http.patch<ApiEnvelope<StockOpname>>(`/warehouse/opnames/${id}/count`, { counts })).data.data }
export async function submitOpname(id: number) { return (await http.post<ApiEnvelope<StockOpname>>(`/warehouse/opnames/${id}/submit`)).data.data }
export async function approveOpname(id: number) { return (await http.post<ApiEnvelope<StockOpname>>(`/warehouse/opnames/${id}/approve`)).data.data }
export async function rejectOpname(id: number, reason: string) { return (await http.post<ApiEnvelope<StockOpname>>(`/warehouse/opnames/${id}/reject`, { reason })).data.data }