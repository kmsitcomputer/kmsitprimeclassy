# 01 — Project Overview

Ringkasan kanonis Prime Classy V2: identitas, stack teknologi, struktur repository, dan status implementasi. Dokumen ini adalah pintu konteks pertama; detail teknis ada di dokumen lain (lihat [README](../README.md) untuk indeks lengkap).

---

## 1. Identitas dan tujuan sistem

**Prime Classy Cake & Cookies** adalah platform pemesanan kue & cookies multi-cabang (multi-Agent):

- Satu **Super Admin** mengelola platform.
- Setiap **Agen** (cabang) menjalankan storefront sendiri, hierarki staf, stok, gudang, dan operasi pengiriman.
- Konsumen membeli dari **katalog bersama (global)** dan teratribusi ke satu cabang melalui rantai referral.
- Verifikasi pembayaran, refund, dan pelaporan keuangan dipisahkan per cabang dengan otoritas khusus Keuangan.

Setiap non-super-admin user wajib terikat pada tepat satu Agen (`users.agent_id`, aturan terkunci — lihat [05-BUSINESS-RULES](05-BUSINESS-RULES.md) §3).

## 2. Stack teknologi (terverifikasi dari source)

| Lapisan | Teknologi | Evidence |
|---|---|---|
| Backend | PHP ^8.2 (DEV 8.3), Laravel 11 (^11.31; produksi 11.56.1) | `backend/composer.json` |
| Autentikasi | Laravel Sanctum — SPA cookie session (stateful), bukan token bearer | `backend/config/sanctum.php`, `backend/bootstrap/app.php` |
| Database | MariaDB 10.11 (DEV & produksi; MySQL-compatible) | `docs` historis + `backend/config/database.php` |
| Frontend | Vue 3 (Composition API, ^3.5.40), TypeScript ~6.0, Vite ^8.1.5, Vue Router ^5.2, Pinia ^4.0.2, vue-i18n (id/en/ar/zh), Tailwind CSS ^4.3.3, CKEditor 5, Axios | `frontend/package.json` |
| Node | ^22.18 atau ≥24.12; package manager resmi **npm** (lockfile otoritatif `package-lock.json`) | `frontend/package.json`, README lama (G-14) |
| Queue | `database` driver, lane `primeclassy-notify` + `default`; worker systemd di produksi | `backend/config/queue.php`, `deploy/production/primeclassy-queue.service` |
| Mail | SMTP dashboard-configurable (`mail.kmsitcomputer.com` di produksi); default config `log` | `backend/config/mail.php`, `SmtpSettings.php` |
| Layanan eksternal | GOWA (WhatsApp engine), OpenRouteService, KMSIT Routing Server, RajaOngkir/Komerce, Google Sheets, Google OAuth, Nominatim (reverse geocode) | `backend/app/Services/WhatsApp/`, `Shipping/`, `GoogleSheets/`, `Geocode/` |

## 3. Struktur repository

```
primeclassy/
├── README.md / AGENTS.md          # pintu masuk + kontrak tata kelola pengembang
├── docs/                           # dokumentasi kanonis (01–17)
├── backend/                        # Laravel 11 JSON API (prefix /api/v1)
│   ├── app/
│   │   ├── Http/{Controllers/Api/V1/*, Middleware, Requests, Resources}
│   │   ├── Models/                 # ±110 model Eloquent
│   │   ├── Policies/               # 17 policy (otorisasi halus)
│   │   ├── Services/               # business logic per domain
│   │   ├── Support/                # helper kanonis (HierarchyRules, PermissionMap, dst.)
│   │   ├── Enums, Observers, Jobs, Mail, Console/Commands
│   ├── routes/api_v1.php           # seluruh permukaan API (±914 baris)
│   ├── database/{migrations (162), seeders, data/regions}
│   ├── lang/{id,en,ar,zh}          # katalog pesan backend
│   └── tests/{Feature (191), Unit (6), Support (9)}
├── frontend/                       # Vue 3 SPA
│   └── src/{api (44 modul), views (69 halaman), components, layouts (3),
│            stores (7), router, i18n, pwa, utils}
└── deploy/                         # build.sh, konfigurasi nginx/php-fpm DEV,
                                    # systemd queue worker, template public_html
```

