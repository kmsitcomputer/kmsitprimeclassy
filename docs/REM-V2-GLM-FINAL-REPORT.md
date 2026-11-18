# PRIMECLASSY V2 — GLM FINAL REMEDIATION

**Agent:** GLM (dilanjutkan setelah Claude Sonnet ×2 dan OpenAI Codex ×1)
**Tanggal:** 12 November 2026 (Asia/Jakarta)
**Repository:** `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy`
**Branch:** `fix/transaction-reset-financial-dependencies` (tidak berubah)
**HEAD:** `6a14041c06e6258c9f011fcb11271c7d34d65d17` (tidak berubah — **tidak ada commit**)
**Mode:** Implementasi lokal terarah + verifikasi ter-scope
**Authority:** Owner Architecture Lock 11 November 2026 (`docs/architecture-lock/2026-11-11/`)

---

## 1. Executive verdict

**PARTIAL overall.** Dua sisa Prioritas A diselesaikan dan diverifikasi: **REM-02** (storefront variant gallery switching) dan **REM-06** (editor hierarki kategori end-to-end: backend + frontend + test). REM-03 dan REM-04 diaudit ulang tanpa defect baru; REM-04 dinyatakan PASS. Seluruh pekerjaan Claude/Codex sebelumnya terverifikasi utuh (manifest Architecture Lock 15/15 hash, logo resmi hash cocok, regression backend **120 test / 971 assertion hijau**, frontend **33 test + type-check + build hijau**). **REM-11 tetap BLOCKED dan admission dinonaktifkan (fail closed)** karena kontrak evidence/immutability, pengakuan finansial historis, dan rekonsiliasi Finance (D-04) tidak dapat dibuktikan dari source — membangunnya tanpa bukti berarti mengarang aturan finansial yang dilarang misi dan Owner Lock. **REM-10 visual acceptance tetap BLOCKED** (tidak ada mockup final terverifikasi di repo). POS UI historis tidak dibangun (mockup final tidak ada + admission nonaktif).

Tidak ada commit, push, merge, deploy, migrasi DEV/production, atau Full Test. Frontend diverifikasi di container Node terisolasi; backend hanya di `primeclassy_testing`.

| REM | Verdict awal (Codex) | Verdict GLM | Catatan |
|---|---|---|---|
| REM-01 | PASS | **PASS — IMPLEMENTED & VERIFIED** | Tidak diubah; regression re-run hijau. Preflight produksi (privilege TRIGGER/binlog) tetap tugas Owner. |
| REM-02 | PARTIAL | **PASS — IMPLEMENTED & VERIFIED** | Storefront gallery switching diimplementasikan + 5 component test. UAT browser manual tersisa untuk Owner. |
| REM-03 | PARTIAL | **PARTIAL** | Audit ulang: tidak ada defect terbukti; alur save tidak menyentuh gambar. UAT browser belum diklaim. |
| REM-04 | PARTIAL | **PASS — IMPLEMENTED & VERIFIED** | Guarded hard delete + denial Admin + proteksi referensi historis teruji; hard delete varian tidak diperluas. |
| REM-05 | PASS | **PASS — IMPLEMENTED & VERIFIED** | Tidak diubah; regression hijau. |
| REM-06 | PARTIAL | **PASS — IMPLEMENTED & VERIFIED** | Frontend hierarchy editor (parent select, indentasi, reparent anti-cycle, error server) + 6 component test; backend + concurrency test Codex dipertahankan. |
| REM-07 | PASS | **PASS — IMPLEMENTED & VERIFIED** | Tidak diubah; regression hijau. |
| REM-08 | PASS | **PASS — IMPLEMENTED & VERIFIED** | Tidak diubah; regression hijau. |
| REM-09 | PASS | **PASS — IMPLEMENTED & VERIFIED** | Tidak diubah; regression hijau. |
| REM-10 | PARTIAL | **PARTIAL** | Logo resmi hash cocok; identitas maroon dipertahankan; visual acceptance BLOCKED (referensi final tidak ada). |
| REM-11 | BLOCKED | **BLOCKED** | Fondasi identity kanonis terverifikasi utuh; preflight read-only fail-closed; admission/evidence/review/POS UI tidak diimplementasikan (alasan §5). |

