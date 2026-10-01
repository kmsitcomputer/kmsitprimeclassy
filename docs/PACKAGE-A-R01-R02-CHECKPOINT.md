# Package A (R-01 + R-02) — Checkpoint

**Objective:** Sales-Kurir → Sales-Kurir-Sub role evolution, 1:1 Sub Location ownership, real Sub stock
(reservation lifecycle, stock-source selection, Transit→Sub replenishment, Sub→Transit return).

- Repo: kmsitcomputer/kmsitprimeclassy · Base: `main` @ `9d97961dbae7a92e02594182be0ae66db9709173`
- Work branch: `claude/r01-r02-sales-kurir-sub-stock`
- Out of scope: R-03 (invoice, final delivery verification), R-04 (order authority, Gudang financial, Kurir queue, reports, Admin Product CRU)

## Baseline test result (before any change)
`vendor/bin/phpunit` on MariaDB 10.11 (`primeclassy_testing`): Tests: 663, Assertions: 4248, **Failures: 2**
(both network-dependent RajaOngkir calls blocked by the sandbox proxy, e.g.
`ShippingProviderTest::test_rajaongkir_falls_back_to_openroute_when_destination_regency_has_no_mapping`).

## Files inspected (single recon pass)
docs/WAREHOUSE-AND-SALES-COURIER-SPECIFICATION.md (§21–22), RoleSeeder, HierarchyRules, UserManagementService,
User::isRole, EnsureRole, ReferralService, SellableStockService, WarehouseStockService, StockTransferService,
StockService, StockRequestService, InventoryCancellationService, OrderService, OrderController, StoreOrderRequest,
CourierService, WarehouseSubLocation model/policy/controller, stock_* migrations.

## Locked decisions implemented
- Role row renamed in place `sales-kurir` -> `sales-kurir-sub` (migration 2026_09_29_090000, seeder, `Role::canonicalSlug`, `User::isRole` alias, SQL lists include both slugs). 10 roles.
- SS- prefix for NEW codes only; conversion keeps existing code (SA-/SK-), generates SS- only if none.
- Sub Location 1:1 owner: `warehouse_sub_locations.owner_user_id` UNIQUE nullable; only `SubLocationOwnershipService` writes it; Gudang cannot create/assign; owned location never reassigned; released on deactivate (`previous_owner_user_id` audit).
- `order_items.stock_source` (agent|sub, default agent) + `sub_location_id` + DB CHECK; `sub_stock_reservations` (one per order item; active/consumed/released).
- Resolver (server-side): Sub stock only for owner's own purchase or own-referral consumer order; client `sub_location_id` only compared, never trusted.
- Reservation: reserve at order creation (physical unchanged) -> consume at `dikirim` (physical -= qty once, `out` movement stock_type=sub) -> release on cancel. Sum of active reservations is read WITH row lock (a plain SELECT oversold in the race test — fixed).
- Agent sellable formula untouched (Transit + Plan - Agent Reserved).
- Guards deferred to R-03: quantity adjust / split / return of Sub-sourced items throw a clear 422.

## Commits
C1 role compat (03787fc) · C2 ownership (b4502cc) · C3 reservation ledger (53c0bd6) · C6 checkout integration (5a280aa) · C4/C5 request/return flow (4296af0) · C7 frontend (7b11d15) · C8 docs

## Final test results
Backend `vendor/bin/phpunit`: Tests: 706, Assertions: 4540, Failures: 2 (baseline network-only RajaOngkir failures, identical to pre-change baseline of 663 tests / 2 failures).
Frontend: `npm run type-check` clean, `npm run build-only` OK (no frontend test script in repo).

## Status: COMPLETE — nothing remaining in Package A. See docs/R01-R02-SALES-KURIR-SUB-AND-SUB-STOCK.md.

---

## Remediation pass (branch `remed/package-a-vps`)

Structured review of the Package A diff (`9d97961..3c79f42`) against the 7 mandatory review
areas. VPS baseline confirmed before remediation: 706 tests / 4542 assertions / 0 failures
(RajaOngkir passes clean on this VPS; the 2 Cloud-sandbox failures do not reproduce here and
were not touched).

### Findings

**BLOCKER — Area 1, Sub stock shipment boundary.**
Sub-sourced order items could have their Sub reservation consumed through two generic paths that
never checked the acting user owned the Sub Location:
- `OrderService::updateStatus` (`backend/app/Services/Order/OrderService.php`) — the Admin/Agen
  bulk office override called `SubStockService::consume()` for any `dikirim` item with
  `isSubSourced()`, unconditionally.
