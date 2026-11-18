# RECON — FULL REPOSITORY AUDIT (PRIMECLASSY V2)

| | |
|---|---|
| **Tanggal audit** | 2026-11-18 |
| **Jenis** | Read-only, evidence-based, satu pass penuh |
| **Branch** | `fix/transaction-reset-financial-dependencies` |
| **HEAD** | `6a14041c06e6258c9f011fcb11271c7d34d65d17` |
| **Remote** | `https://github.com/kmsitcomputer/kmsitprimeclassy.git` |
| **Status working tree** | 63 modified tracked · 170 entri untracked (→ 363 file) · 0 deleted/staged |
| **Diff** | +2.146 / −630 pada 63 file |
| **Perubahan yang dilakukan** | **TIDAK ADA.** Tidak ada source, DB, migration, test, build, dependency, config, atau operasi Git yang dijalankan/diubah. |
| **Verdict** | **COMPLETE WITH GAPS** (§19) |

> Dokumen ini adalah artefak audit, bukan dokumen kanonis. Otoritas tetap `docs/01`–`docs/16` + `AGENTS.md`. Dokumen ini **belum di-commit** (untracked) sesuai aturan larangan operasi Git selama audit.

## Cara reproduksi bukti

Seluruh angka pada dokumen ini dapat diverifikasi ulang dengan perintah di **Lampiran A**. Semua klaim diberi label:

- **VERIFIED** — dibaca langsung dari file/command output pada audit ini.
- **INFERRED** — kesimpulan dari pembacaan kode, bukan eksekusi.
- **NOT VERIFIED** — ada di kode, tidak ada bukti runtime.
- **BLOCKED** — tidak dapat diverifikasi dari repository.

---

## 0. Ringkasan Eksekutif

PrimeClassy V2 adalah platform commerce multi-cabang (multi-Agent) yang **berjalan**: Laravel 11.56.1 + Sanctum SPA cookie session + MariaDB/MySQL, melayani SPA Vue 3.5 via single-domain (`public_html/laravel.php`). Logika bisnis dalam dan benar-benar di-enforce: 11 role terkunci, harga/stok/fee derivasi server, kebenaran pembayaran level-Order dengan satu writer, ledger refund immutable, kanonikalisasi shipment, domain inventori Agen/Sub dengan urutan lock terdokumentasi, dan ~2.026 method test.

### Temuan paling menentukan untuk arsitektur target

| # | Temuan | Severity | Status |
|---|---|---|---|
| **E-1** | **REM-11 (Historical POS) 100% belum di-commit.** `git ls-files` berisi **nol** path REM-11. 61 file source, 4 migration, 13 test backend, ~3.149 baris frontend hanya ada sebagai file untracked di disk. "Preserve REM-11 intact" **belum terpenuhi oleh Git** — satu `git clean -fdx` menghapusnya. | **BLOCKER** | VERIFIED |
| **E-2** | **Tracked tree tidak bisa di-build sendirian.** `frontend/src/router/index.ts:145-223` (tracked, modified) meng-import 13 modul REM-11 yang untracked; `dashboard/navConfig.ts:261-268` mereferensikan `hp-list`. Checkout bersih dari HEAD tanpa file untracked gagal `vue-tsc`/`vite build`. | **BLOCKER** | VERIFIED |
| **E-3** | **REM-11 tidak self-contained.** 20 pemanggilan `HistoricalOrderGuard::assertMutable()` tertanam di service baseline (Order/Payment/Refund/Fulfillment/Return/LineAddition); `PaymentService.php:1019-1030` membaca tabel REM-11 `historical_dossiers` dengan raw SQL dari jalur writer **baseline**. | **BLOCKER** | VERIFIED |
| **E-4** | **Manifest parallel full-test basi dan menolak jalan.** `manifest-verify` → `INVENTORY MISMATCH (181)` (manifest 209 PHP/36 Vitest di `fc8e340`; tree sekarang 238 PHP/59 Vitest). Harness-nya bagus, tapi tidak dapat dipakai tanpa regenerasi + re-approval. | **MAJOR** | VERIFIED (command dijalankan) |
| **E-5** | **Migrasi PostgreSQL punya blocker keras yang terkonsentrasi di ~10 file**, bukan tersebar: trigger MariaDB `ON DUPLICATE KEY UPDATE`/`<=>`, `JSON_EXTRACT`/`DATE_FORMAT` di SQL laporan, `GET_LOCK`, introspeksi `information_schema`+`DATABASE()`, `HAVING` atas alias select, 72 kolom `enum`, dan hilangnya case-insensitive uniqueness `utf8mb4_unicode_ci`. | **MAJOR (terbatas)** | VERIFIED |
| **E-6** | **Adopsi Nuxt hanya punya 2–3 blocker SSR keras** (`api/client.ts:19`, `stores/ui.ts:15`, `stores/locale.ts:18`), plus set `ClientOnly` yang terbatas. Codebase Vue sangat Nuxt-friendly: Composition API, Pinia, tanpa `async setup`, tanpa `<Suspense>`, tanpa `document.cookie`. | **MODERATE (terbatas)** | VERIFIED |
| **E-7** | **Invarian finansial nyata dan dapat diuji, tapi memakai dua model aritmetika**: kolom `decimal(12,2)`/`(14,2)` + float PHP `round(...,2)` untuk order/payment, dan integer-cents (`Services/Refund/Money.php`) hanya di dalam ledger refund. Port Go harus menyatukan di satu batas. | **MODERATE** | VERIFIED |
| **E-8** | **Dokumentasi drift dari source** pada beberapa angka terukur (migration 162→176, Feature test 191→232, policy 17→21, view 69→91, route "±914"→1027 baris, dan `docs/06 §1` masih menyebut registrasi 500 padahal `AuthController.php:60-67` sudah 503). Dokumen harus dikoreksi **sebelum** dipakai sebagai baseline acceptance Go/Nuxt. | **MODERATE (governance)** | VERIFIED |

### Kesimpulan

Migrasi **layak** dan risikonya **terkonsentrasi**, bukan menyebar. Tiga prasyarat menggerbangi semuanya:

1. **Commit/arsipkan REM-11** agar berhenti hanya hidup di disk.
2. **Putuskan 20 guard baseline→REM-11** plus raw read `historical_dossiers`.
3. **Regenerasi + re-approve manifest test** supaya ada jaring regresi sebelum port dimulai.

---

## 1. Lingkup dan Cakupan Audit

| Domain | Cakupan | Basis bukti |
|---|---|---|
| Git forensics | **Lengkap** | `git status --porcelain=v2`, `diff --stat`, `log`, `branch -a`, `ls-files`, `.git/info/exclude` |
| Stack teknologi | **Lengkap** | `composer.json`/`composer.lock` + `package.json`/`package-lock.json` (di-parse) |
| Frontend | **Lengkap** (91 view, 59 komponen, 47 modul API, 7 store, 97 route) | pembacaan langsung + grep seluruh tree |
| Backend | **Lengkap level modul**; semantik per-endpoint ditelusuri untuk seluruh alur finansial/stok/fulfilment; grup CRUD lain dinilai per grup | `routes/api_v1.php` (1027 baris) + controller/service |
| Matriks dependensi bisnis/finansial | **Lengkap** untuk alur yang diminta | `OrderService`, `PaymentService`, `RefundLedgerService`, `OrderFulfillmentService`, `CourierService`, `StockService`, `InvoiceNumberService`, `CommissionFinalizer`, `OrderTotalCalculator`, `PaymentSummaryService`, `CollectibleReceivable` |
| MariaDB→PostgreSQL | **Lengkap per konstruksi** (semua kelas `enum`/`json`/`decimal`/`unsigned`/`foreignId`/`change()`/`DB::statement`/trigger/generated/CHECK/collation dienumerasi); **matriks 30 tabel** kritis | migration, `database.sql`, `config/database.php`, grep raw SQL |
| Isolasi REM-11 | **Lengkap** (manifest, hunk campuran, dependensi DB, graf, kriteria verifikasi) | `git diff -U0/-U3`, enumerasi untracked |
| Testing | **Lengkap struktural**; **tanpa eksekusi** (dilarang) | `tools/parallel-test/**`, `tests/**`, `phpunit.xml`, `vitest.config.ts`, satu `manifest-verify` read-only |
| Keamanan/infrastruktur | **Lengkap untuk kontrol yang terlihat di repo**; **BLOCKED untuk runtime produksi** | middleware, policy, config, `deploy/**`, `public_html/.htaccess` |

### Omissions eksplisit (dinyatakan, bukan disembunyikan)

1. **Infrastruktur runtime produksi tidak ada di repo** (compose/Dockerfile produksi, nginx vhost produksi, grant DB, cron, monitoring). `deploy/production/` hanya berisi unit template queue. → **BLOCKED.**
2. **`backend/vendor/` dan `frontend/node_modules/` tidak di-CVE-scan**; hanya versi deklarasi/lock yang dibaca.
3. **Tidak ada verifikasi runtime apa pun** — perilaku backend adalah kesimpulan statis dari source.
4. **`docs/RECON-*`, `docs/REM-V2-*`, `docs/architecture-lock/**`** diinventarisasi dan dibaca sebagian (spot-read), tidak direlitigasi penuh — itu pekerjaan historis untracked, bukan otoritas (`AGENTS.md §2`).
5. **Matriks per-kolom PostgreSQL mencakup 30 tabel**; ~104 tabel lain dicakup per kelas konstruksi.

---

## 2. Keadaan Git / Repository

```
root     /Users/kmsitcomputer/KMSIT-Server/apps/primeclassy
branch   fix/transaction-reset-financial-dependencies
HEAD     6a14041c06e6258c9f011fcb11271c7d34d65d17
         "docs(release): correct production paths/DB in deployment runbook and record REL008"
         KMSIT Computer · Wed Nov 11 13:36:12 2026 +0700
upstream origin/fix/transaction-reset-financial-dependencies  (+0 / −0)
```

**Divergensi:** `main` lokal = `d7997c4`; `origin/main` = `6a14041` = HEAD. Branch **5 commit di depan `main` lokal**:

```
6a14041 docs(release): ... record REL008
5d34f25 docs: V2 workflow, shortage, invoice access and Full Test reports
bd241db test(tools): parallel full-test runner, manifest, discovery reconciliation
fc72588 feat(workflow): koordinator-gudang fulfilment, shortage reporting, partial courier assignment
fc8e340 fix: reset financial and notification transaction dependencies
```

Tidak ada stash. 18 branch lokal (beberapa branch release/remediation masih tertinggal).

### 2.1 Working tree (diukur ulang; angka lama basi)

| Kelas | Jumlah |
|---|---|
| Modified tracked (`1 .M`) | **63** |
| Untracked (`??`) | **170 entri → 363 file** saat diekspansi |
| Deleted / renamed / staged | **0** |
| Ukuran diff | **+2.146 / −630** |

### 2.2 Modified tracked, dikelompokkan per domain fungsional

| Domain | File |
|---|---|
| **REM-11 hunk campuran** (16) | `Models/Order.php`, `Services/Order/{OrderService,OrderFulfillmentService,OrderLineAdditionService,ReturnService}.php`, `Services/Payment/{PaymentService,AvailablePaymentMethodService}.php`, `Services/Invoice/InvoiceIssuanceService.php`, `Services/Refund/RefundLedgerService.php`, `Observers/WhatsAppLifecycleObserver.php`, `Http/Controllers/Api/V1/Agent/AgentPaymentMethodController.php`, `database/seeders/PaymentMethodSeeder.php`, `routes/api_v1.php`, `frontend/src/router/index.ts`, `frontend/src/dashboard/navConfig.ts`, `.phpunit-concurrency-actor.php`, `tests/Support/ConcurrencyHarness.php` |
| **REM-01/03/06/07/08 katalog & receipt** | `Catalog/{ProductController,ProductCategoryController,ProductImageController,ProductVariationController}.php`, `Policies/ProductCategoryPolicy.php`, `Services/Product/ProductVariationService.php`, `Services/Stock/StockOpnameService.php`, `Models/{ProductCategory,StockOpnameItem}.php`, `Http/Requests/Catalog/UpdateProductVariationRequest.php`, `Http/Resources/ProductVariationResource.php`, `Http/Controllers/Api/V1/Cms/HomepageController.php`, `Support/HomepageBlockTypes.php`, `Http/Middleware/EnsureAgentLinked.php` |
| **Storefront/CMS visual remediation** | `resources/views/pdf/invoice.blade.php` (+243), `frontend/src/assets/product-card.css`, `components/{shop/AppHeader,shop/ProductCard,cms/*}.vue`, `views/{Home,Categories,ProductDetail,ProductListing,Checkout}.vue`, i18n ×4 |
| **Dashboard/product UX** | `views/dashboard/{ProductFormView,ProductManagementView}.vue`, `views/admin/CmsHomepageBlockFormView.vue`, `api/{catalog,cms,orders,types}.ts` |
| **Dokumentasi** | `docs/PRIMECLASSY-WA-MGMT-KMSIT-OSRM-CHECKPOINT.md` (konten Owner, superseded kronologis — `docs/15 §5` menyatakan sengaja tidak disentuh) |

### 2.3 Untracked (363 file) per area

```
docs/unified-rem-evidence          58
backend/app                        52
frontend/src                       48
docs/phase5-t10-evidence           47
backend/tests                      29
docs/visual-card-category-evidence 19
docs/architecture-lock             19
docs/rem06-create-only-evidence    12
docs/design-references             10
backend/database                    9
docs/REM-V2-* (~35 laporan)        ~35
```

### 2.4 Dependensi yang tidak dapat dipisahkan dengan aman

- `routes/api_v1.php` mencampur route REM-11 dan baseline dalam satu file.
- `Models/Order.php:15` **mengubah di tempat** konstanta bersama `WRITE_ONCE`.
- `.phpunit-concurrency-actor.php` menyelang-selingkan actor REM-11 dengan actor REM-01/06 di baris 405–550.

### 2.5 Path ignored tapi kritis

`public_html/` di-exclude via **`.git/info/exclude:17`** (bukan `.gitignore`) — 510 entri aset + `laravel.php` + `config.js` hanya ada di disk. `frontend/dist/` di-ignore `frontend/.gitignore:12`. `backend/.env` dan `frontend/.env.local` untracked (diverifikasi `git ls-files` hanya memuat file `.env.example`).

---

## 3. Stack Teknologi Aktual

| Lapisan | Deklarasi | **Terkunci / aktual** | Bukti |
|---|---|---|---|
| PHP | `^8.2` | DEV 8.3; produksi 8 | `composer.json`, `docs/13` |
| Laravel | `^11.31` | **11.56.1** | `composer.lock` |
| Sanctum | `^4.0` | **4.3.3** | `composer.lock` |
| dompdf / PhpSpreadsheet / HTMLPurifier / google-auth | ^3.1 / ^5.9 / ^4.19 / ^1.50 | **3.1.2 / 5.9.0 / 4.19.0 / 1.53.0** | `composer.lock` |
| PHPUnit | `^11.0.1` | **11.5.56** | `composer.lock` |
| DB | MariaDB 10.11 DEV / MySQL 8.0.46 PROD | `utf8mb4` / `utf8mb4_unicode_ci`, `strict => true` | `config/database.php:45-63`, `docs/13:12` |
| Vue | `^3.5.40` | **3.5.42** | `package-lock.json` |
| Vue Router | `^5.2.0` | **5.3.1** (major v5, bukan v4) | `package-lock.json` |
| Pinia | `^4.0.2` | **4.0.3** | `package-lock.json` |
| Vite | `^8.1.5` | **8.2.2** | `package-lock.json` |
| TypeScript / vue-tsc | `~6.0.0` / `^3.3.7` | **6.0.3 / 3.3.11** | `package-lock.json` |
| Vitest | `^5.0.4` | 5.0.4 | `package-lock.json` |
| Tailwind | `^4.3.3` | 4.3.3 — **CSS-first (`@theme`), tanpa `tailwind.config.*`** | `assets/main.css:1-66` |
| CKEditor / Chart.js | 48.5 / 4.6.1 | sama | `package-lock.json` |
| Queue | `database` | lane `primeclassy-notify`, `default`; worker systemd produksi | `config/queue.php:16-42`, `deploy/production/primeclassy-queue.service` |
| Redis | key ada di `.env.example` | **terkonfigurasi tapi bukan default** cache/session/queue | `config/cache.php`, `session.php`, `queue.php` |
| Scheduler | **tidak ada** | `routes/console.php` = `inspire`; tidak ada `Schedule::` | VERIFIED |
| Events/Listeners | **tidak ada** | tidak ada `app/Events`, `app/Listeners` | VERIFIED |

