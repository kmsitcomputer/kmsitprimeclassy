# Package C (SC-03) — Checkpoint

**Status:** IMPLEMENTED + REVIEW REMEDIATION APPLIED (C-SC03-REV-001..008) — awaiting Codex independent final verification before DEV UAT. DEV UAT NOT done · Human Stage Gate NOT approved · NOT production closed.
**Created:** 2026-10-01
**Implementation baseline:** branch `feat/package-c-sc03`, spec HEAD `418bbdd` (unchanged as the parent of the implementation commit).
**Active spec:** `docs/PACKAGE-C-SC03.md`
**Roadmap recon:** `docs/PACKAGE-C-ROADMAP-RECON.md` (CLOSED — decisions recorded)
**Repository protocol:** `AGENTS.md`

## Objective

Allow an **Admin** (effective role exactly `admin`, restricted to the existing same-Agent/branch scope) to add a **new** product/variation line to an existing eligible order, preserving every Package A and Package B business, stock, fulfillment, delivery, financial, authorization, audit, idempotency, and concurrency invariant. No redesign of existing order architecture.

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
- **SC-03 AUTHORITY = ADMIN ONLY (FINAL, LOCKED)** — only effective role exactly `admin`, restricted to the existing same-Agent/branch scope. All other roles are denied, explicitly including `super_admin` and `agen`. `OrderPolicy::manageFulfillment` must NOT be modified; SC-03 uses a dedicated narrow rule (`OrderPolicy::addLine`).

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

- **Authority:** Admin only — a dedicated `OrderPolicy::addLine` ability (`$user->isRole('admin') && $order->agent_id === $user->agent_id`), gated by a dedicated `role:admin` route group. `OrderPolicy::manageFulfillment` is **not** modified or reused (Package A/B keeps its `super_admin`/same-Agent `agen`/`admin` authority). All other roles, including `super_admin` and `agen`, are denied; cross-Agent `admin` is denied.
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
- `routes/api_v1.php` — add `POST /orders/{order}/items` in a dedicated `role:admin` group.
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

- New `POST /orders/{order}/items` (dedicated `role:admin` group), `Idempotency-Key` required.
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

No Laravel 12 upgrade; no Google Sheets / RajaOngkir change; no historical name snapshots; no change to Package A/B closed behavior; no new role; `OrderPolicy::manageFulfillment` unchanged (only a dedicated Admin-only `OrderPolicy::addLine` ability added); no Sub-sourced or mixed-source addition; no addition outside `diproses`; no product-based grouping; no new payment calculation or financial model; no parallel fulfillment implementation; no schema change beyond the single idempotency-key migration; no WH-08 / DP-03 / DP-04 / dead-code / docs-drift work; no production migration or deployment; no frontend test framework.

## Package A / Package B invariants preserved

Package A: roles/referral, Sub Location 1:1 ownership, server-side `StockSourceResolver`, Sub reservation lifecycle, canonical multi-target and Sub lock orders.
Package B: `self_sub` self-delivery + triggers, append-only delivery verification + idempotency, split lineage, delivery-date grouping projection, R-04 operational-vs-financial projections, Admin Product/Variation CRU-no-Delete, fulfillment money rules (MAJOR-7/8/10/11), courier-fee conservation. Order-level payment truth remains `PaymentSummaryService`-derived; `OrderTotalCalculator` remains the only post-creation total writer.

## Open blockers

None. Recorded scope boundary (not a blocker): Sub-sourced/mixed-source order line addition is excluded and would require a separate Human decision.

## IMPLEMENTATION PASS — 2026-10-01

**Status:** COMPLETE and green. Independent review + DEV UAT not yet done; not production-authorized.

### What was built

- **Authority (Admin only):** `OrderPolicy::addLine(User, Order)` = `$user->isRole('admin') && $order->agent_id === $user->agent_id`; dedicated `role:admin` route group. `OrderPolicy::manageFulfillment` untouched (Package A/B authority unchanged, proven by `test_manage_fulfillment_authority_is_unchanged`).
- **Endpoint:** `POST /orders/{order}/items` (`OrderFulfillmentController::addItem`), required `Idempotency-Key`; `201` on create, `200` on same-request replay, `409` on conflicting key reuse, `422`/`403`/`404` per existing conventions. Returns canonical `OrderResource`.
- **Orchestration:** new `OrderLineAdditionService` composing existing canonical services — `OrderService::resolveLine` + `addReservedLine` (snapshot + Agent reservation + commission), `OrderFulfillmentService::assignFreshShipment` (one fresh standard pending Shipment) and `reconcileAdditionalObligation` (shared rule extracted from `increaseFulfillment`), `StockRequestService::appendItemForOrderItem` (one-per-order request extended), `OrderTotalCalculator` + `PaymentService::reconcileTotals`.
- **Eligibility:** order status `diproses` only; Agent stock only (`stock_source`/`sub_location_id` rejected; Sub-sourced orders rejected 422).
- **Idempotency:** `order_items.idempotency_key` (100, nullable) + `UNIQUE(order_id, idempotency_key)`; DB unique is the concurrency backstop.
- **Frontend:** `OrderDetailView.vue` bounded "Tambah Produk" flow gated on `role === 'admin' && status === 'diproses'` (product/variation select from `listProducts`, quantity, delivery date, reason, submit + canonical refresh); `api/orderAdjustments.ts::addOrderItem` sends one `Idempotency-Key` per submission; i18n keys added in all 4 locales.
- **Audit:** `ActivityLogger` `order_item.added` (actor, order, item, product/variation, quantity, source, delivery date, idempotency key, totals, actor role).

