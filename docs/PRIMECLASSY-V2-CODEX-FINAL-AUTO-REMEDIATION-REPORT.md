# PrimeClassy V2 — Independent final audit and bounded auto-remediation

## Verdict: PASS WITH CONDITIONS

DEV-only review. Three verified F07 defects and the F03 short-verification defect have now been corrected. Owner subsequently decided: record the actual received amount and retain the shortfall as additional-payment outstanding. The financial fix implements that decision; see the follow-up evidence below. Conditions remain: provisional damaged-goods disposition needs Owner confirmation, restock stays blocked, browser UAT and exact comparison with the missing original audit artifact are not complete. This is scoped remediation evidence, not production authorization.

## Baseline and evidence boundaries

- Branch: `main`; HEAD: `d7997c4e6a32ee64bc1c3075a021037c5dfa11c2`, unchanged.
- The working tree was already substantially dirty (roughly 120 modified/untracked entries). Existing changes were preserved. This review concerns the current uncommitted implementation, not just HEAD.
- Owner-supplied remediation baseline: `docs/PRIMECLASSY-V2-F01-F09-REMEDIATION-REPORT.md`. Its stated date is 2026-11-12; the session environment date is 2026-11-11. The metadata is not execution evidence.
- Finding definitions in the active Owner request supply the F01–F09 scope. The supplied report itself says it did not have the original Codex finding artifact. An exact comparison with that missing original artifact cannot be certified; the older `PRIMECLASSY-V2-CODEX-AUDIT-REPORT.md` was not substituted.
- Read the approved REM architecture/specification, authoritative business rules, API/model docs, testing/operations docs, UAT checklist, and relevant application/test paths. No new feature scope was inferred.
- Execution evidence below is independent. The prior 173/193/190-test runs, concurrency runs, 42 frontend tests and type-check/build remain **CLAIMED PASS**, not rerun results of this audit.

## F01–F09 closure matrix

| ID | Assessment | Concrete source/test evidence |
|---|---|---|
| F01 | Source fix present; independent execution NOT TESTED | `backend/app/Services/Refund/OrderFinancialSnapshotService.php:227` resolves split lineage without reassigning frozen rows; `OrderFulfillmentService.php:291` rejects snapshot exhaustion. `PartialCancellationTest.php:232` covers refusal split, two-party return custody, ledger obligation, payout replay and invoice balance. No payout was executed against DEV data. |
| F02 | Shared guards present; independent execution NOT TESTED | `CourierService.php:361`, shipment/per-item paths at 602/701, and `OrderService.php:1036` enforce assignment/accepted receipt and expedition gates. Open refusal is checked at CourierService 606/710 and OrderService 1043. Existing regression checks unassigned, accepted-but-unassigned, and refusal-blocked bulk completion. |
| F03 | **FIXED; independently scoped-tested** | Exact-amount settlement paths now share verification → Order → transaction → obligation locking (`PaymentService.php:386`, `OrderFulfillmentService.php:468`). Existing two-connection race covers exact amount. Short verification retains the residual as additional payment under the subsequent Owner decision; see AR-04 and follow-up evidence below. |
| F04 | Source restriction present; independent execution NOT TESTED | `PaymentService.php:510` rejects partial/multiple COD undo and permits the existing single-full-collection undo. `KurirTokoPaymentLimitTest` contains F04 regressions. No new reversal mechanism was added. |
| F05 | Source fix present; independent execution NOT TESTED | `CourierService.php:238` gates paid/full goods/handover evidence; 252 checks single shipment; 703 rejects dispatching one of several expedition items. `ExpeditionDispatchGateTest` covers the per-item bypass. |
| F06 | Source fix and real-flow regression present; independent execution NOT TESTED | `CourierService.php:330` compares accepted quantity by order item, not pooled product. `PartialCancellationTest::test_f06_accepted_allocation_is_per_line_for_assignment_and_cancellation` receives stock through the real proposal/receipt path and checks another line's cancellation leaves Shipping untouched. |
| F07 | **Verified remedied technical gaps; OWNER DECISION REQUIRED for physical disposition policy** | Current locked demand/hold is checked again at settlement; restock is blocked; partial write-off replay is rejected using durable audit keys. Independently passing database tests listed below. |
| F08 | Source/API integration present; browser UAT NOT TESTED | `frontend/src/components/dispatch/KoordinatorLogisticsPanel.vue:43` loads paginated data; 154 requests fulfillment; 170 dispatches whole shipment; 272/275/283 expose readiness-gated actions, tracking and evidence. `frontend/src/api/koordinatorLogistics.ts` matches dispatch endpoints. `DispatchController.php:98` scopes expedition rows to agent and exposes operational readiness booleans. |
| F09 | Source synchronization present; independent execution NOT TESTED | `PaymentService.php:540/583/618/1017/1034` sync payment/COD undo/COD collection/refund/totals; `OrderFulfillmentService.php:513` syncs direct additional settlement. `InvoiceIssuanceService::syncAfterChange` retains the single issue marker and appends changed balance snapshots. Existing F09 regression checks one invoice and money-event history. |

