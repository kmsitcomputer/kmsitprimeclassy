# RECON-06 — RISK & TEST STRATEGY

Tanggal pemeriksaan: 2026-11-11T21:08:06+07:00 (WIB). Repository: `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy`. Branch: `fix/transaction-reset-financial-dependencies`. HEAD: `6a14041c06e6258c9f011fcb11271c7d34d65d17`.

**RECON ONLY · proposal untuk Owner Review · bukan Architecture Lock.** Source, DB, konfigurasi, dan production tidak diubah; tidak ada test/build/migration/commit/push/deploy. Bukti static tidak berarti runtime PASS.

Paket: [RECON-01](RECON-01-EXISTING-SYSTEM-INVENTORY.md) · [RECON-02](RECON-02-BUSINESS-FLOW-RBAC.md) · [RECON-03](RECON-03-DATABASE-API-MAPPING.md) · [RECON-04](RECON-04-GAP-ANALYSIS.md) · [RECON-05](RECON-05-REMEDIATION-ARCHITECTURE-PROPOSAL.md) · [RECON-06](RECON-06-RISK-TEST-STRATEGY.md) · [RECON-07](RECON-07-OWNER-DECISION-REGISTER.md)

## Batas pelaksanaan

**Tidak ada test, build, browser transaction, fixture insert atau upload yang dijalankan dalam RECON.** Semua skenario di sini adalah rencana validasi setelah implementasi diotorisasi. Test persistence/locking memakai real test DB canonical sesuai harness, bukan mocks. Tidak menjalankan full test/full regression/E2E luas dengan nama lain. Agent hanya scoped test setelah perubahan authorized; Full Test tetap Owner-exclusive dan bukan release gate.

Evidence test existing berupa pembacaan source method, bukan hasil PASS. Laporan testing di docs/14/13 dapat berumur/HEAD berbeda; tidak disalin sebagai hasil audit ini. Runtime test artefact summary lokal tidak ditemukan pada path yang diperiksa, sehingga tidak ada angka PASS baru.

## Regression risk matrix

