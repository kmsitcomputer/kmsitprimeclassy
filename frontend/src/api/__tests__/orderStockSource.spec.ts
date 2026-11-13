import { describe, expect, it, vi } from 'vitest'
const { post } = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('../client', () => ({ http: { post } }))
import { createOrder, type CreateOrderPayload } from '../orders'

describe('REM-09 checkout transport', () => {
  it.each(['agent', 'sub'] as const)('sends one %s source for all cart lines without a client location or prices', async (stockSource) => {
    post.mockResolvedValue({ data: { data: { id: 1 } } })
    const payload: CreateOrderPayload = { items: [{ product_id: 1, quantity: 2 }, { product_id: 2, product_variation_id: 3, quantity: 1 }], destination: { recipient_name: 'Buyer' }, paymentMethodCode: 'cod', idempotencyKey: 'stable', stockSource }
    await createOrder(payload)
    const [path, body, config] = post.mock.lastCall!
    expect(path).toBe('/orders')
    expect(body.stock_source).toBe(stockSource)
    expect(body.items).toEqual(payload.items)
    expect(body).not.toHaveProperty('sub_location_id')
    expect(body).not.toHaveProperty('paid_amount')
    expect(config.headers['Idempotency-Key']).toBe('stable')
  })
})
