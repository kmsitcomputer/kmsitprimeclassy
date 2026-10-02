# Package C — Production UAT Remediation (checkpoint)

## Round 5 — independent final review of `ea5913c` = BLOCKED (bounded remediation)

**Verdict:** BLOCKED. **Branch:** `fix/package-c-production-uat`. **Starting HEAD:** `ea5913c`. **Original main:** `938100c`.

**CLOSED (do not reopen):** F01 · F03 · F04 · F05 · F06 · F07.

**OPEN being remediated now:**
- **R5-F02A (BLOCKER)** — missing RajaOngkir quote identity treated as equivalent: two nonzero carriers with the same fee but missing `courier`/`service` produce the same signature (both null) and are silently deduped.
- **R5-F02B (BLOCKER)** — cross-date OpenRoute quote conflicts overwritten: `reconcileOrder()` only validates conflicts inside one date group, then `redistributeKurirOnlineShippingFee()` writes a new allocation across multiple groups and can normalise `10000/10000/10000`, hiding original conflicting provenance.
- **R5-CLASS-01 (MAJOR)** — null/unknown provider bypasses shipping-method classification (`usesEkspedisi()` is false and `usesKurirOnline()` is false → reschedule proceeds).
- **OBSERVATION** — monetary DECIMAL converted through float (`(int) round(((float) $x) * 100)`) before cents conversion.

**Human-locked shipping classification matrix (authoritative, no implicit fallback):**
| canonical code | classification | reschedule |
|---|---|---|
| `rajaongkir` | EKSPEDISI | DENY (422 before mutation) |
| `openroute` | KURIR_ONLINE | ALLOW while lifecycle mutable; even integer allocation of the unchanged Order fee |
| `free` | FREE | ALLOW while lifecycle mutable; every active group must be zero, Order fee stays zero |
| `pickup` | PICKUP | DENY (422 before mutation) |
| null / empty / unknown / legacy / mixed | UNKNOWN | DENY / FAIL CLOSED (422 before mutation) |

**Implementation plan / exact order:**
1. One canonical classifier `App\Services\Shipping\ShippingMethodClassifier` (`rajaongkir→EKSPEDISI`, `openroute→KURIR_ONLINE`, `free→FREE`, `pickup→PICKUP`, else `UNKNOWN`; only KURIR_ONLINE/FREE allow a date change). `ShipmentGroupingService::shippingClassification(Order)` resolves the order from the canonical provider codes of its relevant shipments; mixed/empty/unknown → UNKNOWN.
2. **R5-F02A** — provider-aware carrier signature: `rajaongkir` requires complete normalized `provider_meta.courier` + `.service`; missing/empty identity gets a per-shipment `incomplete_identity` marker so two such carriers always conflict while a single legacy carrier is preserved.
3. **R5-F02B** — OpenRoute quote **provenance** signature (pricing rule `price_per_km`, `minimum_distance_km`, `minimum_charge`, `chargeable_distance_km` + `rate_per_km`/`distance_km`/`routing_profile`), DELIBERATELY excluding the persisted fee (which an equal allocation rewrites) so a previous canonical allocation is never re-read as a new independent quote. A new `preflightShippingFeeChange()` validates ALL fee evidence across groups BEFORE any date/membership mutation; conflicting/incomplete provenance → 422.
4. **FREE** — `preflight` asserts Order fee + every snapshot zero; anomalous nonzero → 422 unchanged.
5. **Money** — exact decimal-string → integer minor units (`toMinorUnits`), never float; allocation unchanged in whole-rupiah domain.
6. Bounded frontend guard: hide reschedule unless provider is `openroute`/`free`.
7. Round-5 regression suite + adapt Round-4 fixtures to realistic OpenRoute provenance / explicit rajaongkir codes; run focused, concurrency ×3, full backend, frontend; commit; STOP for one narrow independent verification of the Round-5 delta only.