| Area | Potential cause | Affected modules | Severity | Existing control/evidence | Mitigation proposal | Scoped test later |
|---|---|---|---|---|---|---|
| Checkout reguler | Membuka StoreOrderRequest untuk Admin/SuperAdmin secara global | OrderController/Policy/OrderService | P1 | Role gate dan server pricing; EV-11-001/005 | Bounded POS request/action; regular contract unchanged | Regular konsumen/sales/korsal/SKS checkout tetap, admin bukan akses generik baru |
| Checkout SKS | Source cart stale/source UI salah/double reserve | Catalog/cart/StockSourceResolver | P1 | Strict own-sub resolver; canonical domain locks; EV-09-001/004/005 | Satu source context per cart/order; invalidate async response; revalidate switch | Own Sub vs parent Agent; switch item unavailable; two concurrent checkouts capacity conserved |
| POS | Client mengirim owner/referrer/price/source palsu | POS action→OrderService | P1 | No existing POS; existing policy/pricing reusable | Validate chain server; actor session; Agent source forced; no fallback | All11 roles, other-Agent IDs, referral mismatch consumer; rejected call no rows/reserve/fees |
| Produk/varian | Edit atribut global option mengubah sibling atau data komersial | Variation service/compositions/resources | P2 | Shared attrs/options dan snapshots; EV-08-003; EV-11-005 | Relink variant only; partial field updates | Edit A; B label same; SKU/price/weight/fee untouched; historical label same |
| Produk/images | Loader public primary-only; delete path still referenced | ProductForm/ImageController | P1 | Image IDs/path relation; EV-03-001/002 | Management full detail, ref-aware file cleanup, stable ordering | 3-image acceptance below; shared path and failed DB deletion no corruption |
| Hard delete | Concurrent transaction reference or polymorphic history missed | Product/variant destroy/FK/registry | P1 | FK RESTRICT direct; EV-DB-002 | Dependency closure + transaction/locks; preserve retention; final FK guard | Delete vs order insert two connections; no orphan/retry partial; Admin403 |
| Inventory reservation | New path bypasses canonical target lock order | StockService/SubStockService | P1 | Canonical multi-target ordering; Sub parent→stock→reservation | Reuse canonical reservation path, no parallel stock writer | Reverse target ordering checkout/replenishment deterministic two-connection test |
| Partial fulfilment | New order path creates duplicate shipments or moves sibling units | Fulfillment/ShipmentCanonicalization | P1 | Per-item quantities/lineage + canonical shipment grouping | Shared grouping, one shipping snapshot; UI use item planned date | Two items same grouping converge; partial handover/status affects one item only |
| Cancel remaining | Cancel whole order instead of shortage remainder | OrderFulfillment/InventoryCancellation | P1 | Order/StockRequest/inventory locks; quantity conservation | Use existing cancel-remaining operation, not POS custom status | Handed-over unchanged; remainder reserve released once; replay idempotent |
| Reschedule | date input rewrites delivery actual state/provider constraint | Fulfillment/Shipment | P2 | Allowlist openroute/self_sub; EV-X-006 | Reuse policy and canonicalization; planned != actual | Split/reschedule allowed only proper mode; expedition/pickup rejected |
| Kurir assignment | POS bypasses dispatcher or auto-assigns based on referral | CourierService/ShipmentPolicy | P1 | Assigned-only execution; self_sub special rule | POS delegates assignment existing workflow | Unassigned courier no access; assigned executor correct; SKS referral not automatic courier |
| Payment/COD | Entered cash/paid value applied without finance verification | PaymentService/Summary | P1 | Pending receipt separate from verified; Order lock; EV-11-008/009 | Reuse request/verify separation; no UI toggle truth | Cash pending paid=0; verify once updates paid; COD create unpaid; concurrent replay no double |
| Invoice | Layout changes amounts/access/issue timing or renders lazy GET during audit | Invoice template/Pdf/Issuance | P1 | viewInvoice, canonical summary, snapshot; EV-10-001…004 | Presentation-only; render fixtures in test DB after authorization | Currency/totals/status identical; cross-agent403; owner hides referral; repeat issuance not duplicate |
| Referral | Requester/optional consumer replaces selected referrer | Order attribution/POS chain guard | P1 | Buyer-origin and beneficiary functions; EV-11-002/005 | Explicit distinct validated identities; never consumer-side automatic reassignment | Manual requester and optional consumer preserve selected seller; other-Agent/referred mismatch denied |
| Fee | Admin actor credited personal sales or duplicate records per dashboard | Commission/OrderService | P1 | Per item/type separate; snapshot fees; EV-11-005 | Reuse one order+line pipeline, no POS commission writer | Admin placing for SKS credits proper seller once; sales/courier separate; self purchase fee remains |
| Notifications | Retry or failed transaction sends twice | OrderNotifier/email/admin notifier | P2 | afterCommit/idempotency existing | Reuse event trigger only; no POS special engine | Rollback none; replay no duplicate; recipient policy unchanged; no real message sent by tests |
| List Order visibility | Wrong origin/creator used as ownership or broadening scope | scopedQuery/OrderPolicy/resources | P1 | Branch/owner/referral projection; EV-11-006/007 | Populate canonical identity and preserve operational projections | One primary order visible Admin/Agen/referred parties/requester by allowed scope; courier only assigned; no financial leak |
| CMS/reporting | Join payments×shipments duplicates units; return/refund subtracted twice | Bestseller aggregation/reports | P2 | Quantity/lineage/payment meanings known; EV-07-003/004 | Preaggregate at item grain; Owner-approved eligibility/date/scope | Partial/split/COD unpaid/refund/return rejected cases exact expected ranking; variants parent summed |
| Historical orders | Identity/date backfill rewrites past meaning or fails null key | orders/snapshots/exports | P1 | Write-once buyer snapshots and origin-scoped unique; EV-11-002; EV-DB-002 | Expand nullable schema, no inferred mapping; explicit legacy fallback; no notes misuse | Legacy null placed_by fails closed where required; requester migration no changed historical labels/fees/balances |
| Taxonomy/import | Cycles, duplicate SKU update, imported stock overwrites commitments | Category/import/StockService | P1 | Global SKU unique, hierarchy relation, stock writers separated | Preview safe, selected upsert policy, no direct sellable overwrite | Cyclic parent rejected; duplicate simple/variant SKU per-row error; reserved stock not overwritten |

## REM-03 acceptance wajib — hanya rencana

Dengan fixture disposable di database test dan disk test setelah implementasi diotorisasi:

1. Buat produk test yang memenuhi syarat, upload 3 gambar unik melalui endpoint resmi. Rekam 3 IDs/path dan default/order.
2. Simpan, buka Edit Produk via management ID, verifikasi 3 existing previews dan urutan; tidak ada staged duplicate.
3. Simpan tanpa perubahan gambar, refresh; row count, IDs/path dan checksums tetap 3 dan tidak ada upload request baru.
4. Tambahkan 1 gambar; refresh, count 4. Hapus 1 dari gambar existing yang dipilih; refresh.
5. Hasil akhir 3 gambar unik valid dengan preview + storage access; row/path yang masih direferensikan tidak dihapus; no stale state.
6. Ulangi regression bounded untuk inactive/draft/page>100, primary/no-primary, variant gallery terpisah, reorder, upload failure dan delete DB/storage failure.

