/**
 * WhatsApp Saya (Agen). API mocked; the backend owns every rule. These tests pin what the page shows and sends: the QR comes from the server
 * as a data URI, pairing is polled, replacement needs an explicit confirmation, and no secret/slot id is ever rendered.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '@/api/errors'
import type { AgentWaStatus } from '@/api/agentWhatsapp'
import AgentWhatsAppView from '@/views/dashboard/AgentWhatsAppView.vue'

const api = vi.hoisted(() => ({
  getAgentWhatsApp: vi.fn(),
  createAgentWhatsAppDevice: vi.fn(),
  startAgentQrPairing: vi.fn(),
  startAgentCodePairing: vi.fn(),
  reconnectAgentWhatsApp: vi.fn(),
  logoutAgentWhatsApp: vi.fn(),
  replaceAgentWhatsAppDevice: vi.fn(),
  listAgentWhatsAppMessages: vi.fn(),
}))
vi.mock('@/api/agentWhatsapp', () => api)
vi.mock('@/layouts/DashboardLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const dev = (over: Record<string, unknown> = {}) => ({ state: 'not_paired', phone_masked: null, paired_at: null, last_checked_at: null, replaced_count: 0, ...over })
const status = (over: Partial<AgentWaStatus> = {}): AgentWaStatus => ({
  global_enabled: true, gateway_ready: true, table_ready: true, capabilities: { qr: true, pair_code: true }, device: dev() as AgentWaStatus['device'], ...over,
})
const btn = (w: ReturnType<typeof mount>, text: string | RegExp) => w.findAll('button').find((b) => (typeof text === 'string' ? b.text() === text : text.test(b.text())))!

async function mountView() {
  const w = mount(AgentWhatsAppView)
  await flushPromises()
  return w
}

beforeEach(() => {
  Object.values(api).forEach((fn) => fn.mockReset())
  api.getAgentWhatsApp.mockResolvedValue(status())
  api.listAgentWhatsAppMessages.mockResolvedValue([])
})
afterEach(() => vi.useRealTimers())

describe('AgentWhatsAppView', () => {
  it('offers to create the device when the Agent has none', async () => {
    api.getAgentWhatsApp.mockResolvedValue(status({ device: null }))
    api.createAgentWhatsAppDevice.mockResolvedValue(status())
    const w = await mountView()

    expect(w.get('[data-test="state"]').text()).toBe('Belum ada perangkat')
    await btn(w, 'Buat perangkat').trigger('click')
    await flushPromises()
    expect(api.createAgentWhatsAppDevice).toHaveBeenCalledTimes(1)
    expect(w.get('[data-test="state"]').text()).toBe('Belum terhubung')
  })

  it('explains that the platform gateway is not ready and blocks pairing', async () => {
    api.getAgentWhatsApp.mockResolvedValue(status({ gateway_ready: false }))
    const w = await mountView()
    expect(w.find('[data-test="gateway-unavailable"]').exists()).toBe(true)
    expect(btn(w, 'Tampilkan QR').attributes('disabled')).toBeDefined()
  })

  it('says WhatsApp is OFF globally while keeping the session', async () => {
    api.getAgentWhatsApp.mockResolvedValue(status({ global_enabled: false, device: dev({ state: 'connected', phone_masked: '62••••077' }) as AgentWaStatus['device'] }))
    const w = await mountView()
    expect(w.get('[data-test="global-off"]').text()).toContain('tetap tersimpan')
    expect(w.get('[data-test="masked-phone"]').text()).toContain('62••••077')
  })

  it('shows the server-built QR image and never a gateway URL', async () => {
    api.startAgentQrPairing.mockResolvedValue({ image: 'data:image/png;base64,AAAA', expires_in: 30, device: dev({ state: 'pairing' }) })
    const w = await mountView()

    await btn(w, 'Tampilkan QR').trigger('click')
    await flushPromises()
    const img = w.get('[data-test="qr"] img')
    expect(img.attributes('src')).toBe('data:image/png;base64,AAAA')
    expect(w.html()).not.toContain('localhost:3000')
    expect(w.get('[data-test="state"]').text()).toBe('Menunggu pemindaian')
  })

  it('polls while pairing and turns green when the phone has linked, hiding the QR', async () => {
    vi.useFakeTimers()
    api.startAgentQrPairing.mockResolvedValue({ image: 'data:image/png;base64,AAAA', expires_in: 30, device: dev({ state: 'pairing' }) })
    const w = await mountView()
    await btn(w, 'Tampilkan QR').trigger('click')
    await flushPromises()

    api.getAgentWhatsApp.mockResolvedValue(status({ device: dev({ state: 'connected', phone_masked: '62••••077' }) as AgentWaStatus['device'] }))
    await vi.advanceTimersByTimeAsync(4100)
    await flushPromises()

    expect(w.find('[data-test="qr"]').exists()).toBe(false)
    expect(w.get('[data-test="state"]').text()).toBe('Terhubung')
    expect(w.get('[data-test="notice"]').text()).toContain('berhasil terhubung')
    w.unmount()
  })

  it('requests a pairing code for the typed number and shows only the code', async () => {
    api.startAgentCodePairing.mockResolvedValue({ code: 'ABCD-1234', device: dev({ state: 'pairing' }) })
    const w = await mountView()

    await w.get('#agent-wa-phone').setValue('081399900077')
    await w.get('form').trigger('submit')
    await flushPromises()
    expect(api.startAgentCodePairing).toHaveBeenCalledWith('081399900077')
    expect(w.get('[data-test="pair-code"]').text()).toContain('ABCD-1234')
  })

  it('surfaces a backend error instead of pretending it worked', async () => {
    api.startAgentQrPairing.mockRejectedValue(new ApiError('Gateway WhatsApp tidak dapat dijangkau saat ini.', 503))
    const w = await mountView()
    await btn(w, 'Tampilkan QR').trigger('click')
    await flushPromises()
    expect(w.get('[data-test="notice"]').text()).toContain('tidak dapat dijangkau')
    expect(w.find('[data-test="qr"]').exists()).toBe(false)
  })

  it('needs an explicit confirmation before replacing the device', async () => {
    api.getAgentWhatsApp.mockResolvedValue(status({ device: dev({ state: 'connected', phone_masked: '62••••077' }) as AgentWaStatus['device'] }))
    api.replaceAgentWhatsAppDevice.mockResolvedValue(dev({ state: 'not_paired', replaced_count: 1 }))
    const w = await mountView()

    await btn(w, /Ganti perangkat/).trigger('click')
    expect(api.replaceAgentWhatsAppDevice).not.toHaveBeenCalled()
    expect(w.find('[role="alertdialog"]').exists()).toBe(true)

    await btn(w, 'Ya, ganti perangkat').trigger('click')
    await flushPromises()
    expect(api.replaceAgentWhatsAppDevice).toHaveBeenCalledTimes(1)
    expect(w.get('[data-test="state"]').text()).toBe('Belum terhubung')
  })

  it('lists this branch history with masked recipients and a readable reason', async () => {
    api.listAgentWhatsAppMessages.mockResolvedValue([
      { id: 1, purpose: 'transaction', event: 'order_shipped', status: 'skipped', recipient: '62••••001', attempts: 0, skip_reason: 'agent_device_not_ready', error_class: null, created_at: '2026-10-29T01:00:00Z', sent_at: null },
    ])
    const w = await mountView()
    expect(w.text()).toContain('62••••001')
    expect(w.text()).toContain('Perangkat belum terhubung')
  })
})
