# Package C — Roadmap Recon (post-Package B)

**Status: CLOSED (2026-10-01) — Human decisions recorded; scope frozen to Package C = SC-03. This document is the historical recon; the active spec is `docs/PACKAGE-C-SC03.md`.**
**Date:** 2026-10-01
**Branch / baseline:** `main` @ `7d1c481` ("merge: close Package B R-03 and R-04"); `origin/main` = `7d1c481`; working tree clean at recon start.
**Repository protocol:** `AGENTS.md`
**Predecessors:** Package A (`docs/PACKAGE-A-R01-R02-CHECKPOINT.md`) = PRODUCTION CLOSED; Package B (`docs/PACKAGE-B-R03-R04-CHECKPOINT.md`) = PRODUCTION CLOSED.

---

## 0. Method and authority

RECON ONCE. Read once, then reported. Source reality was treated as authoritative over stale documentation.

Authority order applied (from `AGENTS.md` §1): Human Business Decision → approved/controlled Master Business Rule → Blueprint/requirements → current source + DB invariants + API → frontend → automated tests / runtime evidence.

Every claim below is grounded in a file path or a test. An old unchecked checkbox, a `[ ]` item in `FINAL_AUDIT_REPORT.md`, a "not implemented" line in `IMPLEMENTATION-REPORT.md`, or a "DEFERRED" note in an older checkpoint is **not** treated as proof of a current gap — each was re-verified against source.

---

## 1. Documents read

- `AGENTS.md`
- `README.md`
- `BLUEPRINT.md`
- `PBR_RECON_REPORT.md`
- `docs/PACKAGE-B-R03-R04.md`, `docs/PACKAGE-B-R03-R04-CHECKPOINT.md`
- `docs/PACKAGE-A-R01-R02-CHECKPOINT.md`
- `docs/WAREHOUSE-AND-SALES-COURIER-SPECIFICATION.md` (frames REQ-01…REQ-25, INV-01…INV-21)
- `docs/IMPLEMENTATION-REPORT.md` (Phase A–L log)
- `docs/R01-R02-SALES-KURIR-SUB-AND-SUB-STOCK.md`
- `docs/ORDER-TRANSACTION-REPORT-AUDIT.md`, `docs/OPENROUTESERVICE-AUDIT.md`, `docs/RAJAONGKIR-AUDIT.md`, `docs/SKU-HIERARCHY-AUDIT.md`, `docs/GOOGLE-SHEETS-INTEGRATION.md`, `docs/TRANSACTION-RESET-REPORT.md`, `docs/REPOSITORY-CLEANUP-REPORT.md`, `docs/PHASE-K-PRODUCTION-CUTOVER-RUNBOOK.md`
- `backend/FINAL_AUDIT_REPORT.md`, `backend/SECURITY_AUDIT.md`
- Git state (`git status`, `git branch --show-current`, `git log --oneline --decorate -20`)

Source inspected: `backend/routes/api_v1.php`, `backend/app/Http/Controllers/Api/V1/**`, `backend/app/Services/**`, `backend/app/Http/Resources/**`, `backend/composer.json`, `backend/database/migrations/` (names), `frontend/src/views/**`, `frontend/src/api/**`, `frontend/src/stores/**`, `frontend/src/router/index.ts`, `frontend/src/dashboard/navConfig.ts`.

---

## 2. Baseline / git state (start of recon)

| Item | Value |
|---|---|
| Branch | `main` |
| HEAD | `7d1c481` |
| `origin/main` | `7d1c481` |
| Working tree | clean |
| Latest migrations | `2026_10_01_100000…100002` (R-03); R-04 added none |
| Migration file count | 116 |
| Backend test files | 103 Feature files |
| Last recorded full suite (Package B closure) | 815 passed / 5771 assertions / 0 failures |
| Framework | `laravel/framework ^11.31` |

---

## 3. Source-reality verification highlights

