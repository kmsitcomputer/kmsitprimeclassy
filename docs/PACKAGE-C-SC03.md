# Package C — SC-03 Existing-Order Line Addition (Specification)

**Status: SPECIFICATION LOCKED — NOT YET IMPLEMENTED. No implementation is authorized until the Human stage gate.**
**Date:** 2026-10-01
**Branch:** `feat/package-c-sc03` (branched from `ce4a4d5`, the roadmap-recon commit; `ce4a4d5` remains an ancestor of `main`)
**Baseline:** `ce4a4d5` (docs) on top of `main` @ `7d1c481`
**Repository protocol:** `AGENTS.md`
**Predecessors:** Package A = PRODUCTION CLOSED · Package B / R-03 / R-04 = PRODUCTION CLOSED
**Checkpoint:** `docs/PACKAGE-C-SC03-CHECKPOINT.md`

---

## 0. Human decisions (LOCKED)

| ID | Decision |
|---|---|
| RC-1 | **APPROVED.** Admin may add a NEW product/variation line to an existing order (new `OrderItem`), distinct from existing quantity adjustment. SC-03 is an authorized business requirement. |
| RC-2 | **NO CHANGE / OUT OF SCOPE.** No Laravel 11 → 12 upgrade; no framework-upgrade remediation. |
| RC-3 | **NO CHANGE / OUT OF SCOPE.** Google Sheets and RajaOngkir operate satisfactorily; no redesign, no credential rotation, no sharing change, no behavior change. |
| RC-4 | **APPROVED CURRENT BEHAVIOR / NO CHANGE.** Warehouse/report actor names keep stable IDs with current/latest-name resolution. No historical name snapshots. |

**Roadmap freeze:** WH-08, DP-03, DP-04, dead code, documentation drift, framework upgrade, and operational housekeeping remain documented backlog only and are **not** authorized. Package C is the final feature package for the current approved scope.

---

## 1. Objective

Allow an authorized Admin (`super_admin` / same-Agent `agen` / same-Agent `admin`) to add a new product/variation line to an existing eligible order, preserving every closed Package A and Package B business, stock, fulfillment, delivery, financial, authorization, audit, idempotency, and concurrency invariant.

**Do not redesign existing order architecture.** Package C extends the existing order/fulfillment/stock/payment services; it does not replace them.

---

## 2. Bounded SC-03 recon (source inspected)

`AGENTS.md`; `README.md`; `docs/PACKAGE-C-ROADMAP-RECON.md`; `docs/PACKAGE-B-R03-R04.md`; `docs/PACKAGE-B-R03-R04-CHECKPOINT.md`; `docs/PACKAGE-A-R01-R02-CHECKPOINT.md`; `docs/WAREHOUSE-AND-SALES-COURIER-SPECIFICATION.md` (§3 REQ, §6 INV).

Source:
- `app/Services/Order/OrderService.php` (`createOrder`, `resolveLine`, `resolvedTarget`, `priceAndReserveLine`, `recordCommission`, `updateStatus`, `cancel`, `lockSubLocationForCheckout`)
- `app/Services/Order/OrderFulfillmentService.php` (`adjustItemQuantity`, `increaseFulfillment`, `reduceFulfillment`, `splitItemForReschedule`, `lockStockRequestItemForSplit`, `applyStockRequestSplit`, `assignFreshShipment`, `markAdditionalPaymentPaid`, `markAdjustmentRefundStatus`)
- `app/Services/Order/OrderTotalCalculator.php`
- `app/Services/Stock/StockService.php`, `SubStockService.php`, `StockSourceResolver.php`, `StockRequestService.php`
- `app/Services/Payment/PaymentService.php`, `PaymentSummaryService.php`
- `app/Http/Controllers/Api/V1/Order/OrderController.php`, `Fulfillment/OrderFulfillmentController.php`
- `app/Http/Requests/Fulfillment/{AdjustOrderItemQuantityRequest,RescheduleOrderItemRequest}.php`
- `app/Http/Resources/{OrderResource,OrderItemResource}.php`
- `app/Policies/OrderPolicy.php`
- `app/Models/{Order,OrderItem,Shipment}.php`
- `routes/api_v1.php`
- Tests: `OrderFulfillmentTest`, `FinancialObligationReconciliationTest`, `SubQuantityAdjustmentTest`, `StockRequestSplitTest`, `R04OrderProjectionTest`, `FulfillmentConcurrencyTest`, `FinancialConcurrencyTest`; harness `.phpunit-concurrency-actor.php`