## 2. Baseline dan file yang berubah

### 2.1 Baseline audit (RECON ONCE)

- Branch `fix/transaction-reset-financial-dependencies`, HEAD `6a14041c…` — sama dengan baseline laporan Codex; **HEAD tidak digeser**.
- Working tree tetap dirty berisi seluruh implementasi Claude + Codex (tidak ada reset/clean/stash/checkout).
- Laporan Codex ditemukan dan dibaca: `docs/REM-V2-CODEX-FINAL-REPORT.md`; laporan Claude: `docs/REM-V2-IMPLEMENTATION-REPORT.md` (superseded-notice Codex dipertahankan).
- Architecture Lock dibaca: `README.md`, `01-ARCHITECTURE/01-OWNER-DECISION-REGISTER-LOCKED.md` (REM-01–11, ODR-01–05, D-01–07 seluruhnya OPTION A, FINAL), `02-DOMAIN-ARCHITECTURE-AND-ACCEPTANCE.md`, `RECON-CLOSURE-HISTORICAL-POS.md` (addendum paritas POS–CO).
- `MANIFEST-SHA256.json` diverifikasi ulang secara independen: **15/15 file hash SHA-256 cocok**.

### 2.2 Perubahan GLM (round ini — hanya frontend, additive, tidak ada perubahan backend/migrasi/lock)

| File | Jenis | Isi |
|---|---|---|
| `frontend/src/views/ProductDetailView.vue` | Edit (3 titik) | REM-02: `galleryImages` computed (filter per varian + fallback parent gallery), watch reset index saat set galeri berubah, thumbnail memakai `galleryImages`, thumbnail baris cart mengikuti kontrak galeri varian yang sama. |
| `frontend/src/views/dashboard/ProductManagementView.vue` | Edit (3 titik) | REM-06: baris kategori tree-order (DFS pre-order, cycle-safe untuk data legacy), select induk per baris (mengecualikan diri + subtree), select induk saat create, panel error kategori terpisah (`categoryError`), reload daftar saat gagal reparent, hint anti-siklus. |
| `frontend/src/views/__tests__/ProductDetailView.gallery.spec.ts` | **Baru** | 5 focused component test REM-02. |
| `frontend/src/views/dashboard/__tests__/ProductManagementView.categories.spec.ts` | **Baru** | 6 focused component test REM-06. |

File yang tidak disentuh: seluruh `backend/`, `docs/architecture-lock/`, migrasi, `frontend/dist`, `node_modules`.

## 3. Verifikasi pekerjaan Claude/Codex (independen, bukan trust laporan)

| Klaim | Verifikasi GLM |
|---|---|
| REM-01 stock revision/ABA (trigger + token) | Migration `2026_11_14_110000_add_physical_stock_revision.php`, `PhysicalStockRevision.php`, integrasi `StockOpnameService` ada; regression re-run hijau (§10). |
| REM-05 filter default Semua | `ProductManagementView.vue:36-42` — `statusFilter = 'all'`, 4 opsi tetap. Regression hijau. |
| REM-07 bestseller backend + CMS | `BestsellerController.php`, `Services/Catalog/Bestseller*`, `HomepageBlockTypes` `bestseller`, renderer + form CMS ada; `BestsellerBlock.spec` + `CmsHomepage*Test` hijau. |
| REM-08 variant editor | `ProductFormView.variants.spec` (3 test) hijau — hanya varian terpilih yang dikirim, sibling/galeri terisolasi. |
| REM-09 source-aware catalog/cart/checkout | `CatalogStockSource.spec`, `CheckoutView.stockSource.spec`, `orderStockSource.spec`, `SubStockSourceTest` hijau. |
| REM-11 fondasi identity kanonis | `Order.php:15` WRITE_ONCE fields; migration `2026_11_14_120000` (nullable + `orders_historical_source_unique`); route `routes/api_v1.php:350-351` preflight read-only. |
| REM-10 logo resmi | `shasum -a 256 backend/resources/branding/prime-logo.png` = `e2d724eef81645aee42328cc89b8f1a5ff3cd140cf92e70731a00fde26b552d3` — **cocok** dengan referensi Lock. |
| REM-10 identitas maroon invoice | `invoice.blade.php:10,11,15` memakai `#8B2635` (3 titik), tidak ada `#7c3aed`. |
| Media mutable / tidak ada evidence immutable | `MediaService.php:61,86,92` — `replace/delete/attachTo` mutabel, tanpa store immutable ber-hash. ✓ klaim Codex. |
| Commission tanpa payout writer | `CommissionFinalizer.php:12-14` — payout di luar aplikasi, tidak ada writer. ✓ |
| Payment verify = uang bergerak | `PaymentService.php:386` `verifyBankTransfer` menerapkan uang kanonis; replay untuk penerimaan historis berisiko recognition ganda. ✓ |
| Report = proyeksi live, tanpa register issued/closed | `OrderInitialSnapshotService`/`OrderInitialReportService`/`OrderPaymentPositionReportService` ada; grep "issued/closed" di dua reader: tidak ada state period. ✓ |
| Preflight fail-closed | `HistoricalDossierPreflight.php:179-187` — `persisted=false`, `admission_available=false`, 4 `admission_blockers` eksplisit. ✓ |

