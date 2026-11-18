import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useInstallStore } from '@/stores/install'
import { captureReferralFromQuery } from '@/utils/referral'
import { routes } from './routes'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  scrollBehavior(to, from, savedPosition) {
    return savedPosition ?? { top: 0 }
  },
  routes,
})

router.beforeEach(async (to) => {
  // Capture ?ref= on ANY page a visitor lands on (home, a product page, an
  // agent's storefront link, etc.) — not just /register?ref= — so it
  // survives browsing before the visitor actually registers.
  captureReferralFromQuery(to.query)

  const install = useInstallStore()
  if (!install.checked) {
    await install.check()
  }

  if (!install.installed && to.name !== 'installer') {
    return { name: 'installer' }
  }

  if (install.installed && to.name === 'installer') {
    return { name: 'home' }
  }

  if (to.name === 'installer') {
    return true
  }

  const auth = useAuthStore()
  if (!auth.ready) {
    await auth.bootstrap()
  }

  if (to.meta.requiresAuth && !auth.isLoggedIn) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (to.meta.guestOnly && auth.isLoggedIn) {
    return { name: 'home' }
  }

  const requiredPermission = to.meta.requiresPermission as string | undefined
  if (requiredPermission && !auth.can(requiredPermission)) {
    return { name: 'home' }
  }

  const anyPermission = to.meta.requiresAnyPermission as string[] | undefined
  const anyRole = to.meta.requiresAnyRole as string[] | undefined
  if (anyPermission || anyRole) {
    const passesPermission = anyPermission ? anyPermission.some((perm) => auth.can(perm)) : false
    const passesRole = anyRole ? anyRole.includes(auth.user?.role ?? '') : false
    if (!passesPermission && !passesRole) {
      return { name: 'home' }
    }
  }

  return true
})

export default router
