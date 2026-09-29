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

## Status
(see sections appended per commit below)