**Tidak ada artefak Go, Nuxt, atau PostgreSQL di mana pun** — `go.mod`, `*.go`, `nuxt.config.*`, `*.pgsql` semuanya absen (hanya ada satu file Go tak terkait di dalam fixture `node_modules`).

---

## 4. Inventaris Modul Frontend

**Skala:** 155 `.vue` / 138 `.ts` di `src`; **91 view** (`views/` 20 storefront+auth, `views/dashboard` 47, `views/admin` 14, `views/historical-pos` 7, `views/auth` 3, `views/print` 1) · **59 komponen** · **47 modul API** · **7 store Pinia** · **4 layout** · **97 route** · **59 spec** · 4 locale (~2.800 baris) · service worker zero-dependency.

### 4.1 Storefront publik

`HomeView` (CMS block renderer) · `ProductListingView` · `ProductDetailView` (galeri variasi) · `CategoriesView` (pohon kategori) · `CartView` · `WishlistView` (localStorage) · `CheckoutView` (langkah dinamis, geolokasi, pemilih kurir, stock-source) · `OrderHistoryView` · `OrderDetailView` (payment tools, proof viewer, add-line) · `AddressBookView` · `ProfileView` · `Articles*` · `PageView` · `StoreLocatorView` · `PwaInstallView` · `OfflineView` · `NotFoundView` · `InstallerView` (`/setup`).

### 4.2 Dashboard (47 view, role-scoped)

Dashboard home (KPI + chart) · Kurir · Dispatch · keluarga stok/gudang (`StockManagement`, `WarehouseStock`, `WarehouseTransfers`, `WarehouseSubLocations(+Detail)`, `WarehouseOpnames`, `WarehouseReturns`, `OrderDiproses`) · sub-stock (`SubStock`, `SubStockApprovals`, `SubStockExecution`) · produk (`ProductManagement`, `ProductForm`) · users · commissions · finance (`Finance`, `Receivables`) · 8 view laporan + `ReportsView` · CRM (funnel/customers/detail/assisted-order/follow-ups) · setelan agen (payment methods, shipping providers, store profile, Google auth, WhatsApp) · `AgentContacts` · `AdminNotifications` · `GoogleSheets` · `WebsiteSettings` · `PromoManagement` · `InvoiceConfig` · `SmtpSettings` · `WhatsAppGateway`.

### 4.3 Admin (14 view)

CMS homepage blocks / articles / pages (3× list+form) · media library · languages · audit log · shipping settings · Google auth · refunds · additional payments · returns.

### 4.4 Komponen bersama (berdasarkan jumlah import — set reusable)

| Tier | Komponen |
|---|---|
| Core (≥9 pemakai) | `ui/AppIcon` **47**, `ui/AppButton` **37**, `ui/AgentPicker` **17**, `ui/PrimeFileUpload` 9, `ui/PasswordInput` 9 |
| Shared (2–5) | `ui/ImageUploader`, `ui/RichTextEditor`, `ui/StockBadge`, `ui/LanguageSwitcher`, `ui/WarehouseStockCardGrid`, `ui/SubStockCardGrid`, `ui/ProtectedFileViewer`, `ui/WarehouseProductPicker` (0 referensi), `shop/ProductCard`+`ProductCardBase`, `shop/StockSourceSelector`, `shop/CategoryTreeList`, `shop/CatalogCategoryTree`, `cms/HomepageBlockRenderer`, `maps/GoogleMapView` |
| Registry-driven | **16 `cms/blocks/*`** — hanya di-resolve lewat `HomepageBlockRenderer.vue:32-52` |

### 4.5 Lapisan API

`api/client.ts` — axios, `baseURL = ${API_URL}/api/v1`, `withCredentials`, `withXSRFToken`, **tanpa bearer token di mana pun**, `ensureCsrfCookie()` single-flight, header `X-Locale` dari `localStorage`, retry tunggal pada 419, `ApiError` seragam. **Tidak ada lapisan cache sisi klien.** Dua mekanisme ekspor berdampingan: `window.open` (XLSX laporan/CRM) dan blob+`createObjectURL` (ekspor katalog, proof viewer).

### 4.6 Cacat frontend (terbukti, tidak diperbaiki)

| ID | Sev | Temuan |
|---|---|---|
| FE-1 | **BLOCKER SSR** | `stores/ui.ts:15` dan `stores/locale.ts:18` membaca `localStorage` di dalam factory `state()` Pinia tanpa guard → `ReferenceError` pada render server |
| FE-2 | **BLOCKER SSR** | `api/client.ts:19` `window.__APP_CONFIG__` di module scope → crash saat import di server |
| FE-3 | **BLOCKER SSR** | `components/ui/LanguageSwitcher.vue:22-23` memanggil `document.addEventListener` di `setup()` |
| FE-4 | MAJOR | `/dashboard/piutang` (`ReceivablesView.vue`) render **tanpa** `DashboardLayout` — satu-satunya view dashboard tanpa sidebar/topbar |
| FE-5 | MAJOR | SEO nol: tanpa `robots.txt`, tanpa `sitemap.xml`, tanpa `<meta description>`/OG/canonical, **tanpa `useHead`/judul per-route di mana pun**; `site_seo_title/description/keywords` diambil (`stores/site.ts:26-32`) tapi tidak pernah dipakai |
| FE-6 | MAJOR | Tanpa code-splitting untuk CKEditor (eager di `RichTextEditor.vue:11,31`) dan Chart.js (`PcBarChart.vue:13-25`); 4 locale di-import statis (`i18n/index.ts:2-5`) |
| FE-7 | MINOR | Focus trap/restore/ESC **hanya** ada di `HpConfirmDialog.vue`; absen di `ProtectedFileViewer.vue:76`, `PwaInstallButton.vue:78`, `ProductListingView.vue:328`, `WhatsAppGatewayView.vue:485` |
| FE-8 | MINOR | 8 pemanggilan `toLocaleString` ad-hoc melewati pemetaan locale `utils/format.ts` |
| FE-9 | MINOR (higiene) | `frontend/.env.local` menyimpan Google Maps API key `AIza…` yang hidup (gitignored, untracked, ada di disk) |

### 4.7 Inventaris ketidakcocokan SSR (per kelas, dengan lokasi)

| Kelas | Jumlah | Contoh |
|---|---|---|
| `window` di module scope / `state()` | **1** | `api/client.ts:19` |
| `window` | 26 file | `pwa/*`, `api/reports.ts:220`, `views/ProductListingView.vue:145`, `views/auth/LoginView.vue:32` |
| `document` | 9 file | `stores/locale.ts:12-13`, `stores/site.ts:13`, `utils/googleMaps.ts:119,128`, `LanguageSwitcher.vue:22-23` |
| `navigator` | 9 file | `pwa/*`, `views/CheckoutView.vue:155-161`, `views/ProfileView.vue:51,61` |
| `localStorage`/`sessionStorage` | 12 file (5 guarded, 7 unguarded) | unguarded: `stores/ui.ts:15`, `stores/locale.ts:18` |
| `import.meta.env` | 9 site | `client.ts:19`, `api/auth.ts:85`, `router/index.ts:7`, `registerServiceWorker.ts:33` |
| Blob / objectURL | 8 file | `PrimeFileUpload.vue:103-110`, `ProtectedFileViewer.vue:27`, `EvidenceViewerView.vue:71` |
| Teleport | 4 file | `ProtectedFileViewer.vue:72`, `DashboardLayout.vue:324`, `ProductListingView.vue:327` |
| Lib berat client-only | 3 | Chart.js, CKEditor 5, Google Maps script injection |
| Fetch hanya `onMounted` | **~70 view** | `HomeView.vue:18`, `CheckoutView.vue:74,135`, seluruh `views/dashboard/**` |
| `async setup` / `<Suspense>` | **0** | — |

---

## 5. Inventaris Modul Backend

**Skala:** 546 file PHP di `app/` · **88 controller** · **63 form request** · **36 API resource** · **121 model** · **21 policy** · **151 file service** · 3 observer · 2 job · 3 mailable · 17 console command · 7 middleware · **`routes/api_v1.php` 1027 baris / 388 pemanggilan `Route::`** (29 publik, ~359 terautentikasi) + 2 route Google OAuth di `routes/web.php:15-19`.

### 5.1 Peta domain → controller → service → tabel

| Domain | Controller (jumlah route) | Service kanonis | Tabel utama |
|---|---|---|---|
| Install | `Install` (8) | `InstallLock`, `EnvFileWriter`, `SafeSchema` | menulis `.env`, `users/roles`, menjalankan `migrate` |
| Auth/identitas | `Auth, AccountRecovery, EmailVerification, GoogleAuth (web), WhatsAppNumber, Profile` (18) | `Auth/*`, `Consent/OtpChallengeService`, `User/UserManagementService` | `users`, `user_closures`, `user_social_identities`, `identity_otp_challenges`, `personal_access_tokens` |
| Katalog | `Product, ProductVariation, ProductImage, ProductCategory, Bestseller*, ProductManagement*, CatalogImportExport*` (~22) | `Product/{ProductVariationService,CategoryHierarchyService,ProductHardDeleteService}`, `Catalog/{BestsellerService,CatalogImportService,CatalogSpreadsheet}`, `Pricing/PricingService` | `products`, `product_variations`, `product_*_attributes`, `product_images`, `product_fees`, `catalog_skus`, `product_categories` |
| Checkout/Order | `Checkout, Geocode, Order, OrderFulfillment, DeliveryVerification, OrderItemReceipt*, ReturnReceiptAllocation*` (~20) | `Order/{OrderService,OrderTotalCalculator,OrderFilterService,OrderFulfillmentService,OrderLineAdditionService,ShipmentCanonicalizationService,InvoiceNumberService,SalesKurirSubEligibilityService}`, `Checkout/CheckoutStepResolver`, `Shipping/ShippingQuoteService`, `Order/{FulfillmentShortageService,DeliveryRefusalService,CourierService,InventoryCancellationService}` | `orders`, `order_items`, `shipments`, `stock_requests`, `order_item_receipts` |
| Pembayaran | `Payment` (9) | `Payment/{PaymentService,AvailablePaymentMethodService,PaymentSummaryService}` | `payment_transactions`, `bank_transfer_verifications`, `cod_payment_proofs`, `order_additional_payments`, `order_due_date_changes`, `order_invoice_snapshots` |
| Refund/Retur | `RefundLedger*, OrderAdjustment*, Return` (~24) | `Refund/{RefundLedgerService,CancellationFinancialService,OrderFinancialSnapshotService,RefundAuthorization,RefundMetrics,RefundRecipientGuard,Money}`, `Order/ReturnService`, `Commission/SalesFeeRefundAdjuster` | `refund_*`, `order_financial_snapshots(+units)`, `order_item_financial_bases`, `order_sales_reductions`, `commission_adjustments`, `returns`, `return_items` |
| Komisi/Fee | `Commission, Fee` (6) | `Commission/CommissionFinalizer`, `Fee/FeeService` | `commissions`, `product_fees`, `product_variation_fees` |
| Invoice | `Invoice, InvoiceConfig` (5) | `Invoice/{InvoiceIssuanceService,InvoicePdfService,InvoiceConfigService}` | `orders.order_no`, `order_invoice_snapshots`, `invoice_configs`, `settings` |
| Stok/Gudang | 12 controller, **~60 route** | `Stock/{StockService,SellableStockService,StockSourceResolver,WarehouseStockService,StockTransferService,StockOpnameService,StockRequestService,StockRequestProposalService,SubStockService,SubStockRequestService,WarehouseStockRequestService,SubLocationOwnershipService,PhysicalStockRevision}` | `product_stocks`, `product_variation_stocks`, `warehouse_stocks(+revisions)`, `stock_movements`, `warehouse_sub_locations`, `sub_stock_reservations`, `stock_transfers/items/handovers`, `stock_opnames/items`, `stock_requests*`, `sub_stock_requests*`, `warehouse_stock_requests` |
| Dispatch/Kurir | `Dispatch, Shipment, CourierDashboard, DeliveryRefusal` (~18) | `Order/CourierService`, `Order/DeliveryVerificationService` | `shipments`, `delivery_refusals`, `delivery_verifications`, `couriers`, `shipping_couriers` |
| Konfigurasi kirim | `ShippingProvider, ShippingCourier, AgentShippingProvider` (11) | `Shipping/{Providers/*,KurirTokoTariff,OpenRoute/Kmsit/Haversine,KmsitEndpointGuard}`, `Support/EndpointGuard` | `shipping_providers`, `shipping_configurations`, `agent_shipping_provider_*` |
| CRM/Consent | `Crm, CrmAssisted, Consent` (27) | `Crm/{CrmAccess,CrmLinkService,AssistedOrderService,AssistedReturnService,RefundRecipientService}`, `Consent/{ConsentService,ConsentAccess}` | `crm_customers`, `crm_visits`, `crm_customer_link_events`, `consent_events` |
| Laporan | `Report` (18), `ReportRegistry*` (5, REM-11), `DashboardKpi` | `Report/{ReportService,OrderInitialReportService,OrderPaymentPositionReportService,OrderTransactionReportService,NetworkTransactionReportService,OrderStaffWorksheetService,DashboardKpiService,CrmVisitReportService}`, `Support/CollectibleReceivable` | read-only + `order_initial_report_items` |
| CMS/Setelan/Media | `Homepage*, Article, Page, Media, Language, WebsiteSetting, SmtpSettings, GoogleAuthSetting` (~30) | `Cms/*`, `Media/MediaService`, `Setting/WebsiteSettingsService`, `Sanitizer/HtmlSanitizerService`, `Mail/SmtpSettings` | `cms_*`, `settings`, `media`, `languages`, `google_auth_settings` |
| Integrasi | `Sheets, WhatsAppGateway, AgentWhatsApp` (~26) | `GoogleSheets/*`, `WhatsApp/*`, `Notifications/*`, `Geocode/*` | `sheets_*`, `whatsapp_messages`, `email_messages`, `admin_notifications`, `agent_whatsapp_*` |
| Admin ops | `AuditLog, AdminNotification, RegionImportExport, User` (~14) | `Logging/ActivityLogger`, `Region/*`, `Export/ExcelExportService` | `activity_logs`, `regions` |
| **REM-11 (dikecualikan)** | 7 controller, 19 route | 11 service di `Services/HistoricalPos/` | 9 tabel (§8) |

### 5.2 Middleware (diverifikasi `bootstrap/app.php`)

- Global **prepend** `ReflectOriginBeforeInstall`; global **append** `PreventApiResponseCaching`.
- Group `api` **prepend** `EnsurePreInstallSafeDrivers`, `SetLocale`, `EnsureFrontendRequestsAreStateful`.
- Alias: `role`→`EnsureRole`, `agent.linked`→`EnsureAgentLinked`, `not.installed`→`EnsureNotInstalled`.
- CSRF exempt hanya `api/v1/install/*` (`bootstrap/app.php:92-94`); redirect guest dinonaktifkan.
- Exception terpusat → `ApiResponse` seragam (422/401/403/404/500).

### 5.3 Perilaku Laravel yang butuh padanan eksplisit di Go

