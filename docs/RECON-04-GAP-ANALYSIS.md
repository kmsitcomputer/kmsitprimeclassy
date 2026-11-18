# RECON-04 — GAP ANALYSIS

Tanggal pemeriksaan: 2026-11-11T21:08:06+07:00 (WIB). Repository: `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy`. Branch: `fix/transaction-reset-financial-dependencies`. HEAD: `6a14041c06e6258c9f011fcb11271c7d34d65d17`.

**RECON ONLY · proposal untuk Owner Review · bukan Architecture Lock.** Source, DB, konfigurasi, dan production tidak diubah; tidak ada test/build/migration/commit/push/deploy. Bukti static tidak berarti runtime PASS.

Paket: [RECON-01](RECON-01-EXISTING-SYSTEM-INVENTORY.md) · [RECON-02](RECON-02-BUSINESS-FLOW-RBAC.md) · [RECON-03](RECON-03-DATABASE-API-MAPPING.md) · [RECON-04](RECON-04-GAP-ANALYSIS.md) · [RECON-05](RECON-05-REMEDIATION-ARCHITECTURE-PROPOSAL.md) · [RECON-06](RECON-06-RISK-TEST-STRATEGY.md) · [RECON-07](RECON-07-OWNER-DECISION-REGISTER.md)

## Verdict dan cara hitung

**RECON PARTIAL — EVIDENCE GAPS REMAIN.** Semua 11 item disentuh. Satu requirement audit adalah satu baris register di bawah; beberapa kewajiban yang satu jalur dikelompokkan dan tertulis eksplisit. Angka ini bukan estimasi fitur atau test case. VERIFIED berarti keberadaan/gap source cukup terbukti, bukan fitur sudah benar; BLOCKED adalah bukti/definisi yang belum dapat diselesaikan read-only. Row NOT IMPLEMENTED tetap dapat audit VERIFIED.

| Item | Requirements | Verified | Partial | Blocked | Not started | Gap confirmed | Severity | Audit status | Coverage |
|---|---|---|---|---|---|---|---|---|---|
| REM-01 | 9 | 7 | 2 | 0 | 0 | 1 | P1 | PARTIAL | 77.8% |
| REM-02 | 8 | 7 | 1 | 0 | 0 | 1 | P2 | PARTIAL | 87.5% |
| REM-03 | 13 | 8 | 4 | 1 | 0 | 2 | P1/P2 | PARTIAL | 61.5% |
| REM-04 | 8 | 7 | 1 | 0 | 0 | 1 | P1 | PARTIAL | 87.5% |
| REM-05 | 7 | 6 | 1 | 0 | 0 | 1 | P1 | PARTIAL | 85.7% |
| REM-06 | 8 | 8 | 0 | 0 | 0 | 1 | P2 | VERIFIED | 100.0% |
| REM-07 | 10 | 7 | 2 | 1 | 0 | 1 | P2 | PARTIAL | 70.0% |
| REM-08 | 8 | 8 | 0 | 0 | 0 | 1 | P2 | VERIFIED | 100.0% |
| REM-09 | 9 | 8 | 1 | 0 | 0 | 1 | P1 | PARTIAL | 88.9% |
| REM-10 | 8 | 6 | 1 | 1 | 0 | 1 | P2 | PARTIAL | 75.0% |
| REM-11 | 29 | 25 | 2 | 2 | 0 | 2 | P1 | PARTIAL | 86.2% |

**Audit Coverage = (97 / 117) × 100% = 82.9%.** Partial 15; blocked 5; not started 0. Item examined 11/11 bukan coverage 100%.

| Metrik | Aktual | Definisi |
|---|---|---|
| Finding severity | P0=0; P1=7; P2=6; P3=0 | 13 bounded gap findings, bukan setiap row feature absence |
| Gap confirmed | 13 | Gap ID di bawah; root cause source atau absence, bukan confirmed runtime semua kasus |
| Gap belum terverifikasi | 4 | HYP-03-A semua image invisible; HYP-03-B duplicate referenced files; HYP-07-A net-sale formula eligible; HYP-11-A complete no-new-table feasibility |
| Endpoint mapped | 28 | Method+path unik di RECON-03; bukan seluruh API app |
| UI components mapped | 15 | ProductListingView, ProductDetailView, ProductManagementView, ProductFormView, CartView, CheckoutView, OrderHistoryView, OrderDetailView, CrmAssistedOrderView, InvoiceConfigView, HomepageBlockRenderer, ProductBlock, CmsHomepageBlockFormView, OrderPaymentTools, PrimeFileUpload |
| DB metadata inventory | 123 | COUNT information_schema.TABLES lokal; bukan 123 tabel dibaca seluruh isinya |
| Tabel dipetakan field-level | 34 | Tabel unik pada field matrix; tidak menyamakan breadth dengan full data audit |
| FK direct diperiksa | 31 | 20 referencing tables ke product/variant; ditambah model relations pada field matrix |
| Owner decisions baru | 5 | ODR-01…05; aset/bukti ODR-E1…E3 terpisah, bukan membuka lock |
| Schema adjustment proposals | 2 | S1 identity/idempotency; S2 business transaction date. Jumlah kolom/index belum final |
| Reuse proposals | 12 | Daftar bernomor RECON-05; bukan jumlah file/classes |
| Tests run | 0 | Tidak full/scoped test, build, atau transaksi uji |

## REM-01 — requirement register

