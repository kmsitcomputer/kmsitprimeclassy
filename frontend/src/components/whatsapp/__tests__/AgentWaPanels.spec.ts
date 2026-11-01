/** Per-Agen events/templates and registered-number test-send panels: the backend owns every rule; these pin what is shown and sent. */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '@/api/errors'
import AgentWaEvents from '@/components/whatsapp/AgentWaEvents.vue'
import AgentWaTesting from '@/components/whatsapp/AgentWaTesting.vue'

const api = vi.hoisted(() => ({
  listAgentWaEvents: vi.fn(),
  updateAgentWaEvent: vi.fn(),
  resetAgentWaTemplate: vi.fn(),
  previewAgentWaTemplate: vi.fn(),
  listAgentWaTestNumbers: vi.fn(),
  addAgentWaTestNumber: vi.fn(),
  removeAgentWaTestNumber: vi.fn(),
  sendAgentWaTest: vi.fn(),
}))
vi.mock('@/api/agentWhatsapp', () => api)

const ev = (over: Record<string, unknown> = {}) => ({
  key: 'order_shipped', label: 'Pesanan dikirim', variables: ['nama_pelanggan', 'nomor_pesanan', 'resi'], enabled: false, enabled_source: 'default',
  template: 'Halo {nama_pelanggan}', template_source: 'default', default_template: 'Halo {nama_pelanggan}', ...over,
})

describe('AgentWaEvents', () => {
  beforeEach(() => {
    Object.values(api).forEach((f) => f.mockReset())
    api.listAgentWaEvents.mockResolvedValue([ev(), ev({ key: 'order_created', label: 'Pesanan berhasil dibuat', template_source: 'agent', template: 'Custom' })])
  })

  it('toggles an event, inserts a variable, previews, and surfaces the backend template error', async () => {
    const w = mount(AgentWaEvents)
    await flushPromises()

    api.updateAgentWaEvent.mockResolvedValue(ev({ enabled: true, enabled_source: 'agent' }))
    await w.get('input[aria-label="Aktifkan Pesanan dikirim"]').setValue(true)
    await flushPromises()
    expect(api.updateAgentWaEvent).toHaveBeenCalledWith('order_shipped', { enabled: true })

    const card = w.get('[data-test="event-order_shipped"]')
    await card.findAll('button').find((b) => b.text() === '{resi}')!.trigger('click')
    expect((card.get('textarea').element as HTMLTextAreaElement).value).toBe('Halo {nama_pelanggan}{resi}')

    api.previewAgentWaTemplate.mockResolvedValue('Halo Budi')
    await card.findAll('button').find((b) => b.text() === 'Preview')!.trigger('click')
    await flushPromises()
    expect(card.get('[aria-label="Pratinjau"]').text()).toBe('Halo Budi')

    api.updateAgentWaEvent.mockRejectedValue(new ApiError('Validasi gagal', 422, { template: ['Template memakai variabel yang tidak diizinkan: {sandi}'] }))
    await card.findAll('button').find((b) => b.text() === 'Simpan Template')!.trigger('click')
    await flushPromises()
    expect(card.get('[role="alert"]').text()).toContain('{sandi}')
  })

  it('only allows resetting a template that the Agen actually customised', async () => {
    const w = mount(AgentWaEvents)
    await flushPromises()
    const isDisabled = (key: string) => w.get(`[data-test="event-${key}"]`).findAll('button').find((b) => b.text() === 'Kembalikan Bawaan')!.attributes('disabled') !== undefined
    expect(isDisabled('order_shipped')).toBe(true)
    expect(isDisabled('order_created')).toBe(false)
  })

  it('shows an error with retry when the settings cannot be loaded (e.g. 403)', async () => {
    api.listAgentWaEvents.mockRejectedValue(new ApiError('Anda tidak berwenang', 403, null))
    const w = mount(AgentWaEvents)
    await flushPromises()
    expect(w.text()).toContain('Anda tidak berwenang')
  })
})

describe('AgentWaTesting', () => {
  beforeEach(() => {
    Object.values(api).forEach((f) => f.mockReset())
    api.listAgentWaTestNumbers.mockResolvedValue({ numbers: [{ id: 1, masked: '62••••001' }], max: 5 })
  })

  it('requires the authorisation tick before a test number can be registered', async () => {
    api.addAgentWaTestNumber.mockResolvedValue({ numbers: [{ id: 1, masked: '62••••001' }, { id: 2, masked: '62••••002' }], max: 5 })
    const w = mount(AgentWaTesting, { props: { deviceConnected: true, gatewayOn: true } })
    await flushPromises()

    await w.get('[data-test="wa-test-new"]').setValue('081300000002')
    expect(w.get('[data-test="wa-test-add"]').attributes('disabled')).toBeDefined()
    await w.get('[data-test="wa-test-attest"]').setValue(true)
    await w.get('form').trigger('submit')
    await flushPromises()
    expect(api.addAgentWaTestNumber).toHaveBeenCalledWith('081300000002')
    expect(w.get('[data-test="wa-test-list"]').text()).toContain('62••••002')
    expect(w.html()).not.toContain('6281300000002')
  })

  it('blocks test-send while the device is not connected or WhatsApp is globally off', async () => {
    const off = mount(AgentWaTesting, { props: { deviceConnected: false, gatewayOn: true } })
    await flushPromises()
    await off.get('[data-test="wa-test-target"]').setValue('081300000001')
    expect(off.get('[data-test="wa-test-send"]').attributes('disabled')).toBeDefined()
    expect(off.find('[data-test="wa-test-device-hint"]').exists()).toBe(true)

    const globalOff = mount(AgentWaTesting, { props: { deviceConnected: true, gatewayOn: false } })
    await flushPromises()
    await globalOff.get('[data-test="wa-test-target"]').setValue('081300000001')
    expect(globalOff.get('[data-test="wa-test-send"]').attributes('disabled')).toBeDefined()
  })

  it('sends, reports the real outcome, and shows the backend refusal for an unregistered number', async () => {
    api.sendAgentWaTest.mockResolvedValueOnce({ id: 5, status: 'sent', recipient: '62••••001', skip_reason: null, error_class: null, sender_suffix: 'abc123' })
    const w = mount(AgentWaTesting, { props: { deviceConnected: true, gatewayOn: true } })
    await flushPromises()
    await w.get('[data-test="wa-test-target"]').setValue('081300000001')
    await w.findAll('form')[1].trigger('submit')
    await flushPromises()
    expect(w.get('[data-test="wa-test-result"]').text()).toContain('sent')
    expect(w.get('[data-test="wa-test-result"]').text()).toContain('…abc123')

    api.sendAgentWaTest.mockRejectedValueOnce(new ApiError('Nomor tidak diizinkan', 422, { number: ['Nomor tidak diizinkan'] }))
    await w.get('[data-test="wa-test-target"]').setValue('081399999999')
    await w.findAll('form')[1].trigger('submit')
    await flushPromises()
    expect(w.get('[data-test="wa-test-error"]').text()).toContain('tidak diizinkan')
  })
})