**Round-5 implementation result (awaiting one narrow independent verification of the delta only):**
- **R5-CLASS-01** — new `App\Services\Shipping\ShippingMethodClassifier` (`rajaongkir→EKSPEDISI`, `openroute→KURIR_ONLINE`, `free→FREE`, `pickup→PICKUP`, anything else → `UNKNOWN`; only KURIR_ONLINE/FREE allow a date change). `ShipmentGroupingService::shippingClassification(order)` resolves from the canonical provider code of the order's relevant shipments; no relevant shipment / null / empty / unknown / mixed → UNKNOWN. `rescheduleItemDeliveryDate()` denies 422 **before any mutation** (per-classification localized message; `pickup`/`unknown` messages added in all 4 locales).
- **R5-F02A** — provider-aware carrier signature: a nonzero `rajaongkir` quote requires complete normalised `provider_meta.courier` **and** `service`; a missing/empty identity carries a per-shipment `incomplete_identity` marker so two such carriers always conflict while a single legacy carrier is preserved (never guessed by price or shipment id). `openroute` compares provenance; a nonzero fee under `free`/`pickup`/unknown code is never equivalent.
- **R5-F02B** — OpenRoute quote **provenance** signature (pricing `price_per_km` / `minimum_distance_km` / `minimum_charge`, `chargeable_distance_km`, `routing_profile`, distance) deliberately **excludes** the persisted fee, so a previous canonical equal-allocation never masquerades as a new independent quote. New `preflightShippingFeeChange()` runs **before** any date/membership mutation (Order lock first) and validates every fee snapshot a redistribution would overwrite; conflicting/incomplete provenance or a committed nonzero fee → 422.
- **FREE** — preflight asserts `orders.shipping_fee_amount` and every active snapshot are zero; an anomalous nonzero fee fails closed 422 unchanged.
- **Money** — exact decimal-string → integer minor units (`toMinorUnits`/`decimalFromMinor`); the whole-rupiah allocation domain is unchanged; no float anywhere in the path.
- **Tests:** new `PackageCProductionUatRound5Test` (39 passing incl. data providers): full classifier matrix, F02A identity matrix (both ID orderings, both source/target), regroup exit code 1, F02B full/partial/both-orderings/incomplete/identical-provenance, free zero + anomalous fail-closed, committed-fee protection, exact money (`0`, `0.01`, `1`, `1.01`, `30000.01`, `30000/30001/30002`, `9999999999.99` boundary) with conservation, idempotency. Round-2/3/4 fixtures adapted to explicit canonical provider codes (no assertion weakened except the two obsolete "fee stays on one carrier across a paid reschedule" cases, re-expressed to the Human even-allocation). Full backend **975 passed / 7024 assertions / 0 failures** (baseline 936); concurrency suites ×3 green; frontend `npm run type-check` PASS; normal `dist/` build still hits the pre-existing `.well-known/acme-challenge` EACCES, clean temporary-outDir build PASS; `git diff --check` clean.
- **Docs updated:** BUSINESS-RULES §11/§21, ARCHITECTURE §7, MASTER-SYSTEM §11.

**Exact next action:** ONE narrow independent Codex verification of the round-5 delta only. No deployment; PRODUCTION TOUCHED: NO; DEPLOYED: NO; PRODUCTION MIGRATION: NO; PRODUCTION REGROUP: NO; Package C remains NOT production closed.

---

## Round 4 — independent Codex review of `7edc877` = BLOCKED (FINAL bounded remediation)

**Codex verdict:** BLOCKED. **Branch:** `fix/package-c-production-uat`. **Starting HEAD:** `7edc877`. **Original main:** `938100c`.

**CLOSED (do not reopen):** F01 demand-counter concurrency · F03 tracking/resi commitment · F05 canonical Order-first lock order · F06 regroup failure exit status.

