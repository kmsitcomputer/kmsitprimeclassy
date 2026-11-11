# PrimeClassy V2 — Codex independent audit and local remediation

Date: 2026-11-08 (Asia/Jakarta). Branch: `fix/primeclassy-unified-uat-remediation`. Baseline and unchanged HEAD: `e738d0fdef858cb6854f4c88b0db295f883b6133`.

## A. Verdict

**PASS WITH CONDITIONS — manual browser/PWA UAT remains required.** Across the specified scoped invocations and bounded reruns, 230 distinct backend tests have passing evidence; this is a deduplicated coverage count, not a claim that the initial failing invocations passed. Targeted audit of the uncommitted implementation and direct dependencies; no repeated repository-wide RECON. Owner's attached request explicitly authorized assignment-only ordinary couriers, creator-only SKS self-pickup, return assignment UI, and bounded local fixes. This supersedes the old claim rule. No production access, commit, push, merge, or full backend suite was performed.

## B. Docker environment

- Compose project: `primeclassy-local-remediation`; reproducible files in `deploy/local-remediation/`.
- Existing Compose at `../../infra/primeclassy/compose.yml` was inspected. It uses existing application databases/network, so it was not reused for tests. No unrelated containers, networks or volumes were changed.
- PHP: `kmsit-php:8.3-fpm`, derived as `primeclassy-local-remediation-php:8.3` to add GD JPEG. Actual PHP **8.3.36**, Laravel **11.56.1**. The existing base Dockerfile and extensions were inspected. The build context excludes everything except its Dockerfile.
- MariaDB: `mariadb:10.11`, actual **10.11.19**. New named volume `primeclassy-local-remediation_db`; empty `primeclassy_dev` and `primeclassy_testing` created on first boot. Only `primeclassy_testing` was migrated/reset by the guarded test harness. The local user has access only to these databases. `SELECT VERSION(); SHOW DATABASES;` verified the connection and database names.
- Node: `node:24-alpine`, actual **24.22.2**, npm **11.19.1**, locked Vite **8.2.2**. `npm ci --no-audit --no-fund --fetch-retries=1 --fetch-timeout=60000` completed: **595 packages**. Initial install stalled; an offline retry correctly failed `ENOTCACHED`; bounded online retry passed. Lockfiles were not changed.
- Composer: existing read-only vendor tree compared against `composer.lock`: **126 packages, zero version mismatches**. No host PHP/Node installation was substituted.
- Dedicated network `primeclassy-local-remediation_default`; dedicated `storage`, `cache`, `node_modules`, `node_work` volumes. Backend source/vendor read-only. Backend `.env` masked by empty file; frontend source copied without `.env*`, host node_modules, dist or TS caches. No application credentials or production data used.
- Redis unnecessary. Base Compose publishes no ports. Optional `uat.yml` binds only `127.0.0.1:18173` and `127.0.0.1:18080`.
- Services: database healthy; PHP and Node running with the optional UAT profile active. The disposable local database has seeded taxonomy and a synthetic UAT owner; no production records were imported. PHP/Node have no Docker healthcheck configured; successful runtime commands, tests and build are the verification evidence.

## C. Audit findings and remediation

