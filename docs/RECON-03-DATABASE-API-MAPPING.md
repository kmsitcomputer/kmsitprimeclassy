# RECON-03 — DATABASE & API MAPPING

Tanggal pemeriksaan: 2026-11-11T21:08:06+07:00 (WIB). Repository: `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy`. Branch: `fix/transaction-reset-financial-dependencies`. HEAD: `6a14041c06e6258c9f011fcb11271c7d34d65d17`.

**RECON ONLY · proposal untuk Owner Review · bukan Architecture Lock.** Source, DB, konfigurasi, dan production tidak diubah; tidak ada test/build/migration/commit/push/deploy. Bukti static tidak berarti runtime PASS.

Paket: [RECON-01](RECON-01-EXISTING-SYSTEM-INVENTORY.md) · [RECON-02](RECON-02-BUSINESS-FLOW-RBAC.md) · [RECON-03](RECON-03-DATABASE-API-MAPPING.md) · [RECON-04](RECON-04-GAP-ANALYSIS.md) · [RECON-05](RECON-05-REMEDIATION-ARCHITECTURE-PROPOSAL.md) · [RECON-06](RECON-06-RISK-TEST-STRATEGY.md) · [RECON-07](RECON-07-OWNER-DECISION-REGISTER.md)

## Field-level mapping

Nama tabel/kolom berasal model, migration dan metadata lokal EV-DB-002. Status mapping adalah keberadaan data, bukan fitur end-to-end COMPLIANT. Tanda `—` berarti tidak ditemukan field bermakna yang memenuhi requirement; bukan usulan nama kolom final. Field derived tidak diusulkan menjadi storage baru.