### 2.1 What exists today (authoritative)

- **Order creation** (`OrderService::createOrder`) is the only place an `OrderItem` is created for a new sale. It resolves each line via `resolveLine` (active product; variation-required / variation-not-allowed), locks the effective inventory targets in canonical order, snapshots the line in `priceAndReserveLine` (product/variation/SKU/unit price/agent+sales+courier fee/subtotal), reserves stock (Agent via `StockService`, Sub via `SubStockService`), records agent+sales commissions via `recordCommission`, then creates **one Shipment per item** and (when the order starts at `diproses`) a Stock Request via `StockRequestService::createForOrderWhenProcessing`.
- **Quantity adjustment** (`OrderFulfillmentService::adjustItemQuantity`) only changes an existing line's `fulfilled_quantity`, only while `order.status === 'diproses'`, and reconciles totals + refund/additional-payment ledgers canonically.
- **Date split** (`splitItemForReschedule`) already creates a **new `OrderItem`** for the same product on a new date, with its own fresh Shipment, Stock Request split, and (for Sub) reservation split. SC-03 reuses this machinery rather than inventing a parallel one.
- **Payment truth is order-level**: `OrderTotalCalculator::recalculate` is the only place `subtotal_amount`/`total_amount` are recomputed after creation; `PaymentService::recalculatePaymentStatus` (via `applyPaymentToOrder`/`reverseAppliedPayment`/`reconcileTotals`) is the only writer of `paid_amount`/`remaining_amount`/`payment_status`; `PaymentSummaryService` is the canonical summary.
- **Authority**: `OrderPolicy::manageFulfillment` = `super_admin` or same-Agent `agen`/`admin`; fulfillment routes live in the `role:super_admin,agen,admin` group.
- **Stock Request**: exactly one per order, created when the order first enters `diproses`; `StockRequestService::createForOrderWhenProcessing` is idempotent and rejects a second request; Sub items are excluded.
- **Idempotency**: order creation uses an `Idempotency-Key` header + unique `(konsumen_id, idempotency_key)`; fulfillment adjust is naturally idempotent because it sets an absolute quantity. There is **no** per-item idempotency store today.
- **Lock order**: Order-level operations lock the `Order` row (`OrderService::cancel`, `OrderFulfillmentService` mutators); inventory locks use `StockService::canonicalReservationTargets` (`p:{id}` / `v:{id}`, `ksort`ed).

---

## 3. Final SC-03 architecture (LOCKED)

### 3.1 Authority

- Allowed actors: `super_admin` (any branch, existing override) and same-Agent `agen` / `admin`.
- Enforced by reuse of `OrderPolicy::manageFulfillment` (no new policy ability, no authority expansion). Route sits inside the existing `role:super_admin,agen,admin` middleware group.
- Branch isolation preserved: the global `BelongsToAgentScope` on `Order` plus the policy's `order->agent_id === actor->agent_id` re-check.
- Kurir, keuangan, korsal, sales, sales-kurir-sub, konsumen cannot add lines (403). Adding a line is operational, not financial.
- **Locked scope boundary:** a Sub-sourced order (`order` containing `stock_source='sub'` items) cannot accept an added line. Because the authorized actor is never a Sales-Kurir-Sub, Sub source can never be resolved for this action; a Sub-sourced order is rejected with a clear 422. This preserves the Sales-Kurir-Sub/Sub-owner restriction by construction. (Mixed-source orders are out of scope for Package C.)

### 3.2 Product / variation resolution

- Reuse `OrderService::resolveLine` semantics exactly (single source of truth):
  - `products.status = active` required; inactive/missing → 404/422 per existing convention.
  - `has_variations = false` → PRODUCT target; supplying `product_variation_id` → 422 `messages.product.variation_not_allowed`.
  - `has_variations = true` → VARIATION target; a variation id is required, must belong to that product and be active → else `variation_required` / 404.
- The server is authoritative for product identity, variation identity, SKU, unit price, fees, and every snapshot field. Client-submitted price/fee/SKU/subtotal is never accepted (no such fields in the request).

