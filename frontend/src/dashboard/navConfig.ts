import type { useAuthStore } from '@/stores/auth'

type Auth = ReturnType<typeof useAuthStore>

export type IconName =
  | 'dashboard'
  | 'box'
  | 'truck'
  | 'users'
  | 'cash'
  | 'document'
  | 'credit-card'
  | 'settings'
  | 'image'
  | 'map-pin'
  | 'user'
  | 'chart-bar'
  | 'shield'
  | 'globe'

export interface NavItem {
  key: string
  label: string
  routeName: string
  icon: IconName
  show: (auth: Auth) => boolean
}

export interface NavGroup {
  key: string
  label: string
  icon: IconName
  /** Tailwind text-color class applied to the group's own parent icon only — children stay neutral. */
  color: string
  items: NavItem[]
}

export type NavEntry = ({ type: 'item' } & NavItem) | ({ type: 'group' } & NavGroup)

const isRole = (auth: Auth, ...roles: string[]) => !!auth.user && roles.includes(auth.user.role)

/**
 * Single source of truth for the staff sidebar. Deliberately mixes
 * `auth.can(...)` (PermissionMap-driven) with direct role checks — several
 * pages here (products, reports, settings) have no PermissionMap capability
 * of their own, only a route-level role gate on the backend, so the nav
 * visibility hint has to mirror that directly. Exactly like PermissionMap
 * itself, none of this is a security boundary — every page re-checks with
 * the server regardless of whether it's shown here.
 *
 * A group is shown whenever at least one of its items would be; a group
 * with zero visible items simply never renders (see NAV_STRUCTURE consumers).
 */
const DASHBOARD_ITEM: NavItem = {
  key: 'dashboard',
  label: 'Dashboard',
  routeName: 'dashboard-home',
  icon: 'dashboard',
  show: (auth) => !auth.isKonsumen,
}

const ROLE_ITEMS: NavItem[] = [
  {
    key: 'kurir',
    label: 'Pengiriman Saya',
    routeName: 'kurir-dashboard',
    icon: 'truck',
    show: (auth) => isRole(auth, 'kurir'),
  },
  {
    key: 'orders',
    label: 'Order',
    routeName: 'orders',
    icon: 'box',
    show: (auth) => !auth.isKonsumen && !isRole(auth, 'kurir'),
  },
  {
    key: 'commissions',
    label: 'Komisi Saya',
    routeName: 'commissions',
    icon: 'cash',
    show: (auth) =>
      isRole(auth, 'super_admin', 'agen', 'sales', 'korsal', 'kurir', 'admin', 'keuangan'),
  },
]

