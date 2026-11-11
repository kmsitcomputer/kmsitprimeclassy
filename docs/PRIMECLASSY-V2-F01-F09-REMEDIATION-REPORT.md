# PrimeClassy V2 — Laporan Remediasi Audit Codex F01–F09

Tanggal: 2026-11-12 · Lingkungan: DEV lokal · Produksi: tidak disentuh
Branch `main`, HEAD `d7997c4` (tidak berubah; tidak ada commit/push/deploy).

## A. Verdict: PASS WITH CONDITIONS

Seluruh temuan F01–F09 ditutup dengan perbaikan source dan test reproduksi. Kondisi:
1. **Browser UAT belum dilakukan** (tidak ada tooling browser di sesi implementasi). Checklist manual: `PRIMECLASSY-V2-REM-MANUAL-UAT-CHECKLIST.md` (26 langkah).
2. Hasil `write_off` pada F07 adalah interpretasi minimal atas "keputusan Admin"; disposition fisik lain perlu keputusan Owner bila diinginkan.
3. Daftar F01–F09 bersumber dari prompt Owner; tiap temuan direproduksi terhadap source, bukan dicocokkan dengan teks laporan Codex asli (satu-satunya laporan Codex di repo adalah audit 2026-11-08).

## B. Baseline

- Working tree sudah kotor sejak awal (~95 file berubah/untracked: perubahan Owner + REM sebelumnya); seluruhnya dipertahankan.
- Test otomatis: database `primeclassy_testing` (container `primeclassy-fpm`, `DB_HOST=primeclassy-mariadb`). DEV DB `primeclassy_db`: hanya satu migration additive diterapkan (lihat I).
- Runtime frontend: `public_html` dilayani `primeclassy-web`.
- Node: container sementara `node:24-alpine` (`docker run --rm`), tanpa port/volume permanen.

## C. Matriks penutupan temuan

| Finding | Akar masalah | File utama | Test regresi | Status |
|---|---|---|---|---|
| F01 | Child item hasil split setelah freeze tidak punya unit snapshot, sehingga pembatalannya memakai fallback refund legacy tanpa ledger | `OrderFinancialSnapshotService` (`unitOwnerItemId`), `OrderFulfillmentService` | `PartialCancellationTest::test_f01…` | Ditutup |
| F02 | Bulk status office melewati assignment, accepted receipt, dan open refusal | `CourierService`, `OrderService` | `PartialCancellationTest::test_f02…` | Ditutup |
| F03 | Dua entry point (verifikasi transfer vs status paid langsung) menerapkan additional payment yang sama dua kali | `PaymentService`, `OrderFulfillmentService` | 2 test + race dua koneksi (`FinancialConcurrencyTest`) | Ditutup |
| F04 | `markCod(false)` menghapus `paid_amount` setelah COD parsial tetapi transaksi tetap `paid` | `PaymentService` | `KurirTokoPaymentLimitTest` (2 test F04) | Ditutup |
| F05 | Endpoint status per-item dapat memberangkatkan satu item shipment RajaOngkir | `CourierService` | `ExpeditionDispatchGateTest::test_f05…` | Ditutup |
| F06 | Assignment menghitung accepted per produk, pembatalan membalik per item | `CourierService` | `PartialCancellationTest::test_f06…` | Ditutup |
| F07 | Unit rusak dapat kembali sellable setelah reservation release | migration baru, `StockRequestProposalService`, `OrderFulfillmentService` | `StockDiscrepancyTest` (4 test) | Ditutup |
| F08 | UI Koordinator hanya mencatat resi | `KoordinatorLogisticsPanel.vue`, `FulfillmentProposalsView.vue` | `KoordinatorLogisticsPanel.spec.ts` (8 test) | Ditutup |
| F09 | Beberapa event keuangan tidak membuat snapshot saldo invoice | `PaymentService` | `AdditionalPaymentAfterRegularPaymentsTest::test_f09…` + assertion di F01 | Ditutup |

