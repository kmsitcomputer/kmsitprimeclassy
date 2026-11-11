# 06 — Business Workflows (Alur Bisnis End-to-End)

Alur bisnis rekonstruksi dari implementasi aktual. Format per alur: Aktor → Prasyarat → Trigger → Langkah → Validasi backend → Transisi status → Efek DB → Notifikasi → Failure handling → Otorisasi → Pengecualian. Aturan bisnis rinci: [05-BUSINESS-RULES](05-BUSINESS-RULES.md); endpoint: [08-BACKEND-API](08-BACKEND-API.md).

Status legenda: **IMPLEMENTED** (terverifikasi dari kode+tes), **NOT VERIFIED** (ada di kode, verifikasi runtime belum tercatat di repo).

---

## 1. Registrasi & autentikasi — IMPLEMENTED

- **Aktor:** calon konsumen (publik). **Trigger:** `POST /auth/register` (throttle 5/menit) atau Google OAuth.
- **Langkah:** validasi (nama/email/telepon unik & dinormalisasi `PhoneNormalizer`) → resolve referral (opsional untuk email/password; **wajib** untuk Google `register` mode) → buat `users` (role `konsumen`, `agent_id` = Agen dari rantai referral, snapshot hierarki `korsal_id`/`sales_id`/`parent_id`) → audit.
- **Login:** `POST /auth/login` (throttle 10/menit) → session Sanctum SPA (cookie). `GET /auth/me` mengembalikan user + `PermissionMap` capability hints.
- **Efek DB:** `users` + `user_closures`; Google: `user_social_identities`.
- **Failure:** registrasi tanpa Agen aktif → error `registration_unavailable` (catatan AU-01/G-07: masih `RuntimeException` 500 di jalur email/password; jalur Google sudah 503 benar).
- **Otorisasi:** publik; ownership chain dihitung server-side, referral klien hanya di-revalidasi. Google: aturan §23 [05-BUSINESS-RULES](05-BUSINESS-RULES.md) (tanpa auto-link akun unverified; tanpa pembuatan akun saat login).

## 2. Verifikasi Email & WhatsApp — IMPLEMENTED

- **Email (mandiri):** `GET/POST /profile/email/verification` + `/confirm` (throttle khusus). Challenge tercatat; `email_verified_at` di-set setelah konfirmasi. Syarat kanal email transaksional & OTP.
- **WhatsApp (bukti nomor):** `POST /profile/whatsapp/verification` → kode dikirim (WA/OTP) → `POST .../confirm` → proof kepemilikan nomor terikat; ganti nomor membatalkan proof. Kode tidak pernah plaintext/log. Syarat penerima WA transaksional.
- **Agen WA device (pairing):** `POST /agent/whatsapp/device` → `pair/qr` atau `pair/code` → state `agent_whatsapp_devices` (`not_paired→connected→…`), reconnect/logout/replace tersedia (Agen/Admin Agen).
- **Failure:** challenge kedaluwarsa/attempt habis → 422; tidak membocorkan eksistensi akun.

## 3. Pengelolaan akun & role (hierarki) — IMPLEMENTED

- **Aktor:** `super_admin`, `agen`, `korsal`, `koordinator-kurir` (pembuat sesuai `HierarchyRules`); pembaca role ter-scope.
- **Langkah:** `POST /users` (siapa boleh membuat siapa — LOCKED §2) → ownership diturunkan dari aktor (bukan request) → audit. Update/soft-delete/reassign-referral/konversi-SKS (`convert-to-sales-kurir-sub`, hanya `agen` pemilik).
- **Pengecualian:** koordinator-kurir hanya mengelola `kurir` anak di cabangnya (list/edit; tanpa delete/reassign/ubah role).

## 4. Produk & katalog — IMPLEMENTED

- **Aktor CRUD:** `super_admin`/`agen`/`admin` (create/read/update; **admin tanpa delete**); kategori & terjemahan: `super_admin`; diskon/voucher: `super_admin`/`agen`/`admin` scope cabang.
- **Langkah:** form produk (SKU wajib untuk produk sederhana; variasi ber-SKU masing-masing; satu namespace `catalog_skus`) → gambar (media upload, 5 MiB, JPEG/PNG/WebP) → fee per produk/variasi (`super_admin`/`agen`) → publish (`status=active`).
- **Validasi:** baris order hanya boleh produk aktif; XOR produk/variasi sesuai `has_variations`.
- **Pengecualian:** katalog global — satu agen bisa membuat/mengubah produk apa pun (keputusan eksplisit, sama seperti super_admin).

