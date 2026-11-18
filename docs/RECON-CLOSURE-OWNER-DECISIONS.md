# RECON CLOSURE — OWNER DECISIONS

Closure inspection: 2026-11-11T21:40:57+07:00 (Asia/Jakarta). Repository `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy`; branch `fix/transaction-reset-financial-dependencies`; HEAD `6a14041c06e6258c9f011fcb11271c7d34d65d17`. Branch/HEAD unchanged from prior RECON.

**RECON CLOSURE ONLY. NO IMPLEMENTATION.** New Owner directive supersedes conflicting assumptions in RECON-01…07. All schema/API/action names in proposals are **PROPOSED**, not implemented or architecture-approved. Source evidence is not runtime PASS.

[01 OWNER-DECISIONS](RECON-CLOSURE-OWNER-DECISIONS.md) · [02 HISTORICAL-POS](RECON-CLOSURE-HISTORICAL-POS.md) · [03 IMPORT-BESTSELLER](RECON-CLOSURE-IMPORT-BESTSELLER.md) · [04 TRACEABILITY-MATRIX](RECON-CLOSURE-TRACEABILITY-MATRIX.md) · [05 ARCHITECTURE-READINESS](RECON-CLOSURE-ARCHITECTURE-READINESS.md) · [06 OWNER-REVIEW](RECON-CLOSURE-OWNER-REVIEW.md)

## Decision precedence dan koreksi baseline

Owner directive terbaru > keputusan Owner sebelumnya yang masih konsisten > canonical docs consistent with source > current runtime/schema/source evidence > asumsi laporan awal. Prioritas Owner: DATA INTEGRITY > FINANCIAL INTEGRITY > STOCK INTEGRITY > RBAC/SECURITY > DESIGN CONSISTENCY > IMPLEMENTATION SPEED. Fakta source tidak diubah agar terlihat compliant.

Tujuh RECON asli dibaca lengkap (01 inventory,02 flow,03 mapping,04 requirements+gaps,05 proposal,06 risks,07 decisions), lalu preserved byte-for-byte. ODR yang masih Open pada RECON-07 sekarang CLOSED sebagai **OWNER LOCK**, bukan closed sebagai implemented. Rekomendasi sebelumnya mengenai backdate terbatas/hari berjalan tidak berlaku; tidak ada batas lookback bisnis aktif. Referensi batas 30 hari hanya catatan superseded, bukan aturan. Proposal lama memanggil ordinary createOrder tidak lagi layak untuk historical POS.

| ODR | Final authority | Locked parameters/meaning | Evidence | Compliance of proposal vs existing |
|---|---|---|---|---|
| ODR-01 | OWNER LOCK A | Admin own linked valid Agent; SuperAdmin explicitly chooses valid Agent; Agen own Agent. One Agent source/order, no Sub or referral-source change. | CL-E27/28 + CL-DB01 | PARTIAL existing: single FK supports one branch, but 1/3 local Admin linked inactive+soft-deleted Agent. Fail closed that case; no remap. |
| ODR-02 | OWNER LOCK A | Required independent requester existing account or manual, optional referral-scoped consumer, recipient/actor distinct. Nullable additive orders columns preferred; no account/CRM fake, no placed_by/notes/raw_payload substitution. | CL-E29 + prior EV-11-003 | PROPOSED identity columns and readers/visibility changes; new names not approved. |
| ODR-03 | OWNER LOCK B; import=target; duplicate=update; atomic=rows | Physical final counted target; existing global SKU upsert; row transactions/error results; inventory existing adjustment+approval; preserve reservations/stock domains; audited retry safe. | CL-E03…09,34 + CL-DB02 | PARTIAL: target opname exists; importer permission, durable row retry and asynchronous catalog/approval atomicity need design/D-07. |
| ODR-04 | OWNER LOCK A; payment=verified; period=delivery; scope=agent | Fully paid VERIFIED only; legitimate actual receipt/delivery event period; Agent ranking; lawful return deduct once; parent aggregate; active eligible products, hide empty. | CL-E10…15,21…26,31 | PARTIAL: core policy closed; item event/date + late payment/refund restatement semantics D-06 open. |
| ODR-05 | OWNER LOCK REVISED | Historical actual transactions only; manual business date any prior day/month/year, no future; record timestamp preserved. Separate actual receipt/payment/verification/delivery/audit dates. No automatic historic price/fee reconstruction. | CL-E01/02/10/14/18/24…30 | PROPOSED date and reconciliation schema; period treatment D-04 and price discrepancy D-05 remain open. |

