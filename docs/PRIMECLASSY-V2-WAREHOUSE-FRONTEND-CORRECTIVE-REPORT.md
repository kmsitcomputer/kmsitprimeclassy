# PrimeClassy V2 — Remediasi Korektif Frontend Gudang & Verifikasi Runtime

**Tanggal:** 2026-11-11 · **Lingkungan:** Local DEV · **HEAD:** `d7997c4` (dirty tree dipertahankan; tanpa commit/push/deploy production)
**Verdict: PARTIAL.** Source, build, aset yang dilayani, dan scoped test sesuai keputusan Owner. **UAT browser belum dapat dilakukan** (lihat §6).

## 1. Root cause (terbukti)

Halaman aktif menampilkan alur lama karena **aset yang dilayani adalah build lama**, bukan karena source salah.

| Lapisan | Bukti | Keadaan awal |
|---|---|---|
| Source `frontend/src` | `grep "Usulkan (f\|P)\|diproses Admin\|Ajukan ke Admin"` → 0 hasil | Benar |
| `frontend/dist` | 0 teks lama; `Setujui & Serahkan` dan `PEMENUHAN KURANG` ada; build deterministik identik dengan build ulang dari source | Benar |
| **Yang dilayani** (`primeclassy-web` mount `apps/primeclassy/public_html` → nginx `root /var/www/app/public_html`) | build **10 Nov 23:47**, entry `index-B_H3YMgl.js`; teks "Usulkan fulfillment" ada di 4 chunk; `Setujui & Serahkan` dan `PEMENUHAN KURANG` 0 | **Basi** |
| Routing | route `warehouse-orders` → `OrderDiprosesView.vue` (benar); chunk yang dimuat dari `public_html` adalah versi lama | Benar, tetapi memuat chunk lama |
| Backend runtime | `primeclassy-fpm`: `opcache.validate_timestamps=On`, `revalidate_freq=2`; rute proposal terdaftar | Kode terbaru dilayani |
| Service worker | `sw.js` versi-pinned per build (`VERSION`), mengunci daftar aset build lama | Cache lama sampai `sw.js` baru dipublikasi |

Tidak ada mekanisme otomatis `dist → public_html` untuk DEV lokal: publish memang manual. Build baru dibuat (oleh sesi lain, `frontend/dist` 12:14) tetapi tidak pernah dipublikasi ke `public_html`. Frontend lama juga akan gagal pada API baru (bukti wajib → 422), jadi mismatch ini juga menutup alur kerja.

## 2. Perbandingan source / build / aset dilayani (setelah perbaikan)

| | Hasil |
|---|---|
| Build ulang dari source (kontainer Node efemeral pada salinan) | sukses; `sw.js` versi `78089e3ce15c1866`, 154 entri precache |
| `frontend/dist` diselaraskan dengan build itu | sama |
| URL resmi `https://primeclassy.kmsit.localhost/` | entry `index-BpasqXQK.js`, chunk `OrderDiprosesView-BuCc5-yt.js`; `/sw.js` berisi `78089e3ce15c1866`; `/up` 200 |
| Teks lama (`Usulkan fulfillment`, `Usulkan Pemenuhan`, `diproses Admin`, `Ajukan ke Admin`, `Fulfillment Proposal`) di seluruh aset build | 0 |
| Teks baru pada chunk yang dilayani | `Serahkan ke Koordinator`, `Diminta Koordinator Kurir`, `Permintaan Koordinator` ada |

Publish: backup `public_html` ke `/Users/kmsitcomputer/KMSIT-Server/backups/primeclassy-public_html-pre-warehouse-fix-20261111-121930` dan `…pre-queue-fix-20261111-122456` (di luar repo); `rsync` tanpa `--delete` dengan pengecualian `laravel.php`, `.htaccess`, `config.js`, `storage`, `.well-known` (semuanya terverifikasi utuh: 1407 / 2696 / 443 byte, symlink `storage`). 380 aset hash lama dibiarkan (tidak direferensikan; aman untuk klien yang masih memegang index lama). Tidak ada perubahan Docker, nginx, Caddy, domain, port, atau volume.

## 3. File yang diubah dan alasannya (putaran ini)

