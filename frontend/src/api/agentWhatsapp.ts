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
  /** The Agen being managed (an Admin Agen sees its branch Agen here). */
  agent?: { id: number; name: string }
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

/**
 * Which authorised Agen the page acts on. Only a *selection* among `getAgentWhatsAppScope()` — the backend re-validates it
 * (403 otherwise) and ignores it for an Agen. Never an authorisation decision made in the browser.
 */
let targetAgentId: number | undefined
export function setAgentWhatsAppTarget(id: number | undefined) {
  targetAgentId = id
}
const params = () => (targetAgentId ? { agent_id: targetAgentId } : undefined)

export async function getAgentWhatsAppScope() {
  const { data } = await http.get<ApiEnvelope<{ agents: { id: number; name: string }[] }>>(`${base}/scope`)
  return data.data.agents
}

export async function getAgentWhatsApp() {
  const { data } = await http.get<ApiEnvelope<AgentWaStatus>>(base, { params: params() })
  return data.data
}

export async function createAgentWhatsAppDevice() {
  const { data } = await http.post<ApiEnvelope<AgentWaStatus>>(`${base}/device`, null, { params: params() })
  return data.data
}

/** `image` is a data: URI built by the server; `expires_in` is seconds. */
export async function startAgentQrPairing() {
  const { data } = await http.post<ApiEnvelope<{ image: string; expires_in: number | null; device: AgentWaDevice }>>(`${base}/pair/qr`, null, { params: params() })
  return data.data
}

export async function startAgentCodePairing(phone: string) {
  const { data } = await http.post<ApiEnvelope<{ code: string; device: AgentWaDevice }>>(`${base}/pair/code`, { phone }, { params: params() })
  return data.data
}

export async function reconnectAgentWhatsApp() {
  const { data } = await http.post<ApiEnvelope<{ device: AgentWaDevice }>>(`${base}/reconnect`, null, { params: params() })
  return data.data.device
}

export async function logoutAgentWhatsApp() {
  const { data } = await http.post<ApiEnvelope<{ device: AgentWaDevice }>>(`${base}/logout`, null, { params: params() })
  return data.data.device
}

/** Drops the paired session and issues a new slot: the caller must have asked for explicit confirmation. */
export async function replaceAgentWhatsAppDevice() {
  const { data } = await http.post<ApiEnvelope<{ device: AgentWaDevice }>>(`${base}/replace`, { confirm: true }, { params: params() })
  return data.data.device
}

export async function listAgentWhatsAppMessages() {
  const { data } = await http.get<ApiEnvelope<AgentWaMessage[]>>(`${base}/messages`, { params: params() })
  return data.data
}

/* ------------------------------------------------------------------ events / templates (per Agen, edited by the Agen or its Admin) */

export interface AgentWaEvent {
  key: string
  label: string
  variables: string[]
  enabled: boolean
  /** 'agent' = this Agen's own record; 'default' = inherited platform default. */
  enabled_source: 'agent' | 'default'
  template: string
  template_source: 'agent' | 'default'
  default_template: string
}

export async function listAgentWaEvents() {
  const { data } = await http.get<ApiEnvelope<AgentWaEvent[]>>(`${base}/events`, { params: params() })
  return data.data
}

export async function updateAgentWaEvent(key: string, changes: { enabled?: boolean; template?: string }) {
  const { data } = await http.put<ApiEnvelope<AgentWaEvent>>(`${base}/events/${key}`, changes, { params: params() })
  return data.data
}

export async function resetAgentWaTemplate(key: string) {
  const { data } = await http.delete<ApiEnvelope<AgentWaEvent>>(`${base}/events/${key}/template`, { params: params() })
  return data.data
}

export async function previewAgentWaTemplate(key: string, template: string) {
  const { data } = await http.post<ApiEnvelope<{ preview: string }>>(`${base}/events/${key}/preview`, { template }, { params: params() })
  return data.data.preview
}

/* ------------------------------------------------------------------ registered test numbers + test-send */

export interface AgentWaTestNumbers {
  numbers: { id: number; masked: string }[]
  max: number
}

export async function listAgentWaTestNumbers() {
  const { data } = await http.get<ApiEnvelope<AgentWaTestNumbers>>(`${base}/test-numbers`, { params: params() })
  return data.data
}

export async function addAgentWaTestNumber(number: string) {
  const { data } = await http.post<ApiEnvelope<AgentWaTestNumbers>>(`${base}/test-numbers`, { number, confirm_authorized: true }, { params: params() })
  return data.data
}

export async function removeAgentWaTestNumber(id: number) {
  const { data } = await http.delete<ApiEnvelope<AgentWaTestNumbers>>(`${base}/test-numbers/${id}`, { params: params() })
  return data.data
}

export interface AgentWaTestResult {
  id: number
  status: 'queued' | 'sending' | 'sent' | 'failed' | 'unknown' | 'skipped'
  recipient: string
  skip_reason: string | null
  error_class: string | null
  /** Last 6 characters of the sender slot that carried it. */
  sender_suffix: string | null
}

export async function sendAgentWaTest(number: string, message?: string) {
  const { data } = await http.post<ApiEnvelope<AgentWaTestResult>>(`${base}/test-send`, { number, message: message || undefined }, { params: params() })
  return data.data
}
