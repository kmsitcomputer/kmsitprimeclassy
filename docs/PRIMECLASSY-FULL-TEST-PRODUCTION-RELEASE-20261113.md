# PRIMECLASSY V2 — FULL TEST, GIT RELEASE & PRODUCTION DEPLOYMENT (REL009)

**Tanggal:** 13 November 2026 (waktu server/WIB) · **Eksekutor:** Claude Code · **Otorisasi:** Owner (eksekusi otonom fail-closed; keputusan Owner "Option 1 — Controlled REM-11 Isolation"; cakupan rilis "semua kecuali REM-11").

## 20. Verdict akhir — `PASS — PRODUCTION DEPLOYMENT COMPLETE`

Catatan jujur tentang batas bukti smoke test ada di §17 (tidak ada uji browser/JS-console dan tidak ada render invoice produksi).

## 1. Git baseline

| Item | Nilai |
|---|---|
| Repo | `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy` · remote `origin` = `github.com/kmsitcomputer/kmsitprimeclassy` |
| Basis | `6a14041c06e6258c9f011fcb11271c7d34d65d17` (= `origin/main`) |
| Working tree utama | branch `fix/transaction-reset-financial-dependencies`, 233 entri tak ter-commit — **tidak diubah** (kecuali satu perbaikan `ProductCategory.php` yang identik dengan rilis) |
| Backup working tree | `~/primeclassy-release-backups/20261113/` (`worktree-full.tgz`, `tracked.diff`, `untracked.txt`, `status.txt`, `HEAD.txt`, SHA-256 terverifikasi, 0 file untracked hilang) |
| Release worktree | `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy-release` · branch `release/primeclassy-v2-20261113` |
| Commit rilis | **`bfe88a3c42fa2ce1670cad8debf5b40a69263a09`** (118 file, +10.008/−883) |

## 2–3. Release manifest — dimasukkan / dikecualikan

**Dimasukkan (keputusan Owner: semua kecuali REM-11):** REM-01 import/export katalog, REM-02/03 galeri gambar per variasi, REM-04 hard delete terjaga, REM-05 filter status, REM-06 hierarki kategori + create-only + unique `name_key`, REM-07 bestseller + item receipts, REM-08 atribut variasi, REM-09 pemilih sumber stok Sub, revisi stok fisik, ODR-01 `ValidAgentResolver`, Product Card dark/light, Category Catalog visual, kartu Social/Marketplace CMS, layout invoice; 5 migrasi; tes pendukung; 5 dokumen laporan REM.

**Dikecualikan (REM-11 — ON HOLD, utuh di working tree utama):** 75 file REM-11 (7 controller, 9 model, 2 policy, 1 resource, 11 service `HistoricalPos/`, `HistoricalOrderGuard`, `config/historical_pos.php`, 4 migrasi, 13 file tes backend, 26 file frontend historical-pos; termasuk `ReportRegistry*`); 14 file tracked dibiarkan di HEAD karena 100 % REM-11/di luar rilis (Order, OrderService, OrderFulfillmentService, OrderLineAdditionService, ReturnService, PaymentService, RefundLedgerService, InvoiceIssuanceService, AvailablePaymentMethodService, AgentPaymentMethodController, WhatsAppLifecycleObserver, PaymentMethodSeeder, ConcurrencyHarness, dokumen checkpoint WA); hunk REM-11 dibuang dari `routes/api_v1.php` dan `.phpunit-concurrency-actor.php`; `router/index.ts` dan `navConfig.ts` dikembalikan ke HEAD; 216 dokumen/evidence (RECON-*, REM-V2-*, `architecture-lock/`, `design-references/`, `phase5-t10-evidence/`, PNG/PDF bukti) tidak ikut commit dan tetap di working tree utama.

**Split manifest/dependency audit:** pemindaian simbol (`HistoricalOrderGuard|HistoricalPos|historical_origin|historical_manual|ReportRegistry|historical_pos|historical-pos|hp-list`) pada tree rilis = **0**, pada diff staged = **0**, pada artefak produksi = 0 (3 kecocokan nama "histor" adalah `OrderHistoryView`, `DispatchHistoryResource`, `CoordinatorDeliveredHistoryTest` — sah). Satu-satunya kopling tes ke REM-11 (`StockRevisionMigrationTest` memuat migrasi `add_historical_identity_to_orders`) dibuang bagian itu saja; sisa assertion tidak berubah.

