# PrimeClassy V2 — Master Notification Contract & Gap Analysis

**Status:** DRAFT — OWNER DECISIONS PENDING. Belum dikunci. Status implementasi bersifat provisional sampai audit source yang tercantum di §16 selesai.
**Dokumen ini hanya berisi:** spesifikasi, hasil audit read-only, gap, dan keputusan Owner. Tidak ada perubahan kode, migrasi, atau production.
**Scope:** WhatsApp, Email, In-App, CRM/consent notifications.
**Source of the template catalogue:** Master Notification Template Draft 1.0 (NTF-001 to NTF-034, G-01 to G-05).
**Audit basis:** read-only inspection of `backend/app/Services/WhatsApp/*`, `backend/app/Services/Auth/*`, `backend/app/Services/Consent/*`, `backend/app/Services/Crm/*`, `backend/app/Mail/*`, `backend/app/Observers/WhatsAppLifecycleObserver.php`, and the notification-related migrations. Verified on `main` at `419e2e8` plus the production queue activation (`ops/primeclassy-queue-worker`, `4c062f2`).

**Written for:** Owner (keputusan bisnis) and implementers (kontrak teknis).

---

## 1. Tujuan dan batasan sistem

**Tujuan.** Memberi tahu pihak yang berwenang tentang perubahan pesanan, pembayaran, retur, refund, dan akun, lewat kanal yang sesuai, tanpa membocorkan informasi kepada pihak yang tidak berwenang.

**Batasan yang berlaku dan tidak boleh dilanggar:**
- Notifikasi adalah efek samping best-effort. Kegagalan kirim tidak boleh membatalkan transaksi yang sudah committed (`OrderNotifier::defer` memakai `DB::afterCommit` dan menelan error ke log).
- Hanya event yang sudah terdaftar di `WhatsAppEvents` yang boleh dikirim. Template bebas tidak punya jalur kirim tanpa event.
- OTP, kode pemulihan, dan bukti persetujuan (consent) tidak pernah boleh lewat jalur terbatas (restricted lane).
- Event `payment_*` dan `refund_*` tidak pernah dikirim ke nomor yang belum terbukti (`RestrictedDeliveryPolicy::ORDER_EVENTS`).
- Nomor checkout penerima tidak pernah dianggap identitas akun.
- Kanal pengirim WhatsApp (gateway, device, base URL) tidak diubah oleh alur ini.

**Yang tidak tercakup dokumen ini:** pengiriman WhatsApp dari dashboard uji (`sendTest`, `sendAgentTest`) dan operasi gateway (`docs/OPERATIONS.md` §9).

---

## 2. Ringkasan inventaris (fakta yang ada di source)

| Komponen | Lokasi | Keadaan |
|---|---|---|
| Event WhatsApp | `app/Services/WhatsApp/WhatsAppEvents.php` | 10 event: `order_created`, `payment_paid`, `payment_partial`, `order_processed`, `order_shipped`, `order_delivered`, `order_cancelled`, `return_requested`, `return_approved`, `refund_completed` |
| Template default | `WhatsAppEvents::all()[*]['default']` | Teks per event, bisa diganti per Agen (`wa.template.{event}`) dan di-reset (audit `whatsapp.template.*`) |
| Recipient resolver | `OrderRecipientResolver` | Peran `admin`, `creator`, `recipient` |
| Pembatasan penerima | `RestrictedDeliveryPolicy` | Jalur `restricted` untuk `recipient` (event status pesanan saja) dan `registrant` (`account_registered`) |
| Pengiriman | `WhatsAppDelivery` | Ledger `whatsapp_messages`, `idempotency_key` unik, status `queued/sent/failed/unknown/skipped`, retry terikat (`max_attempts`, backoff) |
| Antrean | `SendWhatsAppMessageJob` di `primeclassy-notify` | Worker aktif di production (`primeclassy-queue.service`), `$tries = 1` |
| Email | `AccountRegisteredMail` (`ShouldQueue`, `primeclassy-notify`), `OtpChallengeMail` (sinkron) | Hanya dua mailable; tidak ada email transaksi order/retur/refund |
| OTP | `OtpChallengeService` (purpose `crm_link`, `whatsapp_number`), `AccountRecoveryService` (`password_recovery`), `EmailVerificationService` | Kode sekali pakai, TTL 10 menit, batas percobaan 5, sinkron, fail-closed |
| Consent | `ConsentService` (`assisted_return`), `ConsentAccess` | Bukti persetujuan, tanpa template notifikasi |
| CRM | `app/Services/Crm/*`, tabel `crm_customers`, `crm_customer_links` | Tautan CRM–konsumen lewat OTP; tidak ada notifikasi tautan |
| In-app | — | **Tidak ada.** Tidak ada tabel `notifications`, tidak ada kelas Notification, tidak ada UI pusat notifikasi |

---

## 3. Katalog template (34 utama + 5 terbatas)

Status memakai kategori: `IMPLEMENTED`, `IMPLEMENTED_WITH_DIFFERENT_CONTENT`, `PARTIALLY_IMPLEMENTED`, `NOT_IMPLEMENTED`, `REQUIRES_OWNER_DECISION`. Konten "berbeda" berarti event dan penerima sudah ada tetapi teks atau variabel belum sama dengan spesifikasi; ini tidak dihitung sebagai event baru.