**OPEN being remediated now:**
- **F02 (BLOCKER)** — shipping quote equivalence incomplete. `feeCarrierSignature()` ignores `provider_meta` courier/service, so `25,000 JNE/REG` and `25,000 J&T/EZ` are treated as equivalent and one is discarded.
- **F04 (MAJOR)** — quantity adjustment can mutate historical shipment contents: `adjustItemQuantity()` has no shipment-level historical gate, so a `diproses`/stale item on a delivered/proof/verified/in-transit shipment can change `3 → 2` (quantity, StockRequest demand, reservation, order total).
- **F07 (MINOR)** — approval replay response can expose a stale related `OrderItem`: `loaded()` current-reads proposal/items/StockRequest but `orderItem` is still loaded via a REPEATABLE READ consistent read, so `order_quantity`/`delivery_date` can be stale.

**NEW Human-approved business rule (authoritative):**
- **Ekspedisi** (`shipping_provider_code = 'rajaongkir'`, the canonical domain representation): Admin **cannot** change the requested delivery date → domain 422 **before any mutation**.
- **Kurir Online free** (`'openroute'`, `Order.shipping_fee_amount = 0`): date changes allowed while lifecycle permits; all active group snapshots 0.
- **Kurir Online paid** (`'openroute'`, fee > 0): date changes allowed; `Order.shipping_fee_amount` unchanged; the total is distributed **evenly across all active delivery-date groups** using deterministic integer arithmetic (`base = intdiv(total, N)`, first `total % N` groups get `base + 1`), ordered by `requested_delivery_date ASC` then shipment id. Postcondition: `SUM(active group snapshots) == Order.shipping_fee_amount` exactly. Never floating point.
- **NEW valid redistribution ≠ historical repair:** the even-allocation rule must never normalise ambiguous historical quotes; conflicting historical carriers stay **fail closed** (human decision required).

**Implementation plan / exact order:**
1. **F04** — add the shipment-level historical gate (`ShipmentGroupingService::isHistorical`) at the top of `adjustItemQuantity()` (increase AND decrease), before any mutation.
2. **F02** — extend `feeCarrierSignature()` with normalised `provider_meta.courier` + `.service` (material service identity), keeping the existing fee/rate/provider/distance fields.
3. **New rule** — `ShipmentGroupingService::usesEkspedisi()/usesKurirOnline()` + `redistributeKurirOnlineShippingFee()`; wire the Ekspedisi 422 gate + redistribution into `rescheduleItemDeliveryDate()` (full reschedule and partial split); 2 new localized messages; bounded frontend guard hiding the reschedule action for `shipping_provider === 'rajaongkir'`.
4. **F07** — `loaded()` also locking-reads the related `OrderItem` graph.
5. Focused + deterministic concurrency tests; full backend; frontend; commit; STOP for one final Codex review.

**Round-4 implementation result (awaiting the one final independent review):**
- **Human rule** — `ShipmentGroupingService::usesEkspedisi()/usesKurirOnline()` (canonical server snapshot `shipping_provider_code`); `rescheduleItemDeliveryDate()` rejects Ekspedisi 422 before any mutation; `redistributeKurirOnlineShippingFee()` distributes the unchanged Order fee evenly across active delivery-date groups (integer rupiah: `base = floor(total/N)`, first `total % N` by `requested_delivery_date ASC` take `base + 1`; sub-rupiah remainder rides the first group so the cent is conserved). Committed/historical groups are never rewritten — a redistribution that would require it is 422. 2 new localized messages (4 locales) + bounded frontend guard hiding reschedule for `rajaongkir`.
- **F02** — `feeCarrierSignature()` now includes normalised `provider_meta.courier` + `.service` (material service identity); incidental metadata excluded. `25,000 JNE/REG` vs `25,000 J&T/EZ` now fail closed.
- **F04** — shipment-level `isHistorical` gate added to `adjustItemQuantity()` (increase AND decrease), before any mutation.
- **F07** — `loaded()` locking-reads the related `OrderItem` graph so `order_quantity`/`delivery_date` are current.
- **Tests:** new `PackageCProductionUatRound4Test` (24) + `PackageCProposalReplayConcurrencyTest` test #3 (OrderItem projection). New tests fail on `7edc877` (18 failed / 9 passed; F07 genuinely stale `order_quantity` 10 vs 12 when isolated). Concurrency suites repeated 3× green. Full backend **936 passed / 6775 assertions / 0 failures** (baseline 911/6644); `npm run type-check` PASS; clean temporary-outDir build PASS (normal `dist/` build still hits the pre-existing `.well-known/acme-challenge` EACCES).
- **Docs updated:** BUSINESS-RULES §11/§16/§21 (new delivery-date rule), ARCHITECTURE §7, MASTER-SYSTEM §2/§40/§41, OPERATIONS §4.

