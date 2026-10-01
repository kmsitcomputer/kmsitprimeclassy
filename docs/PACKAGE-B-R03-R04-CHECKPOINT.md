# Package B (R-03 + R-04) — Checkpoint

**Status:** RECON COMPLETE + R-03 ARCHITECTURE LOCKED — AWAITING HUMAN AUTHORIZATION. No R-03/R-04 code written.
**Created:** 2026-10-01
**Active spec:** `docs/PACKAGE-B-R03-R04.md`
**Repository protocol:** `AGENTS.md`

## Objective

Implement Package B in one branch, in strict sequence:

1. R-03 — fulfillment/delivery lifecycle, Sales-Kurir-Sub self-delivery, final Admin delivery verification, delivery-date invoice grouping, safe Sub-order adjustment/split/return.
2. R-04 — role authority/security, operational-vs-financial projections, Admin Product/Variation CRU-no-Delete, operational and finance reports, current/latest actor names.

**Active phase: R-03 ONLY.** Do NOT implement R-04. Do NOT create migrations yet.

## 1. Branch

- Package B branch: `feat/package-b-r03-r04` (verified).
- Branch base (documentation): `8b53f78a9368dfd551e12f4c15a84ad9ed821ff3` (base `main`).

## 2. Starting HEAD

- `e72177d8350638d2aeb42900152ea3f9a16cd4b8` (`e72177d`, "docs: record Package B branch baseline").
- Descends from the branch base above.
- `git status` at RECON: clean, nothing to commit; up to date with `origin/feat/package-b-r03-r04`.

## 3. Backend baseline (VERIFIED — ENV-1 resolved)

Command: `php artisan test`

- **Tests: 738 passed**
- **Assertions: 5055**
- **Failures: 0**
- Duration: 678.86s

Test DB `primeclassy_testing` / user `primeclassy_test` is now provisioned; `ENV-1` is closed.

## 4. Frontend baseline (VERIFIED)

- `npm run type-check` (`vue-tsc --build`): **PASS** (exit 0).
- `npm run build-only` (`vite build`): **PASS** (exit 0, ~4.9s).
- Only pre-existing non-blocking warning: chunks > 500 kB (RichTextEditor). Not a new failure.

## 5. Starting state (from Package A close)

- Package A R-01/R-02: CLOSED; production deployment PASS.
- Package A code baseline before this documentation package: `b9ed09b60b1d2cb3d739031c40bd87e79b0f95ed`.
- Production migrations `2026_09_29_090000` through `2026_09_29_120000`: Ran in batch 7.
- Production reconciliation after Package A:
  - roles = 10; canonical `sales-kurir-sub` present, legacy `sales-kurir` absent;
  - Nida user id 21 remains role_id 10 / agent_id 11;
  - historical referral code remains `SA-4QHJDQ`;
  - historical OrderItems remain `stock_source=agent`, `sub_location_id=NULL`;
  - new Sub reservation/request tables empty; warehouse stocks/movements/transfers preserved;
  - Nida owns active Sub Location id 3 (`Cibar`, "Sub Cibarengkok");
  - Admin and Gudang production UAT passed; unauthenticated Package A endpoint returns 401 (not 404).

Legacy production Sub Locations id 1 (`TUTI`) and id 2 (`tina`) remain unowned. Never silently map/delete/deactivate them in Package B.

## Locked Package B decisions

Read `docs/PACKAGE-B-R03-R04.md`. Key invariants: R-03 first; payment truth Order-level; delivery grouping = Order + delivery date; Sales-Kurir-Sub self-delivers Sub items; Admin final delivery verification separate from payment verification; Gudang/Kurir no unnecessary financial data; Admin Product/Variation CRU-no-Delete; reports show current/latest actor names; preserve Package A stock-source and lock-order invariants; do not remove current 422 Sub guards until an inventory-safe replacement exists.

## 6. LOCKED R-03 ARCHITECTURE DECISIONS

These are locked by the human and must not be re-litigated during implementation.

