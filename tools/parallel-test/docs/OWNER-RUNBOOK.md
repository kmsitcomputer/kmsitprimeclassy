# Owner execution runbook — STAGE B (documented, NOT executed)

`<PKG>` = `tools/parallel-test`, run from the repo root on the macOS host with `python3`. Nothing below has been run.

## Exit-code contract (all commands; defined in `config/scheduler.json` → `exit_codes`)
| Code | Meaning |
|---|---|
| `0` | PASS / PASS_WITH_SKIPS |
| `1` | FAIL (test/worker failure, runner error) · static-validation/inventory mismatch |
| `2` | REFUSED — usage, missing Owner gate, or preflight refusal; **nothing was started**; message on stderr |
| `3` | INCOMPLETE — coverage not proven (incl. runtime discovery failed; then no test is started) |
| `4` | BLOCKED — termination of workers/processes could not be verified; artifacts kept in the run dir (`BLOCKED.json`); do not start another run until you inspect it |
| `130` | aborted by signal (everything the run owned was stopped and verified; if not verifiable it is `4`) |

## 0. Preconditions
- Independent static audit of this tooling approved by you; `git status`/HEAD recorded.
- Containers `primeclassy-fpm` and `primeclassy-mariadb` already running (the tool never starts persistent services; Owner-approved steps may create ephemeral Node containers). `node:24-alpine` present locally (never pulled by the tool).
- **Linux frontend dependencies prepared** (step 0a). Without them preflight refuses (`FRONTEND_DEPS_NOT_PREPARED`).
- Worker databases provisioned (`DB-PROVISIONING-PLAN.md`) by a separate Owner decision. `backend/.env.testing`: `DB_USERNAME`, `DB_PASSWORD` (never printed), `DB_DATABASE=primeclassy_testing`, and **no** `DB_URL`/`DATABASE_URL`/`DB_SOCKET`.
- No other runner active (AGENTS §7). No unresolved run directory or `.pc-preparing.json` marker.
- The existing PHP container must provide **Python >=3.9, Linux pidfds and permission to inspect /proc environments**. The Owner preflight probes this without signalling anything; missing/denied capability refuses activation. No fallback to numeric PID or broad pattern kills. This prerequisite was NOT runtime-verified by the static audit; installing tools/changing an image needs separate Owner approval.
- Testing credentials must have only exact testing-schema grants (plus global USAGE). Global/role/schema-wildcard/non-testing grants are refused. MariaDB underscores in GRANT names must be escaped. The audit did not read or modify live grants.

### 0a. Linux frontend dependencies (Owner; uses the network; ephemeral container; nothing global installed)
```
python3 -B tools/parallel-test/pctest/cli.py prepare-frontend --i-am-the-owner --confirm "PREPARE PRIMECLASSY LINUX FRONTEND DEPENDENCIES"
```
Runs the separately approved `npm ci --ignore-scripts --engine-strict` in an ephemeral container with **read-only frontend source** and a separate Linux dependency mount. The original host node_modules is untouched. The image is pinned by immutable ID with `--pull=never`. The marker records package.json/lock hashes, image ID/architecture and a digest of dependency contents. Preflight verifies these plus native package versions and ELF64 machine type, and checks image Node version metadata. Network access occurs only in this explicitly approved preparation step; test/discovery containers use network none.

Preparation and testing share a host file lease. Preparation invalidates the previous success marker first; `.pc-preparing.json` survives a crash or unverified container shutdown. Re-preparation/run is refused until recovery is established. Do not remove this marker merely to bypass the refusal. A changed dependency tree (including generated cache changes) requires a reviewed preparation again. Whether `--ignore-scripts` is sufficient remains runtime-unverified and requires Owner acceptance.

## 1. Re-inventory (read-only)
```
python3 -B tools/parallel-test/pctest/cli.py manifest-verify --manifest tools/parallel-test/manifest/manifest.json
python3 -B tools/parallel-test/pctest/cli.py manifest-generate --workers 5 --timing-file <recorded *.stats-*.json> --out tools/parallel-test/manifest/manifest.<date>.json
```
Review `serial_reasons`/`warnings`; note the digest.

