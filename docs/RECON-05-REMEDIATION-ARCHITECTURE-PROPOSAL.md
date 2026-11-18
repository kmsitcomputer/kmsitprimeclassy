# RECON-05 — REMEDIATION ARCHITECTURE PROPOSAL

Tanggal pemeriksaan: 2026-11-11T21:08:06+07:00 (WIB). Repository: `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy`. Branch: `fix/transaction-reset-financial-dependencies`. HEAD: `6a14041c06e6258c9f011fcb11271c7d34d65d17`.

**RECON ONLY · proposal untuk Owner Review · bukan Architecture Lock.** Source, DB, konfigurasi, dan production tidak diubah; tidak ada test/build/migration/commit/push/deploy. Bukti static tidak berarti runtime PASS.

Paket: [RECON-01](RECON-01-EXISTING-SYSTEM-INVENTORY.md) · [RECON-02](RECON-02-BUSINESS-FLOW-RBAC.md) · [RECON-03](RECON-03-DATABASE-API-MAPPING.md) · [RECON-04](RECON-04-GAP-ANALYSIS.md) · [RECON-05](RECON-05-REMEDIATION-ARCHITECTURE-PROPOSAL.md) · [RECON-06](RECON-06-RISK-TEST-STRATEGY.md) · [RECON-07](RECON-07-OWNER-DECISION-REGISTER.md)

## Status proposal

Proposal ini dasar **OWNER REVIEW → ARCHITECTURE LOCK**. Tidak ada kode, migration, schema change, atau prompt implementasi dibuat. Tidak menetapkan architecture/business lock atas nama Owner. Paket 11 item mengikuti instruksi terbaru Owner; keputusan locked tidak dibuka kembali.

## Urutan dependensi yang disarankan

1. Owner menyediakan bukti gambar lokal dan dua mockup, lalu mereview keputusan terbuka RECON-07. Tidak perlu akses produksi untuk memulai desain.
2. REM-03 + REM-05 management read/loader: prioritas data existing yang benar, default Semua dan pagination; preserve public active endpoint.
3. REM-02 + REM-08 variant media/structured composition edit, sesudah management detail lengkap; REM-06 hierarchy memakai relation yang sudah ada.
4. REM-04 guarded delete setelah dependency closure/retention audit; jangan purge media/SoftDeleted historical catalogue sebagai bagian fitur.
5. REM-01 import/export setelah schema field contract, duplicate/upsert dan stock-import policy disetujui; bukan menulis stok langsung.
6. REM-09 source-aware SKS catalogue/cart/checkout dengan resolver existing; jalur ini independen dari POS Agen.
7. REM-07 ranking setelah net-sale eligibility/date/scope contract; REM-10 visual invoice setelah mockup.
8. REM-11 POS setelah Owner menyetujui identity/date/Admin source; reuse service pipeline dan scoped validation kemudian DEV UAT/gate tersendiri. Tidak otomatis dilanjutkan oleh recon ini.

## Proposal terukur per item

Kelas: 1 Frontend only; 2 Backend/API; 3 Existing DB adjustment; 4 Existing service reuse; 5 Authorization; 6 UI/design; 7 Data remediation separate approval; 8 Blocked Owner decision. Class 7 tidak diusulkan eksekusinya: hanya bila audit data berikut membuktikan kebutuhan.

