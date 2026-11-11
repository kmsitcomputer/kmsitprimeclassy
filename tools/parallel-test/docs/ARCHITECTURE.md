# Architecture

```
manifest.json (static, reviewed; Owner approval is bound to its digest)
   │  Stage B preflight: guards · live inventory == manifest · adapters · DBs exist / locks free / privileges · no other runner
   ├─ PHASE P  ≤5 concurrent slots: w1..w5 → primeclassy_testing_w1..w5, generated explicit-file phpunit configs (+ vitest on one slot)
   └─ PHASE S  only after P fully exited: 30 serial files on primeclassy_testing via scripts/run-tests-serialized.php
   → aggregate (fail-closed) → summary.json / summary.md
```

## Execution adapters (verified statically against `docker inspect`/`ps`, 2026-11-11)
| Need | Adapter | Facts / mapping |
|---|---|---|
| PHP | `docker exec` into the EXISTING `primeclassy-fpm` (PHP 8.3.36, user root, no git/node) | only `backend/` is bind-mounted: host `…/apps/primeclassy/backend` ⇄ `/var/www/app/backend` (rw). Generated configs and all output therefore live under `backend/storage/framework/testing/parallel/run-<id>/` (git-ignored by `storage/framework/testing/.gitignore`). Container sees paths via `PathMap`. |
| Database | `primeclassy-mariadb` (MariaDB 10.11) on `primeclassy-net`; tests reach it as `DB_HOST=primeclassy-mariadb` (`.env.testing` says `127.0.0.1`, valid only inside the DB container → override via `-e`, same as the Codex/agent procedure) | not published to the host |
| Node | ephemeral `docker run --rm node:24-alpine`, `--network none`, bind mounts: `frontend/` and `<out>/junit`; no ports, no named volumes; image must already exist locally (never pulled) | previously approved temporary environment. **RUNTIME VALIDATION PENDING**: host `node_modules` carries darwin-only native deps (lightningcss-darwin-x64, @rolldown), the earlier Vitest runs by Codex succeeded somehow in this container model, the mechanism is not documented. A failure is reported FAIL, never PASS. |
`adapters.*.mode: host` exists for each, but `php`/`node`/`npm` are not on this machine's PATH.

## Assignment and generated configs
Deterministic LPT over per-class seconds from a recorded full run (timing evidence), heuristic fallback. Explicit `<file>` lists per worker — a new, removed or modified test file makes the inventory check refuse before anything starts. Repo `phpunit.xml` `<php>` settings are copied; isolation keys are `force="true"`.

