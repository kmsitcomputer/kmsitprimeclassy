import { http, ensureCsrfCookie } from './client'
import type { ApiEnvelope } from './client'
import type { AuthPayload, AuthUser } from './types'

export interface RegisterPayload {
  name: string
  email: string
  phone: string
  password: string
  password_confirmation: string
  referral_code: string
}

export async function register(payload: RegisterPayload) {
  await ensureCsrfCookie()
  const { data } = await http.post<ApiEnvelope<AuthPayload>>('/auth/register', payload)
  return data.data
}

export async function login(email: string, password: string) {
  await ensureCsrfCookie()
  const { data } = await http.post<ApiEnvelope<AuthPayload>>('/auth/login', { email, password })
  return data.data
}

export async function logout() {
  await http.post('/auth/logout')
}

export async function me() {
  const { data } = await http.get<ApiEnvelope<AuthPayload>>('/auth/me')
  return data.data
}

export interface UpdateProfilePayload {
  name?: string
  phone?: string
  email?: string
  avatar_media_id?: number | null
}

export async function updateProfile(payload: UpdateProfilePayload) {
  const { data } = await http.patch<ApiEnvelope<AuthUser>>('/profile', payload)
  return data.data
}

export interface UpdatePasswordPayload {
  current_password: string
  password: string
  password_confirmation: string
}

export async function updatePassword(payload: UpdatePasswordPayload) {
  await http.patch('/profile/password', payload)
}

/** agen/korsal/sales only — see ProfileController::updateReferralCode/regenerateReferralCode/deleteReferralCode. */
export async function updateReferralCode(referralCode: string) {
  const { data } = await http.patch<ApiEnvelope<AuthUser>>('/profile/referral-code', { referral_code: referralCode })
  return data.data
}

export async function regenerateReferralCode() {
  const { data } = await http.post<ApiEnvelope<AuthUser>>('/profile/referral-code/regenerate')
  return data.data
}

export async function deleteReferralCode() {
  const { data } = await http.delete<ApiEnvelope<AuthUser>>('/profile/referral-code')
  return data.data
}

export async function previewReferral(code: string) {
  const { data } = await http.get<ApiEnvelope<{ referrer_name: string; agent_store_name: string | null }>>(
    `/referral/${encodeURIComponent(code)}`,
  )
  return data.data
}