| Requirement ID | Class | Evidence | Affected files/modules | Minimal adjustment/reason | Data compatibility | Regression risk | Acceptance criteria | Scoped validation later |
|---|---|---|---|---|---|---|---|---|
| REM-01 | 2,4,5,8 | EV-01-001/002/003; GAP-01-001 | Catalog controllers/requests; ProductVariationService; HasGlobalSku; PhpSpreadsheet; ProductManagementView/catalog.ts | Batch preview→same validated apply contract; DTO/payload row errors, SKU namespace dan duplicate policy, category leaf mapping; URL-image import tidak diasumsikan | Existing catalog unchanged sebelum apply; stock snapshot export tidak jadi instruksi overwrite | P1 wrong upsert/tenant stock; size, rows, MIME and xlsx decompression bounds disetujui sebelum coding | CSV/XLSX roundtrip; preview zero-write; row error/duplicate; rollback batch sesuai agreed policy | ProductSystemTest, GlobalSkuTest, new focused product import/export DB scenarios; frontend type-check/build |
| REM-02 | 2,4,6 | EV-02-001…004; GAP-02-001 | ProductImageController/Request/Resource; ProductFormView; ProductDetailView; catalog.ts | Gunakan product_variation_id existing; parent images=variant NULL; variant gallery terpisah, primary/reorder scope sesuai gallery; upload enforce variant parent | Gambar existing variant=NULL tetap parent; jangan pindah row berdasarkan tebakan | P2 sibling primary/path refs; fallback parent bila variant tanpa image perlu tampilan konsisten | Selector memilih gallery benar; add/edit variant CRUD media; forged parent mismatch ditolak | ProductSystemTest image variants and scoped media ownership cases; frontend selector/cart image |
| REM-03 | 2,4,6 | EV-03-001…006; GAP-03-001/002 | ProductController/Resource; ProductFormView.load/preview; ProductImageController | Management detail ID role-gated, full images+inactive variants; stable sort, catch/finally; preserve existing IDs; reference-aware delete dan reorder | No-change edit tidak rewrite gambar; tidak data cleanup atau duplicate delete | P1 hidden gallery menyebabkan re-upload; file/DB transaction mismatch | Skenario acceptance 3 gambar dan save no-change persis RECON-06 | ProductSystemTest full detail/primary/no-change; frontend hydration; filesystem failure scoped |
| REM-04 | 2,4,5 | EV-04-001…005; EV-DB-002; GAP-04-001 | Catalog destroy path; ProductPolicy; HasGlobalSku; Storage/reference audit | Guarded hard delete service, FK RESTRICT final guard dan per-target locks; block critical deps; cleanup registry/files post-commit terkontrol | Tidak remove SoftDeletes historical rows, tidak undelete/purge massal | P1 historical invoice/inventory reference & races; audit retention harus eksplisit | Truly unreferenced hard delete; referenced fail without mutation; inactive alternative; Admin 403 | Real DB FK and deterministic two-connection delete-vs-order/reference; no mocks replacing integrity |
| REM-05 | 2,5,6 | EV-05-001/002; EV-03-001/002; GAP-05-001 | Management API/controller query; ProductManagementView; catalog.ts | Default Semua; search+category/subcategory/status sebelum paginate; backend total dan sort tie-break; detail ID untuk reactivation | Public endpoint tetap active dan tidak expose internal fee/draft | P1 public leakage/paging stale response; cap per_page proposed | Counts across all status; page>100 accessible; reactivation valid; public unchanged | CatalogSearchTest/ProductSystemTest authorized list matrix; frontend filter/pagination |
| REM-06 | 2,4,6 | EV-06-001…003; GAP-06-001 | Category controller/requests/resource; ProductManagementView/ProductFormView/ProductListingView; CMS resolver | Reuse self-tree; parent+child UI with leaf category_id; descendant filter contract, anti-cycle/depth; preserve super_admin category writes | Root-only historical products keep category_id; no forced assignment | P2 cycle/parent deletion/descendant CMS filtering behavior | Parent-child UI persists/filters; invalid cycle rejected; old root products still work | ProductSystemTest taxonomy cases; CMS category content; import mapping later |
| REM-07 | 2,4,6,8 | EV-07-001…004; GAP-07-001 | HomepageBlockTypes/Controller/Service; CMS form; ProductBlock/renderer | New logical block behavior using existing CMS JSON/type; aggregate net eligible item units by product before fanout; limit4/8/12 period7/30/90/all; empty hide | Existing manual product blocks unchanged; no historical quantity rewrite | P2 incorrect ranking or leakage; cache key must include chosen scope/timezone/period and invalidate/recompute after return/payment/catalog changes | Deterministic net units fixtures; options/settings; no partial-shipping double count; active saleable only | Focused CMS tests + new ranking DB scenarios covering split/refund/payment; query-plan check on approved representative local volume |
| REM-08 | 2,4,6 | EV-08-001…004; GAP-08-001 | Variation update request/controller/service/resource; ProductFormView edit | Expose structured IDs/name/value; variant composition partial update transaction; reuse/create options then relink, avoid sibling shared-option rename side effects | Snapshot order labels/SKU/prices remain; do not reset commercial/fee values on attribute-only edit | P2 sibling label/combination conflict and concurrent edit | Load/rename/value/add/delete; omitted fields preserved; sibling and historical unaffected | ProductSystemTest variation edit DB regressions, SKU uniqueness; focused frontend form |
| REM-09 | 2,4,5,6 | EV-09-001…006; GAP-09-001 | ProductListing/Detail/Cart/Checkout; cart store; orders/catalog API; availability adapter | SKS source selector in catalogue; cart-level source+owner context, revalidate switch; backend derives ownership and uses existing single-source reserve | Old pc-cart defaults Agent with revalidation, not silent Sub inference; created order source unchanged | P1 cross-Agent, stale async catalogue/source, overcommit and double reserve | Catalogue both sources; source→cart→order preserved; switch invalid qty blocked; Sub own only; POS Agent own stock | SubStockSourceTest/SubCheckoutConcurrencyTest/MultiTargetInventoryLockOrderTest scoped later; frontend source race cases |
| REM-10 | 6,4,8 | EV-10-001…004; GAP-10-001 | pdf/invoice.blade.php; InvoicePdfService branding; optional shared print presentation | Use approved invoice reference; white maroon; logo/wordmark left metadata right; renderer-compatible tables/styles, multi-page footer; no external logo fetch | No change totals, statuses, invoice_no, issuance, access, PaymentSummaryService | P2 overflow/font/logo ratios; financial identities hidden in owner copy | Browser/PDF/print Owner compare; COD unpaid shown correctly; numbers identical; long items safe | InvoiceTest/InvoiceNumberTest; scoped PDF render fixtures & visual comparison; no recon endpoint rendering |
| REM-11 | 2,3,4,5,6,8 | EV-11-001…011; GAP-11-001/002 | POS new UI/action bounded within existing app; OrderService/Policy; orders schema if approved; payment/shipping/referral existing | One page two-column, no sidebar/dashboard; dedicated actor/chain guard. Required requester account/manual, optional consumer, recipient distinct. Actor server-set; Agent stock forced own; Admin source ODR-01. Build one primary order with existing transactions/fees/events | Additive nullable identity/date changes only after decisions; no remap historical orders; no account auto-create or fake Sales; no parallel ledger | P1 origin identity/referral errors, fee duplication, NULL idempotency holes, accidental Admin verification grant | RBAC 11-role matrix; optional consumer works; manual no login; cross-agent/mismatch denied; one order lists; valid payment only updates remaining; planned date ≠ delivered | New focused POS DB integration/authorization/idempotency + reused inventory/payment/invoice/commission classes; frontend two-column flow and required fields |

