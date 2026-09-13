import { http } from './client'
import type { ApiEnvelope } from './client'
import type { CheckoutQuote, CheckoutStepsResponse } from './types'
import type { CheckoutDestination, OrderLine } from './orders'

/** Dynamic step list the wizard renders from — never hard-coded in the frontend. */
export async function getCheckoutSteps() {
  const { data } = await http.get<ApiEnvelope<CheckoutStepsResponse>>('/checkout/steps')
  return data.data
}

/** Server-computed total preview for the Review step — the frontend never invents this. */
export async function quoteCheckout(
  items: OrderLine[],
  destination: CheckoutDestination,
  shippingMethod?: string | null,
  konsumenId?: number,
) {
  const { data } = await http.post<ApiEnvelope<CheckoutQuote>>('/checkout/quote', {
    items,
    ...destination,
    shipping_method: shippingMethod ?? null,
    ...(konsumenId ? { konsumen_id: konsumenId } : {}),
  })
  return data.data
}