| ID | Judul | Kanal spesifikasi | Padanan di source | Status | Catatan |
|---|---|---|---|---|---|
| NTF-001 | Pendaftaran akun berhasil | Email | `AccountRegisteredMail` (queued) + WA `account_registered` (jalur restricted, `registrant`) | IMPLEMENTED_WITH_DIFFERENT_CONTENT | Body email hanya memuat nama. `{email_masked}`, `{tanggal_pendaftaran}`, `{url_login}` tidak ada |
| NTF-002 | Akun dibuat oleh admin | Email | Tidak ada | NOT_IMPLEMENTED | Tidak ada event/mailable untuk pembuatan akun oleh admin. Tautan pengaturan password memerlukan mekanisme baru → lihat keputusan D-03 |
| NTF-003 | Verifikasi email | Email (tautan) | `EmailVerificationService` → `OtpChallengeMail` (kode) | REQUIRES_OWNER_DECISION | Source memakai kode OTP, spesifikasi memakai tautan. Lihat D-02 |
| NTF-004 | Verifikasi WhatsApp | WA (OTP) | `WhatsAppOtpChannel`, purpose `whatsapp_number` | IMPLEMENTED | Kanal dan TTL ada. Kata-kata pesan belum dibandingkan per kata |
| NTF-005 | Pemulihan kata sandi | Email | `AccountRecoveryService` (`password_recovery`) → kode | REQUIRES_OWNER_DECISION | Spesifikasi menyebut instruksi dari backend; source memakai kode. Lihat D-02 |
| NTF-006 | Kata sandi berhasil diubah | Email | Tidak ada | NOT_IMPLEMENTED | Tidak ada event/mailable |
| NTF-007 | Informasi kontak berubah | Email | Tidak ada | NOT_IMPLEMENTED | Tidak ada event perubahan kontak |
| NTF-008 | Verifikasi tindakan sensitif | WA (OTP) | `OtpChallengeService` (crm_link, whatsapp_number) | PARTIALLY_IMPLEMENTED | OTP ada untuk tindakan tertentu saja; tidak ada OTP generik "nama_tindakan" |
| NTF-009 | Pesanan berhasil dibuat | Email + WA | `order_created` (WA) | IMPLEMENTED_WITH_DIFFERENT_CONTENT | WA saja. Email order tidak ada |
| NTF-010 | Instruksi pembayaran | Email + WA | Tidak ada event | NOT_IMPLEMENTED | Tidak ada event "menunggu pembayaran" |
| NTF-011 | Pembayaran diterima | Email + WA | `payment_paid` (WA) | IMPLEMENTED_WITH_DIFFERENT_CONTENT | WA saja |
| NTF-012 | Pembayaran DP/parsial | Email + WA | `payment_partial` (WA) | IMPLEMENTED_WITH_DIFFERENT_CONTENT | WA saja |
| NTF-013 | Pelunasan | Email + WA | `payment_paid` (sama dengan NTF-011) | IMPLEMENTED_WITH_DIFFERENT_CONTENT | Variasi konten dari `payment_paid`, bukan event baru |
| NTF-014 | Pembayaran gagal/ditolak | Email + WA | Tidak ada | NOT_IMPLEMENTED | Tidak ada event kegagalan pembayaran |
| NTF-015 | Pesanan diproses | Email + WA | `order_processed` (WA) | IMPLEMENTED_WITH_DIFFERENT_CONTENT | WA saja |
| NTF-016 | Pesanan dikirim | Email + WA | `order_shipped` (WA), variabel `nama_kurir`, `nomor_resi` | IMPLEMENTED_WITH_DIFFERENT_CONTENT | WA saja. Aturan "jangan buat resi palsu" perlu dicek di sisi data pengiriman |
| NTF-017 | Pesanan selesai/diterima | Email + WA | `order_delivered` (WA) | IMPLEMENTED_WITH_DIFFERENT_CONTENT | WA saja |
| NTF-018 | Pesanan dibatalkan | Email + WA | `order_cancelled` (WA) | IMPLEMENTED_WITH_DIFFERENT_CONTENT | WA saja. Janji refund tidak boleh dibuat sebelum status refund disetujui |
| NTF-019 | Perubahan pesanan (umum) | Email + WA | Tidak ada event generik | NOT_IMPLEMENTED | Setiap perubahan memakai event spesifik; event generik akan menambah volume pesan → lihat D-05 |
| NTF-020 | Pengingat pembayaran | Email + WA | Tidak ada (tidak ada job terjadwal untuk ini) | REQUIRES_OWNER_DECISION | Pengingat berkala menambah volume pesan. Lihat D-05 |
| NTF-021 | Pengajuan retur diterima | Email + WA | `return_requested` (WA) | IMPLEMENTED_WITH_DIFFERENT_CONTENT | WA saja |
| NTF-022 | Retur disetujui | Email + WA | `return_approved` (WA) | IMPLEMENTED_WITH_DIFFERENT_CONTENT | WA saja |
| NTF-023 | Retur ditolak | Email + WA | Tidak ada event | NOT_IMPLEMENTED | Tidak ada event `return_rejected` |
| NTF-024 | Permohonan refund diterima | Email + WA | Tidak ada event | NOT_IMPLEMENTED | Tidak ada event `refund_requested` |
| NTF-025 | Refund disetujui | Email + WA | Tidak ada event | NOT_IMPLEMENTED | Tidak ada event `refund_approved` |
| NTF-026 | Refund selesai | Email + WA | `refund_completed` (WA, `RefundPayout`, `jumlah_refund`) | IMPLEMENTED_WITH_DIFFERENT_CONTENT | Penerima checkout tidak menerima event ini (kebijakan). Lihat D-01 |
| NTF-027 | Refund ditolak | Email + WA | Tidak ada event | NOT_IMPLEMENTED | — |
| NTF-028 | Permintaan consent CRM | Email | Consent OTP (`crm_link`, `assisted_return`) ada, tetapi tanpa notifikasi permintaan | PARTIALLY_IMPLEMENTED | Bukti persetujuan ada; notifikasi permintaan belum. Lihat D-04 |
| NTF-029 | Perubahan tautan CRM–konsumen | Email | Tidak ada | NOT_IMPLEMENTED | `CrmLinkService` tidak mengirim notifikasi |
| NTF-030 | Notifikasi admin (pesanan baru) | Email + WA | `order_created`, peran `admin` (agen cabang, nomor terbukti) | IMPLEMENTED_WITH_DIFFERENT_CONTENT | Admin menerima versi `full`. Email tidak ada |
| NTF-031 | Notifikasi pembuat order | Email + WA | `order_created`, peran `creator` | IMPLEMENTED_WITH_DIFFERENT_CONTENT | Buyer dan submitter sama-sama `creator` |
| NTF-032 | Notifikasi penerima order | Email + WA | Peran `recipient`, jalur restricted, `ORDER_EVENTS` | PARTIALLY_IMPLEMENTED | Konten dirender server dari teks tetap, bukan template Agen. Email tidak ada |
| NTF-033 | Kegagalan pengiriman (in-app admin) | In-App | Ledger `whatsapp_messages` (status `failed`/`unknown`) ada, UI in-app tidak | NOT_IMPLEMENTED | Tidak ada pusat notifikasi in-app. Lihat roadmap tahap 4 |
| NTF-034 | Status gateway berubah (in-app) | In-App | `AgentDeviceService` punya status device, tanpa event | NOT_IMPLEMENTED | — |