| Requirement ID | Requirement(s) | Audit status | Existing conformance | Observed / gap | Evidence |
|---|---|---|---|---|---|
| REM-01-R01 | Import CSV dan XLSX | VERIFIED | NOT IMPLEMENTED | Belum ada product import endpoint/service/UI | EV-01-001; EV-01-003 |
| REM-01-R02 | Export CSV dan XLSX | VERIFIED | NOT IMPLEMENTED | Export regions/CRM/Sheets bukan export katalog produk | EV-01-001; backend/routes/api_v1.php:919–921 |
| REM-01-R03 | Template field SKU/harga/varian/kategori/stok/status | VERIFIED | NOT IMPLEMENTED | Mapping 57 field ada RECON-03; template produk belum | EV-01-002; EV-05-002; EV-06-001 |
| REM-01-R04 | Validation dan preview sebelum apply | VERIFIED | NOT IMPLEMENTED | CRUD validators bukan preview batch | EV-01-001; EV-01-002 |
| REM-01-R05 | Error per row dan duplicate/upsert aman | VERIFIED | NOT IMPLEMENTED | Registry SKU tersedia, batch policy tidak ada | EV-01-002 |
| REM-01-R06 | RBAC/file security/transaction boundary import | VERIFIED | NOT IMPLEMENTED | Policy CRUD existing; import limits/type/atomicity belum | EV-04-005; EV-02-003 |
| REM-01-R07 | Harga dan stock ownership/stock write | PARTIAL | UNKNOWN | Stok adalah domain per Agen/Sub; izin overwrite import perlu keputusan ODR-03 | EV-09-005; EV-11-005 |
| REM-01-R08 | Gambar dan URL eksternal | VERIFIED | PARTIALLY COMPLIANT | Upload gambar existing; tidak ada product import URL pipeline | EV-02-002/003 |
| REM-01-R09 | Batas volume dan performance batch | PARTIAL | UNKNOWN | Belum ada batch path atau evidence load; tidak benchmark | EV-01-001 |

## REM-02 — requirement register

| Requirement ID | Requirement(s) | Audit status | Existing conformance | Observed / gap | Evidence |
|---|---|---|---|---|---|
| REM-02-R01 | Relasi gambar khusus varian | VERIFIED | COMPLIANT | product_variation_id existing nullable | EV-02-001 |
| REM-02-R02 | Gallery varian terpisah dari parent | VERIFIED | GAP CONFIRMED | Product::images belum filter variant; detail menampilkan semua | EV-02-004; EV-03-002 |
| REM-02-R03 | Selector varian mengubah active image | VERIFIED | GAP CONFIRMED | Selector tidak mengubah gallery index/filter | EV-02-004; ProductDetailView.vue:204–208 |
| REM-02-R04 | Form tambah/edit varian mengelola gambar | VERIFIED | GAP CONFIRMED | Upload form umum belum memberikan opts variant id | EV-03-001; EV-08-001 |
| REM-02-R05 | API upload validation dan parent ownership | VERIFIED | PARTIALLY COMPLIANT | Real image 5MiB; exists variant belum product ownership | EV-02-003 |
| REM-02-R06 | Storage URL | VERIFIED | COMPLIANT | Storage public URL pada resource | EV-03-005 |
| REM-02-R07 | Delete/reorder/primary per varian | VERIFIED | GAP CONFIRMED | Delete langsung; update hanya primary parent-wide; reorder belum | EV-02-002 |
| REM-02-R08 | Cart/order/invoice image usage | PARTIAL | PARTIALLY COMPLIANT | Cart parent image; order snapshots label/price, visual variant belum runtime | EV-02-004; EV-11-005; EV-10-001 |

## REM-03 — requirement register

| Requirement ID | Requirement(s) | Audit status | Existing conformance | Observed / gap | Evidence |
|---|---|---|---|---|---|
| REM-03-R01 | Data DB dan relasi gambar existing | VERIFIED | UNKNOWN | SELECT images=0; tidak ada data kasus Owner di DB lokal | EV-DB-001 |
| REM-03-R02 | Admin/detail versus storefront payload | VERIFIED | GAP CONFIRMED | Edit memakai public index primary-only; storefront detail all images | EV-03-001/002 |
| REM-03-R03 | Hydration dan inisialisasi form | VERIFIED | GAP CONFIRMED | List active first100, find id; bukan full detail fetch | EV-03-001 |
| REM-03-R04 | Existing preview versus new-upload preview | VERIFIED | PARTIALLY COMPLIANT | Existing dari product.images; staged hanya create | EV-03-004 |
| REM-03-R05 | Save tanpa image changes | VERIFIED | COMPLIANT | Tidak mengirim image list atau upload ulang existing | EV-03-003 |
| REM-03-R06 | Upload baru dan reload | VERIFIED | PARTIALLY COMPLIANT | Sequential post lalu load index; multiple primary flag mungkin saat gallery kosong | EV-03-001; EV-02-002 |
| REM-03-R07 | Delete dan file references | PARTIAL | PARTIALLY COMPLIANT | Delete file dahulu tanpa audit shared paths; lokal images kosong | EV-02-002; EV-DB-001 |
| REM-03-R08 | Reorder dan primary | VERIFIED | GAP CONFIRMED | sort_order ada tetapi PATCH hanya primary | EV-02-002 |
| REM-03-R09 | Storage URL dan akses file aktual | PARTIAL | UNKNOWN | Generator URL terbukti; tidak ada file referenced untuk dibuktikan valid | EV-03-005; EV-DB-001 |
| REM-03-R10 | Cache/service worker | PARTIAL | PARTIALLY COMPLIANT | SW bypass API/storage; cache edge/browser actual belum diperiksa | EV-03-006 |
| REM-03-R11 | Duplicate records/file usage | PARTIAL | UNKNOWN | 0 duplicate groups pada empty set; tidak membuktikan kasus Owner | EV-DB-001 |
| REM-03-R12 | Reproduksi laporan semua gambar hilang pada produk sama | BLOCKED | UNKNOWN | Butuh contoh lokal read-only; tidak upload/create fixture | EV-DB-001 |
| REM-03-R13 | Acceptance upload3-save-refresh-add1-delete1 | VERIFIED | NOT IMPLEMENTED | Dirancang RECON-06, sengaja NOT EXECUTED | EV-03-001/002/003 |

## REM-04 — requirement register

