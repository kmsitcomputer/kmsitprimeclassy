import type { Language } from '@/api/languages'

/**
 * CMS content (articles/pages) ships every translation row to the client —
 * unlike Product/Category, which the backend already resolves to a single
 * string via X-Locale (see api/types.ts). Public views must pick the row
 * matching the active locale themselves, falling back to the registered
 * default language, then to whichever translation happens to be first.
 */
export function pickTranslation<T extends { language_id: number }>(
  translations: T[],
  languages: Language[],
  localeCode: string,
): T | null {
  if (translations.length === 0) return null

  const activeLanguage = languages.find((l) => l.code === localeCode)
  if (activeLanguage) {
    const match = translations.find((t) => t.language_id === activeLanguage.id)
    if (match) return match
  }

  const defaultLanguage = languages.find((l) => l.is_default)
  if (defaultLanguage) {
    const match = translations.find((t) => t.language_id === defaultLanguage.id)
    if (match) return match
  }

  return translations[0] ?? null
}