**A. Sales-Kurir-Sub self-delivery MUST NOT require a Courier profile.**
- Do NOT create a fake `Courier` profile to satisfy the existing code path.
- `shipments.courier_id` remains reserved for normal Kurir.
- R-03 represents the actual self-delivery actor explicitly with a stable user reference (`users.id`) — not a Kurir identity.

**B. Preserve Package A Sub authorization and lock ordering.**
- Do NOT weaken `CourierService::assertMayShipSubStock()`.
- Do NOT weaken Sub reservation consumption rules (reserve -> consume on physical shipment; release pre-shipment).
- Preserve canonical lock order: `WarehouseSubLocation parent -> Sub WarehouseStock targets -> Sub reservation rows`.

**C. Distinguish return domains.**
- `SubStockRequest` direction=return: Sub -> Agent Transit. Existing behavior stays unchanged.
- Customer/order return where `order_item.stock_source=sub`: good returned physical stock must return to the **original `order_item.sub_location_id` / Sub stock domain** — it must NOT silently restock Agent Transit.
- If safe restoration to the original Sub Location is not possible, **reject / require explicit handling**. Never silently remap inventory.

**D. Payment remains Order-level. Do NOT create invoice-level payment truth.**
- `OrderItem.requested_delivery_date` is the canonical delivery grouping key.
- Do NOT create a new invoice/delivery-group table merely because grouping exists.
- A persistent entity is justified only if a stable document number, document lifecycle, immutable issuance/audit identity, or other independent business lifecycle actually requires one. (None of these is required for R-03 grouping → grouping stays derived.)

**E. Admin final delivery verification must be historical/auditable.**
- Required outcomes: `received`, `not_received` / `follow_up`, `return`.
- Do NOT model as a single mutable boolean/status if later actions could erase a previous verification outcome.
- Preserve: actor, timestamp, outcome, history, and relevant delivery/proof reference.

**F. Partial Sub quantity handling.**
- PRE-SHIPMENT SPLIT: preserve `stock_source=sub`; preserve original `sub_location_id`; physical Sub stock unchanged; reservation quantity correctly divided/reconciled; deterministic locks; audit lineage preserved.
- PRE-SHIPMENT REDUCTION: reduce/release reservation only; physical stock unchanged.
- POST-SHIPMENT: never rewrite shipped history as if it had not shipped; use return / additional-item workflow as appropriate.
- Do NOT simply remove the existing R-02 422 guards.

**G. Shipment / self-delivery.**
- Normal Kurir continues to use `Courier` / `Courier` profile.
- Sales-Kurir-Sub self-delivery is a separate first-class path.
- Do NOT make Sales-Kurir-Sub appear as an ordinary Kurir merely to satisfy existing code.

## 7. Files inspected (RECON ONCE)

Protocol + docs: `AGENTS.md`; `docs/PACKAGE-B-R03-R04.md`; this checkpoint.

Models: `Order`, `OrderItem`, `OrderItemAdjustment`, `Shipment`, `ReturnRequest`, `ReturnItem`, `SubStockReservation` (`app/Models/`).

Order services: `OrderService`, `OrderFulfillmentService`, `CourierService`, `ReturnService`, `InventoryCancellationService`, `OrderTotalCalculator` (`app/Services/Order/`).

Payment boundary: `PaymentSummaryService` (`app/Services/Payment/`).

Stock services: `SubStockService`, `StockSourceResolver`, `SubLocationOwnershipService`, `SubStockRequestService`, `StockRequestService`, `StockRequestFulfillmentService` (`app/Services/Stock/`).

HTTP: `OrderController`, `Fulfillment/OrderFulfillmentController`, `Courier/ShipmentController`, `Courier/CourierDashboardController`, `Return/ReturnController`, `Admin/OrderAdjustmentController`; `routes/api_v1.php`.

Policies: `OrderPolicy`, `ShipmentPolicy`.

Resources: `OrderResource`, `OrderItemResource`, `ShipmentReceiptResource`, `CourierOrderResource`.

