# 12 — Security

Model keamanan berdasarkan source code. Prinsip dasar: **backend adalah satu-satunya security boundary**; frontend (nav, tombol, `PermissionMap`) hanya visibility.

---

## 1. Autentikasi

- **Sanctum SPA cookie session** — stateful (bukan bearer token); frontend memanggil `/sanctum/csrf-cookie` sebelum write; `EnsureFrontendRequestsAreStateful` aktif.
- Registrasi/login/recovery di-throttle per IP (login 10/menit, register 5/menit, bucket recovery terpisah).
- Recovery akun enumeration-resistant (jawaban generik seragam).
- Google OAuth: state/nonce/PKCE server-side sekali pakai (10 menit); tanpa penyimpanan token; auto-link hanya ke akun lokal dengan email terverifikasi (§23).
- Password cast `hashed`; personal access tokens tersedia (Sanctum default) namun SPA memakai session.

## 2. Otorisasi (ringkas — detail di [04-ROLES-PERMISSIONS](04-ROLES-PERMISSIONS.md))

- Dua lapis: gate role route (`EnsureRole`, role dibaca ulang dari DB) + Policy/Service ownership (17 policy).
- Isolasi Agen: global scope `BelongsToAgentScope`; cross-Agent ditolak di policy/service; `agent_id` dari klien hanya seleksi (resolusi server-side: `WhatsAppScope`, `CrmAccess`, `OrderPolicy`…).
- Keuangan dipisahkan tugas: verifikasi pembayaran/refund = keuangan/super_admin; admin operasional tanpa uang (LOCKED §13/§24).

## 3. Verifikasi channel & identitas

| Mekanisme | Keterangan |
|---|---|
| Verifikasi email | challenge mandiri; syarat penerima email transaksional & OTP |
| Proof nomor WhatsApp | kode challenge terikat nomor; ganti nomor membatalkan proof; kode tidak pernah plaintext/log/API |
| OTP consent/link CRM | `identity_otp_challenges` (hash, expiry, attempt limit, binding, sekali pakai); kirim via email terverifikasi (WA opsional, default off) |
| RestrictedDeliveryPolicy | pengecualian terbatas pesan status ke nomor unverified (allow-list tertutup, tanpa data finansial/OTP/link) |

## 4. Proteksi data

- **Bukti pembayaran** disimpan disk privat (`proof_disk=local`), disajikan hanya via `GET /payment-proofs/{id}` dengan cek otorisasi (owner/finance/office cabang/in-scope sales/super_admin). Legacy public tetap URL lama.
- **Media** — policy per collection (avatar semua role; collection lain super_admin/agen); batas ukuran & tipe MIME ketat (JPEG/PNG/WebP).
- **Kredensial integrasi** terenkripsi at rest, write-only, tidak pernah di-echo (`agent_payment_gateway_configs`, `agent_shipping_provider_configs`, `google_auth_settings`).
- **Secret body OTP** (`whatsapp_messages.secret_body`) terenkripsi; kode tidak pernah di-log/di-audit.
- **Sanitasi error** — `GatewayResult::sanitize` mencegah kebocoran URL/kredensial pesan error gateway.
- **SSRF guard** — `EndpointGuard` untuk endpoint user-supplied (KMSIT): skema http(s), tanpa kredensial tersemat, alamat publik atau host ter-trust-list, redirect OFF, IP di-pin.
- **XSS** — HTMLPurifier (backend) untuk konten CKEditor/CMS.
- **CSRF** — Sanctum stateful + `PreventApiResponseCaching` (anti stale csrf-cookie dari cache tepi).

## 5. Rate limiting & throttle

| Endpoint | Throttle |
|---|---|
| login/register/recovery | 5–10/menit + bucket terpisah |
| buat order / checkout / CRM write | 30–60/menit |
| WA test send / device pairing | 10/menit + `agent-wa-device` |
| SMTP test | 6/menit |
| WhatsApp delivery | 6/jam per penerima, 20/menit gateway (hard bounds) |
| OTP | attempt limit + expiry per challenge |

## 6. Keamanan data & privasi

- **Isolasi tenant (Agen)** di semua query order/warehouse/CRM/pembayaran; laporan scope aktor di backend.
- **Tanpa data sensitif di notifikasi in-app** (tanpa nomor telepon/OTP/bank/kredensial).
- **Aktor proyeksi operasional** — Gudang/Kurir tanpa data finansial (§20 [05-BUSINESS-RULES](05-BUSINESS-RULES.md)).
- **PWA branding** dari favicon kanonis; ikon hanya derivatif teknis.
- **Retensi (kontrak):** WA ledger 90 hari; OTP 30 hari; consent aktif+5 tahun (floor; legal hold menangguhkan); `consent_events` append-only di boundary model. Eksekusi penghapusan butuh approval Owner + backup (lihat [10-NOTIFICATIONS](10-NOTIFICATIONS.md) §9).
- **Ujian audit eksternal terakhir** (server produksi, 2026-10-04, laporan lokal Owner): tidak ditemukan indikasi kompromi aktif; catatan keamanan jaringan server di luar scope repository ini.

## 7. Audit

- `activity_logs` (causer, subject, event, properties, IP, UA) — ditulis pada aksi bisnis penting (order, pembayaran, retur, stok, user, consent, WA device, add-line `order_item.added`, proof `payment.proof_submitted`…).
- Refund ledger events & stock movements: append-only immutable.
- Viewer super_admin (`GET /admin/audit-logs`) — read-only.

## 8. Keamanan konkurensi

- Lock kanonis Order → Stock Request → inventory; `canonicalReservationTargets` untuk multi-target (mencegah deadlock).
- Idempotency di semua tulis kritis (§7 [02-SYSTEM-ARCHITECTURE](02-SYSTEM-ARCHITECTURE.md)).
- Anti double-count bukti pembayaran (bukti verified tidak bisa di-replace §24).

## 9. Celah tercatat (tidak senyap)

- ~~AU-01 / G-07~~ — **FIXED (REM-004, 2026-10-29):** registrasi tanpa Agen aktif mengembalikan HTTP 503 + `registration_unavailable` (diverifikasi di source, `AuthController.php:61-66`).
- Server produksi tanpa firewall aktif (temuan audit 2026-10-04 — operasional server, di luar scope repo).
