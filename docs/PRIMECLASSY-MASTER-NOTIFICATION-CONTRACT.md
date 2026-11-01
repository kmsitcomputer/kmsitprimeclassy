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
| `jumlah_dibayar` (per transaksi) | — | NOT IMPLEMENTED | KOREKSI audit §18 A7: `jumlah_dibayarkan` adalah total kumulatif `paid_amount`, bukan jumlah per transaksi. Tidak boleh dialias |
| `sisa_pembayaran` | `sisa_tagihan` | ALIAS | Sisa tagihan dari `PaymentSummaryService` |
| `ongkos_kirim` | `ongkir` | ALIAS | — |
| `url_detail_pesanan` | `link_pesanan` | GAP | KOREKSI audit §18 A12: `link_pesanan` berupa path relatif `/orders/{order_no}`, sedangkan route frontend `/orders/:id` membaca id numerik. Tidak boleh dialias sebelum URL absolut dan parameter dibuktikan |
| `tanggal_pesanan` | `tanggal` | REQUIRES CONTEXT | `tanggal` adalah tanggal event. Untuk event pembayaran, gunakan `tanggal_pembayaran` |
| `kurir` | `nama_kurir` | REQUIRES CONTEXT | KOREKSI audit §18 A6: `kurir` = gabungan semua kurir unik dari shipment non-batal; `nama_kurir` = kurir shipment pertama saja. Tidak setara |
| `status_pesanan` | `status` | ALIAS | — |
| `nomor_resi` | `resi` | REQUIRES CONTEXT | KOREKSI audit §18 A6: `resi` = gabungan semua tracking number unik; `nomor_resi` = tracking shipment pertama. Tidak setara |
| `total_dibayar` | `jumlah_dibayarkan` | ALIAS | KOREKSI audit §18 A7: keduanya total kumulatif yang sudah dibayar (`PaymentSummaryService.total_paid`). Sumber ada. `sample()` belum memuat `jumlah_dibayarkan`, perlu dicek saat implementasi |
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
- **Status:** PROPOSED — AWAITING OWNER APPROVAL. Belum disetujui, bukan APPROVED.

### D-02 — Verifikasi email dan pemulihan: OTP atau tautan

- **Pertanyaan:** NTF-003 dan NTF-005 memakai tautan. Source memakai kode OTP. Mekanisme mana yang dipakai?
- **Opsi:**
  - (a) Pertahankan OTP (sesuai source).
  - (b) Tautan sekali pakai baru.
- **Rekomendasi:** (a).
- **Konsekuensi:**
  - (a): template NTF-003 dan NTF-005 diubah agar memuat kode, bukan tautan. Tidak ada mekanisme token baru.
  - (b): butuh tabel token, masa berlaku, sekali pakai, dan audit keamanan ulang. Risiko phishing dan token bocor lewat log.
- **Status:** PROPOSED — AWAITING OWNER APPROVAL. Belum disetujui, bukan APPROVED.

### D-03 — Akun dibuat oleh admin

- **Pertanyaan:** Saat admin membuat akun (NTF-002), apakah pengguna mendapat tautan pengaturan password?
- **Opsi:**
  - (a) Tidak. Admin memberi kata sandi sementara lewat kanal di luar sistem.
  - (b) Ya. Tautan sekali pakai dikirim lewat email.
- **Rekomendasi:** keputusan Owner. Jika (b), D-02 harus diputuskan lebih dulu.
- **Konsekuensi:**
  - (a): tidak ada mekanisme baru. Risiko: kata sandi sementara bisa bocor di luar sistem.
  - (b): butuh token sekali pakai dan email transaksi (D-06). Kata sandi plaintext tidak pernah boleh masuk email.
- **Status:** PROPOSED — AWAITING OWNER APPROVAL. Belum disetujui, bukan APPROVED.

### D-04 — Notifikasi permintaan consent CRM

- **Pertanyaan:** Apakah pelanggan perlu diberi tahu (email/WA) saat ada permintaan consent CRM (NTF-028)?
- **Opsi:**
  - (a) Tidak. Cukup OTP consent yang sudah ada.
  - (b) Ya. Notifikasi permintaan dikirim sebelum OTP.
- **Rekomendasi:** (a) untuk tahap sekarang.
- **Konsekuensi:**
  - (a): NTF-028 tetap PARTIALLY_IMPLEMENTED. Tidak ada perubahan.
  - (b): notifikasi tanpa OTP bisa dipakai untuk phishing. Harus memakai kanal terverifikasi dan tidak boleh memuat tautan persetujuan langsung.
- **Status:** PROPOSED — AWAITING OWNER APPROVAL. Belum disetujui, bukan APPROVED.

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
- **Status:** PROPOSED — AWAITING OWNER APPROVAL. Belum disetujui, bukan APPROVED.

### D-06 — Email transaksi order, retur, refund

- **Pertanyaan:** Apakah email transaksi untuk order/retur/refund dibutuhkan?
- **Opsi:**
  - (a) Ya, tahap 3 roadmap, setelah kontrak variabel selesai.
  - (b) Tidak. Hanya WA.
- **Rekomendasi:** (a).
- **Konsekuensi:**
  - (a): butuh mailable baru, antrean `primeclassy-notify`, dan ledger kanal (migrasi additive, backup, dan izin per langkah). Peran dan jalur penerima harus sama dengan WA.
  - (b): kanal email untuk transaksi tetap kosong. NTF-009 sampai NTF-027 email tidak terpenuhi.