export const NAV_GROUPS: NavGroup[] = [
  {
    key: 'catalog',
    label: 'Katalog',
    icon: 'box',
    color: 'text-amber-600 dark:text-amber-400',
    items: [
      {
        key: 'stock',
        label: 'Stok Produk',
        routeName: 'stock-management',
        icon: 'box',
        show: (auth) =>
          auth.can('stock.view.own') || auth.can('stock.manage') || isRole(auth, 'super_admin'),
      },
      {
        key: 'products',
        label: 'Produk & Kategori',
        routeName: 'product-management',
        icon: 'box',
        show: (auth) => isRole(auth, 'super_admin', 'agen'),
      },
    ],
  },
  {
    key: 'people',
    label: 'Pengguna',
    icon: 'users',
    color: 'text-sky-600 dark:text-sky-400',
    items: [
      {
        key: 'users',
        label: 'Pengguna',
        routeName: 'user-management',
        icon: 'users',
        show: (auth) => auth.can('users.view.all') || auth.can('users.view.network'),
      },
      {
        key: 'agents',
        label: 'Kontak Agen',
        routeName: 'agent-contacts',
        icon: 'map-pin',
        show: (auth) => isRole(auth, 'super_admin'),
      },
      {
        key: 'agent-store-profile',
        label: 'Profil Toko Saya',
        routeName: 'agent-store-profile',
        icon: 'map-pin',
        show: (auth) => isRole(auth, 'agen'),
      },
    ],
  },
  {
    key: 'reports',
    label: 'Laporan & Keuangan',
    icon: 'chart-bar',
    color: 'text-emerald-600 dark:text-emerald-400',
    items: [
      {
        key: 'reports',
        label: 'Laporan',
        routeName: 'reports',
        icon: 'chart-bar',
        show: (auth) => isRole(auth, 'super_admin', 'agen', 'admin', 'keuangan'),
      },
      {
        key: 'finance',
        label: 'Keuangan',
        routeName: 'finance',
        icon: 'cash',
        show: (auth) => isRole(auth, 'super_admin', 'agen', 'admin', 'keuangan'),
      },
      {
        key: 'customers-report',
        label: 'Laporan Pelanggan',
        routeName: 'customers-report',
        icon: 'users',
        show: (auth) => isRole(auth, 'super_admin', 'agen', 'admin', 'keuangan'),
      },
      {
        key: 'korsal-report',
        label: 'Laporan Korsal',
        routeName: 'korsal-report',
        icon: 'chart-bar',
        show: (auth) => isRole(auth, 'super_admin', 'agen', 'admin', 'keuangan'),
      },
      {
        key: 'sales-report',
        label: 'Laporan Sales',
        routeName: 'sales-report',
        icon: 'chart-bar',
        show: (auth) => isRole(auth, 'super_admin', 'agen', 'admin', 'korsal', 'keuangan'),
      },
      {
        key: 'courier-report',
        label: 'Laporan Kurir',
        routeName: 'courier-report',
        icon: 'truck',
        show: (auth) => isRole(auth, 'super_admin', 'agen', 'admin', 'keuangan'),
      },
      {
        key: 'orders-report',
        label: 'Laporan Order',
        routeName: 'orders-report',
        icon: 'box',
        show: (auth) => isRole(auth, 'super_admin', 'agen', 'admin', 'korsal', 'keuangan'),
      },
      {
        key: 'sales-customers-report',
        label: 'Konsumen Saya',
        routeName: 'sales-customers-report',
        icon: 'users',
        show: (auth) => isRole(auth, 'sales'),
      },
    ],
  },
  {
    key: 'payments-shipping',
    label: 'Pembayaran & Pengiriman',
    icon: 'credit-card',
    color: 'text-violet-600 dark:text-violet-400',
    items: [
      {
        key: 'returns',
        label: 'Return & Refund',
        routeName: 'admin-returns',
        icon: 'box',
        show: (auth) => auth.can('orders.manage.fulfillment'),
      },
      {
        key: 'additional-payments',
        label: 'Additional Payment',
        routeName: 'admin-additional-payments',
        icon: 'credit-card',
        show: (auth) =>
          auth.can('orders.manage.fulfillment') || auth.can('finance.additional.manage'),
      },
      {
        key: 'refunds',
        label: 'Daftar Refund',
        routeName: 'admin-refunds',
        icon: 'cash',
        show: (auth) => auth.can('orders.manage.fulfillment') || auth.can('finance.refund.manage'),
      },
      {
        key: 'payment-gateways',
        label: 'Payment Gateway',
        routeName: 'admin-payment-gateways',
        icon: 'credit-card',
        show: (auth) => auth.can('system.payment.manage'),
      },
      {
        key: 'shipping-settings',
        label: 'Shipping Provider',
        routeName: 'admin-shipping-settings',
        icon: 'truck',
        show: (auth) => auth.can('system.shipping.manage'),
      },
      {
        key: 'agent-payment-methods',
        label: 'Metode Pembayaran Saya',
        routeName: 'agent-payment-methods',
        icon: 'credit-card',
        // Admin manages their own agent's payment methods too — same
        // backend scope (role:agen,admin, agent_id-resolved).
        show: (auth) => isRole(auth, 'agen', 'admin'),
      },
      {
        key: 'agent-shipping-providers',
        label: 'Ekspedisi Saya',
        routeName: 'agent-shipping-providers',
        icon: 'truck',
        show: (auth) => isRole(auth, 'agen'),
      },
    ],
  },
  {
    key: 'cms',
    label: 'CMS',
    icon: 'image',
    color: 'text-rose-600 dark:text-rose-400',
    items: [
      {
        key: 'homepage-blocks',
        label: 'CMS Homepage',
        routeName: 'admin-homepage-blocks',
        icon: 'image',
        show: (auth) => auth.can('system.cms.manage'),
      },
      {
        key: 'articles',
        label: 'CMS Artikel',
        routeName: 'admin-articles',
        icon: 'document',
        show: (auth) => auth.can('system.cms.manage'),
      },
      {
        key: 'pages',
        label: 'CMS Halaman',
        routeName: 'admin-pages',
        icon: 'document',
        show: (auth) => auth.can('system.cms.manage'),
      },
      {
        key: 'media',
        label: 'Media',
        routeName: 'admin-media',
        icon: 'image',
        show: (auth) => auth.can('system.cms.manage'),
      },
      {
        key: 'languages',
        label: 'Bahasa',
        routeName: 'admin-languages',
        icon: 'globe',
        show: (auth) => auth.can('system.cms.manage'),
      },
    ],
  },
  {
    key: 'settings',
    label: 'Pengaturan',
    icon: 'settings',
    color: 'text-stone-600 dark:text-stone-400',
    items: [
      {
        key: 'google-sheets',
        label: 'Google Sheets Sync',
        routeName: 'google-sheets',
        icon: 'document',
        show: (auth) => isRole(auth, 'super_admin', 'agen', 'admin'),
      },
      {
        key: 'website-settings',
        label: 'Pengaturan Website',
        routeName: 'website-settings',
        icon: 'settings',
        show: (auth) => isRole(auth, 'super_admin'),
      },
      {
        key: 'audit-logs',
        label: 'Audit Log',
        routeName: 'admin-audit-logs',
        icon: 'shield',
        show: (auth) => isRole(auth, 'super_admin'),
      },
    ],
  },
]

/** Staff who also run the public storefront get a direct link back to it (same SPA, so a plain route). */
const VIEW_WEBSITE_ITEM: NavItem = {
  key: 'view-website',
  label: 'Lihat Website',
  routeName: 'home',
  icon: 'globe',
  show: (auth) => isRole(auth, 'super_admin', 'agen', 'korsal', 'sales'),
}

const PROFILE_ITEM: NavItem = {
  key: 'profile',
  label: 'Profil Saya',
  routeName: 'profile',
  icon: 'user',
  show: () => true,
}

/** Full ordered structure the sidebar renders: dashboard, role items, groups, profile. */
export function buildNavStructure(): NavEntry[] {
  return [
    { type: 'item', ...DASHBOARD_ITEM },
    ...ROLE_ITEMS.map((item) => ({ type: 'item' as const, ...item })),
    ...NAV_GROUPS.map((group) => ({ type: 'group' as const, ...group })),
    { type: 'item', ...VIEW_WEBSITE_ITEM },
    { type: 'item', ...PROFILE_ITEM },
  ]
}

export function groupHasVisibleItems(group: NavGroup, auth: Auth): boolean {
  return group.items.some((item) => item.show(auth))
}

/** Every leaf nav item (role items + every group's items), for consumers like the dashboard home quick-links grid that don't care about grouping. */
export function flattenNavItems(): NavItem[] {
  return [...ROLE_ITEMS, ...NAV_GROUPS.flatMap((group) => group.items), VIEW_WEBSITE_ITEM]
}
