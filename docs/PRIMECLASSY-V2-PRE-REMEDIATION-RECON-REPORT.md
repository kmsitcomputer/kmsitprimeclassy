# PRIMECLASSY V2 — UNIFIED PRE-REMEDIATION RECON REPORT

| | |
|---|---|
| **Executor** | GLM (ali/glm-5.3) |
| **Authorization** | Owner Approved — RECON ONLY (read-only, one-pass) |
| **Tanggal** | 2026-11-08 |
| **Branch / HEAD** | `main` / `e738d0fdef858cb6854f4c88b0db295f883b6133` (sinkron `origin/main`) |
| **Mode** | READ-ONLY — tidak ada perubahan source/database/.env, tidak ada test dijalankan, tidak ada akses produksi, tidak ada commit |
| **Maksud** | Bukti akar-masalah + rancangan remediasi untuk 6 temuan UAT agar remediasi berikutnya dapat dikerjakan dalam satu rangkaian tanpa audit ulang |
| **Status dokumen** | Laporan kerja sementara (bukan dokumen kanonis) — dihapus/dilipat ke dokumen kanonis saat remediasi selesai |

---

## A. Environment

| Item | Nilai |
|---|---|
| Repository root | `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy` |
| Branch | `main` (`git status -sb` → `## main...origin/main`, tanpa ahead/behind) |
| HEAD | `e738d0f` — `chore: clean local repository artifacts and document removals` |
| Working tree | 1 file termodifikasi: `docs/PRIMECLASSY-WA-MGMT-KMSIT-OSRM-CHECKPOINT.md` (checkpoint Owner yang belum di-commit, item G-15 — sengaja tidak disentuh, tidak boleh ikut ter-commit oleh remediasi) |
| Backend | Laravel 11.56, PHP 8.2+ (DEV 8.3), Sanctum SPA cookie-session, MariaDB 10.11 |
| Frontend | Vue 3 (Composition API) + Vite 8 + TypeScript + Pinia + Vue Router + vue-i18n + Tailwind 4; npm; **tidak ada test runner otomatis** (verifikasi = `npm run type-check` + `npm run build-only` + browser manual) |
| Runtime dev | Vite proxy `/api`, `/sanctum`, `/storage`, `/up` → backend, `changeOrigin: false` (`frontend/vite.config.ts:27-34`) — host asli terjaga |
| Baseline produksi | REL006 (`f8445e1`); Package A & B tutup di produksi; Package C DEV stage gate CLOSED |

Perintah inspeksi yang dijalankan (semua read-only): `pwd`, `git rev-parse --show-toplevel`, `git branch --show-current`, `git rev-parse HEAD`, `git status --short/-sb`, `git branch -a -v`, `git log --oneline`, plus pembacaan file source/docs via tool baca.

Dokumentasi kanonis yang dibaca sebagai peta awal (source tetap bukti utama): README, AGENTS.md, 03-FEATURE-CATALOG, 04-ROLES-PERMISSIONS, 05-BUSINESS-RULES, 06-BUSINESS-WORKFLOWS, 07-FRONTEND-GUIDE, 08-BACKEND-API (peta rute), 12-SECURITY, 15-KNOWN-LIMITATIONS.

---

## B. Findings Matrix

| ID | Status | Severity | Root Cause (ringkas) |
|---|---|---|---|
| UI-USER-001 | **CONFIRMED** (defek frontend murni) | MINOR | Halaman Pengguna memanggil `listUsers()` sekali (halaman 1, default 15) dan mengabaikan metadata pagination; API backend **sudah** mendukung server-side pagination + search + filter role |
| SEC-COURIER-001 | **PARTIAL** | CRITICAL | Isolasi assigned-only sudah di-enforce di semua jalur mutasi/baca sensitif; satu-satunya "kurir melihat/memproses order belum ditugaskan" = **antrean `diproses` + klaim mandiri (claim model LOCKED §11)** — konflik aturan, bukan bug implementasi |
| COURIER-002 | **CONFIRMED** (fitur belum ada) | MAJOR | Dispatch workspace hanya menampilkan shipment `pending`+unassigned; tidak ada permukaan riwayat terkirim scope cabang untuk Koordinator Kurir |
| COURIER-003 | **NOT REPRODUCED** sebagai defek otorisasi | MINOR | `OrderPolicy::view` sudah mengizinkan koordinator melihat semua order cabang termasuk `terkirim` (proyeksi operasional); gap-nya adalah navigasi/discovery yang jatuh ke COURIER-002 |
| PAYMENT-001 | **PARTIAL** (backend benar; defek frontend) | HIGH | `openPaymentProof` memakai `window.open` **setelah await fetch** (popup blocker / PWA standalone gagal) + **tanpa error handling** di call site; URL bukti absolut dari `url()` berisiko salah scheme di produksi tanpa TrustProxies |
| INVOICE-001 | **CONFIRMED** | MEDIUM | Logo invoice hanya dirender bila `logo_media_id` di-set di InvoiceConfig (default `null`); tidak ada logo default/fallback; satu-satunya aset logo resmi di repo = `design/assets/prime-logo-reference.png` (referensi lokal, bukan aset runtime) |

---

## C. Detail Temuan + Bukti

### C.1 RECON-01 — UI-USER-001: Halaman Pengguna tanpa pagination

**Status: CONFIRMED — backend sudah benar; defek 100% frontend.**

