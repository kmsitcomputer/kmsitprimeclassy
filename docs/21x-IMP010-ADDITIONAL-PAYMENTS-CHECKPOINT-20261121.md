# IMP-010 Checkpoint — Admin Additional Payments — 2026-11-21

## Objective and phase

Continue complete Laravel-to-Go API parity. `GET /api/v1/admin/additional-payments` is implemented and verified; proceed to the adjacent read endpoint in `OrderAdjustmentController`.

## Repository state

- Branch: `imp010/go-backend`
- Latest endpoint commit: `59511b51c6c546ae35d3638ebf9ac1645db5c01b` (`Port admin additional payments list`)
- Prior commits in the current continuation:
  - `2dad0da` — dispatch queue endpoint parity
  - `83d7ae8` — dispatch queue checkpoint
  - `95c5d63` — dispatch history endpoint parity
  - `bfe866c` — dispatch history checkpoint
- Working tree has only two preserved, pre-existing untracked owner-pause notes (`21g` and `21k`).
- No migrations or schema changes. No production changes, pushes, or GLM environment changes.

## Ported endpoint

Implemented the Laravel additional-payment oversight list, including:

- Role gate for `super_admin, agen, admin, keuangan` and same-Agent scoping for non-super users.
- Optional Super Admin `agent_id` filtering.
- Latest-first pagination and the complete `OrderAdditionalPaymentResource` projection.
- Current buyer and requester names, decimal amount string, status/reason, and decoded manual payment instructions from the related payment transaction.
- Canonical parity matrix now reports **288 GO / 0 PARTIAL / 87 Laravel-only / 375 total**.

## Verification evidence

- `cd go-backend && go test ./internal/api ./internal/orders ./internal/i18n` — PASS.
- `cd go-backend && go vet ./internal/api ./internal/orders ./internal/i18n` — PASS.
- `python3 tools/parity/compare_admin_additional_payments.py` — **7 passed / 0 failed**; covers Admin and Keuangan views, Super Admin all/branch/invalid scope, resource fields and manual instruction payload, pagination, and consumer denial.
- `python3 tools/parity/gen_matrix.py > docs/21a-IMP010-API-PARITY-MATRIX.md` — regenerated; endpoint is GO.
- `git diff --check` — PASS before commit.
- Fixture cleanup verified zero synthetic additional-payment rows and zero orphaned synthetic payment transactions in `primeclassy_testing`.
- `pc_go_imp010` restarted for code changes; no other containers touched.

## Findings and risks

- No endpoint-specific blocker found.
- 87 Laravel-only endpoints remain; none are PARTIAL.
- Preserved untracked owner-pause notes remain untouched.

## EXACT NEXT ACTION

Inspect `OrderAdjustmentController::listRefunds` and `OrderItemAdjustmentResource`, then port `GET /api/v1/admin/order-refunds` with the same exact role/branch behavior and its refund-adjustment resource fields. Build a scoped synthetic Laravel–Go differential covering branch visibility, requester/current user fields, status, pagination, Super Admin filtering, and role denial. Run focused Go tests/vet and the differential harness, regenerate the matrix, review the diff, and commit only after parity passes.
