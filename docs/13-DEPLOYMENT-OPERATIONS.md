# 13 — Deployment & Operations

Runbook DEV / TEST / PRODUCTION berdasarkan repository (prosedur existing; **bukan** instruksi menjalankan deployment). **Tidak ada aksi produksi (deploy, migrate, cache, data) tanpa otorisasi eksplisit Human untuk langkah itu.** Tata kelola: [AGENTS](../AGENTS.md).

---

## 1. Lingkungan

| | DEV | TEST | PRODUCTION |
|---|---|---|---|
| Path | `/www/dev/primeclassy` (server DEV) · `C:\ServBay\www\primeclassy` (workstation lokal) | checkout yang sama | `/opt/primeccookies/app/{backend,public_html}` (Compose project `primeccookies` di `/opt/primeccookies`; container `primeccookies_php`/`_nginx`/`_db`; bukan working tree Git; diverifikasi 2026-11-11, path `/www/wwwroot/...` lama sudah usang) |
| Database | `primeclassy_dev` (MariaDB 10.11) | `primeclassy_testing` | `sql_prime` (container `primeccookies_db`, MySQL 8.0.46; diverifikasi 2026-11-11) |
| `APP_ENV` | `local` | `testing` | `production` (`APP_DEBUG=false`) |
| Web | nginx vhost :8080 (DEV server) / `artisan serve` :8000 + Vite :5174 (lokal) | PHPUnit saja | nginx single-domain: SPA + `public_html/laravel.php` |
| Config | `backend/.env` (DEV) | `backend/.env.testing` + `phpunit.xml` | `.env` produksi sendiri — **tidak pernah** diganti DEV |

Host produksi: `kmsit` (SSH alias), Docker `primeccookies_php` (compose `/opt/primeccookies`, backend `/var/www/backend`), Laravel 11.56.1, `QUEUE_CONNECTION=database`.

## 2. Konfigurasi `backend/.env`

| Kebutuhan | Key / lokasi |
|---|---|
| App | `APP_NAME/ENV/DEBUG/URL/KEY` (per environment; jangan pakai ulang KEY DEV) |
| Database | `DB_*` (mysql) |
| Sanctum/CORS | `FRONTEND_URLS` (dengan scheme), `SANCTUM_STATEFUL_DOMAINS` (host[:port] tanpa scheme), `SESSION_SECURE_COOKIE=true` (produksi HTTPS). Tanpa `SANCTUM_STATEFUL_DOMAINS` ⇒ 500 "Session store not set on request" |
| Warehouse | `WAREHOUSE_STOCK_AUTHORITATIVE` (default true; jangan set false di produksi) |
| Google Sheets | `GOOGLE_SHEETS_ENABLED`, `GOOGLE_SHEETS_CREDENTIALS_PATH` (JSON service account di luar public/Git) |
| Geocode | `GEOCODE_PROVIDER=nominatim` (default), `GEOCODE_BASE_URL`, `GEOCODE_*` |
| KMSIT trust | `KMSIT_TRUSTED_HOSTS` (host privat yang diizinkan operator) |
| Mail/storage | `MAIL_*`, `FILESYSTEM_DISK` |
| Frontend | `frontend/.env` → `VITE_API_URL` (kosong = same-origin), `VITE_GOOGLE_MAPS_API_KEY` opsional; override runtime produksi `public_html/config.js` (`window.__APP_CONFIG__.API_URL`) |

**Secret di DB, bukan `.env`:** kredensial per-Agen (payment gateway, shipping provider — terenkripsi, dashboard Agen) + SMTP + GOWA (settings, dashboard Super Admin). `.env` gitignored; hanya `.env.example` di-commit.

## 3. DEV

Server DEV (`/www/dev/primeclassy`): vhost `deploy/nginx-dev-8080.conf` (`apply-nginx-dev-8080.sh`), PHP-FPM 8.3 socket, `start-php-fpm-dev.sh`. Workstation lokal (ServBay, Windows):

```powershell
# Backend — port 8000
cd C:\ServBay\www\primeclassy\backend
php -c C:\ServBay\etc\php\8.3\php.ini artisan serve --host=127.0.0.1 --port=8000

# Frontend (Vite) — port 5174
cd C:\ServBay\www\primeclassy\frontend
$env:VITE_DEV_PROXY_TARGET="http://127.0.0.1:8000"
npm run dev -- --host 127.0.0.1 --port 5174 --strictPort

# Queue worker (hanya saat job antrean perlu jalan)
php artisan queue:work --queue=primeclassy-notify,default --stop-when-empty
```