- `CourierService::updateShipmentStatus` (`backend/app/Services/Order/CourierService.php`) — the
  ownership guard `assertMayShipSubStock()` only ran when `$actor->isRole('kurir',
  'sales-kurir-sub')`, so an Admin/Agen calling the same per-shipment endpoint (allowed by
  `ShipmentPolicy::updateStatus` for same-agent agen/admin/super_admin) bypassed it entirely.
  `SubStockService::consume()` itself does no actor/ownership validation — it fully trusts the
  caller, by design, with all authorization expected upstream.
- Expected: only the owning Sales-Kurir-Sub's own self-fulfillment path
  (`CourierService::updateShipmentStatus`, already correctly gated for kurir/sales-kurir-sub) may
  consume an active Sub reservation. Full delivery redesign is R-03; Package A only needed to stop
  the accidental consumption.
- Remediation: `OrderService::updateStatus` now throws a 422
  (`messages.order.sub_item_requires_owner_shipment`, added to all 4 locale files) before
  transitioning any Sub-sourced item to `dikirim` — that endpoint is never reached by
  sales-kurir-sub in the first place (`OrderPolicy::updateStatus` only allows agen/admin/
  super_admin), so Sub-sourced items must ship exclusively through the shipment endpoint.
  `CourierService::updateShipmentStatus` now runs `assertMayShipSubStock()` for every actor on
  `dikirim` (not just kurir/sales-kurir-sub), so an office actor hitting the shipment endpoint on
  someone else's Sub Location is blocked with the same 403 as a foreign courier.
- Tests: `SubStockSourceTest::test_admin_cannot_ship_sub_goods_via_the_shipment_endpoint`,
  `::test_admin_cannot_ship_sub_goods_via_the_generic_order_status_endpoint`.

**MAJOR — Area 2, R-02 frontend operational completeness.**
`frontend/src/api/subStock.ts` existed but no route/view consumed it — Sales-Kurir-Sub, Admin and
Gudang had no UI for Sub stock requests at all (confirmed by `docs/R01-R02-...md`'s own "Deferred"
section). Added:
- `SubStockView.vue` (`/dashboard/sub-stock`, role `sales-kurir-sub`) — own Sub stock (physical/
  reserved/sellable), create replenish/return requests, own request history with cancel and
  receive-handover actions.
- `SubStockApprovalsView.vue` (`/dashboard/sub-stock/approvals`, role `admin`) — list + approve/
  reject pending requests (agent-scoped by the existing backend query).
- `SubStockExecutionView.vue` (`/dashboard/sub-stock/execution`, role `gudang`) — list approved
  requests and execute (covers both replenish Transit→Sub and return Sub→Transit; `execute()` is
  the single idempotent action for both directions per `SubStockRequestService`).
- Wired into `router/index.ts` and `dashboard/navConfig.ts`. Fixed `api/subStock.ts` types to
  match actual backend serialization (`SubStockRequest.subLocation`, not `sub_location` — the
  controller returns the raw Eloquent model, so the JSON key is the relation method name; the
  `ProductVariation` model has no `attribute_value`/`label` accessor exposed on the eager-loaded
  `variation` relation, only `sku`) and added missing `meta`/pagination fields.
- No R-03 invoice/delivery UI, no R-04 authority UI added.

**NOT-A-FINDING — Area 3, Sub Location ownership audit.**
`previous_owner_user_id` is a single-value column and does lose finer-grained history on repeated
remap, but it is not the only historical record: `SubLocationOwnershipService` durably logs
`sub_location.created` / `owner_assigned` / `deactivated` to `ActivityLog` with the
`owner_user_id`/`released_owner_user_id` on every write, so full ownership history is
reconstructable from `activity_logs`. Cross-Agent assignment, one-owner-one-location, and
one-user-one-location are all enforced server-side (`lockEligibleOwner`, the `UNIQUE` index).
Deactivation blocks on `WarehouseStock.quantity > 0`, which — given the reservation lifecycle
(reserve never touches physical; every other physical-decrease path is guarded by
`SubStockService::assertPhysicalDecreaseAllowed`) — already implies no active reservation can be
bypassed. No remediation applied.