**Bukti backend (sudah mendukung server-side pagination):**
- `backend/app/Http/Controllers/Api/V1/User/UserController.php:94-142` — `index()`:
  - `paginate($request->integer('per_page', 15))` di baris 135; meta `current_page` / `last_page` / `total` di baris 137-141.
  - Search nama/telepon (baris 130-133), filter `role` (127-129), scoping per role: non-super_admin diikat `agent_id` (106-110); korsal/sales/SKS scope downline (112-116); koordinator-kurir hanya `parent_id` = sendiri + role `kurir` (117-119); super_admin bisa `?agent_id=`.
  - Search & filter diterapkan **sebelum** pagination (query builder → `paginate`) — aman.
- Rute: `backend/routes/api_v1.php:223-234` (`GET /users`, 9 role).

**Bukti frontend (mengabaikan metadata pagination):**
- `frontend/src/views/dashboard/UserManagementView.vue:187-198` — `load()` memanggil `listUsers()` tanpa argumen → selalu halaman 1, default 15 baris; `users.value = rows`. **Tidak ada kontrol halaman, pencarian, filter role/status, pilihan per_page, maupun total record di template (274-398).**
- `frontend/src/api/users.ts:16-19` — `listUsers` mengirim `page` + `role/search/agent_id`, **tidak mengirim `per_page`**.
- Bug turunan: modal reassign memuat kandidat **hanya halaman 1** (`UserManagementView.vue:150` — `listUsers(1, { role })`) → dropdown terbatas 15 baris; sebaliknya watcher pemilih Korsal mem-paginate semua halaman dengan benar (108-129).

**Gap backend untuk desain target (kecil, opsional tapi disarankan):**
- Tidak ada filter `status` (hanya role + search) — permintaan Owner menyebut filter role/status.
- Tidak ada `orderBy` eksplisit → urutan antar halaman tidak dijamin stabil.
- `per_page` tanpa batas atas (klien bisa minta 100000) — clamp disarankan.
- Tidak ada komponen pagination reusable; tiap view inline sendiri (DispatchView, OrderHistoryView, KurirDashboardView memakai pola inline Prev/Next). `AgentPicker.vue` ada dan dipakai DispatchView — bisa dipakai untuk filter cabang super_admin.

**Jawaban pertanyaan kunci RECON:**
1. API sudah mendukung server-side pagination? **Ya** (`paginate` + meta).
2. Frontend mengabaikan metadata pagination? **Ya** (halaman 1 saja, tanpa navigasi).
3. Backend membatasi hasil tanpa navigasi? **Ya** (15 default) — tapi navigasinya hilang di frontend, bukan di backend.
4. Search/filter sebelum pagination? **Ya** (di query builder sebelum `paginate`).
5. Perbaikan terkecil & teraman: **frontend-only** — tambahkan state page/per_page/search/filter + kontrol navigasi; (opsional) 3 penyesuaian kecil backend (status filter, sort stabil, clamp per_page).

---

### C.2 RECON-02 — SEC-COURIER-001: Isolasi order kurir (CRITICAL)

**Status: PARTIAL — semua jalur assigned-only sudah di-enforce server-side; satu celah terstruktur adalah claim model yang merupakan aturan bisnis TERKUNCI.**

#### C.2.1 Yang SUDAH di-enforce (bukti per jalur akses)

| Jalur | Enforcement | Evidence |
|---|---|---|
| Order list generik `GET /orders` | 403 untuk kurir (`/kurir/orders` satu-satunya permukaan discovery) | `OrderController.php:29-31` |
| Order detail generik `GET /orders/{order}` | `OrderPolicy::view` → `return false` untuk kurir (anti-IDOR direct URL) | `OrderPolicy.php:109-113`; test `R04OrderProjectionTest:164` |
| Mutasi status shipment `PATCH /shipments/{id}/status` | Policy cek branch; **kepemilikan executor di service**: `assertMayOperateShipment` — self_sub hanya pemilik SKS; SKS/koordinator hanya kurir tercatat (`courier->user_id === actor`); kurir normal: klaim saat `dikirim` (lihat C.2.2), `terkirim` wajib kurir tercatat + bukti foto (422 tanpa bukti) | `CourierService.php:344-427` (`:416-425` terkirim); `ShipmentPolicy.php:26-60` |
| Mutasi per-item `PATCH /shipments/{id}/items/{item}/status` | Item harus milik order+shipment yang sama (404 bila tidak); executor rule sama | `ShipmentController.php:126-148` (cek 130-133) |
| Executor nonaktif | Ditolak 403 meski assignment dibuat saat masih aktif | `CourierService.php:353-356` |
| Cetak resi `GET /shipments/{id}/receipt` | Kurir/SKS/koordinator hanya shipment milik sendiri (`courier_id` → user_id); order batal tidak bisa dicetak | `ShipmentPolicy.php:87-117` |
| File bukti pembayaran `GET /payment-proofs/{id}` | Kurir/gudang/koordinator TIDAK termasuk yang diizinkan (super_admin, konsumen pemilik, agen/admin/keuangan cabang, korsal/sales/SKS scope `payOnBehalf`) | `PaymentController.php:115-140`; test `MasterV2PaymentCoreTest:328-351` |
| Invoice `GET /orders/{order}/invoice` | Kurir & koordinator ditolak (dokumen finansial) | `InvoiceController.php:33-39` |
| Proyeksi data kurir | Pra-claim = minimal (tanpa nama/telepon/alamat/koordinat penerima); lengkap hanya setelah assignment; item sibling kurir lain difilter keluar | `CourierOrderResource.php:34-42, 62-63, 80-88, 93-135`; test `R04OrderProjectionTest:188, 216` |
| Reassignment / pencabutan | `courier_id` kolom tunggal — assignment baru menimpa (audit `previous_courier_id`); reassignment **hanya** saat order `diproses` DAN shipment `pending` → pasca-pickup tidak bisa direassign → akses kurir lama hilang & atribusi pelaksana akurat | `CourierService.php:64-141` (guard status 91-96, overwrite+audit 120-141) |
| Multi-shipment | Satu order bisa multi-shipment/kurir; proyeksi per-item mencegah bocor sibling | `CourierOrderResource` + test `R04OrderProjectionTest:216` |
| Isolasi antar-Agen | `BelongsToAgentScope` + cek `agent_id` eksplisit di policy/service; kurir lintas cabang 403 | `R04OrderProjectionTest:256`, `CrossAgentIsolationTest.php` |
| Notifikasi | Kurir bukan penerima notifikasi order/pembayaran (WA/email ke pembeli; `payment_proof_uploaded` ke keuangan cabang saja — REL005/006) | `WhatsAppEvents.php` (resipien = buyer/keuangan) |
| Profil kurir | Kurir normal selalu punya `Courier` profile saat akun dibuat (UNIQUE `couriers_user_unique`) — tidak ada kurir tanpa profil | `UserManagementService.php:115-119, 170` |