Migrations (schema context): `2026_09_05_090046_create_order_items_table`; `2026_09_09_090000_add_fulfillment_tracking_to_order_items_table`; `2026_09_10_090001_add_courier_fee_and_delivery_date_to_order_items_table`; `2026_09_11_090000_add_shipment_id_to_order_items_and_allow_multiple_shipments`; `2026_09_05_090052_create_shipments_table`; `2026_09_08_090003_add_shipping_snapshot_to_shipments_table`; `2026_09_05_090048_create_returns_table`; `2026_09_29_110000_create_sub_stock_reservations_table`; `2026_09_29_110001_add_stock_source_to_order_items_table`; Phase-3 Sub migrations.

Tests: layout of `tests/Feature/` (87 files) + `tests/TestCase.php`, `tests/Support/{ConcurrencyHarness,RestoresIsolatedTestDatabase}.php`, `phpunit.xml`; relevant suites identified: `SubStockSourceTest`, `OrderFulfillmentTest`, `OrderStatusTransitionTest`, `ReturnSystemTest`, `CourierSystemTest`, `WarehouseReturnDispositionTest`, `InventoryCancellationReturnTest`, `SubStockRequestFlowTest`, `FulfillmentConcurrencyTest`, `ShipmentReceiptPrintTest`.

Frontend: `src/api/{orders,shipments,returns,courier,orderAdjustments}.ts`; `src/views/OrderDetailView.vue`; `src/views/dashboard/{KurirDashboardView,OrderReportView,WarehouseReturnsView}.vue`; `src/views/admin/{AdminReturnsView,AdminRefundsView,AdminAdditionalPaymentsView}.vue`; `src/views/print/ShipmentReceiptView.vue`; `src/dashboard/navConfig.ts`; `src/router/index.ts`; `package.json`.

## 8. R-03 current-state findings

**Order / OrderItem**
- Shared 7-state lifecycle `diterima -> diproses -> dikirim -> terkirim -> pengembalian -> kembali` (+`dibatalkan`), forward-only via `canTransitionTo()`, enforced in services.
- `OrderItem.requested_delivery_date` (date) is the per-item delivery schedule; `Order.delivery_date_estimate` is the default.
- Quantities: `original_quantity`, `fulfilled_quantity`, `cancelled_quantity`, `returned_quantity`, `refund_quantity`, `additional_quantity`.
- Payment truth is Order-level (`dp_amount/paid_amount/remaining_amount/total_amount` + `PaymentSummaryService`). No `Invoice`/delivery-group model exists anywhere (grep confirmed).

**Shipment / courier**
- `Shipment`: `status` (`pending/picked_up/in_transit/delivered/failed`), `shipped_at`, `delivered_at`, `proof_media_id`, per-shipment `courier_id`. One shipment per order item from creation; reschedule can split onto a fresh shipment.
- `CourierService::updateShipmentStatus`: `diproses->dikirim` and `dikirim->terkirim` (proof mandatory for `terkirim`); consumes Sub reservation on Sub-sourced `dikirim`.
- `CourierService::assertMayShipSubStock` restricts Sub shipping to the owning Sub Location — preserve.
- `recordCommissionsOnDelivery` fires courier commissions only on `terkirim`.
- No Admin "final delivery verification" step exists today; `terkirim` is set by the courier, and returns are a separate consumer-initiated flow.
- `selfAssignIfUnassigned` currently requires a `courierProfile` for both `kurir` and `sales-kurir-sub` — this is exactly what decision A forbids for Sub self-delivery.

**Current Sub 422 guards (do NOT remove until replaced)**
1. `OrderFulfillmentService::adjustItemQuantity` — Sub adjust blocked 422.
2. `OrderFulfillmentService::rescheduleItemDeliveryDate` — Sub partial split blocked 422.
3. `ReturnService::requestReturn` — Sub return blocked 422.
4. `OrderService::updateStatus` — Sub items excluded from office bulk `dikirim` (`sub_item_requires_owner_shipment`).
5. `StockRequestService::createForOrderWhenProcessing` — Sub items excluded from Agent stock request (correct: Gudang must not fulfill Sub).

**Inventory invariants**
- Sub: `Sub Sellable = Sub Physical - active Sub Reserved`; reserve -> consume -> release; one `sub_stock_reservations` row per `order_item_id` (UNIQUE); `quantity` mutable.
- Canonical Sub lock order as in decision B.
- `InventoryCancellationService` prelocks Agent capacity targets before per-item reversal; Sub items release in the Sub ledger only.

