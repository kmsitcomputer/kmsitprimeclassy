import { http } from './client'
import type { ApiEnvelope } from './client'

/** Global SMTP (Super Admin). The password is write-only: no endpoint ever returns it, only whether one is stored. */

export type SmtpEncryption = 'ssl' | 'tls' | 'none'

export interface SmtpSettings {
  enabled: boolean
  host: string | null
  port: number | null
  encryption: SmtpEncryption
  username: string | null
  has_password: boolean
  from_address: string | null
  from_name: string | null
  timeout: number
  complete: boolean
  /** Order / payment / shipping / return / refund e-mail (default OFF; needs SMTP ON + complete as well). */
  transactional_enabled: boolean
  /** Which configuration the application is using right now. */
  active_source: 'dashboard' | 'environment'
}

export interface SmtpUpdatePayload {
  enabled?: boolean
  transactional_enabled?: boolean
  host?: string
  port?: number
  encryption?: SmtpEncryption
  username?: string | null
  /** Omit/empty to KEEP the stored password. */
  password?: string | null
  clear_password?: boolean
  from_address?: string
  from_name?: string | null
  timeout?: number
}

export interface SmtpTestResult {
  ok: boolean
  detail: string | null
}

const base = '/admin/integrations/smtp'

export async function getSmtpSettings() {
  const { data } = await http.get<ApiEnvelope<SmtpSettings>>(base)
  return data.data
}

export async function updateSmtpSettings(payload: SmtpUpdatePayload) {
  const { data } = await http.put<ApiEnvelope<SmtpSettings>>(base, payload)
  return data.data
}

export async function testSmtpConnection() {
  const { data } = await http.post<ApiEnvelope<SmtpTestResult>>(`${base}/test-connection`)
  return data.data
}

/** Sends one message to the signed-in Super Admin's own address; the backend accepts no recipient. */
export async function sendSmtpTestEmail() {
  const { data } = await http.post<ApiEnvelope<SmtpTestResult>>(`${base}/test-email`)
  return data.data
}