#### C.2.2 Celah terstruktur vs ketentuan Owner (BUKAN bug — konflik aturan LOCKED)

Ketentuan Owner: **"Kurir biasa hanya boleh melihat dan memproses order yang secara sah ditugaskan kepadanya oleh Koordinator Kurir."** Tiga permukaan saat ini tidak sesuai ketentuan itu:

1. **Antrean `diproses` terlihat SEMUA kurir cabang** (proyeksi minimal: no order, region, item, tanpa kontak penerima).
   - `CourierDashboardController.php:97-116` — kurir normal tanpa filter status melihat semua item `diproses` cabang + `dikirim` milik sendiri; filter `terkirim` mengikat ke `courier_id` sendiri (100-107).
2. **Klaim mandiri (self-assign) saat pickup**: kurir menandai shipment unassigned `diproses` → `dikirim` dan otomatis menjadi `courier_id` (klaim). Kurir lain lalu kehilangan akses.
   - `CourierService.php:295-321` (`selfAssignIfUnassigned`), dipanggil dari `assertMayOperateShipment:412-414`.
   - Docblock eksplisit di `CourierDashboardController:29-31`: *"semua order diproses bisa dilihat semua kurir"*.
3. **Antrean retur unclaimed terlihat semua kurir cabang** (claim-on-first-action).
   - `CourierDashboardController.php:135-163`; `ReturnService::pickupReturn/confirmReturn` → `claimReturnPickup` (unclaimed = siapa pun; claimed = hanya pemegang).

Ketiganya adalah **desain terkunci** di `docs/05-BUSINESS-RULES.md` §11 (baris 107): *"Kurir boleh claim shipment `diproses` tanpa assignment (jadi `dikirim`); kurir lain kehilangan akses."* — plus minimasi pra-claim R-04 yang teruji. Per AGENTS.md §2 (urutan otoritas), keputusan Human menang atas dokumen, tetapi perubahan aturan LOCKED membutuhkan **persetujuan bisnis eksplisit** dan tidak boleh dipilih diam-diam.

**Opsi keputusan yang harus diambil Owner (bloker remediasi keamanan):**
- (a) **Assignment-only penuh** — antrean kurir hanya menampilkan shipment ber-`courier_id` sendiri; jalur `selfAssignIfUnassigned` dihapus/ditolak untuk kurir normal; pickup hanya terjadi setelah dispatcher menugaskan. Konsekuensi: order tanpa kurir yang ditugaskan tidak pernah ter-pickup sampai koordinator/office bergerak; alur retur unclaimed ikut mengecil.
- (b) **Assignment-only untuk visibility saja** — antrean hanya menampilkan yang ditugaskan, tapi jalur klaim API tetap legal (mis. kurir dapat meng-claim dengan mengetahui ID shipment). Visibilitas sesuai ketentuan, tetap ada jalur klaim.
- (c) **Status quo** (tolak temuan).

**Catatan IDOR/BOLA:** tidak ditemukan jalur IDOR/BOLA pada jalur assigned-only — kurir lain yang mengetahui ID order/shipment/bukti tetap ditolak 403 di semua endpoint yang diperiksa (detail di C.2.1). Potensi terbesar justru **celah desain claim queue** di atas.

---

### C.3 RECON-03 — COURIER-002: Riwayat terkirim Koordinator Kurir

**Status: CONFIRMED — fitur/permukaan belum ada.**

