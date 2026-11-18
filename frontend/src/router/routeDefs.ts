import type { RouteLocationNormalized, RouteMeta } from 'vue-router'

/**
 * Single source of truth for the route table (IMP-009) — METADATA ONLY (path, name, props, meta).
 *
 * Consumed by BOTH the Vue/Vite SPA (./routes.ts attaches the lazy view loaders and src/router/index.ts
 * installs the guard) and the Nuxt storefront (nuxt-storefront/app/router.options.ts), which re-uses these
 * records for the routes it owns (see ./boundary.ts) and turns every other record into a hand-off back to
 * the SPA. Deliberately free of any view import so the Nuxt build never bundles SPA-only (dashboard) code.
 * Do not fork this list.
 */

export interface RouteDef {
  path: string
  name: string
  props?: boolean | ((route: RouteLocationNormalized) => Record<string, unknown>)
  meta?: RouteMeta
}

export const routeDefs: RouteDef[] = [
    {
      // First-run backend installer wizard (fresh database only — the
      // global guard redirects here by NAME while the backend reports
      // uninstalled). Path is /setup: /install is the permanent public
      // PWA installation landing page (see 'pwa-install' below).
      path: '/setup',
      name: 'installer',
    },
    {
      // Permanent public PWA installation landing page. Shareable as
      // https://dev.primeccookies.com/install (DEV) and
      // https://primeccookies.com/install (production). Pure SPA route:
      // served through the existing history-mode fallback, no backend,
      // no special service-worker caching. Uses the central install
      // engine in src/pwa/installPrompt.ts — never an APK/download.
      path: '/install',
      name: 'pwa-install',
    },
    {
      path: '/',
      name: 'home',
    },
    {
      path: '/products',
      name: 'products',
    },
    {
      path: '/categories',
      name: 'categories',
    },
    {
      path: '/products/:slug',
      name: 'product-detail',
      props: true,
    },
    {
      path: '/cart',
      name: 'cart',
    },
    {
      path: '/wishlist',
      name: 'wishlist',
    },
    {
      path: '/articles',
      name: 'articles',
    },
    {
      path: '/toko-kami',
      name: 'store-locator',
    },
    {
      path: '/articles/:slug',
      name: 'article-detail',
      props: true,
    },
    {
      path: '/page/:slug',
      name: 'page-detail',
      props: true,
    },
    {
      path: '/checkout',
      name: 'checkout',
      // No requiresAuth: the wizard's own dynamic "account" step (active only
      // for guests, per GET /checkout/steps) prompts login/register in place
      // instead of bouncing the visitor away from checkout entirely.
    },
    {
      path: '/login',
      name: 'login',
      meta: { guestOnly: true },
    },
    {
      path: '/forgot-password',
      name: 'forgot-password',
      meta: { guestOnly: true },
    },
    {
      path: '/register',
      name: 'register',
      meta: { guestOnly: true },
    },
    {
      path: '/profile',
      name: 'profile',
      meta: { requiresAuth: true },
    },
    {
      path: '/orders',
      name: 'orders',
      meta: { requiresAuth: true },
    },
    {
      path: '/account/addresses',
      name: 'address-book',
      meta: { requiresAuth: true, requiresAnyRole: ['konsumen'] },
    },
    {
      path: '/orders/:id',
      name: 'order-detail',
      props: (route) => ({ id: Number(route.params.id) }),
      meta: { requiresAuth: true },
    },
    {
      path: '/print/shipments/:id',
      name: 'shipment-receipt-print',
      props: (route) => ({ id: Number(route.params.id) }),
      // Same roles as GET /shipments/{shipment}/receipt (ShipmentPolicy::printReceipt)
      // — keuangan/korsal/sales/konsumen never get this print permission.
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin', 'agen', 'admin', 'kurir', 'sales-kurir-sub', 'koordinator-kurir'] },
    },
    {
      path: '/dashboard',
      name: 'dashboard-home',
      meta: {
        requiresAuth: true,
        requiresAnyRole: ['super_admin', 'agen', 'korsal', 'sales', 'admin', 'kurir', 'sales-kurir-sub', 'gudang', 'keuangan', 'koordinator-kurir'],
      },
    },
    {
      path: '/dashboard/kurir',
      name: 'kurir-dashboard',
      meta: { requiresAuth: true, requiresAnyRole: ['kurir', 'sales-kurir-sub', 'koordinator-kurir'] },
    },
    {
      path: '/dashboard/stock',
      name: 'stock-management',
      meta: {
        requiresAuth: true,
        requiresAnyPermission: ['stock.view.own', 'stock.manage'],
        requiresAnyRole: ['super_admin', 'agen', 'admin', 'gudang'],
      },
    },
    {
      path: '/dashboard/warehouse',
      name: 'warehouse-stock',
      meta: { requiresAuth: true, requiresAnyRole: ['gudang', 'admin'] },
    },
    {
      path: '/dashboard/warehouse/transfers',
      name: 'warehouse-transfers',
      meta: { requiresAuth: true, requiresAnyRole: ['gudang', 'admin', 'agen'] },
    },
    {
      path: '/dashboard/warehouse/sub-locations',
      name: 'warehouse-sub-locations',
      meta: { requiresAuth: true, requiresAnyRole: ['agen', 'admin', 'gudang'] },
    },
    {
      path: '/dashboard/warehouse/sub-locations/:id',
      name: 'warehouse-sub-location-detail',
      meta: { requiresAuth: true, requiresAnyRole: ['admin', 'gudang'] },
    },
    {
      path: '/dashboard/warehouse/opnames',
      name: 'warehouse-opnames',
      meta: { requiresAuth: true, requiresAnyRole: ['gudang', 'admin', 'agen'] },
    },
    {
      // IMP-001 Gap 2: Gudang's "Order Diproses" work queue (server-side
      // scoped: status === 'diproses' AND no courier assigned yet).
      path: '/dashboard/warehouse/orders',
      name: 'warehouse-orders',
      meta: { requiresAuth: true, requiresAnyRole: ['gudang'] },
    },
    {
      // IMP-003: Koordinator-Kurir dispatch workspace (server-side scoped
      // same branch + diproses + no courier — see DispatchController).
      path: '/dashboard/dispatch',
      name: 'dispatch',
      meta: { requiresAuth: true, requiresAnyRole: ['koordinator-kurir', 'super_admin', 'agen', 'admin'] },
    },
    {
      path: '/dashboard/warehouse/returns',
      name: 'warehouse-returns',
      meta: { requiresAuth: true, requiresAnyRole: ['gudang'] },
    },
    {
      path: '/dashboard/sub-stock',
      name: 'sub-stock',
      meta: { requiresAuth: true, requiresAnyRole: ['sales-kurir-sub'] },
    },
    {
      path: '/dashboard/sub-stock/approvals',
      name: 'sub-stock-approvals',
      meta: { requiresAuth: true, requiresAnyRole: ['admin'] },
    },
    {
      path: '/dashboard/sub-stock/execution',
      name: 'sub-stock-execution',
      meta: { requiresAuth: true, requiresAnyRole: ['gudang'] },
    },
    {
      path: '/dashboard/products',
      name: 'product-management',
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin', 'agen', 'admin'] },
    },
    {
      path: '/dashboard/products/create',
      name: 'product-create',
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin', 'agen', 'admin'] },
    },
    {
      path: '/dashboard/products/:id/edit',
      name: 'product-edit',
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin', 'agen', 'admin'] },
    },
    {
      path: '/dashboard/google-sheets',
      name: 'google-sheets',
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin', 'agen', 'admin'] },
    },
    {
      path: '/dashboard/users',
      name: 'user-management',
      meta: { requiresAuth: true, requiresAnyPermission: ['users.view.all', 'users.view.network'] },
    },
    {
      path: '/dashboard/commissions',
      name: 'commissions',
      meta: {
        requiresAuth: true,
        requiresAnyRole: ['super_admin', 'agen', 'sales', 'sales-kurir-sub', 'korsal', 'kurir', 'admin', 'keuangan'],
      },
    },
    {
      path: '/dashboard/reports',
      name: 'reports',
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin', 'agen', 'admin', 'keuangan'] },
    },
    {
      path: '/dashboard/finance',
      name: 'finance',
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin', 'agen', 'admin', 'keuangan'] },
    },
    {
      // Improvement 10 / Owner-approved centralized Piutang menu (§8B): Admin +
      // Keuangan outstanding receivables list.
      path: '/dashboard/piutang',
      name: 'receivables',
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin', 'admin', 'keuangan'] },
    },
    {
      path: '/dashboard/reports/customers',
      name: 'customers-report',
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin', 'agen', 'admin', 'keuangan'] },
    },
    {
      path: '/dashboard/reports/korsal',
      name: 'korsal-report',
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin', 'agen', 'admin', 'keuangan'] },
    },
    {
      path: '/dashboard/reports/sales',
      name: 'sales-report',
      meta: {
        requiresAuth: true,
        requiresAnyRole: ['super_admin', 'agen', 'admin', 'korsal'],
      },
    },
    {
      path: '/dashboard/reports/couriers',
      name: 'courier-report',
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin', 'agen', 'admin', 'keuangan'] },
    },
    {
      path: '/dashboard/reports/orders',
      name: 'orders-report',
      meta: {
        requiresAuth: true,
        requiresAnyRole: ['super_admin', 'agen', 'admin', 'korsal'],
      },
    },
    {
      // Network transaction totals (Owner 2026-10-29 §7) — server enforces the
      // per-role network scope; this gate only mirrors the reachable roles.
      path: '/dashboard/reports/network',
      name: 'network-report',
      meta: {
        requiresAuth: true,
        requiresAnyRole: ['super_admin', 'agen', 'admin', 'korsal', 'sales', 'sales-kurir-sub'],
      },
    },
    {
      // Consolidated network summary — mirrors the backend gate exactly:
      // super_admin (agent picker), agen + keuangan (own branch). ADMIN is
      // deliberately NOT here: the per-agen table carries the agent-fee
      // rollup and admin must never see agent fee data.
      path: '/dashboard/reports/network-summary',
      name: 'network-summary',
      meta: {
        requiresAuth: true,
        requiresAnyRole: ['super_admin', 'agen', 'keuangan'],
      },
    },
    {
      path: '/dashboard/crm/funnel',
      name: 'crm-funnel',
      meta: {
        requiresAuth: true,
        requiresAnyRole: ['super_admin', 'agen', 'admin', 'korsal', 'sales', 'sales-kurir-sub'],
      },
    },
    {
      path: '/dashboard/agent/payment-methods',
      name: 'agent-payment-methods',
      // Admin manages their own agent's payment methods too — same backend
      // route group (role:agen,admin) and agent_id-scoped controller.
      meta: { requiresAuth: true, requiresAnyRole: ['agen', 'admin'] },
    },
    {
      path: '/dashboard/agent/shipping-providers',
      name: 'agent-shipping-providers',
      meta: { requiresAuth: true, requiresAnyRole: ['agen'] },
    },
    {
      path: '/dashboard/crm/customers',
      name: 'crm-customers',
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin', 'agen', 'admin', 'korsal', 'sales', 'sales-kurir-sub'] },
    },
    {
      path: '/dashboard/crm/customers/:id(\\d+)',
      name: 'crm-customer-detail',
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin', 'agen', 'admin', 'korsal', 'sales', 'sales-kurir-sub'] },
    },
    {
      path: '/dashboard/crm/customers/:id(\\d+)/order',
      name: 'crm-assisted-order',
      // Mirrors CrmAccess::canPlaceOrder: owner Sales/SKS, their Korsal, or the branch Agen. The server re-checks.
      meta: { requiresAuth: true, requiresAnyRole: ['agen', 'korsal', 'sales', 'sales-kurir-sub'] },
    },
    {
      path: '/dashboard/crm/follow-ups',
      name: 'crm-follow-ups',
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin', 'agen', 'admin', 'korsal', 'sales', 'sales-kurir-sub'] },
    },
    {
      path: '/dashboard/reports/my-customers',
      name: 'sales-customers-report',
      meta: { requiresAuth: true, requiresAnyRole: ['sales', 'sales-kurir-sub', 'korsal'] },
    },
    {
      path: '/dashboard/agent/store-profile',
      name: 'agent-store-profile',
      meta: { requiresAuth: true, requiresAnyRole: ['agen'] },
    },
    {
      path: '/dashboard/agents',
      name: 'agent-contacts',
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin'] },
    },
    {
      path: '/dashboard/agent/whatsapp',
      name: 'agent-whatsapp',
      meta: { requiresAuth: true, requiresAnyRole: ['agen', 'admin'] },
    },
    {
      path: '/dashboard/settings/integrations/smtp',
      name: 'smtp-settings',
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin'] },
    },
    {
      path: '/dashboard/notifications',
      name: 'admin-notifications',
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin', 'agen', 'admin', 'keuangan'] },
    },
    {
      path: '/dashboard/settings/integrations/whatsapp',
      name: 'whatsapp-gateway',
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin'] },
    },
    {
      path: '/dashboard/website-settings',
      name: 'website-settings',
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin'] },
    },
    {
      path: '/admin/homepage-blocks',
      name: 'admin-homepage-blocks',
      meta: { requiresAuth: true, requiresPermission: 'system.cms.manage' },
    },
    {
      path: '/admin/homepage-blocks/create',
      name: 'admin-homepage-blocks-create',
      meta: { requiresAuth: true, requiresPermission: 'system.cms.manage' },
    },
    {
      path: '/admin/homepage-blocks/:id/edit',
      name: 'admin-homepage-blocks-edit',
      meta: { requiresAuth: true, requiresPermission: 'system.cms.manage' },
    },
    {
      path: '/admin/articles',
      name: 'admin-articles',
      meta: { requiresAuth: true, requiresPermission: 'system.cms.manage' },
    },
    {
      path: '/admin/articles/create',
      name: 'admin-articles-create',
      meta: { requiresAuth: true, requiresPermission: 'system.cms.manage' },
    },
    {
      path: '/admin/articles/:id/edit',
      name: 'admin-articles-edit',
      meta: { requiresAuth: true, requiresPermission: 'system.cms.manage' },
    },
    {
      path: '/admin/pages',
      name: 'admin-pages',
      meta: { requiresAuth: true, requiresPermission: 'system.cms.manage' },
    },
    {
      path: '/admin/pages/create',
      name: 'admin-pages-create',
      meta: { requiresAuth: true, requiresPermission: 'system.cms.manage' },
    },
    {
      path: '/admin/pages/:id/edit',
      name: 'admin-pages-edit',
      meta: { requiresAuth: true, requiresPermission: 'system.cms.manage' },
    },
    {
      path: '/admin/media',
      name: 'admin-media',
      meta: { requiresAuth: true, requiresPermission: 'system.cms.manage' },
    },
    {
      path: '/admin/languages',
      name: 'admin-languages',
      meta: { requiresAuth: true, requiresPermission: 'system.cms.manage' },
    },
    {
      path: '/admin/audit-logs',
      name: 'admin-audit-logs',
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin'] },
    },
    {
      path: '/admin/shipping-settings',
      name: 'admin-shipping-settings',
      meta: { requiresAuth: true, requiresPermission: 'system.shipping.manage' },
    },
    {
      path: '/admin/google-auth',
      name: 'admin-google-auth',
      // Super Admin only — GoogleAuthSettingController authorizes
      // ('manage-system-config' — the server is the real gate).
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin'] },
    },
    {
      path: '/dashboard/agent/google-auth',
      name: 'agent-google-auth',
      // §11: only the Agen manages its OWN branch Google Auth config — Admin
      // and every other role must not receive this UI (route gate + backend).
      meta: { requiresAuth: true, requiresAnyRole: ['agen'] },
    },
    {
      // IMP-002: discount + voucher management (super_admin/agen/admin).
      path: '/dashboard/promo',
      name: 'promo-management',
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin', 'agen', 'admin'] },
    },
    {
      // IMP-002: dynamic invoice presentation config (super_admin global, agen branch).
      path: '/dashboard/invoice-config',
      name: 'invoice-config',
      meta: { requiresAuth: true, requiresAnyRole: ['super_admin', 'agen'] },
    },
    {
      path: '/admin/refunds',
      name: 'admin-refunds',
      meta: {
        requiresAuth: true,
        requiresAnyPermission: ['orders.manage.fulfillment', 'finance.refund.manage'],
      },
    },
    {
      path: '/admin/additional-payments',
      name: 'admin-additional-payments',
      meta: {
        requiresAuth: true,
        requiresAnyPermission: ['orders.manage.fulfillment', 'finance.additional.manage'],
      },
    },
    {
      path: '/admin/returns',
      name: 'admin-returns',
      meta: { requiresAuth: true, requiresPermission: 'orders.manage.fulfillment' },
    },
    {
      path: '/offline',
      name: 'offline',
      // Deliberately public: the branded offline state must render for any
      // visitor (authenticated or not) when the network is unreachable.
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
    },
]
