import { http } from './client'
import type { ApiEnvelope } from './client'
import type { AuthUser, PaginationMeta } from './types'

export interface CreateUserPayload {
  role: 'agen' | 'korsal' | 'sales' | 'admin' | 'keuangan' | 'kurir'
  name: string
  email: string
  phone: string
  password: string
  password_confirmation: string
  agent_id?: number
  korsal_id?: number
}

export async function listUsers(page = 1, filters: { role?: string; search?: string; agent_id?: number } = {}) {
  const { data } = await http.get<ApiEnvelope<AuthUser[]>>('/users', { params: { page, ...filters } })
  return { users: data.data, meta: data.meta as unknown as PaginationMeta }
}

export async function getUser(id: number) {
  const { data } = await http.get<ApiEnvelope<AuthUser>>(`/users/${id}`)
  return data.data
}

export async function createUser(payload: CreateUserPayload) {
  const { data } = await http.post<ApiEnvelope<AuthUser>>('/users', payload)
  return data.data
}

export async function updateUser(id: number, payload: { name?: string; phone?: string; status?: 'active' | 'inactive' | 'suspended' }) {
  const { data } = await http.patch<ApiEnvelope<AuthUser>>(`/users/${id}`, payload)
  return data.data
}

/** Moves a sales rep to a different korsal, or a konsumen to a different sales rep — same agent branch only (see UserPolicy::update). */
export async function reassignReferral(userId: number, payload: { korsal_id: number } | { sales_id: number }) {
  const { data } = await http.patch<ApiEnvelope<AuthUser>>(`/users/${userId}/reassign-referral`, payload)
  return data.data
}

/** Soft delete — super_admin only, never a super_admin target (see UserController::destroy). */
export async function deleteUser(id: number): Promise<void> {
  await http.delete(`/users/${id}`)
}
