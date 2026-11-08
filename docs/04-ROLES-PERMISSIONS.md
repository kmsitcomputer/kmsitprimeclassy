# 04 — Roles & Permissions

Matriks role dan otorisasi berdasarkan source code. **Backend adalah satu-satunya security boundary** — navigasi frontend hanya visibility. Sumber utama: `backend/routes/api_v1.php`, `backend/app/Support/HierarchyRules.php`, `backend/app/Support/PermissionMap.php`, `backend/app/Policies/*`, `backend/database/seeders/RoleSeeder.php`.

---

## 1. Daftar role (tepat 11 — terkunci)

Evidence: `RoleSeeder.php`; migrasi `2026_09_22_090000` (keuangan), `2026_09_29_090000` (rename `sales-kurir` → `sales-kurir-sub` in-place), `2026_10_05_100004` (koordinator-kurir).

| Slug | Posisi | Dibuat oleh | Kode referral | Scope data |
|---|---|---|---|---|
| `super_admin` | Pemilik platform | installer / tinker | — | Semua cabang |
| `agen` | Pemilik cabang | `super_admin` | `AG-` | Cabang sendiri |
| `admin` | Staf kantor cabang | `agen` | — | Cabang sendiri |
| `keuangan` | Keuangan cabang | `agen` | — | Cabang sendiri (permukaan finansial saja) |
| `gudang` | Gudang cabang | `agen` | — | Cabang sendiri (proyeksi operasional, tanpa uang) |
| `kurir` | Kurir cabang | `agen` / `koordinator-kurir` | — | Hanya shipment/retur yang di-assign kepadanya (assignment-only, tanpa self-claim) |
| `koordinator-kurir` | Dispatcher pengiriman cabang | `agen` | — | Cabang sendiri (dispatch + assignment kurir/retur + riwayat terkirim read-only; tanpa uang, tanpa fulfilment) |
| `korsal` | Koordinator sales | `agen` | `KO-` | Downline sendiri |
| `sales` | Penjual | `agen` (wajib Korsal) / `korsal` | `SA-` | Konsumen yang direferensikan |
| `sales-kurir-sub` | Penjual + pengantar mandiri | `agen` / `korsal`; konversi Sales oleh `agen` | `SS-` (baru; `SA-`/`SK-` historis tetap sah) | Scope referral + Sub Location sendiri |
| `konsumen` | Pembeli | registrasi mandiri (referral opsional) / Google (referral **wajib**) | — | Order sendiri |

**Siapa boleh membuat siapa** (terkunci, `HierarchyRules::ALLOWED_CREATIONS`):
`super_admin` → `agen`; `agen` → `korsal, sales, admin, keuangan, kurir, gudang, sales-kurir-sub, koordinator-kurir`; `korsal` → `sales, sales-kurir-sub`; `koordinator-kurir` → `kurir` (cabang sama, parent = aktor; approval Human A1-02). Selain itu tidak ada pembuat user.

Setiap user non-super_admin wajib `agent_id` non-NULL (terkunci). Hierarki tersimpan di `users` (`parent_id`, `agent_id`, `korsal_id`, `sales_id`) + closure table `user_closures`.

## 2. Enforcement dua lapis

1. **Route gate** — `EnsureRole` (`role:slug,slug…`) membaca role dari DB user terautentikasi; menolak di luar daftar (403). Ini gate kasar untuk routing/UX.
2. **Policy/Service** — ownership halus (Agen mana, downline siapa) selalu diperiksa ulang server-side: 17 policy di `backend/app/Policies/` (`OrderPolicy`, `ProductPolicy`, `ShipmentPolicy`, `UserPolicy`, `MediaPolicy`, `StockRequestPolicy`, `StockTransferPolicy`, `StockOpnamePolicy`, `SubStockRequestPolicy`, `WarehouseStockRequestPolicy`, `WarehouseSubLocationPolicy`, `StockRequestProposalPolicy`, `StockHandoverPolicy`, `ProductCategoryPolicy`, `ProductStockPolicy`, `ProductVariationStockPolicy`, `CommissionPolicy`, `DeliveryVerificationPolicy`, `SheetsConfigPolicy`) + cek scope di service/controller.

Scoping global per-Agen diterapkan lewat `BelongsToAgentScope` pada model utama (`Order`, `WarehouseStock`, dst.); `PermissionMap` (diekspos lewat `GET /auth/me`) eksplisit menyatakan dirinya hanya hint frontend, bukan boundary keamanan.

## 3. Matriks otorisasi aksi utama

Format: aksi → role yang diizinkan route+policy (evidence route di `api_v1.php`).

