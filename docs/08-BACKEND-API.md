# 08 — Backend API Reference

Seluruh permukaan API dari `backend/routes/api_v1.php` (prefix global `/api/v1`, middleware `auth:sanctum` + `agent.linked` pada grup ter-autentikasi). Format respons seragam `App\Support\ApiResponse`. Kolom **Role** = gate route `role:…`; ownership halus selalu diperiksa ulang di Policy/Service (lihat [04-ROLES-PERMISSIONS](04-ROLES-PERMISSIONS.md)).

---

## 1. Publik (tanpa autentikasi)

| Method | Path | Controller | Keterangan |
|---|---|---|---|
| GET | `/install/status` | InstallController | status installer (selalu bisa dibaca) |
| GET/POST | `/install/*` | InstallController | wizard (disegel `not.installed` setelah lock) — requirements, database/test, save, configure-app, run, finalize, lock |
| POST | `/auth/register` | AuthController | throttle 5/menit |
| POST | `/auth/login` | AuthController | throttle 10/menit |
| POST | `/auth/forgot-password`, `/auth/reset-password` | AccountRecoveryController | bucket throttle terpisah |
| GET | `/categories`, `/products`, `/products/{slug}` | Catalog | katalog global (availabilitas per-agen dilampirkan bila ada session) |
| GET | `/referral/{code}` | ReferralController | info referral |
| GET | `/homepage` | HomepageController | blok homepage |
| GET | `/articles`, `/articles/{slug}`, `/pages/{slug}` | Cms | hanya yang published |
| GET | `/languages` | LanguageController | |
| GET | `/settings`, `/agents` | WebsiteSetting/AgentContact | field public-safe |
| GET | `/checkout/steps` | CheckoutController | daftar langkah dinamis (guest-aware) |
| GET | `/regions/{provinces,regencies,districts,villages}` | RegionController | wilayah bertingkat |

## 2. Umum ter-autentikasi

| Method | Path | Role | Keterangan |
|---|---|---|---|
| POST | `/auth/logout`, GET `/auth/me` | semua | me = user + PermissionMap |
| PATCH | `/profile`, `/profile/password` | semua | profil sendiri |
| GET/POST | `/profile/whatsapp(/verification…)` | semua | bukti nomor WA (throttle khusus) |
| GET/POST | `/profile/email/verification(…)` | semua | verifikasi email |
| PATCH/POST/DELETE | `/profile/referral-code(…)` | agen, korsal, sales, SKS | kode referral sendiri |
| POST/DELETE | `/media`, `/media/{media}` | semua (Policy per collection) | avatar semua role; collection lain super_admin/agen |
| POST | `/users` | super_admin, agen, korsal, koordinator-kurir | pembuatan sesuai HierarchyRules |
| GET/PATCH/DELETE | `/users(…)` | 9 role (scope policy) | + `reassign-referral`, `convert-to-sales-kurir-sub` (agen; alias legacy `convert-to-sales-kurir`) |
| GET | `/notifications/admin`, `/unread-count`, POST `/read-all`, `/{id}/read` | super_admin, agen, admin, keuangan | Notification Center (per-penerima) |

## 3. Checkout & order

| Method | Path | Role | Keterangan |
|---|---|---|---|
| POST | `/checkout/quote`, `/checkout/courier-options` | konsumen, agen, korsal, sales, SKS | throttle 30/menit |
| POST | `/checkout/geocode` | idem | reverse-geocode (IMP-002) |
| POST | `/orders` | idem | `Idempotency-Key` wajib; `OrderPolicy::create` |
| GET | `/orders`, `/orders/regions`, `/orders/{order}` | semua ter-autentikasi (scope role) | filter: status, payment_status, search, delivery_date, wilayah, paid |
| POST | `/orders/{order}/cancel` | scope matriks | jendela §14 |
| PATCH | `/orders/{order}/status` | super_admin, agen, admin | bulk office |
| GET | `/orders/{order}/invoice` | owner / role finansial cabang | PDF dinamis (409 bila belum layak terbit) |
| PATCH | `/orders/{order}/items/{item}/fulfillment` / `reschedule` | super_admin, agen, admin | jendela fulfilment §21 |
| POST | `/orders/{order}/items` | **admin** | SC-03 add-line (`Idempotency-Key`) |
| POST | `/crm/customers/{customer}/orders` | agen, korsal, sales, SKS | assisted order CRM (D3) |

## 4. Pembayaran