| Requirement ID | Data/Field | Existing Table | Existing Column | Model/Relation | Status | Gap | Evidence |
|---|---|---|---|---|---|---|---|
| REM-01 | Nama/status/deskripsi | products | name,status,description,short_description | Product | EXISTING — DIRECT | Tidak ada import/export pipeline | EV-03-002; EV-05-002 |
| REM-01 | SKU simple | products | sku | Product/HasGlobalSku | EXISTING — DIRECT | Tidak ada gap penyimpanan | EV-01-002 |
| REM-01 | SKU varian, harga, berat | product_variations | sku,price,weight_grams,is_active | ProductVariation | EXISTING — DIRECT | Tidak ada gap penyimpanan | EV-08-002 |
| REM-01 | Harga/berat simple | products | base_price,weight_grams | Product | EXISTING — DIRECT | Tidak ada gap penyimpanan | EV-05-002 |
| REM-01 | Kategori/subkategori | products; product_categories | category_id; parent_id | Product::category; ProductCategory::parent | EXISTING — RELATION | CSV perlu stable ID/path mapping; leaf category saja cukup untuk parent derived | EV-06-001 |
| REM-01 | Global SKU registry | catalog_skus | sku,owner_type,owner_id | HasGlobalSku | EXISTING — DIRECT | Tidak ada gap penyimpanan | EV-01-002 |
| REM-01 | Stok sellable Agen | warehouse_stocks; product_stocks; product_variation_stocks | quantity; quantity_on_hand,quantity_reserved | WarehouseStockService/StockService | EXISTING — DERIVED | Import quantity fisik perlu domain/otoritas; tidak tulis sellable langsung | EV-09-005 |
| REM-01 | Fee produk/varian | product_fees; product_variation_fees | beneficiary_role,amount | Product::fee; ProductVariation::fee | EXISTING — DIRECT | beneficiary_role terverifikasi di ProductFee.php:12 dan ProductVariationFee.php:12; tidak expose fee pada export publik | EV-11-005 |
| REM-02/03 | Gambar parent dan variant | product_images | product_id,product_variation_id | Product::images; ProductImage::variation | EXISTING — RELATION | Belum isolasi gallery/selector dan owner guard variant | EV-02-001 |
| REM-02/03 | File, primary dan urutan | product_images | path,is_primary,sort_order | ProductImageResource/public disk | EXISTING — DIRECT | Tidak ada reorder endpoint; primary masih global parent | EV-02-002; EV-03-005 |
| REM-04 | Delete marker existing | products; product_variations | deleted_at | SoftDeletes | EXISTING — DIRECT | Tidak memenuhi hard delete Opsi A | EV-04-001/002 |
| REM-04 | Referensi transaksi | order_items | product_id,product_variation_id | OrderItem::product/variation | EXISTING — RELATION | FK RESTRICT harus tetap; full dependency closure di bawah | EV-X-005; EV-DB-002 |
| REM-05 | Filter status | products | status | Product | EXISTING — DIRECT | Enum cukup; management endpoint/UI belum | EV-05-002 |
| REM-06 | Parent kategori | product_categories | parent_id | parent/children | EXISTING — RELATION | UI saat ini flat; cycle/depth validation belum | EV-06-001 |
| REM-07 | CMS on/off/type/position | cms_homepage_blocks | is_active,type,sort_order | CmsHomepageBlock | EXISTING — DIRECT | Tidak ada gap penyimpanan | EV-07-001/002 |
| REM-07 | CMS konfigurasi | cms_homepage_blocks | content | CmsHomepageBlock JSON | EXISTING — DIRECT | Heading/limit ada; ranking/period/description/layout perlu kontrak validation/render | EV-07-001 |
| REM-07 | Unit terjual bersih per parent | order_items; orders; return_items | product_id,fulfilled_quantity,returned_quantity,refund_quantity,status; payment_status; quantity_returned,refund_status | OrderItem/ReturnService | EXISTING — DERIVED | Formula eligibility/date/refund stage belum diputuskan; belum ada ranking | EV-07-003; EV-X-005 |
| REM-08 | Atribut/nilai/composition | product_variation_attributes; product_variation_attribute_options; product_variation_compositions | name; value; product_variation_id,product_variation_attribute_id,product_variation_attribute_option_id | ProductVariation::compositions.option | EXISTING — RELATION | Shared options; edit harus mengganti link variant, bukan mutate sibling tanpa izin | EV-08-003 |
| REM-08 | Label historis order | order_items | variation_label_snapshot,sku_snapshot,unit_price_snapshot | OrderItem | EXISTING — SNAPSHOT | Tetap historis | EV-11-005 |
| REM-09 | Pemilik lokasi Sub | warehouse_sub_locations | owner_user_id,agent_id,is_active | SubLocationOwnershipService | EXISTING — RELATION | Resolve dari actor; bukan lokasi bebas klien | EV-09-001 |
| REM-09 | Sumber order line | order_items | stock_source,sub_location_id | OrderItem | EXISTING — DIRECT | Satu pilihan source untuk seluruh order; UI belum membawa source | EV-X-005; EV-DB-002 |
| REM-09 | Reserve Sub | sub_stock_reservations | order_item_id,sub_location_id,quantity,status | SubStockService | EXISTING — RELATION | UNIQUE order_item_id; tidak reserve Agent juga | EV-09-006 |
| REM-10 | Invoice amount/version | order_invoice_snapshots | order_id,version,invoice_number,total_amount,verified_paid_amount,outstanding_amount,payment_status,due_date | OrderInvoiceSnapshot | EXISTING — SNAPSHOT | Bukan ledger pembayaran baru | EV-10-004 |
| REM-10 | Konfigurasi branding | invoice_configs | agent_id,config | InvoiceConfigService | EXISTING — DIRECT | Layout masih perlu redesign; logo official sudah tersedia | EV-10-002 |
| REM-11 | Pembuat transaksi | orders | placed_by_user_id | Order::placedBy | EXISTING — DIRECT | Write-once authenticated actor; jangan jadikan referrer | EV-11-002/004 |
| REM-11 | Agen owning stock/order | orders; users | agent_id; agent_id | Order::agent; User | EXISTING — RELATION | Admin source policy belum final; Agen POS own agent only | EV-11-004 |
| REM-11 | Korsal snapshot | orders | korsal_id | Order::korsal | EXISTING — RELATION | Direct Agen mengikuti fallback; jangan isi Korsal palsu | EV-11-004 |
| REM-11 | Sales/SKS/direct referral | orders | sales_id,korsal_id,agent_id | Order sales + beneficiary calculation | EXISTING — RELATION | Chain currently buyer-derived; POS explicit referral membutuhkan validasi baru | EV-11-005 |
| REM-11 | Konsumen akun existing | orders | konsumen_id | Order::konsumen | EXISTING — RELATION | Saat ini sekaligus buyer origin; bukan optional consumer independen dari requester | EV-11-002 |
| REM-11 | Pembeli CRM non-login | orders | crm_customer_id | Order::crmCustomer | EXISTING — RELATION | Harus existing CRM; bukan arbitrary manual requester dan consumer bersamaan | EV-11-003 |
| REM-11 | Nama/telepon buyer manual parsial | orders | buyer_name_snapshot,buyer_phone_snapshot | Order buyerName | EXISTING — SNAPSHOT | Hanya diisi CRM origin; perlu penyesuaian semantic writer untuk manual requester | EV-11-002/003 |
| REM-11 | Akun Pemesan terpisah dari Konsumen | — | — | — | MISSING — ADJUSTMENT REQUIRED | Origin model hanya konsumen_id XOR crm_customer_id; account requester independen belum ada | EV-11-002 |
| REM-11 | Pemesan manual wajib tanpa akun/CRM wajib | — | — | — | MISSING — ADJUSTMENT REQUIRED | OrderService menolak nonpersisted buyer kecuali CRM origin; optional consumer + manual requester belum didukung | EV-11-001/002/003 |
| REM-11 | Tanggal transaksi bisnis dapat diinput | — | — | — | MISSING — ADJUSTMENT REQUIRED | created_at adalah waktu record; tidak boleh ditimpa untuk backdate tanggal bisnis | EV-11-004; EV-DB-002 |
| REM-11 | Penerima barang terpisah | orders | recipient_name_snapshot,recipient_phone_snapshot | Order | EXISTING — SNAPSHOT | Tetap historis | EV-11-004 |
| REM-11 | Alamat/koordinat penerima | orders | address_snapshot,village_snapshot,district_snapshot,regency_snapshot,province_snapshot,latitude_snapshot,longitude_snapshot | Order | EXISTING — SNAPSHOT | Tetap historis | EV-11-004 |
| REM-11 | Region dan kode pos | orders | province_id,regency_id,district_id,village_id,postal_code | Order | EXISTING — DIRECT | Tidak ada gap penyimpanan | EV-11-004 |
| REM-11 | Rencana tanggal kirim | orders; order_items | delivery_date_estimate; requested_delivery_date | Order/OrderItem | EXISTING — DIRECT | Tanggal item jadi grouping; jangan hanya render date order untuk partial | EV-X-005 |
| REM-11 | Tanggal kirim aktual | shipments | shipped_at,delivered_at | Shipment | EXISTING — DIRECT | Writer status existing; jangan isi dari planned date | EV-X-006 |
| REM-11 | Metode pengiriman | shipments | shipping_provider_id,shipping_provider_code,delivery_mode,provider_meta | Shipment | EXISTING — DIRECT | Pilih metode aktif server; POS bukan assignment shortcut | EV-X-006 |
| REM-11 | Kurir | shipments; couriers | courier_id; user_id | Shipment::courier | EXISTING — RELATION | Tetap melalui dispatch berwenang | EV-X-006 |
| REM-11 | Ongkos kirim | orders; shipments | shipping_fee_amount; shipping_fee_snapshot | OrderService/Shipment | EXISTING — DIRECT | Display/reuse server quote; bukan input override bebas | EV-11-004; EV-10-002 |
| REM-11 | Resi | shipments | tracking_number | Shipment | EXISTING — DIRECT | Tidak ada gap penyimpanan | EV-X-006 |
| REM-11 | Status order/item/shipping | orders; order_items; shipments | status | TRANSITIONS/Shipment | EXISTING — DIRECT | Tidak membuat status POS; derived aggregate | EV-X-005/006 |
| REM-11 | Riwayat perubahan | activity_logs; order_item_adjustments | subject_type,subject_id,event,properties,created_at; order_item_id,quantity_reduced,reason | ActivityLogger; OrderItem::adjustments | EXISTING — RELATION | Audit generic tidak otomatis berarti complete status timeline POS; cek event coverage saat desain | EV-04-003; docs/02 §8 |
| REM-11 | Metode pembayaran | orders; payment_methods | payment_method_id; code,type,is_active | Order::paymentMethod | EXISTING — RELATION | QRIS tidak active; cash channel installment bukan initial gateway | EV-11-010; EV-DB-002 |
| REM-11 | Total/DP/paid/outstanding | orders | total_amount,dp_amount,paid_amount,remaining_amount,payment_status | PaymentSummaryService | EXISTING — DERIVED | Server truth; UI input nominal diterima via payment request terverifikasi | EV-11-009 |
| REM-11 | Cicilan/DP/pelunasan/histori | payment_transactions | order_id,type,amount,status,paid_at | Order::paymentTransactions | EXISTING — RELATION | Reuse ledger; pending belum paid | EV-11-008 |
| REM-11 | Referensi pembayaran | payment_transactions; bank_transfer_verifications | gateway_reference; receipt_reference | PaymentTransaction/BankTransferVerification | EXISTING — DIRECT | Tidak ada gap penyimpanan | EV-11-008 |
| REM-11 | Bukti dan nominal verified | bank_transfer_verifications | proof_image_path,proof_disk,submitted_amount,verified_amount,status,verified_by,verified_at | PaymentTransaction::bankTransferVerification | EXISTING — DIRECT | Privat; uploader tidak boleh self verify | EV-11-008 |
| REM-11 | Penerimaan tunai | bank_transfer_verifications | channel,cash_received_by_user_id,cash_received_at,receipt_reference | BankTransferVerification | EXISTING — DIRECT | Record cash tetap pending sampai finance verify | EV-11-008 |
| REM-11 | COD proof/collected | cod_payment_proofs | payment_transaction_id,collected_amount,proof_media_id | CodPaymentProof | EXISTING — RELATION | COD tidak otomatis paid; binding proof existing | EV-DB-002; PaymentService |
| REM-11 | Refund/cancel | refund_obligations; refund_attempts; refund_payouts; orders | order_id; obligation_id; attempt_id; cancelled_at,cancelled_by,cancellation_reason | RefundLedgerService/OrderService | EXISTING — RELATION | Reuse, tidak ledger POS | EV-07-003; docs/02 §10 |
| REM-11 | Fee per item/type | commissions | order_id,order_item_id,beneficiary_user_id,beneficiary_role,amount,status | Commission | EXISTING — RELATION | Tidak hitung sekali lagi per List Order/dashboard | EV-11-005 |
| REM-11 | Catatan | orders | notes | Order | EXISTING — DIRECT | Kolom ada; create writer existing belum mengikat notes pada request ini | EV-11-002 |
| REM-11 | Nomor invoice dan issue date | orders | order_no,invoice_issued_at | InvoiceIssuanceService | EXISTING — DIRECT | Tidak ada gap penyimpanan | EV-10-004 |
| REM-11 | Diskon/voucher | orders | discount_amount,voucher_id | PricingService/VoucherService | EXISTING — RELATION | Tidak memberi manual price override | EV-11-005 |