Cek konflik port sebelum mematikan proses apapun (`Get-NetTCPConnection -LocalPort 8000` → identifikasi CommandLine; jangan bunuh server proyek lain). GOWA DEV lokal: container `primeclassy-gowa-dev` (`http://127.0.0.1:3001`; jangan sentuh container GOWA lain). Noise CLI DEV yang jinak: `Cannot load Zend OPcache` / `mbstring already loaded`. Untuk `GoogleAuthTest` CLI: set `$env:OPENSSL_CONF`.

**Tool maintenance DEV:** `primeclassy:reset-dev-transactions --confirm="RESET DEV TRANSACTIONS"` — DEV saja (guard: tolak `APP_ENV=production` & DB ≠ `primeclassy_dev`); menghapus data transaksional, menjaga master + saldo fisik (hanya `quantity_reserved` di-nolkan); prosedur: dump DEV dulu → jalankan → baca laporan → `cache:clear` → smoke.

## 4. TEST

- DB `primeclassy_testing` saja; guard eksak (`Tests\Support\TestDatabaseGuard`); lock runner tunggal MySQL (`TestRunLock`) — prosedur lengkap: [14-TESTING-STATUS](14-TESTING-STATUS.md).
- Runner serial: `php scripts/run-tests-serialized.php tests/Feature/Nama.php`.

## 5. Install pertama & Super Admin pertama

- Browser installer `/setup` (requirements → database → URL app → Super Admin pertama → migrate+seed → finalize → lock). Lock `backend/storage/app/installed.lock` menyegel permanen (403). (`/install` = landing PWA publik.)
- Alternatif manual: `php artisan migrate --seed` (roles 11, languages, settings, payment methods, shipping providers — tanpa user demo) → `tinker` buat super_admin → `php artisan storage:link` (layout non-single-domain saja).
- **Jangan pernah** `migrate:fresh`/`--seed` di produksi.

## 6. Backup (wajib sebelum migrasi/deployment produksi)

Tiga hal: database, media (`backend/storage/app/public` — tak bisa direproduksi dari DB), `.env` (tersimpan aman, tidak berdampingan dengan backup publik). Tanpa password di command line — pakai `--defaults-extra-file` mode 600.

```bash
mysqldump --defaults-extra-file=<tmp-600> --single-transaction --routines --triggers <db> > database.sql
```

Verifikasi: command sukses, file ada & tidak kosong, diakhiri `-- Dump completed`, jumlah CREATE TABLE wajar. **Dump yang tidak bisa di-restore bukan backup**: sebelum schema change catat juga `--no-data` dump, manifest `COUNT(*)`/`CHECKSUM TABLE`, `SHOW CREATE TABLE` tabel ter-sentuh, `sha256sum -c`, lalu restore ke scratch database terisolasi dan bandingkan manifest. Langkah penghapusan baris: tambah `--complete-insert` dump tabel terdampak + `SELECT *` pra-hapus.

Contoh layout: `/root/primeclassy-backup-<package>-<YYYYMMDD>/{backend,public_html,database.sql}`; REL005: `/opt/primeccookies/deploy-backups/PRE-REL005-…/`.

## 7. PRODUCTION

### 7.1 Layout

```
/www/wwwroot/primeccookies.com/primeclassy/
├── backend/        Laravel (own .env, storage/, uploads) — tidak publik
└── public_html/    document root: Vue build + laravel.php + .htaccess + config.js + storage symlink
```

nginx merute `/api`, `/sanctum`, `/up` ke Laravel via `public_html/laravel.php` (mencari `../backend`); sisanya SPA history fallback. `bootstrap/app.php` mengalihkan public path saat `public_html` ada.

### 7.2 INVARIAN KRITIS — `public_html/laravel.php`

> **Jangan pernah menghapus `public_html/laravel.php` saat deployment frontend.** Sync destruktif menghapusnya → semua panggilan API 404 sementara frontend tetap tampil (insiden produksi nyata). Selalu exclude.

Deploy frontend kanonis (setelah backup terverifikasi & otorisasi):

```bash
sudo rsync -a --delete \
  --exclude='.well-known' \
  --exclude='storage' \
  --exclude='laravel.php' \
  /www/dev/primeclassy/frontend/dist/ \
  /www/wwwroot/primeccookies.com/primeclassy/public_html/
```