- **10 roles exist**, including `gudang` (10th) and canonical `sales-kurir-sub` (`RoleSeeder`; Package A migration `2026_09_29_090000` renames in place).
- **Warehouse layer exists end-to-end**: buckets (`warehouse_stocks`), transfers + handovers, opname, stock requests, fulfillment, cancellation reversal, Sub locations + ownership, Sub stock reservations, sellable service (`WarehouseStockService`/`SellableStockService`).
- **Package A** is implemented: `order_items.stock_source`/`sub_location_id`, `sub_stock_reservations`, `SubStockService`, `SubLocationOwnershipService`, Sub replenishment/return flow.
- **Package B** is implemented: `shipments.delivery_mode`/`self_delivered_by_user_id` (+ CHECK + 2 triggers), `delivery_verifications` (append-only, idempotent), `order_items.split_from_order_item_id`, R-04 role authority projections + `finance-orders` report; Admin Product/Variation CRU-no-Delete.
- **Installer wizard, dark mode, language switcher, courier dashboard, role dashboard shell** all exist (see matrix) — the older README/FINAL_AUDIT "missing" statements are stale.
- `TODO`/`FIXME`/`XXX`/`HACK` in `backend/app` and `frontend/src`: **0 hits**.

---

## 4. Traceability matrix

Status legend: **IMPLEMENTED** · **PARTIAL** · **MISSING** · **DEFERRED** · **REVIEW** · **CONFLICT** · **SUPERSEDED**.
Each entry carries its nearest source ID (REQ-xx from the warehouse spec, PBR-xxx from `PBR_RECON_REPORT.md`, or a recon ID for README/audit-derived items).

