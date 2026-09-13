import axios from 'axios'

// Runtime config (public/config.js, edited on the deployed server, no rebuild needed) wins
// over the build-time VITE_API_URL — see env.d.ts and README §"Production deployment".
// Deliberately `??`, not `||`: an empty string is a MEANINGFUL value — this app is
// single-domain, so '' means "same origin, relative requests" (the default in both dev
// and the deploy/ layout). `||` would wrongly treat that as unset and substitute a host.
const API_URL = window.__APP_CONFIG__?.API_URL ?? import.meta.env.VITE_API_URL ?? ''

/**
 * Sanctum SPA session auth: cookies carry the auth state (withCredentials),
 * axios auto-attaches the XSRF-TOKEN cookie as an X-XSRF-TOKEN header on
 * state-changing requests once `ensureCsrfCookie()` has run once per session.
 * There is no bearer token stored anywhere in this app — nothing to leak via
 * localStorage/XSS.
 */
export const http = axios.create({
  baseURL: `${API_URL}/api/v1`,
  withCredentials: true,
  // Same-origin in production and in dev (Vite proxies /api — see
  // vite.config.ts), but this stays explicit so a deliberately cross-origin
  // setup still forwards the XSRF-TOKEN cookie as a header.
  withXSRFToken: true,
  headers: { Accept: 'application/json' },
})

// Laravel/Sanctum already re-issues a fresh XSRF-TOKEN cookie on EVERY
// stateful response (not just this endpoint) — so by the time a user
// reaches any write action, at least one prior request (page load, the
// app's own auth/me bootstrap check, an earlier navigation) has already
// refreshed it. Explicitly re-fetching before every single write on top of
// that was tried and made things WORSE, not better: several near-
// simultaneous requests (the page's own bootstrap check, this fetch, the
// actual write) each set a session+token cookie pair in quick succession,
// and the browser/server could end up disagreeing about which pair is
// current — surfacing as the exact "CSRF token mismatch" this was meant to
// prevent. De-dupes CONCURRENT callers (several call sites firing in the
// same tick share one in-flight fetch); only actually used at explicit call
// sites (login/register/install) and by the response interceptor's retry
// below, never unconditionally before every request.
let csrfPromise: Promise<void> | null = null

export function ensureCsrfCookie(): Promise<void> {
  csrfPromise ??= axios
    .get(`${API_URL}/sanctum/csrf-cookie`, { withCredentials: true })
    .then(() => undefined)
    .finally(() => {
      csrfPromise = null
    })
  return csrfPromise
}

// Read directly from localStorage (not the Pinia locale store) so this module
// has no dependency on Pinia being installed yet — see stores/locale.ts,
// which writes the same key on every language switch.
http.interceptors.request.use((config) => {
  const locale = localStorage.getItem('pc-locale')
  if (locale) config.headers['X-Locale'] = locale
  return config
})

export interface ApiEnvelope<T> {
  success: boolean
  message: string
  data: T
  errors: Record<string, string[]> | null
  meta: Record<string, unknown> | null
}

export class ApiError extends Error {
  status: number
  errors: Record<string, string[]> | null

  constructor(message: string, status: number, errors: Record<string, string[]> | null) {
    super(message)
    this.status = status
    this.errors = errors
  }
}

http.interceptors.response.use(
  (response) => response,
  async (error) => {
    const status = error.response?.status ?? 0

    // Belt-and-suspenders on top of the request interceptor's unconditional
    // pre-fetch above: if a 419 still slips through (e.g. the token was
    // valid when read but the session moved on server-side between the
    // fetch and this request landing), refresh once more and retry silently
    // — never surface a "CSRF token mismatch" for something the user did
    // nothing wrong to cause.
    const config = error.config as (typeof error.config & { _retriedAfterCsrf?: boolean }) | undefined
    if (status === 419 && config && !config._retriedAfterCsrf) {
      config._retriedAfterCsrf = true
      await ensureCsrfCookie()
      return http(config)
    }

    const body = error.response?.data as ApiEnvelope<unknown> | undefined
    return Promise.reject(new ApiError(body?.message ?? 'Terjadi kesalahan jaringan.', status, body?.errors ?? null))
  },
)
