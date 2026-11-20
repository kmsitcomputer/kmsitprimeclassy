# IMP-010 checkpoint — admin returns index (2026-11-20)

## Position

- Objective: continue the Owner-authorized Laravel-to-Go API parity migration.
- Worktree: `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy-imp010-go`
- Branch: `imp010/go-backend`
- Implementation commit: `84bea82 feat(returns): implement admin returns index`.
- Return request and allocation commits: `1bf9395`, `4500c98`.
- Canonical matrix: **280 GO / 0 PARTIAL / 95 Laravel-only / 375 unique method-path pairs**.
- The untracked owner-pause notes `docs/21g-IMP010-OWNER-PAUSE-20261120.md` and
  `docs/21k-IMP010-OWNER-PAUSE-20261120.md` remain untouched.

## Completed unit

Implemented `GET /api/v1/admin/returns`. The endpoint mirrors the Laravel branch scope for Admin/Agen and the
optional `agent_id` filter for Super Admin, comma-separated status filtering, latest-first pagination, complete
`ReturnRequestResource` serialization, and paginator metadata. The resource loader from the consumer return
unit is shared with this read route.

## Verification evidence

- `PYTHONPATH=tools/parity python3 -u tools/parity/compare_admin_returns.py` — **10 passed / 0 failed**.
  Compared Laravel and Go for default Admin/Agen lists, page/per-page, status filters, ignored Agent filter for
  Admin, invalid per-page fallback, Super Admin optional scope, and consumer role denial. Read-only; no test
  fixtures were changed.
- `cd go-backend && go test ./internal/api ./internal/orders ./internal/crm ./internal/i18n` — passed.
- `cd go-backend && go vet ./internal/api ./internal/orders ./internal/crm ./internal/i18n` — passed.
- `python3 -m py_compile tools/parity/compare_admin_returns.py tools/parity/gen_matrix.py` — passed.
- `python3 tools/parity/gen_matrix.py > docs/21a-IMP010-API-PARITY-MATRIX.md` — **280 GO / 0 PARTIAL /
  95 Laravel-only**.
- `git diff --check` — passed.
- Only `pc_go_imp010` was restarted. No GLM service/database, production data, remote Git, deployment, or full
  acceptance suite was touched.

## Remaining work and risks

- No known blocker for this endpoint.
- Cross-branch behavior is enforced in SQL and the harness covers branch-role paths with the available test
  fixture; the fixture currently has only the configured test Agent.
- The remaining 95 Laravel-only routes still need behavior-level parity.
- Full acceptance testing remains Owner-exclusive.

## EXACT NEXT ACTION

Continue the returns cluster with `GET /api/v1/admin/returns/{return}`. Match Laravel route binding, branch
authorization, ReturnRequestResource serialization, and 404/403 behavior with a scoped read-only differential;
then continue the related review and pickup-schedule routes.
