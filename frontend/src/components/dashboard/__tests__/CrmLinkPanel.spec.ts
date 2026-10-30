/**
 * OD-03/OD-04 — CrmLinkPanel drives pick → emailed OTP → verify → link, and audited unlink.
 * API mocked; the backend owns every rule. Pins the call order and that nothing links without the OTP step.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '@/api/errors'
import CrmLinkPanel from '@/components/dashboard/CrmLinkPanel.vue'

const crm = vi.hoisted(() => ({
  listLinkCandidates: vi.fn(),
  requestLinkOtp: vi.fn(),
  verifyConsentOtp: vi.fn(),
  linkCrmCustomer: vi.fn(),
  unlinkCrmCustomer: vi.fn(),
}))
vi.mock('@/api/crm', () => crm)

const customer = (over = {}) => ({
  id: 7, agent_id: 1, owner_user_id: 2, name: 'Ibu Rina', phone: null, email: null, customer_type: null,
  relation: null, address: null, prospect_percent: null, note: null, archived_at: null, created_at: '',
  linked_konsumen_id: null, ...over,
})

const btn = (w: ReturnType<typeof mount>, text: string) => w.findAll('button').find((b) => b.text().includes(text))!

beforeEach(() => {
  Object.values(crm).forEach((fn) => fn.mockReset())
  crm.listLinkCandidates.mockResolvedValue([
    { id: 55, name: 'Budi', phone_tail: '…111', email_masked: 'b***@example.test', email_verified: true },
    { id: 56, name: 'Tanpa Email', phone_tail: '…222', email_masked: null, email_verified: false },
  ])
})

describe('CrmLinkPanel', () => {
  it('links only after the emailed OTP is verified, then reports the updated customer', async () => {
    crm.requestLinkOtp.mockResolvedValue({ challenge_id: 9, expires_at: '', max_attempts: 5 })
    crm.verifyConsentOtp.mockResolvedValue(undefined)
    crm.linkCrmCustomer.mockResolvedValue(customer({ linked_konsumen_id: 55 }))
    const w = mount(CrmLinkPanel, { props: { customer: customer(), canLink: true, canUnlink: true } })

    await btn(w, 'Tautkan ke Akun').trigger('click')
    await flushPromises()
    expect(crm.listLinkCandidates).toHaveBeenCalledTimes(1)
    // Contact is masked and an account without a verified email cannot be chosen (no OTP channel).
    expect(w.text()).toContain('b***@example.test')
    expect(w.text()).not.toContain('Budi · budi@')
    expect(w.findAll('option').find((o) => o.text().includes('Tanpa Email'))!.attributes('disabled')).toBeDefined()

    await w.find('select').setValue(55)
    await btn(w, 'Kirim Kode').trigger('click')
    await flushPromises()
    expect(crm.requestLinkOtp).toHaveBeenCalledWith(7, 55)
    expect(crm.linkCrmCustomer).not.toHaveBeenCalled()

    await w.find('input[inputmode="numeric"]').setValue('123456')
    await w.find('form').trigger('submit')
    await flushPromises()

    expect(crm.verifyConsentOtp).toHaveBeenCalledWith(9, '123456')
    expect(crm.linkCrmCustomer).toHaveBeenCalledWith(7, 55, 9)
    expect(crm.verifyConsentOtp.mock.invocationCallOrder[0]).toBeLessThan(crm.linkCrmCustomer.mock.invocationCallOrder[0])
    expect(w.emitted('changed')?.[0][0]).toMatchObject({ linked_konsumen_id: 55 })
  })

  it('stays on the OTP step and never links when the code is wrong', async () => {
    crm.requestLinkOtp.mockResolvedValue({ challenge_id: 9, expires_at: '', max_attempts: 5 })
    crm.verifyConsentOtp.mockRejectedValue(new ApiError('Kode tidak valid.', 422, null))
    const w = mount(CrmLinkPanel, { props: { customer: customer(), canLink: true, canUnlink: true } })
    await btn(w, 'Tautkan ke Akun').trigger('click')
    await flushPromises()
    await w.find('select').setValue(55)
    await btn(w, 'Kirim Kode').trigger('click')
    await flushPromises()
    await w.find('input[inputmode="numeric"]').setValue('000000')
    await w.find('form').trigger('submit')
    await flushPromises()

    expect(w.text()).toContain('Kode tidak valid.')
    expect(crm.linkCrmCustomer).not.toHaveBeenCalled()
    expect(w.emitted('changed')).toBeUndefined()
  })

  it('shows no link action to a viewer who cannot link, and an empty candidate list gracefully', async () => {
    const readOnly = mount(CrmLinkPanel, { props: { customer: customer(), canLink: false, canUnlink: false } })
    expect(readOnly.text()).toContain('Belum tertaut')
    expect(readOnly.findAll('button')).toHaveLength(0)

    crm.listLinkCandidates.mockResolvedValue([])
    const w = mount(CrmLinkPanel, { props: { customer: customer(), canLink: true, canUnlink: false } })
    await btn(w, 'Tautkan ke Akun').trigger('click')
    await flushPromises()
    expect(w.text()).toContain('Belum ada konsumen referral Anda.')
  })

  it('unlink requires a reason and sends it for the audit trail', async () => {
    crm.unlinkCrmCustomer.mockResolvedValue(customer())
    const w = mount(CrmLinkPanel, { props: { customer: customer({ linked_konsumen_id: 55 }), canLink: true, canUnlink: true } })
    expect(w.text()).toContain('Tertaut ke akun konsumen #55')

    await btn(w, 'Lepas Tautan').trigger('click')
    const submit = btn(w, 'Lepas')
    expect(submit.attributes('disabled')).toBeDefined()

    await w.find('textarea').setValue('Salah orang')
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(crm.unlinkCrmCustomer).toHaveBeenCalledWith(7, 'Salah orang')
    expect(w.emitted('changed')).toHaveLength(1)
  })
})
