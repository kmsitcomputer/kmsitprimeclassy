# 03 — Feature Catalog

Katalog fitur yang benar-benar ada di source code, dengan status implementasi dan evidence path. Status: **IMPLEMENTED** (ada di kode & diuji scoped), **PARTIAL** (ada tapi belum lengkap/tertutup celah), **CONFIGURED** (infrastruktur ada, aktivasi runtime terpisah), **NOT VERIFIED** (ada di kode, verifikasi runtime belum tercatat), **PLANNED/NOT IMPLEMENTED** (disetujui desain, belum ada kode).

> Fitur tersedia di kode ≠ teruji di produksi. Laporan status produksi dirujuk di [01-PROJECT-OVERVIEW](01-PROJECT-OVERVIEW.md) §4.

## 1. Storefront publik (konsumen)

| Fitur | Status | Evidence |
|---|---|---|
| Katalog produk + kategori (global, publik) | IMPLEMENTED | `ProductController@index/show`, `frontend/src/views/ProductListingView.vue` |
| Detail produk + variasi + gambar | IMPLEMENTED | `ProductDetailView.vue`, `ProductImageController` |
| Keranjang & wishlist (client-side) | IMPLEMENTED | `stores/cart.ts`, `stores/wishlist.ts`, `CartView.vue` |
| Checkout multi-langkah dinamis (`GET /checkout/steps`) | IMPLEMENTED | `CheckoutController`, `CheckoutStepResolver`, `CheckoutView.vue` |
| Alamat bertingkat wilayah (provinsi→desa) | IMPLEMENTED | `RegionController`, `KonsumenAddressController` |
| "Gunakan Lokasi Saya" (reverse-geocode Nominatim) | IMPLEMENTED | `GeocodeController::locate`, `components/maps` |
| Buku alamat pribadi konsumen | IMPLEMENTED | `AddressBookView.vue` |
| Registrasi + referral (`?ref=` tertangkap di semua halaman) | IMPLEMENTED | `AuthController::register`, `utils/referral.ts` |
| Google sign-in (konsumen, referral wajib) | IMPLEMENTED | `Services/Auth/GoogleAuthService`, `user_social_identities` |
| Pencarian & halaman CMS (artikel/news/pages) | IMPLEMENTED | `ArticleController`, `PageController`, `views/Article*` |
| Store locator "Kontak Agen" | IMPLEMENTED | `AgentContactController` (publik `/agents`) |
| PWA + halaman instal `/install` + offline page | IMPLEMENTED | `src/pwa/`, `PwaInstallView.vue`, `OfflineView.vue` |
| Verifikasi email mandiri (challenge) | IMPLEMENTED | `EmailVerificationController` |
| Verifikasi nomor WhatsApp (proof kepemilikan) | IMPLEMENTED | `WhatsAppNumberController`, `Services/WhatsApp/WhatsAppNumberVerification` |
| Recovery akun (forgot/reset password, enumeration-resistant) | IMPLEMENTED | `AccountRecoveryController` |
| Multi-bahasa (id/en/ar/zh) | IMPLEMENTED | `i18n/locales`, `SetLocale` |

## 2. Order & pembayaran

