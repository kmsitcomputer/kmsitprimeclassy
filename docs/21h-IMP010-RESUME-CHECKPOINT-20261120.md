# IMP-010 — Resume checkpoint (2026-11-20)

## Current position

- Execution: resumed; continue autonomously toward 375/375 verified GO.
- Worktree: `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy-imp010-go`
- Branch: `imp010/go-backend`
- HEAD at checkpoint creation: `964ec74d5d44edf2aa94aa1a4d9808a58be90b02` (Warehouse changes pending commit).
- Canonical matrix: 375 unique routes, 274 GO, 0 PARTIAL, 101 Laravel-only.
- Production and GLM environments were not modified.

## Warehouse recovery completed

All five Warehouse routes from checkpoint 21g were verified and added to the canonical generator:

- `GET /api/v1/warehouse/orders/diproses`
- `GET /api/v1/warehouse/orders/{order}/stock-request`
- `GET /api/v1/warehouse/returns`
- `POST /api/v1/warehouse/returns/{item}/inspect`
- `POST /api/v1/warehouse/returns/{item}/finalize`

Evidence:

- `PYTHONPATH=tools/parity python3 -u tools/parity/compare_warehouse_orders.py` — **54 passed, 0 failed**.
- `cd go-backend && go test ./internal/api ./internal/inventory ./internal/orders ./internal/i18n` — passed.
- `cd go-backend && go vet ./internal/api ./internal/inventory ./internal/orders ./internal/i18n` — passed.
- `git diff --check` — passed.
- `python3 tools/parity/gen_matrix.py > docs/21a-IMP010-API-PARITY-MATRIX.md` — generated 274 GO / 0 PARTIAL / 101 Laravel-only.
- Inventory check: 375 route records and 375 unique method/URI pairs.
- CMS backup checksum verification: all 7 manifested entries passed at `/tmp/primeclassy-imp010-codex-wip-20261120.2drgfM`.

A localization mismatch found in Sub Location finalization was fixed by extracting `return.sub_location_unavailable` from the Laravel reference into the generated Go catalog. The Warehouse differential now verifies the localized response along with role/branch checks, queue projections, Agent/Sub stock effects, stock movement audit, damaged-only disposition, and idempotent retry.

## 269 vs 271 reconciliation

At the resumed HEAD, the canonical generator reported 269 GO / 106 Laravel-only. The prior GLM checkpoint's 271/104 figures do not map to two additional implemented method/URI entries in the canonical inventory. The three dispatch reads (`GET /api/v1/dispatch`, `/dispatch/history`, `/dispatch/returns`) remain Laravel-only; they have no corresponding Go handler/registration and are not candidates for reclassification. Historical generator evidence at `7bcc226` had 259 GO, and later verified route commits add 10 (six Kurir dashboard, two refund-recipient, two item-receipt routes), yielding the actual 269 baseline. The discrepancy is a stale checkpoint arithmetic/reporting error, not two missing canonical mappings. Do not alter those route statuses without implementation and differential evidence.

## Files in the verified Warehouse unit

- `go-backend/internal/api/inventory.go`
- `go-backend/internal/api/server.go`
- `go-backend/internal/api/handlers_warehouse_orders.go`
- `go-backend/internal/api/handlers_warehouse_returns.go`
- `go-backend/internal/api/handlers_warehouse_return_mutations.go`
- `go-backend/internal/inventory/proposals.go`
- `go-backend/internal/i18n/catalog.json`
- `tools/parity/gen_i18n.py`
- `tools/parity/gen_matrix.py`
- `tools/parity/compare_warehouse_orders.py`
- `docs/21a-IMP010-API-PARITY-MATRIX.md`

## EXACT NEXT ACTION

Commit the verified Warehouse unit, then implement and differentially verify the next Laravel-only Orders routes in the resume mandate, beginning with `POST /api/v1/crm/customers/{customer}/orders` and `POST /api/v1/orders/{order}/items`. Continue through remaining matrix routes in coherent scoped batches; do not run the full suite, touch GLM resources, push, or deploy.