- **Status:** PROPOSED — AWAITING OWNER APPROVAL. Belum disetujui, bukan APPROVED.

### D-07 — In-App notification center

- **Pertanyaan:** Apakah pusat notifikasi in-app dibutuhkan, dan untuk siapa (NTF-033, NTF-034)?
- **Opsi:**
  - (a) Ya, untuk admin saja.
  - (b) Tidak.
- **Rekomendasi:** (a), tahap 4 roadmap.
- **Konsekuensi:**
  - (a): tabel `notifications` baru, UI, dan retensi. Data sensitif tidak boleh tampil di luar peran.
  - (b): NTF-033 dan NTF-034 tetap NOT_IMPLEMENTED. Kegagalan pengiriman hanya terlihat di ledger `whatsapp_messages`.
- **Status:** PROPOSED — AWAITING OWNER APPROVAL. Belum disetujui, bukan APPROVED.

---

## 17. Audit source (daftar awal, sudah diselesaikan di §18)

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

---

## 18. Hasil audit source (12 audit §17)

Setiap audit memuat: file/fungsi, perilaku aktual, perbandingan dengan Master Template, konflik, dampak variabel, rekomendasi, dan status. **`PASS` berarti audit selesai dengan bukti. Itu bukan berarti fitur sudah diimplementasikan.**

| # | Audit | Status | Ringkasan |
|---|---|---|---|
| A1 | Teks template WA default (10 event) vs NTF-009–031 | GAP | Teks default jauh lebih pendek. Blok invoice, tanggal, dan link dalam spesifikasi belum ada di default |
| A2 | Teks email `AccountRegisteredMail` vs NTF-001 | GAP | Hanya nama dan pemberitahuan. Email masked, tanggal, dan URL login tidak ada |
| A3 | Teks WA registrasi vs NTF-001/G-05 | GAP | Teks tetap, tanpa baris "abaikan jika bukan Anda" |
| A4 | Alur akun dibuat admin vs NTF-002 | PASS (audit) — NTF-002 tetap NOT_IMPLEMENTED | Admin mengisi password sendiri. Tidak ada tautan set-password |
| A5 | Sumber `tanggal` per event | GAP | `tanggal` selalu `order.created_at`, termasuk event pembayaran dan pengiriman |
| A6 | `kurir`/`nama_kurir`, `resi`/`nomor_resi` | GAP | Dua variabel per pasangan tidak setara (gabungan vs shipment pertama) |
| A7 | `PaymentSummaryService` dan `sisa_tagihan` | PASS (audit) — dengan koreksi alias | `sisa_tagihan` = `max(total − paid, 0)`, 0 jika batal. `jumlah_dibayarkan` adalah kumulatif |
| A8 | Allow-list nomor uji dan `sendTest` | GAP (minor) | Allow-list dicek di controller. Service `sendTest` tidak punya pemeriksaan sendiri |
| A9 | Refund ke penerima dan `RefundRecipientService` | PASS (audit) | Penerima checkout tidak menerima event refund. Destinasi refund hanya terlihat peran terbatas |
| A10 | Status `unknown` pada ledger | PASS (audit) | `unknown` tidak pernah di-retry otomatis. Retry operator wajib konfirmasi risiko duplikat |
| A11 | Editor template di dashboard | GAP (minor) | Endpoint dan UI ada. Validasi variabel saat simpan belum dibaca penuh |
| A12 | Target URL (`link_pesanan`, login, invoice, persetujuan) | GAP | `link_pesanan` relatif dan tidak cocok dengan route frontend. Tiga URL lain belum punya route |

### A1. Teks template WA default

- **File/fungsi:** `app/Services/WhatsApp/WhatsAppEvents.php::all()`, field `default`.
- **Perilaku aktual:** 10 teks default, masing-masing 2–4 baris, menyebut `{nama_pelanggan}`, `{nomor_pesanan}`, `{total}`, `{status}`, `{nama_toko}`, dan untuk pembayaran `{jumlah_dibayarkan}` dan `{sisa_tagihan}`. Pengiriman menyebut `{kurir}` dan `{resi}`. Tidak ada daftar produk, diskon, ongkir, metode bayar, atau link.
- **Perbandingan:** NTF-009 memuat ringkasan biaya lengkap, metode bayar, tujuan, dan link invoice. Default `order_created` hanya memuat total. NTF-011 menyebut "jumlah diterima". Default `payment_paid` hanya menyebut `{total}`, yaitu total pesanan, bukan jumlah yang diterima.
- **Konflik:** tidak ada konflik keamanan. Ini gap konten.
- **Dampak variabel:** `daftar_produk`, `subtotal`, `diskon`, `ongkir`, `metode_pembayaran`, `status_pembayaran`, dan `tanggal_pembayaran` sudah ada di `PAYMENT_VARS`, tetapi `ORDER_VARS` tidak memuatnya. Template yang ingin memakainya harus memakai event pembayaran.
- **Rekomendasi:** tahap 1 roadmap. Perbarui teks default `order_created` dan `payment_paid` agar memakai variabel yang tersedia. Template Agen yang sudah diedit tidak ditimpa.
- **Status:** GAP.

### A2. Email `AccountRegisteredMail` vs NTF-001

