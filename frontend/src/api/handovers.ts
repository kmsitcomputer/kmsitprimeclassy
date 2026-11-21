import { http } from './client'
import type { ApiEnvelope } from './client'
import type { StockTransfer } from './transfers'

/**
 * Stock handovers (berita acara serah-terima) written when a transfer is approved.
 *
 * The print endpoint returns an HTML page (A4), not JSON — it is opened in a tab, never fetched.
 */

export interface HandoverUser {
  id: number
  name: string
  [key: string]: unknown
}

export interface StockHandover {
  id: number
  agent_id: number
  stock_transfer_id: number
  handover_number: string | null
  /** Both actors are the full user object the raw model serialises, or null when not yet recorded. */
  handed_over_by: HandoverUser | null
  received_by: HandoverUser | null
  status: string
  handed_over_at: string | null
  received_at: string | null
  note: string | null
  created_at: string
  updated_at: string
  transfer: StockTransfer
}

/** GET /warehouse/handovers/{handover} — the handover with its transfer, items and both actors. */
export async function getHandover(id: number) {
  const { data } = await http.get<ApiEnvelope<StockHandover>>(`/warehouse/handovers/${id}`)
  return data.data
}

/**
 * URL of the printable A4 page (`GET /warehouse/handovers/{handover}/print`).
 * Open with `window.open(url, '_blank')` — the browser sends the session cookie itself.
 */
export function handoverPrintUrl(id: number): string {
  const base = http.defaults.baseURL ?? '/api/v1'
  return `${base}/warehouse/handovers/${id}/print`
}