## Tidak boleh dibuka ulang

All11 REM remain. Hard delete guarded, Admin delete prohibited. Product management default Semua. Categories reuse parent hierarchy. SKS own Sub/parent Agent choice remains regular checkout only, one source/order. Invoice white/maroon official logo/browser-print-PDF; POS dark maroon two columns/no sidebar/no extra dashboard. POS actors only super_admin/admin/agen. Consumer optional, requester required independent/manual without account creation. No new POS/alternate-name historical tables, no unrestricted JSON shortcut, no stock/payment/commission parallel truth, no timestamp forgery, no automatic double accrual/movement. New column/index names only PROPOSED.

## Source evidence ledger

Every CL-E inherits repository/HEAD/timestamp header; method is static source read (nl/sed/rg), no execution of domain methods. Line ranges current working files; scoped relevant function named. Technical claims also refer baseline EV registry in RECON-01 where applicable. All source files checked unchanged relative HEAD; no runtime/PDF/transactions/tests used.

| ID | File:line range | Symbol | Verified observation |
|---|---|---|---|
| CL-E01 | `backend/app/Services/Order/OrderService.php:81–239` | createOrder | Origin persisted account/CRM, buyer agent, default statuses, replay origin-scoped, canonical stock reserve: ordinary create is not safe historical ingestion. |
| CL-E02 | `backend/app/Services/Order/OrderService.php:771–920` | priceAndReserveLine/recordCommission | Current price/fees snapshot; reserve new commitments; create pending Agent/Sales commissions. No reconciliation of off-system historic execution. |
| CL-E03 | `backend/app/Services/Stock/StockOpnameService.php:20–145` | create/count/submit/approve | Gudang creates/counts/submits; Admin approves; locks document/stock, compares current system_quantity (409); target count and movement; approved replay returns existing. |
| CL-E04 | `backend/app/Services/Stock/StockOpnameService.php:184–224` | currentQuantity/lockedStock/assertTypeTarget | physical targets transit/shipping/sub, plan separate; reads target stock, source/bucket/location explicit; no arbitrary overall sellable target. |
| CL-E05 | `backend/app/Policies/StockOpnamePolicy.php:10–39` | StockOpnamePolicy | Create/edit Gudang, approve Admin same branch; catalog-import actor cannot impersonate Gudang. |
| CL-E06 | `backend/app/Models/StockOpname.php:17–35` | StockOpname | Existing document status/creator/submitter/approval timestamps; no import-row key field in fillable. |
| CL-E07 | `backend/app/Models/StockOpnameItem.php:10–23` | StockOpnameItem | system_quantity/counted_quantity/difference/adjusted_quantity with product/variant/location. |
| CL-E08 | `backend/app/Models/Concerns/HasGlobalSku.php:14–41` | save | GlobalSku registry in transaction, owner_type product/variant, DB duplicate validation. |
| CL-E09 | `backend/app/Rules/GlobalSku.php:14–23` | validate | Registry lookup permits same owner id/type only; registry SKU PRIMARY verified previously and locally. |
| CL-E10 | `backend/app/Services/Order/CourierService.php:632–806` | updateShipmentStatus/updateOrderItemStatus | Delivery transitions stamp now, proof+audit, consume Sub on shipping, commissions on delivery; not a historical no-side-effect replay. |
| CL-E11 | `backend/app/Services/Order/CourierService.php:811–861` | syncShipmentAggregate | delivered_at write-once now only when ALL item statuses arrived; earlier delivered item has no dedicated date column. |
| CL-E12 | `backend/app/Models/OrderItem.php:13–66` | fillable/casts/transitions | Original/fulfilled/cancelled/returned/refund quantities distinct; handed_over_at not recipient-received time; no item delivered_at in model. |
| CL-E13 | `backend/app/Services/Order/DeliveryVerificationService.php:32–90` | record | Append-only received/not_received/return verification, gate shipment aggregate delivered and item arrival; verified_at now is verification time. |
| CL-E14 | `backend/app/Services/Payment/PaymentService.php:386–461` | verifyBankTransfer | Pending row/order/transaction locking, reject self verification; verified_at and paid_at now, verified amount distinct; payment truth only canonical writer. |
| CL-E15 | `backend/app/Services/Payment/PaymentService.php:551–622` | markCod/settleCodCollection | COD authorized explicit collection, partial/residual transactions, paid_at now; unpaid not inferred from delivery; inspect route for verifier authority, stale Admin/Agen docblock is not authority. |
| CL-E16 | `backend/app/Services/Payment/PaymentService.php:865–970` | requestInstallment | Transfer/cash record pending with evidence; no receipt=paid shortcut; no historical source key. |
| CL-E17 | `backend/app/Http/Requests/Payment/StoreInstallmentRequest.php:15–27` | rules | Cash received_at may be <=now, reference/photo fields; no blanket historical transfer evidence acceptance. |
| CL-E18 | `backend/app/Models/Commission.php:10–24` | Commission | Existing status/earned_at/paid_at available, but model availability is not a settlement writer. |
| CL-E19 | `backend/app/Services/Commission/CommissionFinalizer.php:8–34` | finalizeItems | Pending→approved per delivered item; paid/reversed preserved; payout outside app has no writer. |
| CL-E20 | `backend/app/Services/Order/CourierService.php:924–966` | recordCommissionsForItems/writeCourierCommissions | Per item courier existence guard and finalization; local commissions metadata has only PRIMARY unique, no blanket item+type unique. |
| CL-E21 | `backend/app/Services/Order/ReturnService.php:48–115` | requestReturn | returned_quantity at request stage; not proof physical return accepted. |
| CL-E22 | `backend/app/Services/Order/ReturnService.php:188–249` | inspectReturn/finalizeInspection | Inspection quantity_received/good/damaged; disposition finalized/restocked once, damaged not sellable; actual returns separated from cash refund. |
| CL-E23 | `backend/app/Services/Order/ReturnService.php:387–461` | markItemRefunded | Processed monetary refund updates refund_quantity and hooks snapshot sales-fee adjuster; not another independent physical-return deduction. |
| CL-E24 | `backend/app/Services/Report/OrderPaymentPositionReportService.php:48–88` | query | Order date and filters created_at, last_paid_at from payment_transactions paid_at, not historical actual receipt date. |
| CL-E25 | `backend/app/Services/Report/OrderTransactionReportService.php:45–104` | query | Order date created_at; delivery date requested_delivery_date; neither proves actual recipient receipt. |
| CL-E26 | `backend/app/Services/Report/OrderInitialReportService.php:60–107` | query | Immutable initial snapshot order_created_at period; do not rewrite previously closed snapshots to simulate historical date. |
| CL-E27 | `backend/app/Http/Middleware/EnsureAgentLinked.php:20–30` | handle | Reject null branch for non-super-admin; does not itself validate referenced Agent active/not-deleted. |
| CL-E28 | `backend/app/Services/User/UserManagementService.php:32–112` | create | Branch derived from creator, Admin under Agen; Agen self-linked at creation. Runtime validity still needs evidence. |
| CL-E29 | `backend/app/Models/Order.php:16–146` | booted/identity/transitions | Account/CRM origin guard and write-once placer/buyer snapshots; requester and optional consumer independent not yet supported by create service. |
| CL-E30 | `backend/app/Services/Invoice/InvoiceIssuanceService.php:12–110` | issuance | Invoice number orders.order_no; issue now under eligibility; do not backdate or duplicate historic external invoice as new payment. |
| CL-E31 | `backend/app/Http/Controllers/Api/V1/Cms/HomepageController.php:30–128` | resolveProducts | CMS manual/category product list, no verified-delivery-Agent ranking. |
| CL-E32 | `backend/app/Services/WhatsApp/OrderNotifier.php:157–214` | defer/deferChannels | afterCommit sends for normal lifecycle; historical ingestion must not replay old operational notifications blindly. |
| CL-E33 | `backend/routes/api_v1.php:248–281` | payment gates | Verify/settle/COD confirmation super_admin+keuangan, not general POS actors. |
| CL-E34 | `backend/app/Http/Controllers/Api/V1/Stock/StockOpnameController.php:25–72` | store/count/submit/approve | Existing endpoints use policy/validation; no import preview/apply/source-key adapter. |

