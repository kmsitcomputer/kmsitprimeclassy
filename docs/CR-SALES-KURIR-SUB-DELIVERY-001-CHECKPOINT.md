# CHECKPOINT — CR-SALES-KURIR-SUB-DELIVERY-001 (2026-10-14) — IMPLEMENTED + REMEDIATED, AWAITING FINAL REVIEW

## 1. Objective & phase

Memungkinkan `sales-kurir-sub` (SKS) mengantarkan pesanan sendiri / konsumen referral-nya, dari stok pribadi (Sub) maupun stok Agen — dengan penugasan wajib oleh Admin/Agen yang berwenang, tanpa self-assignment.
Fase aktif: **implementasi + remediation Review #1 selesai; menunggu Final Review Space Bunny (Tahap 4). Belum ada commit.**

## 2. Branch / HEAD

- Branch `main`, HEAD `a9c5abd` (= baseline paket, `FIX-COURIER-ASSIGNMENT-422`).
- Working tree kotor oleh file pre-existing yang TIDAK terkait (navConfig.ts, GoogleSheetsView.vue, pnpm-lock.yaml, docs audit, dist-*). Tidak disentuh.
- Production: tidak diubah. Tidak ada mutasi database. Tidak ada commit/push.

## 3. Root cause (mengapa SKS hari ini tidak bisa mengantar stok Agen)

SKS **sudah punya Courier profile** (`UserManagementService::create` L114-125 dan `convertSalesToSalesKurirSub` L170-175 — `Courier::firstOrCreate(user_id)`),
tapi seluruh jalur `standard` menolaknya secara eksplisit:

1. `backend/app/Services/Order/CourierService.php::assignCourier` L97-101 — executor harus role `kurir`/`koordinator-kurir`; Courier row milik SKS → 422 `invalid_assignment`.
2. `CourierService::assertMayOperateShipment` L270-272 — SKS di shipment `standard` → 403 `sales_kurir_sub_standard_forbidden` (disengaja, bukan kecelakaan).
3. `DispatchController::couriers` L179-187 — hanya list `kurir`/`koordinator-kurir` se-cabang; SKS tidak pernah jadi kandidat.
4. `ShipmentPolicy::updateStatus` L34-38 — SKS hanya untuk `self_sub` miliknya.
5. `CourierDashboardController::orders` L58-70 — SKS hanya melihat `self_sub` miliknya; `standard` yang (nanti) ditugaskan tidak terlihat.
6. Frontend `OrderDetailView.vue` — `canOperateShipmentGroup` L290 `if (isSubRole) return false` untuk standard; dropdown assignment = list se-cabang (`listDispatchCouriers`), bukan per-shipment eligible (FIX-422 baru menutup role-nya, belum menutup per-pesanan-nya).
7. `selfAssignIfUnassigned` — klaim pickup hanya `kurir`; SKS tidak boleh klaim (sesuai aturan "tanpa self-assignment", dipertahankan).

Yang SUDAH bekerja (tidak diubah): 4 kombinasi dengan **stok Sub** → `self_sub` otomatis milik owner (`OrderService::createOrder` L332-349, `StockSourceResolver` hanya untuk pembelian-sendiri / referral-sendiri oleh SKS);
komisi kurir untuk `self_sub` ke `self_delivered_by_user_id` (`recordCommissionsForItems` L577+); stok dikonsumsi sekali saat `dikirim` owner (`SubStockService::consume`).

## 4. Desain perubahan

### 4a. Alur `self_sub` — TIDAK BERUBAH (menunggu konfirmasi C1)

Executor (`self_delivered_by_user_id`) tetap ditetapkan saat order dibuat dari kepemilikan Sub Location (= kebenaran sumber stok, bukan keputusan dispatch).
CHECK `shipments_delivery_mode_consistent` + 2 trigger `self_sub_no_courier_*` tidak disentuh. Larangan `courier_id` pada `self_sub` dipertahankan penuh.
Aturan "penugasan tidak otomatis saat order dibuat" ditafsirkan berlaku untuk assignment dispatch `standard`; executor `self_sub` adalah atribusi kepemilikan, bukan assignment.