## Dependency map hard delete

EV-DB-002: 31 FK langsung menuju products/product_variations, pada 20 tabel berbeda (termasuk product_variations child). RESTRICT inventory/transaksi tidak boleh diubah menjadi cascade agar tombol delete bekerja. Invoice/payment refer ke order→item→produk, bukan mengharuskan FK invoice langsung ke produk. Audit polymorphic/registry tidak bisa mengandalkan FK saja.

| Referencing table | FK column(s) | DELETE_RULE |
|---|---|---|
| order_items | product_id,product_variation_id | RESTRICT |
| products_translations | product_id | CASCADE |
| product_attributes | product_id | CASCADE |
| product_fees | product_id | CASCADE |
| product_images | product_id,product_variation_id | CASCADE |
| product_stocks | product_id | RESTRICT |
| product_variations | product_id | CASCADE |
| product_variation_attributes | product_id | CASCADE |
| product_variation_compositions | product_variation_id | CASCADE |
| product_variation_fees | product_variation_id | CASCADE |
| product_variation_stocks | product_variation_id | RESTRICT |
| stock_movements | product_id,product_variation_id | RESTRICT |
| stock_opname_items | product_id,product_variation_id | RESTRICT |
| stock_request_items | product_id,product_variation_id | RESTRICT |
| stock_transfer_items | product_id,product_variation_id | RESTRICT |
| sub_stock_request_items | product_id,product_variation_id | RESTRICT |
| sub_stock_reservations | product_id,product_variation_id | RESTRICT |
| warehouse_migration_markers | product_id,product_variation_id | RESTRICT |
| warehouse_stocks | product_id,product_variation_id | RESTRICT |
| warehouse_stock_requests | product_id,product_variation_id | RESTRICT |