### 3.1 Template terbatas G-01 sampai G-05 (nomor WA belum terverifikasi)

| ID | Judul | Padanan | Status | Catatan |
|---|---|---|---|---|
| G-01 | Pesanan baru (referensi saja) | Jalur restricted, peran `recipient`, `order_created` | PARTIALLY_IMPLEMENTED | Jalur ada. Teks spesifikasi (referensi, ajakan verifikasi) belum diaudit terhadap teks tetap di source |
| G-02 | Perubahan status pesanan | Jalur restricted, `ORDER_EVENTS` (processed/shipped/delivered/cancelled) | PARTIALLY_IMPLEMENTED | Variabel `*_aman` tidak ada; source memakai teks tetap |
| G-03 | Informasi pembayaran | — | REQUIRES_OWNER_DECISION | `RestrictedDeliveryPolicy` sengaja menutup `payment_*`. Bertentangan dengan spesifikasi. Lihat D-01 |
| G-04 | Informasi pengiriman | Jalur restricted, `order_shipped`/`order_delivered` | PARTIALLY_IMPLEMENTED | Variabel `*_aman` belum ada |
| G-05 | Pendaftaran akun (nomor WA) | Jalur restricted, peran `registrant`, `account_registered` | PARTIALLY_IMPLEMENTED | Jalur ada. Teks belum diaudit |

---

## 4. Pemetaan event existing ke template

| Event (source) | Peran | Kanal | Template spesifikasi |
|---|---|---|---|
| `order_created` | `creator`, `admin` (full); `recipient` (restricted G-01) | WA | NTF-009, NTF-030, NTF-031, NTF-032 / G-01 |
| `payment_paid` | `creator`, `admin` | WA | NTF-011, NTF-013 |
| `payment_partial` | `creator`, `admin` | WA | NTF-012 |
| `order_processed` | `creator`, `admin`; `recipient` (restricted) | WA | NTF-015 / G-02 |
| `order_shipped` | `creator`, `admin`; `recipient` (restricted) | WA | NTF-016 / G-04 |
| `order_delivered` | `creator`, `admin`; `recipient` (restricted) | WA | NTF-017 / G-02 |
| `order_cancelled` | `creator`, `admin`; `recipient` (restricted) | WA | NTF-018 / G-02 |
| `return_requested` | `creator`, `admin` | WA | NTF-021 |
| `return_approved` | `creator`, `admin` | WA | NTF-022 |
| `refund_completed` | `creator`, `admin` | WA | NTF-026 |
| `account_registered` | `registrant` (restricted) | WA | NTF-001 (WA), G-05 |
| — (`AccountRegisteredMail`) | akun baru | Email | NTF-001 (email) |
| — (`OtpChallengeMail`) | akun terverifikasi | Email (kode) | NTF-003, NTF-005, NTF-008 (kode) |
| — (OTP WA `whatsapp_number`) | pemilik nomor | WA | NTF-004 |

Peran `admin` dan `creator` hanya dipilih jika nomornya terbukti (`WhatsAppNumberProof`). Order CRM-origin dan order tanpa konsumen dilewati dengan skip `recipient_not_verified`.

---

## 5. Matriks penerima Admin / Pembuat / Penerima