1. **Sanctum SPA cookie session + double-submit CSRF** — guard `web`, `EnsureFrontendRequestsAreStateful` di group `api`, `/sanctum/csrf-cookie` di group `web`, CORS `supports_credentials`, cookie `XSRF-TOKEN` → header `X-XSRF-TOKEN`, session driver `database`, `SESSION_SECURE_COOKIE` default `true`, `same_site=lax`.
2. **Global Eloquent scope `BelongsToAgentScope`** pada ~30 model — narrowing tenant otomatis, dilewati untuk `super_admin` dan tanpa autentikasi (queue/console). Port Go harus membuat scoping tenant **eksplisit dan wajib**.
3. **21 Policy** auto-discovered + `Gate::define('manage-system-config')`.
4. **3 observer**: `UserObserver` (rebuild closure-table + invalidasi OTP saat nomor berubah), `WhatsAppLifecycleObserver` (5 model), `AdminNotificationObserver` (2 model) — ini **satu-satunya** titik pemicu side-effect notifikasi.
5. **Invarian `Order::booted()`** — XOR buyer origin + 12 field write-once (`Order.php:15,22-34`).
6. **Deteksi keberadaan skema** (`Order::supportsBuyerOrigin`, `ProductCategory::nameKeyColumnExists`, `WhatsAppMessage::ledgerCompatible`, `SafeSchema`) — pola "kode deployable sebelum migration". Di Go menjadi fitur yang digerbangi migration, bukan probe `information_schema` runtime.
7. **Deferral `DB::afterCommit`** (`OrderNotifier::defer`) — rollback tidak boleh mengirim notifikasi.
8. **Rate limiter bernama** (`recovery-request` dua bucket, `wa-number-*`, `email-verify-*`, `agent-wa-device`) + `throttle:N,M` per route.
9. **Storage disk** `local`→`storage/app/private`, `public`→`storage/app/public` + symlink `storage` di document root.
10. **dompdf** Blade view (`resources/views/pdf/invoice.blade.php`, rewrite 243 baris di working tree), **PhpSpreadsheet**, **google/auth** service-account JWT.
11. **Lokalisasi** — `lang/{id,en,ar,zh}`, `SetLocale` (`X-Locale` → `Accept-Language` → tabel `languages`), kontrak pesan 422 yang dikonsumsi SPA.
12. **Cast `decimal:2` Eloquent** (string) + footgun strict-comparison yang sudah didokumentasikan di cast.

### 5.4 Dead / legacy code (VERIFIED, nol referensi)

- `Http/Controllers/Api/V1/Auth/GoogleAuthController_old.php` (83 baris) — **0 referensi**.
- `Services/Auth/GoogleAuthService_old.php` (466 baris) — **0 referensi**.
- `Models/PaymentWebhookLog.php` + tabel `payment_webhook_logs` (ada kolom `signature_valid`) — **tanpa endpoint, tanpa writer**; gateway eksternal sudah dihapus Owner.
- `Auth/GoogleAuthController.php` di-route hanya via `routes/web.php`, tidak di `api_v1.php` (benar, by design).

---

## 6. Matriks Dependensi Bisnis dan Finansial

### 6.1 Verifikasi invarian LOCKED terhadap source

| Aturan terkunci (`docs/05`) | Realitas source | Verdict |
|---|---|---|
| §13 `paid_amount`/`remaining_amount`/`payment_status` **hanya** ditulis `PaymentService` | `PaymentService::recalculatePaymentStatus` (`:1078-1095`) adalah satu-satunya penulis *rekalkulasi* — **tetapi** `OrderService.php:319-323` menulis `dp_amount/paid_amount=0/remaining_amount=$total` saat pembuatan order DP, dan order dibuat dengan `paid_amount` 0 | **VERIFIED dengan deviasi terdokumentasi**: ada penulisan state awal di luar PaymentService (selalu 0/total). Harus dimodelkan eksplisit di Go sebagai "order dibuat → state pembayaran diinisialisasi". |
| §13 satu ringkasan kanonis | `PaymentSummaryService::summarize` (`:59-120`) — `verified_dp = min(dp, paid)`, `overpaid = max(0, paid−total)`, `remaining = CollectibleReceivable::forOrder` | **VERIFIED** |
| §20c AR = `GREATEST(total − paid, 0)`, 0 bila batal | `Support/CollectibleReceivable.php:22-39` (kembar PHP + SQL) | **VERIFIED** |
| §13/§26 kebenaran pembayaran level-Order vs ledger refund | `Money` cents hanya dipakai subsistem refund; order pakai `round(float,2)` | **VERIFIED — dua model aritmetika hidup bersama** |
| §29 nomor invoice = `order_no`, satu sequence, tidak pernah dipakai ulang | `InvoiceNumberService` (`D/Mmy#####`), sequence di baris `settings` di bawah `lockForUpdate`, 409 saat namespace habis | **VERIFIED** |
| §29 invoice terbit pada pembayaran terverifikasi pertama (COD saat dibuat) | `InvoiceIssuanceService::syncAfterChange` + call site `PaymentService.php:547,591,626,1044,1062`, `OrderService.php:399`, `OrderFulfillmentService.php:518` | **VERIFIED** |
| §15 komisi per item per tipe, lahir `pending` | `OrderService::recordCommission` (`:900-919`) agent+sales; `CourierService::writeCourierCommissions` (`:937-963`) dengan cek eksistensi | **VERIFIED** |
| §15 finalisasi saat `terkirim`, idempoten | `CommissionFinalizer::finalizeItems` (`:22-33`) `WHERE status='pending'`, satu call site `CourierService.php:933` | **VERIFIED** |
| §15a adjustment fee sales saat refund, baris negatif, tanpa double | `SalesFeeRefundAdjuster::adjustForProcessedReturn` (`:51-120`), `UNIQUE(commission_id, return_item_id)`, clamp | **VERIFIED** |
| §10 reserve/consume/release idempoten per item | `StockService::reserveFor*` di bawah `lockForUpdate`; `sub_stock_reservations.order_item_id` UNIQUE | **VERIFIED** |
| §11 kanonikalisasi shipment | `ShipmentCanonicalizationService` (296 baris), CHECK `Shipment` + 2 trigger (`2026_10_01_100000:42-79`) | **VERIFIED** |
| §16/§31 gerbang serah-terima dua pihak | `StockRequestProposalService::{propose,approve,receive,settle,resolveDiscrepancy}`; `pending→approved` sebagai gerbang tunggal | **VERIFIED** |
| §22 SC-03 admin-only | route `role:admin` (`api_v1.php:455-457`) + `OrderPolicy::addLine` | **VERIFIED** |
| §7 resolusi stock-source server-side | `StockSourceResolver`; `stock_source` klien hanya dibandingkan | **VERIFIED** |

### 6.2 Batas transaksi dan locking (diukur ulang)

- **314** kemunculan `lockForUpdate` di 53 file; **176** kemunculan `DB::transaction`.
- Urutan kanonis: **Order → Stock Request → target inventori** dalam urutan `StockService::canonicalReservationTargets` (`StockService.php:204-260`); domain Sub: `WarehouseSubLocation` → `WarehouseStock` Sub → baris reservasi.
- Hanya **satu** `sharedLock`: `OrderService.php:714` (baca Sub location).
- Setiap mutator pembayaran mengunci **baris verifikasi → Order → transaksi → obligation** (`PaymentService.php:400,413,417,468,653,700,771,898,991`).
- Ledger refund: **Order-first** pada 14 transisi (`RefundLedgerService.php:64,143,180,237,360,399,428,468,515,642,679,751,810,841`).

### 6.3 Inventaris idempotency

| Operasi | Mekanisme | Bukti |
|---|---|---|
| Buat order | header `Idempotency-Key` → `orders.idempotency_key` + `UNIQUE(konsumen_id,key)` dan `UNIQUE(crm_customer_id,key)`; unique violation ditangkap → replay | `OrderController.php:132-167`, migrasi `2026_09_06_090100:20`, `2026_10_30_100000:30`, `OrderService.php:417-425` |
| SC-03 add-line | `UNIQUE(order_id,idempotency_key)` + `request_fingerprint` `hash_equals` (replay identik 200 / berbeda 409) | `2026_10_02_100000:27`, `OrderLineAdditionService.php:223` |
| Pelunasan DP | replay request ekuivalen di bawah lock Order (tanpa transaksi baru) | `PaymentService.php:768-830` |
| Fulfilment stock request | `UNIQUE(stock_request_id,idempotency_key)` | `2026_09_17_100040:44` |
| Sub stock request | `UNIQUE(requested_by,idempotency_key)` | `2026_09_29_120000:44` |
| Delivery verification | `UNIQUE(verified_by,idempotency_key)` | `2026_10_01_100001:39` |
| Item receipt | `UNIQUE(verified_by,idempotency_key)` | `2026_11_13_100100:40` |
| Refund attempt | `UNIQUE(idempotency_key)`, `UNIQUE(obligation_id,attempt_no)`, `refund_ledger_events.event_key` UNIQUE | `2026_10_22_100004` |
| WA / email / in-app | `UNIQUE(idempotency_key)` / `UNIQUE(dedupe_key)` | `2026_10_31_100000:22`, `2026_11_05_100000:21` |

### 6.4 Kegagalan, retry, dan risiko duplikat

| Item | Perilaku | Risiko |
|---|---|---|
| `SendWhatsAppMessageJob` | `$tries=1`, klaim baris di bawah lock, retry terbatas dimiliki `WhatsAppDelivery`, `unknown` tidak pernah auto-retry, lane khusus | **Rendah** |
| `SendTransactionalEmailJob` | `$tries=1`, klaim kondisional `queued→sending`, switch dibaca ulang saat kirim | **Rendah** |
| Webhook pembayaran | **Tidak ada endpoint**; `payment_webhook_logs` + model adalah dead code | **Tidak ada** (gateway dihapus Owner) — dead code sebaiknya dibuang, bukan diport |
| Penanganan deadlock | Tanpa sleep, tanpa menelan 1213; urutan diperbaiki + regresi dua koneksi | **Rendah** |
| `RefundMetrics` / `RefundObligation::remaining()` | Menghitung sum **tanpa** lock internal | **Medium (INFERRED)** — kebenaran diwarisi dari konvensi Order-first; Go tidak boleh mengekspos ini sebagai read-model yang dapat dipanggil independen saat mutasi berjalan |
| `PaymentService::regularPaymentCountFor` | Baca tanpa lock untuk proyeksi ringkasan (didokumentasikan display-only) | **Rendah** |
| Finalisasi komisi vs adjustment refund bersamaan | Tabel berbeda (`commissions` vs `commission_adjustments`), keduanya idempoten | **Rendah** |

### 6.5 Isolasi multi-Agen

Di-enforce tiga lapis: global `BelongsToAgentScope` (query), gate route `role:` (kasar), dan re-cek ownership di policy/service (halus). Semua penggunaan `withoutGlobalScopes()` yang terverifikasi disertai cek agent eksplisit — `PaymentController.php:117-132` bahkan mendokumentasikan jebakan 500-vs-403. `EnsureAgentLinked` + `ValidAgentResolver` fail closed pada link agen yang basi/dihapus (`EnsureAgentLinked.php:29-42`).

---

## 7. Matriks Skema MariaDB dan Kompatibilitas PostgreSQL

### 7.1 Fakta skema (diukur ulang)

- **176 file migration → 134 tabel distinct** (+ `payment_gateway_configs` yang dibuat lalu di-drop).
- **`backend/database.sql` adalah artefak basi**: dump MySQL 8.4.11 dengan **54 tabel**, bertanggal 29 Sep, seed **7 role** (bukan 11) dan 2 bahasa. **79 tabel tertinggal** dari migration dan **tidak boleh** dipakai memandu konversi. **VERIFIED.**
- Sensus konstruksi kolom: `enum` **72 kolom / 49 file** (55 set nilai distinct, 26 nama kolom bersama) · `json` **23 kolom / 14 file** · `decimal` **80 kolom / 31 file** (`12,2` uang, `14,2` ledger refund, `10,7` geo) · `unsigned*` **132** · `foreignId` **352** · `->change()` **5 di 2 file** · `softDeletes` **3** (`users`, `products`, `product_variations`) · `->after()` **107** · `DB::statement` **64 di 23 file** · `timestamp` **116** (0 × `dateTimeTz`) · `fullText`/`spatialIndex` **0** · `ON UPDATE CURRENT_TIMESTAMP` **0**.
- CHECK constraint (DDL mentah, 5): `stock_transfer_items` XOR product/variation; `warehouse_stocks` sub-location type; `order_items_stock_source_consistent`; `shipments_delivery_mode_consistent`; `chk_product_categories_name_key_nonempty`.
- Generated column (3): `warehouse_stocks.target_identity` STORED, `.location_identity` STORED, `product_categories.root_name_key` VIRTUAL.
- Trigger: `shipments_self_sub_no_courier_{insert,update}` (`2026_10_01_100000:55-79`), `warehouse_stocks_revision_{insert,update,delete}` (`2026_11_14_110000:29-41`).
- Collation: `utf8mb4_unicode_ci` untuk seluruh tabel (`config/database.php:55`).

### 7.2 Matriks konversi — 30 tabel kritis

`id` = `bigint unsigned auto_increment` kecuali disebut lain. `→` = target PostgreSQL.

| Tabel | PK / FK | Unique & index | Uang & tipe berisiko | Target PG & risiko |
|---|---|---|---|---|
| `users` | `id`; role→roles RESTRICT; parent/agent/korsal/sales→users SET NULL; avatar→media SET NULL | **U email**, **U referral_code**, **U phone** | `status` enum(3) | `bigserial`; **U atas `lower(email)`/`lower(referral_code)`** untuk mempertahankan semantik CI; enum→`varchar + CHECK`. **TINGGI** |
| `roles` | `id` | U slug (CI saat ini) | — | 11 baris seed; CI unique penting. **MEDIUM** |
| `user_closures` | ancestor/descendant CASCADE | U(ancestor,descendant), idx(ancestor,depth) | — | `bigint` lurus; biaya rebuild closure. **MEDIUM** |
| `agent_profiles` | user CASCADE, U user_id | — | `10,7` geo | `numeric(10,7)`. **RENDAH** |
| `warehouse_stocks` | agent/product/variation + `sub_location_id` | U(agent,product,variation,stock_type) **+ U atas 2 generated col** + CHECK | qty integer | **Ganti 2 generated column dengan kolom ekspresi/plain + unique index; CHECK→`CHECK`; trigger revision→PL/pgSQL.** **TINGGI** |
| `warehouse_stock_revisions` | composite target key | unique target | counter `revision` | `ON CONFLICT … DO UPDATE`. **TINGGI** |
| `product_stocks` / `product_variation_stocks` | agent+target | U per pasangan | `quantity_on_hand`, `quantity_reserved` unsigned | `bigint + CHECK >= 0` untuk menjaga non-negativitas. **RENDAH→MEDIUM** |
| `stock_movements` | FK ×5 | idx ×4 | `type` **enum diperluas 4×** via `MODIFY` mentah | `varchar(40) + CHECK IN (…)`; enum churn menjadi tukar constraint. **TINGGI** |
| `products` / `product_variations` | category/product SET NULL / CASCADE | **U slug**, **U sku** | `12,2` unsigned; `status` enum(3); soft delete | CI U atas `lower(slug)`/`lower(sku)`; `numeric(12,2) + CHECK ≥ 0`. **MEDIUM** |
| `product_categories` | self parent SET NULL | U slug; **+ `name_key` UNIQUE(parent,name_key), `root_name_key` UNIQUE** (dari `2026_11_16_200000`, masih untracked) | `name_key` `utf8mb4_bin`, generated VIRTUAL | PG: `citext`/`COLLATE "C"` + partial unique index; generated→`GENERATED ALWAYS AS … STORED`. **TINGGI** |
| `orders` | 8 FK | **U order_no**, U(konsumen,idem), U(crm,idem), U(agent,historical_origin,historical_source_reference)*, idx ×7 | `12,2` unsigned ×5, `10,7` ×2; `status` enum(7), `payment_status` enum(7) | `numeric + CHECK ≥ 0`; enum→varchar+CHECK. *kolom REM-11. **TINGGI** |
| `order_items` | order CASCADE; product RESTRICT | U(order,idem) | `12,2` ×5; enum status(6), `stock_source`(2) + CHECK | Lurus + pertahankan CHECK. **TINGGI** |
| `payment_transactions` | order, method | U(payment_method_id, gateway_reference) dengan kolom **NULLable** | `12,2` unsigned; enum type(3), status(6) | Semantik NULL-distinct PG justru *cocok* dengan MySQL di sini — catat dan uji. **TINGGI** |
| `bank_transfer_verifications` | U payment_transaction_id | U | `12,2` ×3, enum(3) | Lurus. **MEDIUM** |
| `cod_payment_proofs` | U transaction, media RESTRICT | idx status | `collected_amount` (NULL = legacy) | Lurus. **MEDIUM** |
| `returns` / `return_items` | CASCADE / RESTRICT | idx | enum ×4 di `return_items` | Lurus. **MEDIUM** |
| `commissions` | order RESTRICT, item SET NULL, beneficiary RESTRICT | idx(beneficiary,status) | **`amount` bertanda** `12,2`; enum role(3), status(4) | Pertahankan **bertanda**; jangan tambah `CHECK ≥ 0`. **TINGGI** |
| `commission_adjustments` | commission RESTRICT | **U(commission_id, return_item_id)** | `14,2` bertanda | Lurus; kontrak baris negatif harus bertahan. **TINGGI** |
| `refund_obligations` | order RESTRICT, self-parent | **U obligation_no**, **U obligation_key(191)** | `14,2` unsigned ×4; 4 enum-ish | Lurus; unique `varchar(191)` aman di PG. **TINGGI** |
| `refund_attempts` | obligation RESTRICT | **U idempotency_key**, U(obligation,attempt_no) | `14,2`; 3 JSON | **TINGGI** |
| `refund_ledger_events` | nullable FK | **U event_key**, idx ×2 | JSON payload, `occurred_at` | Semantik append-only harus di-enforce Go, bukan konvensi. **TINGGI** |
| `order_financial_snapshots` (+units) | order **U** | idx | `14,2` ×2 + allocation checksum | **TINGGI** |
| `order_item_financial_bases` | order, item **U** | U(snapshot,item) | `14,2` ×7 | **TINGGI** |
| `order_invoice_snapshots` (+`invoice_configs`) | order CASCADE | U(order_id,version) | `12,2` ×3; `json config` | **Tidak ada tabel `invoices`** — identitas invoice = `orders.order_no` + versi snapshot. **TINGGI** |
| `shipments` | order RESTRICT (multi), courier SET NULL | idx courier/status | `10,7`×4, `8,2`; enum status(5), `delivery_mode`(2)+CHECK+2 trigger | `jsonb provider_meta`; trigger→PL/pgSQL. **TINGGI** |
| `stock_requests(+items,+fulfillments)` / `stock_request_proposals(+items)` | order/items | U(stock_request_id, idempotency_key) | `damaged_hold_qty`, accepted/short/damaged | Lurus. **MEDIUM** |
| `stock_transfers(+items,+handovers)` / `stock_opnames(+items)` / `sub_stock_requests(+items)` / `warehouse_stock_requests` | rantai | enum + XOR CHECK | — | Pertahankan CHECK. **MEDIUM** |
| `settings` | `key` UNIQUE | **nama kolom `key` dan `group` adalah reserved word PG** | longText `value` | Quote di mana pun; pertimbangkan rename di model Go. **MEDIUM** |
| `activity_logs` | morphs | idx ×4 | JSON `properties` | `jsonb`. **RENDAH** |
| `media` | created_by, morphs | idx | `size` unsigned bigint | `bigint + CHECK`. **RENDAH** |
| `whatsapp_messages` / `email_messages` / `admin_notifications` | — | **U idempotency_key / dedupe_key** | JSONB, payload terenkripsi | Lurus. **RENDAH** |
| `historical_*` (9) + `report_registry` | `agent_id` + rantai dossier, FK **masuk ke** `commissions`, `payment_transactions`, `stock_opname_items` | banyak nama eksplisit pendek | `12,2`/`14,2`, `json` | **Dikecualikan dari baseline (§8). Jangan dimigrasikan.** |