- **File/fungsi:** `app/Mail/AccountRegisteredMail.php::content()`, lang `messages.auth.registered_mail_subject` dan `registered_mail_body` (id, en, ar, zh).
- **Perilaku aktual:** subjek id: "Selamat datang di PrimeClassy". Body: nama penerima, lalu "Akun PrimeClassy Anda telah dibuat. Email ini hanya pemberitahuan dan bukan verifikasi. ... Kami tidak pernah meminta kata sandi Anda lewat email." Mailable mengimplementasikan `ShouldQueue` dan dikirim lewat `primeclassy-notify`.
- **Perbandingan:** subjek hampir sama. NTF-001 juga memuat `{email_masked}`, `{tanggal_pendaftaran}`, `{url_login}`, dan "jika Anda tidak merasa..." Source tidak memuatnya.
- **Konflik:** tidak ada konflik keamanan. Body source konsisten dengan kebijakan "jangan minta password lewat email".
- **Dampak variabel:** `email_masked`, `tanggal_pendaftaran`, dan `url_login` tidak ada di kontrak.
- **Rekomendasi:** perbarui body menjadi versi spesifikasi bila D-06 disetujui. Sebelum itu, body source tetap dipakai.
- **Status:** GAP.

### A3. Teks WA registrasi vs NTF-001 dan G-05

- **File/fungsi:** `app/Services/Auth/RegistrationNotifier.php::whatsapp()` dan `whatsappBody()`.
- **Perilaku aktual:** teks tetap: "Selamat datang di PrimeClassy. Akun Anda telah dibuat. Untuk melihat informasi lebih lengkap ... verifikasi WhatsApp Anda melalui aplikasi resmi PrimeClassy." Dikirim lewat jalur `restricted`, peran `registrant`, event `account_registered`, dengan idempotency `txn:account_registered:user:{id}`.
- **Perbandingan:** G-05 menyebut "jika bukan Anda, abaikan pesan ini". Teks source tidak memuatnya.
- **Konflik:** tidak ada. Teks source tidak mengandung kode atau tautan.
- **Dampak variabel:** tidak ada variabel. Teks ini tetap.
- **Rekomendasi:** tambahkan baris "abaikan jika bukan Anda" bila G-05 dipilih. Ini perubahan teks tetap dan perlu persetujuan konten.
- **Status:** GAP.

### A4. Alur akun dibuat admin vs NTF-002

- **File/fungsi:** `app/Http/Controllers/Api/V1/User/UserController.php::store()`, `app/Http/Requests/User/CreateUserRequest.php`, `app/Services/User/UserManagementService.php::create()`, `app/Models/User.php` (cast `password => hashed`).
- **Perilaku aktual:** admin mengirim `password` (required, min 8, confirmed). Service menyimpannya lewat cast `hashed`. Setelah itu `RegistrationNotifier::notify()` dipanggil. Komentar R-02 menyebut "never the password". Tidak ada tautan set-password, token, atau email undangan.
- **Perbandingan:** NTF-002 mengharapkan tautan pengaturan password dan instruksi aktivasi. Source tidak memuatnya.
- **Konflik:** NTF-002 bertentangan dengan alur source. Ini keputusan D-03. Password plaintext tidak masuk email dan tidak masuk log (sesuai Ketentuan NTF-002).
- **Dampak variabel:** `url_pengaturan_password` dan `nama_role` tidak ada di kontrak.
- **Rekomendasi:** D-03. Sampai diputuskan, alur source dipertahankan.
- **Status:** PASS (audit selesai). NTF-002 tetap NOT_IMPLEMENTED.

### A5. Sumber `tanggal` per event

- **File/fungsi:** `app/Services/WhatsApp/OrderNotifier.php::data()` baris `tanggal => $this->date($order->created_at)`, dan `tanggal_pembayaran => $this->date($latestVerified?->paid_at)`.
- **Perilaku aktual:** `tanggal` selalu tanggal pembuatan order, untuk semua event. Tanggal pembayaran tersedia lewat `tanggal_pembayaran` dari transaksi terverifikasi terakhir. Tidak ada tanggal pengiriman, tanggal pembatalan, tanggal refund, atau tanggal retur.
- **Perbandingan:** NTF-016 "Tanggal Pengiriman", NTF-026 "Tanggal Penyelesaian", NTF-018 "Tanggal Pembatalan" tidak bisa dipenuhi. Dalam event pengiriman, `{tanggal}` menunjukkan tanggal pesanan dibuat, yang menyesatkan.
- **Konflik:** tidak ada konflik keamanan. Ini salah makna pada variabel yang ada.
- **Dampak variabel:** `tanggal` harus diberi konteks per event, atau diganti per event. `tanggal_pesanan` tetap REQUIRES CONTEXT.
- **Rekomendasi:** tahap 2 roadmap. Tambahkan variabel per event (tanggal pengiriman, pembatalan, refund) hanya jika ada sumber data. Jangan mengisi dengan tanggal lain.
- **Status:** GAP.

### A6. `kurir`/`nama_kurir` dan `resi`/`nomor_resi`