| ID | Source doc | Intended behavior | Implementation evidence | Test evidence | Status | Depends on | Risk | Grouping |
|---|---|---|---|---|---|---|---|---|
| SC-01 | README §2; BLUEPRINT §3.3/§4 | Checkout + quote, idempotency key, server-authoritative pricing/stock | `OrderService::createOrder`, `CheckoutController`; `orders.idempotency_key` unique | `CheckoutTest`, `OrderTest` | IMPLEMENTED | — | low | — |
| SC-02 | Package A R-01/R-02 | Sub-sourced checkout via server-side source resolver | `StockSourceResolver`, `order_items.stock_source`/`sub_location_id`, `SubStockService::reserve` | `SubStockSourceTest`, `SubCheckoutConcurrencyTest` | IMPLEMENTED | A | low | — |
| SC-03 | README §2 "Known gaps" (only source) | Add a **new product line** to an already-placed order | **None.** Only `PATCH /orders/{order}/items/{item}/fulfillment` (quantity) and `/reschedule` exist (`routes/api_v1.php`); `OrderFulfillmentService::adjustItemQuantity` only increases/decreases an existing line; the only post-checkout `OrderItem::create` is a same-product date split (`splitItemForReschedule`). No frontend API/UI. | none | **MISSING** | A, B | medium-high (money + inventory) | **C candidate** |
| RH-01 | REQ-01/REQ-17; R-01 | 10 roles incl. `gudang`, canonical `sales-kurir-sub` | `RoleSeeder`; `2026_09_29_090000_rename_sales_kurir_role_to_sales_kurir_sub` | `WarehouseRoleTest`, `SalesKurirSubRoleTest` | IMPLEMENTED | — | low | — |
| RH-02 | REQ-19; R-01 | Sales-Kurir referral code (SS- for new; SA-/SK- preserved on conversion) | `HierarchyRules::REFERRAL_PREFIXES`, `UserManagementService::createWithUniqueReferralCode`/`convertSalesToSalesKurir` | `UserManagementTest` | IMPLEMENTED | — | low | — |
| RH-03 | PBR-001 | Sales-Kurir can see/use own referral code in UI | `ProfileView.vue` (gate line 53; block line 227+; copy + `?ref=` link) and `KurirDashboardView.vue` (lines 193–211) | covered by role/UI type-check; no UI automation | IMPLEMENTED | — | low | — |
| RH-04 | `HierarchyRules` | Creation matrix (super_admin→agen; agen→…; korsal→sales/sales-kurir-sub) | `HierarchyRules::ALLOWED_CREATIONS` + `UserManagementService` + `UserPolicy` | `UserManagementTest` | IMPLEMENTED | — | low | — |
| CV-01 | REQ-03/REQ-04; R-04 §4.3 | Product/Variation CRU; Admin NO Delete | `ProductPolicy::delete` = super_admin/agen; DELETE routes split out of admin group | `R04OrderProjectionTest` (delete denial) | IMPLEMENTED | B | low | — |
| CV-02 | BLUEPRINT §3.6 | Global SKU registry + immutable snapshots | `catalog_skus`, `products:backfill-sku`, `order_items.sku_snapshot` | `GlobalSkuTest` | IMPLEMENTED | — | low | — |
| CV-03 | SECURITY_AUDIT #2 | Product description sanitized (stored-XSS) | `ProductController` uses `HtmlSanitizerService` | covered by catalog tests | IMPLEMENTED | — | low | — |
| CV-04 | SECURITY_AUDIT (noted) | `ProductTranslation.description` sanitization | No controller writes that model (dead write path, unreachable) | none | DEFERRED | — | low (not active) | backlog |
| WH-01 | REQ-05/REQ-06/REQ-08 | 5 buckets; Transit intake; Plan Pabrik + Admin toggle | `warehouse_stocks`, `WarehouseStockService`, `warehouse_settings.factory_plan_enabled` | `WarehouseStockTest`, `WarehousePlanTransferTest` | IMPLEMENTED | — | low | — |
| WH-02 | REQ-13/REQ-14 | Transfers + handover, atomic, A4 print | `StockTransferService`, `stock_handovers` | `StockTransferTest`, `TransferOpnameConcurrencyTest` | IMPLEMENTED | — | low | — |
| WH-03 | REQ-15; OD-WH-008/013 | Opname (physical / plan / sellable); Admin approves | `StockOpnameService`, `StockOpname*` | `StockOpnameTest` | IMPLEMENTED | — | low | — |
| WH-04 | REQ-10/REQ-11/REQ-16 | Stock request on `diproses`; partial fulfillment | `StockRequestService`, `StockRequest*` | `StockRequestTest`, `StockRequestSplitTest` | IMPLEMENTED | — | low | — |
| WH-05 | REQ-09; OD-WH-003 | Sellable = Transit + active Plan − Reserved; storefront cutover | `SellableStockService`, `WarehouseStockService` | `CanonicalStockReportingTest`, `WarehouseStockTest` | IMPLEMENTED | — | low | — |
| WH-06 | R-01/R-02 | Sub Location + 1:1 ownership | `WarehouseSubLocation`, `SubLocationOwnershipService` | `SubLocationOwnershipTest`, `SubLocationEligibleOwnersTest` | IMPLEMENTED | A | low | — |
| WH-07 | R-02 | Sub replenish (Transit→Sub) / return (Sub→Transit) request→approve→execute→receive | `SubStockRequestService`, `SubStockService` | `SubStockRequestFlowTest`, `SubStockReplenishmentTargetsTest` | IMPLEMENTED | A | low | — |
| WH-08 | PBR-003 | Warehouse screens show product name / variant / SKU, never bare IDs | Mostly done. Gaps: `WarehouseTransfersView.vue:467` and `WarehouseOpnamesView.vue:270` read `variation.label`, but `StockTransferController::index()` / `StockOpnameController::index()` return **raw models** whose variation JSON has no `label` (method, not accessor). `transfers.ts`/`opnames.ts` over-claim `variation.label`. Product name + SKU do render. | `PbrRemediationTest` asserts product name only (no variant-label test) | **PARTIAL** | — | low | backlog |
| WH-09 | taste; Package A | Canonical multi-target lock order; parent→child Sub order | `StockService::canonicalReservationTargets`, `OrderService` prelocks | `MultiTargetInventoryLockOrderTest`, `SubCheckoutConcurrencyTest` | IMPLEMENTED | A | low | — |
| WH-10 | FINAL_AUDIT §Fixes | `StockService::deductProduct/deductVariation` removal or wiring | Still defined and **never called** (dead code) | none | DEFERRED | — | low (cleanliness) | backlog |
| OL-01 | BLUEPRINT §3.4 | Order lifecycle + state machine | `Order::TRANSITIONS`, `OrderService` | `OrderTest`, `CancellationRulesTest` | IMPLEMENTED | — | low | — |
| OL-02 | R-03 §E | Fulfillment quantity adjust with money reconciliation | `OrderFulfillmentService` | `OrderFulfillmentTest`, `FinancialObligationReconciliationTest` | IMPLEMENTED | — | low | — |
| OL-03 | R-03 §A | Sales-Kurir-Sub self-delivery (`self_sub`) | `Shipment::delivery_mode`, `self_delivered_by_user_id`, triggers | `SalesKurirSubSelfDeliveryTest` | IMPLEMENTED | A | low | — |
| OL-04 | R-03 §B | Append-only Admin delivery verification, Idempotency-Key | `DeliveryVerification`, `DeliveryVerificationService` | `DeliveryVerificationTest`, `DeliveryVerificationConcurrencyTest` | IMPLEMENTED | — | low | — |
| OL-05 | R-03 §C/§D | Split lineage + delivery-date grouping | `order_items.split_from_order_item_id`, `delivery_groups` resource | `DeliveryDateGroupingTest`, `StockRequestSplitTest` | IMPLEMENTED | — | low | — |
| OL-06 | R-03 §E/§F | Safe Sub adjust/split/customer-return | `SubQuantityAdjustmentTest`, `SubReturnDomainTest` | IMPLEMENTED | A | low | — |
| PY-01 | README §2 | COD / DP / manual transfer / 3 gateways | `PaymentService`, gateway clients, webhooks | `PaymentSystemTest`-family | IMPLEMENTED | — | low | — |
| PY-02 | BLUEPRINT §3.7.1 | `PaymentSummaryService` canonical order payment truth | `PaymentSummaryService` | report/payment tests | IMPLEMENTED | — | low | — |
| PY-03 | BLUEPRINT §6.3 | Additional-payment/refund driven by real money | `OrderFulfillmentService`, `PaymentService` | `FinancialObligationReconciliationTest` | IMPLEMENTED | — | low | — |
| PY-04 | R-04 §4.5 | Per-order finance report via `PaymentSummaryService` | `GET /reports/finance-orders`, `ReportService` | `R04ReportTest` | IMPLEMENTED | B | low | — |
| CR-01 | REQ-17/INV-17; IMPL Phase G | Cancellation inventory reversal (release vs shipping reversal) | `InventoryCancellationService` | `CancellationReplenishmentConcurrencyTest` | IMPLEMENTED | — | low | — |
| CR-02 | IMPL Phase G; R-03 §F | Return condition-inspection + restock (Transit/Sub) | `ReturnService`, `StockReturnDisposition` | `WarehouseReturnDispositionTest`, `SubReturnDomainTest` | IMPLEMENTED | — | low | — |
| CR-03 | BLUEPRINT §6.3 | Refund = real overpayment only | `AdminRefundsView.vue`, `OrderFulfillmentService` | `OrderFulfillmentTest` | IMPLEMENTED | — | low | — |
| CM-01 | BLUEPRINT §4 | CMS homepage/articles/pages (CKEditor, sanitized) | `Cms*` services/controllers; `Cms*View.vue` | CMS tests | IMPLEMENTED | — | low | — |
| ML-01 | README §2 "Known gaps"; BLUEPRINT §4 | 4-language backend + frontend switcher | Backend `lang/{id,en,ar,zh}`; frontend `LanguageSwitcher.vue`, `stores/locale.ts` | type-check only | **PARTIAL** (switcher not mounted in `DashboardLayout.vue` header) | — | low | backlog |
| DM-01 | FINAL_AUDIT "Dark mode FAIL" | Dark mode | `main.css` `@custom-variant dark`; `stores/ui.ts`; toggles in header/layout/profile/installer | type-check only | IMPLEMENTED (README statement stale) | — | low | — |
| RC-01 | BLUEPRINT §3.4.1 | Per-shipment thermal receipt, read-only, audited | `ShipmentReceiptView.vue`, `ShipmentPolicy::printReceipt` | `ShipmentReceiptPrintTest` | IMPLEMENTED | — | low | — |
| RP-01 | ORDER-TRANSACTION-REPORT-AUDIT | Canonical 14-column item-level report | `OrderTransactionReportService` | `ReportSystemTest`, `CanonicalStockReportingTest` | IMPLEMENTED | — | low | — |
| RP-02 | FINAL_AUDIT "Excel export PARTIAL" | XLSX export reachable from UI | `ExcelExportService`, `ReportsView.vue` | export tests | IMPLEMENTED | — | low | — |
| RP-03 | R-04 §4.5 | Finance/operational report scoping | `ReportController`, `ReportService`, `KeuanganReportingTest` | `R04ReportTest` | IMPLEMENTED | B | low | — |
| RP-04 | docs/GOOGLE-SHEETS-INTEGRATION | One-way whitelisted Sheets export | `DatasetRegistry`, `SyncService`, `GoogleSheetsView.vue` | `GoogleSheetsTest`, `GoogleSheetsFormulaInjectionTest` | **IMPLEMENTED (runtime INCOMPLETE — ops)** | — | low (ops) | REVIEW-RC4 |
| AU-01 | BLUEPRINT §6.6 | Full `activity_logs` audit trail | `ActivityLogger` | covered across suites | IMPLEMENTED | — | low | — |
| AU-02 | R-04 §4.1/§4.2 | Role authority + operational-vs-financial projections | `OrderResource`/`OrderItemResource`/`CourierOrderResource`, `OrderPolicy` | `R04OrderProjectionTest` | IMPLEMENTED | B | low | — |
| AU-03 | SECURITY_AUDIT | Confirmed vulnerabilities fixed | throttling, sanitization, return-race lock, webhook idempotency | regression suites | IMPLEMENTED | — | low | — |
| AU-04 | Package A/B | Idempotency + concurrency invariants | canonical lock order, Idempotency-Key, unique constraints | dedicated concurrency suites | IMPLEMENTED | A, B | low | — |
| AU-05 | IMPL-REPORT "Known dependency risk" | Framework dependency security | `laravel/framework ^11.31`; `composer audit` previously reported 3 advisories incl. CVE-2026-48019 with no 11.x patch | none in-repo | **REVIEW** | — | medium-high | REVIEW-RC2 |
| DP-01 | Package B closure | Never delete `public_html/laravel.php` | `AGENTS.md` §8; `README.md` §19 | doc | IMPLEMENTED | — | low | — |
| DP-02 | Package B closure | Non-blocking CLI warnings (OPcache/mbstring) | environment only | n/a | DEFERRED | — | low | backlog |
| DP-03 | FINAL_AUDIT "Automated tests PARTIAL" | Frontend automated test suite | No test script/runner/dep in `frontend/package.json`; no `*.spec.*` in `src` | none | **MISSING** | — | medium (quality) | backlog |
| DP-04 | FINAL_AUDIT "Performance audit FAIL" | Performance audit (N+1, indexes, pagination, bundle) | No perf doc; no phpstan/larastan; no app-wide cache layer | none | **MISSING** | — | medium | backlog |
| DP-05 | BLUEPRINT §3.1 | Installer wizard + permanent lock | `InstallController`, `InstallerView.vue`, `stores/install.ts`; both `/install` and `not.installed` guards | `Install*` tests | IMPLEMENTED | — | low | — |
| DP-06 | AGENTS §13; README; BLUEPRINT; FINAL_AUDIT; warehouse spec; PBR report | Docs reflecting reality | See §5 | n/a | **SUPERSEDED** | — | low (doc) | **doc pass** |