| Method | Path | Role | Keterangan |
|---|---|---|---|
| POST | `/orders/{order}/payment/proof` | konsumen (own), super_admin/agen/admin/keuangan (cabang), korsal/sales/SKS (payOnBehalf) | bukti transfer; disk privat |
| POST | `/orders/{order}/payment/cod-proof` | idem | bukti COD |
| POST | `/orders/{order}/payment/installment` | idem + konsumen own | cicilan/pelunasan/kas (§29) |
| GET | `/payment-proofs/{verification}` | owner, finance/office cabang, in-scope sales, super_admin | file bukti (otorisasi dicek) |
| PUT | `/orders/{order}/due-date` | super_admin, admin, keuangan | alasan wajib, audit |
| PATCH | `/orders/{order}/payment/cod` | super_admin, keuangan | toggle COD |
| POST | `/orders/{order}/payment/verify` | super_admin, keuangan | approve/reject bukti |
| POST | `/orders/{order}/payment/settle` | super_admin, keuangan | request pelunasan DP |
| PATCH | `/admin/cod-payment-proofs/{id}/confirm` | super_admin, keuangan | konfirmasi bukti COD |
| GET/PUT | `/invoice/config` | super_admin (global), agen (cabang) | konfigurasi invoice |

## 5. Pengiriman & fulfilment

| Method | Path | Role | Keterangan |
|---|---|---|---|
| PATCH | `/shipments/{shipment}/status` | super_admin, agen, admin, kurir, SKS, koordinator-kurir (executor) | per-shipment |
| PATCH | `/shipments/{shipment}/items/{item}/status` | idem | per-item (model unit kerja §11) |
| GET | `/shipments/{shipment}/receipt` | idem | resi termal (keuangan/korsal/sales/konsumen dikecualikan) |
| PATCH | `/shipments/{shipment}/courier` | super_admin, agen, admin, koordinator-kurir | assignment (eligibilitas SKS CR-001) |
| GET | `/shipments/{shipment}/eligible-couriers` | idem | daftar kurir + SKS eligible |
| GET | `/dispatch(/couriers)(/regions)` | idem | workspace dispatch (KK scope cabang; SA `?agent_id=`) |
| GET/POST | `/shipments/{shipment}/delivery-verifications` | super_admin, admin | verifikasi delivery append-only |
| GET | `/kurir/orders` | kurir, SKS, koordinator-kurir | dashboard kurir (scope self) |
| GET/PATCH | `/kurir/returns(/pickup-today)…` | kurir | pickup/konfirmasi retur + laporan delivered |

## 6. Retur & refund

| Method | Path | Role |
|---|---|---|
| POST | `/orders/{order}/returns` | konsumen |
| POST | `/orders/{order}/assisted-returns` | sales, SKS, korsal (consent) |
| GET/PATCH | `/admin/returns(…)(/review)(/pickup-schedule)` | super_admin, agen, admin |
| GET | `/warehouse/returns`, POST `…/inspect` | gudang |
| POST | `/warehouse/returns/{item}/finalize` | admin |
| GET | `/admin/order-refunds`, `/admin/additional-payments` | super_admin, agen, admin, keuangan (read) |
| PATCH | `/admin/order-refunds/{adj}/status`, `additional-payments/{p}/status`, `return-items/{item}/refund` | super_admin, keuangan |
| GET | `/admin/orders/{order}/refunds` | super_admin, agen, admin, keuangan (read) |
| POST | `/admin/refund-obligations/{ob}/…` (approve, attempts, needs-verification/resolve, deferred/request, deferred/materialize) | super_admin, agen, keuangan |
| POST | `/admin/refund-attempts/{a}/…` (initiate, complete, cancel, fail, unknown, reconcile) | super_admin, agen, keuangan |
| POST | `/admin/order-sales-reductions/{r}/reinstate`, `/admin/orders/{order}/cancellation/finalize` | super_admin, agen, keuangan |
| GET/POST | `/orders/{order}/refund-recipient` | sales, SKS, korsal, agen, admin, keuangan, super_admin |

## 7. CRM & consent