**Exact next action:** ONE independent Codex FINAL review of branch `fix/package-c-production-uat` (round-4 delta). No deployment; PRODUCTION TOUCHED: NO; DEPLOYED: NO; PRODUCTION MIGRATION: NO; PRODUCTION REGROUP: NO; Package C remains NOT production closed.

---

## Round 3 — independent Codex review of `0f26fae` = BLOCKED

**Codex verdict:** BLOCKED. **Branch:** `fix/package-c-production-uat`. **Starting HEAD:** `0f26fae`. **Original main:** `938100c`. **Previous remediation base:** `a583b51`.

**CLOSED (do not reopen without necessity):** F01 demand-counter concurrency · F03 tracking/resi commitment · F05 canonical lock order/deadlock · F06 regroup failure exit status.

**OPEN being remediated now:**
- **F02 (BLOCKER)** — conflicting historical shipping-fee snapshots. Target = first nonzero carrier by ID; a second, different nonzero carrier is deleted, so `10,000 + 25,000` can become `10,000` while the canonical Order fee is `25,000`. Multiple nonzero snapshots must be classified, not guessed.
- **F04 (MAJOR)** — historical shipment **contents** can still change: a delivered/proof/verified shipment with inconsistent item status lets membership change (`2 → 1` items) or a partial split change represented quantity (`3 → 2`). Shipment-level evidence must win over stale item status.
- **F07 (MINOR)** — concurrent approval replay returns a stale response: after A commits (proposal/item `approved`), B's replay returns `pending/pending` (eager/`fresh()` REPEATABLE READ snapshot), while stock effects stay correct.

**Remediation plan / exact order (F04 first, so immutable historical shipments are rejected before any fee-carrier reconciliation):**
1. **F04** — add `ShipmentGroupingService::isHistorical()` (delivered / shipped_at / status picked_up-in_transit-delivered-failed / proof / delivery verification). In `OrderFulfillmentService::rescheduleItemDeliveryDate()` gate BOTH the full reschedule and the partial split on it (before any mutation); keep the round-2 committed (assigned/tracked, non-historical) boundary.
2. **F02** — preflight every consolidation (merge group / `releaseIfEmpty`) and classify carriers: none → safe; exactly one → preserve; multiple → dedupe ONLY if numeric value **and** fee metadata are all equivalent; otherwise **fail closed** (`ApiException` 422 → command marks the order failed / interactive rolls back atomically).
3. **F07** — `StockRequestProposalService::loaded()` re-reads the proposal, proposal items and their request items with **locking reads** (order-independent current committed state), so a replay response agrees with the DB while effects remain exactly-once.