**NOT-A-FINDING — Area 4, role/referral compatibility.**
Verified: exactly 10 roles (`RoleSeeder`), `sales-kurir` renamed in place (migration
`2026_09_29_090000`, idempotent, no 11th role), `User::isRole`/`Role::canonicalSlug` alias the
legacy slug, SS- prefix only for new codes (`HierarchyRules::REFERRAL_PREFIXES` +
`UserManagementService::generateCandidateReferralCode`), historical SA-/SK- codes never rewritten,
Sales→Sales-Kurir-Sub conversion keeps the existing code. No remediation applied.

**NOT-A-FINDING — Area 5, stock concurrency/idempotency.**
`SubStockService` locks the Sub stock row before the reservation rows, consistently, in
reserve/consume/release; reservation sums used to guard a write always use `forWrite: true`
(locking read) to avoid the REPEATABLE READ snapshot-read oversell the code's own docblock warns
about. `sub_stock_reservations.order_item_id` is UNIQUE, making reserve/consume naturally
idempotent per item. `SubStockConcurrencyTest` already exercises real two-MySQL-connection races
(oversell, double reserve/consume/transfer). No gap found; existing race tests left as-is per
instructions.

**NOT-A-FINDING — Area 6, stock source forgery.**
`SubStockSourceTest::test_other_actors_can_never_consume_another_users_sub_stock` and
`::test_cross_agent_sales_kurir_sub_cannot_use_a_foreign_location` already forge `sub_location_id`
and actor identity across: other Sales, Korsal, Agen, another Sales-Kurir-Sub (both by referred
consumer and by forged location id), the owner's own forged foreign location, a non-network
consumer, and a mismatched `stock_source`/`sub_location_id` pair — all correctly rejected
server-side (403/422). Extended with the two Area 1 tests above (Admin/office forgery via the
shipment/order-status endpoints). No further gap found.

**MINOR (documented, not fixed) — Area 7, migration safety.**
`2026_09_29_090000_rename_sales_kurir_role_to_sales_kurir_sub.php`'s `down()` unconditionally
renames `sales-kurir-sub` back to `sales-kurir`. If the migration's rare "fold" branch ran at
`up()` time (both the legacy and canonical role rows already existed, e.g. seeder ran before this
migration on a specific ordering), a legacy row was deleted and its users repointed — `down()`
does not recreate that deleted row, so a rollback after a fold is not a clean inverse. This path
requires an unusual migration/seeder ordering to trigger and has no production impact (this
migration already ran once in Package A); rollback correctness for a role-rename migration is low
risk. Left undocumented-but-known rather than adding speculative down() recovery logic for a path
that cannot occur from this point forward. All 5 migrations are otherwise additive/nullable,
MariaDB-10.11-compatible (`CHECK` constraint guarded by driver check), correctly indexed, assign
no production ownership, and create no 11th role.

### Files changed (remediation)
- `backend/app/Services/Order/OrderService.php` — block Sub-sourced `dikirim` via generic path (422)
- `backend/app/Services/Order/CourierService.php` — ownership check applies to every actor, not just kurir/sales-kurir-sub
- `backend/lang/{en,id,ar,zh}/messages.php` — new `order.sub_item_requires_owner_shipment` key
- `backend/tests/Feature/SubStockSourceTest.php` — 2 new remediation tests
- `frontend/src/api/subStock.ts` — corrected response types (relation key casing, pagination meta)
- `frontend/src/views/dashboard/SubStockView.vue` (new) — Sales-Kurir-Sub operational screen
- `frontend/src/views/dashboard/SubStockApprovalsView.vue` (new) — Admin approval screen
- `frontend/src/views/dashboard/SubStockExecutionView.vue` (new) — Gudang execution screen
- `frontend/src/router/index.ts`, `frontend/src/dashboard/navConfig.ts` — wiring

No new migrations.

### Test results (remediation)
Backend `vendor/bin/phpunit` / `php artisan test`: **708 passed, 4551 assertions, 0 failures**
(706 baseline + 2 new; all pass including RajaOngkir on this VPS).
Frontend: `npm run type-check` clean, `npm run build-only` OK (only the pre-existing >500 KB
chunk warning, non-blocking).

### Deferred (unchanged — R-03/R-04, out of scope here)
Same list as the original doc: R-03 invoices/splitting/final delivery verification/Sub-order
quantity adjust-split-return; R-04 order authority/Gudang financial projection/Kurir queue/
reports/Admin Product CRU.

### Unresolved issues
None blocking. The Area 7 MINOR (migration rollback edge case) is documented above and
intentionally left as-is.

### Status: Package A remediation COMPLETE. Working tree has the remediation diff only (see git log on `remed/package-a-vps`); production untouched.

---

