/**
 * Cross-page referral persistence. A visitor may land on `/?ref=CODE` (or
 * any other page carrying that query param, e.g. via an agent's storefront
 * link) and then browse several pages before reaching /register — the raw
 * code is captured here so it survives that navigation. It is never trusted
 * as-is: RegisterView only ever uses it to pre-fill the form field, and the
 * backend re-resolves/validates the code from scratch at submission time
 * (see AuthController::register / ReferralService::resolveChainByCode).
 */
const STORAGE_KEY = 'pc_referral'
const EXPIRY_MS = 7 * 24 * 60 * 60 * 1000

interface StoredReferral {
  code: string
  storedAt: number
}

/** Called from the router's global guard on every navigation. */
export function captureReferralFromQuery(query: Record<string, unknown>): void {
  const ref = query.ref
  if (typeof ref !== 'string' || !ref.trim()) return

  try {
    const stored: StoredReferral = { code: ref.trim(), storedAt: Date.now() }
    sessionStorage.setItem(STORAGE_KEY, JSON.stringify(stored))
  } catch {
    // sessionStorage unavailable (private mode, storage blocked) — the
    // referral simply won't survive a page change, register?ref= still works.
  }
}

export function getPersistedReferralCode(): string | null {
  try {
    const raw = sessionStorage.getItem(STORAGE_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as StoredReferral
    if (!parsed?.code || Date.now() - parsed.storedAt > EXPIRY_MS) {
      sessionStorage.removeItem(STORAGE_KEY)
      return null
    }
    return parsed.code
  } catch {
    return null
  }
}

/** Called once registration succeeds, so a stale code never sticks to the next visitor on a shared device. */
export function clearPersistedReferral(): void {
  try {
    sessionStorage.removeItem(STORAGE_KEY)
  } catch {
    // ignore
  }
}
