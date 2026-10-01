# Package B (R-03 + R-04) — Checkpoint

**Status:** PACKAGE B PRODUCTION CLOSED — R-03 + R-04 implemented, reviewed, DEV/UAT PASS, Human Stage Gate APPROVED, deployed to production and smoke-verified. No remaining Package B implementation/remediation action.
**Created:** 2026-10-01
**Closed:** 2026-10-01
**Active spec:** `docs/PACKAGE-B-R03-R04.md`
**Repository protocol:** `AGENTS.md`

## Objective

1. R-03 — fulfillment/delivery lifecycle, Sales-Kurir-Sub self-delivery, final Admin delivery verification, delivery-date grouping, safe Sub adjustment/split/return, order-generated stock request reconciliation for splits.
2. R-04 — role authority/security, operational-vs-financial projections, Admin Product/Variation CRU-no-Delete, operational and finance reports, current/latest actor names.

**Active phase: R-03 ONLY.** R-04 stays untouched.

## Branch / baseline

- Branch: `feat/package-b-r03-r04`; base (documentation): `8b53f78a9368dfd551e12f4c15a84ad9ed821ff3`.
- Recon commit: `c9ede8a160e204419cc15bd95371b6857433b696` ("docs: lock R-03 recon and baseline").
- Backend baseline (`php artisan test`): **738 passed / 5055 assertions / 0 failures** (678.86s).
- Frontend baseline: `npm run type-check` **PASS**; `npm run build-only` **PASS**; only the pre-existing chunk-size (>500 kB) warning.

### R-03 result (this pass)

- Backend after R-03 (`php artisan test`): **768 passed / 5238 assertions / 0 failures** (693.19s) — baseline 738 + 30 new tests.
- Frontend after R-03: `npm run type-check` **PASS**; `npm run build-only` **PASS**; same pre-existing chunk-size warning only.
- Target migrations were applied only to the isolated test database (`primeclassy_testing`). Production untouched; no production migration run.
- After review remediation (see §R-03 Remediation below): backend **787 passed / 5384 assertions / 0 failures**; frontend PASS.

## R-03 REMEDIATION (review findings closed)

Reviewed baseline HEAD before remediation: `3273278cca9ca078e5040a1799c33fd87383ce61`.

- **BLOCKER-1 — generic status bypass:** `OrderService::updateStatus()` now rejects a Sub-sourced item for BOTH `dikirim` and `terkirim` (previously only `dikirim`). The office generic path can no longer bypass self-delivery ownership or the delivery-proof requirement; Agent-sourced office behavior unchanged.
- **BLOCKER-2 — courier dashboard leak:** `CourierDashboardController::orders()` scopes a Sales-Kurir-Sub to their OWN `self_sub` shipments (`delivery_mode=self_sub AND self_delivered_by_user_id=self`) for both `diproses` and `dikirim`; `CourierOrderResource` filters items the same way. Route group split: `/kurir/orders` = kurir+sales-kurir-sub; `/kurir/returns*` and `/kurir/reports/delivered` = kurir only (no "missing Courier profile" dead actions for Sales-Kurir-Sub).
- **MAJOR-3 — verification policy bypass:** `OrderResource.delivery_verifications` is emitted only to `super_admin`/`admin`; Konsumen/Sales/Korsal/Kurir no longer receive verifier identity or internal notes. Admin UI may still use `GET /shipments/{shipment}/delivery-verifications`.
- **MAJOR-4 — idempotency:** delivery verification now REQUIRES a non-empty `Idempotency-Key` (≤100 chars). A replay is valid only for an identical `(shipment_id, outcome, normalized note)`; otherwise 409. Frontend retains one key per logical submission until success.
- **MAJOR-5 — counter reconciliation:** an increase restores `cancelled_quantity` before counting `additional_quantity`; a fully cancelled (terminal) line rejects increase with 422 and never reactivates its Sub reservation. Return capacity now uses `fulfilled_quantity - returned_quantity`.
- **MAJOR-6 — self_sub frontend controls:** `OrderItemResource` exposes `delivery_mode` + `self_delivered_by_user_id`; `OrderDetailView` gates pickup/deliver/assign-courier/receipt accordingly (self_sub = owner only; standard = never a Sales-Kurir-Sub). Backend remains authoritative.
- **AUDIT continuity:** `Shipment::selfDeliveredBy()` and `DeliveryVerification::verifiedBy()` use `withTrashed()` so soft-deleted actors stay resolvable.
- **MIGRATION verification:** fresh apply OK; invariants enforced (self_sub+courier rejected, invalid mode/actor rejected, valid standard/self_sub accepted); rollback (`--step=3`) removes triggers → CHECK → FK/columns/table cleanly and `migrate` restores them.

Files changed (remediation): `Services/Order/{OrderService,OrderFulfillmentService,ReturnService,DeliveryVerificationService}.php`; `Http/Controllers/Api/V1/{Courier/CourierDashboardController,Order/DeliveryVerificationController}.php`; `Http/Resources/{OrderResource,OrderItemResource,CourierOrderResource}.php`; `Models/{Shipment,DeliveryVerification}.php`; `routes/api_v1.php`; `lang/{id,en,ar,zh}/messages.php`; `.phpunit-concurrency-actor.php`; frontend `api/types.ts`, `views/OrderDetailView.vue`.

Tests added/extended: new `FulfillmentCounterReconciliationTest`, `AuditContinuityTest`, `DeliveryVerificationConcurrencyTest`, `MigrationVerificationTest`; extended `SalesKurirSubSelfDeliveryTest`, `DeliveryVerificationTest`, `SubQuantityAdjustmentTest`, `SubReturnDomainTest`; new actor op `delivery-verify`.

Migrations: **unchanged** (the three R-03 migrations were not modified; no new migration added).

Exact results after remediation: `php artisan test` → **787 passed / 5384 assertions / 0 failures** (704.97s). `npm run type-check` → PASS. `npm run build-only` → PASS.

## R-03 FINAL REMEDIATION (MAJOR-7/8, MINOR-9)

Reviewed HEAD before this pass: `1b4e465148df66431d1a90b680f4f3f0e086c80e`.

- **MAJOR-7 — stale financial obligation:** `OrderFulfillmentService` locks the financial row deterministically (before any inventory mutation). An INCREASE is rejected 422 while the item has a pending `OrderItemAdjustment` (`refund_status=pending`); a REDUCTION is rejected 422 while the item's linked `OrderAdditionalPayment` is `pending`. Once the obligation reaches a terminal state (processed / paid / failed), existing payment rules apply again. No ledger rows deleted or silently invalidated; `PaymentSummaryService` remains canonical.
- **MAJOR-8 — courier fee follows active quantity:** `courier_fee_amount` now tracks the current active fulfilled quantity — scaled on reduce/increase and allocated proportionally on a partial split (parent + child exactly conserved; rounding remainder kept on the parent), always derived from the historical snapshot, never today's catalog config. Agent/Sales fee snapshots and already-created commission rows are untouched. Courier commission is still earned per item on delivery (covers multi-courier and Sales-Kurir-Sub self-delivery).
- **MINOR-9 — self_sub receipt visibility:** frontend `printableShipmentGroups()` now mirrors `ShipmentPolicy::printReceipt` for `self_sub` (owning Sales-Kurir-Sub, super_admin, and same-Agent agen/admin may print; a normal Kurir may not). Office roles still get NO pickup/deliver/assign controls on a `self_sub` shipment.

