# Codex final independent infrastructure safety audit and bounded auto-remediation

## Executive summary and verdict

**STATIC SAFETY AUDIT PASS — AUTO REMEDIATION COMPLETE — RUNTIME VALIDATION PENDING**

The independent review found additional concrete defects after Claude REM-PT-001 and corrected them within testing infrastructure. This verdict concerns source-level safety, fail-closed behavior and static inventory only. It is not Full Test PASS, production readiness, or authorization to activate testing/provisioning.

No PHPUnit, Vitest, tooling test, synthetic harness, runtime discovery, runner, preflight, database operation, dependency installation, container execution or production access occurred in this audit. New regression tests were written as source and were NOT run. PHP syntax lint is NOT VERIFIED: host PHP was unavailable; using docker exec for lint was prohibited by the active request.

## Baseline, authority, scope and independence

- Session environment date: 2026-11-11. Branch `main`, HEAD `d7997c4e6a32ee64bc1c3075a021037c5dfa11c2`; unchanged and uncommitted.
- The substantial pre-existing dirty tree (Owner/Claude/Codex changes) was preserved. HEAD alone does not represent the audited implementation.
- Primary baseline: `docs/REM-PT-001-REMEDIATION-REPORT.md` inside this package; integration report, Owner runbook, current source and installed framework/dependency code. The prior Codex F01–F08 definitions are in the conversation and REM report, as explicitly permitted by the current Owner request; no older unrelated report was substituted.
- Initial SHA-256 map covered package files, top-level backend test support, guard unit test and testing-status documentation. Actor/TestCase/probe paths were added when their bootstrap/connection dependency was identified; their source was read before patching, but no initial hash was captured for these four expanded paths. This limitation is not hidden as a clean Git baseline.
- Ownership: runner/discovery/aggregation/lifecycle/provisioning safety, testing-only bootstrap/guards/probes, source regressions and related docs. Business services, migrations, package versions/lockfile, environment/credentials, app UI, and runtime data were not edited.
- No unexpected concurrent change was observed in the captured hashes. `ConcurrencyHarness.php` is hash-identical to the initial snapshot; actor edits are limited to pre-provider safety checks/listeners, without changing roles, barriers, domain lock order, race operations or acquiring the parent's runner lock.
- The review was independent of Claude's claims. Codex's own patches received a subsequent source review by the same agent; a separate independent review of those patches was not performed. No Human stage gate or runtime approval is implied.

## F01–F08 verification matrix

| Finding | Independent source assessment | Final state |
|---|---|---|
| F01 P0 DB target | Claude's URL/parser/socket/read-write/port restrictions retained. Added validation before provider boot, all selected/dynamic connections and SQL entry; target equality with the held lock; standalone actor/probe checks; strict privilege preflight. | Closed statically; actual server/user/grants NOT VERIFIED |
| F02 P1 discovery | PHPUnit identity format agrees with installed renderer/event source. Discovery is now supervised and parsed before tests; Vitest collection expands datasets and retains skips. Aggregator independently checks file assignment, identities, terminal status and termination proof. | Closed statically; discovery/JUnit execution NOT VERIFIED |
| F03 P1 lifecycle | Fixed lease being counted as a worker, scan failure-as-empty, job-only ownership and numeric PID reuse. Added pidfd signalling, exact run+job ownership, discovery supervision and final artifact ordering. | Closed statically; new controller prerequisites and real signals NOT VERIFIED |
| F04 P1 strict keys | Claude's strict integer-key expectation and assertCount(5) are correct and retained. Four additional guard regression methods are source-only. | Existing fix verified from source; no PHPUnit run |
| F05 P1 cache isolation | Five APP_*_CACHE names match installed Laravel. Parallel AND serial Laravel storage/caches are scoped; fixed roots/traversal/symlink/nested-run protections added. | Closed statically; actor/storage integration NOT VERIFIED |
| F06 P1 cleanup lease | Existing all-target SQL lease+test-lock-before-DROP order retained. Added server ownership heartbeats and verified STOPPED-history barriers, including for ad-hoc test/probe entry points. A released lease alone never permits DROP. | Closed statically; SQL/connection-loss behavior NOT VERIFIED |
| F07 P1 native deps | Separate Linux tree retained; strengthened preparation/execution coordination, crash markers, no-pull image pin, content/package hashes, version/ELF checks and readonly install source. | Closed statically; preparation/Node runtime NOT VERIFIED |
| F08 P2 exits | Explicit refusal/error/incomplete/blocked/abort handling and terminal summaries; malformed status fails closed. Offline aggregation cannot upgrade blocked/aborted results. | Closed statically; exit behavior not executed |