| Aspek | Admin | Pembuat (creator) | Penerima (recipient) | Registrant |
|---|---|---|---|---|
| Siapa | Semua admin cabang Agen order yang punya nomor terbukti | Buyer (`konsumen_id`) dan submitter (`placed_by_user_id`) jika berbeda | Nomor `recipient_phone_snapshot` jika berbeda dari semua nomor yang sudah tercakup | Pengguna yang baru mendaftar |
| Template | `full` | `full` | `restricted` | `restricted` |
| Event yang boleh | Semua event order/return/refund | Semua event order/return/refund | Hanya `ORDER_EVENTS` (status pesanan, tanpa keuangan) | Hanya `account_registered` |
| Informasi keuangan | Ya | Ya, jika terbukti | Tidak | Tidak |
| Konten | Template Agen (bisa diubah) | Template Agen | Teks tetap di server | Teks tetap di server |
| Dedup | Nomor sama di dua peran → varian peran tertinggi saja (admin > creator > recipient) | — | — | — |
| Syarat kirim | Nomor terbukti | Nomor terbukti | Nomor checkout tetap sama dengan saat dicatat (`recipient_changed` jika berubah) | Nomor saat ini sama dengan nomor pengguna |

Aturan: penerima checkout tidak pernah menjadi akun (`recipient_user_id` harus null).

---

## 6. Matriks kanal WhatsApp / Email / In-App

| Kategori | WhatsApp | Email | In-App | CRM/consent |
|---|---|---|---|---|
| Transaksi order (10 event) | Ya (template Agen, dengan jalur restricted untuk penerima) | Tidak ada | Tidak ada | — |
| Akun baru | Ya (`account_registered`, restricted) | Ya (`AccountRegisteredMail`, queued) | Tidak | — |
| OTP verifikasi | Ya (`whatsapp_number`, sinkron) | Ya (kode, sinkron) | — | — |
| OTP pemulihan | Tidak | Ya (kode, sinkron) | — | — |
| OTP consent/CRM | Ya, hanya jika nomor terbukti | Ya (email terverifikasi akun terkait) | — | Ya |
| Notifikasi operasional | Tidak | Tidak | Tidak ada | — |

Email transaksional selain akun baru belum ada. Pesan WA adalah satu-satunya kanal transaksi yang aktif.

---

## 7. Kontrak variabel kanonis dan alias

Kanonis = nama yang dipakai source hari ini (`WhatsAppEvents::*_VARS` dan `sample()`). Template yang sudah tersimpan per Agen memakai nama kanonis, sehingga nama kanonis tidak diubah. Spesifikasi memakai nama lain; pemetaannya didokumentasikan di sini.

**Aturan kompatibilitas:** alias ditambahkan lewat tabel eksplisit. Tidak ada placeholder lama yang dihapus. Alias yang tidak semantik identik ditandai `REQUIRES CONTEXT` dan tidak boleh diganti otomatis.

| Spesifikasi | Kanonis (source) | Status alias | Catatan |
|---|---|---|---|
| `nama_pengguna` | `nama_pelanggan` | REQUIRES CONTEXT | Hanya untuk akun (NTF-001, NTF-002, NTF-003, NTF-005, NTF-006) |
| `nama_penerima` | `nama_pelanggan` | REQUIRES CONTEXT | Untuk penerima checkout, nama dari `recipient_phone_snapshot`/nama penerima, bukan nama akun. Tidak sama |
| `total_pesanan` | `total` | ALIAS | `total` di `ORDER_VARS` adalah total pesanan. Di `PAYMENT_VARS`, `total_tagihan` adalah nilai tagihan terbaru yang berbeda |
| `jumlah_dibayar` | `jumlah_dibayarkan` | ALIAS | Keduanya jumlah yang dibayar pada transaksi/event ini |
| `sisa_pembayaran` | `sisa_tagihan` | ALIAS | Sisa tagihan dari `PaymentSummaryService` |
| `ongkos_kirim` | `ongkir` | ALIAS | — |
| `url_detail_pesanan` | `link_pesanan` | ALIAS | — |
| `tanggal_pesanan` | `tanggal` | REQUIRES CONTEXT | `tanggal` adalah tanggal event. Untuk event pembayaran, gunakan `tanggal_pembayaran` |
| `kurir` | `nama_kurir` | ALIAS | Kedua nama sudah ada di source; default `order_shipped` memakai `{kurir}` |
| `status_pesanan` | `status` | ALIAS | — |
| `nomor_resi` | `resi` | ALIAS | — |
| `total_dibayar` | — | REQUIRES CONTEXT | Akumulasi dibayar belum ada di `sample()`. Jangan disamakan dengan `jumlah_dibayarkan` |
| `nama_pembuat` | — | NOT IMPLEMENTED | Variabel peran `creator`, belum ada di kontrak |
| `nama_agen` | — | NOT IMPLEMENTED | Perlu sumber nama Agen dari relasi order |
| `alamat_pengiriman` | — | NOT IMPLEMENTED | Data sensitif. Hanya boleh untuk admin/creator terbukti (lihat D-01) |
| `instruksi_pembayaran`, `instruksi_retur`, `instruksi_pengembalian` | — | NOT IMPLEMENTED | Teks dinamis, tidak boleh bebas (lihat §8) |
| `alasan_aman`, `referensi_aman`, `*_aman` | — | NOT IMPLEMENTED | Variabel "aman" harus didefinisikan per event; tidak ada di source |
| `kode_otp`, `url_verifikasi_email`, `url_pengaturan_password`, `instruksi_pemulihan` | — | NOT ALLOWED in configurable template | Lihat §8 |
| `email_masked`, `device_id_masked` | — | NOT IMPLEMENTED | Masking sudah ada untuk nomor (`WhatsAppNumber::mask`), belum untuk email |
| `tanggal_pendaftaran`, `tanggal_waktu`, `tanggal_refund`, `tanggal_pengiriman`, `tanggal_pembatalan`, `tanggal_pembayaran` | `tanggal` / `tanggal_pembayaran` | REQUIRES CONTEXT | Pilih per event |
| `url_login`, `url_invoice`, `url_dashboard_order`, `url_persetujuan` | `link_pesanan` (order only) | NOT IMPLEMENTED | Perlu URL kanonis baru per kanal |
| `masa_berlaku_menit`, `waktu_kedaluwarsa` | — | NOT IMPLEMENTED | Dari TTL OTP (`TTL_MINUTES = 10`) |
| `subtotal`, `diskon`, `daftar_produk`, `metode_pembayaran`, `status_pembayaran`, `tanggal_pembayaran`, `nama_toko`, `nomor_retur`, `jumlah_refund`, `nomor_pesanan`, `nama_pelanggan`, `status`, `tanggal`, `total`, `kurir`, `resi`, `nomor_telepon_kurir`, `estimasi_tiba`, `layanan_pengiriman`, `link_pesanan`, `link_pelacakan`, `nama_kurir`, `total_tagihan` | sama | KANONIS | — |

