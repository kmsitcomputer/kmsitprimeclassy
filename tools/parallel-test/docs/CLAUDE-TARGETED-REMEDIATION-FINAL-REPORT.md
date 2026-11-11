# Claude Targeted Remediation — Final Report

**Verdict: PARALLEL FULL TEST PASS — VERIFIED**

Run `56f76cae391a`, manifest digest `f45d9bb86086f428af212572c200c5cea8b1e94b8d5ecaeaf65238924469e812`, branch `main`, HEAD `d7997c4e6a32ee64bc1c3075a021037c5dfa11c2` (dirty tree preserved, nothing committed, pushed or deployed).

## 1. Result in one table

| | Baseline run `388f6e66d024` | Final run `56f76cae391a` |
|---|---|---|
| Discovered (runtime) | 2162 | 2180 |
| Executed | 2162 | 2180 |
| Passed | 2133 | 2180 |
| Failed / Errors / Skipped | 13 / 16 / 0 | 0 / 0 / 0 |
| Assertions reported | 13,804 | 14,010 |
| Missing / Unexpected / Duplicate | 0 / 0 / 0 | 0 / 0 / 0 |
| `inventory_completeness` | INCOMPLETE (failure) | RECONCILED |
| Verified shutdown (`STOPPED.json`) | yes | yes (`04:17:42Z`) |

The counts are not comparable one-for-one: the new discovery includes the tests added by this remediation (+1 reset, +1 harness, +6 fingerprint engine tests, minus none).

## 2. The original 29 failures/errors, root cause and fix

| Group | Count | Root cause | Fix |
|---|---|---|---|
| `ProductResetCommandTest` | 7 | `admin_notifications.order_id` (SET NULL FK, added 2026-11-05) pointed into the order delete scope and the reset refused with `UNMAPPED DEPENDENCY`. | `ResetProducts`: the reference is mapped as a *retained SET NULL reference* (`RETAINED_SET_NULL_REFERENCES`). It applies only while the live schema still reports `SET NULL` (anything else stays unmapped, fail-closed). The link is cleared explicitly (`detachRetainedOrderReferences`) before the orders are deleted. Notification rows are never deleted. |
| `DeliveryDateGroupingTest` | 2 | Hard-coded `2026-11-01` / `2026-12-15` fell into the past. | Dates derived from the application clock (+7 days, reschedule +38 days), fixed once per test. `after_or_equal:today` untouched. |
| `RefundLedgerConcurrencyTest` | 1 | Dispatch of a Kurir Toko shipment now requires a Koordinator assignment and accepted receipt (F02/REM-003); the fixture had none, so no snapshot was created. | Fixture assigns a courier through the real `CourierService::assignCourier` as the branch Koordinator (existing `AcceptsWarehouseDemand::assignAsKoordinator`). The exactly-one-snapshot assertion and actor/barrier timing are unchanged. |
| `OrderLockedStateConcurrencyTest` ×2, `TrueConcurrencyHarnessTest` ×1 | 3 | Cascade: a leftover `concurrency-*` run directory. | See section 3 (a harness teardown defect found by the second run). |
| `IsolatedSchemaFingerprintTest` | 16 | Fingerprint written for MySQL 8.4 `information_schema` (`information_schema_stats_expiry`, `SRS_ID`, `EXPRESSION`, `IS_VISIBLE`, `ENFORCED`, `DEFAULT_ENCRYPTION`) on a MariaDB 10.11 test server. | Engine-aware fingerprint, section 4. |

## 3. Additional defect found by the verification runs (infrastructure/test support)

The intermediate run `f22a1ced2f88` (2179 executed) failed only on the three cascade tests. Cause: the concurrency actor writes extra marker files beside the declared barrier files (`<ready>.capacity-attempt`, `<ready>.first-capacity-lock`); `ConcurrencyHarness::cleanupBarrierDir()` unlinked only the declared paths, so its `rmdir` failed silently and the directory survived to fail later tests.