Dependency non-FK/retention: catalog_skus(owner_type,owner_id), activity_logs(subject_type,subject_id), immutable report/snapshot data terkait order_item; product discounts/voucher scopes dan file paths/shared references juga perlu closure audit sebelum delete. Keamanan file delete: jangan hapus path yang masih dipakai row lain; jangan percaya CASCADE DB akan membersihkan storage. Metadata lokal tidak membuktikan seluruh legacy polymorphic references bersih.

Kriteria aman yang diusulkan: role berwenang; target dan child variants terkunci; tidak ada order item termasuk historical/deleted dependencies; tidak ada ledger/movement/opname/request/reservation/stock kritis; bukan hanya quantity=0; registry/audit retention jelas; delete transaction dilindungi FK dari concurrent inserts; file cleanup hanya sesudah commit dan setelah reference check; failure tidak meninggalkan partial delete. Jika diblok, gunakan inactive. Tidak dieksekusi.

## Constraint dan indeks teramati

| Tabel | Constraint/index lokal | Dampak |
|---|---|---|
| catalog_skus | PRIMARY(sku); UNIQUE(owner_type,owner_id) | Namespace lintas simple/variant; registry bukan FK biasa |
| orders | UNIQUE(order_no); UNIQUE(konsumen_id,idempotency_key); UNIQUE(crm_customer_id,idempotency_key) | Origin manual baru tanpa keduanya memerlukan key scope aditif; NULL pada unique tidak menjamin retry aman |
| orders | INDEX(agent_id,created_at), status, payment_status, sales_id, korsal_id, region IDs | Reuse query scopes; business transaction date belum ada |
| product_images | PRIMARY(id), INDEX(product_id), INDEX(product_variation_id) | Tidak ada unique path atau single-primary constraint teramati |
| product_variation_compositions | UNIQUE(product_variation_id,product_variation_attribute_id) | Satu nilai per atribut per varian; bukan uniqueness seluruh combination antar varian |
| sub_stock_reservations | UNIQUE(order_item_id); target/status composite | Satu lifecycle reservation per item |
| warehouse_stocks | UNIQUE(agent_id,stock_type,location_identity,target_identity) dan legacy unique target | Generated identity mengatasi NULL target/location; jangan tambahkan index vocabulary lain |
| product_categories | parent_id nullable FK SET NULL; slug UNIQUE | Delete parent bisa mengubah anak jadi root; tidak sama dengan taxonomy policy yang final |
| products | category_id nullable SET NULL; status enum draft/active/inactive | Existing root-only products tetap valid; jangan mandatory remap |

