# 19 — IMP-008: Baseline Laravel, Isolasi REM-11, dan Kesiapan Test

Status: **dibuat 2026-11-18 pada worktree terisolasi** `primeclassy-imp008-baseline` (branch `baseline/laravel-imp008`, basis HEAD `6a14041c06e6258c9f011fcb11271c7d34d65d17`). Dokumen ini adalah fakta teknis saat ini; laporan historis tetap catatan sejarah.

## 1. Keputusan Owner yang berlaku

- **REM-11 (Historical POS lama) DIKECUALIKAN dari baseline migrasi.** Seluruh pekerjaan REM-11 dipertahankan utuh di luar baseline hanya sebagai referensi teknis (arsip eksternal ber-checksum + branch Git lokal `archive/rem11-historical-pos-20261118`).
- Historical POS akan didesain ulang di Go dalam otorisasi terpisah. **Ditunda.**
- Semua fitur bisnis lain yang disetujui (Product Card, Category, CMS, invoice, dashboard, katalog import/export, bestseller, item receipt, dst.) **dipertahankan**. Aturan bisnis, semantik pembayaran, stok, invarian finansial, dan RBAC **tidak diubah**.
- Arsitektur target migrasi: Vue 3 + Nuxt + TypeScript · backend Go (menggantikan Laravel) · PostgreSQL (menggantikan MariaDB) · Redis bila perlu. **Belum ada kode Go/Nuxt/PostgreSQL.**
- Roadmap tetap **lima paket**: IMP-008 Baseline & Isolasi REM-11 · IMP-009 Nuxt Frontend · IMP-010 Go Backend · IMP-011 PostgreSQL · IMP-012 Integrasi, Testing & Cutover.

## 2. Yang dikeluarkan dari baseline

Backend: 6 controller `Order/Historical*`, `Report/ReportRegistryController`, `HistoricalDossierResource`, 7 model `Historical*` + `ReportRegistry`, `HistoricalDossierPolicy`, `ReportRegistryPolicy`, `Services/HistoricalPos/*` (11 file), `Support/HistoricalOrderGuard`, `config/historical_pos.php`, 4 migrasi (`2026_11_14_120000`, `2026_11_15_100000`, `2026_11_15_110000`, `2026_11_16_100000`), 13 kelas test `Historical*`, 19 route (`/historical-pos/*`, `/reports/registry/*`).
Frontend: route `/historical-pos`, entri navigasi "POS Historis", `api/historicalPos.ts`, `HistoricalPosLayout`, `components/historical-pos/`, `views/historical-pos/` (7 view), `assets/historical-pos.css`, 10 spec.
Hunk REM-11 pada file bersama dibuang: `PaymentService`, `AvailablePaymentMethodService`, `PaymentMethodSeeder` (`historical_manual`), `AgentPaymentMethodController`, `Order` (WRITE_ONCE/casts historis), `OrderService`, `OrderFulfillmentService`, `OrderLineAdditionService`, `ReturnService`, `RefundLedgerService`, `InvoiceIssuanceService`, `WhatsAppLifecycleObserver`, `ConcurrencyHarness`, `.phpunit-concurrency-actor.php` (5 operasi/barrier historis), `StockRevisionMigrationTest` (blok migrasi historis). Bagian non-REM-11 pada `api_v1.php` (bestseller, catalog import/export, receipt, kategori REM-06, dst.) dan aktor konkurensi `physical-stock-aba`/`category-*`/`catalog-import-rows` **dipertahankan**.
Dokumen/evidence khusus REM-11 (laporan `REM-V2-*PHASE*`, `docs/phase5-t10-evidence/`, mockup S-1/S-2, dokumen `PCV2-TC-REM11-*`) juga dikeluarkan dari baseline dan hanya ada di arsip (arsip utama + suplemen evidence). Laporan historis lain yang menyebut REM-11 sepintas dibiarkan sebagai catatan sejarah.
`PayloadFingerprint` **dipertahankan** (dipakai `CatalogImportService` dan `OrderItemReceiptService`).

