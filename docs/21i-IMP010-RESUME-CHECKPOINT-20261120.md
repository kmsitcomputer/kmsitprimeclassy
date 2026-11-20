# IMP-010 — Resume checkpoint (2026-11-20, CRM assisted order)

## Position

- Objective: finish the remaining canonical Laravel-to-Go API routes; owner resume mandate is active.
- Worktree: `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy-imp010-go`
- Branch / HEAD: `imp010/go-backend` @ `8f9b4dd3690fd9afb1292ea3d7f8c86059772406`
- Canonical matrix: 375 unique method/URI pairs; **275 GO / 0 PARTIAL / 100 Laravel-only**.
- Commits from resumed Warehouse work: `4d6a4dc` (Warehouse order/return parity), `8f9b4dd` (CRM assisted order parity).
- The pre-existing untracked `docs/21g-IMP010-OWNER-PAUSE-20261120.md` remains preserved; no reset/clean/rebase/push/deploy occurred.
- CMS WIP backup checksums verified at `/tmp/primeclassy-imp010-codex-wip-20261120.2drgfM`.
- Production and GLM services/databases were not modified. Scoped tests used `primeclassy_testing` only.

## Verified units

### Warehouse — 5 routes

`GET /warehouse/orders/diproses`, `GET /warehouse/orders/{order}/stock-request`, `GET /warehouse/returns`, and return `inspect`/`finalize` are GO. The differential harness passed **54/0**; scoped Go tests/vet passed. See checkpoint 21h and commit `4d6a4dc`.

### CRM assisted order — 1 route

`POST /api/v1/crm/customers/{customer}/orders` is GO. It shares canonical order pricing and Agent reservation, snapshots CRM buyer origin and placer, attributes commissions to the CRM owner's seller chain, uses CRM-scoped idempotency, and denies Sub stock.

Evidence:

- `PYTHONPATH=tools/parity python3 -u tools/parity/compare_crm_assisted_order.py` — **7 passed, 0 failed** (response, persisted order/items/shipment/commission/payment/snapshot/reservation state, replay, role denial, archived customer, missing binding).
- `cd go-backend && go test ./internal/api ./internal/orders ./internal/inventory ./internal/i18n` — passed.
- `cd go-backend && go vet ./internal/api ./internal/orders ./internal/inventory ./internal/i18n` — passed.
- `python3 -m py_compile tools/parity/compare_crm_assisted_order.py` and `git diff --check` — passed.
- Differential cleanup left no synthetic `Codex CRM parity` customers or `codex-crm-*` orders; Agent product 3 reservation returned to 3.

The differential exposed that `orders.CanView` scanned nullable `konsumen_id` into an integer for CRM-origin orders. It now handles the nullable account origin and continues evaluating branch/seller attribution, which also allows the created-order response to evaluate invoice capability without a 500.

## Discrepancy note

The 269 vs 271 discrepancy remains reconciled as stale checkpoint arithmetic, not two eligible mappings. Three dispatch GET routes remain Laravel-only and have no Go implementation. Do not reclassify them without porting and differential proof. Historical 259 GO + 10 subsequently verified routes equals the generated 269 baseline; the five Warehouse routes and CRM assisted route bring the canonical total to 275.

## EXACT NEXT ACTION

Implement and differential-test `POST /api/v1/orders/{order}/items` (Package C SC-03) from `OrderFulfillmentController::addItem` / `OrderLineAdditionService`. Preserve Admin-only same-Agent authorization, order-first lock order, financial snapshot freeze, no executor assigned, Agent-stock only, product/variation resolution, reservation, shipment canonicalization, Stock Request reconciliation, totals/additional-payment obligation, audit, and same-key replay/conflict behavior. Then continue the remaining Priority A Orders/returns routes in the active resume mandate.
