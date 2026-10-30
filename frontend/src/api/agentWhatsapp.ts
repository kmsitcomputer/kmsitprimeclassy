import { http } from './client'
import type { ApiEnvelope } from './client'

/**
 * The signed-in Agen's OWN WhatsApp device on the central GOWA server. Nothing here names an Agent or a device: the backend resolves
 * both from the session. No GOWA credential, slot id, QR source URL or full number is ever returned.
 */

export type AgentWaState = 'not_paired' | 'pairing' | 'connected' | 'disconnected'

export interface AgentWaDevice {
  state: AgentWaState
  /** Masked (e.g. `62••••077`); the full number never reaches the browser. */
  phone_masked: string | null
  paired_at: string | null
  last_checked_at: string | null
  replaced_count: number
}

export interface AgentWaStatus {
  /** The Super Admin's global switch. */
  global_enabled: boolean
  /** The central gateway connection is complete (the Agent cannot see or change it). */
  gateway_ready: boolean
  table_ready: boolean
  capabilities: { qr: boolean; pair_code: boolean }
  device: AgentWaDevice | null
}

export interface AgentWaMessage {
  id: number
  purpose: 'transaction' | 'otp' | 'test'
  event: string | null
  status: 'queued' | 'sending' | 'sent' | 'failed' | 'unknown' | 'skipped'
  recipient: string | null
  attempts: number
  skip_reason: string | null
  error_class: string | null
  created_at: string
  sent_at: string | null
}

const base = '/agent/whatsapp'

export async function getAgentWhatsApp() {
  const { data } = await http.get<ApiEnvelope<AgentWaStatus>>(base)
  return data.data
}

export async function createAgentWhatsAppDevice() {
  const { data } = await http.post<ApiEnvelope<AgentWaStatus>>(`${base}/device`)
  return data.data
}

/** `image` is a data: URI built by the server; `expires_in` is seconds. */
export async function startAgentQrPairing() {
  const { data } = await http.post<ApiEnvelope<{ image: string; expires_in: number | null; device: AgentWaDevice }>>(`${base}/pair/qr`)
  return data.data
}

export async function startAgentCodePairing(phone: string) {
  const { data } = await http.post<ApiEnvelope<{ code: string; device: AgentWaDevice }>>(`${base}/pair/code`, { phone })
  return data.data
}

export async function reconnectAgentWhatsApp() {
  const { data } = await http.post<ApiEnvelope<{ device: AgentWaDevice }>>(`${base}/reconnect`)
  return data.data.device
}

export async function logoutAgentWhatsApp() {
  const { data } = await http.post<ApiEnvelope<{ device: AgentWaDevice }>>(`${base}/logout`)
  return data.data.device
}

/** Drops the paired session and issues a new slot: the caller must have asked for explicit confirmation. */
export async function replaceAgentWhatsAppDevice() {
  const { data } = await http.post<ApiEnvelope<{ device: AgentWaDevice }>>(`${base}/replace`, { confirm: true })
  return data.data.device
}

export async function listAgentWhatsAppMessages() {
  const { data } = await http.get<ApiEnvelope<AgentWaMessage[]>>(`${base}/messages`)
  return data.data
}