---

## 8. Kebijakan verifikasi dan pembatasan informasi

1. **Penerima checkout** hanya menerima event status pesanan, dengan teks tetap di server (`RestrictedDeliveryPolicy::check`). Tidak menerima keuangan, OTP, atau template Agen.
2. **Nomor belum terbukti** tidak menerima apa pun kecuali jalur restricted yang sudah disetujui.
3. **OTP dan kode pemulihan** tidak pernah boleh dikirim lewat template yang bisa diedit. Kode hanya dikirim dari `WhatsAppOtpChannel` atau `OtpChallengeMail` dan tidak pernah disimpan sebagai body yang bisa diedit.
4. **Kata sandi plaintext** tidak pernah masuk template (spesifikasi, Ketentuan NTF-002).
5. **Tautan pemulihan dan verifikasi** tidak dikirim ke nomor WA berbeda dari akun yang diverifikasi (NTF-005).
6. **Blok informasi kosong** dihilangkan utuh. Source sudah mengikuti aturan ini untuk `SHIPPING_VARS` (opsional dihapus jika tidak tersedia, AUD-06).
7. **Janji refund** tidak boleh dibuat sebelum status refund disetujui (NTF-018).
8. **Pengirim WhatsApp** (gateway, device, base URL) tidak diubah oleh notifikasi.

---

## 9. Aturan OTP dan pemulihan akun

| Purpose | Layanan | Kanal | Batas |
|---|---|---|---|
| `crm_link` | `OtpChallengeService` | Email terverifikasi akun terkait; WA hanya jika nomor terbukti | TTL 10 menit, 5 percobaan, 3 pengiriman per 10 menit |
| `whatsapp_number` | `OtpChallengeService` | WA (proof nomor) | Cooldown, limit window dari `WhatsAppSettings::otp()` |
| `password_recovery` | `AccountRecoveryService` | Email | Sama dengan OTP; kode sinkron, fail-closed |
| Email verifikasi | `EmailVerificationService` | Email | Kode, bukan tautan |

- OTP tidak pernah diretry oleh queue. Pengiriman OTP tetap sinkron (`Mail::send`, `WhatsAppOtpChannel::deliver` throws).
- OTP dan kode pemulihan tidak pernah lewat jalur restricted.
- Perubahan OTP dari kode ke tautan memerlukan keputusan D-02 dan audit ulang keamanan.

---

## 10. Idempotency, deduplication, audit, dan retry

**Idempotency** (`WhatsAppDelivery`, `OrderNotifier::send`):
- Kunci transaksi: `txn:{event}:{subject_type}:{subject_id}`.
- Peran primer (`creator` pertama) memakai kunci persis seperti sebelumnya (kompatibel dengan histori).
- Peran lain menambahkan `:{role}` dan menjadi baris ledger terpisah.
- `whatsapp_messages.idempotency_key` unik di database. Replay job atau dispatch ulang memberi baris yang sama, bukan duplikat.
- Entry point memakai `DB::afterCommit`, sehingga notifikasi tidak dikirim untuk transaksi yang di-rollback.

**Deduplication:** satu nomor dalam dua peran → varian peran tertinggi saja (admin > creator > recipient). Dedup terjadi di `OrderRecipientResolver::resolve`.

**Audit:**
- Perubahan dan reset template tercatat (`whatsapp.template.updated`, `whatsapp.template.reset`).
- Perubahan konfigurasi WA tercatat tanpa nilai rahasia (`secret_changed` saja).
- Pengiriman tercatat di ledger `whatsapp_messages` dengan `skip_reason` (contoh: `recipient_not_verified`, `number_not_verified`, `restricted_not_allowed`, `recipient_changed`, `rate_limited:*`, `agent_device_not_ready`).

**Retry:**
- WA: retry terikat di `WhatsAppDelivery` (`max_attempts`, backoff, klasifikasi RETRY/FAILED/UNKNOWN). Laravel retry dimatikan (`$tries = 1`), sehingga crash worker menjadi re-claim, bukan pengiriman ganda.
- `unknown` berarti kemungkinan sudah terkirim. Cek nomor sebelum retry manual.
- Email queued (`AccountRegisteredMail`): tidak ada retry ledger. Gagal terekam di log (`registration.notify.email_failed`).

---

## 11. Gap analysis

### 11.1 Gap berdasarkan kategori

Hitungan dari tabel §3 (34 template utama) dan §3.1 (5 template terbatas, total 39):