Source paths abbreviated in this matrix after their first occurrence refer to their existing service directories under `backend/app/Services/`.

## Verified additional findings and remediation

### AR-01 / F07 — MAJOR: queued proposals bypass damaged hold — FIXED

Failure path: prepare proposal A for 10 and proposal B for 4 before either receipt. Receive A as accepted 6, short 3, damaged 1. Outstanding is 4 with hold 1. Proposal B previously passed settlement because it compared 4 only with remaining 4. Receiving B could consume the held reservation; the Admin approval route had the same bypass.

Fix: settlement obtains current demand rows using a locking read, then compares prepared quantity against `remaining_qty - damaged_hold_qty`. Both Koordinator receipt and Admin approval use this check. The transaction rolls back without stock movement, reservation change, or proposal approval on failure. No synthetic inventory fixture replaces the real receipt flow.

Evidence: `backend/app/Services/Stock/StockRequestProposalService.php:140` and 169; `backend/tests/Feature/StockDiscrepancyTest.php:136` checks both endpoints and conservation of Transit, Shipping, reservations, movement count, and demand/hold.

### AR-02 / F07 — MAJOR: unverified restock removes damaged hold — SAFELY RESTRICTED

Previously `restock` required only an Admin and a five-character reason; there was no persisted inspection result or verifiable physical authorization. The prior test explicitly expected this unverified operation to succeed.

Fix: domain service rejects restock with 422 before any stock/hold mutation. The regression now requires hold and physical stock to remain unchanged. This is the safety restriction explicitly authorized by the active Owner request; no inspection workflow or new damaged-goods policy was invented.

Evidence: `StockRequestProposalService.php:309`; `StockDiscrepancyTest.php:125`.

### AR-03 / F07 — MAJOR: partial write-off replay consumes another unit — FIXED

Failure path: hold 3; write off 1 twice with the same logical request. Both requests previously returned 200, reducing physical Transit twice and hold to 1. The old regression only retried after exhausting a hold of 1, so its quantity guard could not establish idempotency.

Fix: the API requires a UUID `idempotency_key`. Under the demand-item lock, a current locking read checks the item's existing `stock_request.discrepancy_resolved` audit entries. A used key returns 409, including reuse with a changed quantity. Movement, hold decrement, and the audit key are committed atomically. A fresh key can resolve the remaining hold. No schema migration was required. This relies on retaining the existing durable business audit trail.

Evidence: `StockRequestProposalController.php:84`; `StockRequestProposalService.php:319`; `StockDiscrepancyTest.php:148`. Endpoint has no frontend caller in the current tree; callers must generate one UUID per logical resolution and reuse it on retry. No two-connection test of this new resolution path was executed; serial replay/conservation is independently verified, concurrency safety is source-reviewed.

### AR-04 / F03 — MAJOR: short verified additional payment is falsely settled — FIXED after Owner decision

Source-established failure path:

1. A Kurir Toko order already has three successful regular payments and is fully paid.
2. Admin adds a Rp25.000 line, creating an additional-payment obligation and linked transfer transaction.
3. Finance verifies that transaction with actual amount Rp10.000 and an adjustment reason. `PaymentService.php:419` permits an amount below submitted; §29 explicitly preserves submitted amount and applies only actual verified money.
4. `PaymentService.php:460` unconditionally sets the entire linked obligation to `paid`; only Rp10.000 is applied to the order.
5. The order still owes Rp15.000. The obligation is no longer pending, its transaction/proof are closed, and direct settlement rejects it. The remaining sum falls into regular remaining, where three prior regular successes block another payment.

The initial pass established this from source and stopped at the policy boundary. Owner then explicitly chose: “Catat Rp10.000 dan pertahankan Rp15.000 sebagai additional payment outstanding”. Two new real-database regressions reproduced the defect before the fix (2 tests, 6 assertions, 2 failures, 17.122 s).

Implementation in `PaymentService::verifyBankTransfer`: record only actual verified money; preserve submitted transaction/proof nominal; retain the original item-linked obligation as pending for the residual and attach a new additional-payment transaction. Append a paid portion in the existing obligation table linked to the original transaction, so historical obligation amounts still sum to the original charge (including existing financial-summary projections). No new ledger or schema was added. Audit `order_additional_payment.partially_settled` records previous/received/residual amounts and linked record IDs. All changes share the existing transaction and lock boundary. Exact/full verification still closes the obligation normally.

`OrderFulfillmentService::markAdditionalPaymentPaid` rechecks the transaction linkage after acquiring the obligation lock. A stale direct-settlement request cannot apply money against a link replaced by partial verification. Fresh direct settlement collects only the residual. No concurrency harness or runner was changed; this new partial branch was not independently exercised with two connections.

New regressions exercise 25.000 → received 10.000/outstanding 15.000 → received 5.000/outstanding 10.000 → final settlement, replay rejection, actual/submitted amounts, paid+pending obligation conservation, pending cancellation protection, API upload selecting the residual transaction, invoice balance, audit evidence and exactly three regular payments. A second regression settles the residual through the direct status endpoint with a pending residual proof and verifies both proof and obligation synchronization.


## Exact changes owned by this pass

1. `backend/app/Services/Stock/StockRequestProposalService.php` — current locked demand/hold settlement check; restock safety block; durable replay guard.
2. `backend/app/Http/Controllers/Api/V1/Stock/StockRequestProposalController.php` — required UUID contract passed into the service.
3. `backend/tests/Feature/StockDiscrepancyTest.php` — real queued-proposal receipt/approval regression, partial replay regression, safer restock assertion, UUID payloads.
4. `docs/05-BUSINESS-RULES.md` — documents safety restriction/idempotency; removes an obsolete split-child legacy-fallback sentence that contradicted F01 source and §31a.
5. `docs/08-BACKEND-API.md` — discrepancy endpoint request/replay contract.
6. This report.
7. `backend/app/Services/Payment/PaymentService.php` — partial additional settlement and residual transaction/history/audit.
8. `backend/app/Services/Order/OrderFulfillmentService.php` — locked transaction-linkage recheck for direct settlement.
9. `backend/tests/Feature/AdditionalPaymentAfterRegularPaymentsTest.php` — two partial/residual regressions.
10. `docs/09-DATABASE-MODEL.md` — partial obligation/history semantics. API/business documentation above also records the Owner decision.

No migration, fixture helper, concurrency harness, actor, runner, worker, staging configuration, frontend source, or infrastructure documentation was changed by this pass. The five initial existing files above were identified by comparing their hashes with the captured pre-edit dirty-worktree baseline, rather than claiming all changes against HEAD as our own.

## Independent scoped execution evidence

Environment: existing `primeclassy-fpm`, PHP 8.3.36, MySQL host `primeclassy-mariadb`. `backend/phpunit.xml` sets `APP_ENV=testing`, `DB_DATABASE=primeclassy_testing`. `Tests\Support\TestDatabaseGuard` enforces exact environment/database, and `TestRunLock` acquires the database named lock before schema work. Container process preflight found no active test runner. Commands were serial; no Full Test was run.