### 4b. Alur `standard` — SATU-SATUNYA jalur yang dibuka

- Eligibility baru (service kecil, mis. `SalesKurirSubEligibilityService::isEligibleForShipment(User $sks, Shipment $s)`):
  same-Agent SKS aktif + Courier profile aktif + order `diproses` + shipment `standard/pending/unassigned` +
  (`order.konsumen_id == sks.id` ATAU `order.sales_id == sks.id` ATAU [`konsumen.sales_id == sks.id` rantai SAAT INI + same-Agent]) — menunggu konfirmasi C2.
- `assignCourier`: jika executor milik SKS → tolak `self_sub`, wajib lolos eligibility di atas **di dalam lock Order→Shipment yang sudah ada** (A1-03 dipertahankan); idempotency same-executor + tolak executor-beda + status terminal tidak berubah.
- `assertMayOperateShipment`: SKS boleh memproses `standard` HANYA jika `shipment.courier.user_id == dirinya` (cermin aturan kurir) + bukti foto wajib `dikirim→terkirim`.
- `selfAssignIfUnassigned`: SKS tetap DITOLAK (tanpa self-claim pickup; hanya office yang assign). `ShipmentPolicy::assignCourier` tidak berubah (agen/admin/koordinator/super_admin saja).
- `ShipmentPolicy::updateStatus`: tambah cabang SKS-untuk-standard-yang-ditugaskan.
- `CourierDashboardController::orders`: SKS melihat `self_sub` miliknya + `standard` yang `courier_id`-nya = profilnya.
- Endpoint baru `GET /shipments/{shipment}/eligible-couriers` (role office/dispatch): kurir+koordinator se-cabang (seperti hari ini) + SKS yang eligible **untuk shipment itu saja**; frontend dropdown per-shipment memakai ini.
- Profil kurir SKS yang hilang (legacy): lazy `ensure...`-mirip-koordinator saat assignment — menunggu konfirmasi C3.
- Stok/komisi: tidak ada gerakan stok saat assignment; konsumsi tetap sekali via `consume()`; komisi kurir tetap satu baris per item ke executor tercatat (`beneficiary_user_id` = SKS), tidak digabung dengan fee sales.

## 5. Daftar file yang dimodifikasi (BELUM commit — menunggu review)

Backend:
- `backend/app/Services/Order/SalesKurirSubEligibilityService.php` (BARU): predicate eligibility SKS (C2: konsumen==diri / snapshot sales_id / rantai saat ini + guard se-Agen + aktif); `eligibleCouriersForShipment` READ-ONLY (kurir/koordinator cabang + SKS eligible shipment itu saja; profil-less → `courier_id: null` + `user_id`; tanpa kandidat untuk shipment yang tak dapat ditugaskan — diproses/pending/unassigned).
- `backend/app/Services/Order/CourierService.php`: `assignCourier` menerima executor SKS eligible (cek di dalam lock Order→Shipment→Courier→executor→konsumen + locking konsumen read; self_sub tetap ditolak; idempotency/terminal tidak berubah); `resolveSalesKurirSubExecutor` (BARU — resolve/buat profil SKS pada jalur WRITE dengan lock kanonis + race-safe `couriers_user_unique`); `assertMayOperateShipment` mengizinkan SKS pada standard YANG ditugaskan padanya + bukti wajib + **menolak executor (`kurir`/`sales-kurir-sub`/`koordinator-kurir`) yang dinonaktifkan (403 `executor_inactive`)**; `selfAssignIfUnassigned` tetap menolak SKS (tanpa self-claim).
- `backend/app/Policies/ShipmentPolicy.php::updateStatus`: cabang SKS-standard-yang-ditugaskan (self_sub owner tidak berubah).
- `backend/app/Http/Controllers/Api/V1/Courier/CourierDashboardController.php::orders`: SKS melihat self_sub miliknya + standard yang courier_id-nya = profilnya.
- `backend/app/Http/Controllers/Api/V1/Courier/ShipmentController.php`: `eligibleCouriers` baru (`{courier_id,user_id,name,executor_role}`, guard assignable-state); `assign` mendukung `executor_user_id` (resolve-on-write) di samping `courier_id` dan sentinel koordinator `0`.
- `backend/app/Http/Requests/Courier/AssignCourierRequest.php`: tepat satu dari `courier_id` / `executor_user_id` (mutually exclusive; validasi `exists` dipertahankan).
- `backend/app/Http/Resources/CourierOrderResource.php`: SKS melihat item self_sub miliknya **dan** item standard yang ditugaskan pada profilnya (cermin koordinator); item lain tetap tersembunyi.
- `backend/routes/api_v1.php`: `GET /shipments/{shipment}/eligible-couriers` (role office/dispatch yang sama dengan assign).
- `backend/lang/{id,en,ar,zh}/messages.php`: tambah `courier.executor_inactive`; hapus `sales_kurir_sub_standard_forbidden` yang tak terpakai.
- `backend/tests/Feature/SalesKurirSubStandardDeliveryTest.php` (BARU, 20 tes): 4 kombinasi, 6 penolakan, self-assign 403, cross-branch 403, take-over/idempotency, bukti wajib, stok-sekali, komisi-tunggal-terpisah, endpoint eligible (read-only + guard), resolve-on-assign (+4 penolakan resolve), visibilitas items assignee + isolasi split-shipment, executor dinonaktifkan (SKS + kurir, service + HTTP).
- Docs otoritatif: `BUSINESS-RULES.md` (§2 matriks 2 baris + §12 amandemen CR-001 + interpretasi §2.5 tercatat), `MASTER-SYSTEM.md` (§15/§16/§28), `ARCHITECTURE.md` (§3 service + §7 lock order).