## Verified defects and bounded corrections

Every item below was established by a concrete source path. Acceptance criteria were checked by source inspection/allowed parsing only; **runtime verification for every item remains pending**.

### PT-A01 — P0: guard timing and selectable-connection bypass

Root cause: TestCase/actor kernel boot could reach providers before effective-target validation; non-MySQL and dynamically selected connections were not protected by the initial default-config check. Guard/lock reuse could also validate one default target while retaining a lock on another.

Evidence/fix: `backend/tests/TestCase.php:18`; `backend/tests/Support/TestDatabaseGuard.php:218`; `backend/.phpunit-concurrency-actor.php:186`; `backend/tests/Support/TestRunLock.php:88`. The pre-RegisterProviders callback validates configuration, installs connection-established and pre-SQL guards, then acquires the runner lock before providers query. Connection endpoint/database/credentials must match that lock. Actors freeze a validated target instead and deliberately do not acquire the parent's lock. Standalone schema/lock probes validate explicit testing host/user/port/history before PDO. No URL or socket fallback was added.

Acceptance: known unsafe default/sibling/dynamic/late-retarget paths fail before provider SQL or PDO query execution, and lock/application targets agree. Added source regressions for dynamic non-MySQL selection, late retargeting, actor URL-before-boot refusal and unresolved history. Raw arbitrary PHP/PDO code is not claimed to be an OS sandbox; testing credentials are separately constrained by privilege checks and actual server identity needs Owner runtime verification.

### PT-A02 — P1: discovery existence was mistaken for valid complete discovery

Root cause: successful command/file existence was checked before tests, but malformed/duplicate discovery was not parsed until aggregation. Installed Vitest5 list defaults to static parsing and excludes skipped definitions, so it cannot reliably represent expanded runtime datasets/skips.

Evidence/fix: `tools/parallel-test/pctest/runner.py:553`; `tools/parallel-test/container/discover-vitest.mjs:7`. Discovery uses supervised owned processes with abort/timeout/lease checks. Every artifact is parsed and duplicate-checked before any worker starts. Vitest uses the installed public collect API with `staticParse:false`, enumerating all definitions including skips, and validates module/collection errors. PHP's installed list XML/JUnit normalization is retained; whitespace/Unicode/named datasets are not normalized away. Any unreconciled identity is INCOMPLETE.

Acceptance: failed/malformed/duplicate discovery starts no test bodies; missing/unexpected/duplicate final identities cannot PASS. Collection executes definition/provider code, so documentation no longer claims arbitrary discovery can never bootstrap applications or access services. Discovery was not executed here.

### PT-A03 — P1: process ownership/termination proof was unsafe

Root cause: shell tr/grep failures/unreadable environ could return success with an empty list; job names repeat across runs; numeric PID signalling could target a reused PID. The lease carried PC_RUN_ID and therefore made the serial barrier report its own lease as a surviving worker.

Evidence/fix: `tools/parallel-test/pctest/runner.py:311`; `tools/parallel-test/container/process_control.py:39`. Controller scans fail closed on unreadable live entries, requires exact run+job ownership for a job, opens a pidfd, revalidates identity/start time, and signals via the pinned descriptor. No broad pkill/numeric-PID fallback. Lease has PC_LEASE_RUN_ID instead. All cleanup attempts are made even if one fails; unsupported control or unverifiable survivors become BLOCKED and tmp is retained. Source regressions cover scan failure, job-only authority, survivors and PID reuse without actual signals.

Acceptance: an enumeration failure is never interpreted as no processes; no other run's job or recycled PID is signalled; unknown termination blocks further work and destructive cleanup. Runtime prerequisite: Python>=3.9 plus Linux pidfd/proc permissions inside the existing PHP container. This prerequisite was not installed or probed during the audit; absence causes preflight refusal.

### PT-A04 — P1: lease liveness/completion records could permit orphan cleanup

Root cause: a live lease process did not prove that its MariaDB session still owned locks. SIGKILL can release a parent's lease while children survive. run.json was written before shutdown and BLOCKED directories were exempted from cleanup refusal.