### 3.3 OrderItem snapshot

- The new line is created with the same canonical snapshot fields as a checkout line: `product_name_snapshot`, `variation_label_snapshot`, `sku_snapshot`, `unit_price_snapshot`, `agent_fee_amount` (per-unit × qty), `sales_fee_amount`, `courier_fee_amount`, `subtotal_snapshot`, `original_quantity = fulfilled_quantity = qty`, `status = order.status`, `stock_source = 'agent'`, `sub_location_id = null`, `split_from_order_item_id = null`, `requested_delivery_date`.
- **Existing lines are never mutated** by the addition (their snapshots, quantities, and shipment assignments are untouched). Only the order-level totals are recomputed by `OrderTotalCalculator`.
- The snapshot construction is extracted into ONE shared method consumed by both `createOrder`'s `priceAndReserveLine` and the new addition path, so checkout and addition cannot drift.

### 3.4 Stock source

- **Agent stock only.** The action is office-only; `StockSourceResolver` is not asked to authorise Sub (an Admin is never an eligible Sub actor). A client-supplied `stock_source`/`sub_location_id` is rejected outright (422) — never silently ignored.
- Reservation uses `StockService::reserveForProduct` / `reserveForVariation` (Agent commitment row first, then Warehouse Transit/Plan) under the transaction, exactly as checkout, with `InsufficientStockException` → 422 on shortfall.
- No alternate stock truth; no Sub reservation is created; `sub_stock_reservations` is untouched.

### 3.5 Quantity

- `quantity` required, integer, `min:1`. Non-integer/`< 1` → 422.
- Capacity validated atomically under the canonical target lock (no oversell); see §3.12.

### 3.6 Delivery date

- `requested_delivery_date` optional; when supplied it must be a valid date `after_or_equal:today` (existing convention). When omitted it defaults to the order's `delivery_date_estimate` (falling back to today if the order has none).
- Preserves Package B delivery grouping: the derived `OrderResource.delivery_groups` projection (`Order + requested_delivery_date`) automatically includes the new line — no product-based grouping, no new grouping model.

### 3.7 Fulfillment / shipment

- The new line receives its **own fresh Shipment** created by the existing shipment-creation helper (`OrderFulfillmentService::assignFreshShipment` logic, extracted/reused), cloning the order's destination/provider snapshot: `delivery_mode = standard`, `self_delivered_by_user_id = null`, `status = pending`, `courier_id = null`. The new line is then discoverable through the existing courier/office workflow.
- **Eligible order status: `diproses` only** — the same window as quantity adjustment (`manageFulfillment`). This guarantees the order is already in the Stock-Request-backed fulfillment flow and avoids inventing a new pre-processing state. An addition on any other status (`diterima`, `dikirim`, `terkirim`, `pengembalian`, `kembali`, `dibatalkan`) is rejected 422 (`messages.fulfillment.window_closed` / a dedicated key). No parallel fulfillment implementation.

### 3.8 Financial reconciliation

Canonical sequence inside the transaction, after the item is created and reserved:

1. `OrderTotalCalculator::recalculate($order)` → recomputes `subtotal_amount` (Σ `unit_price_snapshot × fulfilled_quantity`) and `total_amount` (subtotal − discount + shipping + admin).
2. `PaymentService::reconcileTotals($order)` → re-derives `remaining_amount`/`payment_status` from the **existing** `paid_amount` against the new total.

| Order state before addition | Result after addition |
|---|---|
| Unpaid (`paid_amount = 0`) | `remaining_amount` grows; `payment_status` stays `unpaid`. No separate ledger row. |
| Partially paid (DP) | `remaining_amount` grows; stays `partially_paid`. No separate ledger row. |
| Fully paid (`remaining_amount = 0`) | New total > paid → `remaining_amount > 0`, status becomes `partially_paid`; a **pending `OrderAdditionalPayment`** is created for the delta via `PaymentService::initiateAdditionalPayment`, linked to the new item's `additional_payment_id` — identical to the canonical `increaseFulfillment` rule. |
| Already has a pending obligation on the new line's target | N/A (a new line has no prior obligation); the order-level pending obligation is only created in the fully-paid case above. |