## Dua paket penyesuaian schema yang masih proposal

**S1 — requester identity / optional consumer / origin idempotency (REM-11, ODR-02).** Evidence EV-11-002/003/004 dan EV-DB-002 membuktikan origin sekarang akun XOR CRM; tidak boleh menaruh requester di recipient/notes atau memaksakan CRM untuk setiap manual requester. Usulkan penambahan relasi/kolom snapshot yang nullable pada orders, serta unique idempotency scope yang tetap bekerja bila buyer account/CRM tidak dipakai. Nama final, FK behavior, null defaults dan key scope belum dikunci. Account consumer/referral validation independen wajib. Existing buyer snapshot bisa reuse hanya bila semantiknya konsisten dan readers/exports aman.

**S2 — business transaction date (REM-11, ODR-05).** created_at/paid_at/invoice_issued_at/requested_delivery_date mempunyai semantik berbeda. Jika POS mengizinkan tanggal transaksi input, usulkan kolom tanggal bisnis aditif di orders; jangan mengganti created_at atau paid_at. Timezone WIB dan batas backdate/reporting/effective price memerlukan keputusan Owner. Tidak ada backfill otomatis ke tanggal tebakan; historical display fallback harus eksplisit dan diuji.

Belum ada bukti kebutuhan tabel POS baru. Seluruh state transaksi tetap orders/order_items/shipments/payment/refund/commission/invoice tables existing. Feasibility final menunggu keputusan identity/source/date; jika kebutuhan kemudian benar-benar memerlukan tabel baru, status **BLOCKED — OWNER DECISION REQUIRED**, bukan membuat tabel atau raw JSON workaround. Tidak ada indeks baru lain diusulkan tanpa query-plan evidence.