Cara kerja perbaikan:
- **F01:** child membaca unit beku lewat lineage `split_from_order_item_id`; baris beku tidak diubah (hanya ditandai terkonsumsi). Setelah snapshot handover, unit habis menghasilkan 409 `snapshot_units_exhausted`; tidak ada fallback legacy.
- **F02:** satu guard bersama (`assertKurirTokoDispatchReady`: ditugaskan + accepted per baris) dipakai pada jalur kurir per-shipment, per-item, dan bulk office. Bulk `terkirim` ditolak saat ada refusal terbuka.
- **F03:** satu urutan lock untuk semua entry point: verifikasi bukti → Order → transaksi → obligation. Kedua entry point menyelesaikan transaksi dan obligation bersamaan; percobaan kedua mendapat 422.
- **F04:** undo hanya untuk koleksi tunggal penuh (transaksi paid yang sama kembali pending); koleksi parsial ditolak (`cod_undo_not_allowed`).
- **F05:** shipment ekspedisi hanya berangkat utuh.
- **F06:** gerbang membandingkan `StockRequestItem.fulfilled_qty` per baris dengan quantity aktif baris itu; tidak ada pooling antar baris.
- **F07:** `stock_request_items.damaged_hold_qty` menahan unit rusak (tidak dapat disiapkan ulang, dilepas, atau dibatalkan). Admin menyelesaikan via `POST /warehouse/stock-request-items/{id}/resolve-discrepancy`: `restock` (hold dicabut, stok tidak berubah) atau `write_off` (Transit berkurang lewat movement `adjustment`, hold dicabut). Unit *short* tetap outstanding dan dapat disiapkan ulang. Persetujuan Admin atas proposal menjadi pengecualian: alasan wajib (min. 5 huruf) dan diaudit.
- **F08:** panel Koordinator: daftar + gerbang kesiapan, minta ke Gudang, handover, "Tandai Dikirim (seluruh pengiriman)", pagination, state loading/error.
- **F09:** satu invoice utama; snapshot saldo ditambahkan pada koleksi COD, perubahan total (`reconcileTotals`), pelunasan additional payment, dan refund diproses; idempoten (hanya bila saldo berubah).
- **Isolasi SKS:** refusal/serah-balik, minta Gudang, split batch, dan pembatalan sisa menolak shipment/baris `self_sub`; tombol "Ditolak" disembunyikan untuk `self_sub`.

## D. Integritas keuangan

- Race dua koneksi untuk F03 menemukan deadlock nyata (1213) pada versi pertama perbaikan saya; urutan lock diseragamkan dan kelas `FinancialConcurrencyTest` lulus (10 test, 306 assertion).
- Additional payment tidak dihitung sebagai pembayaran reguler ke-4; satu obligation hanya settled sekali dari arah mana pun.
- Refund diproses lewat ledger tepat sekali; retry menghasilkan 422.
- Satu invoice utama; riwayat versi hanya bertambah saat saldo benar-benar berubah.

## E. Integritas inventori dan fulfilment

- Alokasi accepted per baris: pembatalan satu baris tidak membalik stok baris lain.
- Short dan damaged dibedakan; unit rusak mempertahankan reservasi sampai Admin menyelesaikan.
- SKS dan return-pickup tidak berubah (suite regresi hijau).

## F. Keamanan dan otorisasi

- Bulk override kini tunduk pada invariant dispatch yang sama dengan jalur kurir.
- Pengecualian Admin atas proposal memerlukan alasan tercatat.
- Role dan scope cabang diperiksa ulang pada endpoint baru.

## G. Bukti test

Semua dijalankan dengan `docker exec -e DB_HOST=primeclassy-mariadb primeclassy-fpm php vendor/bin/phpunit --do-not-cache-result <kelas>` pada `primeclassy_testing`.

| Run | Hasil |
|---|---|
| Kelas REM baru + fulfilment, refund, Gudang, dispatch | 173 test, 1519 assertion, OK |
| SKS, return, courier, payment, invoice, checkout | 193 test, 1410 assertion, OK |
| Kelas yang memakai bulk override (notifikasi, laporan, riwayat, dll.) | 190 test, 1719 assertion, OK |
| `FinancialConcurrencyTest`, `PaymentProofConcurrencyTest`, `InvoiceIssuanceConcurrencyTest` | 10 test, 306 assertion, OK |
| `DispatchAssignmentConcurrencyTest` (setelah satu perbaikan fixture) | 10 test, 181 assertion, OK |

