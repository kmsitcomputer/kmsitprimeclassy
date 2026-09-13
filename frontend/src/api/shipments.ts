import { http } from './client'
import type { ApiEnvelope } from './client'
import type { Order } from './types'

/**
 * Per-shipment delivery progress — "satu order bisa beberapa kurir" (an
 * order can have more than one shipment once an item is rescheduled), so
 * this always targets a specific shipment_id (see OrderItem.shipment_id),
 * never the order as a whole. Marking 'terkirim' as a kurir requires a
 * delivery proof photo — the backend enforces this regardless of what's sent.
 */
export async function updateShipmentStatus(shipmentId: number, status: 'dikirim' | 'terkirim', proof?: File) {
  const form = new FormData()
  form.append('status', status)
  if (proof) form.append('proof', proof)
  form.append('_method', 'PATCH')

  const { data } = await http.post<ApiEnvelope<Order>>(`/shipments/${shipmentId}/status`, form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data.data
}

/** Office-only (admin/agen/super_admin) proactive assignment — a kurir self-assigns instead by marking 'dikirim'. */
export async function assignCourier(shipmentId: number, courierId: number) {
  const { data } = await http.patch<ApiEnvelope<Order>>(`/shipments/${shipmentId}/courier`, { courier_id: courierId })
  return data.data
}