| Requirement ID | Requirement(s) | Audit status | Existing conformance | Observed / gap | Evidence |
|---|---|---|---|---|---|
| REM-04-R01 | Hard delete produk/varian Opsi A | VERIFIED | GAP CONFIRMED | Keduanya SoftDeletes dan delete biasa | EV-04-001/002/003/004 |
| REM-04-R02 | Order/invoice/payment dependency | VERIFIED | PARTIALLY COMPLIANT | order_items FK RESTRICT; invoice/payment via order; app dependency preflight belum | EV-DB-002; EV-X-005 |
| REM-04-R03 | Inventory movement/request/opname/reservation dependency | VERIFIED | PARTIALLY COMPLIANT | RESTRICT links daftar lengkap RECON-03; soft delete tidak diblok FK | EV-DB-002 |
| REM-04-R04 | Audit/registry/polymorphic references | PARTIAL | UNKNOWN | SKU registry dan audit morph bukan FK; full retention closure belum dibuktikan | EV-01-002; docs/02:125–129 |
| REM-04-R05 | Cascade/restrict/nullable/index inspection | VERIFIED | COMPLIANT | 31 FK langsung dan indeks enam target diperiksa | EV-DB-002 |
| REM-04-R06 | Authorization delete | VERIFIED | COMPLIANT | Admin tetap tidak boleh delete | EV-04-005; EV-X-002 |
| REM-04-R07 | Concurrency dan transaction delete | VERIFIED | GAP CONFIRMED | Belum guarded hard-delete transaction/reference recheck | EV-04-003/004 |
| REM-04-R08 | Fallback inactive saat blocked | VERIFIED | PARTIALLY COMPLIANT | Status inactive ada; message/delete dependency UX belum | EV-05-002; EV-04-003 |

## REM-05 — requirement register

| Requirement ID | Requirement(s) | Audit status | Existing conformance | Observed / gap | Evidence |
|---|---|---|---|---|---|
| REM-05-R01 | Semua/Aktif/Draft/Nonaktif dan default Semua | VERIFIED | GAP CONFIRMED | Enum sudah; public list dipakai management hanya active | EV-05-001; EV-03-002 |
| REM-05-R02 | Search nama/SKU | VERIFIED | PARTIALLY COMPLIANT | Backend public search ada; management UI belum | EV-03-002; EV-05-001 |
| REM-05-R03 | Kategori/subkategori/status combined filters | VERIFIED | GAP CONFIRMED | Public category slug exact; management tidak memberi kombinasi | EV-03-002; EV-05-001 |
| REM-05-R04 | Backend pagination dan count | VERIFIED | PARTIALLY COMPLIANT | API paginate ada; management mengabaikan meta first100 | EV-05-001; EV-03-002 |
| REM-05-R05 | Storefront active + sale eligibility | PARTIAL | PARTIALLY COMPLIANT | Active filtering & active variant ada; validasi jual final di OrderService; tidak runtime semua eligibility | EV-03-002; EV-11-005 |
| REM-05-R06 | RBAC dan fee exposure | VERIFIED | COMPLIANT | Public resource tanpa fee; management read mesti role gate sendiri | EV-04-005; backend/app/Http/Resources/ProductResource.php:9–16 |
| REM-05-R07 | Edit/reactivation draft/inactive dan item page>100 | VERIFIED | GAP CONFIRMED | Loader active index tidak bisa mengakses target tersebut | EV-03-001/002 |

## REM-06 — requirement register

| Requirement ID | Requirement(s) | Audit status | Existing conformance | Observed / gap | Evidence |
|---|---|---|---|---|---|
| REM-06-R01 | Hierarchy category→subcategory existing | VERIFIED | COMPLIANT | parent_id/parent/children sudah | EV-06-001 |
| REM-06-R02 | Subcategory management UI | VERIFIED | GAP CONFIRMED | Quick-add/rename flat; parent API belum diekspos | EV-05-001; EV-06-002 |
| REM-06-R03 | Product form parent/child selection | VERIFIED | GAP CONFIRMED | Satu category_id selector flat | EV-03-001; EV-06-001 |
| REM-06-R04 | Storefront filter hierarchy | VERIFIED | PARTIALLY COMPLIANT | category slug exact; bukan descendant traversal | EV-03-002 |
| REM-06-R05 | Import/export hierarchy integration | VERIFIED | NOT IMPLEMENTED | Belum product import/export | EV-01-001 |
| REM-06-R06 | Compatibility produk existing | VERIFIED | COMPLIANT | category nullable dan parent nullable; tidak perlu remap historis | EV-05-002; EV-06-001 |
| REM-06-R07 | Cycle/depth dan delete parent semantics | VERIFIED | PARTIALLY COMPLIANT | Parent exists tanpa anti-cycle; FK nullOnDelete | EV-06-003; schema RECON-03 |
| REM-06-R08 | CMS taxonomy dependencies | VERIFIED | PARTIALLY COMPLIANT | category_ids dan category_id existing; filter descendants belum | EV-07-001/002 |

## REM-07 — requirement register

