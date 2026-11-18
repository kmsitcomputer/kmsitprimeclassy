# 21 — IMP-010: Go backend migration

Status: **implemented as a verified increment on branch `imp010/go-backend`, local only, NOT closed.**
Not deployed, not cut over, no production access. Laravel remains the reference and rollback implementation.

| | |
|---|---|
| Baseline | IMP-009 final commit `b487261894582de7a0f5a582db73468d485b9ec5` (which sits on the IMP-008 baseline `331dcf8`) |
| Branch / worktree | `imp010/go-backend` at `../primeclassy-imp010-go` |
| Database | the existing MySQL/MariaDB schema. No PostgreSQL, no production import, one additive Go-owned table |
| Frontend | untouched — no Vue, Nuxt, routing, PWA or locale change was made |
| Verdict | **PASS WITH LIMITATIONS** (§14) |
| Checkpoints done | A, B, C, F (identity + public reads) · **G (inventory read surface)** |

Companion documents:

* [21a — Complete API parity matrix](21a-IMP010-API-PARITY-MATRIX.md) — all 375 routes with per-endpoint status (generated).
* [AGENTS.md](../AGENTS.md) — the operating contract (scope, testing policy, production safety).
* [05-BUSINESS-RULES.md](05-BUSINESS-RULES.md) — the locked invariants this package must not change.

---

## 1. Objective and what "migration" means here

The Owner authorised a controlled **Laravel → Go** migration in the local environment. The brief is explicit
that this is not a rewrite of the business system, that Laravel stays available until parity is *demonstrated*
and cutover is separately approved, and that a checkpoint must not be left behind when a critical invariant is
unverified.

This package therefore delivers a **verified vertical slice** rather than a "big bang" port:

1. a complete, machine-generated audit of the Laravel API surface (checkpoint A);
2. a working Go service with the same HTTP contract, configuration discipline, observability and test harness
   (checkpoint B);
3. identity, session/CSRF and the public read surface, **contract-compared against the running Laravel** on the
   same database (checkpoints C and F);
4. an explicit, per-endpoint statement of what is **not** ported yet, and why proceeding further without the
   financial/inventory design would violate the brief (checkpoints D and E, §9–§10).

The honest headline: **20 of 375 `/api/v1` routes are served by Go (5.3%), and every one of them is proven
byte-compatible against Laravel.** The remaining 355 are untouched and still answered by Laravel.

## 2. Git state

Starting state (verified before any edit):

```
worktree  /Users/kmsitcomputer/KMSIT-Server/apps/primeclassy-imp009-nuxt
branch    imp009/nuxt-frontend
HEAD      b487261894582de7a0f5a582db73468d485b9ec5   (clean tree)
```

Existing worktrees/branches preserved untouched:

| Worktree | Branch | HEAD |
|---|---|---|
| `primeclassy` | `fix/transaction-reset-financial-dependencies` | `6a14041` |
| `primeclassy-imp008-baseline` | `baseline/laravel-imp008` | `331dcf8` |
| `primeclassy-imp008-rem11-archive` | `archive/rem11-historical-pos-20261118` | `877c4a1` |
| `primeclassy-imp009-nuxt` | `imp009/nuxt-frontend` | `b487261` |
| `primeclassy-imp010-go` | **`imp010/go-backend`** (new) | `b487261` + IMP-010 commits |

No branch was rewritten, no history was shared, nothing was pushed.

## 3. Baseline audit (checkpoint A)

### 3.1 How the inventory was produced

Not by regex: `php artisan route:list --json` was executed **against this worktree's own routes** (the parent
worktree still carries the 19 REM-11 routes, so its output would have overstated the surface by 13 routes).
The result is committed as `go-backend/internal/api/parity_routes.json`; a Go test pins the counts.

### 3.2 Measured surface (this baseline)