---

## 5. Documentation superseded by Package A / Package B

These documents are stale relative to source reality. They are recorded as **documentation gaps** (not implementation gaps):

1. **`README.md` §2** — states "8 roles: super_admin, agen, korsal, sales, konsumen, admin, keuangan, kurir". Source has **10 roles** incl. `gudang` and canonical `sales-kurir-sub`. Additionally §2 "Known gaps" still lists "no dark-mode toggle", "no frontend language-switcher UI", and "no frontend test suite" — the first two are now implemented (only the frontend test suite remains true).
2. **`BLUEPRINT.md` §5 / §2** — still describes "8 role tetap" and no `gudang`/`sales-kurir-sub`; the network diagram and permission table predate Packages A/B.
3. **`backend/FINAL_AUDIT_REPORT.md`** — its 57-item checklist and its "did not build" list are largely **superseded**: the installer, all role dashboards, courier dashboard, stock/fee/reports/audit-log/website-settings/contact-agent admin UIs, language switcher, and dark mode are now implemented. Only the frontend test suite and performance audit remain genuinely open.
4. **`backend/SECURITY_AUDIT.md`** — its findings are historical; fixes are present in source. `ProductTranslation.description` remains a noted-but-unreachable dead path.
5. **`PBR_RECON_REPORT.md`** — PBR-001, PBR-002 and PBR-003 are all now addressed in source (referral UI, commission `sales-kurir-sub => ['sales','courier']` at `CommissionController.php:38`, warehouse product-name display). Only the PBR-003 variant-label residual (WH-08) remains.
6. **`docs/WAREHOUSE-AND-SALES-COURIER-SPECIFICATION.md`** — header still reads "Status: SPECIFICATION ONLY — NOT IMPLEMENTED … commit `6d54f6b`". Phases A–L plus Packages A/B have since implemented the bulk of REQ-01…REQ-25. It should be re-labelled as the historical specification/baseline, with the implementation report as the build record.
7. **`docs/IMPLEMENTATION-REPORT.md`** — Phase A–L log; its later phases are superseded by the Package A/B checkpoints.
8. **`docs/R01-R02-SALES-KURIR-SUB-AND-SUB-STOCK.md`** — "Deferred (not in Package A)" section is now largely delivered by Packages B; its R-03/R-04 deferrals are closed.
9. **`AGENTS.md` §13 "Current roadmap"** — still says "Package B: R-03 + R-04 — next active package". This is stale: Package B is production-closed. (Left to the documentation-owner decision; a roadmap refresh is recommended.)
10. **`docs/PACKAGE-B-R03-R04-CHECKPOINT.md`** — the earlier "next: DEV UAT" / "EXACT NEXT ACTION" entries are historical; the closure section supersedes them (consistent with the existing "historical entries are superseded" convention used in the Package A checkpoint).