Commands, from the container's backend working directory:

```sh
docker exec -e DB_HOST=primeclassy-mariadb primeclassy-fpm php vendor/bin/phpunit --do-not-cache-result tests/Feature/StockDiscrepancyTest.php
```

- First red run: 6 tests, 49 assertions, 2 failures and 1 test-setup error (nonexistent relation in the newly added test). The restock and partial replay failures each observed 200 instead of the required 422/409.
- The test query was corrected without changing application behavior. The queued-proposal-only red command below then reproduced 200 instead of 422: 1 test, 5 assertions, 1 failure, 15.800 s.

```sh
docker exec -e DB_HOST=primeclassy-mariadb primeclassy-fpm php vendor/bin/phpunit --do-not-cache-result --filter test_queued_proposal_cannot_accept_units_held_after_it_was_prepared tests/Feature/StockDiscrepancyTest.php
```

- After service/controller fixes: discrepancy class **OK, 6 tests, 62 assertions, 18.316 s**.
- Extended replay assertions were then added (changed payload with same key; new legitimate key). Final affected-area command:

```sh
docker exec -e DB_HOST=primeclassy-mariadb primeclassy-fpm php vendor/bin/phpunit --do-not-cache-result tests/Feature/StockDiscrepancyTest.php tests/Feature/CoordinatorReceiptTest.php tests/Feature/GudangItemProposalTest.php tests/Feature/WarehouseFulfillmentProposalTest.php
```

**Final F07 result: OK, 28 tests, 302 assertions, 28.595 s, zero failures/errors.** These ordinary real-database tests cover the modified discrepancy path and the shared proposal/receipt settlement area. No concurrency suite or unrelated financial/frontend sweep was run. `git diff --check`: exit 0. `php -l` inside `primeclassy-fpm` also reported no syntax errors for the modified service, controller and discrepancy test.

## Follow-up execution after the Owner residual-payment decision

The Owner explicitly authorized retaining short-verified additional payment as additional outstanding. Baseline branch/HEAD and DEV-only/no-commit restrictions remained unchanged; the existing dirty worktree was preserved.

Focused red command:

```sh
docker exec -e DB_HOST=primeclassy-mariadb primeclassy-fpm php vendor/bin/phpunit --do-not-cache-result --filter 'test_short_' tests/Feature/AdditionalPaymentAfterRegularPaymentsTest.php
```

Before the fix: 2 tests / 6 assertions / 2 failures, 17.122 s (obligation was paid instead of pending; residual direct settlement returned 422). After initial correction: 2 tests / 35 assertions / OK, 17.283 s. Regressions were then extended for API proof selection, item protection and proof synchronization. The first area run passed 26 tests / 283 assertions. A final correction retained paid portions as existing obligation-history rows so the existing financial-summary sum remains the original charge; conservation assertions were added and the same area run repeated.

Final scoped command (ordinary tests only):

```sh
docker exec -e DB_HOST=primeclassy-mariadb primeclassy-fpm php vendor/bin/phpunit --do-not-cache-result --filter 'AdditionalPaymentAfterRegularPaymentsTest|MasterV2PaymentCoreTest|test_.*additional_payment' tests/Feature/AdditionalPaymentAfterRegularPaymentsTest.php tests/Feature/MasterV2PaymentCoreTest.php tests/Feature/OrderFulfillmentTest.php
```

**Final result: OK, 26 tests / 286 assertions / 0 failures or errors, 33.222 s.** Database: exact guarded `primeclassy_testing`, PHP 8.3.36, existing container. Process preflight found no other runner. Tests were serial. New regression sources: `AdditionalPaymentAfterRegularPaymentsTest.php:238` and `:294`; residual implementation: `PaymentService.php:466`; linkage recheck: `OrderFulfillmentService.php:484`.

