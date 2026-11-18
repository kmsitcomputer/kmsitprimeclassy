# 21b — IMP-010 compatibility deviation register

Every place where the Go service behaves **differently** from the Laravel reference. Anything not listed here is
asserted identical by the comparison suites (`tools/parity/compare_*.py`), for the endpoints they cover.

Rules for this register:

1. A deviation is only acceptable when the reference behaviour is a defect (5xx, undefined ordering, an
   internal bound that leaks) or when the difference is invisible to the API contract.
2. Every entry states what the reference does, what Go does, why, and **how it is tested**. A deviation that is
   not asserted is not on this list — it is a bug.
3. A deviation is never described as "exact parity".
4. Business rules (money, stock, roles, state machines) are **never** deviated from; only the wrapper behaviour
   around a defect may differ.

| # | Endpoint | Laravel | Go | Why | Asserted by |
|---|---|---|---|---|---|
| D-1 | `GET /warehouse-stock` | Paginates **without any `ORDER BY`**. Row order — and therefore page composition — depends on which index MySQL picks and changes when a `stock_type` filter is applied, so pages can overlap or skip rows between requests. | Orders by `ws.id` (deterministic). | Undefined ordering cannot be reproduced; a stable order is strictly safer for a paginated list and is what every other list endpoint already does. | `compare_inventory.py` compares the **row set across all pages** (not page bytes) and reports the ordering difference explicitly. `TestWarehouseStockResourceShape` pins the rendered shape. |
| D-2 | `GET /warehouse/sellable` with neither `product_id` nor `variation_id` | **HTTP 500** — `"Undefined array key \"product_id\""`. `$request->validate()` returns only the keys that were present, and the next line indexes the missing one. | **HTTP 422** with a `required` field error on `product_id`. | A 500 on a missing parameter is a defect (it leaks an exception and gives the client no actionable information). Validating the pair is the correct contract. | `compare_inventory.py` asserts the divergence explicitly (`500 vs 422`) so it can never drift silently; `TestSellableRejectsMissingTarget` pins the Go side. |
| D-3 | `POST /stock/adjust` | **403 for every caller** in the shipping configuration: `AdjustStockRequest::authorize()` requires `config('warehouse.authoritative') === false` **and** role agen/admin, and `WAREHOUSE_STOCK_AUTHORITATIVE` defaults to **true**. | Reproduced exactly: 403 whenever `WAREHOUSE_STOCK_AUTHORITATIVE` is unset/true, or when the caller is not agen/admin. The legacy write path exists but is unreachable under the default flag. | Not a deviation in behaviour — it is listed because the *implementation* contains a legacy path that is disabled by default. Recorded so nobody "helpfully" enables it. | `compare_inventory_mutations.py` asserts 403 for agen **and** admin (guards scenario). |
| D-4 | Session storage | `sessions` table, `payload` = base64(PHP-serialised array); cookie name derived from `APP_NAME`. | Own `pc_go_sessions` table (additive), cookie `pc_sid`; CSRF contract identical (`XSRF-TOKEN` readable, `X-XSRF-TOKEN` header, 419 on mismatch). | Reading a Laravel session would require reimplementing PHP serialisation + the cookie encrypter, binding Go to `APP_KEY` forever. The browser-visible contract is unchanged; the only cost is one re-login when a user crosses runtimes. | `compare_auth.py` asserts the shared contract (419, login body, `/auth/me`, logout → 401, cookie flags) and states the cookie-name difference explicitly. |
| D-5 | Rate limiting storage | Shared cache store (multi-instance safe). In the local test env `CACHE_STORE=array` makes `throttle:` effectively per-request, i.e. inert. | Per-process fixed window, keyed by `user id ‖ ip` + path. | One Go process per origin matches the current single-instance topology. **Multi-instance needs a shared counter** — tracked as a limitation, not hidden. | `compare_inventory_mutations.py` treats a 429 as a real control (waits it out rather than weakening it). |
| D-6 | `GET /warehouse/settings` | `firstOrCreate` — a **GET creates the row** on first access. | Reproduced (same side effect). | Listed because a GET with a write side effect is surprising; it is part of the observable contract (the response carries `id`/`created_at`, which can only exist if the row was created). | `compare_inventory.py` compares the full body including `id`/timestamps. |
| D-7 | Timestamp serialisation | Carbon → `2006-01-02T15:04:05.000000Z`. | Same form; columns are read with `CAST(... AS CHAR)` and formatted in Go. | The driver would otherwise reinterpret the value in the connection's time zone. Reading as text makes the UTC contract explicit and byte-identical. | Every comparison compares timestamps as strings, so any drift fails immediately. |
| D-8 | `GET /sub-stock/my` | Computes `reserved` from `sub_stock_reservations`. | Same query; the verified fixtures contain **no** Sub reservation (that table requires an `order_item_id`), so `reserved` is 0 in the compared runs. | The reservation-with-value case needs the orders fixture chain, which belongs to the next checkpoint. Recorded so the coverage is not overstated. | `compare_inventory.py` compares the endpoint (identical); the gap is the fixture, not the code. |

## Deviations explicitly NOT taken

These were considered and rejected, so they do not creep in later:

| Considered | Why rejected |
|---|---|
| Integer minor-unit (cents) money | The schema is `DECIMAL(12,2)`/`(14,2)` and Laravel rounds with `round(float, 2)`. Converting would change approved rounding at the boundary. Go uses exact decimal arithmetic on `DECIMAL` values (D-02). |
| Reimplementing Laravel cookie encryption | Couples Go to `APP_KEY` and PHP's cookie format permanently for no user-visible benefit (see D-4). |
| Reading Laravel's `sessions` rows | Requires a PHP-serialisation decoder in Go; same objection as above. |
| Relaxing the CSRF check for "simplicity" | It is a security control; `419` behaviour is asserted by `compare_auth.py`. |
| Skipping `EnsureAgentLinked` on the Go side | It is a fail-closed tenant guard; the stale-agent check is implemented (`isValidAgent`) and role-tested. |
| Adding a secondary sort to hide D-1 | Would change which rows a page returns relative to the reference in the *defined* cases (the reference's order is undefined, so "matching" is not achievable; being deterministic is the honest improvement). |
| Returning 200 for `POST /stock/adjust` in authoritative mode | The reference forbids it; allowing it would let the dashboard edit stock the warehouse owns (a business-rule violation). |
