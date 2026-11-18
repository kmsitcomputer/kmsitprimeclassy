# PRIMECLASSY V2 — REM-001 s.d. REM-006
## Architecture Lock v1.1 & Technical Implementation Specification v1.0

**Tanggal:** 10 November 2026  
**Status:** Disetujui Owner sebagai spesifikasi; **implementasi belum diotorisasi**  
**Lingkungan:** Development lokal `https://primeclassy.kmsit.localhost/`  
**Repo target:** `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy/docs/`  
**Production:** DILARANG DISENTUH

> **Aturan kejujuran:** Dokumen ini membedakan keputusan Owner (LOCKED), fakta audit source Codex (AUDIT), kewajiban implementasi (REQUIRED), dan hal yang belum dibuktikan (VERIFY/UNDECIDED). Tidak ada klaim source telah dimodifikasi, migrasi telah dijalankan, atau frontend telah sinkron.

---

## 1. Otoritas dan ruang lingkup

### 1.1 Sumber
- **LOCKED:** keputusan Owner dalam percakapan 10 November 2026, termasuk Architecture Lock v1.1 dan Technical Implementation Specification v1.0.
- **AUDIT:** hasil recon Codex yang disampaikan Owner, verdict `ARCHITECTURAL REMEDIATION REQUIRED`. Audit statis/read-only; tidak menjalankan test, build, migration, transaksi atau pemeriksaan runtime browser.
- **VERIFY:** `AGENTS.md`, `CHECKPOINT.md`, `docs/BUSINESS-RULES.md`, `docs/ARCHITECTURE.md`, route, migrations, dan source code aktual wajib diperiksa sebelum coding. Dokumen historis tidak mengalahkan keputusan Owner terbaru.

### 1.2 Pembatasan LOCKED
1. Development saja; production tidak boleh diakses/diubah untuk REM ini.
2. Reservasi stok checkout tetap; tidak ada TTL/auto-expiration.
3. **Admin tetap bisa mengubah QTY dan menambahkan produk (SC-03) pada order `diproses`**, sesuai validasi existing yang sah. Total, stok, demand Gudang, saldo, dan invoice harus direkonsiliasi.
4. **SKS/`self_sub` tetap seperti existing**: frontend, backend, API, permission, stok, pembayaran, pickup, dan ownership.
5. Ongkir pelanggan dihitung sekali saat checkout; **satu invoice utama per order**.
6. Tidak membuat modul/ledger biaya operasional pengiriman.
7. Aplikasi masih DEV, tidak ada order nyata: tidak diperlukan migrasi workflow historis Kurir Toko. **Data uji existing tidak boleh dihapus tanpa izin.**
8. Frontend, backend, API, role, status, stok, pembayaran, dan frontend yang benar-benar disajikan runtime harus sinkron.
9. Tidak boleh mengubah integrasi quote/fee RajaOngkir, ORS/KMSIT Routing, atau komponen lain di luar kebutuhan yang disetujui.
10. Belum ada izin coding, commit, push, deploy, reset database, atau migration.

## 2. Keputusan bisnis final LOCKED

### 2.1 Order dan pembayaran
- **Kurir Toko COD** langsung `diproses` dari checkout.
- Kurir Toko non-COD mengikuti payment verification dan processing gate existing.
- **RajaOngkir wajib lunas termasuk ongkir sebelum pengiriman**; tanpa COD, DP, cicilan, atau pengiriman parsial.
- Kurir Toko: **maksimal 3 pembayaran berhasil per order**, gabungan COD, DP, dan transfer. Pembayaran ketiga wajib melunasi sisa tagihan sah; pembayaran keempat ditolak. Pembayaran gagal/dibatalkan sebelum berhasil tidak dihitung.
- COD Kurir Toko dapat dibayar bertahap, **tidak harus satu kali per batch**. Jumlah batch pengiriman boleh lebih dari tiga.
- Tidak boleh ada paid melebihi kewajiban sah, transaksi ganda, atau ongkir tambahan per batch.