| ID | Finding / evidence | Remediation | Status |
|---|---|---|---|
| RET-01 / MAJOR | `CourierDashboardController::returns` filtered parent returns by own assignment but eager-loaded **all** sibling items. | Apply ownership, pending/approved, and physical-awaiting filters to eager-loaded items too; multi-item regression. | Fixed, tested |
| RET-02 / MAJOR | `ReturnService::assignPickupCourier` locked rows but allowed another dispatcher to silently overwrite a completed assignment write. | Compare locked `courier_id` with `expected_courier_id`; stale replacement returns 409. Same-assignee replay remains idempotent. Two-connection deterministic regression proves one winner, one 409, one audit event. | Fixed, tested |
| RET-03 / MAJOR | Assignment endpoint existed without a coordinator-facing workflow. | Existing Dispatch gets Penugasan Retur tab; operational item list, pagination, current executor/status, eligible selection, assignment and feedback. Uses existing PATCH endpoint. | Implemented; browser UAT pending |
| RET-04 / MAJOR | Return assignment accepted coordinator executors, but return processing routes are `role:kurir` only. Service lacked an independent assigner-role guard. | Return executors must be active same-branch `kurir` accounts; `for_returns=1` narrows existing courier selector. Add service assigner-role, executor-active and branch checks. | Fixed, tested |
| RET-05 / HIGH | Pickup/confirm returned raw `ReturnItem`, including monetary refund attributes, despite operational role. | Return only operational action fields. No refund/payment writer changed. | Fixed; existing action tests passed |
| PAY-01 / HIGH | `protectedFile.ts` checked AxiosError, while `client.ts` converts failures into `ApiError`; 401/403/404 became generic errors. | Recognize canonical ApiError; test 401/403/404/network mapping and authenticated-client blob request. | Fixed, tested |
| PAY-02 / MINOR | Fetch could resolve after closing/unmounting viewer and create an unreleased object URL. | Request generation invalidation; discard stale results, release on close/unmount. Tests cover delayed close and normal preview/download/revocation. | Fixed, tested |
| PAY-03 / MAJOR | Dispatch history used private API viewer for public shipment media URLs, causing `/storage/...` to be requested through `/api/v1`. | Preserve public delivery-proof link mechanism. Restrict private-file helper to canonical `/api/v1/payment-proofs/{id}`; discard supplied host. | Fixed; build verified, browser UAT pending |
| USER-01 / MINOR | Reassignment candidate search did not explicitly narrow super-admin candidates to the target user's branch and could retain selection across search changes. | Pass target branch and active status; invalidate candidate selection/request on new search. Main search also invalidates in-flight results immediately. | Fixed; type-check/build verified |
| INV-01 / MEDIUM | Blade forced every custom logo to 48×48, distorting non-square images. | Auto dimensions with maximum height/width; official embedded fallback preserved. | Fixed; PDF tests passed; visual UAT pending |
| TEST-01 | Existing tests retained ordinary self-claim assumptions or assigned a second shipment after pickup had changed the aggregate order state. | Update only affected positive fixtures and assertions; assign both deliveries before pickup; preserve negative authorization assertions. | Fixed, scoped re-tested |

Confirmed working implementation: assignment-only ordinary courier list/mutations; creator-only `self_sub` predicate with NULL creator denied; same-branch enforcement; multi-shipment projection; operational delivered history; stable user pagination/filter totals; private proof backend authorization; official default invoice logo and authorized custom precedence.

## D. Implementation changes

Codex directly changed:

- Backend controllers: `CourierDashboardController.php`, `DispatchController.php`, `ReturnController.php`; routes `api_v1.php`.
- Return service, return-model/resource comments and KPI comments: `ReturnService.php`, `ReturnItem.php`, `CourierReturnResource.php`, `DashboardKpiService.php`.
- Invoice template: `backend/resources/views/pdf/invoice.blade.php`.
- Frontend: `api/dispatch.ts`, `api/protectedFile.ts`, `ProtectedFileViewer.vue`, `DispatchView.vue`, `UserManagementView.vue`.
- Regression/compatibility tests: `CourierSystemTest.php`, `DispatchAssignmentConcurrencyTest.php`, `.phpunit-concurrency-actor.php`, `InvoiceTest.php`, `MasterV2PaymentCoreTest.php`, `R04OrderProjectionTest.php`, `ShipmentReceiptPrintTest.php`, `ReturnPickupScheduleTest.php`, `CourierFeeSplitTest.php`, `OrderDetailFeaturesTest.php`, `ReportSystemTest.php`, `SalesKurirSubSelfDeliveryTest.php`, plus two focused frontend spec files.
- Added isolated Compose, Dockerfile, initialization SQL, empty env mask, frontend sync helper, optional UAT override and local instructions in `deploy/local-remediation/`.

Other pre-existing Sonnet source/docs/assets remain in the combined diff and were audited within scope. No migrations added. No identity backfill, invoice numbering, financial calculations, stock writer, role hierarchy or order-wide mutation permissions changed.