**Order-generated Gudang request (§3.6, largely present)**
- `StockRequestService::createForOrderWhenProcessing` creates an order-scoped `StockRequest` when the order enters `diproses` (COD immediately; non-COD on admin status change); Sub items excluded.
- Gudang direct `fulfill()` is disabled (422); Gudang proposes via `StockRequestProposalService`, Admin approves/rejects.

## 9. EXACT proposed additive R-03 schema / migration plan

No application code and no migrations are created yet. Three additive migrations are proposed. Nothing here duplicates payment totals; no invoice/delivery-group table is created (decision D).

### Migration 1 — `2026_10_01_100000_add_self_delivery_to_shipments_table.php`

Table `shipments`:

- **`delivery_mode`**
  - Type: `enum('standard','self_sub')`, `NOT NULL`, `DEFAULT 'standard'`, placed after `status`.
  - FK/index: none (low-cardinality flag; always filtered together with `status`/`courier_id`). No dedicated index.
  - Historical-row behavior: every existing shipment becomes `'standard'` via the column default — correct, since historical shipments used the normal courier/office flow. No data rewrite.
  - Why existing schema is insufficient: decision G requires self-delivery to be a first-class path distinct from normal Kurir; deriving it only from `order_items.stock_source` couples shipment authorization to a join and cannot express shipment-level routing.
  - Rollback limitation: `down()` drops the column; no other table references it, so rollback loses only this flag (an implementation could re-derive historical values as all `'standard'`).

- **`self_delivered_by_user_id`**
  - Type: `foreignId('self_delivered_by_user_id')->nullable()`, FK -> `users(id)` `nullOnDelete`, plus a plain index.
  - Historical-row behavior: `NULL` for every existing shipment.
  - Why existing schema is insufficient: `courier_id` references `couriers` (a Kurir profile) and must stay reserved for normal Kurir (decision A). The self-delivering Sales-Kurir-Sub has no Courier profile, so a stable `users` reference is required to record the actual actor.
  - Rollback limitation: `down()` drops the FK + index + column; the actor record is lost. `nullOnDelete` matches the codebase's actor-reference convention (`User` is soft-deleted, so the FK effectively never fires on normal deletes).

- **Optional CHECK constraint (MySQL/MariaDB only):** `CHECK (delivery_mode = 'standard' OR courier_id IS NULL)` — a self-delivery shipment must never carry a Kurir. Historical rows all pass (`'standard'`). Rollback: drop constraint. (Flagged as a design question in §14.)

### Migration 2 — `2026_10_01_100001_create_delivery_verifications_table.php`

New table `delivery_verifications` (append-only verification history — decision E):

- **`id`** — `bigIncrements` PK.
- **`order_id`** — `foreignId` `NOT NULL`, FK -> `orders(id)` `restrictOnDelete`, index.
  - Historical: n/a (new table). Why: aggregate root + branch authorization scoping.
- **`shipment_id`** — `foreignId` `nullable`, FK -> `shipments(id)` `restrictOnDelete`, index.
  - Historical: n/a. Why: an order can have multiple deliveries; verification is per-delivery, and this references the delivery proof (`shipments.proof_media_id`). Nullable allows order-level verification and keeps legacy shipments referenceable.
- **`outcome`** — `enum('received','not_received','return')` `NOT NULL`.
  - Why: decision E's exact three outcomes.
- **`note`** — `text` `nullable`.
  - Why: follow-up reason / handling detail for `not_received` and `return`.
- **`verified_by`** — `foreignId` `NOT NULL`, FK -> `users(id)` `nullOnDelete`, index.
  - Why: decision E actor requirement; soft-deleted users keep the row.
- **`verified_at`** — `timestamp` `NOT NULL`.
  - Why: decision E timestamp, distinct from `created_at` so true verification time is preserved.
- **`return_request_id`** — `foreignId` `nullable`, FK -> `returns(id)` `nullOnDelete`, index.
  - Why: decision E / spec §3.3 — "return" transitions into the existing return process; links the verification to the actual return for audit.