Pertahankan juga `public_html/storage` (symlink), `.well-known` (ACME), `config.js`, aturan `.htaccess`/nginx — **dry-run dulu** (`rsync -an --itemize-changes`) dan baca daftar hapusan. Bila `laravel.php` hilang: pulihkan dari backup pra-deploy (template `deploy/public_html/laravel.php`). Sumber branding PWA: favicon kanonis (`site_favicon_media_id`); `frontend/public/icons/*` hanya derivatif teknis.

### 7.3 Prosedur deployment & migrasi

1. **Preflight (read-only):** konfirmasi branch/commit, UAT DEV + Stage Gate Human, tree bersih, scoped test per area + type-check/build hijau (Full Test bukan gerbang — keputusan Owner 2026-11-02). Cek `php artisan migrate:status` produksi untuk kumpulan Pending; review tiap migrasi (additive? kompatibel MariaDB 10.11? dampak data?).
2. **Backup** (§6): DB + backend + public_html; verifikasi.
3. **Maintenance mode ON** untuk jendela kode/schema tak kompatibel: `php artisan down`.
4. **Deploy backend whitelist** (jangan salin `.env`/`storage/`/uploads/sessions/log DEV); `composer install --no-dev --optimize-autoloader`.
5. **Migrate:** pastikan *Pending* → `php artisan migrate --force` → pastikan *Ran*. Jangan pernah `migrate:fresh`/`db:wipe`/truncate/FK checks off.
6. **Rekonsiliasi pra-live:** row count & FK/orphan tabel ter-sentuh, default historis, invarian bisnis (role = 11; `order_items` count; kolom nullable baru NULL di historis; kepemilikan Sub; jumlah warehouse stock/movement terjaga).
7. **Cache:** `config:clear && config:cache` (juga route & view) **sebagai user web server** — `docker exec -u www-data -w /var/www/backend primeccookies_php php artisan …`. Cache yang ditulis root menyebabkan permission error runtime (`storage/framework/views`) — insiden nyata pasca-REL003; bila terjadi: `chown -R www-data:www-data` storage + bootstrap/cache, rebuild cache, restart `primeccookies_php`.
8. **Deploy frontend** dengan perintah kanonis (§7.2).
9. `php artisan up`.
10. **Smoke** (§7.4). Catat path backup, migrasi Ran, angka rekonsiliasi, hasil smoke di checkpoint.

**Catatan migrasi:** `2026_10_01_100000` membuat 2 trigger BEFORE INSERT/UPDATE `shipments` — server dengan binary logging aktif butuh `SUPER`/`log_bin_trust_function_creators=1`. **Diverifikasi 2026-11-13 (REL009): produksi `log_bin=ON`, `log_bin_trust_function_creators=OFF`, dan `prime_app` tanpa SUPER** — migrasi yang membuat trigger (`2026_11_14_110000_add_physical_stock_revision`) gagal fail-closed (error 1419) bila dijalankan sebagai `prime_app`; jalankan migrasi itu saja (`--path=…`) dengan kredensial root lewat `docker exec --env-file <(…)` (tanpa mencetak/command-line password), tanpa mengubah variabel server; trigger berdefiner `root@%`. `down()` migrasi rename role Package A bukan inverse bersih — jangan andalkan rollback. Migrasi REL005 (`email_messages`, `admin_notifications`) ter-apply produksi; migrasi REL006 (`2026_11_02_100000` dst., `2026_11_04_*`) — status produksi: rilis ter-deploy (laporan Owner REL006 merge & deploy berhasil); verifikasi `migrate:status` saat preflight berikutnya.

### 7.4 Smoke test

- HTTPS home 200; `GET /up` 200; `GET /api/v1/install/status` 200; `GET /api/v1/homepage` 200; `GET /api/v1/auth/me` tanpa auth → 401 (bukan 404 — 404 di `/api/*` = `laravel.php` hilang).
- Browser: login per role kunci, daftar produk, detail order, halaman warehouse; untuk rilis yang mengubah API: panggil endpoint tanpa auth (harap 401/403) lalu sebagai role berwenang.
- `php artisan about` → environment production, cache aktif, maintenance OFF.

### 7.5 Rollback

Maintenance ON → restore dump database terverifikasi + `backend`/`public_html` backup → verifikasi ulang → `up`. `down()` migrasi hanya dengan approval Human eksplisit dan hanya untuk perubahan additive. Setelah ada trafik nyata: jangan rollback aritmetik/truncation — pakai rekonsiliasi & koreksi terkontrol.