### 7.3 Blocker PostgreSQL, berperingkat (file:line)

1. **`JSON_EXTRACT` + `JSON_UNQUOTE`** → `OrderStaffWorksheetService.php:202-206` (5 kemunculan) → `->>`; jika terlewat, **hasil laporan salah secara senyap**. **TINGGI**
2. **`DATE_FORMAT`** → 10 site di 4 file (`OrderStaffWorksheetService.php:169,204,206,207`; `OrderInitialReportService.php:69`; `OrderTransactionReportService.php:51,58`; `OrderPaymentPositionReportService.php:60,71,73`) → `to_char()`. **TINGGI**
3. **`HAVING` atas alias SELECT** → `ReportService.php:589` `->havingRaw('korsal_id IS NOT NULL')` di mana `korsal_id` adalah alias `selectRaw` CASE. MariaDB menerima; **PostgreSQL menolak**. Harus dipindah ke `WHERE` atau subquery. (`OrderController.php:112` dan `DispatchController.php:315` aman — alias hanya di `ORDER BY`.) **TINGGI**
4. **Trigger MariaDB** → `2026_11_14_110000:29-41` (`ON DUPLICATE KEY UPDATE`, `<=>`, `IF()`, `CONCAT_WS`, `information_schema.TRIGGERS … DATABASE()`) dan `2026_10_01_100000:55-79`. Rewrite PL/pgSQL; counter revision saat ini hidup di DB, tidak terlihat oleh kode aplikasi. **TINGGI**
5. **`GET_LOCK`/`RELEASE_LOCK`** → `CategoryHierarchyService.php:45,72,139,176`, `2026_11_16_200000:49,98`, dan **harness test** (`TestRunLock`, `tools/parallel-test/php/db-lease.php`, `pctest/provision.py:50`) → `pg_advisory_lock`. **TINGGI**
6. **`information_schema` + `DATABASE()`** → `ProductHardDeleteService.php:38-39,115-118`, `ResetProducts.php:1293-1334`, `2026_10_19_100643:81-85`. Result set kosong secara senyap membuat **guard hard-delete produk lolos secara palsu** — ini risiko *kebenaran*, bukan sekadar pekerjaan porting. **TINGGI**
7. **72 kolom `enum` / 55 set nilai distinct** — pemetaan 1:1 `CREATE TYPE` mustahil tanpa rename; rekomendasi `varchar(n) + CHECK (col IN (…))` dengan daftar nilai diberi versi di Go. **TINGGI**
8. **Hilangnya case-insensitive uniqueness** (`utf8mb4_unicode_ci`): `users.email`, `users.referral_code`, `roles.slug`, `products.slug`, `product_variations.sku`, `orders.order_no`, `product_categories.slug`. Btree PG byte-wise ⇒ regresi duplikat email/slug. `AccountRecoveryService.php:168` dan `GoogleAuthService.php:228` sudah query `LOWER()`, tapi `AuthController` tidak. **TINGGI**
9. **`decimal … unsigned` (44) dan `unsigned*` (132)** tanpa padanan PG; non-negativitas bersifat load-bearing untuk kolom stok/finansial → `CHECK (col >= 0)`. **MEDIUM**
10. **`->change()` (5 di 2 file)** — `2026_10_19_100643:41,47,69,70`, `2026_10_30_100000:35`; tanpa `doctrine/dbal`; PG butuh cast `USING` eksplisit. **MEDIUM**
11. **`SET SESSION TRANSACTION READ ONLY`** (`CategoryUniquePreflight.php:19,25`) → `SET TRANSACTION READ ONLY`; **`AUTO_INCREMENT = 1`** (`DevTransactionResetService.php:137`) → `ALTER SEQUENCE … RESTART`. **RENDAH**
12. **`GREATEST`/`LEAST`** (5 di `CollectibleReceivable.php:32,38`, `SellableStockService.php:186`, `PaymentSummaryService`) — kompatibel PG. **TIDAK ADA**
13. **`insertOrIgnore`/`upsert`** (masing-masing 3 file) — Laravel mengemit `ON CONFLICT` di PG, tapi `upsert` **butuh** unique index yang cocok; verifikasi `provinces/regencies/districts/villages` sebelum cutover. **RENDAH**
14. **`settings.key` / `settings.group`** reserved word. **RENDAH**

### 7.4 Kebutuhan rekonsiliasi (finansial + inventori, dijalankan di kedua sisi)

- Per order: `total = subtotal − effective_discount + shipping + admin` (clamp ≥ 0); `remaining = total − paid` (≥ 0); `paid ≥ 0`; distribusi `payment_status`.
- Per set transaksi pembayaran: `SUM(baris paid type='payment') == orders.paid_amount`; baris `additional_payment` terpisah; payout refund rekonsiliasi ke baris `affects_paid_amount`.
- Per obligation refund: `SUM(succeeded attempts) ≤ amount`; `deferred_amount + paid == amount`; satu event ledger per transisi.
- Per snapshot: `subtotal_basis == SUM(unit gross)`; `SUM(allocated_discount) == effective discount`; `allocation_checksum` dihitung ulang.
- Per target stok: paritas `SUM(quantity)`; `reserved ≤ on_hand`; `stock_movements` rekonsiliasi ke saldo (pakai ulang `warehouse:reconcile`).
- Global: `roles` = 11; hash pair-set `user_closures`; kontinuitas tabel `migrations`.

---

## 8. Manifest Isolasi REM-11 dan Analisis Dependensi

### 8.1 Headline

**REM-11 tidak punya perlindungan Git sama sekali.** `git ls-files | grep -ci historical` → **0**; `| grep -c ReportRegistry` → **0**. Seluruh isi di bawah adalah konten untracked di disk.

### 8.2 Manifest file REM-11 lengkap (61 file source + dokumen)

| Grup | File | Status Git |
|---|---|---|
| Controller backend (7) + resource (1) | `Order/Historical{Preflight,Dossier,Evidence,Review,FinancialVerification,Admission}Controller.php`, `Report/ReportRegistryController.php`, `Http/Resources/HistoricalDossierResource.php` | **UNTRACKED** |
| Model backend (9) | `HistoricalDossier`, `HistoricalEvidence`, `HistoricalReviewAssertion`, `HistoricalStockCorrectionClaim`, `HistoricalPaymentRecognition`, `HistoricalCommissionSettlement`, `HistoricalFinancialVerification`, `HistoricalOpeningTreatment`, `ReportRegistry` | **UNTRACKED** |
| Service backend (11) | `Services/HistoricalPos/{HistoricalDossierPreflight, HistoricalDossierService, HistoricalEvidenceService, HistoricalReviewService, HistoricalVerificationService, HistoricalVerificationResolver, HistoricalCommissionProjector, ReportRegistryService, HistoricalAdmissionService, HistoricalPaymentMethodService, HistoricalAccessAudit}` | **UNTRACKED** |
| Support/policy/config (5) | `Support/HistoricalOrderGuard.php`, `Support/PayloadFingerprint.php`, `Policies/HistoricalDossierPolicy.php`, `Policies/ReportRegistryPolicy.php`, `config/historical_pos.php` | **UNTRACKED** |
| Migration (4) | `2026_11_14_120000_add_historical_identity_to_orders`, `2026_11_15_100000_create_historical_pos_phase2_foundation`, `2026_11_15_110000_create_historical_pos_phase3_foundation`, `2026_11_16_100000_add_historical_payment_transaction_link` | **UNTRACKED** |
| Test backend (13) | `HistoricalPreflightTest`, `HistoricalPosPhase2Test`, `Phase3{Verification,Registry,Concurrency,AuditConcurrency,AuditRemediation}`, `Phase4{Admission,AdmissionConcurrency,Migration}`, `Phase5W3{AccessAudit,Resolution}`, `HistoricalReviewConcurrencyTest` | **UNTRACKED** |
| Frontend (13 source + 10 spec) | `api/historicalPos.ts` (487 baris), `assets/historical-pos.css`, `layouts/HistoricalPosLayout.vue`, `components/historical-pos/{HpConfirmDialog,HpPipeline,HpStatusChip,tabKeys.ts}`, `views/historical-pos/{DossierList,DossierForm,DossierWorkspace,FinancialVerification,Admission,EvidenceViewer,ReportRegistry}View.vue` | **UNTRACKED** |
| Dokumen | 5 `PCV2-TC-REM11-*`, `RECON-CLOSURE-HISTORICAL-POS.md`, `docs/architecture-lock/**`, ~20 laporan `REM-V2-*` | **UNTRACKED** |

### 8.3 Inventaris hunk file campuran (REM-11 di dalam file tracked)

| File | Baris hunk (file baru) | Klasifikasi |
|---|---|---|
| `routes/api_v1.php` | 46-53 (import), 61 (import), **356-419 (19 route REM-11)** | Dapat dipisah bersih |
| `Models/Order.php` | **15 (baris `WRITE_ONCE` *diedit di tempat* — 8 token ditambahkan)**, 71-73 (cast) | **AMBIGU** |
| `Services/Order/OrderService.php` | 441-443 (tolak `historical_manual`), 1019-1022, 1145-1148 (guard) | REM-11 |
| `Services/Order/OrderFulfillmentService.php` | 65-68, 172, 556-559, 651 (guard) | REM-11 |
| `Services/Order/OrderLineAdditionService.php` | 63-65 (guard **sebelum** lock) | REM-11 |
| `Services/Order/ReturnService.php` | 50 (guard) | REM-11 |
| `Services/Payment/PaymentService.php` | 129, 171-174, 326, 414, 563, 654, 701, 773-774, 899, 992, 1041, 1058 (guard) · **1019-1030 (raw `DB::table('historical_dossiers')`)** | **AMBIGU / risiko tertinggi** |
| `Services/Payment/AvailablePaymentMethodService.php` | 38 (`code != 'historical_manual'` sebagai literal telanjang) | **AMBIGU** |
| `Services/Invoice/InvoiceIssuanceService.php` | 55-57 (`historical_origin !== null` → ineligible) | REM-11 |
| `Services/Refund/RefundLedgerService.php` | 1070 (guard) | REM-11 |
| `Observers/WhatsAppLifecycleObserver.php` | 47-49, 55-57 (suppress WA untuk historis) | REM-11 |
| `Http/Controllers/.../Agent/AgentPaymentMethodController.php` | 35, 57, 92 | **AMBIGU** |
| `database/seeders/PaymentMethodSeeder.php` | 29 (seed **`historical_manual` aktif**) | **AMBIGU — keputusan Owner** |
| `.phpunit-concurrency-actor.php` | 405-438, 480-497, 750-820, 1024 — **diselang-seling dengan actor REM-01/06 di 504-550** | **AMBIGU** |
| `tests/Support/ConcurrencyHarness.php` | 343 | **AMBIGU (rendah)** |
| `frontend/src/router/index.ts` | **145-223 (seluruh tree `/historical-pos`, 9 route)** | Dapat dipisah bersih |
| `frontend/src/dashboard/navConfig.ts` | 261-268 | REM-11 |
| `frontend/src/api/{types,orders}.ts`, `i18n/locales/*.ts` | — | **Tanpa konten REM-11** (terverifikasi) — jangan disentuh |
| `Http/Controllers/Api/V1/Catalog/*` | — | **Tanpa konten REM-11** (REM-01/03/06/08) — jangan disentuh |

### 8.4 Dependensi database

| Migration | Efek | Reversibilitas |
|---|---|---|
| `2026_11_14_120000` | **MENGUBAH `orders` baseline**: `historical_origin`, `historical_business_date`(+index), `historical_source_reference`, `historical_dossier_fingerprint`, `historical_requester_user_id` (FK→users RESTRICT), 2 snapshot, `historical_recorded_at`; **UNIQUE(agent_id, historical_origin, historical_source_reference)** | Aditif & `down()`-reversible **jika** tidak ada order historis yang diadmit |
| `2026_11_15_100000` | Membuat `historical_dossiers`, `historical_evidence`, `historical_review_assertions` | Aditif penuh |
| `2026_11_15_110000` | Membuat `historical_stock_correction_claims` (FK→`stock_opname_items`), `historical_payment_recognitions`, `historical_commission_settlements` (FK→`commissions`), `historical_financial_verifications`, `report_registry`, `historical_opening_treatments` | Aditif; FK masuk berada di sisi REM-11 |
| `2026_11_16_100000` | Menambah FK `historical_payment_recognitions.payment_transaction_id` (guard idempoten) | Aditif |

**Config/flag:** `config/historical_pos.php` → `verification_enabled` (default **false**), `admission_enabled` (default **false**). Tidak ada entri `HISTORICAL_POS_*` di `.env`/`.env.example`. Tidak ada registrasi service provider atau `Gate::policy` — kedua policy bergantung pada auto-discovery konvensi Laravel, sehingga **penghapusan tidak butuh edit provider**.

### 8.5 Graf dependensi (net)

- **REM-11 → baseline (outbound, ekstensif):** `PaymentService`, `InvoiceNumberService`, `OrderInitialSnapshotService`, `StockSourceResolver`, `StockService`, `StockOpname`, `FeeService`, `PricingService`, `ActivityLogger`, `Order/OrderItem/PaymentTransaction/Commission/User`, `OrderInitialReportService`, `OrderPaymentPositionReportService`, `ValidAgentResolver`. Plus 3 edge FK ke `commissions`, `payment_transactions`, `stock_opname_items`.
- **baseline → REM-11 (inbound, blocker penghapusan):**
  1. **`PaymentService.php:1019-1030` raw SQL ke `historical_dossiers`** — jalur writer baseline yang rusak bila tabel absen;
  2. **20 referensi kelas `HistoricalOrderGuard::assertMutable()`**;
  3. pembacaan kolom `historical_origin` di `InvoiceIssuanceService:55` dan `WhatsAppLifecycleObserver:47,55`;
  4. literal `historical_manual` di 5 tempat;
  5. `Order.php:15,71-73`;
  6. edit harness test.