| Requirement ID | Requirement(s) | Audit status | Existing conformance | Observed / gap | Evidence |
|---|---|---|---|---|---|
| REM-07-R01 | Aktual unit net sales ranking bukan views/random/manual | VERIFIED | NOT IMPLEMENTED | Product block manual/category limit, tanpa unit aggregation | EV-07-001/002 |
| REM-07-R02 | Variant aggregate ke parent | VERIFIED | NOT IMPLEMENTED | product_id per order item tersedia; ranking belum | EV-X-005; EV-07-002 |
| REM-07-R03 | Definisi order/payment valid dan tanggal periode | BLOCKED | UNKNOWN | Existing financial/operational projections berbeda; ODR-04 diperlukan | EV-07-003/004; EV-11-009 |
| REM-07-R04 | Cancel/refund/return net semantics | PARTIAL | UNKNOWN | returned dimulai saat request; refund processed kemudian; formula belum final | EV-07-003 |
| REM-07-R05 | Partial fulfilment anti-double-count | VERIFIED | NOT IMPLEMENTED | Lineage/fulfilled tersedia; bestseller query belum | EV-X-005; EV-07-002 |
| REM-07-R06 | COD tidak otomatis lunas | VERIFIED | COMPLIANT | Initial COD payment unpaid; paid dari PaymentService | EV-11-004/009 |
| REM-07-R07 | Active/sellable product dan hide empty | VERIFIED | PARTIALLY COMPLIANT | Active filter dan ProductBlock v-if products.length ada; net ranking eligibility belum | EV-07-002; ProductBlock.vue:21–28 |
| REM-07-R08 | CMS enable/title/description/count4/8/12/period/order | VERIFIED | PARTIALLY COMPLIANT | is_active/sort/content reuse; period/strict limit options belum | EV-07-001/002 |
| REM-07-R09 | Grid/carousel renderer | VERIFIED | PARTIALLY COMPLIANT | ProductBlock grid ada; carousel bestseller belum | frontend/src/components/cms/blocks/ProductBlock.vue:21–40 |
| REM-07-R10 | Caching/reporting/performance ranking | PARTIAL | UNKNOWN | Tidak ada ranking cache/query plan; volume dan key scope belum final | EV-07-002/004 |

## REM-08 — requirement register

| Requirement ID | Requirement(s) | Audit status | Existing conformance | Observed / gap | Evidence |
|---|---|---|---|---|---|
| REM-08-R01 | Create/edit difference dan load attributes existing | VERIFIED | GAP CONFIRMED | Create structured map; edit/resource hanya label+commercial fields | EV-08-001/002/004 |
| REM-08-R02 | Rename key dan change value | VERIFIED | NOT IMPLEMENTED | PATCH ignores attributes; immutable composition source | EV-08-002; EV-04-004 |
| REM-08-R03 | Add/delete attribute | VERIFIED | NOT IMPLEMENTED | Create helper ada, edit tidak | EV-08-001 |
| REM-08-R04 | Model/serialization/transaction | VERIFIED | PARTIALLY COMPLIANT | Shared attrs/options+composition transaction create; update path belum | EV-08-003/004 |
| REM-08-R05 | Preserve SKU/harga/berat/fee unchanged | VERIFIED | PARTIALLY COMPLIANT | Separate update/fee calls; future attribute-only update harus partial | EV-08-001; EV-04-004 |
| REM-08-R06 | Historical order snapshot | VERIFIED | COMPLIANT | Label/SKU/price snapshot per item; bukan live label invoice | EV-11-005; EV-X-005 |
| REM-08-R07 | Uniqueness varian/combination | VERIFIED | PARTIALLY COMPLIANT | SKU global unique; composition unique per attribute; duplicate combination policy belum | EV-01-002; EV-DB-002 |
| REM-08-R08 | Storefront labels dan gambar REM-02 | VERIFIED | PARTIALLY COMPLIANT | Label existing; structured selector/image update masih gap | EV-08-004; EV-02-004 |

## REM-09 — requirement register

| Requirement ID | Requirement(s) | Audit status | Existing conformance | Observed / gap | Evidence |
|---|---|---|---|---|---|
| REM-09-R01 | SKS role/parent Agen/own Sub ownership | VERIFIED | COMPLIANT | Server resolver strict SKS/referral/branch/location | EV-09-001 |
| REM-09-R02 | Dua source terlihat di katalog SKS | VERIFIED | GAP CONFIRMED | ProductController hanya attach Agent availability | EV-03-002 |
| REM-09-R03 | Source state diteruskan cart→checkout→order | VERIFIED | GAP CONFIRMED | Cart/order API client belum field source | EV-09-002/003 |
| REM-09-R04 | Single source per order dan immutable attribution | VERIFIED | PARTIALLY COMPLIANT | Backend resolve sekali dan item source; UI source state belum | EV-09-004; EV-X-005 |
| REM-09-R05 | Switch source revalidation/async race | VERIFIED | NOT IMPLEMENTED | Belum source switch action/frontend validation | EV-09-002/003 |
| REM-09-R06 | Cross-Agent/forged Sub/source validation | VERIFIED | COMPLIANT | 403 ownership dan 422 missing/mismatch source context di resolver | EV-09-001 |
| REM-09-R07 | Reservation/deduction/release partial cancel/return | VERIFIED | PARTIALLY COMPLIANT | Domain services exist; frontend source capability missing | EV-09-004/005/006; EV-07-003 |
| REM-09-R08 | Concurrency/idempotency | PARTIAL | PARTIALLY COMPLIANT | Canonical locks+origin replay ada; tidak menjalankan actors DB | EV-09-004/005/006 |
| REM-09-R09 | Regular SKS versus POS Agent separation | VERIFIED | GAP CONFIRMED | Backend berbeda source domain sudah; source-aware SKS UI belum | EV-09-001/003 |

## REM-10 — requirement register

| Requirement ID | Requirement(s) | Audit status | Existing conformance | Observed / gap | Evidence |
|---|---|---|---|---|---|
| REM-10-R01 | Approved mockup spesifik tersedia | BLOCKED | UNKNOWN | Tidak ditemukan pada inventory design; ODR-E2 | EV-ENV-001; RECON-01 aset |
| REM-10-R02 | White/maroon/logo kiri/meta kanan/table/footer | VERIFIED | GAP CONFIRMED | White/PDF ada; company purple dan logo kanan | EV-10-001 |
| REM-10-R03 | Official logo/wordmark source | VERIFIED | PARTIALLY COMPLIANT | Bundled logo fallback ada; wordmark/layout proportional belum parity | EV-10-002 |
| REM-10-R04 | PDF renderer/browser/print parity | PARTIAL | UNKNOWN | Dompdf A4; tidak render endpoint karena lazy write; mockup tidak ada | EV-10-002/003 |
| REM-10-R05 | COD/payment truth | VERIFIED | COMPLIANT | Template COD membaca canonical summary; unpaid tidak LUNAS | EV-10-001/004 |
| REM-10-R06 | Access control/financial data binding | VERIFIED | COMPLIANT | view + viewInvoice, canonical summary; consumer hides referral identities | EV-10-002/003; EV-11-007 |
| REM-10-R07 | Currency/numbering/issue timing | VERIFIED | COMPLIANT | Rp number_format, invoice=order_no; COD vs verified transfer issuance | EV-10-001/004 |
| REM-10-R08 | No nominal/status/workflow change | VERIFIED | COMPLIANT | Proposal dibatasi presentasi; endpoint tidak dipanggil | EV-10-003; RECON-05 |