Frontend:
- `frontend/src/api/dispatch.ts`: `EligibleCourier {courier_id,user_id,name,executor_role?}` + `listEligibleCouriers(shipmentId)` (read-only).
- `frontend/src/api/shipments.ts`: `assignCourier(shipmentId, number | {courier_id} | {executor_user_id})` (kompatibel pemanggil lama/DispatchView).
- `frontend/src/views/OrderDetailView.vue`: dropdown per-shipment dari endpoint eligible (tanpa param cabang — scoping server-side per shipment; guard stale-seq + error per shipment dipertahankan); kandidat profil-less dikirim via `executor_user_id` (key `u:{user_id}`); SKS dapat mengoperasikan standard yang ditugaskan padanya + cetak resi-nya; self_sub tanpa dropdown (tetap).
- `frontend/src/views/__tests__/OrderDetailView.spec.ts`: kontrak baru (14 tes, termasuk assign profil-less via user id).

Disengaja TIDAK diubah: CHECK/TRIGGER self_sub, StockSourceResolver, komisi, DispatchController::couriers cabang, kunci i18n (key `courierListNoBranch` kini tak terpakai di komponen — dibiarkan, harmless; key `sales_kurir_sub_standard_forbidden` tak terpakai di service — dibiarkan).

## 6. Perintah + hasil

- `git status / branch / rev-parse` — main @ a9c5abd; kotoran pre-existing (navConfig.ts, GoogleSheetsView.vue, pnpm-lock, docs audit, dist-*) tidak disentuh, di luar diff paket.
- `php -l` 6 file backend — bersih (warning OPcache/mbstring adalah noise host).
- Baru: `SalesKurirSubStandardDeliveryTest` 20/20 PASS (148 assertions) — termasuk visibilitas items assignee, isolasi split-shipment, resolve-on-assign, executor nonaktif.
- Batch terdampak: R04+Dispatch+DispatchRemediation+AssignmentConcurrency+CourierSystem+ItemProgression+FeeSplit+DualFee — 82/82 PASS.
- Pre-existing suites SKS: `SalesKurirSubSelfDeliveryTest` 16/16, `DispatchTest`, `CourierSystemTest` — PASS (perilaku larangan lama untuk unassigned dipertahankan).
- FULL backend suite: **1177 passed / 13 failed** — ke-13 adalah bom waktu tanggal PRE-EXISTING yang didokumentasikan checkpoint FIX-422 (12 `ShipmentCanonicalizationTest` + 1 `DeliveryDateRescheduleRuleTest "five distinct dates"`, signature `delivery_date must be a date after or equal to today` pada order-create fixture 2026-10-10 vs hari ini; terbukti gagal di baseline pristine via git stash di paket sebelumnya; 1162+15=1177 cocok persis).
- Frontend: `npm run type-check` exit 0; `vitest run OrderDetailView.spec` 14/14 PASS; `npm run build-only` exit 0 (hanya warning chunk >500kB pre-existing); `npx oxlint` file tersentuh exit 0.
- `git diff --check` bersih. TIDAK ada commit/push; TIDAK ada mutasi DEV/prod database; served `frontend/dist` TIDAK di-rebuild (butuh otorisasi sync seperti pola FIX-422).

