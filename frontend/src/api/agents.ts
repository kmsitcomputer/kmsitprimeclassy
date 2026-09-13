import { http } from './client'
import type { ApiEnvelope } from './client'

export interface AgentDirectoryRow {
  user_id: number
  name: string
  email: string
  phone: string | null
  referral_code: string | null
  status: string
  profile: {
    id: number
    store_name: string
    address: string
    phone: string | null
    latitude: number
    longitude: number
  } | null
}

export async function listAgentDirectory() {
  const { data } = await http.get<ApiEnvelope<AgentDirectoryRow[]>>('/admin/agents')
  return data.data
}

export async function createAgentProfile(payload: {
  user_id: number
  store_name: string
  address: string
  phone?: string | null
  latitude: number
  longitude: number
}) {
  const { data } = await http.post<ApiEnvelope<AgentDirectoryRow>>('/admin/agents', payload)
  return data.data
}

export async function updateAgentProfile(
  agentProfileId: number,
  payload: Partial<{ store_name: string; address: string; phone: string | null; latitude: number; longitude: number }>,
) {
  const { data } = await http.patch<ApiEnvelope<AgentDirectoryRow>>(`/admin/agents/${agentProfileId}`, payload)
  return data.data
}

export async function deleteAgentProfile(agentProfileId: number) {
  await http.delete(`/admin/agents/${agentProfileId}`)
}

export async function toggleAgentStatus(agentUserId: number) {
  const { data } = await http.patch<ApiEnvelope<AgentDirectoryRow>>(`/admin/agents/${agentUserId}/toggle-status`)
  return data.data
}

/** Public "Kontak Agen" directory (Cari Toko) — active agents only, contact/location fields only. */
export interface PublicAgentContact {
  name: string
  address: string
  phone: string | null
  latitude: number
  longitude: number
  referral_code: string | null
}

export async function listPublicAgents() {
  const { data } = await http.get<ApiEnvelope<PublicAgentContact[]>>('/agents')
  return data.data
}

/** An agen's own store profile — self-service, never another agent's. */
export interface OwnAgentStoreProfile {
  store_name: string
  address: string
  phone: string | null
  latitude: number
  longitude: number
}

export async function getOwnStoreProfile() {
  const { data } = await http.get<ApiEnvelope<OwnAgentStoreProfile | null>>('/agent/store-profile')
  return data.data
}

export async function updateOwnStoreProfile(payload: OwnAgentStoreProfile) {
  const { data } = await http.put<ApiEnvelope<OwnAgentStoreProfile>>('/agent/store-profile', payload)
  return data.data
}