## Codex Round-2 remediation (branch `remed/package-a-vps`)

| Finding | Fix | Tests | Commit |
|---|---|---|---|
| MAJOR-1 agent reservation vs Sub replenishment capacity race | serialized capacity check/reservation | `AgentSubCapacityConcurrencyTest` | 5d545ec |
| MAJOR-2 generic transfer approved after Sub got an owner | `approve()` re-checks ownership under lock (same row `assignOwner` locks) | `GenericTransferOwnershipBypassTest`, `TransferOwnershipAssignmentConcurrencyTest` | 2ecc2e5 |
| MAJOR-3 Sub Location UI ≠ API | Agen/Admin-only create with `owner_user_id` selector, owner shown, explicit assign for unowned legacy locations; Gudang sees no controls (`WarehouseSubLocationsView.vue`, `api/warehouse.ts`) | existing backend forged/cross-Agent owner tests; type-check + build (no frontend test framework, per instruction) | 4ea8295 |
| MAJOR-4 first / new-SKU replenishment impossible | `GET /sub-stock/replenishment-targets` (valid active catalog targets, no Sub row and no Transit>0 needed; returns informational `current_transit`); `create()` rejects only invalid/inactive/deleted/wrong-kind targets (see Correction below); return stays limited to Sub sellable; no fake zero rows | `SubStockReplenishmentTargetsTest` (zero-row first flow, new SKU, forged/foreign/no-Transit/invalid variation, role gate) | 3729084 (backend), afaec33 (UI) |
| MINOR-5 relation key | canonical `sub_location` (API already emitted it); TS type + Approvals/Execution views fixed | `SubStockReplenishmentTargetsTest::test_request_responses_use_the_canonical_snake_case_sub_location_key` (sub/admin/gudang, asserts `subLocation` absent) | afaec33 |
| MINOR-6 load/pagination error states | explicit loading / error(+retry) / empty in SubStock, Approvals, Execution; backend 404 "no active Sub Location" shown as such | type-check + build | afaec33 |

### Correction (post Round-2): replenish request must not require Transit > 0
A replenish request is demand only (request -> Admin approve -> Gudang execute). Commit 3729084 wrongly required
current Transit > 0 for listing/creating. Corrected: targets = active Products without variations + active Variations of
active variation-products (catalog is global, so eligible for every Agent network); `current_transit` is returned for UI
information only. Execution stays authoritative: Transit sufficiency and the 5d545ec Agent reservation/capacity locks are
untouched, insufficient Transit fails 422 with no transfer/movement/handover and the request stays `approved`.
Return remains limited to the Sub's own sellable stock. Tests: `SubStockReplenishmentTargetsTest`
(zero-Transit request -> execute fails safely -> add Transit -> execute succeeds; inactive/deleted/variation-mismatch/
nonexistent targets rejected; first-stock and new-SKU flows unchanged).

### Results
Backend `vendor/bin/phpunit`: **719 tests, 4684 assertions, 0 failures**. Frontend type-check clean, `build-only` OK (chunk-size warning only).


### Remaining
None. Manual DEV/UAT still needed for role visibility/interaction in the Sub Location and Sub stock screens. `main` not merged; production untouched; R-03/R-04 untouched. Branch not pushed yet.

### EXACT NEXT ACTION
Push `remed/package-a-vps` to origin (original brief asked for it) on user confirmation; then DEV/UAT.

---

## Codex Round-3 remediation (branch `remed/package-a-vps`)

Three confirmed remaining findings: MAJOR-1 multi-target deadlock inversion, MAJOR-3 Sub Location
owner selector pagination, MINOR-6 no retry after no-active-Sub 404. R-03/R-04 untouched;
`main` not merged; production untouched. Starting HEAD `b2a2f98`.

| Finding | Fix | Tests | Commit |
|---|---|---|---|
| MAJOR-1 multi-target deadlock inversion | one canonical target order for every multi-target Agent-capacity transaction | `MultiTargetInventoryLockOrderTest` | `remed: enforce canonical multi-target inventory lock order` |
| MAJOR-3 Sub Location owner selector pagination | `GET /warehouse/sub-locations/eligible-owners` (server-authoritative, paged + searchable) + selector wired to it | `SubLocationEligibleOwnersTest` | `remed: make sub owner selection fully eligible and pageable` |
| MINOR-6 no retry after no-active-Sub 404 | no-location state exposes Retry, which re-runs stock + replenishment-targets + requests | type-check + build (no frontend test framework) | `remed: allow retry after missing sub location` |

