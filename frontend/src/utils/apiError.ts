import { ApiError } from '@/api/client'

/**
 * `ApiError.message` alone is usually just the generic envelope message
 * ("Validasi gagal") — the actually useful, field-specific reason lives in
 * `errors` (Laravel's standard `{field: [messages]}` validation shape).
 * Surface those too so a 422 doesn't read as an unexplained failure.
 */
export function formatApiError(error: ApiError): string {
  if (!error.errors) return error.message
  const details = Object.values(error.errors).flat()
  if (!details.length) return error.message
  return `${error.message}: ${details.join(' ')}`
}
