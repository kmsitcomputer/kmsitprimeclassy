import { http } from './client'
import type { ApiEnvelope } from './client'

export interface WarehouseRow {
  id: number
  product_id: number | null
  product_variation_id: number | null
  stock_type: string
  quantity: number
  sub_location_id?: number | null
  // Product-oriented identity (PBR-003) — flat human-readable fields added
  // alongside the legacy nested objects; IDs stay present but secondary.
  product_name?: string | null
  variation_label?: string | null
  sku?: string | null
  product_image_url?: string | null
  product?: { id: number; name: string; sku?: string | null } | null
  variation?: { id: number; label: string; sku: string } | null
  sub_location?: { id: number; code: string; name: string } | null
}
export interface SubLocation { id: number; code: string; name: string; address: string | null; contact_number?: string | null; description: string | null; is_active: boolean }
export interface SellableStock { transit: number; factory_plan: number; factory_plan_enabled: boolean; sellable_base: number; reserved: number; available: number; physical_stock: number; has_commitment_deficit: boolean }

export interface SubStockCard {
  product_id: number | null
  product_variation_id: number | null
  product_name: string
  variation_label: string | null
  sku: string | null
  product_image_url: string | null
  sub_stock: number
}

export async function listWarehouseStock(stockType?: string, search?: string) {
  const { data } = await http.get<ApiEnvelope<WarehouseRow[]>>('/warehouse-stock', { params: { ...(stockType ? { stock_type: stockType } : {}), ...(search ? { search } : {}) } })
  return data.data
}

export interface StockCard {
  product_id: number | null
  product_variation_id: number | null
  product_name: string
  variation_label: string | null
  sku: string | null
  product_image_url: string | null
  transit: number
  factory_plan: number
  reserved: number
  sellable: number
  factory_plan_enabled: boolean
}

export interface StockAdditionRequest {
  id: number
  product_id: number | null
  product_variation_id: number | null
  request_type?: string | null
  target_stock_type: 'transit' | 'factory_plan' | 'sub'
  sub_location_id?: number | null
  quantity: number
  reference: string | null
  note: string | null
  status: 'pending' | 'approved' | 'rejected'
  product_name?: string | null
  variation_label?: string | null
  sku?: string | null
  product_image_url?: string | null
  current_stock?: number | null
  rejection_reason?: string | null
  requester?: { id: number; name: string } | null
  sub_location?: { id: number; code: string; name: string } | null
  created_at?: string | null
}

export async function createStockAdditionRequest(payload: { product_id?: number; variation_id?: number; quantity: number; target_stock_type: 'transit' | 'factory_plan'; reference?: string; note?: string }) {
  const { data } = await http.post<ApiEnvelope<StockAdditionRequest>>('/warehouse/stock-addition-requests', payload)
  return data.data
}

export interface SellableSummary {
  transit: number
  factory_plan: number
  factory_plan_enabled: boolean
  reserved: number
  available: number
}

export async function getSellableSummary(payload: { product_id?: number; variation_id?: number }) {
  const { data } = await http.get<ApiEnvelope<SellableSummary>>('/warehouse/sellable', { params: payload })
  return data.data
}

export async function listStockCards(search?: string, page = 1) {
  const { data } = await http.get<ApiEnvelope<StockCard[]>>('/warehouse/stock-cards', { params: { ...(search ? { search } : {}), page, per_page: 12 } })
  return { cards: data.data, meta: data.meta as unknown as { current_page: number; last_page: number; total: number; per_page: number } }
}

export async function listStockAdditionRequests(params: { status?: string; search?: string; page?: number; per_page?: number; scope?: 'mine'; request_type?: string; sub_location_id?: number } = {}) {
  const { data } = await http.get<ApiEnvelope<StockAdditionRequest[]>>('/warehouse/stock-addition-requests', { params: { per_page: 15, ...params } })
  return { requests: data.data, meta: data.meta as unknown as { current_page: number; last_page: number; total: number } }
}

export async function createSubAdjustmentRequest(payload: { product_id?: number; variation_id?: number; sub_location_id: number; delta: number; reference?: string; note?: string }) {
  const { data } = await http.post<ApiEnvelope<StockAdditionRequest>>('/warehouse/sub-adjustment-requests', payload)
  return data.data
}

export async function listSubStockCards(subLocationId: number, search?: string, page = 1) {
  const { data } = await http.get<ApiEnvelope<SubStockCard[]>>(`/warehouse/sub-locations/${subLocationId}/stock-cards`, { params: { ...(search ? { search } : {}), page, per_page: 12 } })
  return { cards: data.data, meta: data.meta as unknown as { current_page: number; last_page: number; total: number; per_page: number } }
}

export async function approveStockAdditionRequest(id: number) {
  const { data } = await http.post<ApiEnvelope<StockAdditionRequest>>(`/warehouse/stock-addition-requests/${id}/approve`)
  return data.data
}

export async function rejectStockAdditionRequest(id: number, reason: string) {
  const { data } = await http.post<ApiEnvelope<StockAdditionRequest>>(`/warehouse/stock-addition-requests/${id}/reject`, { reason })
  return data.data
}

export async function getWarehouseSetting() {
  const { data } = await http.get<ApiEnvelope<{ factory_plan_enabled: boolean }>>('/warehouse/settings')
  return data.data
}

export async function setWarehouseSetting(factory_plan_enabled: boolean) {
  const { data } = await http.patch<ApiEnvelope<{ factory_plan_enabled: boolean }>>('/warehouse/settings/factory-plan', { factory_plan_enabled })
  return data.data
}

export async function listSubLocations() { return (await http.get<ApiEnvelope<SubLocation[]>>('/warehouse/sub-locations')).data.data }
export async function createSubLocation(payload: { code: string; name: string; address?: string; contact_number?: string; description?: string }) { return (await http.post<ApiEnvelope<SubLocation>>('/warehouse/sub-locations', payload)).data.data }
export async function updateSubLocation(id: number, payload: Partial<{ code: string; name: string; address: string; description: string }>) { return (await http.patch<ApiEnvelope<SubLocation>>(`/warehouse/sub-locations/${id}`, payload)).data.data }
export async function deactivateSubLocation(id: number) { return (await http.post<ApiEnvelope<SubLocation>>(`/warehouse/sub-locations/${id}/deactivate`)).data.data }