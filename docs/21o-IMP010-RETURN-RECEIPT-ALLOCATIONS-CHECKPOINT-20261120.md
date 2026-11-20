# IMP-010 checkpoint — return receipt allocations (2026-11-20)

## Position

- Objective: continue the Owner-authorized Laravel-to-Go API parity migration; continue after this unit.
- Worktree: `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy-imp010-go`
- Branch: `imp010/go-backend`
- Implementation commit: `4500c98 feat(returns): record receipt allocations`.
- Prior consumer return commit: `1bf9395 feat(orders): implement consumer return request parity`.
- Canonical matrix: **279 GO / 0 PARTIAL / 96 Laravel-only / 375 unique method-path pairs**.
- The untracked owner-pause notes `docs/21g-IMP010-OWNER-PAUSE-20261120.md` and
  `docs/21k-IMP010-OWNER-PAUSE-20261120.md` remain untouched.

## Completed unit

Implemented `POST /api/v1/return-items/{returnItem}/receipt-allocations`. It enforces Admin same-Agent or
Super Admin authority, approved and inspected return state, exact physical quantity allocation, receipt
ownership, receipt capacity, deterministic receipt locking, identical one-shot replay, conflict for changed
replay, trimmed evidence note, and the Laravel audit record. The response is the same created envelope and
receipt/quantity projection as Laravel.

## Verification evidence

- `PYTHONPATH=tools/parity python3 -u tools/parity/compare_return_receipt_allocations.py` — **13 passed / 0
  failed**. The synthetic differential covered success resource and DB/audit state, replay, changed replay,
  role denial, missing/empty/nested/oversized-note validation, missing model, uninspected return, total mismatch,
  receipt capacity, and concurrent allocation to one receipt. Concurrent Laravel and Go behavior both produced
  one 201 plus one 422 with allocated quantity exactly one.
- `cd go-backend && go test ./internal/api ./internal/orders ./internal/crm ./internal/i18n` — passed.
- `cd go-backend && go vet ./internal/api ./internal/orders ./internal/crm ./internal/i18n` — passed.
- `python3 -m py_compile tools/parity/compare_return_receipt_allocations.py tools/parity/gen_matrix.py tools/parity/gen_i18n.py` — passed.
- `python3 tools/parity/gen_matrix.py > docs/21a-IMP010-API-PARITY-MATRIX.md` — **279 GO / 0 PARTIAL /
  96 Laravel-only**.
- Route inventory — **375 records / 375 unique method-path pairs**.
- `git diff --check` — passed.
- Harness cleanup check — synthetic orders, returns and receipt events removed; `quantity_reserved` and invoice
  sequence restored. Only `pc_go_imp010` was restarted. No GLM service/database, production data, remote Git,
  deployment, or full acceptance suite was touched.

## Remaining work and risks

- No known blocker for this endpoint.
- The route was added to the shared GLM route registration file solely to serve this endpoint; no GLM-owned
  endpoint or business logic was changed.
- The remaining 96 Laravel-only routes still need behavior-level parity; route registration alone is not GO
  evidence.
- Full acceptance testing remains Owner-exclusive.

## EXACT NEXT ACTION

Continue with the Laravel-only returns cluster, beginning at `GET /api/v1/admin/returns`: inspect its Laravel
controller query, branch and status filters, pagination, serialization, and authorization; implement and
differentially verify that read endpoint, then continue the related return detail/review/refund routes without
changing return business rules.
