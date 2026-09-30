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
| MAJOR-4 first / new-SKU replenishment impossible | `GET /sub-stock/replenishment-targets` (Agent Transit stock, no Sub row needed); `create()` rejects replenish targets not in the Agent's Transit; return stays limited to Sub sellable; no fake zero rows | `SubStockReplenishmentTargetsTest` (zero-row first flow, new SKU, forged/foreign/no-Transit/invalid variation, role gate) | 3729084 (backend), afaec33 (UI) |
| MINOR-5 relation key | canonical `sub_location` (API already emitted it); TS type + Approvals/Execution views fixed | `SubStockReplenishmentTargetsTest::test_request_responses_use_the_canonical_snake_case_sub_location_key` (sub/admin/gudang, asserts `subLocation` absent) | afaec33 |
| MINOR-6 load/pagination error states | explicit loading / error(+retry) / empty in SubStock, Approvals, Execution; backend 404 "no active Sub Location" shown as such | type-check + build | afaec33 |

### Results
Backend `vendor/bin/phpunit`: **718 tests, 4674 assertions, 0 failures**. Frontend `npm run type-check` clean, `npm run build-only` OK (pre-existing chunk-size warning only).

### Remaining
None. Manual DEV/UAT still needed for role visibility/interaction in the Sub Location and Sub stock screens. `main` not merged; production untouched; R-03/R-04 untouched. Branch not pushed yet.

### EXACT NEXT ACTION
Push `remed/package-a-vps` to origin (original brief asked for it) on user confirmation; then DEV/UAT.