`php -l` in `primeclassy-fpm`: no syntax errors for the changed payment service, fulfillment service and additional-payment test. `git diff --check`: exit 0. No frontend changes, migrations, DEV publication, Full Test, concurrency runner edits, commit, push or production actions. New concurrent partial-settlement interleavings remain source-reviewed rather than independently race-tested.

## Finance, inventory, custody, and preserved scope

- Refund lineage, payment lock order, COD restriction, expedition gates and invoice synchronization were source-reviewed with their existing targeted assertions; their earlier execution counts remain attributed to the implementer.
- Additional payments remain outside the regular-payment cap; AR-04 now retains partially settled adjustments in their own existing payment mechanism.
- New F07 regression uses Gudang proposal → Koordinator receipt endpoints, tests both Admin/Koordinator settlement routes, and verifies inventory conservation on refusal. No sleeping, swallowed deadlock, stock-ID special case, or altered inventory source was introduced.
- Damaged hold remains reserved through cancellation; short demand remains re-preparable. Write-off still follows the provisional implementation: reduce Transit, lift hold, retain replacement demand/reservation. **Owner approval of that physical disposition remains outstanding.**
- Courier → Koordinator → Gudang refusal custody is preserved; SKS/self_sub and return-pickup services were not modified by this pass. Existing guards isolate those flows; independent SKS/return-pickup execution was not performed.
- Checkout reservation, payment-verification gate, no automatic reservation expiry, Admin quantity/add-line scope and immutable handover rules were not changed. This is not a new runtime certification of those existing flows.

## Test-quality review and limitations

- `ConcurrencyHarness.php:158` uses the correct disjunction for non-overlapping time intervals; analogous guards exist for other races. Different connection IDs and overlapping intervals are checked. Interval overlap alone is not proof that every vulnerable statement interleaved.
- `FinancialConcurrencyTest.php:220` uses two actors for direct additional settlement versus transfer verification and checks no deadlock, one aggregate increase, and paid obligation/transaction. It does not cover a smaller verified amount.
- `AcceptsWarehouseDemand` explicitly remains a fixture shortcut. `makeDispatchable()` establishes dispatch authorization/accepted counters; it is not independent physical-custody evidence. The real receipt/cancellation tests provide stronger conservation assertions. No helper/runner modifications were made here.
- The prior report says approximately 23 fixtures changed and lists concurrency classes not rerun after final fixture edits. Those classes were not rerun here because the Owner restricts execution to ordinary scoped tests for our corrections. Their claimed PASS is not promoted to independent PASS.
- No claim is made that every fixture edit was independently executed or that the missing original audit artifact was reviewed.

## Frontend and DEV runtime

Frontend source was not changed by this pass, so no build/publication was necessary. Existing build and served-directory bundle were checked read-only:

- `frontend/dist/assets/index-B_H3YMgl.js`
- `public_html/assets/index-B_H3YMgl.js`
- Both SHA-256: `35e5bbb72da9065731b0518cae15c53cfbad3da93af0fbedddf94e50ea7ebcd2`.
- `public_html/index.html` references that bundle; `public_html/laravel.php` exists.

This establishes filesystem build/publication consistency for that artifact only. It does not prove source reproducibility, browser execution, service-worker freshness or absence of console/network errors. No authenticated browser UAT was performed; the manual UAT checklist remains required. Prior frontend tests/type-check/build are implementer claims, not independent reruns.

## Safety, concurrent work, and recommendation

Production was not accessed or mutated. No DEV migration/data cleanup, commit, push, merge, deployment or service restart was performed. Tests used only `primeclassy_testing` through the existing guard and lock. Existing Owner modifications were preserved. Claude's parallel testing infrastructure and its documentation were not edited; no conflicting same-file change was observed in the captured file-hash comparison.

Owner next actions: approve or revise the provisional damaged-goods write-off policy and define inspection authorization before enabling restock; supply the original F01–F09 audit artifact for exact historical-baseline comparison; complete manual DEV UAT. Full Test remains Owner-exclusive. The open financial path is resolved under the Owner decision; production still requires the normal Human stage gate and separate explicit authorization.
