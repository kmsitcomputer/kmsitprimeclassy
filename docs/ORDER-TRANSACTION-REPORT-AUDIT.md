# Audit dan Implementasi Laporan Order/Transaksi

Tanggal audit: 14 September 2026

## Hasil

Laporan order/transaksi kini memakai satu sumber data kanonis di `OrderTransactionReportService`. Dashboard laporan, ekspor XLSX, dan Google Sheets (`transactions` serta alias `transaction_items`) membaca query item-level yang sama. Setiap `order_items` menghasilkan tepat satu baris dan relasi shipment/kurir diambil melalui `order_items.shipment_id`, sehingga item berbeda dalam order yang sama dapat menampilkan kurir dan tanggal kirim berbeda tanpa double count.

Urutan output baku:

1. Order No
2. Tanggal
3. SKU
4. Produk
5. Harga
6. Qty
7. Status Item
8. Subtotal
9. Konsumen
10. Tgl Kirim
11. Kurir
12. Status Order
13. Sales
14. Korsal

## Sumber dan aturan data

| Output | Sumber |
|---|---|
| Order No, Tanggal, Status Order | `orders` |
| SKU, Produk/varian, Harga, Qty, Status Item, Subtotal, Tgl Kirim | snapshot dan status pada `order_items` |
| Konsumen | `orders.recipient_name_snapshot`, fallback nama akun untuk data lama |
| Kurir | shipment milik item → `couriers` |
| Sales | sales referrer order; self-purchase Agen/Korsal/Sales memakai pembeli itu sendiri |
| Korsal | snapshot relasi `orders.korsal_id`, termasuk Korsal self-purchase |

Tanggal diformat `DD/MM/YYYY`. Nilai kosong dinormalisasi menjadi `-`. Harga, Qty, dan Subtotal dipertahankan sebagai nilai numerik pada ekspor. Filter tanggal order, tanggal kirim, Sales, Korsal, Kurir, Status Item, Status Order, dan Agen diterapkan sebelum pagination/ekspor.

## Permission dan scope

- Super Admin: seluruh data; `agent_id` dapat mempersempit hasil.
- Agen/Admin: hanya order dengan `orders.agent_id` cabangnya.
- Korsal: hanya order dengan `orders.korsal_id` miliknya pada endpoint laporan yang memang diizinkan.
- Role lain: tetap ditolak oleh middleware route yang sudah ada.

## Google Sheets

Definisi whitelist untuk `transactions` dan `transaction_items` sekarang identik dengan 14 kolom baku. Backend mengirim default mapping beserta labelnya; UI mengisi mapping saat dataset dipilih dan menyediakan **Reset ke Default**. Query dibungkus sebagai derived table agar pemilihan serta urutan kolom kustom tidak menimpa ekspresi snapshot. Harga, Qty, dan Subtotal dikonversi eksplisit menjadi angka sebelum dikirim ke Sheets.

Konfigurasi lama yang memakai field transaksi versi sebelumnya perlu dibuka lalu di-reset dan disimpan ulang. Data bisnis tidak dimigrasikan atau diubah.

## Batas snapshot historis

Skema saat ini menyimpan ID Sales, Korsal, dan Kurir, tetapi belum menyimpan snapshot nama mereka pada order/item. Karena itu laporan historis memakai relasi ID transaksi yang dibekukan, lalu menampilkan nama akun/kurir saat ini. Snapshot SKU, produk/varian, harga, subtotal, penerima, dan tanggal kirim sudah tersedia dan digunakan. Audit ini tidak menambah migrasi besar hanya untuk menduplikasi nama.

## Verifikasi

- Feature test laporan dan Google Sheets mencakup permission, scope cabang/Korsal, snapshot SKU, shipment per item, format tanggal, referral langsung/self-purchase, mapping baku, whitelist, dan XLSX.
- Type-check Vue memastikan kedua tampilan laporan memakai kontrak kolom kanonis.
- Pemeriksaan sintaks PHP dan `git diff --check` dijalankan setelah perubahan.
