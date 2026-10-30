/** Checkout quote identity: address change during an in-flight quote. */
import { describe, expect, it } from 'vitest'

import { createLatestRequestGuard } from '@/utils/latestRequest'

describe('createLatestRequestGuard', () => {
  it('only the newest token is current', () => {
    const g = createLatestRequestGuard()
    const a = g.begin()
    const b = g.begin()
    expect(g.isCurrent(a)).toBe(false)
    expect(g.isCurrent(b)).toBe(true)
  })

  it('invalidate() retires an in-flight request (destination changed) so it can no longer apply', () => {
    const g = createLatestRequestGuard()
    const inFlight = g.begin()
    g.invalidate()
    expect(g.isCurrent(inFlight)).toBe(false)
    expect(g.isCurrent(g.begin())).toBe(true)
  })

  it('simulated checkout: stale response is dropped and loading is cleared by the invalidating watcher', async () => {
    const g = createLatestRequestGuard()
    let quote: string | null = null
    let quoting = false
    let resolveOld!: (v: string) => void
    const old = new Promise<string>((r) => (resolveOld = r))

    const token = g.begin()
    quoting = true
    const run = old.then((v) => { if (g.isCurrent(token)) quote = v }).finally(() => { if (g.isCurrent(token)) quoting = false })

    // address changes while the request is in flight (what the CheckoutView watcher does)
    g.invalidate(); quote = null; quoting = false

    resolveOld('OLD-PRICE')
    await run
    expect(quote).toBeNull()
    expect(quoting).toBe(false)
  })
})
