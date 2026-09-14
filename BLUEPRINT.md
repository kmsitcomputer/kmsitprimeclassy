# Blueprint — Prime Classy Cake & Cookies

Dokumen ini merangkum kondisi aplikasi **saat ini** (bukan rencana/wacana) — arsitektur, alur kerja, fitur, proses bisnis, level user & permission-nya, serta hal-hal penting lain yang perlu dipahami siapa pun yang akan mengembangkan, mengelola, atau men-deploy platform ini.

---

## 1. Ringkasan

Prime Classy Cake & Cookies adalah platform **pemesanan kue & kukis multi-cabang (multi-agen)**. Setiap **agen** (cabang) punya toko, stok, staf, dan operasional pengiriman sendiri-sendiri, sementara satu **super_admin** mengawasi seluruh platform. Konsumen berbelanja dari katalog bersama, terhubung ke satu cabang lewat rantai referral (agen → korsal → sales → konsumen), dan bisa checkout dengan kurir cabang, COD, transfer bank manual, atau payment gateway (Xendit/Tripay/Stripe).

---

## 2. Teknologi yang Dipakai

### Backend
| Komponen | Versi/Detail |
|---|---|
| Bahasa & Framework | PHP 8.2+, Laravel 11 |
| Database | MySQL 8.0+ (dikembangkan di MySQL 8.4) |
| Autentikasi | Laravel Sanctum — mode **SPA cookie session** (bukan bearer token) |
| Sanitasi konten CMS | HTMLPurifier (`ezyang/htmlpurifier`) |
| Export Excel | PhpSpreadsheet (`phpoffice/phpspreadsheet`) |
| Integrasi Google | Google Sheets API melalui satu service account pusat; ekspor manual satu arah |
| Testing | PHPUnit; 45 file test dengan 459 method test terdeklarasi, dijalankan terhadap database MySQL test terisolasi |

### Frontend
| Komponen | Versi/Detail |
|---|---|
| Framework | Vue 3 (Composition API) + Vite |
| Routing & State | Vue Router, Pinia |
| Styling | Tailwind CSS v4 |
| Editor | CKEditor 5 (rich-text untuk konten CMS) |
| HTTP Client | Axios |
| i18n | vue-i18n — 4 bahasa (Indonesia, Inggris, Arab, Mandarin) |

### Arsitektur Deployment
```
Browser → public_html/ (Vue SPA + front controller Laravel)
        ├── route/file frontend → aset statis atau index.html
        └── /api, /sanctum, /storage → backend Laravel → MySQL
```

