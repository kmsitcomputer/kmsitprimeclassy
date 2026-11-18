import { AsyncLocalStorage } from 'node:async_hooks'
import { afterEach, describe, expect, it } from 'vitest'
import { createAppI18n, currentI18n, i18n } from '@/i18n'
import { setRequestContextResolver } from '@/utils/requestContext'
import { formatDate, orderStatusLabel } from '@/utils/format'

const als = new AsyncLocalStorage<{ locale: string; i18n: unknown }>()
const tick = () => new Promise((r) => setTimeout(r, Math.random() * 5))

afterEach(() => setRequestContextResolver(null))

describe('request-scoped i18n (Nuxt SSR concurrency)', () => {
  it('without a request context (SPA / browser) the singleton is used — behaviour unchanged', () => {
    expect(currentI18n()).toBe(i18n)
  })

  it('concurrent requests in one process each format in THEIR OWN language, across awaits', async () => {
    setRequestContextResolver(() => als.getStore())
    const locales = ['id', 'en', 'zh', 'ar'] as const
    const results = await Promise.all(
      locales.flatMap((locale) =>
        Array.from({ length: 5 }, async () =>
          als.run({ locale, i18n: createAppI18n(locale) }, async () => {
            await tick()
            const label = orderStatusLabel('selesai')
            await tick()
            const date = formatDate('2026-11-18T00:00:00Z')
            await tick()
            return { locale, label, date, active: currentI18n().global.locale.value }
          }),
        ),
      ),
    )
    for (const r of results) expect(r.active).toBe(r.locale)
    const byLocale = (l: string) => new Set(results.filter((r) => r.locale === l).map((r) => `${r.label}|${r.date}`))
    for (const l of locales) expect(byLocale(l).size).toBe(1) // stable inside a request
    const labels = new Set(locales.map((l) => [...byLocale(l)][0]))
    expect(labels.size).toBeGreaterThan(1) // and different between languages
    // the shared singleton was never touched
    expect(i18n.global.locale.value).toBe('id')
  })
})
