# PrimeClassy V2 — Remediasi Workflow Koordinator Kurir ↔ Gudang dan PEMENUHAN KURANG

**Tanggal:** 2026-11-11 · **Lingkungan:** Local DEV · **Branch/HEAD:** `main` / `d7997c4` (working tree dirty dipertahankan; tidak ada commit, push, deploy)
**Baseline historis:** Parallel Full Test run `56f76cae391a` PASS (2.180/2.180). **Full Test tidak dijalankan ulang** pada pekerjaan ini (sesuai perintah); angka itu bukan hasil setelah perubahan.

## 1. Inventaris workflow existing dan sisa alur Gudang → Admin

Sebelum perubahan (bukti: source):

| Area | Keadaan sebelum | Penilaian |
|---|---|---|
| Koordinator meminta ke Gudang | `POST /dispatch/orders/{order}/request-fulfillment` (`StockRequestService::coordinatorRequestFulfillment`), tombol "Minta ke Gudang" di Dispatch | Sudah benar, tidak disentuh |
| Gudang memproses | Gudang "mengusulkan" (`StockRequestProposalService::propose`); UI berkata "Ajukan ke Admin", "dikirim ke Admin, stok belum berubah sampai disetujui" | **Salah**: bahasa dan alur masih Gudang → Admin; tanpa bukti serah terima; tanpa alasan kekurangan |
| Halaman Admin "Fulfillment Proposal" (approve/reject) | Menu, route, view, tile KPI "Approval Gudang" | **Salah**: persetujuan rutin Admin |
| Penyelesaian stok | Konfirmasi penerimaan Koordinator (`receive`: diterima/kurang/rusak), satu pergerakan stok | Benar, dipertahankan (serah terima dua pihak, keputusan Owner) |
| Penugasan/kirim | Koordinator saja, wajib barang diterima, batch lanjutan (`split-batch`) | Benar |
| Kekurangan per item | `stock_request_items.remaining_qty` ada, tetapi tanpa alasan, tanpa penanda "dilaporkan Gudang", tanpa tampilan Admin | **Belum tersedia** |
| Notifikasi Admin | `admin_notifications` ada; tidak ada tipe kekurangan | **Belum tersedia** |
| Reschedule setelah kiriman parsial | `rescheduleItemDeliveryDate` menolak bila order sudah `dikirim` | **Menghalangi** keputusan Admin atas sisa |
| Cancel sisa | `POST .../cancel-undelivered` sudah ada, juga setelah dispatch pertama | Benar, dipakai ulang |
| Test lama | 37 pemanggilan `POST .../proposals` tanpa bukti/alasan; test Admin approve | Disesuaikan (bagian 4) |

## 2. Pemetaan aturan Owner ke implementasi

| Aturan Owner | Implementasi |
|---|---|
| A1 Koordinator mengajukan ke Gudang | Existing (`request-fulfillment`) |
| A2–A3 Gudang menerima, menyetujui jumlah, menyerahkan dengan bukti | `POST /warehouse/stock-requests/{id}/proposals` multipart: `items[]` (jumlah ≤ sisa), `items.*.shortage_reason`, `shortages[]` (tidak ada barang), `proof` (foto, **wajib bila ada barang diserahkan**); UI Gudang "Setujui & Serahkan ke Koordinator" |
| A4 Koordinator menugaskan | Existing, wajib barang diterima; tanpa Admin |
| B1 Gudang mencatat jumlah per item | Jumlah per item; alasan **wajib** bila < sisa (backend 422) |
| B3 Pengiriman parsial Kurir Toko | Existing (`split-batch`, assign, dispatch); diuji tanpa satu pun aksi Admin |
| B4 Notifikasi PEMENUHAN KURANG | `AdminNotificationService::fulfillmentShortage` (tipe `fulfillment.shortage`, tertaut `order_id`, penerima Admin + Agen cabang saja, dedupe per proposal/penerima) **dan** panel pada detail order Admin |
| B5 Reschedule / Cancel Sisa | Memakai endpoint existing; panel memanggil form item existing |
| B6 Keputusan Admin tidak menghalangi kirim | Tidak ada dependensi Admin di jalur penugasan/dispatch (diuji) |
| C RajaOngkir | Tidak diubah (`ExpeditionDispatchGateTest` lulus) |
| C Finansial | Tidak diubah; cancel sisa memakai `reduceFulfillment` existing |
| Admin tetap bisa menambah item (SC-03) | Tidak diubah; diuji pada order dengan kekurangan terbuka |

## 3. Root cause