## 7. Risiko (kompatibilitas, migrasi, stok, komisi) (kompatibilitas, migrasi, stok, komisi)

- Skema `self_sub` (CHECK+trigger) tidak tersentuh → risiko migrasi NOL untuk jalur ini; endpoint eligible baru read-only.
- Risiko utama adalah **perluasan akses**: predicate eligibility yang terlalu longgar akan membuka lintas-cabang / konsumen orang lain. Mitigasi: same-Agent ganda (order + konsumen), rantai referral dibaca server-side di dalam lock, tanpa input frontend.
- `OrderDetailView.spec.ts` + `DispatchTest` + suite Courier/Checkout yang ada harus tetap hijau (FIX-422 gate).

## 10. Status kesiapan review Space Bunny

- Acceptance gate: 4/4 kombinasi (sendiri+Sub, sendiri+Agen, referral+Sub, referral+Agen) PASS via tes; 10 penolakan/batasan (referral orang lain, beda Agen, nonaktif, self-assign 403, cross-branch office 403, take-over 403/422, idempotency replay 200, bukti wajib 422, stok-sekali, komisi-tunggal) PASS; suite FIX-422 hijau kecuali 13 bom waktu pre-existing yang terdokumentasi.
- Diff paket: 8 file modifikasi + 2 file backend baru + 1 checkpoint (di luar kotoran pre-existing). TIDAK di-commit sesuai batasan — menunggu review independen Space Bunny dan otorisasi commit file paket saja (tanpa `git add -A`).
- UAT browser DEV masih diperlukan setelah `dist` di-sync (otorisasi terpisah): assign SKS eligible dari Order Detail, SKS memproses dari /kurir/orders, SKS ineligible tidak muncul di dropdown.

## 12. Final Review + Auto Remediation (Space Bunny, Tahap 4, 2026-10-14)

- **Finding baru (MEDIUM, in-scope) → FIXED**: `resolveSalesKurirSubExecutor` membuat profil Courier tanpa guard status assignable → bisa meninggalkan profil yatim pada request yang pasti 422 (order non-diproses / shipment sudah di-assign / self_sub). Remediasi: tambah guard kanonis yang sama persis dengan `assignCourier` (order `diproses`, shipment `pending` + unassigned + standard + tanpa self-delivery owner, `assertAddedDemandFulfilled`) SEBELUM `firstOrCreate`. Regression: `test_resolve_writes_nothing_when_the_assignment_could_never_complete` (a) sudah-di-assign (b) terminal (c) self_sub — semua 422 + `Courier::count()` tak berubah.
- **Full backend suite**: 1183 passed / 13 failed (8699 assertions). Ke-13 = bom waktu tanggal pre-existing yang sama; baseline pristine (stash CR) → 13 failed identik (98 assertions), signature `delivery_date must be a date after or equal to today` pada order-create fixture 2026-10-10.
- **Frontend**: `npm test` 14/14; `npm run type-check` exit 0; `npm run build-only` exit 0 (hanya warning chunk pre-existing); oxlint lokal 1.81.0 exit 0; `git diff --check` bersih.
- **PHP lint**: 9 file backend bersih. **Route**: `GET /shipments/{shipment}/eligible-couriers` + `PATCH .../courier` terdaftar di grup `role:super_admin,agen,admin,koordinator-kurir`.
- Tidak ada commit/push/deploy; kotoran pre-existing (navConfig, GoogleSheetsView, pnpm-lock, docs audit, dist-*) tidak tersentuh; stash dipulihkan penuh (list kosong); 21 tes CR terkonfirmasi di tree.
- **Kriteria kelulusan Tahap 4**: seluruh finding Review #1 + finding baru remediated + targeted regression PASS + tidak ada kegagalan baru vs baseline (1183 = 1162 baseline + 21 baru; 13 gagal identik). Menunggu keputusan akhir Owner (Human Approval).