| Fitur | Status | Evidence |
|---|---|---|
| Pembuatan order (konsumen mandiri; agen/korsal/sales/SKS atas nama) | IMPLEMENTED | `OrderController@store`, `OrderPolicy::create` |
| Order berasal CRM (assisted order tanpa login) | IMPLEMENTED | `CrmAssistedController::placeOrder`, `orders.crm_customer_id` |
| Metode: COD, Bank Transfer manual, DP (sub-opsi transfer) | IMPLEMENTED | `PaymentMethodSeeder`, `AvailablePaymentMethodService` (konsumen: transfer saja) |
| Cicilan/pelunasan/catatan kas pasca-`diproses` | IMPLEMENTED | `PaymentController::installment` (`POST /orders/{order}/payment/installment`) |
| Upload bukti transfer (bank) — konsumen & staff scoped | IMPLEMENTED | `PaymentController::submitProof`, `bank_transfer_verifications` |
| Upload bukti COD + konfirmasi Keuangan | IMPLEMENTED | `submitCodProof`, `confirmCodProof`, `cod_payment_proofs` |
| Verifikasi pembayaran oleh Keuangan/super_admin (`verified_amount` terpisah) | IMPLEMENTED | `PaymentController::verify` + `assertMayVerifyPayment` |
| DP & pembayaran parsial (`partially_paid`, `dp_amount`) | IMPLEMENTED | migrasi `2026_09_23_090000`, `PaymentService` |
| Tanggal jatuh tempo per order + audit | IMPLEMENTED | `setDueDate`, `order_due_date_changes` |
| Invoice dinamis (PDF, nomor = `order_no`, snapshot versi; logo cabang valid → logo Prime resmi bawaan `resources/branding/prime-logo.png` → teks) | IMPLEMENTED | `InvoiceController`, `InvoicePdfService`, `order_invoice_snapshots`, `InvoiceConfigController` |
| Riwayat terkirim koordinator (`GET /dispatch/history`) & assignment retur kurir | IMPLEMENTED (lokal; belum diuji penuh) | `DispatchController::history`, `ReturnController::assignPickupCourier` |
| Piutang (receivables) terpusat Admin+Keuangan | IMPLEMENTED | `ReceivablesView.vue`, `CollectibleReceivable` |
| Gateway pembayaran eksternal (Xendit/Tripay/Stripe) | **DIHAPUS by Owner** | catatan §13 [05-BUSINESS-RULES](05-BUSINESS-RULES.md) |

## 3. Fulfilment & pengiriman

| Fitur | Status | Evidence |
|---|---|---|
| Status order: `diterima→diproses→dikirim→terkirim` (+ pembatalan/retur) | IMPLEMENTED | `Order::TRANSITIONS` (`app/Models/Order.php`) |
| Status per item + kuantitas tracking (cancelled/returned/refund/additional) | IMPLEMENTED | migrasi `2026_09_09_090000` |
| Multi-shipment per order (grouping key kanonis) | IMPLEMENTED | `ShipmentCanonicalizationService`, migrasi `2026_09_11_090000` |
| Antrian "Order Diproses" Gudang (hanya diproses & belum ada kurir) | IMPLEMENTED | `WarehouseOrderController::diproses` |
| Proposal fulfilment Gudang → approval Admin | IMPLEMENTED | `StockRequestProposalController` |
| Assignment kurir per shipment (dispatcher: KK/agen/admin/super_admin) | IMPLEMENTED | `ShipmentController::assign`, `eligibleCouriers` |
| Dispatch workspace Koordinator-Kurir | IMPLEMENTED | `DispatchController`, `DispatchView.vue` |
| Progres kurir per OrderItem + bukti foto saat `terkirim` | IMPLEMENTED | `ShipmentController::updateItemStatus` |
| Self-delivery SKS (`self_sub`, CHECK + trigger DB) | IMPLEMENTED | migrasi `2026_10_01_100000` |
| SKS mengeksekusi shipment `standard` (CR-001, eligibility server-side) | IMPLEMENTED | `SalesKurirSubEligibilityService` |
| Verifikasi pengiriman Admin (append-only, idempotent) | IMPLEMENTED | `DeliveryVerificationController`, `delivery_verifications` |
| Resi termal per shipment (print) | IMPLEMENTED | `ShipmentController::receipt`, `print/ShipmentReceiptView.vue` |
| Ongkir Kurir Toko (ORS/KMSIT distance × tarif agen) | IMPLEMENTED | `ShippingQuoteService`, `KurirTokoTariff` |
| Ekspedisi RajaOngkir/Komerce (kurir provider) | CONFIGURED — provider ter-seed nonaktif; aktif bila agen+super_admin menyalakan | `ShippingProviderSeeder`, `agent_shipping_provider_*` |
| Jadwal `requested_delivery_date` per item + reschedule (allowlist mode) | IMPLEMENTED | `OrderFulfillmentController::reschedule`, `Shipment::isDeliveryDateReschedulable` |
| Split partial-quantity (R-03, lineage `split_from_order_item_id`) | IMPLEMENTED | `OrderFulfillmentService`, migrasi `2026_10_01_100002` |
| SC-03 add-line Admin-only | IMPLEMENTED | `OrderFulfillmentController::addItem`, `OrderLineAdditionService` |

