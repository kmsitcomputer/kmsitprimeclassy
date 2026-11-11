import { http } from './client'
import type { ApiEnvelope } from './client'
import type { Order } from './types'

export interface UploadOptions {
  onProgress?: (percent: number) => void
  signal?: AbortSignal
}

export function uploadConfig(opts?: UploadOptions) {
  return {
    headers: { 'Content-Type': 'multipart/form-data' },
    signal: opts?.signal,
    onUploadProgress: opts?.onProgress
      ? (e: { loaded: number; total?: number }) => { if (e.total) opts.onProgress!((e.loaded / e.total) * 100) }
      : undefined,
  }
}

/** Admin/Agen (of the order's branch) or Super Admin only — server re-checks this regardless of what the UI shows. */
export async function verifyBankTransfer(
  orderId: number,
  approved: boolean,
  rejectionReason?: string,
  /** Money actually received. Omitted = the submitted nominal is confirmed as-is; a different value needs a reason. */
  actual?: { verifiedAmount: number; reason?: string },
) {
  const { data } = await http.post<ApiEnvelope<{ transaction: Order['payment_transaction'] }>>(
    `/orders/${orderId}/payment/verify`,
    {
      approved,
      rejection_reason: rejectionReason,
      ...(approved && actual ? { verified_amount: actual.verifiedAmount, amount_adjustment_reason: actual.reason } : {}),
    },
  )
  return data.data
}

export interface InstallmentPayload {
  amount: number
  channel: 'transfer' | 'cash'
  receiptReference?: string
  receivedAt?: string
  receiptPhoto?: File | null
}

/** Records an installment / payoff / cash receipt (pending until Keuangan verifies it). */
export async function recordInstallment(orderId: number, p: InstallmentPayload) {
  const form = new FormData()
  form.append('amount', String(p.amount))
  form.append('channel', p.channel)
  if (p.receiptReference) form.append('receipt_reference', p.receiptReference)
  if (p.receivedAt) form.append('received_at', p.receivedAt)
  if (p.receiptPhoto) form.append('receipt_photo', p.receiptPhoto)
  const { data } = await http.post<ApiEnvelope<{ transaction: Order['payment_transaction']; replay: boolean }>>(
    `/orders/${orderId}/payment/installment`,
    form,
    uploadConfig(),
  )
  return data.data
}

/** Admin / Keuangan / Super Admin: per-order due date with a mandatory reason (audited server-side). */
export async function setOrderDueDate(orderId: number, dueDate: string, reason: string) {
  const { data } = await http.put<ApiEnvelope<{ order_id: number; due_date: string | null }>>(
    `/orders/${orderId}/due-date`,
    { due_date: dueDate, reason },
  )
  return data.data
}

/**
 * Private payment proofs are served only through an authorized endpoint. Use
 * `<ProtectedFileViewer>` (components/ui) in views: it fetches via `fetchProtectedFile`
 * and previews/downloads from a user-triggered control, which works in browsers and installed PWAs
 * (no async `window.open`, which popup protection blocks).
 */
export { fetchProtectedFile, ProtectedFileError } from './protectedFile'

export async function markCodPayment(orderId: number, paid: boolean) {
  const { data } = await http.patch<ApiEnvelope<Order>>(`/orders/${orderId}/payment/cod`, { paid })
  return data.data
}

/** Konsumen submits a photo of the cash handed to the kurir, requesting Admin/Agen mark the COD order paid in full. */
export async function submitCodPaymentProof(orderId: number, proof: File, paidBy: 'konsumen' | 'sales' | 'korsal' = 'konsumen', opts?: UploadOptions, amount?: number) {
  const form = new FormData()
  form.append('proof', proof)
  // REM-004: nominal actually handed over (partial COD). Omitted = the whole open amount.
  if (amount !== undefined && amount > 0) form.append('amount', String(amount))
  form.append('paid_by', paidBy)
  const { data } = await http.post<ApiEnvelope<{ transaction: Order['payment_transaction'] }>>(
    `/orders/${orderId}/payment/cod-proof`,
    form,
    uploadConfig(opts),
  )
  return data.data
}

/** Admin/Agen (of the order's branch) or Super Admin only. */
export async function confirmCodPayment(proofId: number, confirmed: boolean, rejectionReason?: string) {
  const { data } = await http.patch<ApiEnvelope<Order>>(`/admin/cod-payment-proofs/${proofId}/confirm`, {
    confirmed,
    rejection_reason: rejectionReason,
  })
  return data.data
}

/**
 * Keuangan/Super Admin requests settlement (pelunasan) of a DP order's
 * outstanding balance — this only creates a new manual-transfer transaction
 * for the remaining amount; the konsumen then uploads proof through the
 * normal transfer-proof flow and Keuangan verifies it exactly like a DP
 * proof. Pelunasan is never an Additional Payment.
 */
export async function requestDpSettlement(orderId: number) {
  const { data } = await http.post<ApiEnvelope<{ transaction: Order['payment_transaction']; order: Order }>>(
    `/orders/${orderId}/payment/settle`,
  )
  return data.data
}
