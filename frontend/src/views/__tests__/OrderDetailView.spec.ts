/**
 * FIX-COURIER-ASSIGNMENT-422 — focused regression for OrderDetailView.vue.
 *
 * Covers the R-1 lifecycle/stale-response remediation and the R-2 super_admin
 * branch-scope remediation at the component level (jsdom + @vue/test-utils,
 * all API modules mocked — no backend, no network).
 *
 * Scenario map (task §3–§7):
 *  1. A → B navigation follows branch B and clears A immediately.
 *  2. A late order-A response never overwrites B.
 *  3. A late courier-list response from the previous order is discarded.
 *  4. getOrder() failure renders a safe error (ApiError + generic).
 *  5. super_admin scopes by the ORDER agent_id, never its own.
 *  6. super_admin with empty agent_id: no request + guidance + disabled dropdown.
 *  7. agen/admin send no extra branch param (backend scoping).
 *  8. Courier-list failure keeps the detail with an empty dropdown.
 *  9. self_sub shipments never offer courier assignment.
 *  10. Same-order double refresh keeps the loaded list; stale in-flight
 *      courier responses are discarded.
 */
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '@/api/errors'
import type { Order, OrderItem } from '@/api/types'
import OrderDetailView from '@/views/OrderDetailView.vue'

/* ------------------------------------------------------------------ */
/* Mocked modules                                                      */
/* ------------------------------------------------------------------ */

const api = vi.hoisted(() => ({
  getOrder: vi.fn(),
  cancelOrder: vi.fn(),
  submitBankTransferProof: vi.fn(),
  updateOrderStatus: vi.fn(),
  verifyBankTransfer: vi.fn(),
  markCodPayment: vi.fn(),
  submitCodPaymentProof: vi.fn(),
  confirmCodPayment: vi.fn(),
  requestDpSettlement: vi.fn(),
  updateShipmentStatus: vi.fn(),
  assignCourier: vi.fn(),
  listDispatchCouriers: vi.fn(),
  recordDeliveryVerification: vi.fn(),
  adjustItemFulfillment: vi.fn(),
  rescheduleOrderItem: vi.fn(),
  addOrderItem: vi.fn(),
  downloadInvoice: vi.fn(),
  listProducts: vi.fn(),
  requestReturn: vi.fn(),
}))

vi.mock('@/api/orders', () => ({
  getOrder: api.getOrder,
  cancelOrder: api.cancelOrder,
  submitBankTransferProof: api.submitBankTransferProof,
  updateOrderStatus: api.updateOrderStatus,
}))
vi.mock('@/api/payments', () => ({
  verifyBankTransfer: api.verifyBankTransfer,
  markCodPayment: api.markCodPayment,
  submitCodPaymentProof: api.submitCodPaymentProof,
  confirmCodPayment: api.confirmCodPayment,
  requestDpSettlement: api.requestDpSettlement,
}))
vi.mock('@/api/shipments', () => ({
  updateShipmentStatus: api.updateShipmentStatus,
  assignCourier: api.assignCourier,
}))
vi.mock('@/api/dispatch', () => ({ listDispatchCouriers: api.listDispatchCouriers }))
vi.mock('@/api/deliveries', () => ({ recordDeliveryVerification: api.recordDeliveryVerification }))
vi.mock('@/api/orderAdjustments', () => ({
  adjustItemFulfillment: api.adjustItemFulfillment,
  rescheduleOrderItem: api.rescheduleOrderItem,
  addOrderItem: api.addOrderItem,
}))
vi.mock('@/api/invoice', () => ({ downloadInvoice: api.downloadInvoice }))
vi.mock('@/api/catalog', () => ({ listProducts: api.listProducts }))
vi.mock('@/api/returns', () => ({ requestReturn: api.requestReturn }))

// The component only needs ApiError from here; mocking it cuts the real
// client → connectivity → i18n import chain (no backend, no network).
vi.mock('@/api/client', () => ({ ApiError }))

const authState = vi.hoisted(() => ({
  user: { id: 900, role: 'super_admin', agent_id: null } as {
    id: number
    role: string
    agent_id: number | null
  } | null,
  permissions: [] as string[],
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    get user() {
      return authState.user
    },
    can: (capability: string) => authState.permissions.includes(capability),
    get isKonsumen() {
      return authState.user?.role === 'konsumen'
    },
  }),
}))

vi.mock('vue-i18n', async (importOriginal) => {
  const actual = await importOriginal<typeof import('vue-i18n')>()
  // Partial mock: the real i18n setup (createI18n) keeps working for any
  // evaluated module, while the component under test gets a key-echo t().
  return { ...actual, useI18n: () => ({ t: (key: string) => key }) }
})
vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ resolve: () => ({ href: '/' }), push: vi.fn() }),
}))