| Method | Path | Role |
|---|---|---|
| GET | `/crm/customers(…)(/visits)`, `/crm/follow-ups` | super_admin, agen, admin, korsal, sales, SKS (read scope) |
| POST/PATCH | `/crm/customers…`, `/crm/customers/{c}/archive`, `…/visits`, `/crm/visits/{v}` | sales, SKS, korsal (owner) |
| GET/POST | `/crm/link-candidates`, `…/link-otp`, `…/link`, `…/consent-otp`, `…/consent-events` | sales, SKS, korsal |
| POST | `/crm/customers/{c}/unlink` | sales, SKS, korsal, agen, admin |
| POST | `/consent/otp(…/verify)`, `/consent-events` | sales, SKS, korsal, agen |
| POST | `/consent-events/{e}/withdraw`, `/dispute` | konsumen, sales, SKS, korsal, agen, admin, super_admin |
| GET | `/orders/{order}/consent-events`, `/consent-context` | idem |
| POST | `/consent-events/{e}/reinstate` | agen, admin, super_admin |

## 8. Stok & warehouse

| Method | Path | Role |
|---|---|---|
| GET | `/stock/products`, `/stock/variations`, `/warehouse-stock`, `/warehouse/sellable` | super_admin, agen, admin, gudang |
| GET | `/warehouse/settings` | idem |
| PATCH | `/warehouse/settings/factory-plan` | admin |
| POST | `/warehouse/stock-addition-requests`, `/sub-adjustment-requests` | gudang |
| GET | `/warehouse/stock-addition-requests(…)(/cards)`, `/sub-locations/{s}/stock-cards` | admin, gudang |
| POST | `stock-addition-requests/{r}/approve|reject` | admin |
| GET | `/warehouse/sub-locations/eligible-owners` | agen, admin |
| GET | `/warehouse/sub-locations(…)(/stocks)`, `/warehouse/transfers(…)`, `/warehouse/handovers/{h}(/print)` | super_admin, agen, admin, gudang |
| PATCH | `/warehouse/sub-locations/{s}`, POST `…/deactivate` | agen, admin, gudang |
| POST | `/warehouse/sub-locations`, `…/assign-owner` | agen, admin |
| GET | `/sub-stock/requests(…)` | SKS, agen, admin, gudang |
| GET/POST | `/sub-stock/my`, `/replenishment-targets`, `requests…(cancel)(receive)` | SKS |
| POST | `…/approve|reject` | admin; `execute` = gudang |
| POST | `/warehouse/transfers`, `plan-to-transit`, `{t}/cancel`; `/warehouse/opnames…(count)(submit)(cancel)` | gudang |
| GET | `/warehouse/opnames(…)` | super_admin, agen, admin, gudang |
| POST | `/warehouse/opnames/{o}/approve|reject`, `/warehouse/transfers/{t}/approve|reject` | admin |
| GET | `/warehouse/orders/diproses`, `/warehouse/orders/{order}/stock-request` | gudang |
| POST | `/warehouse/stock-requests/{r}/fulfill`, `…/proposals` | gudang |
| GET | `/warehouse/fulfillment-proposals(…)` | admin, gudang |
| POST | `…/approve|reject` | admin |
| POST | `/stock/adjust` | agen, admin (403 saat warehouse-authoritative) |

## 9. Katalog, promo & fee

| Method | Path | Role |
|---|---|---|
| POST/PATCH/DELETE | `/categories(…)` | super_admin |
| POST/PATCH | `/products`, `…/variations…`, `…/images…` | super_admin, agen, admin |
| DELETE | `/products`, `…/variations…` | super_admin, agen (admin TANPA delete) |
| GET/POST/PATCH/DELETE | `/promo/discounts`, `/promo/vouchers` | super_admin, agen, admin (scope cabang) |
| GET | `/products/{p}/fees`, `…/variations/{v}/fees` | super_admin, agen, sales, SKS, korsal (visibilitas dibatasi FeeResource) |
| PUT | `…/fees` | super_admin, agen |

## 10. Pengaturan Agen & integrasi

| Method | Path | Role |
|---|---|---|
| GET/PATCH/PUT | `/agent/payment-methods…(toggle)(config)` | agen, admin (cabang sendiri) |
| GET/PUT | `/agent/google-auth` | agen, admin |
| GET/PUT | `/agent/shipping-tariff` | agen |
| GET/PATCH/PUT/POST | `/agent/shipping-providers…(toggle)(config)(couriers)(destinations)(test)` | agen |
| GET/PUT | `/agent/store-profile` | agen |
| GET/POST/PUT/DELETE | `/agent/whatsapp…` | agen, admin (scope WhatsAppScope) — device/pair/reconnect/logout/replace, events, test-numbers, test-send |
| GET/PUT | `/invoice/config` | super_admin, agen |
| GET/POST/PUT/DELETE | `/google-sheets…(connection)(destinations)(configs)(sync)` | super_admin, agen, admin |

## 11. Laporan & dashboard