### 2.2 Gudang, Koordinator, Admin
- Koordinator Kurir mengelola seluruh logistik **Kurir Toko dan RajaOngkir**.
- Koordinator meminta barang dari Gudang. Gudang memeriksa dan menyiapkan barang, lalu mengonfirmasi penyerahan fisik. Koordinator memeriksa dan mengonfirmasi penerimaan fisik.
- Kuantitas valid, kurang, dan rusak harus dibedakan. Barang yang tidak diterima valid tidak boleh dianggap siap dikirim.
- **Approval Admin rutin atas proposal Gudang lama tidak digunakan dalam workflow baru**. Admin memutuskan pengecualian, sengketa Gudang–Koordinator, reschedule, adjustment, dan cancellation; keputusan sengketa dengan alasan/bukti/audit.
- Mutasi stok harus konsisten dengan serah terima fisik valid, **tidak boleh double decrement/double release**.

### 2.3 Kurir Toko
- **Hanya Koordinator** dapat assign/reassign Kurir Toko, baik lewat frontend maupun API. Kurir hanya boleh memproses tugas yang assigned.
- Boleh mengirim full/partial dalam beberapa batch, termasuk membentuk/melanjutkan batch setelah batch sebelumnya dikirim.
- Assignment hanya terhadap barang yang telah diterima valid oleh Koordinator.
- Barang ditolak pelanggan dikembalikan Kurir kepada Koordinator dengan **konfirmasi balik dua pihak**; lalu redelivery atau kembali ke Gudang.
- Pembatalan parsial hanya terhadap **sisa yang belum dikirim**. Barang delivered dan pembayaran sah tetap valid.

### 2.4 RajaOngkir
- Tetap melalui Gudang → Koordinator dengan serah terima dua pihak.
- **Barang harus lengkap** sebelum handover/dispatch. Jika kurang, tahan pengiriman; Admin menentukan reschedule/adjustment/cancellation.
- **Tidak ada assignment Kurir Toko** dan tidak ada partial shipment.
- Koordinator mengatur ekspedisi pilihan checkout, lalu mencatat nomor resi yang cocok dengan ekspedisi setelah ada bukti penyerahan ke ekspedisi, serta mengelola status pengiriman.

---

## 3. AUDIT — Existing architecture dan konflik terkonfirmasi secara statis

| Area | Fakta dari recon Codex | Konflik dengan target |
|---|---|---|
| Checkout | `OrderService::createOrder` mereservasi stok Agent | Jangan membuat reservasi checkout kedua |
| Fulfillment | `StockRequest` otomatis saat `diproses`; Gudang proposal; Admin approve | Target permintaan Koordinator dan fulfillment Gudang tanpa approval Admin rutin |
| Inventory | `StockRequestProposalService::approve` memindahkan Transit → Shipping dan mengonsumsi reserved | Target stock movement terkait valid receipt; jangan dua kali |
| Assignment | `ShipmentPolicy` mengizinkan Super Admin/Agen/Admin/Koordinator; `CourierService` belum mensyaratkan receipt Koordinator | Hanya Koordinator dan accepted goods |
| Dispatch | Filter `delivery_mode=standard` belum provider-specific | RajaOngkir/pickup berpotensi masuk Kurir Toko |
| Multi-shipment | Existing mendukung beberapa shipment, tetapi split lanjutan tertutup setelah `dikirim` | Target batch parsial berulang |
| COD | `PaymentService::markCod` menyetel paid ke total order; installment menolak COD | Target pembayaran COD parsial dan max 3 |
| Receipt | Receipt COD per shipment dapat menampilkan total order | Risiko tagihan ganda |
| Cancellation | Existing belum cocok dengan cancel sisa setelah pengiriman pertama | Perlu partial cancel/reversal aman |
| RajaOngkir | Provider, service, quote metadata dan tracking column ada; booking/tracking carrier tidak terbukti | Jangan mengarang integrasi tracking otomatis |
| SKS | Ada reservation/pickup/ownership khusus | Harus diisolasi dari perubahan |
| Frontend | Runtime `primeclassy-web` melayani `public_html`; `frontend/dist` berbeda dari `public_html` | Parity source-build-runtime belum terbukti |

