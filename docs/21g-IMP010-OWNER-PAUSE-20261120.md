# IMP-010 — Owner pause checkpoint (2026-11-20)

**Execution status: PAUSED BY OWNER.** Implementation and testing stopped at the Owner's request. Do not resume
until a new Owner mandate arrives.

## Repository position

- Canonical worktree: `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy-imp010-go`
- Branch: `imp010/go-backend`
- HEAD: `964ec74d5d44edf2aa94aa1a4d9808a58be90b02`
- No changes from this session have been committed.
- The latest `git status --short --branch` was:

```text
## imp010/go-backend
 M go-backend/internal/api/inventory.go
 M go-backend/internal/api/server.go
 M go-backend/internal/inventory/proposals.go
?? go-backend/internal/api/handlers_warehouse_orders.go
?? go-backend/internal/api/handlers_warehouse_return_mutations.go
?? go-backend/internal/api/handlers_warehouse_returns.go
?? tools/parity/compare_warehouse_orders.py
```

The prior Codex CMS WIP backup remains outside the repository at
`/tmp/primeclassy-imp010-codex-wip-20261120.2drgfM`; its manifest and contents were checksum-verified before this
work. No cleanup, reset, revert, rebase, merge, push, or deployment was performed.

## Inventory and takeover reconciliation

The Owner-provided GLM checkpoint was `964ec74` with 271 GO / 104 Laravel-only. Preflight confirmed that exact
commit is HEAD and the tree was clean. Running the repository's canonical `tools/parity/gen_matrix.py` reported
**269 GO / 0 PARTIAL / 106 Laravel-only / 375 total**. This is a two-route discrepancy from the reported
checkpoint; the matrix was not edited or regenerated to conceal it. Current matrix remains the source to
reconcile when work resumes.

## Progress in this session

Implemented, but not yet added to the canonical matrix:

- `GET /api/v1/warehouse/orders/diproses`
- `GET /api/v1/warehouse/orders/{order}/stock-request`
- `GET /api/v1/warehouse/returns`
- `POST /api/v1/warehouse/returns/{item}/inspect`
- `POST /api/v1/warehouse/returns/{item}/finalize`

The warehouse order handlers preserve branch and queue visibility predicates, the OrderResource projection,
the stock-request overlay, current stock buckets, per-item courier/proof additions, and queue pagination. The
return queue projects only approved and uninspected lines. Return inspection/finalization code is present but
must remain **unverified WIP** until differential checks pass, including stock movements and retry behavior.

`tools/parity/compare_warehouse_orders.py` exercises these routes with temporary fixtures and cleanup. It also
temporarily alters one existing testing order's queue fields and restores them in `finally`. The harness only
targets `primeclassy_testing`; no GLM database or container was used or modified.

## Verification evidence

- Scoped Go tests and vet passed before the final harness-normalization-only edit:
  - `cd go-backend && go test ./internal/api ./internal/inventory`
  - `cd go-backend && go vet ./internal/api ./internal/inventory`
- An earlier scoped pass also covered `./internal/orders`.
- `git diff --check` passed before the final small Go/harness edits; rerun it on resume.
- The combined warehouse differential last reported **25 passed / 1 failed**. The single failure was the
  harness comparing SQL timestamp strings at second precision across sequential Laravel/Go calls; response
  comparison passed. The harness normalizer was then changed to normalize SQL timestamps, but that final change
  has **not** been rerun.
- Earlier verified read-route harness batches reported **18/0** and then **22/0** before adding inspection and
  finalization scenarios.
- The mismatch found during those comparisons—Laravel's per-item courier projection missing from Go—was fixed
  by adding warehouse-specific courier/proof enrichment. Final combined differential after that fix passed
  those scenarios.
- `pc_go_imp010` was restarted to load local code. Laravel IMP-010 and all GLM containers/databases were not
  restarted or modified.

## WIP and known gaps

- All seven paths listed in Git status are uncommitted; keep them intact. In particular,
  `handlers_warehouse_return_mutations.go` is WIP and must not be represented as GO yet.
- The combined harness's timestamp-normalization change is unverified.
- The warehouse stock-request differential fixture currently includes a product line, but the full mutation
  behavior of return finalization (agent/Sub restock, movement rows, idempotent retry, and rollback on errors)
  has not passed differential comparison.
- The matrix has not been updated for these endpoints. Do not count them as GO based on route registration or
  compilation.
- No current endpoint count beyond the canonical generator result (269/0/106) is claimed.

## EXACT NEXT ACTION

After the Owner resumes work: rerun `PYTHONPATH=tools/parity python3 -u tools/parity/compare_warehouse_orders.py`
against the isolated testing services, confirm the timestamp-normalization fix and inspect response/database
parity, then add differential cases for finalize (transit/Sub stock effects, stock movement audit, damaged-only
disposition and idempotent replay). Run scoped Go test/vet and `git diff --check`; only after all five warehouse
surfaces pass should their entries be added to the matrix and committed. Then continue with the next assigned
endpoint group from the canonical matrix.

**No further implementation or test commands were run after this checkpoint was written.**