## UI → API → service mapping

Semua API berprefix `/api/v1`. UI route: `/products` ProductListingView; `/products/:slug` ProductDetailView; `/dashboard/products` ProductManagementView; `/dashboard/products/create` dan `/:id/edit` ProductFormView; `/checkout` CheckoutView; `/cart` CartView; `/orders` dan `/orders/:id` history/detail; `/dashboard/crm/customers/:id/order` CrmAssistedOrderView; `/dashboard/invoice-config` InvoiceConfigView. Bukti router/index.ts:38–90, 118–137, 241–259, 395–401, 558–563; catalog.ts dan orders.ts sebagai transport.

| Requirement ID | UI Component | API Endpoint | Controller | Service | Model | Policy/authorization | Evidence |
|---|---|---|---|---|---|---|---|
| REM-01/03/05/09 | ProductListingView; ProductManagementView; ProductFormView | GET /products | Catalog/ProductController::index | WarehouseStockService | Product | public; management read missing | EV-01-001; EV-X-001/002; EV terkait item |
| REM-02/03/09 | ProductDetailView | GET /products/{slug} | Catalog/ProductController::show | WarehouseStockService | Product | public active | EV-01-001; EV-X-001/002; EV terkait item |
| REM-01/06 | ProductFormView; ProductListingView | GET /categories | Catalog/ProductCategoryController::index | — | ProductCategory | public active | EV-01-001; EV-X-001/002; EV terkait item |
| REM-01/05 | ProductFormView | POST /products | Catalog/ProductController::store | HasGlobalSku | Product | ProductPolicy::create | EV-01-001; EV-X-001/002; EV terkait item |
| REM-03/05 | ProductFormView | PATCH /products/{product} | Catalog/ProductController::update | HasGlobalSku | Product | ProductPolicy::update | EV-01-001; EV-X-001/002; EV terkait item |
| REM-04 | ProductManagementView | DELETE /products/{product} | Catalog/ProductController::destroy | — | Product | ProductPolicy::delete | EV-01-001; EV-X-001/002; EV terkait item |
| REM-08 | ProductFormView | POST /products/{product}/variations | Catalog/ProductVariationController::store | ProductVariationService | ProductVariation | ProductPolicy::update | EV-01-001; EV-X-001/002; EV terkait item |
| REM-08 | ProductFormView | PATCH /products/{product}/variations/{variation} | Catalog/ProductVariationController::update | — | ProductVariation | ProductPolicy::update | EV-01-001; EV-X-001/002; EV terkait item |
| REM-04 | ProductFormView | DELETE /products/{product}/variations/{variation} | Catalog/ProductVariationController::destroy | — | ProductVariation | ProductPolicy::delete | EV-01-001; EV-X-001/002; EV terkait item |
| REM-02/03 | ProductFormView | POST /products/{product}/images | Catalog/ProductImageController::store | Storage | ProductImage | ProductPolicy::update | EV-01-001; EV-X-001/002; EV terkait item |
| REM-02/03 | ProductFormView | PATCH /products/{product}/images/{image} | Catalog/ProductImageController::update | DB transaction primary | ProductImage | ProductPolicy::update | EV-01-001; EV-X-001/002; EV terkait item |
| REM-02/03 | ProductFormView | DELETE /products/{product}/images/{image} | Catalog/ProductImageController::destroy | Storage | ProductImage | ProductPolicy::update | EV-01-001; EV-X-001/002; EV terkait item |
| REM-06 | ProductManagementView | POST /categories | Catalog/ProductCategoryController::store | — | ProductCategory | ProductCategoryPolicy; super_admin | EV-01-001; EV-X-001/002; EV terkait item |
| REM-06 | ProductManagementView | PATCH /categories/{category} | Catalog/ProductCategoryController::update | — | ProductCategory | ProductCategoryPolicy; super_admin | EV-01-001; EV-X-001/002; EV terkait item |
| REM-06 | ProductManagementView | DELETE /categories/{category} | Catalog/ProductCategoryController::destroy | — | ProductCategory | ProductCategoryPolicy; super_admin | EV-01-001; EV-X-001/002; EV terkait item |
| REM-07 | HomepageBlockRenderer; ProductBlock | GET /homepage | Cms/HomepageController::index | resolveProducts | CmsHomepageBlock/Product | public | EV-01-001; EV-X-001/002; EV terkait item |
| REM-07 | CmsHomepageBlockFormView | POST /cms/homepage-blocks | Cms/HomepageBlockController::store | HomepageBlockService | CmsHomepageBlock | super_admin | EV-01-001; EV-X-001/002; EV terkait item |
| REM-09/11 | CheckoutView; CrmAssistedOrderView | POST /checkout/quote | Checkout/CheckoutController::quote | OrderService::quote | Product/Order | auth; buyer scope | EV-01-001; EV-X-001/002; EV terkait item |
| REM-09/11 | CheckoutView | POST /orders | Order/OrderController::store | OrderService::createOrder | Order | StoreOrderRequest + OrderPolicy::create | EV-01-001; EV-X-001/002; EV terkait item |
| REM-11 | OrderHistoryView | GET /orders | Order/OrderController::index | OrderFilterService | Order | scopedQuery | EV-01-001; EV-X-001/002; EV terkait item |
| REM-11 | OrderDetailView | GET /orders/{order} | Order/OrderController::show | PaymentSummaryService via resource | Order | OrderPolicy::view | EV-01-001; EV-X-001/002; EV terkait item |
| REM-10/11 | OrderDetailView | GET /orders/{order}/invoice | Invoice/InvoiceController::show | InvoicePdfService/InvoiceIssuanceService | Order | view + viewInvoice; lazy write | EV-01-001; EV-X-001/002; EV terkait item |
| REM-10 | InvoiceConfigView | GET /invoice/config | Invoice/InvoiceConfigController::show | InvoiceConfigService | InvoiceConfig | super_admin/agen | EV-01-001; EV-X-001/002; EV terkait item |
| REM-10 | InvoiceConfigView | PUT /invoice/config | Invoice/InvoiceConfigController::update | InvoiceConfigService | InvoiceConfig | super_admin/agen | EV-01-001; EV-X-001/002; EV terkait item |
| REM-11 | CrmAssistedOrderView | POST /crm/customers/{customer}/orders | Crm/CrmAssistedController::placeOrder | AssistedOrderService | CrmCustomer/Order | CrmAccess::canPlaceOrder | EV-01-001; EV-X-001/002; EV terkait item |
| REM-11 | OrderPaymentTools | POST /orders/{order}/payment/installment | Payment/PaymentController::installment | PaymentService::requestInstallment | PaymentTransaction | OrderPolicy::payOnBehalf/scope | EV-01-001; EV-X-001/002; EV terkait item |
| REM-11 | OrderPaymentTools | POST /orders/{order}/payment/verify | Payment/PaymentController::verify | PaymentService | BankTransferVerification | super_admin/keuangan | EV-01-001; EV-X-001/002; EV terkait item |
| REM-11 | OrderPaymentTools | GET /payment-proofs/{verification} | Payment/PaymentController::proofFile | Storage private | BankTransferVerification | proof ownership scope | EV-01-001; EV-X-001/002; EV terkait item |