**Identitas saat audit (VERIFY ulang sebelum pekerjaan):** repo `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy`; branch `main`, HEAD `d7997c4e6a32ee64bc1c3075a021037c5dfa11c2`. Working tree **sudah dirty**. Jangan reset/clean/overwrite. Backend Laravel 11.56.1 dan frontend Vue 3.5.42/Vite 8.2.2/TypeScript 6.0.3 berasal dari lockfile, bukan runtime verification.

### 3.1 File/komponen yang dipetakan audit (bukan perintah mengubah semuanya)
- `backend/app/Services/Order/OrderService.php`
- `backend/app/Services/Order/OrderFulfillmentService.php`
- `OrderLineAdditionService` (VERIFY path)
- `backend/app/Services/Order/CourierService.php`
- `StockRequestService`, `StockRequestProposalService`, `StockService`, `SellableStockService`, `StockTransferService`, `InventoryCancellationService` (VERIFY path aktual)
- `PaymentService`, `AvailablePaymentMethodService`, `OrderTotalCalculator`, `InvoiceIssuanceService`, `RefundLedgerService` (VERIFY path)
- `WarehouseOrderController`, `DispatchController`, `ShipmentController`, `OrderController`, `ShipmentPolicy`, `OrderPolicy`, `ShipmentReceiptResource`, `ReturnService`, `backend/routes/api_v1.php` (VERIFY path)
- `frontend/src/views/CheckoutView.vue`
- `frontend/src/views/OrderDetailView.vue`
- `frontend/src/components/orders/OrderPaymentTools.vue`
- `frontend/src/views/dashboard/OrderDiprosesView.vue`
- `frontend/src/views/dashboard/FulfillmentProposalsView.vue`
- `frontend/src/views/dashboard/DispatchView.vue`
- `frontend/src/views/dashboard/KurirDashboardView.vue`
- API clients, router/navigation, dashboard Admin/Gudang/Koordinator/Kurir/Keuangan/SKS yang terkait.

**VERIFY:** lokasi aktual semua komponen, seluruh jalur status/office override/bulk, schema terpasang, worker/job, dan frontend runtime.

---

## 4. Prinsip integritas lintas REM REQUIRED

1. **Conservation quantity:** definisikan dan rekonsiliasi ordered, checkout-reserved, pending demand, prepared, accepted, short, damaged, assigned, dispatched, delivered, refused, returned, cancelled pada granularitas item/alokasi yang sesuai. Jangan menghitung unit yang sama dua kali.
2. **Satu stock movement otoritatif per unit valid** untuk workflow baru. Event approval lama tidak boleh tetap memindahkan stok lalu receipt baru memindahkannya lagi.
3. **Idempotency dan concurrency:** retry, dua permintaan bersamaan, dan replay setelah state berubah tidak boleh membuat allocation, stock movement, payment, atau receipt ganda. Gunakan locking/transaksi konsisten berdasarkan source existing.
4. **Backend authoritative:** role, branch/Agen scope, provider, status, quantity, receipt, dan payment gate harus divalidasi server-side, termasuk bulk/office override; menyembunyikan tombol saja tidak cukup.
5. **Reuse first:** Order → OrderItem → Shipment, StockRequest, payment transactions, invoice, refund ledger, dan audit existing dipertahankan bila memenuhi kebutuhan. Jangan membuat tabel `delivery_batches` atau ledger pembayaran paralel tanpa bukti gap.
6. **Financial truth:** pending submission bukan paid; paid adalah transaksi sah terverifikasi. Satu invoice per order, ongkir sekali, refund tidak menghapus histori penerimaan.
7. **Admin SC-03/QTY protection:** perubahan sah pada order `diproses` harus memperbarui demand, stok, total, outstanding, dan invoice tanpa mengubah delivered unit secara ilegal.
8. **SKS isolation:** setiap perubahan shared service memerlukan pemeriksaan regresi SKS.

---

## 5. REM-001 — Order dan pemenuhan Gudang

**Tujuan:** Koordinator meminta barang; Gudang menyiapkan/menyerahkan; approval Admin rutin lama tidak lagi menjadi gerbang workflow baru.

