import { useAuthStore } from '@/stores/auth'
import { useCartStore } from '@/stores/cart'
import { useInstallStore } from '@/stores/install'
import { useLocaleStore } from '@/stores/locale'
import { useSiteStore } from '@/stores/site'
import { useUiStore } from '@/stores/ui'
import { useWishlistStore } from '@/stores/wishlist'
import { setupPwaInstallListeners } from '@/pwa/installPrompt'
import { registerServiceWorker } from '@/pwa/registerServiceWorker'
import { deferStorageHydration } from '@/utils/storageHydration'

/**
 * Browser start-up, run AFTER hydration (first Suspense resolve) so the first client render equals the server HTML (no mismatch):
 * restores the persisted theme/locale/cart/wishlist, wires PWA, then resolves the session exactly like the
 * SPA's router guard (install check, then GET /auth/me through the shared Sanctum-cookie client).
 */
export default defineNuxtPlugin((nuxtApp) => {
  // `app:mounted` can fire before the async page (Suspense) has finished hydrating; the FIRST
  // `app:suspense:resolve` is the point where the server-rendered tree is fully adopted.
  let started = false
  nuxtApp.hook('app:suspense:resolve', async () => {
    if (started) return
    started = true
    const pinia = nuxtApp.$pinia as never
    deferStorageHydration(false)
    const ui = useUiStore(pinia)
    ui.loadStoredTheme()
    ui.initTheme()
    useLocaleStore(pinia).initLocale()
    useCartStore(pinia).hydrateFromStorage()
    useWishlistStore(pinia).hydrateFromStorage()

    setupPwaInstallListeners()
    registerServiceWorker()

    const site = useSiteStore(pinia)
    if (!site.loaded) void site.load()

    const install = useInstallStore(pinia)
    if (!install.checked) await install.check()
    if (!install.installed) {
      // Backend not installed yet: the installer lives in the SPA.
      window.location.assign('/setup')
      return
    }
    const auth = useAuthStore(pinia)
    if (!auth.ready) await auth.bootstrap()
  })
})