- **File/fungsi:** `OrderNotifier::data()` baris 205–206 dan 219–221.
- **Perilaku aktual:**
  - `kurir` = gabungan nama kurir unik dari semua shipment non-batal, dipisah koma.
  - `nama_kurir` = kurir shipment pertama (`first()`), atau nama `selfDeliveredBy` jika tidak ada kurir.
  - `resi` = gabungan semua `tracking_number` unik.
  - `nomor_resi` = `tracking_number` shipment pertama.
  - `nomor_telepon_kurir` dan `estimasi_tiba` selalu null. Komentar HA-06 menyebut tidak ada sumber, sehingga baris dihilangkan.
- **Perbandingan:** spesifikasi memakai satu kurir dan satu resi. Pesanan dengan banyak shipment menghasilkan daftar di `kurir`/`resi`, tetapi nilai tunggal di `nama_kurir`/`nomor_resi`.
- **Konflik:** tidak ada konflik keamanan. Aturan "jangan buat resi palsu" terpenuhi karena nilai berasal dari data shipment, bukan dibuat.
- **Dampak variabel:** alias `kurir`↔`nama_kurir` dan `resi`↔`nomor_resi` dikoreksi menjadi REQUIRES CONTEXT (lihat §7).
- **Rekomendasi:** D-08 (baru, §19): aturan tampilan untuk pesanan multi-shipment.
- **Status:** GAP.

### A7. `PaymentSummaryService` dan `sisa_tagihan`

- **File/fungsi:** `app/Services/Payment/PaymentSummaryService.php::summarize()` dan `app/Support/CollectibleReceivable.php::forOrder()`.
- **Perilaku aktual:**
  - `total_paid` = `order.paid_amount` (kumulatif, sumber kebenaran pembayaran).
  - `grand_total` = `order.total_amount`.
  - `remaining_balance` = `CollectibleReceivable::forOrder` = `max(total − paid, 0)`, dan 0 jika order batal.
  - `overpaid_amount` = `max(paid − total, 0)`.
  - `submitted_dp` berasal dari verifikasi pending dan tidak dihitung sebagai dibayar.
- **Perbandingan:** `sisa_tagihan` = `sisa_pembayaran` sudah setara. `jumlah_dibayarkan` = total kumulatif, sehingga `total_dibayar` di spesifikasi setara dengan `jumlah_dibayarkan`.
- **Konflik:** tidak ada. Ini sesuai HA-04/HA-06 (pembayaran terverifikasi saja).
- **Dampak variabel:** koreksi alias: `jumlah_dibayar` per transaksi tidak punya sumber. `total_dibayar` ↔ `jumlah_dibayarkan` adalah ALIAS. `total_tagihan` = `total_amount`, bukan sisa.
- **Rekomendasi:** tambahkan variabel `jumlah_pembayaran_ini` bila NTF-012 dan NTF-011 butuh nominal per transaksi (D-10, §19).
- **Status:** PASS (audit selesai). Berlaku dengan koreksi §7.

### A8. Allow-list nomor uji dan `sendTest`

- **File/fungsi:** `app/Http/Controllers/Api/V1/Admin/WhatsAppGatewayController.php` (baris 52–60), `app/Http/Controllers/Api/V1/Agent/AgentWhatsAppController.php` (baris 189–190), `app/Services/WhatsApp/WhatsAppDelivery.php::sendTest()` dan `sendAgentTest()`.
- **Perilaku aktual:** controller memeriksa `testNumbers()` atau `isAllowedTestNumber()` sebelum memanggil service. Service `sendTest` dan `sendAgentTest` tidak memeriksa allow-list sendiri. Keduanya membuat ledger `purpose = test` dan langsung mengirim.
- **Perbandingan:** NTF-004 meminta kode OTP. Allow-list adalah mekanisme uji, bukan OTP. Keduanya berbeda.
- **Konflik:** risiko pertahanan berlapis. Pemanggil service baru bisa mengirim ke nomor mana pun.
- **Dampak variabel:** tidak ada.
- **Rekomendasi:** pindahkan pemeriksaan allow-list ke service sebagai pertahanan kedua. Ini perubahan kode, bukan bagian audit ini.
- **Status:** GAP (minor).

### A9. Refund ke penerima dan `RefundRecipientService`

- **File/fungsi:** `app/Services/WhatsApp/RestrictedDeliveryPolicy.php::eventEligible()` dan `check()`, `app/Services/Crm/RefundRecipientService.php`.
- **Perilaku aktual:**
  - `eventEligible` untuk peran `recipient` hanya `ORDER_EVENTS`. `refund_completed` dan `payment_*` tidak eligible.
  - `check()` mengembalikan `restricted_not_allowed` untuk event itu.
  - `RefundRecipientService` mencatat siapa penerima refund CRM (nama, metode, bukti). Bersifat append-only. Tidak membuat, menyetujui, atau membayar refund.
  - `canSeeDestination` hanya untuk `super_admin` dan `keuangan`. Peran lain melihat ekor masking.
- **Perbandingan:** NTF-026 ditujukan ke `{nama_penerima}`. Source hanya mengirim `refund_completed` ke pembuat dan admin. Ini tidak sama dengan spesifikasi.
- **Konflik:** NTF-026 dan G-03 bertentangan dengan `RestrictedDeliveryPolicy`. Ini D-01.
- **Catatan:** baris ledger untuk penerima dibuat dengan skip `restricted_not_allowed`. Ini dibuktikan dari `check()`. Alur enqueue penuh untuk penerima belum saya baca. Ledger tetap bersih dari pengiriman.
- **Dampak variabel:** tidak ada tambahan.
- **Rekomendasi:** D-01 (opsi a).
- **Status:** PASS (audit selesai). Konflik spesifikasi tetap menunggu D-01.