## 4. Retur & refund

| Fitur | Status | Evidence |
|---|---|---|
| Pengajuan retur per item oleh konsumen (order `terkirim`) | IMPLEMENTED | `ReturnController::store` |
| Retur berbantu CRM-origin (consent OTP) | IMPLEMENTED | `CrmAssistedController::initiateReturn` |
| Review retur Admin/Agen, jadwal pickup | IMPLEMENTED | `review`, `schedulePickup` |
| Pickup & konfirmasi kurir | IMPLEMENTED | `CourierDashboardController::pickupReturn/confirmReturn` |
| Inspeksi Gudang (good/damaged/mixed) + finalisasi Admin | IMPLEMENTED | `inspect`, `finalize` |
| Restock ke Transit / Sub Location asal | IMPLEMENTED | `ReturnService` |
| Refund ledger 360° (obligation/attempt/payout/event) | IMPLEMENTED | `RefundLedgerController`, `RefundLedgerService` |
| Bukti penerima refund (refund recipient evidence) | IMPLEMENTED | `refund_recipient_evidences`, `CrmAssistedController::storeRecipient` |
| Penyesuaian komisi atas refund — fee SALES | **IMPLEMENTED** (WS-8 FINAL 2026-10-30: `SalesFeeRefundAdjuster`, baris negatif `commission_adjustments`, snapshot per-unit, clamp, hanya fee sales) | `Services/Commission/SalesFeeRefundAdjuster.php`, migrasi `2026_10_30_105000` |
| Penyesuaian komisi atas refund — entri matriks lain (courier/agent, OD-05c) | **NOT IMPLEMENTED** (entri policy pending Owner) | §15a [05-BUSINESS-RULES](05-BUSINESS-RULES.md) |

## 5. Stok & gudang

| Fitur | Status | Evidence |
|---|---|---|
| Stok per Agen (produk non-variasi & variasi) | IMPLEMENTED | `product_stocks`, `product_variation_stocks` |
| Warehouse buckets (transit/factory_plan/sub/shipping) | IMPLEMENTED | `warehouse_stocks` |
| Sub Location + kepemilikan 1:1 SKS | IMPLEMENTED | `warehouse_sub_locations` (owner UNIQUE) |
| Reservasi Sub (per order item) & Agent | IMPLEMENTED | `sub_stock_reservations`, `StockService` |
| Transfer antar-bucket + dokumen serah terima (handover) + cetak | IMPLEMENTED | `StockTransferController`, `printHandover` |
| Stok opname (fisik/rekonsiliasi) + approval Admin | IMPLEMENTED | `StockOpnameController` |
| Permintaan stok Gudang (addition/sub-adjustment) → approval Admin | IMPLEMENTED | `WarehouseStockRequestController` |
| Sub stock request (replenish/return): SKS→Admin→Gudang→SKS receive | IMPLEMENTED | `SubStockRequestController` |
| Kartu stok (stock cards) per target/Sub | IMPLEMENTED | `cards`, `subCards` |
| Rekonsiliasi & audit warehouse (CLI) | IMPLEMENTED | `warehouse:reconcile`, `warehouse:migrate-legacy-stock` |

## 6. CRM & consent

| Fitur | Status | Evidence |
|---|---|---|
| CRM customer (prospect, kepemilikan Sales/SKS) + arsip | IMPLEMENTED | `CrmController`, `crm_customers` |
| Kunjungan (visit) + follow-up (`plan_next_date`) | IMPLEMENTED | `crm_visits` |
| Funnel CRM (0–7/N) | IMPLEMENTED | `ReportController::crmVisits`, `CrmFunnelView.vue` |
| Linking CRM↔Konsumen via OTP email terverifikasi | IMPLEMENTED | `CrmAssistedController::linkOtp/link`, `crm_customer_link_events` |
| Consent event (OTP-verified, append-only) + withdraw/dispute/reinstate | IMPLEMENTED | `ConsentController`, `consent_events` |
| Retensi consent (report-only planner) | IMPLEMENTED (report-only) | `RetentionPlanner` |
| Reassignment kepemilikan CRM/export/transfer Agen | **NOT IMPLEMENTED** | §27 [05-BUSINESS-RULES](05-BUSINESS-RULES.md) |