Fix: `cleanupBarrierDir()` now also unlinks regular files inside *this harness instance's own* per-run directory before `rmdir`. It never touches another run's directory. Regression: `TrueConcurrencyHarnessTest::test_barrier_teardown_removes_actor_marker_files_but_only_in_its_own_run_directory`. The leftover directory of that failed run (4 marker files, run verified stopped) was removed by hand.

## 4. MariaDB / MySQL fingerprint parity assessment

Original integrity properties (all preserved on MySQL, unchanged queries): schema charset/collation, per-table engine/row format/collation/options/comment/AUTO_INCREMENT, row content via `CHECKSUM TABLE` (validated fail-closed), columns (type, nullability, default, extra, charset, collation, generation expression, comment, SRS), indexes (order, prefix, type, visibility, expression, uniqueness, collation), constraints and enforcement, FK mapping and rules, CHECK clauses, triggers, views, routines, events, partitions.

Implementation (`tests/Support/IsolatedSchemaFingerprint.php`): explicit `engine()` detection. MySQL 8.x → original queries. MariaDB 10.6+ → `mariadbQueries()`. Any other or unreadable server → `RuntimeException` (fail-closed). `engine` is part of the hashed input.

| Property | MySQL source | MariaDB handling | Gap? |
|---|---|---|---|
| stats freshness | `information_schema_stats_expiry=0` | MariaDB does not cache I_S statistics; statement skipped | no |
| schema encryption | `DEFAULT_ENCRYPTION` | no such attribute exists | no (cannot vary) |
| CHECK/constraint enforcement | `ENFORCED` | MariaDB cannot declare NOT ENFORCED, always enforced | no |
| functional index key | `EXPRESSION` | not supported by MariaDB | no |
| index visibility | `IS_VISIBLE` | `IGNORED` (same property, inverse polarity) | no |
| spatial SRID | `SRS_ID` | not in MariaDB I_S; tables with spatial columns also hash a normalized `SHOW CREATE TABLE` (AUTO_INCREMENT stripped) | no (no spatial column exists in current migrations; path covered by a mock test) |
| table kinds | `BASE TABLE` | `table_type <> 'VIEW'`: also SYSTEM VERSIONED and SEQUENCE, each checksummed fail-closed | strengthened |
| CHECK level, view ALGORITHM | n/a | added | strengthened |

No integrity property was dropped without an equivalent. No Owner decision was required.

Drift detection proof: the live suite (51 tests) adds/changes/drops tables, columns (type, default, collation, generated, expression), indexes (prefix, visibility/ignored, order, uniqueness), FKs and rules, CHECKs, triggers, views, data, auto-increment and cross-process DDL, each asserting the fingerprint changes and returns after cleanup. Two tests ended their mutation by dropping what they added, which on MariaDB legitimately returns to the start state; they now assert each drop explicitly and keep a different end state, so the helper's "must change" check is not vacuous. Engine tests: version matrix (11 cases, including unsupported MySQL 5.7, MariaDB 10.5/10.3, unknown, empty), MySQL path (stats statement, no `SHOW CREATE TABLE`), MariaDB path (no MySQL-only columns by whole-word check, spatial hash, SRID included, counter excluded), unsupported server throws before hashing.

Side effect: a working fingerprint lets the serial lane skip `migrate:fresh` when nothing changed; the serial lane dropped from 2429 s to 1552 s.

## 5. Files changed by this remediation

- `backend/app/Console/Commands/ResetProducts.php`
- `backend/tests/Feature/ProductResetCommandTest.php` (+1 test)
- `backend/tests/Feature/DeliveryDateGroupingTest.php`
- `backend/tests/Feature/RefundLedgerConcurrencyTest.php`
- `backend/tests/Feature/TrueConcurrencyHarnessTest.php` (+1 test)
- `backend/tests/Support/ConcurrencyHarness.php` (`cleanupBarrierDir`)
- `backend/tests/Support/IsolatedSchemaFingerprint.php`
- `backend/tests/Unit/IsolatedSchemaFingerprintTest.php` (+6 tests/data sets)
- `tools/parallel-test/manifest/manifest.json` (hashes and counts only, no lane change; digest `f45d9bb8…`)
- these two reports