## Local SELECT evidence

**CL-DB01 VERIFIED (aggregate only):** 3 non-soft-deleted Admin accounts; missing agent_id=0; invalid referenced Agent=1. Diagnostic counts: absent parent0, wrong role0, non-self Agent0, inactive parent1, soft-deleted parent1. These predicates overlap: invalid total remains1, not2. Admin linked to invalid Agent is **BLOCKED for POS source resolution**; two other accounts satisfy only the checked ownership predicates, not all login/business permissions. No identities or PII printed.

Query: `SELECT COUNT(*) total_admins, COALESCE(SUM(u.agent_id IS NULL),0) missing_agent, COALESCE(SUM(u.agent_id IS NOT NULL AND (a.id IS NULL OR ar.slug<>'agen' OR a.agent_id<>a.id OR a.status<>'active' OR a.deleted_at IS NOT NULL)),0) invalid_agent FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN users a ON a.id=u.agent_id LEFT JOIN roles ar ON ar.id=a.role_id WHERE r.slug='admin' AND u.deleted_at IS NULL`. Diagnostic SELECT uses SUM of those separate predicates with same joins/filter.

**CL-DB02 VERIFIED:** catalog_skus rows3; simple products with SKU missing matching registry0; variants missing matching registry0. Query left joins catalog_skus on owner_type+owner_id+sku against products/product_variations, returns aggregate counts only. Does not prove arbitrary future files lack duplicate SKU rows.

