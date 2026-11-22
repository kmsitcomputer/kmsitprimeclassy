import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }))
vi.mock('../client', () => ({ http }))

import { getOwnStoreProfile } from '../agents'

describe('agent store profile API contract', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('converts decimal-string coordinates from Go to numbers before the form submits them again', async () => {
    http.get.mockResolvedValue({
      data: {
        data: { store_name: 'Toko', address: 'Alamat', phone: null, latitude: '-6.2000000', longitude: '106.8000000' },
      },
    })

    const profile = await getOwnStoreProfile()

    expect(profile?.latitude).toBe(-6.2)
    expect(profile?.longitude).toBe(106.8)
    expect(typeof profile?.latitude).toBe('number')
    expect(typeof profile?.longitude).toBe('number')
  })

  it('keeps absent coordinates null instead of sending NaN', async () => {
    http.get.mockResolvedValue({
      data: { data: { store_name: 'Toko', address: 'Alamat', phone: null, latitude: null, longitude: 'invalid' } },
    })

    const profile = await getOwnStoreProfile()

    expect(profile?.latitude).toBeNull()
    expect(profile?.longitude).toBeNull()
  })
})