## TestDatabaseGuard (integrated, `backend/tests/Support/TestDatabaseGuard.php`)
* Worker id = env `PRIMECLASSY_PARALLEL_WORKER`. Unset/`''`/`'0'` ⇒ canonical `primeclassy_testing` (all existing behaviour). `'1'..'5'` ⇒ exactly `primeclassy_testing_w{N}` from an **explicit constant map** (no regex/prefix; the existing unit test forbids `preg_match`). Any other id (`6`, `01`, `w1`, `10`) ⇒ REFUSED.
* `APP_ENV` must be `testing`; connection `mysql|mariadb`; database must equal the worker's own; a worker can never use another worker's or the canonical DB; `primeclassy_db`, `primeclassy_dev`, `sql_prime` etc. fail by equality. No fallback anywhere.
* New host rule: DB host ∈ `{127.0.0.1, localhost, ::1, primeclassy-mariadb}`. Mandatory in worker mode; in canonical mode checked whenever resolvable (hosts unknown/absent — socket connections — keep working). Production endpoints fail.
* `TestRunLock` passes host + worker id to the same guard; its named lock is `primeclassy:testrun:<db>` ⇒ distinct per worker; its metadata file lives in `storage_path()` ⇒ per worker.
* Not changed: `ConcurrencyHarness` / actor (still hard-wired to the canonical DB ⇒ those tests stay serial; the guard's worker mode is deliberately incompatible with them), assertions, fixtures, `phpunit.xml`, `.env.testing`, `scripts/run-tests-serialized.php`.
* Compatibility proof is **static only**: `php -l` clean in the container; new unit cases added to `tests/Unit/TestDatabaseGuardTest.php` — **not executed** (Owner/Codex scoped run pending).

## Worker isolation matrix
| Resource | Isolation | Evidence |
|---|---|---|
| Database | `primeclassy_testing_wN`; guard + preflight (exists, lock free, privileges) | guard, runner |
| Run lock | per-DB named lock | `TestRunLock` |
| Laravel storage (logs, views, `Storage::fake`, disks, run-lock metadata) | `LARAVEL_STORAGE_PATH=<out>/worker-N/storage` (also `force` in phpunit config) | `Application::storagePath()` honours `$_ENV/$_SERVER`; `filesystems.php` roots use `storage_path()`; every test using `Storage::` fakes it except 3 serial files |
| Temp files | `TMPDIR` per worker; tests use `tempnam(sys_get_temp_dir())` | grep |
| Cache / session / queue / mail | `array`/`array`/`sync`/`array` (in-process) + per-worker prefixes | `phpunit.xml`, template. `GeocodeTest` uses only `Cache::store('array')` |
| Redis | not used by config/tests (shared `kmsit-shared-redis` exists but is not referenced) | grep |
| HTTP/external | all HTTP tests use `Http::fake` (0 files without) | grep |
| Fixed ports / sockets | none found in tests | grep |
| Shared repo files | `installed.lock`, `framework/testing/concurrency/*` (wiped by `stopLingeringTestProcesses`), `.env` writer → serial lane | rules |
| JUnit / logs / status / metadata | per worker under `<out>/` | runner |
| PHPUnit result cache | `--do-not-cache-result` (no shared `.phpunit.result.cache`) | config |
| Test-user identities | fixtures are created per DB; shared DB user `primeclassy_test` only | — |
| Application code writing outside storage (`EnvFileWriter`, `InstallLock`) | only reachable from `InstallerTest`/pre-install tests → serial | grep |
Not proven at runtime: see KNOWN-LIMITATIONS §1.

## Aggregation
Verdict `PASS` only if every job exited 0, every report parsed, every manifest file has results, no duplicate/unknown/wrong-job results, zero fail/error/skip, no filters. Skips ⇒ `PASS_WITH_SKIPS`; crashes/non-zero ⇒ `FAIL`; anything unreconciled ⇒ `INCOMPLETE`. Assertions are only summed from JUnit attributes. `discovered_test_cases` stays `null` until Owner runtime discovery is reconciled.


## REM-PT-001 additions (F01–F08) — see `REM-PT-001-REMEDIATION-REPORT.md`
* **Effective DB target (F01):** `TestDatabaseGuard::assertApplicationSafe()` resolves the default connection through Laravel's own `ConfigurationUrlParser` (as `DatabaseManager::configuration()` does) and refuses any `url`/`DB_URL`/`DATABASE_URL`, read/write splitting, unix sockets, a port ≠ 3306, a driver ≠ connection, and unsafe sibling mysql/mariadb connections; it returns the validated config, which `TestRunLock` then uses (same target for lock and app). Worker env passes `DB_URL=`, `DATABASE_URL=`, `DB_SOCKET=` explicitly empty and `force`s them in the generated phpunit config; preflight refuses a container/`.env*` that defines them.
* **Bootstrap cache (F05):** `APP_SERVICES_CACHE`, `APP_PACKAGES_CACHE`, `APP_CONFIG_CACHE`, `APP_ROUTES_CACHE`, `APP_EVENTS_CACHE` → `<worker>/bootstrap-cache/*` (Laravel 11.56 `Application::normalizeCachePath` reads these via `Env::get`; absolute paths are used as-is). `backend/bootstrap/cache` is never written; a DEV `config:cache` file cannot leak in.
* **Lifecycle (F03/F06):** run-owned processes are identified by the environment markers `PC_RUN_ID`/`PC_JOB_ID` (inherited by descendants), stopped TERM→KILL and *proven* gone; serial lane starts only after a verified barrier; failures → exit 4 BLOCKED. Runner lease = server-side named locks `primeclassy:parallelrun:<db>` (session-bound); cleanup takes lease + `TestRunLock` names first, in one session.
* **Coverage (F02):** mandatory runtime discovery + identity-set reconciliation; static counts are informational only.
* **Frontend (F07):** Linux musl `node_modules` prepared separately and mounted over `/work/node_modules`.


## Current Codex correction layer

This section supersedes earlier descriptions where they differ. See OWNER-RUNBOOK and the Codex final report for the current protocol. TestCase validates configuration/acquires the DB lock before provider boot, then selected/dynamic connections and SQL entry points enforce the held target. Both serial and parallel Laravel storage/bootstrap caches are run-scoped (outer serial runner metadata remains canonical).

Runtime discovery is supervised and parsed before any worker test starts: PHPUnit XML and Vitest collection API with staticParse:false, including skipped cases. All file assignments and testcase identities are reconciled independently. No PASS artifact may precede verified termination; terminal BLOCKED/ABORTED states survive offline aggregation.

Container signals use a Python>=3.9 Linux pidfd controller, exact run+job markers and strict /proc error handling. The DB lease has its own marker and server-side ownership heartbeat. Crashed/unverified runs block cleanup even after their lease disappears. STOPPED is written only after quiescence; no automatic destructive crash recovery.

Frontend preparation and testing share a host lease. Prepared dependencies are separate, content-hashed, package/lock/image-pinned, native-version/ELF-checked, and mounted over node_modules; frontend source is read-only during installation. No npm action occurred in the audit. Runtime capability, PHP lint (no host PHP), collection API behavior, signals, DB grants and Docker identity remain unverified.
