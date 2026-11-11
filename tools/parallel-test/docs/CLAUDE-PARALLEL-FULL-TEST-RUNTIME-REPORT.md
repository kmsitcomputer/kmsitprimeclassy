# Claude Parallel Full Test — Runtime Report

> **UPDATE 2026-11-11 — current verdict: PARALLEL FULL TEST PASS — VERIFIED.**
> After the Owner-approved targeted remediation, run `56f76cae391a` (manifest `f45d9bb86086f428…`) executed 2180 of 2180 discovered tests (PHPUnit 1897 + Vitest 283): 2180 passed, 0 failed, 0 errors, 0 skipped, 14,010 assertions, missing/unexpected/duplicate 0/0/0, `inventory_completeness: RECONCILED`, `STOPPED.json` verified. Details, root causes and files: [CLAUDE-TARGETED-REMEDIATION-FINAL-REPORT.md](CLAUDE-TARGETED-REMEDIATION-FINAL-REPORT.md).
> Everything below is the **historical** report of run `388f6e66d024` (FAILED — APPLICATION DEFECTS, 29 failures) kept as evidence; its "pending Owner decisions" are resolved by the update above.

## 1–2. Executive summary and final verdict

**PARALLEL FULL TEST FAILED — APPLICATION DEFECTS**

The infrastructure is now operational and safe: 5 parallel workers, the serial lane and all Vitest specs ran to completion with verified shutdown, and every discovered test identity was reconciled against the results (no missing, unexpected or duplicate). The run still FAILS because **29 tests fail**, none of which is an infrastructure fault:

- 7 + 2 + 1 (+ 2 cascaded) are application / test-fixture defects (section 18);
- 16 are an engine mismatch (tests written for MySQL 8.4, local test DB is MariaDB 10.11) that needs an Owner decision.

No business logic, business assertion, guard, lease, discovery reconciliation or Owner gate was weakened. No test was deleted, excluded or filtered.

## 3. Repository baseline

Branch `main`, HEAD `d7997c4e6a32ee64bc1c3075a021037c5dfa11c2`, dirty tree preserved (nothing reset/stashed/committed). Final manifest digest `9311dcfbcce8e19a5c14a24c5a251bff7a0d21081f6d3d47aa9d038589192a5a` (Codex baseline `4e79997c…`; the only inventory change is `RefundBackfillTest.php` parallel → serial, 208 PHP files = 177 parallel + 31 serial, 34 Vitest specs, no file added/removed, no hash drift).

## 4. Codex remediation verification (runtime)

Verified at runtime: PHP lint; strict privilege preflight (it correctly rejected the pre-existing wildcard grant); runtime discovery for PHP and Vitest; JUnit identity reconciliation; STOPPED proof; lease/lock coordination between runs; Python/pidfd controller inside the testing container; process shutdown proof; dependency-tree digest check (it correctly caught a post-preparation modification, see 12). Defects found in the Codex work and fixed: see 12.

## 5–8. Environment inventory

| Item | Observation |
|---|---|
| Host | macOS Darwin 25.6; no `php`/`node`; Python 3.15.2 |
| Runtime PHP container | `primeclassy-fpm` (`kmsit-php:8.3-fpm`) — **untouched** |
| Testing PHP image | `primeclassy-test-php:8.3-py` (id `sha256:60bf0f48b9f3…`) = `kmsit-php:8.3-fpm` + `python3` (3.14.10) + `git` (2.54.0); PHP 8.3.36 |
| Testing container | `primeclassy-fpm-test`, network `primeclassy-net`, backend rw mount + ini ro, no published ports, `--restart no` (still running; remove with `docker rm -f primeclassy-fpm-test`) |
| pidfd / proc | `os.pidfd_open` available, `pidfd_send_signal` verified, `/proc` readable, controller probe passed in preflight on both runs |
| Node | ephemeral `node:24-alpine` (`sha256:a34ab6d7f6f4…`, amd64), network none |
| DB server | `primeclassy-mariadb`, MariaDB 10.11.19, network `primeclassy-net` |

## 9. Database identity and privileges