### 7.6 Aturan keamanan produksi (rekap)

Jangan salin `.env`/kredensial DEV ke produksi · pertahankan `.env`, uploads, kredensial, sessions, logs, lock produksi · tidak ada reset destruktif · backup sebelum migrasi · maintenance ON saat jendela tak kompatibel · rekonsiliasi pra-live · whitelist + dry-run.

### 7.7 Queue worker & scheduler (AKTIF sejak 2026-11-02)

- **Worker:** systemd `primeclassy-queue.service` (template `deploy/production/primeclassy-queue.service`) — `queue:work database --queue=primeclassy-notify,default` sebagai `www-data` dalam container; `flock` anti-instance ganda; `Restart=always`; `ExecStop` = `queue:restart`; `$tries=1` (crash = re-claim, bukan retry duplikat). Lane `primeclassy-notify` didahulukan agar backlog `default` tidak menunda notifikasi. Menutup WhatsApp notification + queued account-registered email; OTP tetap sinkron fail-closed.
- **Scheduler:** `/etc/cron.d/primeccookies-scheduler` menjalankan `schedule:run` tiap menit via flock (sudah ada; `schedule:list` = tanpa task / no-op). Repo tidak menambah artefak scheduler.
- **Aktivasi 2026-11-02 (Owner):** preflight jobs/failed_jobs/whatsapp_messages = 0, tanpa worker sebelumnya; backup `/root/primeclassy-backup-queue-20261102/`; pasca: 1 worker (PID 856), `/up` 200, endpoint publik 200; tanpa pesan uji E2E.
- **Rollback worker:** `systemctl disable --now primeclassy-queue.service`, hapus unit, `daemon-reload`. Tanpa perubahan kode/data/schema.
- **Aturan:** jangan jalankan perintah worker DEV (§3) ke produksi; jangan tambah perintah retensi ke scheduler; jangan ubah konfigurasi gateway WA di sini.

## 8. Perintah maintenance & data

| Command | Peruntukan / hati-hati |
|---|---|
| `regions:import [--reset] [--force]` | load data wilayah (`--reset` hapus dulu) |
| `products:backfill-sku [--apply]` | backfill SKU deterministik (dry-run default; jalankan `system:audit-catalog-hierarchy` dulu) |
| `system:audit-catalog-hierarchy` | audit SKU/hierarki read-only |
| `warehouse:reconcile [--agent_id=]` | diagnostik read-only bucket/reservasi/movement |
| `warehouse:migrate-legacy-stock` | tooling backfill legacy→Transit (cutover produksi sudah selesai; non-dry-run butuh backup + otorisasi) |
| `transactions:reset [--dry-run] [--force]` | **destruktif** — hapus data turunan transaksi, jaga master & stok fisik; produksi: otorisasi Human + backup terverifikasi + audit dry-run dulu; menolak produksi tanpa `--force` |
| `products:reset [--force] [--backup-verified=]` | **destruktif** — reset domain produk penuh beserta relasinya; `--force` ditolak tanpa referensi backup terverifikasi |
| `primeclassy:reset-dev-transactions` | DEV saja (§3) |
| `primeclassy:ar-reconciliation-report` | bukti OD-K5-4 read-only |
| `refund:ledger-backfill` | backfill ledger (insert-or-skip; apply test-DB saja) |
| `primeclassy:whatsapp-retention-report` / `-minimize` | retensi WA — minimize: dry-run default; eksekusi butuh otorisasi Owner + `--execute --backup-confirmed`; **tidak di-schedule** |
| `otp:retention-purge` | purge OTP expired (kontrak 30 hari) |
| `primeclassy:admin-notifications-prune` | prune notification center (dry-run/`--execute`; tidak di-schedule; produksi = langkah Owner) |

`transactions:reset` mencakup snapshot finansial dan invoice, laporan awal, perubahan jatuh tempo, ledger refund, penyesuaian komisi, consent terkait order, bukti penerima refund, dan penolakan pengiriman. Log WhatsApp/email/notifikasi admin yang terkait order atau subject transaksi ikut dihapus sebelum order; log OTP/akun/sistem yang tidak terkait transaksi dipertahankan. Penghapusan child-before-parent serta pemutusan self-reference/cycle return–consent berjalan dalam satu transaksi DB; kegagalan FK membatalkan seluruh perubahan DB. Ini operasi maintenance destruktif yang diotorisasi tersendiri, bukan perubahan aturan append-only pada alur bisnis normal. Master CRM, konfigurasi, dan semantik stok existing tetap dipertahankan (stok Agent dipulihkan dari movement transaksi; stok fisik warehouse tidak diubah). `primeclassy:reset-dev-transactions` adalah command terpisah dengan guard database eksak; jangan mengganti konfigurasi database untuk melewati guard.

