# Safety and rollback

**Modes.** PREPARE (default, every command except `run`/`provision create|cleanup`): static, no process/DB/docker. EXECUTE: needs `--i-am-the-owner` + typed phrase + digest-bound `--approve-manifest`; no automatic transition; no CI/cron/hook invokes it.
**Refusals (nothing starts):** non-testing env; DB not allowlisted or production/DEV-like; host not allowlisted; missing isolation vars/shared resources; inventory ≠ manifest; app guard unpatched; missing/!rw bind mount, no shared network, wrong DB image; vitest image absent; another runner (host ps, `docker top`, leftover `pc-vitest-*`); worker DB missing, run-lock held, or privileges short; out-dir outside `backend/storage/framework/testing/parallel`.
**Process safety.** Foreground only; every path (success, abort, timeout, partial launch, exception) ends in `stop_everything`: host client groups TERM→KILL, ephemeral Node containers `docker rm -f`, processes inside the php container selected ONLY by this run's `PC_RUN_ID` environment marker (no pattern kill), then verified; unverifiable ⇒ exit 4 BLOCKED, artifacts kept, temp dirs kept. Serial lane never starts without a verified barrier. Aggregation cannot PASS after any of this. Per-job timeouts; no retries.
**Cleanup scope.** Only `<run-dir>/worker-*/tmp`, and only after the run is proven stopped. DB cleanup is lock-coordinated (see runbook §5). Databases, logs and artifacts are kept.
**Rollback of this integration (all additive):** revert `backend/tests/Support/TestDatabaseGuard.php` and `TestRunLock.php` (one line) and the added cases in `tests/Unit/TestDatabaseGuardTest.php` via `git diff`/`git checkout -p` **for those three files only**; delete `tools/parallel-test/` and the added section in `docs/14-TESTING-STATUS.md`. Rolling the guard back restores the exact-equality behaviour. Worker databases (if ever provisioned) are removed with `provision cleanup`. No application behaviour, migration or data is affected.


## Codex post-REM-PT-001 safety corrections (authoritative for current source)

Guard/lock validation now runs before RegisterProviders. Every selected/dynamic connection and pre-query configuration is checked; non-MySQL selection is refused, and endpoint/credentials must match the held lock. No application business logic is changed.

Shell process scanning/killing was replaced with a fail-closed Python/Linux pidfd controller (new Owner environment prerequisite, unverified here). Run+job are both required for job control. Lease identity is separate; ownership heartbeats query the server. Discovery is supervised/validated before tests. No PASS exists before STOPPED; blocked/aborted terminal states survive offline aggregation.

A crash/unknown shutdown leaves run/preparation markers blocking future execution, preparation and DB cleanup. Absence of a live lease is never proof of no orphan. Existing SQL locks remain held across DROP, with zero-wait acquisition and all targets locked before any DROP. No database operation was executed in this audit.

Run and serial Laravel storage/cache paths are isolated. Output and dependency roots are fixed, symlink/traversal/nested-run destinations refused. Dependency preparation/run share a file lease; source mount is read-only, images are pinned/no-pull, dependency contents are fingerprinted, native binaries inspected as data. Actual toolchain/server identity still requires Owner runtime validation.

The older three-file rollback list above predates these corrections. Do not apply it as a complete rollback or blindly revert shared files; review the exact changed-file/hash inventory in CODEX-FINAL-SAFETY-AUDIT-AUTO-REMEDIATION.md and preserve unrelated work.


Testing-only actor/probe protection: standalone concurrency actors now validate before provider boot and freeze the effective target without acquiring the parent's runner lock. Schema/lock probes require explicit allowed host/port/testing environment. TestCase, actor and probes also reject unresolved parallel-run history, so a newly started ad-hoc runner cannot reset a database merely because a killed parent's lease disappeared. No actor barrier/domain lock ordering was changed.
