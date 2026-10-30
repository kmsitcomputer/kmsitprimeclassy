/**
 * REM-005 / WS-9 — ConsentPanel behaviour (jsdom + @vue/test-utils, API mocked; no backend, no network).
 * The backend owns every rule; these tests pin what the UI shows and which calls it makes, in order.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '@/api/errors'
import type { ConsentContext, ConsentEvent } from '@/api/crm'
import ConsentPanel from '@/components/dashboard/ConsentPanel.vue'

const api = vi.hoisted(() => ({
  getConsentContext: vi.fn(),
  listConsentEvents: vi.fn(),
  issueConsentOtp: vi.fn(),
  verifyConsentOtp: vi.fn(),
  captureConsent: vi.fn(),
  captureCrmConsent: vi.fn(),
  issueCrmConsentOtp: vi.fn(),
  initiateAssistedReturn: vi.fn(),
  consentAction: vi.fn(),
}))
vi.mock('@/api/crm', () => api)

const auth = vi.hoisted(() => ({ user: { id: 1, role: 'sales' } as { id: number; role: string } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => auth }))

const ctx = (over: Partial<ConsentContext> = {}): ConsentContext => ({
  can_capture: true,
  purposes: ['assisted_return'],
  subject_user_id: 77,
  subject_email_verified: true,
  items: [{ id: 5, name: 'Kue Lapis', variation: null, max_quantity: 3 }],
  ...over,
})

const event = (over: Partial<ConsentEvent> = {}): ConsentEvent => ({
  id: 1, root_event_id: null, event_type: 'consent_captured', purpose: 'assisted_return',
  scope: [{ order_item_id: 5, quantity: 2 }], has_evidence: true, evidence_sha256: 'a'.repeat(64),
  note: 'ok', actor_id: 1, occurred_at: '2026-10-29T04:00:00Z', ...over,
})

async function mountPanel() {
  const wrapper = mount(ConsentPanel, { props: { orderId: 9 } })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  Object.values(api).forEach((fn) => fn.mockReset())
  auth.user = { id: 1, role: 'sales' }
  api.getConsentContext.mockResolvedValue(ctx())
  api.listConsentEvents.mockResolvedValue([])
})

describe('ConsentPanel', () => {
  it('renders nothing when the viewer cannot capture and no consent exists', async () => {
    api.getConsentContext.mockResolvedValue({ can_capture: false, purposes: ['assisted_return'] })
    const wrapper = await mountPanel()
    expect(wrapper.find('section').exists()).toBe(false)
  })

  it('shows a load error with a retry instead of a blank panel', async () => {
    api.getConsentContext.mockRejectedValueOnce(new ApiError('boom', 500, null))
    const wrapper = await mountPanel()
    expect(wrapper.text()).toContain('boom')
    api.getConsentContext.mockResolvedValue(ctx())
    await wrapper.find('button.underline').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Catat Persetujuan')
  })

  it('warns and blocks OTP when the konsumen has no verified email', async () => {
    api.getConsentContext.mockResolvedValue(ctx({ subject_email_verified: false }))
    const wrapper = await mountPanel()
    expect(wrapper.text()).toContain('belum memiliki email terverifikasi')
    // No verified channel => the capture wizard is not offered at all.
    expect(wrapper.findAll('button').find((b) => b.text() === 'Catat Persetujuan')).toBeUndefined()
    expect(api.issueConsentOtp).not.toHaveBeenCalled()
  })

  it('runs scope → OTP → verify → evidence → capture in order with one stable consent key', async () => {
    api.issueConsentOtp.mockResolvedValue({ challenge_id: 42, expires_at: '', max_attempts: 5 })
    api.verifyConsentOtp.mockResolvedValue(undefined)
    api.captureConsent.mockResolvedValue(event())
    const wrapper = await mountPanel()

    await wrapper.findAll('button').find((b) => b.text() === 'Catat Persetujuan')!.trigger('click')
    await wrapper.find('input[type="number"]').setValue(2)
    await wrapper.findAll('button').find((b) => b.text().includes('Kirim Kode'))!.trigger('click')
    await flushPromises()
    expect(api.issueConsentOtp).toHaveBeenCalledWith(77, 'assisted_return')

    await wrapper.find('input[inputmode="numeric"]').setValue('123456')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(api.verifyConsentOtp).toHaveBeenCalledWith(42, '123456')

    const file = new File(['x'], 'bukti.png', { type: 'image/png' })
    const input = wrapper.find('input[type="file"]')
    Object.defineProperty(input.element, 'files', { value: [file] })
    await input.trigger('change')
    await wrapper.find('textarea').setValue('Pelanggan setuju via telepon')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.captureConsent).toHaveBeenCalledTimes(1)
    const sent = api.captureConsent.mock.calls[0][0]
    expect(sent).toMatchObject({
      subjectUserId: 77, orderId: 9, purpose: 'assisted_return', challengeId: 42,
      scope: [{ order_item_id: 5, quantity: 2 }], evidence: file,
    })
    expect(sent.consentKey).toMatch(/^[0-9a-f-]{36}$/)
  })

  it('surfaces a wrong-OTP error and stays on the OTP step', async () => {
    api.issueConsentOtp.mockResolvedValue({ challenge_id: 42, expires_at: '', max_attempts: 5 })
    api.verifyConsentOtp.mockRejectedValue(new ApiError('Kode tidak valid.', 422, null))
    const wrapper = await mountPanel()
    await wrapper.findAll('button').find((b) => b.text() === 'Catat Persetujuan')!.trigger('click')
    await wrapper.find('input[type="number"]').setValue(1)
    await wrapper.findAll('button').find((b) => b.text().includes('Kirim Kode'))!.trigger('click')
    await flushPromises()
    await wrapper.find('input[inputmode="numeric"]').setValue('000000')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(wrapper.text()).toContain('Kode tidak valid.')
    expect(wrapper.find('input[type="file"]').exists()).toBe(false)
    expect(api.captureConsent).not.toHaveBeenCalled()
  })

  it('CRM mode runs the SAME OTP flow on the linked account channel and sends the verified challenge with the evidence', async () => {
    api.getConsentContext.mockResolvedValue(ctx({ mode: 'crm', crm_customer_id: 31, channel_available: true, subject_user_id: undefined, subject_email_verified: undefined }))
    api.issueCrmConsentOtp.mockResolvedValue({ challenge_id: 77, expires_at: '', max_attempts: 5 })
    api.verifyConsentOtp.mockResolvedValue(undefined)
    api.captureCrmConsent.mockResolvedValue(event({ crm_customer_id: 31 }))
    const wrapper = await mountPanel()
    expect(wrapper.text()).not.toContain('membutuhkan OTP ke kanal terverifikasi')

    await wrapper.findAll('button').find((b) => b.text() === 'Catat Persetujuan')!.trigger('click')
    await wrapper.find('input[type="number"]').setValue(2)
    await wrapper.findAll('button').find((b) => b.text().includes('Kirim Kode'))!.trigger('click')
    await flushPromises()
    expect(api.issueCrmConsentOtp).toHaveBeenCalledWith(31, 'assisted_return')
    expect(api.issueConsentOtp).not.toHaveBeenCalled()

    // Evidence step is unreachable before the OTP is verified.
    expect(wrapper.find('input[type="file"]').exists()).toBe(false)
    await wrapper.find('input[inputmode="numeric"]').setValue('123456')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(api.verifyConsentOtp).toHaveBeenCalledWith(77, '123456')

    const file = new File(['x'], 'bukti.png', { type: 'image/png' })
    const input = wrapper.find('input[type="file"]')
    Object.defineProperty(input.element, 'files', { value: [file] })
    await input.trigger('change')
    await wrapper.find('textarea').setValue('Pelanggan setuju di toko')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.captureConsent).not.toHaveBeenCalled()
    expect(api.captureCrmConsent).toHaveBeenCalledTimes(1)
    expect(api.captureCrmConsent.mock.calls[0][0]).toBe(31)
    expect(api.captureCrmConsent.mock.calls[0][1]).toMatchObject({
      challengeId: 77, orderId: 9, evidence: file, scope: [{ order_item_id: 5, quantity: 2 }],
    })
  })

  it('CRM mode with no verified channel is blocked: explanation shown, no way to send an OTP or submit evidence only', async () => {
    api.getConsentContext.mockResolvedValue(ctx({ mode: 'crm', crm_customer_id: 31, channel_available: false, subject_user_id: undefined, subject_email_verified: undefined }))
    const wrapper = await mountPanel()
    expect(wrapper.find('[role="alert"]').text()).toContain('membutuhkan OTP ke kanal terverifikasi')
    expect(wrapper.find('[role="alert"]').text()).toContain('Foto dan catatan saja tidak cukup')

    // The wizard is not even reachable: no "Catat Persetujuan" button without a verified channel.
    expect(wrapper.findAll('button').find((b) => b.text() === 'Catat Persetujuan')).toBeUndefined()
    expect(wrapper.find('input[type="number"]').exists()).toBe(false)

    expect(api.issueCrmConsentOtp).not.toHaveBeenCalled()
    expect(api.captureCrmConsent).not.toHaveBeenCalled()
    expect(wrapper.find('input[type="file"]').exists()).toBe(false)
  })

  it('surfaces the server 409 when the channel disappears between page load and OTP request', async () => {
    api.getConsentContext.mockResolvedValue(ctx({ mode: 'crm', crm_customer_id: 31, channel_available: true }))
    api.issueCrmConsentOtp.mockRejectedValue(new ApiError('Persetujuan membutuhkan OTP di kanal terverifikasi.', 409, null))
    const wrapper = await mountPanel()
    await wrapper.findAll('button').find((b) => b.text() === 'Catat Persetujuan')!.trigger('click')
    await wrapper.find('input[type="number"]').setValue(1)
    await wrapper.findAll('button').find((b) => b.text().includes('Kirim Kode'))!.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('membutuhkan OTP di kanal terverifikasi')
    expect(wrapper.find('input[type="file"]').exists()).toBe(false)
  })

  it('offers "Ajukan Return" only on a valid CRM consent and submits inside the consented items', async () => {
    api.getConsentContext.mockResolvedValue(ctx({ mode: 'crm', crm_customer_id: 31 }))
    api.listConsentEvents.mockResolvedValue([event({ crm_customer_id: 31 })])
    api.initiateAssistedReturn.mockResolvedValue({ id: 88, status: 'requested' })
    const wrapper = await mountPanel()

    await wrapper.findAll('button').find((b) => b.text() === 'Ajukan Return')!.trigger('click')
    const qty = wrapper.find('input[aria-label^="Jumlah return"]')
    expect(qty.attributes('max')).toBe('2') // never above the consent scope
    await qty.setValue(1)
    const forms = wrapper.findAll('form')
    await forms[0].find('textarea').setValue('Barang rusak')
    const file = new File(['x'], 'rusak.png', { type: 'image/png' })
    const input = forms[0].find('input[type="file"]')
    Object.defineProperty(input.element, 'files', { value: [file] })
    await input.trigger('change')
    await forms[0].trigger('submit')
    await flushPromises()

    expect(api.initiateAssistedReturn).toHaveBeenCalledWith(9, {
      consentEventId: 1, reason: 'Barang rusak', items: [{ order_item_id: 5, quantity: 1 }], evidence: file,
    })
    expect(wrapper.text()).toContain('Permintaan return #88 diajukan')
  })

  it('does not offer a return on a withdrawn consent or to a viewer who cannot capture', async () => {
    api.getConsentContext.mockResolvedValue(ctx({ mode: 'crm', crm_customer_id: 31 }))
    api.listConsentEvents.mockResolvedValue([event(), event({ id: 2, root_event_id: 1, event_type: 'consent_withdrawn' })])
    let wrapper = await mountPanel()
    expect(wrapper.text()).not.toContain('Ajukan Return')

    api.getConsentContext.mockResolvedValue({ mode: 'crm', can_capture: false, purposes: ['assisted_return'] })
    api.listConsentEvents.mockResolvedValue([event()])
    wrapper = await mountPanel()
    expect(wrapper.text()).not.toContain('Ajukan Return')
  })

  it('shows history and offers reinstate only to reviewers on a disputed chain', async () => {
    api.getConsentContext.mockResolvedValue({ can_capture: false, purposes: ['assisted_return'] })
    api.listConsentEvents.mockResolvedValue([
      event(),
      event({ id: 2, root_event_id: 1, event_type: 'consent_disputed', note: 'bukan saya' }),
    ])

    let wrapper = await mountPanel()
    expect(wrapper.text()).toContain('Persetujuan disengketakan')
    expect(wrapper.text()).not.toContain('Pulihkan (tinjauan)')

    auth.user = { id: 2, role: 'admin' }
    wrapper = await mountPanel()
    const reinstate = wrapper.findAll('button').find((b) => b.text().includes('Pulihkan'))!
    await reinstate.trigger('click')
    await wrapper.find('textarea').setValue('Sudah dikonfirmasi')
    api.consentAction.mockResolvedValue(event({ id: 3, root_event_id: 1, event_type: 'consent_reinstated' }))
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(api.consentAction).toHaveBeenCalledWith(1, 'reinstate', 'Sudah dikonfirmasi')
  })
})