**NOT IMPLEMENTED:** product CSV/XLSX template/preview/import/export endpoint; management product GET by ID dengan full gallery/all status; variant structured attribute update; bestseller ranking; SKS source-aware storefront/cart; POS route/component/API. Existing CRUD bukan bukti fitur tersebut sudah ada. Tidak ada endpoint usulan yang dilabel sebagai existing.

## Contract dan state traces

| Jalur | Trace dan gap | Response/error/race |
|---|---|---|
| REM-03 | ProductForm.load → catalog.listProducts → GET index → active+primary only → ProductResource → product.images → existing preview | Produk page>100/draft/inactive tidak ditemukan; load tanpa catch/finally dapat tertahan; stale state setelah async load belum runtime-tested |
| REM-02 | uploadProductImage punya opts variant_id → request exists global → controller row → ProductImageResource | Belum parent match; gallery parent mencakup variant; primary update parent-wide; file deletion belum reference guard |
| REM-05 | ProductManagement.load → listProducts 100 → products array | Metadata count tidak digunakan; tidak ada kombinasi status/category/subcategory/search+pagination management |
| REM-06 | createCategory parent_id API ada → UI quick-add hanya name; product form single category | Tree bisa reuse parent relation; perlu reset child saat parent berubah dan fail-closed cycles/depth |
| REM-08 | Create AttributeRow→attributes map→create service; Edit commercial fields→update only | Resource label bukan structured attrs; shared option mutation dapat mengubah sibling; protect historical snapshots |
| REM-09 | Catalog own-Agent availability→cart no source→orders.ts no source→backend default Agent | Backend Sub capability tidak dapat dipilih dari alur storefront ini; source-switch stale cart/racing response harus ditangani nanti |
| REM-11 | POS NOT IMPLEMENTED; regular create authorize excludes Admin/SuperAdmin | Explicit POS authorization+chain validation dibutuhkan tanpa membuka checkout role lain; server totals dan service shared wajib |