## 5. Keranjang & checkout (konsumen) — IMPLEMENTED

- **Aktor:** konsumen (atau guest — langkah "account" dinamis muncul untuk tamu). **Trigger:** `POST /checkout/quote`, `GET /checkout/steps`.
- **Langkah:** pilih alamat (buku alamat / wilayah bertingkat / "Gunakan Lokasi Saya" reverse-geocode) → pilih metode pengiriman (Kurir Toko distance-based via ORS→fallback KMSIT; Ekspedisi RajaOngkir; Pickup) → quote ongkir (`ShippingQuoteService`) → pilih metode pembayaran (`AvailablePaymentMethodService`: konsumen = transfer saja) → `Idempotency-Key`.
- **Validasi server:** harga/fee/SKU/ongkir/stok-sumber semua derivasi server; stok availabilitas dari `SellableStockService` (target warehouse-only mendapat baris komitmen nol agar orderable).

## 6. Pembuatan pesanan — IMPLEMENTED

- **Aktor:** `konsumen` (sendiri), `agen`/`korsal`/`sales`/`sales-kurir-sub` (atas nama konsumen dalam jaringan; `OrderPolicy::create`).
- **Trigger:** `POST /orders` (throttle 30/menit; `Idempotency-Key` wajib).
- **Langkah (transaksional, lock Order-first):** resolve tiap baris (`resolveLine`) → reserve stok (Agen: `quantity_reserved += qty`; Sub: baris `sub_stock_reservations` bila SKS self/network per §7) → buat `orders` + `order_items` (snapshot harga/fee/nama/SKU/alamat/wilayah) → komisi pending agent+sales → shipment kanonis `standard` pending (per grouping key) → Stock Request bila ada item Agent-source → snapshot finansial awal (Laporan A: `order_initial_report_items`).
- **Transisi:** COD → `diproses`; non-COD → `diterima` + `payment_status=unpaid`; DP → `diterima` hingga verifikasi.
- **Efek DB:** orders, order_items, sub_stock_reservations / product_stocks.reserved, commissions, shipments, stock_requests(+items), order_initial_report_items, activity_logs.
- **Notifikasi:** `order_created` (WA+email+in-app, deferred after-commit).
- **Failure:** stok kurang → 422; idempotency replay → order sama dikembalikan; deadlock dipecahkan via urutan lock kanonis.

## 7. Order yang dibuat Admin/Agen (assisted & CRM-origin) — IMPLEMENTED

- **Agen/korsal/sales/SKS membuat order untuk konsumen existing:** sama dengan alur 6 (pembayar boleh upload bukti, `placed_by` tercatat).
- **CRM assisted order (D3):** `POST /crm/customers/{customer}/orders` oleh pemilik Sales/SKS, Korsal-nya, atau Agen cabang. Tanpa login konsumen: buyer = `crm_customer_id` + snapshot nama/telepon (`buyer_name_snapshot`), `konsumen_id` NULL. Semua kebenaran (harga/stok/idempotency/snapshot/audit) tetap server-side. **Pengecualian:** order CRM-origin tidak pernah dinotifikasi WA/email (penerima tidak terverifikasi) — dicatat skipped.

## 8. Pembayaran penuh, DP & parsial — IMPLEMENTED

- **Penuh (transfer):** konsumen/staf scoped `POST /orders/{order}/payment/proof` → `bank_transfer_verifications` (pending) + `payment_transactions` pending → verifikasi Keuangan → `PaymentService::applyPaymentToOrder` (`paid_amount`, `payment_status=paid`).
- **DP:** metode `down_payment` (DP = sub-opsi transfer untuk staf; konsumen tidak ditawarkan DP) → verifikasi → `partially_paid`, `dp_amount` tercatat, `remaining_amount` = sisa → order boleh masuk `diproses`.
- **Parsial/cicilan:** `POST /orders/{order}/payment/installment` saat `diproses`/`dikirim`/`terkirim` (bukan COD; satu catatan pending per order; identik = replay).
- **Pelunasan:** `POST /orders/{order}/payment/settle` (Keuangan/super_admin meminta pelunasan saldo; idempotent §13).
- **Invarian:** satu writer `PaymentService`; `verified_amount` ≠ `submitted_amount` butuh `amount_adjustment_reason`; tanpa self-approval.

## 9. Upload bukti pembayaran — IMPLEMENTED