Evidence/fix: `tools/parallel-test/pctest/runner.py:408`; `tools/parallel-test/pctest/cli.py:279`; `backend/tests/Support/TestDatabaseGuard.php:188`. PING/OWNED checks prove IS_USED_LOCK=CONNECTION_ID during discovery/phases and finalization. STOPPED.json is issued only after proven quiescence. New runs, dependency preparation and cleanup refuse unresolved history; ad-hoc TestCase/actors/probes also refuse other unresolved runs. run.json/BLOCKED alone are not stop proof. Existing zero-wait all-target lease/test locks remain held across DDL.

Acceptance: killing a runner does not automatically authorize DROP or a new ad-hoc schema reset. Unknown history requires separately approved operator recovery; no marker fabrication, history deletion or automatic destructive recovery. No SQL/lease process ran here.

### PT-A05 — P1: result artifacts could retain or manufacture false PASS

Root cause: an early aggregate could write PASS before final termination failed, and offline aggregation could ignore terminal runner state. Invalid JSON status was uncaught; false (boolean) could compare as exit 0; caller-supplied empty/incomplete job plans were not independently reconciled.

Evidence/fix: `tools/parallel-test/pctest/aggregate.py:62`. Aggregation requires matching STOPPED proof, independently assigns every manifest file once, rejects duplicate job/file assignment, checks exact job/run identity and int/bool terminal fields and JUnit suite/testcase counts, and preserves BLOCKED/ABORTED terminal states. No early PASS is written by execute. Missing/corrupt status yields INCOMPLETE; unknown terminal accounting is null rather than invented zero. Source regressions cover malformed/bool status, empty plans, absent stop proof and offline blocked-state preservation.

Acceptance: no PASS/PASS_WITH_SKIPS before verified shutdown and complete reconciliation; skips remain distinct. The summary schema and exit mapping support BLOCKED/ABORTED. No aggregator test or synthetic harness was executed.

### PT-A06 — P1: dependency preparation could race a run or reuse stale success

Root cause: a prior marker could survive a failed/interrupted npm ci; content was not fingerprinted; preparation could overlap testing. Mutable image tags/marker architecture were insufficient, and installation mounted frontend source writable.

Evidence/fix: `tools/parallel-test/pctest/cli.py:182`; `tools/parallel-test/pctest/runner.py:234`. Explicit Owner gates precede a shared dependency file lease. Preparation invalidates success first; PREPARING marker survives crashes/unknown shutdown. Frontend source is readonly; Linux tree stays separate. Image ID is pinned with --pull=never. Preflight checks package/lock/content digest, current image ID/architecture/Node metadata, native package name/version and ELF64 machine. No npm/package/version/lock change occurred.

Acceptance: missing/stale/changed/escaping/incompatible trees refuse before tests; failed preparation cannot reuse an old success marker; no automatic install/pull/network in run. Prepared marker is currently absent (filesystem observation only). Strict hashing may require new reviewed preparation after generated dependency-cache changes; no silent cache exclusion weakens integrity.

### PT-A07 — P1: path isolation remained incomplete

Root cause: lexical prefix checks permitted traversal, root symlinks/nested run destinations, or a Linux node_modules symlink to the host tree; serial Laravel storage still defaulted to DEV runtime storage.

Evidence/fix: `tools/parallel-test/pctest/guards.py:128`. Output/dependency roots are fixed; output is a fresh direct run-ID child; symlink/traversal/nested destinations are refused. Serial Laravel storage is retained in its scoped environment, along with all five cache paths. Actors inherit these values through the unchanged Symfony Process inheritance behavior. Outer serialized-runner lock/report metadata retains its existing canonical testing directory.

Acceptance: known generated path routes cannot escape into DEV storage/bootstrap cache or another run's tmp. This provides logical resource isolation, not a hostile-code filesystem sandbox; container/source permissions still require Owner runtime review.

### PT-A08 — P0: privilege preflight accepted non-testing access/schema patterns

Root cause: the minimum-privilege check accepted global privileges, and database GRANT names with unescaped underscores are wildcard patterns in MariaDB.

Evidence/fix: `tools/parallel-test/pctest/runner.py:176`; `pctest/provision.py`. Reject global privileges except USAGE, role grants, GRANT OPTION, non-testing/schema-wildcard grants. Future generated worker grants escape underscores, validate privileges/identifiers and quote/restrict the root-secret path. No existing grant, credential or database was changed.