### A10. Status `unknown` pada ledger

- **File/fungsi:** `app/Services/WhatsApp/WhatsAppDelivery.php` baris 20, 260–261, 298–318.
- **Perilaku aktual:** hasil gateway `UNKNOWN` menjadi status `unknown`. Tidak di-retry otomatis. Retry manual untuk `unknown` mengembalikan 409 `duplicate_risk_confirmation_required` sampai operator menerima risiko duplikat. Batas volume menghitung `unknown` sebagai kemungkinan sudah terkirim.
- **Perbandingan:** NTF-014 berisi "jangan melakukan pembayaran ulang sebelum memastikan status". Itu teks pembayaran ke pelanggan, bukan aturan ledger. Tidak ada padanan langsung.
- **Konflik:** tidak ada.
- **Dampak variabel:** tidak ada.
- **Rekomendasi:** tidak ada perubahan. Dokumen operasi (`docs/OPERATIONS.md`) sudah mencatat aturan ini.
- **Status:** PASS (audit selesai).

### A11. Editor template di dashboard

- **File/fungsi:** `app/Http/Controllers/Api/V1/Admin/WhatsAppGatewayController.php` (`previewTemplate`, `updateTemplate`, `resetTemplate`), `frontend/src/api/whatsapp.ts` (`previewWhatsAppTemplate`, `saveWhatsAppTemplate`, `resetWhatsAppTemplate`), `frontend/src/views/dashboard/WhatsAppGatewayView.vue`.
- **Perilaku aktual:** endpoint preview memakai `WhatsAppEvents::sample()`. Simpan menulis `wa.template.{event}` dan mencatat audit. Reset mengembalikan default. UI memanggil ketiga fungsi.
- **Perbandingan:** preview memakai nilai contoh di `sample()`. Nilai `sample()` belum memuat `jumlah_dibayarkan` dan beberapa variabel lain sesuai kontrak.
- **Konflik:** tidak ada.
- **Dampak variabel:** validasi variabel asing saat simpan belum dibaca penuh. Ini gap kecil.
- **Rekomendasi:** baca `MessageTemplates::render` dan validasi simpan. Tambahkan tes bila perlu.
- **Status:** GAP (minor).

### A12. Target URL

- **File/fungsi:** `OrderNotifier::data()` baris `link_pesanan => '/orders/'.$order->order_no`, `frontend/src/router` (`/orders/:id`, `props: Number(route.params.id)`, `requiresAuth: true`), `/login`, `/dashboard/invoice-config`, `frontend` route persetujuan CRM (belum ditemukan).
- **Perilaku aktual:**
  - `link_pesanan` adalah path relatif tanpa domain. Route frontend `/orders/:id` mengubah parameter dengan `Number()`. `order_no` seperti `PC-261028-000123` menjadi `NaN`. Tautan tidak berfungsi.
  - `/orders/:id` memerlukan login. Penerima checkout yang belum punya akun tidak bisa membukanya.
  - Tidak ada route untuk invoice pelanggan (`/dashboard/invoice-config` adalah pengaturan toko).
  - Tidak ada route persetujuan konsumen.
- **Perbandingan:** NTF-009, NTF-016, dan lainnya memakai `{url_detail_pesanan}`. Source memakai path relatif yang rusak.
- **Konflik:** tautan ke pihak yang tidak berwenang (penerima tanpa akun) bertentangan dengan kebijakan restricted. Tautan hanya boleh untuk peran yang berwenang.
- **Dampak variabel:** `url_detail_pesanan` GAP (lihat §7). `url_login`, `url_invoice`, `url_dashboard_order`, `url_persetujuan` belum punya sumber.
- **Rekomendasi:** D-09 (baru, §19): basis URL absolut dari konfigurasi, parameter route yang benar, dan aturan siapa yang boleh menerima tautan.
- **Status:** GAP.

---

## 19. Keputusan tambahan yang ditemukan audit

Audit menemukan tiga keputusan yang belum ada di §16. Semuanya berstatus **PROPOSED — AWAITING OWNER APPROVAL**.

- **D-08 — Tampilan multi-shipment.** Pesanan dengan lebih dari satu shipment. Opsi: (a) gabungkan semua kurir dan resi di `kurir`/`resi`, (b) tampilkan shipment pertama, (c) kirim satu pesan per shipment. Rekomendasi: (a) untuk kompatibilitas template, dengan `nama_kurir`/`nomor_resi` tetap berarti shipment pertama. Konsekuensi: (c) mengubah idempotency key dan jumlah pesan.
- **D-09 — Basis URL dan akses tautan.** Opsi: (a) basis URL dari konfigurasi (`APP_URL` atau setting baru), dan tautan hanya untuk peran berwenang; (b) tanpa tautan dalam pesan restricted. Rekomendasi: (a) untuk pesan full, (b) untuk restricted. Konsekuensi: butuh route pelanggan yang benar.
- **D-10 — Nominal per transaksi.** Apakah `jumlah_pembayaran_ini` (nominal transaksi ini) dibutuhkan? Opsi: (a) tambahkan dari `PaymentTransaction.amount` yang terverifikasi, (b) tidak. Rekomendasi: (a) untuk NTF-011 dan NTF-012.

