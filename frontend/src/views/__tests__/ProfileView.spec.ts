/**
 * Akun Saya — read-only "Nama Korsal" for Sales and Sales-Kurir-Sub.
 *
 * The name comes from the backend's own-record `korsal_name` (UserResource,
 * canonical korsal_id relation); the view only decides visibility by role and
 * never sends Korsal data back with the profile update.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive } from 'vue'
import { createI18n } from 'vue-i18n'

import type { AuthUser } from '@/api/types'
import id from '@/i18n/locales/id'
import ProfileView from '@/views/ProfileView.vue'

const api = vi.hoisted(() => ({
  updateProfile: vi.fn(),
  listOrders: vi.fn(),
}))

const auth = vi.hoisted(() => ({
  state: null as unknown as { user: AuthUser | null; can: (p: string) => boolean; refresh: () => Promise<void>; logout: () => Promise<void> },
}))

vi.mock('@/stores/auth', () => ({ useAuthStore: () => auth.state }))
vi.mock('@/stores/ui', () => ({ useUiStore: () => ({}) }))
vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ push: vi.fn() }),
  RouterLink: { template: '<a><slot /></a>' },
}))
vi.mock('@/api/auth', () => ({
  googleAuthUrl: vi.fn(() => '#'),
  updateProfile: api.updateProfile,
  updatePassword: vi.fn(),
  updateReferralCode: vi.fn(),
  regenerateReferralCode: vi.fn(),
  deleteReferralCode: vi.fn(),
}))
vi.mock('@/api/orders', () => ({ listOrders: api.listOrders }))

const i18n = createI18n({ legacy: false, locale: 'id', missingWarn: false, fallbackWarn: false, messages: { id } })

function user(role: string, extra: Partial<AuthUser> = {}): AuthUser {
  return {
    id: 7, name: 'Sari', email: 'sari@example.test', phone: '0811', avatar_url: null, role,
    referral_code: null, agent_id: 1, korsal_id: null, sales_id: null, status: 'active',
    created_at: '2026-10-01T00:00:00Z', ...extra,
  }
}

function mountAs(u: AuthUser) {
  auth.state = reactive({ user: u, can: () => false, refresh: vi.fn(async () => {}), logout: vi.fn(async () => {}) })
  return mount(ProfileView, {
    global: {
      plugins: [i18n],
      stubs: {
        ShopLayout: { template: '<div><slot /></div>' },
        ImageUploader: true,
        WhatsAppNumberVerification: true,
      },
    },
  })
}

describe('ProfileView — Nama Korsal', () => {
  beforeEach(() => {
    api.updateProfile.mockReset().mockResolvedValue({})
    api.listOrders.mockReset().mockResolvedValue({ orders: [], meta: { total: 0 } })
  })

  it('shows the assigned Korsal for a Sales', () => {
    const w = mountAs(user('sales', { korsal_id: 3, korsal_name: 'Korsal Bandung' }))
    const field = w.find('[data-testid="profile-korsal"]')
    expect(field.exists()).toBe(true)
    expect(field.text()).toContain('Nama Korsal')
    expect(field.text()).toContain('Korsal Bandung')
  })

  it('shows the assigned Korsal for a Sales-Kurir-Sub', () => {
    const w = mountAs(user('sales-kurir-sub', { korsal_id: 3, korsal_name: 'Korsal Cimahi' }))
    expect(w.find('[data-testid="profile-korsal"]').text()).toContain('Korsal Cimahi')
  })

  it('shows "Belum ditugaskan" when no Korsal is assigned', () => {
    expect(mountAs(user('sales', { korsal_name: null })).find('[data-testid="profile-korsal"]').text()).toContain('Belum ditugaskan')
    expect(mountAs(user('sales-kurir-sub')).find('[data-testid="profile-korsal"]').text()).toContain('Belum ditugaskan')
  })

  it.each(['konsumen', 'korsal', 'agen', 'admin', 'keuangan', 'kurir', 'super_admin'])('never shows the field for %s', (role) => {
    const w = mountAs(user(role, { korsal_id: 3, korsal_name: 'Korsal Bandung' }))
    expect(w.find('[data-testid="profile-korsal"]').exists()).toBe(false)
    expect(w.text()).not.toContain('Nama Korsal')
  })

  it('is read-only and never sent with the profile update', async () => {
    const w = mountAs(user('sales', { korsal_id: 3, korsal_name: 'Korsal Bandung' }))
    expect(w.find('[data-testid="profile-korsal"] input').exists()).toBe(false)

    await w.find('form').trigger('submit')
    await flushPromises()

    expect(api.updateProfile).toHaveBeenCalledTimes(1)
    const payload = api.updateProfile.mock.calls[0][0]
    expect(payload).toEqual({ name: 'Sari', phone: '0811', email: 'sari@example.test' })
    expect(payload).not.toHaveProperty('korsal_id')
    expect(payload).not.toHaveProperty('korsal_name')
  })
})