- **`created_at`, `updated_at`** — `timestamps`.

Composite indexes: `(order_id, id)`, `(order_id, shipment_id)`, `(outcome)`.

- Historical-row behavior: table starts empty; existing delivered orders are simply "not yet verified" (read as absence, never as an outcome). No historical rows touched.
- Append-only semantics: each verification inserts a new row; the latest row per `(order_id, shipment_id)` is current; earlier rows are retained → a later action cannot erase a previous outcome (decision E).
- Rollback limitation: `down()` drops the table; all verification history is lost, but no existing table is affected.

### Migration 3 — `2026_10_01_100002_add_split_lineage_to_order_items_table.php`

Table `order_items`:

- **`split_from_order_item_id`** — `foreignId` `nullable`, self-FK -> `order_items(id)` `nullOnDelete`, index.
  - Historical-row behavior: `NULL` for every existing row (no historical splits recorded).
  - Why existing schema is insufficient: spec §3.5 requires split operations to preserve audit lineage. Today `OrderFulfillmentService::splitItemForReschedule` creates a new item and only `ActivityLog` links it; a self-FK makes lineage queryable and reconcilable for both Agent and Sub splits, and survives log pruning.
  - Rollback limitation: `down()` drops FK + index + column; lineage recorded after deployment is lost.

### Explicitly NOT proposed

- No invoice / delivery-group table or columns (decision D) — grouping is derived from `order_id + OrderItem.requested_delivery_date`.
- No payment-truth columns anywhere (decision D).
- No new columns on `returns` / `return_items` — Sub return restock target is derived from `order_item.sub_location_id` (decision C).
- No fake `Courier` row or `couriers` change (decision A).

## 10. EXACT R-03 implementation file map

**Existing backend files to modify**
- `app/Models/Shipment.php` — casts/fillable for `delivery_mode`, `self_delivered_by_user_id`; `selfDeliveredBy()` relation.
- `app/Models/OrderItem.php` — casts/fillable + `splitFrom()`/`splits()` for `split_from_order_item_id`.
- `app/Models/Order.php` — `deliveryVerifications()` relation.
- `app/Services/Order/OrderFulfillmentService.php` — inventory-safe Sub pre-shipment split + reduction (replace 2 of the 3 Sub 422 guards), lineage, deterministic locks.
- `app/Services/Order/ReturnService.php` — Sub post-shipment return to the Sub domain (replace the Sub return 422 guard); keep the Agent path.
- `app/Services/Order/CourierService.php` — keep `assertMayShipSubStock`; route Sub-sourced shipping to the first-class self-delivery path; keep consume rules.
- `app/Services/Order/OrderService.php` — replace the office bulk-`dikirim` Sub guard with explicit guidance to the self-delivery path.
- `app/Services/Order/InventoryCancellationService.php` — Sub pre-shipment partial withdrawal reconciliation.
- `app/Services/Stock/SubStockService.php` — lock-ordered helpers to divide/shrink/release a reservation for split/reduction.
- `app/Services/Stock/StockRequestService.php` — reconcile the order-generated request on Agent split/reduction; confirm Sub exclusion.
- `app/Policies/ShipmentPolicy.php` — authorize the Sales-Kurir-Sub self-delivery path (own Sub shipment) without a Courier profile; keep the courier path.
- `app/Policies/OrderPolicy.php` — only if Sub adjust/split needs an owner-scoped variant of `manageFulfillment`.
- `app/Http/Controllers/Api/V1/Courier/ShipmentController.php` — split normal Kurir vs self-delivery routing.
- `app/Http/Controllers/Api/V1/Fulfillment/OrderFulfillmentController.php` — expose Sub adjust/split.
- `app/Http/Controllers/Api/V1/Return/ReturnController.php` — Sub return inspect/finalize disposition.
- `app/Http/Controllers/Api/V1/Order/OrderController.php` — eager-load verification/relations for detail.
- `app/Http/Resources/OrderItemResource.php` — split lineage + stock-source/delivery-date fields as needed.
- `app/Http/Resources/OrderResource.php` — delivery groups (Order + `requested_delivery_date`) + verification summary.
- `app/Http/Resources/ReturnRequestResource.php` — Sub return-domain flag if displayed.
- `routes/api_v1.php` — admin verification endpoints; self-delivery endpoint; Sub adjust/split.
- `lang/{id,en,ar,zh}/messages.php` — new messages.