### 8.6 Usulan isolasi aman (tidak dieksekusi)

1. **Preserve dulu, putuskan kemudian.** Karena tidak ada commit, buat artefak preservasi **di luar** working tree sebelum apa pun: snapshot `git stash create` **tidak memadai** (file untracked tidak ikut) — gunakan `tar` dari 61 file tersebut, atau orphan branch (`git checkout --orphan rem11-archive` → `git add -f` manifest → commit) yang merupakan satu-satunya opsi yang membuat "preserved intact" dapat diverifikasi di repo ini. **Owner/Architect harus memilih kendaraannya** (§18 D-1).
2. **Worktree rilis terisolasi** untuk baseline ke depan: buat worktree dari titik arsip, lalu terapkan patch ekstraksi.
3. **Patch ekstraksi (bagian mekanis):** hapus baris 46-53, 61, 356-419 `routes/api_v1.php`; hapus `router/index.ts:145-223`; hapus `navConfig.ts:261-268`; hapus 20 pemanggilan guard; hapus `Order.php:71-73` dan 8 token yang ditambahkan ke `WRITE_ONCE` (**pertahankan barisnya**).
4. **Bagian non-mekanis butuh Architect:** `PaymentService.php:1019-1030` (ganti pembacaan tabel dengan guard fail-closed `historical_origin !== null → 409`, atau hapus cabangnya), konsep payment method `historical_manual`, dan klasifikasi `PayloadFingerprint`.

### 8.7 Hunk ambigu yang butuh review Owner/Architect

| # | Item | Kenapa ini keputusan, bukan mekanik |
|---|---|---|
| A1 | `Order.php:15` konstanta write-once diedit di tempat | Revert per baris akan membuang tujuan baseline; harus edit token |
| A2 | `PaymentService.php:1019-1030` | Service baseline bergantung pada **tabel** REM-11; menghapus cabang mengubah perilaku error baseline untuk order historis |
| A3 | Payment method `historical_manual` (`PaymentMethodSeeder.php:29` + 5 guard) | Apakah *metode pembayaran* ini artefak REM-11 atau konsep akuntansi bersama? Seeder membuatnya **aktif** di setiap deployment saat ini |
| A4 | `AvailablePaymentMethodService.php:38` filter literal tanpa import kelas REM-11 | REM-11 secara perilaku, baseline secara gaya — ikut sisi mana? |
| A5 | `ConcurrencyHarness.php:343` | Baris test-support baseline diedit untuk marker REM-11 |
| A6 | `PayloadFingerprint.php` | Utility bersama bila ada fitur baseline (catalog import / receipts) yang memakainya |
| A7 | `/reports/registry` bersarang di bawah `/reports` baseline | Containment namespace |
| A8 | Dokumen REM-V2 dibagi antara REM-11 dan paket lain | Keputusan pemisahan dokumentasi |
| A9 | `docs/05 §31a` mereferensikan perilaku `§31` yang mencakup pekerjaan berdekatan REM-11 | Konfirmasi tidak ada teks aturan LOCKED yang harus dibawa ke baseline Go |

### 8.8 Kriteria verifikasi bahwa baseline bersih dari REM-11

**Source:** `grep -rn "HistoricalPos|HistoricalOrderGuard|HistoricalDossier|HistoricalEvidence|HistoricalReview|HistoricalVerification|HistoricalAdmission|HistoricalPaymentMethod|HistoricalCommission|HistoricalOpeningTreatment|HistoricalStockCorrection|ReportRegistry" backend/{app,routes,config,database,tests} frontend/src` → 0 hit. Idem untuk `historical_origin|historical_business_date|historical_source_reference|historical_dossier_fingerprint|historical_requester|historical_recorded_at|historical_manual|historical-pos|hp-list|report_registry`.

**Route table:** `php artisan route:list --json` memuat 0 dari 19 route REM-11; `router.getRoutes()` tidak memuat `/historical-pos`.

**Skema:** tabel `migrations` 0 baris untuk 4 migration REM-11; `information_schema` 0 dari 9 tabel; `orders` 0 kolom `historical_%` dan tanpa `orders_historical_source_unique`.

**Config:** `config('historical_pos') === null`; tanpa key env `HISTORICAL_POS_*`.

**Test:** 13 kelas REM-11 absen **dan** kelas baseline berikut tetap lulus tanpa perubahan: `OrderFulfillmentTest`, `OrderLineAdditionTest`, `ReturnSystemTest`, `PaymentGatewayRemovalTest`, `RefundLedger*` (8), `Invoice*` (5), `WhatsAppRestrictedDeliveryTest`, `ShipmentReceiptPrintTest`, `OrderStatusTransitionTest`.

**Data:** `SELECT COUNT(*) FROM orders WHERE historical_origin IS NOT NULL` = 0 (atau kolom absen); bergantung keputusan untuk `payment_methods.code='historical_manual'`.

**Frontend:** `npm run type-check` + `npm run build-only` lulus dengan 13 modul REM-11 dihapus — yang sekaligus membuktikan E-2 terselesaikan.

---

## 9. Harness Pengujian dan Penilaian Cakupan

**Skala (diukur ulang):** `tests/Feature` **232** file · `tests/Unit` **6** · `tests/Support` 7 + `bin/` probe · **~2.026 method `test*`** · frontend **59 spec / ~473 kasus** · **13 kelas REM-11 backend / 127 method** · 10 spec REM-11 frontend.

### 9.1 Arsitektur harness (`tools/parallel-test/`) — dinilai kuat

| Kontrol | Implementasi | Penilaian |
|---|---|---|
| Manifest + digest | `pctest/manifest.py:154,169` — digest atas `{workers, entries(test_id, sha256, lane, worker), frontend}` saja | Baik (content-addressed, imun terhadap churn timing) |
| Deteksi drift | `manifest-verify` → NEW / REMOVED / CHANGED_SHA256 / CHANGED_LANE / CHANGED_WORKER / CHANGED_PHPUNIT_XML / DIGEST_MISMATCH | **Bekerja — dan saat ini menyala** |
| Isolasi DB worker | per-worker `DB_DATABASE=primeclassy_testing_w1..w5`, storage/TMPDIR/cache/session/redis prefix unik, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, `DB_URL` dipaksa kosong (`guards.py:22-28,82-113`) | Kuat |
| Guard sisi aplikasi | `TestDatabaseGuard` allowlist nama eksak (tanpa pattern), host allowlist | Kuat |
| Serial lane | `exclusive_after_parallel` + barrier quiescence terverifikasi (tanpa PID milik run, tanpa container) | Kuat |
| Approval gate | `--i-am-the-owner` + frasa eksak + `--approve-manifest <prefix digest>` | Kuat |
| Fail-closed | lease (`GET_LOCK`) → discovery wajib (identity-set, bukan jumlah) → run → **proved** stop → aggregate; `STOPPED.json` menggerbangi PASS; tidak ada retry di mana pun | Kuat |
| Retry / timeout | kebijakan retry = `"none"`; worker 14.400 s, serial 21.600 s, frontend 1.800 s | Memadai |
| Evidence | `summary.json/md`, `junit/*.xml`, `discovery/`, log+artifact per worker | Baik |

### 9.2 Kegagalan/celah terverifikasi

| ID | Temuan |
|---|---|
| T-1 | **Manifest basi — harness tidak dapat jalan.** `manifest-verify` → `INVENTORY MISMATCH (181)` (209/36 di `fc8e340` vs 238/59 sekarang). Perlu regenerasi + re-approval. |
| T-2 | `docs/14-TESTING-STATUS.md:§5` menyebut 191 Feature file; aktual **232**. `docs/14 §7` menyatakan harness paralel "tidak pernah dijalankan", sementara `docs/13:198` dan `docs/PRIMECLASSY-V2-PARALLEL-FULL-TEST-FINAL-REPORT.md` mengutip run `4b6f27f7775b` **PASS 2.227/2.227**. **Dokumentasi bertentangan dengan dirinya sendiri.** |
| T-3 | `docs/15 §5` masih menyebut "full suite 855/6075/0" di satu baris dan 1.721 di baris lain — keduanya basi. |
| T-4 | Tidak ada jalur test PostgreSQL sama sekali: `phpunit.xml` memaku `DB_CONNECTION=mysql`, helper fingerprint memakai `CHECKSUM TABLE`/`information_schema_stats_expiry`, run lock memakai `GET_LOCK`. |
| T-5 | Tidak ada test kontrak frontend↔API: 59 spec bersifat komponen-lokal; tidak ada yang meng-assert ekspektasi SPA atas ~390 route. |
| T-6 | Tidak ada otomasi E2E/browser (tanpa Playwright/Cypress); UAT berupa checklist manual. |
| T-7 | Tidak ada bukti eksekusi self-test harness (`tools/parallel-test/tests/test_tooling.py` ada, `KNOWN-LIMITATIONS.md` menyatakan belum pernah dijalankan). |
| T-8 | Frontend **bukan** gerbang rilis secara kebijakan; hanya `type-check` + `build-only`. |

### 9.3 Penilaian cakupan

**Benar-benar tercakup:** checkout, pembayaran, ledger refund (invarian/IDOR/immutability/concurrency), invoice, komisi/fee, stok/gudang (43 file), RBAC/permission map, isolasi lintas-Agen, fulfilment/shortage/dispatch, WhatsApp/SMTP.
**Benar-benar tidak tercakup:** PostgreSQL (nol), Nuxt (nol), aritmetika uang frontend, kontrak API, E2E.

### 9.4 Strategi paritas Go/Nuxt/PostgreSQL — **golden-contract, bukan reimplementasi**

1. **Bekukan kontrak:** emit OpenAPI dari `routes/api_v1.php` (**tidak ada artefak `openapi`/`swagger`/Postman di repo saat ini**), plus envelope `ApiResponse` seragam dan key pesan 422 dari `lang/*`.
2. **Shadow-run** skenario identik terhadap Laravel/MariaDB dan stack baru; diff JSON ternormalisasi **dan** invarian finansial §7.4.
3. Pakai ulang `primeclassy:ar-reconciliation-report` dan `warehouse:reconcile` sebagai oracle pembanding.
4. Perluas harness dengan adapter manifest Go/Nuxt + lane `primeclassy_testing_pg(_wN)`; port `TestRunLock`/`db-lease` ke `pg_advisory_lock`; port fingerprint skema dari `CHECKSUM TABLE`.
5. **Gate:** digest manifest yang sama harus PASS di kedua stack sebelum cutover apa pun.

---

## 10. Temuan Keamanan dan Infrastruktur

| ID | Sev | Temuan | Bukti | Status |
|---|---|---|---|---|
| **S-1** | **MAJOR (kondisional)** | `ReflectOriginBeforeInstall` merefleksikan **Origin apa pun** ke `cors.allowed_origins` **dan** `sanctum.stateful` selama `InstallLock::isInstalled()` false. Lock-nya adalah **file** (`storage/app/installed.lock`) dan `deploy/build.sh` secara eksplisit **mengecualikannya** dari paket rilis. Jika lock hilang pada instalasi live (storage dipindah, direstore, atau salah exclude), request cross-origin ber-kredensial dari origin penyerang menjadi stateful. Endpoint tulis tetap terlindungi `hasSuperAdmin()`, jadi ini paparan permukaan CORS/session, bukan bypass autentikasi. | `ReflectOriginBeforeInstall.php:52-77`; `InstallLock.php:29-34`; `deploy/build.sh` (`--exclude 'storage/app/installed.lock'`) | VERIFIED (kode) / INFERRED (exploitability) |
| S-2 | MODERATE | **Tidak ada security header di repository**: tanpa `Content-Security-Policy`, `HSTS`, `X-Frame-Options`, `X-Content-Type-Options` (kecuali 2 respons CSV/XLSX), `Referrer-Policy`. Config nginx produksi tidak ada di repo, jadi mungkin ditambahkan di edge — **tidak dapat diverifikasi**. | grep atas `backend/app`, `backend/config`, `deploy/**`, `public_html/.htaccess` → hanya `CatalogSpreadsheet.php:213,235` | VERIFIED (absen) / BLOCKED (prod) |
| S-3 | MODERATE | **Tidak ada enforcement HTTPS di kode aplikasi** (tanpa `URL::forceScheme`, tanpa middleware `forceHttps`); bergantung penuh pada edge. | grep → 0 hit | VERIFIED |
| S-4 | RENDAH | `frontend/.env.local` memuat Google Maps API key hidup di disk (gitignored, untracked). Tidak pernah ter-commit, tapi ikut tersalin oleh build artefak yang naif. | `git ls-files` (absen) + baca file | VERIFIED |
| S-5 | RENDAH | Permukaan gateway pembayaran mati: tabel `payment_webhook_logs` + model `PaymentWebhookLog` dengan kolom `signature_valid`, tanpa endpoint, tanpa writer. Pembaca masa depan bisa salah mengira ada jalur verifikasi aktif. | model + migration, 0 referensi | VERIFIED |
| S-6 | INFO | `install/*` dikecualikan CSRF **permanen** (rasional terdokumentasi: setiap write disegel `not.installed` + `hasSuperAdmin`). Dapat dipertahankan, tetapi ini pengecualian permanen yang bergantung pada `installed.lock` (lihat S-1). | `bootstrap/app.php:92-94` | VERIFIED |
| S-7 | INFO | `api/v1/auth/google/{redirect,callback}` berada di group **`web`** (benar: state OAuth berbasis session), throttle 20/menit. | `routes/web.php:15-19` | VERIFIED |

### 10.1 Kontrol yang terverifikasi benar (jangan dilemahkan saat migrasi)

- **Auth:** cookie session Sanctum saja; **tanpa bearer token di sisi klien** (`client.ts:21-27`); CSRF double-submit dengan retry tunggal pada 419; `SESSION_SECURE_COOKIE` default `true`; `same_site=lax`; recovery enumeration-resistant dengan **dua** bucket (per-IP + hash per-identifier); Google OAuth state/nonce/PKCE 10 menit sekali pakai, token tidak dipersist.
- **Otorisasi:** dua lapis (route `role:` membaca role dari DB tiap request + 21 policy dengan ownership), plus global agent scope sebagai lapis ketiga. `OrderPolicy::view`/`viewInvoice`/`payOnBehalf` eksplisit dan sempit; scope `gudang` fail-closed via `isInWarehouseScope()`.
- **Otoritas verifikasi pembayaran:** `assertMayVerifyPayment` membatasi ke `super_admin` + `keuangan` Agen sama (`PaymentController.php:283-296`), konsisten dengan aturan LOCKED.
- **Kerahasiaan bukti:** `GET /payment-proofs/{id}` memakai `withoutGlobalScopes()` **lalu** gate otorisasi eksplisit + `Cache-Control: private, no-store`; bukti baru disimpan di disk **privat**. Benar dan didokumentasikan dengan sengaja.
- **Upload:** `MediaService` memvalidasi MIME **dan** ekstensi **dan** `getimagesize()` **dan** batas dimensi, menulis nama file UUID dengan ekstensi pilihan aplikasi; SVG sengaja dikecualikan per collection. 12 collection dengan batas ukuran berbeda sesuai `docs/05 §29`.
- **SSRF:** `EndpointGuard` — skema, kredensial tersemat, query/fragment, loopback, link-local/metadata, range privat, cek per-IP hasil resolve, allowlist host tepercaya, redirect mati, IP ter-vet di-pin, proxy dinonaktifkan.
- **Secret at rest:** cast `encrypted` pada `agent_payment_gateway_configs`, `agent_shipping_provider_configs`, `google_auth_settings`, `agent_google_auth_configs`, `whatsapp_messages.secret_body`, `refund_recipient_evidences.destination_details`. `.env` untracked; hanya `.env.example` yang di-commit; tidak ada secret di tracked tree (diverifikasi scan pola `git ls-files`).
- **Rate limiting:** login 10/menit, register 5/menit, bucket recovery, checkout 30/menit, CRM 30–60/menit, WA test/device 10–12/menit, delivery WA 6/jam per penerima + 20/menit gateway.
- **Notifikasi:** deferral `DB::afterCommit`; idempotency key unique; OTP tidak pernah dikirim ulang, tidak pernah di-log, `secret_body` terenkripsi.
- **Audit:** `activity_logs` dengan causer/subject/IP/UA; `refund_ledger_events` dan `stock_movements` append-only; `consent_events` menolak UPDATE/DELETE di level model.

