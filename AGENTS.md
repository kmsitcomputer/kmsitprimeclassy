# AGENTS.md — Prime Classy AI / Developer Governance

Operating contract for every AI coding/review agent and every human developer working in this repository. Keep it practical; the system itself is described in the authoritative docs below.

## 1. Authoritative documentation (read before changing anything)

| Doc | Purpose |
|---|---|
| [README.md](README.md) | Entry point: stack, layout, setup, commands |
| [docs/MASTER-SYSTEM.md](docs/MASTER-SYSTEM.md) | Canonical description of the whole system as it exists |
| [docs/BUSINESS-RULES.md](docs/BUSINESS-RULES.md) | Locked business invariants and the authority matrix |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Technical architecture, services, locking, idempotency |
| [docs/OPERATIONS.md](docs/OPERATIONS.md) | DEV / TEST / PRODUCTION runbook |

Historical specs, checkpoints, audits and reports were removed from the working tree on purpose. **Git history is the archive** (`git log -- docs/`). Do not resurrect a historical decision just because an old file said so.

## 2. Authority order

When sources disagree, use this order:

1. Human decisions and explicit stage-gate approvals.
2. The authoritative docs above (once consistent with source).
3. Current source code, migrations, tests and runtime evidence.
4. Anything in Git history.

**Source reality wins over stale documentation.** If you find the docs wrong, fix the docs in the same change (or flag it) — never "fix" source to match an outdated sentence. If you find source that contradicts a locked business rule in `docs/BUSINESS-RULES.md`, stop and report it instead of silently choosing.

## 3. Roles in the workflow

- **Architect / documentation owner** — owns locked business rules, package scope, orchestration and the Markdown docs; decides whether a finding is in scope.
- **Implementer** — implements an authorized package; follows this file, the docs and the active checkpoint.
- **Independent reviewer + direct fixer** — reviews the completed diff against its baseline. A real in-scope finding is fixed directly (with tests and a commit) — no review → handoff → remediation loops for ordinary findings.
- **Specialist verifier (optional)** — focused concurrency / security / database / diff verification when materially useful. Not a mandatory gate unless the Human asks.

## 4. RECON ONCE and the anti-loop rule

- At the start of a session: read this file and the relevant authoritative docs; run `git status`, `git branch --show-current`, `git rev-parse HEAD`; inspect only the source/tests you need and build a file map **once**.
- Do not re-read the whole repository. Re-open only files needed by the current change or a newly discovered dependency.
- A finding that has already been independently reproduced is not re-debated: inspect the concrete path → implement a bounded fix → add a regression → run the focused test → mark it addressed → move on. Do not repeatedly refactor working code.
- Do not search for extra features or findings beyond the assigned scope.

## 5. Scope discipline

- Do not opportunistically redesign unrelated modules; do not implement future ideas unless required to make the active change correct.
- Preserve backward compatibility unless the Human explicitly changes it.
- A real but out-of-scope finding is recorded as technical debt with concrete evidence (file, behaviour), not silently fixed.
- Never special-case a single production order/user/ID when the real issue is a domain rule.
- **Bounded defect handling:** a concrete runtime defect found during UAT is fixed in source (never only in a built artifact), with the smallest change, a regression/verification, and its own commit. It does not authorize roadmap recon or new features.
- There is no authorized "next package" in the repository docs. A new feature package needs an explicit Human change request.

## 6. Diff-first review

Review the diff against the stated baseline, not just the last commit. At least check: authorization / IDOR / role scope; financial-data exposure; inventory source and lock ordering; delivery/fulfilment state transitions; idempotency (including replay after state changes); invoice grouping vs Order-level payment truth; historical-data compatibility; migration safety; API ↔ frontend contract; report scoping; realistic failure and concurrency paths; accidental future-scope leakage. Passing tests alone never makes a change production-ready.

## 7. Testing requirements

- Every behaviour change gets the smallest meaningful regression test. Use real-database tests for persistence, locking, policy, migration and concurrency behaviour — do not replace a DB/lock defect with mocks.
- Run focused tests while iterating, then the **full backend suite** (`cd backend && php artisan test`, 0 failures) before review. Frontend changes: `npm run type-check` and `npm run build-only`. Then `git diff --check`.
- Distinguish pre-existing noise (e.g. the CLI "OPcache already loaded" / `mbstring` warnings) from new failures. Record exact commands and results in the checkpoint.
- Tests run only against a database whose name contains `_test`/`_testing` (`primeclassy_testing`); the bootstrap refuses anything else. Never point tests at DEV or production data.