## 4–7. Full test (harness existing `tools/parallel-test/bin/pc`)

Lima worker `primeclassy_testing_w1..w5` + serial lane `primeclassy_testing`, gerbang Owner (`--i-am-the-owner --stage-b-confirm … --approve-manifest <digest>`), manifest diregenerasi lewat `manifest-generate` resmi, `manifest-verify` + `static-validate` OK, self-test tooling 36/36.

| Run | Digest | Verdict | Tes | Lulus | Gagal | Error | Skip | Asersi |
|---|---|---|---|---|---|---|---|---|
| `dfff10927f8d` | `86afb591` | ABORTED (sengaja, SIGINT) | 2152 | 2140 | 1 | 11 | 0 | 12.606 |
| `641330babb47` | `b2113f28` | FAIL | 2408 | 2405 | 0 | 3 | 0 | 15.069 |
| `7912f8c82f33` | `b007195f` | PASS | 2408 | 2408 | 0 | 0 | 0 | 15.096 |
| (preflight ditolak) | `d2dc8ab3` | REFUSED — tidak ada tes jalan | – | – | – | – | – | – |
| **`0299a90e4e6c` (final)** | **`d2dc8ab3…`** | **PASS** | **2408** | **2408** | **0** | **0** | **0** | **15.098** |

Run final: inventori **RECONCILED** (ditemukan 2408 = dijalankan 2408, tanpa missing/unexpected/duplikat), `skipped_tests: []`, tidak ada masalah non-informasional, `STOPPED.json` `verified_stopped: true`, tidak ada proses PHPUnit/`pc-*` tersisa. Job: w1 9 tes/586 s, w2 380/601 s, w3 498/718 s, w3-vitest 399/56 s, w4 366/436 s, w5 500/393 s, serial 256/2131 s — semua exit 0. Perintah: `python3 -B tools/parallel-test/pctest/cli.py run --manifest tools/parallel-test/manifest/manifest.json --out-dir backend/storage/framework/testing/parallel --i-am-the-owner --stage-b-confirm "RUN PRIMECLASSY PARALLEL FULL TEST" --approve-manifest d2dc8ab30d7f1ecb`. Evidence: `~/primeclassy-release-backups/20261113/evidence/run-*/` dan `…/logs/pc-run-*.log`.

Gerbang frontend: `vue-tsc --build` lulus; `vite build && build-pwa` lulus; Vitest penuh (399 kasus) di dalam run final. **Lint:** baseline HEAD sudah tidak bersih (oxlint 2, eslint 14 error lama). Perubahan ini sempat menambah 6 pelanggaran, semuanya diperbaiki; hasil akhir tanpa pelanggaran baru (oxlint 2 dan eslint 13, semuanya lama, tidak disentuh).

## 9. Riwayat kegagalan / remediasi (dipisah menurut jenis)

**Application defect**
1. `ProductCategory::nameKeyColumnExists()` menyimpan jawaban "kolom belum ada" selamanya per proses → setelah migrasi menambah kolom `name_key` NOT NULL, proses berumur panjang (queue worker/proses tes) gagal insert (error 1364): 11 error + 1 failure di w3. Diperbaiki: memo hanya untuk jawaban "ada". Regresi baru: `CategoryUniqueKeyMigrationTest::test_a_stale_absent_column_answer_never_survives_the_migration_in_a_long_lived_process` (13/13 lulus).
2. Pelanggaran lint baru (5 `any` di 3 spec baru, 1 spread di `ProductFormView.vue`) — diperbaiki tanpa perubahan perilaku.

**Test fixture defect**
3. `GoogleSheetsStaffWorksheetTest::makeProduct()` membuat kategori root "Kue Kering" ganda dalam satu tes; unique key REM-06 menolaknya (3 error). Fixture memakai `firstOrCreate`; assertion tidak diubah (16/16).

**Harness / infrastructure**
4. Container uji `primeclassy-fpm-test` terikat ke working tree utama; dibuat `primeclassy-fpm-test-rel` yang me-mount release worktree (`scheduler.json` diarahkan ke nama itu **hanya di worktree, tidak di-commit**).
5. Perintah type-check/lint/build saya menulis cache ke mount dependency Linux milik harness → guard `FRONTEND_DEPS_CONTENT_CHANGED` menolak run (fail-closed, tidak ada tes mulai). Dependency dipulihkan dari salinan tree utama yang utuh (marker identik); tooling berikutnya memakai salinan terpisah. Tidak ada guard yang dilonggarkan.
6. Run pertama dihentikan sengaja lewat abort resmi (SIGINT) setelah defect #1 ditemukan; `verified_stopped: true`.

