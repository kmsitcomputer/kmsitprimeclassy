# IMP-010 checkpoint — return item refund confirmation (2026-11-21)

## Position

- Objective: continue the Owner-authorized Laravel-to-Go API parity migration.
- Worktree: `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy-imp010-go`
- Branch: `imp010/go-backend`
- HEAD: `208565022e15b714d7acb32bb7a909422a4afa60` (`feat(returns): implement refund confirmation parity`).
- Canonical matrix: **285 GO / 0 PARTIAL / 90 Laravel-only / 375 unique method-path pairs**.
- Pre-existing untracked owner-pause notes `docs/21g-IMP010-OWNER-PAUSE-20261120.md` and
  `docs/21k-IMP010-OWNER-PAUSE-20261120.md` remain untouched.

## Completed unit

Implemented `PATCH /api/v1/admin/return-items/{item}/refund` with Keuangan/Super Admin route gates, same-Agent
authorization, approved and fully-paid checks, CRM refund-recipient evidence guard, legacy manual attempt/payout
ledger writes, paid-balance and invoice snapshot reversal, obligation and return completion, order-item refund state,
Sales-only commission reversal, full audit, and ReturnItem serialization.

## Verification evidence

- `PYTHONPATH=tools/parity python3 -u tools/parity/compare_return_item_refund.py` — **10 passed / 0 failed**.
  Compared non-finance role denial, unpaid refusal, needs-verification refusal, successful legacy payout, stale replay,
  ReturnItem response, paid/gross/remaining and invoice state, obligation/attempt/payout/refund-ledger rows, return and
  order-item transitions, commission adjustment, and activity logs.
- The differential fixture creates an Owner-approved payable synthetic obligation after first checking refusal states;
  no production data is used. The shared `primeclassy_testing` fixture is removed after each runtime.
- `cd go-backend && go test ./internal/api ./internal/orders ./internal/crm ./internal/i18n` — passed.
- `cd go-backend && go vet ./internal/api ./internal/orders ./internal/crm ./internal/i18n` — passed.
- `python3 -m py_compile tools/parity/compare_return_item_refund.py tools/parity/gen_matrix.py`, JSON catalog parse,
  and `git diff --check` — passed.
- Canonical matrix regeneration — **285 GO / 0 PARTIAL / 90 Laravel-only**.
- Harness cleanup confirmed no `codex-rreview-*` order/return remains, reserved product quantity is 3, and invoice
  sequence is 0.
- Test service `pc_go_imp010` was restarted to load the handler. No production/GLM service or database, remote Git,
  deployment, or full acceptance suite was touched.

## Remaining work and risks

- No known blocker for this endpoint.
- The endpoint carries financial side effects; its differential covers approved payable settlement and safety refusals.
- Full acceptance testing remains Owner-exclusive.

## EXACT NEXT ACTION

Continue the dispatch read surface with `GET /api/v1/dispatch/history`: inspect the Laravel filters, branch and courier
scope, WIB date behavior, executor attribution, projection and pagination; implement and compare both response shape
and query behavior against Laravel, then regenerate the canonical matrix and commit.
