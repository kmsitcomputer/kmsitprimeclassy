/** Email verification card (Profile): unverified → send → code → verified; stale/invalid code surfaces an error. */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'

import { ApiError } from '@/api/client'
import EmailVerification from '@/components/EmailVerification.vue'
import id from '@/i18n/locales/id'

const api = vi.hoisted(() => ({
  getEmailVerificationState: vi.fn(),
  requestEmailVerification: vi.fn(),
  confirmEmailVerification: vi.fn(),
}))
vi.mock('@/api/auth', () => api)

const i18n = createI18n({ legacy: false, locale: 'id', missingWarn: false, fallbackWarn: false, messages: { id } })
const unverified = { email_masked: 's••@example.test', verified: false, pending: false }

async function mountCard() {
  const w = mount(EmailVerification, { global: { plugins: [i18n] } })
  await flushPromises()
  return w
}

describe('EmailVerification', () => {
  beforeEach(() => {
    api.getEmailVerificationState.mockReset().mockResolvedValue(unverified)
    api.requestEmailVerification.mockReset().mockResolvedValue({ challenge_id: 9, expires_at: 'x', max_attempts: 5 })
    api.confirmEmailVerification.mockReset().mockResolvedValue({ ...unverified, verified: true })
  })

  it('shows the unverified status and a send button', async () => {
    const w = await mountCard()
    expect(w.find('[data-test="email-unverified"]').exists()).toBe(true)
    expect(w.find('[data-test="email-send"]').exists()).toBe(true)
  })

  it('is hidden when the account has no email or the endpoint is unreachable', async () => {
    api.getEmailVerificationState.mockResolvedValue({ email_masked: null, verified: false, pending: false })
    expect((await mountCard()).find('[data-test="email-verify"]').exists()).toBe(false)

    api.getEmailVerificationState.mockRejectedValue(new Error('offline'))
    expect((await mountCard()).find('[data-test="email-verify"]').exists()).toBe(false)
  })

  it('sends a code, accepts it and flips to verified', async () => {
    const w = await mountCard()
    await w.find('[data-test="email-send"]').trigger('click')
    await flushPromises()
    expect(w.find('[data-test="email-notice"]').text()).toContain('s••@example.test')
    expect(w.find('[data-test="email-resend"]').attributes('disabled')).toBeDefined()

    await w.find('[data-test="email-code"]').setValue('123456')
    await w.find('form').trigger('submit')
    await flushPromises()

    expect(api.confirmEmailVerification).toHaveBeenCalledWith(9, '123456')
    expect(w.find('[data-test="email-verified"]').exists()).toBe(true)
    expect(w.find('[data-test="email-send"]').exists()).toBe(false)
  })

  it('shows the backend reason for a stale or wrong code and keeps the form usable', async () => {
    api.confirmEmailVerification.mockRejectedValue(new ApiError('Kode tidak valid.', 422, null))
    const w = await mountCard()
    await w.find('[data-test="email-send"]').trigger('click')
    await flushPromises()
    await w.find('[data-test="email-code"]').setValue('000000')
    await w.find('form').trigger('submit')
    await flushPromises()

    expect(w.find('[data-test="email-error"]').text()).toContain('Kode tidak valid')
    expect(w.find('[data-test="email-verified"]').exists()).toBe(false)
    expect((w.find('[data-test="email-code"]').element as HTMLInputElement).value).toBe('')
  })

  it('requires exactly six digits before confirming', async () => {
    const w = await mountCard()
    await w.find('[data-test="email-send"]').trigger('click')
    await flushPromises()
    await w.find('[data-test="email-code"]').setValue('123')
    expect(w.find('[data-test="email-confirm"]').attributes('disabled')).toBeDefined()
  })
})