### 10.2 Infrastruktur — **BLOCKED untuk produksi**

**Ada di repo:** `deploy/build.sh` (paket single-domain), `deploy/public_html/{laravel.php,.htaccess,config.js}`, `deploy/nginx-dev-8080.conf` (praktik baik: `SCRIPT_FILENAME` literal, eksekusi PHP ditolak di bawah `/storage/`, dotfile deny), `deploy/php-fpm-dev.conf`, `deploy/production/primeclassy-queue.service` (flock, `Restart=always`, `$tries=1`, lane notify didahulukan).

**Tidak ada di repo:** compose/Dockerfile produksi, nginx vhost produksi, file cron, grant DB, otomasi backup, monitoring/alerting.

Invarian `public_html/laravel.php` (AGENTS §10) nyata dan didokumentasikan benar; `deploy/build.sh` menyalinnya eksplisit dan rsync kanonis di `docs/13 §7.2` mengecualikannya.

---

## 11. Desain Migrasi Vue + Nuxt

**Prinsip:** pertahankan Vue 3 + TypeScript + SFC; tambahkan Nuxt untuk routing/SSR/SEO; **jangan** menulis ulang UI.

**Fase N1 — shell Nuxt, tanpa perubahan perilaku (`ssr: false`).**
Pasang SPA yang ada di dalam Nuxt dengan render client-only sehingga routing/layout/registrasi plugin teruji tanpa mengubah rendering. Pekerjaan wajib:
- `plugins/api.ts` — pindahkan `api/client.ts` ke plugin; ganti `window.__APP_CONFIG__` dengan `useRuntimeConfig().public.apiUrl` **plus** route `/config.js` yang disajikan Nitro agar kontrak terdokumentasi "edit di server tanpa rebuild" tetap hidup (`deploy/public_html/config.js`, `index.html:22`, daftar bypass `sw.js`).
- `plugins/{i18n,pinia,pwa}.ts` (dari `main.ts:14-24`).
- `app.vue` — pindahkan `ui.initTheme()/locale.initLocale()/site.load()` keluar dari `onMounted` ke guard `import.meta.client`.
- `middleware/{install,auth,role}.global.ts` — port rantai `router/index.ts:681-733` **dalam urutan persis**: capture referral → cek install → redirect installer → bootstrap auth → requiresAuth → guestOnly → permission/role.
- Kompatibilitas nama route: 97 route bernama direferensikan sebagai `{ name: 'x' }` di banyak view/komponen. Kirim shim `useNamedRoute()` (peta name → path) agar tidak semua `RouterLink`/`router.push` harus diubah dalam satu commit.

**Fase N2 — normalisasi layout.**
Saat ini setiap layout adalah wrapper **di dalam** masing-masing view (`ShopLayout`, `AuthLayout`, `DashboardLayout`). Konversi ke `layouts/*.vue` Nuxt + `<NuxtLayout>` dalam satu pass mekanis; ini juga memperbaiki FE-4 (`ReceivablesView` tanpa `DashboardLayout`). `HistoricalPosLayout` sudah level-route dan memetakan 1:1.

**Fase N3 — SSR selektif (storefront saja).**
Aktifkan SSR **hanya** untuk: `pages/index.vue` (homepage CMS), `pages/products/index.vue`, `pages/products/[slug].vue`, `pages/categories/index.vue`, `pages/articles/*`, `pages/page/[slug].vue`, `pages/toko-kami.vue`. Ini satu-satunya route ber-SEO dan juga yang paling sedikit interaktif.
- Data via `useAsyncData`/`useFetch` berkey param route; ganti fetch `onMounted` hanya di 8 view itu.
- Bungkus `<ClientOnly>`: konsumen `HomepageBlockRenderer` yang butuh DOM (`BannerSliderBlock`, maps), `AppHeader`, `BottomNav`, `PwaUpdateBanner`, `OfflineBanner`, `LanguageSwitcher`, `PrimeFileUpload`, `ImageUploader`, `ProtectedFileViewer`, `PcBarChart`, `RichTextEditor`, `GoogleMapView`, `AddressMapPicker`, `HpConfirmDialog`, `ShipmentReceiptView`, `EvidenceViewerView`, `DashboardLayout` (Teleport + localStorage + `getBoundingClientRect`).
- Pertahankan tree dashboard/admin/historical-pos `ssr: false` **permanen** (auth-only, tanpa nilai SEO, berat localStorage/Telemetry). Ini membatasi semua pekerjaan rumit ke ~8 route publik.

**Fase N4 — kelengkapan SEO (sekaligus menutup FE-5).**
`useHead` per halaman publik (title/description/canonical/OG), `robots.txt`, `sitemap.xml` dinamis dari produk/artikel/halaman, `<html lang>`/`dir` dari store locale, dan menyambungkan `site_seo_title/description/keywords` (sudah diambil di `stores/site.ts:26-32`, saat ini tidak dipakai) ke `useHead`.

**Fase N5 — paritas PWA.**
Reimplementasi `scripts/build-pwa.mjs` + `src-pwa/sw.js` sebagai route Nitro atau `@vite-pwa/nuxt` **dengan mempertahankan aturan terkunci**: jangan pernah cache `/api/*`, `/sanctum*`, `/up`, `/storage/*`, `/config.js`; tanpa auto-`skipWaiting`; fingerprint cache sensitif-konten. Implementasi saat ini memuat self-check yang membalik satu byte dan meng-assert versi berubah (`build-pwa.mjs:98-121`) — bawa assertion itu ke penggantinya.

**Fase N6 — cutover.**
Sajikan output Nuxt lewat layout single-domain yang ada; `public_html/laravel.php`, `.htaccess`, dan symlink `/storage` tetap utuh. Pensiunkan rewrite SPA di `deploy/public_html/.htaccess` hanya setelah route rules Nuxt terbukti.

### 11.1 Matriks kompatibilitas Vue→Nuxt

| Bucket | Jumlah / file |
|---|---|
| **Drop-in** | ~25 komponen: `ui/{AppIcon,AppButton,AgentPicker,StockBadge,PasswordInput}`, `cms/BrandIcon`, 15/16 `cms/blocks/*`, `historical-pos/{HpStatusChip,HpPipeline}`, `ui/{WarehouseStockCardGrid,SubStockCardGrid}`, `orders/FulfillmentShortagePanel`, `dashboard/CrmLinkPanel`, `AuthLayout` (hanya RouterLink→NuxtLink) |
| **Butuh `ClientOnly`** | 18 komponen (daftar di atas) |
| **Butuh `useAsyncData`** | 8 view publik (SSR) + opsional ~60 view dashboard (rekomendasi: biarkan client-side) |
| **Butuh registrasi plugin** | 5 (`http` client, i18n, Pinia, PWA, app boot) |
| **Vite-only / build-coupled** | `import.meta.env` ×9 site; `BASE_URL`; `PROD`; `public/config.js`; `build-pwa.mjs` + `src-pwa/sw.js`; fallback SPA `public/.htaccess`; proxy `vite.config.ts` → `nitro.devProxy`; `@tailwindcss/vite` → Nuxt Tailwind (pertahankan `@theme` CSS-first di `main.css`, tanpa file config) |
| **Router-coupled (wajib berubah)** | 8 komponen memakai `useRoute`/`useRouter`/`RouterLink` langsung |

### 11.2 Peta migrasi routing (Vue Router → file route Nuxt)

`/` → `pages/index.vue`; `/setup|/install|/offline` → page flat; `/products`, `/products/[slug]`, `/categories/index`, `/articles/index`, `/articles/[slug]`, `/page/[slug]`, `/toko-kami`, `/cart`, `/checkout`, `/wishlist`, `/login`, `/register`, `/forgot-password`, `/profile`, `/orders/index`, `/orders/[id]`, `/account/addresses`, `/print/shipments/[id]`; `/historical-pos` → `layouts/historical-pos.vue` + 9 page anak (dikecualikan dari baseline, §8); `/dashboard/**` → `pages/dashboard/**` (~40 flat + `products/[id]/edit`, `warehouse/sub-locations/[id]`, `crm/customers/[id]`, `crm/customers/[id]/order`); `/admin/**` → `pages/admin/**` (homepage-blocks/articles/pages × index|create|[id]/edit + 8 flat); `/:pathMatch(.*)*` → `pages/[...slug].vue`.

**Bahaya:** constraint regex `:id(\\d+)` (8 route) tidak punya padanan file-route → `validate()` di `<script setup>`.

---

## 12. Desain Migrasi Laravel → Go

**Target:** modular monolith Go, batas domain eksplisit, single-writer ownership, routing API inkremental di belakang layout single-origin yang sama, cutover dan rollback terkendali.

### 12.1 Routing bertahap (tanpa big bang)

Pertahankan routing nginx `.htaccess`/`laravel.php` dan letakkan **gateway Go** di depan kedua runtime, merutekan berdasarkan prefix path **dan** allowlist per-tenant/per-role:

```
/api/v1/*  →  gateway  →  match(prefix, tenant, role, flag)  →  Laravel  atau  Go
/sanctum/* →  Laravel (sampai Go memiliki penerbitan session)
/up        →  health gateway (keduanya)
```

Karena session Sanctum adalah baris server-side di `sessions` dan cookie dienkripsi Laravel, **Go tidak dapat memvalidasi cookie session Laravel** tanpa mengimplementasi ulang `Encrypter` (AES-256-CBC/GCM + HMAC dengan `APP_KEY`) atau berbagi state session. Dua opsi — **keputusan Owner (§18 D-4)**:
- **D-4a (rekomendasi):** Go memiliki autentikasi **lebih dulu** (login/logout/`me`) dan menerbitkan session sendiri; Laravel menjadi layanan downstream yang memercayai header internal bertanda tangan (`X-PC-User` + HMAC + TTL pendek, hanya dari jaringan privat gateway). Butuh satu cutover breaking pada `/auth/*` dan jendela migrasi session.
- **D-4b:** implementasi ulang dekripsi cookie Laravel di Go (bisa, ~1 modul, tapi mengikat Go ke `APP_KEY` dan format cookie Laravel selamanya).

### 12.2 Batas domain (paket Go), masing-masing satu writer

| Modul Go | Memiliki (single writer) | Membaca | Cutover pertama |
|---|---|---|---|
| `identity` | `users`, `roles`, `user_closures`, `user_social_identities`, `identity_otp_challenges`, sessions | — | Auth + profile (ambiguitas tertinggi, risiko finansial terendah) |
| `catalog` | `products`, `product_variations`, `product_*_attributes`, `product_images`, `product_fees`, `catalog_skus`, `product_categories` | — | CRUD katalog + baca storefront |
| `inventory` | `warehouse_stocks`, `warehouse_stock_revisions`, `product_stocks`, `product_variation_stocks`, `stock_movements`, `warehouse_sub_locations`, `sub_stock_reservations`, `stock_transfers/items/handovers`, `stock_opnames/items`, `stock_requests*`, `sub_stock_requests*`, `warehouse_stock_requests` | orders (baca) | Permukaan baca stok dulu; **jalur tulis terakhir** |
| `ordering` | `orders`, `order_items`, `shipments`, `delivery_refusals`, `order_item_receipts`, `order_initial_report_items` | inventory (via RPC, bukan tabel) | Checkout → buat order |
| `payments` | `payment_transactions`, `bank_transfer_verifications`, `cod_payment_proofs`, `order_additional_payments`, `orders.paid_amount/gross_received_amount/remaining_amount/payment_status`, `order_invoice_snapshots` | ordering | Verifikasi dulu, lalu intake |
| `refunds` | `refund_*`, `order_financial_snapshots(+units)`, `order_item_financial_bases`, `order_sales_reductions`, `commission_adjustments` | payments | Modul finansial terakhir |
| `commissions` | `commissions` | ordering/payments | Bersama refunds |
| `reporting` | proyeksi read-only | semua | Read-only, kapan saja (kemenangan *terlihat* terbaik lebih dulu) |
| `notifications` | `whatsapp_messages`, `email_messages`, `admin_notifications`, `agent_whatsapp_*` | event | Belakangan |
| `cms`, `settings`, `media`, `regions`, `integrations`, `crm`, `consent` | tabelnya masing-masing | — | Menengah |

### 12.3 Invarian Go non-negotiable

Setiap butir di bawah saat ini merupakan **konvensi** Laravel yang harus dibuat **eksplisit/struktural** di Go:

1. **Scope tenant eksplisit.** Ganti `BelongsToAgentScope` dengan nilai `Tenant` wajib di setiap signature repository; **tanpa default**. Jalur kode tanpa tenant harus **gagal compile**, bukan diam-diam mengembalikan semua baris.
2. **Urutan lock sebagai API kelas satu.** Port `canonicalReservationTargets` sebagai **satu-satunya** cara membangun himpunan lock inventori; `SELECT … FOR UPDATE` dalam urutan itu; `FOR UPDATE` Postgres + ordering deterministik. Tambah lint/test level paket yang menolak `FOR UPDATE` di luar helper ordering.
3. **Satu tipe uang.** `type Money int64` (minor unit) end-to-end, dengan batas DB eksplisit yang mengonversi `numeric(12,2)`/`numeric(14,2)`. Ledger refund sudah berpikir dalam cents (`Money.php`) — perluas model itu ke order/payment/invoice, bukan memport aritmetika float.
4. **Satu writer per fakta finansial**, ditegakkan batas modul (`payments` satu-satunya modul dengan akses tulis ke `orders.paid_amount`), menggantikan penulisan awal campuran di `OrderService` saat ini.
5. **Idempotency sebagai infrastruktur**: middleware yang mewajibkan `Idempotency-Key` pada himpunan route yang sama, plus constraint `UNIQUE` dipertahankan persis (`orders_konsumen_idempotency_unique`, `orders_crm_idempotency_unique`, `order_items(order_id,idempotency_key)`, `refund_attempts.idempotency_key`, key notifikasi).
6. **Side effect setelah commit.** `UnitOfWork`/outbox eksplisit: notifikasi hanya setelah commit; rollback harus secara struktural tidak mampu mengirim.
7. **Invarian write-once & XOR** dari `Order::booted()` — sebagai constraint DB bila mungkin (`CHECK`), validator model bila tidak.
8. **Akses dokumen/media terproteksi** — pola otorisasi-sebelum-stream yang sama dengan `PaymentController::proofFile`, termasuk `private, no-store`.
9. **PDF/invoice** — ganti dompdf dengan engine HTML→PDF Go; Blade view invoice baru saja ditulis ulang (+243 baris) dan harus diperlakukan sebagai **kontrak visual beku** dengan 10 PDF bukti di `docs/unified-rem-evidence/invoice/after/` sebagai fixture acceptance.
10. **Excel/Sheets** — reimplementasi template ekspor byte-compatible terhadap fixture yang sama; sync Sheets satu arah dengan batas fail-closed 10.000 baris yang sama.

### 12.4 Cutover & rollback per modul

Dual-*read* (shadow-read Go di belakang flag, di-diff terhadap Laravel selama N hari) → tulis Go untuk subset role (mis. satu Agen) → tulis penuh → drop route Laravel. Rollback = balikkan aturan gateway; tanpa migrasi data per langkah karena skema tetap dibagi sampai cutover PG.

### 12.5 Di luar scope port

`ResetTransactions`, `ResetProducts`, `ResetDevTransactions`, `MigrateLegacyWarehouseStock`, `RefundLedgerBackfill` (destruktif/dev/one-shot), dan kelas mati `*_old` Google.

---

## 13. Desain Migrasi MariaDB → PostgreSQL

**Batasan urutan:** konversi skema **tidak boleh** mendahului ekstraksi REM-11, karena 4 migration dan 9 tabel akan diterjemahkan lalu dibuang.

**Langkah 1 — Bekukan sumber kebenaran.** Migration (176 file) adalah kebenaran skema; `database.sql` tertinggal 79 tabel dan harus **dipensiunkan sebagai artefak** (atau diregenerasi) sebelum menyesatkan translator.

**Langkah 2 — Generate DDL PG dari migration, dengan peta tipe eksplisit.**