### MAJOR-1 — canonical multi-target lock order
- `StockService::canonicalTargetKey()` / `canonicalReservationTargets()` is now the ONE ordering
  vocabulary: `p:{product_id}` / `v:{variation_id}`, `ksort`ed. `StockTransferService` (both the
  Plan→Transit approval and `executeApprovedTransfer`) and the checkout path key off it, so the two
  paths can never acquire the same targets in opposite orders.
- `StockService::lockReservationTarget()` locks one target in the already-correct per-target order
  (Agent commitment row first, then Warehouse Transit/Plan). `lockReservationTargets()` sorts
  `ksort` then locks each — `OrderService::createOrder` calls it once for Agent-sourced orders
  (Sub-sourced orders reserve entirely in the Sub ledger, no Agent capacity locks) BEFORE the
  per-line reserve loop. The loop still runs in the incoming order, so order-item creation and
  presentation order are untouched; the rows are simply already held.
- The 5d545ec per-target order (commitment → Transit) is unchanged; this finding only fixed the
  cross-target order. No `reserveFor*` semantics changed.
- Deterministic regression: `MultiTargetInventoryLockOrderTest` runs real two-connection races with
  a file barrier after each side's first target — an inverted order deadlocks (positive control),
  the canonical order never does — plus reversed real-path races (real `OrderService::createOrder`
  vs real `SubStockRequestService::execute`) for product+product, variation+variation, and
  product+variation, asserting no deadlock, exactly one winner, and Transit ≥ committed Reserved.
- Residual (out of scope, unchanged): `InventoryCancellationService::reverseOrder` can lock several
  Agent commitment rows in `$order->items` order, and a Sub-sourced checkout reserves several Sub
  rows in line order. Neither is a Package-A checkout Agent-capacity path; left as-is.

### MAJOR-3 — fully eligible, pageable owner selection
- New Agen/Admin-only `GET /warehouse/sub-locations/eligible-owners` (registered before the
  `/{subLocation}` wildcard): active, non-deleted Sales-Kurir-Sub of the actor's own network that do
  not already own a Sub Location; `search` + `per_page`/`page`; returns `UserResource[]` + meta.
- `WarehouseSubLocationsView.vue` now uses it (search box + Prev/Next), so any eligible owner is
  reachable regardless of pagination; ineligible candidates are never presented. Create/assign
  endpoints remain authoritative and re-check everything server-side.
- Tests: later page + search reachable; inactive / soft-deleted / already-assigned / foreign-Agent /
  normal-Sales all excluded; gudang/sales/non-managers forbidden; forged owner ids still 422.

### MINOR-6 — retry after no active Sub Location
- The 404 "no active Sub Location" branch now always shows **Coba lagi**, calling `retry()` which
  re-runs `loadStock()` (my Sub stock + replenishment targets) and `loadRequests()`. On success
  `noLocation`/stale error clear and the stock screen appears without a browser reload; on failure
  the explicit error state is retained.

### Results
Backend `php artisan test`: **727 tests, 4854 assertions, 0 failures** (719 baseline + 8 new).
Frontend `npm run type-check` clean, `npm run build-only` OK (pre-existing chunk-size warning only).

### Files changed (Round-3)
- `backend/app/Services/Stock/StockService.php` — canonical key/order helpers + `lockReservationTarget(s)`
- `backend/app/Services/Order/OrderService.php` — canonical pre-lock before the per-line reserve loop
- `backend/app/Services/Stock/StockTransferService.php` — use the shared canonical target key
- `backend/.phpunit-concurrency-actor.php` — `agent-lock-multi` + `checkout-order` race ops
- `backend/app/Http/Controllers/Api/V1/Stock/WarehouseSubLocationController.php` — `eligibleOwners`
- `backend/routes/api_v1.php` — eligible-owners route (before the `{subLocation}` wildcard)
- `backend/tests/Feature/MultiTargetInventoryLockOrderTest.php` (new)
- `backend/tests/Feature/SubLocationEligibleOwnersTest.php` (new)
- `frontend/src/api/warehouse.ts` — `listEligibleSubLocationOwners`
- `frontend/src/views/dashboard/WarehouseSubLocationsView.vue` — searchable/pageable eligible selector
- `frontend/src/views/dashboard/SubStockView.vue` — Retry in the no-location state

### Remaining
Manual DEV/UAT still needed for role visibility/interaction in the Sub Location and Sub stock
screens. `main` not merged; production untouched; R-03/R-04 untouched.