**Round-3 implementation result (awaiting independent re-review):**
- **F04** — `ShipmentGroupingService::isHistorical()` (delivered / shipped_at / status picked_up-in_transit-delivered-failed / proof / delivery verification). `rescheduleItemDeliveryDate()` gates **both** the full reschedule and the partial split on it, before any mutation, using shipment-level evidence (inconsistent item status cannot bypass). The assigned/tracked (pre-delivery) boundary is preserved: single-item redate rejected, a sibling may still move off untouched. 18 focused tests incl. 4 lifecycles × shared/single × full/partial, plus assigned/tracked boundary.
- **F02** — `ShipmentGroupingService` classifies fee carriers by a signature (fee + rate + provider id/code + distance). `assertCarriersConsistent()` runs **before** any merge (`reconcileOrder`) or shell deletion (`releaseIfEmpty`): none → safe; exactly one → preserved (deterministic lowest-id carrier survives); multiple only if all signatures equal; otherwise `ApiException` 422 → interactive 422 / command marks the order failed, transaction rolls back. 8 focused tests incl. conflict values, conflicting metadata, both id orderings, releaseIfEmpty, split, regroup, repeated apply.
- **F07** — `StockRequestProposalService::loaded()` re-reads the proposal, its items and their request items with **locking reads**, so a replay response equals the committed DB graph while effects stay exactly-once. 2 deterministic held-lock tests (single line, multi-line projection).
- **Strengthened (bounded):** F05 races now assert no DB error at all (not merely ≠ 1213), plus success outcomes for the deterministic races; new `assertCleanDomainOutcome` helper. No harness rewrite.
- **Tests:** new `PackageCProductionUatRound3Test` (18) and `PackageCProposalReplayConcurrencyTest` (2). All three new/affected concurrency suites repeated green. Full backend **911 passed / 6644 assertions / 0 failures** (baseline 891/6474); `npm run type-check` PASS; clean temporary-outDir build PASS (normal `dist/` build still hits the pre-existing `.well-known/acme-challenge` EACCES). New tests fail on `0f26fae` (15 failed / 5 passed) and pass after.
- **Docs updated:** BUSINESS-RULES §11/§16/§21, ARCHITECTURE §7, MASTER-SYSTEM §2/§40/§41, OPERATIONS §4.

**Exact next action:** independent Codex re-review of ONLY this round-3 delta (`F02`/`F04`/`F07`) plus regression verification. No deployment; PRODUCTION TOUCHED: NO; DEPLOYED: NO; Package C remains NOT production closed.

---

## Round 2 — independent Codex review of `a583b51` = BLOCKED

**Codex verdict:** BLOCKED. **Branch:** `fix/package-c-production-uat`. **Starting HEAD:** `a583b51`. **Baseline:** `main @ 938100c`.

**Findings being remediated (F01–F06):**
- **F01 (BLOCKER)** — `StockRequestProposalService::approveItems()` reads the StockRequestItem demand from a stale (eager/non-locking) snapshot; a concurrent quantity adjustment can corrupt `requested/fulfilled/remaining`.
- **F02 (BLOCKER)** — `ShipmentGroupingService::carryFeeSnapshot()` only carries when target `=== null`, but the column is `NOT NULL DEFAULT 0`, so a nonzero `shipping_fee_snapshot` (25,000) is lost on regroup/reschedule.
- **F03 (MAJOR)** — `ShipmentGroupingService::isMutable()` does not treat an assigned tracking/resi identity as operational commitment → a pending shipment with `tracking_number` can be deleted/rewritten by regroup.
- **F04 (MAJOR)** — `OrderFulfillmentService::rescheduleItemDeliveryDate()` silently redates a committed (courier-assigned) single-item shipment.
- **F05 (MAJOR)** — lock inversion / real 1213 deadlock: `reconcileOrder()` = Order → Shipment → OrderItem vs `rescheduleItemDeliveryDate()` = OrderItem → Order.
- **F06 (MINOR)** — `RegroupShipments::handle()` prints per-order errors but always returns SUCCESS.

**Locked domain decisions (do not change):** order demand ≠ fulfillment proposal (rejecting a proposal item never cancels order demand); shipment grouping = ORDER + `requested_delivery_date`; `requested_delivery_date` remains the only scheduling truth; payment stays Order-level; no new authority.

