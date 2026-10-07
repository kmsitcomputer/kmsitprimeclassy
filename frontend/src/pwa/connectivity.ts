import { computed, ref } from 'vue'
import { ApiError } from '@/api/errors'
import { i18n } from '@/i18n'

/**
 * Centralized connectivity / mutation guard — the SINGLE authoritative
 * client-side boundary for offline behavior (PWA V1).
 *
 * Rules (locked):
 * - `navigator.onLine` contributes to UX state but is NOT proof the server
 *   is reachable. Real API outcomes (success vs no-response failure) drive
 *   the `serverUnreachable` signal; no `/up` heartbeat polling exists.
 * - Mutations (POST/PUT/PATCH/DELETE) are pre-blocked ONLY when the browser
 *   is certain there is no connectivity (`navigator.onLine === false`).
 *   Everything else is attempted against the real server, and any failure
 *   rejects honestly — offline state NEVER reports an operation as
 *   successful. The backend remains authoritative.
 * - No API payload, credential, profile, or permission is stored here —
 *   only boolean/counter UX signals.
 */

const MUTATION_METHODS = new Set(['post', 'put', 'patch', 'delete'])

export function isMutationMethod(method: string | undefined): boolean {
  return MUTATION_METHODS.has((method ?? 'get').toLowerCase())
}

/** Thrown when a mutation is blocked before touching the network. */
export class OfflineBlockedError extends ApiError {
  readonly offlineBlocked = true as const

  constructor() {
    super(resolveBlockedMessage(), 0, null)
    this.name = 'OfflineBlockedError'
  }
}

function resolveBlockedMessage(): string {
  try {
    return i18n.global.t('pwa.mutationBlocked') as string
  } catch {
    return 'Tidak ada koneksi ke server. Operasi ini memerlukan koneksi — tidak ada perubahan yang dikirim.'
  }
}

const browserOffline = ref(
  typeof navigator !== 'undefined' && typeof navigator.onLine === 'boolean'
    ? !navigator.onLine
    : false,
)
const consecutiveNetworkFailures = ref(0)

if (typeof window !== 'undefined') {
  window.addEventListener('online', () => {
    browserOffline.value = false
  })
  window.addEventListener('offline', () => {
    browserOffline.value = true
  })
}

/** Any HTTP response (including 4xx/5xx) proves the server is reachable. */
export function reportNetworkSuccess(): void {
  consecutiveNetworkFailures.value = 0
}

/** No response at all (DNS/TCP/timeout/abort) — the server was not reached. */
export function reportNetworkFailure(): void {
  consecutiveNetworkFailures.value += 1
}

const serverUnreachable = computed(() => consecutiveNetworkFailures.value > 0)

/** UX signal only — drives the offline banner, never blocks by itself. */
export const isOffline = computed(() => browserOffline.value || serverUnreachable.value)

export function useConnectivity() {
  return { isOffline, browserOffline, serverUnreachable }
}

/**
 * Called from the single Axios request interceptor. Throws
 * OfflineBlockedError for mutations when the browser is certain there is
 * no connectivity. Reads are never blocked here — they attempt the network
 * and fail honestly through the normal error path.
 */
export function assertOnlineForMutation(method: string | undefined): void {
  if (!isMutationMethod(method)) return
  if (typeof navigator !== 'undefined' && navigator.onLine === false) {
    throw new OfflineBlockedError()
  }
}