## Database impact provisional

REM-02/03/05/06/08/09 dapat memakai kolom/relasi existing; REM-04 mengubah jalur deletion dan lifecycle registry/file tanpa perlu drop SoftDeletes massal atau backfill. REM-07 konfigurasi CMS dapat memakai content JSON existing; definisi eligibility/periode harus disetujui. REM-01 stock-write semantics perlu keputusan terpisah dari format spreadsheet.

POS identity lengkap dan business transaction date belum tercakup. **Dua paket schema adjustment diusulkan**, bukan migration: (S1) requester identity/manual snapshot + optional consumer relation serta origin-scoped idempotency pada orders; (S2) tanggal transaksi bisnis tersendiri pada orders jika Owner memang memerlukan tanggal input/backdate. Jumlah kolom/index final belum ditetapkan; jangan menyebut 2 kolom. Reuse buyer snapshots hanya setelah kontrak semantiknya ditinjau; jangan menaruh requester pada notes/raw_payload untuk menghindari perubahan. Tidak ada tabel POS baru diusulkan/dibuat. Kesimpulan semua kebutuhan pasti bisa dipenuhi tanpa tabel baru masih **PARTIAL**, menunggu kontrak identity/Admin source; tidak ada bukti saat ini yang memaksa tabel baru.

## EV-DB-003 — field-name reconciliation

