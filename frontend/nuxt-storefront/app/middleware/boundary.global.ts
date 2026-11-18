import { captureReferralFromQuery } from '@/utils/referral'

/**
 * Routing boundary guard (see src/router/boundary.ts).
 * - Captures ?ref= on every navigation, like the SPA guard (referral must survive browsing).
 * - A navigation to a route the SPA owns is handed over as a FULL PAGE navigation (the SPA is a different
 *   build/bundle). This middleware is NOT an authorization layer: all auth/RBAC stays in the SPA guard and,
 *   authoritatively, in the Laravel backend.
 */
export default defineNuxtRouteMiddleware((to) => {
  const nuxtApp = useNuxtApp()
  if (import.meta.client) captureReferralFromQuery(to.query)

  if (to.meta.spaOwned) {
    if (import.meta.client && !nuxtApp.isHydrating) {
      window.location.assign(to.fullPath)
      return abortNavigation()
    }
    // A SPA-owned URL reached the Nuxt server/hydration directly: the front proxy is misrouting it.
    // Fail closed instead of rendering anything (and never redirect to the same URL — that would loop).
    return abortNavigation(createError({ statusCode: 404, statusMessage: 'Not Found' }))
  }
})