| Aksi | Role diizinkan | Catatan enforcement |
|---|---|---|
| Membuat order | `konsumen`, `agen`, `korsal`, `sales`, `sales-kurir-sub` | `OrderPolicy::create` mempersempit ke jaringan sendiri |
| Membuat order atas nama CRM | `agen`, `korsal`, `sales`, `sales-kurir-sub` | `CrmAccess::canPlaceOrder` |
| Melihat order | semua role ter-autentikasi (scope berbeda) | scope di `OrderController`/`OrderPolicy::view`: super_admin semua; agen/admin/keuangan/koordinator-kurir cabang; korsal downline; sales/SKS milik; konsumen milik sendiri; gudang hanya antrian diproses |
| Menaikkan status order | `super_admin`, `agen`, `admin` | office-only |
| Membatalkan order | `super_admin`; `agen`/`admin` cabang; `korsal`/`sales`/`sales-kurir-sub`/`konsumen` milik sendiri | aturan jendela §14 [05-BUSINESS-RULES](05-BUSINESS-RULES.md) |
| Tambah baris produk ke order (SC-03) | **`admin` saja** (terkunci) | `role:admin` + `OrderPolicy::addLine` |
| Sesuaikan kuantitas terpenuhi / reschedule / split | `super_admin`, `agen`, `admin` | jendela fulfilment §21 |
| Assign kurir ke shipment | `super_admin`, `agen`, `admin`, `koordinator-kurir` | `ShipmentPolicy` + eligibility SKS CR-001 |
| Progres status shipment / per-item | `super_admin`, `agen`, `admin`, `kurir`, `koordinator-kurir` (hanya sebagai executor tercatat), `sales-kurir-sub` (executor tercatat, atau self_sub **hanya untuk order yang ia buat**) | `ShipmentPolicy::updateStatus` |
| Cetak resi | `super_admin`, `agen`, `admin`, `kurir`, `sales-kurir-sub`, `koordinator-kurir` | `ShipmentPolicy::printReceipt` (keuangan/korsal/sales/konsumen sengaja dikecualikan) |
| Verifikasi pengiriman (delivery verification) | `super_admin`, `admin` (cabang sama) | `DeliveryVerificationPolicy` |
| Upload bukti pembayaran (transfer/COD/DP/cicilan) | `konsumen` (milik sendiri); `super_admin`, `agen`, `admin`, `keuangan` (cabang); `korsal`/`sales`/`sales-kurir-sub` dalam scope `payOnBehalf` | upload saja, tidak memverifikasi |
| Verifikasi/approve pembayaran, request pelunasan, konfirmasi COD | **`super_admin`, `keuangan` saja** (terkunci; Human menolak perluasan UAT-008) | `role:super_admin,keuangan` + `assertMayVerifyPayment` |
| Toggle COD paid/unpaid | `super_admin`, `keuangan` | tanpa alur bukti |
| Set tanggal jatuh tempo | `super_admin`, `admin`, `keuangan` | alasan wajib + audit |
| Status refund/additional payment; tandai retur refunded | `super_admin`, `keuangan` (list: `agen`/`admin` juga) | `OrderAdjustmentController` |
| Refund ledger (approve/attempt/initiate/complete/…) | `super_admin`, `agen`, `keuangan` (read: + `admin`) | pembagian tugas di `RefundLedgerController` |
| Pengajuan retur | `konsumen` (milik sendiri); assisted: `sales`/`sales-kurir-sub`/`korsal` (consent OTP) | `ReturnController::store`, `CrmAssistedController` |
| Review retur | `super_admin`, `agen`, `admin` | |
| Jadwal pickup retur | `super_admin`, `agen`, `admin` | `schedulePickup` |
| Pickup/konfirmasi retur kurir | `kurir` (profil kurir sendiri) | `CourierDashboardController` |
| Inspeksi retur | `gudang` | `inspect` |
| Finalisasi retur | `admin` | `finalize` |
| CRUD produk & variasi (create/read/update) | `super_admin`, `agen`, `admin` | katalog global; kebijakan "agen = super_admin untuk produk" |
| Hapus produk/variasi; tulis fee; tulis kategori | hapus & fee: `super_admin`, `agen` (**admin ditolak** terkunci); kategori: `super_admin` | `ProductPolicy::delete` dst. |
| Diskon produk & voucher | `super_admin`, `agen`, `admin` (scope cabang; super_admin `?agent_id=`) | `Promo/*` |
| Baca stok (produk/variasi/warehouse/sellable) | `super_admin`, `agen`, `admin`, `gudang` | oversight super_admin hanya baca |
| Mengubah stok (`POST /stock/adjust`) | `agen`, `admin` — **403 pada mode warehouse-authoritative (default)** | `AdjustStockRequest::authorize` |
| Buat Sub Location / assign owner | `agen`, `admin` (gudang tidak pernah) | `WarehouseSubLocationPolicy` |
| Buat transfer/opname/proposal fulfilment/permintaan stok | `gudang` | |
| Approve/reject transfer/opname/proposal/permintaan stok/toggle Factory Plan | `admin` saja | |
| Sub stock request create/cancel/receive | `sales-kurir-sub` (milik sendiri) | |
| Sub stock request approve/reject | `admin` | |
| Sub stock request execute | `gudang` | |
| Antrian order diproses gudang | `gudang` (scope server-side: `diproses` AND tanpa kurir) | `WarehouseOrderController` |
| Laporan operasional (transactions/sales) | `super_admin`, `agen`, `admin`, `korsal` | scope `ReportService::scopeToActor` |
| Laporan finansial (finance-orders/summary/payment-status/fee/refund) | `super_admin`, `agen`, `admin`, `keuangan` | |
| Network summary | `super_admin`, `agen`, `keuangan` (admin sengaja tidak — memuat fee agen) | |
| Fee agen | `super_admin`, `agen` | `agentFees` |
| Komisi (index/summary) | 9 role terbatas scope masing-masing | `CommissionController::allowedBeneficiaryRoles` |
| KPI dashboard | `agen`, `korsal`, `sales`, `sales-kurir-sub`, `gudang`, `kurir` | `DashboardKpiController` |
| Google Sheets | `super_admin`, `agen`, `admin` (admin tidak menyentuh `financial_summary`) | `SheetsConfigPolicy` |
| CMS/media library/bahasa/website settings/audit log/global toggle gateway/shipping/region import | `super_admin` | |
| Konfigurasi payment method cabang, Google Auth cabang | `agen`, `admin` (cabang sendiri) | `AgentPaymentMethodController` |
| Konfigurasi shipping provider/tarif/store profile | `agen` | `role:agen` |
| Manajemen WA cabang (device/event/template/test-number) | `agen`, `admin` (Agen cabang sendiri, resolusi server-side `WhatsAppScope`) | WA-DEC-01..04 |
| Global ON/OFF WA, GOWA console, SMTP, OTP policy | `super_admin` | `WhatsAppGatewayController` |
| Manajemen user (list/show/update/destroy/reassign/konversi) | 9 role (scope policy) | `UserController` + `UserPolicy` |
| Notification Center (in-app) | `super_admin`, `agen`, `admin`, `keuangan` | `admin_notifications` per-penerima |
| Kode referral self-service | `agen`, `korsal`, `sales`, `sales-kurir-sub` | `ProfileController` |