## 4. Prioritas A — REM-02 / REM-03 / REM-04 / REM-06

### 4.1 REM-02 — Variant Gallery (PASS)

**Implementasi** (`frontend/src/views/ProductDetailView.vue`):

- Kontrak backend existing dipakai tanpa perubahan API: `ProductImageResource` sudah mengekspos `product_variation_id`; scope galeri = (produk, varian-or-null) per `ProductImageController` (`scope()` line 94–98), galeri parent dan tiap varian independen.
- `galleryImages` (computed): varian terpilih → gambar milik varian itu (`product_variation_id === variation.id`); jika varian tidak punya gambar → fallback galeri parent (`product_variation_id === null`); tanpa seleksi → daftar penuh (perilaku lama). Galeri varian lain tidak pernah tersentuh — murni filtering display, **tanpa mutasi baris gambar**.
- Anti-stale: watch pada identitas set galeri mereset `activeImageIndex` ke 0 setiap galeri berubah (ganti varian / reload produk) — offset lama tidak pernah dipakai lintas galeri; guard load existing (`createLatestRequestGuard`) tetap memegang stale-response antar request produk.
- Thumbnail baris cart (`addToCart`) mengikuti kontrak yang sama (primary galeri aktif → fallback) sehingga gambar baris konsisten dengan viewer.

**Test:** `ProductDetailView.gallery.spec.ts` — 5 test: (1) varian auto-terpilih menampilkan galeri miliknya, bukan parent/sibling; (2) ganti varian mereset index viewer; (3) fallback ke galeri parent; (4) empty state saat varian tanpa gambar sendiri maupun parent; (5) thumbnail cart baris mengikuti galeri varian. Semua hijau (§10).

### 4.2 REM-03 — Product Management Image Save (PARTIAL — tanpa defect terbukti)

Audit alur wajib di source (tidak ada perubahan kode — tidak ada defect):

- **Edit tanpa mengubah gambar:** payload `updateProduct`/`createProduct` (`ProductFormView.vue:343-373`) hanya berisi field teks/kategori/status/stock — **tidak ada field images**; test REM-08 existing juga menegaskan "unchanged save omits attributes and image mutation fields".
- **Tambah/hapus/primary:** via endpoint dedicated langsung (`uploadProductImage`, `deleteProductImage`, `setPrimaryProductImage`) yang di-backend scoped per galeri dengan demote primary hanya di galeri yang sama (`ProductImageController::store/update`) — galeri varian lain tidak terdampak.
- **Simpan & reload:** detail management dimuat via `getManagedProduct` (diuji spec REM-08); tidak ada jalur yang bisa menghapus/duplikasi gambar pada save form.

Batas: UAT browser manual (tambah gambar → hapus → primary → simpan → reload) belum diklaim; automated hanya menyentuh kontrak payload + aksi endpoint. **Verdict tetap PARTIAL** menunggu UAT Owner, sesuai aturan "perbaiki hanya defect yang terbukti".

### 4.3 REM-04 — Hard Delete (PASS)

