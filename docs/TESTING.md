# TESTING — backend test harness, Owner procedure and agent policy

Scope: `backend/tests`, `backend/scripts/run-tests-serialized.php`. Application behaviour is not described here (see MASTER-SYSTEM / BUSINESS-RULES).

## 1. One runner per test database

All automated tests use exactly one database: `primeclassy_testing` (`APP_ENV=testing`, guarded by `Tests\Support\TestDatabaseGuard`, exact equality — never a pattern).

`Tests\Support\TestRunLock` takes a **MySQL named lock** (`GET_LOCK('primeclassy:testrun:primeclassy_testing', 0)`) on a dedicated connection the first time any `Tests\TestCase` boots, i.e. before the first schema operation.

| Property | Behaviour |
|---|---|
| Second runner | Refused immediately: `TEST DATABASE ALREADY IN USE ... Holder run <uuid>, pid, started, command, head` |
| Entry points covered | `php artisan test`, `vendor/bin/phpunit`, `scripts/run-tests-serialized.php`, IDE runners (anything that boots `Tests\TestCase`) |
| Crash | The server frees the lock when the owning connection dies. There is **no lock file to delete**; just retry |
| Child concurrency actors | They bootstrap the kernel only (never `TestCase`), so they never ask for the lock and cannot deadlock against the runner |
| Metadata | `storage/framework/testing/run-lock.json` (run id, pid, start, command fingerprint, db name, git head). Informational only; ownership is decided by the server |

`scripts/run-tests-serialized.php` additionally keeps its older `flock` lock and refuses non-testing databases.

### Lock connection loss (fail-closed)

A MySQL named lock has **no lease/TTL**: it exists exactly as long as the session that took it. If that session dies (killed, `wait_timeout`, server restart, network) the server frees the lock while the PHP runner may still be alive. Therefore ownership is re-proven (`IS_USED_LOCK(name) = CONNECTION_ID()` on the lock connection) at every critical boundary:

| Boundary | Where |
|---|---|
| every test boot | `Tests\TestCase::createApplication()` |
| before `migrate:fresh` of the isolated schema | `RestoresIsolatedTestDatabase::restoreIsolatedTestDatabase()` |
| before an actor batch, before every actor launch, before actor results are used | `ConcurrencyHarness::ensureRuntimeDir()`, `spawnActor()`, `startProcess()`, `readJson()` |

If ownership cannot be proven the runner throws `TEST RUN LOCK LOST: refusing to … — <reason>` and **stays lost** for the rest of the process: it never reconnects, never re-acquires (another runner may have reset the schema in between) and never releases anything (`RELEASE_LOCK` only acts on its own, dead session). Every following test errors immediately. Stop the run and start a new one after the preflight.

Limits: protection is per boundary, not continuous — a loss in the middle of a single test body is detected at that test's next boundary (at the latest the next test boot). The lock connection sets `wait_timeout = 172800` so an idle multi-hour run is not reaped. `RefreshDatabase`'s own `migrate:fresh` happens right after the test-boot check.

## 2. Three kinds of test, three isolation strategies

1. **Ordinary tests** (`RefreshDatabase`): each test runs in a transaction that is rolled back. Cheap; cannot see data committed by another connection.
2. **Committed concurrency tests** (`RestoresIsolatedTestDatabase`): separate PHP actor processes must *see* the fixtures through their own MySQL connections, so fixtures are committed. Committed rows survive any rollback, so the schema is restored to "exactly what `migrate:fresh` produces" at the boundaries.
3. **Migration / DDL contract tests** (`MigrationVerificationTest`, `DevSchemaForeignKeyRestorationDdlTest`): they alter the schema. They set `protected bool $isolatedSchemaAlwaysRebuild = true;` and always start from and leave behind a freshly migrated schema.

### Rebuild policy for category 2 (P1)

A rebuild (`migrate:fresh`, ~157 migrations) measured **≈ 43 s** on the Owner's machine. `ensureIsolatedSchemaClean()` skips it **only** when `IsolatedSchemaFingerprint::isClean()` returns true, i.e. a baseline taken right after this process's last rebuild exists **and** a fresh fingerprint is equal to it.

The fingerprint (MySQL 8.4 `information_schema`, columns verified on 8.4.11) covers: schema charset/collation; every base table (engine, row format, collation, create options, comment, AUTO_INCREMENT); row content of every table (`CHECKSUM TABLE`); columns (position, type, nullability, default, extra, charset, collation, generation expression, comment); indexes (column order, prefix length, type, visibility, expression, uniqueness); all constraints with enforcement; FK column mapping (composite positions) and rules; CHECK clauses; triggers; views; routines; events; partitions.

A rebuild is **forced** when: no baseline in this process (first isolated test, or the previous run died); the baseline could not be taken after a rebuild; any metadata query fails; `CHECKSUM TABLE` returns a NULL, missing, duplicate, extra, non-numeric or wrongly named row (THR-01); a table has no column metadata; no base tables are visible; the class sets `$isolatedSchemaAlwaysRebuild = true`; or anything differs. The reason is counted in `stats-*.json` → `fingerprint_dirty_reasons`.

Limits (why this is a strong equality check, not an absolute identity proof): `CHECKSUM TABLE` is a checksum, collisions are theoretically possible; objects invisible to the test user would not be seen (the user has ALL on `primeclassy_testing`); server-level state (global variables, other schemas, users/grants) is out of scope; the baseline is per process. Auto-increment is included on purpose, so an ordinary test that ran in between forces a rebuild — conservative, never unsafe. One unexplained false "dirty" (FK added then dropped) was observed once in 9 runs and not reproduced; its effect is an extra rebuild only.