/* ------------------------------------------------------------------ */
/* Fixtures + helpers                                                  */
/* ------------------------------------------------------------------ */

const OFFICE_PERMISSIONS = [
  'orders.manage.status',
  'orders.manage.shipment',
  'orders.manage.fulfillment',
]

function stdItem(overrides: Partial<OrderItem> = {}): OrderItem {
  return {
    id: 101,
    product_id: 1,
    product_variation_id: null,
    product_name: 'Produk A',
    variation_label: null,
    sku: 'SKU-A',
    unit_price: '10000',
    original_quantity: 2,
    fulfilled_quantity: 2,
    cancelled_quantity: 0,
    returned_quantity: 0,
    refund_quantity: 0,
    additional_quantity: 0,
    subtotal: '20000',
    status: 'diproses',
    requested_delivery_date: null,
    shipment_id: 12,
    delivery_mode: 'standard',
    self_delivered_by_user_id: null,
    courier: null,
    delivery_proof_url: null,
    ...overrides,
  }
}

function orderFixture(overrides: Partial<Order> = {}): Order {
  return {
    id: 1,
    order_no: 'ORD-1',
    status: 'diproses',
    payment_status: 'unpaid',
    subtotal_amount: '20000',
    shipping_fee_amount: '0',
    admin_fee_amount: '0',
    total_amount: '20000',
    recipient_name: 'Budi',
    recipient_phone: '081000',
    address: 'Jl. Merdeka 1',
    latitude: null,
    longitude: null,
    agent_id: 10,
    delivery_date_estimate: null,
    cancellation_reason: null,
    konsumen: null,
    sales: null,
    korsal: null,
    couriers: [],
    returns: [],
    items: [stdItem()],
    payment_method: null,
    payment_transaction: null,
    payment_summary: {
      grand_total: 20000,
      requested_dp: 0,
      verified_dp: 0,
      submitted_dp: 0,
      total_paid: 0,
      remaining_balance: 20000,
      overpaid_amount: 0,
      payment_status: 'unpaid',
      is_fully_paid: false,
      pending_verification_status: null,
      has_pending_proof: false,
      additional_payment_amount: 0,
      additional_payment_status: null,
      refund_amount: 0,
      refund_status: null,
    },
    created_at: '2026-10-14T00:00:00Z',
    ...overrides,
  }
}

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason?: unknown) => void
  const promise = new Promise<T>((res, rej) => {
    resolve = res
    reject = rej
  })
  return { promise, resolve, reject }
}

function mountDetail(orderId: number) {
  return mount(OrderDetailView, {
    props: { id: orderId },
    global: {
      stubs: {
        ShopLayout: { template: '<div class="shop-stub"><slot /></div>' },
        GoogleMapView: true,
      },
    },
  })
}

/** The courier-assignment dropdown (placeholder option = mocked i18n key). */
function courierSelect(wrapper: VueWrapper) {
  return wrapper
    .findAll('select')
    .find((s) => s.html().includes('orders.chooseCourier'))
}

function courierOptions(wrapper: VueWrapper): string[] {
  const select = courierSelect(wrapper)
  if (!select) return []
  return select.findAll('option').map((o) => o.text())
}

function assignButton(wrapper: VueWrapper) {
  return wrapper.findAll('button').find((b) => b.text() === 'orders.assignCourierButton')
}

/* ------------------------------------------------------------------ */
/* Tests                                                               */
/* ------------------------------------------------------------------ */