Unchanged: `RefundLedgerBackfill` database-name rule, serial rule for `RefundBackfillTest`, courier/dispatch/payment/refund/inventory policy, `package.json`, lockfile, images, containers and worker databases.

## 6. Scoped verification before the Full Test

| Check | Result |
|---|---|
| PHP lint of all changed files | no errors |
| `ProductResetCommandTest` | 20/20 |
| `DeliveryDateGroupingTest` | 2/2 |
| `RefundLedgerConcurrencyTest` | 4/4, 63 assertions |
| `OrderLockedStateConcurrencyTest` + `TrueConcurrencyHarnessTest` | 3/3 (before the harness fix, on a clean directory) |
| `IsolatedSchemaFingerprintTest` | 51/51, 129 assertions |
| `TestRunnerSupportTest` + `TestDatabaseGuardTest` | 77/77 and 67/67 (guard unchanged) |
| `DispatchAssignmentConcurrencyTest` + `TrueConcurrencyHarnessTest` + `OrderLockedStateConcurrencyTest` | 14/14, no leftover directory |
| tooling unit tests | 36/36 |
| `manifest-verify`, `static-validate` | INVENTORY MATCHES MANIFEST, STATIC VALIDATION OK |
| `git diff --check` | clean |

## 7. Full Test evidence (run `56f76cae391a`)

Started 03:43:19Z, last job ended 04:17:40Z (34.4 min).

| Job | Executed | Exit | Duration | Assertions |
|---|---|---|---|---|
| worker-1 PHPUnit | 9 | 0 | 496 s | 51 |
| worker-2 PHPUnit | 350 | 0 | 508 s | 2369 |
| worker-3 PHPUnit | 471 | 0 | 305 s | 3228 |
| worker-3 Vitest (34 specs) | 283 | 0 | 55 s | n/a |
| worker-4 PHPUnit | 350 | 0 | 373 s | 2911 |
| worker-5 PHPUnit | 481 | 0 | 380 s | 3151 |
| serial PHPUnit (31 files) | 236 | 0 | 1552 s | 2300 |
| **Total** | **2180** | | | **14,010** |

PHPUnit 1897 + Vitest 283 = 2180. Failures 0, errors 0, skipped 0. Runtime discovery (`phpunit --list-tests-xml`, Vitest collect) reconciled by `Class::method[#dataset]` identity against JUnit: 2180 discovered = 2180 executed, 0 missing, 0 unexpected, 0 duplicate; `inventory_completeness: RECONCILED`; every job's `discovered` equals its `executed`. No filters. Only informational problems (static lower bounds vs. actual counts), no `fail` severity problem.

Shutdown: `STOPPED.json` `verified_stopped: true` at 04:17:42Z; no `pc-*` containers; `storage/framework/testing/concurrency/` empty; no PHPUnit process remains.

## 8. Remaining risks and notes

- `email_messages.order_id` and `whatsapp_messages.order_id` are `RESTRICT` foreign keys to `orders`. `products:reset` will refuse (fail-closed, correctly) when a deleted order has such a row. No test exercises it and the reset policy for those tables is not specified; this is a latent Owner decision, not changed here.
- Notification rows whose order was reset keep stale `subject_type`/`subject_id` text (polymorphic, no FK). Retained by design; `AdminNotificationsPrune` governs retention.
- The fingerprint's MariaDB path was validated live on MariaDB 10.11 only. The MySQL path is unchanged but was not executed on a MySQL server here (none available).
- The serial lane (≈26 min) is the critical path of the whole run.
- `primeclassy-fpm-test` and image `primeclassy-test-php:8.3-py` are kept as instructed. Rollback commands are in the runtime report.

## 9. Confirmations

- Production, VPS and `primeclassy_db` were not accessed or modified. Only `primeclassy_testing` and `primeclassy_testing_w1..w5` were used, with the existing testing credentials; no root credential was used in this phase and no secret was displayed.
- No test was deleted, skipped, excluded or filtered; no assertion was weakened; no guard, lock, lease, discovery reconciliation or process-control safeguard was disabled.
- No commit, push, merge or deploy; Woodpecker CI not used; the pre-existing dirty tree was preserved.