**Bukti:**
- Dispatch workspace hanya menampilkan baris dispatchable: `DispatchController::index` (`backend/app/Http/Controllers/Api/V1/Dispatch/DispatchController.php:43-88`) — shipment `delivery_mode = standard`, `status = 'pending'`, `whereNull('courier_id')`, `whereNull('self_delivered_by_user_id')`, dari order `diproses` (63-68). **Tidak ada mode/tab riwayat.** `DispatchView.vue` pun tanpa tab selesai (hanya antrean + filter + pagination inline 379-383).
- Tab "Selesai" di Pengiriman Saya (koordinator) hanya menampilkan pengiriman yang dieksekusi **sendiri**: `KurirDashboardView.vue:106-123` — `isExecutorOnly → listCourierOrders(page, 'terkirim')` (per-shipment milik profil Courier sendiri, `CourierDashboardController.php:85-92`).
- Endpoint laporan terkirim `GET /kurir/reports/delivered` **hanya `role:kurir`**: `backend/routes/api_v1.php:478-484` — koordinator tidak bisa memakainya.
- Daftar order generik `GET /orders?status=terkirim` **bisa** diakses koordinator (BelongsToAgentScope; filter finansial dihapus untuk non-financial roles — `OrderController.php:85-93`), dan `OrderHistoryView.vue` berfungsi untuk staf (filter status `terkirim` + search + region + pagination, baris 18-66). Menu "Order" tampil untuk koordinator (`navConfig.ts:70-80` — hanya mengecualikan kurir & gudang). **Tetapi** ini daftar order-level (status agregat order), bukan riwayat per-shipment dengan atribusi kurir + waktu + bukti.
- Pembedaan riwayat pribadi vs cabang sudah benar di kode (executor-only vs branch) — yang tidak ada adalah **permukaan riwayat cabang**.

**Atribusi "Kurir yang benar-benar menyelesaikannya":** karena reassignment hanya legal saat order `diproses` + shipment `pending` (`CourierService.php:91-96`), `shipments.courier_id` pada saat item mencapai `terkirim` ADALAH kurir pelaksana. `delivered_at` tersimpan per shipment (`shipments` tabel, `Shipment.php:22,47-48`); bukti pengiriman = `proof_media_id` (Media). Baris komisi kurir dibuat saat delivery dari executor tercatat — sumber atribusi kedua. Tidak ada log historis kurir per item, tapi tidak dibutuhkan karena pasca-pickup reassign mustahil via jalur resmi.

**Rancangan perbaikan (terkecil & aman):**
- Endpoint baru (mis. `GET /dispatch/history` atau `GET /shipments/delivered`): shipment `standard` + `self_sub` scope cabang koordinator yang punya item `terkirim`; kolom: order_no, kurir pelaksana (courier/self-delivery SKS), penerima (proyeksi operasional — tanpa nominal), status, `delivered_at`, bukti (`proof` media URL), filter (tanggal, kurir, region) + pagination + `?agent_id=` untuk super_admin (pola A1-18 `resolveSuperAdminAgentId`).
- UI: tab "Riwayat Terkirim" di DispatchView dengan link "Buka order" ke route existing (`openOrder`, `DispatchView.vue:197-199`).
- Proyeksi wajib operasional (tanpa DP/paid/remaining/fee) — koordinator bukan peran finansial (04-ROLES §3; `OrderResource.php:42-43`).

---

### C.4 RECON-04 — COURIER-003: Permission order terkirim untuk Koordinator

**Status: NOT REPRODUCED sebagai defek otorisasi — jalur baca sudah benar end-to-end; kebutuhan riil tercakup oleh COURIER-002.**

**Bukti (jalur baca TERBUKA untuk koordinator, tanpa gate status):**
- `backend/app/Policies/OrderPolicy.php:33-35` — `isRole('agen','admin','keuangan','koordinator-kurir')` → `agent_id === user->agent_id` (termasuk order `terkirim`; tidak ada status-based authorization di view).
- `backend/app/Http/Controllers/Api/V1/Order/OrderController.php:172-179` — `show()` hanya `authorize('view')`.
- `backend/app/Support/PermissionMap.php:62-64` — koordinator: `users.view.network`, `orders.view.network`, `orders.manage.shipment`.
- `frontend/src/router/index.ts:118-135` — `/orders` dan `/orders/:id` hanya `requiresAuth` (backend yang scoping).
- `frontend/src/dashboard/navConfig.ts:70-80` — menu "Order" tampil untuk koordinator.
- Proyeksi operasional tanpa uang: `OrderResource.php:42-43` (`seesFinancials` mengecualikan koordinator); uji regresi existing `R04OrderProjectionTest.php:289` (`test_koordinator_order_detail_keeps_operational_return_status_but_never_refund_amounts`).

**Pembedaan hak (semua sudah benar — tidak perlu perubahan permission):**

| Hak | Koordinator | Evidence |
|---|---|---|
| Melihat order (semua status, scope cabang) | ✅ ya | `OrderPolicy:33` |
| Mengubah order / naikkan status order-wide | ❌ 403 | route `role:super_admin,agen,admin` (`api_v1.php:283-285`); `OrderPolicy::updateStatus:185-192` |
| Mengubah status pengiriman | hanya sebagai executor tercatat | `ShipmentPolicy:50-57` + `CourierService:398-410` |
| Assign kurir | ✅ ya (dispatcher) | `ShipmentPolicy::assignCourier:63-70` |
| Informasi pembayaran / invoice | ❌ 403 | `InvoiceController:33-39`; `OrderResource` tanpa field uang |

"Jangan memperluas hak mutasi hanya untuk memberikan hak baca" — terpenuhi: tidak ada perubahan permission yang dibutuhkan. Yang hilang dari pengalaman UAT adalah **permukaan navigasi ke order terkirim** (COURIER-003 → solusinya di COURIER-002: tab riwayat dengan link detail).

---

### C.5 RECON-05 — PAYMENT-001: Tombol "Lihat Bukti Transfer" tidak bisa dibuka

**Status: PARTIAL — endpoint backend benar & teruji; defek di frontend.**

