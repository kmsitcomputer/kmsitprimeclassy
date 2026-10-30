/**
 * IMP-006 slice 1 — CrmCustomersView: write controls follow the viewer's role, and empty/error
 * states render. The backend remains the authority (OD-01); this pins the UI hints only.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '@/api/errors'
import CrmCustomersView from '@/views/dashboard/CrmCustomersView.vue'

const api = vi.hoisted(() => ({ listCrmCustomers: vi.fn(), createCrmCustomer: vi.fn() }))
vi.mock('@/api/crm', () => ({ ...api, PROSPECT_PERCENTS: [0, 25, 50, 75, 90, 100] }))

const auth = vi.hoisted(() => ({ user: { id: 1, role: 'sales' } as { id: number; role: string } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => auth }))

// The layout pulls the whole app shell; the view only needs its slot.
vi.mock('@/layouts/DashboardLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const row = (over = {}) => ({
  id: 1, agent_id: 9, owner_user_id: 1, name: 'Ibu Rina', phone: '0812', email: null,
  customer_type: 'reseller', relation: null, address: null, prospect_percent: 50, note: null,
  archived_at: null, created_at: '', ...over,
})
const page = (items: unknown[]) => ({ items, currentPage: 1, lastPage: 1, total: items.length })

const stubs = { RouterLink: { props: ['to'], template: '<a><slot /></a>' } }

async function mountView() {
  const wrapper = mount(CrmCustomersView, { global: { stubs } })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  Object.values(api).forEach((fn) => fn.mockReset())
  auth.user = { id: 1, role: 'sales' }
})

describe('CrmCustomersView', () => {
  it('lets the owning sales add customers and lists rows without an owner column', async () => {
    api.listCrmCustomers.mockResolvedValue(page([row()]))
    const wrapper = await mountView()
    expect(wrapper.text()).toContain('Ibu Rina')
    expect(wrapper.text()).toContain('50%')
    expect(wrapper.text()).toContain('Tambah Pelanggan')
    expect(wrapper.text()).not.toContain('Pemilik')
  })

  it('is read-only for agen: no create button, owner column shown', async () => {
    auth.user = { id: 2, role: 'agen' }
    api.listCrmCustomers.mockResolvedValue(page([row()]))
    const wrapper = await mountView()
    expect(wrapper.text()).not.toContain('Tambah Pelanggan')
    expect(wrapper.text()).toContain('Pemilik')
  })

  it('FU and D are filters over the existing follow-up/deal data (stage param), not stored statuses', async () => {
    api.listCrmCustomers.mockResolvedValue(page([row()]))
    const wrapper = await mountView()
    expect(api.listCrmCustomers.mock.calls[0][0]).toMatchObject({ stage: undefined })

    await wrapper.findAll('button').find((b) => b.text() === 'FU (Follow Up)')!.trigger('click')
    await flushPromises()
    expect(api.listCrmCustomers.mock.calls.at(-1)![0]).toMatchObject({ stage: 'FU' })

    await wrapper.findAll('button').find((b) => b.text() === 'D (Deal)')!.trigger('click')
    await flushPromises()
    expect(api.listCrmCustomers.mock.calls.at(-1)![0]).toMatchObject({ stage: 'D' })

    await wrapper.findAll('button').find((b) => b.text() === 'Semua')!.trigger('click')
    await flushPromises()
    expect(api.listCrmCustomers.mock.calls.at(-1)![0]).toMatchObject({ stage: undefined })
  })

  it('renders the empty state', async () => {
    api.listCrmCustomers.mockResolvedValue(page([]))
    const wrapper = await mountView()
    expect(wrapper.text()).toContain('Belum ada pelanggan CRM.')
  })

  it('renders a load error with retry', async () => {
    api.listCrmCustomers.mockRejectedValueOnce(new ApiError('Server error', 500, null))
    const wrapper = await mountView()
    expect(wrapper.text()).toContain('Server error')
    api.listCrmCustomers.mockResolvedValue(page([row()]))
    await wrapper.find('button.underline').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Ibu Rina')
  })

  it('shows a server validation error in the create form and keeps it open', async () => {
    api.listCrmCustomers.mockResolvedValue(page([]))
    api.createCrmCustomer.mockRejectedValue(new ApiError('Validasi gagal', 422, { phone: ['Nomor sudah dipakai.'] }))
    const wrapper = await mountView()
    await wrapper.findAll('button').find((b) => b.text() === 'Tambah Pelanggan')!.trigger('click')
    await wrapper.find('input[type="text"]').setValue('Pak Budi')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(wrapper.text()).toContain('Nomor sudah dipakai.')
    expect(wrapper.find('form').exists()).toBe(true)
  })
})