| Dimension | Value |
|---|---|
| `/api/v1` routes | **375** (+4 non-API: `/`, `/up`, `/storage/{path}`, `/sanctum/csrf-cookie`) |
| Public (no session) | **31** |
| Session-guarded | **344** |
| Controller files | 81 (77 of them appear in the `/api/v1` table) |
| Services (`app/Services`) | 140 |
| Models | 114 |
| Policies | 19 |
| Migrations | 172 (the 4 REM-11 migrations are absent — REM-11 is excluded) |
| Middleware aliases | `role`, `agent.linked`, `not.installed` + global `api` group |
| Role gates | `super_admin` 195, `agen` 186, `admin` 162, `sales-kurir-sub` 62, `korsal` 52, `keuangan` 50, `sales` 49, `gudang` 41, `koordinator-kurir` 34, `kurir` 21, `konsumen` 13 |
| Rate limiters | `throttle:N,M` per route plus named buckets (`recovery-*`, `wa-number-*`, `email-verify-*`, `agent-wa-device`) |

Largest areas by route count: `admin` 59, `warehouse` 24, `orders` 20, `reports` 18, `crm` 17,
`google-sheets` 7 (full breakdown in [21a §Totals](21a-IMP010-API-PARITY-MATRIX.md)).

### 3.3 Database dependencies identified

Schema facts that constrain the port (verified by reading the migration-built test schema):

* `utf8mb4_unicode_ci` everywhere — case-insensitive uniqueness is load-bearing (email, slug, sku, order_no).
* Money is `decimal(12,2)` (orders/payments) and `decimal(14,2)` (refund ledger); geo is `decimal(10,7)`.
* `enum` columns are widely used (order status, payment status, role-ish discriminators).
* Generated columns + triggers exist (`warehouse_stocks.target_identity`, stock revision counters, shipment
  self/sub consistency) — they run inside MySQL and are therefore **unchanged by a Go port**, but any future
  schema rewrite must re-create them.