- **BLOCKER-1 (items kosong)**: FIXED — `CourierOrderResource::itemVisibleToViewer` cabang SKS-standard + 2 regression (`assigned_standard_items_are_visible_only_to_the_assignee`, `split_shipments_keep_each_executors_items_isolated` via SC-03 split + fulfilment).
- **HIGH-2/3 (docs + rekaman keputusan)**: FIXED — `BUSINESS-RULES.md` §2 (2 baris matriks) + §12 (amandemen CR-001 + interpretasi §2.5 tercatat eksplisit); `MASTER-SYSTEM.md` §15/§16/§28; `ARCHITECTURE.md` §3 + §7.
- **MEDIUM-4 (write-on-read)**: FIXED — GET murni read-only (dibuktikan `Courier::count()` unchanged); profil dibuat di `resolveSalesKurirSubExecutor` pada jalur WRITE (lock kanonis + `couriers_user_unique` race-safe); kontrak kandidat `courier_id|null + user_id`; assign via `executor_user_id` (+4 penolakan resolve: ineligible/non-SKS/unknown/ganda).
- **MEDIUM-5 (nonaktif pasca-assignment)**: FIXED — `assertMayOperateShipment` menolak executor nonaktif (403, service + HTTP, SKS + kurir); scope office tidak diubah (residual tercatat).
- **MEDIUM-6 (lock docs)**: FIXED — `ARCHITECTURE.md` §7 mencatat Order→Shipment→Courier→executor→konsumen + tidak ada siklus users→orders.
- **LOW-7/8/9**: FIXED — endpoint guard (non-diproses/pending/tereksekusi → `[]`); eager `with(user.role)` + `with(role)`; `doesntContain` + key i18n mati dihapus.
- Risiko tersisa: (a) office yang dinonaktifkan masih bisa beroperasi bila sudah login (pre-existing, di luar scope); (b) SC-03 pending demand masih 422 saat assign (by design, pesan eksplisit) — endpoint tidak memfilternya; (c) 13 bom waktu tanggal pre-existing belum diperbaiki (di luar scope).

## 8. Temuan yang memerlukan keputusan Owner (C1–C3 — diajukan via pertanyaan)

- **C1**: executor `self_sub` otomatis dari kepemilikan stok vs aturan "tanpa penugasan otomatis". Rekomendasi: pertahankan (kepemilikan ≠ dispatch).
- **C2**: bentuk predicate eligibility SKS (apakah mencakup `konsumen_id==diri` + rantai konsumen SAAT INI, atau hanya snapshot `order.sales_id`?). Rekomendasi: ketiganya dengan guard same-Agent.
- **C3**: SKS legacy tanpa Courier profile (role di-rename in-place; profil hanya dibuat untuk akun baru/konversi). Rekomendasi: lazy-create saat assignment eligible (mirip koordinator), tanpa migrasi backfill.

## 9. Keputusan Owner (2026-10-14 — via pertanyaan implementer)

- **C1**: Pertahankan otomatis — executor `self_sub` = atribusi kepemilikan stok, bukan dispatch.
- **C2**: Lengkap — `konsumen_id==diri` ATAU `order.sales_id==diri` ATAU `konsumen.sales_id==diri` (rantai saat ini), semua dengan guard se-Agen.
- **C3**: Lazy-create profil kurir SKS saat assignment eligible pertama (mirip koordinator-kurir), tanpa migrasi backfill.

## 10. EXACT NEXT ACTION

Implementasi backend (eligibility service + assign/operate/policy/dashboard/endpoint eligible) → frontend → regression → review Space Bunny.