Files changed: `app/Services/Order/OrderFulfillmentService.php`; `lang/{id,en,ar,zh}/messages.php`; `frontend/src/views/OrderDetailView.vue`; extended `tests/Feature/SalesKurirSubSelfDeliveryTest.php`. New tests: `FinancialObligationReconciliationTest`, `CourierFeeSplitTest`.

Migrations: **unchanged** (no new migration).

Exact results after final remediation: `php artisan test` → **795 passed / 5442 assertions / 0 failures** (598.23s). `npm run type-check` → PASS. `npm run build-only` → PASS.

## R-03 FINAL CONCURRENCY REMEDIATION (MAJOR-10, MAJOR-11)

Reviewed HEAD before this pass: `16e56f8e54060ef2888fc84d0942f28ed9fbbf31`.

- **MAJOR-10 — financial lock-order deadlock:** the adjustment-side financial guards no longer take a FOR UPDATE lock. `increaseFulfillment()`'s pending-refund check and `reduceFulfillment()`'s pending-additional-payment check are now plain reads. `OrderItem` is already locked (concurrent adjustments on the same item serialise), and a stale pending read only yields a conservative 422. This removes the `Order -> financial row` lock cycle against Keuangan settlement (`markAdditionalPaymentPaid` / `markAdjustmentRefundStatus`, which lock the financial row first — those lock orders were **not** changed).
  - Coverage (`FinancialConcurrencyTest`, real MySQL harness, 5 iterations each): admin quantity INCREASE vs Keuangan processing a pending refund; admin quantity REDUCTION vs Keuangan settling a pending additional payment. Asserts no deadlock (no 1213/1205, no lock-wait timeout), no 500, one serialized valid outcome, inventory/reservation conserved, and payment state consistent.
- **MAJOR-11 — second increase while additional payment pending:** before ANY quantity increase (after `OrderItem`/`Order` are locked, before inventory/reservation mutation), if the item's linked `OrderAdditionalPayment` is still `pending` → explicit 422 (normal read, no FOR UPDATE per MAJOR-10). A pending obligation now freezes both further increase and reduction until it reaches a terminal state (`paid`/`failed`), after which existing payment rules resume. A pending refund continues to block increases.
  - Tests (Agent + Sub, `FinancialObligationReconciliationTest`): fully paid 3 → 4 creates a pending obligation equal to the one-unit delta; 4 → 5 rejected 422 with fulfilled quantity / order total / remaining_amount / reservation unchanged, exactly one pending obligation, and no second `PaymentTransaction`; after settling it `paid`, 4 → 5 succeeds and creates the next obligation.

Regression preserved (all green): courier-fee scaling (3→2 = 20, 2→3 = 30, 3→4 = 40), split parent+child exact conservation, multi-courier commission, self_sub commission. The three R-03 migrations are **unchanged**.

Files changed: `app/Services/Order/OrderFulfillmentService.php`; `lang/{id,en,ar,zh}/messages.php`; `.phpunit-concurrency-actor.php` (ops `fulfillment-increase`/`fulfillment-reduce`/`refund-process`/`additional-settle`); extended `tests/Feature/FinancialObligationReconciliationTest.php`; new `tests/Feature/FinancialConcurrencyTest.php`.

Migrations: **unchanged** (no new migration).

Exact results after this pass: `php artisan test` → **799 passed / 5599 assertions / 0 failures** (623.47s). Frontend not touched this pass.

## UAT-R03-01 — DEV UAT FINDING (RESOLVED)

Observed in R-03 DEV manual UAT as a Sales-Kurir-Sub: the frontend called the Kurir-only endpoints `GET /kurir/returns` and `GET /kurir/reports/delivered`, both returning 403 after the intentional BLOCKER-2 role-boundary remediation. The backend 403s are **correct** and were preserved (no permission restored).

- **FIX A — Retur tab:** the Retur tab is hidden for Sales-Kurir-Sub and `switchTab('returns')` refuses to run for them, so `/kurir/returns` and `/kurir/returns/*` are never called. Normal Kurir retains the Retur tab and existing behavior unchanged.
- **FIX B — Selesai tab:** `GET /kurir/orders` now accepts an optional `status` filter. For a Sales-Kurir-Sub every status (`diproses`/`dikirim`/`terkirim`) is scoped to their OWN `self_sub` shipments (`delivery_mode=self_sub AND self_delivered_by_user_id=actor`), so their completed history uses `GET /kurir/orders?status=terkirim`. `CourierOrderResource` keeps the same item-level self_sub ownership filter. Agent-source standard shipments, another Sales-Kurir-Sub's shipments, and unrelated Agent branches are never exposed; normal Kurir behavior is unchanged.

Files changed: `app/Http/Controllers/Api/V1/Courier/CourierDashboardController.php`; `frontend/src/api/courier.ts`; `frontend/src/views/dashboard/KurirDashboardView.vue`; extended `tests/Feature/SalesKurirSubSelfDeliveryTest.php`.

Backend tests: own self_sub visible for diproses/dikirim/terkirim; another Sales-Kurir-Sub / Agent-source standard / unrelated-Agent terkirim NOT visible; `/kurir/returns` and `/kurir/reports/delivered` remain 403 for Sales-Kurir-Sub; normal Kurir still reaches both. Frontend: Retur tab removed for Sales-Kurir-Sub, Selesai uses `/kurir/orders?status=terkirim`, no 403 during normal sub dashboard navigation, normal Kurir unchanged (type-check + build green).

Exact results: `php artisan test` → **801 passed / 5618 assertions / 0 failures** (629.60s). `npm run type-check` → PASS. `npm run build-only` → PASS.

## R-04 RECON ONCE — findings + file map

R-04 authorized. Reviewed baseline HEAD: `e5ec8c8daf7cc66f367e9630e06a2efb344222a9` (R-03 closed, 801/5618/0). No architecture contradiction found; **no migration required** (stable IDs + live relations already support the locked requirements).

### Source findings

