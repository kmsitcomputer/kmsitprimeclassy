# IMP-010 Resume checkpoint — SC-03 add-line verified (2026-11-20)

## Position

- Objective: complete remaining Laravel-to-Go API parity routes under the active Owner resume mandate; do not stop at intermediate counts.
- Canonical worktree: `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy-imp010-go`
- Branch: `imp010/go-backend`
- HEAD: `3d86481fee26665b91c2601151f684b6c30ed7aa`
- Canonical route inventory: 375 unique method/URI pairs.
- Matrix after this unit: **276 GO / 0 PARTIAL / 99 Laravel-only**.
- Owner pause note `docs/21g-IMP010-OWNER-PAUSE-20261120.md` remains untouched and untracked. The CMS backup at `/tmp/primeclassy-imp010-codex-wip-20261120.2drgfM` remains checksum-valid.
- Only the Codex test service `pc_go_imp010` was restarted. Tests used `primeclassy_testing`; no production or GLM database/service was changed.

## Completed unit

`POST /api/v1/orders/{order}/items` (Package C / SC-03) is GO. The route is Admin-only and same-Agent; order-first locking is preserved, followed by Stock Request then canonical Agent inventory target locks. The implementation persists the original request key/fingerprint, checks replay before creation guards, uses the canonical quote/reservation/commission pipeline, consolidates shipment dates, appends Stock Request demand (including reopening fulfilled requests), recalculates order totals, reconciles additional-payment obligations, and audits the mutation.

The Order response's payment summary now reflects pending additional-payment ledger rows. Request parsing covers Laravel integer-string coercion and prohibited stock-source errors.

Commit:

- `3d86481 feat(orders): implement SC-03 add-line parity`

## Verification

- `PYTHONPATH=tools/parity python3 -u tools/parity/compare_order_line_addition.py` — **25 passed / 0 failed**. Compared Laravel and Go responses and persisted item snapshots, reservations/movements, commission rows, shipment grouping, Stock Request state, audit, replay/conflict, settled-order additional-payment ledger, fulfilled-request reopen, validation, role, executor, status-window, Sub-source, canceled request, and past-date behavior.
- `cd go-backend && go test ./internal/api ./internal/orders ./internal/inventory ./internal/i18n` — passed.
- `cd go-backend && go vet ./internal/api ./internal/orders ./internal/inventory ./internal/i18n` — passed.
- `python3 -m py_compile tools/parity/compare_order_line_addition.py` — passed.
- `python3 tools/parity/gen_matrix.py > docs/21a-IMP010-API-PARITY-MATRIX.md` — 276 GO / 0 PARTIAL / 99 Laravel-only.
- Route inventory check — 375 records / 375 unique.
- `git diff --check` — passed.
- Differential cleanup check — 0 synthetic SC-03 orders; product 3 reservation restored to 3; 0 orphan stock requests/additional payments; 0 synthetic CRM customers.
- CMS WIP backup SHA256 manifest — all entries OK.

## Remaining work and known risks

- 99 Laravel-only routes remain; no unresolved SC-03 defects are known from the scoped differential.
- Previous 269-vs-271 report is reconciled in checkpoint 21i as stale checkpoint arithmetic; do not revive the unverified two-route count.
- Existing unrelated CMS WIP remains only in its verified external backup; the owner pause file is preserved.
- Full acceptance suite remains Owner-exclusive. No push, deployment, production access, cleanup, or destructive reset was performed.

## EXACT NEXT ACTION

Implement and differentially verify `POST /api/v1/orders/{order}/assisted-returns` from its Laravel route/controller/service. Inspect the exact reference contract first, preserve order/return/inventory locks and financial effects, build a scoped harness against synthetic `primeclassy_testing` data, run focused tests/vet, regenerate the matrix, commit only after differential PASS, checkpoint, then continue Priority A Orders/returns.
