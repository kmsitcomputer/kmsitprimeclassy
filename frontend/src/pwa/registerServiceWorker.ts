import { ref } from 'vue'

/**
 * PWA service-worker registration (production builds only).
 *
 * Lifecycle (locked):
 * - Registered only when `import.meta.env.PROD` is true AND the browser
 *   supports service workers — normal Vite development never registers.
 * - NEVER calls skipWaiting automatically. A waiting worker only activates
 *   after the user explicitly taps "Muat ulang" in the update banner, which
 *   posts SKIP_WAITING and reloads once on `controllerchange`.
 * - No credentials, profiles, or API data are handled here — the worker
 *   caches user-neutral shell assets only (see src-pwa/sw.js).
 */

const updateAvailable = ref(false)
let waitingWorker: ServiceWorker | null = null
let updateApplied = false

function trackWaiting(registration: ServiceWorkerRegistration): void {
  const worker = registration.waiting
  if (worker) {
    waitingWorker = worker
    updateAvailable.value = true
  }
}

export function useServiceWorkerUpdate() {
  return { updateAvailable, applyUpdate }
}

export function registerServiceWorker(): void {
  if (!import.meta.env.PROD) return
  if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return

  window.addEventListener('load', () => {
    navigator.serviceWorker
      .register('/sw.js')
      .then((registration) => {
        trackWaiting(registration)
        registration.addEventListener('updatefound', () => {
          const installing = registration.installing
          if (!installing) return
          installing.addEventListener('statechange', () => {
            if (installing.state === 'installed' && registration.waiting) {
              trackWaiting(registration)
            }
          })
        })
      })
      .catch(() => {
        // Registration failure is non-fatal: the app works as a normal
        // website without the worker. Never surface to the user.
      })
  })
}

/**
 * Sends SKIP_WAITING to the waiting worker, then reloads exactly once when
 * the new worker takes control. Called ONLY from the update banner action.
 */
export function applyUpdate(): Promise<void> {
  if (updateApplied) return Promise.resolve()
  if (!waitingWorker) return Promise.resolve()
  updateApplied = true
  return new Promise((resolve) => {
    const onControllerChange = () => {
      navigator.serviceWorker.removeEventListener('controllerchange', onControllerChange)
      window.location.reload()
      resolve()
    }
    navigator.serviceWorker.addEventListener('controllerchange', onControllerChange)
    waitingWorker?.postMessage('SKIP_WAITING')
    // Safety net: if no controllerchange arrives (edge cases), resolve
    // without reloading rather than hanging the UI.
    window.setTimeout(() => {
      navigator.serviceWorker.removeEventListener('controllerchange', onControllerChange)
      resolve()
    }, 10000)
  })
}