- Keputusan Owner diaudit: Admin tidak boleh hard delete — `ProductPolicy::delete` existing; test `CatalogRemediationTest::test_hard_delete_is_guarded_denied_for_admin_and_blocked_by_references` (line 223) menguji denial + blokir oleh referensi historis.
- `ProductHardDeleteService` menghapus produk unreferenced dengan varian/gambar/SKU + pembersihan file setelah commit + `activity_logs` `product.hard_deleted` (test line 241).
- Soft delete tetap jalur terpisah (`test_soft_delete_remains_distinct_and_agen_may_hard_delete_unreferenced`, line 261).
- **Tidak ada perluasan hard delete ke varian** (tidak diotorisasi Lock). Regression re-run hijau (§10).

### 4.4 REM-06 — Category Hierarchy (PASS)

**Backend (Codex, dipertahankan):** `CategoryHierarchyService` — serialisasi lock seluruh tree (`lockTree`), parent harus ada, parent ≠ diri sendiri, parent bukan turunan (anti-cycle), pesan validasi Indonesia; `CategoryHierarchyConcurrencyTest` (re-parent dua koneksi) hijau.

**Frontend (GLM, baru):**

- **Parent selection saat create:** select "Induk kategori baru" (default "Tanpa induk (utama)") → payload `createCategory({ name, parent_id })`.
- **Hierarchy display:** baris disusun DFS pre-order dari root, anak menjorok `1.5rem` per level; baris ter-strand oleh data legacy (loop) tetap dirender (cycle-safe).
- **Reparent inline:** setiap baris punya select induk berisi semua kategori **kecuali dirinya dan seluruh turunannya** (anti-cycle sisi klien); label opsi berindentasi agar terbaca; perubahan mengirim `updateCategory(id, { parent_id })`.
- **Server-side validation authoritative:** saat server menolak (race cycle / parent hilang), pesan API ditampilkan di panel kategori (`categoryError`), daftar di-reload sehingga select kembali menunjukkan parent tersimpan — tidak ada state liar.
- **Tidak ada redesign navigasi** — panel kategori di halaman manajemen yang sama, gaya visual existing dipertahankan.

**Test:** `ProductManagementView.categories.spec.ts` — 6 test: urutan tree + parent tersimpan tampil + indentasi benar; opsi parent mengecualikan diri+subtree (root hanya "Tanpa induk"; leaf punya semua opsi valid); reparent via API + refresh; pesan server + restore saat gagal; create dengan parent + reset form; error create dengan input terpelihara. Semua hijau (§10).

## 5. Prioritas B — REM-11 Historical POS (BLOCKED)

### 5.1 Fondasi kanonis (terverifikasi utuh, dipertahankan)

- Field nullable di `orders` + unique `(agent_id, historical_origin, historical_source_reference)` — migration `2026_11_14_120000_add_historical_identity_to_orders.php`; `Order.php` WRITE_ONCE + tidak mass-assignable (diuji `HistoricalPreflightTest::test_canonical_historical_identity_is_nullable_typed_and_not_mass_assignable`).
- Tidak ada tabel POS paralel; tidak ada Order domain baru. **Posisi ini benar dan dipertahankan.**

### 5.2 Evidence & independent review — TIDAK diimplementasikan (alasan)

Misi §5.2 meminta mekanisme: evidence privat, hash immutable, reviewer terotorisasi, verifikasi independen, binding dossier, audit trail. Setelah audit domain, **kontrak ini tidak dapat dibangun tanpa mengarang aturan finansial/otoritas**:

1. **Evidence immutable tidak ada basis aman:** `MediaService::replace/delete/attachTo` (line 61/86/92) mutabel; disk default + `MediaPolicy` tidak memenuhi pre-admission private-scoped. Membangun store evidence baru = desain domain finansial baru yang menurut laporan Codex dan Owner Lock memerlukan kontrak teknis yang direview — bukan keputusan agent.
2. **Reviewer terotorisasi tidak dapat diturunkan:** D-02 hanya mengunci "verifikasi independen terotorisasi" untuk payment existing (`keuangan`); authority untuk **review dossier historis / period-close / settlement** tidak ada di 11-role matrix. Menunjuk role = mengarang otoritas (dilarang misi §5.7 "Do not invent… approval authorities").
3. **D-04 Finance semantics unproven** (detail §7): tidak ada register issued/closed, tidak ada approved opening treatment, tidak ada bukti settlement eksternal. Misi sendiri menyatakan: *"If critical Finance semantics remain unproven, keep admission disabled for affected cases and report exact missing evidence"* — dan ini berlaku untuk **semua** kasus admission.