### REQUIRED
1. Pertahankan checkout, reservasi, COD `diproses`, dan gate non-COD existing.
2. Periksa semua jalur pembuatan otomatis `StockRequest`; definisikan bagaimana permintaan Koordinator menggunakan demand existing tanpa duplikasi.
3. Gudang melihat kebutuhan barang berdasarkan kuantitas outstanding, termasuk ketika shipment lain sudah assigned.
4. Gudang dapat memproses barang penuh/parsial **hanya pada provider yang mengizinkan**; RajaOngkir harus menunggu lengkap.
5. Admin menangani hanya pengecualian/sengketa, bukan approve rutin.
6. **Pertahankan edit QTY dan add-line SC-03 Admin**. Penambahan/penurunan demand tidak boleh mengubah barang yang sudah diterima/dikirim secara tidak sah.
7. Audit requester, item, quantity, dan state transition.

### VERIFY
`OrderService`, `OrderFulfillmentService`, add-line SC-03 service, `StockRequestService`, proposal service, Gudang queue, OrderPolicy, API routes, UI Admin/Gudang/Koordinator.

### ACCEPTANCE
- [ ] COD Kurir Toko langsung `diproses`, non-COD tetap mengikuti gate.
- [ ] Reservasi checkout tepat sekali.
- [ ] Koordinator request → Gudang melihat demand yang benar.
- [ ] Tidak perlu Admin approve rutin.
- [ ] Admin edit QTY/SC-03 tetap berfungsi; total, demand, reserved konsisten.
- [ ] Kebutuhan item lain tidak hilang setelah satu shipment assigned.
- [ ] SKS tidak berubah.

---

## 6. REM-002 — Inventory dan serah terima dua pihak

**Tujuan:** barang yang benar-benar diterima Koordinator menjadi dasar custody dan stock movement yang sah.

### REQUIRED
1. Gudang menyatakan jumlah/kondisi diserahkan; Koordinator menyatakan jumlah/kondisi diterima.
2. Accepted, short, damaged, dan unresolved harus dapat dibedakan per item; tidak boleh accepted > prepared.
3. Mutasi stok terkait receipt valid **tepat sekali**; checkout reserved tidak ditambah lagi.
4. Barang kurang/rusak masuk rekonsiliasi/keputusan Admin, bukan otomatis sellable.
5. Return/refusal/cancel mempunyai reversal yang sesuai state fisik dan tidak double-release.
6. Audit dua aktor, waktu, kuantitas, kondisi, bukti, dan referensi order/item.

### AUDIT/VERIFY
`StockRequestProposalService::approve` sudah memindahkan Transit → Shipping; `stock_handovers` existing terikat pada `stock_transfer_id` dan transfer bergerak sebelum konfirmasi. **Jangan menganggap tabel tersebut langsung cocok.** Periksa schema existing dan perluasan minimal; **nama tabel/kolom/migration baru belum disetujui**.

### ACCEPTANCE
- [ ] Dua konfirmasi valid dari Gudang dan Koordinator.
- [ ] Retry/race tidak menggandakan movement.
- [ ] Short/damaged tidak masuk accepted/sellable.
- [ ] Cancel/reversal sesuai quantity dan status.
- [ ] SKS inventory tidak berubah.

---

## 7. REM-003 — Kurir Toko, RajaOngkir, shipment, refusal

### Kurir Toko REQUIRED
1. Gunakan persisted provider (`openroute`) untuk eligibility, bukan `standard` saja.
2. **Hanya Koordinator** dapat assign/reassign; role lain ditolak oleh API dan tidak disajikan kontrol assignment.
3. Assignment mensyaratkan accepted handover dan quantity sah.
4. Multiple shipment/batch boleh berlanjut setelah batch pertama `dikirim`.
5. Kurir hanya mengoperasikan tugas assigned; tidak boleh double-dispatch unit.
6. Refusal: Kurir → Koordinator dengan dua konfirmasi → redelivery atau kembali Gudang.
7. Cancel hanya undelivered remainder.

