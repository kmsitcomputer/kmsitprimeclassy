import { http } from './client'
import type { ApiEnvelope } from './client'
import type { KonsumenAddress } from './types'

export interface AddressPayload {
  label?: string
  recipient_name: string
  phone: string
  address_line: string
  village_id: string
  latitude: number
  longitude: number
  is_default?: boolean
}

export async function listAddresses() {
  const { data } = await http.get<ApiEnvelope<KonsumenAddress[]>>('/addresses')
  return data.data
}

export async function createAddress(payload: AddressPayload) {
  const { data } = await http.post<ApiEnvelope<KonsumenAddress>>('/addresses', payload)
  return data.data
}

export async function updateAddress(id: number, payload: AddressPayload) {
  const { data } = await http.patch<ApiEnvelope<KonsumenAddress>>(`/addresses/${id}`, payload)
  return data.data
}

export async function deleteAddress(id: number) {
  await http.delete(`/addresses/${id}`)
}