- **Never silently mark the new amount paid.** `paid_amount` is only ever changed by real money movements through `PaymentService`.
- **Locked decision (identical rule to `increaseFulfillment`, extracted to one shared helper):** `previousRemaining <= 0 && newRemaining > 0` → create exactly one pending `OrderAdditionalPayment` for `newRemaining − previousRemaining`. The obligation-blocking guards (pending refund / pending additional payment) remain owned by the existing fulfillment paths; a brand-new line is not blocked by them because it does not mutate an existing line's obligation — but see §3.12 for order-level serialization.

### 3.9 Fees / commissions

- Fee snapshot uses `FeeService::resolveForLine($product, $variation)` per unit × qty for agent/sales/courier — the canonical calculation. No new financial model.
- Agent + sales `Commission` rows are recorded for the new line via the **same** canonical `OrderService::recordCommission` path (extracted to a shared public method), using the same sales-beneficiary rule as checkout (`$konsumen->isRole('agen','korsal','sales') ? konsumen->id : (sales_id ?? korsal_id ?? agentId)`).
- Courier fee is earned on delivery through the unchanged `CourierService::recordCommissionsOnDelivery`; the new line participates because it has its own Shipment. No courier commission row is written at addition time.
- Rationale (lock): a genuinely new product line is a new sale, so it snapshots and earns exactly like a checkout line. This is distinct from a quantity increase on an existing line (which deliberately records no new commission).

### 3.10 Stock Request interaction

- For the eligible `diproses` Agent order, the existing one-per-order `StockRequest` is extended with a new `StockRequestItem` for the added line (`requested_qty = qty`, `fulfilled_qty = 0`, `remaining_qty = qty`, `sku_snapshot` from the item) through a new canonical method on `StockRequestService` (e.g. `appendItemForOrderItem(Order, OrderItem)`), preserving the unique `order_item_id` invariant and never duplicating request logic.
- If the `StockRequest` is missing or `cancelled` (or the order is Sub-only) → reject 422; the addition never silently leaves demand untracked.
- Sub-sourced orders are rejected earlier (§3.1), so no Sub item ever reaches this path.

### 3.11 Idempotency

- The endpoint **requires a non-empty `Idempotency-Key` header** (≤ 100 chars), consistent with the project's idempotency rule.
- The key is stored on the created `OrderItem` and is unique per order (`UNIQUE(order_id, idempotency_key)`).
- Replay semantics:
  - Same key + identical canonical request (order, product, variation, quantity, delivery date) → return the originally created line idempotently (no second item, no second reservation, no second financial effect).
  - Same key + a **different** request → 409 conflict (never an unrelated row).
  - Concurrent duplicate submissions → the unique index is the real guard; the loser returns the winner's item (mirroring `createOrder`'s `orders_konsumen_idempotency_unique` race handling).
- Missing key → 422, matching the existing `messages.payment.idempotency_key_required` convention.

### 3.12 Concurrency

- Transaction boundary: one `DB::transaction`.
- Lock order (LOCKED): **`Order` row (`lockForUpdate`) → Agent inventory target(s) in canonical order (`StockService::lockReservationTargets` / `reserveFor*`) → `StockRequest`/`StockRequestItem` → new `OrderItem`/`Shipment`**.
  - Locking the `Order` first makes addition mutually exclusive with the existing order-level mutators that also lock the `Order` row (`OrderService::cancel`, `OrderService::updateStatus`, and the fulfillment mutators), so addition cannot interleave with cancellation/status changes/another addition.
  - The inventory targets are acquired via the shared canonical vocabulary (identical to checkout and Transit→Sub execution), so no second ordering vocabulary and no lock inversion is introduced.
- Correctness must hold against concurrent: quantity adjustment, another line addition, stock reservation (checkout), fulfillment, cancellation/return, and payment activity. Concurrent additions on the same order serialize on the `Order` lock; the second sees the first's totals/reservations.

### 3.13 Audit

- `ActivityLogger::log($actor->id, $newItem, 'order_item.added', $reason, [...])` recorded inside the transaction, capturing: `order_id`, `product_id`, `product_variation_id`, `quantity`, `stock_source` (`agent`), `requested_delivery_date`, `total_before`/`total_after`, `actor_role`, and the idempotency key (non-secret). No competing audit system.

---

## 4. DB / migration decision