### 5.3 Historical admission — dinonaktifkan (fail closed)

Preflight (`HistoricalDossierPreflight::run`) sudah memvalidasi dan diuji: business date historis-only (masa lalu tak terbatas, **future rejected** — line 45–47), Agent valid (authority matrix diuji test line 125), requester independen dari consumer opsional, recipient independen, D-05 canonical price mismatch **ditolak tanpa override** (line 91–92), D-01 klasifikasi stok (unknown → block; not-reflected → koreksi sebelum admission), D-02 bukti payment, D-03 partial external settlement **blocked** (line 157–161), fingerprint dossier, SKU alias kanonis, item SKU berulang ditolak.

Namun admission **tetap `admission_available=false`** dan `persisted=false` (line 179–187) — posisi ini **dipertahankan dan benar**: menjalankan `OrderService::createOrder`/`PaymentService::verifyBankTransfer`/`CommissionFinalizer` untuk "mengakui" transaksi historis akan menciptakan efek nyata baru (reserve, cash recognition, fee) — persis yang dilarang ODR-05 dan D-02/D-04. Tidak ada fake approval, tidak ada Order karantina, tidak ada replay checkout.

### 5.4 D-01 — Stock

- Already reflected → tidak ada deduksi kedua: benar secara desain karena admission nonaktif (tidak ada penulis stok sama sekali); claim koreksi sekali-pakai (one-use) belum diimplementasikan dan **tidak dibuat** tanpa kontrak evidence.
- Not reflected → koreksi terotorisasi sebelum admission: jalurnya adalah opname/approval existing (REM-01); binding klaim koreksi ke dossier belum ada.
- Unknown → block: teruji (`stock_corrections_required_before_admission` + blocker).

### 5.5 D-02 — Payment

- Bukti standar + verifikasi independen: rule PaymentService existing utuh; preflight memvalidasi reference/amount/date sebagai **klaim**, bukan kredit.
- Actual received date vs verification date: penyimpanan terpisah tersedia di level desain (`historical_*` + tanggal preflight), writer recognition belum ada — dan tidak dibangun (lihat 5.2).
- No fresh cash recognition: `verifyBankTransfer` tidak dipanggil untuk penerimaan historis; tidak ada canonical credit ganda karena tidak ada credit sama sekali.

### 5.6 D-03 — Commission

- Full external settlement hanya dengan evidence lengkap: preflight menolak `full_external` tanpa `evidence_ref`/`settled_on`; `partial_external` diblok.
- Unpaid tetap liability; tidak ada payout fiktif: `CommissionFinalizer` tanpa payout writer, tidak dipanggil untuk historis.

### 5.7 Historical POS UI — tidak dibangun (BLOCKED, keputusan sadar)

Design Lock mensyaratkan: Modern Dark Maroon, two-column standalone tanpa sidebar, paritas presentasi item-list/summary dengan Checkout dan Order History. **Mockup final terverifikasi tidak ada** — aset `architecture-lock/2026-11-11/03-DESIGN-REFERENCES/POS/` hanyalah kandidat ungu bertanda `NOT-VERIFIED-FINAL` (README Lock line 5–8); kandidat ungu dilarang disubstitusi. Misi §5.9 memperbolehkan "implement only visual-independent contracts" bila mockup tidak tersedia — namun: (1) kontrak presentasi bersama POS–CO masih status **PROPOSED** di addendum Lock (belum implementasi terautorisasi); (2) admission nonaktif berarti UI tidak punya workflow yang bisa diuji end-to-end. Membangun scaffolding UI spekulatif berisiko melanggar "jangan mengarang desain" dan menghasilkan dead code. **UI tetap BLOCKED; tidak ada komponen visual yang dibuat dan tidak ada klaim parity.**

## 6. D-01–D-07 compliance

