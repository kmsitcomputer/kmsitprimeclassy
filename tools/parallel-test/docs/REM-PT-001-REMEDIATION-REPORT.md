# REM-PT-001 — Parallel Full Test infrastructure remediation report

## A. Executive summary
**Verdict: `REMEDIATED — PENDING INDEPENDENT STATIC AUDIT`** (F01–F08 closed at source level only).
Scope: the eight Codex findings, nothing else. Baseline: branch `main`, HEAD `d7997c4e6a32ee64bc1c3075a021037c5dfa11c2`, dirty working tree (≈124 paths of Owner/Codex/Claude work, preserved; no reset/stash/clean/checkout). Manifest digest was `b4167169…b454`, is now `ea52aa2c…d8ba` (guard unit test and tooling changed; inventory itself unchanged: 208 PHP files, 178/30 lanes, 34 Vitest).
Limitations: **nothing was executed** (no PHPUnit, Vitest, tooling tests, runner, discovery, docker run/exec for tests, DB connection). Everything below is `RUNTIME VALIDATION PENDING`. The Codex audit text was taken from the Owner's task statement; no separate audit file exists in the repository.
Safety-relevant behaviour changes beyond the findings (called out for the auditor): the guard now refuses unix sockets and any URL in *all* modes; host is mandatory in the effective config.

## B. Findings closure matrix
| ID | Root cause | Files | Remediation | Static evidence | Status |
|---|---|---|---|---|---|
| F01 P0 | Guard validated raw config; Laravel's `DatabaseManager::configuration()` merges `url` via `ConfigurationUrlParser` (driver/host/port/database/user/password replaced); lock used raw config; env could carry `DB_URL` | `TestDatabaseGuard.php`, `TestRunLock.php`, `TestDatabaseGuardTest.php`, `env/worker.env.template`, `guards.py`, `plan.py`, `runner.py` | Guard resolves the effective config with the same parser, refuses any `url`/`DB_URL`/`DATABASE_URL`, read/write/sticky, unix socket, port ≠ 3306, driver ≠ connection, unsafe sibling mysql/mariadb connections; returns the validated config which `TestRunLock` uses; worker env passes the three override vars EMPTY (+ `force` in phpunit config); preflight refuses container Env / `.env*` that define them | `php -l` clean; Laravel 11.56 source read (`DatabaseManager.php:218`, `ConfigurationUrlParser.php:30`); `static-validate` checks; 10 + 5 new regression cases (raw-safe/URL-unsafe, socket, read/write, port, sibling, DB_URL env, env≠testing, unknown default) | CLOSED STATICALLY |
| F02 P1 | Static method counts used as evidence; `discovered_test_cases=null`; `forbidden_args` never enforced | `discover.py` (new), `aggregate.py`, `plan.py`, `runner.py`, `scheduler.json` | Mandatory runtime discovery before any test (`phpunit --list-tests-xml` per job config; `vitest list --json`) → identity sets reconciled against JUnit: missing/unexpected/duplicate/parse error/discovery missing ⇒ INCOMPLETE; discovery failure ⇒ INCOMPLETE and **no test started**; forbidden args scanned in every argv ⇒ preflight refusal + aggregator INCOMPLETE; static counts informational only | PHPUnit 11.5 identity formats verified in vendor source (`TestMethod::id()`, `JunitXmlLogger`); synthetic fixtures in `tests/test_tooling.py` (not run) | CLOSED STATICALLY (Vitest identity format UNVERIFIED ⇒ may yield INCOMPLETE, never PASS) |
| F03 P1 | Exceptions between launches skipped cleanup; `pkill -f` pattern; kill exit code taken as proof | `runner.py` | Owner-scoped identity: env markers `PC_RUN_ID`/`PC_JOB_ID` (inherited by descendants/actors), found via `/proc/*/environ`; TERM→KILL→**re-scan proves none left**; host clients in own process group; node containers `docker rm -f` + verified; `active` handles owned by caller so partial launch is cleaned; `stop_everything` on every path; verified barrier before serial lane; unverifiable ⇒ exit 4 BLOCKED, tmp kept; temp removal only after proof | code review; synthetic lifecycle tests (not run) | CLOSED STATICALLY |
| F04 P1 | PHP normalises numeric string keys to int | `TestDatabaseGuardTest.php` | `assertSame([1,2,3,4,5], array_keys(...))` + `assertCount(5, …)` | `php -l` | CLOSED STATICALLY |
| F05 P1 | `LARAVEL_STORAGE_PATH` does not move `bootstrap/cache` | `worker.env.template`, `plan.py`, `guards.py` | `APP_{SERVICES,PACKAGES,CONFIG,ROUTES,EVENTS}_CACHE` per worker/serial under `<run>/…/bootstrap-cache/` (Laravel `normalizeCachePath` via `Env::get`, absolute used as-is); forced in phpunit config; guard refuses any path outside the worker dir or under `bootstrap/cache`; dirs created by `materialise` | `Application.php:1285-1374` read; `plan` dry-run shows per-worker paths | CLOSED STATICALLY |
| F06 P1 | Cleanup `DROP` with no coordination | `provision.py`, `cli.py`, `runner.py`, `php/db-lease.php` (new), `db-preflight.php` | Runner **lease** = session-bound `GET_LOCK('primeclassy:parallelrun:<db>')` held for the whole run; cleanup takes lease + `TestRunLock` names for all targets in ONE `mariadb` session before the first DROP (held lock ⇒ error ⇒ batch aborts, nothing dropped), holds them across DDL, locks die with the session (no stale locks); worker-name regex only; Owner phrase retained; host-side incomplete-run check as secondary | code review | CLOSED STATICALLY (GET_LOCK/abort semantics runtime-pending) |
| F07 P1 | Host darwin `node_modules` bind-mounted into alpine; warning only | `plan.py`, `runner.py`, `cli.py`, `scheduler.json` | Option A: Linux tree prepared separately (`prepare-frontend`, Owner, `npm ci --ignore-scripts` in ephemeral container from `package-lock.json`) at `backend/storage/framework/testing/parallel-node-linux/`, mounted **over** `/work/node_modules`; marker (lock hash, image, arch); preflight REFUSES when missing/stale/mismatched or when any native family with a darwin build lacks `linux-<arch>-musl` (rolldown always required); host adapter removed | lockfile lists `@rolldown/binding-linux-{x64,arm64}-musl` (verified); host tree has only `binding-darwin-x64` | CLOSED STATICALLY; runtime NOT VERIFIED |
| F08 P2 | `sys.exit("msg")` ⇒ exit 1 | `cli.py`, `scheduler.json`, `OWNER-RUNBOOK.md` | `die(msg, code=2)` to stderr for every refusal; contract 0/1/2/3/4/130 in config + runbook; `static-validate` asserts runbook covers it; observed: gate refusal exits 2 | one safe invocation (`run` without Owner flags ⇒ exit 2) | CLOSED STATICALLY |

