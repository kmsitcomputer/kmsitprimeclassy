# IMP-010 checkpoint — return pickup schedule (2026-11-21)

## Position

- Objective: continue the Owner-authorized Laravel-to-Go API parity migration.
- Worktree: `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy-imp010-go`
- Branch: `imp010/go-backend`
- HEAD: `6352dd97586d7b82572be94eb872d23bc71adb30` (`feat(returns): implement pickup schedule parity`).
- Canonical matrix: **283 GO / 0 PARTIAL / 92 Laravel-only / 375 unique method-path pairs**.
- Pre-existing untracked owner-pause notes `docs/21g-IMP010-OWNER-PAUSE-20261120.md` and
  `docs/21k-IMP010-OWNER-PAUSE-20261120.md` remain untouched.

## Completed unit

Implemented `PATCH /api/v1/admin/returns/{return}/pickup-schedule`: same-Agent authorization, exact Y-m-d and
Asia/Jakarta non-past validation, updates only pending/approved return items, no-eligible-item response, Laravel
response shape and `return.pickup_scheduled` activity properties/IP/user-agent.

## Verification evidence

- `PYTHONPATH=tools/parity python3 -u tools/parity/compare_return_pickup_schedule.py` — **6 passed / 0 failed**.
  Compared required/date-format/past-date validation, scheduled result, pending-item DB change and activity audit,
  and no-eligible-item behavior after rejection against Laravel. Synthetic fixtures cleaned; no
  `codex-rreview-*` orders or synthetic returns remain, product reserved quantity restored to 3, invoice sequence 0.
- `cd go-backend && go test ./internal/api ./internal/orders ./internal/crm ./internal/i18n` — passed.
- `cd go-backend && go vet ./internal/api ./internal/orders ./internal/crm ./internal/i18n` — passed.
- `python3 -m py_compile tools/parity/compare_return_pickup_schedule.py tools/parity/gen_matrix.py` — passed.
- `python3 tools/parity/gen_matrix.py > docs/21a-IMP010-API-PARITY-MATRIX.md` — **283 GO / 0 PARTIAL /
  92 Laravel-only**.
- `python3 -m json.tool go-backend/internal/i18n/catalog.json` and `git diff --check` — passed.
- The first Go test/vet attempt hit host disk exhaustion with 119 MiB free. Regenerable Go build cache was cleared
  (`go clean -cache`), restoring 3.4 GiB; scoped tests and vet then passed. Test service `pc_go_imp010` restarted
  for the changed binary. No production/GLM service or database, remote Git, deployment, or full acceptance suite
  was touched.

## Remaining work and risks

- No known blocker for this endpoint.
- Canonical inventory still has 92 Laravel-only routes; ownership must continue to follow the active migration
  mandate and repository governance.
- Full acceptance testing remains Owner-exclusive.

## EXACT NEXT ACTION

Continue the returns cluster by identifying the next Laravel-only return endpoint in `backend/routes/api_v1.php`.
Inspect its controller, service, policy and side effects; implement only existing Laravel behavior and add a scoped
Laravel-Go differential harness that compares response and resulting DB/filesystem/audit state before updating the
canonical parity matrix and committing.
