/**
 * G17-D3 — CrmAssistedOrderView. The server owns pricing/stock/authority; the UI must collect lines +
 * destination, send ONE idempotency key per logical submission (a failed retry reuses it), and go to the order.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '@/api/errors'
import CrmAssistedOrderView from '@/views/dashboard/CrmAssistedOrderView.vue'

const crm = vi.hoisted(() => ({ getCrmCustomer: vi.fn(), placeAssistedOrder: vi.fn() }))
vi.mock('@/api/crm', () => crm)
const catalog = vi.hoisted(() => ({ listProducts: vi.fn() }))
vi.mock('@/api/catalog', () => catalog)
const checkout = vi.hoisted(() => ({ getCheckoutSteps: vi.fn() }))
vi.mock('@/api/checkout', () => checkout)
const regions = vi.hoisted(() => ({ listProvinces: vi.fn(), listRegencies: vi.fn(), listDistricts: vi.fn(), listVillages: vi.fn() }))
vi.mock('@/api/regions', () => regions)

const push = vi.hoisted(() => vi.fn())
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '31' } }),
  useRouter: () => ({ push }),
  RouterLink: { props: ['to'], template: '<a><slot /></a>' },
}))
vi.mock('@/layouts/DashboardLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }))

const customer = (over = {}) => ({
  id: 31, agent_id: 1, owner_user_id: 2, name: 'Ibu Rina', phone: '0812', email: null, customer_type: null, relation: null,
  address: 'Jl. Mawar 1', prospect_percent: null, note: null, archived_at: null, created_at: '', ...over,
})
const product = { id: 4, name: 'Kue Lapis', has_variations: false, base_price: '100000', variations: [] }

async function fill(w: ReturnType<typeof mount>) {
  await w.find('li button').trigger('click') // add product
  const selects = w.findAll('select')
  await selects[0].setValue('P1')
  await flushPromises()
  await w.findAll('select')[1].setValue('R1')
  await flushPromises()
  await w.findAll('select')[2].setValue('D1')
  await flushPromises()
  await w.findAll('select')[3].setValue('V1')
  await w.findAll('select')[4].setValue('cod')
  const num = w.findAll('input[type="number"]')
  await num[num.length - 2].setValue(-6.9)
  await num[num.length - 1].setValue(107.6)
}

async function mountView() {
  const w = mount(CrmAssistedOrderView)
  await flushPromises()
  return w
}

beforeEach(() => {
  Object.values(crm).forEach((fn) => fn.mockReset())
  push.mockReset()
  crm.getCrmCustomer.mockResolvedValue(customer())
  catalog.listProducts.mockResolvedValue({ products: [product], meta: {} })
  checkout.getCheckoutSteps.mockResolvedValue({
    payment_methods: [{ id: 1, code: 'cod', name: 'COD', type: 'cod' }],
    shipping_methods: [{ code: 'rajaongkir', label: 'Ekspedisi' }, { code: 'pickup', label: 'Pickup' }],
  })
  regions.listProvinces.mockResolvedValue([{ id: 'P1', name: 'Jabar' }])
  regions.listRegencies.mockResolvedValue([{ id: 'R1', name: 'Bandung' }])
  regions.listDistricts.mockResolvedValue([{ id: 'D1', name: 'Coblong' }])
  regions.listVillages.mockResolvedValue([{ id: 'V1', name: 'Dago' }])
})

describe('CrmAssistedOrderView', () => {
  it('prefills the recipient from the CRM customer and keeps submit disabled until the order is complete', async () => {
    const w = await mountView()
    expect(w.text()).toContain('Pesanan untuk Ibu Rina')
    expect((w.find('input[maxlength="150"]').element as HTMLInputElement).value).toBe('Ibu Rina')
    expect(w.findAll('button').find((b) => b.text() === 'Buat Pesanan')!.attributes('disabled')).toBeDefined()
  })

  it('submits the lines + destination, goes to the new order, and sends an idempotency key', async () => {
    crm.placeAssistedOrder.mockResolvedValue({ id: 99, order_no: 'PC-1', total_amount: '1', buyer_origin: 'crm' })
    const w = await mountView()
    await fill(w)
    await w.find('form').trigger('submit')
    await flushPromises()

    const [customerId, payload, key] = crm.placeAssistedOrder.mock.calls[0]
    expect(customerId).toBe(31)
    expect(payload).toMatchObject({
      items: [{ product_id: 4, product_variation_id: null, quantity: 1 }],
      payment_method_code: 'cod', shipping_method: 'pickup', village_id: 'V1', recipient_name: 'Ibu Rina', latitude: -6.9, longitude: 107.6,
    })
    expect(key).toMatch(/^[0-9a-f-]{36}$/)
    expect(push).toHaveBeenCalledWith({ name: 'order-detail', params: { id: 99 } })
  })

  it('reuses the SAME idempotency key when a failed submission is retried', async () => {
    crm.placeAssistedOrder
      .mockRejectedValueOnce(new ApiError('Stok tidak cukup.', 422, null))
      .mockResolvedValueOnce({ id: 99, order_no: 'PC-1', total_amount: '1', buyer_origin: 'crm' })
    const w = await mountView()
    await fill(w)
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(w.text()).toContain('Stok tidak cukup.')
    expect(push).not.toHaveBeenCalled()

    await w.find('form').trigger('submit')
    await flushPromises()
    expect(crm.placeAssistedOrder).toHaveBeenCalledTimes(2)
    expect(crm.placeAssistedOrder.mock.calls[1][2]).toBe(crm.placeAssistedOrder.mock.calls[0][2])
    expect(push).toHaveBeenCalledTimes(1)
  })

  it('offers no Ekspedisi, auto-selects a single supported shipping method, and blocks when only Ekspedisi exists', async () => {
    let w = await mountView()
    expect(w.text()).toContain('Metode pengiriman:')
    expect(w.text()).toContain('Pickup')
    expect(w.text()).not.toContain('Ekspedisi')

    checkout.getCheckoutSteps.mockResolvedValue({
      payment_methods: [{ id: 1, code: 'cod', name: 'COD', type: 'cod' }],
      shipping_methods: [{ code: 'rajaongkir', label: 'Ekspedisi' }],
    })
    w = await mountView()
    expect(w.find('[role="alert"]').text()).toContain('hanya menawarkan Ekspedisi')
    await fill(w)
    expect(w.findAll('button').find((b) => b.text() === 'Buat Pesanan')!.attributes('disabled')).toBeDefined()
  })

  it('blocks an archived customer and shows a load error with retry', async () => {
    crm.getCrmCustomer.mockResolvedValue(customer({ archived_at: '2026-10-01T00:00:00Z' }))
    let w = await mountView()
    expect(w.text()).toContain('Pelanggan ini diarsipkan')
    expect(w.findAll('button').find((b) => b.text() === 'Buat Pesanan')!.attributes('disabled')).toBeDefined()

    crm.getCrmCustomer.mockRejectedValueOnce(new ApiError('Server error', 500, null))
    w = await mountView()
    expect(w.text()).toContain('Server error')
    crm.getCrmCustomer.mockResolvedValue(customer())
    await w.find('button.underline').trigger('click')
    await flushPromises()
    expect(w.text()).toContain('Pesanan untuk Ibu Rina')
  })
})
