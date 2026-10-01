# Package C (SC-03) — Checkpoint

**Status:** SPECIFICATION LOCKED — NOT YET IMPLEMENTED. Awaiting Human authorization.
**Created:** 2026-10-01
**Active spec:** `docs/PACKAGE-C-SC03.md`
**Roadmap recon:** `docs/PACKAGE-C-ROADMAP-RECON.md` (CLOSED — decisions recorded)
**Repository protocol:** `AGENTS.md`

## Objective

Allow an authorized Admin (`super_admin` / same-Agent `agen` / same-Agent `admin`) to add a **new** product/variation line to an existing eligible order, preserving every Package A and Package B business, stock, fulfillment, delivery, financial, authorization, audit, idempotency, and concurrency invariant. No redesign of existing order architecture.

## Baseline

- Branch: `feat/package-c-sc03` (created from `ce4a4d5`).
- `ce4a4d5` ("docs: reconcile roadmap after Package B") is the roadmap-recon commit; it is an ancestor of `main` and of the working branch and is not rewritten.
- `main` @ `7d1c481` ("merge: close Package B R-03 and R-04"); `origin/main` = `7d1c481`.
- Package A = PRODUCTION CLOSED · R-03 = PRODUCTION CLOSED · R-04 = PRODUCTION CLOSED · Package B = PRODUCTION CLOSED.
- Last recorded full backend suite (Package B closure): 815 passed / 5771 assertions / 0 failures.

## Locked decisions (Human)

- **RC-1 APPROVED** — SC-03 is an authorized business requirement (add a new product/variation line to an existing order; distinct from quantity adjustment).
- **RC-2 NO CHANGE / OUT OF SCOPE** — no Laravel 11 → 12 upgrade; no framework-upgrade remediation.
- **RC-3 NO CHANGE / OUT OF SCOPE** — Google Sheets and RajaOngkir unchanged (no redesign, rotation, sharing, or behavior change).
- **RC-4 APPROVED CURRENT BEHAVIOR** — stable IDs with current/latest-name resolution; no historical name snapshots.

## Source files inspected (bounded SC-03 recon)

- `AGENTS.md`, `README.md`, `docs/PACKAGE-C-ROADMAP-RECON.md`, `docs/PACKAGE-B-R03-R04.md`, `docs/PACKAGE-B-R03-R04-CHECKPOINT.md`, `docs/PACKAGE-A-R01-R02-CHECKPOINT.md`, `docs/WAREHOUSE-AND-SALES-COURIER-SPECIFICATION.md`.
- `app/Services/Order/{OrderService,OrderFulfillmentService,OrderTotalCalculator}.php`.
- `app/Services/Stock/{StockService,SubStockService,StockSourceResolver,StockRequestService}.php`.
- `app/Services/Payment/{PaymentService,PaymentSummaryService}.php`.
- `app/Http/Controllers/Api/V1/Order/OrderController.php`, `Fulfillment/OrderFulfillmentController.php`.
- `app/Http/Requests/Fulfillment/{AdjustOrderItemQuantityRequest,RescheduleOrderItemRequest}.php`.
- `app/Http/Resources/{OrderResource,OrderItemResource}.php`; `app/Policies/OrderPolicy.php`.
- `app/Models/{Order,OrderItem,Shipment}.php`; `routes/api_v1.php`.
- Tests + harness: `OrderFulfillmentTest`, `FinancialObligationReconciliationTest`, `SubQuantityAdjustmentTest`, `StockRequestSplitTest`, `R04OrderProjectionTest`, `FulfillmentConcurrencyTest`, `FinancialConcurrencyTest`, `.phpunit-concurrency-actor.php`.

## Locked architecture summary

- **Authority:** reuse `OrderPolicy::manageFulfillment` (`super_admin` or same-Agent `agen`/`admin`); route in the existing `role:super_admin,agen,admin` group. No authority expansion.
- **Product/variation:** reuse `OrderService::resolveLine` (active product; variation-required / variation-not-allowed); server-authoritative price/fees/SKU/snapshots.
- **Snapshot:** new line created with the canonical checkout snapshot fields; existing lines never mutated. Snapshot construction extracted to ONE shared helper used by checkout and addition.
- **Stock source:** Agent stock only; `stock_source`/`sub_location_id` rejected if supplied; Sub-sourced orders rejected 422 (scope boundary).
- **Quantity:** required int ≥ 1; atomic reserve under the canonical target lock.
- **Delivery date:** optional, `after_or_equal:today`, defaults to the order's `delivery_date_estimate`; preserves Order + requested_delivery_date grouping.
- **Shipment/fulfillment:** one fresh `standard` pending Shipment for the new line (reuse the existing shipment helper); eligible order status = `diproses` only.
- **Financial:** `OrderTotalCalculator::recalculate` then `PaymentService::reconcileTotals`; unpaid/partial → remaining grows, no new ledger; fully-paid → exactly one pending `OrderAdditionalPayment` for the delta linked to the new item (identical to `increaseFulfillment`, shared helper). Never silently mark paid.
- **Fees/commissions:** `FeeService::resolveForLine` per unit × qty; agent + sales `Commission` rows via the canonical `recordCommission`; courier fee earned on delivery through the unchanged path.
- **Stock Request:** append one `StockRequestItem` for the new line via a new canonical `StockRequestService` method; unique `order_item_id` preserved; missing/cancelled request → 422.
- **Idempotency:** required `Idempotency-Key` header (≤100 chars); unique per order; identical replay idempotent, divergent reuse 409, concurrent duplicate guarded by the unique index.
- **Concurrency lock order:** `Order` row → Agent inventory targets (canonical order) → `StockRequest`/`StockRequestItem` → new `OrderItem`/`Shipment`.
- **Audit:** `ActivityLogger` `order_item.added` with actor/order/item/qty/source/date/totals.

