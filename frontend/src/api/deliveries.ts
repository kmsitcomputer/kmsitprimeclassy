import { http } from './client'
import type { ApiEnvelope } from './client'
import type { DeliveryVerification, DeliveryVerificationOutcome } from './types'

/**
 * R-03 / decision B: Admin final delivery verification. Append-only on the server (a later outcome
 * never erases an earlier one), Admin-only / same-Agent, and idempotent by Idempotency-Key. This is
 * separate from payment verification and from the courier's own delivery action.
 */
export async function listDeliveryVerifications(shipmentId: number) {
  const { data } = await http.get<ApiEnvelope<DeliveryVerification[]>>(
    `/shipments/${shipmentId}/delivery-verifications`,
  )
  return data.data
}

export async function recordDeliveryVerification(
  shipmentId: number,
  outcome: DeliveryVerificationOutcome,
  note: string | null,
  idempotencyKey: string,
) {
  const { data } = await http.post<ApiEnvelope<DeliveryVerification>>(
    `/shipments/${shipmentId}/delivery-verifications`,
    { outcome, note },
    { headers: { 'Idempotency-Key': idempotencyKey } },
  )
  return data.data
}
