/**
 * FIX-COURIER-ASSIGNMENT-422 + CR-SALES-KURIR-SUB-DELIVERY-001 — focused
 * regression for OrderDetailView.vue.
 *
 * Covers the R-1 lifecycle/stale-response remediation at the component level
 * (jsdom + @vue/test-utils, all API modules mocked — no backend, no network).
 *
 * The assignment dropdown source is the PER-SHIPMENT eligible list
 * (GET /shipments/{id}/eligible-couriers): branch kurir/koordinator plus the
 * Sales-Kurir-Sub executors eligible for the viewed order only. Scoping is
 * server-side per shipment — the component never sends a branch param
 * (the FIX-422 super_admin branch-scope remediation holds structurally).
 *
 * Scenario map (task §3–§7):
 *  1. A → B navigation fetches B's shipment candidates and clears A's.
 *  2. A late order-A response never overwrites B (and triggers no fetch).
 *  3. A late eligible-list response from the previous order is discarded.
 *  4. getOrder() failure renders a safe error (ApiError + generic) with no fetch.
 *  5. super_admin/agen/admin fetch per shipment id with no branch param.
 *  6. Eligible-list failure keeps the detail with an empty dropdown.
 *  7. self_sub shipments never offer courier assignment (and trigger no fetch).
 *  8. Two standard shipments fetch independently per shipment.
 *  9. Same-order double refresh keeps loaded lists; stale in-flight
 *      eligible responses are discarded.
 *  10. A Sales-Kurir-Sub operates only its assigned standard shipment or its
 *      owned self_sub shipment — never unassigned or another courier's work.
 */
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiError } from '@/api/errors'
import type { EligibleCourier } from '@/api/dispatch'
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
  listEligibleCouriers: vi.fn(),
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
vi.mock('@/api/dispatch', () => ({ listEligibleCouriers: api.listEligibleCouriers }))
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