A **documentation reconciliation pass** is a legitimate, low-risk next action, but it is *not* the same as a feature package and is not proposed as Package C (see §8).

---

## 6. Actual remaining gaps (source-verified)

**Implementation gaps (real code missing/partial):**

- **SC-03 — add a new product line to an already-placed order.** Genuinely missing. Only quantity adjust/reschedule of *existing* items exist. Evidence: `routes/api_v1.php` (fulfillment `adjust` + `reschedule` only), `OrderFulfillmentService::adjustItemQuantity`, `orders.items.*` request classes, `frontend/src/api/orderAdjustments.ts`. Documented only as a README "Known gap".
- **WH-08 — warehouse variant-label on transfers/opnames lists.** Partial. `variation.label` is not serialized because those two index endpoints return raw models. Product name + SKU are fine. Also a frontend type over-claim (`transfers.ts`, `opnames.ts`).
- **ML-01 — language switcher not mounted in the dashboard header.** Partial (storefront + auth layouts have it; `DashboardLayout.vue` does not).
- **DP-03 — no frontend automated test suite.** Tooling gap.
- **DP-04 — no performance audit.** Audit/evidence gap (no perf doc; no static analysis; no query profiling evidence).

**Documentation gaps:** §5 items (README/BLUEPRINT role count and stale known-gaps; FINAL_AUDIT checklist; PBR report; warehouse spec status; AGENTS §13; checkpoint historical lines).

