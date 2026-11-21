import { http } from './client'
import type { ApiEnvelope } from './client'

/** Super Admin's GLOBAL on/off view — credentials/rates are configured per-agen instead (see agentSettings.ts). */
export interface ShippingProviderAdmin {
  id: number
  code: string
  name: string
  is_active: boolean
}

export async function listShippingProviders() {
  const { data } = await http.get<ApiEnvelope<ShippingProviderAdmin[]>>('/admin/shipping-providers')
  return data.data
}

export async function toggleShippingProvider(id: number) {
  const { data } = await http.patch<ApiEnvelope<ShippingProviderAdmin>>(`/admin/shipping-providers/${id}/toggle`)
  return data.data
}

/**
 * Master kurir ekspedisi (ShippingCourierController). Daftar `code`/`name` yang
 * dipakai sebagai pilihan kurir per provider; tidak terkait jumlah staf kurir
 * internal pada laporan "Jumlah Kurir per Agen" di view yang sama.
 */
export interface ShippingCourierAdmin {
  id: number
  code: string
  name: string
  is_active: boolean
}

export async function listShippingCouriers() {
  const { data } = await http.get<ApiEnvelope<ShippingCourierAdmin[]>>('/admin/shipping-couriers')
  return data.data
}

export async function createShippingCourier(payload: { code: string; name: string }) {
  const { data } = await http.post<ApiEnvelope<ShippingCourierAdmin>>('/admin/shipping-couriers', payload)
  return data.data
}

export async function toggleShippingCourier(id: number) {
  const { data } = await http.patch<ApiEnvelope<ShippingCourierAdmin>>(`/admin/shipping-couriers/${id}/toggle`)
  return data.data
}

export async function importRegionsCsv(file: File) {
  const form = new FormData()
  form.append('file', file)
  const { data } = await http.post<ApiEnvelope<{ provinces: number; regencies: number; districts: number; villages: number }>>(
    '/admin/regions/import',
    form,
    { headers: { 'Content-Type': 'multipart/form-data' } },
  )
  return data.data
}

export function regionsExportUrl(): string {
  return `${http.defaults.baseURL}/admin/regions/export`
}