### Migration

- `2026_10_02_100000_add_idempotency_key_to_order_items_table.php` — additive, nullable, unique `(order_id, idempotency_key)`; historical rows NULL. No other schema change.

### Files changed

Backend modified: `app/Http/Controllers/Api/V1/Fulfillment/OrderFulfillmentController.php`, `app/Models/OrderItem.php`, `app/Policies/OrderPolicy.php`, `app/Services/Order/OrderService.php`, `app/Services/Order/OrderFulfillmentService.php`, `app/Services/Stock/StockRequestService.php`, `routes/api_v1.php`, `lang/{id,en,ar,zh}/messages.php`, `.phpunit-concurrency-actor.php`.
Backend new: `app/Services/Order/OrderLineAdditionService.php`, `app/Http/Requests/Fulfillment/AddOrderItemRequest.php`, `database/migrations/2026_10_02_100000_add_idempotency_key_to_order_items_table.php`, `tests/Feature/OrderLineAdditionTest.php`, `tests/Feature/OrderLineAdditionConcurrencyTest.php`.
Tests modified: `tests/Feature/MigrationVerificationTest.php` (rollback step 3 → 4 to cover the Package C tail migration, plus idempotency-column assertions — coverage extended, not weakened).
Frontend modified: `src/api/orderAdjustments.ts`, `src/views/OrderDetailView.vue`, `src/i18n/locales/{id,en,ar,zh}.ts`.

### Validation

- Focused: `php artisan test --filter=OrderLineAdditionTest` → 25 passed; `--filter=OrderLineAdditionConcurrencyTest` → 3 passed; `--filter=MigrationVerificationTest` → 2 passed.
- Full backend suite: `php artisan test` → **843 passed / 5918 assertions / 0 failures** (baseline 815 / 5771 / 0; +28 tests).
- Frontend: `npm run type-check` PASS; `npm run build-only` PASS (only the pre-existing >500 kB chunk-size warning).
- `git diff --check` clean.

### Known non-blocking warnings

- CLI: `Cannot load Zend OPcache - it was already loaded`, `Module "mbstring" is already loaded` (pre-existing environment noise).
- Frontend: pre-existing large-chunk bundle warning.

### Open findings / blockers

None. Recorded scope boundary (not a blocker): Sub-sourced/mixed-source order addition is excluded (separate Human decision required).

## INDEPENDENT REVIEW REMEDIATION — 2026-10-01

**Objective:** remediate the 8 evidence-backed independent-review findings (0 BLOCKER / 5 MAJOR / 3 MINOR) in ONE pass. Remediation only — no new features, no Package A/B redesign, no deploy, no production access, no `main` merge.
**Baseline:** `871a14d` (implementation) · spec authority `418bbdd`. Branch `feat/package-c-sc03`.
**Locked constraints (unchanged):** SC-03 = ADMIN ONLY + same-Agent; `OrderPolicy::manageFulfillment` untouched; Sub-sourced orders rejected.

