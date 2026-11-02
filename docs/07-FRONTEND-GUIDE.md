# 07 — Frontend Guide

Panduan frontend berdasarkan source code `frontend/`. Stack, struktur, routing, navigasi per role, dan mapping fitur → API.

---

## 1. Stack

| Komponen | Teknologi | Evidence |
|---|---|---|
| Framework | Vue 3 (Composition API) | `frontend/package.json` |
| Build | Vite ^8.1.5 + TypeScript ~6.0 | `frontend/vite.config.*` |
| Router | Vue Router ^5.2 (history mode, lazy loading semua view) | `src/router/index.ts` |
| State | Pinia ^4.0.2 (7 store) | `src/stores/` |
| i18n | vue-i18n — `id`, `en`, `ar`, `zh` | `src/i18n/locales/` |
| UI | Tailwind CSS ^4.3.3 + komponen lokal (`src/components/ui`) | `package.json` |
| Editor | CKEditor 5 (CMS) | `components/cms` |
| HTTP | Axios (`src/api/client.ts`) | — |
| PWA | Service worker + install prompt engine (`src/pwa/installPrompt.ts`) | `views/PwaInstallView.vue` |
| Maps | komponen maps (picker; opsional Google Maps) | `components/maps` |

Package manager resmi: **npm** (`package-lock.json` otoritatif; `pnpm-lock.yaml` untracked adalah stray legacy — jangan dipakai/dihapus tanpa instruksi Owner — G-14).

## 2. Layout & area aplikasi

| Layout | Area | View utama |
|---|---|---|
| `ShopLayout` | Storefront publik | Home, ProductListing/Detail, Categories, Cart, Checkout, Wishlist, Articles/Page, StoreLocator, PwaInstall, Offline |
| `AuthLayout` | Autentikasi | Login, Register, ForgotPassword |
| `DashboardLayout` | Panel internal (semua 10 role non-konsumen + keuangan) | 49 view `views/dashboard/*` + `views/admin/*` |
| (print) | Halaman cetak | `print/ShipmentReceiptView.vue` |

## 3. Guard routing (`src/router/index.ts`)

Urutan `router.beforeEach`:
1. `captureReferralFromQuery` (`?ref=` di halaman mana pun).
2. Install check (`stores/install`) — belum ter-install ⇒ paksa `/setup`; sudah ⇒ tolak `/setup`.
3. Auth bootstrap (`stores/auth`); `requiresAuth` ⇒ redirect login; `guestOnly` ⇒ home.
4. `requiresPermission` / `requiresAnyPermission` / `requiresAnyRole` ⇒ gagal → redirect home.

> Guard hanya **visibility/navigation**. Otorisasi sebenarnya selalu di backend (lihat [04-ROLES-PERMISSIONS](04-ROLES-PERMISSIONS.md)).

## 4. Daftar route utama (ringkas, 60+ route)

**Publik:** `/`, `/products`, `/products/:slug`, `/categories`, `/cart`, `/wishlist`, `/articles`, `/articles/:slug`, `/page/:slug`, `/toko-kami`, `/checkout` (guest diizinkan — langkah akun dinamis), `/login`, `/register`, `/forgot-password`, `/install` (PWA), `/offline`, `/setup` (installer), `/:pathMatch(.*)` (404).

**Konsumen (requiresAuth):** `/profile`, `/orders`, `/orders/:id`, `/account/addresses` (role konsumen).

**Cetak:** `/print/shipments/:id` (role: super_admin, agen, admin, kurir, sales-kurir-sub, koordinator-kurir — mirror `ShipmentPolicy::printReceipt`).

**Dashboard (role gate frontend):**

