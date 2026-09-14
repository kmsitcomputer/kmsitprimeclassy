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
  api_version?: 'komerce_v2' | 'legacy' | null
  origin?: string | null
  couriers?: string[]
  profile?: string | null
  origin_coordinates?: { latitude: number; longitude: number } | null
  pricing?: { price_per_km: number; minimum_distance_km: number; minimum_charge: number } | null
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
  config: { api_key: string; api_version: 'komerce_v2'; origin_destination_id: string; origin_label: string; origin_search: string; couriers: string[] }
}
export interface AgentOpenRouteConfigPayload {
  config: { api_key: string; profile: string }
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

/** One provider-supported courier (RajaOngkir/Komerce master list), flagged with whether THIS agen currently has it enabled. */
export interface AgentCourierRow {
  code: string
  name: string
  supported: boolean
  enabled: boolean
}

export async function getAgentShippingCouriers(providerId: number) {
  const { data } = await http.get<ApiEnvelope<AgentCourierRow[]>>(`/agent/shipping-providers/${providerId}/couriers`)
  return data.data
}

export async function saveAgentShippingCouriers(providerId: number, couriers: string[]) {
  const { data } = await http.put<ApiEnvelope<{ couriers: string[] }>>(`/agent/shipping-providers/${providerId}/couriers`, { couriers })
  return data.data
}

export interface RajaOngkirDestination { id: string; label: string; province_name: string | null; city_name: string | null; district_name: string | null; subdistrict_name: string | null; zip_code: string | null }
export async function searchRajaOngkirDestinations(id: number, search: string, apiKey?: string) {
  const { data } = await http.post<ApiEnvelope<RajaOngkirDestination[]>>(`/agent/shipping-providers/${id}/destinations`, { search, api_key: apiKey || undefined })
  return data.data
}
export async function testRajaOngkirConnection(id: number) {
  const { data } = await http.post<ApiEnvelope<{ connected: boolean; api_version: string; origin_id: string; origin_label: string }>>(`/agent/shipping-providers/${id}/test`)
  return data.data
}
export async function testOpenRouteConnection(id: number, destinationLatitude: number, destinationLongitude: number) {
  const { data } = await http.post<ApiEnvelope<{ connected: boolean; profile: string; distance_km: number; duration_seconds: number }>>(`/agent/shipping-providers/${id}/test`, {
    destination_latitude: destinationLatitude,
    destination_longitude: destinationLongitude,
  })
  return data.data
}