| Decision | Status | Bukti / batas |
|---|---|---|
| D-01 A | Terjaga | Preflight klasifikasi per-item; unknown block; koreksi sebelum admission; tidak ada deduksi ganda (tidak ada penulis stok historis). Claim-once belum diimplementasikan (admission nonaktif). |
| D-02 A | Terjaga | Bukti standar existing; verifikasi independen utuh; tidak ada attestation substitusi; tidak ada cash recognition baru untuk uang sudah-diterima. |
| D-03 A | Terjaga | Partial external settlement diblok preflight (line 157–161); unpaid tetap liability; tidak ada payout fiktif. |
| D-04 A | Terjaga (by not acting) | Tidak ada restatement otomatis; snapshot report existing tidak diubah; business date vs recording date tersimpan terpisah pada fondasi kanonis; **tidak ada duplicate receivable/cash karena tidak ada recognition sama sekali**. Rekonsiliasi aktif belum diimplementasikan (§7). |
| D-05 A | Terjaga | `price_mismatch_with_canonical` blocker tanpa override (preflight line 91–92); harga server kanonik tetap satu-satunya sumber. |
| D-06 A | Terjaga | Bestseller memakai `order_item_receipts` aktual per item + alokasi return diterima sekali (regression hijau); fakta historis tidak masuk ranking (admission nonaktif). |
| D-07 A | Terjaga | Publikasi baris katalog tetap atomic dalam approval opname; stale/ABA → 409; replay approved tidak publish dua kali (regression hijau). |

## 7. D-04 — Finance reconciliation (batas eksak)

**Terverifikasi dari source:**

- `OrderInitialSnapshotService::capture` menulis snapshot awal sekali di checkout; `OrderInitialReportService::query` proyeksi + kolom terkunci (Laporan A); `OrderPaymentPositionReportService::query` sengaja live; export Sheets = proyeksi one-way; invoice punya snapshot versinya sendiri.
- Tidak ada register issued/closed report (grep negatif), tidak ada period lock, tidak ada record rekonsiliasi Finance, tidak ada approved opening balance mechanism.

**Implikasi untuk admission historis (mengapa dinonaktifkan):**

1. Tidak dapat dibuktikan sumber historis mana yang **sudah** terwakili di catatan finansial eksternal/issued — mengklaimnya = menebak.
2. Tidak ada "actual approved opening treatment" yang bisa direferensikan tanpa inventing.
3. `keuangan` memiliki authority verifikasi payment existing — tidak sama dengan procedure period-close/reopen atau settlement komisi yang terimplementasi.
4. Membuat field nullable "approved" sembarang tidak membuktikan invariant (kesimpulan Codex — diverifikasi dan setuju).

**Keputusan GLM:** admission disabled untuk seluruh kasus; tidak ada komponen Finance yang diubah; tidak ada pertanyaan re-approve D-04 (sesuai misi). Missing evidence eksak tercantum di §12.

## 8. REM-10 — Invoice

- Logo resmi: `backend/resources/branding/prime-logo.png` — SHA-256 diverifikasi ulang langsung: **cocok** (`e2d724eef81645aee42328cc89b8f1a5ff3cd140cf92e70731a00fde26b552d3`). Logo tidak diganti; tidak ada logo buatan.
- Identitas putih/maroon dipertahankan (`#8B2635` di 3 titik header/company/th; tanpa sisa ungu).
- Kalkulasi finansial kanonik tidak disentuh; `InvoiceOfficialLogoTest` regression hijau.
- **BLOCKED:** paritas visual browser/print/PDF final vs referensi ter-approve — referensi final tidak ada di repo (kandidat invoice = unverified candidate). Tidak ada klaim layout parity.

## 9. Historical POS UI status

**BLOCKED — tidak diimplementasikan.** Alasan eksak: (1) mockup final approved tidak ditemukan (kandidat ungu bertanda NOT-VERIFIED-FINAL; spec dashboard generik bukan substitusi — Lock README line 5–8); (2) admission nonaktif sehingga tidak ada workflow untuk UI; (3) kontrak presentasi bersama POS–CO masih PROPOSED di addendum Lock. Kontrak visual-independent tidak dibangun spekulatif. Tidak ada komponen baru, tidak ada route baru, tidak ada navigasi diubah.

## 10. Scoped test evidence