Read-only metadata recheck after report drafting: SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE(); PDO transaction read-only then rollback, local host guard unchanged. Only the 34 field-mapped tables are used below; no row data. Mapping corrected to actual beneficiary_role and order_item_adjustments quantity_reduced/reason.

| Table | Existing columns used in mapping |
|---|---|
| activity_logs | created_at, event, properties, subject_id, subject_type |
| bank_transfer_verifications | cash_received_at, cash_received_by_user_id, channel, proof_disk, proof_image_path, receipt_reference, status, submitted_amount, verified_amount, verified_at, verified_by |
| catalog_skus | owner_id, owner_type, sku |
| cms_homepage_blocks | content, is_active, sort_order, type |
| cod_payment_proofs | collected_amount, payment_transaction_id, proof_media_id |
| commissions | amount, beneficiary_role, beneficiary_user_id, order_id, order_item_id, status |
| couriers | user_id |
| invoice_configs | agent_id, config |
| order_invoice_snapshots | due_date, invoice_number, order_id, outstanding_amount, payment_status, total_amount, verified_paid_amount, version |
| order_item_adjustments | created_at, order_item_id, quantity_reduced, reason |
| order_items | fulfilled_quantity, product_id, product_variation_id, refund_quantity, requested_delivery_date, returned_quantity, sku_snapshot, status, stock_source, sub_location_id, unit_price_snapshot, variation_label_snapshot |
| orders | address_snapshot, agent_id, buyer_name_snapshot, buyer_phone_snapshot, cancellation_reason, cancelled_at, cancelled_by, crm_customer_id, delivery_date_estimate, discount_amount, district_id, district_snapshot, dp_amount, invoice_issued_at, konsumen_id, korsal_id, latitude_snapshot, longitude_snapshot, notes, order_no, paid_amount, payment_method_id, payment_status, placed_by_user_id, postal_code, province_id, province_snapshot, recipient_name_snapshot, recipient_phone_snapshot, regency_id, regency_snapshot, remaining_amount, sales_id, shipping_fee_amount, status, total_amount, village_id, village_snapshot, voucher_id |
| payment_methods | code, is_active, type |
| payment_transactions | amount, gateway_reference, order_id, paid_at, status, type |
| product_categories | parent_id |
| product_fees | amount, beneficiary_role |
| product_images | is_primary, path, product_id, product_variation_id, sort_order |
| product_stocks | quantity_on_hand, quantity_reserved |
| product_variation_attribute_options | product_variation_attribute_id, value |
| product_variation_attributes | name |
| product_variation_compositions | product_variation_attribute_id, product_variation_attribute_option_id, product_variation_id |
| product_variation_fees | amount, beneficiary_role |
| product_variation_stocks | quantity_on_hand, quantity_reserved |
| product_variations | deleted_at, is_active, price, sku, weight_grams |
| products | base_price, category_id, deleted_at, description, name, short_description, sku, status, weight_grams |
| refund_attempts | obligation_id |
| refund_obligations | cancelled_at, order_id |
| refund_payouts | attempt_id, obligation_id, order_id |
| return_items | quantity_returned, refund_status, status |
| shipments | courier_id, delivered_at, delivery_mode, provider_meta, shipped_at, shipping_fee_snapshot, shipping_provider_code, shipping_provider_id, status, tracking_number |
| sub_stock_reservations | order_item_id, quantity, status, sub_location_id |
| users | agent_id |
| warehouse_stocks | quantity |
| warehouse_sub_locations | agent_id, is_active, owner_user_id |