**Operational (non-code) items:** Google Sheets destination `HTTP 403 PERMISSION_DENIED` (share the spreadsheet with the service account as Editor); rotate the RajaOngkir API key that previously leaked into a local trace; non-blocking CLI OPcache/mbstring warnings.

---

## 7. REVIEW / CONFLICT items — Human decision required

No business decision is made here.

**RC-1 (REVIEW) — Is "add a new product line to an existing order" an authorized requirement?**
Competing sources: `README.md` §2 lists it under "Known gaps (not implemented)" (implying desire); `BLUEPRINT.md` §3.4/§4 documents only quantity adjustment, reschedule/split, cancellation, and return — it does **not** describe adding a new product line. `AGENTS.md` §1 places a Human Business Decision above README/Blueprint. Decision needed: is line-addition in scope at all, and under what constraints? This is the gate for SC-03.

**RC-2 (REVIEW) — Framework dependency security (`laravel/framework ^11.31`).**
Competing positions: `docs/IMPLEMENTATION-REPORT.md` records `composer audit --locked --no-dev` returning 3 advisories incl. CVE-2026-48019, with no patched 11.x release; moving to Laravel 12 is a major upgrade deliberately not bundled. Decision needed: upgrade to Laravel 12 (with its own package/compat spike) or formally accept and document the risk. Not a code defect; a governance/security decision.