- **Aktor:** konsumen (miliknya); staf cabang; korsal/sales/SKS scope `payOnBehalf`; trio audit (`paid_by_role`, `submitted_by_user_id`, `verified_by`).
- **Trigger:** `POST /orders/{order}/payment/proof` (transfer) / `cod-proof` (COD) / `installment` (cicilan/kas).
- **Langkah:** validasi file (5 MiB, JPEG/PNG/WebP) → simpan disk **privat** (`proof_disk=local`) → row verifikasi `pending` (nominal `submitted_amount`, channel transfer/cash) → audit.
- **Pengecualian:** ditolak 422 bila transfer sudah verified/paid, bukti COD confirmed, atau order batal (anti double-count); bukti rejected boleh di-upload ulang.

## 10. Verifikasi oleh Keuangan — IMPLEMENTED

- **Aktor:** `keuangan`/`super_admin` SAJA (terkunci; route gate + `assertMayVerifyPayment`).
- **Langkah:** buka bukti via `GET /payment-proofs/{id}` (otorisasi dicek) → `POST /orders/{order}/payment/verify` (approve/reject + alasan) → bila approve: `verified_amount` (≤ outstanding; selisih wajib alasan) → `PaymentService` menerapkan ke `paid_amount`/`payment_status`/`remaining_amount` → transaksi `paid` + `paid_at` → invoice terbit bila pembayaran terverifikasi pertama (`invoice_issued_at`, snapshot v1) → notifikasi `payment_paid`/`payment_partial`.
- **COD:** `PATCH /orders/{order}/payment/cod` (toggle langsung, tanpa bukti) atau konfirmasi bukti COD `confirmCodProof`.
- **Efek DB:** payment_transactions, bank_transfer_verifications/cod_payment_proofs, orders.payment_*, order_invoice_snapshots, payment ledger events.
- **Failure:** nominal berbeda → wajib `amount_adjustment_reason`; kelebihan outstanding → 422.

## 11. Status pembayaran & invoice — IMPLEMENTED

- `payment_status`: `unpaid → pending_verification → partially_paid → paid` (+ `failed`/`partially_refunded`/`refunded` via jalur refund). Proyeksi "DP Diajukan" (submitted unverified) read-only, tidak pernah dihitung sebagai uang (§13).
- **Invoice:** `GET /orders/{order}/invoice` (PDF dinamis; otorisasi owner konsumen / role finansial cabang); konfigurasi presentasi `invoice_configs` (super_admin global, agen cabang); `order_invoice_snapshots` versi immutable.

## 12. Pemrosesan pesanan (fulfilment) — IMPLEMENTED

- **Aktor:** Koordinator Kurir (meminta), Gudang (antrian "Order Diproses"; menyetujui & menyerahkan), Koordinator Kurir (konfirmasi penerimaan). **Admin tidak menyetujui pemenuhan rutin** (Keputusan Owner 2026-11-11). **Prasyarat:** order `diproses` (COD sejak dibuat; non-COD setelah pembayaran terverifikasi).
- **Antrean Gudang:** `GET /warehouse/orders/diproses` (opsional `?requested=1`) menyertakan `fulfillment_request` per order: Koordinator pengaju, waktu, catatan, jumlah diminta/diserahkan/menunggu penerimaan/sisa — nilai nyata dari Stock Request, `null`/`requested_by_coordinator=false` bila Koordinator belum meminta. UI default menampilkan permintaan Koordinator dan dapat beralih ke semua order diproses.
- **Langkah:** Gudang lihat `GET /warehouse/orders/diproses` (hanya diproses AND tanpa kurir) → Stock Request internal order → Koordinator Kurir `POST /dispatch/orders/{order}/request-fulfillment` → Gudang `POST /warehouse/stock-requests/{request}/proposals` (multipart: jumlah per item ≤ remaining, **bukti serah terima wajib** bila ada barang diserahkan, **alasan wajib** untuk item yang diserahkan kurang dari sisa, `shortages[]` untuk item yang tidak tersedia sama sekali) → Koordinator Kurir `POST /warehouse/fulfillment-proposals/{p}/receive` (diterima/kurang/rusak) → pergerakan atomik `transit−q, shipping+q, reserved−q` sekali → Koordinator menugaskan kurir (`PATCH /shipments/{s}/courier`) dan mengirim yang sudah diterima, **tanpa menunggu keputusan Admin**. Bila satu shipment memuat baris yang sudah diterima dan baris yang **sama sekali belum diterima** (mis. 3 produk, 2 diterima), penugasan memindahkan baris yang belum diterima ke shipment pending baru (`assignFreshShipment`, audit `shipment.split_for_unaccepted`) lalu menugaskan sisanya; tidak ada yang dipindah bila belum ada yang diterima, dan baris yang diterima sebagian tetap memakai `split-batch`. Pada form Gudang satu order utuh, produk yang dikosongkan dianggap tidak diserahkan dan **wajib beralasan**, sehingga kekurangan selalu tercatat dan Admin diberi notifikasi.
- **PEMENUHAN KURANG:** bila Gudang menyerahkan kurang dari sisa, alasan dicatat (audit `stock_request.fulfillment_shortage`) dan satu notifikasi in-app `fulfillment.shortage` (Admin + Agen cabang, tertaut ke order) muncul. Detail order Admin menampilkan per produk: dipesan / diserahkan / kurang / alasan / status penanganan / bukti. Admin memutuskan **hanya untuk sisa**: *Reschedule* (`PATCH /orders/{o}/items/{i}/reschedule`, tetap dibuka walau order sudah `dikirim` selama baris tersebut punya kekurangan terlapor) atau *Cancel Sisa* (`POST /orders/{o}/items/{i}/cancel-undelivered`). Barang yang sudah diserahkan/dikirim tidak terpengaruh; konsekuensi finansial pembatalan mengikuti aturan existing (tidak ada kebijakan baru). Admin tetap dapat menambah item (SC-03).
- **Legacy (kandidat pensiun, perlu keputusan Owner):** `POST /warehouse/fulfillment-proposals/{p}/approve|reject` (Admin, pengecualian beralasan) masih ada di backend tetapi tidak lagi punya halaman/menu dan bukan bagian alur rutin.
- **Pengecualian:** item Sub tidak lewat Gudang (self-delivery); SC-03 menambah item ke Stock Request order.