| MariaDB | PostgreSQL |
|---|---|
| `$table->id()` / `foreignId` | `bigint GENERATED BY DEFAULT AS IDENTITY` / `bigint` |
| `enum(...)` (72 kolom) | `varchar(n) + CHECK (col IN (...))`, daftar nilai diberi versi di Go |
| `json` (23 kolom) | `jsonb` |
| `decimal(12,2) unsigned` / `(14,2) unsigned` | `numeric(12,2)` / `numeric(14,2)` + `CHECK (col >= 0)` |
| `unsignedBigInteger/Integer` | `bigint`/`integer` + `CHECK (col >= 0)` di mana non-negativitas load-bearing |
| `timestamp` (116) | `timestamptz` (**keputusan diperlukan**, §18 D-5); `APP_TIMEZONE=UTC` sudah menjadi kontrak |
| `->change()` (5) | `ALTER COLUMN … TYPE … USING` eksplisit |
| generated column (3) | `GENERATED ALWAYS AS (...) STORED` (PG tidak punya VIRTUAL) |
| trigger (2 file) | fungsi PL/pgSQL + `CREATE TRIGGER`; `<=>` → `IS DISTINCT FROM`; `IF()` → `CASE`; `ON DUPLICATE KEY UPDATE` → `ON CONFLICT … DO UPDATE` |
| unique `utf8mb4_unicode_ci` (email/slug/sku/order_no) | `citext` **atau** expression index `UNIQUE (lower(col))` |
| `GET_LOCK` | `pg_advisory_lock` / `pg_try_advisory_lock` |
| `information_schema` + `DATABASE()` | `information_schema.columns`/`table_constraints` + `current_schema()` |
| `DATE_FORMAT` | `to_char` |
| `JSON_EXTRACT`/`JSON_UNQUOTE` | `->>` / `#>>` |
| `SET SESSION TRANSACTION READ ONLY` | `SET TRANSACTION READ ONLY` |
| `AUTO_INCREMENT = 1` | `ALTER SEQUENCE … RESTART WITH 1` |

**Langkah 3 — Rewrite SQL aplikasi (worklist terbatas, ~10 file).** Lihat **Lampiran C**.

**Langkah 4 — Rehearsal (berulang, tidak pernah di produksi).**

- **R1:** skema PG + `COPY` snapshot + full suite. Perkiraan kegagalan: 13 kelas REM-11 (dikecualikan), 8 kelas refund, 28 kelas konkurensi, dan setiap test laporan yang menyentuh blocker 1/2/3.
- **R2:** sama setelah rewrite SQL.
- **R3:** sama setelah lane PG harness ada.

Setiap rehearsal mencatat: row count, checksum per tabel, hasil cek §7.4, dan baseline durasi.

**Langkah 5 — Cutover (freeze, bukan dual-write).**

Dual-write **ditolak**: kebenaran finansial bersifat DB-authoritative (`AGENTS.md §9`) dan dual-write menciptakan sumber kebenaran kedua yang senyap. Sebagai gantinya: maintenance window → freeze write → delta export final → jalankan rekonsiliasi §7.4 di **target** → flip `DB_CONNECTION` (atau gateway) → smoke (`docs/13 §7.4`) → pertahankan MariaDB **read-only dan utuh** sampai Keuangan sign-off. Rollback = flip balik + restore dari snapshot pra-cutover (tidak ada yang ditulis ke MariaDB selama window).

**Langkah 6 — Gate paritas harness.** Tambah `primeclassy_testing_pg(_wN)` ke `pctest/guards.py:8` dan ke `TestDatabaseGuard`; port `TestRunLock` dan `db-lease.php`; port `IsolatedSchemaFingerprint` dari `CHECKSUM TABLE`. Wajibkan **digest manifest yang sama** PASS di kedua stack sebelum PG menjadi kanonis.

---

## 14. Risk Register Konsolidasi

| ID | Risiko | Sev | Kemungkinan | Bukti | Keyakinan |
|---|---|---|---|---|---|
| R-01 | REM-11 musnah oleh `git clean`/checkout — hanya ada di disk | **Critical** | Medium | `git ls-files` = 0 path REM-11 | VERIFIED |
| R-02 | Tracked tree tidak buildable tanpa modul REM-11 untracked (memblok baseline clean-room & CI) | **Critical** | Pasti hari ini | `router/index.ts:145-223`, `navConfig.ts:261-268` | VERIFIED |
| R-03 | Port baseline Go mewarisi dependensi mentah ke `historical_dossiers` | **High** | Pasti bila terlewat | `PaymentService.php:1019-1030` | VERIFIED |
| R-04 | Regresi finansial akibat mismatch float→decimal/int lintas port | **High** | Medium | dua model aritmetika (§6.1) | VERIFIED |
| R-05 | Korupsi laporan senyap di PG (`JSON_EXTRACT`/`DATE_FORMAT`/`HAVING` alias) | **High** | Tinggi bila port mekanis | 4 file, 17 statement | VERIFIED |
| R-06 | Case-insensitive uniqueness hilang di PG → akun/slug duplikat | **High** | Tinggi bila tidak ditangani | `utf8mb4_unicode_ci` | VERIFIED |
| R-07 | Guard hard-delete produk lolos senyap di PG (introspeksi mengembalikan kosong) | **High** | Medium | `ProductHardDeleteService.php:38-39,115-118` | VERIFIED |
| R-08 | Drift manifest ⇒ tidak ada jaring regresi saat port dimulai | **High** | Pasti hari ini | `INVENTORY MISMATCH (181)` | VERIFIED (dieksekusi) |
| R-09 | `installed.lock` absen pada situs live ⇒ refleksi origin terbuka + CORS stateful | **High (kondisional)** | Rendah–Medium | S-1 | VERIFIED kode / INFERRED paparan |
| R-10 | SSR Nuxt crash pada render pertama (3 akses browser module-scope) | **High** | Pasti bila tidak ditangani | FE-1/2/3 | VERIFIED |
| R-11 | Go tidak dapat memvalidasi cookie session Laravel ⇒ cutover auth memaksa forced-logout | **Medium** | Pasti | tabel `sessions` + `APP_KEY` | VERIFIED |
| R-12 | Regresi visual invoice saat tukar engine PDF | **Medium** | Medium | `invoice.blade.php` (+243, baru ditulis ulang) | VERIFIED |
| R-13 | Dokumen dipakai sebagai baseline acceptance Go/Nuxt padahal basi → "parity" palsu | **Medium** | Tinggi | tabel drift dokumen §3 | VERIFIED |
| R-14 | SEO hilang permanen bila migrasi Nuxt tetap `ssr:false` untuk route publik | **Medium** | Medium | tanpa `useHead`/meta di mana pun | VERIFIED |
| R-15 | Baca tanpa lock `RefundMetrics`/`remaining()` menjadi dapat dipanggil independen di Go | **Medium** | Medium | §6.4 | INFERRED |
| R-16 | Scheduler tidak ada ⇒ kontrak retensi/purge tidak pernah dieksekusi | **Rendah** | Pasti | `routes/console.php` = `inspire` | VERIFIED |
| R-17 | Dead code (`*_old`, `PaymentWebhookLog`) ikut diport tanpa sengaja | **Rendah** | Medium | 0 referensi | VERIFIED |
| R-18 | 63 modified + 170 untracked membuat "baseline diff" ambigu bagi reviewer | **Medium** | Pasti | §2 | VERIFIED |

---

## 15. Roadmap Migrasi (penomoran linear)

> **Catatan penomoran:** repository ini sudah membekukan `IMP-001…IMP-007` dan menetapkan **`IMP-008`** sebagai nomor berikutnya (`docs/15 §4`, G-09). Roadmap di bawah melanjutkan seri itu agar tidak bertabrakan; permintaan enumerasi linear `IMP-001…` memetakan 1:1 secara urutan.

| ID | Paket | Tujuan | Bergantung pada | Exit criteria |
|---|---|---|---|---|
| **IMP-008** | Arsip preservasi REM-11 | Membuat "preserve intact" benar dan dapat diverifikasi: orphan branch **atau** arsip eksternal dari 61 file + 4 migration + dokumen + 16 diff file campuran sebagai patch referensi | Keputusan Owner D-1 | Digest arsip tercatat; `git show <archive>:<path>` dapat diambil; patch ekstraksi baseline direview |
| **IMP-009** | Ekstraksi baseline | Menghapus REM-11 dari baseline ke depan: 19 route, 9 route frontend, entri nav, 20 pemanggilan guard, cast `historical_*`, penanganan `historical_manual`, raw read `PaymentService:1019-1030` | IMP-008, D-2/D-3 | Suite verifikasi §8.8 hijau; `type-check` + `build-only` lulus di checkout bersih; 23 kelas test baseline bernama lulus |
| **IMP-010** | Koreksi kebenaran dokumen | Memperbaiki angka basi dan klaim registrasi-500; menuliskan lingkup baseline migrasi secara eksplisit | IMP-009 | `docs/01,03,06,09,14,15` sesuai source; ada bagian "migration baseline" yang menyebut apa yang in/out |
| **IMP-011** | Bekukan kontrak | Emit OpenAPI dari route table; bekukan envelope `ApiResponse` + key pesan 422 + fixture PDF invoice + kontrak kolom laporan (A=24, B=15, operasional 14 kolom) | IMP-010 | Artefak kontrak ter-commit dan dapat diverifikasi mesin; golden fixture untuk 17 dataset Sheets |
| **IMP-012** | Regenerasi manifest test + rig paritas PG | Regenerasi + re-approve manifest; tambah `primeclassy_testing_pg(_wN)`; port run-lock/fingerprint ke primitif PG; adapter manifest Go/Nuxt | IMP-011 | `manifest-verify` bersih; digest yang sama PASS di MariaDB **dan** PG untuk suite baseline |
| **IMP-013** | Translasi skema PG | Generate DDL PG dari migration dengan peta tipe §13; rewrite worklist SQL 10 file; CI + `lower()` unique; trigger PL/pgSQL | IMP-012 | Rehearsal R2 hijau; rekonsiliasi §7.4 lulus pada snapshot berbentuk produksi |
| **IMP-014** | Skeleton Go + gateway | Gateway dengan routing path/tenant/role; modul `identity`; primitif tenant + lock-ordering + uang eksplisit dengan lint guard | IMP-011, D-4 | Gateway merutekan seluruh trafik tanpa perubahan; `identity` shadow-read cocok |
| **IMP-015** | Cutover baca Go | `reporting`, path baca `catalog`, baca `inventory` — di belakang flag, di-shadow-diff | IMP-014 | Shadow diff < toleransi selama 7 hari berturut-turut; drill rollback dieksekusi |
| **IMP-016** | Cutover tulis Go — ordering & inventory | Checkout → buat order → reservasi → kanonikalisasi shipment → fulfilment/shortage/handover | IMP-015 | Regresi konkurensi penuh diport dan hijau; tanpa duplikat reservasi/komisi pada replay 2 koneksi |
| **IMP-017** | Cutover tulis Go — payments, refunds, commissions, invoices | Modul uang, dengan batas single-writer dan `Money` berbasis cents | IMP-016 | Invarian ledger refund + paritas metrik AR; byte-diff PDF invoice dalam delta yang diterima |
| **IMP-018** | Nuxt N1–N2 | Shell Nuxt (`ssr:false`), plugin, middleware, normalisasi layout | IMP-011, IMP-014 (bentuk API stabil) | `type-check` + `build` + seluruh 59 spec vitest hijau di bawah Nuxt; nol diff visual pada 20 layar kunci |
| **IMP-019** | Nuxt N3–N5 | SSR selektif (8 route publik), kelengkapan SEO, paritas PWA | IMP-018 | Cek Lighthouse/SEO; `robots`/`sitemap` tersaji; aturan bypass SW tidak berubah; 4 locale ter-render server-side |
| **IMP-020** | Cutover produksi MariaDB→PostgreSQL | Maintenance window, freeze, delta, rekonsiliasi, flip, smoke, sign-off Keuangan | IMP-013, IMP-017 | §7.4 seluruhnya hijau di target; smoke per `docs/13 §7.4`; MariaDB utuh & read-only; rollback terdokumentasi |
| **IMP-021** | Redesain REM-11 (persetujuan Owner terpisah) | Historical POS dibangun ulang di Go di atas PG — **eksplisit di luar baseline** | IMP-020 + keputusan Owner baru | Design lock baru + seri paketnya sendiri |

**Rasional urutan:** tiga item adalah prasyarat keras dan tidak ada pekerjaan finansial boleh mulai sebelumnya — IMP-008/009 (kemurnian baseline), IMP-011 (kontrak), IMP-012 (jaring regresi). PG (IMP-013) dapat berjalan paralel dengan pekerjaan modul Go (IMP-014/015) tetapi harus mendahului cutover finansial (IMP-017) dan flip final (IMP-020).

---

## 16. Acceptance Criteria dan Release Gate

**Gate universal setiap fase** (per `AGENTS.md §7`, `docs/14 §3`): scoped test backend untuk setiap area yang berubah (0 failure) + frontend `type-check` + `build-only` + `git diff --check`; tree bersih; baseline + HEAD tercatat di checkpoint. **Full Test tetap eksklusif Owner dan bukan gate.**

| Gate | Kriteria |
|---|---|
| **G-1 Kemurnian baseline** | Grep §8.8 mengembalikan 0 di luar `docs/`; route table memuat 0 route REM-11; skema memuat 0 tabel/kolom REM-11; checkout bersih dapat di-build; 23 kelas test baseline bernama lulus |
| **G-2 Kebenaran dokumentasi** | Setiap angka di `docs/01,03,09,14,15` cocok dengan command yang dapat dijalankan ulang; lingkup baseline migrasi tertulis |
| **G-3 Kontrak** | OpenAPI valid; setiap key error 422 ada di 4 locale; Δ PDF invoice dalam batas yang diterima Owner vs 10 fixture bukti; kontrak kolom laporan di-assert oleh test |
| **G-4 Jaring regresi** | `manifest-verify` bersih; run paralel dan serial keduanya PASS dengan digest sama; setiap area berubah terpetakan ke test |
| **G-5 Kesiapan PG** | Rehearsal R3 hijau pada snapshot berbentuk produksi; §7.4 seluruhnya lulus; 10 file rewrite SQL punya test yang gagal pada sintaks khusus MariaDB; constraint CI-unique dan non-negativitas di-assert |
| **G-6 Paritas Go** | Per modul cutover: JSON ternormalisasi identik untuk korpus skenario tetap; invarian finansial identik; replay konkurensi 2 koneksi hijau; drill rollback dieksekusi dan diukur waktunya |
| **G-7 Paritas Nuxt** | Seluruh 59 spec vitest hijau; nol regresi visual pada 20 layar kunci; SSR ter-render untuk 8 route publik; aturan bypass PWA terverifikasi test; `config.js` runtime-editability terjaga |
| **G-8 Cutover produksi** | Backup terverifikasi (DB + media + `.env`, restore-tested); migration Pending→Ran; angka rekonsiliasi tercatat; smoke per `docs/13 §7.4`; `laravel.php`/symlink `storage`/`.well-known` terjaga; sign-off Keuangan untuk modul finansial; persetujuan Owner untuk langkah spesifik itu |
| **G-9 Bukti eksklusi REM-11** | Baseline ke depan memuat nol perilaku REM-11 (§8.8), dan arsip masih memuat seluruh 61 file byte-identik dengan digest preservasi |

---

## 17. Pertanyaan Teknis Terbuka