- Kelas `FulfillmentConcurrencyTest`, `FulfillmentCancellationConcurrencyTest`, `OrderLineAdditionConcurrencyTest`, `PartialCancellationConcurrencyTest` lulus pada invokasi sebelumnya (7 test) dan tidak dijalankan ulang setelah edit fixture terakhir.
- `git diff --check` bersih.
- **Full Test: TIDAK DIJALANKAN** (khusus Owner).
- Perbaikan kualitas test: predicate overlap di `ConcurrencyHarness` salah di 6 tempat (`&&` seharusnya `||`, sehingga "tidak overlap" tidak pernah terdeteksi) — sudah diperbaiki dan seluruh race tetap lulus; ditambah race F03; `AcceptsWarehouseDemand` didokumentasikan sebagai shortcut otorisasi (bukan bukti inventori), tidak menciptakan stok fisik secara default.
- Fixture yang diubah mengikuti invariant baru (bukan melemahkan assertion): ±23 fixture memakai helper `makeDispatchable()`; kasus WhatsApp "tanpa kurir" memakai shipment pickup; approval proposal di test mengirim alasan.

## H. Bukti frontend

- Type-check lulus; Vitest scoped 42 test lulus (termasuk 8 test baru panel Koordinator); build lulus.
- Dipublikasikan ke DEV tanpa `--delete`; `laravel.php` utuh. Bundle disajikan `index-B_H3YMgl.js` sama dengan hasil build dan `public_html` (SHA awalan `35e5bbb72da90657`). `/up` 200; `/api/v1/auth/me` anonim 401. Service worker ter-regenerasi dengan versi baru.
- **Browser UAT: BELUM dilakukan.**

## I. Database dan dokumentasi

- Migration additive baru (diterapkan ke DEV): `2026_11_12_100000_add_damaged_hold_to_stock_request_items` (default 0). Data DEV tidak berubah.
- Dokumen diperbarui: `05-BUSINESS-RULES.md` (§31a), `08-BACKEND-API.md`, `09-DATABASE-MODEL.md`, checklist UAT.

## J. Sisa isu

- **Keputusan Owner:** apakah `write_off` sesuai sebagai disposition unit rusak, atau diperlukan disposition fisik lain.
- **Environment:** tidak ada tooling browser.
- **Belum teruji:** panel baru, UI refusal kurir, dan field alasan approval proposal di browser sungguhan; tiga kelas concurrency (Fulfillment, Cancellation, line-addition) tidak dijalankan ulang setelah edit fixture terakhir.

## K. Keselamatan

Produksi tidak disentuh; tidak ada data dihapus atau reset destruktif; tidak ada commit/push/deploy; perubahan Owner dan perubahan tak terkait dipertahankan.

## L. Rekomendasi untuk re-audit Codex

Seluruh F01–F09 siap diverifikasi independen. Titik periksa:
- **F01:** `OrderFinancialSnapshotService::unitOwnerItemId` dan pemeriksaan exhaustion di `OrderFulfillmentService::reduceFulfillment`.
- **F02/F05/F06:** `CourierService::assertKurirTokoDispatchReady`, `assertAcceptedReceiptCovers`, guard ekspedisi; cabang bulk di `OrderService::updateStatus`.
- **F03:** urutan lock di `PaymentService::verifyBankTransfer` dan `OrderFulfillmentService::markAdditionalPaymentPaid`; race test di `FinancialConcurrencyTest`.
- **F04/F09:** `PaymentService::markCod`, `reconcileTotals`, `reverseAppliedPayment`.
- **F07:** `damaged_hold_qty`, `StockRequestProposalService::resolveDiscrepancy`.
- **Kualitas test:** predicate overlap di `tests/Support/ConcurrencyHarness.php`.