## E. Security evidence

- `CourierAssignmentIsolationTest`: assigned-only listing and status filters; unassigned/direct-other mutation denied; revoked assignment removed; photo required; generic detail/invoice denied; own SKS creator allowed; another creator or NULL denied; cross-branch SKS denied; repeated pickup preserves ownership.
- `R04OrderProjectionTest`: financial restrictions and multi-shipment sibling projection preserved; unassigned queue now asserted absent.
- `CourierSystemTest`: return assignment role/branch checks, stale replacement conflict, correct assigned courier visibility, old courier access removal, lifecycle rejection after confirmation, sibling-item isolation, operational action flow and audit logging.
- `DispatchAssignmentConcurrencyTest` selected regression: real independent MariaDB connections overlap at the return-row lock. First assignment commits; second receives business status 409; exactly one assignment audit entry.
- `CoordinatorDeliveredHistoryTest`: branch scope, executor attribution including SKS, date/courier filters, pagination, no financial/contact fields and unchanged read-only detail permissions.
- `MasterV2PaymentCoreTest`: explicit courier/gudang/coordinator 403 for private files; authorized missing file 404; existing owner/financial/sales scope and legacy proof behavior retained.
- Invoice reference/runtime SHA-256 both `e2d724eef81645aee42328cc89b8f1a5ff3cd140cf92e70731a00fde26b552d3`; actual offline PDF embedding tested; foreign custom media not embedded.

Legacy orders with NULL `placed_by_user_id` remain ineligible for SKS self-pickup. No creator inferred or backfilled.

## F. Scoped backend verification

All commands use the isolated PHP container and exactly `primeclassy_testing`, one runner at a time. Exact prefix:

```sh
docker compose -f /tmp/primeclassy-local-remediation/compose.yml exec -T php php vendor/bin/phpunit --do-not-cache-result
```

After the same project configuration was copied into the repository, final commands used:

```sh
docker compose -f deploy/local-remediation/compose.yml exec -T php php vendor/bin/phpunit --do-not-cache-result
```

Append the following exact arguments (each row is a separate invocation):

| Arguments | Result / remediation |
|---|---|
| `tests/Feature/CourierAssignmentIsolationTest.php` (initial image, without `--do-not-cache-result`) | 9 tests, 60 assertions, 2 JPEG fixture errors; fixed derived Docker image. A read-only PHPUnit cache warning was removed by subsequent `--do-not-cache-result`. |
| `tests/Feature/CourierAssignmentIsolationTest.php tests/Feature/CoordinatorDeliveredHistoryTest.php tests/Feature/CourierSystemTest.php tests/Feature/UserManagementTest.php tests/Feature/InvoiceTest.php tests/Feature/MasterV2PaymentCoreTest.php tests/Feature/SalesKurirSubSelfDeliveryTest.php tests/Feature/DispatchTest.php` | 127 tests, 961 assertions: 1 missing reference-mount error + 4 outdated fixture/assertion failures. Corrected mount, assignment ordering, queue assertions. All unaffected tests passed. |
| `tests/Feature/CourierSystemTest.php tests/Feature/InvoiceTest.php tests/Feature/SalesKurirSubSelfDeliveryTest.php tests/Feature/MasterV2PaymentCoreTest.php` | **77 tests, 636 assertions, PASS**, 58.607s. |
| `tests/Feature/R04OrderProjectionTest.php tests/Feature/ShipmentReceiptPrintTest.php tests/Feature/ReturnPickupScheduleTest.php tests/Feature/CourierFeeSplitTest.php tests/Feature/OrderDetailFeaturesTest.php tests/Feature/ReportSystemTest.php` | 100 tests, 758 assertions: 1 repeated assignment fixture error and 1 unassigned SKU-projection fixture failure; other tests passed. |
| `tests/Feature/CourierFeeSplitTest.php tests/Feature/ReportSystemTest.php` | **46 tests, 390 assertions, PASS**, 41.708s. |
| `tests/Feature/DispatchAssignmentConcurrencyTest.php --filter='test_concurrent_return_assignments_have_one_winner_and_one_conflict\|test_sc03_wins_then_assignment_waits_for_added_demand_fulfillment'` | **2 tests, 42 assertions, PASS**, 59.493s. |

