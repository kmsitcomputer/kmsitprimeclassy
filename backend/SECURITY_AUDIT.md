# Security Audit — Prime Classy Cake & Cookies

Date: 2026-09-06
Scope: Laravel 11 + Sanctum SPA backend (`backend/`), 7 roles (super_admin, agen, korsal, sales, konsumen, admin, kurir).
Method: five parallel read-only, penetration-style code-tracing passes across the 30 requested categories, followed by a manual fix pass and a full regression run (`php artisan test`).

No new features were added. Every fix below is a hardening/patch to existing code paths only. All 236 existing backend tests pass after every change (`236 passed, 1095 assertions`).

## Summary

| # | Category | Verdict | Action taken |
|---|---|---|---|
| 1 | Authentication | NEEDS FIX (rate limiting) | **Fixed** — throttle added to login/register |
| 2 | Authorization | VERIFIED SAFE | none |
| 3 | RBAC | VERIFIED SAFE | none |
| 4 | IDOR | VERIFIED SAFE | none |
| 5 | SQL Injection | VERIFIED SAFE | none |
| 6 | XSS | CONFIRMED VULNERABLE (Product description) | **Fixed** — sanitizer wired in |
| 7 | CSRF | VERIFIED SAFE | none |
| 8 | Mass assignment | VERIFIED SAFE | none |
| 9 | File upload | NEEDS FIX (SVG gap) | **Fixed** — `mimes:` allowlist added |
| 10 | Path traversal | VERIFIED SAFE | none |
| 11 | API security | NEEDS FIX (no throttling anywhere) | **Fixed** — throttle added to auth/checkout/webhook |
| 12 | Rate limiting | NEEDS FIX | **Fixed** (same as #1/#11) |
| 13 | Password security | VERIFIED SAFE | none |
| 14 | Session security | NEEDS FIX (env only) | documented below (env, not code) |
| 15 | Token security | VERIFIED SAFE / not applicable | none |
| 16 | Webhook security | NEEDS FIX (minor race) | **Fixed** — race wrapped, fails to `duplicate` not 500 |
| 17 | Payment security | VERIFIED SAFE | none |
| 18 | Referral manipulation | VERIFIED SAFE | none |
| 19 | Stock manipulation | VERIFIED SAFE | none |
| 20 | Price manipulation | VERIFIED SAFE | none |
| 21 | Fee leakage | VERIFIED SAFE | none |
| 22 | Agent data isolation | VERIFIED SAFE | none |
| 23 | Order data isolation | VERIFIED SAFE | none |
| 24 | Refund manipulation | CONFIRMED VULNERABLE (race) | **Fixed** — `ReturnService::review()` locked & re-validated |
| 25 | Additional payment manipulation | VERIFIED SAFE | none |
| 26 | Admin privilege escalation | VERIFIED SAFE | none |
| 27 | Super Admin restrictions | VERIFIED SAFE | none |
| 28 | Courier authorization | VERIFIED SAFE | none |
| 29 | Sensitive information exposure | VERIFIED SAFE | none |
| 30 | Environment secrets | VERIFIED SAFE (working copy), env hardening noted | documented below |

Penetration-style repros explicitly requested by the user were traced and cleared:
- `GET /api/orders/123` by another user → blocked by `BelongsToAgentScope` (branch) + `OrderPolicy::view` (exact `konsumen_id` match). 404/403, never leaks.
- Client-submitted price/fee/agent_id/sales_id/status/stock/payment_status → none of these fields exist in any validated request payload; every one is server-derived from DB state at write time (`OrderService::priceAndReserveLine`, `FeeService`, `StockService`).
- Cross-role dashboard access → every route group is gated by `role:` middleware plus a Policy/service ownership check; no route was found reachable by an unintended role.

---

## Fixes applied this pass

### 1. No rate limiting anywhere (HIGH) — fixed
`bootstrap/app.php` never called `->throttleApi()` and no `RateLimiter::for(...)` was registered anywhere — login, registration, checkout, and the payment webhook endpoint had zero request-rate protection.

Added `throttle:` middleware directly on the affected routes in `routes/api_v1.php`:
- `POST /auth/login` → `throttle:10,1`
- `POST /auth/register` → `throttle:5,1`
- `POST /checkout/quote`, `POST /orders` → `throttle:30,1`
- `POST /webhooks/payment/{method}` → `throttle:60,1` (generous — legitimate gateways retry)

### 2. Product description never sanitized — stored XSS (HIGH) — fixed
`HtmlSanitizerService`'s own docblock claimed product descriptions were sanitized through it, but `ProductController::store()`/`update()` never called it — `description` was written to the DB and served back through the **public**, unauthenticated catalog API verbatim. An admin/agen could store `<img src=x onerror=...>` or similar, which would execute if the storefront ever renders the field with `v-html`.

Fixed: `ProductController` now injects `HtmlSanitizerService` and sanitizes `description` in both `store()` and `update()`, matching the existing CMS article/page/homepage-block pattern.

`ProductTranslation.description` has the identical gap in its column definition, but no controller in the codebase currently writes to that model at all (dead write path, not reachable via any endpoint) — noted for whoever wires up translations later, not an active vulnerability today.

### 3. `StoreProductImageRequest` missing `mimes:` allowlist — SVG upload (MEDIUM) — fixed
Every other `image` validation rule in the codebase pairs it with an explicit `mimes:jpg,jpeg,png,webp` allowlist — this was the one outlier, and it's also the one image-upload path that bypasses `MediaService` (no `getimagesize()` decode check, no MIME-vs-extension cross-check). Laravel's bare `image` rule accepts SVG, which can carry an embedded `<script>`/`<foreignObject>` payload that executes if the stored file is ever opened directly or embedded.

Fixed: added `'mimes:jpg,jpeg,png,webp'` to `StoreProductImageRequest::rules()`.

### 4. Return-approval race condition — double stock restock (HIGH) — fixed
`ReturnService::review()` validated `$return->status` **before** entering its `DB::transaction`, then approved every `ReturnItem` unconditionally inside the transaction without checking that item's own current status. Two concurrent `PATCH /admin/returns/{return}/review` calls (double-click, client retry) could both pass the outer guard, and the second call — after blocking on the row lock and then proceeding once the first committed — would re-approve the same item and call `restockItem()` a second time, double-crediting stock for one physical return.

Fixed:
- `review()` now re-locks (`lockForUpdate()`) and re-validates the parent `ReturnRequest`'s status *inside* the transaction.
- Each `ReturnItem`'s approve/reject branch is now conditional on that item's own `status === 'pending'` before mutating stock or state, so a second pass on an already-processed item is a no-op.
- `markItemRefunded()` additionally now locks the `ReturnItem` row and checks `refund_status` (not just `status`) before marking it processed, tightening what was flagged as a fragile (though not currently exploitable) idempotency guard.

### 5. Webhook idempotency insert race (LOW, robustness only) — fixed
The `(payment_method_id, event_id)` unique DB constraint is the real idempotency guard and was already correct — but the application-level check-then-insert wasn't wrapped in a try/catch, so a genuine race between two near-simultaneous deliveries of the same webhook event would surface as an uncaught `QueryException` (500) on the second delivery instead of a clean `duplicate` response. Not a security hole (the constraint still prevented any double state-change), but a robustness gap that could confuse a retrying gateway.

Fixed: the insert is now wrapped in a `try/catch (QueryException)` that returns `['status' => 'duplicate', ...]` on a unique-constraint hit, identical to the already-existing-row path.

---

## Verified safe (no change needed)

- **Authorization / RBAC / IDOR** — every `{order}`/`{shipment}`/`{returnItem}`/`{codPaymentProof}` route is gated by both a global Eloquent scope (`BelongsToAgentScope`, branch-level) and an explicit Policy or in-controller ownership check (record-level). No cross-branch or cross-user data access was reproducible for any of the 25 business-logic scenarios traced (Agent A vs Agent B, Sales A vs Sales B, Admin A vs Agent B's orders, Courier A vs Agent B's orders, Consumer A vs Consumer B, etc.).
- **SQL injection** — every `whereRaw`/`selectRaw`/`havingRaw`/`orderByRaw` call either uses parameter bindings or is a static developer-authored string never touched by request data. No `DB::statement` is ever reachable from HTTP input.
- **CSRF** — Sanctum's stateful-domain pipeline handles CSRF for genuine SPA requests; the payment webhook route is correctly outside the `auth:sanctum` group and relies on per-gateway signature verification instead, not an accidental CSRF exemption that also drops other checks.
- **Mass assignment** — no model sets `$guarded = []`; every write path uses either a FormRequest's validated subset or an explicit field array, never `$request->all()`.
- **Path traversal** — every `Storage::` call operates on a server-generated path read back from the database, never a raw client-supplied path segment.
- **Price / fee / stock manipulation** — `StoreOrderRequest` accepts only `product_id`/`product_variation_id`/`quantity` per line; `unit_price`, all four fee columns, and stock reservation are always resolved server-side inside `OrderService::priceAndReserveLine` and `StockService`, under row-level `lockForUpdate()` locks that close the last-unit-race window.
- **Snapshot immutability** — `unit_price_snapshot`/fee columns are written exactly once at order creation and never rewritten by any later fulfillment/refund/additional-payment code path; changing a live `Product`/`ProductFee` has zero effect on any existing order.
- **Payment security** — `PaymentTransaction.amount` is always server-derived from `order->total_amount`; every gateway webhook handler (Xendit, Tripay, Stripe) verifies its signature with `hash_equals` against the raw request body, and the reported amount is cross-checked against the stored transaction amount (>0.01 mismatch is rejected) before the order is ever marked paid. Stripe additionally enforces a 300-second replay-tolerance window.
- **Sensitive information exposure** — `PaymentGatewayConfig`/`ShippingProvider` credentials are never read back through any Resource or controller response (only `configured`/`is_active` booleans); no Resource class exposes a `_key`/`_secret`/`_token`/`password`/`config` field.
- **Fee visibility** — `agent_fee_amount`/`sales_fee_amount`/`courier_fee_amount` are gated per-role in `OrderItemResource`/`FeeResource` exactly per the documented business rule (super_admin/agen/sales see agent+sales fee; only super_admin/agen see courier fee; kurir/korsal/konsumen see neither).
- **Courier restrictions** — kurir can never reach fee, report, stock, price, or bulk order-status endpoints; every action available to kurir is either read-only or a pure logistics status flip, scoped to their own branch.
- **Super Admin business-rule parity** — order cancellation/status-transition/fulfillment-window rules apply identically regardless of role; the only `super_admin`-conditioned branches anywhere in the service layer are pure visibility/scoping (which agents' data a report includes), never a business-rule bypass.

## Minor / informational (no fix required, noted for awareness)

- **Kurir user-listing inconsistency**: `GET /users` (index) includes kurir in its route-level role gate, but `UserPolicy::view` has no explicit kurir branch, so a kurir can list branch users via `index()` but gets a 403 on `show()` for any individual user besides themselves. This fails closed (denies rather than leaks), so it's not a security gap — just worth confirming the intended behavior with the product owner.
- **Fulfillment quantity-increase pricing**: `OrderFulfillmentService::increaseFulfillment` bills added units at the original (frozen) `unit_price_snapshot` and creates no additional `Commission` rows for the added quantity. This is a stronger form of price immutability than expected, not a vulnerability — but it means agent/sales/courier earn no commission on fulfillment-increase quantity. Flagged for business-owner sign-off, not a code defect.

## Environment / deployment hardening (not code bugs — configuration only)

These do not require a code change; they are `.env` values that must be set correctly at deploy time and were confirmed as gaps in the current **local development** `.env`, not in the application code:

1. **`SESSION_SECURE_COOKIE`** is unset in the working `.env`, so session cookies are not flagged `Secure`. Sanctum's stateful middleware already forces `http_only=true` and `same_site=lax`, but does not touch the `secure` flag. Set `SESSION_SECURE_COOKIE=true` in production (requires HTTPS termination).
2. **`APP_KEY`** currently sitting in the working `.env`/`.env.testing` should be rotated (freshly generated via `php artisan key:generate`) before any shared/production deployment — this key is correctly `.gitignore`'d and was never found committed anywhere, but a key generated for local development should not be reused in production.
3. **`APP_DEBUG`** must be `false` and **`APP_ENV`** must be `production` in production — `.env.example` defaults to `APP_DEBUG=true`/`APP_ENV=local`, which is correct for a template but will leak exception class/file/line/message on every 500 response if left on in production (`bootstrap/app.php`'s exception renderer is otherwise already production-safe when debug is off).

None of the above are exploitable in the current local/testing environment; they are standard pre-deployment checklist items and are recorded here so they aren't missed at go-live.
