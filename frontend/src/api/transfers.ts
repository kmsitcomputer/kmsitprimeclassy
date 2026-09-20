import { http } from './client'
import type { ApiEnvelope } from './client'
import type { PaginationMeta } from './types'

export interface StockTransfer { id: number; transfer_number: string; source_stock_type: string; source_sub_location_id?: number | null; destination_stock_type: string; destination_sub_location_id?: number | null; status: string; reference: string | null; items: { quantity: number; product?: { name: string; sku?: string | null } | null; variation?: { label: string; sku: string } | null }[]; handover?: { id: number } | null }

export async function listTransfers(page = 1) {
  const { data } = await http.get<ApiEnvelope<StockTransfer[]>>('/warehouse/transfers', { params: { page } })
  return { transfers: data.data, meta: data.meta as unknown as PaginationMeta }
}

export async function completeTransfer(id: number) { return (await http.post<ApiEnvelope<StockTransfer>>(`/warehouse/transfers/${id}/complete`)).data.data }
export async function cancelTransfer(id: number) { return (await http.post<ApiEnvelope<StockTransfer>>(`/warehouse/transfers/${id}/cancel`)).data.data }
export async function createTransfer(payload: { source_stock_type: string; source_sub_location_id?: number; destination_stock_type: string; destination_sub_location_id?: number; reference?: string; items: { product_id?: number; product_variation_id?: number; quantity: number }[] }) { return (await http.post<ApiEnvelope<StockTransfer>>('/warehouse/transfers', payload)).data.data }