* `sessions` (Laravel's own) holds `payload = base64(serialize(php array))` — see §6.
* `settings` uses the reserved words `key` and `group` as column names.

### 3.4 Measurable acceptance criteria set for this package

| Criterion | Target | Result |
|---|---|---|
| Route inventory matches the Laravel table | exact | 375/375 (§3.2, pinned by test) |
| Implemented endpoints contract-identical | 100% of ported | 26/26 public + 24/24 auth (§8) |
| Go test suite | green, ≥50 tests | 60 tests, green |
| `go vet` | clean | clean |
| No backend/frontend file changed | 0 | 0 (§11) |
| No production/remote/Git side effect | none | none |

## 4. Go architecture and dependency decisions (checkpoint B)

### 4.1 Shape: one modular monolith, one database

```
cmd/api            entrypoint: config → logger → DB → server → graceful shutdown
cmd/migrate        additive provisioning for the Go-owned tables only
internal/config    env loading + validation (incl. the *_test database policy)
internal/httpx     the {success,message,data,errors,meta} envelope + API error type
internal/i18n      message catalogue generated from backend/lang/*/messages.php (+ drift test)
internal/db        MySQL pool, UTC session, transaction helper, MySQL error classification
internal/auth      bcrypt verification, DB sessions, CSRF, RBAC gates, permission map
internal/catalog   public product/category reads
internal/cms       homepage blocks, articles, pages
internal/site      settings, languages, agent directory, region cascades, install status
internal/api       router, middleware chain, handlers, embedded parity inventory
```

The boundaries are the ones the repository actually has (identity, catalog, content, site config), not the
ten-module sketch from the earlier audit — `inventory`, `ordering`, `payments`, `refunds` and `reporting` are
**not** created yet because nothing is ported into them; inventing empty packages would be noise.

### 4.2 Dependencies (chosen from the actual requirement, not by default)

| Dependency | Decision | Reason |
|---|---|---|
| `net/http` + `ServeMux` (Go 1.22+ patterns) | **use, no router** | `GET /api/v1/products/{slug}` style patterns cover the entire 375-route table. A chi/echo/gin dependency would add nothing here. |
| `go-sql-driver/mysql` | **use** | the only MySQL/MariaDB driver required; `database/sql` stays the abstraction. |
| explicit SQL | **use, no ORM** | the parity work is precisely about *which SQL* runs. An ORM would hide the join predicates and `ORDER BY` expressions that the API contract depends on (two real defects in this package were found exactly by comparing SQL-level ordering and joins). |
| `golang.org/x/crypto/bcrypt` | **use** | must verify the existing `$2y$` hashes; stdlib has no bcrypt. |
| `log/slog` | **use (stdlib)** | structured JSON logs without a framework. |
| validation library | **not used** | the enforced rules are few (required/in/exists/length) and the *sentences* must match Laravel exactly; a generic validator would fight the catalogue. |
| migration framework | **not used** | Laravel owns the schema. `cmd/migrate` applies only the Go-owned additive table, idempotently, with checksums. |
| Redis/queue/metrics stack | **not used** | no ported endpoint needs them; the router is stateless. |

### 4.3 Configuration and safety validation

`internal/config` fails fast and refuses dangerous combinations (all covered by tests):

* a `testing` process **must** point at a `*_test`/`*_testing` database, and a non-test process **must not**;
* `SESSION_COOKIE=laravel_session` is refused (see §6);
* `SESSION_SAME_SITE=none` requires `SESSION_SECURE_COOKIE=true`;
* enum-like settings (`APP_ENV`, `LOG_LEVEL`, `LOG_FORMAT`, `SESSION_SAME_SITE`) are validated, not guessed;
* the DSN pins `parseTime`, `loc=UTC` and the connection `time_zone='+00:00'` (the app stores UTC and computes
  business-day windows explicitly).

### 4.4 Middleware chain (mapped to `bootstrap/app.php`)

| Laravel | Go | Notes |
|---|---|---|
| global `ReflectOriginBeforeInstall` | *not ported* | installer-only; the Go service never runs the installer |
| global `PreventApiResponseCaching` | every response is `Cache-Control: no-store, private` | written by `httpx.WriteJSON` |
| `SetLocale` | `RequestState` → `i18n.ResolveLocale(X-Locale → Accept-Language → id)` | same precedence |
| `EnsureFrontendRequestsAreStateful` + CORS | `CORS(statefulDomains)` | only listed origins get credentials/echoed Origin; everything else gets no CORS headers |
| `auth:sanctum` | `LoadSession` + `RequireAuth` | session resolved for every request, rejected only where required |
| `VerifyCsrfToken` | `ValidateCSRF` | double-submit, `X-XSRF-TOKEN`/`X-CSRF-TOKEN`, `419` on mismatch |
| `EnsureRole` | `RequireRole(spec)` | role re-read from the DB row every request, never from the client |
| `EnsureAgentLinked` | `RequireAgentLinked` | fail-closed, including the stale-agent check (`ValidAgentResolver::isValid`) |
| `throttle:N,M` | `Throttle(limit, window)` | per-process fixed window (§12 limitation) |
| exception renderers | `writeError` | `422/401/403/404/419/500` envelopes with the catalogue text |

## 5. Domain migration coverage (checkpoints C–E)

| Domain | Checkpoint | State |
|---|---|---|
| Identity, session, CSRF, RBAC | C | **ported & compared** (login/logout/me/register/password, 24 parity checks) |
| Public catalog (anonymous) | C | **ported & compared** (list, filters, sort, pagination, detail, categories) |
| Public content (homepage/articles/pages) | C | **ported & compared** |
| Site configuration (settings/languages/agents/regions/install status) | C | **ported & compared** |
| Inventory & warehouse — **read surface** | **G** | **ported & compared**: canonical sellable projection, agent stock lists, warehouse stock list, sub-locations, stock cards, sub-stock replenishment/requests (13 routes) |
| Inventory & warehouse — **mutation surface** | D | **not ported**: adjust, transfers, opnames, stock requests/proposals/handovers (§9) |
| Orders, checkout, shipping, courier, dispatch | D | **not ported** (§9) |
| Payments, refunds, commissions, invoices | E | **not ported** (§9) |
| Reporting, CRM, consent, notifications, integrations, media, promo, admin | E | **not ported** (§9) |

### 5.1 Two upstream inconsistencies found while porting (recorded, not silently "fixed")

1. **`messages.system.not_found` is unreachable for API 404s.** `bootstrap/app.php` registers a renderer for
   `ModelNotFoundException`, but Laravel converts it to `NotFoundHttpException` before custom renderers run, so
   `firstOrFail()` answers with `endpoint_not_found`. Go reproduces the **observed** text (`endpoint_not_found`)
   because the SPA displays it; changing it would be an unapproved behaviour change. Evidence:
   `tools/parity/compare_public_api.py` (`/api/v1/products/tidak-ada`).
2. **`ProductVariationResource.attributes` never appears** on `GET /products/{slug}` or the listing, because the
   controller eager-loads `variations` without `compositions` and the field is `whenLoaded`. An earlier Go
   draft emitted it; the comparison caught it and the key was removed (a present-but-null key is already a
   contract difference).

## 6. Session interoperability (explicitly *not* assumed)

Go **cannot** read Laravel sessions without reimplementing PHP serialisation + the cookie encrypter, which
would bind the Go binary to `APP_KEY` and to Laravel's cookie format forever. The audit's D-4 decision point was
therefore resolved conservatively:

| Aspect | Laravel | Go | Contract impact |
|---|---|---|---|
| Session storage | `sessions` (PHP-serialised payload) | `pc_go_sessions` (own table, additive) | none visible to the browser |
| Session cookie name | `primeclassy_session` (APP_NAME-based) | `pc_sid` | none — the cookie is `HttpOnly` and the frontend never reads it |
| CSRF cookie | `XSRF-TOKEN`, readable | `XSRF-TOKEN`, readable | **identical** |
| CSRF header | `X-XSRF-TOKEN` | `X-XSRF-TOKEN` (also accepts `X-CSRF-TOKEN`, `_token`) | identical |
| CSRF failure | `419` + JSON envelope | `419` + JSON envelope | identical |
| Login/me/logout payload | `{user, permissions}` | identical (compared field by field) | identical |
| Password hash | bcrypt `$2y$` | verifies `$2y$`, writes `$2a$` (PHP `password_verify` accepts both) | **interchangeable, proven by test** |

Consequence for cutover: moving a user from a Laravel-served session to a Go-served session costs **one
re-login** (a different cookie name/session table). There is no way around it without the D-4b work described
in the audit, and the two runtimes can therefore serve different route subsets of the same origin without
corrupting each other's sessions.

## 7. Frontend compatibility

No frontend change was made and none is needed:

* The SPA reads the envelope's five keys and `data` — the Go envelope is pinned by a test that asserts exactly
  five keys, `application/json; charset=utf-8`, `no-store`.
* `GET /settings`, `/languages`, `/categories`, `/products*`, `/articles*`, `/pages/*`, `/homepage`, `/agents`,
  `/regions/*` return the same JSON as Laravel — proven by `compare_public_api.py` (26/26).
* Login/logout/me/CSRF behave identically — proven by `compare_auth.py` (24/24), including the 419 and 422
  bodies the storefront and dashboard display verbatim.
* The Nuxt storefront's SSR calls are anonymous GETs; they hit the same compared endpoints.
* PWA, routing ownership, locale/SSR behaviour: untouched (§11).

Not verified in this package (and not claimable): a full browser run of the SPA against the Go service. The
contract is proven at the HTTP level against the same fixtures, and the gateway (§13) is not configured.

## 8. Evidence

Environment: `golang:1.27.4-alpine` container on `primeclassy-net`; MySQL/MariaDB (`primeclassy-mariadb`);
Laravel 11.56.1 served by `php artisan serve` from this worktree; isolated database **`primeclassy_testing`**
(126 tables, 172 migrations, **0** `historical_*`/`report_registry` tables — REM-11 is absent).

### 8.1 Go build and tests

| Command | Result |
|---|---|
| `go build ./...` | pass |
| `go vet ./...` | clean |
| `go test ./... -count=1` | **60 tests, all pass** (api, auth, config, httpx, i18n) |
| `go run ./cmd/migrate -dry-run` then apply | 1 migration applied, re-run is a no-op, checksum-guarded |
| `docker build` (multi-stage) | pass (static binary, non-root, `/healthz` healthcheck) |

Test inventory (by intent):

| Test group | What it pins |
|---|---|
| `config`: policy, precedence, DSN | the `*_testing` database rule **both ways**, `laravel_session` refusal, SameSite/Secure coupling, UTC DSN |
| `httpx`: envelope, meta, decode | the exact five-key envelope; `per_page` must **not** appear in meta |
| `i18n`: catalogue vs PHP, locale precedence | every message the Go service can emit is re-extracted from `backend/lang/*/messages.php`; drift fails the build; hostile locale falls back to `id` |
| `auth`: bcrypt `$2y$`, roles, permissions | verifies a **real PHP-produced `$2y$` hash**; 11 locked roles; permission map incl. `keuangan` separation of duties; phone normalisation |
| `api` (inventory): 375 routes, 31 public / 344 authed | the audit numbers, REM-11 absence, `implementsRoute ⇒ knownRoute` |
| `api` (integration): routing, CSRF, login/me/logout, RBAC, public reads | tested through the real handler against the real schema |

### 8.2 Laravel ↔ Go contract comparison (checkpoint F)

`tools/parity/compare_public_api.py` — 26 comparisons, **26 pass**:
settings, languages, categories, products (list, page 2, price sort, search, category-with-descendants, price
filter), product detail (simple + variation), articles (list, typed, detail), page detail, homepage block
resolution, agents, all four region levels, plus 5 negative cases (404 for missing product/article/page, 422 for
unknown parent, 422 for invalid `stock_source`) with **identical status codes and identical bodies**.

`tools/parity/compare_auth.py` — 24 comparisons, **24 pass**:
CSRF bootstrap (204 + readable `XSRF-TOKEN` + HttpOnly session cookie), 419 without token, 422 wrong password
with the same field error, successful login with a Laravel-stored password, identical `user` + `permissions`
payloads (including role-conditional `korsal_name` and own-record `google_linked`), `/auth/me`, logout → 401,
`+62` phone login resolving the same row.

### 8.3 Defects the comparison found in this package (fixed)

| # | Defect | How it was caught |
|---|---|---|
| 1 | products list selected category columns without joining `product_categories` (SQL error 1054) | integration test |
| 2 | `implementsRoute` used a bare prefix, so `/products/{product}/fees` (not ported) was advertised as implemented | integration test + parity-report walk |
| 3 | `attributes` emitted for variations where Laravel omits it | `compare_public_api.py` |
| 4 | `meta.per_page` emitted where Laravel sends only `current_page/last_page/total` | `compare_public_api.py` |
| 5 | default sort added an `id` tiebreak, changing the order of rows with equal timestamps | `compare_public_api.py` |
| 6 | 404 body used `not_found` instead of the observed `endpoint_not_found` | `compare_public_api.py` |
| 7 | 422 body used a generic message instead of `validation_failed` + the rule sentence | `compare_public_api.py` |
| 8 | `korsal_name` emitted for every role instead of only Sales/Sales-Kurir-Sub | `compare_auth.py` |
| 9 | CSRF check ran before the routing decision, masking 501/404 behind 419 | integration test |

### 8.4 Checkpoint G — inventory read surface

Scope: the canonical availability projection plus the warehouse read grids. All 13 endpoints were captured
from the **running Laravel** on the same fixtures first, then implemented, then compared.

| Endpoint | Result |
|---|---|
| `GET /stock/products` | identical (incl. the quirk that `meta.total` counts TARGETS while rows are filtered to `has_variations=false`) |
| `GET /stock/variations` | identical |
| `GET /warehouse-stock` (+ `stock_type`, `search` filters) | identical; **row SET** compared across all pages (see defect G-1) |
| `GET /warehouse/sellable` | identical for a product and a variation target, incl. the factory-plan switch |
| `GET /warehouse/settings` | identical (incl. Laravel's `firstOrCreate` side effect) |
| `GET /warehouse/sub-locations`, `/{id}`, `/{id}/stocks` | identical (incl. the generated `target_identity`/`location_identity` columns) |
| `GET /warehouse/stock-cards`, `/{id}/stock-cards` | identical (incl. `meta.per_page`, which only these two send) |
| `GET /sub-stock/my` | identical shape; `reserved` is 0 in the verified fixtures (a reservation needs an `order_item`, which belongs to the orders checkpoint) |
| `GET /sub-stock/replenishment-targets` | identical |
| `GET /sub-stock/requests` (+ status/direction filters) | identical, incl. the `requested_by` scope for Sales-Kurir-Sub and the branch scope for Admin |

`tools/parity/compare_inventory.py` — **29/29** comparisons, executed for three different roles against the same
database. The invariant tests in `internal/api/inventory_api_test.go` pin the behaviour independently of the
comparison:

| Invariant | Test |
|---|---|
| No warehouse row ⇒ legacy on-hand is the base, `source: legacy`, plan flag false | `TestSellableLegacyFallback` |
| **Any** warehouse row makes the warehouse authoritative, but only `transit`/`factory_plan` feed the base — a target holding only a `sub` row has base 0 and is in deficit | `TestSellableWarehouseAuthorityWithSubOnlyRows` |
| The factory-plan bucket counts only when the agent enabled it (and flipping it changes the base) | `TestSellableFactoryPlanSwitch` |
| Card grids read warehouse buckets only (no legacy fallback) while still reading the legacy reserved quantity | `TestStockCardsDoNotFallBackToLegacy` |
| Tenant scope: another agent's sub-location is a 404 and never appears in the list | `TestSubLocationTenantIsolation` |
| A Sales-Kurir-Sub cannot widen its request list with query filters; an Admin sees the branch | `TestSubStockRequestsAreScopedToOwnRequests`, `TestSubStockRequestsAdminSeesBranch` |
| Role gates for every ported inventory endpoint | `TestInventoryRoleGates` |
| `super_admin` must name an Agent explicitly (422, no implicit branch) | `TestSuperAdminMustNameAgent` |
| A literal sibling route is never swallowed by a `{param}` pattern | `TestLiteralSiblingRouteIsNotSwallowed` |

#### Defects found in this checkpoint

| # | Where | Finding | Action |
|---|---|---|---|
| G-1 | `StockController::warehouse` (Laravel) | The listing is **paginated without any `ORDER BY`**, so row order — and therefore page composition — depends on which index MySQL picks and changes when a `stock_type` filter is applied. Pages can overlap or skip rows between requests. | Go orders by `ws.id` (deterministic, strictly safer). The comparison asserts the **same row set** across all pages instead of page bytes. Reported, not silently "matched". |
| G-2 | `WarehouseController::sellable` (Laravel) | With neither `product_id` nor `variation_id`, `$request->validate()` returns an array without the key and the next line indexes it → **HTTP 500 "Undefined array key"**. | Go answers **422**. This is the only place Go is intentionally stricter; it is asserted explicitly in the comparison so it cannot drift unnoticed. |
| G-3 | `WarehouseStockRequestController::cards`/`subCards` (Laravel) | These are the only paginated lists in the domain that DO include `per_page` in `meta`, while `stock/products`, `stock/variations` and `warehouse-stock` do not. Inconsistent contract. | Reproduced exactly (per endpoint), pinned by `TestStockListsMetaShape` / `TestStockCardsMetaIncludesPerPage`. |
| G-4 | `implementsRoute` (this package) | A `{param}` pattern swallowed the literal sibling `/warehouse/sub-locations/eligible-owners`, advertising an endpoint that was not implemented and answering 404 instead of 501. Caught by the parity report disagreeing with the matrix generator. | Fixed: the literal is excluded from the prefix rule, and a non-numeric parameter that matches a real Laravel route now answers **501**. Regression test added. |
| G-5 | shared renderer (this package) | Rows sharing one product consumed (deleted) the internal image key from a shared map, so every row after the first lost `product_image_url`. Caught by the comparison. | Fixed by rendering a shallow copy; `TestWarehouseStockResourceShape` guards it. |

## 9. Why checkpoints D and E were not implemented

The brief forbids simplifying financial or inventory workflows to move faster, and forbids proceeding when a
critical invariant is unverified. Porting them correctly needs three decisions that are **not** code decisions
and are still open in the audit's owner register:

| Blocker | Why it cannot be improvised | Blocks |
|---|---|---|
| **Single writer for payment truth** (`paid_amount`/`remaining_amount`/`payment_status` are written by `PaymentService` today, plus an initial write in `OrderService`) | The Go module boundary must state which runtime owns those columns *during* the migration. Two writers on one row is the exact failure the audit flagged (E-7/R-04). | payments, refunds, reporting |
| **Money representation** (decimal vs integer minor units, and where the boundary sits) | `Refund/Money.php` already works in cents while orders use `decimal(12,2)` + float rounding. Porting to Go without choosing one representation would silently change rounding. | payments, refunds, commissions, invoices |
| **Lock ordering as a first-class API** (`StockService::canonicalReservationTargets`, Order → Stock Request → target) | A port that "looks right" but orders locks differently produces the deadlocks AGENTS.md §11 forbids fixing with sleeps. | inventory, orders, fulfilment |

Inventory/orders additionally require the reservation lifecycle, shortage handling, handover gates and the
shipment canonicalisation triggers to be verified together — none of which can be proven by HTTP comparison
alone; they need the two-connection concurrency regressions the Laravel suite already has.

What this package *does* provide for those domains: the identity/RBAC/subscription plumbing they will sit on,
plus the comparison harness (`compare_*.py`) and the fixture generator that any future domain must extend.

## 10. Database compatibility for IMP-011

IMP-011 plans to import the full production database into the local MySQL/MariaDB and (separately) considers
PostgreSQL. Findings that matter for that step:

1. **Nothing in this package constrains the schema.** The only addition is `pc_go_sessions` (+ `go_migrations`),
   both additive, both created by `cmd/migrate`, both removed with two `DROP TABLE`s.
2. **`sessions` remains Laravel's.** A production import will restore Laravel session rows that reference
   production users' logins; those rows are simply ignored by Go (different table). No cleanup is required, and
   no session can be "half-migrated".
3. **The Go service reads the schema the Laravel migrations create** — it does not need a schema-owned table to
   run, and on a fresh/unmigrated database the public endpoints degrade to the same empty/default payloads the
   `SafeSchema` guards produce (implemented and tested via `IsMissingSchema`).
4. **Timestamps**: the connection is pinned to UTC and the Go code formats `TIMESTAMP` as Eloquent does
   (`…Z` with microseconds). Any database whose server time zone is not UTC would currently shift values — the
   connection-level `time_zone='+00:00'` prevents that, and the comparison runs prove it on real rows.
5. **`utf8mb4_unicode_ci` uniqueness** is relied on implicitly: the Go queries filter by exact slug/email/SKU.
   A future PostgreSQL move must keep the case-insensitive semantics (audit E-5 item 8) or Go's lookups will
   start behaving differently from Laravel's.
6. **No production data was imported and none is needed for this package**; every fixture is synthetic and lives
   in `tools/parity/seed_test_fixtures.sql`.

## 11. Boundaries respected

| Prohibition | Evidence |
|---|---|
| No production access / deployment | only local containers; no remote host contacted |
| No remote push | branch is local only (`git log @{u}` → no upstream) |
| No PostgreSQL implementation | no `pgsql` code, no driver, no migration |
| No production database import | fixtures are synthetic; DEV (`primeclassy_db`) untouched |
| No REM-11 restoration | 0 `historical_*`/`report_registry` tables in the test DB; inventory test asserts their absence |
| No financial rule change | no financial code ported, no Laravel file changed |
| No removal of approved functionality | Laravel is untouched and still serves 355 routes |
| No security weakening | CSRF/419, session rotation, role re-read, agent-linked fail-closed, bcrypt cost preserved, `no-store` on every response |
| No automatic Laravel removal / cutover | nothing routed to Go; gateway not configured |
| No IMP-011 execution | PostgreSQL/import work not started |

`git diff --name-only b487261..HEAD -- backend frontend docs` is limited to this document, the parity matrix and
`go-backend/` + `tools/parity/`.

## 12. Remaining gaps and limitations

1. **Coverage is 20/375 routes (5.3%).** Everything in checkpoints D/E (inventory, orders, checkout, shipping,
   payments, refunds, commissions, invoices, reports, CRM, consent, notifications, integrations, media, admin)
   is still Laravel-only and answers `501` from Go.
2. **Authenticated catalog responses differ in one documented way**: Go omits `stock`/`agent_available_quantity`
   because the agent/Sub availability resolver (`WarehouseStockService::sellableForTargets`,
   `SubStockService::sellable`, `StockSourceResolver`) belongs to the inventory domain. Anonymous responses
   (which is what the storefront SSR uses) are exact.
3. **`POST /auth/register` does not emit the welcome e-mail / restricted WhatsApp notice**
   (`RegistrationNotifier`). The user, referral chain, closure rows and audit entry are created identically; the
   queued notification is a Laravel-queue side effect that needs a queue bridge (or a Go job) — deliberately not
   half-implemented.
4. **Rate limiting is per-process.** Laravel's `throttle:` uses the shared cache store. One Go process per origin
   is correct for the current single-instance topology; multi-instance needs a shared counter.
5. **The installer wizard is not ported** (`/install/*`), by design: it is a one-shot bootstrap surface and
   `GET /install/status` (read-only) is enough for the frontends.
6. **Not ported and needed before any nav-wide cutover**: media/file serving beyond `/storage` passthrough,
   the `/payment-proofs/{id}` protected stream (`private, no-store` + authorization-before-stream), PDF invoice
   rendering, and the Excel/Sheets exports.
7. **`google_linked` query cost**: it is resolved per own-record request (a single `EXISTS`); fine at this scale,
   worth batching if it ever appears on a list endpoint.
8. **No load/soak test** was run. The service is stateless and the queries mirror Laravel's, but performance
   parity is unmeasured and not claimed.
9. **A full-page SPA/Nuxt browser run against Go was not performed** (contract-level comparison only).

## 13. Local rollback procedure

Nothing is cut over, so rollback is a no-op today. If a proxy is later configured to send a subset to Go:

1. **Immediate (seconds, no code, no data):** remove the routing rules → Laravel answers every path again. Go can
   be stopped (`docker stop pc_go_imp010` / `systemctl stop`), because its only state is `pc_go_sessions`, which
   no other runtime reads.
2. **Sessions:** users who logged in through Go hold a `pc_sid` cookie that Laravel ignores; they are simply
   anonymous to Laravel and log in again. No Laravel session is affected.
3. **Schema:** optionally `DROP TABLE pc_go_sessions;` and `DROP TABLE go_migrations;` — both are Go-owned and
   referenced by nothing else. The business schema is untouched either way.
4. **Code:** revert the IMP-010 commits; `go-backend/` and `tools/parity/` disappear, Laravel and the frontends
   are unaffected (they were never modified).

## 14. Verdict

**PASS WITH LIMITATIONS**

What this means concretely:

* Checkpoints **A, B, C and F are complete and verified**, with 60 Go tests, `go vet` clean, and **50
  cross-runtime contract checks (26 public + 24 auth) passing against the running Laravel on the same database**.
* Checkpoints **D and E are deliberately not started**, because the three prerequisites above are Owner/architect
  decisions (single writer for payment truth, money representation, lock ordering as an API). Implementing them
  as a "fast port" would violate the brief and the locked invariants.
* The Laravel application remains the reference and the rollback implementation; no production, database,
  frontend or Git-history side effect occurred.

IMP-010 is **not closed**. Closure requires Owner + ChatGPT review of this report, an explicit decision on the
three D/E prerequisites, and a new package (with its own brief) for the financial/inventory domains.