- Read-only probe with the testing credentials (secrets never printed). Server identified as `10.11.19-MariaDB-ubu2204` at `primeclassy-mariadb`.
- Owner-approved provisioning executed via `pc provision create` (root secret read only inside the DB container): created `primeclassy_testing_w1..w5` (utf8mb4 / utf8mb4_unicode_ci) with a minimum privilege list for `primeclassy_test`@`%`, escaped schema names.
- Strict preflight then rejected the existing canonical grant `ALL PRIVILEGES ON primeclassy_testing.*` because the unescaped `_` is a MariaDB wildcard pattern. Minimal adjustment applied under the Owner's item 4: `REVOKE ALL … ON primeclassy_testing.*` then `GRANT ALL PRIVILEGES ON primeclassy\_testing.*` (same privilege set, now an exact schema, i.e. strictly narrower). Final grants: `USAGE ON *.*`, exact-schema grants for `primeclassy_testing` and `_w1.._w5` only.
- `primeclassy_db`, production and every other database were not touched. No database was dropped or reset by me (the test suites run `migrate:fresh` inside their own testing schemas as designed).
- One out-of-scope attempt (reading table counts through the root credential) was denied by the permission system and not repeated.

## 10. Frontend dependency readiness

`pc prepare-frontend` (Owner-authorized): `npm ci --ignore-scripts --engine-strict`, 595 packages, source read-only, host `node_modules` untouched, `package.json`/`package-lock.json` unchanged, `@rolldown/binding-linux-x64-gnu` and `-musl` present, marker written. No install script was needed.

## 11. Infrastructure validation performed

Python tooling tests 36/36 OK; `static-validate` OK; `plan` dry-run with no assignment problems; runner preflight passed on both runs; discovery passed on both runs (7 jobs); aggregator produced a fail-closed verdict (`FAIL`, not PASS) with terminal-state override; exit code 2 on test failure per contract; `STOPPED.json` `verified_stopped: true` for both runs; no orphan process or `pc-*` container left.

## 12. Infrastructure auto remediation performed

| # | Defect (evidence) | Fix | Verified by |
|---|---|---|---|
| 1 | PHP container had no Python → pidfd controller unusable | New disposable image `primeclassy-test-php:8.3-py` + separate container; adapter container name changed in `scheduler.json` | preflight probe passed |
| 2 | Tooling test fixture built a class name from a Windows path (`test_windows_recorded_paths_resolve`) | fixture only | 36/36 OK |
| 3 | Guard hook re-read the mutable app environment on every query, so tests that deliberately set `app['env']='production'` were refused (`TransactionResetCommandTest::test_production_guard_requires_explicit_force`, `ResetDevTransactionsTest::test_refuses_production_environment`) | `TestDatabaseGuard::installConnectionGuards` now validates the environment captured at boot; the connection TARGET (driver/host/port/database/user/lock) is still verified on every query | both files pass; guard unit tests 67/67 |
| 4 | Concurrency actor exited 0 when the guard refused (Laravel renders the exception and exits 0), so Codex's `test_actor_refuses_url_override_before_provider_boot` failed and a refusal was not machine-visible | actor catches the refusal before providers boot, prints it and `exit(3)` | `TestDatabaseGuardTest` OK (67 tests) |
| 5 | `PRIMECLASSY_TEST_RUN_ID` was `<run>-w<N>`; `TestRunLockTest` asserts a UUIDv4 | deterministic UUIDv4-shaped id per run+worker (`plan.test_run_uuid`, template placeholder `{RUN_UUID}`) | `TestRunLockTest` OK (11) |
| 6 | Testing image lacked `git` (`TestRunnerSupportTest`, exit 127) | `git` added to the testing image | `TestRunnerSupportTest` OK (10) |
| 7 | `RefundBackfillTest` (3 tests): the command refuses `--apply` unless the DB name ends in `_test`/`_testing` (`RefundLedgerBackfill.php:43`); worker DBs end in `_wN` | rule `SER-APPLY-DB-NAME` routes tests invoking `refunds:backfill-ledger` to the serial lane (canonical DB). Test kept, application rule unchanged | `RefundBackfillTest` OK (3), both full runs |
| 8 | Vitest wrote 276 cache files (`.vite`, `.vite-temp`, `.vitest-cache`) into the audited dependency tree, so the second run's preflight refused (`FRONTEND_DEPS_CONTENT_CHANGED`) | generated files removed (digest verified equal to the marker again); node container now mounts source and `node_modules` **read-only** and gives those three directories a throw-away tmpfs | scoped Vitest run: 15/15, 0 files changed in the tree; full run 283/283 |