## 13. Pengiriman & multi-shipment — IMPLEMENTED

- **Aktor:** dispatcher (`koordinator-kurir`/`agen`/`admin`/`super_admin`) assign kurir; executor (`kurir`/SKS-self/executor-SKS/KK-self) memprogres.
- **Langkah:** assignment `PATCH /shipments/{shipment}/courier` (eligibilitas SKS CR-001 dicek server-side di bawah lock) → kurir yang di-assign memprogres per item `PATCH /shipments/{shipment}/items/{item}/status` (tanpa self-claim; non-assignee 403) → `dikirim` (pickup) → `terkirim` per item **wajib bukti foto** (`proof_media_id`) → shipment `delivered` saat SEMUA item tiba (agregat) → komisi courier difinalisasi per item (`CommissionFinalizer`).
- **Self-delivery:** item Sub → `self_sub` shipment milik SKS, tanpa kurir; progres hanya oleh SKS yang juga **pembuat order** (`placed_by_user_id`).
- **Riwayat terkirim (koordinator):** Dispatch → tab *Riwayat Terkirim* (`GET /dispatch/history`): shipment `delivered` cabang, executor (kurir / SKS / tidak tersedia), waktu selesai, bukti kirim, tautan *Buka Order*; filter tanggal/kurir/wilayah/no. order; paginasi server-side.
- **Resi:** `GET /shipments/{shipment}/receipt` (per shipment; cetak `/print/shipments/:id`).
- **Notifikasi:** `order_shipped`, `order_delivered`.

## 14. Pelacakan pesanan — IMPLEMENTED

- Konsumen: `GET /orders` (OrderHistoryView) + `GET /orders/:id` (OrderDetailView) — status order/item, shipment (resi, kurir), sisa tagihan. Kurir: `GET /kurir/orders`. Gudang: antrian. Semua scope role di backend.

## 15. Pembatalan — IMPLEMENTED

- **Aktor:** `super_admin`; `agen`/`admin` cabang; owner (`korsal`/`sales`/SKS/konsumen) milik sendiri.
- **Jendela (§14):** COD: `diterima`/`diproses`; non-COD: hanya `diterima`.
- **Langkah:** `POST /orders/{order}/cancel` (transaksional) → mark `cancelled` + `cancelled_at/by/reason` → Stock Request `cancelled` (riwayat terjaga) → **reversal inventory** (`InventoryCancellationService`): pra-shipment → lepas reservasi (stok fisik tidak diarang); pasca-fulfilment → Shipping→Transit sekali (`cancellation_release`) → `cancellation_financial_status` = `pending_reconciliation` → finalisasi finansial oleh `RefundLedgerController::finalizeCancellation` (`min(P,C)` obligation).
- **Notifikasi:** `order_cancelled`.