Prosedur rinci `transactions:reset`/`products:reset` (boundary, syarat sukses, "on error STOP") mengikuti praktik terdokumentasi: dry-run → tinjau audit lengkap → eksekusi → verifikasi; jangan substitusi `migrate:fresh/refresh/reset`, `db:wipe`, `TRUNCATE`, `FOREIGN_KEY_CHECKS=0`.

## 9. Catatan operasional integrasi

- **Google Sheets:** service account terpusat (key JSON privat, mis. `backend/storage/app/private/google/service-account.json`); share spreadsheet tujuan sebagai **Editor**; "Periksa koneksi" (read-only) di dashboard; error umum `PERMISSION_DENIED`/`API_DISABLED`/`SPREADSHEET_NOT_FOUND`/`RATE_LIMIT`. Sync manual satu arah ≤ 10.000 baris, replace per tab — bukan backup database. Dataset `order_initial_transactions` = Laporan A (24 kolom, snapshot); `order_payment_positions` = Laporan B (15 kolom, live). Laporan A pakai `ROW_NUMBER()` ⇒ butuh MariaDB ≥ 10.2 / MySQL ≥ 8.0. Filter periode dihitung kalender WIB. `transactions:reset`/`products:reset` ikut menghapus baris snapshot dalam scope-nya.
- **RajaOngkir / ORS:** kredensial per-Agen di dashboard; key tidak pernah dikembalikan API; base URL ORS dikontrol server. Rotasi key RajaOngkir bila pernah terekspos log/trace.
- **Uploads:** disk `public` via symlink `storage`; 404 semua upload = symlink hilang.
- **GOWA produksi:** `wagateway.kmsitcomputer.com` (plan terverifikasi); konfigurasi hanya di console dashboard (Base URL, user/pass terenkripsi, device id); tanpa nilai WA di `.env`. Pesan riil DEV hanya ke nomor owner-authorised. Baca status: `queued` menunggu/retry · `sent` acknowledged · `failed` gagal pasti · `unknown` mungkin terkirim (cek telepon SEBELUM retry) · `skipped` sengaja tidak dikirim (alasan tampil). Pesan Agen tanpa device terpasang: skip `agent_device_not_ready`.
- **KMSIT:** fallback jarak Kurir Toko; konfig per-Agen (base URL + key write-only + profile); Super Admin hanya toggle global (default nonaktif); kontrak API `GET {base}/api/v1/routes?profile=car|motorcycle&coordinates=…` + header `X-API-Key`; coverage Bandung Raya (bbox kontrak) — di luar itu gateway menjawab VALIDATION_ERROR dan PrimeClassy fail closed.
- **Nominatim:** default tanpa API key; HTTPS; timeout default 4s; cache koordinat 24 jam; wajib patuh usage policy Nominatim (maks 1 request/detik aplikasi, User-Agent identitas, atribusi OSM).
- **SMTP:** dashboard Super Admin; menyimpan tidak otomatis menyalakan — centang switch; matikan untuk kembali ke `.env` instan; jangan test ke alamat bukan milik sendiri.

## 10. Troubleshooting

| Gejala | Kemungkinan |
|---|---|
| Login jalan, request berikut 401/419 | `FRONTEND_URLS`/`SANCTUM_STATEFUL_DOMAINS` tidak cocok origin |
| 500 "Session store not set on request" | `SANCTUM_STATEFUL_DOMAINS` kosong |
| Frontend tampil tapi `/api/*` 404 | `laravel.php` terhapus — pulihkan (§7.2) |
| Reload `/products/foo` 404 | fallback SPA hilang (`try_files … /index.html`) |
| Gambar upload 404 | symlink `storage` hilang / document root salah |
| 500 tanpa detail (produksi) | wajar (`APP_DEBUG=false`); baca `backend/storage/logs/laravel.log` |
| Opsi RajaOngkir 422 | key/origin/kurir kode agen, berat produk, hierarki desa pembeli belum lengkap |
| `MAC is invalid` di log lama | histori perubahan `APP_KEY`; bukan defek aktif |
| Config basi setelah edit `.env` | `php artisan config:clear` (+ re-cache produksi) |
| Pesan WA `rate_limited:*` | volume cap — dikirim ulang oleh delayed job |
| Pesan WA stuck `queued` | worker tidak jalan (§7.7) |
| Riwayat deploy REL005 | catatan lengkap ada di Git history (commit `38fee8f`/`87ef993`) |