**One additive migration is genuinely required** (idempotency), everything else reuses the current schema.

- **Why:** SC-03 is a non-idempotent "append" operation, and there is no existing per-order-line idempotency store. `orders.idempotency_key` is order-creation-scoped and cannot represent a line addition. Without a persisted, uniquely-constrained key, a network retry can create a duplicate line with a duplicate reservation and duplicate financial effect. Requirement §3.11 cannot be met without it.
- **Migration:** `2026_10_02_100000_add_idempotency_key_to_order_items_table.php`
  - `order_items.idempotency_key` — `string(100)`, **nullable** (historical rows and checkout lines stay NULL); `UNIQUE(order_id, idempotency_key)` (MySQL permits multiple NULLs, so existing rows are unaffected).
  - Additive only; no column dropped/altered; no data rewrite; production-safe.
  - Rollback: drop the unique index, then the column.
- **No other migration.** No marker column is needed: a new line reuses the existing `order_items` shape (`split_from_order_item_id = null`). The `OrderItem` model gains `idempotency_key` to `$fillable` only.

---

## 5. API

Smallest change; no unrelated endpoint redesign.

- **Route:** `POST /orders/{order}/items` added to the existing `role:super_admin,agen,admin` group (adjacent to the fulfillment routes in `routes/api_v1.php`). Controller: `OrderFulfillmentController::addItem` (reuse the existing controller).
- **Headers:** `Idempotency-Key` (required).
- **Body:** `product_id` (required, int), `product_variation_id` (nullable, int), `quantity` (required, int ≥ 1), `requested_delivery_date` (nullable, date ≥ today), `reason` (required, string ≤ 255), `additional_payment_method` (optional, `in:transfer,cod`, default `transfer`; only meaningful in the fully-paid → obligation case).
- **Validation:** new `AddOrderItemRequest` following the existing `BaseFormRequest` convention. No price/fee/SKU/subtotal/agent_id/sales_id/status fields exist.
- **Responses:** `201 Created` with the canonical `OrderResource` (full order + items + payment summary) so the frontend refreshes authoritative state; idempotent replay → `200 OK` with the same `OrderResource`; conflicting key reuse → `409`; validation/authority/state errors → existing `ApiException` 422/403 conventions with 404 for out-of-branch resources.
- Authority failure → 403 (`manageFulfillment`); status not `diproses` → 422; insufficient stock → 422.

---

## 6. Frontend