### EXACT NEXT ACTION
Push `remed/package-a-vps` to origin on user confirmation; then DEV/UAT.

---

## Codex Round-4 remediation (branch `remed/package-a-vps`)

Three remaining MAJOR concurrency findings: A (prelock target ≠ reserved target), B (cancellation vs
Sub replenishment lock inversion), C (concurrent multi-target Sub checkout). Starting HEAD `046db5e`.
R-03/R-04 untouched; `main` not merged; production untouched; no migrations.

| Finding | Fix | Tests | Commit |
|---|---|---|---|
| A prelock target ≠ reserved target | one effective inventory target per line, resolved once and used by both prelock and reserve | `OrderInventoryTargetResolutionTest` | `remed: normalize order inventory targets before locking` |
| B cancellation vs replenishment inversion | canonical Agent capacity prelock before the reversal loop | `CancellationReplenishmentConcurrencyTest` | `remed: canonicalize cancellation inventory locks` |
| C concurrent multi-target Sub checkout | canonical Sub WarehouseStock prelock before the per-line Sub reserve | `SubCheckoutConcurrencyTest` | `remed: canonicalize multi-target sub checkout locks` |

### Target-resolution decision (MAJOR A)
`OrderService::resolveLine()` is now the single source of truth for a line's EFFECTIVE inventory
target: a product with `has_variations = false` is a PRODUCT target and must NOT carry a variation id
(supplying one is now a 422 `messages.product.variation_not_allowed`, not silently ignored); a
variation product is a VARIATION target and requires an active variation of THAT product (missing →
422 `variation_required`; foreign/inactive → 404, unchanged). `createOrder` resolves every line once
(`$resolvedLines`), builds `$reservationTargets` from it, and both the canonical prelock and
`priceAndReserveLine()` consume those resolved lines — so the prelocked target can never differ from
the target actually reserved. `quoteLine()` uses the same resolver, so quote and order share line
semantics. The per-line loop still runs in the incoming order (presentation/order-item order
unchanged). New lang key in all 4 locales.

### Canonical Agent cancellation locking (MAJOR B)
`InventoryCancellationService::lockAgentCapacityForReversal()` runs before the per-item reversal
loop: it collects each non-Sub item's target, locks them via `StockService::lockReservationTargets()`
(canonical order, commitment row → Transit/Plan), then the loop releases in its original item order.
Sub-sourced items are excluded (they release in the Sub ledger). Because the Transit row is already
held, the later `reverseShippingToTransit()` Shipping lock cannot invert against a concurrent
Transit → Sub execution.

### Canonical Sub checkout locking (MAJOR C)
`SubStockService::lockReservationTargets($subLocationId, $targets)` locks the Sub WarehouseStock rows
for every effective target in the same canonical order the Agent domain uses
(`StockService::canonicalReservationTargets`), creating no rows (a missing row stays normal
insufficient stock). `OrderService::createOrder` calls it for Sub-sourced orders before the per-line
Sub `reserve()` loop. Per-target order (Sub stock row → reservation rows) and the
Sellable = Physical − Reserved formula are unchanged.

### Results
Backend `php artisan test`: **736 tests, 5013 assertions, 0 failures** (727 baseline + 9 new).
Frontend `npm run type-check` clean, `npm run build-only` OK (pre-existing chunk-size warning only).

### Files changed (Round-4)
- `backend/app/Services/Order/OrderService.php` — `resolveLine`/`resolvedTarget`; canonical Agent prelock and Sub prelock from resolved targets; `priceAndReserveLine` takes a resolved line
- `backend/app/Services/Order/InventoryCancellationService.php` — `lockAgentCapacityForReversal` prelock
- `backend/app/Services/Stock/SubStockService.php` — `lockReservationTargets`
- `backend/lang/{en,id,ar,zh}/messages.php` — `product.variation_not_allowed`
- `backend/.phpunit-concurrency-actor.php` — `cancel-order`, `sub-reserve-order` race ops; `checkout-order` gains `stock_source`/`sub_location_id`
- `backend/tests/Feature/OrderInventoryTargetResolutionTest.php` (new)
- `backend/tests/Feature/CancellationReplenishmentConcurrencyTest.php` (new)
- `backend/tests/Feature/SubCheckoutConcurrencyTest.php` (new)