1. Alur Gudang → Admin berasal dari desain lama (proposal → Admin approve) yang kemudian "dilapisi" penerimaan Koordinator tanpa membersihkan UI/teks/menu Admin, sehingga petugas dan Admin masih melihat alur lama.
2. Kekurangan tidak punya model data: sisa permintaan tidak dibedakan dari "menunggu batch berikutnya", dan alasan tidak disimpan.
3. Reschedule dikunci pada `order.status` (`dikirim` menutup jendela), padahal sisa yang belum dikirim adalah baris terpisah yang masih `diproses`.

## 4. File dan komponen

**Backend (tanpa migrasi baru)**
- `app/Services/Stock/StockRequestProposalService.php` — `propose()` menerima `shortages` dan `proof`; `reportShortageOnly()` (Gudang tidak punya barang sama sekali: catat + notifikasi tanpa proposal dan tanpa pergerakan stok); `recordShortages()`.
- `app/Http/Controllers/Api/V1/Stock/StockRequestProposalController.php` — validasi multipart, bukti wajib bila ada barang.
- `app/Services/Order/FulfillmentShortageService.php` (baru) — read model per produk, dikelompokkan per baris akar (batch hasil `split` tetap satu produk); `lineHasOpenShortage()`.
- `app/Http/Resources/OrderResource.php` — blok `fulfillment_shortage` hanya untuk `super_admin/agen/admin`, hanya pada detail.
- `app/Services/Order/OrderFulfillmentService.php` — jendela reschedule: order `dikirim` tetap boleh **hanya** untuk baris yang masih `diproses` dan punya kekurangan terlapor terbuka.
- `app/Models/StockRequestProposal.php`, `StockRequestProposalResource.php` — relasi bukti, daftar kekurangan.
- `app/Services/Notifications/AdminNotificationService.php`, `config/media.php` (koleksi `fulfillment_handover_proof`), `lang/{id,en}/messages.php`.
- Penyimpanan tanpa kolom baru: bukti → `media` (polimorfik); alasan → `activity_logs` (`stock_request.fulfillment_shortage`); keputusan Admin dibaca dari log event existing (`order_item.delivery_rescheduled`, `order_item.undelivered_cancelled`).

**Frontend** — `OrderDiprosesView.vue` (form Gudang: jumlah, alasan, bukti), `FulfillmentShortagePanel.vue` (baru) + integrasi `OrderDetailView.vue`, `KoordinatorLogisticsPanel.vue`, `api/warehouseFulfillment.ts`, `api/types.ts`, `navConfig.ts` dan `router/index.ts` (menu/route Admin "Fulfillment Proposal" dihapus), `FulfillmentProposalsView.vue` dihapus, `DashboardHomeView.vue` (tile "Approval Gudang" Admin dihapus).

**Dokumen** — `docs/03, 04, 05, 06, 09, 14` diselaraskan; baris lama "Admin approve" di `05-BUSINESS-RULES.md` §11 ditandai digantikan.

**Fixture konkurensi** — aturan baru "alasan wajib bila kurang" menggagalkan 2 test konkurensi yang memanggil `propose()` dengan jumlah parsial tanpa alasan. Diperbaiki dengan menambahkan `shortage_reason` pada pemanggil (`DispatchAssignmentConcurrencyTest`, `FulfillmentCancellationConcurrencyTest`, `FulfillmentConcurrencyTest`, `OrderLineAdditionConcurrencyTest`) dan pada operasi `propose` di `backend/.phpunit-concurrency-actor.php`; urutan lock dan timing race tidak diubah.

**Test infrastruktur** — manifest diregenerasi (`99ae8bfb…`; +3 berkas test, tanpa perubahan lane).

## 5. Regression test

- Baru: `FulfillmentShortageWorkflowTest` (9 test, 98+ asersi): bukti dan alasan wajib; hanya Gudang cabang; satu notifikasi tertaut ke Admin + Agen saja; barang diserahkan dikirim tanpa keputusan Admin; angka per produk akurat dan blok tidak terlihat oleh Gudang/Koordinator/Konsumen; Admin reschedule dan cancel hanya sisa setelah kiriman parsial, unit terkirim tidak tersentuh, peran lain 403, permintaan ulang tidak menggandakan; pengecualian reschedule terbatas pada baris yang terlapor; laporan "tidak ada barang" tanpa proposal/stok; SC-03 tambah item tetap berfungsi.
- Disesuaikan: 37 pemanggilan endpoint proposal pada 12 berkas test lewat helper `withHandoverProof()` (`tests/TestCase.php`); tidak ada assertion bisnis dilemahkan.
- Vitest baru: `FulfillmentShortagePanel.spec.ts` (6), `OrderDiprosesView.spec.ts` (6); `OrderDetailView.spec.ts` ditambah kasus panel dan tombol.

