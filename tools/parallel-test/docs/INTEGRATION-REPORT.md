# Integration report (static; 2026-11-11)

Verdict: **INTEGRATED — PENDING CODEX SAFETY AUDIT**. Not "ready for Owner execution".
Baseline: branch `main`, HEAD `d7997c4e6a32ee64bc1c3075a021037c5dfa11c2`, working tree dirty (≈119 paths, preserved; no reset/stash/clean/checkout).

## Mapping staging → repository
| Staging (`pc-parallel-test-v1`, kept unchanged as reference) | Repository | Change |
|---|---|---|
| pctest/{common,guards,manifest,aggregate}.py | tools/parallel-test/pctest/ | guards: host allowlist (+`primeclassy-mariadb`), out-dir guard; others as staged |
| pctest/plan.py, runner.py, provision.py, cli.py | same | **revised**: docker-exec/docker-run adapters, host↔container PathMap, in-container read-only DB probe, mariadb-container provisioning, removed host `php`/`mysql` assumptions |
| config/scheduler.json | same | + db host, adapters, out root, privilege list, hosts |
| config/serialized-rules.json, env/, schema/, examples/, tests/, bin/pc | same | unchanged |
| — | tools/parallel-test/php/db-preflight.php | new (read-only probe, Stage B) |
| manifest/manifest.json | tools/parallel-test/manifest/ | regenerated: digest `b41671699d829f99649b8b48ec3d792ad5c8357c1ad70987dea4d1aa7c32b454` |
| docs/* | tools/parallel-test/docs/ | rewritten/extended |
| proposed TestDatabaseGuard patch | backend/tests/Support/TestDatabaseGuard.php | **revised** (explicit map, host allowlist, fail-closed ids) |
Staging patch revision rationale: original used a single `1..9` digit and no host rule; the integrated guard uses an explicit w1..w5 map (spec §6), rejects malformed ids, adds the host allowlist required by spec §6.3 and by the real topology (`primeclassy-mariadb`).

## Files added / modified in the repository
Modified: `backend/tests/Support/TestDatabaseGuard.php`, `backend/tests/Support/TestRunLock.php` (1 line: passes host + worker to the guard), `backend/tests/Unit/TestDatabaseGuardTest.php` (+3 test methods/18+ cases, existing ones untouched), `docs/14-TESTING-STATUS.md` (+ short section).
Added: `tools/parallel-test/**` (see README).
Pre-existing uncommitted Codex/Owner changes (incl. `ConcurrencyHarness.php`, actor) untouched.

## Inventory (static, regenerated)
PHP files 208 (178 parallel / 30 serial), Vitest files 34; lower-bound cases 1770 PHP + 269 Vitest; 8 files with datasets. Versus the staging manifest: no files added/removed, no lane changes, 2 content hashes changed. Warnings: 0.

## Static validation performed
`php -l` in `primeclassy-fpm` (3 changed PHP files): clean. `git diff --check`: see final report. `bin/pc static-validate` (python syntax, JSON, guard evaluation on synthetic names/hosts, app-guard source checks: explicit w1..w5, no `preg_match`/prefix, host lists equal, manifest == live inventory): OK. `bin/pc plan` dry run: assignments complete/no duplicates; adapters `docker-exec`/`docker-run`. Read-only `docker ps`/`docker inspect` used to learn topology.
NOT performed: PHPUnit, Vitest, any test (incl. tooling self-tests), runner execution, DB connection/creation/migration, container start, browser, production access.

## Remaining blockers (before Owner activation)
Codex safety audit; Owner decision on guard patch acceptance; worker DB provisioning + privilege verification; Node/native-binding resolution; runtime validation items in KNOWN-LIMITATIONS §1.