## 8. Context compaction / checkpoint protocol

Before context pressure, auto-compact, model switch or hand-off, write a checkpoint (a temporary `docs/*-CHECKPOINT.md` on the working branch, removed or folded into the authoritative docs when the work closes) containing: objective and active phase; branch and HEAD; locked decisions; files read / modified; migrations; progress; commands + exact results; open findings and risks; production state; constraints; **EXACT NEXT ACTION**. After resuming, read this file, the authoritative docs, the checkpoint and `git status`, then continue from EXACT NEXT ACTION — do not restart RECON or repeat finished work.

## 9. Database and production-data safety

Production data is authoritative.

- Prefer additive migrations. Use expand → migrate/backfill → verify → constrain/contract for schema/data transitions. Make data migrations idempotent or safely retryable.
- **Never** run `migrate:fresh`, `db:wipe`, truncate, blanket delete or any destructive reset on production. Never disable FK checks to force a migration.
- Never silently remap historical identities, referrals, ownership, financial records or inventory. Never guess an owner/user mapping.
- Destructive schema/data changes need explicit Human approval before execution.
- Audit row counts, FK/orphans, business invariants and historical defaults before and after a migration. Back up the production database and relevant runtime data **before** any production migration — a backup is not optional.
- DEV cleanup tools (for example `primeclassy:reset-dev-transactions`) are not production procedures.

## 10. Production deployment safety

- Production is not a playground and is not a Git working tree unless explicitly verified.
- **Never copy the DEV `.env`, DEV credentials or DEV uploads to production.** Preserve production `.env`, uploads, private credentials, sessions, logs, runtime caches and installed-lock state.
- **No production mutation (deploy, migrate, cache, data) without explicit Human authorization for that step.** Approval for one step does not carry over to the next.
- Use dry-runs / whitelists when production holds local/runtime differences. Keep maintenance mode on during incompatible code/schema transition windows. Verify migrations Pending before and Ran after. Reconcile data before bringing the app fully live.
- **Frontend production invariant — never delete `public_html/laravel.php`.** The single-domain layout routes `/api`, `/sanctum` and `/up` to Laravel through that bridge; a destructive sync silently removes it and the API returns 404 while the frontend still loads (a real production incident). Never run `rsync --delete` against the document root without `--exclude='laravel.php'`. The canonical command is in [docs/OPERATIONS.md](docs/OPERATIONS.md).

## 11. Concurrency and inventory invariants

Any path touching Agent or Sub inventory must preserve the established lock and source invariants (details in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)):

- Sub domain order: `WarehouseSubLocation` parent → Sub `WarehouseStock` targets → Sub reservation rows.
- Multi-target Agent capacity uses the shared canonical target ordering (`StockService::canonicalReservationTargets`). Do not introduce a second ordering vocabulary.
- Stock-request paths take Order → Stock Request → inventory. Status/sum reconciliation under a lock uses locking reads, not plain `sum()` (REPEATABLE READ snapshot).
- Never trust a client-provided stock source or location; server-side domain resolution is canonical.
- Do not "fix" a deadlock with sleeps or by swallowing 1213; fix the ordering and add a deterministic two-connection regression.

## 12. Preserved package invariants

Packages A, B and C are implemented; A and B are closed in production. Their invariants in [docs/BUSINESS-RULES.md](docs/BUSINESS-RULES.md) (10 roles, Sub ownership and stock source, reservation lifecycle, self-delivery, delivery verification, Order-level payment truth, operational vs financial projections, SC-03 Admin-only add-line) must not change without Human business approval.

## 13. Independent review and remediation workflow

1. The implementer finishes the package, runs the full validation and records the baseline and HEAD.
2. An independent reviewer reviews diff-first and reports evidence-backed findings (BLOCKER / MAJOR / MINOR).
3. The Human assigns findings for one coordinated remediation pass; the remediation agent fixes only those findings, adds regressions, re-runs focused then full suites, commits, and stops.
4. A specialist verifier may re-check the remediation. The reviewer does not review its own remediation.
5. Manual DEV UAT → Human Stage Gate → only then production preflight, backup, deployment, migration, reconciliation, smoke test.

## 14. Git discipline

- One coherent branch per package; commit logical units with descriptive messages; do not rewrite published history without Human approval.
- Before final review the working tree must be clean and the baseline/HEAD identified.
- Never commit secrets, `.env`, credentials, database dumps or runtime files. Back-ups live outside the repository.
- Do not merge `main`, push, deploy or touch production unless the Human asked for that step.