Folder lokal lain (untracked, bukan bagian repository Git): `design/` (referensi mockdesign lokal), `artifacts/`, berkas log Full Test. Jangan di-stage dan jangan dihapus tanpa instruksi Owner.

## 4. Status implementasi saat ini

Status per rilis terakhir yang tercatat di Git (HEAD `main` = REL006):

| Rilis | Isi | Status produksi |
|---|---|---|
| Package A | Sales-Kurir-Sub + Sub stock / Sub Location | **Tutup di produksi** |
| Package B | Siklus fulfilment/delivery + otoritas & pelaporan | **Tutup di produksi** |
| Package C | SC-03 — Admin-only add-line ke order existing | Diimplementasi, DEV UAT PASS, stage gate CLOSED; deployment produksi tertunda |
| REL004 | WhatsApp branch management, routing fallback KMSIT, account recovery | Produksi (2026-11 sekitar `419e2e8`) |
| REL005 | Notification system: WhatsApp transaksional, email transaksional, In-App Notification Center, queue worker | Produksi (`87ef993`, `38fee8f`) |
| REL006 | Notifikasi `payment_proof_uploaded` untuk Keuangan per cabang | Merge + deployment berhasil (`f8445e1`) |

Baseline produksi/main REL006: `f8445e1dcbe4a40fa4a477797f07306092ae3b68`.

Catatan penting tentang status:

- "Terdapat di kode" ≠ "teruji di produksi". Status verifikasi per fitur ada di [03-FEATURE-CATALOG](03-FEATURE-CATALOG.md).
- Sejumlah keputusan Owner disetujui sebagai **desain** namun belum diimplementasikan (OD-05 refund commission adjustment, OD-06 format parity Sheets) — rincian di [15-KNOWN-LIMITATIONS](15-KNOWN-LIMITATIONS.md).
- Full Test bukan gerbang rilis (keputusan Owner 2026-11-02); bukti review = scoped tests per area perubahan — lihat [14-TESTING-STATUS](14-TESTING-STATUS.md).

## 5. Batasan bacaan lanjutan

| Topik | Dokumen |
|---|---|
| Arsitektur, locking, idempotency | [02-SYSTEM-ARCHITECTURE](02-SYSTEM-ARCHITECTURE.md) |
| Daftar fitur & status | [03-FEATURE-CATALOG](03-FEATURE-CATALOG.md) |
| Role & permission | [04-ROLES-PERMISSIONS](04-ROLES-PERMISSIONS.md) |
| Aturan bisnis terkunci | [05-BUSINESS-RULES](05-BUSINESS-RULES.md) |
| Alur bisnis end-to-end | [06-BUSINESS-WORKFLOWS](06-BUSINESS-WORKFLOWS.md) |
| Frontend | [07-FRONTEND-GUIDE](07-FRONTEND-GUIDE.md) |
| API | [08-BACKEND-API](08-BACKEND-API.md) |
| Database | [09-DATABASE-MODEL](09-DATABASE-MODEL.md) |
| Notifikasi | [10-NOTIFICATIONS](10-NOTIFICATIONS.md) |
| Integrasi | [11-INTEGRATIONS](11-INTEGRATIONS.md) |
| Keamanan | [12-SECURITY](12-SECURITY.md) |
| Deployment & operasional | [13-DEPLOYMENT-OPERATIONS](13-DEPLOYMENT-OPERATIONS.md) |
| Pengujian | [14-TESTING-STATUS](14-TESTING-STATUS.md) |
| Keterbatasan & gap | [15-KNOWN-LIMITATIONS](15-KNOWN-LIMITATIONS.md) |
| Register keputusan | [16-TRACEABILITY](16-TRACEABILITY.md) |