**Remediation plan / exact order:**
1. **F05 foundation** — one canonical **Order-first** lock discipline for every order-scoped shipment/fulfilment writer: quantity adjustment, reschedule, split, regroup (`reconcileOrder`), Package C add-line/checkout grouping, courier lifecycle (`updateShipmentStatus`, `assignCourier`), delivery verification. Child rows then locked deterministically.
2. **F01** — `approveItems()` (and `rejectItems()`) acquire the Order lock first, then Proposal → StockRequest → **locking re-read** of the StockRequestItems before any validation/calculation/mutation; `remaining = requested − fulfilled` holds after every transaction.
3. **F03** — `isMutable()` also requires an empty `tracking_number` (assigned resi = committed).
4. **F04** — rescheduling an item whose current shipment is committed and carries only that item is rejected atomically with a clear domain 422; committed-with-siblings still moves the item to its own mutable shipment without touching the committed one.
5. **F02** — explicit shipping-fee conservation: carry a nonzero snapshot to the surviving shipment on merge/delete (never overwrite an already-nonzero target → no double count, never lose a nonzero source); regroup target = the fee carrier.
6. **F06** — track processed/changed/skipped/failed, print a summary, return nonzero when any order failed.

**Locked invariants:** F01–F06 must not weaken Package A/B/C invariants; migration `2026_10_03_100000` stays as-is; the "cannot reduce below warehouse-fulfilled" 422 stays.

**Round-2 implementation result (awaiting independent re-review):**
- **F05** — one canonical Order-first lock discipline: quantity adjustment + reschedule (`OrderFulfillmentService`), regroup/`assignItemToDateGroup` (`ShipmentGroupingService`), courier lifecycle (`CourierService::updateShipmentStatus`, `assignCourier`), delivery verification (`DeliveryVerificationService`), and proposal approve/reject now take the **Order row lock first**, then children. Deterministic query-log assertion: the first locking read of each writer is `orders`.
- **F01** — `StockRequestProposalService::approveItems()`/`rejectItems()` lock Order → Proposal → Stock Request and **re-read the demand items and proposal-item decisions with locking reads**; `remaining = requested − fulfilled` holds after every transaction. Deterministic held-lock test reproduces Codex's interleaving and fails on `a583b51` (`remaining` 4 vs 6).
- **F03** — `ShipmentGroupingService::isMutable()` also requires an empty `tracking_number` (assigned resi = committed).
- **F04** — rescheduling an item alone on a committed shipment is rejected 422 atomically; committed-with-siblings still moves the item onto its own new mutable shipment untouched.
- **F02** — nonzero shipping-fee snapshot conservation (`carryFeeSnapshot` transfers only a nonzero source to a zero target; regroup target = the fee carrier). Numeric tests assert exact totals (25,000), one carrier, no loss/double count.
- **F06** — `shipments:regroup` tracks processed/changed/skipped/failed, prints a summary and returns non-zero when any order failed (per-order rollback preserved); tested with a stubbed failing order.
- **Tests:** new `PackageCProductionUatRemediationTest` (11) and `PackageCShipmentConcurrencyTest` (9, incl. 3 deterministic forced-interleaving + 6 real two-connection races); new harness `runServiceActorAgainstHeldLocks` + actor ops `shipment-regroup`/`reschedule`/`courier-ship`. Full backend **891 passed / 6474 assertions / 0 failures**; `npm run type-check` PASS; clean temporary-outDir build PASS (the normal `dist/` build still hits the pre-existing `.well-known/acme-challenge` EACCES infrastructure condition — not a product failure).
- **Docs updated:** ARCHITECTURE §7 lock table, BUSINESS-RULES §11/§16/§21, OPERATIONS §4/§8, MASTER-SYSTEM §2/§40/§41.

**Exact next action:** independent Codex re-review of branch `fix/package-c-production-uat` (HEAD = remediation commit). No deployment; PRODUCTION TOUCHED: NO; DEPLOYED: NO; Package C remains NOT production closed.

---

# Package C — Production UAT Remediation (checkpoint — round 1, historical)

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