### RajaOngkir REQUIRED
1. Gudang–Koordinator handover dua pihak, **harus lengkap**.
2. Full payment termasuk ongkir sebelum dispatch.
3. Tanpa COD/DP/installment, tanpa partial shipment, tanpa Kurir Toko assignment.
4. Koordinator memasukkan resi yang sesuai ekspedisi pilihan **setelah bukti penyerahan ke ekspedisi**.
5. Stok kurang: hold; Admin memutuskan reschedule/adjustment/cancellation.
6. Quote/fee/integrasi provider yang tidak terkait tetap.

### VERIFY
`DispatchController`, `ShipmentController`, `ShipmentPolicy`, `CourierService`, `OrderFulfillmentService`, `Shipment` model, `ReturnService`, tracking API, bulk status, dashboard Koordinator/Kurir. Existing `PATCH /shipments/{id}/courier` dan shipment progress API harus diperiksa; **tidak ada endpoint baru yang diasumsikan**.

### ACCEPTANCE
- [ ] Role selain Koordinator tidak dapat assign Kurir Toko.
- [ ] Assignment tanpa accepted receipt ditolak.
- [ ] Batch kedua bisa dibentuk/dikirim setelah batch pertama.
- [ ] Penolakan dan pengembalian terlacak dengan dua pihak.
- [ ] RajaOngkir menolak partial/unpaid/internal assignment.
- [ ] Resi cocok dengan ekspedisi dan bukti handover.
- [ ] SKS tidak berubah.

---

## 8. REM-004 — Payment, COD, max 3, invoice

### REQUIRED
1. Gunakan payment transactions dan PaymentService existing, bukan ledger baru.
2. Maksimal **tiga pembayaran berhasil per order Kurir Toko**, digabung lintas COD/DP/transfer. Pending/gagal/batal sebelum berhasil tidak dihitung.
3. Pembayaran ketiga wajib menutup outstanding sah; pembayaran keempat ditolak.
4. `markCod` existing yang menetapkan full paid harus disesuaikan agar pembayaran COD parsial mencatat nominal nyata.
5. Akumulasi paid harus idempotent, tidak melebihi kewajiban sah, aman dari race.
6. Receipt per shipment tidak boleh menagih **total order berulang**.
7. Ongkir sekali saat checkout; satu invoice utama; perubahan QTY/SC-03/cancel merekonsiliasi saldo dan invoice.
8. RajaOngkir tetap wajib lunas sebelum dispatch dan tidak boleh metode bertahap.
9. Refund tidak menghapus histori pembayaran berhasil.

### AUDIT
- `PaymentService::markCod` menyetel `paid_amount=total_amount` atau 0.
- Installment existing menolak COD.
- COD proof existing berhubungan dengan transaksi order.
- `ShipmentReceiptResource` menampilkan potensi tagihan total order pada setiap shipment.
- Order-level payment accumulation, invoice, dan refund ledger sudah tersedia.

### UNDECIDED — jangan diisi dengan asumsi
Jika Admin menaikkan total order **setelah tiga pembayaran berhasil** dan muncul kewajiban baru, Owner belum menetapkan cara menyelesaikan konflik antara fitur add-line/QTY dan batas maksimal tiga pembayaran. **Jangan mengizinkan pembayaran keempat secara diam-diam atau melarang fitur Admin tanpa keputusan Owner.** Laporkan kasus ini sebagai decision gate sebelum implementasi perilaku tersebut.

### ACCEPTANCE
- [ ] Tiga pembayaran berhasil gabungan dihitung benar.
- [ ] Pembayaran keempat ditolak; pembayaran ketiga yang belum melunasi ditolak.
- [ ] COD parsial tidak langsung fully paid.
- [ ] Duplicate/retry/concurrent confirmation tidak menggandakan paid.
- [ ] RajaOngkir unpaid tidak dapat dispatch.
- [ ] Receipt tidak menagih seluruh order di setiap batch.
- [ ] Ongkir sekali, invoice satu, QTY/SC-03 dan refund konsisten.

---

## 9. REM-005 — Frontend, backend, API, dan runtime sinkron

**Kriteria:** `UI → API → policy → service → DB → response → UI refreshed` harus sesuai untuk setiap role/aksi.

