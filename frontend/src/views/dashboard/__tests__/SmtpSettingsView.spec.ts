/**
 * SMTP (Super Admin). API mocked; the backend owns every rule. Pinned here: the password is write-only (never shown, never kept after
 * saving), tests need a saved configuration, results are honest, and the page never asks for a test recipient.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '@/api/errors'
import type { SmtpSettings } from '@/api/smtp'
import SmtpSettingsView from '@/views/dashboard/SmtpSettingsView.vue'

const api = vi.hoisted(() => ({ getSmtpSettings: vi.fn(), updateSmtpSettings: vi.fn(), testSmtpConnection: vi.fn(), sendSmtpTestEmail: vi.fn() }))
vi.mock('@/api/smtp', () => api)
vi.mock('@/layouts/DashboardLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const settings = (over: Partial<SmtpSettings> = {}): SmtpSettings => ({
  enabled: false, host: null, port: null, encryption: 'tls', username: null, has_password: false, from_address: null, from_name: null,
  timeout: 10, complete: false, active_source: 'environment', ...over,
})
const saved = (over: Partial<SmtpSettings> = {}) =>
  settings({ enabled: true, host: 'mail.contoh.com', port: 587, username: 'mailer', has_password: true, from_address: 'noreply@contoh.com', from_name: 'Prime', complete: true, active_source: 'dashboard', ...over })

async function mountView() {
  const w = mount(SmtpSettingsView)
  await flushPromises()
  return w
}
const btn = (w: ReturnType<typeof mount>, text: string | RegExp) => w.findAll('button').find((b) => (typeof text === 'string' ? b.text() === text : text.test(b.text())))!

beforeEach(() => {
  Object.values(api).forEach((fn) => fn.mockReset())
  api.getSmtpSettings.mockResolvedValue(settings())
})

describe('SmtpSettingsView', () => {
  it('states which configuration is active and disables tests until one is saved', async () => {
    const w = await mountView()
    expect(w.get('[data-test="active-source"]').text()).toContain('konfigurasi server')
    expect(btn(w, 'Tes koneksi').attributes('disabled')).toBeDefined()
    expect(btn(w, /Kirim email uji/).attributes('disabled')).toBeDefined()
  })

  it('keeps the password write-only: placeholder says it is stored, nothing is prefilled, it is wiped after saving', async () => {
    api.getSmtpSettings.mockResolvedValue(saved())
    api.updateSmtpSettings.mockResolvedValue(saved())
    const w = await mountView()

    const pw = w.get('input[type="password"]')
    expect((pw.element as HTMLInputElement).value).toBe('')
    expect(pw.attributes('placeholder')).toContain('Tersimpan')

    await pw.setValue('new-secret')
    await w.get('form').trigger('submit')
    await flushPromises()
    expect(api.updateSmtpSettings).toHaveBeenCalledWith(expect.objectContaining({ password: 'new-secret', host: 'mail.contoh.com' }))
    expect((w.get('input[type="password"]').element as HTMLInputElement).value).toBe('')
    expect(w.html()).not.toContain('new-secret')
  })

  it('omits the password from the payload when left empty so the stored one is kept', async () => {
    api.getSmtpSettings.mockResolvedValue(saved())
    api.updateSmtpSettings.mockResolvedValue(saved())
    const w = await mountView()
    await w.get('form').trigger('submit')
    await flushPromises()
    expect(api.updateSmtpSettings.mock.calls[0][0]).not.toHaveProperty('password')
  })

  it('shows the server validation error and keeps the form', async () => {
    api.updateSmtpSettings.mockRejectedValue(new ApiError('Host, port, dan alamat pengirim wajib diisi.', 422))
    const w = await mountView()
    await w.get('form').trigger('submit')
    await flushPromises()
    expect(w.get('[data-test="notice"]').text()).toContain('wajib diisi')
  })

  it('reports a failed connection test honestly and a successful one as such', async () => {
    api.getSmtpSettings.mockResolvedValue(saved())
    api.testSmtpConnection.mockResolvedValueOnce({ ok: false, detail: 'Connection refused' }).mockResolvedValueOnce({ ok: true, detail: null })
    const w = await mountView()

    await btn(w, 'Tes koneksi').trigger('click')
    await flushPromises()
    expect(w.get('[data-test="test-result"]').text()).toContain('Gagal: Connection refused')

    await btn(w, 'Tes koneksi').trigger('click')
    await flushPromises()
    expect(w.get('[data-test="test-result"]').text()).toContain('berhasil')
  })

  it('sends the test e-mail without any recipient field and says it goes to the admin themself', async () => {
    api.getSmtpSettings.mockResolvedValue(saved())
    api.sendSmtpTestEmail.mockResolvedValue({ ok: true, detail: null })
    const w = await mountView()

    expect(w.findAll('input[type="email"]').length).toBe(1) // only the SENDER address
    await btn(w, /Kirim email uji/).trigger('click')
    await flushPromises()
    expect(api.sendSmtpTestEmail).toHaveBeenCalledWith()
    expect(w.get('[data-test="test-result"]').text()).toContain('alamat Anda sendiri')
  })

  it('warns when encryption is switched off', async () => {
    const w = await mountView()
    await w.findAll('select')[1].setValue('none')
    expect(w.find('[role="note"]').text()).toContain('Tanpa enkripsi')
  })
})
