import { listLanguages } from '@/api/languages'
import { activeSupportedLocales } from '@/utils/localeSeo'
import type { LocaleCode } from '@/i18n'

/**
 * Languages the storefront may serve = backend-active languages (public GET /languages) ∩ UI catalogues.
 * Public, user-independent data, so one process-wide cache is safe; short TTL so an admin enabling/disabling a
 * language is picked up quickly. If the backend is unreachable only the default language is served (fail safe).
 */
const TTL_MS = 5 * 60 * 1000
const FAILURE_TTL_MS = 15 * 1000 // a failed lookup (default-only fallback) is retried quickly, never pinned for minutes
let cached: { at: number; value: LocaleCode[]; ttl: number } | null = null
let inflight: Promise<LocaleCode[]> | null = null

export async function getActiveLocales(): Promise<LocaleCode[]> {
  if (cached && Date.now() - cached.at < cached.ttl) return cached.value
  inflight ??= listLanguages()
    .then((languages) => ({ value: activeSupportedLocales(languages.map((l) => l.code)), ttl: TTL_MS }))
    .catch(() => ({ value: cached?.value ?? (['id'] as LocaleCode[]), ttl: FAILURE_TTL_MS }))
    .then(({ value, ttl }) => {
      cached = { at: Date.now(), value: value.length ? value : ['id'], ttl }
      return cached.value
    })
    .finally(() => {
      inflight = null
    })
  return inflight
}