Acceptance: effective test credentials have only approved exact testing-schema access; malformed privilege/secret inputs are refused. Actual grants and MariaDB escaping/DDL execution remain unverified and require a separate Owner step. Root-secret use is never automatically authorized by this report.

### PT-A09 — P2: exit/reporting and adjacent argument inconsistencies

Root cause: unexpected preflight/preparation errors and finalization errors did not share a reliable terminal contract; forbidden-list-only checking allowed alternate CLI flags; host execution lacked container ownership proof.

Fix: preflight exceptions refuse with 2; test/worker failure=1, incomplete=3, unknown termination/finalization=4, verified abort=130. Only reviewed PHP diagnostic extras and generated Vitest arguments are accepted; direct installed Vitest CLI avoids npm-script selection overrides. Host PHP adapter refuses. Inherited PHPRC/PHP_INI_SCAN_DIR are not forwarded. No source regression was run.

Acceptance: failures cannot become PASS, runtime blockers do not degrade into an unlabeled ordinary failure, and unknown selection/config arguments refuse before execution. Runbook/schema reflect the contract.

## Inventory and manifest reconciliation

- Baseline manifest: `ea52aa2ce7bb6d2536c936d5efe1c36d9f5720dead670c92956ae2c99c36d8ba`.
- Final manifest: `4e79997ca00c7a2c048e66dcead6ad363a19bf4481ada918e9b70978abd821c5`.
- Actual inventory: 208 PHPUnit files = 178 parallel + 30 serial; 34 Vitest specs; 28 actor-rule files, all serial; 8 files with datasets/parameterization.
- Independently checked file sets, assignment duplication, SHA-256 and digest reconstruction: no missing/extra/duplicate files, no hash drift, digest matches. Warnings: 0.
- Static lower bounds: 1,780 PHP methods and 269 Vitest calls. These are NOT runtime discovered/executed totals. Four guard test methods were added; no business test was removed/excluded/weakened.
- Manifest was regenerated using the audited static manifest builder (source/JSON reads, read-only git queries and writing this manifest only). No runner, runtime discovery, plan, static-validate, preflight or test command was invoked.

## Static validation evidence

1. Python `ast.parse` of all 12 Python files in this package, including helper and regression sources: OK; modules/tests were not executed by that validation.
2. JSON parsing of all 5 package JSON files: OK.
3. Independent file/hash/count/digest reconciliation: OK as above.
4. `sh -n tools/parallel-test/bin/pc`: exit 0; shell body not executed.
5. `git diff --check`: exit 0.
6. Host `command -v php`: unavailable. PHP syntax lint NOT VERIFIED; no docker exec was used. JS helper syntax/API were source-reviewed against installed Vitest5 code, not executed.
7. Installed source read: Laravel Application/DatabaseManager/ConfigurationUrlParser/Connection events and pre-query callbacks; PHPUnit ListTestsAsXmlCommand/TestMethod/JUnit behavior; Vitest createVitest/collect/TestCollection/JUnit behavior. No vendor source was edited.

These are static results only. No test count/assertion count is reported as passing. Owner explicitly requested static-only validation, overriding the ordinary scoped-test execution policy for this task.

## Runtime limitations and Owner decisions

- Python/pidfd/proc availability/permissions in the PHP container: NOT VERIFIED. If missing, approve an environment change separately or keep execution refused. No container was inspected/executed/changed in this audit.
- PHP syntax lint, early guard/provider/event integration, actor/probe behavior, serial scoped storage propagation, all regression sources and real two-connection scenarios remain unexecuted.
- Actual PHP/Node binary versions, immutable image identity, native-loader behavior, Vitest dynamic collection/JUnit parity (including Unicode/skips/datasets) and no body execution during collection require separate runtime validation.
- Physical database server identity is not proven by host/port/schema naming. Actual credentials/grants, lease heartbeat/disconnect semantics, SQL batch refusal-before-DROP and provisioning permissions remain NOT VERIFIED. No DB connection was made.
- Linux dependency preparation is not done. Owner must separately accept/authorize the preparation policy (`--ignore-scripts --engine-strict`), any necessary native/install-script adjustment, network use and ephemeral container execution. No package version/lock policy was changed.
- Root-secret usage, canonical/worker grant review, provisioning and cleanup each require their own explicit Owner authorization. Existing grants may be rejected by the new strict policy; no automatic privilege repair occurs.
- Unresolved run/preparation history requires operator recovery; no automatic bypass/delete is provided. Do not fabricate stop markers.
- Source hashes/manifest are not a full immutable application build attestation. Review the dirty application changes and obtain normal independent review/Human stage gates separately. No benchmark or production readiness is claimed.