1. **Order authority hole (A/D/B).** `OrderController::index` performs **no `authorize` and no role narrowing** for `agen/admin/keuangan/kurir/gudang` (only `konsumen/sales/korsal/sales-kurir-sub` are narrowed; `routes/api_v1.php` `GET /orders` has no role group). `OrderResource` exposes `dp_amount/paid_amount/remaining_amount/payment_summary/payment_transaction` to **every** viewer, so a Gudang/Kurir caller currently receives full financial data. `OrderPolicy::view` returns false for `gudang` on detail, but the list bypasses it.
2. **Kurir receives money (A/C/D).** `ShipmentController::updateStatus/assign` return full `OrderResource` (money included) on endpoints gated to `kurir,sales-kurir-sub`. `CourierOrderResource` itself is already money-free, and `CourierDashboardController::orders` already scopes: normal kurir = branch `diproses` (all kurir) + own `dikirim/terkirim`; sales-kurir-sub = own `self_sub` for all statuses (R-03/UAT-R03-01).
3. **Kurir queue exposure (C).** `CourierOrderResource` returns full recipient name/phone/address/lat/lng for **unassigned** `diproses` items (discoverable by every kurir in the branch). Needs a minimal pre-claim projection vs full detail once assigned.
4. **Product/Variation authority (E).** `ProductPolicy::delete` → `manage()` = `super_admin,agen,admin`; DELETE routes are in the `role:super_admin,agen,admin` group; both Product and ProductVariation use `SoftDeletes`; the frontend Delete button is **ungated** in `ProductManagementView.vue` and variation delete in `ProductFormView.vue`. Admin currently CAN delete.
5. **Operational report (F/H) already exists**: `OrderTransactionReportService` is one row per `order_item` with exactly the 14 locked columns (`order_no, order_date, sku, product, unit_price, quantity, item_status, subtotal, customer, delivery_date, courier, order_status, sales, korsal`); actor names resolved by **live joins** to `users`/`couriers` (current/latest). `Tgl Kirim` = `order_items.requested_delivery_date`. **Gap:** for `self_sub` shipments `courier` is null — must resolve to `self_delivered_by_user_id`'s current name.
6. **Finance report (G).** `ReportService::financeSummary/paymentStatus` are aggregate and read `Order.paid_amount/remaining_amount/total_amount` directly; **neither uses `PaymentSummaryService`**, and there is no per-order finance report. Need a per-order finance projection driven by `PaymentSummaryService::summarize()`.
7. **Current/latest names (H).** No actor-name snapshot columns exist on `orders`/`order_items`/`shipments`/`commissions`; names are live relations. Only product + recipient snapshots exist. `Shipment::selfDeliveredBy()` / `DeliveryVerification::verifiedBy()` already `withTrashed()`.

### R-04 file map

- Modify: `Http/Controllers/Api/V1/Order/OrderController.php` (index scoping + authorize); `Http/Resources/OrderResource.php` (role-gated financial projection); `Http/Resources/CourierOrderResource.php` (minimal pre-claim vs assigned detail); `Http/Controllers/Api/V1/Courier/ShipmentController.php` (money-free response for kurir — via OrderResource gating); `Policies/ProductPolicy.php` (delete: `super_admin,agen` only); `routes/api_v1.php` (split product/variation DELETE gate); `Services/Report/OrderTransactionReportService.php` (self_sub courier = self-delivered user name); `Http/Controllers/Api/V1/Report/ReportController.php` + `Services/Report/ReportService.php` (new per-order finance report using `PaymentSummaryService`); `lang/{id,en,ar,zh}/messages.php` (new messages).
- Frontend: `views/OrderDetailView.vue` (guard money sections on presence/role); `views/dashboard/ProductManagementView.vue` + `ProductFormView.vue` (hide Delete for admin); `api/reports.ts` + `views/dashboard/FinanceView.vue` (per-order finance report); `i18n/locales/{id,en,ar,zh}.ts` if needed.
- No migration.

### Push status — PUSH-1 RESOLVED

PUSH-1 is **RESOLVED**. The deploy-key blocker is considered closed by the human; `git push origin feat/package-b-r03-r04` is the intended publish step. (A push attempt from the CI/dev sandbox may still report `Permission denied (publickey)` if that specific environment lacks the key — the repository-side blocker is treated as resolved.)

## R-04 IMPLEMENTATION RESULT (COMPLETE)

Baseline HEAD before R-04: `e5ec8c8daf7cc66f367e9630e06a2efb344222a9` (801/5618/0). **No migration** (stable IDs + live relations already supported every locked requirement).