describe('OrderDetailView courier assignment (FIX-COURIER-ASSIGNMENT-422 + SKS-DELIVERY-001)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    authState.user = { id: 900, role: 'super_admin', agent_id: null }
    authState.permissions = [...OFFICE_PERMISSIONS]
    api.adjustItemFulfillment.mockResolvedValue(undefined)
  })

  it('follows shipment B after A → B navigation and clears A immediately', async () => {
    const orderA = orderFixture({ id: 1, order_no: 'ORD-A', agent_id: 10, items: [stdItem({ shipment_id: 12 })] })
    const orderB = orderFixture({ id: 2, order_no: 'ORD-B', agent_id: 20, items: [stdItem({ shipment_id: 13 })] })
    const orderBRequest = deferred<Order>()
    api.getOrder.mockImplementation((id: number) =>
      id === 1 ? Promise.resolve(orderA) : orderBRequest.promise,
    )
    api.listEligibleCouriers.mockImplementation((shipmentId: number) =>
      Promise.resolve(shipmentId === 12 ? [{ courier_id: 1, user_id: 11, name: 'Kurir A' }] : [{ courier_id: 2, user_id: 22, name: 'Kurir B' }]),
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
    expect(api.listEligibleCouriers).toHaveBeenLastCalledWith(13)
  })

  it('discards a late order-A response after navigating to B', async () => {
    const orderA = orderFixture({ id: 1, order_no: 'ORD-A', agent_id: 10, items: [stdItem({ shipment_id: 12 })] })
    const orderB = orderFixture({ id: 2, order_no: 'ORD-B', agent_id: 20, items: [stdItem({ shipment_id: 13 })] })
    const orderARequest = deferred<Order>()
    api.getOrder.mockImplementation((id: number) =>
      id === 1 ? orderARequest.promise : Promise.resolve(orderB),
    )
    api.listEligibleCouriers.mockResolvedValue([{ courier_id: 2, user_id: 22, name: 'Kurir B' }])

    const wrapper = mountDetail(1)
    await wrapper.setProps({ id: 2 })
    await flushPromises()
    expect(wrapper.html()).toContain('ORD-B')

    orderARequest.resolve(orderA)
    await flushPromises()
    expect(wrapper.html()).toContain('ORD-B')
    expect(wrapper.html()).not.toContain('ORD-A')
    // Order A never resolved while active, so its shipment was never fetched.
    expect(api.listEligibleCouriers).not.toHaveBeenCalledWith(12)
  })

  it('discards a late eligible-list response from the previous order', async () => {
    const orderA = orderFixture({ id: 1, order_no: 'ORD-A', agent_id: 10, items: [stdItem({ shipment_id: 12 })] })
    const orderB = orderFixture({ id: 2, order_no: 'ORD-B', agent_id: 20, items: [stdItem({ shipment_id: 13 })] })
    const staleCouriers = deferred<EligibleCourier[]>()
    api.getOrder.mockImplementation((id: number) =>
      Promise.resolve(id === 1 ? orderA : orderB),
    )
    api.listEligibleCouriers.mockImplementation((shipmentId: number) =>
      shipmentId === 12 ? staleCouriers.promise : Promise.resolve([{ courier_id: 2, user_id: 22, name: 'Kurir B' }]),
    )

    const wrapper = mountDetail(1)
    await flushPromises()
    expect(wrapper.html()).toContain('ORD-A')

    await wrapper.setProps({ id: 2 })
    await flushPromises()
    expect(courierOptions(wrapper)).toContain('Kurir B')

    staleCouriers.resolve([{ courier_id: 1, user_id: 11, name: 'Kurir STALE' }])
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
    expect(api.listEligibleCouriers).not.toHaveBeenCalled()
  })

  it('falls back to a generic message for non-API order failures', async () => {
    api.getOrder.mockRejectedValueOnce(new Error('boom'))
    const wrapper = mountDetail(8)
    await flushPromises()
    expect(wrapper.html()).toContain('orders.errors.loadOrder')
    expect(courierSelect(wrapper)).toBeUndefined()
  })

  it.each(['super_admin', 'agen', 'admin'] as const)(
    '%s fetches candidates per shipment id with no branch param',
    async (role) => {
      authState.user = { id: 11, role, agent_id: role === 'super_admin' ? 999 : 10 }
      api.getOrder.mockResolvedValue(orderFixture({ agent_id: 10, items: [stdItem({ shipment_id: 12 })] }))
      api.listEligibleCouriers.mockResolvedValue([{ courier_id: 3, user_id: 33, name: 'Kurir Cabang' }])
      const wrapper = mountDetail(1)
      await flushPromises()
      // Branch scoping is server-side per shipment — no branch arg by construction.
      expect(api.listEligibleCouriers).toHaveBeenCalledTimes(1)
      expect(api.listEligibleCouriers).toHaveBeenCalledWith(12)
      expect(api.listEligibleCouriers.mock.calls[0]).toHaveLength(1)
      expect(courierOptions(wrapper)).toContain('Kurir Cabang')
    },
  )

  it('keeps the order detail with an empty dropdown when the eligible list fails', async () => {
    api.getOrder.mockResolvedValue(orderFixture())
    api.listEligibleCouriers.mockRejectedValueOnce(new Error('offline'))
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
    api.listEligibleCouriers.mockResolvedValue([{ courier_id: 1, user_id: 11, name: 'Kurir A' }])
    const wrapper = mountDetail(1)
    await flushPromises()
    expect(wrapper.html()).toContain('ORD-1')
    expect(courierSelect(wrapper)).toBeUndefined()
    expect(assignButton(wrapper)).toBeUndefined()
    expect(api.listEligibleCouriers).not.toHaveBeenCalled()
  })

  it('fetches each standard shipment independently within one order', async () => {
    api.getOrder.mockResolvedValue(
      orderFixture({
        items: [stdItem({ shipment_id: 12 }), stdItem({ id: 102, shipment_id: 13 })],
      }),
    )
    api.listEligibleCouriers.mockImplementation((shipmentId: number) =>
      Promise.resolve(shipmentId === 12 ? [{ courier_id: 1, user_id: 11, name: 'Kurir A' }] : [{ courier_id: 2, user_id: 44, name: 'SKS B', executor_role: 'sales-kurir-sub' }]),
    )
    const wrapper = mountDetail(1)
    await flushPromises()
    expect(api.listEligibleCouriers).toHaveBeenCalledWith(12)
    expect(api.listEligibleCouriers).toHaveBeenCalledWith(13)
    const selects = wrapper.findAll('select').filter((s) => s.html().includes('orders.chooseCourier'))
    expect(selects).toHaveLength(2)
    const texts = selects.map((s) => s.findAll('option').map((o) => o.text()))
    expect(texts[0]).toContain('Kurir A')
    expect(texts[1]).toContain('SKS B')
  })

  it('same-order refresh keeps the loaded list and discards the stale in-flight request', async () => {
    api.getOrder.mockResolvedValue(orderFixture({ id: 1, order_no: 'ORD-1', agent_id: 10 }))
    const firstCouriers = deferred<EligibleCourier[]>()
    const secondCouriers = deferred<EligibleCourier[]>()
    api.listEligibleCouriers
      .mockImplementationOnce(() => firstCouriers.promise)
      .mockImplementationOnce(() => secondCouriers.promise)

    const wrapper = mountDetail(1)
    await flushPromises()
    expect(api.listEligibleCouriers).toHaveBeenCalledTimes(1)

    // Same-order refresh via the fulfillment-adjust action while the first
    // eligible request is still in flight.
    const openForm = wrapper.findAll('button').find((b) => b.text() === 'orders.changeFulfillment')
    expect(openForm).not.toBeUndefined()
    await openForm!.trigger('click')
    const reason = wrapper.find('input[placeholder="orders.reason"]')
    await reason.setValue('penyesuaian UAT')
    const save = wrapper.findAll('button').find((b) => b.text() === 'orders.save')
    await save!.trigger('click')
    await flushPromises()
    expect(api.getOrder).toHaveBeenCalledTimes(2)
    expect(api.listEligibleCouriers).toHaveBeenCalledTimes(2)

    firstCouriers.resolve([{ courier_id: 1, user_id: 11, name: 'Kurir STALE' }])
    await flushPromises()
    expect(courierOptions(wrapper)).toEqual(['orders.chooseCourier'])

    secondCouriers.resolve([{ courier_id: 2, user_id: 22, name: 'Kurir BARU' }])
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
    expect(api.listEligibleCouriers).toHaveBeenCalledTimes(2)
    expect(courierOptions(wrapper)).toContain('Kurir BARU')
  })

  it('a sales-kurir-sub operates only its assigned standard or owned self_sub work', async () => {
    authState.user = { id: 777, role: 'sales-kurir-sub', agent_id: 10 }
    authState.permissions = [...OFFICE_PERMISSIONS]
    api.getOrder.mockResolvedValue(
      orderFixture({
        items: [
          // Assigned to this SKS → actionable pickup.
          stdItem({ id: 101, product_name: 'Milik SKS', shipment_id: 12, courier: { name: 'SKS', phone: null, user_id: 777 } }),
          // Another courier's shipment → rendered but without any action.
          stdItem({ id: 102, product_name: 'Milik Lain', shipment_id: 13, courier: { name: 'Lain', phone: null, user_id: 888 } }),
          // Own self_sub shipment → actionable pickup.
          stdItem({ id: 103, product_name: 'Self Sub', shipment_id: 14, delivery_mode: 'self_sub', self_delivered_by_user_id: 777, courier: null }),
        ],
      }),
    )
    api.listEligibleCouriers.mockResolvedValue([])
    const wrapper = mountDetail(1)
    await flushPromises()
    // Office dropdowns never render for a sales-kurir-sub viewer.
    expect(courierSelect(wrapper)).toBeUndefined()
    const pickups = wrapper.findAll('button').filter((b) => b.text() === 'orders.pickupAndDeliver')
    expect(pickups).toHaveLength(2)
    expect(wrapper.html()).toContain('Milik Lain')
  })

  it('assigns a profile-less candidate by user id and a regular courier by courier id', async () => {
    api.getOrder.mockResolvedValue(orderFixture({ items: [stdItem({ shipment_id: 12 })] }))
    api.listEligibleCouriers.mockResolvedValue([
      { courier_id: 5, user_id: 55, name: 'Kurir A' },
      { courier_id: null, user_id: 777, name: 'SKS Tanpa Profil', executor_role: 'sales-kurir-sub' },
    ])
    api.assignCourier.mockResolvedValue(orderFixture())
    const wrapper = mountDetail(1)
    await flushPromises()

    const select = courierSelect(wrapper)
    expect(select).not.toBeUndefined()
    await select!.setValue('u:777')
    await assignButton(wrapper)!.trigger('click')
    await flushPromises()
    expect(api.assignCourier).toHaveBeenCalledWith(12, { executor_user_id: 777 })

    await select!.setValue('c:5')
    await assignButton(wrapper)!.trigger('click')
    await flushPromises()
    expect(api.assignCourier).toHaveBeenCalledWith(12, { courier_id: 5 })
  })
})
