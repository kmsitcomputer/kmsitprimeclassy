# PrimeClassy Go API (IMP-010)

A Go service that serves a **verified subset** of the existing Laravel API, byte-compatible with the current
frontend contracts, on the **same MySQL/MariaDB schema**. Laravel remains the reference and the rollback
implementation: nothing is cut over, and no endpoint Laravel serves is removed.

Read [docs/21-IMP010-GO-BACKEND.md](../docs/21-IMP010-GO-BACKEND.md) first — it holds the API parity matrix,
the architecture rationale, the remaining gaps and the rollback procedure.

## What this build serves

`GET /api/v1/_parity` is the machine-readable answer (computed from the same data the router uses, so it
cannot drift):

| Area | Endpoints |
|---|---|
| Identity | `POST /sanctum/csrf-cookie`, `POST /auth/login`, `POST /auth/register`, `POST /auth/logout`, `GET /auth/me`, `PATCH /profile/password` |
| Catalog | `GET /products`, `GET /products/{slug}`, `GET /categories` |
| Content | `GET /homepage`, `GET /articles`, `GET /articles/{slug}`, `GET /pages/{slug}` |
| Site | `GET /settings`, `GET /languages`, `GET /agents`, `GET /regions/{provinces,regencies,districts,villages}`, `GET /install/status` |
| Inventory (read) | `GET /stock/products`, `/stock/variations`, `/warehouse-stock`, `/warehouse/sellable`, `/warehouse/settings`, `/warehouse/sub-locations` (+ `/{id}`, `/{id}/stocks`, `/{id}/stock-cards`), `/warehouse/stock-cards`, `/sub-stock/my`, `/sub-stock/replenishment-targets`, `/sub-stock/requests` |
| Inventory (mutations) | `POST /stock/adjust` (403 in warehouse-authoritative mode, as the reference), `PATCH /warehouse/settings/factory-plan`, `GET/POST /warehouse/transfers`, `GET /warehouse/transfers/{id}`, `POST /warehouse/transfers/{id}/approve|cancel|reject` |
| Admin config | `GET/POST /admin/languages`, `PATCH /admin/languages/{id}`, `PATCH /admin/languages/{id}/set-default`, `PATCH /admin/languages/{id}/toggle-active`, `GET /admin/settings`, `PUT /admin/settings` |
| Ops | `GET /up`, `GET /healthz`, `GET /api/v1/_parity` |

Every other real Laravel route answers `501` with `errors.code = ["not_implemented"]`; an unknown path answers
`404` with the API envelope. That distinction is deliberate: a reverse proxy may send a chosen subset of
traffic here without any endpoint silently changing behaviour.

## Run locally

```bash
cp .env.example .env          # then fill DB_* (and set APP_ENV=testing for a *_testing database)
go run ./cmd/migrate          # additive: creates pc_go_sessions + go_migrations only
go run ./cmd/api              # listens on HTTP_ADDR (default :8081)
curl -s localhost:8081/healthz
```

`go run ./cmd/migrate -dry-run` lists pending migrations; `-allow-production` is required for
`APP_ENV=production` (an Owner-authorised step, never done by this package).

## Test

```bash
tools/parity/run_go_tests.sh          # provisions the _testing database, then `go vet` + `go test ./...`
tools/parity/compare_public_api.py    # Laravel ↔ Go contract comparison (both runtimes must be up)
tools/parity/compare_auth.py          # Laravel ↔ Go authentication/CSRF/CSRF-419 comparison
tools/parity/compare_inventory.py     # Laravel ↔ Go inventory read surface (3 roles, 29 checks)
tools/parity/compare_inventory_mutations.py  # Laravel ↔ Go inventory mutations — compares DB state too (37 checks)
```

Integration tests skip themselves when no `_testing` database is reachable, so `go test ./...` still works on a
machine without MariaDB; the scripts above are the evidence path and require them to execute.

## Layout

```
cmd/api           HTTP entrypoint (config → logger → db → server → graceful shutdown)
cmd/migrate       additive schema provisioning for the Go-owned tables
internal/config   environment loading + validation (incl. the *_test database policy)
internal/httpx    the {success,message,data,errors,meta} envelope and the API error type
internal/i18n     message catalogue generated from backend/lang/*/messages.php (+ drift test)
internal/db       MySQL pool, UTC session, transaction helper
internal/auth     bcrypt verification, sessions/CSRF, RBAC gates, permission map
internal/catalog  public product/category reads
internal/cms      homepage blocks, articles, pages
internal/site     settings, languages, agent directory, region cascades, install status
internal/inventory canonical sellable projection + warehouse read surfaces
internal/api      router, middleware chain, handlers, embedded parity inventory
```

## Dependencies (and why)

| Dependency | Why |
|---|---|
| `github.com/go-sql-driver/mysql` | the only MySQL/MariaDB driver needed; the schema is owned by the Laravel migrations |
| `golang.org/x/crypto/bcrypt` | verifies the existing `$2y$` hashes, so no user has to reset a password |
| stdlib `net/http` + `ServeMux` | Go 1.22+ patterns (`GET /api/v1/products/{slug}`) cover the whole route table — no third-party router was justified |
| stdlib `log/slog` | structured JSON logging without a logging framework |
| stdlib `database/sql` + explicit SQL | the queries must stay auditable and identical to the Laravel ones; an ORM would hide the exact SQL that financial/report parity depends on |

No queue, no cache server, no second datastore, no microservices: one binary, one database.