## Exact changed files and hash provenance

The table is relative to the captured pre-edit dirty worktree, not a claim that the entire HEAD diff belongs to Codex. A dash means a new file or a dependency path added to scope without an initial captured hash. Full source business/application files were not reset, stashed, committed or overwritten.

| File | Initial SHA-256 prefix | Final SHA-256 prefix |
|---|---|---|
| `backend/.phpunit-concurrency-actor.php` | — | 42acb39c121a |
| `backend/tests/Support/TestDatabaseGuard.php` | a3e55d7703bb | 45ee2e5f134f |
| `backend/tests/Support/TestRunLock.php` | 442fb702ba26 | a25c0ee1035d |
| `backend/tests/Support/bin/lock-probe.php` | — | 50ef0cea64b4 |
| `backend/tests/Support/bin/schema-probe.php` | — | 3238433fcde7 |
| `backend/tests/TestCase.php` | — | ce1f3e85a358 |
| `backend/tests/Unit/TestDatabaseGuardTest.php` | 8ff15618d2d1 | e586c05e61a7 |
| `docs/14-TESTING-STATUS.md` | e34aa1d858c6 | fb353d11a1cb |
| `tools/parallel-test/README.md` | a9152e8635cb | 5d21db6df377 |
| `tools/parallel-test/config/scheduler.json` | 8ad7af669b97 | e66767738072 |
| `tools/parallel-test/container/discover-vitest.mjs` | — | 1767d62f2e6f |
| `tools/parallel-test/container/process_control.py` | — | da0afd6ac46a |
| `tools/parallel-test/docs/ARCHITECTURE.md` | 96deae49010a | 6971da967e19 |
| `tools/parallel-test/docs/DB-PROVISIONING-PLAN.md` | 8dd24ce9810c | 4d2e62803f51 |
| `tools/parallel-test/docs/KNOWN-LIMITATIONS.md` | 312c2aa51ddf | fa695ee11c29 |
| `tools/parallel-test/docs/OWNER-RUNBOOK.md` | 062a73004f6b | f57379332f02 |
| `tools/parallel-test/docs/SAFETY-AND-ROLLBACK.md` | 0a5bedda0808 | 9a187d4f9f5e |
| `tools/parallel-test/manifest/manifest.json` | 6f1b9e4c7c9a | fbff495965ce |
| `tools/parallel-test/pctest/aggregate.py` | 0c73f15ba4bd | 7ca4e1c4c3e9 |
| `tools/parallel-test/pctest/cli.py` | 46ebb8781536 | ec34b6f4acc2 |
| `tools/parallel-test/pctest/common.py` | 6710e1b84296 | 875e872d70d9 |
| `tools/parallel-test/pctest/guards.py` | 9c9e8647aa36 | 22301aa79392 |
| `tools/parallel-test/pctest/plan.py` | 92acd4215542 | aa1a00a4949f |
| `tools/parallel-test/pctest/provision.py` | 628e58a86b0a | d6ddeca8becf |
| `tools/parallel-test/pctest/runner.py` | 6394a3ec59e9 | eb553f3a45ee |
| `tools/parallel-test/php/db-lease.php` | f258efc2e5e4 | fd74da996546 |
| `tools/parallel-test/schema/summary.schema.json` | 5607fa2854a3 | bdff11eb6e73 |
| `tools/parallel-test/tests/test_tooling.py` | a5d62971817c | 4fdbb5646449 |

This final report is also newly added. The temporary audit checkpoint was folded into this report and removed. Baseline Claude REM-PT-001 report/staging reference remain unchanged.

## Safety confirmation and recommendation

No tests of any kind, runtime discovery, runner/preflight, SQL/DB connection, provisioning/cleanup, migration/seeder, dependency installation, container execution/mutation, service restart, production access, commit/push/merge/rebase/deployment or Woodpecker CI occurred. Existing app financial/inventory business fixes remain intact.

The source-level defects identified in scope are addressed and fail-closed prerequisites are explicit. Proceed only to a separately authorized environment/prerequisite review and controlled runtime validation; this report does not authorize those actions or claim readiness for Owner execution.
