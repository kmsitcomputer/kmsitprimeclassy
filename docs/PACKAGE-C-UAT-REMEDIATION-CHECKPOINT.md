# Package C — Production UAT Remediation (checkpoint)

**Status:** IN PROGRESS. Package C is NOT production closed. DEV only; no production access, deploy or migration.
**Branch:** `fix/package-c-production-uat` (from `main` @ `938100c`). **Baseline backend suite:** 855 passed / 6075 assertions / 0 failures; frontend type-check + build PASS.

## Findings being remediated
A. Resi/shipment is per OrderItem (checkout creates one Shipment per item). Rule is Order + requested delivery date.
B. Admin approval is proposal-scoped (one click executes every product).
C. Order quantity adjustment does not reconcile the Stock Request item.
D/E. Consumer renders flat per-item delivery instead of the backend `delivery_groups`; Gudang needs Order/Diajukan/Dipenuhi/Sisa.

## Design decisions (targeted verification done; no re-RECON)
1. **Per-item decision lives on `stock_request_proposal_items`** (Gudang's proposed fulfilment), never on `stock_request_items` (order demand). Rejecting a proposal item leaves requested/fulfilled/remaining untouched; Gudang can propose again.
   - Migration adds `decision_status` (`pending|approved|rejected`), `decided_by`, `decided_at`, `decision_reason`; header enum gains `partial`. Backfill only from existing header evidence (approved/rejected proposals) — pending stays pending.
   - Header status is derived: all pending → `pending`; all approved → `approved`; all rejected → `rejected`; otherwise → `partial`.
   - New endpoints approve/reject one proposal item; the existing whole-proposal endpoints stay and act on all still-pending items.
2. **Stock Request follows the order.** Quantity adjustment takes Order → Stock Request → inventory lock order (same as SC-03/approval) and reconciles `requested_qty`/`remaining_qty`; reduction beyond the unfulfilled remainder is rejected 422 (never rewrites fulfilled warehouse history); status recomputed with locking reads.
3. **One canonical shipment-group resolver** (`ShipmentGroupingService::resolveMutableShipmentFor(order, date, attributes)`), used by checkout, SC-03, split and reschedule. Serialization = the Order row lock (all writers already hold it); no unsafe pre-check, no new unique index.
4. **No new `shipments` date column.** Scheduling truth stays `order_items.requested_delivery_date`; a shipment's date is derived from its (non-cancelled) items, so a second editable truth cannot exist. A shipment is **mutable** only if `pending`, no courier, not shipped/delivered, no delivery verification; only mutable shipments are reused/merged. Assigned / in-flight / delivered shipments are never regrouped (an item gets a new mutable shipment instead — explicit domain constraint).
5. **Historical/active production orders:** `ShipmentGroupingService::reconcileOrder` merges mutable same-date shipments (preserving the shipping fee snapshot); exposed as `shipments:regroup` (dry-run default, `--apply`), also run lazily when an order is rescheduled/added to. No data merge inside a migration.
6. **Consumer:** backend `delivery_groups` is extended (items, derived group status, safe shipment info); the consumer UI renders it. No warehouse internals exposed.

## Implementation result (awaiting independent review)
- **Migration (1, additive):** `2026_10_03_100000_add_item_decision_to_stock_request_proposal_items` (decision columns + `partial` header status; backfill only from header evidence). No `shipments` column, no unique index. Applied to the DEV DB and the test DB only.
- **New:** `ShipmentGroupingService`, `shipments:regroup` command, per-item approve/reject endpoints (`POST /warehouse/fulfillment-proposals/{p}/items/{i}/approve|reject`).
- **Changed:** `StockRequestProposalService` (per-line decision, derived header), `StockRequestService` (`lockItemForQuantityChange`, `applyQuantityChange`, `reconcileStatus`), `OrderFulfillmentService` (quantity reconciliation; reschedule/split via resolver; old `assignFreshShipment`/`splitShipmentIfShared` removed), `OrderService` (checkout via resolver), `OrderLineAdditionService`, `OrderResource.delivery_groups` (items/status/shipments), `ShipmentReceiptResource` (active lines only), `StockRequestItemResource` (`order_quantity`, `delivery_date`), proposal item resource/controller/routes, 4 lang files; frontend `StockRequestsView` (per-product actions; Jumlah Order/Diajukan/Dipenuhi/Sisa), `OrderDetailView` (consumer delivery plan), `api/{proposals,stockRequests,types}.ts`, 4 locales.
- **Tests:** new `PackageCUatRemediationTest` (14) + 2 race tests in `OrderLineAdditionConcurrencyTest` (per-product approvals, same-date additions) + migration test (6 steps). Seven existing tests whose premise was "one shipment per product" were re-based to the Order + date rule (CourierSystemTest ×2, OrderFulfillmentTest, R04OrderProjectionTest, ReportSystemTest ×2); none weakened.
- **Results:** full `php artisan test` **871 passed / 6244 assertions / 0 failures** (baseline 855/6075); `npm run type-check` PASS; `npm run build-only` PASS; `git diff --check` clean.
- **Docs updated:** BUSINESS-RULES, MASTER-SYSTEM, ARCHITECTURE, OPERATIONS, README (Package C = deployed, Production UAT FAILED, remediation pending review, NOT production closed).
- **Remaining risks:** (1) the migration backfill is not covered by an automated test (SQL reviewed only); (2) committed (assigned/in-flight) shipments are never regrouped — a same-date item then gets a separate new shipment (documented constraint); (3) `shipments:regroup --apply` on production needs backup + Human authorization; (4) reducing an order line below the warehouse-fulfilled quantity is now an explicit 422.

## EXACT NEXT ACTION
Independent Codex review of branch `fix/package-c-production-uat`. No deployment; Package C remains NOT production closed.