**New backend files**
- `app/Models/DeliveryVerification.php`
- `app/Services/Order/DeliveryVerificationService.php`
- `app/Policies/DeliveryVerificationPolicy.php`
- `app/Http/Controllers/Api/V1/Order/DeliveryVerificationController.php` (admin)
- `app/Http/Controllers/Api/V1/Courier/SelfDeliveryController.php` (Sales-Kurir-Sub self-delivery; may instead extend `ShipmentController` — design question §14)
- `app/Http/Resources/DeliveryVerificationResource.php`
- `app/Http/Requests/Delivery/StoreDeliveryVerificationRequest.php`

**Migrations (new)**
- `backend/database/migrations/2026_10_01_100000_add_self_delivery_to_shipments_table.php`
- `backend/database/migrations/2026_10_01_100001_create_delivery_verifications_table.php`
- `backend/database/migrations/2026_10_01_100002_add_split_lineage_to_order_items_table.php`

**Backend tests (new)**
- `tests/Feature/SalesKurirSubSelfDeliveryTest.php` — self-delivery without Courier profile; ownership/foreign-location rejection; proof; `courier_id` stays NULL.
- `tests/Feature/DeliveryVerificationTest.php` — received / not_received / return; audit actor+timestamp+history; duplicate/idempotent actions.
- `tests/Feature/DeliveryDateGroupingTest.php` — grouping = Order + delivery date; Order-level payment remaining invariant across groups.
- `tests/Feature/SubQuantityAdjustmentTest.php` — pre-shipment split/reduction; physical unchanged; reservation reconciled; post-shipment rejection.
- `tests/Feature/SubReturnDomainTest.php` — Sub return restocks the original Sub Location, never Agent Transit; unsafe-restore rejection.
- `tests/Feature/DeliveryVerificationConcurrencyTest.php` — deterministic duplicate-verification/self-delivery race coverage.

**Backend tests (extend)**
- `OrderFulfillmentTest`, `ReturnSystemTest`, `CourierSystemTest`, `SubStockSourceTest`, `FulfillmentConcurrencyTest`, `SubStockConcurrencyTest`, `MultiTargetInventoryLockOrderTest`.

**Frontend files (modify)**
- `src/api/orders.ts`, `src/api/shipments.ts`, `src/api/returns.ts`, `src/api/orderAdjustments.ts`, `src/api/types.ts`
- `src/views/OrderDetailView.vue` (admin verification + Sub split/adjust/return + delivery-date grouping)
- `src/views/dashboard/KurirDashboardView.vue` (Sales-Kurir-Sub self-delivery path)
- `src/views/dashboard/OrderReportView.vue` (delivery-date grouping)
- `src/views/admin/AdminReturnsView.vue` (Sub return handling)
- `src/router/index.ts`, `src/dashboard/navConfig.ts`
- `src/i18n/locales/{id,en,ar,zh}.ts`

**Frontend files (new)**
- `src/api/deliveries.ts` (admin verification endpoints)
- `src/views/print/ShipmentReceiptView.vue` extension or a self-delivery receipt view (design question §14)

## 11. Implementation sequence (DO NOT EXECUTE)

1. Write the three additive migrations (§9) + model cast/relation updates; run `php artisan migrate` on DEV; run baseline suite to confirm no regression.
2. Implement `DeliveryVerification` (model, service, policy, controller, request, resource, routes) with append-only history (decisions E).
3. Implement Sales-Kurir-Sub self-delivery as a first-class path (decision A/G), preserving `assertMayShipSubStock` and consume rules (decision B).
4. Implement delivery-date grouping by Order + `requested_delivery_date` — derived only, no new table (decision D); expose in `OrderResource` + frontend.
5. Replace Sub 422 guard #1 (adjust) and #2 (split) with inventory-safe, lock-ordered, idempotent pre-shipment logic (decision F); keep lineage (`split_from_order_item_id`).
6. Replace Sub 422 guard #3 (return) with the Sub-domain return path (decision C); keep SubStockRequest return unchanged.
7. Reconcile Sub pre-shipment partial reduction (reservation release/shrink only, physical unchanged).
8. Confirm order-generated Gudang stock request semantics (§3.6) incl. Sub exclusion and Agent-split reconciliation.
9. Frontend surfaces (OrderDetailView, KurirDashboardView, OrderReportView, AdminReturnsView, api modules, router/nav, i18n).
10. Focused R-03 tests incl. deterministic concurrency, then full backend regression + frontend type-check/build.
11. Update this checkpoint with exact commands/results; migration reconciliation criteria.
12. STOP before R-04.

