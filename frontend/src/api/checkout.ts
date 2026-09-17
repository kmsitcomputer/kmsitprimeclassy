import { http } from './client'
import type { ApiEnvelope } from './client'
import type { CheckoutQuote, CheckoutStepsResponse, CourierOption, CourierSelection } from './types'
import type { CheckoutDestination, OrderLine } from './orders'

/**
 * Dynamic step list the wizard renders from — never hard-coded in the
 * frontend. `shippingMethod` (once chosen) narrows `payment_methods` to
 * whatever is actually compatible (e.g. Ekspedisi -> Manual Transfer only) —
 * see AvailablePaymentMethodService. Omit it while shipping hasn't been
 * picked yet.
 */
export async function getCheckoutSteps(shippingMethod?: string | null) {
  const { data } = await http.get<ApiEnvelope<CheckoutStepsResponse>>('/checkout/steps', {
    params: shippingMethod ? { shipping_method: shippingMethod } : undefined,
  })
  return data.data
}

/** Server-computed total preview for the Review step — the frontend never invents this. */
export async function quoteCheckout(
  items: OrderLine[],
  destination: CheckoutDestination,
  shippingMethod?: string | null,
  konsumenId?: number,
  courier?: CourierSelection | null,
) {
  const { data } = await http.post<ApiEnvelope<CheckoutQuote>>('/checkout/quote', {
    items,
    ...destination,
    shipping_method: shippingMethod ?? null,
    ...(konsumenId ? { konsumen_id: konsumenId } : {}),
    ...(courier ? { courier: courier.courier, service: courier.service } : {}),
  })
  return data.data
}

/** Every courier/service "Ekspedisi" (RajaOngkir) currently offers for this destination+cart, cheapest first. */
export async function getCourierOptions(
  items: OrderLine[],
  destination: CheckoutDestination,
  konsumenId?: number,
) {
  const { data } = await http.post<ApiEnvelope<CourierOption[]>>('/checkout/courier-options', {
    items,
    ...destination,
    ...(konsumenId ? { konsumen_id: konsumenId } : {}),
  })
  return data.data
}
