# PrimeClassy V2 — P1: Penugasan Kurir Parsial, Notifikasi Kekurangan, dan Invoice COD (UAT-03)

**Tanggal:** 2026-11-11 · Local DEV · HEAD `d7997c4` (dirty tree dipertahankan; tanpa commit/push/deploy production)
**Verdict: PARTIAL** untuk ketiganya: akar masalah terbukti, perbaikan ada di kode dan aset DEV yang dilayani, scoped test lulus. **UAT browser pada aplikasi aktif belum dilakukan** (tidak ada alat browser dan kredensial akun uji di sesi ini; tidak ada token/reset kata sandi yang dibuat).

## 1. Transaksi simulasi Owner (read-only, `primeclassy_db`)

Satu-satunya stock request di DEV: `stock_requests.id=10`, order `D-112600007` (id 21, COD, `diproses`), 3 item (35, 36, 37), diminta Koordinator 05:11 UTC.

| Fakta | Nilai |
|---|---|
| Proposal 9 (Gudang, 05:21:58) | 2 item (24, 25) × 1, bukti `fulfillment_handover_proof` tersimpan (media 18) |
| Penerimaan Koordinator 05:22:51 | `accepted_qty` 1 dan 1; `fulfilled_qty` item 24, 25 = 1 |
| Item ketiga (26 → order item 37) | `fulfilled_qty=0`, `remaining_qty=1`, **tidak ada** log `stock_request.fulfillment_shortage` |
| Shipment | **satu** shipment (22) memuat ketiga order item; `courier_id` NULL |
| `admin_notifications` | hanya `order.created` (2 baris); tidak ada `fulfillment.shortage` |

## 2. Root cause

**Penugasan (backend, state/gate):** `CourierService::assertAcceptedReceiptCovers` mensyaratkan **setiap** baris shipment sudah diterima. Checkout mengelompokkan 3 produk satu tanggal ke satu shipment, sehingga produk C (belum diterima) menahan produk A dan B. `split-batch` tidak bisa menolong: hanya memecah sebagian jumlah (`quantity < fulfilled_quantity`), tidak bisa memindahkan satu baris utuh berjumlah 1.

**Notifikasi (frontend + aturan pelaporan, bukan kegagalan pengiriman):** notifikasi hanya dipicu oleh laporan kekurangan Gudang. Form Gudang hanya mengirim baris yang diisi, jadi produk yang dibiarkan kosong tidak pernah dilaporkan: tidak ada catatan, tidak ada notifikasi, tidak ada panel. Pemicu, penerima (Admin + Agen cabang), dedupe, dan tampilan sudah teruji; yang hilang adalah laporan itu sendiri. Frontend notifikasi Admin generik (judul, tingkat, tautan "Lihat pesanan"), tidak perlu diubah.

**Invoice COD (backend + frontend):** `InvoiceController` dan flag `viewer_can_download_invoice` hanya mengenal `super_admin/agen/admin/keuangan` dan konsumen pemilik; Sales dan Korsal ditolak, dan tombol frontend di-hardcode ke peran itu. Pemesan yang membeli dengan peran lain (mis. Sales membeli untuk dirinya) juga tidak terhitung pemilik.

## 3. Perbaikan

- `CourierService::carveWhollyUnacceptedLines` (dipanggil di `assignCourier` sebelum gate): pada shipment Kurir Toko pending tanpa kurir yang punya baris diterima **dan** baris yang sama sekali belum diterima, baris belum diterima dipindah ke shipment pending baru lewat `OrderFulfillmentService::assignFreshShipment` (audit `shipment.split_for_unaccepted`), lalu gate berjalan atas sisanya. Tidak memindahkan apa pun bila belum ada yang diterima (tetap ditolak); baris diterima sebagian tetap memakai `split-batch`; RajaOngkir/pickup/SKS tidak tersentuh; gate penerimaan dan dispatch tidak dilonggarkan.
- `OrderDiprosesView.vue`: pada order utuh, setiap baris outstanding harus dijawab; kosong dianggap 0 dan wajib beralasan, lalu dikirim lewat `shortages[]`. Mode per-produk tetap hanya untuk produk itu.
- `OrderPolicy::viewInvoice` (satu aturan: Pemesan peran apa pun, super_admin/agen/admin/keuangan cabang, Sales/Korsal dalam lingkup `view`), dipakai `InvoiceController`, `OrderResource::viewer_can_download_invoice`, dan frontend (`OrderDetailView.vue` mengikuti flag server untuk non-office). Koordinator Kurir, Kurir, Gudang tetap ditolak.
- Dokumen: `docs/05`, `06`, `08`.

