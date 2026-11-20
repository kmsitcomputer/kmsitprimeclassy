# IMP-010 checkpoint — consumer return request parity (2026-11-20)

## Position

- Objective: continue the Owner-authorized Laravel-to-Go API parity migration; continue after this unit.
- Worktree: `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy-imp010-go`
- Branch: `imp010/go-backend`
- Baseline before this unit: `7d8861ac6739bd1075b5e0a43de3f10d1f14e8ab`.
- Implementation commit: `1bf9395 feat(orders): implement consumer return request parity`.
- Canonical matrix: **278 GO / 0 PARTIAL / 97 Laravel-only / 375 unique method-path pairs**.
- The untracked owner-pause notes `docs/21g-IMP010-OWNER-PAUSE-20261120.md` and
  `docs/21k-IMP010-OWNER-PAUSE-20261120.md` were left untouched.

## Completed unit

Implemented `POST /api/v1/orders/{order}/returns` for the owning consumer. The Go route now matches Laravel's
request validation (including multipart, JSON, and URL-encoded fields), owner authorization, delivery and
returnable-quantity guards, image evidence storage, transaction behavior, return and return-item records,
order-item transition, refund total, audit event, response resource, and localized messages.

The shared assisted-return item parser was corrected to report all missing nested required fields at once and
retain Laravel's nested attribute spelling. The shared image reader accepts Laravel's 5120 KB limit; its
assisted-return endpoint still enforces its own 4096 KB limit.

## Verification evidence

- `PYTHONPATH=tools/parity python3 -u tools/parity/compare_return_request.py` — **9 passed / 0 failed**.
  Compared Laravel and Go success responses, return resources, evidence bytes, database and audit state,
  delivered-state and quantity errors, role denial, JSON and URL-encoded validation, nested JSON validation,
  and transaction rollback including file side effects.
- `PYTHONPATH=tools/parity python3 -u tools/parity/compare_assisted_return.py` — **15 passed / 0 failed** after
  the shared nested-validation parser correction.
- `cd go-backend && go test ./internal/api ./internal/orders ./internal/crm ./internal/i18n` — passed.
- `cd go-backend && go vet ./internal/api ./internal/orders ./internal/crm ./internal/i18n` — passed.
- `python3 -m py_compile tools/parity/compare_return_request.py tools/parity/compare_assisted_return.py tools/parity/gen_matrix.py` — passed.
- `python3 tools/parity/gen_matrix.py > docs/21a-IMP010-API-PARITY-MATRIX.md` — regenerated the canonical
  matrix at **278 GO / 0 PARTIAL / 97 Laravel-only**.
- Route inventory — **375 records / 375 unique method-path pairs**.
- `git diff --check` — passed.
- Differential cleanup verification in `primeclassy_testing` — **0** orders with `codex-return-*` idempotency
  keys and **0** returns with the synthetic reason. The harness removed only its own evidence files and rows.
- Only `pc_go_imp010` was restarted. No GLM service/database, production data, remote Git, deployment, or full
  acceptance suite was touched.

## Remaining work and risks

- No known blocker for this endpoint.
- The parser correction is shared with assisted returns; its differential harness remains green.
- The remaining 97 Laravel-only routes still require behavior-level parity; route registration is not evidence
  of GO status.
- Full acceptance testing remains Owner-exclusive.

## EXACT NEXT ACTION

Continue with `POST /api/v1/return-items/{returnItem}/receipt-allocations`: inspect its Laravel controller,
request validation, authorization, service, transactions, and response resource; implement the Go behavior;
write a scoped Laravel–Go differential comparing response and database effects; run only scoped Go tests/vet,
the relevant differential, and `git diff --check`; regenerate the matrix and commit only after parity passes.
