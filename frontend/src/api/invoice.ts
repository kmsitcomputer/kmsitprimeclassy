import { http } from './client'
import type { ApiEnvelope } from './client'

/** IMP-002 dynamic invoice config (display-only; financial truth stays server-side). */
export interface InvoiceConfig {
  company_name: string
  title: string
  contact: string
  address: string
  footer: string
  notes: string
  show_items: boolean
  show_konsumen: boolean
  show_sales: boolean
  show_korsal: boolean
  show_payment_summary: boolean
  show_voucher: boolean
  logo_media_id: number | null
}

export async function getInvoiceConfig() {
  const { data } = await http.get<ApiEnvelope<InvoiceConfig>>('/invoice/config')
  return data.data
}

export async function saveInvoiceConfig(payload: Partial<InvoiceConfig>) {
  const { data } = await http.put<ApiEnvelope<InvoiceConfig>>('/invoice/config', payload)
  return data.data
}

/** Order-level invoice PDF — returns a blob, NOT JSON. */
export async function downloadInvoice(orderId: number) {
  const response = await http.get(`/orders/${orderId}/invoice`, { responseType: 'blob' })
  return response.data as Blob
}