## REM-11 — requirement register

| Requirement ID | Requirement(s) | Audit status | Existing conformance | Observed / gap | Evidence |
|---|---|---|---|---|---|
| REM-11-R01 | POS single page baru Admin/SuperAdmin dan Agen saja | VERIFIED | NOT IMPLEMENTED | Tidak ada POS registry; regular create menolak Admin/SuperAdmin | EV-11-001; frontend/src/router/index.ts |
| REM-11-R02 | Agen hanya jaringan sendiri | VERIFIED | PARTIALLY COMPLIANT | Existing branch policy reusable; POS chain selectors/validation belum | EV-11-006/007 |
| REM-11-R03 | Pilih Korsal; Agen-as-Korsal tanpa Sales palsu | VERIFIED | NOT IMPLEMENTED | POS selector tidak ada; fallback beneficiary Agen existing | EV-11-005 |
| REM-11-R04 | Sales/referral termasuk Korsal/Sales/SKS bawahannya | VERIFIED | NOT IMPLEMENTED | Hierarchy existing; explicit POS referral override belum | EV-X-004; EV-11-004 |
| REM-11-R05 | Konsumen opsional terfilter referral dan reset saat berubah | VERIFIED | NOT IMPLEMENTED | Existing buyer konsumen berbeda kontrak dari optional consumer POS | EV-11-002/003 |
| REM-11-R06 | Manipulasi konsumen/referral API harus ditolak | VERIFIED | NOT IMPLEMENTED | Policy regular/CRM ada; independent POS chain contract belum | EV-11-001/003 |
| REM-11-R07 | Pemesan required existing/manual tanpa auto login | VERIFIED | GAP CONFIRMED | Existing origin akun XOR CRM; arbitrary manual requester belum | EV-11-002/003 |
| REM-11-R08 | Pemesan berbeda dari konsumen dan penerima | VERIFIED | GAP CONFIRMED | Recipient snapshot terpisah; requester dan consumer independen belum | EV-11-002/004 |
| REM-11-R09 | Pembuat/Agen/Korsal/referral/consumer/requester/recipient tetap distinct | VERIFIED | PARTIALLY COMPLIANT | placed_by dan some snapshots ada; full identity tidak | EV-11-002/004/005 |
| REM-11-R10 | Stok POS Agen sendiri no Sub/no other Agent/no fallback | VERIFIED | PARTIALLY COMPLIANT | Agent resolver default reusable; POS boundary belum | EV-09-001; EV-11-003 |
| REM-11-R11 | POS Admin source/ownership | BLOCKED | UNKNOWN | Scope branch Admin ada; pemilihan source POS belum keputusan ODR-01 | EV-11-001/006 |
| REM-11-R12 | Product search name/SKU/variant/qty/system price | VERIFIED | PARTIALLY COMPLIANT | Catalog search+resolveLine+pricing existing; POS UI belum | EV-03-002; EV-11-005 |
| REM-11-R13 | Discount permission/server total/historical price snapshot | VERIFIED | COMPLIANT | Pricing/voucher & item snapshots existing, tidak manual price | EV-11-005 |
| REM-11-R14 | Tanggal transaksi input | VERIFIED | GAP CONFIRMED | created_at bukan editable business date; field meaningful belum | EV-11-004; EV-DB-002 |
| REM-11-R15 | Tanggal rencana kirim dan alamat/penerima | VERIFIED | COMPLIANT | Fields order/item/recipient tersedia; POS binding belum | EV-11-004; EV-X-005 |
| REM-11-R16 | Metode kirim/kurir/ongkir/resi | VERIFIED | PARTIALLY COMPLIANT | Shipment/quote ada; assign tetap existing dispatch | EV-X-006; EV-11-001 |
| REM-11-R17 | Order/shipping status dan audit history | VERIFIED | PARTIALLY COMPLIANT | State machines/audit ada; POS timeline belum | EV-X-005/006 |
| REM-11-R18 | Metode aktif transfer/COD; cash jika supported; QRIS jika supported | VERIFIED | PARTIALLY COMPLIANT | Transfer/COD aktif; cash installment pending; QRIS tidak ditemukan aktif | EV-DB-002; EV-11-008/010 |
| REM-11-R19 | Total valid paid/remaining/unpaid/partial/paid | VERIFIED | COMPLIANT | Order canonical summary existing; pending ≠ paid | EV-11-009 |
| REM-11-R20 | DP/lanjutan/proof/reference/history | VERIFIED | COMPLIANT | PaymentService requestInstallment/verifikasi existing | EV-11-008 |
| REM-11-R21 | Refund/cancel/COD tidak auto-paid | VERIFIED | COMPLIANT | Existing financial lifecycle reuse; tidak toggle paid di POS | EV-11-004/009; EV-07-003 |
| REM-11-R22 | List Order one primary record all authorized parties | VERIFIED | PARTIALLY COMPLIANT | Existing role-scoped list ada; POS attribution baru perlu contract test | EV-11-006/007 |
| REM-11-R23 | Admin creator bukan personal sales; fee tidak double | VERIFIED | PARTIALLY COMPLIANT | Beneficiary pipeline existing; explicit referral contract belum | EV-11-005 |
| REM-11-R24 | Notifications existing pipeline | VERIFIED | COMPLIANT | afterCommit existing; tidak perlu POS notification engine | OrderNotifier.php:157–214; docs/02 §5 |
| REM-11-R25 | Approved POS Modern Dark Edition asset | BLOCKED | UNKNOWN | Dashboard mockup bukan mockup POS terakhir; ODR-E3 | RECON-01 aset |
| REM-11-R26 | Two columns/no sidebar/dashboard; tokens existing | VERIFIED | NOT IMPLEMENTED | POS belum; pc-internal token reusable, layout tidak boleh berubah | EV-11-011 |
| REM-11-R27 | Seluruh field termasuk status/kurir/date/paid/outstanding | VERIFIED | PARTIALLY COMPLIANT | 57-row data mapping; identity/date/source Admin gap | RECON-03 |
| REM-11-R28 | No new POS table/service parallel | PARTIAL | PARTIALLY COMPLIANT | Reuse terbukti sebagian; schema 2 adjustment packages menunggu identity/source decisions | EV-11-002/003/008; RECON-03 |
| REM-11-R29 | Idempotency/concurrency manual origin | PARTIAL | UNKNOWN | Existing key unique account/CRM; optional origin null/null tidak aman tanpa scope baru | EV-DB-002; EV-09-004 |

