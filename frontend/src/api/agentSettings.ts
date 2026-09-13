import { http } from './client'
import type { ApiEnvelope } from './client'

/**
 * An Agen's own scoped payment-method settings — on/off (never the global
 * super_admin switch) plus, for manual/gateway types, their own credentials
 * and (for gateway types) which environment is active. Never visible or
 * writable by another agen.
 */
export interface AgentPaymentMethodRow {
  id: number
  code: string
  name: string
  type: 'cod' | 'manual' | 'gateway'
  globally_active: boolean
  is_active: boolean
  active_environment: 'sandbox' | 'production' | null
  configured: boolean | null
}

export async function getAgentPaymentMethods() {
  const { data } = await http.get<ApiEnvelope<AgentPaymentMethodRow[]>>('/agent/payment-methods')
  return data.data
}

export async function toggleAgentPaymentMethod(id: number) {
  const { data } = await http.patch<ApiEnvelope<AgentPaymentMethodRow>>(`/agent/payment-methods/${id}/toggle`)
  return data.data
}

export async function setAgentPaymentMethodEnvironment(id: number, environment: 'sandbox' | 'production') {
  await http.patch(`/agent/payment-methods/${id}/environment`, { environment })
}

export async function saveAgentPaymentMethodConfig(id: number, config: Record<string, string | string[]>, environment?: 'sandbox' | 'production') {
  await http.put(`/agent/payment-methods/${id}/config`, { config, environment })
}

/** An Agen's own scoped shipping-provider settings — on/off plus their own credentials/rate config. */
export interface AgentShippingProviderRow {
  id: number
  code: string
  name: string
  globally_active: boolean
  is_active: boolean
  configured: boolean
}

export async function getAgentShippingProviders() {
  const { data } = await http.get<ApiEnvelope<AgentShippingProviderRow[]>>('/agent/shipping-providers')
  return data.data
}

export async function toggleAgentShippingProvider(id: number) {
  const { data } = await http.patch<ApiEnvelope<AgentShippingProviderRow>>(`/agent/shipping-providers/${id}/toggle`)
  return data.data
}

export interface AgentRajaOngkirConfigPayload {
  config: { api_key: string; account_type: 'starter' | 'basic' | 'pro'; origin_city_id: string; couriers: string[] }
}
export interface AgentOpenRouteConfigPayload {
  config: { api_key: string; base_url?: string }
  price_per_km: number
  minimum_distance_km: number
  minimum_charge?: number
  free_shipping_enabled?: boolean
  free_shipping_min_amount?: number | null
}

export async function saveAgentShippingProviderConfig(id: number, payload: AgentRajaOngkirConfigPayload | AgentOpenRouteConfigPayload) {
  const { data } = await http.put<ApiEnvelope<AgentShippingProviderRow>>(`/agent/shipping-providers/${id}/config`, payload)
  return data.data
}