## 2. Static validation + dry-run plan (no processes, no DB, no docker)
```
python3 -B tools/parallel-test/pctest/cli.py static-validate --manifest <manifest>
python3 -B tools/parallel-test/pctest/cli.py plan --manifest <manifest> --out-dir backend/storage/framework/testing/parallel
```

## 3. Run — the single activation command (foreground; Ctrl-C aborts safely)
```
python3 -B tools/parallel-test/pctest/cli.py run --manifest <manifest> \
  --out-dir backend/storage/framework/testing/parallel \
  --i-am-the-owner --stage-b-confirm "RUN PRIMECLASSY PARALLEL FULL TEST" --approve-manifest <≥12 hex of the digest>
```
Order: explicit Owner gate → dependency file lease → preflight → database lease → mandatory discovery → validation of every discovery artifact → Phase P → verified barrier → Phase S → verified shutdown → STOPPED.json → aggregation → final run.json → release lease.

PHPUnit uses `--list-tests-xml`. Vitest uses the installed public collection API with `staticParse:false`, including skipped definitions and expanded datasets; unlike `vitest list`, it does not silently exclude skips or rely on static parsing. Collection loads modules/providers and can execute their definition-time code; it is **not** a promise that arbitrary provider/module code cannot bootstrap an app or access services. No discovery is permitted during the static audit. Any discovery error/duplicate/malformed artifact prevents all test bodies from starting.

Serial tests keep the canonical database, but now use run-scoped Laravel storage and bootstrap caches; actors inherit those paths and ownership markers. The unchanged outer serial runner still writes its own canonical lock/report metadata. This path integration needs runtime validation.
Artifacts: `backend/storage/framework/testing/parallel/run-<id>/{summary.json,summary.md,status/,junit/,discovery/,worker-N/logs,plan.json,plan.env.json}`; the serial lane also writes the existing `storage/framework/testing/runs/*`.

## 4. After
`verdict: PASS` requires: every job exit 0, reports parsed, **every discovered test identity executed exactly once and nothing unexpected** (datasets included), complete independent file assignment, valid status identities, no filters, no skips, and a matching verified STOPPED record. BLOCKED/ABORTED cannot become PASS through offline aggregation. Anything else is not PASS. Offline re-aggregation: `cli.py aggregate --run-dir <run-dir> --manifest <manifest>`.

## 5. Database cleanup (optional, Owner)
`cli.py provision cleanup --i-am-the-owner --confirm "DROP PRIMECLASSY TEST WORKER DATABASES"` — takes every runner lease and every `TestRunLock` of `primeclassy_testing_w1..w5` **in one server session before the first DROP** and holds them across the DDL; if any run or test process holds one, the batch aborts with nothing dropped (exit 2). Never targets `primeclassy_testing`, `primeclassy_db` or anything not `_w1.._w5`. Every historical run directory must first have a valid matching STOPPED.json. run.json or BLOCKED.json alone does not authorize DROP: a SIGKILL can release the lease while children survive.

## Aborting / recovery
Ctrl-C/SIGTERM: host clients get TERM→KILL in their own process group. Container control requires exact run+job ownership, revalidates identity using pidfds, escalates TERM→KILL and rescans. Unreadable process state or unsupported pidfds means BLOCKED (4), never an empty process list. The lease uses a separate marker and periodic server-side ownership heartbeats, so it is not mistaken for a worker at the serial barrier.

Completion records and PASS are written only after verified termination. If the runner is killed, or a run/preparation marker remains unresolved, stop: an operator must establish process quiescence and lock state through a separately approved recovery. Do not fabricate STOPPED.json, delete run history, or clear markers to enable DROP. No automatic destructive recovery is provided.


Testing-only actor/probe protection: standalone concurrency actors now validate before provider boot and freeze the effective target without acquiring the parent's runner lock. Schema/lock probes require explicit allowed host/port/testing environment. TestCase, actor and probes also reject unresolved parallel-run history, so a newly started ad-hoc runner cannot reset a database merely because a killed parent's lease disappeared. No actor barrier/domain lock ordering was changed.