| Kategori | Jumlah | Template |
|---|---|---|
| IMPLEMENTED | 1 | NTF-004 |
| IMPLEMENTED_WITH_DIFFERENT_CONTENT | 14 | NTF-001, 009, 011, 012, 013, 015, 016, 017, 018, 021, 022, 026, 030, 031 |
| PARTIALLY_IMPLEMENTED | 7 | NTF-008, 028, 032; G-01, G-02, G-04, G-05 |
| NOT_IMPLEMENTED | 13 | NTF-002, 006, 007, 010, 014, 019, 023, 024, 025, 027, 029, 033, 034 |
| REQUIRES_OWNER_DECISION | 4 | NTF-003, 005, 020; G-03 |

Jumlah utama: 1 + 14 + 3 + 13 + 3 = 34. Dengan G: 39. Tabel §3 adalah sumber kebenaran; hitungan ini harus diperbarui bila status berubah.

### 11.2 Gap berdasarkan jenis (bukan event baru)

- **Event bisnis yang benar-benar belum ada:** `payment_failed`, `order_changed`, `payment_reminder`, `return_rejected`, `refund_requested`, `refund_approved`, `refund_rejected`, `account_created_by_admin`, `password_changed`, `contact_changed`, `consent_requested`, `crm_link_changed`. Ini memerlukan keputusan Owner (D-05).
- **Variasi penerima:** `admin`, `creator`, `recipient` sudah ada untuk event order/return/refund. Tidak ada variasi baru yang diperlukan.
- **Variasi konten:** NTF-009 sampai NTF-031 kebanyakan variasi teks `order_*`, `payment_*`, `return_*`, `refund_*`. Tidak dihitung sebagai event baru.
- **Kanal:** email transaksi order/retur/refund belum ada sama sekali. In-app belum ada sama sekali.

### 11.3 Gap teknis

- Belum ada alias layer. Template Agen memakai nama kanonis saja.
- Belum ada email transaksional (mailable, antrean, dan kolom kanal di ledger). Ledger `whatsapp_messages` khusus WA.
- Belum ada tabel `notifications` atau UI pusat notifikasi.
- Belum ada job terjadwal untuk pengingat (`NTF-020`).
- `AccountRegisteredMail` memakai teks sederhana, sementara spesifikasi punya blok lebih lengkap.

---

## 12. Keputusan Owner yang diperlukan

| ID | Keputusan | Opsi | Rekomendasi |
|---|---|---|---|
| **D-01** | Apakah penerima checkout boleh menerima `payment_*` / `refund_*` (G-03, NTF-026 kepada penerima)? | (a) Tetap ditutup. (b) Buka dengan versi tanpa angka. (c) Buka penuh | (a). Sesuai `RestrictedDeliveryPolicy` yang sudah ada. Spesifikasi NTF-026 ditujukan ke pembuat, bukan penerima |
| **D-02** | Verifikasi email dan pemulihan: kode OTP atau tautan? | (a) Pertahankan OTP (sesuai source). (b) Tautan sekali pakai baru | (a). Tautan adalah mekanisme baru dengan implikasi keamanan |
| **D-03** | Akun dibuat oleh admin: apakah perlu email dengan tautan pengaturan password? | (a) Tidak, admin memberi password sementara. (b) Ya, tautan sekali pakai | Perlu keputusan. Jika (b), butuh mekanisme token baru |
| **D-04** | Notifikasi permintaan consent CRM: apakah dibutuhkan? | (a) Tidak, cukup OTP. (b) Ya, email/WA permintaan | (a) untuk tahap sekarang |
| **D-05** | Event baru (pembayaran gagal, pengingat, refund diajukan/disetujui/ditolak, retur ditolak, perubahan pesanan umum)? | Buka per event, atau tunda | Buka per event dengan prioritas. Pengingat butuh job terjadwal dan persetujuan volume |
| **D-06** | Email transaksional order/retur/refund: apakah dibutuhkan? | Ya/tidak, dan kapan | Ya, tahap 3, setelah kontrak variabel selesai |
| **D-07** | In-App notification center: apakah dibutuhkan? | Ya/tidak, dan untuk siapa | Ya untuk admin (NTF-033, NTF-034), tahap 4 |

---

## 13. Dependency dan rekomendasi roadmap implementasi

Roadmap ini hanya rancangan. Tidak ada migrasi, controller, job, UI, atau kode baru yang dibuat dari dokumen ini.

| Tahap | Isi | Dependency | Risiko utama |
|---|---|---|---|
| **1. Penyempurnaan template WA existing** | Samakan teks default 10 event dengan spesifikasi, tanpa mengubah nama event atau placeholder. Ubah hanya konten, dan hanya untuk template default (template Agen yang sudah diedit tidak ditimpa) | Tidak ada | Template Agen yang sudah diedit tidak boleh tertimpa. Uji lewat dashboard, bukan dengan kirim massal |
| **2. Kontrak variabel dan kompatibilitas** | Alias layer di `MessageTemplates::render` berdasarkan tabel §7. Tambah variabel baru hanya bila ada sumber data. Jangan ubah kanonis | Keputusan untuk REQUIRES CONTEXT (`nama_penerima`, `tanggal_pesanan`, `total_dibayar`) | Alias yang salah makna. Tes unit wajib untuk setiap alias |
| **3. Email notifikasi transaksi** | Mailable baru untuk order/retur/refund, antrean `primeclassy-notify`, dan ledger kanal (kolom baru atau tabel baru). Migrasi additive | D-06, tahap 2 selesai, backup production dan izin per langkah | Email ke pihak yang tidak berwenang. Harus memakai peran dan jalur yang sama dengan WA |
| **4. In-App notification center** | Tabel `notifications`, UI untuk admin (NTF-033, NTF-034), dan pembaruan status | D-07 | Volume dan retensi. Data sensitif tidak boleh tampil di luar peran |
| **5. CRM dan consent notifications** | Notifikasi permintaan consent (NTF-028) dan perubahan tautan (NTF-029), jika D-04 memutuskan | D-04, tahap 2 | Persetujuan palsu atau kedaluwarsa |
| **6. UAT dan verifikasi lintas kanal** | Uji lintas WA, email, dan in-app dengan nomor/alamat uji yang diizinkan. Verifikasi idempotency dan skip reason | Semua tahap sebelumnya | Pengiriman nyata ke pelanggan saat uji. Gunakan `wa.test_numbers` saja |