## 6. Hasil verifikasi (semua scoped; Full Test tidak dijalankan)

| Pemeriksaan | Hasil |
|---|---|
| PHP lint file berubah | tanpa error |
| PHPUnit regresi 20 kelompok test (proposal, stok request, penerimaan, penugasan, batch/refusal, pembatalan parsial, ekspedisi, reschedule, add-line, notifikasi, dispatch) | 197 test, 1.598 asersi, OK |
| PHPUnit `FulfillmentShortageWorkflowTest` + proposal/stok/penerimaan | 34 test, 374 asersi, OK |
| PHPUnit konkurensi, DB kanonik (`DispatchAssignmentConcurrencyTest`, `PartialCancellationConcurrencyTest`) | 11 test, 189 asersi, OK (pada percobaan pertama 2 gagal karena fixture, lihat di bawah) |
| PHPUnit konkurensi lain yang memanggil `propose()` (`FulfillmentCancellationConcurrencyTest`, `FulfillmentConcurrencyTest`, `OrderLineAdditionConcurrencyTest`) | 6 test, 307 asersi, OK; tidak ada direktori `concurrency-*` tersisa |
| `vue-tsc` (app dan node config) | 0 error |
| Vitest seluruh suite frontend | 36 file, 300 test lulus |
| `vite build` | sukses |
| oxlint dan eslint pada 9 berkas berubah | 0 temuan |
| `manifest-verify`, `static-validate`, `git diff --check` | OK |

Frontend dijalankan di kontainer Node efemeral pada **salinan** `frontend/` dengan dependensi Linux read-only; `frontend/dist` dan `public_html` tidak disentuh.

## 7. Hal yang belum dapat dipastikan

- **UAT browser pada aplikasi aktif tidak dilakukan.** Belum dibuktikan bahwa build yang dilayani `primeclassy-web` berasal dari source ini (spesifikasi REM-005 mensyaratkan hal itu). Tidak ada deploy/publish ke `public_html`.
- **Satuan produk:** katalog tidak punya kolom satuan, jadi kolom "Satuan" ditampilkan sebagai "unit" (nilai `unit` selalu `null` dari server); tidak diciptakan.
- **Approve/reject Admin pada backend masih ada** (`POST /warehouse/fulfillment-proposals/{p}/approve|reject`, pengecualian beralasan, `role:admin`). Tidak ada menu/halaman/tile, bukan bagian alur rutin, dan dipertahankan karena banyak test lama memakainya sebagai fixture dan spesifikasi REM menyebutnya "pengecualian". **Keputusan Owner:** pensiunkan endpoint ini atau tidak.
- **Bukti serah terima yatim pada `products:reset`:** media `fulfillment_handover_proof` milik proposal dari order yang di-reset tidak ikut dihapus oleh perintah itu (utang teknis di luar lingkup, tidak diubah).
- **Pembatalan sisa dan keuangan:** mengikuti `reduceFulfillment` existing (refund hanya bila pembayaran melebihi total baru). Tidak ada kebijakan baru; kasus total naik setelah 3 pembayaran tetap keputusan Owner yang terbuka (T22).
- **Alasan kekurangan tersimpan di audit trail**, bukan kolom; bila diinginkan kolom tersendiri, perlu persetujuan migrasi.
- **Dua sesi agen mengedit repo bersamaan** pada sebagian pekerjaan ini (frontend dan sebagian backend/dokumen). Hasil gabungan sudah ditinjau dan diverifikasi sebagai satu kesatuan (seluruh verifikasi di bagian 6 dijalankan setelah sesi lain berhenti), tetapi Owner sebaiknya meninjau `git diff` secara menyeluruh.
- Full Test belum dijalankan ulang: test baru, perubahan `propose()` dan jendela reschedule belum tercakup baseline.

## 8. Konfirmasi

Tidak ada akses production/VPS, deploy, commit, push, atau merge. `primeclassy_db` tidak disentuh; pengujian memakai `primeclassy_testing` dan `primeclassy_testing_w1..w5`. Tidak ada test dihapus atau assertion dilemahkan. Aturan RajaOngkir, pembayaran, refund, stok, dan courier di luar lingkup ini tidak diubah. Perubahan working tree sebelumnya dipertahankan.
