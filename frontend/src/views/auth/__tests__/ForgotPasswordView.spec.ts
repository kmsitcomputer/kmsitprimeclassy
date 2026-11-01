/**
 * Password recovery view — enumeration-safe by construction: the request stage ALWAYS advances with the same notice,
 * only throttling/transport errors differ, and the confirm stage reports an invalid/expired code.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'

import { ApiError } from '@/api/client'
import id from '@/i18n/locales/id'
import ForgotPasswordView from '@/views/auth/ForgotPasswordView.vue'

const api = vi.hoisted(() => ({ forgotPassword: vi.fn(), resetPassword: vi.fn() }))

vi.mock('@/api/auth', () => api)
vi.mock('vue-router', () => ({ RouterLink: { template: '<a><slot /></a>' }, useRouter: () => ({ push: vi.fn() }), useRoute: () => ({ query: {} }) }))

const i18n = createI18n({ legacy: false, locale: 'id', missingWarn: false, fallbackWarn: false, messages: { id } })

function mountView() {
  return mount(ForgotPasswordView, {
    global: { plugins: [i18n], stubs: { AuthLayout: { template: '<div><slot /></div>' }, RouterLink: { template: '<a><slot /></a>' } } },
  })
}

async function requestStage(w: ReturnType<typeof mountView>, identifier = 'someone@example.com') {
  await w.find('[data-test="recovery-identifier"]').setValue(identifier)
  await w.find('form').trigger('submit')
  await flushPromises()
}

describe('ForgotPasswordView', () => {
  beforeEach(() => {
    vi.useRealTimers()
    api.forgotPassword.mockReset().mockResolvedValue(undefined)
    api.resetPassword.mockReset().mockResolvedValue(undefined)
  })

  it('moves to the code stage with the generic notice and sends the chosen channel', async () => {
    const w = mountView()
    await w.find('[data-test="recovery-channel-wa"]').setValue(true)
    await requestStage(w, ' 0812345 ')

    expect(api.forgotPassword).toHaveBeenCalledWith('0812345', 'whatsapp')
    expect(w.find('[data-test="recovery-notice"]').text()).toContain('Jika akun cocok')
    expect(w.find('[data-test="recovery-code"]').exists()).toBe(true)
  })

  it('gives the same outcome whether or not the account exists (backend answers identically)', async () => {
    const a = mountView()
    await requestStage(a, 'known@example.com')
    const b = mountView()
    await requestStage(b, 'ghost@example.com')

    expect(a.find('[data-test="recovery-notice"]').text()).toBe(b.find('[data-test="recovery-notice"]').text())
  })

  it('shows a rate-limit notice (not a stage change) on 429', async () => {
    api.forgotPassword.mockRejectedValue(new ApiError('x', 429, null))
    const w = mountView()
    await requestStage(w)

    expect(w.find('[data-test="recovery-error"]').text()).toContain('Terlalu banyak')
    expect(w.find('[data-test="recovery-code"]').exists()).toBe(false)
  })

  it('blocks the reset until the code is 6 digits and the passwords match', async () => {
    const w = mountView()
    await requestStage(w)
    const btn = w.find('[data-test="recovery-reset"]')
    expect(btn.attributes('disabled')).toBeDefined()

    await w.find('[data-test="recovery-code"]').setValue('123456')
    const pw = w.findAll('input[type="password"]')
    await pw[0].setValue('new-password-1')
    await pw[1].setValue('different-1')
    expect(w.find('[data-test="recovery-mismatch"]').exists()).toBe(true)
    expect(w.find('[data-test="recovery-reset"]').attributes('disabled')).toBeDefined()

    await pw[1].setValue('new-password-1')
    expect(w.find('[data-test="recovery-reset"]').attributes('disabled')).toBeUndefined()
  })

  it('resets, then shows the done state; an invalid code shows the backend reason and clears the code', async () => {
    const w = mountView()
    await requestStage(w)
    const fill = async () => {
      await w.find('[data-test="recovery-code"]').setValue('654321')
      const pw = w.findAll('input[type="password"]')
      await pw[0].setValue('new-password-1')
      await pw[1].setValue('new-password-1')
    }

    api.resetPassword.mockRejectedValueOnce(new ApiError('Kode pemulihan tidak valid.', 422, null))
    await fill()
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(w.find('[data-test="recovery-error"]').text()).toContain('tidak valid')
    expect((w.find('[data-test="recovery-code"]').element as HTMLInputElement).value).toBe('')

    await fill()
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(api.resetPassword).toHaveBeenLastCalledWith({
      identifier: 'someone@example.com', code: '654321', password: 'new-password-1', password_confirmation: 'new-password-1',
    })
    expect(w.find('[data-test="recovery-done"]').exists()).toBe(true)
  })

  it('locks resend behind a cooldown after sending', async () => {
    const w = mountView()
    await requestStage(w)
    const resend = w.find('[data-test="recovery-resend"]')
    expect(resend.attributes('disabled')).toBeDefined()
    expect(resend.text()).toContain('60')
  })
})