1. **Kontinuitas session pada cutover auth Go** — forced re-login dapat diterima, atau session hidup harus bertahan (interop tabel `sessions`)? Ini menentukan D-4a vs D-4b dan ukuran modul identity.
2. **Kontrak timezone di PG** — `timestamptz` di mana-mana, atau pertahankan `timestamp` + `APP_TIMEZONE=UTC` + perhitungan hari bisnis `Asia/Jakarta` eksplisit (saat ini `Carbon::now('Asia/Jakarta')` muncul di `ReportService` dan filter laporan)? Mencampur keduanya menggeser batas laporan secara senyap.
3. **`decimal` vs integer minor unit di PG** — pertahankan `numeric(12,2)` demi keterbacaan laporan, atau pindahkan semua uang ke `bigint` cents (lebih bersih untuk Go, memutus keterbacaan SQL ad-hoc dan setiap query laporan saat ini)?
4. **Kepemilikan SQL laporan** — haruskah presentasi gaya `DATE_FORMAT` **keluar dari SQL** sepenuhnya (format di Go/TS)? Ini menghapus blocker 2 dan meningkatkan testability, tetapi mengubah jalur konstruksi payload Sheets.
5. **Mekanisme case-insensitive uniqueness** — ekstensi `citext` (butuh instalasi superuser) vs `UNIQUE (lower(col))` (tanpa ekstensi, tapi setiap lookup harus `lower()`)?
6. **Target paritas PDF invoice** — piksel-level, atau struktural + dalam toleransi visual yang dinyatakan? Blade view ditulis ulang 3 hari lalu dan 10 PDF bukti adalah satu-satunya kontrak.
7. **Mekanisme enforcement tenant scope / lock ordering di Go** — custom analyser gaya `go vet`, aturan `depguard`, atau assertion runtime + test? Ini menentukan apakah dua konvensi paling berbahaya menjadi mustahil secara struktural atau sekadar terdokumentasi.
8. **Lingkup SSR route publik** — persis route mana? (Rekomendasi: 8 route di §11; Owner mungkin ingin `/toko-kami` masuk atau keluar.)
9. **`public/config.js` runtime editability** — apakah ini kontrak produksi permanen (saat ini ya, dan SW melewatinya), atau dapat diganti `runtimeConfig` build-time?
10. **Apakah baseline Go perlu mempertahankan installer wizard** (`/install/*`, `EnvFileWriter`, `SafeSchema`, `EnsurePreInstallSafeDrivers`, `ReflectOriginBeforeInstall`)? Itu ~600 baris logika khusus bootstrap yang seluruh tujuannya first-run pada DB kosong.
11. **Timing Sheets/CRM/WhatsApp** — apakah ini masuk port Go sama sekali, atau tetap di Laravel lebih lama? Ketiganya membawa sebagian besar permukaan integrasi eksternal.
12. **`database.sql`** — dipensiunkan, atau diregenerasi dari migration sebagai artefak paritas PG?

---

## 18. Keputusan Owner yang Diperlukan

| ID | Keputusan | Kenapa hanya Owner | Jika ditunda |
|---|---|---|---|
| **D-1** | **Kendaraan preservasi REM-11** — orphan branch di repo ini, arsip eksternal, atau keduanya | Menyentuh "existing REM-11 source must be preserved intact"; governance repo menyatakan Git history adalah arsip, tetapi REM-11 tidak ada di dalamnya | R-01 tetap Critical; setiap hari meningkatkan risiko kehilangan permanen |
| **D-2** | **Apakah `historical_manual` artefak REM-11 atau konsep akuntansi bersama?** Metode ini di-seed **aktif** di setiap deployment (`PaymentMethodSeeder.php:29`) dan difilter di 5 tempat | Ini pertanyaan kosakata bisnis/akuntansi, bukan pertanyaan kode | Patch ekstraksi ambigu; A3 di §8.7 tetap terbuka |
| **D-3** | **Perilaku `PaymentService::applyPaymentToOrder` untuk order historis setelah tabel REM-11 hilang** — 409 fail-closed keras, atau hapus cabangnya | Mengubah perilaku error baseline untuk tipe order yang ada adalah keputusan bisnis | R-03 tetap Critical |
| **D-4** | **Strategi cutover auth** (Go menerbitkan session vs Go mendekripsi cookie Laravel) — menerima forced-logout atau tidak | Dampak ke pengguna produksi | Memblok IMP-014 |
| **D-5** | **Kebijakan timezone/timestamp PG** (lihat Q2) | Risiko pergeseran batas laporan senyap | Memblok IMP-013 |
| **D-6** | **Representasi uang di PG dan Go** (decimal vs cents) | Mempengaruhi setiap query finansial dan alur rekonsiliasi Keuangan | Memblok IMP-013/017 |
| **D-7** | **Lingkup SSR route publik** dan apakah SEO menjadi syarat rilis untuk Nuxt N3 | Keputusan produk/marketing | Lingkup IMP-019 tidak jelas |
| **D-8** | **Kontrak runtime `config.js`** (permanen atau dipensiunkan) | Keputusan operasional yang mempengaruhi deploy tanpa rebuild | Forks desain IMP-018 |
| **D-9** | **Pensiunkan `database.sql`?** | Artefak basi yang akan aktif menyesatkan translator | Risiko rendah, tapi harus eksplisit |
| **D-10** | **Sign-off Keuangan** atas kontrak AR (`CollectibleReceivable`) sebelum rollout produksi — sudah disyaratkan `docs/05 §20c OD-K5-4` dan masih outstanding | Otoritas finansial | Cutover finansial PG/Go tanpa sign-off wajibnya |

*Tidak ada keputusan di atas yang diasumsikan dalam audit ini; bila ada rekomendasi, itu dilabeli sebagai rekomendasi.*

---

## 19. Verdict Audit

**AUDIT VERDICT: COMPLETE WITH GAPS**

**Mengapa COMPLETE:** setiap area audit yang diminta diperiksa dalam satu pass — Git forensics; permukaan frontend penuh (91 view, 59 komponen, 47 modul API, 7 store, 97 route, seluruh kelas ketidakcocokan SSR dengan file:line); permukaan backend penuh (88 controller, ~390 route, 121 model, 21 policy, 151 service, seluruh middleware/observer/job/command/mail); matriks dependensi bisnis & finansial dengan invarian terverifikasi serta setiap batas transaksi dan mekanisme idempotency; matriks kompatibilitas MariaDB→PostgreSQL (sensus konstruksi di seluruh 176 migration + matriks 30 tabel + 14 blocker berperingkat dengan file:line); manifest isolasi REM-11 (61 file, 16 peta hunk file campuran, 4 migration, 9 tabel, graf dependensi dua arah, 9 item ambigu, usulan isolasi, kriteria verifikasi); penilaian testing/quality (arsitektur harness, drift manifest **dieksekusi dan dikonfirmasi**, celah cakupan, strategi paritas Go/Nuxt/PG); serta temuan keamanan/infrastruktur dengan severity. Tiga desain migrasi (Nuxt, Go, PostgreSQL) dengan sequencing yang sadar-dependensi, gate acceptance, risk register, dan keputusan Owner disertakan. Tidak ada source, database, Git history, atau state deployment yang diubah.

**Mengapa WITH GAPS — omissions persisnya:**

1. **Infrastruktur runtime produksi tidak ada di repository** (compose, nginx vhost produksi, grant DB, cron, monitoring). Temuan konfigurasi produksi (security header, enforcement TLS, otomasi backup) karena itu **BLOCKED**, bukan "absen".
2. **`backend/vendor/` dan `frontend/node_modules/` tidak di-CVE-scan** — hanya versi deklarasi/lock yang dibaca.
3. **Tidak ada verifikasi runtime apa pun** — tidak ada test, migration, atau endpoint yang dieksekusi (dilarang). Semua klaim perilaku adalah kesimpulan statis dari source.
4. **Matriks per-kolom PostgreSQL mencakup 30 tabel**; ~104 tabel lain dicakup per kelas konstruksi (enum/json/decimal/unsigned/FK/trigger/generated/CHECK/collation), bukan kolom-demi-kolom.
5. **~35 grup endpoint di luar inti finansial/stok/fulfilment** (CMS, media, languages, settings, regions, consent, sebagian pembacaan CRM) dinilai di level grup, bukan service-demi-service.
6. **Dokumentasi untracked (`RECON-*`, `REM-V2-*`, `architecture-lock/**`, ~120 file)** diinventarisasi dan dibaca sebagian; keputusan historisnya sengaja tidak direlitigasi, sesuai urutan otoritas `AGENTS.md §2`.

**Tiga fakta yang sebaiknya ditindaklanjuti Owner sebelum pekerjaan migrasi dimulai:**

1. **REM-11 hanya ada di disk** (`git ls-files` = 0). Sampai IMP-008, "preserved intact" adalah asumsi, bukan jaminan.
2. **Tracked tree tidak dapat di-build tanpa file untracked itu** — baseline tidak dapat direproduksi clean-room hari ini.
3. **Jaring regresi saat ini rusak** (`INVENTORY MISMATCH (181)`) — tidak ada port yang boleh dimulai sebelum IMP-012.

---

## Lampiran A — Perintah Verifikasi

Semua read-only. Jalankan dari root repository.

```bash
# --- Git / repo state ---
git rev-parse --show-toplevel
git branch --show-current
git rev-parse HEAD
git status --porcelain | awk '{print $1}' | sort | uniq -c
git diff --stat | tail -3
git diff --numstat | awk '{a+=$1;d+=$2} END {print "added",a,"deleted",d}'
git log --oneline -6
git ls-files | grep -E '(^|/)\.env($|\.)'        # harus hanya .env.example
git ls-files | grep -ci historical                 # harus 0  → REM-11 tidak terlacak
git check-ignore -v public_html frontend/dist

# --- Skala ---
ls backend/database/migrations/*.php | wc -l                       # 176
grep -rhoE "Schema::create\('[a-z_0-9]+'" backend/database/migrations | sort -u | wc -l   # 134
ls backend/tests/Feature/*.php | wc -l                              # 232
ls backend/tests/Unit/*.php | wc -l                                 # 6
grep -rhoE "public function test_[a-zA-Z0-9_]+" backend/tests | wc -l   # ~2026
find frontend/src -name '*.spec.ts' | wc -l                         # 59
find frontend/src/views -name '*.vue' | wc -l                       # 91
ls frontend/src/api/*.ts | wc -l                                    # 47
grep -cE "Route::(get|post|put|patch|delete|match|any|apiResource|resource)" backend/routes/api_v1.php
grep -rn "lockForUpdate" backend/app | wc -l                        # 314
grep -rn "DB::transaction" backend/app | wc -l                      # 176

# --- REM-11 eksklusi (§8.8) ---
grep -rn "HistoricalPos\|HistoricalOrderGuard\|HistoricalDossier\|ReportRegistry" \
  backend/app backend/routes backend/config backend/database backend/tests frontend/src
grep -rn "historical_origin\|historical_manual\|historical-pos\|hp-list" \
  backend/app backend/routes backend/database backend/tests frontend/src

# --- Blocker PostgreSQL (§7.3) ---
grep -rn "JSON_EXTRACT\|JSON_UNQUOTE" backend/app --include="*.php"
grep -rn "DATE_FORMAT" backend/app --include="*.php"
grep -rn "havingRaw" backend/app --include="*.php"
grep -rn "GET_LOCK\|RELEASE_LOCK" backend/app backend/database
grep -rn "information_schema\|DATABASE()" backend/app
grep -rn "CREATE TRIGGER\|ON DUPLICATE KEY" backend/database/migrations
grep -rho -- "->enum(" backend/database/migrations | wc -l          # 72

# --- Blocker SSR (§4.7) ---
sed -n '19p'  frontend/src/api/client.ts
sed -n '15p'  frontend/src/stores/ui.ts
sed -n '18p'  frontend/src/stores/locale.ts
sed -n '22,23p' frontend/src/components/ui/LanguageSwitcher.vue

# --- Harness test (§9) ---
python3 -B tools/parallel-test/pctest/cli.py manifest-verify \
  --manifest tools/parallel-test/manifest/manifest.json
```

---

## Lampiran B — Manifest REM-11 Ringkas per Grup

| Grup | Jumlah | Path pola |
|---|---|---|
| Controller | 7 + 1 resource | `backend/app/Http/Controllers/Api/V1/Order/Historical*.php`, `.../Report/ReportRegistryController.php`, `.../Resources/HistoricalDossierResource.php` |
| Model | 9 | `backend/app/Models/Historical*.php`, `.../ReportRegistry.php` |
| Service | 11 | `backend/app/Services/HistoricalPos/**` |
| Support/Policy/Config | 5 | `Support/{HistoricalOrderGuard,PayloadFingerprint}.php`, `Policies/{HistoricalDossierPolicy,ReportRegistryPolicy}.php`, `config/historical_pos.php` |
| Migration | 4 | `2026_11_14_120000_*`, `2026_11_15_100000_*`, `2026_11_15_110000_*`, `2026_11_16_100000_*` |
| Test backend | 13 | `backend/tests/Feature/Historical*.php` |
| Frontend source | 13 | `api/historicalPos.ts`, `assets/historical-pos.css`, `layouts/HistoricalPosLayout.vue`, `components/historical-pos/**`, `views/historical-pos/**` |
| Frontend test | 10 | `frontend/src/**/__tests__/*historical*`, `views/historical-pos/__tests__/**` |
| Dokumen | ~28 | `docs/PCV2-TC-REM11-*`, `docs/RECON-CLOSURE-HISTORICAL-POS.md`, `docs/architecture-lock/**`, `docs/REM-V2-*` |

**Total:** 61 file source + 4 migration + ~28 dokumen. **Tracked: 0.**

---

## Lampiran C — Worklist Rewrite SQL (PostgreSQL)

| File | Baris | Konstruksi | Aksi |
|---|---|---|---|
| `app/Services/Report/OrderStaffWorksheetService.php` | 169, 202-207 | `DATE_FORMAT`, `JSON_EXTRACT`, `JSON_UNQUOTE` | `to_char`, `->>`; **prioritas tertinggi** |
| `app/Services/Report/OrderInitialReportService.php` | 69 | `DATE_FORMAT` | `to_char` |
| `app/Services/Report/OrderTransactionReportService.php` | 51, 58 | `DATE_FORMAT` | `to_char` |
| `app/Services/Report/OrderPaymentPositionReportService.php` | 60, 71, 73 | `DATE_FORMAT` | `to_char` |
| `app/Services/Report/ReportService.php` | 589 | `havingRaw` atas alias `selectRaw` | pindah ke `WHERE` / subquery |
| `app/Services/Product/CategoryHierarchyService.php` | 45, 72, 139, 176 | `GET_LOCK`/`RELEASE_LOCK` | `pg_advisory_lock` |
| `app/Services/Product/ProductHardDeleteService.php` | 38-39, 115-118 | `information_schema` + `DATABASE()` | `current_schema()`; **risiko kebenaran** |
| `app/Console/Commands/ResetProducts.php` | 1293-1334 | introspeksi | `information_schema.columns` |
| `app/Console/Commands/CategoryUniquePreflight.php` | 19, 25 | `SET SESSION TRANSACTION READ ONLY` | `SET TRANSACTION READ ONLY` |
| `app/Services/Maintenance/DevTransactionResetService.php` | 137 | `AUTO_INCREMENT = 1` | `ALTER SEQUENCE … RESTART` |
| `database/migrations/2026_11_14_110000_add_physical_stock_revision.php` | 29-41 | trigger + `ON DUPLICATE KEY UPDATE` + `<=>` | PL/pgSQL + `ON CONFLICT` |
| `database/migrations/2026_10_01_100000_add_self_delivery_to_shipments_table.php` | 55-79 | trigger | PL/pgSQL |
| `database/migrations/2026_11_16_200000_*` | 49, 71, 84-115 | `GET_LOCK`, `COLLATE utf8mb4_bin`, generated VIRTUAL, `DROP CHECK`/`DROP CONSTRAINT` | `pg_advisory_lock`, `citext`/`COLLATE "C"`, `STORED` |

---

## Lampiran D — Matriks Traceability Temuan → Roadmap

| Temuan | Gate | Paket |
|---|---|---|
| E-1 (REM-11 hanya di disk) | G-1, G-9 | IMP-008 |
| E-2 (tracked tree tidak buildable) | G-1 | IMP-009 |
| E-3 (guard baseline→REM-11) | G-1 | IMP-009 |
| E-4 (manifest mismatch 181) | G-4 | IMP-012 |
| E-5 (blocker PostgreSQL) | G-5 | IMP-013 |
| E-6 (blocker SSR) | G-7 | IMP-018 |
| E-7 (dua model aritmetika) | G-6 | IMP-017 |
| E-8 (drift dokumentasi) | G-2 | IMP-010 |
| FE-1/2/3 | G-7 | IMP-018 |
| FE-4 | G-7 | IMP-018 |
| FE-5 | G-3, G-7 | IMP-019 |
| FE-6 | G-7 | IMP-018/019 |
| T-1 | G-4 | IMP-012 |
| T-2/T-3 | G-2 | IMP-010 |
| T-4/T-5/T-6 | G-5, G-6 | IMP-012 |
| S-1 | G-8 | IMP-020 (preflight) |
| S-2/S-3 | G-8 | IMP-020 (preflight, edge config) |
| S-4/S-5 | — | Higiene, dapat diselesaikan kapan saja |
| R-01…R-18 | G-1…G-9 | Lihat kolom paket |
| D-1…D-10 | — | Prasyarat keputusan sebelum paket terkait |

---

*Akhir dokumen. Tidak ada file source, database, state Git, atau deployment yang diubah selama audit ini. Dokumen ini sendiri adalah file baru yang belum di-commit.*