**Infrastructure produksi (ditemukan di preflight)** — `log_bin=ON`, `log_bin_trust_function_creators=OFF`, `prime_app` tanpa SUPER → migrasi trigger `add_physical_stock_revision` akan gagal (error 1419; terbukti di rehearsal). Runbook `docs/13` yang menulis "produksi `log_bin=OFF`" sudah usang (diperbaiki di commit dokumen ini).

## 11–12. Commit & push

Commit `bfe88a3` dibuat hanya setelah run final PASS; diff staged diaudit (0 secret, 0 artefak/`.env`, 0 simbol REM-11, `git diff --cached --check` bersih). Push normal (tanpa force) ke `origin/release/primeclassy-v2-20261113`; verifikasi `git ls-remote` = `bfe88a3`; `origin/main` tetap `6a14041` (tidak di-merge, sesuai AGENTS §14).

## 13. Preflight produksi (read-only)

Host `kmsitcomputer.com` (103.119.237.51), `/opt/primeccookies/app/{backend,public_html}`, container `primeccookies_{php,nginx,db}`, Laravel 11.56.1, PHP 8.3.35, `APP_ENV=production`, debug OFF, MySQL 8.0.46 (`sql_prime`, InnoDB, Dynamic). 167 migrasi Ran/0 Pending; pending pasca-deploy tepat 5 (non-REM-11). Dry-run rsync berbasis checksum: hanya 62 file (+2 direktori) berbeda = delta rilis persis, tanpa drift. Tidak ada tabel/kolom REM-11 di produksi. 5 kategori root berbeda, 0 konflik. Produksi bukan git tree — kesesuaian dengan basis dibuktikan lewat checksum.

## 14. Backup produksi

`/opt/primeccookies/deploy-backups/PRE-REL009-20261113T105648Z/` (izin 600): `database.sql` (3,9 MB, "Dump completed", 123 CREATE TABLE), `backend.tgz`, `public_html.tgz`, `compose.yaml`, `backend.env`, `md5sums.txt` (cocok). Uji restore pada MySQL 8.0 sementara (container dihapus): 123 tabel, 167 migrasi, 53 produk, 5 kategori, 106 user, 2 trigger — sama dengan produksi hidup. Backup lama tidak dihapus.

## 15. Migrasi

Rehearsal (MySQL 8.0.46, skema produksi tanpa data, `log_bin=ON`, user non-SUPER): A) tanpa hak khusus → error 1419 fail-closed pada migrasi trigger; B) prosedur yang disetujui Owner → sukses, 172 Ran/0 Pending, jumlah baris tidak berubah. Produksi (maintenance ON, `categories:unique-preflight` exit 0 dengan 5 kategori/0 konflik): tahap 1 tiga migrasi aditif sebagai `prime_app` (DONE); tahap 2 **hanya** `2026_11_14_110000_add_physical_stock_revision` dengan kredensial root via `--env-file` (tidak tercetak, tidak di command line; `log_bin_trust_function_creators` tidak diubah); tahap 3 `2026_11_16_200000_add_unique_normalized_name_key_to_product_categories` sebagai `prime_app`. Hasil: 167 → 172 Ran, 0 Pending, 126 tabel; products 53, users 106, orders 0, categories 5, opname_items 0, warehouse_stocks 0 — sebelum = sesudah. Trigger `warehouse_stocks_revision_{insert,update,delete}` ada dengan `DEFINER=root@%` (disengaja; berfungsi normal). Kolom `name_key`/`root_name_key` dan dua unique index ada. **Tidak ada migrasi REM-11 dijalankan; tidak ada `migrate` buta.**

## 16. Perintah deployment (ringkas)