## Bounded findings dan proposal minimal

Severity adalah dampak bila workflow memerlukan fitur tersebut: P1 order/product critical function, P2 supporting/UI. Tidak ada bukti P0 atau kehilangan data runtime. Semua proposal belum diotorisasi implementasi.

### GAP-01-001 — Import/export produk belum ada

**Requirement:** REM-01. **Expected Behavior:** CSV/XLSX template-preview-errors-safe update.

**Observed Existing Behavior:** CRUD + Spreadsheet dependency tersedia, pipeline produk tidak ada. **Evidence:** EV-01-001/002/003.

**Impact:** Import/export produk belum ada; menghalangi kesesuaian requirement terkait. **Severity:** P1. **Root Cause:** Confirmed.

**Affected Modules:** REM-01, Product CRUD, HasGlobalSku, PhpSpreadsheet. **Existing Reusable Components:** Product CRUD, HasGlobalSku, PhpSpreadsheet.

**Minimal Adjustment Proposal:** Tambahkan adapter katalog dan validasi preview; stock write terpisah sesuai keputusan ODR-03. **Database Impact:** Unknown.

**Regression Risk:** Import mengganti stok domain atau SKU historis. **Acceptance Criteria:** Roundtrip CSV/XLSX; duplicate rows tidak apply tanpa policy; error per row; tidak ubah data saat preview.

**Owner Decision Required:** Yes. Pengujian scoped dan dependency order: RECON-05/06.

### GAP-02-001 — Gambar varian belum menjadi gallery independen

**Requirement:** REM-02. **Expected Behavior:** Gambar varian terpisah dan mengikuti selector/create/edit.

**Observed Existing Behavior:** Schema ada; UI/resource parent gallery; primary parent-wide; parent-match upload belum. **Evidence:** EV-02-001/002/003/004.

**Impact:** Gambar varian belum menjadi gallery independen; menghalangi kesesuaian requirement terkait. **Severity:** P2. **Root Cause:** Confirmed.

**Affected Modules:** REM-02, ProductImage/public storage/catalog.ts. **Existing Reusable Components:** ProductImage/public storage/catalog.ts.

**Minimal Adjustment Proposal:** Reuse variant FK, scope gallery+primary dan parent validation, selector/cart image; reorder scoped. **Database Impact:** None.

**Regression Risk:** Sibling gambar/primary atau file path terhapus. **Acceptance Criteria:** Varian A/B berbeda; parent tidak tercampur; forged foreign variant ditolak.

**Owner Decision Required:** No. Pengujian scoped dan dependency order: RECON-05/06.

### GAP-03-001 — Edit Produk memakai payload listing terbatas

**Requirement:** REM-03. **Expected Behavior:** Hydrate full data produk yang sama dan seluruh gambar.

**Observed Existing Behavior:** GET index active primary-only per_page100; show slug all images; edit tidak pakai detail. **Evidence:** EV-03-001/002/004.

**Impact:** Edit Produk memakai payload listing terbatas; menghalangi kesesuaian requirement terkait. **Severity:** P1. **Root Cause:** Confirmed (jalur source); kasus Owner seluruh gambar hilang belum direproduksi.

**Affected Modules:** REM-03, ProductResource/ProductImageResource/ProductFormView. **Existing Reusable Components:** ProductResource/ProductImageResource/ProductFormView.

**Minimal Adjustment Proposal:** Management read by ID authorized dengan all images dan variants; hydrate form langsung, stable order dan explicit errors. **Database Impact:** None.

**Regression Risk:** Memperluas public endpoint dapat membocorkan draft/fees. **Acceptance Criteria:** Skenario 3→save→reload→+1→-1→reload menghasilkan 3 unik; no-change save tidak upload.

**Owner Decision Required:** No. Pengujian scoped dan dependency order: RECON-05/06.

### GAP-03-002 — Reorder belum ada; delete file belum reference-aware

**Requirement:** REM-03. **Expected Behavior:** Lifecycle image tidak kehilangan referenced file.

**Observed Existing Behavior:** sort_order ada tanpa update route; destroy delete file dahulu; data lokal kosong. **Evidence:** EV-02-002; EV-DB-001.

**Impact:** Reorder belum ada; delete file belum reference-aware; menghalangi kesesuaian requirement terkait. **Severity:** P2. **Root Cause:** Confirmed (source); kerusakan referensi aktual Unknown.

**Affected Modules:** REM-03, ProductImage/Storage. **Existing Reusable Components:** ProductImage/Storage.

**Minimal Adjustment Proposal:** Reorder transactional scoped; audit path references dan defer safe file cleanup; tanpa purge historical duplicates. **Database Impact:** None.

**Regression Risk:** DB failure sesudah file delete; duplicate shared path. **Acceptance Criteria:** Save/reorder deterministic; delete referenced/shared path tidak merusak row lain; failure tidak partial.