### 10.1 Frontend (container Node terisolasi `node:24-alpine`, nama `pc-glm-frontend`)

Metode: source `frontend/` di-copy ke `/app` container **tanpa** `node_modules`/`dist`; `npm ci --no-audit --no-fund`; repo `node_modules`, lockfile, dan `frontend/dist` tidak tersentuh. File `._*` AppleDouble (artefak copy) dibersihkan di dalam container saja.

```sh
npm test -- src/views/dashboard/__tests__/ProductFormView.variants.spec.ts \
  src/views/__tests__/CheckoutView.stockSource.spec.ts \
  src/views/__tests__/CatalogStockSource.spec.ts \
  src/api/__tests__/orderStockSource.spec.ts \
  src/components/cms/__tests__/BestsellerBlock.spec.ts \
  src/views/admin/__tests__/CmsHomepageBlockFormView.slider.spec.ts \
  src/views/__tests__/ProductDetailView.gallery.spec.ts \
  src/views/dashboard/__tests__/ProductManagementView.categories.spec.ts
```

**PASS: 8 file / 33 tests** (7.12s; 6 file regression Codex + 2 spec baru GLM).

```sh
npm run type-check   # exit 0, tanpa error
npm run build-only   # exit 0, built 6.77s, PWA a6a50f245cc428b0, 156 precache
```

Build: hanya advisory pre-existing chunk >500 kB. Iterasi: 3 kegagalan awal pada spec baru (fixture varian tanpa field `stock`; ekspektasi urutan tree DFS; value DOM opsi null) — **diperbaiki di test, bukan dengan melemahkan source/guard**.

### 10.2 Backend (serialized runner, hanya `primeclassy_testing`)

Preflight DB sebelum eksekusi: `APP_ENV=testing`, `DB_HOST=primeclassy-mariadb`, `DB_DATABASE=primeclassy_testing`; database DEV `primeclassy_db` teridentifikasi dan tidak disentuh; `ps` memastikan tidak ada runner paralel aktif; harness serial + named-lock existing dipakai.

```sh
docker exec -e APP_ENV=testing -e DB_HOST=primeclassy-mariadb -e DB_DATABASE=primeclassy_testing \
  -w /var/www/app/backend primeclassy-fpm php scripts/run-tests-serialized.php \
  --filter='CatalogImportExportTest|ItemReceiptBestsellerTest|HistoricalPreflightTest|CatalogRemediationTest|SubStockSourceTest|StockOpnameTest|StockOpnameSubOwnershipTest|StockRevisionMigrationTest|CatalogImportConcurrencyTest|CmsHomepageTest|CmsHomepageStartingPriceTest|ValidAgentResolverTest|InvoiceOfficialLogoTest|CategoryHierarchyConcurrencyTest|WarehouseFulfillmentProposalTest|SubStockRequestFlowTest'
```

**HASIL: PASS — 120 test / 971 assertion, exit 0**, durasi runner 233.4 detik, `environment=0 migration=0 concurrency=0 test=0` (run `9a2e7a97-f2a1-4867-b73e-7c3de99f623a`; PHPUnit 11.5.56 / PHP 8.3.36; bukti JSON/JUnit di `backend/storage/framework/testing/runs/` — git-ignored). Tiga deprecation PHPUnit (noise pre-existing yang sama dengan run Codex), bukan failure. Cakupan 16 kelas = seluruh area REM-01/02/04/05/06/07/08/09/11 yang relevan (import/export+ABA revision, gallery isolation + hard delete + bestseller receipts, preflight historis, opname + sub ownership, CMS homepage, valid-agent resolver, logo invoice, concurrency kategori reparent, fulfilment/SKS). Backend tidak diubah GLM — run ini adalah regression re-evidence pada tree yang sama.

### 10.3 Lain-lain

- `git diff --check`: **PASS** (hanya advisory CRLF pre-existing pada `HomepageBlockTypes.php`).
- Full Test tidak dijalankan. Tidak ada dua runner pada satu database.

## 11. Migration dan environment safety