Not implemented (future, needs its own evidence): replacing the teardown rebuild by a data-only restore or a schema template/snapshot. The teardown rebuild is now the dominant cost.

## 3. Owner procedure — Full Test (PowerShell)

Full Test is **exclusive to the Owner**.

1. **Preflight**
   ```powershell
   cd C:\ServBay\www\primeclassy
   git status --short; git rev-parse HEAD
   Get-CimInstance Win32_Process -Filter "Name='php.exe'" | Where-Object CommandLine -match 'artisan\s+test|phpunit|pest|run-tests' | Select ProcessId,CreationDate,CommandLine
   ```
   The last command must print nothing. Do not run two runners, and do not let an agent run any test while yours is running (a refused second runner is the safety net, not the plan).
2. **Database safety** — `backend/.env.testing` must name `primeclassy_testing`. The guard refuses anything else.
3. **Run with the official runner** (keeps a log and a machine-readable summary):
   ```powershell
   cd C:\ServBay\www\primeclassy\backend
   php scripts/run-tests-serialized.php 2>&1 | Tee-Object ..\primeclassy-fulltest-backend-$(Get-Date -Format yyyyMMdd-HHmm).log
   ```
   (`php artisan test` also works and is protected by the same lock, but writes no summary.)
   Filters containing `|` must not go through `php.cmd`; call `C:\ServBay\packages\php\current\php.exe -c C:\ServBay\etc\php\current\php.ini scripts/run-tests-serialized.php ...` directly.
4. **Read the result** — the last line prints `RUN <uuid> exit=… failures: environment=… migration=… concurrency=… test=…`. Files in `backend/storage/framework/testing/runs/` (git-ignored):
   * `<uuid>.summary.json` — run id, git head, `git` (`available`, `dirty`, `paths`; from `git status --porcelain=v1 -z` stdout only, warnings never counted, `available:false` when git fails — never read that as clean), PHP, arguments, start/end, duration, PHPUnit exit code, `junit` (`path`, `custom`, `available`) and `failures` classified (environment = lock refusal/loss or schema contention, migration, concurrency, test). When JUnit is missing/invalid the counts are `null` and the console prints `failures: UNAVAILABLE (...)` — not zero;
   * JUnit — `<uuid>.junit.xml`, or exactly the path you passed with `--log-junit path` / `--log-junit=path` (the summary and classifier read that same path);
   * `<uuid>.stats-<pid>.json` — PHP/Laravel/PHPUnit versions, per-class test count and seconds, rebuild count and seconds (`rebuild_log` with phase setup/teardown), fingerprint checks and skipped rebuilds.
5. **Status while running** — `backend/storage/framework/testing/run-lock.json` shows the holder; the lock itself is `SELECT IS_USED_LOCK('primeclassy:testrun:primeclassy_testing')` (non-NULL = held).
6. **Crash recovery** — kill nothing by hand. When the process is gone the lock is gone. Re-run preflight, then retry. A new process has no fingerprint baseline, so its first isolated test always rebuilds; ordinary tests rebuild through `RefreshDatabase`. If a run reports `TEST RUN LOCK LOST`, stop it (it is already refusing every test) and start a new run.
7. **Compare runs** — diff `duration_seconds`, `rebuilds.count`, `rebuilds.seconds`, `rebuilds_skipped_clean` and per-class `seconds` between two `stats-*.json` files; compare `git_head` plus `git.available` / `git.dirty` / `git.paths` first, otherwise the comparison is meaningless. If `git.available` is false, do not treat that run as clean or directly comparable.
8. A failing `environment` count > 0 means the run was disturbed (contention / schema state), not that the application is wrong: investigate the run id before looking at tests.

### Running scoped tests safely

```powershell
cd C:\ServBay\www\primeclassy\backend
# preflight (§3.1) must print nothing, then one class or filter:
php scripts/run-tests-serialized.php tests/Unit/TestRunLockTest.php
# filters containing | : call php.exe directly (php.cmd hands | to cmd.exe)
C:\ServBay\packages\php\current\php.exe -c C:\ServBay\etc\php\current\php.ini scripts/run-tests-serialized.php tests/Unit '--filter=TestRunLockTest|IsolatedSchemaFingerprintTest'
```

Harness self-tests: `tests/Unit/TestRunLockTest.php` (lock, loss, boundaries), `tests/Unit/IsolatedSchemaFingerprintTest.php` (data/schema/trigger/view/CHECK/FK matrix, checksum validation, metadata failures, trait decisions), `tests/Unit/TestRunnerSupportTest.php` (JUnit path, classification, git metadata, nested runner end to end). They create only uniquely named temporary objects (`fp_t_*`, `fp_sub_*`, random lock names) and drop them in `finally`.

## 4. Agent testing policy

* Agents run **scoped tests only** (a class or `--filter`); never the full suite, never under another name (full regression, comprehensive, exhaustive, all tests).
* The Owner is the only party that runs Full Test.
* No concurrent runner on the same database. Before any test: check no runner is active (preflight command above); never stop processes that are not your own.
* Never reset/migrate/wipe `primeclassy_dev` or production; destructive schema operations happen only on `primeclassy_testing`, by the harness.
* Every PASS claim carries evidence: command, counts, duration, database.
* Do not add database privileges, create databases, delete or weaken tests to speed things up.