- `backend/app/Http/Controllers/Api/V1/Stock/WarehouseOrderController.php` — antrean Gudang kini menyertakan `fulfillment_request` (Koordinator pengaju, waktu, catatan, jumlah, status penyerahan; nilai nyata, tidak diinferensi), filter `?requested=1`, urutan permintaan Koordinator terbaru lebih dulu, `meta.requested_total`. Sebelumnya halaman Gudang **tidak menampilkan** permintaan Koordinator sama sekali (celah terhadap §4A).
- `frontend/src/api/warehouseFulfillment.ts`, `views/dashboard/OrderDiprosesView.vue` — tab "Permintaan Koordinator (n)" / "Semua order diproses" (default permintaan Koordinator), blok identitas/waktu/catatan/status per order, label status (menunggu Gudang / menunggu konfirmasi penerimaan / sebagian / terpenuhi).
- Test: `FulfillmentShortageWorkflowTest` (+1: antrean menampilkan pengaju dan waktu nyata, filter, status penyerahan, otorisasi), `OrderDiprosesView.spec.ts` (+4).
- `docs/06-BUSINESS-WORKFLOWS.md`, manifest (`dda21be3…`; hanya hash/jumlah, tanpa perubahan lane).
- Publish aset: `frontend/dist` dan `public_html` (bagian 2).

Pekerjaan putaran sebelumnya tetap berlaku (form Gudang: jumlah, alasan wajib, bukti wajib; panel PEMENUHAN KURANG; menu/route Admin lama dihapus).

## 4. Bukti halaman Gudang, Koordinator, Admin

Gudang (`/dashboard/warehouse/orders`): bukti pada bundel yang dilayani + Vitest — tidak ada kata "ke Admin/diproses Admin"; tombol "Setujui & Serahkan" dan "Setujui & Serahkan ke Koordinator"; alasan wajib bila < sisa; bukti foto wajib bila ada barang diserahkan; submit ganda dicegah (`submitting`); 0 barang → laporan kekurangan tanpa proposal/stok (backend diuji).
Koordinator: tombol "Minta ke Gudang" (Dispatch), panel penerimaan menampilkan bukti Gudang dan alasan kekurangan, penugasan tanpa Admin (diuji backend: nol aksi Admin pada jalur kirim).
Admin: tanpa menu approval Gudang; panel PEMENUHAN KURANG pada detail order, tabel per produk, alasan, bukti, Reschedule dan Cancel Sisa (Vitest `FulfillmentShortagePanel`, `OrderDetailView`).

## 5. Hasil scoped test (setelah perubahan ini)

| Pemeriksaan | Hasil |
|---|---|
| PHPUnit `FulfillmentShortageWorkflowTest` + `GudangOrderQueueTest` + `WarehouseFulfillmentProposalTest` | 29 test, 314 asersi, OK |
| Vitest seluruh frontend | 36 file, 304 test OK |
| `vue-tsc`, oxlint, eslint (berkas berubah), `vite build` | bersih / sukses |
| `manifest-verify`, `static-validate`, `git diff --check`, `migrate:status --pending` | OK / OK / bersih / tidak ada pending |

Angka 197/34/17/300 pada brief adalah baseline sebelumnya; test konkurensi tidak dijalankan ulang karena logika service tidak berubah putaran ini (hanya pembacaan antrean).

## 6. UAT browser — belum dilakukan

Alat otomasi browser tidak tersedia pada sesi ini, dan akun uji DEV (gudang, koordinator-kurir, admin ada di DB) tidak memiliki kredensial yang saya ketahui; saya tidak mereset kata sandi atau membuat token pada `primeclassy_db`. **Tidak ada skenario A–D yang diklaim PASS.** Yang sudah terbukti hanya pada tingkat bundel yang dilayani dan test otomatis.

Langkah manual untuk Owner (hard-refresh atau tutup tab agar service worker mengambil versi `78089e3ce15c1866`):
1. **A — lengkap:** login Koordinator → Dispatch → "Minta ke Gudang" pada order `diproses`. Login Gudang → `/dashboard/warehouse/orders`: harus muncul tab "Permintaan Koordinator (1)", kartu dengan nama Koordinator + waktu + catatan, tombol "Setujui & Serahkan". Isi jumlah penuh, lampirkan foto, "Setujui & Serahkan ke Koordinator". Koordinator → panel penerimaan → konfirmasi → tugaskan Kurir. Admin tidak diminta apa pun.
2. **B — kurang:** jumlah sebagian → kolom "Alasan kekurangan" wajib muncul per item; tanpa alasan atau tanpa foto tombol tertahan. Setelah submit: Koordinator tetap bisa menugaskan/mengirim bagian yang diterima; Admin (lonceng) menerima "PEMENUHAN KURANG" tertaut ke order; detail order menampilkan tabel per produk, alasan, bukti; "Reschedule sisa" / "Cancel sisa" hanya pada sisa; baris yang sudah dikirim tidak punya tombol.
3. **C — nol barang:** isi 0 + alasan → tanpa foto → "Kekurangan tercatat"; stok Transit/Shipping tidak berubah; Admin menerima notifikasi.
4. **D — otorisasi:** akun Admin/Koordinator/Kurir ke `/dashboard/warehouse/orders` → ditolak; Koordinator/Gudang pada Reschedule/Cancel Sisa → 403 (sudah diuji backend).

