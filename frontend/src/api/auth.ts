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

/** agen/korsal/sales/sales-kurir-sub — see ProfileController referral-code routes. */
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

/**
 * IMP-001: browser-navigation URL that starts Google sign-in (top-level redirect, never XHR). `register`
 * mode needs a referral code; the backend re-validates it and re-resolves the hierarchy itself.
 */
export function googleAuthUrl(mode: 'login' | 'register' | 'link', referralCode?: string): string {
  const base = window.__APP_CONFIG__?.API_URL ?? import.meta.env.VITE_API_URL ?? ''
  const params = new URLSearchParams({ mode })
  if (mode === 'register' && referralCode) params.set('referral_code', referralCode.trim())
  return `${base}/api/v1/auth/google/redirect?${params.toString()}`
}