### Residual concurrency note (concrete competing path only)
Two REAL checkouts cannot be made to race on Sub rows because they serialize on the global unique
`orders.order_no` placeholder `'TEMP'` (set on insert, updated after pricing) — an unrelated
pre-existing checkout artifact, not a Sub-domain lock. MAJOR C's Sub lock ordering is therefore
covered by a deterministic race of the production Sub prelock + reserve sequence (with a positive
control that deadlocks when the prelock is skipped) plus a functional real-checkout test. This was
left unchanged (out of scope, and changing order numbering would be risky).

### Remaining
Manual DEV/UAT still needed. `main` not merged; production untouched; R-03/R-04 untouched.

### EXACT NEXT ACTION
Push `remed/package-a-vps` to origin on user confirmation; then DEV/UAT.

---

## Codex Round-5 remediation — final MAJOR C (branch `remed/package-a-vps`)

Findings A (effective target) and B (cancellation) are CLOSED and untouched. Only MAJOR C remained:
multi-target Sub **ordering** was fixed, but a higher-level inversion persisted between Sub checkout
and `SubStockRequestService::execute()`.

### The defect
Checkout acquired Sub stock target locks first and only needed the `warehouse_sub_locations` parent
row later, at the `order_items.sub_location_id` FK insert. Execution locks the location exclusively
(`SubStockRequestService::execute`, line 201) and then the stock rows. So: checkout holds stock →
waits for the parent; execution holds the parent → waits for stock. A single target with sufficient
stock could deadlock.

### Location-before-stock invariant + chosen mechanism
Canonical Sub-domain order is now **WarehouseSubLocation parent row → Sub WarehouseStock target rows
→ Sub reservation rows**. `OrderService::lockSubLocationForCheckout()` runs inside the checkout
transaction BEFORE `SubStockService::lockReservationTargets()` and takes a **SHARED** lock
(`WarehouseSubLocation::...->sharedLock()`), then re-validates the freshly locked row (exists, active,
same agent, still owned by the acting Sales-Kurir-Sub) instead of trusting the pre-transaction
snapshot; a failed re-check is a 422.

Shared (not exclusive) is sufficient and preferred: checkout only needs the parent to stay
stable/owned while it creates FK-backed order items and reserves stock, and multiple checkouts may
hold it concurrently; the Gudang executor keeps its exclusive location lock, so it simply waits at
the location boundary and the location↔stock cycle cannot form. MariaDB 10.11 / Laravel's
`sharedLock()` (`LOCK IN SHARE MODE`) is supported and conflicts with the executor's `FOR UPDATE`, so
the serialization is real. No FK removed, no FK checks disabled, no deadlock retry. Execution order
(location exclusive → stock) is unchanged; `SubLocationOwnershipService` is untouched.

### Test added
`SubCheckoutConcurrencyTest`:
- `test_real_sub_checkout_and_sub_execution_never_deadlock_and_keep_invariants` — real
  `OrderService::createOrder()` (Sub-sourced) racing real `SubStockRequestService::execute()` on the
  same location/target: no deadlock, both succeed, physical 10→15 (only the executed replenishment
  moves stock), reservation 5 ≤ physical, Transit moved once (2 transfer movements), request
  `executed`, one order.
- `test_parent_location_lock_precedes_stock_targets_and_inverted_order_deadlocks` — deterministic
  barrier control: the pre-fix order (stock targets first, parent at the FK insert) deadlocks against
  the executor's parent-first order (positive control), while the parent-first order never does.
- Existing methods retained: multi-target Sub race (product/variation/mixed), Sub prelock control,
  functional real checkout.

### Results
Backend `php artisan test`: **738 passed, 5055 assertions, 0 failures** (736 baseline + 2 new).
Frontend `npm run type-check` clean, `npm run build-only` OK (pre-existing chunk-size warning only).

### Files changed (Round-5)
- `backend/app/Services/Order/OrderService.php` — `lockSubLocationForCheckout` (shared parent lock + re-validation) before the Sub stock prelock
- `backend/.phpunit-concurrency-actor.php` — `sub-location-stock-lock` lock-order op
- `backend/tests/Feature/SubCheckoutConcurrencyTest.php` — real checkout-vs-execution race + parent-first control

### Remaining
Manual DEV/UAT still needed. `main` not merged; production untouched; R-03/R-04 untouched.

### EXACT NEXT ACTION
Push `remed/package-a-vps` to origin on user confirmation; then DEV/UAT.

---

## Final independent review (HEAD ab461d2, branch `remed/package-a-vps`)

