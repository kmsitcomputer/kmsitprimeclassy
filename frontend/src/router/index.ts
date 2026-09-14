import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useInstallStore } from '@/stores/install'
import { captureReferralFromQuery } from '@/utils/referral'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  scrollBehavior(to, from, savedPosition) {
    return savedPosition ?? { top: 0 }
  },
  routes: [
    {
      path: '/install',
      name: 'installer',
      component: () => import('@/views/InstallerView.vue'),
    },
    {
      path: '/',
      name: 'home',
      component: () => import('@/views/HomeView.vue'),
    },
    {
      path: '/products',
      name: 'products',
      component: () => import('@/views/ProductListingView.vue'),
    },
    {
      path: '/categories',
      name: 'categories',
      component: () => import('@/views/CategoriesView.vue'),
    },
    {
      path: '/products/:slug',
      name: 'product-detail',
      component: () => import('@/views/ProductDetailView.vue'),
      props: true,
    },
    {
      path: '/cart',
      name: 'cart',
      component: () => import('@/views/CartView.vue'),
    },
    {
      path: '/wishlist',
      name: 'wishlist',
      component: () => import('@/views/WishlistView.vue'),
    },
    {
      path: '/articles',
      name: 'articles',
      component: () => import('@/views/ArticlesListingView.vue'),
    },
    {
      path: '/toko-kami',
      name: 'store-locator',
      component: () => import('@/views/StoreLocatorView.vue'),
    },
    {
      path: '/articles/:slug',
      name: 'article-detail',
      component: () => import('@/views/ArticleDetailView.vue'),
      props: true,
    },
    {
      path: '/page/:slug',
      name: 'page-detail',
      component: () => import('@/views/PageView.vue'),
      props: true,
    },
    {
      path: '/checkout',
      name: 'checkout',
      component: () => import('@/views/CheckoutView.vue'),
      // No requiresAuth: the wizard's own dynamic "account" step (active only
      // for guests, per GET /checkout/steps) prompts login/register in place
      // instead of bouncing the visitor away from checkout entirely.
    },
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/auth/LoginView.vue'),
      meta: { guestOnly: true },
    },
    {
      path: '/register',
      name: 'register',
      component: () => import('@/views/auth/RegisterView.vue'),
      meta: { guestOnly: true },
    },
    {
      path: '/profile',
      name: 'profile',
      component: () => import('@/views/ProfileView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/orders',
      name: 'orders',
      component: () => import('@/views/OrderHistoryView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/account/addresses',
      name: 'address-book',
      component: () => import('@/views/AddressBookView.vue'),
      meta: { requiresAuth: true, requiresAnyRole: ['konsumen'] },
    },
    {
      path: '/orders/:id',
      name: 'order-detail',
      component: () => import('@/views/OrderDetailView.vue'),
      props: (route) => ({ id: Number(route.params.id) }),
      meta: { requiresAuth: true },
    },
    {
      path: '/dashboard',
      name: 'dashboard-home',
      component: () => import('@/views/dashboard/DashboardHomeView.vue'),
      meta: {
        requiresAuth: true,
        requiresAnyRole: ['super_admin', 'agen', 'korsal', 'sales', 'admin', 'kurir', 'keuangan'],
      },
    },
    {
      path: '/dashboard/kurir',
      name: 'kurir-dashboard',
      component: () => import('@/views/dashboard/KurirDashboardView.vue'),
      meta: { requiresAuth: true, requiresAnyRole: ['kurir'] },
    },
    {
      path: '/dashboard/stock',
      name: 'stock-management',
      component: () => import('@/views/dashboard/StockManagementView.vue'),
      meta: {
        requiresAuth: true,
        requiresAnyPermission: ['stock.view.own', 'stock.manage'],
        requiresAnyRole: ['super_admin'],
      },
    },
    {
      path: '/dashboard/products',
      name: 'product-management',
      component: () => import('@/views/dashboard/ProductManagementView.vue'),
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin', 'agen'] },
    },
    {
      path: '/dashboard/products/create',
      name: 'product-create',
      component: () => import('@/views/dashboard/ProductFormView.vue'),
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin', 'agen'] },
    },
    {
      path: '/dashboard/products/:id/edit',
      name: 'product-edit',
      component: () => import('@/views/dashboard/ProductFormView.vue'),
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin', 'agen'] },
    },
    {
      path: '/dashboard/google-sheets',
      name: 'google-sheets',
      component: () => import('@/views/dashboard/GoogleSheetsView.vue'),
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin', 'agen', 'admin'] },
    },
    {
      path: '/dashboard/users',
      name: 'user-management',
      component: () => import('@/views/dashboard/UserManagementView.vue'),
      meta: { requiresAuth: true, requiresAnyPermission: ['users.view.all', 'users.view.network'] },
    },
    {
      path: '/dashboard/commissions',
      name: 'commissions',
      component: () => import('@/views/dashboard/CommissionsView.vue'),
      meta: {
        requiresAuth: true,
        requiresAnyRole: ['super_admin', 'agen', 'sales', 'korsal', 'kurir', 'admin', 'keuangan'],
      },
    },
    {
      path: '/dashboard/reports',
      name: 'reports',
      component: () => import('@/views/dashboard/ReportsView.vue'),
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin', 'agen', 'admin', 'keuangan'] },
    },
    {
      path: '/dashboard/finance',
      name: 'finance',
      component: () => import('@/views/dashboard/FinanceView.vue'),
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin', 'agen', 'admin', 'keuangan'] },
    },
    {
      path: '/dashboard/reports/customers',
      name: 'customers-report',
      component: () => import('@/views/dashboard/CustomersReportView.vue'),
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin', 'agen', 'admin', 'keuangan'] },
    },
    {
      path: '/dashboard/reports/korsal',
      name: 'korsal-report',
      component: () => import('@/views/dashboard/KorsalReportView.vue'),
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin', 'agen', 'admin', 'keuangan'] },
    },
    {
      path: '/dashboard/reports/sales',
      name: 'sales-report',
      component: () => import('@/views/dashboard/SalesReportView.vue'),
      meta: {
        requiresAuth: true,
        requiresAnyRole: ['super_admin', 'agen', 'admin', 'korsal', 'keuangan'],
      },
    },
    {
      path: '/dashboard/reports/couriers',
      name: 'courier-report',
      component: () => import('@/views/dashboard/CourierReportView.vue'),
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin', 'agen', 'admin', 'keuangan'] },
    },
    {
      path: '/dashboard/reports/orders',
      name: 'orders-report',
      component: () => import('@/views/dashboard/OrderReportView.vue'),
      meta: {
        requiresAuth: true,
        requiresAnyRole: ['super_admin', 'agen', 'admin', 'korsal', 'keuangan'],
      },
    },
    {
      path: '/dashboard/agent/payment-methods',
      name: 'agent-payment-methods',
      component: () => import('@/views/dashboard/AgentPaymentMethodsView.vue'),
      meta: { requiresAuth: true, requiresAnyRole: ['agen'] },
    },
    {
      path: '/dashboard/agent/shipping-providers',
      name: 'agent-shipping-providers',
      component: () => import('@/views/dashboard/AgentShippingProvidersView.vue'),
      meta: { requiresAuth: true, requiresAnyRole: ['agen'] },
    },
    {
      path: '/dashboard/reports/my-customers',
      name: 'sales-customers-report',
      component: () => import('@/views/dashboard/SalesCustomersReportView.vue'),
      meta: { requiresAuth: true, requiresAnyRole: ['sales'] },
    },
    {
      path: '/dashboard/agent/store-profile',
      name: 'agent-store-profile',
      component: () => import('@/views/dashboard/AgentStoreProfileView.vue'),
      meta: { requiresAuth: true, requiresAnyRole: ['agen'] },
    },
    {
      path: '/dashboard/agents',
      name: 'agent-contacts',
      component: () => import('@/views/dashboard/AgentContactsView.vue'),
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin'] },
    },
    {
      path: '/dashboard/website-settings',
      name: 'website-settings',
      component: () => import('@/views/dashboard/WebsiteSettingsView.vue'),
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin'] },
    },
    {
      path: '/admin/homepage-blocks',
      name: 'admin-homepage-blocks',
      component: () => import('@/views/admin/CmsHomepageBlocksView.vue'),
      meta: { requiresAuth: true, requiresPermission: 'system.cms.manage' },
    },
    {
      path: '/admin/homepage-blocks/create',
      name: 'admin-homepage-blocks-create',
      component: () => import('@/views/admin/CmsHomepageBlockFormView.vue'),
      meta: { requiresAuth: true, requiresPermission: 'system.cms.manage' },
    },
    {
      path: '/admin/homepage-blocks/:id/edit',
      name: 'admin-homepage-blocks-edit',
      component: () => import('@/views/admin/CmsHomepageBlockFormView.vue'),
      meta: { requiresAuth: true, requiresPermission: 'system.cms.manage' },
    },
    {
      path: '/admin/articles',
      name: 'admin-articles',
      component: () => import('@/views/admin/CmsArticlesView.vue'),
      meta: { requiresAuth: true, requiresPermission: 'system.cms.manage' },
    },
    {
      path: '/admin/articles/create',
      name: 'admin-articles-create',
      component: () => import('@/views/admin/CmsArticleFormView.vue'),
      meta: { requiresAuth: true, requiresPermission: 'system.cms.manage' },
    },
    {
      path: '/admin/articles/:id/edit',
      name: 'admin-articles-edit',
      component: () => import('@/views/admin/CmsArticleFormView.vue'),
      meta: { requiresAuth: true, requiresPermission: 'system.cms.manage' },
    },
    {
      path: '/admin/pages',
      name: 'admin-pages',
      component: () => import('@/views/admin/CmsPagesView.vue'),
      meta: { requiresAuth: true, requiresPermission: 'system.cms.manage' },
    },
    {
      path: '/admin/pages/create',
      name: 'admin-pages-create',
      component: () => import('@/views/admin/CmsPageFormView.vue'),
      meta: { requiresAuth: true, requiresPermission: 'system.cms.manage' },
    },
    {
      path: '/admin/pages/:id/edit',
      name: 'admin-pages-edit',
      component: () => import('@/views/admin/CmsPageFormView.vue'),
      meta: { requiresAuth: true, requiresPermission: 'system.cms.manage' },
    },
    {
      path: '/admin/media',
      name: 'admin-media',
      component: () => import('@/views/admin/MediaLibraryView.vue'),
      meta: { requiresAuth: true, requiresPermission: 'system.cms.manage' },
    },
    {
      path: '/admin/languages',
      name: 'admin-languages',
      component: () => import('@/views/admin/LanguagesView.vue'),
      meta: { requiresAuth: true, requiresPermission: 'system.cms.manage' },
    },
    {
      path: '/admin/audit-logs',
      name: 'admin-audit-logs',
      component: () => import('@/views/admin/AuditLogView.vue'),
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin'] },
    },
    {
      path: '/admin/payment-gateways',
      name: 'admin-payment-gateways',
      component: () => import('@/views/admin/AdminPaymentGatewaysView.vue'),
      meta: { requiresAuth: true, requiresPermission: 'system.payment.manage' },
    },
    {
      path: '/admin/shipping-settings',
      name: 'admin-shipping-settings',
      component: () => import('@/views/admin/AdminShippingSettingsView.vue'),
      meta: { requiresAuth: true, requiresPermission: 'system.shipping.manage' },
    },
    {
      path: '/admin/refunds',
      name: 'admin-refunds',
      component: () => import('@/views/admin/AdminRefundsView.vue'),
      meta: {
        requiresAuth: true,
        requiresAnyPermission: ['orders.manage.fulfillment', 'finance.refund.manage'],
      },
    },
    {
      path: '/admin/additional-payments',
      name: 'admin-additional-payments',
      component: () => import('@/views/admin/AdminAdditionalPaymentsView.vue'),
      meta: {
        requiresAuth: true,
        requiresAnyPermission: ['orders.manage.fulfillment', 'finance.additional.manage'],
      },
    },
    {
      path: '/admin/returns',
      name: 'admin-returns',
      component: () => import('@/views/admin/AdminReturnsView.vue'),
      meta: { requiresAuth: true, requiresPermission: 'orders.manage.fulfillment' },
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('@/views/NotFoundView.vue'),
    },
  ],
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