A new missing-default-logo regression initially exposed test-setup errors (`useResourcePath` is unavailable; subsequently DomPDF needed its real public path retained). These were test-fixture issues, corrected without weakening production fallback behavior. Final focused regression: `tests/Feature/InvoiceTest.php --filter=test_missing_default_asset_keeps_company_text_and_pdf_rendering` → **1 test / 3 assertions PASS**, 15.929s. Final full scoped class invocation `tests/Feature/InvoiceTest.php` → **13 tests / 54 assertions PASS**, 22.667s (`invoice-suite-final.log`).

An additional late scoped invocation, `tests/Feature/InvoiceTest.php tests/Feature/CourierAssignmentIsolationTest.php tests/Feature/CoordinatorDeliveredHistoryTest.php`, ran **29 tests / 194 assertions** with only the new missing-asset test-fixture error; courier isolation and coordinator history passed.

Logs: `/tmp/primeclassy-local-remediation/` (`core-tests.log`, `core-retest.log`, `compat-tests.log`, `compat-retest.log`, `concurrency-tests.log`, `final-backend.log`, invoice retest logs). No Full Test or equivalent comprehensive run.

## G. Frontend verification

Executed inside isolated Node container:

```sh
deploy/local-remediation/sync-frontend.sh
docker compose -f deploy/local-remediation/compose.yml exec -T node npm run type-check
docker compose -f deploy/local-remediation/compose.yml exec -T node npm run test -- src/api/__tests__/protectedFile.spec.ts src/components/ui/__tests__/ProtectedFileViewer.spec.ts
docker compose -f deploy/local-remediation/compose.yml exec -T node npm run build-only
```

- Type-check: exit 0.
- Focused tests: **2 files / 8 tests PASS** (2.54s). Initial test-only mock/cleanup issues were corrected; final assertions still cover all intended error and lifecycle paths.
- Build-only: exit 0; PWA generated, 154 precache entries. Large-chunk warning remains; no unrelated bundling redesign performed. Build lives in isolated `/work/dist`, not the existing DEV runtime document root.
- Local UAT HTTP smoke (after confirming Compose port ownership): SPA 200; backend `/up` 200; proxied `/up` 200; anonymous `/api/v1/auth/me` 401; install status 200. Initial frontend port 15173 was occupied; changed to verified-free 18173 without changing the occupying service. Initial responses on the conflicting frontend port were discarded.
- Browser and installed-PWA interaction: **NOT EXECUTED**. No browser automation tool was exposed in this session. jsdom and builds do not establish browser/PWA PASS.
- `git diff --check`: clean.

## H. Documentation

Codex updated canonical `05-BUSINESS-RULES.md` (return CAS and executor restriction), `07-FRONTEND-GUIDE.md` (return-assignment tab), and `08-BACKEND-API.md` (GET return queue and PATCH expected assignment). Pre-existing Sonnet changes to other canonical files were preserved. Local Docker usage is documented beside Compose.

## I. Protected files

Unchanged from audit start, verified by SHA-256:

- Owner checkpoint `docs/PRIMECLASSY-WA-MGMT-KMSIT-OSRM-CHECKPOINT.md`: `8bb913a78f0babbcae90350e2c8e643174e2a4da29a709c6d62976a489bed6ee`.
- Existing untracked RECON report `docs/PRIMECLASSY-V2-PRE-REMEDIATION-RECON-REPORT.md`: `23a8e066f0b8510543a2dd20da7463e13d6d60a55a0bd11169c0db9ed1338418`.

Working tree intentionally remains dirty as requested. HEAD and branch unchanged. No `.env` modified, no secrets committed, no Git mutation performed.

## J. Remaining risks / limits

