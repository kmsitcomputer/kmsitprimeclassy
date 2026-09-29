# R-01 / R-02 — Sales-Kurir-Sub and Sub stock (Package A)

## R-01 role, referral, ownership
- Role `sales-kurir` became `sales-kurir-sub` (display "Sales-Kurir-Sub"). The existing `roles` row is renamed in place by migration `2026_09_29_090000` (no 11th role; `users.role_id` untouched). Until it runs, `Role::canonicalSlug()` / `User::isRole()` treat the legacy slug as the new one; SQL role lists carry both slugs. API emits the canonical slug. Legacy routes/payloads (`convert-to-sales-kurir`, `role: sales-kurir`) still work.
- Referral: **SS-** prefix for NEW Sales-Kurir-Sub codes only. Historical SA-/SK- codes are never rewritten; Sales -> Sales-Kurir-Sub conversion keeps the existing code (SS- generated only if none).
- Ownership: `warehouse_sub_locations.owner_user_id` (UNIQUE, nullable). 1 user = 1 Sub Location. Written only by `SubLocationOwnershipService` (Agen/Admin; same-Agent active Sales-Kurir-Sub; no silent reassignment; released on deactivate). Gudang cannot create Sub Locations or assign owners. Existing production locations stay unowned until explicitly assigned (`POST /warehouse/sub-locations/{id}/assign-owner`).

## R-02 stock
- Agent Sellable = Transit + active Factory Plan - Agent Reserved. Sub is **never** subtracted again (unchanged `SellableStockService`).
- Sub Sellable = Sub physical - active Sub reservations (`sub_stock_reservations`, one per order item).
- Source rules (`StockSourceResolver`, server-side): Sub stock only for the owning Sales-Kurir-Sub's own purchase or an order they place for a consumer in their own referral network. Consumer self-checkout, other Sales/Korsal/Sub, other Agents => Agent stock only (Sub request => 403). Persisted on `order_items.stock_source` / `sub_location_id` (+ DB CHECK).
- Lifecycle: create -> reserve (physical unchanged) · shipment (`dikirim`) -> physical -= qty once, reservation consumed, `out` movement · cancel -> reservation released. Lock order: Sub stock row, then reservation rows; reservation sums that guard writes are locking reads.
- Replenishment (Transit -> Sub): Sales-Kurir-Sub requests -> Admin approves -> Gudang executes (audited transfer: movements + handover) -> Sub confirms receipt. Return (Sub -> Transit): request -> Admin approves -> Gudang receives goods (Sub down, Transit up, movements + handover). Nothing moves before execute; execute is idempotent. Owned Sub Locations reject direct generic Gudang transfers. Every Sub decrease (return, transfer, adjustment, opname) is refused below active reservations.

## Deferred (not in Package A)
- R-03: invoices/splitting, final delivery verification, Sub-order quantity adjust/split/return (currently guarded with a clear 422), Admin final outcome for Sub orders.
- R-04: order authority, Gudang financial projection, Kurir queue, reports, Admin Product CRU.
- Frontend screens for Sub requests/approval/execution (API client `src/api/subStock.ts` only); quote-time Sub sellable warning.
