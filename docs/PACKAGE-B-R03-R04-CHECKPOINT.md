# Package B (R-03 + R-04) — Checkpoint

**Status:** R-03 AUTHORIZED — IMPLEMENTATION IN PROGRESS. R-04 **NOT** authorized.
**Created:** 2026-10-01
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

### Push status (BLOCKER, environment)

`git push origin feat/package-b-r03-r04` fails: the configured deploy key `~/.ssh/github_primeclassy` is passphrase-encrypted (OpenSSH bcrypt/aes256-ctr), there is no ssh-agent (`SSH_AUTH_SOCK` unset) and no TTY for the passphrase prompt, and no credential helper/token is configured. Push must be performed by a human or after the key is added to an agent. Local commits are unaffected.

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

## Progress

- [x] Package A closed; Package B scope documented.
- [x] RECON ONCE + baselines recorded.
- [x] Final R-03 architecture locked (A–H).
- [ ] M1–M3 migrations + model changes.
- [ ] Focused migration/model tests.
- [ ] Self-sub shipment behavior.
- [ ] Append-only delivery verification.
- [ ] Derived delivery grouping.
- [ ] Sub reduction/increase/split reconciliation.
- [ ] StockRequest split reconciliation.
- [ ] Sub customer-return restock.
- [ ] Frontend R-03 surfaces.
- [ ] Focused backend tests pass.
- [ ] Full backend regression passes.
- [ ] Frontend type-check/build passes.
- [ ] Checkpoint updated with results.

## Open findings

- **PUSH-1 (blocker, environment):** cannot push — encrypted deploy key with no passphrase/agent/TTY. Needs human action.
- **DESIGN-1/2/3:** resolved by the locked A–H architecture.

Pre-existing technical debt (NOT Package B regressions): Package A role migration `down()` imperfect inverse; checkout global unique TEMP `order_no`; CLI duplicate OPcache/mbstring warnings; historical "MAC is invalid" log entries.

## Production state

Production is LIVE after Package A. Package B development must not mutate production. No Package B production deployment/migration is authorized until after implementation, review, DEV/UAT, and Human Stage Gate.

## EXACT NEXT ACTION

R-03 implementation is authorized and proceeding. Next concrete step: create the three additive migrations (M1–M3) and the accompanying model cast/relation updates, then run them on the isolated test/DEV database only and confirm the 738/5055 baseline still holds.