## 12. Progress

- [x] Package A production deployment closed.
- [x] Package B business scope documented.
- [x] AI workflow / compaction / production safety documented in `AGENTS.md`.
- [x] Package B branch baseline recorded.
- [x] RECON ONCE performed; file map + R-03 findings recorded.
- [x] Backend baseline recorded: 738 passed / 5055 assertions / 0 failures.
- [x] Frontend baseline recorded: type-check PASS, build PASS (chunk-size warning only).
- [x] R-03 architecture decisions A–G locked.
- [x] R-03 additive schema/migration plan produced.
- [x] R-03 implementation file map + sequence produced.
- [ ] R-03 implemented.
- [ ] R-03 focused tests pass.
- [ ] R-04 implemented.
- [ ] R-04 focused tests pass.
- [ ] Full backend regression passes.
- [ ] Frontend type-check/build passes.
- [ ] Claude independent final review complete.
- [ ] DEV manual UAT complete.
- [ ] Human Stage Gate approved.

## 13. Open findings

- **ENV-1 — CLOSED:** backend test environment provisioned; baseline 738 passed / 5055 assertions / 0 failures.
- **DESIGN-1 (resolved by plan):** no delivery-verification entity existed → new additive `delivery_verifications` table.
- **DESIGN-2 (resolved by plan):** `sub_stock_reservations` is one full-quantity row per order item → split/reduction reconcile `quantity`, keep UNIQUE per item, deterministic locks.
- **DESIGN-3 (resolved by plan):** Sub customer return restocks the original Sub Location, never Agent Transit.

Known pre-existing technical debt (NOT Package B regressions):

- Package A role migration `down()` is not a perfect inverse after the rare dual-row fold path.
- Checkout's global unique temporary `orders.order_no='TEMP'` behavior (Package A concurrency review); do not change opportunistically.
- CLI duplicate OPcache/mbstring warnings are environmental and non-blocking.
- Historical production "MAC is invalid" log entries pre-date Package B.

## 14. Unresolved R-03 design questions

1. `shipments.delivery_mode` vs deriving purely from `stock_source=sub` + `self_delivered_by_user_id` — proposed to keep the explicit flag; confirm.
2. `order_items.split_from_order_item_id` vs ActivityLog-only lineage — proposed to add the self-FK for queryable audit; confirm.
3. `delivery_verifications` granularity — proposed per-shipment (nullable `shipment_id`); confirm whether per-item verification is ever required.
4. Self-delivery endpoint shape — dedicated `SelfDeliveryController` vs a method on `ShipmentController`.
5. Does a Sales-Kurir-Sub ever self-deliver Agent-sourced items? Locked rule says Sub-sourced only — confirm Agent path unchanged.
6. Agent-sourced split's effect on an existing order-generated `StockRequest` (stale request items) — in-scope fix or documented technical debt?

## Production state

Production is LIVE after Package A. Package B development must not mutate production. No Package B production deployment/migration is authorized until after implementation, review, DEV/UAT, and Human Stage Gate.

## EXACT NEXT ACTION

**Do NOT implement R-03 and do NOT create migrations yet.** Await explicit human authorization of this locked plan (and answers to §14 design questions).

When authorized, the exact first action is:

1. Create the three additive migrations from §9 and the accompanying model cast/relation updates.
2. Run `php artisan migrate` on DEV only, then `php artisan test` to confirm the 738/5055 baseline still holds before any behavioral change.