## Expected implementation file map

**Backend — modify**
- `app/Services/Order/OrderService.php` — extract/reuse `resolveLine` + line snapshot/commission helpers as shared public methods (single source of truth for checkout and addition).
- `app/Services/Order/OrderFulfillmentService.php` — extract reusable shipment-creation and additional-obligation helpers; add the add-line entry point (or delegate to a new service).
- `app/Services/Stock/StockRequestService.php` — add `appendItemForOrderItem(Order, OrderItem)`.
- `app/Http/Controllers/Api/V1/Fulfillment/OrderFulfillmentController.php` — add `addItem`.
- `app/Models/OrderItem.php` — add `idempotency_key` to `$fillable`.
- `routes/api_v1.php` — add `POST /orders/{order}/items` in the `role:super_admin,agen,admin` group.
- `lang/{id,en,ar,zh}/messages.php` — new messages.

**Backend — new**
- `app/Http/Requests/Fulfillment/AddOrderItemRequest.php`.
- `app/Services/Order/OrderLineAdditionService.php` (if the add-line flow is not kept inside `OrderFulfillmentService`).

**Backend tests — new/extended**
- New `OrderLineAdditionTest` (authority, validation, snapshot, totals, audit, status window, Sub rejection, stock request).
- New `OrderLineAdditionConcurrencyTest` (concurrent additions; addition vs checkout/fulfillment; canonical lock order).
- New `OrderLineAdditionPaymentTest` (unpaid / partially paid / fully paid obligation cases; idempotency replay + 409).
- Extended Package A/B regression suites as needed.

**Frontend — modify**
- `views/OrderDetailView.vue` — bounded "Tambah Produk" flow (role + `diproses` gated).
- `api/orderAdjustments.ts` (or `api/orders.ts`) — `addOrderItem` with `Idempotency-Key`.
- `i18n/locales/{id,en,ar,zh}.ts` — new keys.

## DB impact

- **One additive migration:** `2026_10_02_100000_add_idempotency_key_to_order_items_table.php` — `order_items.idempotency_key` `string(100)` nullable + `UNIQUE(order_id, idempotency_key)`. Historical rows and checkout lines stay NULL (MySQL allows multiple NULLs). Additive only; rollback drops index then column.
- No other schema change; no column dropped/altered; no data rewrite.

## API impact

- New `POST /orders/{order}/items` (existing `role:super_admin,agen,admin` group), `Idempotency-Key` required.
- Body: `product_id`, `product_variation_id?`, `quantity`, `requested_delivery_date?`, `reason`, `additional_payment_method?`.
- `201` canonical `OrderResource`; idempotent replay `200`; conflicting key reuse `409`; 422 validation/state; 403 authority; 404 out-of-branch.
- No unrelated endpoint changes.

## Frontend impact

- `OrderDetailView.vue` add-product flow (product/variation picker, quantity, delivery date, submit, refresh canonical state); no editable price/fee fields.
- API client sends per-submission `Idempotency-Key`; i18n keys added.
- Verification: `npm run type-check` + `npm run build-only` PASS. No new frontend test framework.

## Tests required

See `docs/PACKAGE-C-SC03.md` §7 (21 enumerated areas): authorized/unauthorized/cross-Agent, invalid product/variation/quantity, insufficient Agent stock, Sub rejection, correct reservation/snapshot/totals, unpaid/partial/fully-paid financial behavior, idempotent retry + 409, concurrent additions, concurrent stock mutation, delivery-date grouping, shipment/stock-request behavior, audit evidence, and Package A/B regression invariants. Run focused first, then the full backend suite green (0 failures); frontend type-check + build.

## Explicit exclusions

No Laravel 12 upgrade; no Google Sheets / RajaOngkir change; no historical name snapshots; no change to Package A/B closed behavior; no new role or authority expansion; no Sub-sourced or mixed-source addition; no addition outside `diproses`; no product-based grouping; no new payment calculation or financial model; no parallel fulfillment implementation; no schema change beyond the single idempotency-key migration; no WH-08 / DP-03 / DP-04 / dead-code / docs-drift work; no production migration or deployment; no frontend test framework.

## Package A / Package B invariants preserved

Package A: roles/referral, Sub Location 1:1 ownership, server-side `StockSourceResolver`, Sub reservation lifecycle, canonical multi-target and Sub lock orders.
Package B: `self_sub` self-delivery + triggers, append-only delivery verification + idempotency, split lineage, delivery-date grouping projection, R-04 operational-vs-financial projections, Admin Product/Variation CRU-no-Delete, fulfillment money rules (MAJOR-7/8/10/11), courier-fee conservation. Order-level payment truth remains `PaymentSummaryService`-derived; `OrderTotalCalculator` remains the only post-creation total writer.

## Open blockers

None. Recorded scope boundary (not a blocker): Sub-sourced/mixed-source order line addition is excluded and would require a separate Human decision.

## EXACT NEXT ACTION

**Human reviews and authorizes Package C SC-03 implementation. No other feature implementation is authorized.**
