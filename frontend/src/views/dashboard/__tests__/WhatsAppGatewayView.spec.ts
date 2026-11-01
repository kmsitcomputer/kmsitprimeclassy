/**
 * WhatsApp Gateway console (Super Admin). API mocked; the backend owns every rule. These tests pin what the page shows
 * and sends: write-only password, honest connection/message states, duplicate-risk confirmation, template feedback.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '@/api/errors'
import type { WaMessage, WaSettings } from '@/api/whatsapp'
import WhatsAppGatewayView from '@/views/dashboard/WhatsAppGatewayView.vue'

const api = vi.hoisted(() => ({
  getWhatsAppSettings: vi.fn(),
  updateWhatsAppSettings: vi.fn(),
  testWhatsAppConnection: vi.fn(),
  sendWhatsAppTestMessage: vi.fn(),
  listWhatsAppMessages: vi.fn(),
  retryWhatsAppMessage: vi.fn(),
}))
vi.mock('@/api/whatsapp', () => api)
vi.mock('@/layouts/DashboardLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const settings = (over: Partial<WaSettings> = {}): WaSettings => ({
  provider: 'gowa', enabled: true, base_url: 'http://127.0.0.1:3001', username: 'primeclassy', has_password: true,
  device_id: 'dev-1', timeout: 10, max_attempts: 3, backoff_seconds: 60, limits: { per_recipient_per_hour: 6, global_per_minute: 20 },
  test_numbers: [{ masked: '62••••890' }], test_numbers_count: 1,
  otp: { enabled: false, ttl_minutes: 5, max_attempts: 5, cooldown_seconds: 60, limit: 3, limit_window_minutes: 10 },
  events: [
    { key: 'order_shipped', label: 'Pesanan dikirim', enabled: false, template: 'Halo {nama_pelanggan} {nomor_pesanan}', is_default_template: true, variables: ['nama_pelanggan', 'nomor_pesanan', 'kurir', 'resi'], required: ['nomor_pesanan'] },
    { key: 'order_created', label: 'Pesanan berhasil dibuat', enabled: true, template: 'Custom {nomor_pesanan}', is_default_template: false, variables: ['nomor_pesanan'], required: ['nomor_pesanan'] },
  ],
  configured: true, table_ready: true, ...over,
})

const message = (over: Partial<WaMessage> = {}): WaMessage => ({
  id: 7, purpose: 'transaction', event: 'order_shipped', status: 'failed', order_id: 3, recipient: '62••••890', body: 'x',
  attempts: 1, max_attempts: 3, provider_message_id: null, error_class: 'rejected', error_message: 'HTTP 400', skip_reason: null,
  queued_at: null, sent_at: null, created_at: '2026-10-29T05:00:00Z', can_retry: true, needs_duplicate_risk_confirmation: false, ...over,
})

async function mountView() {
  const w = mount(WhatsAppGatewayView)
  await flushPromises()
  return w
}
const btn = (w: Awaited<ReturnType<typeof mountView>>, text: string | RegExp) =>
  w.findAll('button').find((b) => (typeof text === 'string' ? b.text() === text : text.test(b.text())))!
const tab = (w: Awaited<ReturnType<typeof mountView>>, name: string) => w.findAll('[role="tab"]').find((t) => t.text() === name)!

beforeEach(() => {
  Object.values(api).forEach((fn) => fn.mockReset())
  api.getWhatsAppSettings.mockResolvedValue(settings())
  api.updateWhatsAppSettings.mockImplementation(async () => settings())
})

describe('WhatsAppGatewayView', () => {
  it('renders the connection form with a write-only password and never shows a secret', async () => {
    const w = await mountView()
    expect(w.text()).toContain('Pengaturan › Integrasi › WhatsApp Gateway')
    expect((w.find('input[type="url"]').element as HTMLInputElement).value).toBe('http://127.0.0.1:3001')
    const pw = w.find('input[type="password"]')
    expect((pw.element as HTMLInputElement).value).toBe('')
    expect(pw.attributes('placeholder')).toContain('Tersimpan')
    expect(pw.attributes('autocomplete')).toBe('new-password')
    expect(w.text()).toContain('62••••890') // masked test number only
  })

  it('keeps the stored password when the field is blank, sends it when typed, and wipes the field after saving', async () => {
    const w = await mountView()
    await w.find('input[type="url"]').setValue('http://gowa.example.test')
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(api.updateWhatsAppSettings.mock.calls[0][0]).not.toHaveProperty('password')
    expect(api.updateWhatsAppSettings.mock.calls[0][0]).toMatchObject({ base_url: 'http://gowa.example.test', username: 'primeclassy' })

    const pw = w.find('input[type="password"]')
    await pw.setValue('new-secret-1')
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(api.updateWhatsAppSettings.mock.calls[1][0]).toMatchObject({ password: 'new-secret-1' })
    expect((w.find('input[type="password"]').element as HTMLInputElement).value).toBe('')
    expect(w.html()).not.toContain('new-secret-1')
  })

  it('sends test numbers only when entered, and an empty list when explicitly cleared', async () => {
    const w = await mountView()
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(api.updateWhatsAppSettings.mock.calls[0][0]).not.toHaveProperty('test_numbers')

    await w.find('textarea').setValue('0812-1111-2222\n0813 3333 4444')
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(api.updateWhatsAppSettings.mock.calls[1][0].test_numbers).toEqual(['0812-1111-2222', '0813 3333 4444'])

    await w.find('input[type="checkbox"].h-4:not(.accent-brand-600)').setValue(true)
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(api.updateWhatsAppSettings.mock.calls[2][0].test_numbers).toEqual([])
  })

  it('surfaces a server validation error and keeps the form', async () => {
    api.updateWhatsAppSettings.mockRejectedValue(new ApiError('Validasi gagal', 422, { base_url: ['Masukkan alamat http(s) yang valid tanpa kredensial.'] }))
    const w = await mountView()
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(w.find('[role="alert"]').text()).toContain('tanpa kredensial')
    expect(w.find('input[type="url"]').exists()).toBe(true)
  })

  it('shows the connection status for each state', async () => {
    const w = await mountView()
    api.testWhatsAppConnection.mockResolvedValue({ state: 'connected', reachable: true, connected: true, logged_in: true, devices: 1, device_id: 'dev-1', detail: null })
    await btn(w, 'Test Connection').trigger('click')
    await flushPromises()
    expect(w.text()).toContain('Terhubung')
    expect(w.text()).toContain('dev-1')

    api.testWhatsAppConnection.mockResolvedValue({ state: 'unreachable', reachable: false, connected: false, logged_in: false, devices: 0, device_id: null, detail: 'cURL error 7' })
    await btn(w, 'Test Connection').trigger('click')
    await flushPromises()
    expect(w.text()).toContain('Server tidak terjangkau')
    expect(w.text()).toContain('cURL error 7')
  })

  it('sends a test message and reports the honest outcome, warning on an uncertain one', async () => {
    const w = await mountView()
    const form = w.findAll('form')[1]
    await form.find('input[type="tel"]').setValue('0812 1111 2222')
    api.sendWhatsAppTestMessage.mockResolvedValue(message({ purpose: 'test', status: 'unknown', error_message: 'timeout', can_retry: true }))
    await form.trigger('submit')
    await flushPromises()
    expect(api.sendWhatsAppTestMessage).toHaveBeenCalledWith('0812 1111 2222', '')
    expect(w.text()).toContain('Tidak pasti')
    expect(w.text()).toContain('periksa ponsel penerima')

    api.sendWhatsAppTestMessage.mockRejectedValue(new ApiError('Nomor ini bukan nomor uji yang diizinkan.', 422, null))
    await form.trigger('submit')
    await flushPromises()
    expect(w.text()).toContain('bukan nomor uji yang diizinkan')
  })

  it('blocks test messages while the delivery-log table does not exist yet', async () => {
    api.getWhatsAppSettings.mockResolvedValue(settings({ table_ready: false }))
    const w = await mountView()
    expect(w.text()).toContain('menunggu persetujuan Owner')
    await w.findAll('form')[1].find('input[type="tel"]').setValue('0812')
    expect(btn(w, 'Kirim Pesan Uji').attributes('disabled')).toBeDefined()
  })

  it('OTP tab states the Owner rule and saves the policy', async () => {
    const w = await mountView()
    await tab(w, 'OTP').trigger('click')
    expect(w.text()).toContain('OTP email tetap tersedia')
    expect(w.text()).toContain('akun konsumen yang sudah')
    expect(w.text()).toContain('tidak')
    await w.find('[aria-label="OTP"] input[type="number"]').setValue(7)
    await w.find('[aria-label="OTP"] form').trigger('submit')
    await flushPromises()
    expect(api.updateWhatsAppSettings.mock.calls[0][0].otp).toMatchObject({ ttl_minutes: 7, max_attempts: 5, enabled: false })
  })

  it('no longer offers per-Agen templates, event switches or a Notifikasi tab (delegated to Agen / Admin Agen)', async () => {
    const w = await mountView()
    expect(w.findAll('[role="tab"]').map((t) => t.text())).toEqual(['Koneksi', 'OTP', 'Riwayat'])
    expect(w.find('textarea[aria-label^="Template"]').exists()).toBe(false)
    expect(w.find('input[aria-label^="Aktifkan Pesanan"]').exists()).toBe(false)
    expect(w.find('[data-test="wa-delegated-note"]').exists()).toBe(true)
    // The global ON/OFF and central connection stay with the Super Admin.
    expect(w.find('[aria-label="Koneksi"] input[type="checkbox"]').exists()).toBe(true)
  })

  it('loads the history lazily, filters it, and retries a definite failure without any risk prompt', async () => {
    api.listWhatsAppMessages.mockResolvedValue({ migration_pending: false, data: [message()], total: 1, last_page: 1, current_page: 1 })
    api.retryWhatsAppMessage.mockResolvedValue(message({ status: 'queued' }))
    const w = await mountView()
    expect(api.listWhatsAppMessages).not.toHaveBeenCalled()

    await tab(w, 'Riwayat').trigger('click')
    await flushPromises()
    expect(w.text()).toContain('Gagal')
    expect(w.text()).toContain('62••••890')

    await w.find('[aria-label="Riwayat"] select').setValue('failed')
    await btn(w, 'Terapkan').trigger('click')
    await flushPromises()
    expect(api.listWhatsAppMessages).toHaveBeenLastCalledWith({ page: 1, status: 'failed', purpose: undefined })

    await btn(w, 'Coba ulang').trigger('click')
    expect(w.find('[role="dialog"]').text()).not.toContain('pesan ganda')
    await btn(w, 'Coba Ulang').trigger('click')
    await flushPromises()
    expect(api.retryWhatsAppMessage).toHaveBeenCalledWith(7, false)
  })

  it('an UNKNOWN message needs the duplicate-risk confirmation before it can be retried', async () => {
    api.listWhatsAppMessages.mockResolvedValue({ migration_pending: false, data: [message({ status: 'unknown', needs_duplicate_risk_confirmation: true })], total: 1, last_page: 1, current_page: 1 })
    api.retryWhatsAppMessage.mockResolvedValue(message({ status: 'queued' }))
    const w = await mountView()
    await tab(w, 'Riwayat').trigger('click')
    await flushPromises()

    await btn(w, 'Coba ulang').trigger('click')
    expect(w.find('[role="dialog"]').text()).toContain('pesan ganda')
    const confirm = btn(w, 'Coba Ulang')
    expect(confirm.attributes('disabled')).toBeDefined()

    await w.find('[role="dialog"] input[type="checkbox"]').setValue(true)
    await confirm.trigger('click')
    await flushPromises()
    expect(api.retryWhatsAppMessage).toHaveBeenCalledWith(7, true)
  })

  it('shows the migration-pending state in the history instead of a fake empty list', async () => {
    api.listWhatsAppMessages.mockResolvedValue({ migration_pending: true, data: [], total: 0, last_page: 1, current_page: 1 })
    const w = await mountView()
    await tab(w, 'Riwayat').trigger('click')
    await flushPromises()
    expect(w.text()).toContain('migrasi database menunggu persetujuan Owner')
  })

  it('shows a load error with retry', async () => {
    api.getWhatsAppSettings.mockRejectedValueOnce(new ApiError('Server error', 500, null))
    const w = await mountView()
    expect(w.text()).toContain('Server error')
    await w.find('button.underline').trigger('click')
    await flushPromises()
    expect(w.text()).toContain('Koneksi GOWA')
  })
})