- `views/OrderDetailView.vue`: a bounded **"Tambah Produk"** flow, visible only to `super_admin`/`agen`/`admin` while `order.status === 'diproses'`:
  - select product/variation (reuse the existing catalog product/variation picker components),
  - enter quantity,
  - confirm/choose the requested delivery date (default the order's `delivery_date_estimate`),
  - submit, then refresh the canonical order/payment state from the returned `OrderResource`.
- No editable authoritative price/fee fields are exposed; the UI renders server-derived values only.
- `api/orderAdjustments.ts` (or `api/orders.ts`): add `addOrderItem(orderId, payload)` that sends an `Idempotency-Key` generated per logical submission and held until success (mirroring the existing checkout idempotency pattern).
- i18n: new keys in `i18n/locales/{id,en,ar,zh}.ts`; backend messages in `lang/{id,en,ar,zh}/messages.php`.
- Frontend controls mirror backend permission/status rules (UX-only); the backend remains authoritative.
- Verification: `npm run type-check` and `npm run build-only` must pass. **No new frontend test framework is introduced for Package C.**

---

## 7. Tests required (backend regression)

New/extended Feature coverage (real DB; deterministic concurrency via the existing `.phpunit-concurrency-actor.php` harness where races are involved):

1. Authorized `super_admin` / same-Agent `agen` / same-Agent `admin` success; canonical `OrderResource` returned.
2. Unauthorized roles denied: kurir, keuangan, korsal, sales, sales-kurir-sub, konsumen → 403 (no side effects).
3. Cross-Agent denied: agen/admin of another branch → 403/404, no item/reservation created.
4. Invalid product/variation: inactive/soft-deleted product; missing/foreign/inactive variation; variation id on a simple product; missing variation on a variation product → 422/404, nothing created.
5. Invalid quantity: missing, `0`, negative, non-integer → 422.
6. Insufficient Agent stock → 422 (`InsufficientStockException`), no reservation and no item persisted.
7. Insufficient Sub stock → N/A by construction: `stock_source=sub` / `sub_location_id` supplied → 422; Sub-sourced order → 422 (explicit rejection test).
8. Correct reservation: agent committed/transit reserved by exactly the added quantity; `stock_movements` `reserve` row with the canonical reference.
9. Correct snapshot: product/variation/SKU/unit price/agent+sales+courier fee/subtotal snapshotted from the server; client cannot influence them.
10. Correct totals: `subtotal_amount`/`total_amount` recomputed by `OrderTotalCalculator`; existing line untouched.
11. Unpaid order: remaining grows; `payment_status` stays `unpaid`; no `OrderAdditionalPayment`.
12. Partially paid (DP) order: remaining grows; stays `partially_paid`; no `OrderAdditionalPayment`.
13. Already fully paid order: `remaining > 0` → `partially_paid` + exactly one pending `OrderAdditionalPayment` linked to the new item; no silent paid marking.
14. Idempotent retry: same key + identical request → one item, one reservation, one financial effect (idempotent 200); same key + different request → 409.
15. Concurrent line additions (same order, two connections): serialized; no lost update; both items present with both reservations; no duplicate key.
16. Concurrent stock mutation (addition vs checkout/fulfillment increase on the same target): no oversell, no deadlock, one valid outcome, inventory conserved.
17. Delivery-date grouping: the added line appears in the correct derived `delivery_groups` bucket for its `requested_delivery_date`; changing the date re-buckets.
18. Shipment/fulfillment behavior: exactly one fresh `standard` pending Shipment for the new line; Stock Request gains exactly one `StockRequestItem`; existing request/items conserved.
19. Audit evidence: `order_item.added` log with actor, order, item, quantity, source, date, totals.
20. Package A regression invariants: Sub stock ownership/source rules, reservation lifecycle, canonical lock order unchanged.
21. Package B regression invariants: self-delivery, delivery verification, R-04 authority projections, split lineage, fulfillment money rules unchanged.

Focused tests first; then the full `php artisan test` suite must be green (0 failures) before review. Frontend: type-check + build.

---

## 8. Explicit exclusions

- No Laravel 12 upgrade (RC-2); no Google Sheets / RajaOngkir change (RC-3); no historical name snapshots (RC-4).
- No change to Package A/B closed behavior (R-01/R-02/R-03/R-04).
- No new role, no authority expansion, no change to `OrderPolicy` beyond reuse.
- No Sub-sourced or mixed-source order line addition; no Sub reservation from this path.
- No addition outside `diproses`; no addition of a brand-new *order* (that is checkout).
- No product-based invoice/delivery grouping; no new payment calculation; no new financial model.
- No parallel fulfillment implementation; no schema change beyond the single idempotency-key migration.
- No WH-08 / DP-03 / DP-04 / dead-code / documentation-drift work.
- No production migration or deployment.
- No frontend test framework introduction.

---

## 9. Package A / Package B invariants preserved

- **Package A:** role set (10), `sales-kurir-sub` canonical slug, referral prefixes, Sub Location 1:1 ownership, `StockSourceResolver` server-side authority, Sub reservation lifecycle, canonical multi-target lock order, `SubStockService` lock order (stock row → reservations).
- **Package B:** `self_sub` self-delivery + triggers, append-only delivery verification + idempotency, split lineage (`split_from_order_item_id`), delivery-date grouping projection, R-04 operational-vs-financial projections (added items still flow through `OrderItemResource`/`OrderResource` role gating), Admin Product/Variation CRU-no-Delete, fulfillment money rules (MAJOR-7/8/10/11), courier-fee-scaling/split conservation, canonical additional-payment/refund semantics.
- Order-level payment truth remains `PaymentSummaryService`-derived; `OrderTotalCalculator` remains the only post-creation total writer.

---

## 10. Open blockers

None. One scope boundary is recorded for the Human (not a blocker): Sub-sourced/mixed-source order line addition is deliberately excluded (§3.1); if it is ever wanted, it requires a separate Human decision and its own stock-source design.

---

## EXACT NEXT ACTION

**Human reviews and authorizes Package C SC-03 implementation. No other feature implementation is authorized.**
