import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { NUXT_OWNED_ROUTE_NAMES } from '../boundary'
import { routeDefs } from '../routeDefs'
import { routes } from '../routes'

const NUXT_APP = join(__dirname, '../../../nuxt-storefront/app')

describe('SPA ⇄ Nuxt routing boundary (IMP-009)', () => {
  it('every Nuxt-owned route name exists in the single shared route table', () => {
    const names = new Set(routeDefs.map((r) => r.name))
    for (const owned of NUXT_OWNED_ROUTE_NAMES) expect(names.has(owned)).toBe(true)
  })

  it('route names are unique (the boundary is keyed by name)', () => {
    const names = routeDefs.map((r) => r.name)
    expect(new Set(names).size).toBe(names.length)
  })

  it('the SPA route table is exactly the shared definitions plus a view for each (no fork)', () => {
    expect(routes.map((r) => [r.name, r.path])).toEqual(routeDefs.map((r) => [r.name, r.path]))
    expect(routes.every((r) => typeof (r as { component?: unknown }).component === 'function')).toBe(true)
  })

  it('every authenticated / guest-only / permission-gated route stays in the SPA', () => {
    const owned = new Set<string>(NUXT_OWNED_ROUTE_NAMES)
    for (const def of routeDefs) {
      const meta = (def.meta ?? {}) as Record<string, unknown>
      const guarded = meta.requiresAuth || meta.guestOnly || meta.requiresPermission || meta.requiresAnyPermission || meta.requiresAnyRole
      if (guarded) expect(owned.has(def.name), `${def.name} must not be Nuxt-owned`).toBe(false)
    }
  })

  it('checkout, cart, installer, dashboard and admin are never Nuxt-owned', () => {
    const owned = new Set<string>(NUXT_OWNED_ROUTE_NAMES)
    for (const def of routeDefs) {
      if (/^(\/dashboard|\/admin|\/setup|\/install|\/checkout|\/cart|\/wishlist|\/login|\/register|\/forgot-password|\/profile|\/orders|\/account|\/print)/.test(def.path)) {
        expect(owned.has(def.name), `${def.path} must stay in the SPA`).toBe(false)
      }
    }
  })

  it('routeDefs.ts imports no view (so the Nuxt build can never bundle SPA-only code)', () => {
    const src = readFileSync(join(__dirname, '../routeDefs.ts'), 'utf8')
    expect(src).not.toMatch(/import\(/)
    expect(src).not.toMatch(/@\/views|\.vue/)
  })

  it('REM-11 Historical POS / ReportRegistry routes did not re-enter the route table', () => {
    for (const def of routeDefs) {
      expect(def.path).not.toMatch(/historical|registry/i)
      expect(def.name).not.toMatch(/historical|registry|^hp-/i)
    }
  })

  it('REM-11 exclusion holds for the Nuxt app sources too', () => {
    const walk = (dir: string): string[] =>
      readdirSync(dir).flatMap((f) => (statSync(join(dir, f)).isDirectory() ? walk(join(dir, f)) : [join(dir, f)]))
    for (const file of walk(NUXT_APP)) {
      const text = readFileSync(file, 'utf8')
      expect(text, file).not.toMatch(/historical-pos|HistoricalPos|ReportRegistry|historical_dossier/i)
    }
  })
})