---

## 20. Matriks event, kanal, dan penerima (terverifikasi)

| Event | Admin | Pembuat | Penerima (restricted) | Registrant | Email | In-App |
|---|---|---|---|---|---|---|
| `order_created` | full | full | ya (status) | — | tidak | tidak |
| `payment_paid` | full | full | tidak (kebijakan) | — | tidak | tidak |
| `payment_partial` | full | full | tidak (kebijakan) | — | tidak | tidak |
| `order_processed` | full | full | ya | — | tidak | tidak |
| `order_shipped` | full | full | ya | — | tidak | tidak |
| `order_delivered` | full | full | ya | — | tidak | tidak |
| `order_cancelled` | full | full | ya | — | tidak | tidak |
| `return_requested` | full | full | tidak | — | tidak | tidak |
| `return_approved` | full | full | tidak | — | tidak | tidak |
| `refund_completed` | full | full | tidak (kebijakan) | — | tidak | tidak |
| `account_registered` | — | — | — | restricted | `AccountRegisteredMail` (queued) | tidak |
| OTP WA / email / pemulihan | — | — | — | — | OTP sinkron | tidak |

Keterangan: "full" memakai template Agen. "restricted" memakai teks tetap di server. Tidak ada kanal in-app dan tidak ada email transaksi order, retur, atau refund.

---

## 21. Kontrak variabel aktual (hasil audit)

Sumber kebenaran: `OrderNotifier::data()`, `WhatsAppEvents::sample()`, dan konstanta `*_VARS`.

| Variabel | Sumber | Event yang memakainya | Catatan |
|---|---|---|---|
| `nama_pelanggan` | `order.konsumen.name` | semua event order | — |
| `nomor_pesanan` | `order.order_no` | semua | — |
| `status` | `STATUS_LABEL[order.status]` | order, dibatalkan | label, bukan kode |
| `total` | `order.total_amount` | order, pembayaran | total pesanan |
| `tanggal` | `order.created_at` | semua | selalu tanggal pesanan (A5) |
| `nama_toko` | `AgentProfile.store_name` atau "Prime Classy" | order | — |
| `kurir` | gabungan kurir unik, shipment non-batal | pengiriman | multi (A6) |
| `resi` | gabungan tracking unik | pengiriman | multi (A6) |
| `daftar_produk` | item snapshot | pembayaran | — |
| `subtotal`, `diskon`, `ongkir` | `order.*_amount` | pembayaran | — |
| `total_tagihan` | `order.total_amount` | pembayaran | bukan sisa (A7) |
| `metode_pembayaran` | `order.paymentMethod` atau transaksi terverifikasi | pembayaran | — |
| `status_pembayaran` | `order.payment_status` | pembayaran | — |
| `jumlah_dibayarkan` | `PaymentSummary.total_paid` | DP, pembayaran | kumulatif (A7) |
| `sisa_tagihan` | `CollectibleReceivable.forOrder` | DP, pembayaran | — |
| `tanggal_pembayaran` | `paid_at` transaksi terverifikasi terakhir | pembayaran | — |
| `nama_kurir` | kurir shipment pertama | pengiriman | tunggal (A6) |
| `layanan_pengiriman` | `shipping_provider_code` atau tipe kurir | pengiriman | — |
| `nomor_resi` | `tracking_number` shipment pertama | pengiriman | tunggal (A6) |
| `nomor_telepon_kurir`, `estimasi_tiba`, `link_pelacakan` | null | pengiriman | selalu dibuang, tidak pernah dikarang |
| `link_pesanan` | `/orders/{order_no}` | semua | relatif, rusak (A12) |
| `nomor_retur` | `RTN-{id:06}` | retur | — |
| `jumlah_refund` | `RefundPayout.amount` | refund | — |

Variabel dari spesifikasi yang **tidak ada sumbernya**: `email_masked`, `tanggal_pendaftaran`, `url_login`, `url_pengaturan_password`, `url_verifikasi_email`, `url_invoice`, `url_dashboard_order`, `url_persetujuan`, `waktu_kedaluwarsa`, `masa_berlaku_menit`, `kode_otp` (boleh di OTP channel, bukan di template), `instruksi_*`, `alasan_aman`, `referensi_aman`, `*_aman`, `alamat_pengiriman`, `nama_pembuat`, `nama_agen`, `tanggal_pengiriman`, `tanggal_pembatalan`, `tanggal_refund`, `jumlah_dibayar` (per transaksi), `device_id_masked`.

---

## 22. Konflik, keputusan, dan kebutuhan implementasi

### 22.1 Konflik yang terselesaikan

- **Kurir dan resi:** alias ALIAS dikoreksi menjadi REQUIRES CONTEXT (A6).
- **Jumlah dibayar:** `jumlah_dibayarkan` adalah kumulatif. Alias per transaksi dihapus (A7).
- **Total dibayar:** ALIAS ke `jumlah_dibayarkan` (A7).
- **Link pesanan:** GAP, bukan ALIAS (A12).
- **Penerima dan refund:** konsisten dengan `RestrictedDeliveryPolicy` (A9). Tetap menunggu D-01 untuk spesifikasi G-03.
- **Status `unknown`:** konsisten dengan operasi (A10).

### 22.2 Konflik yang masih terbuka