1. Artefak dari `git archive bfe88a3 backend` + `frontend/dist` (sumber == commit); `artifact.sha256` 1167 file.
2. `artisan down` (www-data) → rsync backend `-rlt --no-owner --no-group` **tanpa `--delete`**, mengecualikan `storage/ vendor/ .env* bootstrap/cache/` → verifikasi `rsync -rlnc` = 0 beda → `config:clear`.
3. Migrasi (§15).
4. Frontend: `rsync -rlt --delete` dengan `--exclude` untuk `laravel.php config.js storage .well-known .htaccess index.html.* sw.js.*` dan `--filter='P /assets/*'` (bundel lama dipertahankan agar klien ber-`index.html` lama tidak 404). **Dry-run pertama menunjukkan penghapusan `laravel.php` dan `storage` karena zsh tidak memecah `$EX`; tidak dijalankan, perintah ditulis ulang dengan argumen eksplisit, dry-run kedua hanya menghapus 7 file sampah macOS `._*`.** Checksum `index.html`/`sw.js` cocok; `laravel.php`, `config.js`, `.htaccess`, symlink `storage` utuh.
5. `config:cache`, `route:cache`, `view:cache` (www-data), `queue:restart` (worker `primeclassy-queue.service` kembali `active`), `artisan up`. Tidak ada restart layanan global.

## 17. Smoke test produksi

HTTP 200: `/`, `/login`, `/categories`, `/products`, `/up`, `/api/v1/install/status`, `/api/v1/homepage` (7 blok), `/api/v1/categories` (5 root), `/api/v1/products`, `/api/v1/bestsellers`, `manifest.webmanifest`, `sw.js`, `config.js`; gambar produk `/storage/products/…jpg` 200 `image/jpeg`; 3 bundel `/assets/*` yang dirujuk `index.html` 200. Auth: `/api/v1/auth/me` 401 (bukan 404 → `laravel.php` utuh), `/api/v1/manage/products`, `/api/v1/catalog/export`, `/api/v1/order-items/1/receipts` 401 (rute baru terdaftar). **REM-11:** `/api/v1/historical-pos/dossiers` 404, `/api/v1/reports/registry` 404, 0 rute historis di `route:list`, 0 tabel/kolom historis. Tidak ada entri error baru di `laravel.log` (modifikasi terakhir 16:40 WIB, sebelum deploy); `jobs=0`, `failed_jobs=0`; **0 pesan WA/email/notifikasi admin dibuat sejak awal jendela deploy**; orders=0 sebelum dan sesudah; tidak ada data finansial historis berubah.

**Tidak diverifikasi (jujur):** (a) tidak ada uji browser/konsol JS dan tampilan Product Card Light/Dark di produksi — hanya pengecekan HTTP/aset; bukti visual Light/Dark ada di evidence UAT lokal; (b) invoice tidak dapat dirender di produksi karena `orders=0` (template ter-compile oleh `view:cache`; layout dibuktikan oleh `InvoiceLayoutTest`/`InvoiceOfficialLogoTest` di run final); (c) login/keranjang/checkout dan impor katalog tidak dijalankan di produksi untuk menghindari transaksi/data nyata.

## 18. Kesiapan rollback

Backup terverifikasi dan teruji-restore di atas. Prosedur: maintenance ON → restore `database.sql` (atau `DROP` hanya objek REL009: 3 tabel baru, 2 kolom opname, 3 trigger, `name_key`/`root_name_key` + 2 index; `down()` migrasi bersifat aditif) → ekstrak `backend.tgz`/`public_html.tgz` → `config:clear` → `queue:restart` → `up`. Karena produksi berisi `orders=0` dan `warehouse_stocks=0`, risiko kehilangan data transaksi saat rollback sangat rendah sampai ada transaksi baru.

## 19. Kepatuhan REM-11

REM-11 tetap ON HOLD: tidak ada kode, rute, konfigurasi, migrasi, tabel, atau kolom REM-11 di commit rilis maupun di produksi; seluruh pekerjaan REM-11 utuh di working tree utama (backup terverifikasi). Historical admission tidak ada (404, fail-closed) dan tidak ada perubahan finansial historis.

## Catatan tindak lanjut (bukan blocker)

- Working tree utama masih memegang pekerjaan yang sama (belum di-commit) plus REM-11; saat branch rilis di-merge, selaraskan working tree utama dengan commit `bfe88a3` (perbaikan fixture Google Sheets, 4 file lint frontend, dan manifest hanya ada di branch rilis). `main` belum di-merge menunggu keputusan Owner.
- `tools/parallel-test/config/scheduler.json` di repo tetap menunjuk `primeclassy-fpm-test`; container `primeclassy-fpm-test-rel` hanya untuk worktree rilis dan dapat dihapus (`docker rm -f primeclassy-fpm-test-rel`).
- Lint: 15 error lama (oxlint 2, eslint 13) di luar cakupan.
