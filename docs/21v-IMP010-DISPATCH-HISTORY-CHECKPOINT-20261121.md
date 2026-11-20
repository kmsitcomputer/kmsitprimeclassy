# IMP-010 Checkpoint — Dispatch History — 2026-11-21

## Objective and phase

Continue the Laravel-to-Go API migration under the active IMP-010 mandate. This unit ports `GET /api/v1/dispatch/history` and then continues into the remaining Laravel-only dispatch read surface.

## Repository state

- Branch: `imp010/go-backend`
- Endpoint commit: `95c5d63db956893ac61f4bdbe0ffba8fdd7c0741` (`Port dispatch history endpoint parity`)
- Working tree after endpoint commit: only the two pre-existing untracked owner-pause notes remain:
  - `docs/21g-IMP010-OWNER-PAUSE-20261120.md`
  - `docs/21k-IMP010-OWNER-PAUSE-20261120.md`
- No migrations or schema changes.
- No production access, deployment, push, or GLM worktree/container/database changes.

## Ported endpoint

Implemented Laravel `DispatchController::history` and `DispatchHistoryResource` behavior in Go:

- Route auth and role gate for `super_admin, agen, admin, koordinator-kurir`.
- Super Admin `agent_id` requirement and validation against an existing Agen.
- Same-Agent scope; delivered status and non-null completion time.
- Jakarta-local date bounds, courier filter, region filters, escaped order-number search, stable sort, and pagination capped at 50.
- Operational-only resource projection, including courier/SKS/unavailable executor and proof availability/URL; excludes payment, address, and phone details.
- Canonical generated parity matrix now reports **286 GO / 0 PARTIAL / 89 Laravel-only / 375 total**. This endpoint is counted once.

## Verification evidence

- `cd go-backend && go test ./internal/api ./internal/dispatch ./internal/i18n` — PASS.
- `cd go-backend && go vet ./internal/api ./internal/dispatch ./internal/i18n` — PASS.
- `python3 tools/parity/compare_dispatch_history.py` — **10 passed / 0 failed**, comparing Laravel and Go for list/proof, courier/date/region scope, self-delivery, validation, search escaping, pagination, Super Admin agent selection, and role denial.
- `python3 tools/parity/gen_matrix.py > docs/21a-IMP010-API-PARITY-MATRIX.md` — regenerated; dispatch history is GO.
- `git diff --check` — PASS before commit.
- Synthetic harness rows and media were removed; post-run check returned 0 dispatch-history media rows, 0 synthetic test orders, and reserved stock remained 3.
- Go service container `pc_go_imp010` was restarted for the new binary. No other containers were touched.

## Remaining findings and risks

- No endpoint-specific blocker found.
- The 89 Laravel-only routes remain unported; no GLM scope was taken over in this unit.
- Proof URL comparison covered the configured public media disk. If a deployment uses a different media disk, its Laravel `Storage::disk(...)->url()` behavior must remain matched by Go media configuration.
- Owner-pause notes listed above are intentionally preserved and were not staged.

## EXACT NEXT ACTION

Inspect Laravel `DispatchController::index` and route middleware for `GET /api/v1/dispatch`. Compare the current Go inventory and existing dispatch services, then implement the Go route and a scoped Laravel–Go differential harness for this endpoint. Keep work within the existing API migration scope, run its focused Go tests/vet and differential comparison, regenerate the matrix, review the diff, and commit only after parity passes.