Source tetap terdiri dari dua aplikasi: Vue menghasilkan file statis dan Laravel menyediakan API JSON. Paket produksi resmi `deploy/build.sh` menyatukan keduanya dalam satu document root `public_html/`: `.htaccess` meneruskan `/api`, `/sanctum`, dan `/storage` ke Laravel, sedangkan route lain memakai SPA fallback. Deployment dua subdomain tetap mungkin, tetapi membutuhkan konfigurasi CORS, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`, HTTPS, dan cookie lintas-subdomain yang benar. Mode single-domain adalah jalur deployment utama karena mengurangi masalah cookie/CSRF pada shared hosting.

### Konfigurasi Runtime Frontend (tanpa rebuild)
URL backend API di frontend **tidak di-hardcode saat build**, melainkan dibaca dari `frontend/dist/config.js` (dimuat sebagai `<script>` sebelum bundle utama). Ini artinya satu hasil build bisa dipakai untuk domain apa pun — tinggal edit satu baris di `config.js` lewat File Manager hosting, tanpa perlu `npm run build` ulang. Cocok untuk shared hosting tanpa akses terminal.

---

## 3. Alur Kerja (Workflow) Sistem

### 3.1. Instalasi Awal (Installer Wizard)
Aplikasi punya wizard instalasi berbasis browser (`/install` di frontend, `POST/GET /api/v1/install/*` di backend) yang membuat deploy ke shared hosting **tanpa terminal** jadi mungkin:

1. **Selamat Datang** → **Persyaratan Server** (cek versi PHP, ekstensi, permission folder).
2. **Database** — input host/nama DB/user/password MySQL, test koneksi, simpan otomatis ke `.env`.
3. **Konfigurasi Aplikasi** — nama aplikasi, URL backend, URL frontend (menulis `FRONTEND_URLS`/`SANCTUM_STATEFUL_DOMAINS`/`SESSION_DOMAIN` ke `.env` otomatis).
4. **Akun Super Admin** — input data admin pertama.
5. **Instalasi Database** — menjalankan migrasi + seeder + membuat akun Super Admin, sekaligus `storage:link`, semua lewat satu klik dari browser (bukan CLI).
6. **Finalisasi** — verifikasi ringkasan instalasi.
7. **Kunci Instalasi** — mengunci wizard secara **permanen**; setelah ini seluruh endpoint `/install/*` selalu menolak (403), bahkan kalau file lock-nya hilang (double-guard: cek lock file DAN cek apakah Super Admin sudah ada).

`APP_KEY` dibuat otomatis oleh aplikasi sendiri pada request pertama (tidak perlu `php artisan key:generate`). Selama proses instalasi berlangsung (belum terkunci), CORS dan domain sesi Sanctum otomatis "memantulkan" origin yang benar-benar meminta — begitu instalasi dikunci, keduanya kembali ketat sesuai `FRONTEND_URLS`/`SANCTUM_STATEFUL_DOMAINS` yang dikonfigurasi.

### 3.2. Alur Registrasi & Hierarki Referral
```
super_admin
   └── agen (per cabang/toko)
         ├── admin (operasional kantor cabang)
         ├── keuangan (verifikasi dan penyelesaian finansial cabang)
         ├── korsal (koordinator sales)
         │     └── sales (termasuk Sales yang dibuat oleh Agen)
         │           └── konsumen (lewat kode referral sales)
         └── kurir (staf pengiriman cabang)

konsumen juga bisa daftar langsung pakai kode referral agen ATAU korsal
(tanpa sales perantara) — sales_id/korsal_id yang dilewati jadi null.
```
- Setiap **agen, korsal, sales** otomatis punya `referral_code` unik saat dibuat (format `{PREFIX}-{6 karakter acak}`), dan bisa mengelola sendiri kodenya (lihat/ubah manual/acak ulang/hapus) lewat halaman Profil.
- **konsumen, admin, keuangan, kurir** tidak pernah punya referral code.
- Matriks pembuatan akun bersifat final: `super_admin` → **agen saja**; `agen` → korsal/sales/admin/keuangan/kurir di cabangnya sendiri; `korsal` → sales saja. Konsumen mendaftar sendiri via kode referral. Pembatasan ini ditegakkan di request validation, policy, service, API, dan pilihan role pada frontend.
- Saat **Agen membuat Sales**, Agen wajib memilih Korsal dari cabangnya sendiri. Server mengabaikan ownership yang dapat direkayasa dari klien dan menetapkan `parent_id = korsal.id`, `korsal_id = korsal.id`, serta `agent_id = Agen yang login`.
- Saat **Korsal membuat Sales**, pilihan ownership tidak berasal dari frontend. Server menetapkan `parent_id = Korsal yang login`, `korsal_id = Korsal yang login`, dan `agent_id = agent_id milik Korsal`.
- Sales lama yang kehilangan Korsal atau memiliki rantai tidak konsisten tidak boleh dipasangkan berdasarkan tebakan. Jalankan audit, lalu minta Agen menentukan Korsal jika relasi yang benar tidak dapat dibuktikan dari data yang ada.
- Rantai referral (`sales_id`/`korsal_id`/`agent_id`) di-snapshot pada saat pendaftaran konsumen dan **tidak berubah otomatis** — memindahkan sales ke korsal lain, atau konsumen ke sales lain, adalah aksi eksplisit & tercatat di audit log (`ReferralReassignmentService`), tidak pernah terjadi diam-diam.
- Isolasi data per-cabang diterapkan berlapis: query level (`BelongsToAgentScope`), policy level, dan service level — satu agen tidak pernah bisa melihat data agen lain.

### 3.3. Alur Belanja & Checkout (Konsumen)
1. Jelajahi katalog (produk bisa punya varian, harga & stok per-agen).
2. Tambah ke keranjang / wishlist.
3. Checkout: pilih alamat (tersimpan atau input manual + opsi simpan alamat baru), pilih tanggal kirim, pilih metode pengiriman (**Kurir Online** — dihitung dari rute jalan OpenRouteService Directions API — atau **Ekspedisi** via RajaOngkir), pilih metode pembayaran.
   Koordinat tujuan dapat berasal dari alamat tersimpan, geolocation browser, atau Google Maps picker/autocomplete bila key publik frontend dikonfigurasi. Laravel tetap memvalidasi latitude/longitude dan menyimpannya sebagai snapshot; key privat provider pengiriman tidak pernah dikirim ke browser.
4. Metode pembayaran yang tersedia: **COD**, **Transfer Bank Manual** (upload bukti transfer, diverifikasi Keuangan), atau **Payment Gateway** (Xendit/Tripay/Stripe, webhook dengan verifikasi signature).
5. Order dibuat — **setiap item produk mendapat Shipment-nya sendiri secara independen** (bukan satu shipment untuk seluruh order), supaya satu order bisa ditangani oleh beberapa kurir berbeda sekaligus, dan reschedule satu produk tidak mengganggu produk lain dalam order yang sama.
6. Status order: **COD langsung masuk status `diproses`** (tidak perlu verifikasi pembayaran dulu, karena memang belum ada yang dibayar) — sedangkan transfer manual/gateway tetap mulai di `diterima` sampai pembayarannya diverifikasi.

### 3.4. Alur Fulfillment & Pengiriman (Kantor & Kurir)
```
diterima → diproses → dikirim → terkirim → (pengembalian → kembali, jika ada retur)
                  └──────────→ dibatalkan (sebelum dikirim)
```
- **Operasional** (agen/admin/super_admin) memproses order (`diterima → diproses`), menyesuaikan jumlah item yang dipenuhi, dan mengubah tanggal kirim per produk—termasuk memindah sebagian jumlah menjadi baris/shipment baru. **Keuangan** menangani verifikasi pembayaran/DP/COD, refund, dan additional payment; Keuangan tidak mengambil alih perubahan status, fulfillment, shipment, atau pembatalan operasional.
- **Kurir** melihat semua order berstatus `diproses` di cabangnya (belum diklaim siapa pun), mengambilnya (self-assign saat menekan "Ambil" → status `dikirim`), lalu menandai `terkirim` **wajib disertai foto bukti pengiriman**. Sekali sebuah shipment diambil kurir tertentu, kurir lain tidak bisa melihat/memprosesnya lagi (baik di dashboard kurir maupun halaman detail order) — walau order yang sama masih berisi produk lain yang belum diambil siapa pun.
- **Retur**: konsumen mengajukan retur (wajib foto bukti) untuk item yang sudah `terkirim` → kurir menjemput retur (`pengembalian`) dengan catatan kondisi barang → admin meninjau (approve/reject) → jika approve, stok dikembalikan & status refund dikelola terpisah.
- Aturan pembatalan: **COD** boleh dibatalkan selama masih `diterima` ATAU `diproses`; **non-COD** hanya boleh dibatalkan selama `diterima` (begitu `diproses`, uang sudah/akan diverifikasi terkumpul — pembatalan lewat jalur retur/refund, bukan cancel biasa).

### 3.5. Alur Fee & Komisi
- Setiap produk/varian punya 3 jenis fee yang bisa dikonfigurasi **per unit** (sama seperti harga): **fee agen**, **fee sales**, **fee kurir** — dikalikan quantity saat order dibuat, lalu nilainya di-snapshot ke `order_items` (perubahan fee di kemudian hari tidak mengubah order lama).
- Fee agen dan fee sales adalah dua komponen terpisah, tidak pernah digabung jadi satu angka — dicatat sebagai baris `commissions` terpisah (`beneficiary_role` = `agent` vs `sales`) walau penerimanya kebetulan orang yang sama (lihat poin referral di bawah).
- **Referral menentukan penerima fee sales** — bukan role pembeli: kalau konsumen/pembeli terhubung ke kode referral **Agen** langsung (tanpa sales/korsal di tengah), Agen tersebut berperan ganda sebagai AGENT + SALES REFERRER untuk transaksi itu dan menerima **fee agen + fee sales** (dua baris komisi terpisah). Kalau referral-nya **Korsal** langsung, Korsal menerima fee sales, sedangkan fee agen tetap ke agen induknya. Kalau referral-nya **Sales**, Sales menerima fee sales, fee agen tetap ke agen induk. Role akun pembeli tidak pernah berubah hanya karena checkout (Agen/Korsal/Sales yang checkout tetap berperan sebagai Agen/Korsal/Sales, bukan berubah jadi konsumen) — termasuk saat mereka membeli untuk diri sendiri (self-purchase), yang diperlakukan sebagai referral milik mereka sendiri.
- Komisi kurir baru dicatat saat item benar-benar `terkirim` (bukan saat order dibuat).
- Visibilitas fee dibatasi ketat per role (lihat §5) — kurir dan konsumen tidak pernah melihat angka fee/harga modal apa pun.

### 3.6. Alur SKU Katalog

- Produk sederhana (`has_variations = false`) **wajib** mempunyai SKU. Produk dengan varian tidak boleh mempunyai SKU pada parent; setiap variannya wajib mempunyai SKU.
- SKU produk sederhana dan seluruh SKU varian memakai satu namespace global melalui registry `catalog_skus`. Nilai yang sudah dipakai produk tidak dapat dipakai varian, dan sebaliknya.
- Saat order baru dibuat, `order_items.sku_snapshot` diisi dari `product.sku` untuk produk sederhana atau dari SKU varian yang dipilih. Snapshot transaksi lama tidak mengikuti perubahan SKU katalog.
- Data lama ditangani dengan audit dan backfill eksplisit yang deterministik serta idempotent. SKU valid yang sudah ada tidak diubah dan relasi bisnis tidak ditebak.

### 3.7. Alur Ekspor Google Sheets

```text
MySQL / aplikasi → dataset whitelist → filter scope cabang → Google Sheets
```

- MySQL tetap menjadi sumber kebenaran. Integrasi fase ini hanya mengekspor data; tidak ada alur Google Sheets → MySQL dan kegagalan Google API tidak me-rollback transaksi utama.
- Satu service account pusat dikonfigurasi server-side dengan `GOOGLE_SHEETS_ENABLED` dan `GOOGLE_SHEETS_CREDENTIALS_PATH`. File JSON disimpan di luar public web root, tidak masuk Git, log, frontend, atau respons API.
- Super Admin mendaftarkan spreadsheet dengan scope global atau satu Agen. Agen dan Admin hanya dapat mengelola konfigurasi, menjalankan sync, dan membaca log pada scope Agen mereka sendiri. Keuangan, Korsal, Sales, Kurir, dan Konsumen tidak memiliki akses.
- Dataset yang tersedia dibatasi pada: products, stock, transactions, transaction_items, transaction_report, financial_summary, sales, korsal, courier_deliveries, sales_fees, korsal_fees, courier_fees, payment_status, refunds, dan additional_payments. Setiap dataset hanya membuka kolom yang didefinisikan aplikasi; tabel atau nama kolom arbitrary dari browser ditolak.
- Sinkronisasi dijalankan manual melalui `/dashboard/google-sheets`, menulis maksimal 10.000 baris, membuat tab valid yang belum ada, dan mengganti seluruh nilai tab agar retry tidak menggandakan baris. Mapping mendukung pemilihan, header custom, dan urutan kolom. Setiap percobaan menghasilkan log sukses/gagal dengan ringkasan error dan Spreadsheet ID tersanitasi.
- Tombol **Periksa koneksi** melakukan pembacaan metadata nyata melalui `spreadsheets.get`, tanpa menulis sel. Status, judul spreadsheet, waktu test terakhir, dan kode error aman disimpan per destination. Dashboard menampilkan email service account aktual dan membedakan credential hilang/tidak valid, authentication, permission, API disabled, spreadsheet not found/invalid, rate limit, network, dan error Google lain.

### 3.8. Konfigurasi Pembayaran, Pengiriman, dan Wilayah

- Super Admin mengaktifkan/nonaktifkan jenis payment gateway dan shipping provider secara global. Super Admin juga mengelola master kode ekspedisi yang boleh dipilih Agen.
- Agen menentukan metode pembayaran yang aktif untuk tokonya, memilih environment gateway, dan menyimpan credential gateway terenkripsi per Agen. Toggle global tetap menjadi batas atas: provider yang dimatikan Super Admin tidak dapat dipakai cabang.
- Agen mengaktifkan provider pengiriman untuk cabangnya, menyimpan API key terenkripsi, mengatur origin/rule harga, menguji koneksi, dan memilih kurir RajaOngkir dari master yang aktif. API key yang sudah tersimpan tidak dikirim kembali ke frontend.
- RajaOngkir memakai Komerce V2: origin dipilih dari destination resmi, tujuan dicari dari hierarchy wilayah konsumen, berat dihitung server dalam gram, opsi courier/service diverifikasi ulang saat order dibuat, dan tarif final disimpan sebagai snapshot shipment.
- OpenRouteService memakai origin koordinat profil Agen dan koordinat tujuan order. Jarak jalan, durasi, profile, rule tarif, koordinat, serta biaya final disimpan sebagai snapshot. Kegagalan provider menolak quote/order dengan error aman; sistem tidak mengganti hasil menjadi garis lurus, gratis, atau provider lain.
- Data provinsi/kabupaten/kecamatan/kelurahan tersedia melalui API publik bertingkat. Super Admin dapat export/import CSV wilayah dari dashboard/API, sedangkan command `regions:import` tersedia untuk operasional server.

---

## 4. Fitur yang Ada

- **Autentikasi & Otorisasi**: Sanctum SPA cookie session, 8 role, hierarki dan permission per role. Perubahan domain penting memakai `ActivityLogger` ke tabel `activity_logs`; diagnostic teknis tertentu memakai log Laravel terstruktur.
- **Manajemen Pengguna**: CRUD user sesuai hierarki, reassign referral (pindah sales↔korsal, konsumen↔sales), status akun (active/inactive/suspended dengan badge berbeda).
- **Katalog Produk**: produk dengan/tanpa varian, SKU global, kategori, gambar (multi-gambar per produk, satu primary), stok per-agen dan per-varian; harga mulai otomatis dari varian termurah kalau produk punya varian.
- **Keranjang, Wishlist, Checkout**: dengan quote real-time (subtotal, ongkir, biaya admin) sebelum submit order, idempotency key untuk mencegah order duplikat dari klik ganda/retry jaringan.
- **Order Lifecycle Lengkap**: lihat §3.4 — termasuk penyesuaian fulfillment per item, reschedule tanggal kirim (termasuk split kuantitas), pembatalan, retur/refund per-item, additional payment.
- **Alamat Tersimpan (Konsumen)**: buku alamat dengan cascading Provinsi/Kabupaten-Kota/Kecamatan/Kelurahan dan koordinat, bisa dipakai langsung saat checkout.
- **Pembayaran**: COD (dengan foto bukti + konfirmasi Keuangan), Transfer Bank Manual (foto bukti + verifikasi Keuangan), dan 3 payment gateway (Xendit/Tripay/Stripe) dengan webhook terverifikasi signature serta idempotency guard.
- **Pengiriman**: Kurir Online memakai OpenRouteService Directions V2 dengan origin koordinat Agen dan destination snapshot order; Ekspedisi memakai RajaOngkir/Komerce V2. Super Admin mengatur sakelar provider dan master ekspedisi global. Setiap Agen menyimpan kredensial terenkripsi, memilih kurir, dan mengatur tarifnya sendiri. Hasil provider dan tarif final dihitung server lalu disimpan sebagai snapshot shipment.
- **Dashboard Kurir**: daftar order siap ambil, tab retur, tab riwayat selesai (dengan rekap fee milik kurir itu sendiri saja, tidak pernah fee kurir lain).
- **CMS**: blok konten homepage, artikel/berita, halaman statis — semua lewat CKEditor 5, disanitasi server-side sebelum disimpan (mencegah XSS).
- **Website Settings**: nama situs, logo, favicon, slogan, kontak, sosial media — publik & bisa diedit Super Admin; header frontend/dashboard otomatis pakai logo yang di-upload (fallback ke teks kalau belum ada logo).
- **Direktori "Cari Agen/Toko"**: publik, dengan kode referral yang bisa langsung disalin (klik untuk copy).
- **Media Upload**: pipeline dengan validasi MIME/tipe file sungguhan (bukan cuma cek ekstensi), nama file di-generate (tidak pernah dari nama asli), batas ukuran per jenis (umumnya maks 4MB gambar).
- **Multi-bahasa**: 4 bahasa penuh di backend (pesan error/sukses) dan frontend (UI) — Indonesia, Inggris, Arab (RTL), Mandarin.
- **Laporan & dashboard**: transaksi/order, fee sales-korsal, pembatalan/refund, fee kurir, fee Agen, status pembayaran, ringkasan keuangan, kurir per Agen, pelanggan, roster Korsal/Sales/Kurir, dashboard summary, network summary, serta daftar konsumen milik Sales. Endpoint report menerapkan filter tanggal/delivery/status/search dan scope role; report tabel mendukung export `.xlsx` sesuai implementasi controller.
- **Google Sheets Sync**: ekspor manual satu arah untuk 15 dataset yang di-whitelist, dengan pilihan/urutan kolom, header custom, scope global/cabang, test metadata read-only, status koneksi persisten, last-sync status, dan log tersanitasi.
- **Operasional Data**: import/export master wilayah serta command reset transaksi dengan dry-run, backup guard, urutan penghapusan child-to-parent, pemulihan stok, pembersihan bukti transaksi, dan verifikasi master data tetap utuh.
- **Payment Gateway & Shipping Provider Admin**: kredensial disimpan terenkripsi di database (bukan `.env`), tidak pernah ditampilkan kembali lewat API setelah disimpan.

### 4.1. Permukaan Website dan Dashboard

| Area | Halaman/alur utama | Akses |
|---|---|---|
| Storefront publik | Home, katalog produk/kategori, detail produk, artikel, halaman CMS, direktori toko/Agen | Publik |
| Akun pembeli | Cart, wishlist, checkout, profil, riwayat/detail order, buku alamat | Login; buku alamat khusus Konsumen, checkout untuk Konsumen/Agen/Korsal/Sales |
| Dashboard umum | Ringkasan dashboard, order, detail order, profil | Role internal sesuai scope; detail order tetap diperiksa backend |
| Katalog/stok | Produk, kategori, varian, gambar, fee, stok | Produk: Super Admin/Agen; kategori: Super Admin; stok: Super Admin read, Agen/Admin scoped |
| Pengguna | Daftar/detail/edit user, pembuatan sesuai hierarchy, reassign referral | Super Admin/Agen/Korsal/Sales/Admin/Keuangan/Kurir sesuai policy; Konsumen tidak masuk manajemen user |
| Keuangan/laporan | Komisi, finance, report transaksi/order, payment, fee, refund, pelanggan dan roster | Kombinasi Super Admin/Agen/Admin/Keuangan/Korsal/Sales/Kurir sesuai endpoint dan scope |
| Operasional order | Fulfillment, reschedule/split, assignment shipment, return/refund, additional payment | Operasional: Super Admin/Agen/Admin; finansial: Super Admin/Keuangan; Kurir pada shipment/return miliknya |
| Pengaturan Agen | Profil toko, metode pembayaran, credential/environment gateway, provider pengiriman, origin, tarif, kurir | Agen pemilik cabang |
| Pengaturan platform | Website, CMS, media library, bahasa, kontak Agen, payment gateway, provider/master ekspedisi, wilayah, audit log | Super Admin |
| Google Sheets | Destination, mapping dataset, test connection, sync, log | Super Admin global; Agen/Admin hanya cabangnya |
| Dashboard Kurir | Antrean siap ambil, pengiriman sendiri, return pickup, riwayat terkirim dan komisi sendiri | Kurir pada cabangnya |
| Installer | Requirements, database, URL/session, Super Admin pertama, migrate/seed, finalisasi dan lock | Publik hanya sebelum instalasi terkunci |

---

## 5. Level User & Permission

8 role tetap (`roles` table), tanpa role kustom:

| Role | Posisi | Bisa Bikin Akun | Referral Code | Lihat Data |
|---|---|---|---|---|
| **super_admin** | Puncak, lintas cabang | agen saja | ❌ | Semua cabang |
| **agen** | Pemilik/kepala cabang | admin, keuangan, korsal, sales, kurir | ✅ | Cabangnya sendiri (network) |
| **korsal** | Koordinator sales | sales | ✅ | Sales & konsumen di bawahnya |
| **sales** | Penjual, punya kode referral konsumen | — | ✅ | Konsumen miliknya sendiri |
| **konsumen** | Pembeli | — | ❌ | Order miliknya sendiri |
| **admin** | Staf kantor cabang (dibuat agen) | — | ❌ | Cabang yang menugaskannya |
| **keuangan** | Staf finansial cabang (dibuat agen) | — | ❌ | Data finansial cabang yang menugaskannya |
| **kurir** | Staf pengiriman cabang | — | ❌ | Order/shipment yang diklaimnya |

### Ringkasan otoritas efektif

Frontend memakai `PermissionMap` sebagai petunjuk visibilitas menu. Middleware, policy, dan service tetap menjadi sumber keputusan otorisasi pada setiap request.

| Permission | super_admin | agen | admin | keuangan | korsal | sales | konsumen | kurir |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| `system.config.manage` / `system.payment.manage` / `system.shipping.manage` / `system.cms.manage` | ✅ | | | | | | | |
| `users.view.all` | ✅ | | | | | | | |
| `users.view.network` | | ✅ | ✅ | ✅ | ✅ | ✅ | | |
| Lihat stok | ✅ global | ✅ cabang | ✅ cabang | | | | | |
| Ubah stok | | ✅ cabang | ✅ cabang | | | | | |
| `orders.view.all` | ✅ | | | | | | | |
| `orders.view.network` | | ✅ | ✅ | ✅ | ✅ | ✅ | | |
| `orders.view.own` | | | | | | | ✅ | |
| `orders.view.assigned` | | | | | | | | ✅ |
| `orders.create` | | ✅ | | | ✅ | ✅ | ✅ | |
| `orders.manage.status` / `.fulfillment` | ✅ | ✅ | ✅ | | | | | |
| `orders.manage.shipment` | ✅ | ✅ | ✅ | | | | | ✅ |
| Membatalkan order sesuai ownership dan status | ✅ | ✅ cabang | ✅ cabang | | ✅ jaringan Korsal | ✅ milik Sales | ✅ milik sendiri | |
| Verifikasi/penyelesaian payment, COD, dan DP; kelola refund/additional payment | ✅ | | | ✅ | | | | |
| `sheets.manage` | ✅ | ✅ | ✅ | | | | | |

Catatan: nama capability di atas adalah hint UI dari `PermissionMap`, sedangkan otorisasi efektif berasal dari middleware, policy, dan service. Contohnya pembatalan milik Konsumen/Sales/Korsal diizinkan oleh `OrderPolicy` berdasarkan ownership walaupun capability `orders.cancel` tidak dikirim sebagai hint umum kepada role tersebut.

Aturan tambahan yang tidak terlihat penuh pada tabel:

- Kategori hanya dapat dibuat/diubah/dihapus Super Admin. Produk, varian, gambar produk, dan fee katalog dikelola Super Admin atau Agen; pembacaan fee oleh Sales dibatasi resource sesuai kebutuhannya.
- Super Admin dapat melihat stok global. Endpoint penyesuaian stok dibatasi Agen/Admin dan selalu memakai scope cabang.
- Semua role internal dapat membuka daftar user sesuai policy, tetapi membuat akun hanya Super Admin/Agen/Korsal sesuai hierarchy. Menghapus user jauh lebih sempit: Super Admin, atau Agen untuk Admin/Keuangan/Kurir milik cabangnya; akun sendiri tidak dapat dihapus melalui alur ini.
- Bukti transfer/COD dapat diajukan oleh pihak yang berhak atas order. Verifikasi transfer, konfirmasi COD, settlement DP, perubahan status refund, additional payment, dan refund return hanya Super Admin/Keuangan.
- Review operasional retur hanya Super Admin/Agen/Admin. Kurir hanya menangani pickup/konfirmasi fisik return dan tidak mengubah status uang.

### Aturan visibilitas fee (contoh kontrol paling ketat di sistem):
- **agent_fee**: hanya super_admin & agen — **tidak pernah** admin/keuangan, dalam kondisi apapun (termasuk order referral langsung, lihat catatan split di bawah).
- **sales_fee**: super_admin, agen, sales (miliknya sendiri), korsal (tim sales di bawahnya, atau miliknya sendiri kalau korsal jadi referrer langsung), admin, keuangan.
- **courier_fee**: super_admin, agen, admin, keuangan — **tidak pernah sales, tidak pernah konsumen, tidak pernah kurir sendiri** (kurir tahu dia dibayar, tapi tidak melihat angka fee mentah lewat API order — hanya lewat `/commissions` miliknya sendiri).
- **Referral langsung tanpa sales di tengah** (konsumen direferensikan langsung oleh agen, atau oleh korsal tanpa sales): agen/korsal yang menjadi referrer langsung itu berperan sebagai "sales" untuk konsumen tersebut, sehingga fee produk dibagi 2 komisi terpisah — `agent_fee` (tetap ke agen cabang, tetap tersembunyi dari admin/keuangan) DAN `sales_fee` tambahan (ke agen/korsal yang mereferensikan, terlihat oleh admin/keuangan seperti sales_fee biasa). Dua komisi ini tidak pernah digabung jadi satu.
- Konsumen dan kurir **tidak pernah** melihat field harga/fee/subtotal/payment_status pada endpoint yang memang didesain untuk mereka (`CourierOrderResource` khusus kurir sengaja tidak menyertakan field uang sama sekali).
- Konsumen **tidak** melihat identitas sales/korsal yang menangani ordernya sendiri (informasi rantai referral internal, bukan urusan konsumen).

---

## 6. Proses Bisnis Kunci (Aturan yang Sering Jadi Sumber Bug Kalau Dilanggar)

1. **Harga/fee/stok/total tidak pernah dipercaya dari klien** — semua dihitung ulang di server dari database di setiap request, klien cuma kirim ID produk + kuantitas.
2. **Snapshot, bukan referensi hidup** — harga, fee, alamat pengiriman, dll di order/item disalin ("snapshot") saat transaksi terjadi. Perubahan harga produk/fee di kemudian hari tidak pernah mengubah order yang sudah ada.
3. **COD = belum ada uang masuk** — tidak pernah masuk ke sistem refund (karena tidak ada yang harus dikembalikan), pengurangan kuantitas pada COD adalah pembatalan biasa, bukan refund; penambahan kuantitas pada COD tidak membuat "additional payment" terpisah (nempel ke total COD yang akan dibayar sekaligus saat barang sampai).
4. **Satu order, banyak shipment, banyak kurir** — konsekuensi dari aturan "reschedule per produk" dan "kurir tidak boleh saling mengambil order kurir lain". Status order keseluruhan adalah hasil rangkuman dari status semua shipment-nya.
5. **Idempotency** — pembuatan order pakai `Idempotency-Key` header; retry jaringan/klik ganda tidak pernah membuat order duplikat. Webhook payment juga idempotent per event.
6. **Audit trail sesuai domain** — user/referral, payment, order service, fulfillment, shipment/return, stok, settings, profil Agen, provider cabang, master kurir, produk, dan import wilayah menulis `activity_logs` dengan actor serta konteks sebelum/sesudah yang relevan. Log teknis framework tetap berada di `storage/logs`; keduanya tidak boleh memuat secret.
7. **Isolasi cabang berlapis** — bukan cuma difilter di query, tapi juga dicek ulang di policy/service layer sebagai lapisan kedua (defense in depth) — satu baris kode yang lupa filter tidak langsung jadi kebocoran data lintas cabang.
8. **Kegagalan layanan eksternal ditangani eksplisit** — OpenRouteService atau RajaOngkir yang tidak dapat memverifikasi ongkir menghasilkan error aman. Order ditolak dengan 422 agar ongkir tidak berubah diam-diam menjadi hasil garis lurus, gratis, atau provider lain. Error gateway ditangani per gateway.
9. **Ownership user diturunkan dari actor** — `agent_id`, `parent_id`, dan `korsal_id` yang dapat ditentukan dari user yang login tidak dipercayai dari request. Sales selalu berada di bawah Korsal; jika Agen yang membuatnya, Korsal wajib dipilih dari cabang Agen itu.
10. **SKU unik lintas tipe katalog** — validasi tabel produk saja atau tabel varian saja tidak cukup. Semua write harus melewati registry global `catalog_skus` dan konflik tetap harus ditangani aman jika dua request bersaing.
11. **Google Sheets adalah salinan laporan** — exporter hanya membaca proyeksi kolom eksplisit setelah scope cabang diterapkan. Spreadsheet tidak boleh dipakai untuk mengubah data aplikasi.
12. **Pemisahan operasional dan finansial** — Agen/Admin mengubah fulfillment/status/shipment dan review retur; Keuangan memverifikasi/menyelesaikan pembayaran, refund, additional payment, dan DP. Super Admin mempunyai override lintas cabang.
13. **Reset transaksi bukan reset master** — gunakan `transactions:reset --dry-run` lalu `--force` setelah backup. Command hanya menyasar order dan turunan transaksi/laporan, memulihkan stok yang dapat dibuktikan, dan tidak menghapus user, katalog, konfigurasi, wilayah, atau CMS.

---

## 7. Keamanan

- Setiap upload file diverifikasi MIME/tipe sungguhan (bukan sekadar ekstensi), disimpan dengan nama ter-generate.
- Kredensial payment gateway & shipping provider **tidak pernah** di `.env` — hidup di database terenkripsi, hanya lewat layar admin, tidak pernah dikembalikan lewat API setelah disimpan.
- Kredensial service account Google adalah konfigurasi infrastruktur terpusat: path file JSON disimpan di `.env`, sedangkan file kunci berada di filesystem privat di luar web root. Isi credential, private key, dan access token tidak disimpan dalam konfigurasi spreadsheet, log, atau respons API; diagnostic hanya membuka fakta aman seperti path resolved, validitas, project, email, dan keberadaan field key.
- Rate limiting diterapkan di login, registrasi, checkout, dan webhook pembayaran.
- CSRF (Sanctum) aktif di semua endpoint yang butuh sesi login, **kecuali** endpoint wizard instalasi (`/install/*`) — sengaja dikecualikan karena endpoint ini hanya bisa dipakai sebelum aplikasi ter-install sama sekali (tidak ada data sungguhan untuk diserang), dan otomatis tersegel permanen begitu instalasi dikunci.
- Pada paket single-domain, browser memakai same-origin `/api` dan `/sanctum`, sehingga konfigurasi cookie lebih sederhana. Untuk deployment dua subdomain, gunakan HTTPS dan set `FRONTEND_URLS`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`, serta atribut cookie sesuai domain induk; salah satu nilai yang tidak cocok dapat memunculkan CSRF/session mismatch.
- `APP_DEBUG=false` di production menyembunyikan detail teknis error dari respons API — cek `backend/storage/logs/laravel.log` untuk detailnya.

---

## 8. Hal Penting Lain untuk Yang Meneruskan/Mengelola Proyek Ini

- **Migrasi adalah sumber kebenaran skema**, bukan `database.sql` (itu cuma dump hasil ekspor, jangan diedit langsung dan berharap berpengaruh ke aplikasi).
- **Tidak ada demo data/user bawaan** — seeder hanya mengisi role tetap, 4 bahasa, payment method dasar (cod/bank_transfer aktif; xendit/tripay/stripe nonaktif sampai dikonfigurasi), dan pengaturan default. Super Admin pertama dibuat lewat wizard instalasi (§3.1).
- **Backup**: database (mysqldump/snapshot host) + `backend/storage/app/public` (semua file upload — tidak bisa direproduksi dari database saja) + `.env` (simpan aman terpisah, jangan sertakan di backup publik).
- **File upload tersimpan di `storage/app/public`**, diakses lewat symlink `public/storage` — kalau gambar 404 padahal sukses upload, biasanya symlink ini belum dibuat (wizard instalasi sudah otomatis mencoba membuatnya).
- **Belum ada test otomatis untuk frontend** — validasi yang tersedia adalah TypeScript check, production build, dan pemeriksaan manual di browser. Repository saat audit memiliki 45 file test backend dan 459 method test terdeklarasi; angka pass/assertion harus diambil dari eksekusi suite terbaru, bukan disalin sebagai angka permanen. Bootstrap test menolak nama database yang tidak memuat `_test` atau `_testing`.
- **Belum ada command backup bawaan** — backup terjadwal harus disiapkan sendiri di level infrastruktur (cron + mysqldump, atau fitur snapshot dari penyedia hosting/database).
- **Upgrade database existing untuk SKU harus eksplisit** — review `php artisan system:audit-catalog-hierarchy`, preview `php artisan products:backfill-sku`, lalu gunakan `--apply` setelah hasilnya disetujui. Preview/apply idempotent; jangan memakai `migrate:fresh` pada data existing.
- Pada database lokal tanggal 14 September 2026, seluruh migrasi repository sampai `2026_09_25_100000_add_connection_status_to_sheets_destinations` sudah berstatus **Ran**. Kondisi database target deployment tetap harus diperiksa dengan `php artisan migrate:status`.
- Credential Google Sheets lokal sudah valid dan autentikasi mencapai Google, tetapi destination `1X2r…VsHk` terakhir mendapat `HTTP 403 PERMISSION_DENIED`. Spreadsheet harus dibagikan sebagai Editor ke service account yang tampil di dashboard, lalu **Periksa koneksi** dan satu sync tab khusus dijalankan ulang sebelum status produksi dianggap working end-to-end.
- Paket produksi resmi dibuat dengan `deploy/build.sh`; hasilnya menempatkan SPA, `laravel.php`, `.htaccess`, `config.js`, dan symlink storage pada `public_html/`, sedangkan source Laravel berada di sibling `backend/` yang tidak publik.
- Dokumen referensi teknis: `README.md`, `docs/IMPLEMENTATION-REPORT.md`, `docs/GOOGLE-SHEETS-INTEGRATION.md`, `docs/RAJAONGKIR-AUDIT.md`, `docs/OPENROUTESERVICE-AUDIT.md`, `docs/TRANSACTION-RESET-REPORT.md`, `docs/SKU-HIERARCHY-AUDIT.md`, `docs/REPOSITORY-CLEANUP-REPORT.md`, `backend/SECURITY_AUDIT.md`, dan `backend/FINAL_AUDIT_REPORT.md`.