**Owner Decision Required:** No. Pengujian scoped dan dependency order: RECON-05/06.

### GAP-04-001 — Delete masih soft delete

**Requirement:** REM-04. **Expected Behavior:** Hard delete hanya tanpa referensi kritis, block otherwise.

**Observed Existing Behavior:** Models SoftDeletes dan controller delete(); FK block hanya hard physical delete. **Evidence:** EV-04-001/002/003/004; EV-DB-002.

**Impact:** Delete masih soft delete; menghalangi kesesuaian requirement terkait. **Severity:** P1. **Root Cause:** Confirmed.

**Affected Modules:** REM-04, ProductPolicy/FK RESTRICT/registry. **Existing Reusable Components:** ProductPolicy/FK RESTRICT/registry.

**Minimal Adjustment Proposal:** Dedicated guarded hard-delete service; full dependency closure termasuk variants/polymorph; inactive fallback. **Database Impact:** None.

**Regression Risk:** Concurrent reference insertion dan registry/file orphan. **Acceptance Criteria:** Referenced delete ditolak tanpa perubahan; unreferenced benar hilang; Admin 403; two-connection integrity.

**Owner Decision Required:** No. Pengujian scoped dan dependency order: RECON-05/06.

### GAP-05-001 — Listing management tidak mencakup draft/inactive atau pagination

**Requirement:** REM-05. **Expected Behavior:** Semua default + kombinasi filter + backend paging.

**Observed Existing Behavior:** Management memanggil public active list100 dan mengabaikan meta; reactivation target hilang. **Evidence:** EV-05-001/002; EV-03-001/002.

**Impact:** Listing management tidak mencakup draft/inactive atau pagination; menghalangi kesesuaian requirement terkait. **Severity:** P1. **Root Cause:** Confirmed.

**Affected Modules:** REM-05, ProductController query/ProductManagementView. **Existing Reusable Components:** ProductController query/ProductManagementView.

**Minimal Adjustment Proposal:** Management query role-gated terpisah dari public; default Semua; counts/search/category descendant/status+pagination. **Database Impact:** None.

**Regression Risk:** Public draft exposure dan first100 truncation. **Acceptance Criteria:** Semua/active/draft/inactive counts benar; page>100 bisa edit/reactivate; public tetap active.

**Owner Decision Required:** No. Pengujian scoped dan dependency order: RECON-05/06.

### GAP-06-001 — Hierarki existing belum diekspos lengkap

**Requirement:** REM-06. **Expected Behavior:** Category→subcategory UI/filter/import kompatibel.

**Observed Existing Behavior:** parent_id ada; UI flat; filter slug exact; parent rule hanya exists. **Evidence:** EV-06-001/002/003; EV-05-001; EV-07-002.

**Impact:** Hierarki existing belum diekspos lengkap; menghalangi kesesuaian requirement terkait. **Severity:** P2. **Root Cause:** Confirmed.

**Affected Modules:** REM-06, ProductCategory parent/children, category API. **Existing Reusable Components:** ProductCategory parent/children, category API.

**Minimal Adjustment Proposal:** Expose tree choice tanpa tabel baru; anti-cycle/depth; reuse leaf category + derived parent. **Database Impact:** None.

**Regression Risk:** Historical root products dan CMS filter berubah. **Acceptance Criteria:** Parent/child reset benar; cycle ditolak; root-only produk tidak remap.

**Owner Decision Required:** No. Pengujian scoped dan dependency order: RECON-05/06.

### GAP-07-001 — CMS belum menghitung bestseller

**Requirement:** REM-07. **Expected Behavior:** Unit net actual valid sales aggregated parent + options.

**Observed Existing Behavior:** Block manual/category; no sales aggregation/period; return/refund tahap berbeda. **Evidence:** EV-07-001/002/003/004.

**Impact:** CMS belum menghitung bestseller; menghalangi kesesuaian requirement terkait. **Severity:** P2. **Root Cause:** Confirmed (absence); formula Unknown.

**Affected Modules:** REM-07, CMS registry/content/grid; item/return/payment models. **Existing Reusable Components:** CMS registry/content/grid; item/return/payment models.

**Minimal Adjustment Proposal:** Derive ranking setelah ODR-04, preaggregate units sebelum join payments/shipments; hide empty; content configuration existing. **Database Impact:** None.

**Regression Risk:** Fanout/double subtract/tenant leaks/stale ranking. **Acceptance Criteria:** Fixtures cancel/return/refund/split/COD hasil unit deterministik; 4/8/12 dan 7/30/90/all.

**Owner Decision Required:** Yes. Pengujian scoped dan dependency order: RECON-05/06.

### GAP-08-001 — Edit varian tidak menerima atribut

**Requirement:** REM-08. **Expected Behavior:** Load/edit/add/delete structured attributes.

**Observed Existing Behavior:** Edit UI/payload/resource tidak ada; update request immutable dan controller commercial only. **Evidence:** EV-08-001/002/003/004; EV-04-004.

**Impact:** Edit varian tidak menerima atribut; menghalangi kesesuaian requirement terkait. **Severity:** P2. **Root Cause:** Confirmed.

**Affected Modules:** REM-08, Variation attributes/options/compositions. **Existing Reusable Components:** Variation attributes/options/compositions.

**Minimal Adjustment Proposal:** Structured serialization dan partial attribute-update transaction; change composition link, bukan shared option global. **Database Impact:** None.

**Regression Risk:** Sibling variant label dan historical snapshot rusak. **Acceptance Criteria:** Rename/value/add/remove benar; sibling & snapshots unchanged; SKU/fee/weight preserved.

**Owner Decision Required:** No. Pengujian scoped dan dependency order: RECON-05/06.

### GAP-09-001 — Katalog/cart SKS belum source-aware

**Requirement:** REM-09. **Expected Behavior:** Dua source di katalog→cart→checkout; single source/order.

