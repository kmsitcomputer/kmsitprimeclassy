# IMP-010 checkpoint — assisted-return parity verified (2026-11-20)

## Position

- Objective: continue the Owner-authorized Laravel-to-Go API parity migration to 375 GO; no intermediate stop.
- Worktree: `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy-imp010-go`
- Branch: `imp010/go-backend`
- Implementation commit: `fe126626f4564894aa2a02cd6cc1ca5656f5a4c7`
- Response-catalog correction: `5892a896b92a5fbdd2f2546f1e14ea82cbce0cae`.
- Canonical matrix: **277 GO / 0 PARTIAL / 98 Laravel-only / 375 routes**.
- `docs/21g-IMP010-OWNER-PAUSE-20261120.md` and `docs/21k-IMP010-OWNER-PAUSE-20261120.md` remain untracked and untouched. This checkpoint supersedes their earlier pause/count statements.
- External CMS backup `/tmp/primeclassy-imp010-codex-wip-20261120.2drgfM` was checksum-verified during this resume; all manifest entries passed.

## Completed unit

`POST /api/v1/orders/{order}/assisted-returns` is now implemented and marked GO. The service enforces CRM-origin order scope, owning Sales/Sales-Kurir-Sub/Korsal authorization, current append-only consent state, item/quantity quota, delivered item state and returnable quantity. It stores validated public image evidence and commits the return request, return lines, order-item state, totals, consent association and audit record in the database transaction. Laravel's localized validation and consent errors are represented in Go's generated message catalogue.

Commits:

- `fe12662 feat(crm): complete assisted return parity`
- `5892a89 fix(crm): match assisted return response messages`

## Verification evidence

- `PYTHONPATH=tools/parity python3 -u tools/parity/compare_assisted_return.py` — **15 passed / 0 failed**. Compared Laravel and Go success envelopes/resources including localized success message, stored evidence bytes, return and return-item rows, order-item return state, audit row, missing-reason validation, admin denial, concurrent single-use quota (one 201 and one 422), and disputed-consent rejection.
- `cd go-backend && go test ./internal/api ./internal/crm ./internal/i18n` — passed.
- `cd go-backend && go vet ./internal/api ./internal/crm ./internal/i18n` — passed.
- `python3 -m py_compile tools/parity/compare_assisted_return.py tools/parity/gen_matrix.py tools/parity/gen_i18n.py` — passed.
- `python3 tools/parity/gen_i18n.py` — generated the four-locale catalogue; package test confirms it matches Laravel messages.
- `python3 tools/parity/gen_matrix.py > docs/21a-IMP010-API-PARITY-MATRIX.md` — **277 GO / 0 PARTIAL / 98 Laravel-only**.
- Canonical inventory check — **375 route records / 375 unique method-path pairs**.
- `git diff --check` — passed.
- Differential cleanup check — 0 synthetic CRM customers, returns, return items, consent roots or return audit rows; 0 leftover public evidence files.
- Only `pc_go_imp010` was restarted to load the local Go implementation. No GLM service/database, production data, remote Git, deployment, or full test suite was touched.

## Remaining work and risks

- No known blocker remains for this endpoint.
- The 98 Laravel-only routes remain; do not infer completion from handler registration alone.
- The earlier 269-vs-271 discrepancy was superseded by the newer verified 276/0/99 checkpoint; this unit moves that baseline to 277/0/98.
- Full acceptance testing remains Owner-exclusive.

## EXACT NEXT ACTION

Continue Priority A with `POST /api/v1/orders/{order}/returns`: inspect `ReturnController::store` and `ReturnService::requestReturn`, compare its authorization/validation/error contract with the assisted-return path, implement the ordinary return behavior without CRM-consent gating, and add a scoped Laravel–Go differential covering response, DB effects, file evidence, audit and rollback. Then run scoped Go tests/vet, regenerate the matrix, commit only after parity passes, checkpoint, and continue to `/api/v1/return-items/{returnItem}/receipt-allocations` and the remaining Laravel-only inventory.
