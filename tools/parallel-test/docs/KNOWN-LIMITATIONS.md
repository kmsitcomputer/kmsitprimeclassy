# Known limitations / RUNTIME VALIDATION PENDING

1. **Nothing was run.** Pending at runtime: new guard unit cases and existing `TestDatabaseGuardTest`/`TestRunLockTest` still passing; `LARAVEL_STORAGE_PATH` honoured everywhere (also `$_ENV` population under `docker exec -e`); `RefreshDatabase`/`migrate:fresh` on `_wN`; `docker exec`/`pkill`/`docker top` behaviour; Vitest 5 JUnit shape and `--outputFile.junit` in the node container; PHPUnit warning/risky classification in JUnit; `run-tests-serialized.php` inside the container (it calls `git` helpers; the container has no git — behaviour unverified, may only affect its summary metadata).
2. **Frontend Node environment** — see ARCHITECTURE (darwin-only `node_modules`). `RUNTIME VALIDATION PENDING`.
3. **Privileges of `primeclassy_test`** and its host pattern unknown (no DB connection made). Provisioning needs the MariaDB root secret via the container; root use is an Owner decision.
4. **Speed estimate** (from recorded timings of another host): ≈ 86 min vs 125 min; bounded by the serial lane (≈ 4,340 s of 30 files); contention across 5 workers/1 MariaDB not modelled. One class (`DevSchemaForeignKeyRestorationDdlTest`, ≈ 826 s) is the parallel critical path. The concurrency harness is intentionally untouched ⇒ no further speed-up in this phase.
5. **Inventory** counts are static lower bounds (≥1,770 PHP, ≥269 Vitest); 8 files with datasets; `discovered_test_cases = null`. Manifest drifts with every test edit — regenerate and re-approve.
6. Skips ⇒ `PASS_WITH_SKIPS`, never `PASS`. Aggregator/runner Python unit tests (`tools/parallel-test/tests`) exist but were not executed in this integration.
7. Guard host rule applies in canonical mode only when a host is resolvable; a `unix_socket` setup is therefore not host-checked in canonical mode (same as before).
8. The guard's host allowlist is duplicated (PHP constant and `config/scheduler.json`); `static-validate` asserts they are equal.

## REM-PT-001 residual risks
9. Everything added by the remediation is **static-only**: nothing was executed. Runtime-pending: `ConfigurationUrlParser` use inside the guard under PHPUnit; `docker exec` marker scanning via `/proc/*/environ` (needs `tr`, `grep -x`, `kill` in the container); `GET_LOCK` multi-lock/abort-on-error semantics of the cleanup batch on MariaDB 10.11; `phpunit --list-tests-xml` against this suite (data providers executed); Vitest `list --json` entry shape (identity mapping unverified ⇒ mismatch would yield INCOMPLETE, never PASS); `npm ci --ignore-scripts` producing a complete musl tree; `APP_*_CACHE` handling under `docker exec -e` + phpunit `force`.
10. The guard now refuses unix sockets and any URL in **all** modes (previously unchecked). A developer using `DB_SOCKET` for the canonical database must switch to host/port.
11. pgsql/sqlsrv/sqlite entries of `config/database.php` are not host-validated (tests never use them); only a URL on any connection is refused.
12. `provision create` privileges remain unverified against the existing test-user grants (no DB connection made).


## Current independent static audit limitations

Earlier F03/F06 process/lease and optional-discovery descriptions are superseded by the Codex final report and current runbook. Nothing was executed by Codex except allowed file parsing/hashing, read-only git queries, shell syntax checking, and static manifest reconciliation. No tests (including tooling), runtime discovery, container execution or DB access.

New mandatory runtime prerequisite: Python>=3.9 and Linux pidfd/proc permission in the existing PHP container. Missing capability is fail-closed; installation/image changes are a separate Owner step. No current availability is claimed. Host PHP is absent and container execution was forbidden, so PHP syntax lint remains NOT VERIFIED.

Other pending validation: early provider guard/event listener integration, serial actor propagation of scoped storage/cache and markers, Vitest5 dynamic collection/JUnit identity (including skips/Unicode/datasets), exact image Node/runtime behavior, least-privilege escaped MariaDB grants, lease heartbeat/loss/DDL coordination, pidfd ownership/abort/PID-reuse handling, artifact accounting and all source regressions. No benchmark or runtime PASS is claimed.

Full dependency hashing is intentionally strict; cache-generated dependency-tree changes require a reviewed preparation again. Old interrupted runs/preparations without verified stop markers require operator recovery; do not clear markers to bypass safety gates. Root-secret access, privilege changes, dependency installation and any persistent environment changes still require explicit Owner authorization.
