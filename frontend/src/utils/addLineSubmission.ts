import type { AddOrderItemPayload } from '@/api/orderAdjustments'

/**
 * Package C / SC-03 (C-SC03-REV-005): submission identity for "add a product to an existing order".
 *
 * An Idempotency-Key only protects against duplicates if the SAME key is re-sent for the SAME logical
 * request, so the key must outlive the form. Lifecycle:
 *
 *   NEW logical submission  -> no pending record; the first submit mints a key and persists it with
 *                              the normalized payload BEFORE the request leaves (in-flight = same key).
 *   IN-FLIGHT / AMBIGUOUS   -> the pending record stays (network error, timeout, 5xx: the server may
 *                              have committed). Retry re-sends the stored key + stored payload.
 *   DISMISS + REOPEN        -> the pending record is restored (sessionStorage, per order); the form
 *                              is locked to the stored payload so the key can't meet a changed body.
 *   CONFIRMED SUCCESS       -> record cleared.
 *   DEFINITIVE REJECTION    -> (4xx: validation/authority/state/conflict) nothing was applied, so the
 *                              record is cleared and the next submit is a new logical submission.
 *   EXPLICIT DISCARD        -> user chose "start new": record cleared, next submit mints a new key.
 *
 * The backend 409 on key reuse with a different payload remains the final guard.
 */
export interface PendingAddLine {
  key: string
  payload: AddOrderItemPayload
  /** Display-only labels so a restored submission can be shown without the product list loaded. */
  label: string
}

const storageKey = (orderId: number) => `sc03:add-line:${orderId}`

/** Canonical payload shape: what is stored, compared and sent are always identical. */
export function normalizeAddLinePayload(p: AddOrderItemPayload): AddOrderItemPayload {
  return {
    product_id: Number(p.product_id),
    product_variation_id: p.product_variation_id ? Number(p.product_variation_id) : null,
    quantity: Number(p.quantity),
    requested_delivery_date: p.requested_delivery_date || null,
    reason: (p.reason ?? '').trim(),
    additional_payment_method: p.additional_payment_method ?? 'transfer',
  }
}

export function sameAddLinePayload(a: AddOrderItemPayload, b: AddOrderItemPayload): boolean {
  return JSON.stringify(normalizeAddLinePayload(a)) === JSON.stringify(normalizeAddLinePayload(b))
}

/** The server may have committed the request: no response (status 0 = network/timeout), 408, or 5xx. */
export function isAmbiguousAddLineFailure(status: number | undefined): boolean {
  return status === undefined || status === 0 || status === 408 || status >= 500
}

export function loadPendingAddLine(orderId: number): PendingAddLine | null {
  try {
    const raw = window.sessionStorage.getItem(storageKey(orderId))
    if (!raw) return null
    const parsed = JSON.parse(raw) as PendingAddLine
    return parsed?.key && parsed.payload ? parsed : null
  } catch {
    return null
  }
}

export function savePendingAddLine(orderId: number, pending: PendingAddLine): void {
  try {
    window.sessionStorage.setItem(storageKey(orderId), JSON.stringify(pending))
  } catch {
    /* storage unavailable: the in-memory record still protects direct retries */
  }
}

export function clearPendingAddLine(orderId: number): void {
  try {
    window.sessionStorage.removeItem(storageKey(orderId))
  } catch {
    /* ignore */
  }
}

export function newAddLineSubmission(payload: AddOrderItemPayload, label: string): PendingAddLine {
  return { key: crypto.randomUUID(), payload: normalizeAddLinePayload(payload), label }
}