## 3. Catatan kompatibilitas data (fail-closed)

Dengan guard `HistoricalOrderGuard` dihapus, order historis lama (jika ada) tidak lagi dilindungi dari mutasi operasional biasa. Bukti DEV (read-only, 2026-11-18, `primeclassy_db`): **0** baris `payment_methods.historical_manual`, **0** order dengan `historical_origin`, **0** dossier; flag `HISTORICAL_POS_*` default OFF. **Produksi tidak diperiksa.** Sebelum paket DB/cutover, jalankan preflight read-only: `SELECT COUNT(*) FROM orders WHERE historical_origin IS NOT NULL` dan `... payment_methods WHERE code='historical_manual'`; bila ≠ 0, hentikan dan putuskan penanganan oleh Owner (jangan dihapus/diubah senyap).
DEV DB masih memuat 4 baris tabel `migrations` + tabel/kolom REM-11 yang tidak lagi ada di baseline (tidak berbahaya; harus diperhitungkan pada IMP-011 — DB baru tidak akan memilikinya).

## 4. Inventaris terverifikasi (baseline)

Migrasi 172 · policy 19 · controller 81 · model 114 · route (`artisan route:list`) 379, 0 route historis · `routes/api_v1.php` 956 baris · view frontend 84 (dashboard 47) · modul API 46 · test Feature 219, Unit 6, Support 8 · spec Vitest 49.

## 5. Test harness paralel

Manifest diregenerasi **statis** (Stage A, tanpa DB/proses) di baseline: 225 file PHP (190 paralel / 35 serial) + 49 Vitest; `manifest-verify`: INVENTORY MATCHES; `static-validate`: OK. **Digest baru: `86afb591a75425f2b82aa4369ec2c2c91b84df1eb8f23ea1e5929b550d93af53`** (lama `7a723a1d…`). Gerbang persetujuan Owner (`--i-am-the-owner`, `--stage-b-confirm`, `--approve-manifest <digest>`) tidak diubah; digest baru **belum disetujui** dan Full Test **tidak dijalankan**. Perubahan apa pun pada file test mengubah digest dan memerlukan regenerasi.

## 6. Risiko teknis tersisa

1. Digest manifest menunggu persetujuan Owner; runtime harness paralel tetap UNVERIFIED (docs/14).
2. DB produksi belum diperiksa untuk artefak REM-11 (§3).
3. `vendor/composer` classmap lokal belum di-dump ulang (composer tidak tersedia di host/image uji); autoload PSR-4 dinamis tetap benar.
4. Dokumen historis (`RECON-*`, `REM-V2-*`, `PRIMECLASSY-*`) tetap menyebut REM-11 sebagai catatan sejarah; bukan fakta teknis baseline.
5. Drift dokumen kanonis lain di luar angka yang diperbaiki (docs 01/03/06/09/14) belum diaudit baris-demi-baris.
6. Aritmetika float vs desimal/int dan perbedaan semantik lock MariaDB vs PostgreSQL adalah risiko IMP-010/011.

## 7. Penutupan IMP-008 (2026-11-18)

Owner menyetujui penutupan administratif. Owner mengonfirmasi: production berjalan tanpa REM-11 dan full parallel test sebelumnya telah dikonfirmasi Owner. **Tidak ada test baru yang dijalankan pada penutupan ini**; bukti test baseline hanya scoped (§ validasi pada laporan IMP-008: 198 test backend, type-check/build frontend, 90 Vitest). Digest manifest dicatat: `86afb591a75425f2b82aa4369ec2c2c91b84df1eb8f23ea1e5929b550d93af53`; persetujuan manifest (`--approve-manifest`) hanya diperlukan saat Owner menjalankan harness dan tidak diberikan di sini.