**Siklus file bukti (dipetakan penuh — 4 mekanisme akses file):**

| Mekanisme | Penyimpanan | Jalur akses frontend | Kondisi |
|---|---|---|---|
| Bukti transfer/kas **baru** (DP, pelunasan, cicilan transfer/cash) | disk **`local` privat** (`PaymentService.php:39-45,222,232,710-711`) | `GET /api/v1/payment-proofs/{id}` → blob → `window.open(objectUrl)` — **BROKEN** | Otorisasi ketat; popup/PWA; tanpa error surface |
| Bukti transfer **legacy** | disk `public` | `<a href>` URL publik | bekerja (`PaymentTransactionResource:31-36` cabang legacy) |
| Bukti **COD** | Media disk publik (`codPaymentProof->proof->url()`) | `<a href>` (`OrderDetailView:1608-1610`) | bekerja |
| **Bukti pengiriman** (shipment) | Media disk publik (`proof_media_id`) | `<a href>` (`OrderDetailView:1163-1164`; `OrderItemResource:66-69`) | bekerja |

**Bukti backend (otorisasi benar):** `PaymentController::proofFile` (`backend/app/Http/Controllers/Api/V1/Payment/PaymentController.php:115-140`):
- Diizinkan: super_admin; konsumen pemilik order; agen/admin/keuangan cabang sama; korsal/sales/SKS scope `payOnBehalf`. Kurir/gudang/koordinator → 403.
- Disk dipilih dari `proof_disk` (`local` vs `public`); file hilang → 404; `Cache-Control: private, no-store`.
- Pembebanan order tanpa global scope (menghindari null → 500 lintas cabang) — komentar 117-119.
- Uji existing: `MasterV2PaymentCoreTest.php:328-351` (privat + 403 untuk konsumen lain & agen cabang lain + URL API bukan storage publik) dan `:353-364` (legacy tetap terbaca).

**Akar masalah frontend (3 lapis):**
1. **Popup blocker / PWA standalone** — `frontend/src/api/payments.ts:73-78`:
   ```ts
   const { data } = await http.get<Blob>(proofUrl, { responseType: 'blob' })
   const objectUrl = URL.createObjectURL(data)
   window.open(objectUrl, '_blank', 'noopener')
   ```
   `window.open` dipanggil **setelah `await`** — user-gesture/transient activation sudah hangus → Chrome/Safari memblokir popup; di PWA standalone (aplikasi ini PWA ter-install) `window.open` sering tidak melakukan apa-apa. Bandingkan pola unduh invoice yang benar: `OrderDetailView.vue:390-400` — blob → `<a download>` → `click()` programatik (tidak terblokir, bekerja di PWA).
2. **Tanpa error handling** — call site template `OrderDetailView.vue:1496-1511` memanggil `openPaymentProof(...)` langsung tanpa try/catch → semua kegagalan (403/404/jaringan/mixed-content) **senyap**, tombol tampak "mati" ("tidak dapat diklik").
3. **URL absolut dari `url()`** — `PaymentTransactionResource.php:31-36` menghasilkan `url('/api/v1/payment-proofs/{id}')` absolut berbasis request yang dilihat Laravel. Di DEV aman (Vite proxy `changeOrigin:false` menjaga host). Di PRODUKSI single-domain di belakang proxy/CDN: **`bootstrap/app.php` tidak mengonfigurasi TrustProxies** (middleware 31-92 tanpa `trustProxies`) → skema bisa terbaca `http://` → URL `http://…` di-fetch dari halaman `https://…` → **mixed-content diblokir browser**. (Komponen produksi ini NOT VERIFIED — tidak bisa diperiksa tanpa akses produksi; perbaikan desain menghilangkan risikonya.)

**Catatan tambahan:** `proof_url` = `null` (button tidak dirender sama sekali) bila `proof_image_path` kosong — mis. cicilan channel transfer tanpa foto (installment `receipt_photo` hanya untuk cash, `PaymentController.php:67-88`); itu perilaku wajar, bukan bagian defect.

**Rancangan perbaikan (frontend-only, backend tak berubah):**
- `openPaymentProof`: bangun **URL relatif** sendiri dari `API_URL` (`${API_URL}/api/v1/payment-proofs/{id}` — id sudah tersedia via `bank_transfer_verification.id` + flag `proof_private`; jangan konsumsi URL absolut `url()`), fetch blob, lalu **anchor `<a download>` + click** (pola `downloadInvoicePdf`), revoke objectURL.
- Bungkus call site dengan try/catch + surface pesan error (pola `paymentActionError`/toast yang sudah dipakai view).
- Simetri: lakukan sama untuk semua titik bukti privat (saat ini hanya tombol transfer).

---

### C.6 RECON-06 — INVOICE-001: Logo resmi Prime pada invoice

**Status: CONFIRMED — tidak ada logo default; logo sepenuhnya opsional per konfigurasi.**