## 7. Notifikasi (REL005 & REL006)

| Fitur | Status | Evidence |
|---|---|---|
| WhatsApp transaksional via GOWA (16 event katalog) | IMPLEMENTED | `WhatsAppEvents`, `OrderNotifier`, `GowaClient` |
| Email transaksional (SMTP dashboard) | IMPLEMENTED | `TransactionalEmailNotifier`, `SendTransactionalEmailJob` |
| In-App Notification Center operator | IMPLEMENTED | `AdminNotificationService`, `AdminNotificationsView.vue` |
| Pengaturan event/template/test-number per Agen | IMPLEMENTED | `agent_whatsapp_event_settings`, `AgentWhatsAppController` |
| Pairing perangkat WA per Agen (QR/pair code) | IMPLEMENTED | `agent_whatsapp_devices`, `AgentDeviceService` |
| `payment_proof_uploaded` → Keuangan cabang (WA/email/in-app) | IMPLEMENTED | `OrderNotifier::paymentProofUploaded`, `FinanceRecipientResolver` |
| Worker queue produksi (systemd) | IMPLEMENTED & ACTIVE | `deploy/production/primeclassy-queue.service` |
| OTP via WhatsApp (alternative channel) | IMPLEMENTED (default OFF) | `WhatsAppOtpChannel` |

Detail lengkap: [10-NOTIFICATIONS](10-NOTIFICATIONS.md).

## 8. Laporan & integrasi

| Fitur | Status | Evidence |
|---|---|---|
| Laporan transaksi (14 kolom, per item) | IMPLEMENTED | `ReportController::transactions` |
| Laporan finansial (PaymentSummaryService) + finance-orders | IMPLEMENTED | `financeSummary`, `financeOrders` |
| Network transactions/summary, KPI dashboard per role | IMPLEMENTED | `networkTransactions`, `DashboardKpiController` |
| Laporan A/B (initial snapshot + posisi pembayaran) | IMPLEMENTED | `order_initial_report_items` |
| Ekspor XLSX | IMPLEMENTED | `Services/Export/*` |
| Google Sheets one-way sync (17 dataset) | IMPLEMENTED | `DatasetRegistry`, `SheetsController` |
| Format parity SimpleV2 (43/47 kolom) | **NOT IMPLEMENTED** (OD-06 approved) | §20b [05-BUSINESS-RULES](05-BUSINESS-RULES.md) |
| Audit log viewer | IMPLEMENTED | `AuditLogController` |
| CMS (pages/articles/homepage blocks + terjemahan) | IMPLEMENTED | `CmsPagesView.vue` dst. |
| Bahasa & katalog terjemahan | IMPLEMENTED | `LanguageController` |
| Website settings publik | IMPLEMENTED | `WebsiteSettingController` |
| Installer wizard first-run | IMPLEMENTED | `InstallController`, `InstallerView.vue` |
| Google Auth global + per-Agen | IMPLEMENTED | `GoogleAuthSettingController`, `AgentGoogleAuthConfigController` |
| SMTP settings dashboard (Super Admin) | IMPLEMENTED | `SmtpSettingsController` |
| WhatsApp Gateway console (Super Admin) | IMPLEMENTED | `WhatsAppGatewayController` |
| KMSIT routing fallback (Kurir Toko) | IMPLEMENTED | `KmsitDistanceCalculator`, `EndpointGuard` |

## 9. Dashboard internal (per role ringkas)

Halaman `frontend/src/views/dashboard/` (69 view). Ringkasan navigasi per role: [07-FRONTEND-GUIDE](07-FRONTEND-GUIDE.md) §5; otorisasi backend: [04-ROLES-PERMISSIONS](04-ROLES-PERMISSIONS.md).

## 10. Item PLANNED / belum ada di kode

- Penyesuaian komisi refund (OD-05 entries) — `commission_adjustments` tanpa writer.
- Format parity SimpleV2 untuk Sheets (OD-06).
- Reassignment/export/transfer CRM antar-Agen.
- Eksekusi retensi WhatsApp/consent (retention planner report-only; penghapusan butuh approval Owner terpisah).
- Lihat [15-KNOWN-LIMITATIONS](15-KNOWN-LIMITATIONS.md).