| ID | Sev | Status | Remediation |
|---|---|---|---|
| REV-001 | MAJOR | ADDRESSED | Immutable request identity: additive `order_items.request_fingerprint` (SHA-256 of the ORIGINAL normalized request: product, nullable variation, quantity, requested date or null=order default, payment method), written once with the line in the same transaction. Replay resolves BEFORE status/date/stock validation, so it survives item mutation/split, order status change and an elapsed date. Same key + different request → 409 (also on the unique-index race path). `reason` is excluded (audit annotation; does not change stock/money/schedule). "Requested date not in the past" moved from FormRequest into the service (new creations only). |
| REV-002 | MAJOR | ADDRESSED | `StockRequestService::appendItemForOrderItem` reconciles lifecycle with the canonical formula (pending/partial/fulfilled from item sums; `fulfilled_at` cleared when demand is outstanding). History (`fulfilled_qty`) untouched; no duplicate request/item. |
| REV-003 | MAJOR | ADDRESSED | Lock order is now Order → **Stock Request** → inventory targets (canonical) → items/shipment. Matches `StockRequestProposalService::approve` (Proposal → Stock Request → Transit/Shipping/Agent rows). New `StockRequestService::lockActiveRequestForOrder`. No Package A/B service changed; no sleeps; no deadlock-catching. |
| REV-004 | MAJOR | ADDRESSED | Controller `refresh()`es the route-bound Order and reloads the same relations as `OrderController::show` before `OrderResource`. |
| REV-005 | MAJOR | ADDRESSED | New `frontend/src/utils/addLineSubmission.ts` (pure helpers + per-order `sessionStorage` pending record) + `OrderDetailView.vue` lifecycle: NEW → mint key at first submit and persist before sending; in-flight/ambiguous (status 0/408/5xx) → keep key+payload; dismiss+reopen → restore locked pending (Retry / Start new); success → clear; definitive 4xx → clear; explicit discard → clear. Backend 409 remains final guard. |
| REV-006 | MINOR | ADDRESSED | Product change resets the variation; submit only sends a variation that belongs to the selected variation-product. |
| REV-007 | MINOR | ADDRESSED | Controller validates Idempotency-Key before mutation: blank → 422, > 100 chars → 422 (error envelope, `errors.idempotency_key`); exactly 100 accepted. |
| REV-008 | MINOR | ADDRESSED | Picker uses existing `/products` `search` + `page` (`per_page=30`) with debounced search and "load more"; selected product stays selectable across searches. |

### Schema/migration delta
- `2026_10_02_110000_add_request_fingerprint_to_order_items_table.php` — additive, nullable `string(64)`; no data rewrite; historical + checkout rows NULL; rollback drops the column. `MigrationVerificationTest` rollback step 4 → 5 with column assertions.

### Files modified (remediation)
Backend: `app/Http/Controllers/Api/V1/Fulfillment/OrderFulfillmentController.php`, `app/Http/Requests/Fulfillment/AddOrderItemRequest.php`, `app/Models/OrderItem.php`, `app/Services/Order/OrderLineAdditionService.php`, `app/Services/Stock/StockRequestService.php`, `app/Services/Stock/StockRequestProposalService.php` (status sums → locking read only), `lang/{id,en,ar,zh}/messages.php`, `.phpunit-concurrency-actor.php` (`proposal-approve` op), new migration above, `tests/Feature/{OrderLineAdditionTest,OrderLineAdditionConcurrencyTest,MigrationVerificationTest}.php`.
Frontend: `src/utils/addLineSubmission.ts` (new), `src/views/OrderDetailView.vue`, `src/i18n/locales/{id,en,ar,zh}.ts`.
Docs: this checkpoint.

### Tests added
REV-001 ×3, REV-002 ×1, REV-003 ×2 (deterministic SQL lock-order assertion + real two-connection race vs `StockRequestProposalService::approve`; the race was confirmed to fail with the old order), REV-004 ×1 (unpaid/partial/fully-paid), REV-007 ×1, migration test extended. All original SC-03 tests retained unchanged.

### Validation
- Focused: `OrderLineAdditionTest` (35) + `OrderLineAdditionConcurrencyTest` (4) + `MigrationVerificationTest` (2) PASS. The REV-003 race passed 14 consecutive runs after the fix; with the early Stock Request lock removed it failed (old deadlock/ordering reproduced).
- Extra finding during validation (in REV-003 scope, "both states truthful"): under REPEATABLE READ, plain `sum()` status reconciliation used a pre-lock-wait snapshot and could miss a concurrently committed warehouse approval (SC-03 side → status `pending`) or a concurrently appended SC-03 line (approval side → `fulfilled` with demand outstanding). Fixed with locking reads of the request items in `StockRequestService::appendItemForOrderItem` and — minimal, behavior-preserving change — in `StockRequestProposalService::approve` (status computation only).
- Package A/B regression: covered by the full suite (proposal/approval, Sub reservation/ownership, Sales-Kurir-Sub, self-delivery, split/reschedule, cancellation/return, additional obligation, payment reconciliation, role authority, concurrency suites).
- Full backend: `php artisan test` → **851 passed / 6024 assertions / 0 failures** (previous 843 / 5918).
- Frontend: `npm run type-check` PASS; `npm run build-only` PASS (only the pre-existing >500 kB chunk warning). Submission-lifecycle helpers sanity-checked at runtime (restore same key, normalization, ambiguous vs definitive classification, clear).
- `git diff --check` clean.
- Not done / not claimed: DEV UAT, Human Stage Gate, production closure.

## C-SC03-UAT-PRE-001 — `crypto.randomUUID is not a function` (pre-UAT runtime defect)

