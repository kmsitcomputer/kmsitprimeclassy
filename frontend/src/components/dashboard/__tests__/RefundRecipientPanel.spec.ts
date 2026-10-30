/**
 * G17-D4 — RefundRecipientPanel records recipient + method + authorization evidence for CRM-origin refunds.
 * It must never be possible to submit without the authorization evidence, and bank details only for bank methods.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '@/api/errors'
import RefundRecipientPanel from '@/components/dashboard/RefundRecipientPanel.vue'

const api = vi.hoisted(() => ({ listRefundRecipients: vi.fn(), recordRefundRecipient: vi.fn() }))
vi.mock('@/api/crm', () => api)

const row = (over = {}) => ({
  id: 1, recipient_name: 'Ibu Rina', recipient_phone: null, recipient_relation: 'Pemilik', method: 'bank_transfer',
  destination: { account_number_tail: '7890' }, consent_event_id: null, has_evidence_file: true,
  authorization_note: 'Pelanggan minta transfer ke rekening sendiri.', supersedes_id: null, occurred_at: '2026-10-30T01:00:00Z', ...over,
})

async function mountPanel() {
  const w = mount(RefundRecipientPanel, { props: { orderId: 5 } })
  await flushPromises()
  return w
}
const btn = (w: Awaited<ReturnType<typeof mountPanel>>, text: string) => w.findAll('button').find((b) => b.text().includes(text))!

beforeEach(() => {
  Object.values(api).forEach((fn) => fn.mockReset())
})

describe('RefundRecipientPanel', () => {
  it('warns that refunds are blocked until evidence exists', async () => {
    api.listRefundRecipients.mockResolvedValue([])
    const w = await mountPanel()
    expect(w.text()).toContain('Refund untuk order ini akan ditolak sampai bukti dicatat')
    expect(w.text()).toContain('Catat Penerima')
  })

  it('shows the masked destination the API returned and offers a correction', async () => {
    api.listRefundRecipients.mockResolvedValue([row()])
    const w = await mountPanel()
    expect(w.text()).toContain('Ibu Rina')
    expect(w.text()).toContain('****7890')
    expect(w.text()).toContain('Koreksi Penerima')
  })

  it('cannot submit without the authorization document, then sends bank details for a bank method', async () => {
    api.listRefundRecipients.mockResolvedValue([])
    api.recordRefundRecipient.mockResolvedValue(row())
    const w = await mountPanel()
    await btn(w, 'Catat Penerima').trigger('click')

    const inputs = w.findAll('input')
    await inputs[0].setValue('Ibu Rina') // nama
    await w.find('textarea').setValue('Pelanggan menyetujui transfer ke rekeningnya.')
    // bank fields: provider, number, holder are the three inputs after the method select
    const bank = w.findAll('input').filter((i) => ['maxlength', 'inputmode'].some((a) => i.attributes(a)) && !i.attributes('type'))
    const number = w.find('input[inputmode="numeric"]')
    await number.setValue('1234567890')
    const holder = bank.find((i) => i.attributes('maxlength') === '150')!
    await holder.setValue('Rina')

    const submit = btn(w, 'Simpan')
    expect(submit.attributes('disabled')).toBeDefined() // no evidence file yet

    const file = new File(['x'], 'otorisasi.png', { type: 'image/png' })
    const input = w.find('input[type="file"]')
    Object.defineProperty(input.element, 'files', { value: [file] })
    await input.trigger('change')
    expect(btn(w, 'Simpan').attributes('disabled')).toBeUndefined()

    await w.find('form').trigger('submit')
    await flushPromises()
    expect(api.recordRefundRecipient).toHaveBeenCalledTimes(1)
    const sent = api.recordRefundRecipient.mock.calls[0]
    expect(sent[0]).toBe(5)
    expect(sent[1]).toMatchObject({
      recipient_name: 'Ibu Rina', method: 'bank_transfer', evidence: file,
      destination: { account_number: '1234567890', account_holder: 'Rina' },
    })
  })

  it('omits bank details for cash and surfaces a server rejection', async () => {
    api.listRefundRecipients.mockResolvedValue([])
    api.recordRefundRecipient.mockRejectedValue(new ApiError('Validasi gagal', 422, { authorization: ['Wajib.'] }))
    const w = await mountPanel()
    await btn(w, 'Catat Penerima').trigger('click')
    await w.find('select').setValue('cash')
    expect(w.find('input[inputmode="numeric"]').exists()).toBe(false)

    await w.findAll('input')[0].setValue('Ibu Rina')
    await w.find('textarea').setValue('Diambil tunai.')
    const input = w.find('input[type="file"]')
    Object.defineProperty(input.element, 'files', { value: [new File(['x'], 'a.png', { type: 'image/png' })] })
    await input.trigger('change')
    await w.find('form').trigger('submit')
    await flushPromises()

    expect(api.recordRefundRecipient.mock.calls[0][1].destination).toBeUndefined()
    expect(w.text()).toContain('Wajib.')
  })
})
