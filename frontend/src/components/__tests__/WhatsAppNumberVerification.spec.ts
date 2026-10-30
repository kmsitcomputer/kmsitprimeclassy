/**
 * Self-service WhatsApp number verification (account page). API mocked; the backend owns every rule. Pins what the
 * widget shows and sends: no code is ever displayed, the code is cleared after a wrong attempt, verified state is honest.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '@/api/client'
import { i18n } from '@/i18n'
import type { WaNumberState } from '@/api/whatsapp'
import WhatsAppNumberVerification from '@/components/WhatsAppNumberVerification.vue'

const api = vi.hoisted(() => ({
  getWhatsAppNumberState: vi.fn(),
  requestWhatsAppNumberVerification: vi.fn(),
  confirmWhatsAppNumberVerification: vi.fn(),
}))
vi.mock('@/api/whatsapp', () => api)

const state = (over: Partial<WaNumberState> = {}): WaNumberState => ({
  has_phone: true, phone_masked: '62••••890', verified: false, channel_available: true, pending: false, ...over,
})

async function mountIt() {
  const w = mount(WhatsAppNumberVerification, { global: { plugins: [i18n] } })
  await flushPromises()
  return w
}

beforeEach(() => {
  Object.values(api).forEach((fn) => fn.mockReset())
  api.getWhatsAppNumberState.mockResolvedValue(state())
})

describe('WhatsAppNumberVerification', () => {
  it('shows the masked number as unverified and never the full number', async () => {
    const w = await mountIt()
    expect(w.find('[data-test="wa-unverified"]').exists()).toBe(true)
    expect(w.text()).toContain('62••••890')
  })

  it('explains the channel is unavailable instead of offering a button that cannot work', async () => {
    api.getWhatsAppNumberState.mockResolvedValue(state({ channel_available: false }))
    const w = await mountIt()
    expect(w.find('[data-test="wa-unavailable"]').exists()).toBe(true)
    expect(w.find('form').exists()).toBe(false)
  })

  it('is hidden entirely when the endpoint cannot be reached', async () => {
    api.getWhatsAppNumberState.mockRejectedValue(new Error('network'))
    const w = await mountIt()
    expect(w.find('[data-test="wa-verify"]').exists()).toBe(false)
  })

  it('requests a code for the number on file, then verifies with the 6-digit code', async () => {
    api.requestWhatsAppNumberVerification.mockResolvedValue({ challenge_id: 5, expires_at: 'x', max_attempts: 5, target: '62••••890' })
    api.confirmWhatsAppNumberVerification.mockResolvedValue(state({ verified: true }))
    const w = await mountIt()

    await w.find('form').trigger('submit')
    await flushPromises()
    expect(api.requestWhatsAppNumberVerification).toHaveBeenCalledWith(undefined)

    const input = w.find('#wa-code')
    await input.setValue('123456')
    await w.find('form').trigger('submit')
    await flushPromises()

    expect(api.confirmWhatsAppNumberVerification).toHaveBeenCalledWith(5, '123456')
    expect(w.find('[data-test="wa-verified"]').exists()).toBe(true)
    expect(w.find('#wa-code').exists()).toBe(false)
  })

  it('sends a proposed new number and keeps the confirm button disabled until six digits are typed', async () => {
    api.requestWhatsAppNumberVerification.mockResolvedValue({ challenge_id: 9, expires_at: 'x', max_attempts: 5, target: '62••••111' })
    const w = await mountIt()

    await w.find('#wa-phone').setValue('081234567111')
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(api.requestWhatsAppNumberVerification).toHaveBeenCalledWith('081234567111')

    await w.find('#wa-code').setValue('12')
    expect(w.find('form button[type="submit"]').attributes('disabled')).toBeDefined()
    await w.find('#wa-code').setValue('123456')
    expect(w.find('form button[type="submit"]').attributes('disabled')).toBeUndefined()
  })

  it('shows the error and clears the code after a wrong attempt', async () => {
    api.requestWhatsAppNumberVerification.mockResolvedValue({ challenge_id: 5, expires_at: 'x', max_attempts: 5, target: '62••••890' })
    api.confirmWhatsAppNumberVerification.mockRejectedValue(new ApiError('Kode salah', 422))
    const w = await mountIt()

    await w.find('form').trigger('submit')
    await flushPromises()
    await w.find('#wa-code').setValue('000000')
    await w.find('form').trigger('submit')
    await flushPromises()

    expect(w.find('[data-test="wa-error"]').text()).toContain('Kode salah')
    expect((w.find('#wa-code').element as HTMLInputElement).value).toBe('')
    expect(w.find('[data-test="wa-verified"]').exists()).toBe(false)
  })

  it('treats an already-proven number as verified without showing a code field', async () => {
    api.requestWhatsAppNumberVerification.mockResolvedValue(state({ verified: true }))
    const w = await mountIt()
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(w.find('[data-test="wa-verified"]').exists()).toBe(true)
    expect(w.find('#wa-code').exists()).toBe(false)
  })
})