Kasus Owner memerlukan ODR-E1 sebagai reproduksi read-only terpisah. Tidak upload 3 gambar ke DEV nyata dalam recon, tidak menduplikasi data untuk membuat klaim reproduce.

## Existing focused test inventory

| Existing test file/method | Coverage reusable | Evidence status |
|---|---|---|
| ProductSystemTest.php | Catalog CRUD/SKU/images/variations; base for new management/hard-delete/attrs tests | Source inspected; NOT RUN |
| CatalogSearchTest.php; GlobalSkuTest.php | Search name/SKU dan global namespace | Files identified, HasGlobalSku path read; NOT RUN |
| SubStockSourceTest.php | Own referral Sub reserve, Agent source, forged ownership | Methods enumerated/source resolver read; NOT RUN |
| SubStockConcurrencyTest.php; SubCheckoutConcurrencyTest.php | Sub reservation/checkout locking | Files identified, canonical paths read; NOT RUN |
| MultiTargetInventoryLockOrderTest.php:54,94,125,156 | Deterministic reverse target ordering products/variants/mixed checkout/replenishment | Methods identified; NOT RUN |
| AssistedOrderTest.php:32,54,74,102,122,211,242 | CRM origin no minted user; commission ownership; scope; no injected source; idempotency; XOR | Methods enumerated and service/model inspected; NOT RUN |
| MasterV2PaymentCoreTest.php:112,152,166,203,216,245,328 | Verification amount/self-verify deny; issuance; installment cash; private proof | Methods enumerated and relevant service path read; NOT RUN |
| InvoiceTest.php:69,79,100,108,239,294,334,356,375,393 | Role access, PDF, privacy, official logo, COD unpaid, repeat issuance | Methods enumerated, source template/controller read; NOT RUN |
| OrderFulfillmentTest.php; InventoryCancellationReturnTest.php; ReturnSystemTest.php | Partial quantities, cancellation release, return/refund flow | Files identified; not newly executed |
| OrderLineAdditionTest.php; OrderLineAdditionConcurrencyTest.php | SC-03 Admin-only and compatible inventory/payment baseline | Files identified; not newly executed |
| R04OrderProjectionTest.php; SalesCourierOrderIsolationTest.php | Operational financial-data minimization and scoped lists | Files identified; policy/list read; NOT RUN |
| frontend product/card/form/order-related specs | Hydration, source selector, attr editing and design regression focused when changed | Existing test script vitest run in package.json; suite not run |

Tidak mengklaim test existing menutup fitur yang belum ada. Perubahan baru memerlukan test meaningful terkecil, khususnya product management all-status, hard-delete guarded dependency, attribute edit, source-aware cart, ranking formula dan POS identity/RBAC/replay. Jangan hanya menguji ulang line implementation tanpa behavioral outcome.

## Rencana command setelah implementasi authorized

Pilih kelas sesuai changed areas dari tabel; jalankan satu runner canonical test DB setelah preflight lock, menggunakan `php scripts/run-tests-serialized.php tests/Feature/<kelas-terkait>.php` atau `php artisan test --filter=<kelas-terkait>` sesuai docs/14. Tambahkan focused regression baru; lalu scoped tests mencakup setiap changed area (0 failures), frontend `npm run type-check` dan `npm run build-only`, lalu `git diff --check`. Ini rencana, bukan command yang dieksekusi sekarang. Jangan menjalankan semua kelas/suite dengan nama scoped. Tidak menggunakan primeclassy_db/DEV sebagai test DB.

## Data safety dan rollback future

Read-only audit mengakses aggregate/metadata tanpa PII; PDO read-only transaction dirollback. Tidak membaca .env ke output, tidak menyalin secrets, tidak mengambil log pelanggan. Credentials koneksi lokal hanya digunakan internal oleh proses PDO yang tidak mencetak nilainya. Tidak ada SSH/query/deploy production.

Future schema changes: expand nullable, compatibility readers/writers, verify historic null/default/FK/idempotency, baru constrain bila diotorisasi. Backup+restore verification production mandatory sebelum migration, tapi produksi tidak bagian tugas ini. Future rollback code harus tetap bisa membaca nullable additions; tidak menghapus identity columns/data atau mengurangi paid/stock dengan aritmetik ad hoc. Hard delete tidak punya rollback lunak—jika disetujui perlu tested backup/retention/reference criteria. File cleanup defer until DB commit dan audit path refs; rollback transaksi DB tidak bisa mengembalikan file yang sudah dihapus.

Syarat manual DEV UAT dan Human Stage Gate tetap; Architecture Lock maupun production authorization belum diberikan.
