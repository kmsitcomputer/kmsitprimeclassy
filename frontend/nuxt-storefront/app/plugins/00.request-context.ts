import { configureServerApi } from '@/api/client'
import { createAppI18n, i18n, type LocaleCode } from '@/i18n'
import { resolveRequestLocale } from '@/utils/localeSeo'
import { setRequestContextResolver } from '@/utils/requestContext'
import { deferStorageHydration } from '@/utils/storageHydration'
import { LOCALE_COOKIE } from '@/stores/locale'
import { getActiveLocales } from '../utils/activeLocales'

interface LocaleState {
  locale: LocaleCode
  fromQuery: boolean
  /** Languages this deployment may serve (backend-active ∩ UI catalogues). */
  active: LocaleCode[]
}

/**
 * Request-scoped localisation (runs first):
 *  - SERVER: resolve the locale of THIS request (`?lang=` > `pc-locale` cookie > default, validated against the
 *    backend-active languages), give the request its OWN vue-i18n instance (many requests render concurrently in one
 *    process — the SPA singleton must never be mutated here), and expose both through Nuxt's async context.
 *  - CLIENT: adopt exactly what the server rendered (via the payload) so hydration is identical.
 * The locale store then starts from that value on both sides (utils/storageHydration.ts).
 */
export default defineNuxtPlugin(async (nuxtApp) => {
  const state = useState<LocaleState>('pc-locale-state')

  // One resolver for the whole process; it answers from the request that is currently rendering.
  setRequestContextResolver(() => {
    const ctx = useNuxtApp().$pcCtx as { locale: string; i18n: unknown } | undefined
    return ctx ? { locale: ctx.locale, i18n: ctx.i18n } : undefined
  })

  if (import.meta.server) {
    // Must happen before the first API call below (this plugin runs before 01.api-and-ssr-data).
    configureServerApi(String(useRuntimeConfig().apiInternalBase))
    // Server: stores never read browser storage; the locale store starts from THIS request's locale (utils/storageHydration.ts).
    // The flag is constant (always true here), so sharing it between concurrent requests is safe.
    deferStorageHydration(true)
    const active = await getActiveLocales()
    const resolved = resolveRequestLocale({
      query: useRequestURL().searchParams.getAll('lang'),
      cookie: useCookie<string | null>(LOCALE_COOKIE, { default: () => null }).value,
      active,
    })
    state.value = { ...resolved, active }
    const instance = createAppI18n(resolved.locale)
    nuxtApp.vueApp.use(instance)
    return { provide: { pcCtx: { locale: resolved.locale, i18n: instance } } }
  }

  // Client: the server's decision, applied to the (single-user) browser instance before anything renders.
  const { locale } = state.value
  i18n.global.locale.value = locale
  deferStorageHydration(true, locale)
  nuxtApp.vueApp.use(i18n)
  return { provide: { pcCtx: { locale, i18n } } }
})