## 16. Retur & refund — IMPLEMENTED

- **Pengajuan:** konsumen (`POST /orders/{order}/returns`, item `terkirim`, kapasitas = `fulfilled − returned`) atau assisted (`/orders/{order}/assisted-returns` dengan consent OTP CRM).
- **Alur:** review (`review` — approve/reject; jadwal `pickup-schedule`) → office/dispatch meng-assign kurir (`PATCH /return-items/{item}/courier`) → kurir yang di-assign `pickup`/`confirm` → inspeksi Gudang (`inspect`: good/damaged/mixed) → finalisasi Admin (`finalize`: restock good → Transit/Sub asal; damaged tidak sellable) → refund.
- **Refund ledger (§26):** obligation (pending → approved) → attempt (initiate → complete/fail/unknown/reconcile) → payout (membalik `paid_amount` via `PaymentService`) → `refund_ledger_events` append-only. Bukti penerima (`refund-recipient` evidence). Approval: `super_admin`/`agen`/`keuangan` (per matriks §3 [04-ROLES-PERMISSIONS](04-ROLES-PERMISSIONS.md)).
- **Notifikasi:** `return_requested/approved/rejected`, `refund_requested/approved/rejected/completed`.
- **Pengecualian:** komisi TIDAK disesuaikan otomatis (OD-05 pending); order status `pengembalian→kembali` untuk retur penuh.

## 17. Notifikasi WA, Email & In-App — IMPLEMENTED (status runtime: lihat [10-NOTIFICATIONS](10-NOTIFICATIONS.md))

- **Pola umum:** fakta bisnis ter-persist → observer/service memanggil `OrderNotifier` → defer `afterCommit` → 3 kanal independen (WA: gate global+per-event per-Agen+proof nomor; email: SMTP switch+email terverifikasi; in-app: operator cabang) → ledger idempotent → queue worker kirim.
- **16 event katalog:** order_created/paid/partial/processed/shipped/delivered/cancelled, payment_proof_uploaded, payment_failed, return_requested/approved/rejected, refund_requested/approved/rejected/completed.
- **Failure isolation:** kegagalan kanal tidak menggagalkan bisnis; kegagalan delivery → notifikasi `delivery.failed` ke operator; tidak pernah auto-retry hasil `unknown`.

## 18. Template & pengaturan Agen — IMPLEMENTED

- **Agen:** store profile (`/agent/store-profile`), tarif Kurir Toko (`/agent/shipping-tariff`), provider pengiriman + kredensial + kurir ter-support, metode pembayaran + kredensial gateway per environment, Google Auth cabang, WhatsApp (device/event/template/test-number), config invoice, promo scope cabang.
- **Admin Agen:** metode pembayaran + WA cabang. **Super Admin:** global (SMTP, gateway WA, toggle provider, bahasa, CMS, website settings, region import, Kontak Agen, shipping couriers master, Google Auth global).

## 19. Audit, keamanan & isolasi data — IMPLEMENTED

- `activity_logs` (causer/subject/event/properties/IP/UA) di semua aksi penting; viewer super_admin.
- Isolasi: global scope per-Agen; policy ownership; proof file privat; throttling per endpoint sensitif; error sanitasi (`GatewayResult::sanitize`); OTP/rahasia tidak pernah di-log.
- Konkurensi: lock kanonis + idempotency di setiap tulis kritis (§7 [02-SYSTEM-ARCHITECTURE](02-SYSTEM-ARCHITECTURE.md)).

## 20. Alur tambahan yang ditemukan

- **Recovery akun:** forgot/reset password enumeration-resistant (throttle bucket terpisah).
- **Google linking eksplisit** dari My Account (mode link; §23).
- **Konversi Sales→SKS** (agen pemilik; kode referral dipertahankan).
- **Reassign referral** (audited; scope `payOnBehalf` mengikuti rantai terkini).
- **Opname & transfer gudang** (§18–19 [05-BUSINESS-RULES](05-BUSINESS-RULES.md)).
- **Ekspor XLSX & sinkronisasi Google Sheets** satu arah (17 dataset; batas 10.000 baris; scope role/Agen).
- **Installer first-run** (`/setup`; terkunci permanen setelah `installed.lock`).
- **Cetak handover transfer & resi shipment** (read-only + audit cetak).
- **KMSIT routing fallback** (§30; EndpointGuard; cache 5 menit per Agen).
