# IMP-010 Checkpoint — Dispatch Queue — 2026-11-21

## Objective and phase

Continue complete Laravel-to-Go HTTP API parity under the active IMP-010 mandate. The dispatch read surface is now fully ported: the dispatchable shipment queue and delivered shipment history both run in Go.

## Repository state

- Branch: `imp010/go-backend`
- Latest endpoint commit: `2dad0da7890eac6f5631db2d506a00724790a374` (`Port dispatch queue endpoint parity`)
- Previous commits in this work batch:
  - `95c5d63` — dispatch history endpoint and differential harness
  - `bfe866c` — checkpoint after dispatch history
- Working tree contains only two pre-existing untracked owner-pause notes, preserved and untouched:
  - `docs/21g-IMP010-OWNER-PAUSE-20261120.md`
  - `docs/21k-IMP010-OWNER-PAUSE-20261120.md`
- No schema changes or migrations.
- No production, remote push, GLM worktree/container/database, or unrelated service changes.

## Dispatch queue parity

Implemented `GET /api/v1/dispatch` and the Laravel `DispatchShipmentResource` projection:

- Branch resolution and Super Admin required/validated `agent_id`.
- Eligible `diproses`/`dikirim` orders, structured region, payment, and order delivery-date filters.
- Per-shipment pending/standard/unassigned eligibility, cancelled-item exclusion, provider exclusions, and shipment-specific delivery date filter.
- Per-shipment resource projection with order/item snapshots, date list, stock-request fulfillment quantities, and boolean paid-in-full signal. No financial amounts are returned.
- Canonical generated parity matrix: **287 GO / 0 PARTIAL / 88 Laravel-only / 375 total**; dispatch is **9/9 GO**.

## Verification evidence

- `cd go-backend && go test ./internal/api ./internal/dispatch ./internal/i18n` — PASS.
- `cd go-backend && go vet ./internal/api ./internal/dispatch ./internal/i18n` — PASS.
- `python3 tools/parity/compare_dispatch_queue.py` — **10 passed / 0 failed**, covering queue projection, stock request values, unpaid/paid filtering and paid-in-full, date and region filters, pagination, Super Admin scope and validation, and role denial.
- `python3 tools/parity/gen_matrix.py > docs/21a-IMP010-API-PARITY-MATRIX.md` — regenerated; dispatch is fully GO.
- `git diff --check` — PASS before commit.
- The scoped harness uses only `primeclassy_testing` fixtures and cleans them through the existing return parity cleanup procedure.
- `pc_go_imp010` restarted for the Go service update; no other containers were touched.

## Findings and risks

- No dispatch queue blocker found.
- The matrix still contains 88 Laravel-only endpoints and no PARTIAL endpoints.
- The two owner-pause notes remain untracked and untouched.

## EXACT NEXT ACTION

Start the remaining Laravel-only admin additional-payment read surface with `GET /api/v1/admin/additional-payments`. Inspect its route middleware, controller/resource, policies and persistence behavior, then implement a scoped Go parity path and Laravel–Go differential comparison. Follow with focused Go tests/vet, regenerate the canonical matrix, review and commit only after the scoped comparison passes.
