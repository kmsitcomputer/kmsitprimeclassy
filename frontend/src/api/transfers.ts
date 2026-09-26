import { http } from './client'
import type { ApiEnvelope } from './client'
import type { PaginationMeta } from './types'

export interface StockTransferItem {
  id?: number
  quantity: number
  product_id?: number | null
  product_variation_id?: number | null
  // Product-oriented identity (PBR-003) — nested objects from eager-loaded
  // relations; variation.label mirrors backend ProductVariation::label().
  product?: { id?: number; name: string; sku?: string | null; images?: { url: string }[] } | null
  variation?: { id?: number; label: string; sku: string } | null
}

export interface StockTransfer { id: number; transfer_number: string; source_stock_type: string; source_sub_location_id?: number | null; destination_stock_type: string; destination_sub_location_id?: number | null; status: string; reference: string | null; note?: string | null; rejection_reason?: string | null; created_at?: string | null; items: StockTransferItem[]; handover?: { id: number } | null; creator?: { id: number; name: string } | null }

export async function listTransfers(page = 1, perPage = 15, status?: string) {
  const { data } = await http.get<ApiEnvelope<StockTransfer[]>>('/warehouse/transfers', { params: { page, per_page: perPage, ...(status ? { status } : {}) } })
  return { transfers: data.data, meta: data.meta as unknown as PaginationMeta }
}

export async function createPlanTransfer(payload: { reference?: string; note?: string; items: { product_id?: number; product_variation_id?: number; quantity: number }[] }) { return (await http.post<ApiEnvelope<StockTransfer>>('/warehouse/transfers/plan-to-transit', payload)).data.data }

export async function approveTransfer(id: number) { return (await http.post<ApiEnvelope<StockTransfer>>(`/warehouse/transfers/${id}/approve`)).data.data }

export async function rejectTransfer(id: number, reason: string) { return (await http.post<ApiEnvelope<StockTransfer>>(`/warehouse/transfers/${id}/reject`, { reason })).data.data }