- **NTF-002 dan D-03:** spesifikasi meminta tautan set-password, source memakai password dari admin (A4).
- **NTF-003, NTF-005 dan D-02:** spesifikasi memakai tautan, source memakai OTP.
- **G-03, NTF-026 dan D-01:** spesifikasi meminta informasi keuangan ke penerima checkout, kebijakan menutupnya.
- **Tautan pesanan dan D-09:** tautan relatif dan rusak, dan tidak jelas siapa yang boleh menerimanya.
- **Multi-shipment dan D-08:** tampilan `kurir`/`resi` belum disepakati.

### 22.3 Daftar kebutuhan implementasi baru (belum dikerjakan, tidak masuk audit ini)

1. Teks default `order_created` dan `payment_paid` yang memakai variabel tersedia (A1).
2. Teks email `AccountRegisteredMail` dengan variabel tambahan, bila D-06 disetujui (A2).
3. Baris "abaikan jika bukan Anda" di teks registrasi, bila G-05 disetujui (A3).
4. Variabel `tanggal` per event (tanggal pengiriman, pembatalan, refund) dengan sumber nyata (A5).
5. Aturan tampilan multi-shipment (D-08) (A6).
6. Pemeriksaan allow-list di service `sendTest` dan `sendAgentTest` (A8).
7. Validasi variabel saat simpan template (A11).
8. Basis URL absolut dan route pelanggan (D-09) (A12).
9. Variabel `jumlah_pembayaran_ini` (D-10) (A7).
10. Email transaksi order, retur, refund (D-06).
11. Pusat notifikasi in-app untuk admin (D-07).
12. Event baru: pembayaran gagal, refund diajukan/disetujui/ditolak, retur ditolak, perubahan pesanan umum (D-05).
13. Pengingat pembayaran, dengan job terjadwal dan persetujuan volume (D-05, ditunda).
14. Notifikasi permintaan consent (D-04, bila disetujui).
15. Alias layer di `MessageTemplates::render`, sesuai §7 yang sudah dikoreksi.

### 22.4 Jawaban untuk pertanyaan Owner

1. **Apakah 39 template sudah dipetakan dengan benar?** Sebagian. Pemetaan event ke template sudah benar. Gap konten (A1–A3) dan koreksi alias (A6, A7, A12) memperbaiki beberapa pemetaan. Klasifikasi §3 tetap berlaku dengan catatan di §18.
2. **Apakah semua variabel punya sumber yang valid?** Tidak. Lebih dari 25 variabel dari spesifikasi tidak punya sumber (§21). Beberapa variabel yang ada punya makna berbeda (`tanggal`, `kurir`, `resi`, `jumlah_dibayarkan`).
3. **Apakah tiga penerima sudah didukung secara aktual?** Ya di kode untuk event order: resolver memisahkan admin, pembuat, dan penerima, dengan dedup berdasarkan privilege (`OrderRecipientResolver`). Penerima hanya menerima event status, dan teksnya tetap di server, sehingga NTF-032 hanya PARTIAL. Belum diuji end-to-end di sesi ini, karena Full Test hanya milik Owner.
4. **Apakah semua kebijakan verifikasi dan privasi konsisten?** Sebagian besar ya: OTP, restricted lane, penerima tanpa akun, destinasi refund, dan status `unknown` konsisten. Ada tiga ketidakkonsistenan: tautan pesanan rusak dan tidak dibatasi (A12), allow-list uji hanya di controller (A8), dan alur admin membuat password (A4) belum sesuai spesifikasi NTF-002.
5. **Fitur mana yang benar-benar belum diimplementasikan?** Lihat §22.3 (15 item) dan NOT_IMPLEMENTED di §3. Termasuk email transaksi order/retur/refund, in-app, pembayaran gagal, refund diajukan/disetujui/ditolak, retur ditolak, pengingat, notifikasi consent, tautan absolut, dan alias layer.
6. **Keputusan apa yang masih butuh persetujuan Owner?** D-01 sampai D-07 (status PROPOSED) dan D-08 sampai D-10 (§19).

---

## 23. Status dokumen

- **Status:** DRAFT — keputusan D-01 sampai D-10 **dipakai sebagai baseline desain** atas instruksi Owner (2026-11-02, "Notification Fast-Track"). Belum ada persetujuan formal per keputusan; perubahan terhadap baseline tetap memerlukan keputusan Owner.
- Status implementasi terbaru ada di §24. Status di §3 dan §11 adalah kondisi **sebelum** implementasi dan dipertahankan sebagai riwayat audit.

---

## 24. Status implementasi (branch `docs/notification-contract-v1`, 2026-11-02)

"IMPLEMENTED" di bawah berarti kode, migrasi, dan scoped test ada di branch. **Belum berarti aktif di production.** Aktivasi production memerlukan migrasi dan saklar Owner.

### 24.1 Koreksi hasil audit

- **A11 → PASS.** `MessageTemplates::validate()` sudah menolak token di luar allow-list, kurung kurawal rusak, karakter kontrol, dan teks lebih dari 1000 karakter. Gap A11 di §18 tidak berlaku.
- **`sample()` sudah memuat `jumlah_dibayarkan`.** Catatan di §7 dan §18 A11 yang menyebut sebaliknya keliru.
- **NTF-009 dan invariant yang ada:** template status pesanan (`order_created` dan status lain) sengaja tidak boleh memakai variabel uang selain `{total}` (dikunci oleh `WhatsAppAgentScopeTest`). Rincian invoice NTF-009 tidak ditambahkan ke `order_created`; rincian itu tersedia di event pembayaran.

