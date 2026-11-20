# IMP-010 checkpoint — dispatch returns queue (2026-11-21)

## Position

- Objective: continue the Owner-authorized Laravel-to-Go API parity migration.
- Worktree: `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy-imp010-go`
- Branch: `imp010/go-backend`
- HEAD: `f0907d1afec32e64d3956dc9a8dd97f8b9ef373d` (`feat(dispatch): implement return queue parity`).
- Canonical matrix: **284 GO / 0 PARTIAL / 91 Laravel-only / 375 unique method-path pairs**.
- Pre-existing untracked owner-pause notes `docs/21g-IMP010-OWNER-PAUSE-20261120.md` and
  `docs/21k-IMP010-OWNER-PAUSE-20261120.md` remain untouched.

## Completed unit

Implemented `GET /api/v1/dispatch/returns`: same-Agent dispatch queue, required and validated `agent_id` for Super
Admin, return/order-item state and branch filters, courier projection, 1–100 page-size clamp and Laravel pagination
metadata. The handler is isolated in its own API file; GLM route worktree/source was not changed.

## Verification evidence

- `PYTHONPATH=tools/parity python3 -u tools/parity/compare_dispatch_returns.py` — **6 passed / 0 failed**. Compared
  queue projection, page metadata, Super Admin missing/invalid/valid agent selection, and consumer role denial.
  The harness removes the Laravel fixture before creating the Go fixture because both runtimes share
  `primeclassy_testing`; cleanup was confirmed.
- `cd go-backend && go test ./internal/api ./internal/dispatch ./internal/orders ./internal/crm ./internal/i18n` — passed.
- `cd go-backend && go vet ./internal/api ./internal/dispatch ./internal/orders ./internal/crm ./internal/i18n` — passed.
- `python3 -m py_compile tools/parity/compare_dispatch_returns.py tools/parity/gen_matrix.py` and
  `git diff --check` — passed.
- Canonical matrix regeneration — **284 GO / 0 PARTIAL / 91 Laravel-only**.
- Isolated DB check: no synthetic `codex-rreview-*` orders or returns remain; reserved product quantity is 3 and
  invoice sequence is 0.
- No production/GLM service or database, remote Git, deployment, or full acceptance suite was touched.

## Remaining work and risks

- No known blocker for this endpoint.
- Financial return refund processing remains a separate high-impact endpoint and must preserve paid-order gates,
  refund ledger settlement and fee adjustments exactly as Laravel implements them.
- Full acceptance testing remains Owner-exclusive.

## EXACT NEXT ACTION

Inspect and port `PATCH /api/v1/admin/return-items/{item}/refund` from `ReturnController::markItemRefunded` and
`ReturnService::markItemRefunded`, including paid-order enforcement, RefundRecipientGuard, refund-obligation settlement,
return/order-item transitions, sales commission adjustment and activity audit. Differentially compare both successful
settlement and relevant refusal states against Laravel with isolated synthetic fixtures before marking GO.