## Dua belas reuse proposals

| Reuse ID | Existing reuse | Requirements |
|---|---|---|
| R01 | Product/ProductVariation + HasGlobalSku/catalog_skus | REM-01/04/08/11 |
| R02 | ProductImage/public storage/catalog.ts | REM-02/03 |
| R03 | ProductCategory parent/children | REM-01/05/06/07 |
| R04 | OrderService/OrderTotalCalculator/PricingService/VoucherService | REM-09/11 |
| R05 | StockSourceResolver + SubLocationOwnershipService | REM-09/11 |
| R06 | StockService/WarehouseStockService/SubStockService + movements/reservations | REM-01/09/11 |
| R07 | OrderFulfillmentService/ShipmentCanonicalizationService/CourierService | REM-09/11 |
| R08 | PaymentService/PaymentSummaryService/AvailablePaymentMethodService + existing ledgers | REM-10/11 |
| R09 | HierarchyRules/OrderPolicy/CrmAccess/branch scopes | REM-09/11 |
| R10 | HomepageBlockTypes/content JSON/renderer/ProductBlock | REM-07 |
| R11 | InvoicePdfService/IssuanceService + official branding + snapshots | REM-10/11 |
| R12 | pc-internal tokens/AppButton/PrimeFileUpload; existing OrderNotifier/event/audit | REM-01/02/03/10/11 |

## Design lock dan compatibility

Invoice: layout putih/maroon dari reference Owner, logo asli bundled, bukan gambar logo tiruan mockup. Nominal/status/numbering dan akses tetap dari existing. POS: dua kolom satu halaman tanpa sidebar; kiri identities, consumer/requester, shipping, payment, notes; kanan searchable catalogue, variant, cart, totals, submit. Tokens pc-internal (maroon #8B2635, canvas #160D10, surface #29171C, cream #F2D0AB, gold #D8A66C, radius8/12/18, system-ui) mengikuti dashboard source. Hindari DashboardLayout jika otomatis menampilkan sidebar; reuse CSS namespace/control tanpa shell side effects. Tidak memakai purple mockup jika berlawanan tokens. Actual mockup masih ODR-E2/E3; tidak membuat substitusi.

Historical compatibility: snapshot item price/fee/label tetap; immutable invoice/report/refund tidak di-backfill. Root-only categories tetap; images variant NULL tetap parent. Old carts perlu source default Agent dan explicit revalidation. New identity/action tidak mengubah create regular SKS atau SC-03 Admin-only. Paid amount hanya writer PaymentService; Finance verification tidak diberikan ke Agen/Admin karena UI POS.

Data remediation apa pun (duplicate purge, deleted-row cleanup, ownership remap) **tidak termasuk proposal otomatis**. Jika ditemukan actual invalid references, minta approval terpisah setelah reference audit. Arsitektur final belum locked.