**Observed Existing Behavior:** Backend resolver dua source; catalog attach Agent, cart/API tidak memuat source. **Evidence:** EV-09-001/002/003/004; EV-03-002.

**Impact:** Katalog/cart SKS belum source-aware; menghalangi kesesuaian requirement terkait. **Severity:** P1. **Root Cause:** Confirmed.

**Affected Modules:** REM-09, StockSourceResolver/SubStockService/StockService. **Existing Reusable Components:** StockSourceResolver/SubStockService/StockService.

**Minimal Adjustment Proposal:** Source selector SKS + source-scoped availability; cart-level context version; revalidate switch dan replay; backend canonical unchanged. **Database Impact:** None.

**Regression Risk:** Stale carts, mixed source/double reserve, cross-Agent. **Acceptance Criteria:** SKS source A/B show benar; switch revalidate; source tamper rejected; no double reserve; POS tetap own Agent.

**Owner Decision Required:** No. Pengujian scoped dan dependency order: RECON-05/06.

### GAP-10-001 — Invoice visual belum sesuai design lock

**Requirement:** REM-10. **Expected Behavior:** White maroon brand kiri/meta kanan konsisten browser/PDF/print.

**Observed Existing Behavior:** Template purple company/logo right; official logo sudah ada; mockup spesifik belum. **Evidence:** EV-10-001/002/003.

**Impact:** Invoice visual belum sesuai design lock; menghalangi kesesuaian requirement terkait. **Severity:** P2. **Root Cause:** Confirmed (source mismatch); final visual Unknown.

**Affected Modules:** REM-10, Dompdf template + official logo + canonical summary. **Existing Reusable Components:** Dompdf template + official logo + canonical summary.

**Minimal Adjustment Proposal:** Presentation-only update dari approved mockup; preserve numbering/payment/access/lazy issuance workflow. **Database Impact:** None.

**Regression Risk:** PDF page overflow atau fee identity leak. **Acceptance Criteria:** Owner visual compare browser/PDF/print; totals/status identical; COD unpaid tetap bukan LUNAS.

**Owner Decision Required:** Yes (aset saja). Pengujian scoped dan dependency order: RECON-05/06.

### GAP-11-001 — POS belum ada dan create regular tidak cocok Admin

**Requirement:** REM-11. **Expected Behavior:** One page POS Admin/Agen via same order service.

**Observed Existing Behavior:** No POS registry; regular create exclude admin/super_admin; CRM assisted actor/origin contract lain. **Evidence:** EV-11-001/003/006; router/index.ts.

**Impact:** POS belum ada dan create regular tidak cocok Admin; menghalangi kesesuaian requirement terkait. **Severity:** P1. **Root Cause:** Confirmed.

**Affected Modules:** REM-11, OrderService/Pricing/Shipping/PaymentSummary/Policies. **Existing Reusable Components:** OrderService/Pricing/Shipping/PaymentSummary/Policies.

**Minimal Adjustment Proposal:** Bounded POS action/request/UI own gate; explicit chain/referral validation; Agent stock only, Admin source ODR-01. **Database Impact:** Existing Adjustment Proposed.

**Regression Risk:** Checkout role leakage, wrong owner/beneficiary, duplicate order. **Acceptance Criteria:** Only Admin/Agen; cross branch/referral tamper 403; one order all authorized lists; actor ≠ beneficiary.

**Owner Decision Required:** Yes. Pengujian scoped dan dependency order: RECON-05/06.

### GAP-11-002 — Requester independen/manual dan transaction date belum tercakup

**Requirement:** REM-11. **Expected Behavior:** Optional consumer + required requester account/manual + recipient distinct.

**Observed Existing Behavior:** Origin XOR account/CRM; snapshots CRM only; created_at bukan editable business date; manual-origin retry key gap. **Evidence:** EV-11-002/003/004; EV-DB-002.

**Impact:** Requester independen/manual dan transaction date belum tercakup; menghalangi kesesuaian requirement terkait. **Severity:** P1. **Root Cause:** Confirmed.

**Affected Modules:** REM-11, orders buyer/placer/recipient snapshots, origin idempotency. **Existing Reusable Components:** orders buyer/placer/recipient snapshots, origin idempotency.

**Minimal Adjustment Proposal:** ODR-02/05 lalu additive columns/relations/index pada orders; 2 adjustment packages, tanpa tabel POS. **Database Impact:** Existing Adjustment Proposed.

**Regression Risk:** Historical identity remap, NULL unique replay duplicates, incorrect reports. **Acceptance Criteria:** Manual creates no login; selected consumer optional; requester distinct; retry same origin exactly one order; historical rows unchanged.

**Owner Decision Required:** Yes. Pengujian scoped dan dependency order: RECON-05/06.

## Hipotesis / evidence gaps terpisah

| ID | Kondisi | Status | Bukti/next evidence |
|---|---|---|---|
| HYP-03-A | Semua gambar produk Owner hilang di Edit | Unknown runtime; source partial-loader confirmed | Local images=0; perlu representative product read-only ODR-E1 |
| HYP-03-B | Duplikat fisik dan path dipakai historical reference | Unknown | Tidak purge; perlu safe reference inventory dari local representative images |
| HYP-07-A | Formula paid+delivered-net-refund cocok definisi best seller | Unknown, bukan formula final | returned_quantity/refund_quantity tahap beda; Owner ODR-04 |
| HYP-11-A | Seluruh POS dapat memenuhi no-new-table via proposed adjustments | Partial feasibility | Identity/date/source contract belum final; ODR-01/02/05; tanpa tabel baru saat ini |

Root cause REM-03 dibatasi: **loader payload mismatch terkonfirmasi lewat source**; tidak menyatakan ROOT CAUSE CONFIRMED untuk seluruh laporan Owner atau file hilang. Save biasa source tidak mengupload ulang existing image, sehingga duplikasi akibat upload ulang pengguna tetap laporan/hipotesis, bukan hasil reproduksi.