## 7. Audit endpoint lama `POST /warehouse/fulfillment-proposals/{p}/approve|reject`

- **Otorisasi:** `role:admin` + `StockRequestProposalPolicy::approve/reject` (Admin cabang yang sama). `approve` mewajibkan alasan 5–255 karakter dan mencatat `stock_request.admin_exception_approval`.
- **Pemanggil produksi:** hanya controller→service. **Frontend: tidak ada** (client, view, menu, route dihapus).
- **Pemanggil test:** HTTP 37 titik pada 8 berkas (`WarehouseFulfillmentProposalTest` 23, `StockRequestTest` 4, `GudangOrderQueueTest` 4, lainnya 6); service langsung `approve(admin, proposal)` 4 titik (`DispatchAssignmentConcurrencyTest`, `PricingRemediationTest`). Semuanya memakainya sebagai **fixture penyelesaian** karena sebelum REM-002 itu satu-satunya cara memindahkan stok.
- **Bukan syarat alur normal:** penugasan dan pengiriman hanya membaca `fulfilled_qty`; test `FulfillmentShortageWorkflowTest` membuktikan nol `admin_exception_approval` pada jalur Koordinator → Gudang → Koordinator → kirim.
- **Fungsi yang berbeda secara bisnis:** `approve` Admin menyelesaikan **seluruh jumlah disiapkan sebagai diterima tanpa penerimaan fisik Koordinator** (melewati serah terima dua pihak; risiko integritas stok); `reject` membatalkan serah terima Gudang yang menunggu. Keduanya "pengecualian/sengketa" menurut spesifikasi REM sebelumnya, tetapi **bertentangan dengan keputusan Owner terbaru** (Admin hanya memutuskan sisa).
- **Dampak bila dihapus:** ±41 titik test harus diganti dengan penerimaan Koordinator (`receive`) sebagai fixture; tidak ada dampak pada UI atau alur normal; `resolve-discrepancy` (Admin, unit rusak) tidak terkait dan tidak disentuh.
- **Rekomendasi:** pensiunkan `approve|reject` dan migrasikan fixture ke `receive`. **Tidak saya lakukan** sesuai perintah (laporkan dulu bukti dan dampak). Perlu keputusan Owner.

## 8. Review `git diff` terkait

Perubahan terkait workflow ini tersebar pada working tree yang sudah dirty (REM-001..006, remediasi sebelumnya, dua sesi agen). Yang dapat dipastikan: lint PHP/TS bersih, `git diff --check` bersih, tidak ada migrasi pending, tidak ada perubahan pada `laravel.php`/`.htaccess`/`config.js`. Review baris-demi-baris seluruh dirty tree belum dilakukan dan disarankan oleh Owner sebelum commit.

## 9. Risiko dan pekerjaan belum selesai

1. **UAT browser A–D belum dijalankan** (verdict PARTIAL).
2. Keputusan endpoint approve/reject (bagian 7).
3. Service worker: pengguna yang tab-nya terbuka mungkin masih memegang build lama sampai refresh.
4. Publish `dist → public_html` manual; tanpa pembaruan ulang setiap build, masalah yang sama akan berulang. Perlu skrip/runbook DEV lokal (tidak dibuat; di luar scope).
5. Media bukti serah terima yatim pada `products:reset`; satuan produk tidak ada di katalog (dilaporkan sebelumnya).
6. Full Test belum dijalankan ulang (tidak diminta).

## 10. Konfirmasi

Tidak ada akses production/VPS, deploy production, commit, push, atau merge. `primeclassy_db` tidak dimodifikasi (hanya `migrate:status` baca-saja); pengujian otomatis memakai `primeclassy_testing_w*`. Tidak ada perubahan Docker/nginx/Caddy/domain/port/volume. Satu-satunya perubahan runtime DEV: menyalin aset build ke `public_html` (dengan backup).
