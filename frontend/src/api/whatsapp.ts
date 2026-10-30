import { http } from './client'
import type { ApiEnvelope } from './client'

/** Super Admin WhatsApp Gateway (GOWA) console API. No endpoint ever returns the stored password or an OTP. */

export interface WaEvent {
  key: string
  label: string
  enabled: boolean
  template: string
  is_default_template: boolean
  variables: string[]
  required: string[]
}

export interface WaOtp {
  enabled: boolean
  ttl_minutes: number
  max_attempts: number
  cooldown_seconds: number
  limit: number
  limit_window_minutes: number
}

export interface WaSettings {
  provider: 'gowa'
  enabled: boolean
  base_url: string | null
  username: string | null
  /** The secret itself is never sent to the browser — only whether one is stored. */
  has_password: boolean
  device_id: string | null
  timeout: number
  max_attempts: number
  backoff_seconds: number
  /** Volume protection for the shared WhatsApp device (conservative defaults). */
  limits: { per_recipient_per_hour: number; global_per_minute: number }
  test_numbers: { masked: string | null }[]
  test_numbers_count: number
  otp: WaOtp
  events: WaEvent[]
  configured: boolean
  /** false until the Owner-approved `whatsapp_messages` migration exists on this database. */
  table_ready: boolean
}

export interface WaUpdatePayload {
  enabled?: boolean
  base_url?: string | null
  username?: string | null
  /** Omit/empty to KEEP the stored password. Write-only. */
  password?: string | null
  device_id?: string | null
  timeout?: number
  max_attempts?: number
  backoff_seconds?: number
  limits?: Partial<WaSettings['limits']>
  test_numbers?: string[]
  otp?: Partial<WaOtp>
  events?: Record<string, boolean>
}

export type WaConnectionState = 'not_configured' | 'unreachable' | 'unauthorized' | 'disconnected' | 'connected' | 'error'

export interface WaConnectionStatus {
  state: WaConnectionState
  reachable: boolean
  connected: boolean
  logged_in: boolean
  devices: number
  device_id: string | null
  detail: string | null
}

export type WaMessageStatus = 'queued' | 'sending' | 'sent' | 'failed' | 'unknown' | 'skipped'

export interface WaMessage {
  id: number
  purpose: 'transaction' | 'otp' | 'test'
  event: string | null
  status: WaMessageStatus
  order_id: number | null
  /** Masked (62••••123). */
  recipient: string | null
  body: string | null
  attempts: number
  max_attempts: number
  provider_message_id: string | null
  error_class: string | null
  error_message: string | null
  skip_reason: string | null
  queued_at: string | null
  sent_at: string | null
  created_at: string
  can_retry: boolean
  needs_duplicate_risk_confirmation: boolean
}

export interface WaMessagePage {
  migration_pending: boolean
  data: WaMessage[]
  total: number
  last_page: number
  current_page: number
}

const base = '/admin/integrations/whatsapp'

export async function getWhatsAppSettings() {
  const { data } = await http.get<ApiEnvelope<WaSettings>>(base)
  return data.data
}

export async function updateWhatsAppSettings(payload: WaUpdatePayload) {
  const { data } = await http.put<ApiEnvelope<WaSettings>>(base, payload)
  return data.data
}

export async function testWhatsAppConnection() {
  const { data } = await http.post<ApiEnvelope<WaConnectionStatus>>(`${base}/test-connection`)
  return data.data
}

export async function sendWhatsAppTestMessage(number: string, message?: string) {
  const { data } = await http.post<ApiEnvelope<WaMessage>>(`${base}/test-message`, { number, message: message || undefined })
  return data.data
}

export async function previewWhatsAppTemplate(event: string, template: string) {
  const { data } = await http.post<ApiEnvelope<{ preview: string }>>(`${base}/templates/${event}/preview`, { template })
  return data.data.preview
}

export async function saveWhatsAppTemplate(event: string, template: string) {
  const { data } = await http.put<ApiEnvelope<WaSettings>>(`${base}/templates/${event}`, { template })
  return data.data
}

export async function resetWhatsAppTemplate(event: string) {
  const { data } = await http.delete<ApiEnvelope<WaSettings>>(`${base}/templates/${event}`)
  return data.data
}

export async function listWhatsAppMessages(params: { page?: number; status?: string; purpose?: string; event?: string } = {}) {
  const { data } = await http.get<ApiEnvelope<WaMessagePage>>(`${base}/messages`, { params })
  return data.data
}

/** `acceptDuplicateRisk` is required for an `unknown` message: the retry may deliver a second copy. */
export async function retryWhatsAppMessage(id: number, acceptDuplicateRisk = false) {
  const { data } = await http.post<ApiEnvelope<WaMessage>>(`${base}/messages/${id}/retry`, { accept_duplicate_risk: acceptDuplicateRisk })
  return data.data
}

/** Self-service proof of control of the account's own WhatsApp number. Channel proof only — never consent, never CRM linking. */
export interface WaNumberState {
  has_phone: boolean
  phone_masked: string | null
  verified: boolean
  channel_available: boolean
  pending: boolean
}

export interface WaNumberChallenge {
  challenge_id: number
  expires_at: string
  max_attempts: number
  target: string | null
}

export async function getWhatsAppNumberState() {
  const { data } = await http.get<ApiEnvelope<WaNumberState>>('/profile/whatsapp')
  return data.data
}

/** Resolves to a challenge, or to the state when that exact number is already proven (nothing is sent). */
export async function requestWhatsAppNumberVerification(phone?: string) {
  const { data } = await http.post<ApiEnvelope<WaNumberChallenge | WaNumberState>>('/profile/whatsapp/verification', { phone: phone || undefined })
  return data.data
}

export async function confirmWhatsAppNumberVerification(challengeId: number, code: string) {
  const { data } = await http.post<ApiEnvelope<WaNumberState>>(`/profile/whatsapp/verification/${challengeId}/confirm`, { code })
  return data.data
}