**RC-3 (REVIEW) — Google Sheets runtime + RajaOngkir key rotation.**
Competing states: integration code is complete and tested; runtime connection is `INCOMPLETE` pending a Google-side share (`docs/GOOGLE-SHEETS-INTEGRATION.md`), and `docs/RAJAONGKIR-AUDIT.md` requires rotating a previously-leaked key. Decision needed: schedule the ops actions (no code change implied).

**RC-4 (REVIEW) — Warehouse document name snapshots vs live names.**
Competing rules: the warehouse spec proposed `product_name_snapshot` on document lines (§10.2, §42); the project's current/latest-name rule (BLUEPRINT §4.6; R-04 §4.6) and the established preference against name-snapshot columns mean stock-request items carry only `sku_snapshot` and resolve names live via the eager-loaded relation. Reconciliation needed: confirm live resolution is the intended permanent rule for warehouse documents (recommended, matches existing rules) and that no snapshot column is required.

**No CONFLICT items were found where one rule is being violated by another in a way that affects runtime behaviour.** The role-count and "missing feature" contradictions are documentation-vs-source drift (RC-1/§5), not competing active rules.

---

## 8. Proposed next package (NOT AUTHORIZED)

There is exactly **one genuine, source-verified feature gap** (SC-03) plus a set of quality/hardening gaps and documentation drift. Because a feature plan would require an explicit Human Business Decision (RC-1), the proposal below is presented as the smallest coherent option if the feature is authorized, with a hardening alternative if it is not.

### 8.1 Option C-A (feature) — "Existing-Order Line Addition"

- **Objective:** allow an authorized operational actor to add a brand-new product/variation line to an existing, not-shipped, not-cancelled order, with correct inventory reservation, shipment creation, Stock Request reconciliation, and money-safe payment obligation handling.
- **Included requirement IDs:** SC-03.
- **Explicit exclusions:** warehouse variant-label fix (WH-08), frontend test runner (DP-03), performance audit (DP-04), Laravel 12 upgrade (RC-2), any docs reconciliation pass, any UI redesign, any change to Package A/B behaviours.
- **Dependencies:** Package A (`StockSourceResolver`, Sub reservations), Package B (`OrderFulfillmentService` obligation-freeze rules MAJOR-7/10/11, `OrderTotalCalculator`, `PaymentSummaryService`, `StockRequestService` split reconciliation, delivery verification).
- **Expected backend areas:** `Services/Order/OrderService` (new add-line path) and/or `OrderFulfillmentService`; `StockService`/`SubStockService` reserve; `StockRequestService`; `PaymentService`/additional-payment; `CourierService` fee snapshot; `routes/api_v1.php`; `lang/{id,en,ar,zh}`.
- **Expected frontend areas:** `views/OrderDetailView.vue` add-line control gated by role/status; `api/orders.ts`/`orderAdjustments.ts`; i18n.
- **Expected DB changes:** ideally **none**; a migration is required only if a distinguishing marker is proven necessary (a new line can reuse the existing `order_items` shape with `split_from_order_item_id = null`). Per the project's preference, avoid schema changes unless required.
- **Test requirements:** new Feature test set — role/IDOR authority matrix; inventory conservation (Agent and Sub sources); delivery-date/shipment creation; Stock Request creation/idempotency; money rules (pending obligation freeze; no duplicate commission); concurrency (deterministic real multi-connection race on the same item/order); negative paths (order status not eligible → 422; no silent payment mutation).
- **Human decisions required:** (1) authorize SC-03 at all and confirm scope/exclusions; (2) allowed order statuses (before `diproses`? before `dikirim`?); (3) allowed payment states / whether an outstanding obligation blocks addition (mirroring MAJOR-11); (4) whether Sub-sourced orders may add a line (and from which source); (5) default delivery date for the new line; (6) whether commissions/fees are earned for the added line.
- **Why these belong together:** SC-03 is a single coherent capability whose correctness depends on the Package A/B order/inventory/payment invariants; everything else on the backlog is independent of it.

