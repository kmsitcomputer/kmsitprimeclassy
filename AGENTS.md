# AGENTS.md — Prime Classy AI Development Protocol

This file is the repository-level operating contract for every AI coding/review agent.

## 1. Authority order

When sources disagree, use this order:

1. Human decisions and explicit stage-gate approvals.
2. The active package specification in `docs/`.
3. The active package checkpoint in `docs/`.
4. Current source code, migrations, tests, and runtime evidence.
5. Older documentation.

Source reality wins over stale documentation. Never resurrect an older decision merely because it still appears in an old file.

## 2. Agent roles

### ChatGPT — architect / documentation owner
- Owns locked business rules, package scope, architecture, orchestration, checkpoints, and Markdown documentation.
- Decides whether a finding is in-scope for the active package.
- Does not silently expand implementation scope.

### DeepSeek V4.1 — primary implementer / primary remediator
- Performs the implementation after the active package is authorized.
- Fixes implementation/test findings during the main development pass.
- Must follow this file, the active package spec, and the active checkpoint.

### Claude — independent final reviewer + direct fixer
- Reviews the completed package diff-first against the baseline.
- If a real in-scope finding exists, Claude fixes it directly, adds/updates tests, runs the required suites, and commits the fix.
- Do not create a review -> handoff -> remediation -> review loop for ordinary findings.

### Codex — optional specialist verifier
- Use for focused concurrency, security, database, or diff verification when materially useful.
- Codex is not a mandatory stage gate unless the human explicitly requests it.

## 3. RECON ONCE

At the start of an implementation/review session:

1. Read `AGENTS.md`.
2. Read the active package specification.
3. Read the active package checkpoint.
4. Run `git status`, `git branch --show-current`, and `git rev-parse HEAD`.
5. Inspect the relevant source/tests once and build a file map.

Do not repeatedly re-read the whole repository. Re-open only files needed by the current change or a newly discovered dependency.

## 4. Context compaction / resume protocol

Before context pressure, auto-compact, model switch, or handoff, update the active checkpoint with:

- objective and active package/phase;
- branch and current HEAD;
- locked decisions;
- files read;
- files modified;
- migrations added/changed;
- progress completed;
- tests/commands and exact results;
- open findings and risks;
- production state;
- constraints / out-of-scope items;
- **EXACT NEXT ACTION**.

After compaction/resume, the agent must read:

1. `AGENTS.md`;
2. the active package spec;
3. the active checkpoint;
4. `git status`.

Then continue from **EXACT NEXT ACTION**. Do not restart RECON, revert to an older spec, or repeat completed work.

The same checkpoint rule applies to implementation, remediation, feature revision, change request, and scope change.

## 5. Active package workflow

For Package B, follow this order:

1. R-03 implementation and tests.
2. R-03 internal verification.
3. R-04 implementation and tests.
4. Full cross-domain regression.
5. Claude independent diff-first final review; Claude directly fixes any real in-scope finding.
6. Full backend/frontend verification after any fix.
7. Manual DEV/UAT.
8. Human Stage Gate.
9. Only after explicit human approval: production preflight, backup, deployment, migration, reconciliation, smoke test.

R-03 and R-04 share one Package B branch, but R-03 is the foundation and must be completed before R-04 changes are layered on top.

## 6. Scope discipline

- Do not opportunistically redesign unrelated modules.
- Do not implement future package ideas unless they are required to make the active package correct.
- Preserve backward compatibility unless the active spec explicitly changes it.
- If a finding is real but out of scope, document it as technical debt with concrete evidence.
- Never special-case a single production order/user when the real issue is a domain rule.

## 7. Database and production-data safety

Production data is authoritative.

Mandatory rules:

- Prefer additive migrations.
- Use **expand -> migrate/backfill -> verify -> constrain/contract** for schema/data transitions.
- Never run `migrate:fresh`, `db:wipe`, destructive reset, truncate, or blanket delete on production.
- Never disable FK checks to force a migration through.
- Never silently remap historical identities, referrals, ownership, financial records, or inventory.
- Never guess an owner/user mapping.
- Destructive schema/data changes require explicit Human approval before execution.
- Data migrations should be idempotent or safely retryable where practical.
- Audit row counts, FK/orphans, business invariants, and historical defaults before and after migration.
- Back up production database and relevant source/runtime data before applying production migrations.
- DEV/test cleanup patterns are not automatically valid for production.

## 8. Production deployment safety

- Production is not a playground and is not a Git working tree unless explicitly verified.
- Never copy DEV `.env` or DEV private credentials to production.
- Preserve production `.env`, uploads, private credentials, sessions, logs, runtime cache directories, and installed-lock state.
- Use deployment dry-runs/whitelists when production contains local/runtime differences.
- Never deploy or migrate production without explicit human authorization for that step.
- Keep maintenance mode active during incompatible code/schema transition windows.
- Verify migrations as Pending before apply and Ran after apply.
- Reconcile data before bringing the application fully live.
- A backup is not optional.

## 9. Inventory / concurrency rules

Any path touching Agent/Sub inventory must preserve the established lock and source invariants.

For Sub-domain checkout/execution, preserve the canonical order:

`WarehouseSubLocation parent -> Sub WarehouseStock targets -> Sub reservation rows`.

For multi-target Agent capacity, use the shared canonical target ordering already implemented in the stock services. Do not introduce a second ordering vocabulary.

Do not trust client-provided stock source/location as authority. Server-side domain resolution remains canonical.

## 10. Testing expectations

For every behavior change:

- Add or update the smallest meaningful regression test.
- Use real database tests for persistence, locking, migration, policy, and concurrency behavior where required.
- Do not replace a real regression with mocks when the defect depends on DB state/locking.
- Run focused tests while iterating, then the full backend suite before final review.
- Run frontend type-check and production build for frontend changes.
- Record exact commands/results in the checkpoint.
- Existing unrelated warnings must be distinguished from new failures.

## 11. Review and closure

Final review is diff-first against the Package B baseline.

The reviewer must check at least:

- authorization / IDOR / role scope;
- financial-data exposure;
- inventory source and lock ordering;
- delivery/fulfillment state transitions;
- idempotency;
- invoice grouping vs Order-level payment truth;
- historical-data compatibility;
- migration safety;
- API/frontend contract alignment;
- report scoping;
- tests for realistic failure and concurrency paths;
- accidental R-05/future-scope leakage.

No package is production-ready only because tests pass. It requires manual UAT and Human Stage Gate approval.

## 12. Git discipline

- Keep one coherent Package B branch.
- Do not rewrite published history unless the human explicitly approves it.
- Commit logical units with descriptive messages.
- Do not mix generated secrets/runtime files into commits.
- Before final review, working tree must be clean and the checkpoint must identify the exact baseline and HEAD.

## 13. Current roadmap

- Package A: R-01 + R-02 — CLOSED and deployed to production.
- Package B: R-03 + R-04 — next active package.
- R-03 must be implemented first, then R-04 on the same branch/package.

See:
- `docs/PACKAGE-B-R03-R04.md`
- `docs/PACKAGE-B-R03-R04-CHECKPOINT.md`