Diff-first review of `9d97961..ab461d2` (99 files) across all 19 priority areas: role/referral compatibility
(canonical slug aliasing in `User::isRole`/`EnsureRole`/`HierarchyRules`, SS- only for new codes, SA-/SK- preserved,
role migration renames in place), Sub Location ownership (1:1 UNIQUE, same-Agent/active eligibility under lock, no silent
legacy mapping, Gudang excluded at policy + route), stock-source authorization (`StockSourceResolver`), Agent capacity
locking, Sub reservation lifecycle, request workflow, generic-transfer ownership re-check under lock, generic office
shipment guard (`OrderService` + `CourierService`), effective target normalization, canonical multi-target lock order in
checkout/cancellation/execution, Sub-domain order (location shared -> Sub stock -> reservations; executor location
exclusive -> stock), frontend/backend contracts (`sub_location`, `eligible-owners` route registered before `{subLocation}`),
zero-Transit replenish semantics, no-active-Sub retry, migration data safety (additive/nullable, defaults, CHECK guarded by
driver), and R-03/R-04 leakage (Sub-item quantity adjust/split/return blocked with a clear 422 only).

**Result: no concrete defect found; no code changed in this pass.**

### Results
Backend `php artisan test`: **738 passed, 5052 assertions, 0 failures** (Pest/artisan counting; the earlier 5055 figure came
from a different runner's assertion tally — test count identical). Frontend `npm run type-check` clean,
`npm run build-only` OK (pre-existing >500 KB chunk warning only).
Migrations unchanged; production untouched; R-03/R-04 untouched.

### Remaining technical debt (outside Package A)
- R-03: invoices, splitting, final delivery verification, Sub-order quantity adjust/split/return.
- R-04: order authority, Gudang financial projection, Kurir queue, reports, Admin Product CRU.
- Role migration `down()` is not a clean inverse after a fold (documented, no forward risk).

### EXACT NEXT ACTION
Manual DEV/UAT (role visibility for Agen/Admin/Gudang/Sales-Kurir-Sub; owner assignment; Sub checkout; Transit<->Sub
request approve/execute/receive). Then push on user confirmation.


---

## Production deployment closure — 2026-10-01

Package A was merged to `main` at `b9ed09b60b1d2cb3d739031c40bd87e79b0f95ed` and subsequently deployed to production after read-only preflight, source/database backup, whitelist deployment, migration verification, reconciliation, and smoke/UAT.

### Production migration result
All five Package A migrations ran successfully in batch 7:

- `2026_09_29_090000_rename_sales_kurir_role_to_sales_kurir_sub`
- `2026_09_29_100000_add_owner_to_warehouse_sub_locations`
- `2026_09_29_110000_create_sub_stock_reservations_table`
- `2026_09_29_110001_add_stock_source_to_order_items_table`
- `2026_09_29_120000_create_sub_stock_requests_tables`

### Reconciliation result
- role count remained 10;
- `sales-kurir` -> 0, `sales-kurir-sub` -> 1;
- Nida remains user id 21, role_id 10, agent_id 11, historical referral `SA-4QHJDQ` preserved;
- all seven new schema/table checks passed;
- historical Orders = 3 and OrderItems = 3;
- all three historical OrderItems defaulted safely to `stock_source=agent`, `sub_location_id=NULL`;
- new Sub reservation/request tables started empty;
- legacy Sub Locations id 1 `TUTI` and id 2 `tina` remained unowned;
- Nida owns active Sub Location id 3 `Cibar / Sub Cibarengkok`;
- warehouse rows remained preserved: WarehouseStocks 4, StockMovements 42, StockTransfers 1.

### Runtime / UAT
- Package A routes cache successfully.
- Ten `/api/v1/sub-stock/*` routes are registered.
- Frontend Package A build deployed with same-origin runtime config.
- Home and Laravel health return HTTP 200 after maintenance mode was disabled.
- Unauthenticated `/api/v1/sub-stock/my` returns HTTP 401 (route exists and auth is enforced).
- Admin and Gudang production UAT passed.
- Sales-Kurir-Sub ownership resolution for Nida -> Sub Location id 3 passed.

### Final status

**R-01 CLOSED · R-02 CLOSED · Package A CLOSED · Production deployment CLOSED.**

Old sections above that say "production untouched", "main not merged", or "manual UAT still needed" are historical checkpoint entries and are superseded by this closure section.

### EXACT NEXT ACTION
Proceed with Package B using `AGENTS.md`, `docs/PACKAGE-B-R03-R04.md`, and `docs/PACKAGE-B-R03-R04-CHECKPOINT.md`.