### 8.2 Option C-B (hardening) — only if SC-03 is NOT authorized

If the Human declines SC-03, the smallest worthwhile package is hardening/quality rather than inventing features:

- **PC-H1 — Warehouse presentation integrity (WH-08):** wrap `StockTransferController::index`/`StockOpnameController::index` (or equivalent) so `variation.label` is serialized (reusable warehouse item resource), align `transfers.ts`/`opnames.ts` types, add regression tests asserting product name + variant label + SKU.
- **PC-H2 — Frontend automated test foundation (DP-03):** introduce Vitest + a minimal suite covering auth/route guards, checkout, role-based order projection, and commission separation.
- **PC-H3 — Performance audit (DP-04):** produce a written N+1/index/pagination/bundle audit with evidence and targeted, non-speculative fixes.
- **PC-H4 — Documentation reconciliation pass (§5):** refresh README/BLUEPRINT role counts and known-gaps, re-label the warehouse spec as historical, and refresh `AGENTS.md` §13.

### 8.3 Explicitly not proposed

- Any change to Package A/B closed behaviour (R-01/R-02/R-03/R-04).
- Any new business rule, role, or financial model.
- Any speculative feature beyond SC-03.
- Any production migration or deployment.
- Any Laravel 12 upgrade until RC-2 is decided as its own, separately-scoped effort.

---

## 9. Human decisions — RECORDED (2026-10-01)

1. **RC-1 — APPROVED.** SC-03 (add a new product/variation line to an existing order) is an authorized business requirement, distinct from quantity adjustment. Option **C-A** is selected.
2. **RC-2 — NO CHANGE / OUT OF SCOPE.** No Laravel 11 → 12 upgrade; no framework-upgrade remediation in Package C.
3. **RC-3 — NO CHANGE / OUT OF SCOPE.** Google Sheets and RajaOngkir operate satisfactorily; no redesign, credential rotation, sharing change, or behavior change. Revisit only via an explicit future Human request or a concrete operational defect.
4. **RC-4 — APPROVED CURRENT BEHAVIOR / NO CHANGE.** Stable IDs with current/latest-name resolution for warehouse/report actors; no historical name snapshots.
5. **Package selection — C-A (feature).** Option C-B (hardening) is not selected.

**Roadmap freeze:** WH-08, DP-03, DP-04, dead code, documentation drift, framework upgrade, and operational housekeeping remain documented backlog only and do **not** authorize implementation. After Package C production closure, the current approved Prime Classy feature roadmap is complete; a future Package D or equivalent requires either an explicit Human change request or a concrete production/application defect.

**Closure:** roadmap recon is CLOSED. The active Package C specification is `docs/PACKAGE-C-SC03.md`, with `docs/PACKAGE-C-SC03-CHECKPOINT.md`.

---

## EXACT NEXT ACTION

**Human reviews and authorizes Package C SC-03 implementation. No other feature implementation is authorized.**