describe('OrderDetailView courier assignment (FIX-COURIER-ASSIGNMENT-422)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    authState.user = { id: 900, role: 'super_admin', agent_id: null }
    authState.permissions = [...OFFICE_PERMISSIONS]
    api.adjustItemFulfillment.mockResolvedValue(undefined)
  })

  it('follows branch B after A → B navigation and clears A immediately', async () => {
    const orderA = orderFixture({ id: 1, order_no: 'ORD-A', agent_id: 10 })
    const orderB = orderFixture({ id: 2, order_no: 'ORD-B', agent_id: 20 })
    const orderBRequest = deferred<Order>()
    api.getOrder.mockImplementation((id: number) =>
      id === 1 ? Promise.resolve(orderA) : orderBRequest.promise,
    )
    api.listDispatchCouriers.mockImplementation((agentId?: number) =>
      Promise.resolve(agentId === 10 ? [{ id: 1, name: 'Kurir A' }] : [{ id: 2, name: 'Kurir B' }]),
    )

    const wrapper = mountDetail(1)
    await flushPromises()
    expect(wrapper.html()).toContain('ORD-A')
    expect(courierOptions(wrapper)).toContain('Kurir A')

    await wrapper.setProps({ id: 2 })
    // New order still in flight: the previous detail must already be gone.
    expect(wrapper.html()).not.toContain('ORD-A')
    expect(wrapper.html()).not.toContain('Kurir A')

    orderBRequest.resolve(orderB)
    await flushPromises()
    expect(wrapper.html()).toContain('ORD-B')
    expect(courierOptions(wrapper)).toContain('Kurir B')
    expect(courierOptions(wrapper)).not.toContain('Kurir A')
    expect(api.listDispatchCouriers).toHaveBeenLastCalledWith(20)
  })

  it('discards a late order-A response after navigating to B', async () => {
    const orderA = orderFixture({ id: 1, order_no: 'ORD-A', agent_id: 10 })
    const orderB = orderFixture({ id: 2, order_no: 'ORD-B', agent_id: 20 })
    const orderARequest = deferred<Order>()
    api.getOrder.mockImplementation((id: number) =>
      id === 1 ? orderARequest.promise : Promise.resolve(orderB),
    )
    api.listDispatchCouriers.mockResolvedValue([{ id: 2, name: 'Kurir B' }])

    const wrapper = mountDetail(1)
    await wrapper.setProps({ id: 2 })
    await flushPromises()
    expect(wrapper.html()).toContain('ORD-B')

    orderARequest.resolve(orderA)
    await flushPromises()
    expect(wrapper.html()).toContain('ORD-B')
    expect(wrapper.html()).not.toContain('ORD-A')
    expect(api.listDispatchCouriers).not.toHaveBeenCalledWith(10)
  })

  it('discards a late courier-list response from the previous order', async () => {
    const orderA = orderFixture({ id: 1, order_no: 'ORD-A', agent_id: 10 })
    const orderB = orderFixture({ id: 2, order_no: 'ORD-B', agent_id: 20 })
    const staleCouriers = deferred<{ id: number; name: string }[]>()
    api.getOrder.mockImplementation((id: number) =>
      Promise.resolve(id === 1 ? orderA : orderB),
    )
    api.listDispatchCouriers.mockImplementation((agentId?: number) =>
      agentId === 10 ? staleCouriers.promise : Promise.resolve([{ id: 2, name: 'Kurir B' }]),
    )

    const wrapper = mountDetail(1)
    await flushPromises()
    expect(wrapper.html()).toContain('ORD-A')

    await wrapper.setProps({ id: 2 })
    await flushPromises()
    expect(courierOptions(wrapper)).toContain('Kurir B')

    staleCouriers.resolve([{ id: 1, name: 'Kurir STALE' }])
    await flushPromises()
    expect(courierOptions(wrapper)).toContain('Kurir B')
    expect(courierOptions(wrapper)).not.toContain('Kurir STALE')
  })

  it('renders a safe error when getOrder fails and never offers assignment', async () => {
    api.getOrder.mockRejectedValueOnce(new ApiError('Sesi berakhir.', 401, null))
    const wrapper = mountDetail(7)
    await flushPromises()
    expect(wrapper.html()).toContain('Sesi berakhir.')
    expect(wrapper.html()).not.toContain('orders.assignCourierButton')
    expect(courierSelect(wrapper)).toBeUndefined()
    expect(api.listDispatchCouriers).not.toHaveBeenCalled()
  })

  it('falls back to a generic message for non-API order failures', async () => {
    api.getOrder.mockRejectedValueOnce(new Error('boom'))
    const wrapper = mountDetail(8)
    await flushPromises()
    expect(wrapper.html()).toContain('orders.errors.loadOrder')
    expect(courierSelect(wrapper)).toBeUndefined()
  })

  it('super_admin scopes the courier list by the order agent_id, never its own', async () => {
    authState.user = { id: 900, role: 'super_admin', agent_id: 999 }
    api.getOrder.mockResolvedValue(orderFixture({ agent_id: 10 }))
    api.listDispatchCouriers.mockResolvedValue([{ id: 1, name: 'Kurir A' }])
    const wrapper = mountDetail(1)
    await flushPromises()
    expect(api.listDispatchCouriers).toHaveBeenCalledTimes(1)
    expect(api.listDispatchCouriers).toHaveBeenCalledWith(10)
    expect(api.listDispatchCouriers).not.toHaveBeenCalledWith(999)
    expect(courierOptions(wrapper)).toContain('Kurir A')
  })

  it('super_admin with an empty agent_id makes no request and disables assignment', async () => {
    api.getOrder.mockResolvedValue(orderFixture({ agent_id: null }))
    api.listDispatchCouriers.mockResolvedValue([{ id: 1, name: 'Must Not Appear' }])
    const wrapper = mountDetail(1)
    await flushPromises()
    expect(api.listDispatchCouriers).not.toHaveBeenCalled()
    expect(wrapper.html()).toContain('orders.errors.courierListNoBranch')
    const select = courierSelect(wrapper)
    expect(select?.attributes('disabled')).not.toBeUndefined()
    expect(courierOptions(wrapper)).toEqual(['orders.chooseCourier'])
    expect(assignButton(wrapper)?.attributes('disabled')).not.toBeUndefined()
  })

  it.each(['agen', 'admin'] as const)(
    '%s relies on backend scoping without an extra branch param',
    async (role) => {
      authState.user = { id: 11, role, agent_id: 10 }
      api.getOrder.mockResolvedValue(orderFixture({ agent_id: 10 }))
      api.listDispatchCouriers.mockResolvedValue([{ id: 3, name: 'Kurir Cabang' }])
      const wrapper = mountDetail(1)
      await flushPromises()
      expect(api.listDispatchCouriers).toHaveBeenCalledTimes(1)
      expect(api.listDispatchCouriers.mock.calls[0]).toHaveLength(0)
      expect(courierOptions(wrapper)).toContain('Kurir Cabang')
    },
  )

  it('keeps the order detail with an empty dropdown when the courier list fails', async () => {
    api.getOrder.mockResolvedValue(orderFixture())
    api.listDispatchCouriers.mockRejectedValueOnce(new Error('offline'))
    const wrapper = mountDetail(1)
    await flushPromises()
    expect(wrapper.html()).toContain('ORD-1')
    expect(wrapper.html()).toContain('orders.errors.courierList')
    expect(courierOptions(wrapper)).toEqual(['orders.chooseCourier'])
    expect(courierSelect(wrapper)?.attributes('disabled')).not.toBeUndefined()
  })

  it('never offers courier assignment for self_sub shipments', async () => {
    api.getOrder.mockResolvedValue(
      orderFixture({
        items: [
          stdItem({ delivery_mode: 'self_sub', self_delivered_by_user_id: 777 }),
        ],
      }),
    )
    api.listDispatchCouriers.mockResolvedValue([{ id: 1, name: 'Kurir A' }])
    const wrapper = mountDetail(1)
    await flushPromises()
    expect(wrapper.html()).toContain('ORD-1')
    expect(courierSelect(wrapper)).toBeUndefined()
    expect(assignButton(wrapper)).toBeUndefined()
  })

  it('same-order refresh keeps the loaded list and discards the stale in-flight request', async () => {
    api.getOrder.mockResolvedValue(orderFixture({ id: 1, order_no: 'ORD-1', agent_id: 10 }))
    const firstCouriers = deferred<{ id: number; name: string }[]>()
    const secondCouriers = deferred<{ id: number; name: string }[]>()
    api.listDispatchCouriers
      .mockImplementationOnce(() => firstCouriers.promise)
      .mockImplementationOnce(() => secondCouriers.promise)

    const wrapper = mountDetail(1)
    await flushPromises()
    expect(api.listDispatchCouriers).toHaveBeenCalledTimes(1)

    // Same-order refresh via the fulfillment-adjust action while the first
    // courier request is still in flight.
    const openForm = wrapper.findAll('button').find((b) => b.text() === 'orders.changeFulfillment')
    expect(openForm).not.toBeUndefined()
    await openForm!.trigger('click')
    const reason = wrapper.find('input[placeholder="orders.reason"]')
    await reason.setValue('penyesuaian UAT')
    const save = wrapper.findAll('button').find((b) => b.text() === 'orders.save')
    await save!.trigger('click')
    await flushPromises()
    expect(api.getOrder).toHaveBeenCalledTimes(2)
    expect(api.listDispatchCouriers).toHaveBeenCalledTimes(2)

    firstCouriers.resolve([{ id: 1, name: 'Kurir STALE' }])
    await flushPromises()
    expect(courierOptions(wrapper)).toEqual(['orders.chooseCourier'])

    secondCouriers.resolve([{ id: 2, name: 'Kurir BARU' }])
    await flushPromises()
    expect(courierOptions(wrapper)).toContain('Kurir BARU')
    expect(courierOptions(wrapper)).not.toContain('Kurir STALE')

    // A further same-order refresh must not refetch the loaded list.
    const reopen = wrapper.findAll('button').find((b) => b.text() === 'orders.changeFulfillment')
    await reopen!.trigger('click')
    await wrapper.find('input[placeholder="orders.reason"]').setValue('cek ulang')
    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'orders.save')!
      .trigger('click')
    await flushPromises()
    expect(api.listDispatchCouriers).toHaveBeenCalledTimes(2)
    expect(courierOptions(wrapper)).toContain('Kurir BARU')
  })
})
