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

## Commits so far
C1 role compat · C2 Sub Location ownership · C3 reservation ledger + stock source · C6 checkout/cancel/ship integration + tests

## Remaining
C4 Transit->Sub request/approve/Gudang-execute/handover flow · C5 Sub->Transit return flow · C7 frontend/API compat · C8 full regression, docs, final report

## Tests so far
Focused suites green (SalesKurirSubRoleTest 8, SubLocationOwnershipTest 8, SubStockSourceTest 15, SubStockConcurrencyTest 2). Full-suite baseline failures = 2 network-only (RajaOngkir via proxy 403).