| Path | Role frontend |
|---|---|
| `/dashboard` | semua 10 role internal |
| `/dashboard/kurir` | kurir, sales-kurir-sub, koordinator-kurir |
| `/dashboard/stock` | super_admin, agen, admin, gudang |
| `/dashboard/warehouse` | gudang, admin |
| `/dashboard/warehouse/transfers` | gudang, admin, agen |
| `/dashboard/warehouse/sub-locations(/:id)` | agen/admin/gudang (detail: admin, gudang) |
| `/dashboard/warehouse/opnames` | gudang, admin, agen |
| `/dashboard/warehouse/orders` (antrian diproses) | gudang |
| `/dashboard/warehouse/returns` | gudang |
| `/dashboard/warehouse/fulfillment-proposals` | admin |
| `/dashboard/dispatch` | koordinator-kurir, super_admin, agen, admin |
| `/dashboard/sub-stock` / `approvals` / `execution` | sales-kurir-sub / admin / gudang |
| `/dashboard/products(/create/:id/edit)` | super_admin, agen, admin |
| `/dashboard/promo` | super_admin, agen, admin |
| `/dashboard/users` | permission `users.view.all/network` |
| `/dashboard/commissions` | 9 role |
| `/dashboard/reports` (+ `customers`, `korsal`, `sales`, `couriers`, `orders`, `network`, `network-summary`) | campuran sesuai backend (`network-summary`: super_admin, agen, keuangan — admin sengaja tidak) |
| `/dashboard/finance`, `/dashboard/piutang` | super_admin, admin, keuangan (piutang tanpa agen) |
| `/dashboard/crm/customers(/:id)(/order)`, `follow-ups`, `funnel` | scope CRM (assisted order: agen, korsal, sales, SKS) |
| `/dashboard/reports/my-customers` | sales, sales-kurir-sub, korsal |
| `/dashboard/agent/payment-methods` | agen, admin |
| `/dashboard/agent/shipping-providers` | agen |
| `/dashboard/agent/store-profile` | agen |
| `/dashboard/agent/google-auth` | agen |
| `/dashboard/agent/whatsapp` | agen, admin |
| `/dashboard/notifications` (Notification Center) | super_admin, agen, admin, keuangan |
| `/dashboard/google-sheets` | super_admin, agen, admin |
| `/dashboard/invoice-config` | super_admin, agen |
| `/dashboard/agents` (Kontak Agen) | super_admin |
| `/admin/homepage-blocks(/…)` , `/admin/articles(…)`, `/admin/pages(…)`, `/admin/media`, `/admin/languages` | permission `system.cms.manage` |
| `/admin/audit-logs` | super_admin |
| `/admin/shipping-settings` | permission `system.shipping.manage` |
| `/admin/google-auth` | super_admin |
| `/admin/refunds`, `/admin/additional-payments`, `/admin/returns` | permission fulfillment/finance |
| `/dashboard/settings/integrations/smtp`, `/dashboard/settings/integrations/whatsapp`, `/dashboard/website-settings` | super_admin |

## 5. Navigasi per role (visibility saja)

- **super_admin**: seluruh menu admin global + oversight cabang (`?agent_id=`) + dashboard.
- **agen**: dashboard cabang, produk, promo, stok, warehouse (read), komisi, laporan cabang, CRM read, pengaturan cabang (payment/shipping/store/WA/Google Auth/invoice), Sheets, notification center.
- **admin**: produk (CRUD tanpa delete), promo, stok, approval warehouse (transfer/opname/proposal/sub-stock/stock-request), order & fulfilment (SC-03 add-line), retur review/finalisasi, dispatch, laporan, piutang, notification center, WA + payment-method cabang.
- **keuangan**: finance, piutang, verifikasi pembayaran, laporan finansial, network summary, komisi (slice), notification center, refund ledger.
- **gudang**: antrian order diproses, warehouse stock/transfers/opnames/returns, sub-stock execution, proposal fulfilment, stok read, KPI gudang.
- **kurir**: `/dashboard/kurir` (antrian delivery, retur pickup), komisi own, KPI kurir.
- **koordinator-kurir**: dispatch workspace, assignment kurir, (executor self via profil kurir), komisi.
- **korsal**: laporan transaksi/sales/network, CRM downline read, my-customers, komisi tim, KPI.
- **sales / sales-kurir-sub**: CRM miliknya (customers/visits/follow-ups/assisted order), my-customers, komisi, KPI; SKS + `/dashboard/sub-stock` (stok sendiri) + `/dashboard/kurir`.
- **konsumen**: storefront + `/orders`, `/profile`, `/account/addresses`.

