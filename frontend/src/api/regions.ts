import { http } from './client'
import type { ApiEnvelope } from './client'
import type { RegionOption } from './types'

export async function listProvinces() {
  const { data } = await http.get<ApiEnvelope<RegionOption[]>>('/regions/provinces')
  return data.data
}

export async function listRegencies(provinceId: string) {
  const { data } = await http.get<ApiEnvelope<RegionOption[]>>('/regions/regencies', { params: { province_id: provinceId } })
  return data.data
}

export async function listDistricts(regencyId: string) {
  const { data } = await http.get<ApiEnvelope<RegionOption[]>>('/regions/districts', { params: { regency_id: regencyId } })
  return data.data
}

export async function listVillages(districtId: string) {
  const { data } = await http.get<ApiEnvelope<RegionOption[]>>('/regions/villages', { params: { district_id: districtId } })
  return data.data
}