**Bukti:**
- `backend/app/Services/Invoice/InvoicePdfService.php:71-107` — `resolveLogoUrl()`: logo dirender **hanya** bila `logo_media_id` di-set DAN baris Media ada DAN di-upload agen cabang itu/super_admin (anti cross-agent) DAN MIME png/jpeg/gif DAN file ada → embed **base64 data URI** (DomPDF remote loading dinonaktifkan, `setOption('isRemoteEnabled', false)` baris 59 — desain aman untuk PDF offline). Selain itu → `null`.
- `backend/app/Services/Invoice/InvoiceConfigService.php:31` — DEFAULTS `logo_media_id => null` → **tanpa konfigurasi, invoice tanpa logo**.
- `backend/resources/views/pdf/invoice.blade.php:29` — `@if(!empty($logo_url)) <img … style="max-height:28px; float:right"> @endif` — tanpa fallback; ukuran hanya dibatasi tinggi (rasio mengikuti file; `resolveLogoUrl` sudah memvalidasi `getimagesizefromstring`).
- UI konfigurasi: `frontend/src/views/dashboard/InvoiceConfigView.vue:117` — input **angka `media_id` mentah** ("0 = tanpa logo") — tanpa pemilih media; UX mempersulit pengisian logo yang benar.
- Invoice adalah dokumen order-level tunggal (§29): DP/pelunasan/cicilan tidak membuat invoice utama baru; nomor = `orders.order_no`; snapshot versi di `order_invoice_snapshots`. Tidak ada lampiran invoice via email/WA (pemeriksaan services notification — invoice tidak dikirim sebagai attachment).
- **Aset logo resmi**: satu-satunya di repo = `design/assets/prime-logo-reference.png` (referensi desain lokal, BUKAN aset runtime backend). `frontend/public/icons/*` = ikon PWA derivatif favicon. Logo header storefront = `site.logoUrl` (website settings, runtime). Frontend `src/assets/brands/` hanya ikon sosial.

**Rancangan perbaikan:**
- Tetapkan `design/assets/prime-logo-reference.png` sebagai **sumber kanonis** (RECON tidak membuat logo baru): salin ke aset runtime backend (mis. `backend/public/assets/prime-logo.png` — atau embed dari path resource).
- `resolveLogoUrl()` → fallback ke aset default ketika config `logo_media_id` kosong/null (branch override tetap menang bila di-set dan valid).
- Blade: render fallback bila embed default gagal dimuat (teks nama perusahaan tetap ada di `.company` — sudah ada); pertimbangkan ukuran/rasio cetak (naikkan `max-height`, jaga rasio).
- (Opsional) perbaiki UX `InvoiceConfigView` memakai pemilih media.
- Multi-branch: default berlaku untuk semua cabang tanpa konfigurasi — konsisten dengan resolusi global row (`InvoiceConfigService::resolved`).

---

## D. Authorization Matrix — Kurir & Koordinator Kurir

| Jalur | Existing (kode) | Ketentuan Owner | Status |
|---|---|---|---|
| Kurir: order list generik | 403 (`OrderController:29`) | hanya order ditugaskan | ✅ |
| Kurir: order detail generik / direct URL | 403 (`OrderPolicy:109-113`) | tolak | ✅ (anti-IDOR) |
| Kurir: `/kurir/orders` antrean | `diproses` cabang terlihat semua kurir (proyeksi minimal) + `dikirim`/`terkirim` milik sendiri | hanya yang ditugaskan | ⚠️ **KONFLIK LOCKED §11** — perlu keputusan Owner |
| Kurir: claim shipment unassigned saat pickup | diizinkan (`selfAssignIfUnassigned`) | hanya via penugasan koordinator | ⚠️ idem |
| Kurir: progress shipment kurir lain | 403 `not_your_delivery` (`CourierService:416-425`) | tolak | ✅ |
| Kurir: `terkirim` tanpa bukti foto | 422 wajib bukti | bukti wajib | ✅ |
| Kurir: resi shipment orang lain | 403 (`ShipmentPolicy::printReceipt:105-114`) | tolak | ✅ |
| Kurir: file bukti pembayaran | 403 (`PaymentController:125-128`) | tanpa akses finansial | ✅ |
| Kurir: invoice PDF | 403 (`InvoiceController:37-39`) | tanpa akses finansial | ✅ |
| Kurir: retur unclaimed | terlihat semua kurir cabang; claim-on-first-action | hanya yang ditugaskan | ⚠️ claim model — keputusan yang sama |
| Reassignment/revocation | hanya order `diproses` + shipment `pending`; `courier_id` ditimpa + audit; pasca-pickup mustahil → akses lama dicabut, atribusi akurat | akses lama dicabut | ✅ |
| Multi-shipment | proyeksi per-item; sibling kurir lain difilter | konsisten | ✅ |
| Koordinator: view order cabang (semua status incl. terkirim) | diizinkan, proyeksi operasional tanpa uang | boleh lihat | ✅ |
| Koordinator: mutasi order / status / pembayaran / invoice | tolak (route+policy) | hanya baca | ✅ (jangan diperluas) |
| Koordinator: assign kurir / self-assign executor | ya (dispatcher) | ya | ✅ |
| Koordinator: riwayat terkirim cabang | **TIDAK ADA** permukaan | boleh lihat scope cabang | ❌ GAP = COURIER-002 |
| Isolasi antar-Agen | global scope + policy + uji | tanpa kebocoran | ✅ |

---

## E. File Change Plan

