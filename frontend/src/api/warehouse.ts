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
export interface SubLocation { id: number; code: string; name: string; address: string | null; description: string | null; is_active: boolean }
export interface SellableStock { transit: number; factory_plan: number; factory_plan_enabled: boolean; sellable_base: number; reserved: number; available: number; physical_stock: number; has_commitment_deficit: boolean }

export async function listWarehouseStock(stockType?: string, search?: string) {
  const { data } = await http.get<ApiEnvelope<WarehouseRow[]>>('/warehouse-stock', { params: { ...(stockType ? { stock_type: stockType } : {}), ...(search ? { search } : {}) } })
  return data.data
}

export async function receiveTransit(payload: { product_id?: number; variation_id?: number; quantity: number; reference: string; note?: string }) {
  const { data } = await http.post<ApiEnvelope<WarehouseRow>>('/warehouse/transit/receive', payload)
  return data.data
}

export async function adjustFactoryPlan(payload: { product_id?: number; variation_id?: number; delta: number; reference: string }) {
  const { data } = await http.post<ApiEnvelope<WarehouseRow>>('/warehouse/factory-plan', payload)
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
export async function createSubLocation(payload: { code: string; name: string; address?: string; description?: string }) { return (await http.post<ApiEnvelope<SubLocation>>('/warehouse/sub-locations', payload)).data.data }
export async function updateSubLocation(id: number, payload: Partial<{ code: string; name: string; address: string; description: string }>) { return (await http.patch<ApiEnvelope<SubLocation>>(`/warehouse/sub-locations/${id}`, payload)).data.data }
export async function deactivateSubLocation(id: number) { return (await http.post<ApiEnvelope<SubLocation>>(`/warehouse/sub-locations/${id}/deactivate`)).data.data }