- **A/B/D — order authority + operational/financial projection:** `OrderResource` and `OrderItemResource` now emit the financial projection only to `super_admin, agen, admin, keuangan, konsumen, sales, korsal`. Gudang and Kurir receive the operational projection instead — no `payment_status/dp_amount/paid_amount/remaining_amount/payment_summary/payment_method/payment_transaction`, and no item `unit_price`/`subtotal`. This closes the previously ungated `GET /orders` leak (any branch role, incl. Gudang, received full `OrderResource`) and the Kurir shipment-status response (which returned the monetary `OrderResource`).
- **C — Kurir queue minimal pre-claim vs assigned detail:** `CourierOrderResource` returns `detail_available=false` with recipient name/phone/street address/coords withheld while a normal Kurir has not claimed the shipment, and the full operational detail once a shipment is assigned to them. Sales-Kurir-Sub self_sub shipments are already theirs → always full detail. R-03 self_sub queue behavior preserved.
- **E — Admin Product/Variation CRU-no-Delete:** `ProductPolicy::delete` is now `super_admin`/`agen` only (Admin denied); the product + variation DELETE routes are moved out of the `super_admin,agen,admin` group (variation delete is authorized against the owning Product policy, so it is covered too); the frontend hides Delete for Admin in `ProductManagementView`/`ProductFormView`. Admin CREATE/READ/UPDATE unchanged.
- **F/G — reporting:** the operational transaction report's `Kurir` column now resolves a `self_sub` shipment to the self-delivering Sales-Kurir-Sub's **current** name (live join on `self_delivered_by_user_id`), keeping the exact 14-column, one-row-per-`order_item` contract and `Tgl Kirim = order_items.requested_delivery_date`. New per-order finance report `GET /reports/finance-orders` (`PaymentSummaryService`-backed, one canonical row per order — never duplicated by items/transactions/refunds; fees limited by the actor's finance authority), surfaced in `FinanceView`.
- **H — current/latest actor names:** verified live-relation name resolution (no snapshot columns); renaming the Sales actor or the self-delivering Sales-Kurir-Sub is reflected on the next report render (regression test).
- **I — security matrix:** forged direct-API tests cover Gudang/Kurir operational-only projection, financial roles/konsumen retaining money, Kurir pre-claim vs assigned detail, and Admin Product/Variation delete denial (with super_admin/agen still allowed).

Files changed (R-04): `Http/Resources/{OrderResource,OrderItemResource,CourierOrderResource}.php`; `Policies/ProductPolicy.php`; `Http/Controllers/Api/V1/Report/ReportController.php`; `Services/Report/{ReportService,OrderTransactionReportService}.php`; `routes/api_v1.php`; frontend `api/courier.ts`, `api/reports.ts`, `dashboard/navConfig.ts`, `views/OrderDetailView.vue`, `views/OrderHistoryView.vue`, `views/dashboard/{FinanceView,KurirDashboardView,ProductFormView,ProductManagementView}.vue`.

Tests added: `R04OrderProjectionTest`, `R04ReportTest` (8 tests).

Migrations: **none**.

Exact results: `php artisan test` → **809 passed / 5751 assertions / 0 failures** (651.68s; 801 baseline + 8 R-04). `npm run type-check` → PASS. `npm run build-only` → PASS.

## R-04 REVIEW REMEDIATION (MAJOR-12, MAJOR-13, D-011)

Baseline HEAD before remediation: `e74f2b9b6fd0e8861fbc9a522da4cbe3ee2f31ce` (809/5751/0). No migration.

- **MAJOR-12 — Kurir generic-order bypass:** `OrderPolicy::view` now returns `false` for a normal `kurir` (the agent-match branch is removed) and `OrderController::index` rejects `kurir` with 403, so a Kurir can no longer obtain generic `OrderResource` through `GET /orders` or `GET /orders/{order}`. Kurir discovery + assigned delivery work stay on `/kurir/orders`. `ShipmentController::updateStatus` now returns `CourierOrderResource` for `kurir`/`sales-kurir-sub` (their own items only — no `sales`/`korsal`, no recipient/sibling/financial leakage); office roles keep `OrderResource`. A mixed-shipment order never exposes a sibling courier's item. Sales-Kurir-Sub R-03 self_sub behavior unchanged.
- **MAJOR-13 — Keuangan operational-report routes:** `GET /reports/transactions` and `GET /reports/sales` moved from `role:super_admin,agen,admin,korsal,keuangan` to `role:super_admin,agen,admin,korsal`. Keuangan keeps the financial surface (`finance-orders`, `finance-summary`, `payment-status`). Frontend nav + router meta updated so Keuangan no longer sees the operational reports.
- **D-011 test gap:** the operational report now has a real stable `korsal_id` fixture; the test asserts the current Korsal name and that renaming the Korsal is reflected on the next render — alongside the existing Sales and self-delivery-actor coverage. No snapshot column, no migration.

Files changed (remediation): `Policies/OrderPolicy.php`; `Http/Controllers/Api/V1/Order/OrderController.php`; `Http/Controllers/Api/V1/Courier/ShipmentController.php`; `routes/api_v1.php`; `tests/Feature/{R04OrderProjectionTest,R04ReportTest}.php`; frontend `dashboard/navConfig.ts`, `router/index.ts`.

Migrations: **none**.

Exact results after remediation: `php artisan test` → **813 passed / 5758 assertions / 0 failures** (639.31s; 801 baseline + 12 R-04). `npm run type-check` → PASS. `npm run build-only` → PASS.



## Package A starting state

Package A R-01/R-02 CLOSED and deployed to production (code baseline `b9ed09b60b1d2cb3d739031c40bd87e79b0f95ed`; migrations `2026_09_29_090000`–`2026_09_29_120000` Ran in batch 7). Production reconciliation: 10 roles; `sales-kurir-sub` canonical; Nida id 21 = role_id 10 / agent_id 11 owning active Sub Location id 3; historical OrderItems remain `stock_source=agent`/`sub_location_id=NULL`; warehouse stocks/movements preserved. Legacy Sub Locations id 1 (`TUTI`) and id 2 (`tina`) remain unowned — never silently map/delete/deactivate.

## FINAL R-03 ARCHITECTURE (LOCKED)

### A. Self delivery

Keep explicit Shipment fields:

- `shipments.delivery_mode` — `enum('standard','self_sub')`, NOT NULL, DEFAULT `'standard'`.
- `shipments.self_delivered_by_user_id` — nullable foreignId → `users(id)`, **RESTRICT ON DELETE**, indexed. (Users use SoftDeletes, so audit identity must NOT be erasable via nullOnDelete.)

MariaDB/MySQL CHECK:

```
(
  delivery_mode = 'standard' AND self_delivered_by_user_id IS NULL
)
OR
(
  delivery_mode = 'self_sub' AND self_delivered_by_user_id IS NOT NULL AND courier_id IS NULL
)
```

Historical shipments: `delivery_mode='standard'`, `self_delivered_by_user_id=NULL`.

- Sub-sourced Order: each Shipment is created as `self_sub`, `self_delivered_by_user_id` = owning Sales-Kurir-Sub, `courier_id = NULL`.
- Agent-sourced Order: `delivery_mode=standard`; existing Kurir workflow unchanged.
- A Sales-Kurir-Sub MUST NOT require a Courier profile; do NOT create fake Courier records.
- Sales-Kurir-Sub may self-deliver ONLY Sub-sourced shipments. An attempt on an Agent-sourced `standard` shipment must be rejected explicitly with an authorization/business-rule error (not via a "missing courier profile" accident).
- Preserve `assertMayShipSubStock()` semantics and `SubStockService::consume()` rules.
- Do NOT create a separate `SelfDeliveryController`; use the existing `ShipmentController` / shipment lifecycle and refactor the service cleanly.
- Update `ShipmentPolicy` (incl. receipt/proof access) so `self_sub` authority derives from `self_delivered_by_user_id` / Sub ownership, not `courier_id`.

### B. Delivery verification

Create `delivery_verifications`:

- `id`
- `shipment_id` — FK `shipments`, NOT NULL, restrictOnDelete
- `outcome` — `enum('received','not_received','return')`, NOT NULL
- `note` — text nullable
- `verified_by` — FK `users`, NOT NULL, restrictOnDelete
- `verified_at` — timestamp NOT NULL
- `idempotency_key` — varchar(100) nullable
- timestamps

Indexes: `(shipment_id, id)`, `outcome`. Unique: `(verified_by, idempotency_key)`.

- Do NOT add `order_id` (order derived through Shipment).
- Do NOT add `return_request_id` in this migration (Admin `return` outcome does not yet identify exact returned items/quantities; ReturnRequest remains its own workflow).
- History is APPEND-ONLY: never update/delete a previous verification to replace an outcome; latest row = current operational outcome (e.g. `not_received` → `received` is valid history).
- Every record preserves: actor, timestamp, outcome, note, shipment ref, and delivery proof indirectly via `shipment.proof_media_id`.
- Controller accepts `Idempotency-Key`; retries must not create duplicate history.
- Admin only, same-Agent authority. Super Admin follows existing explicit application policy — do not invent broader transition privileges.
- Verification is separate from payment verification, transaction verification, and the shipment delivery action.

### C. Split lineage

- `order_items.split_from_order_item_id` — nullable foreignId, self FK → `order_items(id)`, **restrictOnDelete**, indexed. Historical rows = NULL.
- ActivityLog remains supplemental audit; the FK is the canonical structural lineage.

### D. Delivery grouping

- NO invoice table, NO delivery-group table, NO payment columns.
- Canonical grouping: `Order ID + OrderItem.requested_delivery_date`.
- Expose a **derived** `delivery_groups` structure through the appropriate resource/API. Items with the same `order_id + requested_delivery_date` form one group.
- `PaymentSummaryService` remains the ONLY Order-level financial truth. Splitting delivery groups must never duplicate payment state.

### E. Sub quantity adjustment

Do NOT just remove the R-02 422 guards; implement safe Sub reservation reconciliation.

- PRE-SHIPMENT REDUCTION: lock deterministically; reduce the active Sub reservation by the same delta; physical stock unchanged; fulfilled/cancelled quantities stay financially consistent; reuse existing Order total/payment reconciliation semantics; record audit.
- PRE-SHIPMENT INCREASE: lock Sub Location/target/reservation consistently; verify Sub sellable capacity; increase the existing reservation quantity; physical unchanged; preserve existing additional-payment semantics; reject insufficient Sub stock; no Agent reservation touched.
- PRE-SHIPMENT PARTIAL SPLIT: preserve `stock_source='sub'`; preserve original `sub_location_id`; set `split_from_order_item_id`; source reservation decreases by moved qty; new OrderItem gets its own ACTIVE reservation for moved qty; source + child reservation totals equal the pre-split total; physical Sub qty unchanged; new child gets its own Shipment; `self_sub` delivery_mode and self-delivered actor preserved; financial totals not duplicated; audit lineage recorded.
- FULL date reschedule without row split: no reservation quantity change.
- POST-SHIPMENT: never rewrite shipped inventory/history; use return/additional-item workflows.
- Add deterministic concurrency tests for Sub reservation adjust/split races. Preserve Package A canonical Sub lock invariants (`WarehouseSubLocation parent -> Sub WarehouseStock targets -> Sub reservation rows`).

### F. Sub customer return

Do not confuse:

- `SubStockRequest direction=return` → Sub → Agent Transit. **EXISTING BEHAVIOR REMAINS.**

with:

- customer/order `ReturnItem` whose `OrderItem.stock_source='sub'`.

For a Sub-sourced customer return:

- Good returned quantity → original `order_item.sub_location_id` → `WarehouseStock stock_type='sub'`. NOT Agent Transit.
- Create the corresponding `StockMovement`: `stock_type=sub`, original `sub_location_id`, `ReturnItem` reference, positive returned quantity, actor/audit metadata.
- Damaged quantity must NOT become sellable Sub stock.
- If the original Sub Location cannot safely accept the return (invalid/missing/incompatible state): reject with an explicit business error. Never silently redirect inventory to Agent Transit.
- Operation must remain idempotent with existing return inspection/finalization semantics.

### G. Agent StockRequest + OrderItem split (IN SCOPE)

`stock_request_items.order_item_id` is UNIQUE. A split must not leave the StockRequest pointing only at the parent while a new operational OrderItem exists with stale demand.

During an Agent-sourced partial split:

- lock the related `StockRequestItem` if it exists;
- keep total requested/fulfilled/remaining quantities conserved;
- create/reassign the corresponding `StockRequestItem` for the split child when safe;
- preserve the unique `order_item_id` invariant.

If the StockRequest has progressed to a state where quantities cannot be split deterministically without rewriting physical fulfillment history: reject that partial split with an explicit 422. Do NOT silently create inconsistent `StockRequestItem` state.

Tests required: pending request split; partially fulfilled request split; fulfilled request boundary; conservation of `requested_qty`/`fulfilled_qty`/`remaining_qty`; no duplicate `StockRequestItem` for one OrderItem.

### H. Scope correction

- `OrderReportView.vue` is REMOVED from the R-03 implementation map unless a direct R-03 contract dependency is proven. Reporting redesign is R-04.
- NOT implemented in R-03: Gudang financial projection redesign; Kurir queue security redesign beyond R-03 requirements; Admin Product/Variation CRU changes; operational/finance reports; current-name reporting work.

## Schema / migration plan (final)

Three additive migrations. No app code for them yet.

**M1 — `add_self_delivery_to_shipments_table`**
- `shipments.delivery_mode` enum('standard','self_sub') NOT NULL DEFAULT 'standard' (after `status`).
- `shipments.self_delivered_by_user_id` nullable foreignId → users(id) restrictOnDelete + index.
- CHECK constraint (A). Historical rows satisfy it (all `standard`, self NULL).
- Rollback: drop constraint + FK/index + column.

**M2 — `create_delivery_verifications_table`**
- Columns/indexes/unique exactly as decision B.
- History append-only; new table empty at deploy (existing delivered orders are simply unverified).
- Rollback: drop table; no other table references it.

**M3 — `add_split_lineage_to_order_items_table`**
- `order_items.split_from_order_item_id` nullable self-FK restrictOnDelete + index; historical NULL.
- Rollback: drop FK/index/column.

Explicitly NOT proposed: invoice/delivery-group table; payment columns; new columns on `returns`/`return_items`.

## Implementation file map (final)

**Modify (backend):** `Models/{Shipment,OrderItem,Order}`; `Services/Order/{OrderFulfillmentService,ReturnService,CourierService,OrderService,InventoryCancellationService}`; `Services/Stock/{SubStockService,StockRequestService}`; `Policies/{ShipmentPolicy,OrderPolicy}`; `Http/Controllers/Api/V1/{Courier/ShipmentController,Fulfillment/OrderFulfillmentController,Return/ReturnController,Order/OrderController}`; `Http/Resources/{OrderItemResource,OrderResource,ReturnRequestResource}`; `routes/api_v1.php`; `lang/{id,en,ar,zh}/messages.php`.

**New (backend):** `Models/DeliveryVerification.php`; `Services/Order/DeliveryVerificationService.php`; `Policies/DeliveryVerificationPolicy.php`; `Http/Controllers/Api/V1/Order/DeliveryVerificationController.php`; `Http/Resources/DeliveryVerificationResource.php`; `Http/Requests/Delivery/StoreDeliveryVerificationRequest.php`; the three migrations.

**New backend tests:** `SalesKurirSubSelfDeliveryTest`, `DeliveryVerificationTest`, `DeliveryDateGroupingTest`, `SubQuantityAdjustmentTest`, `SubReturnDomainTest`, `StockRequestSplitTest`, `DeliveryVerificationConcurrencyTest`, `SubReservationAdjustConcurrencyTest`.

**Extend backend tests:** `OrderFulfillmentTest`, `ReturnSystemTest`, `CourierSystemTest`, `SubStockSourceTest`, `FulfillmentConcurrencyTest`, `SubStockConcurrencyTest`, `MultiTargetInventoryLockOrderTest`.

**Frontend modify:** `api/{orders,shipments,returns,orderAdjustments,types}.ts`; `views/OrderDetailView.vue`; `views/dashboard/KurirDashboardView.vue`; `views/admin/AdminReturnsView.vue`; `router/index.ts`; `dashboard/navConfig.ts`; `i18n/locales/{id,en,ar,zh}.ts`.
**Frontend new:** `api/deliveries.ts`.
(`views/dashboard/OrderReportView.vue` deliberately excluded per decision H.)

## Implementation sequence

1. Final architecture checkpoint commit.
2. Add the 3 additive migrations + model relations/casts.
3. Run migrations only on `primeclassy_testing` / DEV, never production.
4. Add focused migration/model tests.
5. Implement first-class `self_sub` shipment behavior.
6. Implement append-only Admin delivery verification + idempotency.
7. Implement derived Order+delivery-date grouping.
8. Implement Sub reduction/increase/split reservation reconciliation.
9. Implement Agent StockRequest reconciliation for splits.
10. Implement Sub customer-return restock to original Sub domain.
11. Confirm order-generated Gudang StockRequest behavior.
12. Implement required frontend R-03 surfaces.
13. Run focused backend tests.
14. Run full `php artisan test`.
15. Run frontend `npm run type-check` + `npm run build-only`.
16. Update checkpoint (files, migrations, tests/results, open findings, HEAD, EXACT NEXT ACTION).
17. STOP after R-03 is fully green. Do NOT start R-04, deploy, or run production migrations.

## R-03 IMPLEMENTATION RESULT (COMPLETE)

### Migrations added (3, additive; applied to `primeclassy_testing` only)

- `2026_10_01_100000_add_self_delivery_to_shipments_table.php` — adds `shipments.delivery_mode` (`enum('standard','self_sub')` NOT NULL DEFAULT `'standard'`) + `shipments.self_delivered_by_user_id` (nullable FK → `users`, RESTRICT, indexed).
  - **MariaDB 10.11 limitation (documented):** error 1901 forbids referencing a FK column whose action is SET NULL (`courier_id` is `nullOnDelete`) inside a CHECK. The two-way mode/actor half is a real CHECK (`shipments_delivery_mode_consistent`); the "self_sub never carries a courier" half is enforced by BEFORE INSERT / BEFORE UPDATE triggers (`shipments_self_sub_no_courier_insert|update`, SIGNAL SQLSTATE 45000). No existing FK was altered.
- `2026_10_01_100001_create_delivery_verifications_table.php` — append-only table: `shipment_id` (FK restrict), `outcome` enum(`received`,`not_received`,`return`), `note`, `verified_by` (FK restrict), `verified_at`, `idempotency_key` varchar(100) nullable, timestamps; indexes `(shipment_id,id)` + `outcome`; unique `(verified_by, idempotency_key)`. No `order_id`, no `return_request_id` (per decision B).
- `2026_10_01_100002_add_split_lineage_to_order_items_table.php` — `order_items.split_from_order_item_id` (nullable self-FK restrict, indexed).

### Backend files changed

- Modified: `Models/{Order,OrderItem,Shipment}.php`; `Services/Order/{OrderService,OrderFulfillmentService,CourierService,ReturnService}.php`; `Services/Stock/SubStockService.php`; `Policies/ShipmentPolicy.php`; `Http/Controllers/Api/V1/{Courier/CourierDashboardController,Courier/ShipmentController,Order/OrderController}.php`; `Http/Resources/{OrderResource,ShipmentReceiptResource}.php`; `routes/api_v1.php`; `lang/{id,en,ar,zh}/messages.php`; `.phpunit-concurrency-actor.php` (new `sub-increase`/`sub-reduce` race ops).
- New: `Models/DeliveryVerification.php`; `Services/Order/DeliveryVerificationService.php`; `Policies/DeliveryVerificationPolicy.php`; `Http/Controllers/Api/V1/Order/DeliveryVerificationController.php`; `Http/Resources/DeliveryVerificationResource.php`; `Http/Requests/Delivery/StoreDeliveryVerificationRequest.php`.

### Frontend files changed

- Modified: `api/types.ts` (Order `delivery_groups` + `delivery_verifications`, new `DeliveryGroup`/`DeliveryVerification` types); `views/OrderDetailView.vue` (Admin delivery-verification card); `i18n/locales/{id,en,ar,zh}.ts`.
- New: `api/deliveries.ts`.

### Tests

- New (7): `SalesKurirSubSelfDeliveryTest`, `DeliveryVerificationTest`, `DeliveryDateGroupingTest`, `SubQuantityAdjustmentTest`, `SubReturnDomainTest`, `StockRequestSplitTest`, `SubReservationConcurrencyTest` (deterministic `runServiceRace` proving concurrent Sub increases never oversell).
- Updated (3): `SubStockSourceTest` (obsolete R-02 Sub-guard test → positive reduction assertion); `SalesCourierDualFeeTest` + `PbrRemediationTest` (dual-fee re-based onto Sub-sourced **self-delivery**).

### Intentional behavior change (must be reviewed)

R-03 decision A removes the Package A ability for a Sales-Kurir-Sub to operate an **Agent-sourced (standard) shipment** — that now throws an explicit business-rule error ("may only self-deliver shipments sourced from their own Sub stock"). The Package A dual-fee outcome (sales + courier fee to the same Sales-Kurir-Sub) is **preserved**, now via Sub-sourced self-delivery: `CourierService::recordCommissionsForItems` accepts a `?User $beneficiary` and credits the courier fee to `self_delivered_by_user_id` for `self_sub` shipments (no Courier profile needed). This is the one place Package A behavior changed by design; it is covered by the updated dual-fee/PBR tests.

### Verification commands / exact results

- `php artisan migrate --env=testing --force` → 3 migrations DONE.
- `php artisan test` (full) → **768 passed, 5238 assertions, 0 failures** (693.19s).
- `npm run type-check` → PASS (exit 0).
- `npm run build-only` → PASS (exit 0, ~4.5s); only pre-existing chunk-size warning.

## Progress

- [x] Package A closed; Package B scope documented.
- [x] RECON ONCE + baselines recorded.
- [x] Final R-03 architecture locked (A–H).
- [x] M1–M3 migrations + model changes.
- [x] Focused migration/model tests.
- [x] Self-sub shipment behavior.
- [x] Append-only delivery verification.
- [x] Derived delivery grouping.
- [x] Sub reduction/increase/split reconciliation.
- [x] StockRequest split reconciliation.
- [x] Sub customer-return restock.
- [x] Frontend R-03 surfaces.
- [x] Focused backend tests pass.
- [x] Full backend regression passes (768/5238/0).
- [x] Frontend type-check/build passes.
- [x] Checkpoint updated with results.
- [x] Review remediation (BLOCKER-1/2, MAJOR-3/4/5/6, audit continuity, migration verification) — closed.
- [x] Post-remediation full regression passes (787/5384/0); frontend PASS.
- [x] Final remediation (MAJOR-7/8, MINOR-9) — closed.
- [x] Post-final full regression passes (795/5442/0); frontend PASS.
- [x] Final concurrency remediation (MAJOR-10/11) — closed.
- [x] Post-concurrency full regression passes (799/5599/0).
- [x] R-03 DEV MANUAL UAT (UAT-R03-01 found and fixed; 801/5618/0; frontend PASS).
- [x] R-04 authorized + implemented (authority/projection, product CRU-no-Delete, reports).
- [x] R-04 focused tests pass.
- [x] Full backend regression passes (809/5751/0); frontend type-check + build PASS.
- [x] R-04 review remediation (MAJOR-12/13 + D-011 test gap) — closed.
- [x] Post-remediation regression passes (813/5758/0); frontend PASS.
- [x] Full Package B regression (cross-domain).
- [x] Claude independent final review / direct fixes (815/5771/0).
- [x] Package B DEV/UAT — PASS by Human decision.
- [x] Human Stage Gate — APPROVED.
- [x] Production pre-deployment backup (filesystem + database).
- [x] Production migrations (R-03 batch 8); R-04 no migration.
- [x] Production schema reconciliation (columns/table/triggers verified).
- [x] Package A regression reconciliation on production.
- [x] Frontend production build + deploy.
- [x] Post-deployment smoke test — PASS.
- [x] Package B production closure documentation.

## Open findings

- **PUSH-1 — RESOLVED.** The deploy-key blocker is closed by the human.
- **DESIGN-1/2/3:** resolved by the locked A–H architecture.
- **R03-1 (documented, by design):** MariaDB 10.11 cannot CHECK a SET NULL FK column, so the "self_sub ⇒ courier_id IS NULL" half is enforced by triggers rather than the single CHECK the architecture sketched. Behaviourally equivalent; no existing FK altered.
- **R03-2 (behavior change — CONFIRMED by review):** a Sales-Kurir-Sub can no longer operate an Agent-sourced (standard) shipment (decision A) — the review additionally required the generic `terkirim` path to be blocked too (BLOCKER-1, now fixed). Package A dual-fee is preserved via Sub-sourced self-delivery.
- **R03-3 (by design):** an Agent partial split whose StockRequest remainder cannot cover the moved quantity is rejected 422 (never rewrites fulfilled warehouse history).
- **R03-4 (deferred):** Admin verification outcome `return` records the operational outcome only; it does not itself create a `ReturnRequest` (per decision B). Return remains its own workflow.

**Review findings:** BLOCKER-1, BLOCKER-2, MAJOR-3, MAJOR-4, MAJOR-5, MAJOR-6, MAJOR-7, MAJOR-8, MINOR-9, MAJOR-10, MAJOR-11, audit continuity, and migration verification are all CLOSED with regression coverage (see §R-03 Remediation, §R-03 Final Remediation, and §R-03 Final Concurrency Remediation).

Pre-existing technical debt (NOT Package B regressions): Package A role migration `down()` imperfect inverse; checkout global unique TEMP `order_no`; CLI duplicate OPcache/mbstring warnings; historical "MAC is invalid" log entries.

## Production state

Production is **LIVE with Package B (R-03 + R-04) deployed and production-closed** (2026-10-01). Package A remains CLOSED. Environment `production`, maintenance mode OFF, config/route/view caches CACHED, health smoke PASS. See §PACKAGE B PRODUCTION CLOSURE for the full evidence trail. No further Package B implementation or remediation action remains.

## INDEPENDENT FINAL REVIEW (Claude) — Package B

Reviewer: Claude (independent). Reviewed diff: `8b53f78..ecf9713` (72 files, full diff, not only the last commit). Reviewed HEAD: `ecf97133495e469ca12eb395da5f44a012a2b0f6`.

Reviewed: self-delivery authority/ownership, generic status bypass, delivery verification (append-only, idempotency), Sub reservation reduce/increase/split, Agent StockRequest split, counter/courier-fee/financial-obligation reconciliation, lock ordering (adjust vs Keuangan settlement vs Package A parent->stock->reservation order), Sub customer-return restock, R-04 projections (Gudang/Kurir/list+detail), Kurir queue, Product/Variation delete, operational/finance reports, three R-03 migrations (additive, CHECK + triggers, rollback order), Package A regression. Concurrency/lock code was NOT changed by this review, so no new real-MySQL race coverage was required (existing harness tests remain green).

### Confirmed findings (both fixed directly)

1. **MAJOR — Package A regression: Sales-Kurir-Sub lost the financial projection of its own orders.** Invariant: Sales-Kurir-Sub is Sales + self-delivery (own purchase / referral orders), not an operational-only role; R-04 only restricts Gudang and Kurir. Path: `OrderResource` / `OrderItemResource` `$seesFinancials` role list (and the frontend `OrderDetailView` `seesFinancials`) omitted `sales-kurir-sub`, so on `GET /orders` / `/orders/{id}` a Sub (scoped to `sales_id=self OR konsumen_id=self`) lost `payment_status`, totals, DP/paid/remaining, `payment_summary`, `payment_method`, item `unit_price`/`subtotal` — it could no longer see or settle what it owes on its own purchase. Fix: add `sales-kurir-sub` to the list in both resources and `OrderDetailView.vue`. Test: `R04OrderProjectionTest::test_sales_kurir_sub_keeps_the_financial_projection_of_its_own_orders` (fails without the fix).
2. **MINOR — normal Kurir queue listed Sales-Kurir-Sub self-delivery orders.** Invariant: `self_sub` shipments are never part of the normal Kurir path. Path: `CourierDashboardController::orders` (normal-Kurir branch) matched every `diproses` item in the branch and `CourierOrderResource::itemVisibleToViewer` showed it, so every Kurir saw other Subs' self_sub orders (minimal pre-claim data: order no, region, product, qty) with a claim action that always 403s. No mutation was possible. Fix: normal-Kurir query excludes `delivery_mode=self_sub` shipments (AND-ed with both queue alternatives) and the resource filter hides self_sub items. Test: `SalesKurirSubSelfDeliveryTest::test_normal_kurir_queue_never_lists_a_self_sub_shipment` (fails without the fix).

### Non-findings / verified
- Gudang: list is operational-only, detail 403; Kurir: generic list/detail 403, courier projection money-free, pre-claim minimal vs assigned detail, no sibling-item leakage; Sub dashboard scope unchanged (Order + Selesai via `?status=terkirim`, no `/kurir/returns*`, `/kurir/reports/delivered`).
- Admin Product/Variation CRU-no-Delete enforced by route group AND policy; super_admin/agen delete preserved.
- Operational report: one row per OrderItem, exactly 14 columns, `Tgl Kirim` = `requested_delivery_date`, live names (self_sub actor, Sales, Korsal). Finance report: one row per Order via `PaymentSummaryService`; Keuangan removed from operational report routes.
- Delivery verification: append-only, mandatory Idempotency-Key, conflicting reuse = 409, Admin-only same-Agent.
- Counters/fees: increase restores cancelled before additional; terminal lines not resurrected; courier fee conserved on split; commission not duplicated on retry.
- `OrderFulfillmentService` adjust path is gated by order status `diproses` (any shipped item moves the order to `dikirim`), so Sub `increase()` cannot reactivate a consumed reservation.

### Documentation inconsistency
- The checkpoint header said "R-03 ... AWAITING DEV UAT, R-04 NOT authorized" and the old EXACT NEXT ACTION described a pending regression; both were stale (R-04 is implemented and reviewed). Corrected here.

### Deployment note (not a code defect)
- Migration M1 creates two BEFORE INSERT/UPDATE triggers on `shipments`. On a MySQL/MariaDB server with binary logging enabled the migrating DB user needs the `SUPER`/`SET_USER_ID` privilege or `log_bin_trust_function_creators=1`. It already applied on DEV; confirm the production DB user/config before the production stage.

### Files changed (this review)
`backend/app/Http/Resources/{OrderResource,OrderItemResource,CourierOrderResource}.php`; `backend/app/Http/Controllers/Api/V1/Courier/CourierDashboardController.php`; `frontend/src/views/OrderDetailView.vue`; tests `R04OrderProjectionTest`, `SalesKurirSubSelfDeliveryTest`; this checkpoint.

Migrations: **none changed or added.** Production untouched.

### Results
Focused: `R04OrderProjectionTest`, `SalesKurirSubSelfDeliveryTest`, `CourierSystemTest`, `R04ReportTest` → 63 passed. Full `php artisan test` → **815 passed / 5771 assertions / 0 failures** (671.65s; 813 + 2 regressions). `npm run type-check` PASS; `npm run build-only` PASS (pre-existing chunk-size warning only).

### Remaining risks / open findings
None open. Documented by design: R03-1 (triggers instead of single CHECK), R03-3, R03-4 (verification outcome `return` does not itself create a ReturnRequest). Pre-existing debt unchanged.

## PACKAGE B PRODUCTION CLOSURE — 2026-10-01

### Final source

- Final application code HEAD before closure documentation: **`5bf34dd`** (`5bf34dd...` "fix(package-b): close independent review findings").
- Branch: **`feat/package-b-r03-r04`**.
- Closure documentation is documentation-only; no application code, migration, or test was changed.

### Verification (pre-deployment, final)

- Backend: **815 tests passed / 5771 assertions / 0 failures**.
- Frontend `npm run type-check`: **PASS**.
- Frontend `npm run build-only`: **PASS** (pre-existing chunk-size warning only).
- Working tree was clean.
- Independent final reviewer: **Claude**.

### DEV / UAT

- Package B DEV UAT: **PASS** by Human decision.
- Human Stage Gate: **APPROVED**.

### Production pre-deployment backup

- Production filesystem backup:
  - `/root/primeclassy-backup-package-b-20261001/backend`
  - `/root/primeclassy-backup-package-b-20261001/public_html`
- Database backup: `/root/primeclassy-backup-package-b-20261001/database.sql`
- DB dump size observed: 3.6 MB; `CREATE TABLE` count observed: 89; dump completed successfully.
- Production MariaDB: **10.11.10-MariaDB-log**; `log_bin=OFF`; `log_bin_trust_function_creators=OFF`.
- Because binary logging is OFF, the M1 trigger `SUPER`/`SET_USER_ID` privilege concern (see §Deployment note) was **not** a deployment blocker.

### Production migrations

Package B R-03 migrations ran successfully in **batch 8**:

- `2026_10_01_100000_add_self_delivery_to_shipments_table`
- `2026_10_01_100001_create_delivery_verifications_table`
- `2026_10_01_100002_add_split_lineage_to_order_items_table`

R-04 introduced **no migration**.

### Production schema reconciliation

Verified on production:

- `shipments.delivery_mode` exists.
- `shipments.self_delivered_by_user_id` exists.
- `delivery_verifications` table exists.
- `order_items.split_from_order_item_id` exists.
- Triggers `shipments_self_sub_no_courier_insert` and `shipments_self_sub_no_courier_update` exist; both enforce that a `self_sub` shipment cannot carry a `courier_id`.

### Package A regression reconciliation (production)

Verified on production:

- `roles_count = 10`; `sales-kurir-sub` role count = 1; legacy `sales-kurir` role count = 0.
- Sub Locations unchanged:
  1. `TUTI` / Tuti Mugiastuti / active / owner NULL
  2. `tina` / RS Otista / active / owner NULL
  3. `Cibar` / Sub Cibarengkok / active / owner_user_id 21
- `shipments_total = 3`; `self_sub_shipments = 0`; `invalid_self_sub_with_courier = 0`; `delivery_verifications = 0`. The zero values were **expected immediately after deployment**.

### Frontend

- Production frontend build **PASS**.
- Deployed to: `/www/wwwroot/primeccookies.com/primeclassy/public_html`.

### Deployment incident / PERMANENT PRODUCTION INVARIANT

The initial frontend deployment used:

```bash
rsync -a --delete frontend/dist/ public_html/
```

This **deleted production-specific `public_html/laravel.php`**. Production Nginx routes `/api`, `/sanctum`, and `/up` through that Laravel bridge. Effect: the frontend loaded successfully but API requests returned 404, including `/api/v1/install/status`, `/api/v1/auth/me`, and `/api/v1/homepage`, even though the Laravel routes themselves remained registered and healthy.

The bridge was restored from the pre-deployment backup `public_html/laravel.php`. After restoration: `GET /api/v1/install/status` → 200, `GET /api/v1/homepage` → 200, `GET /api/v1/auth/me` → 401 unauthenticated (expected). Browser smoke then PASS.

**PERMANENT FRONTEND PRODUCTION DEPLOYMENT RULE:**

> Never delete `public_html/laravel.php` during frontend deployment.

The canonical deployment command must explicitly preserve it:

```bash
sudo rsync -a --delete \
  --exclude='laravel.php' \
  /www/dev/primeclassy/frontend/dist/ \
  /www/wwwroot/primeccookies.com/primeclassy/public_html/
```

This is a **production invariant**, not merely a historical note. It is recorded in `AGENTS.md` §8 (production deployment safety) and the README §19 deployment steps so no future frontend deploy command can delete `laravel.php`. (Nginx architecture is unchanged by this documentation task.)

### Post-deployment smoke verification

- Production environment = `production`.
- Maintenance mode = OFF.
- Config cache = CACHED; Routes cache = CACHED; Views cache = CACHED.
- HTTPS homepage = HTTP/2 200.
- `GET /api/v1/install/status` = 200.
- `GET /api/v1/homepage` = 200.
- Unauthenticated `GET /api/v1/auth/me` = 401 (expected).
- Final browser smoke = PASS.

### Known non-blocking CLI warnings

- `Cannot load Zend OPcache - it was already loaded`
- `Module "mbstring" is already loaded`

Neither is a Package B blocker.

### Final status

- Package A = **CLOSED**
- R-03 = **CLOSED**
- R-04 = **CLOSED**
- Package B DEV/UAT = **PASS**
- Package B Human Stage Gate = **APPROVED**
- Package B Production Deployment = **PASS**
- Package B = **PRODUCTION CLOSED**

There is no remaining Package B implementation or remediation action.

## EXACT NEXT ACTION

**Package B is production-closed. Determine and authorize the next roadmap package before any new implementation.** Do not pre-authorize a new package.