## Catatan rilis REL008 (2026-11-11)

- **Revisi terpasang:** `5d34f25` (`origin/main`, fast-forward dari `d7997c4`, tanpa force); basis produksi sebelumnya = `d7997c4` + hotfix manual `ResetTransactions.php` (digantikan versi final `fc8e340`). Verifikasi: 118 file backend yang berbeda di produksi = persis `git diff d7997c4..5d34f25`; setelah deploy 0 selisih konten terhadap artefak rilis; frontend dibangun dari ekspor bersih commit (identik dengan `dist`), `sw.js` versi `cfa3b60ba266a673`.
- **Gerbang:** Parallel Full Test run `4b6f27f7775b` PASS (2.227/2.227, 14.348 asersi). Rehearsal migrasi pada MySQL 8.4 dengan skema produksi: maju, `down`, maju (semua OK).
- **Backup:** `/opt/primeccookies/deploy-backups/PRE-REL008-20261111T063050Z/` (dump `sql_prime`, tar backend, tar public_html, compose.yaml, md5sums); dump diuji restore pada MySQL 8.0 sementara (122 tabel; migrations 162, users 104, roles 11, orders 3 sama dengan produksi). Skrip rollback: `/root/rel008-5d34f25/rollback.sh`.
- **Eksekusi:** `artisan down` → rsync backend tracked-only (tanpa `--delete`; `.env`, `vendor`, `storage`, `bootstrap/cache` tidak disentuh) → `config:clear` → `migrate --force` (5 DONE: 162 → 167) → `config/route/view:cache` sebagai www-data → ekstrak `dist` tanpa `config.js`/`.htaccess`/`laravel.php` (tanpa `--delete`) → `chown` storage dan bootstrap/cache → `docker compose restart php` → `artisan up`. Tanpa insiden.
- **Smoke (non-destruktif):** `/`, `/up`, `/api/v1/install/status`, `/api/v1/homepage` 200; `/api/v1/auth/me` 401; 8 endpoint baru tanpa auth 401; rute terdaftar; skema baru ada (123 tabel); matriks otorisasi invoice 13/13 pada kode produksi (model di memori); tanpa 5xx. **Alur berdata (penugasan parsial, notifikasi kekurangan, invoice per akun) belum diuji di produksi** karena tidak ada data transaksi/akun uji dan tidak ada penulisan produksi yang diotorisasi; bukti fungsional berasal dari Full Test DEV.


## Catatan rilis REL009 (2026-11-13)

- **Revisi terpasang:** `bfe88a3` (branch `release/primeclassy-v2-20261113`, basis `6a14041`; `main` belum di-merge). Semua pekerjaan kecuali REM-11 (ON HOLD, tidak ada kode/rute/migrasi/tabel REM-11 di produksi).
- **Gerbang:** Parallel Full Test run `0299a90e4e6c` PASS (2408/2408, 15.098 asersi, inventori RECONCILED); `vue-tsc` + build OK. Rehearsal migrasi MySQL 8.0.46 (skema produksi, `log_bin=ON`): tanpa SUPER → error 1419 pada migrasi trigger; prosedur root-satu-langkah → OK.
- **Backup:** `/opt/primeccookies/deploy-backups/PRE-REL009-20261113T105648Z/` (dump, backend, public_html, compose, md5sums; restore diuji: 123 tabel/167 migrasi/53 produk/106 user).
- **Migrasi:** 167 → 172 (5 DONE; trigger oleh root, sisanya `prime_app`); baris tabel tidak berubah.
- **Frontend:** `rsync --delete` dengan `--exclude` eksplisit (`laravel.php config.js storage .well-known .htaccess index.html.* sw.js.*`) dan `--filter='P /assets/*'`. **Pelajaran:** di zsh variabel `$EX` berisi beberapa `--exclude` tidak dipecah — selalu tulis argumen eksplisit dan baca dry-run (dry-run pertama menunjukkan `laravel.php` akan terhapus).
- Laporan lengkap: [PRIMECLASSY-FULL-TEST-PRODUCTION-RELEASE-20261113](PRIMECLASSY-FULL-TEST-PRODUCTION-RELEASE-20261113.md).