## C. Database security
Effective resolution = `ConfigurationUrlParser::parseConfiguration()` (same class/version Laravel uses). Refused: `DB_URL`/`DATABASE_URL` env, any connection `url`, `read`/`write`/`sticky`, `unix_socket`, port ≠ 3306, driver ≠ connection, non-allowlisted host, wrong env, database ≠ the worker's own. Worker allowlist remains the explicit map `w1..w5` (+ canonical for id unset/0). Sibling mysql/mariadb connections validated identically (connection switching). `TestRunLock` consumes the validated config. Guard still runs in `TestCase::createApplication()` before the lock and any schema work. Limitation: pgsql/sqlsrv/sqlite config entries are not host-validated (unused by tests; URL on them is refused). Provisioning/cleanup: names by regex `_w[1-5]` only; cleanup lock-coordinated.

## D. Worker lifecycle
See F03/F06. Serial barrier: `barrier()` requires zero owned PIDs and zero run containers. Timeout path stops only that job (`PC_JOB_ID`). Lease loss during phase P ⇒ BLOCKED. Orphan prevention for a SIGKILLed runner: lease and `docker exec -i` stdin close; owned in-container processes are NOT auto-killed in that case (documented risk; next run's preflight refuses while they hold locks/are visible via `docker top`).

## E. Test completeness
Identity = canonical `Class::method[#int|"name"]` (PHPUnit) / `file::title` (Vitest). PASS requires set equality discovery↔JUnit per job, zero duplicates/unexpected, no filters, every job exit 0, all reports parsed. `discovered_test_cases` is filled only from reconciled discovery. Dynamic datasets are covered by discovery (providers executed by PHPUnit at listing time).

## F. Runtime compatibility (all NOT VERIFIED)
PHP via `docker exec primeclassy-fpm` (needs `tr`,`grep`,`kill`,`sh` there — `ps` present, others unverified). Docker/MariaDB as before. Node: ephemeral `node:24-alpine`, network none, Linux musl tree mounted over `node_modules`; Rolldown 1.2.7 optional bindings per lockfile.

## G. Static validation
Executed: `php -l` (container) on the 3 changed PHP files — clean; Python `ast`/`compile` of all modules and tests; JSON parsing; `static-validate` (guard evaluation on synthetic names/hosts, app-guard source assertions, template/runbook/exit-code checks, manifest == live inventory: 0 differences); `plan` dry run (no docker/DB, shows paths/argv, no assignment problems); one refusal invocation of `run` (exit 2, nothing started); `git diff --check` (clean); `sh -n bin/pc`. Read-only `docker ps/inspect` earlier in the session.
**Not executed (intentionally):** PHPUnit, Vitest, `tools/parallel-test/tests` (source only), any runner/discovery/lease/prepare/provision/cleanup, `npm`, DB connections, container changes, production access.

## H. Change inventory (sha256 prefixes)
Modified application test-support: `TestDatabaseGuard.php`, `TestRunLock.php`, `TestDatabaseGuardTest.php`. New/changed tooling and docs under `tools/parallel-test/` (`discover.py`, `php/db-lease.php` new). Docs updated: `ARCHITECTURE`, `OWNER-RUNBOOK`, `SAFETY-AND-ROLLBACK`, `DB-PROVISIONING-PLAN`, `KNOWN-LIMITATIONS`, this report. Staging package untouched.
| File | Baseline | Final |
|---|---|---|
| `backend/tests/Support/TestDatabaseGuard.php` | a4d3dadaf29e | a3e55d7703bb |
| `backend/tests/Support/TestRunLock.php` | 2ab285fbfab5 | 442fb702ba26 |
| `backend/tests/Unit/TestDatabaseGuardTest.php` | d809153aa513 | 8ff15618d2d1 |
| `tools/parallel-test/pctest/__init__.py` | e3b0c44298fc | e3b0c44298fc |
| `tools/parallel-test/pctest/aggregate.py` | 99c1ace3d0f9 | 0c73f15ba4bd |
| `tools/parallel-test/pctest/cli.py` | 6e4ae997f1c7 | 46ebb8781536 |
| `tools/parallel-test/pctest/common.py` | 612834eb61d2 | 6710e1b84296 |
| `tools/parallel-test/pctest/discover.py` | — (new) | c3da18a0832c |
| `tools/parallel-test/pctest/guards.py` | 51445a45e4f6 | 9c9e8647aa36 |
| `tools/parallel-test/pctest/manifest.py` | ea8a28939113 | 5598d2f31d2c |
| `tools/parallel-test/pctest/plan.py` | babacf005d9b | 92acd4215542 |
| `tools/parallel-test/pctest/provision.py` | 07d70ecece64 | 628e58a86b0a |
| `tools/parallel-test/pctest/runner.py` | a97d5b847cf8 | 6394a3ec59e9 |
| `tools/parallel-test/php/db-lease.php` | — (new) | f258efc2e5e4 |
| `tools/parallel-test/php/db-preflight.php` | — (new) | 6b2398ff8c0a |
| `tools/parallel-test/config/scheduler.json` | 737edf0d64f9 | 8ad7af669b97 |
| `tools/parallel-test/config/serialized-rules.json` | 3c95b4bea398 | 3c95b4bea398 |
| `tools/parallel-test/manifest/manifest.json` | 694be044ca13 | 6f1b9e4c7c9a |
Concurrently-owned changes (`ConcurrencyHarness.php`, actor, app code, tests) untouched.

## I. Remaining risks
Runtime items in KNOWN-LIMITATIONS §1, §9–12. Pending Owner decisions: accept guard tightening (sockets), approve `--ignore-scripts` for the Linux tree, root-secret use for provisioning/cleanup, test-user grants. No unresolved safety defect known statically; no new P0 found.

## J. Handover for Codex
Audit: `backend/tests/Support/TestDatabaseGuard.php`, `TestRunLock.php`, `tests/Unit/TestDatabaseGuardTest.php`; `tools/parallel-test/pctest/{runner,plan,aggregate,discover,provision,guards,cli}.py`, `php/db-lease.php`, `php/db-preflight.php`, `env/worker.env.template`, `config/scheduler.json`, `docs/OWNER-RUNBOOK.md`.
Acceptance: each row in §B reproducible by reading source; no bypass between components (e.g. lease name ↔ `TestRunLock` prefix `primeclassy:testrun:`; marker env ↔ `-e` flags).
Confirmations: no test ran · no database created/changed/connected · no production access · no commit/push/deploy.
