import { http } from './client'
import type { ApiEnvelope } from './client'
import type { CourierSelection, Order, PaginationMeta } from './types'

export interface OrderLine {
  product_id: number
  product_variation_id?: number | null
  quantity: number
}

export interface CheckoutDestination {
  /** When set, the backend re-resolves the destination from this saved address itself — every other field below is ignored. */
  address_id?: number | null
  recipient_name?: string
  recipient_phone?: string
  address_line?: string
  village_id?: string | null
  latitude?: number | null
  longitude?: number | null
  /** IMP-002 structured address additions */
  postal_code?: string | null
  province_id?: string | null
  regency_id?: string | null
  district_id?: string | null
}

export interface CreateOrderPayload {
  items: OrderLine[]
  destination: CheckoutDestination
  paymentMethodCode: string
  deliveryDate?: string | null
  /** DP only — the partial nominal the konsumen pays now (server re-validates 0 < dp < total). */
  dpAmount?: number | null
  /** "Ekspedisi" (rajaongkir) / "Kurir Online" (openroute) — only meaningful when both are active. */
  shippingMethod?: string | null
  /** The specific courier+service picked under "Ekspedisi" (e.g. JNE REG) — only meaningful with shippingMethod: 'rajaongkir'. */
  courier?: CourierSelection | null
  konsumenId?: number | null
  /** IMP-002 voucher code — server re-validates everything. */
  voucherCode?: string | null
  /** Client-generated key (e.g. secureUuid() from @/utils/uuid) — a retried submission with the
   *  same key returns the original order instead of creating a duplicate. */
  idempotencyKey: string
}

export async function createOrder(payload: CreateOrderPayload) {
  const { data } = await http.post<ApiEnvelope<Order>>(
    '/orders',
    {
      items: payload.items,
      ...payload.destination,
      payment_method_code: payload.paymentMethodCode,
      delivery_date: payload.deliveryDate ?? null,
      shipping_method: payload.shippingMethod ?? null,
      ...(payload.courier ? { courier: payload.courier.courier, service: payload.courier.service } : {}),
      ...(payload.dpAmount ? { dp_amount: payload.dpAmount } : {}),
      ...(payload.konsumenId ? { konsumen_id: payload.konsumenId } : {}),
      ...(payload.voucherCode ? { voucher_code: payload.voucherCode } : {}),
    },
    { headers: { 'Idempotency-Key': payload.idempotencyKey } },
  )
  return data.data
}

/** Server-side order filters. Dispatch-equivalent keys (delivery_date, region ids, paid) share one backend interpretation. */
export interface OrderListFilters {
  status?: string
  payment_status?: string
  search?: string
  delivery_date?: string
  province_id?: string
  regency_id?: string
  district_id?: string
  village_id?: string
  paid?: 'paid' | 'unpaid'
  agent_id?: number
}

function cleanFilters(filters: object): Record<string, string | number> {
  return Object.fromEntries(
    Object.entries(filters).filter(([, v]) => v !== undefined && v !== null && v !== ''),
  ) as Record<string, string | number>
}

export async function listOrders(page = 1, filters: OrderListFilters = {}) {
  const { data } = await http.get<ApiEnvelope<Order[]>>('/orders', { params: { page, ...cleanFilters(filters) } })
  return { orders: data.data, meta: data.meta as unknown as PaginationMeta }
}

export interface OrderRegionOptions {
  provinces: { id: string; name: string }[]
  regencies: { id: string; name: string }[]
  districts: { id: string; name: string }[]
  villages: { id: string; name: string }[]
}

/** Cascading region options derived only from the caller's own scoped orders. */
export async function listOrderRegionOptions(filters: OrderListFilters = {}): Promise<OrderRegionOptions> {
  const { data } = await http.get<ApiEnvelope<OrderRegionOptions>>('/orders/regions', { params: cleanFilters(filters) })
  return data.data
}

export async function getOrder(id: number) {
  const { data } = await http.get<ApiEnvelope<Order>>(`/orders/${id}`)
  return data.data
}

export async function cancelOrder(id: number, reason: string) {
  const { data } = await http.post<ApiEnvelope<Order>>(`/orders/${id}/cancel`, { reason })
  return data.data
}

/**
 * Office-only bulk status advance (super_admin/agen/admin — see
 * OrderPolicy::updateStatus). This is the ONLY way an order ever leaves
 * 'diterima' for 'diproses' — COD skips the payment-verification gate
 * (`OrderService::updateStatus`) but still requires this explicit action;
 * nothing does it automatically.
 */
export async function updateOrderStatus(id: number, status: 'diproses' | 'dikirim' | 'terkirim') {
  const { data } = await http.patch<ApiEnvelope<Order>>(`/orders/${id}/status`, { status })
  return data.data
}

export async function submitBankTransferProof(orderId: number, proof: File, paidBy: 'konsumen' | 'sales' | 'korsal' = 'konsumen') {
  const form = new FormData()
  form.append('proof', proof)
  form.append('paid_by', paidBy)
  const { data } = await http.post<ApiEnvelope<{ transaction: Order['payment_transaction'] }>>(
    `/orders/${orderId}/payment/proof`,
    form,
    { headers: { 'Content-Type': 'multipart/form-data' } },
  )
  return data.data
}
