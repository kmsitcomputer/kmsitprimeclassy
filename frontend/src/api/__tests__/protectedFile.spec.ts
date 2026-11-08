import { describe, expect, it, vi, beforeEach } from 'vitest'
import { ApiError } from '../errors'
const { get } = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('../client', async () => ({ http: { get }, ...await import('../errors') }))
import { fetchProtectedFile, toApiRelativePath } from '../protectedFile'

describe('protected payment proof', () => {
  beforeEach(() => { get.mockReset() })
  it('uses only the local protected proof endpoint even for absolute server URLs', () => {
    expect(toApiRelativePath('http://wrong.example/api/v1/payment-proofs/12')).toBe('/payment-proofs/12')
    expect(() => toApiRelativePath('//wrong.example/private')).toThrow()
  })
  it.each([[401, 'unauthenticated'], [403, 'forbidden'], [404, 'not_found'], [0, 'network']])('maps client error %s', async (status, kind) => {
    get.mockRejectedValue(new ApiError('error', Number(status), null))
    let caught: unknown
    try { await fetchProtectedFile('/api/v1/payment-proofs/12') } catch (e) { caught = e }
    expect(caught).toMatchObject({ kind })
  })
  it('fetches a blob with the authenticated client and derives its filename', async () => {
    get.mockResolvedValue({ data: new Blob(['image'], { type: 'image/png' }) })
    expect(await fetchProtectedFile('/api/v1/payment-proofs/12', 'transfer')).toMatchObject({ mime: 'image/png', filename: 'transfer.png' })
    expect(get).toHaveBeenCalledWith('/payment-proofs/12', { responseType: 'blob' })
  })
})
