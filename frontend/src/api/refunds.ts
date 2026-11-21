import { http } from './client'
import type { ApiEnvelope } from './client'
import { secureUuid } from '@/utils/uuid'

/**
 * Refund ledger — the money-out side of an order (RefundLedgerController).
 *
 * Source of truth (docs/02-SYSTEM-ARCHITECTURE.md): `refund_obligations` (what is owed, lifecycle status)
 * → `refund_attempts` (one payment attempt, idempotent per attempt_no) → `refund_payouts` (the realised
 * payment) → `refund_ledger_events` (append-only). The backend owns every state transition; this module only
 * exposes them, and deliberately re-reads the ledger after each action instead of guessing the new state.
 *
 * Roles: the read is same-agent (or super_admin); every mutation except approve/finalize also requires
 * `keuangan` — the backend enforces it and answers 403 `refund_role_forbidden`.
 */

export type RefundObligationStatus =
  | 'pending'
  | 'approved'
  | 'partially_paid'
  | 'completed'
  | 'needs_verification'
  | 'cancelled'

export type RefundAttemptStatus = 'created' | 'processing' | 'succeeded' | 'failed' | 'unknown' | 'cancelled'

export type RefundAttemptMethod = 'bank_transfer' | 'gateway' | 'cash'

/** `refund_obligations` row (plus its relations, which the ledger nests under the same object). */
export interface RefundObligation {
  id: number
  obligation_no: string
  order_id: number
  agent_id: number
  source_type: 'return_stage' | 'fulfillment_shortfall' | 'cancellation' | 'manual' | 'legacy' | 'deferred'
  status: RefundObligationStatus
  /** Decimal string, as the backend stores money. */
  amount: string
  reason: string | null
  parent_obligation_id: number | null
  approved_by: number | null
  approved_at: string | null
  deferred_requested_at?: string | null
  deferred_amount?: string | null
  created_at: string
  updated_at: string
  /** Set by the ledger read; newest attempt first. */
  attempts: RefundAttempt[]
  payouts: RefundPayout[]
  /** Materialised children of a `deferred` obligation. */
  children: RefundObligation[]
}

/** `refund_attempts` row. */
export interface RefundAttempt {
  id: number
  obligation_id: number
  attempt_no: string
  method: RefundAttemptMethod
  /** Decimal string. */
  amount: string
  status: RefundAttemptStatus
  external_reference: string | null
  destination_snapshot: Record<string, unknown> | unknown[] | null
  failure_code: string | null
  failure_message: string | null
  proof_media_id: number | null
  proof_type: string | null
  initiated_at: string | null
  started_at: string | null
  finished_at: string | null
  unknown_since: string | null
  reconciliation_evidence: string | null
  reconciled_at: string | null
  created_at: string
  updated_at: string
}

/** `refund_payouts` row — the realised money movement. */
export interface RefundPayout {
  id: number
  payout_no: string
  obligation_id: number
  order_id: number
  attempt_id: number | null
  /** Decimal string. */
  amount: string
  method: RefundAttemptMethod
  paid_at: string
  proof_media_id: number | null
  proof_type: string | null
  gateway_reference: string | null
  verification_status: 'proven' | 'unverified' | 'rejected'
  affects_paid_amount: boolean
  recorded_on_cancelled_obligation: boolean
  created_at: string
}

/** `order_sales_reductions` row — a reduction of the order's billable sales value. */
export interface RefundSalesReduction {
  id: number
  order_id: number
  /** Decimal string. */
  amount: string
  reason: string | null
  applied_to_order_total: boolean
  reinstated_at: string | null
  reinstated_by: number | null
  reinstate_evidence: string | null
  created_at: string
}

/** Money summary computed server-side, in cents (integers — no float rounding). */
export interface RefundLedgerMetrics {
  gross_cents: number
  paid_cents: number
  applied_cents: number
  proven_cents: number
  unverified_cents: number
  reserved_cents: number
  billable_cents: number
  outstanding_cents: number
  overpayment_cents: number
  available_cents: number
}

export interface OrderRefundLedger {
  order_id: number
  cancellation_financial_status: string | null
  metrics_cents: RefundLedgerMetrics
  obligations: RefundObligation[]
  reductions: RefundSalesReduction[]
}

/** GET /admin/orders/{order}/refunds — the whole money-out picture of one order. */
export async function getOrderRefundLedger(orderId: number) {
  const { data } = await http.get<ApiEnvelope<OrderRefundLedger>>(`/admin/orders/${orderId}/refunds`)
  return data.data
}

/**
 * POST /admin/orders/{order}/cancellation/finalize — closes the cancellation and freezes the final refund
 * obligations so nothing can be added afterwards. Keuangan (or super_admin) only.
 */
export async function finalizeCancellation(orderId: number) {
  const { data } = await http.post<ApiEnvelope<Record<string, unknown>>>(`/admin/orders/${orderId}/cancellation/finalize`)
  return data.data
}

/** POST /admin/order-sales-reductions/{reduction}/reinstate — gives the sales/commission value back. */
export async function reinstateSalesReduction(reductionId: number, payload: { evidence: string; reason?: string }) {
  const { data } = await http.post<ApiEnvelope<RefundSalesReduction>>(
    `/admin/order-sales-reductions/${reductionId}/reinstate`,
    payload,
  )
  return data.data
}

