/**
 * C-SC03-UAT-PRE-001: the ONE generator for transaction / idempotency-key identities.
 *
 * `crypto.randomUUID()` is only exposed in secure contexts (HTTPS/localhost) and newer browsers; some
 * environments expose `crypto` without it, which crashed Checkout setup. Preference order:
 *   1. native `crypto.randomUUID()`
 *   2. RFC 4122 version-4 UUID built from `crypto.getRandomValues()`
 *   3. otherwise FAIL explicitly — never fall back to Math.random()/Date.now()/counters, because the
 *      value is a financial request identity and must not be predictable or collide.
 */
export function secureUuid(): string {
  const c: Crypto | undefined = typeof globalThis !== 'undefined' ? (globalThis as { crypto?: Crypto }).crypto : undefined

  if (c && typeof c.randomUUID === 'function') {
    return c.randomUUID()
  }

  if (c && typeof c.getRandomValues === 'function') {
    const b = c.getRandomValues(new Uint8Array(16))
    const h = Array.from(b, (x, i) => {
      const v = i === 6 ? (x & 0x0f) | 0x40 : i === 8 ? (x & 0x3f) | 0x80 : x // version 4 / RFC 4122 variant
      return v.toString(16).padStart(2, '0')
    })
    return `${h.slice(0, 4).join('')}-${h.slice(4, 6).join('')}-${h.slice(6, 8).join('')}-${h.slice(8, 10).join('')}-${h.slice(10).join('')}`
  }

  throw new Error('A secure random source (crypto.randomUUID / crypto.getRandomValues) is required to create a request identifier.')
}