## 6. API client & mapping fitur → modul

`src/api/client.ts` — Axios; baseURL `VITE_API_URL` (kosong = same-origin); interceptor session/CSRF (panggil `/sanctum/csrf-cookie` sebelum write) + handler error seragam (`api/errors.ts`). Identitas request `secureUuid` untuk header `Idempotency-Key` (checkout/order).

44 modul domain (`src/api/`): auth, addresses, catalog, checkout, orders, payments, shipments, deliveries, dispatch, courier, returns, orderAdjustments, stock, warehouse, warehouseFulfillment, transfers, opnames, subStock, commissions, reports, promo, invoice, crm, agents, agentSettings, agentWhatsapp, whatsapp, adminNotifications, cms, cmsAdmin, cmsContent, languages, media, users, regions, settings, shippingAdmin, smtp, googleAuthSettings, auditLogs, install, errors, types.

Contoh pemetaan (evidence: file modul):

| Fitur frontend | Modul API | Endpoint backend |
|---|---|---|
| Checkout | `checkout.ts` | `POST /checkout/quote`, `courier-options`, `geocode`; `GET /checkout/steps` |
| Upload bukti & verifikasi | `payments.ts` | `POST /orders/{order}/payment/*`, `GET /payment-proofs/{id}` |
| Order & status | `orders.ts` | `GET/POST /orders`, `POST /orders/{order}/cancel`, `PATCH /orders/{order}/status` |
| Warehouse antrian | `warehouseFulfillment.ts` | `GET /warehouse/orders/diproses`, proposal/fulfillment |
| WA cabang | `agentWhatsapp.ts` | `/agent/whatsapp/*` |
| Notification Center | `adminNotifications.ts` | `/notifications/admin/*` |
| Invoice | `invoice.ts` | `GET /orders/{order}/invoice`, `/invoice/config` |

## 7. Store (Pinia)

| Store | Isi |
|---|---|
| `auth` | session user + role + capability hints (`auth.can()`), bootstrap |
| `cart` | keranjang (client-side, tanpa server cart) |
| `wishlist` | wishlist lokal |
| `site` | settings situs publik + kontak agen |
| `locale` | bahasa aktif |
| `install` | status installer backend |
| `ui` | state UI (toast, modal, dsb.) |

## 8. Komponen utama

`components/checkout` (wizard multi-langkah), `components/orders` (detail/riwayat/status), `components/dashboard` (widget panel), `components/whatsapp` (pairing QR, saklar event, test send), `components/cms` (blocks + CKEditor), `components/maps` (picker koordinat), `components/pwa` (install prompt), `components/ui` (basis design system), `components/shop` (storefront). Pengujian komponen: `__tests__` bersebar (lihat [14-TESTING-STATUS](14-TESTING-STATUS.md)).

## 9. PWA & offline

- `/install` = landing instalasi PWA publik (bukan installer backend) — memakai `src/pwa/installPrompt.ts`; tanpa APK/download.
- `/offline` render kondisi offline branded untuk semua visitor.
- Service worker + manifest + ikon derivatif favicon kanonis (`site_favicon_media_id`, lihat [13-DEPLOYMENT-OPERATIONS](13-DEPLOYMENT-OPERATIONS.md) §7).

## 10. Perintah

```bash
cd frontend
npm install
npm run dev          # dev server (Vite)
npm run type-check   # vue-tsc
npm run build-only   # vite build → dist/
npm run build        # type-check + build
```

Tidak ada test runner frontend otomatis; verifikasi = type-check + build + pemeriksaan browser manual.
