import { http } from './client'
import type { ApiEnvelope } from './client'

/** Only the verified SimpleV2 vocabulary; `FU` / `D` are intentionally absent until the Owner defines them (CI-07). */
export const VISIT_STATUSES = ['N', '1', '2', '3', '4', '5', '6', '7'] as const
export type VisitStatus = (typeof VISIT_STATUSES)[number]

/** UI abbreviations exactly as in the SimpleV2 visit screens (expansions are not documented anywhere). */
export const VISIT_STATUS_LABEL: Record<VisitStatus, string> = {
  N: 'N',
  '1': 'BS',
  '2': 'MS',
  '3': 'IP',
  '4': 'IH',
  '5': 'MK',
  '6': 'MKK',
  '7': 'Deal',
}

export const PROSPECT_PERCENTS = [0, 25, 50, 75, 90, 100] as const

export interface CrmCustomer {
  id: number
  agent_id: number
  owner_user_id: number
  name: string
  phone: string | null
  email: string | null
  customer_type: string | null
  relation: string | null
  address: string | null
  prospect_percent: number | null
  note: string | null
  archived_at: string | null
  /** Current link to a konsumen ACCOUNT (OD-03); null when unlinked. Never changes any order. */
  linked_konsumen_id?: number | null
  created_at: string
  owner?: { id: number; name: string } | null
}

export interface CrmCustomerPayload {
  name: string
  phone?: string | null
  email?: string | null
  customer_type?: string | null
  relation?: string | null
  address?: string | null
  prospect_percent?: number | null
  note?: string | null
}

export interface CrmVisit {
  id: number
  crm_customer_id: number
  user_id: number
  visit_date: string
  status: VisitStatus
  prospect_percent: number | null
  note: string | null
  plan_next_date: string | null
  customer?: { id: number; name: string; phone: string | null; owner_user_id: number }
}

export interface CrmVisitPayload {
  visit_date: string
  status: VisitStatus
  prospect_percent?: number | null
  note?: string | null
  plan_next_date?: string | null
}

/** Empty strings become null so optional fields clear instead of failing server-side validation. */
function clean<T extends object>(payload: T): T {
  return Object.fromEntries(Object.entries(payload).map(([k, v]) => [k, v === '' ? null : v])) as T
}

/** `stage`: FU = open follow-up (plan_next_date on the latest visit), D = Deal (latest visit status 7). */
export async function listCrmCustomers(params: { archived?: boolean; page?: number; per_page?: number; stage?: 'FU' | 'D' } = {}) {
  // The backend returns a Laravel paginator inside `data` (rows in `data.data`).
  const { data } = await http.get<ApiEnvelope<{ data: CrmCustomer[]; current_page: number; last_page: number; total: number }>>(
    '/crm/customers',
    { params: { ...params, archived: params.archived ? 1 : undefined } },
  )
  const p = data.data
  return { items: p.data, currentPage: p.current_page, lastPage: p.last_page, total: p.total }
}

export async function getCrmCustomer(id: number) {
  const { data } = await http.get<ApiEnvelope<CrmCustomer>>(`/crm/customers/${id}`)
  return data.data
}

export async function createCrmCustomer(payload: CrmCustomerPayload) {
  const { data } = await http.post<ApiEnvelope<CrmCustomer>>('/crm/customers', clean(payload))
  return data.data
}

export async function updateCrmCustomer(id: number, payload: Partial<CrmCustomerPayload>) {
  const { data } = await http.patch<ApiEnvelope<CrmCustomer>>(`/crm/customers/${id}`, clean(payload))
  return data.data
}

export async function archiveCrmCustomer(id: number, restore = false) {
  const { data } = await http.post<ApiEnvelope<CrmCustomer>>(`/crm/customers/${id}/archive`, restore ? { restore: true } : {})
  return data.data
}

export async function listCrmVisits(customerId: number) {
  const { data } = await http.get<ApiEnvelope<CrmVisit[]>>(`/crm/customers/${customerId}/visits`)
  return data.data
}

export async function createCrmVisit(customerId: number, payload: CrmVisitPayload) {
  const { data } = await http.post<ApiEnvelope<CrmVisit>>(`/crm/customers/${customerId}/visits`, clean(payload))
  return data.data
}

export async function updateCrmVisit(visitId: number, payload: Partial<CrmVisitPayload>) {
  const { data } = await http.patch<ApiEnvelope<CrmVisit>>(`/crm/visits/${visitId}`, clean(payload))
  return data.data
}

/** Latest plan per customer whose `plan_next_date` is on/before `until` (default: today), within the viewer's read scope. */
export async function listCrmFollowUps(until?: string) {
  const { data } = await http.get<ApiEnvelope<CrmVisit[]>>('/crm/follow-ups', { params: { until } })
  return data.data
}

/**
 * XLSX export of the CRM customer list, filtered by the CURRENT toolbar state — the backend applies
 * the same CrmAccess scope as the JSON list (export never widens it), then audits the mass read.
 * Opens in a new tab; the browser downloads using the active session cookie.
 */