Changed-and-reverted: a partial MariaDB tweak to `IsolatedSchemaFingerprint.php` was reverted (file identical to HEAD), see section 18.

## 13. Full Test execution evidence

Commands (Owner gate phrase supplied by the Owner's approval):
`pc run --manifest tools/parallel-test/manifest/manifest.json --out-dir backend/storage/framework/testing/parallel --i-am-the-owner --stage-b-confirm "RUN PRIMECLASSY PARALLEL FULL TEST" --approve-manifest <digest prefix>`

- Run 1 `72aadc6f5aaa` (digest `4e79997c…`): FAIL, 23 + 14 failures; used to diagnose 12.
- Run 2 `388f6e66d024` (digest `9311dcfb…`): FAIL, 2162 executed, wall clock 22:23:49 → 23:12:42 UTC (48.9 min). Artifacts: `backend/storage/framework/testing/parallel/run-388f6e66d024/` (`summary.json`, `summary.md`, `STOPPED.json`).

## 14. Worker-by-worker results (run 2)

| Worker | DB | Files | Testcases | Fail | Error | Duration | Exit |
|---|---|---|---|---|---|---|---|
| worker-1 | `primeclassy_testing_w1` | 1 | 9 | 0 | 0 | 486 s | 0 |
| worker-2 | `primeclassy_testing_w2` | 39 | 350 | 2 | 0 | 502 s | 1 |
| worker-3 | `primeclassy_testing_w3` | 43 | 471 | 0 | 0 | 302 s | 0 |
| worker-4 | `primeclassy_testing_w4` | 47 | 349 | 7 | 0 | 358 s | 1 |
| worker-5 | `primeclassy_testing_w5` | 47 | 481 | 0 | 0 | 378 s | 0 |

## 15. Serial lane (run 2)

`primeclassy_testing`, 31 files, started only after verified barrier, 219 testcases, 4 failures + 16 errors, 2429 s, exit 2.

## 16. Vitest (run 2)

34 spec files, **283 tests, 283 passed**, 0 failed, 54 s (worker-3 container, Linux deps, read-only mounts).

## 17. Runtime testcase reconciliation (run 2)

| Set | Discovered | Executed | Missing | Unexpected | Duplicates |
|---|---|---|---|---|---|
| PHPUnit workers 1–5 + serial | 1879 | 1879 | 0 | 0 | 0 |
| Vitest | 283 | 283 | 0 | 0 | 0 |
| **Total** | **2162** | **2162** | 0 | 0 | 0 |

PASS 2133, FAIL 13, ERROR 16, SKIP 0, assertions reported 13,804 (the aggregator's own `inventory_completeness` field reads `INCOMPLETE` and `discovered_test_cases` is `null` solely because the run has failing jobs; no `TESTCASE_*`/`DISCOVERY_*` problem was raised — the table above was recomputed from the discovery and JUnit artifacts with the aggregator's own parsers). Static lower bounds (1,780 / 269) are informational only.

## 18. Remaining failures (29) — classification and evidence

**A. Application / test defects (not changed, Owner decision)**

1. `ProductResetCommandTest` ×7 (worker-4). Command output captured on a worker DB: `REFUSED — UNMAPPED DEPENDENCY: admin_notifications.order_id -> orders (delete rule SET NULL): a preserved row references a record inside the orders delete scope`. `admin_notifications` was introduced in commit `87ef993` (2026-11-02); `ResetProducts.php` (last changed 2026-10-30) does not map it. The previous full run (2026-11-01) predates the table. Needs an application change to the reset command's dependency map or an agreed preserved/deleted decision.
2. `DeliveryDateGroupingTest` ×2 (worker-2). Fixtures hard-code `delivery_date` `2026-11-01` / `2026-12-15`; validation `after_or_equal:today` returns 422 now that the date has passed (time-dependent fixture; passed on 2026-11-01).
3. `RefundLedgerConcurrencyTest::test_concurrent_dispatch_creates_exactly_one_snapshot` (serial). Actor report: both actors fail with `ApiException: A store-courier shipment can only be dispatched once the Koordinator assigned it.` — the fixture never assigns a Koordinator, which the newer courier rule (HEAD `d7997c4` UAT remediation) now requires.
4. **Cascade of 3:** the failing test above leaves `storage/framework/testing/concurrency/concurrency-*` behind; `OrderLockedStateConcurrencyTest` ×2 and `TrueConcurrencyHarnessTest` then fail their "no leftover runtime directory" assertion. With the leftover removed they passed in a scoped run on the canonical DB (2/2 and 1/1), so they are expected to pass once item 3 is resolved.

**B. Engine mismatch (Owner decision)**

5. `IsolatedSchemaFingerprintTest` ×16 (serial). `SQLSTATE 1193 Unknown system variable 'information_schema_stats_expiry'`, then `1054 Unknown column 'SRS_ID'`: `tests/Support/IsolatedSchemaFingerprint.php` is written for MySQL 8.4 `information_schema` ("verified against 8.4.11") while the local test database container is MariaDB 10.11. They passed on 2026-11-01 on the Owner's earlier environment. Porting the fingerprint to MariaDB changes the semantics of a schema-integrity tool, so it was not done. Options: run the Full Test against a MySQL 8.4 test server, or approve a MariaDB port of the fingerprint with its own review.

## 19. Safety / rollback status

- Verified shutdown for both runs (`STOPPED.json`), no orphan process, no leftover `pc-*` container, no active runner.
- Rollback: `docker rm -f primeclassy-fpm-test`; `docker rmi primeclassy-test-php:8.3-py`; restore `scheduler.json` container to `primeclassy-fpm`; delete `backend/storage/framework/testing/parallel-node-linux/` and `.../parallel/run-*` when no longer needed. Provisioned databases `primeclassy_testing_w1..w5` and the escaped grant are kept (drop only with the separate cleanup gate `DROP PRIMECLASSY TEST WORKER DATABASES`).

## 20. Owner decisions pending

1. Application: `ResetProducts` vs `admin_notifications`; the two stale-date fixtures; the `RefundLedgerConcurrencyTest` fixture vs the Koordinator rule. (No change made; business logic untouched.)
2. Test engine for `IsolatedSchemaFingerprintTest`: MySQL 8.4 test server vs MariaDB port.
3. Optional: relax `RefundLedgerBackfill`'s `_test(ing)$` rule to also accept `_w[1-5]` (then the serial routing rule `SER-APPLY-DB-NAME` could be dropped).
4. Whether to keep the testing container/image or remove them.
5. A re-run after the above changes (expected outcome unknown until they are made).

## 21. Exact files changed (this task)

Application / test support: `backend/.phpunit-concurrency-actor.php` (refusal exit code), `backend/tests/Support/TestDatabaseGuard.php` (boot-time environment capture). `backend/tests/Support/IsolatedSchemaFingerprint.php` unchanged (edit reverted).

Tooling: `tools/parallel-test/container/Dockerfile.php-test` (new), `config/scheduler.json`, `config/serialized-rules.json`, `env/worker.env.template`, `pctest/plan.py`, `pctest/cli.py` (placeholder allowlist), `manifest/manifest.json`, `tests/test_tooling.py` (fixture), this report. No `package.json`/lockfile, migration, business-logic or assertion change.

## 22. No-production / no-deploy confirmation

No production/VPS access; `primeclassy_db` untouched; no commit, push, merge, rebase, deploy or Woodpecker; no secret printed or stored in this report; existing application changes preserved; `git diff --check` clean.