### REQUIRED
- Checkout: provider, metode bayar, ongkir, reservasi, status.
- Admin: **edit QTY dan add-line SC-03 tetap tersedia**; stok, total, payment, invoice dan fulfillment terbarui.
- Gudang: antrean, request, quantity, short/damaged, handover.
- Koordinator: request, receipt, Kurir Toko assignment, RajaOngkir resi/status.
- Kurir: assigned jobs, POD, refusal, return.
- Keuangan: pending vs verified, paid, outstanding, max tiga.
- Konsumen: status pengiriman/tagihan yang benar.
- SKS: semua UI/API existing tetap.
- Semua role: navigation, capability, button, policy, error, loading, stale-state handling konsisten.

### AUDIT — parity belum terbukti
`primeclassy-web` melayani `public_html`, sedangkan source Vue berada di `frontend/src`; audit menemukan `frontend/dist` dan `public_html` berbeda. **Jangan mengklaim PASS dari typecheck/build source saja.**

### VERIFY
1. Catat baseline source, build output, runtime served assets, dan hash/build provenance.
2. Pelajari build/publish lokal existing; jangan membuat URL/port/container/proxy/DB baru.
3. Verifikasi aplikasi aktif pada `https://primeclassy.kmsit.localhost/`.
4. Uji aksi nyata dari frontend aktif dengan role yang tepat dan response backend.
5. Jangan menimpa `public_html` sebelum memastikan prosedur existing dan keselamatan perubahan lokal.

### ACCEPTANCE
- [ ] API dan UI payload/status/error sesuai.
- [ ] Role frontend sesuai backend; unauthorized API tetap ditolak.
- [ ] Edit QTY dan SC-03 Admin lulus browser UAT.
- [ ] Dashboard Gudang/Koordinator/Kurir/Keuangan sinkron.
- [ ] Build aktif terbukti berasal dari source yang diuji.
- [ ] SKS dan fitur tidak terkait tidak mengalami regresi.

---

## 10. REM-006 — Scoped verification dan Owner acceptance

**Agent hanya menjalankan pengujian terbatas yang relevan.** Tidak menjalankan full suite/full regression/full E2E atas nama lain tanpa persetujuan Owner. Owner memegang acceptance akhir.

| ID | Skenario wajib | Hasil |
|---|---|---|
| T01 | Checkout Kurir Toko COD | `diproses`; reserve sekali |
| T02 | Checkout non-COD | Gate existing |
| T03 | Koordinator request Gudang | Demand benar |
| T04 | Gudang & Koordinator handover | Dua konfirmasi; accepted/short/damaged benar |
| T05 | Handover retry/race | Movement tepat sekali |
| T06 | Admin edit QTY | Total/stok/demand/saldo konsisten |
| T07 | Admin SC-03 add-line | Item baru terpenuhi tanpa merusak existing |
| T08 | Assignment Kurir Toko | Hanya Koordinator; accepted quantity |
| T09 | Multi-batch setelah batch pertama | Batch berikutnya dapat diproses |
| T10 | Partial cancellation | Hanya sisa undelivered; reversal benar |
| T11 | Refusal | Dua konfirmasi balik dan disposition |
| T12 | COD/DP/transfer hingga 3 pembayaran | Akumulasi benar; ketiga lunas |
| T13 | Pembayaran keempat, retry, race | Ditolak/tidak duplikat |
| T14 | RajaOngkir kurang stok | Hold dispatch |
| T15 | RajaOngkir unpaid/COD/DP/partial | Ditolak |
| T16 | RajaOngkir full paid/full goods/resi | Valid, ekspedisi cocok |
| T17 | Ongkir/invoice | Sekali per order |
| T18 | SKS/self_sub | Regression PASS, tidak berubah |
| T19 | Cross-agent/cross-role | Forbidden |
| T20 | Browser UAT runtime aktif | Source-build-runtime terbukti sinkron |
| T21 | Admin QTY/SC-03 sesudah fulfillment parsial | Tidak merusak accepted/delivered |
| T22 | Admin tambah kewajiban setelah 3 pembayaran | **BLOCKED: keputusan Owner belum ada** |