export function downloadCrmCustomersXlsx(filters: { archived?: boolean; stage?: '' | 'FU' | 'D' } = {}) {
  const params = new URLSearchParams()
  if (filters.archived) params.set('archived', '1')
  if (filters.stage) params.set('stage', filters.stage)
  params.set('export', 'xlsx')
  const apiBase = (window.__APP_CONFIG__?.API_URL ?? import.meta.env.VITE_API_URL ?? '') + '/api/v1'
  window.open(`${apiBase}/crm/customers?${params.toString()}`, '_blank')
}

/* ---------------- REM-005 consent / OTP ---------------- */

export interface ConsentContext {
  /** 'crm' = the order's buyer origin is a CRM customer (no account, no OTP channel). */
  mode?: 'crm'
  crm_customer_id?: number
  /** CRM mode: true only when the customer is linked to an account with a verified email (the sole OTP channel). */
  channel_available?: boolean
  can_capture: boolean
  purposes: string[]
  subject_user_id?: number
  subject_email_verified?: boolean
  items?: { id: number; name: string; variation: string | null; max_quantity: number }[]
}

export type ConsentEventType = 'consent_captured' | 'consent_withdrawn' | 'consent_disputed' | 'consent_reinstated'

export interface ConsentEvent {
  id: number
  crm_customer_id?: number | null
  root_event_id: number | null
  event_type: ConsentEventType
  purpose: string
  scope: { order_item_id: number; quantity: number }[] | null
  has_evidence: boolean
  evidence_sha256: string | null
  note: string | null
  actor_id: number
  occurred_at: string
}

export async function getConsentContext(orderId: number) {
  const { data } = await http.get<ApiEnvelope<ConsentContext>>(`/orders/${orderId}/consent-context`)
  return data.data
}

export async function listConsentEvents(orderId: number) {
  const { data } = await http.get<ApiEnvelope<ConsentEvent[]>>(`/orders/${orderId}/consent-events`)
  return data.data
}

/** The OTP itself is emailed to the customer; the response only carries the challenge id. */
export async function issueConsentOtp(subjectUserId: number, purpose: string) {
  const { data } = await http.post<ApiEnvelope<{ challenge_id: number; expires_at: string; max_attempts: number }>>(
    '/consent/otp',
    { subject_user_id: subjectUserId, purpose },
  )
  return data.data
}

export async function verifyConsentOtp(challengeId: number, code: string) {
  await http.post(`/consent/otp/${challengeId}/verify`, { code })
}

export interface CaptureConsentInput {
  subjectUserId: number
  orderId: number
  purpose: string
  challengeId: number
  note: string
  scope: { order_item_id: number; quantity: number }[]
  evidence: File
  consentKey: string
}