**Urutan yang saya sarankan:** 1 → 2 → (D-01, D-02 diputuskan) → 3 → 4 → 5 → 6. Tahap 1 dan 2 tidak mengubah perilaku produksi karena template Agen yang sudah diedit tetap dipakai.

---

## 14. Catatan kepatuhan

- Dokumen ini tidak mengubah konfigurasi WhatsApp Gateway, DNS, atau production.
- Dokumen ini tidak mengubah kebijakan tiga penerima, `RestrictedDeliveryPolicy`, atau OTP.
- Untuk setiap perubahan lanjutan, `docs/BUSINESS-RULES.md` menjadi acuan. Setiap konflik dengan aturan bisnis dilaporkan, bukan dipilih sendiri.
- Dokumen ini belum di-merge ke `main`. Tidak ada push, merge, implementasi, migrasi, atau perubahan production sampai ada instruksi berikutnya.

---

## 15. Keputusan sementara (provisional, belum dikunci)

Keputusan berikut berlaku sebagai batasan selama dokumen berstatus DRAFT. Tidak mengubah kebijakan yang sudah ada.

1. `RestrictedDeliveryPolicy` dipertahankan apa adanya.
2. OTP existing (WhatsApp, email, pemulihan, consent/CRM) dipertahankan.
3. Template Agen yang sudah ada tidak boleh ditimpa oleh tahap apa pun dari roadmap §13.
4. Email transaksi order/retur/refund dan In-App adalah **gap pengembangan**, bukan fitur aktif.
5. Pengingat pembayaran (NTF-020) **belum boleh diaktifkan**. Tidak ada job terjadwal untuknya dan tidak ada persetujuan volume.
6. Kontrak variabel existing (`WhatsAppEvents::*_VARS`) tetap kanonis. Alias di §7 adalah **rancangan**, bukan fitur yang sudah aktif.
7. Status implementasi (§3, §11) bersifat provisional. Status "belum diverifikasi" tetap berlaku sampai audit §16 selesai.

---

## 16. Keputusan Owner — detail

Setiap keputusan: opsi, rekomendasi, konsekuensi, dan status persetujuan. **Semua berstatus BELUM DISETUJUI.**

### D-01 — Penerima checkout dan informasi keuangan (`payment_*`, `refund_*`)

- **Pertanyaan:** Apakah penerima checkout yang belum terverifikasi boleh menerima event pembayaran atau refund (G-03, dan NTF-026 jika ditujukan ke penerima)?
- **Opsi:**
  - (a) Tetap ditutup, sesuai `RestrictedDeliveryPolicy`.
  - (b) Dibuka dengan versi tanpa nominal (hanya status).
  - (c) Dibuka penuh.
- **Rekomendasi:** (a).
- **Konsekuensi:**
  - (a): G-03 tidak bisa dipenuhi. NTF-026 ditujukan ke pembuat/admin saja.
  - (b): perlu perubahan `RestrictedDeliveryPolicy` dan audit privasi baru. Tidak boleh dilakukan tanpa keputusan tertulis.
  - (c): bertentangan dengan kebijakan privasi yang ada, dan membuka kebocoran nilai transaksi ke nomor yang tidak terbukti.
- **Status:** BELUM DISETUJUI.

### D-02 — Verifikasi email dan pemulihan: OTP atau tautan

- **Pertanyaan:** NTF-003 dan NTF-005 memakai tautan. Source memakai kode OTP. Mekanisme mana yang dipakai?
- **Opsi:**
  - (a) Pertahankan OTP (sesuai source).
  - (b) Tautan sekali pakai baru.
- **Rekomendasi:** (a).
- **Konsekuensi:**
  - (a): template NTF-003 dan NTF-005 diubah agar memuat kode, bukan tautan. Tidak ada mekanisme token baru.
  - (b): butuh tabel token, masa berlaku, sekali pakai, dan audit keamanan ulang. Risiko phishing dan token bocor lewat log.
- **Status:** BELUM DISETUJUI.

### D-03 — Akun dibuat oleh admin

- **Pertanyaan:** Saat admin membuat akun (NTF-002), apakah pengguna mendapat tautan pengaturan password?
- **Opsi:**
  - (a) Tidak. Admin memberi kata sandi sementara lewat kanal di luar sistem.
  - (b) Ya. Tautan sekali pakai dikirim lewat email.
- **Rekomendasi:** keputusan Owner. Jika (b), D-02 harus diputuskan lebih dulu.
- **Konsekuensi:**
  - (a): tidak ada mekanisme baru. Risiko: kata sandi sementara bisa bocor di luar sistem.
  - (b): butuh token sekali pakai dan email transaksi (D-06). Kata sandi plaintext tidak pernah boleh masuk email.
