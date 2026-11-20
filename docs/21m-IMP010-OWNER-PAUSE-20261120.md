# IMP-010 — Owner pause checkpoint (2026-11-20)

**Status: PAUSED BY OWNER.** Implementation and tests stopped at the Owner's request. Preserve all current WIP;
do not treat the endpoint in progress as complete. No reset, revert, clean, push, deployment, or service shutdown
was performed.

## Repository position

- Worktree: `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy-imp010-go`
- Branch: `imp010/go-backend`
- HEAD before this checkpoint: `89f8f884fdd2a8bf7704e18d1dd2013ac96eb6fe`
- Last implementation commits:
  - `fe12662 feat(crm): complete assisted return parity`
  - `5892a89 fix(crm): match assisted return response messages`
- Canonical matrix: **277 GO / 0 PARTIAL / 98 Laravel-only / 375 routes**. This count includes assisted-return, not the endpoint currently in progress.

## Current Git state

```text
## imp010/go-backend
 M go-backend/internal/api/api_test.go
 M go-backend/internal/api/domain_e_routes.go
 M go-backend/internal/api/handlers_crm_assisted_return.go
 M go-backend/internal/api/inventory.go
 M go-backend/internal/api/inventory_test.go
 M tools/parity/gen_matrix.py
?? docs/21g-IMP010-OWNER-PAUSE-20261120.md
?? docs/21k-IMP010-OWNER-PAUSE-20261120.md
?? go-backend/internal/api/handlers_return_request.go
?? go-backend/internal/orders/return_request.go
```

The `21g` and `21k` notes are older untracked checkpoints and remain untouched. The WIP listed above is preserved
and was not committed. `tools/parity/gen_matrix.py` already has a provisional entry for ordinary order returns,
but the canonical matrix was not regenerated and the route must not be claimed GO yet.

## Last verified work

`POST /api/v1/orders/{order}/assisted-returns` is verified and committed. The latest differential reported
**15 passed / 0 failed**, including localized success message, resource, evidence file bytes, database and audit
effects, missing-reason validation, role denial, concurrent one-use consent quota, and disputed-consent denial.
The canonical matrix was **277 GO / 0 PARTIAL / 98 Laravel-only**. See
`docs/21l-IMP010-ASSISTED-RETURN-CHECKPOINT-20261120.md`.

For the ordinary consumer return endpoint, the current WIP adds a route, return request persistence/resource
code, and route-inventory/test updates. The latest scoped checks passed:

- `cd go-backend && go test ./internal/api ./internal/orders ./internal/crm ./internal/i18n`
- `cd go-backend && go vet ./internal/api ./internal/orders ./internal/crm ./internal/i18n`

No Laravel–Go differential harness has been created or run for `POST /api/v1/orders/{order}/returns`; no matrix
count was advanced. The changes remain **unverified WIP**.

## Known unfinished issue

The shared multipart parser currently returns an empty form map for non-multipart/JSON requests. Laravel's
`StoreReturnRequest` validates JSON `reason` and `items` even when the required evidence file is missing. This
means the Go 422 field set can differ for JSON requests. Fix the parser or provide a return-specific decoder
before claiming parity. Keep the ordinary return evidence limit at 5120 KB; the assisted-return limit is 4096 KB.

## EXACT NEXT ACTION ON RESUME

1. Inspect `git status`, this checkpoint, and the uncommitted return WIP; do not overwrite it.
2. Fix JSON/no-file request parsing so validation includes Laravel's `reason`, `items`, and `evidence` errors.
3. Complete `tools/parity/compare_return_request.py` using only synthetic `primeclassy_testing` data and compare
   the full success/error envelope, public evidence bytes, return/return-item/order-item state, audit row,
   ownership/RBAC, delivered-state and quantity failures, and rollback. Clean only fixtures created by the harness.
4. Restart only `pc_go_imp010` after Go code changes; run the differential harness, scoped Go tests/vet and
   `git diff --check`.
5. Regenerate the canonical matrix and mark this endpoint GO only after the differential passes; commit the
   verified unit, update its checkpoint, then continue with
   `POST /api/v1/return-items/{returnItem}/receipt-allocations`.

No production, GLM database/service, remote Git, full test suite, or deployment activity occurred. The existing
CMS WIP backup remains at `/tmp/primeclassy-imp010-codex-wip-20261120.2drgfM`; its manifest was last verified
successfully.