**CL-DB03 VERIFIED metadata:** 192 column definitions inspected across orders,order_items,shipments,payment_transactions,bank_transfer_verifications,cod_payment_proofs,commissions,stock_opnames,stock_opname_items. Existing unique indexes: orders order_no/account+key/CRM+key; catalog_skus PRIMARY sku+owner pair; stock_opnames opname_number; commissions only PRIMARY id; stock_opname_items only PRIMARY id. No import row key or universal item+type commission unique observed. Source function guards still matter. No per-item receipt timestamp found in current order_items schema.

Queries use `information_schema.COLUMNS (TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE)` and `STATISTICS` grouped by table/index for stated table whitelist, TABLE_SCHEMA=DATABASE(). Connection allowed only DB_HOST=primeclassy-mariadb and DB_DATABASE=primeclassy_db, no Laravel bootstrap; `SET SESSION TRANSACTION READ ONLY` → begin → SELECTs → rollback. Credentials used only inside process, not printed/copied. No DB writes, migrations or production queries. Previous data counts/runtime root status are baseline evidence, not rerun now.

## Remaining decisions register

D-01…07 below are narrower integration semantics not settled by ODR-01…05. Existing resolved subparts are explicitly closed; do not re-ask source ownership, unlimited historical date, target/upsert/row atomicity or verified/delivery/agent eligibility. Options and recommendations in OWNER-REVIEW are PROPOSED, not authorization.

| Decision | Still open | Already resolved by contract | Blocking scenario |
|---|---|---|---|
| D-01 | Evidence/authorized reconciliation for already-reflected vs not-reflected historical stock; remaining units current commitments | Agent source locked; never automatic deduct or new reserve for completed historical units | H01/H04/H07/H08/H12 |
| D-02 | Minimum old evidence/acceptance per channel and actual receipt dates; old payments beyond current admission rules | Finance/SuperAdmin verify; no self verify or COD auto-paid | H01/H02/H03 |
| D-03 | Proof/authority for off-system commission settlement and partial settlement representation | Beneficiary/item/type ownership stays; approved≠paid; no payout writer existing | H05/H06 |
| D-04 | Open vs closed accounting-period treatment and accepted effective report dates | created_at/audit and historic data must not be backdated/replaced | H10 |
| D-05 | Actual historical price/discount/fee differs current server configuration | No free manual override, no automatic historic reconstruction | H01/H02/H06 |
| D-06 | Per-item arrival vs aggregate/verified receipt gate, late payments and refund-only/cross-period restatement | Fully paid verified, actual delivery period, Agent scope, lawful return once are locked | REM-07 and H10 |
| D-07 | Target below Agent commitments, importer→Gudang authority/handoff and async catalog+stock row outcome | Existing stale physical snapshot→409 recount; Admin approval; Sub reservation floor; no auto rebase | REM-01 and H08 |


## Latest addendum — OWNER LOCK frontend POS/CO parity

POS and CO transaction presentation must share the same existing CO reference contract, structure/labels/status/formats/applicable controls/actions, one canonical ListOrder, distinct historical backend admission, truthful business versus recorded dates and unchanged role restrictions/design locks. Source audit/mapping CL-P01…10 and acceptance PARITY-AC01…10 in HISTORICAL-POS; matrix PARITY-01. No literal transactionthead in audited Checkout/ListOrder cards, so no invented column design; exactalternate reference if intended remains missing evidence. This does not reopen any ODR or authorize redesign/source changes.