- **Status:** BELUM DISETUJUI.

### D-04 — Notifikasi permintaan consent CRM

- **Pertanyaan:** Apakah pelanggan perlu diberi tahu (email/WA) saat ada permintaan consent CRM (NTF-028)?
- **Opsi:**
  - (a) Tidak. Cukup OTP consent yang sudah ada.
  - (b) Ya. Notifikasi permintaan dikirim sebelum OTP.
- **Rekomendasi:** (a) untuk tahap sekarang.
- **Konsekuensi:**
  - (a): NTF-028 tetap PARTIALLY_IMPLEMENTED. Tidak ada perubahan.
  - (b): notifikasi tanpa OTP bisa dipakai untuk phishing. Harus memakai kanal terverifikasi dan tidak boleh memuat tautan persetujuan langsung.
- **Status:** BELUM DISETUJUI.

### D-05 — Event baru dan pengingat

- **Pertanyaan:** Event mana yang dibuka: pembayaran gagal, pengingat pembayaran (NTF-020), refund diajukan/disetujui/ditolak, retur ditolak, perubahan pesanan umum?
- **Opsi:**
  - (a) Buka per event dengan prioritas.
  - (b) Tunda semua.
- **Rekomendasi:** (a), dengan urutan: refund diajukan/disetujui/ditolak dan retur ditolak dulu (menutup gap status), lalu pembayaran gagal. Pengingat (NTF-020) **ditunda**: butuh job terjadwal, persetujuan volume, dan batas frekuensi.
- **Konsekuensi:**
  - Setiap event baru menambah entri `WhatsAppEvents`, template default, dan `RestrictedDeliveryPolicy` bila perlu.
  - Pengingat: volume pesan naik, risiko spam ke pelanggan. Belum boleh diaktifkan (§15 butir 5).
  - Event baru harus lewat tes idempotency dan audit sendiri.
- **Status:** BELUM DISETUJUI.

### D-06 — Email transaksi order, retur, refund

- **Pertanyaan:** Apakah email transaksi untuk order/retur/refund dibutuhkan?
- **Opsi:**
  - (a) Ya, tahap 3 roadmap, setelah kontrak variabel selesai.
  - (b) Tidak. Hanya WA.
- **Rekomendasi:** (a).
- **Konsekuensi:**
  - (a): butuh mailable baru, antrean `primeclassy-notify`, dan ledger kanal (migrasi additive, backup, dan izin per langkah). Peran dan jalur penerima harus sama dengan WA.
  - (b): kanal email untuk transaksi tetap kosong. NTF-009 sampai NTF-027 email tidak terpenuhi.
- **Status:** BELUM DISETUJUI.

### D-07 — In-App notification center

- **Pertanyaan:** Apakah pusat notifikasi in-app dibutuhkan, dan untuk siapa (NTF-033, NTF-034)?
- **Opsi:**
  - (a) Ya, untuk admin saja.
  - (b) Tidak.
- **Rekomendasi:** (a), tahap 4 roadmap.
- **Konsekuensi:**
  - (a): tabel `notifications` baru, UI, dan retensi. Data sensitif tidak boleh tampil di luar peran.
  - (b): NTF-033 dan NTF-034 tetap NOT_IMPLEMENTED. Kegagalan pengiriman hanya terlihat di ledger `whatsapp_messages`.
- **Status:** BELUM DISETUJUI.

---

## 17. Audit source yang masih terbuka

Status §3 dan §11 bergantung pada audit berikut. Sampai selesai, status tersebut provisional.

1. **Teks template WA default** (10 event di `WhatsAppEvents::all()`): belum dibandingkan kata per kata dengan NTF-009 sampai NTF-031. Status "berbeda konten" belum diverifikasi.
2. **Teks `AccountRegisteredMail` dan `messages.auth.registered_mail_subject`:** belum dibandingkan dengan NTF-001 dan G-05.
3. **Teks WA `account_registered`** di `RegistrationNotifier::whatsapp`: belum dibaca. Status G-05 provisional.
4. **Alur akun dibuat admin (NTF-002):** belum dipastikan. Inventaris hanya memeriksa daftar `Mail::` dan `Mail` classes, belum membaca seluruh layanan `Auth` dan admin user controller.
5. **Sumber tanggal per event** (`tanggal`, `tanggal_pembayaran`): belum dibaca di `WhatsAppEvents::sample()` dan `MessageTemplates`.
6. **Sumber `kurir` dan `nama_kurir`** di `SHIPPING_VARS`: belum dipastikan apakah keduanya terisi dari sumber yang sama.
7. **`PaymentSummaryService`** untuk `total_tagihan`, `jumlah_dibayarkan`, dan `sisa_tagihan`: belum diaudit. Alias `total_dibayar` belum punya sumber.
8. **Kebijakan `wa.test_numbers`** dan `sendTest`: belum diverifikasi terhadap spesifikasi NTF-004 (kode WA ke nomor uji).
9. **Kebijakan refund ke penerima** di `OrderRecipientResolver` dan `RefundRecipientService`: belum dibaca. Terkait D-01.
10. **Status `unknown` pada ledger:** belum diuji terhadap janji "jangan kirim ulang sebelum cek" di NTF-014.
11. **Konfigurasi template di dashboard:** belum dibuka. Pastikan preview per event sama dengan default source.
12. **Sumber `url_login`, `url_invoice`, `url_dashboard_order`, `url_persetujuan`:** belum ditentukan route kanoniknya.