export async function captureConsent(input: CaptureConsentInput) {
  const form = new FormData()
  form.append('subject_user_id', String(input.subjectUserId))
  form.append('order_id', String(input.orderId))
  form.append('purpose', input.purpose)
  form.append('otp_challenge_id', String(input.challengeId))
  form.append('note', input.note)
  form.append('consent_key', input.consentKey)
  input.scope.forEach((line, i) => {
    form.append(`scope[${i}][order_item_id]`, String(line.order_item_id))
    form.append(`scope[${i}][quantity]`, String(line.quantity))
  })
  form.append('evidence', input.evidence)

  const { data } = await http.post<ApiEnvelope<ConsentEvent>>('/consent-events', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

export type ConsentAction = 'withdraw' | 'dispute' | 'reinstate'

export async function consentAction(rootEventId: number, action: ConsentAction, reason: string) {
  const { data } = await http.post<ApiEnvelope<ConsentEvent>>(`/consent-events/${rootEventId}/${action}`, { reason })
  return data.data
}

/* ---------------- Phase 2 / G17: Assisted Order, linking, CRM consent, assisted return, refund recipient ---------------- */

export interface AssistedOrderPayload {
  items: { product_id: number; product_variation_id?: number | null; quantity: number }[]
  payment_method_code: string
  recipient_name: string
  recipient_phone: string
  address_line: string
  village_id: string
  latitude: number
  longitude: number
  postal_code?: string | null
  delivery_date?: string | null
  /** `openroute` (Kurir Online) or `pickup`. Ekspedisi/RajaOngkir needs the courier picker and is not offered. */
  shipping_method?: string | null
  dp_amount?: number | null
  voucher_code?: string | null
}

/** The key must stay the same for a retry of the SAME submission (server replays instead of duplicating). */
export async function placeAssistedOrder(customerId: number, payload: AssistedOrderPayload, idempotencyKey: string) {
  const { data } = await http.post<ApiEnvelope<{ id: number; order_no: string; total_amount: string; buyer_origin: string }>>(
    `/crm/customers/${customerId}/orders`,
    clean(payload),
    { headers: { 'Idempotency-Key': idempotencyKey } },
  )
  return data.data
}

export async function requestLinkOtp(customerId: number, konsumenId: number) {
  const { data } = await http.post<ApiEnvelope<{ challenge_id: number; expires_at: string; max_attempts: number }>>(
    `/crm/customers/${customerId}/link-otp`,
    { konsumen_id: konsumenId },
  )
  return data.data
}

export async function linkCrmCustomer(customerId: number, konsumenId: number, challengeId: number) {
  const { data } = await http.post<ApiEnvelope<CrmCustomer>>(`/crm/customers/${customerId}/link`, {
    konsumen_id: konsumenId,
    otp_challenge_id: challengeId,
  })
  return data.data
}

export async function unlinkCrmCustomer(customerId: number, reason: string) {
  const { data } = await http.post<ApiEnvelope<CrmCustomer>>(`/crm/customers/${customerId}/unlink`, { reason })
  return data.data
}

/**
 * Owner 2026-10-31: CRM consent is OTP-verified on the verified email of the konsumen account the customer is LINKED
 * to. The code is emailed there (never returned). 409 = no verified channel (customer not linked): the action is blocked.
 */
export async function issueCrmConsentOtp(customerId: number, purpose: string) {
  const { data } = await http.post<ApiEnvelope<{ challenge_id: number; expires_at: string; max_attempts: number }>>(
    `/crm/customers/${customerId}/consent-otp`,
    { purpose },
  )
  return data.data
}

/** The verified OTP challenge is mandatory; photo + note are additional evidence, never a substitute. */
export async function captureCrmConsent(customerId: number, input: {
  challengeId: number
  orderId: number
  purpose: string
  note: string
  scope: { order_item_id: number; quantity: number }[]
  evidence: File
  consentKey: string
}) {
  const form = new FormData()
  form.append('otp_challenge_id', String(input.challengeId))
  form.append('order_id', String(input.orderId))
  form.append('purpose', input.purpose)
  form.append('note', input.note)
  form.append('consent_key', input.consentKey)
  input.scope.forEach((line, i) => {
    form.append(`scope[${i}][order_item_id]`, String(line.order_item_id))
    form.append(`scope[${i}][quantity]`, String(line.quantity))
  })
  form.append('evidence', input.evidence)

  const { data } = await http.post<ApiEnvelope<ConsentEvent>>(`/crm/customers/${customerId}/consent-events`, form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

export async function initiateAssistedReturn(orderId: number, input: {
  consentEventId: number
  reason: string
  items: { order_item_id: number; quantity: number }[]
  evidence: File
}) {
  const form = new FormData()
  form.append('consent_event_id', String(input.consentEventId))
  form.append('reason', input.reason)
  input.items.forEach((line, i) => {
    form.append(`items[${i}][order_item_id]`, String(line.order_item_id))
    form.append(`items[${i}][quantity]`, String(line.quantity))
  })
  form.append('evidence', input.evidence)

  const { data } = await http.post<ApiEnvelope<{ id: number; status: string }>>(`/orders/${orderId}/assisted-returns`, form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

export type RecipientMethod = 'bank_transfer' | 'ewallet' | 'cash'

export interface RefundRecipient {
  id: number
  recipient_name: string
  recipient_phone: string | null
  recipient_relation: string | null
  method: RecipientMethod
  /** Full details for finance roles; a masked `account_number_tail` for everyone else. */
  destination: { bank_or_provider?: string; account_number?: string; account_holder?: string; account_number_tail?: string } | null
  consent_event_id: number | null
  has_evidence_file: boolean
  authorization_note: string
  supersedes_id: number | null
  occurred_at: string
}

export async function listRefundRecipients(orderId: number) {
  const { data } = await http.get<ApiEnvelope<RefundRecipient[]>>(`/orders/${orderId}/refund-recipient`)
  return data.data
}

export async function recordRefundRecipient(orderId: number, input: {
  recipient_name: string
  recipient_phone?: string
  recipient_relation?: string
  method: RecipientMethod
  destination?: { bank_or_provider?: string; account_number?: string; account_holder?: string }
  authorization_note: string
  consent_event_id?: number | null
  evidence?: File | null
}) {
  const form = new FormData()
  form.append('recipient_name', input.recipient_name)
  if (input.recipient_phone) form.append('recipient_phone', input.recipient_phone)
  if (input.recipient_relation) form.append('recipient_relation', input.recipient_relation)
  form.append('method', input.method)
  form.append('authorization_note', input.authorization_note)
  if (input.consent_event_id) form.append('consent_event_id', String(input.consent_event_id))
  if (input.destination) {
    for (const [k, v] of Object.entries(input.destination)) if (v) form.append(`destination[${k}]`, v)
  }
  if (input.evidence) form.append('evidence', input.evidence)

  const { data } = await http.post<ApiEnvelope<RefundRecipient>>(`/orders/${orderId}/refund-recipient`, form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

/** Accounts the owning seller may link: their OWN referred, unlinked konsumen (contact masked). Includes those with no order yet. */
export interface LinkCandidate {
  id: number
  name: string
  phone_tail: string | null
  email_masked: string | null
  email_verified: boolean
}

export async function listLinkCandidates() {
  const { data } = await http.get<ApiEnvelope<LinkCandidate[]>>('/crm/link-candidates')
  return data.data
}