### MODIFY
| File | Temuan | Alasan |
|---|---|---|
| `frontend/src/views/dashboard/UserManagementView.vue` | 01 | Kontrol halaman (prev/next), per_page 10/25/50/100, search, filter role+status, total record, AgentPicker super_admin; perbaiki kandidat reassign (loop semua halaman, bukan halaman 1) |
| `frontend/src/api/users.ts` | 01 | Kirim `per_page`, `status`, search/role yang ada |
| `backend/app/Http/Controllers/Api/V1/User/UserController.php` | 01 | Filter `status`, `orderBy` eksplisit stabil (mis. `latest('id')`), clamp `per_page` ≤ 100 |
| `backend/app/Http/Controllers/Api/V1/Courier/CourierDashboardController.php` | 02 *(kondisional)* | Sempitkan antrean kurir sesuai opsi keputusan Owner (assignment-only) |
| `backend/app/Services/Order/CourierService.php` | 02 *(kondisional)* | Perlakukan `selfAssignIfUnassigned` sesuai keputusan claim model |
| `backend/app/Http/Controllers/Api/V1/Dispatch/DispatchController.php` + `backend/routes/api_v1.php` | 03 | Endpoint riwayat terkirim scope cabang (per-shipment: kurir pelaksana, `delivered_at`, bukti, penerima; filter tanggal/kurir/region; pagination; `?agent_id=` super_admin) |
| `frontend/src/views/dashboard/DispatchView.vue` + `frontend/src/api/dispatch.ts` | 03 | Tab "Riwayat Terkirim" + link "Buka order" ke route existing |
| `frontend/src/api/payments.ts` | 05 | `openPaymentProof` → URL relatif dari `API_URL` + pola anchor-download |
| `frontend/src/views/OrderDetailView.vue` | 05 | Try/catch + surface error pada tombol Lihat Bukti Transfer |
| `backend/app/Services/Invoice/InvoicePdfService.php` | 06 | Fallback logo default saat `logo_media_id` kosong |
| `backend/resources/views/pdf/invoice.blade.php` | 06 | Render fallback logo; ukuran/rasio cetak |
| `frontend/src/views/dashboard/InvoiceConfigView.vue` | 06 *(opsional)* | Pemilih media logo, bukan input angka |

### ADD
| File | Temuan | Alasan |
|---|---|---|
| `backend/public/assets/prime-logo.png` (disalin dari `design/assets/prime-logo-reference.png` — tanpa membuat logo baru) | 06 | Aset runtime logo resmi |
| `frontend/src/components/ui/PaginationControls.vue` *(opsional; atau konsisten pola inline DispatchView)* | 01 | Navigasi halaman konsisten |

### TEST
| File | Temuan | Cakupan baru |
|---|---|---|
| `backend/tests/Feature/UserManagementTest.php` (extend) | 01 | per_page/meta/total, filter status, sort stabil antar halaman, search+filter sebelum pagination |
| `backend/tests/Feature/CourierAssignmentIsolationTest.php` (baru) atau extend `CourierSystemTest.php` | 02 *(kondisional)* | Antrean assignment-only; kurir lain 403 di mutasi; revocation mencabut akses; direct-URL 403 (existing); bukti pembayaran 403 untuk kurir/koordinator |
| `backend/tests/Feature/CoordinatorDeliveredHistoryTest.php` (baru) atau extend `DispatchTest.php` | 03 | Riwayat terkirim: scope cabang, atribusi kurir pelaksana, `delivered_at`, bukti, tanpa data finansial, filter+pagination, super_admin `?agent_id=` |
| `backend/tests/Feature/R04OrderProjectionTest.php` (extend) | 04 | Eksplisit: koordinator membuka detail order `terkirim` → 200 proyeksi operasional; mutasi → 403 (sebagian besar sudah tercakup baris 289) |
| `backend/tests/Feature/MasterV2PaymentCoreTest.php` (extend) | 05 | 403 kurir/gudang/koordinator di `/payment-proofs/{id}`; 404 file hilang (sebagian sudah tercakup 328-351) |
| `backend/tests/Feature/InvoiceTest.php` (extend) | 06 | Logo default ter-render saat tanpa config; fallback teks saat aset hilang; rasio ukuran |

Frontend: tidak ada runner — bukti = `npm run type-check` + `npm run build-only` + verifikasi browser manual (07-FRONTEND-GUIDE §10).

---

## F. Implementation Order (disarankan)

1. **Keamanan Kurir (SEC-COURIER-001)** — *prasyarat: keputusan tertulis Owner atas claim model LOCKED §11* → ubah `CourierDashboardController::orders` + `selfAssignIfUnassigned` + `returns` sesuai opsi terpilih; regression test.
2. **Permission & riwayat Koordinator (COURIER-002/003)** — endpoint riwayat terkirim + tab UI (otorisasi detail sudah benar; tanpa perubahan permission).
3. **Protected payment proof (PAYMENT-001)** — perbaikan pola buka file + error surface di frontend (backend tak berubah).
4. **Pagination Pengguna (UI-USER-001)** — frontend + 3 penyesuaian backend kecil.
5. **Branding invoice (INVOICE-001)** — aset default + fallback + test.
6. **Scoped tests + verifikasi UI** — per area berubah; frontend type-check/build; `git diff --check`; UAT DEV manual → stage gate Owner.

---

## G. Risk & Blockers

