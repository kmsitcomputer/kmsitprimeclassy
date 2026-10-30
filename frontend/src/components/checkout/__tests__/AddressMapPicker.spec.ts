/** AddressMapPicker: load failure must be visible and retryable (never a silent blank box). */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'

import AddressMapPicker from '@/components/checkout/AddressMapPicker.vue'
import en from '@/i18n/locales/en'

const loader = vi.hoisted(() => ({ loadGoogleMaps: vi.fn(), onGoogleMapsAuthFailure: vi.fn(() => () => undefined), getGoogleMapsMapId: vi.fn(() => 'DEMO_MAP_ID') }))
vi.mock('@/utils/googleMaps', () => loader)

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en } })

describe('AddressMapPicker', () => {
  beforeEach(() => loader.loadGoogleMaps.mockReset())

  it('shows the load error with a Retry button and re-attempts the load', async () => {
    loader.loadGoogleMaps.mockRejectedValueOnce(new Error('boom'))
    const w = mount(AddressMapPicker, { global: { plugins: [i18n] } })
    await flushPromises()
    expect(w.find('[role="alert"]').text()).toContain(en.checkout.address.mapLoadError)

    loader.loadGoogleMaps.mockRejectedValueOnce(new Error('boom again'))
    await w.find('[role="alert"] button').trigger('click')
    await flushPromises()
    expect(loader.loadGoogleMaps).toHaveBeenCalledTimes(2)
    expect(w.find('[role="alert"]').exists()).toBe(true)
  })
})