- **Cause:** frontend code called `crypto.randomUUID()` directly. It is only exposed in secure contexts / newer browsers; where `crypto` exists without it, `CheckoutView` setup threw and checkout crashed.
- **Source call sites found (all replaced):** `views/CheckoutView.vue` (order idempotency key), `views/OrderDetailView.vue` (delivery-verification key), `views/dashboard/SubStockView.vue` (Sub stock request key), `utils/addLineSubmission.ts` (SC-03 add-line key). Two doc comments (`api/orders.ts`, `api/orderAdjustments.ts`) updated.
- **Fix:** one helper `frontend/src/utils/uuid.ts::secureUuid()`: native `crypto.randomUUID()` → RFC 4122 v4 from `crypto.getRandomValues()` → otherwise throws an explicit error (no Math.random/Date.now/counter fallback).
- **Semantics preserved:** key lifecycle untouched (one key per logical submission; SC-03 sessionStorage pending-submission behavior from `e111bca` unchanged). No backend change.
- **Validation:** runtime script (tsx, mocked `crypto`): A native used ✔; B getRandomValues-only → valid v4 ✔; C no secure API / no `crypto` → controlled error ✔; D 1000 distinct ✔; E/F key generation works without randomUUID and SC-03 restore-same-key / new-submission-new-key intact ✔. `npm run type-check` PASS; `npm run build-only` PASS; built `CheckoutView` chunk contains no `randomUUID` (only the helper chunk references it, guarded); `git diff --check` clean.
- **Files:** `src/utils/uuid.ts` (new), `src/utils/addLineSubmission.ts`, `src/views/{CheckoutView,OrderDetailView}.vue`, `src/views/dashboard/SubStockView.vue`, `src/api/{orders,orderAdjustments}.ts`, this checkpoint.

## DEV MAINTENANCE TOOL — `primeclassy:reset-dev-transactions` (DEV ONLY)

**Not a production procedure. Does not change Package C business scope.** Resets transactional data in the DEV database so UAT can restart clean.

- **Guards (no override flag exists):** refuses when the environment is `production` OR the connected DB is not exactly `primeclassy_dev`; requires the phrase `RESET DEV TRANSACTIONS` (`--confirm=` or interactive prompt).
- **Deletes (child → parent, from the actual FK graph, one DB transaction):** sub_stock_reservations, stock_request_{proposal_items,proposals,fulfillments,items}, inventory_cancellation_reversals, stock_requests, return_items, returns, commissions, order_item_adjustments, delivery_verifications, cod_payment_proofs, bank_transfer_verifications, order_items (cross/self FKs nulled first), shipments, order_additional_payments, payment_webhook_logs, payment_transactions, orders, stock_handovers, sub_stock_request_{items,}, stock_transfer_{items,}, stock_movements, stock_opname_{items,}, warehouse_stock_requests. FK checks are NOT disabled. AUTO_INCREMENT is reset only on these tables (DDL, after the data commit — DDL cannot be transactional).
- **Preserves:** users/roles/agents/products/variations/SKUs/fees/CMS/settings/payment+shipping config/warehouse settings/Sub Locations + ownership/media/sessions/tokens and ALL physical balances (`product_stocks.quantity_on_hand`, `product_variation_stocks.quantity_on_hand`, `warehouse_stocks.quantity`; physical stock is never reconstructed from movements). Only `quantity_reserved` is zeroed. Inside the transaction the service re-checks per-row physical checksums, master counts and Sub ownership and rolls back on any difference.
- **activity_logs decision: PRESERVED** (522 rows, mostly auth/user/product/config audit; only 11 reference Order/Shipment via subject metadata and nothing FK-links to transaction tables). **media** preserved (site logo/favicon only).
- **Files:** `app/Console/Commands/ResetDevTransactions.php`, `app/Services/Maintenance/DevTransactionResetService.php`, `tests/Feature/ResetDevTransactionsTest.php`.
- **Tests:** `ResetDevTransactionsTest` 4 passed (wrong DB refused, production refused, no force/override option, deletes transactions + preserves masters/physical stock/Sub ownership + clears reserved).
- **DEV run (2026-10-01):** env `local`, DB `primeclassy_dev`; backup `~/primeclassy-dev-backups/primeclassy-dev-pre-transaction-reset-20261001-124014.sql` (3,777,707 bytes; outside the repo, not committed). Physical stock identical before/after (product_stocks 4 rows/160, variation stocks 28 rows/530, warehouse_stocks 4 rows/135 all checksum-equal); reserved 1 → 0 / 0 → 0; every targeted table = 0; masters unchanged (users 9, roles 10, agent_profiles 1, products 10, variations 31, Sub Locations 1); Sub Location 3 still owned by user 21. Smoke: orders/dashboard/stock-requests/proposals/transfers/sub-stock-requests API → 200 with zero data; stock + products still listed; app cache cleared.

## EXACT NEXT ACTION

**Qwen performs bounded verification of C-SC03-UAT-PRE-001 before DEV UAT.**