## 4. Isolasi data Agen (backend)

- Setiap query order/warehouse/CRM/retur/pembayaran scoping `agent_id` via global scope atau eksplisit.
- Cross-Agent akses ditolak (403) di policy (contoh: `OrderPolicy::payOnBehalf` mensyaratkan same-Agent; `WhatsAppScope` menolak `agent_id` klien di luar scope).
- `super_admin` dapat override scope (kadang dengan `?agent_id=`), namun **tidak** boleh mengubah stok (§ matriks).

## 5. Capability hints frontend (`PermissionMap`)

`backend/app/Support/PermissionMap.php` → diekspos di `GET /auth/me` → dipakai router/menu (visibility). Contoh isi per role: `super_admin` (`system.*`, `orders.*` …), `keuangan` (`finance.payment.verify`, `finance.refund.manage`, …, tanpa kemampuan operasional), `koordinator-kurir` (`orders.manage.shipment` saja), `gudang` (`stock.view.own`, `orders.view.assigned`), `sales-kurir-sub` (+ `users.create.*` sesuai hierarki). `sheets.manage` untuk super_admin/agen/admin; `stock.adjust.legacy` hanya bila warehouse tidak authoritative.

## 6. Verifikasi channel sebagai syarat aksi

| Aksi | Syarat channel | Enforcement |
|---|---|---|
| Menerima email transaksional (semua event, termasuk proof) | email terverifikasi (`email_verified_at`) | `TransactionalEmailNotifier::verifiedEmail` — row `skipped/email_not_verified` |
| Menerima WA transaksional | nomor WA ber-proof (`whatsapp_number` challenge valid) | `WhatsAppNumberProof::msisdnFor` — skip `number_not_verified` |
| Google auto-link ke akun lokal | email lokal terverifikasi & sama | `GoogleAuthService` |
| OTP consent/link CRM | email terverifikasi akun (atau WA ber-proof sebagai kanal alternatif, default off) | `IdentityOtpChallenge` |