1. **BLOCKER (satu-satunya, nyata): SEC-COURIER-001 bertentangan dengan aturan LOCKED.** `docs/05-BUSINESS-RULES.md` §11 (baris 107) mengunci model klaim: *"Kurir boleh claim shipment `diproses` tanpa assignment"*, dan antrean diproses-semua-kurir adalah desain R-04 yang teruji (`R04OrderProjectionTest:188`). Ketentuan Owner baru memerlukan persetujuan eksplisit perubahan aturan bisnis sebelum implementasi (AGENTS.md §2 — jangan memilih diam-diam). Opsi (a) assignment-only penuh / (b) assignment-only visibility saja / (c) status quo — beserta konsekuensi operasional (order tanpa penugasan tidak ter-pickup; alur retur unclaimed mengecil).
2. Riwayat terkirim koordinator wajib proyeksi operasional (tanpa nominal) — jangan membuka permukaan finansial `OrderResource` untuk koordinator.
3. PAYMENT-001: komponen produksi mixed-content (proxy tanpa TrustProxies) **NOT VERIFIED** — tidak dapat diverifikasi tanpa akses produksi (dilarang RECON ini). Perbaikan desain (URL relatif + anchor-download) menghilangkan risiko tersebut sekaligus.
4. INVOICE-001: keputusan kecil Owner — kanonisasi `design/assets/prime-logo-reference.png` sebagai sumber logo resmi (tidak membuat logo baru; aset runtime disalin dari file itu).
5. Frontend tanpa test runner — verifikasi perilaku tombol/pagination murni browser-manual.
6. `docs/PRIMECLASSY-WA-MGMT-KMSIT-OSRM-CHECKPOINT.md` ter-modifikasi di working tree (milik Owner, G-15) — jangan tersentuh/jangan ikut ter-commit oleh remediasi.

Cross-cutting yang diperiksa dan tidak menuntut perubahan permission: 11 role terkunci; isolasi antar-Agen; REL005/006 (penerima = pembeli & keuangan cabang — kurir bukan penerima); DP/parsial (`PaymentSummaryService` + `PaymentService` single-writer); multi-shipment (§11 grouping); retur/refund (alur claim koordinator tidak menyentuh uang); navigasi frontend existing; PermissionMap. Temuan di luar enam scope tidak ditemukan signifikan selama inspeksi jalur terkait.

---

## H. Readiness Verdict

**READY WITH CONDITIONS.**

Lima temuan (UI-USER-001, COURIER-002, COURIER-003, PAYMENT-001, INVOICE-001) memiliki akar masalah teridentifikasi dengan bukti path:line, rancangan perbaikan bounded, dan titik test yang jelas — siap dieksekusi dalam satu rangkaian remediasi tanpa audit ulang.

**SATU kondisi eksplisit sebelum work-package keamanan kurir (SEC-COURIER-001):** keputusan tertulis Owner mengubah/mempertahankan LOCKED §11 claim model (visibility `diproses`, klaim mandiri, retur unclaimed) — pilih opsi (a)/(b)/(c) di atas. Tanpa itu, perubahan apa pun di `CourierDashboardController`/`CourierService` melanggar kontrak governance AGENTS.md §2.

COURIER-003 terkonfirmasi **bukan defek otorisasi** (akses sudah benar & teruji) — kebutuhannya tercakup solusi COURIER-002.

---

## I. Test Inventory (existing yang relevan — tidak dijalankan saat RECON)

| Test existing | Relevan untuk | Sudah menutup |
|---|---|---|
| `UserManagementTest.php:283-311, 315-327` | 01 | role/search/scoping listing; **belum** pagination/meta/status |
| `R04OrderProjectionTest.php:164, 188, 216, 256, 273, 289, 325, 351` | 02/04 | kurir ditolak list/detail; proyeksi minimal pra-claim; anti-bocor sibling; lintas-Agen; proyeksi koordinator; capability invoice |
| `SalesCourierOrderIsolationTest.php:101-140` | 02 | scoping SKS sebelum filter+pagination |
| `CourierSystemTest.php`, `CourierItemProgressionTest.php` | 02 | klaim, per-item progress, bukti wajib |
| `ShipmentReceiptPrintTest.php` | 02 | resi own-only |
| `DispatchTest.php`, `DispatchRemediationTest.php`, `DispatchAssignmentConcurrencyTest.php`, `DispatchDateFilterTest.php`, `DispatchRegionFilterTest.php` | 03 | dispatch queue + assignment concurrency; **belum** riwayat terkirim |
| `KoordinatorAccessTest.php:60-91` | 02/03 | permukaan koordinator; **belum** riwayat terkirim |
| `MasterV2PaymentCoreTest.php:328-375` | 05 | proofFile otorisasi positif/negatif + legacy + batas 5 MiB; **belum** 403 kurir/gudang/koordinator spesifik |
| `PaymentProofConcurrencyTest.php` | 05 | concurrency verifikasi bukti |
| `InvoiceTest.php:69-239`, `InvoiceIssuanceConcurrencyTest.php`, `InvoiceNumberTest.php` | 06 | gate role invoice, toggle, logo same-branch anti cross-agent; **belum** logo default |
| `CrossAgentIsolationTest.php`, `PermissionMapTest.php` | 02/04 | isolasi antar-Agen, kontrak permission hint |

---

## J. EXACT NEXT ACTION (untuk remediasi)

1. Owner memutuskan claim model §11 (opsi a/b/c di §G.1) — tulis persetujuan di checkpoint remediasi.
2. Implementer memulai dari urutan §F pada branch remediasi baru per kebijakan Git discipline (AGENTS.md §14), membaca laporan ini + `git status` (pastikan file checkpoint Owner tidak ikut ter-commit).
3. Item pertama yang bisa dikerjakan tanpa menunggu keputusan §11: **PAYMENT-001 (frontend-only)**, lalu **UI-USER-001**.

---

*Dokumen ini dibuat dari RECON read-only satu-pass pada 2026-11-08. Semua klaim CONFIRMED disertai bukti path:line dari source pada HEAD `e738d0f`. Tidak ada nilai secret, token, kredensial, atau data pelanggan yang disertakan.*