- **Tidak ada migrasi baru oleh GLM.** Migrasi additive existing (Claude/Codex) tetap: `2026_11_13_100000` (import opname), `2026_11_13_100100` (receipts), `2026_11_14_100000` (return allocations), `2026_11_14_110000` (stock revision/ABA), `2026_11_14_120000` (historical identity). Total tetap 5.
- **Tidak ada migrasi DEV.** Test harness hanya rebuild `primeclassy_testing`.
- Tidak ada akses production, tidak ada deploy, tidak ada cache/queue/ops mutation.
- Frontend diverifikasi di container disposabel; build output tetap di dalam container (repo `dist` tidak ditimpa).
- Working tree tetap dirty untuk Owner review; HEAD tetap `6a14041c`.

## 12. Remaining blockers (dengan referensi source eksak)

1. **REM-11 admission/evidence/review** — `backend/app/Services/HistoricalPos/HistoricalDossierPreflight.php:179-187` (`admission_blockers`): (a) immutable scoped evidence + independent review belum ada (basis Media mutabel: `MediaService.php:61,86,92`); (b) stock correction claim-once belum ada; (c) historical payment & commission recognition belum ada (`PaymentService.php:386` menerapkan uang; `CommissionFinalizer.php:12-14` tanpa payout writer); (d) finance opening treatment + issued-report reconciliation belum ada (tanpa register issued/closed di `Services/Report/*`).
2. **D-04 Finance semantics unproven** — tidak ada register issued/closed report, approved opening treatment, atau bukti settlement eksternal yang bisa diverifikasi dari source; tidak boleh diarang oleh agent. Admission disabled.
3. **REM-11 POS UI** — mockup final approved tidak ada: `docs/architecture-lock/2026-11-11/README.md:5-8` (kandidat ungu NOT-VERIFIED-FINAL); admission nonaktif; kontrak paritas POS–C O masih PROPOSED (`docs/RECON-CLOSURE-HISTORICAL-POS.md` addendum).
4. **REM-10 visual acceptance** — referensi invoice final ter-approve tidak ada (`03-DESIGN-REFERENCES/INVOICE/` = kandidat); logo/hash/maroon terverifikasi tapi bukan acceptance visual.
5. **REM-03 browser UAT** — manual Owner (tidak ada defect kode yang terbukti).
6. **REM-01 production preflight** — privilege TRIGGER/binlog deployment target belum diverifikasi (di luar akses agent; dokumentasi Codex D).
7. **Opname non-import lama** — kontrak quantity-only untuk opname pra-revision (known limit Codex; tidak berubah).
8. **Media generic mutable** — bukan sealed dossier store; receipt evidence reference ≠ evidence historis terkunci.

## 13. Owner handoff

**Yang berubah sejak laporan Codex (untuk review diff):** hanya dua view frontend + dua spec baru (daftar §2.2). Seluruh sisa diff dirty = pekerjaan Claude/Codex yang sudah ada, tidak diubah.

**Rekomendasi urutan review:**

1. Review `ProductDetailView.vue` (REM-02) — logika `galleryImages` + reset index; jalankan UAT browser pilih varian.
2. Review `ProductManagementView.vue` (REM-06) — tree + reparent; UAT buat/anak/reparent/invalid parent.
3. UAT browser REM-03 (gambar: tambah/hapus/primary/tanpa-ubah).
4. REM-11: putuskan langkah berikutnya untuk kontrak teknis evidence/review/Finance — **keputusan bisnis tidak dibutuhkan ulang** (D-01–07 FINAL); yang dibutuhkan adalah spesifikasi teknis ter-review (siapa reviewer dossier, di mana register issued report, bagaimana opening treatment direkam). Admission tetap nonaktif sampai itu ada dan terverifikasi.
5. REM-10/POS UI: cari & verifikasi artefak visual final ter-approve (di luar repo) sebelum implementasi visual apa pun.

**Tidak ada** commit, push, merge, deploy, migrasi, Full Test, atau aksi production yang dilakukan atau diminta oleh laporan ini. Seluruh keputusan Owner tetap FINAL dan tidak dibuka ulang.

**EXACT NEXT ACTION:** Owner/independent reviewer me-review diff working tree (fokus 4 file §2.2) + bukti test §10; tidak ada aksi production atau pengaktifan admission. Container verifikasi frontend (`pc-glm-frontend`) masih berjalan dan bisa diinspeksi sebelum dibuang.
