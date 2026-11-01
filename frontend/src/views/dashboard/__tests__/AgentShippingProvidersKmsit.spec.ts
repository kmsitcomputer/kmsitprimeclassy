/** KMSIT Routing Server card: endpoint is editable, the stored token is never shown, save never sends a blank token. */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'

import id from '@/i18n/locales/id'

import AgentShippingProvidersView from '@/views/dashboard/AgentShippingProvidersView.vue'

const api = vi.hoisted(() => ({
  getAgentShippingProviders: vi.fn(),
  toggleAgentShippingProvider: vi.fn(),
  saveAgentShippingProviderConfig: vi.fn(),
  searchRajaOngkirDestinations: vi.fn(),
  testRajaOngkirConnection: vi.fn(),
  testOpenRouteConnection: vi.fn(),
  getAgentShippingCouriers: vi.fn().mockResolvedValue([]),
  saveAgentShippingCouriers: vi.fn(),
  getKurirTokoTariff: vi.fn(),
  saveKurirTokoTariff: vi.fn(),
}))
vi.mock('@/api/agentSettings', () => api)

const row = (over: Record<string, unknown>) => ({ id: 1, code: 'x', name: 'X', globally_active: true, is_active: true, configured: false, ...over })
const i18n = createI18n({ legacy: false, locale: 'id', missingWarn: false, fallbackWarn: false, messages: { id } })
const mountView = () => mount(AgentShippingProvidersView, { global: { plugins: [i18n], stubs: { DashboardLayout: { template: '<div><slot /></div>' } } } })

describe('AgentShippingProvidersView — KMSIT', () => {
  beforeEach(() => {
    Object.values(api).forEach((f) => f.mockReset?.())
    api.getAgentShippingCouriers.mockResolvedValue([])
    api.getAgentShippingProviders.mockResolvedValue([
      row({ id: 1, code: 'rajaongkir' }),
      row({ id: 2, code: 'openroute' }),
      row({ id: 3, code: 'kmsit', globally_active: false, is_active: true, configured: true, base_url: 'https://route.example.id', has_token: true, profile: 'car' }),
    ])
    api.saveAgentShippingProviderConfig.mockResolvedValue({})
    api.getKurirTokoTariff.mockResolvedValue({ price_per_km: 2000, minimum_distance_km: 3, minimum_charge: 5000, free_shipping_enabled: false, free_shipping_min_amount: null, is_active: true })
    api.saveKurirTokoTariff.mockResolvedValue({})
  })

  it('shows the saved endpoint, hides the token and flags a globally disabled provider', async () => {
    const w = mountView()
    await flushPromises()
    expect((w.find('[data-test="kmsit-url"]').element as HTMLInputElement).value).toBe('https://route.example.id')
    expect(w.find('[data-test="kmsit-global-off"]').exists()).toBe(true)
    expect(w.find('[data-test="kmsit-token-hint"]').text()).toContain('API key tersimpan')
    expect(w.html()).not.toContain('kmsit-secret')
  })

  it('saves without a token when the field is left blank (keeps the stored one)', async () => {
    const w = mountView()
    await flushPromises()
    await w.find('[data-test="kmsit-card"] form').trigger('submit')
    await flushPromises()
    expect(api.saveAgentShippingProviderConfig).toHaveBeenCalledWith(3, { config: { base_url: 'https://route.example.id', profile: 'car' } })
  })

  it('explains a failed Test Connection with the safe reason code (404 = wrong path), never a URL or token', async () => {
    const { ApiError } = await import('@/api/errors')
    api.testOpenRouteConnection.mockRejectedValue(new ApiError('x', 422, { reason: ['not_found'], http_status: ['404'] }))
    const w = mountView()
    await flushPromises()
    const inputs = w.get('[data-test="kmsit-card"]').findAll('input[type="number"]')
    await inputs[0].setValue('-6.94')
    await inputs[1].setValue('107.67')
    await w.get('[data-test="kmsit-card"]').findAll('button').find((b) => b.text() === 'Test Connection')!.trigger('click')
    await flushPromises()
    expect(w.get('[data-test="kmsit-fail"]').text()).toContain('404')
    expect(w.get('[data-test="kmsit-fail"]').text()).not.toContain('route.example.id')
  })

  it('offers only the Gateway profiles (car, motorcycle) and shows a gateway-specific reason for a rejected key', async () => {
    const { ApiError } = await import('@/api/errors')
    api.testOpenRouteConnection.mockRejectedValue(new ApiError('x', 422, { reason: ['invalid_credential'], http_status: ['401'] }))
    const w = mountView()
    await flushPromises()
    const card = w.get('[data-test="kmsit-card"]')
    expect(card.findAll('select option').map((o) => o.attributes('value'))).toEqual(['car', 'motorcycle'])
    const inputs = card.findAll('input[type="number"]')
    await inputs[0].setValue('-6.94')
    await inputs[1].setValue('107.67')
    await card.findAll('button').find((b) => b.text() === 'Test Connection')!.trigger('click')
    await flushPromises()
    expect(w.get('[data-test="kmsit-fail"]').text()).toContain('API key ditolak')
  })

  it('shows the Kurir Toko tariff in its own card, editable and savable while OpenRoute is globally off and unconfigured', async () => {
    api.getAgentShippingProviders.mockResolvedValue([
      row({ id: 1, code: 'rajaongkir' }),
      row({ id: 2, code: 'openroute', globally_active: false, is_active: false, configured: false }), // ORS off everywhere, no key
      row({ id: 3, code: 'kmsit', globally_active: true, is_active: true, configured: false }),
    ])
    const w = mountView()
    await flushPromises()
    expect((w.get('[data-test="tariff-price"]').element as HTMLInputElement).value).toBe('2000')
    expect((w.get('[data-test="tariff-min-distance"]').element as HTMLInputElement).value).toBe('3')
    expect((w.get('[data-test="tariff-min-charge"]').element as HTMLInputElement).value).toBe('5000')

    await w.get('[data-test="tariff-price"]').setValue('2500')
    await w.get('[data-test="tariff-card"] form').trigger('submit')
    await flushPromises()
    expect(api.saveKurirTokoTariff).toHaveBeenCalledWith({ price_per_km: 2500, minimum_distance_km: 3, minimum_charge: 5000 })
    expect(api.saveAgentShippingProviderConfig).not.toHaveBeenCalled() // saving the tariff never touches provider credentials
    expect(w.get('[data-test="tariff-notice"]').text()).toContain('disimpan')
  })

  it('keeps the tariff out of the OpenRoute and KMSIT cards and never sends it with their saves', async () => {
    const w = mountView()
    await flushPromises()
    expect(w.get('[data-test="tariff-card"]').text()).toContain('Biaya Minimum')
    // The only tariff inputs on the page live in the tariff card.
    expect(w.findAll('[data-test="tariff-price"]')).toHaveLength(1)

    await w.get('[data-test="kmsit-card"] form').trigger('submit')
    await flushPromises()
    const kmsitPayload = api.saveAgentShippingProviderConfig.mock.calls[0][1]
    expect(kmsitPayload).not.toHaveProperty('price_per_km')
    expect(kmsitPayload).not.toHaveProperty('minimum_distance_km')
  })

  it('is absent when the backend does not list the provider', async () => {
    api.getAgentShippingProviders.mockResolvedValue([row({ id: 1, code: 'rajaongkir' })])
    const w = mountView()
    await flushPromises()
    expect(w.find('[data-test="kmsit-card"]').exists()).toBe(false)
  })
})