| Method | Path | Role |
|---|---|---|
| GET | `/reports/my-customers` | sales, SKS, korsal |
| GET | `/commissions`, `/commissions/summary` | 9 role (scope `allowedBeneficiaryRoles`) |
| GET | `/reports/transactions`, `/reports/sales` | super_admin, agen, admin, korsal |
| GET | `/reports/fees/sales-korsal`, `cancellations-refunds`, `fees/courier`, `payment-status`, `finance-summary`, `finance-orders`, `couriers-per-agent`, `customers`, `korsal`, `couriers` | super_admin, agen, admin, keuangan |
| GET | `/dashboard/summary` | super_admin, agen, admin, korsal, sales, SKS |
| GET | `/dashboard/kpis` | agen, korsal, sales, SKS, gudang, kurir |
| GET | `/reports/network-transactions`, `/reports/crm-visits` | super_admin, agen, admin, korsal, sales, SKS |
| GET | `/reports/network-summary` | super_admin, agen, keuangan |
| GET | `/reports/fees/agent` | super_admin, agen |

## 12. Admin global (super_admin)

| Method | Path | Keterangan |
|---|---|---|
| GET/POST/PATCH/DELETE | `/cms/homepage-blocks…(reorder)(toggle)` | CMS homepage |
| GET/POST/PATCH/DELETE | `/cms/articles…`, `/cms/pages…` | CMS konten |
| GET | `/media` | media library semua collection |
| GET/POST/PATCH | `/admin/languages…(toggle-active)(set-default)` | manajemen bahasa |
| GET | `/admin/audit-logs` | audit log read-only |
| GET/PUT/POST | `/admin/integrations/smtp(…)(test-connection)(test-email)` | SMTP global (throttle) |
| GET/PUT/POST/DELETE | `/admin/integrations/whatsapp…(test)(messages)(retry)(templates…)` | GOWA console |
| GET/PUT | `/admin/settings` | website settings |
| GET/PUT | `/admin/google-auth` | Google Auth global |
| GET/POST/PATCH/DELETE | `/admin/agents…(toggle-status)` | Kontak Agen (AgentProfile) |
| GET/PATCH | `/admin/shipping-providers…(toggle)` | toggle global provider |
| GET/POST/PATCH | `/admin/shipping-couriers…(toggle)` | master kurir provider |
| GET/POST | `/admin/regions/export|import` | data wilayah CSV |

## 13. Console commands (artisan)

Sumber: `backend/app/Console/Commands/` + `routes/console.php` (tanpa schedule task aktif).

| Command | Fungsi |
|---|---|
| `regions:import [--reset] [--force]` | import data wilayah |
| `products:backfill-sku [--apply]` | backfill SKU deterministik (dry-run default) |
| `system:audit-catalog-hierarchy` | audit SKU/hierarki read-only |
| `warehouse:reconcile [--agent_id=]` | diagnostik bucket/reservasi/movement |
| `warehouse:migrate-legacy-stock` | tooling backfill legacy → Transit (dry-run default) |
| `transactions:reset [--dry-run] [--force]` | reset data transaksi (destruktif; prosedur §13-DEPLOYMENT-OPERATIONS) |
| `products:reset [--force] [--backup-verified=]` | reset domain produk penuh (destruktif) |
| `primeclassy:reset-dev-transactions` | DEV saja |
| `primeclassy:ar-reconciliation-report` | bukti OD-K5-4 read-only |
| `refund:ledger-backfill` | backfill ledger refund (insert-or-skip) |
| `reconcile:order-shipments`, `reconcile:warehouse-stock` | rekonsiliasi pasca-incident |
| `whatsapp:retention-report` / `minimize` | laporan/eksekusi retensi WA (minimize = kontrak, butuh approval) |
| `otp:retention-purge` | purge OTP challenge expired (sesuai kontrak retensi) |
| `admin-notifications:prune` | prune notification center |
| `self-purchase:attribution-report` | laporan atribusi self-purchase |

## 14. Jobs & queue

| Job | Fungsi |
|---|---|
| `SendWhatsAppMessageJob` | kirim pesan WA dari ledger (lane `primeclassy-notify`) |
| `SendTransactionalEmailJob` | kirim email transaksional |

Produksi: worker systemd `primeclassy-queue.service` — `queue:work database --queue=primeclassy-notify,default` (lihat [13-DEPLOYMENT-OPERATIONS](13-DEPLOYMENT-OPERATIONS.md)). OTP tetap sinkron (fail-closed, tanpa retry).
