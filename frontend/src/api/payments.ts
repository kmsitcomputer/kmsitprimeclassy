import { http } from './client'
import type { ApiEnvelope } from './client'
import type { Order } from './types'

/** Super Admin's GLOBAL on/off view — credentials are configured per-agen instead (see agentSettings.ts). */
export interface PaymentGateway {
  id: number
  code: string
  name: string
  type: 'manual' | 'gateway' | 'cod'
  is_active: boolean
  webhook_url: string | null
}

export async function listPaymentGateways() {
  const { data } = await http.get<ApiEnvelope<PaymentGateway[]>>('/admin/payment-gateways')
  return data.data
}

export async function togglePaymentGateway(methodId: number) {
  const { data } = await http.patch<ApiEnvelope<PaymentGateway>>(`/admin/payment-gateways/${methodId}/toggle`)
  return data.data
}

/** Admin/Agen (of the order's branch) or Super Admin only — server re-checks this regardless of what the UI shows. */
export async function verifyBankTransfer(orderId: number, approved: boolean, rejectionReason?: string) {
  const { data } = await http.post<ApiEnvelope<{ transaction: Order['payment_transaction'] }>>(
    `/orders/${orderId}/payment/verify`,
    { approved, rejection_reason: rejectionReason },
  )
  return data.data
}

export async function markCodPayment(orderId: number, paid: boolean) {
  const { data } = await http.patch<ApiEnvelope<Order>>(`/orders/${orderId}/payment/cod`, { paid })
  return data.data
}

/** Konsumen submits a photo of the cash handed to the kurir, requesting Admin/Agen mark the COD order paid in full. */
export async function submitCodPaymentProof(orderId: number, proof: File) {
  const form = new FormData()
  form.append('proof', proof)
  const { data } = await http.post<ApiEnvelope<{ transaction: Order['payment_transaction'] }>>(
    `/orders/${orderId}/payment/cod-proof`,
    form,
    { headers: { 'Content-Type': 'multipart/form-data' } },
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
