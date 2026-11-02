# Prime Classy Cake & Cookies

Platform pemesanan kue & cookies **multi-cabang (multi-Agent)**: Laravel 11 JSON API + SPA Vue 3/Vite terpisah. Setiap Agen (cabang) menjalankan storefront, hierarki staf, stok, gudang, dan pengiriman sendiri; satu Super Admin mengawasi platform; konsumen membeli dari katalog bersama dan teratribusi ke cabang melalui rantai referral.

README ini hanya pintu masuk. Sistem dijelaskan dalam **dokumentasi kanonis**:

| Dokumen | Isi |
|---|---|
| [docs/01-PROJECT-OVERVIEW.md](docs/01-PROJECT-OVERVIEW.md) | Identitas, stack, struktur repo, status implementasi |
| [docs/02-SYSTEM-ARCHITECTURE.md](docs/02-SYSTEM-ARCHITECTURE.md) | Topologi, layanan, locking, idempotency |
| [docs/03-FEATURE-CATALOG.md](docs/03-FEATURE-CATALOG.md) | Katalog fitur + status (IMPLEMENTED/PARTIAL/…) |
| [docs/04-ROLES-PERMISSIONS.md](docs/04-ROLES-PERMISSIONS.md) | 11 role, matriks otorisasi, isolasi data Agen |
| [docs/05-BUSINESS-RULES.md](docs/05-BUSINESS-RULES.md) | Aturan bisnis **terkunci (LOCKED)** — jangan diubah tanpa persetujuan Owner |
| [docs/06-BUSINESS-WORKFLOWS.md](docs/06-BUSINESS-WORKFLOWS.md) | 20 alur bisnis end-to-end |
| [docs/07-FRONTEND-GUIDE.md](docs/07-FRONTEND-GUIDE.md) | SPA Vue: routing, halaman, navigasi per role |
| [docs/08-BACKEND-API.md](docs/08-BACKEND-API.md) | Referensi endpoint `/api/v1` + command + jobs |
| [docs/09-DATABASE-MODEL.md](docs/09-DATABASE-MODEL.md) | Tabel, kolom kunci, relasi (±100 tabel) |
| [docs/10-NOTIFICATIONS.md](docs/10-NOTIFICATIONS.md) | Sistem notifikasi WA/Email/In-App (REL005 & REL006) |
| [docs/11-INTEGRATIONS.md](docs/11-INTEGRATIONS.md) | GOWA, SMTP, ORS/KMSIT, RajaOngkir, Sheets, Google |
| [docs/12-SECURITY.md](docs/12-SECURITY.md) | Autentikasi, otorisasi, proteksi data, audit |
| [docs/13-DEPLOYMENT-OPERATIONS.md](docs/13-DEPLOYMENT-OPERATIONS.md) | Runbook DEV/TEST/PRODUCTION + invarian `laravel.php` |
| [docs/14-TESTING-STATUS.md](docs/14-TESTING-STATUS.md) | Harness uji + kebijakan (Full Test = Owner) |
| [docs/15-KNOWN-LIMITATIONS.md](docs/15-KNOWN-LIMITATIONS.md) | Gap ledger + keterbatasan |
| [docs/16-TRACEABILITY.md](docs/16-TRACEABILITY.md) | Register keputusan Owner |
| [AGENTS.md](AGENTS.md) | Kontrak tata kelola AI/developer |

Spesifikasi, audit, checkpoint dan laporan historis hanya ada di **Git history** (`git log -- docs/`); laporan reset dokumentasi ini: [docs/17-DOCUMENTATION-RESET-REPORT.md](docs/17-DOCUMENTATION-RESET-REPORT.md).

## Stack

- **Backend:** PHP 8.2+ (DEV 8.3), Laravel 11.56, Sanctum (SPA cookie session), MariaDB 10.11, HTMLPurifier, PhpSpreadsheet, Google API (Sheets export).
- **Frontend:** Vue 3 (Composition API), Vite 8, TypeScript, Pinia, Vue Router, vue-i18n (id/en/ar/zh), Tailwind CSS 4, CKEditor 5, Axios. Node ^22.18 / ≥24.12; package manager **npm**.
- **Layanan eksternal:** GOWA (WhatsApp), OpenRouteService, KMSIT Routing Server, RajaOngkir/Komerce, Google Sheets, Google OAuth, Nominatim.

## Setup lokal

```bash
# backend
cd backend
composer install
cp .env.example .env && php artisan key:generate
# buat database kosong + user, set DB_* di .env, lalu:
php artisan migrate --seed   # roles (11), languages, settings, payment methods, shipping providers — tanpa user demo
php artisan serve            # http://localhost:8000

# frontend
cd frontend
npm install
cp .env.example .env         # VITE_API_URL (kosong = same origin)
npm run dev                  # http://localhost:5173
```

Sanctum butuh `FRONTEND_URLS` (dengan scheme) **dan** `SANCTUM_STATEFUL_DOMAINS` (host:port, tanpa scheme) di `backend/.env`. Super Admin pertama dibuat lewat installer browser (`/setup`; `/install` = landing PWA publik) atau `php artisan tinker` — lihat [docs/13-DEPLOYMENT-OPERATIONS.md](docs/13-DEPLOYMENT-OPERATIONS.md) §5.

## Perintah utama

```bash
cd backend
php artisan test --filter=NamaTest      # scoped (database primeclassy_testing)
php scripts/run-tests-serialized.php tests/Feature/NamaTest.php
cd ../frontend
npm run type-check                      # vue-tsc
npm run build-only                      # vite build
```

Kebijakan pengujian (scoped test untuk agen; Full Test eksklusif Owner; satu runner per database): [docs/14-TESTING-STATUS.md](docs/14-TESTING-STATUS.md).

## Build & deployment

`npm run build-only` → `frontend/dist/`; `deploy/build.sh` merakit paket produksi single-domain (SPA + `laravel.php` + `.htaccess` + `config.js` + backend). Aturan deployment produksi (termasuk invarian **`public_html/laravel.php` tidak boleh dihapus**): [docs/13-DEPLOYMENT-OPERATIONS.md](docs/13-DEPLOYMENT-OPERATIONS.md) §7.

## Status

Baseline produksi/main: REL006 (`f8445e1`). Package A & B tutup di produksi; Package C diimplementasi (DEV stage gate CLOSED, deployment produksi tertunda). Rincian status per fitur: [docs/03-FEATURE-CATALOG.md](docs/03-FEATURE-CATALOG.md).