/** POST /admin/refund-obligations/{obligation}/approve — `pending` → `approved`, the precondition for paying. */
export async function approveRefundObligation(obligationId: number) {
  const { data } = await http.post<ApiEnvelope<RefundObligation>>(`/admin/refund-obligations/${obligationId}/approve`)
  return data.data
}

export interface CreateRefundAttemptPayload {
  /** Integer cents; must be ≥ 1 and must not exceed the obligation's remaining amount. */
  amount_cents: number
  method: RefundAttemptMethod
  external_reference?: string
  destination_snapshot?: Record<string, unknown>
}

/**
 * POST /admin/refund-obligations/{obligation}/attempts — creates one attempt.
 * The backend REQUIRES an `Idempotency-Key` (8–100 chars) and rejects a key reused with another amount/method.
 */
export async function createRefundAttempt(
  obligationId: number,
  payload: CreateRefundAttemptPayload,
  idempotencyKey: string = secureUuid(),
) {
  const { data } = await http.post<ApiEnvelope<RefundAttempt>>(
    `/admin/refund-obligations/${obligationId}/attempts`,
    payload,
    { headers: { 'Idempotency-Key': idempotencyKey } },
  )
  return data.data
}

/** POST .../{obligation}/deferred/request — asks for a deferred obligation to be realised. Requires `Idempotency-Key`. */
export async function requestDeferredMaterialization(
  obligationId: number,
  amountCents: number,
  idempotencyKey: string = secureUuid(),
) {
  const { data } = await http.post<ApiEnvelope<null>>(
    `/admin/refund-obligations/${obligationId}/deferred/request`,
    { amount_cents: amountCents },
    { headers: { 'Idempotency-Key': idempotencyKey } },
  )
  return data.data
}

/** POST .../{obligation}/deferred/materialize — turns deferred value into a real (child) obligation. Requires `Idempotency-Key`. */
export async function materializeDeferred(
  obligationId: number,
  amountCents: number,
  idempotencyKey: string = secureUuid(),
) {
  const { data } = await http.post<ApiEnvelope<RefundObligation>>(
    `/admin/refund-obligations/${obligationId}/deferred/materialize`,
    { amount_cents: amountCents },
    { headers: { 'Idempotency-Key': idempotencyKey } },
  )
  return data.data
}

/**
 * POST .../{obligation}/needs-verification/resolve — manual exit from `needs_verification`.
 * `exit` is `A` (resolve as paid/verified) or `B` (resolve as not paid / reopen) — the backend accepts only those.
 */
export async function resolveRefundVerification(
  obligationId: number,
  payload: { exit: 'A' | 'B'; evidence: string; reason?: string },
) {
  const { data } = await http.post<ApiEnvelope<RefundObligation>>(
    `/admin/refund-obligations/${obligationId}/needs-verification/resolve`,
    payload,
  )
  return data.data
}

/** POST /admin/refund-attempts/{attempt}/initiate — `created` → `processing`, optionally storing the bank/gateway reference. */
export async function initiateRefundAttempt(attemptId: number, payload: { external_reference?: string } = {}) {
  const { data } = await http.post<ApiEnvelope<RefundAttempt>>(`/admin/refund-attempts/${attemptId}/initiate`, payload)
  return data.data
}

export interface CompleteRefundAttemptPayload {
  /** Media id of the transfer proof (must exist in `media`). */
  proof_media_id?: number
  proof_type?: string
  gateway_reference?: string
}

/** POST .../{attempt}/complete — `processing` → `succeeded`; writes a `refund_payout` and the ledger event. */
export async function completeRefundAttempt(attemptId: number, payload: CompleteRefundAttemptPayload = {}) {
  const { data } = await http.post<ApiEnvelope<RefundPayout>>(`/admin/refund-attempts/${attemptId}/complete`, payload)
  return data.data
}

/** POST .../{attempt}/fail — permanent failure with a machine code + human message; the obligation stays open. */
export async function failRefundAttempt(attemptId: number, payload: { code: string; message: string }) {
  const { data } = await http.post<ApiEnvelope<RefundAttempt>>(`/admin/refund-attempts/${attemptId}/fail`, payload)
  return data.data
}

/** POST .../{attempt}/cancel — only an attempt that was never initiated (`created`). */
export async function cancelRefundAttempt(attemptId: number, reason: string) {
  const { data } = await http.post<ApiEnvelope<RefundAttempt>>(`/admin/refund-attempts/${attemptId}/cancel`, { reason })
  return data.data
}

/** POST .../{attempt}/unknown — gateway timeout / no confirmation; moves the attempt into reconciliation. */
export async function markRefundAttemptUnknown(attemptId: number, note?: string) {
  const { data } = await http.post<ApiEnvelope<RefundAttempt>>(`/admin/refund-attempts/${attemptId}/unknown`, {
    note: note ?? undefined,
  })
  return data.data
}

/**
 * POST .../{attempt}/reconcile — matches an `unknown` attempt against external proof (bank mutation).
 * Returns either the attempt or the payout the reconciliation created, so the caller re-reads the ledger.
 */
export async function reconcileRefundAttempt(attemptId: number, payload: { succeeded: boolean; evidence: string }) {
  const { data } = await http.post<ApiEnvelope<RefundAttempt | RefundPayout>>(
    `/admin/refund-attempts/${attemptId}/reconcile`,
    payload,
  )
  return data.data
}