- Owner browser/PWA UAT, including actual download behavior, custom non-square logo visual layout, return UI feedback, search race interactions and small-screen layouts, remains outstanding.
- No production scheme/proxy or production data was inspected. Private payment requests now derive the canonical local endpoint; public legacy/COD/shipment proof mechanisms remain separate.
- No legacy creator repair; NULL creator remains fail-closed.
- Optional UAT uses disposable test data; test harness resets must not overlap manual UAT.
- Direct fixes have been tested by Codex; this is not a second independent review of Codex's own remediation.

## K. Owner local acceptance

Use Docker commands in `deploy/local-remediation/README.md`. With no test runner active, start optional `uat.yml`, open `http://127.0.0.1:18173`, and run `deploy/local-remediation/bootstrap-uat.sh`. A synthetic local owner is ready; retrieve its random credentials privately with `docker compose -f deploy/local-remediation/compose.yml exec -T php cat storage/framework/testing/local-uat-owner.txt`. Create synthetic branch A/B accounts/orders through normal local workflows. The read-only `.env` is deliberately preserved; the installer wizard is not used.

1. Coordinator → Dispatch → Penugasan Retur: assign an awaiting return, verify current courier/status and success feedback. Courier sees only its assignment. A second tab with stale assignment must get 409 when selecting a different courier; reload then deliberately reassign. Old courier loses visibility/actions. Completed returns disappear.
2. Courier: unassigned/other-courier deliveries absent; assigned delivery progresses with mandatory proof. SKS: own-created self_sub order allowed; foreign or NULL creator denied. Check branch B cannot access branch A.
3. Coordinator → Riwayat Terkirim: ordinary and SKS executors, dates/filters/pages and proof links; open delivered detail and confirm no money or order-wide mutation controls.
4. Authorized payment viewer: preview then download in browser and installed PWA; close during slow load; verify explicit error text on missing/forbidden proof. Confirm legacy/COD proofs still open.
5. User list: 10/25/50/100 sizes, totals, search/role/status, rapid search changes. Reassignment search finds candidates beyond initial page and only valid branch candidates.
6. Invoice: official default, authorized non-square custom logo, invalid custom fallback, company text fallback; compare unchanged order number and financial values.

## L. Production gate

**LOCAL ONLY — NO COMMIT, PUSH, MERGE OR PRODUCTION DEPLOYMENT AUTHORIZED.**

## Test compatibility classification (targeted static search)

- **Updated fixtures/assertions:** CourierSystemTest, R04OrderProjectionTest, ShipmentReceiptPrintTest, ReturnPickupScheduleTest, CourierFeeSplitTest, ReportSystemTest, OrderDetailFeaturesTest, SalesKurirSubSelfDeliveryTest, DispatchAssignmentConcurrencyTest.
- **Additional negative/security coverage:** CourierSystemTest (return ownership/CAS/sibling), DispatchAssignmentConcurrencyTest (real return race), MasterV2PaymentCoreTest (role denial/missing file); existing CourierAssignmentIsolationTest and CoordinatorDeliveredHistoryTest exercised.
- **Still valid:** CourierItemProgressionTest (already assigned), DispatchTest/DispatchRemediationTest/KoordinatorAccessTest/Uat4008RemediationTest (explicit dispatch), SalesKurirSubStandardDeliveryTest (explicit standard assignment or creator-owned self_sub), SalesCourierDualFeeTest/SubReturnDomainTest/AuditContinuityTest/PbrRemediationTest/SubStockSourceTest (creator-owned SKS flow).
- **Unrelated search matches, unchanged:** ReturnSystemTest, WhatsAppNotificationTest, WarehouseReturnQueueTest, OrderStatusTransitionTest, CrmReturnConsentTest, Sc03CanonicalGroupingTest, OrderPaymentBadgeTest, GudangOrderQueueTest, WarehouseReturnDispositionTest, OrderFulfillmentTest, PricingRemediationTest, RefundLedgerTest, ShipmentAggregateLifecycleTest, SalesFeeRefundAdjustmentTest, DeliveryVerificationTest (office transitions, existing assigned executors, or unrelated domain vocabulary). These were not run merely to discover failures.
