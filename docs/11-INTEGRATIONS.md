# 11 — Integrations

Integrasi eksternal yang terdefinisi di repository. Kredensial tidak pernah dicantumkan di dokumen ini; semua tersimpan terenkripsi di DB atau `.env`.

| Integrasi | Tipe | Status | Evidence |
|---|---|---|---|
| GOWA (WhatsApp engine) | HTTP API ke `go-whatsapp-web-multidevice` v9 | Terkonfigurasi produksi (`wagateway.kmsitcomputer.com`); aktivasi per event/Agen | `Services/WhatsApp/{GowaClient,GowaDeviceClient,WhatsAppSettings}` |
| SMTP (email transaksional) | SMTP dashboard-configurable | Aktif produksi (`mail.kmsitcomputer.com`) | `Services/Mail/SmtpSettings`, `config/mail.php` |
| OpenRouteService (ORS) | REST routing (jarak Kurir Toko) | Terimplementasi; aktivasi global + per-Agen | `Services/Shipping/OpenRouteDistanceCalculator` |
| KMSIT Routing Server | REST routing fallback (RT-DEC-01..05) | Terimplementasi; provider seed nonaktif; endpoint per-Agen | `Services/Shipping/KmsitDistanceCalculator`, `Support/EndpointGuard` |
| RajaOngkir / Komerce V2 | REST tarif ekspedisi + master kurir | Terimplementasi; provider seed nonaktif | `Services/Shipping/Providers/*`, `shipping_couriers` |
| Nominatim | Reverse geocoding "Gunakan Lokasi Saya" | Terimplementasi (default Human-approved) | `Services/Geocode/*` |
| Google Sheets | One-way export via Google API | Terimplementasi (17 dataset, batas 10.000 baris) | `Services/GoogleSheets/*`, `config/google_sheets.php` |
| Google OAuth (sign-in/linking) | OAuth 2.0 + PKCE | Terimplementasi; global + konfigurasi per-Agen | `Services/Auth/GoogleAuthService`, `google_auth_settings` |
| Google Maps | Frontend picker (opsional) | Terimplementasi (opsional) | `frontend/src/components/maps` |
| Gateway pembayaran (Xendit/Tripay/Stripe) | — | **DIHAPUS by Owner** — tanpa webhook; hanya manual transfer + COD | §13 [05-BUSINESS-RULES](05-BUSINESS-RULES.md) |

## 1. GOWA — WhatsApp

- **Transport:** `GowaClient` memanggil endpoint GOWA (send message, device pairing via `GowaDeviceClient`). Base URL & kredensial dari settings dashboard (`WhatsAppSettings` — Super Admin).
- **Device per Agen:** `agent_whatsapp_devices` — pairing QR/pair code; reconnect/logout/replace; `sender_device_id` per pesan.
- **Template & saklar:** global (Super Admin console) + override per-Agen (`agent_whatsapp_event_settings`); test-number allow-list per Agen; pesan uji hanya dari slot Agen ke nomor terdaftar (rate-limited).
- **Keamanan:** nomor WA penerima wajib ber-proof (`whatsapp_number` challenge); kode OTP tidak pernah di-log/plaintext; error disanitasi (`GatewayResult::sanitize`).

## 2. SMTP — email transaksional

- Konfigurasi global oleh Super Admin (`/admin/integrations/smtp`): host/port/user/encryption + test connection/test email (throttle).
- Gate transaksional terpisah ON/OFF; email hanya ke alamat terverifikasi. Queue job `SendTransactionalEmailJob`.

## 3. OpenRouteService & KMSIT — Kurir Toko

- **ORS** = sumber jarak utama untuk metode Kurir Toko (`provider_code=openroute`): jarak rute jalan → tarif per-Agen.
- **KMSIT fallback (RT-DEC):** ORS gagal/tidak dikonfigurasi/OFF global/OFF per-Agen → endpoint KMSIT aktif Agen itu. Fail-closed (422) bila tidak ada hasil valid. Endpoint di-encode (http/https publik atau host ter-list `KMSIT_TRUSTED_HOSTS`); API key write-only terenkripsi; redirect OFF; IP ter-vet di-pin. Cache hasil 5 menit per Agen+endpoint+profil+koordinat.
- **Satu tarif:** `shipping_configurations` per-Agen ber-anchor provider `openroute` — `price_per_km`, `minimum_distance_km`, `minimum_charge`, free-shipping.

## 4. RajaOngkir / Komerce — Ekspedisi

- Tarif ekspedisi + master kurir provider-supported (`shipping_couriers` — dikelola Super Admin; agen memilih kurir yang disupport lewat `agent_shipping_provider_settings` + `updateCouriers`).
- Per-Agen: kredensial terenkripsi (`agent_shipping_provider_configs`), toggle, destinasi, test connection.
- Ekspedisi tidak pernah fallback ke KMSIT; tanggal pengiriman tidak bisa dijadwalkan ulang (§21 LOCKED).

## 5. Nominatim — reverse geocode

- Tombol "Gunakan Lokasi Saya" di checkout (`POST /checkout/geocode`) → koordinat → alamat terdekat → cocokkan master wilayah. Default Human-approved.

## 6. Google Sheets — one-way export

- 17 dataset terdaftar (`DatasetRegistry`): products, stock, transactions, transaction_items, order_initial_transactions, order_payment_positions, sales, korsal, courier_deliveries, sales_fees, courier_fees, korsal_fees, payment_status, refunds, additional_payments, transaction_report, financial_summary.
- Destinasi (`sheets_destinations`) + konfigurasi tab (`sheets_configs`) + log sinkronisasi (`sheets_sync_logs`); status koneksi & test; role: super_admin/agen/admin (admin tanpa `financial_summary`); scope Agen.
- Batas 10.000 baris per sync → gagal total bila lebih (tanpa pemotongan senyap). Format parity SimpleV2 = OD-06 NOT IMPLEMENTED.

## 7. Google OAuth

- **Global** (`google_auth_settings`, Super Admin) dan **per-Agen** (`agent_google_auth_configs`, Agen) — client id/secret (terenkripsi), redirect, frontend URL.
- Alur sign-in/registrasi konsumen: §23 [05-BUSINESS-RULES](05-BUSINESS-RULES.md) — state+nonce+PKCE session server-side 10 menit sekali pakai; tanpa penyimpanan token Google; hanya `provider_user_id/provider_email/last_login_at`.

## 8. Prinsip keamanan lintas integrasi

- Kredensial per-Agen terenkripsi at rest; write-only (tidak pernah di-echo ke klien).
- Endpoint eksternal user-supplied melalui `EndpointGuard` (anti SSRF).
- Sanitasi pesan error (tanpa kebocoran URL/kredensial).
- Rate limit & throttle untuk semua aksi integrasi sensitif.