### 24.2 Yang diimplementasikan

**Paket 1 — WhatsApp Foundation**
- `link_pesanan` menjadi URL absolut dengan id numerik: `{APP_FRONTEND_URL|APP_URL}/orders/{id}` (`OrderNotifier::orderUrl`). Baris dihilangkan jika basis URL kosong. Teks restricted tetap tanpa tautan (D-09).
- Variabel baru bersumber nyata: `jumlah_pembayaran_ini` (D-10, transaksi terverifikasi terakhir), `tanggal_pengiriman`, `tanggal_diterima` (shipment), `tanggal_pembatalan` (order), `tanggal_refund` (payout). `{tanggal}` tetap tanggal pesanan.
- Daftar variabel hanya ditambah (superset), sehingga template Agen yang tersimpan tetap valid dan tidak ditimpa. Teks default 10 event diperkaya sesuai Master Template, dengan footer WhatsApp standar.
- Allow-list nomor uji dicek ulang di service (`sendTest`, `sendAgentTest`), bukan hanya di controller (A8).

**Paket 2 — Email transaksi**
- `TransactionalEmailNotifier` + `SendTransactionalEmailJob` (antrean `primeclassy-notify`, `$tries = 1`, klaim baris atomik `queued → sending`) + `TransactionalNotificationMail`.
- Ledger `email_messages` (migrasi `2026_11_05_100000`, additive) dengan `idempotency_key` unik per event × subjek × akun penerima.
- Penerima: admin cabang dan pembuat (buyer + submitter), **hanya email terverifikasi**. Penerima checkout tidak pernah menerima email. Order CRM-origin dilewati, sama seperti WhatsApp.
- Saklar `smtp.transactional_enabled` (**default OFF**), terpisah dari saklar SMTP dan dari saklar WhatsApp. Saklar dibaca ulang saat kirim.

**Paket 3 — In-App dan event tambahan**
- Event baru (semua default OFF per Agen, seperti event lain): `payment_failed`, `return_rejected`, `refund_requested`, `refund_approved`, `refund_rejected`. Event non-status otomatis tertutup untuk penerima checkout (`RestrictedDeliveryPolicy` tidak berubah).
- Pusat notifikasi operator: tabel `admin_notifications` (migrasi `2026_11_05_100100`, additive), satu baris per akun penerima, `dedupe_key` unik. API `GET/POST /api/v1/notifications/admin*` (peran `super_admin`, `agen`, `admin`) hanya membaca baris milik pemanggil. UI: lonceng di topbar dan halaman `/dashboard/notifications`.
- Sumber notifikasi In-App: pesanan baru, pembayaran ditolak, retur baru, refund menunggu persetujuan (operator cabang); kegagalan pengiriman WA (`failed`/`unknown`) dan email (`failed`), serta perangkat WA terputus/terhubung kembali (operator cabang + Super Admin).
- Retensi: `php artisan primeclassy:admin-notifications-prune` (dry-run default, `--execute`, hanya baris sudah dibaca, minimal 7 hari). **Tidak dijadwalkan.**
- Pengingat pembayaran (NTF-020) **tidak** diimplementasikan, sesuai D-05.

### 24.3 Klasifikasi 39 template setelah implementasi

| Status | Jumlah | Template |
|---|---|---|
| IMPLEMENTED | 20 | NTF-004, 011, 012, 013, 014, 015, 016, 017, 018, 021, 022, 023, 024, 025, 026, 027, 030, 031, 033, 034 |
| IMPLEMENTED_WITH_DIFFERENT_CONTENT | 2 | NTF-001 (tidak diubah), NTF-009 (tanpa rincian invoice, invariant §24.1) |
| PARTIALLY_IMPLEMENTED | 7 | NTF-008, 028, 032; G-01, G-02, G-04, G-05 |
| NOT_IMPLEMENTED | 7 | NTF-002, 006, 007, 010, 019, 020 (ditunda D-05), 029 |
| REQUIRES_OWNER_DECISION | 3 | NTF-003, 005 (D-02); G-03 (D-01) |

Catatan semantik: NTF-011 dan NTF-013 sama-sama `payment_paid`, karena event itu hanya untuk pelunasan penuh. NTF-023 berarti retur selesai dengan **semua** item ditolak. NTF-027 hanya bersumber dari penutupan obligation `needs_verification` oleh Owner (exit B), karena tidak ada status "refund ditolak" lain di source.

### 24.4 Aktivasi production (belum dilakukan)

1. Scoped test untuk semua area yang berubah lulus, frontend type-check/build lulus. Full Test bukan syarat rilis (keputusan Owner, 2026-11-02).
2. Backup production, lalu migrasi `2026_11_05_100000` dan `2026_11_05_100100` (Pending → Ran).
3. Opsional: `APP_FRONTEND_URL` bila SPA tidak satu domain dengan `APP_URL` (production satu domain, jadi tidak perlu).
4. Owner menyalakan event baru per Agen dan saklar email transaksi bila diinginkan. Teks default baru langsung berlaku untuk event yang sudah aktif dan memakai template default.