**Bukti per REM:** baseline HEAD/dirty files, gap map, file berubah, alasan, migration jika diperlukan, perintah dan hasil scoped test, browser UAT aktif, known issues, bukti production tidak disentuh, serta verdict PASS/PASS WITH CONDITIONS/FAIL. Tidak boleh menyatakan PASS tanpa bukti.

---

## 11. Urutan pekerjaan dan stage gate

| Tahap | Prasyarat | Exit gate |
|---|---|---|
| Preflight | Otorisasi implementasi Owner **terpisah** | Read `AGENTS.md`/checkpoint, git baseline, schema, routes, runtime |
| REM-001 | Preflight | Demand dan Admin QTY/SC-03 aman |
| REM-002 | REM-001 contract | Dual receipt, stock movement tepat sekali |
| REM-003 | REM-002 custody | Dispatch provider dan partial aman |
| REM-004 | Order/financial contract | Max tiga, COD, invoice, refund aman |
| REM-005 | Kontrak API stabil | UI dan runtime aktif sinkron |
| REM-006 | Scoped changes siap | Evidence/UAT dan Owner review |

Frontend dan backend boleh dikerjakan beriringan pada REM terkait; urutan ini bukan izin meninggalkan frontend tidak sinkron.

---

## 12. Risiko wajib dikendalikan

| Risiko | Kontrol |
|---|---|
| Double stock-out/reserve release | Satu event otoritatif, lock, idempotency |
| Assign sebelum barang diterima | Accepted receipt gate backend |
| Provider tercampur | Persisted provider, bukan `standard` saja |
| COD menandai full paid | Nominal transaksi aktual dan saldo terverifikasi |
| Tagihan berulang per shipment | Order-level invoice, ongkir sekali |
| Split setelah `dikirim` gagal | Quantity/item/shipment allocation yang sah |
| Cancel menghapus delivered unit | State-specific partial reversal |
| Admin QTY/SC-03 rusak | Regression tests wajib |
| SKS berubah | Isolasi scope dan regression SKS |
| Frontend build lama | Provenance dan browser UAT |
| Dirty worktree tertimpa | Jangan reset/clean/overwrite |
| Data DEV hilang | Jangan `migrate:fresh`, `db:wipe`, destructive seed |
| Production berubah | Tidak ada akses/deploy production |

---

## 13. Hal yang belum boleh diasumsikan

1. Skema/migration yang benar-benar applied pada DEV belum diverifikasi dalam recon statis.
2. Build frontend yang paling baru dan hubungan `frontend/dist` dengan `public_html` belum dibuktikan.
3. Concurrency, worker, service worker, browser, dan provider-network runtime belum diuji.
4. **Belum ada persetujuan** nama tabel/kolom/status/endpoint baru; agent harus membuktikan gap existing dahulu.
5. **Belum ada keputusan** untuk pembayaran tambahan setelah sudah tiga pembayaran berhasil dan Admin menaikkan kewajiban.
6. Belum ada izin menghapus test data, mengubah SKS, membuat ledger biaya operasional, atau mengubah production.
7. Kolom tracking existing **tidak membuktikan** booking/tracking carrier otomatis.
8. `delivery_mode=standard` **tidak identik** dengan Kurir Toko.
9. Tidak boleh mengubah aturan bisnis berdasarkan preferensi agent; konflik dilaporkan dengan file/baris, dampak, dan opsi untuk Owner.
10. Audit bukan sertifikasi semua endpoint aman atau semua fitur frontend sudah berjalan.

## 14. Penutup / change control

**Business decisions:** APPROVED & LOCKED.  
**Architecture Lock:** v1.1 APPROVED.  
**Technical Implementation Specification:** v1.0 APPROVED sebagai acuan.  
**REM-001..006:** NOT IMPLEMENTED.  
**Migration/tests/build/UAT:** NOT EXECUTED untuk dokumen ini.  
**Production:** DO NOT TOUCH.

Perubahan keputusan bisnis memerlukan persetujuan Owner. Setelah Owner memberi otorisasi implementasi terpisah, agent melakukan inspeksi source terarah dan gap map sebelum coding, lalu scoped tests dan evidence per REM. Tidak ada auto-deploy.

**Akhir dokumen.**