## 4. Bukti (scoped)

| Pemeriksaan | Hasil |
|---|---|
| `FulfillmentShortageWorkflowTest::test_owner_simulation_two_of_three_products_are_assigned_and_the_third_is_reported_short_to_the_admin` | OK: 3 produk × 1 pada satu shipment → Gudang menyerahkan 2 + melaporkan 1 → Koordinator menerima 2 → notifikasi Admin+Agen tertaut order **sebelum** penugasan → penugasan 2 berhasil, produk ketiga di shipment pending lain tanpa kurir (assign ditolak 422) → kirim 2 → stok shipping 2, tanpa stok negatif, tanpa gerakan ganda, nol approval Admin → detail Admin: hanya produk ketiga (dipesan 1, diserahkan 0, kurang 1, alasan Gudang, `pending_decision`, bukti 1) → Reschedule hanya produk ketiga (produk terkirim 422), Cancel Sisa hanya produk ketiga |
| `..._nothing_accepted_still_refuses_assignment...` | OK |
| PHPUnit: workflow, penugasan, batch/refusal, ekspedisi, pembatalan parsial, Gudang queue, kurir, dispatch, SKS | 149 test, 1.504 asersi OK |
| PHPUnit `InvoiceTest` + `R04OrderProjectionTest` + `MasterV2PaymentCoreTest` + `CoordinatorDeliveredHistoryTest` (3 test invoice baru: Pemesan/Sales/Korsal/Admin/Agen/Keuangan OK, Sales/Korsal luar lingkup + Koordinator + Gudang + pembeli lain 403, cabang lain 404, satu snapshot, COD tetap "Belum Dibayar", Sales sebagai Pemesan) | 53 test, 489 asersi OK |
| PHPUnit konkurensi `DispatchAssignmentConcurrencyTest` + `PartialCancellationConcurrencyTest` (DB kanonik) | 11 test, 189 asersi OK; tanpa direktori sisa |
| Vitest seluruh frontend (+`OrderDiprosesView` form order utuh, +`OrderDetailView` invoice per peran) | 36 file, 314 test OK |
| `vue-tsc`, eslint, oxlint, `vite build` | bersih / sukses |
| `manifest-verify`, `static-validate`, `git diff --check`, migrasi pending | OK / OK / bersih / tidak ada |

## 5. Runtime aktif

Backup `public_html`: `/Users/kmsitcomputer/KMSIT-Server/backups/primeclassy-public_html-pre-p1-assign-invoice-20261111-124238`. Publish `rsync` tanpa `--delete`, tanpa menyentuh `laravel.php`, `.htaccess`, `config.js`, `storage`. URL resmi melayani `index-BlatWgWn.js`; `/sw.js` versi `cfa3b60ba266a673`; chunk Gudang memuat teks "dianggap tidak ada yang diserahkan"; chunk detail order memuat `viewer_can_download_invoice`; `/up` 200. Backend runtime memvalidasi timestamp, jadi kode terbaru dilayani.

## 6. Keterbatasan dan langkah manual Owner

- **Order 21 belum berubah** (tidak dimanipulasi). Analisis baca-saja: item 35, 36 diterima dan 37 tidak, sehingga penugasan shipment 22 kini akan memindahkan item 37 ke shipment baru dan menugaskan 35, 36. Notifikasi untuk produk ketiga baru muncul setelah Gudang melaporkannya.
- UAT manual (hard-refresh dahulu): **(1)** Koordinator → Dispatch → tugaskan shipment order 21 → kurir terpasang pada 2 produk; produk ketiga tampil di shipment terpisah. **(2)** Gudang → `/dashboard/warehouse/orders` → "Setujui & Serahkan" order 21 → produk ketiga kosong → isi alasan → kirim (tanpa foto, karena tidak ada barang) → Admin menerima "PEMENUHAN KURANG" di lonceng; detail order menampilkan baris produk ketiga dengan Reschedule/Cancel Sisa. **(3)** Invoice: buka order COD sebagai Pemesan, Sales, Korsal dalam cakupan → tombol unduh invoice muncul dan PDF terbuka; akun di luar cakupan tidak.
- Endpoint Admin `approve|reject` tidak disentuh (sesuai perintah).
- Dua sesi agen bekerja bersamaan pada sebagian repo; tinjau `git diff` secara menyeluruh.
- Full Test tidak dijalankan.

## 7. Konfirmasi

Tanpa akses production/VPS, deploy production, commit, push, merge. `primeclassy_db` hanya dibaca (SELECT, `migrate:status`); tes otomatis memakai `primeclassy_testing*`. Tidak ada perubahan Docker/nginx/Caddy/domain/port/volume.
