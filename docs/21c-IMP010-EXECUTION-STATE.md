# 21c — IMP-010 execution state and resumable continuation record

**Scope now governed by the Owner's final mandate: migrate 100% of the Laravel backend to Go, every feature and
integration included, then archive Laravel.** `AGENTS.md` §0 records the mandate and removes the administrative
review gates that previously stopped this work; the production-safety rules remain absolute.

This file is the resumable state. Read §1 (position), §4-x (what is done and its traps) and §4a (exact next action)
before touching code. Do not re-derive a completed checkpoint.

## 1. Verified position

| Metric | Value |
|---|---|
| Routes `/api/v1` in the baseline | 375 |
| Served by Go | **112 (29.9%)** |
| Fully GO | 106 |
| PARTIAL (incomplete behaviour) | 6 |
| Laravel-only (Go answers 501) | **263** |
| Go tests | **124 test functions** green (all packages, incl. 6 media oracles), `go vet` clean, gofmt clean |
| Cross-runtime comparison checks | **400 passing** (26 public + 24 auth + 29 inventory read + 78 inventory mutations + 17 admin config + 17 address + 21 promo + 38 checkout steps + 25 checkout quote + 43 orders + 22 warehouse requests + 20 proposals + 5 handovers + 10 order lifecycle + 11 order read + 14 item lifecycle) |
| Laravel files modified by IMP-010 | **0** |
| Production / remote side effects | none |

## 1a. Owner mandate (final) — how work proceeds from here

| Rule | Effect |
|---|---|
| Migrate everything | All 375 `/api/v1` routes to **GO**, plus jobs, queue, scheduler, installer, OAuth, payments, refunds, stock, commissions, invoices, PDF, Excel, media and integrations. |
| Laravel is the specification | Every technical question is answered by reading the Laravel source, the schema, the contract and verified runtime behaviour. Do not ask the Owner to choose between implementation options. |
| No administrative stops | No review checkpoint, stage gate or re-confirmation blocks progress. Fix findings directly, with tests, and continue. |
| Scoped testing during migration | Tests for the changed area + the matching differential harness. Full regression only at final acceptance. |
| Parity before removal | Laravel is archived and PHP removed only after full parity is verified. |
| Still forbidden | Production access, deployment, remote push, destructive operations outside the isolated test environment. Security is never weakened to make a response match. |

**No feature may be dropped or simplified to finish faster.** A domain that cannot be fully ported yet stays
explicitly listed as outstanding rather than silently reduced.

## 2. Domains completed and verified

| Domain | Routes | Evidence |
|---|---|---|
| Identity, session, CSRF, RBAC | 6 | `compare_auth.py` (24) |
| Public catalog, content, site config | 16 | `compare_public_api.py` (26) |
| Inventory reads (canonical sellable + grids) | 13 | `compare_inventory.py` (29) |
| Inventory mutations (transfers, adjustment gate) | 8 | `compare_inventory_mutations.py` (37) + 7 two-connection concurrency tests |
| Admin system configuration (languages, settings) | 7 | `compare_admin_config.py` (17) |
| Inventory opname, plan transfer, eligible owners | 10 | `compare_inventory_mutations.py` (78, incl. the opname 409 stale-snapshot path) + 5 opname state-machine/concurrency tests |
| Sub-location ownership (create/update/assign/deactivate) | 4 | `compare_inventory_mutations.py` sublocation scenario + owner/previous_owner/is_active state diff |
| Konsumen address book | 4 | `compare_address.py` (17) — incl. the resolved province/regency/district columns and the ownership 404 |
| Promotions (vouchers + discounts) | 8 | `compare_promo.py` (21) — incl. both resulting row sets and the RBAC refusal |
| Product categories — admin mutations | 3 | create/update/delete; role split, normalized sibling names, parent-cycle/duplicate guards, slug uniqueness, delete conflict |
| Checkout wizard contract | 1 | `compare_checkout.py` (38) — 11 scenarios: guest, konsumen, sales, agen, super_admin, branch provider switches, the KMSIT fallback, "nothing to offer", and payment-method switches |
| Checkout quote (Review preview) | 1 | `compare_order_quote.py` (25) — pickup/address-book/inline destinations, fixed/percentage/targeted vouchers, inapplicable + unknown vouchers, provider-failure 422s, validation shapes, policy vs role 403, guest 401. Provider clients additionally pinned by 4 Go test groups incl. a stub ORS/RajaOngkir/KMSIT and the SSRF guard |
| Agent stock reservations | — (primitive) | 12 two-connection oracles in `internal/inventory/reserve_test.go`: anti-oversell, exact-capacity lost-update, opposite-order deadlock, rollback, legacy boundary, warehouse-authoritative trap, provisioning, release clamp |
| Order creation | 1 | `compare_orders.py` (43) — response body AND database state (order, items, commissions, shipments, stock request, payment transaction, snapshot, reservation), plus idempotent replay after a status change and full rollback on failure |
| Handover read + print | 2 | `compare_handovers.py` (5) — JSON shape (users serialize with NO hidden fields) and the A4 HTML page byte-for-byte after normalising the volatile timestamp/serial numbers |
| Stock-request proposals | 8 | `compare_proposals.py` (20) — propose/receive/approve/reject/write-off across 20 scenarios, response + DB state |
| Warehouse stock requests | 6 | `compare_warehouse_requests.py` (22) — transit receipt, factory_plan change, Sub adjustment both directions, idempotent re-approval, rejection, the negative-Sub guard, route gates, and 7 validation shapes; DB state compared after every scenario |
| Item lifecycle (fulfillment / cancellation / reschedule) | 4 | `compare_fulfillment.py` (14) — reduce → increase → no-op, full reduction then refused increase, the closed transfer window, byte-exact validation sentences, the cancel-eligibility projection, the 409 stale-quantity guard, full cancellation then refusal, the pickup refusal, the Kurir-Online reschedule, the partial split, and CSRF 419. DB state compared after every scenario (items, order totals, snapshots/units/bases, reductions, obligations + ledger, adjustments, additional payments, payment transactions, stock-request demand/status, the Agent reservation, shipments, activity log) |
| Ops (`/up`, `/healthz`, parity report) | 3 | integration tests |

## 3. What remains (266 routes) — ordered, with the real obstacle for each

The order below is dependency-correct. **Start at the top of §3.1.**

### 3.1 Phase A remainder — inventory (41 routes)

| Area | Routes | Obstacle / notes |
|---|---|---|
| Stock opnames | **DONE** (8 routes) — the remaining gap is `pending_catalog_change` publication (import-driven), which needs `CatalogImportService`. Documented as an explicit partial in the matrix. |
| Stock requests / proposals / handovers | ~20 | `StockRequestProposalService` (propose/approve/receive/settle/resolveDiscrepancy) — the §16/§31 two-party gate. Requires the `stock_requests*` chain plus `StockTransfer` handover linkage (already built). |
| Sub stock-request workflow | 6 | `SubStockRequestService` (create/approve/execute/receive/cancel/reject) — needs `stock_transfers` execution (built) + `SubStockService` reservation lifecycle. |
| `POST /warehouse/transfers/plan-to-transit` | **DONE** |
| `GET /warehouse/sub-locations/eligible-owners` | **DONE** |
| `PATCH /warehouse/sub-locations/{id}` + `/assign-owner` + `/deactivate` | 3 | `SubLocationOwnershipService` — locks the owner user row then the location; `previous_owner_user_id` history. |

### 3.2 Phase B — orders & fulfilment (43 routes)

`POST /orders` is the single most consequential endpoint in the system. Port in this order:

1. `OrderTotalCalculator` + `PricingService` + `FeeService` (money: exact decimal, `round(.., 2)` at the same
   points as the reference).
2. `StockService::reserveFor*` + `provisionProductCommitment` + `lockReservationTargets` (canonical order
   **already extracted** in `internal/inventory/transfers.go` — reuse `sortTargets`/`targetKey`, do not write a
   second ordering).
3. `OrderService::create` incl. the `Idempotency-Key` replay (`UNIQUE(konsumen_id, key)`, `UNIQUE(crm_customer_id,key)`).
4. `OrderFulfillmentService` + `FulfillmentShortageService` + `ShipmentCanonicalizationService`
   (note the two DB triggers on `shipments`; they run in MySQL and need no Go code, but the CHECK
   `shipments_delivery_mode_consistent` must be honoured).
5. `CourierService` (+ commission writes), `DispatchController`, `DeliveryVerificationService` (`UNIQUE(verified_by,idempotency_key)`).

### 3.3 Phase C — financial (~40 routes)

**Do not start before the Owner confirms the money type (D-02).** The reference mixes `decimal(12,2)` + float
`round()` for orders/payments with integer cents inside `Refund/Money.php`. Pick one at the boundary and test
rounding edge cases (`.005`, negative adjustments) against the reference.

Highest-risk units: `RefundLedgerService` (14 Order-first lock transitions), `RefundObligation::remaining()`
(unlocked read — must not become an independently callable read model), `InvoiceNumberService` (row-locked
sequence in `settings`, 409 on namespace exhaustion), `CommissionFinalizer` (idempotent on `pending`),
`SalesFeeRefundAdjuster` (`UNIQUE(commission_id, return_item_id)`).

### 3.4 Phase D — remaining application domains (~150 routes)

| Area | Routes | Notes |
|---|---|---|
| admin (agents, SMTP, WhatsApp gateway, shipping config, regions import/export, audit log, media, refunds, additional payments, order adjustments) | 52 | Mostly CRUD; `MediaController` needs the upload validation set (MIME + extension + `getimagesize` + dimension caps) |
| agent (contacts, shipping providers/tariffs, store profile, payment methods, Google auth, WhatsApp) | 33 | `encrypted` casts must become a Go encryption boundary — **not** plaintext columns |
| products management (+ variations, images, fees, bestsellers, import/export) | 17 | `ProductHardDeleteService` uses `information_schema` guards; `HtmlSanitizerService` is used for descriptions |
| reports | 17 | Many use `DATE_FORMAT`/`JSON_EXTRACT`; the Go port must keep the same output strings |
| CRM + consent | 16 | `ConsentService`/`CrmAccess`; consent events are append-only |
| CMS admin (articles, pages, homepage blocks) | 14 | **Blocker: `HtmlSanitizerService`.** The reference uses HTMLPurifier with a specific allowlist. A Go sanitizer (e.g. bluemonday) will not produce byte-identical output; this needs either a documented deviation with fixture tests, or an allowlist port. Block create/update additionally need multipart image handling. |
| profile (referral code, WhatsApp/email verification) | 11 | OTP challenges (`identity_otp_challenges`), rate-limited; `phone` change invalidates prior proofs |
| promo (vouchers, discounts) | 8 | Straightforward CRUD; `resolveAgentId` scoping |
| users | 8 | `UserManagementService` — closure rebuild on parent change, referral-code generation |
| dispatcher/kurir/shipments | 24 | Depends on Phase B |
| install (8) | 8 | Owner decision needed: keep the installer in Go, or drop it (a Go-only deployment can be provisioned by env + migrations). `EnvFileWriter`/`SafeSchema` are pure bootstrap. |
| google-sheets (7) | 7 | Service-account JWT; needs a Go Sheets client |
| addresses, categories admin, regions | 9 | Simple CRUD |

### 3.5 Phase E — non-HTTP (no route count, still mandatory before removal)

| Item | Notes |
|---|---|
| Queue + 2 jobs | `SendWhatsAppMessageJob`, `SendTransactionalEmailJob` — `$tries=1`, claim-then-send, dedicated lane. Requires a Go worker with the same claim semantics. |
| Scheduler | The reference has **no** scheduler (`routes/console.php` = `inspire`), so nothing to port; confirm before removal. |
| Notifications | `WhatsAppLifecycleObserver`, `AdminNotificationObserver`, `OrderNotifier::defer` (after-commit only) |
| Media/protected streaming | `GET /payment-proofs/{id}` — authorization-before-stream + `private, no-store` |
| PDF | `invoice.blade.php` → HTML→PDF engine; 10 PDF fixtures in `docs/unified-rem-evidence/invoice/after/` are the contract |
| Excel/Sheets | `PhpSpreadsheet` exports + Sheets push (10k row fail-closed bound) |
| Google OAuth | state/nonce/PKCE, 10-minute single use, tokens not persisted |

## 4. Blockers that need a decision (not ordinary implementation detail)

| # | Question | Why it cannot be derived |
|---|---|---|
| B-1 | **Money type for Phase C** — `decimal` + exact decimal arithmetic (current), or integer minor units | Changes every financial query and the rounding boundary; the reference is internally inconsistent |
| B-2 | **`HtmlSanitizerService` parity** — Owner resolved: reproduce the HTMLPurifier POLICY in Go with golden fixtures incl. malicious HTML, never weakening it. Work not started (needs bluemonday + fixtures). | Affects 4 CMS/description write paths |
| B-3 | **Installer** — port to Go, or replace with env-driven provisioning | Product decision about first-run UX |
| B-4 | **Session continuity at cutover** — accept one forced re-login (documented as D-4), or implement Laravel cookie decryption | Affects every logged-in user at cutover |

## 4-0. Money boundary and pricing core — DONE (do not re-derive)

`POST /orders` is a 1,181-line chain (`OrderService::createOrder` → pricing → reservation → totals → commission →
invoice → notification). Per D-02 the money boundary was built and verified FIRST, so the order chain can be
written against it instead of inventing a second arithmetic:

| Package | Ported from | Verification |
|---|---|---|
| `internal/money` | PHP `round($v, 2)` | **47 fixtures generated by the running reference** (`testdata/php_round.json`) — includes the cases where a naive float implementation diverges (1.005, 2.675, 33.335, 0.045…), negatives and zero. `TestNaiveFloatRoundingWouldFail` documents why `math.Round(v*100)/100` is not acceptable. |
| `internal/pricing` | `PricingService` + `OrderTotalCalculator` | unit price (incl. 0/100/over-100 percentage clamps and cents rounding), fixed-voucher transactional budget (A1-07: 40000 across two 25000 lines → 25000 then 15000, never ×2), percentage voucher per line, `isUsable`/`isCurrentlyValid` windows and exhaustion, `effectiveDiscountAmount` clamp (A1-08), and the `subtotal − voucher + shipping + admin` total. Expectations come from `php -r 'round(...)'`. |

**Trap recorded:** a product-discount percentage is an INTEGER column (`tinyint unsigned`), so 12.5% is not
expressible there — 12.5 belongs to a VOUCHER `value` (`decimal(12,2)`). Mixing them silently produces a wrong
line price.

## 4-1. Checkout entrance — DONE (do not re-derive)

`GET /api/v1/checkout/steps` is served (`internal/{checkout,shipping,payment}`), verified by
`tools/parity/compare_checkout.py`: **38 checks, 0 failed**, across 11 scenarios.

Ported semantics worth remembering:
- `openroute` ("Kurir Toko") is offered when the ORS row is eligible **OR the agent's KMSIT fallback is**;
  KMSIT itself never appears as a method. Only the second half covers a branch whose openroute row is inactive
  yet whose kmsit config exists — the branch a naive `activeProviders` port gets wrong.
- Pickup needs no provider row: it exists iff the agent has an `agent_profiles` row, and its coordinates are
  that row's (the same source OpenRoute uses as an origin).
- `availableShippingMethods` sorts by CODE then appends pickup last; `payment_methods` orders by id.
- `steps.account` is active only for a guest; `steps.referral` is always false.
- A konsumen sees only `bank_transfer`; `?shipping_method=rajaongkir` narrows staff to `bank_transfer`;
  an unrecognised `shipping_method` means "not chosen yet" (unrestricted), not "nothing allowed".
- A guest still gets globally active delivery providers (no branch, so no pickup and no agent overrides).

Fixtures: `tools/parity/seed_test_fixtures.sql` now seeds rajaongkir+openroute active, kmsit inactive, and
bank_transfer/cod/down_payment plus an ACTIVE gateway row that must never be listed.

**Parity taxonomy fixed.** `gen_matrix.py` used to mark every annotated route PARTIAL, so the matrix published
31 PARTIAL while this file published 6 — two different counts for the same codebase. Status is now driven by an
explicit `GAPS` set (routes whose own behaviour is incomplete); a note alone leaves a route **GO**. The matrix
now reports 75 served / 69 GO / 6 PARTIAL / 300 Laravel-only, which is the same 6 this file always claimed.

Also fixed in this checkpoint: `implementsRoute` did not recognise `POST .../assign-owner` and
`POST .../deactivate`, which skipped CSRF validation on two state-changing endpoints and undercounted the
parity report by 2. `TestEveryRegisteredRouteIsImplemented` now enforces "registered ⇒ implemented".

## 4-2. Checkout quote — DONE (do not re-derive)

`POST /api/v1/checkout/quote` is served. Packages: `internal/checkout/quote.go` (destination + lines + voucher +
admin fee), `internal/shipping/quote.go` (provider engine + RajaOngkir/ORS/KMSIT clients), `internal/shipping/guard.go`
(SSRF policy), `internal/cryptox` (reads Laravel-encrypted credentials).

Traps recorded — each one produced a real mismatch until fixed:
- **Two different 422 shapes.** A validation failure serialises `{"field": ["msg"]}`; an `ApiException` with a
  field map serialises `{"field": "msg"}` (a STRING). `httpx.Error` now carries both (`Fields` and
  `FieldStrings`) — see the unknown-village and region-hierarchy cases.
- **`__()` returns the key when the translation is missing.** `messages.voucher.invalid` is emitted VERBATIM by
  the reference because `lang/id/messages.php` has no `voucher` group. The Go catalogue stores that literal
  (`RAW_KEY_FALLBACKS` in `gen_i18n.py`); "improving" it would break parity.
- **Policy denial is not the role-middleware denial.** `OrderPolicy::create` throws Laravel's
  `AuthorizationException`, whose message is the raw English `"This action is unauthorized."`; the route role gate
  returns the localised `system.unauthorized_action`. Both are 403 and they are different bodies.
- **Existence rules fire before service logic.** An unknown `village_id` is a `validation.exists` error, not the
  service's `order.invalid_village` ApiException.
- **`required_without` / `required_with` sentences** carry the OTHER field's display name
  (`"... apabila address id tidak ada."`), not the plain `required` sentence.
- **Provider failures are never surfaced raw**: every `QuoteError` becomes the generic
  `shipping.method_not_available` 422 with a `shipping_method` field sentence, exactly like the reference after it
  logs `ShippingQuoteException`.
- **RajaOngkir regency matching strips `Kota/Kabupaten` from the INTERNAL name only** — a stub that returns
  "Kota Bandung" would make the reference fail too. Retested with a realistic payload.

Verification split (honest): the reference-observable paths are compared live (25/25). The three outbound clients
are pinned by Go tests against a **local stub server** (tariff arithmetic incl. minimum charge/free-shipping/
below-minimum, KMSIT fallback + SSRF refusal, RajaOngkir courier intersection + cheapest-first + explicit
courier/service, unmapped destination fails closed) because no real provider credentials exist here. RajaOngkir's
base URL is hardcoded in the reference, so its successful round-trip cannot be compared differentially; Go keeps
the same default and allows an override purely as a test seam.

`APP_KEY` must be present in the Go process environment for real agent credentials to decrypt (deviation D-12).

## 4-3. Agent reservations — DONE (do not re-derive)

`internal/inventory/reserve.go` + `subreserve.go` port
`StockService::{reserveForProduct,reserveForVariation,provision*,lockReservationTarget(s)}` and
`SubStockService::{reserve,lockReservationTargets,reserved}`. The canonical ordering is the SAME
`targetKey`/`sortTargets` vocabulary the transfer path already used — there is one ordering, not two.

**Real defect found and fixed here:** `quantity_reserved` is UNSIGNED, and MySQL evaluates
`quantity_reserved - ?` in UNSIGNED arithmetic, so releasing more than is reserved raised error 1690
("BIGINT UNSIGNED value is out of range") BEFORE `GREATEST()` could clamp it. A cancellation of an
already-released order would have been a 500 instead of a harmless no-op. Fixed with `CAST(... AS SIGNED)`.

Design note (stronger than the reference, deliberately): the warehouse availability read is ONE locking read over
every `stock_type` of the target. It must not be filtered by `stock_type` (a target holding only `sub` rows is
warehouse-authoritative with base 0), and one statement takes the locks in a single index-ordered pass. It cannot
cycle against the Sub domain, which takes a sub-location lock that an Agent reservation never takes.

## 4-4. Order creation — DONE (do not re-derive)

`POST /api/v1/orders` is served. Packages: `internal/orders/{create,support,load,render}.go`.

Order of operations (the correctness argument): idempotency pre-check → payment method → StockSourceResolver →
resolve ALL lines to targets → canonical target locks → placeholder order → per-line price+reserve+snapshot+commission
→ shipping quote → payment-method re-check against the RESOLVED provider → voucher consume under row lock →
invoice number under `settings` row lock (after the redemption) → totals → shipment grouping → Stock Request (COD)
→ payment transaction → invoice_issued_at (COD) → initial report snapshot → activity log.

Traps found the hard way, all now pinned by tests/fixtures:
- **`activity_logs` uses `causer_id`, not `user_id`, and has no `updated_at`.** A wrong guess failed the whole
  order with a 500 AFTER every reservation was taken. `properties` also has a `json_valid()` CHECK, so it is
  always real JSON, and an empty set is persisted as NULL.
- **A nil Go map in an `any` is not a nil interface.** `ErrorsPayload`'s `if e.ErrorsAny != nil` therefore
  returned an EMPTY map and swallowed `FieldStrings`, so every ApiException-style 422 answered `"errors": null`
  while its fields were populated. `internal/httpx/errors_payload_test.go` pins all four shapes.
- **`InsufficientStockException` has its own payload**: `{"item": "label", "available": N, "requested": M}` — a
  MIXED-typed map, neither the array nor the string shape. `httpx.Error` now carries `ErrorsAny` for it.
- **DATE is not rendered the same everywhere**: an `order`'s `delivery_date_estimate` / `due_date` /
  item `requested_delivery_date` are Eloquent `date` casts → ISO timestamps at midnight UTC, while
  `delivery_groups[].delivery_date` comes from `?->toDateString()` → a BARE date. Making them identical would be
  wrong in one of the two places.
- **`decimal(10,7)` cast attributes serialise as STRINGS** (`"-6.9000000"`), not numbers — unlike the checkout
  preview payload, which is computed in PHP from a float cast.
- **COD orders create their Stock Request immediately** and their items carry the ORDER's status (`diproses`).
- **Hand-written JSON fixtures with `//` comments break parsing** silently in the middle of a response body.

Fixture/harness note: `seed_test_fixtures.sql` DELETEs `users`, which cascades to sessions — reseeding it between
scenarios invalidated both runtimes' cookies mid-run and produced spurious 419s. `compare_orders.py` therefore
loads the fixtures ONCE and resets only order-scoped tables per scenario.

## 4-5. Warehouse stock requests — DONE (do not re-derive)

6 routes: `internal/inventory/stockrequests.go` + `internal/api/handlers_stockrequests.go`.

**The reference's serialisation asymmetry is the contract, not a bug** — `sub_location` appears or not depending on
which relations THAT action eager-loaded:
`create(addition)` absent · `create(sub_adjustment)` present · `approve` present · `reject` present · `index`
present · `show` absent. A loaded-but-empty relation renders `null`, never a dict of nulls. `current_stock` is the
same: the key is always there but only `index`/`show` fill it.

Other traps confirmed here:
- A missing request id answers **`system.endpoint_not_found`** ("Endpoint tidak ditemukan."), not the model-not-found
  sentence — the frontend branches on that text.
- Approval is idempotent through the `warehouse_stock_request_id` marker on `stock_movements`, not through the
  status column alone, so a retry after a partial failure cannot move stock twice.
- A `sub_adjustment` delta may be negative but is refused when it would drop physical below the ACTIVE Sub
  reservations; a `factory_plan` change is refused when `transit + plan` would fall below reserved.

## 4b. Security invariants — re-verified after every checkpoint

Check these after adding routes; each is cheap and each has caught a real defect:

| Invariant | How to check |
|---|---|
| Registered ⇒ implemented | `go test ./internal/api/ -run TestEveryRegisteredRouteIsImplemented`. A route the mux serves but `implementsRoute` denies skips CSRF and undercounts the matrix. |
| CSRF on every state-changing route | `curl -s -o /dev/null -w "%{http_code}" -X POST <url> -H 'Content-Type: application/json' -d '{}'` must answer **419**. Verified for all `/checkout/*`, `/orders` and the six stock-request routes. |
| Tenant isolation | every query is `agent_id`-scoped from the SESSION, never from a query parameter (except super_admin reads that require an explicit `agent_id`). A foreign id must answer 404, not 403. |
| SSRF | `KMSIT_TRUSTED_HOSTS` is the only allow-list; the vetted IP is pinned through a custom dialer. `internal/shipping/quote_test.go` proves a loopback endpoint is refused. |
| Secrets | provider/bank credentials are READ from Laravel's `encrypted:array` payloads with `APP_KEY`; Go never writes a new encrypted format. `activity_logs.properties` are redacted. |
| No weakening for parity | a divergence is recorded in `docs/21b` as a deviation; the check is never removed to make a comparison pass. |

## 4-6. Stock-request proposals + the media pipeline — DONE (do not re-derive)

8 proposal routes (`internal/inventory/proposals.go`, `internal/api/handlers_proposals.go`) plus
`internal/media` (MediaService + config/media.php) — the shared upload path every image slot uses.

**Settlement moves stock EXACTLY ONCE**: Koordinator receipt (routine) or Admin approval (exception) share one
`settleProposal`; only ACCEPTED units move transit→shipping; short/damaged units stay in Transit with their
reservation intact and remain outstanding demand. Damaged units are HELD on the line (`damaged_hold_qty`) until an
Admin write-off; `restock` is refused outright (the reference refuses it too, pending an Owner inspection rule).
The demand-line sums after settlement use LOCKING reads (`FOR UPDATE`), never `SUM()` — a plain aggregate would
read the pre-lock REPEATABLE READ snapshot.

**Write-off idempotency** goes through a LOCKING activity-log lookup on `properties->idempotency_key`: a retried
resolution answers 409.

**Media pipeline** (needed by proposals AND shipment/COD proofs, avatars, CMS):
- MIME is sniffed from the file CONTENT (never the client header); the stored extension comes from the
  collection's allowlist (never echoed from the client); the file must actually DECODE as an image
  (image.DecodeConfig); raster only — no SVG anywhere, exactly like the reference.
- `proof` is REQUIRED on propose whenever goods are handed over (multipart); a shortage-only report carries none.
  The controller's conditional rule is reproduced including its validation sentence.

Reference asymmetries reproduced (each was a failing comparison until matched):
- `request_item.product.images` is present ONLY on index/show (loaded there); the propose/settle responses omit
  the key entirely.
- `product` and `variation` keys are always PRESENT on a loaded relation (null for the target the line does not
  have) — a null relation still renders.
- An empty `receipt_note` persists as NULL, not ''.
- The propose endpoint is Multipart; the same fields arrive as `items[i][item_id|...]` form names.

REAL DEFECT FIXED in the reservation primitives while porting this: Laravel writes the reference pair
(`reference_type`/`reference_id`) in DEDICATED movement columns; my earlier port put "order#123" in the note and
left the columns NULL, and release quantities were recorded POSITIVE where the reference records them NEGATIVE.
Both fixed; compare_orders.py still 43/43.

## 4-7. Handover read/print — DONE (do not re-render)

2 routes: `internal/inventory/handovers.go` + `internal/api/handlers_handovers.go`. The rows themselves are
written by the transfer approval path (checkpoint H); these are the read surfaces.

Traps here:
- **The User model has NO $hidden in this context**, so the handover payload serialises the actor rows WITH email
  and phone — reproduce it exactly; do not "improve" the payload by hiding fields the reference exposes to this
  authenticated warehouse audience.
- **Blade whitespace is part of the contract**: the print page is compared byte-for-byte after normalising only
  the "Printed:" timestamp and the two serial numbers (HOV-/TRF- date+random). `@foreach` indentation,
  the `?? '-'` dashes for an absent receiver/note, and the absence of a trailing newline after `</html>` all had
  to match. html/template reproduces Blade's `{{ }}` escaping.
- A NULL users.korsal_id cannot be scanned into int64 — the obvious column-list bug; found by the harness.

## 4-8. Order lifecycle — DONE (do not re-derive)

2 routes: `PATCH /orders/{order}/status` + `POST /orders/{order}/cancel` (`internal/orders/lifecycle.go`).

**Cancellation is one transaction under the Order lock** that does everything the reference does in order:
policy (konsumen/branch-agen/super_admin; korsal if the order is theirs; sales if the order is their sale) →
reverse the inventory (idempotent per item via inventory_cancellation_reversals, canonical Agent capacity
prelock, release outstanding reservation, return ACCEPTED Shipping→Transit with cancellation_release movements,
cancel the Stock Request) → mirror item statuses + cancelled_quantity → book the OrderSalesReduction → freeze the
OrderFinancialSnapshot (integer-cent largest-remainder voucher allocation, sha256 checksum) → create the
RefundObligation for what was actually PAID → mark finalized.

**Status transition** (office bulk override) re-reads payment eligibility UNDER the Order lock, refuses an unpaid
non-COD order at diproses, refuses to advance a Sub-sourced item, stamps the first handover (B-01 basis event),
and creates the Stock Request on the manual move to diproses.

Serialisation asymmetries reproduced: the fresh-create payload omits konsumen/sales/korsal and the item's
shipment context (delivery_mode/self_delivered_by_user_id), while show/cancel load and render them. konsumen
carries phone; sales/korsal carry only {id, name}.

REAL DEFECTS FIXED along the way: a DECIMAL SUM scanned into int64 (the reference renders money as text); the
cancel policy initially only allowed agen/konsumen (missing admin/korsal/sales branches); an InvalidStateTransition
message that hand-rolled the sentence instead of rendering the catalogue + the {from,to} errors map.

Known gap (recorded, not silent): the Kurir Toko acceptance rule inside assertExpeditionDispatchReady needs the
courier dispatch service (later checkpoint); the `dikirim` scenarios here use PICKUP orders, so the boundary is
honest.

## 4-9. Order read surface — DONE (do not re-derive)

3 routes: `GET /orders`, `GET /orders/{order}`, `GET /orders/regions` (`internal/orders/read.go`,
`internal/api/handlers_orders_read.go`).

Role scoping is ONE nested group so no filter can widen it (the reference's own comment): konsumen own only,
sales/sales-kurir-sub own sales + self + the IMP-004 reassigned-owner leg, korsal own team + symmetric leg, gudang
warehouse-queue only (diproses, no courier/self-delivery), agen/admin/keuangan/koordinator their branch,
super_admin all + optional agent_id. A kurir is refused the generic surface outright (their minimized courier
surface is a separate route).

R-04 / §C projection split: the RESOURCE gates the financial fields per viewer; the list/detail re-derive it from
the session. `viewer_can_download_invoice` renders for every authenticated viewer (false for gudang, which has
view() but not viewInvoice). The SHOW controller loads payment_transaction (latest by id) and returns; the INDEX
omits both keys.

Filters reproduced: status (canonical), payment_status (financial audience only — the reference REMOVES the
param for operational roles), search (order_no/recipient with LIKE escaping), delivery_date, region ids, paid.

## 4-10. Item lifecycle — DONE (do not re-derive)

4 routes: `PATCH .../items/{item}/fulfillment`, `GET .../cancel-eligibility`,
`POST .../cancel-undelivered`, `PATCH .../items/{item}/reschedule` (`internal/orders/fulfillment.go` +
`financial.go`, `internal/api/handlers_orders_fulfillment.go`).

**Reduce** consumes FROZEN snapshot units in consumption order (never re-derives from the current price), books the
`order_sales_reduction` row, then the refund obligation: a `fulfillment_shortfall` basis books
`shortfall:<item>:<from>-<to>` auto-approved; a `provisional_legacy` basis books the needs-verification variant.
Inventory then unwinds Order → Stock Request → Sub reservation/stock, and the total is re-derived. **Increase**
restores cancelled units first (`ORDER BY id`), reserves fresh stock only for the shortfall, and creates ONE
`adjustment:<adjustment_id>` additional-payment obligation only when the order was fully settled — a pending
additional payment or pending refund blocks the further adjustment (code `pending_additional_payment_...`).
`assertQuantityAdjustable` allows only a `fulfillment_shortfall` snapshot while the order is still `diproses`.

**cancel-undelivered** is REM-003 Owner Option A: it cancels part of the undelivered remainder (allowed after
first dispatch), takes the frozen-order lock FIRST, then the Stock Request/demand rows, and treats
`expected_quantity != fulfilled_quantity` as a **409 stale-quantity** guard. `cancel-eligibility` is the
read-only projector: order window + stock source + item status + open delivery refusal + remaining quantity +
pending additional payment decide `eligible`/`reason_code`; `max_quantity` is `fulfilled_quantity` only when
eligible.

**reschedule** is LOCKED to Kurir Online (`shipping_provider_code = 'openroute'`) and Self Delivery/Sub; expedition
and pickup are refused. A partial `quantity` splits the line onto a new `OrderItem` (+ its own `Shipment`) with
`split_from_order_item_id` lineage and a `order_item.split_for_reschedule` activity; the shipment is then
re-homed onto the canonical shipment for that date (consolidate-into-one), and an open shortage line may stay
`dikirim`. Splits lock the Stock Request item and reject a move larger than the unfulfilled remainder
(`split_stock_request_locked`).

Reproduced details worth keeping: the audit `snapshot.created` event (event_key `snapshot:<order>`, one per order)
is written when the snapshot freezes — its payload `checksum` embeds the order id and therefore differs per
runtime by construction (compare its shape, not its bytes). `remaining_active` on
`order_item.undelivered_cancelled` is the POST-reduction fulfilled quantity. `order_item.split_for_reschedule`
carries `actor_role`.

## 4a. Next action (resume here)

**DONE — Payments surface (9 routes, parity 16/16 via tools/parity/compare_payments.py):** proof, cod-proof,
installment, verify, settle, markCod, admin cod-proof confirm, payment-proofs/{id} download, due-date.
Ported from PaymentService + PaymentController into `internal/orders/payment.go` (+ render_payment.go, shortage.go
for the FulfillmentShortageService read model). The renderer now gates every key on the controller's ACTUAL
relation-load set (create/index/show/status/cancel/markCod/settle/confirm differ — whenLoaded semantics, keys
ABSENT not null). CSRF is now scoped to stateful requests (Sanctum fromFrontend: Origin/Referer on
SANCTUM_STATEFUL_DOMAINS) — bare requests answer 401 like Laravel, stateful ones 419.

**DONE — Category admin mutations (3 routes, parity 3/3 via tools/parity/compare_category_mutations.py):**
integrated from the Copilot branch (commit 348dfbc, cherry-picked cleanly) and then corrected to the Laravel
contract: role-gated presentation attributes (slug/is_active/sort_order are super_admin-only on store),
uniqid()-shaped slug suffix, validator sentences (self-parent message, unique slug, empty-name), integer/boolean
type coercions (Laravel accepts "24"/"1"), delete error 1761 → 409, i18n key product.category_deleted, and the
audit row carries actor_role + agent_id.

**DONE — Copilot Domain E/F integration (Owner directive 19 Nov):** commits bf30e4a (jobs worker+scheduler,
Google OAuth), 9432dab (invoice PDF via gofpdf, XLSX via excelize), d870e7f (users, agent-admin, product admin,
CMS admin, profile packages + handlers) and the secured WIP 93824f0 (crm, reporting, admininfra, install,
notifications, cryptox EncryptArray) were cherry-picked onto imp010/go-backend and then repaired:
reporting.go type error and the dead `crmActor`/`UserFromIDPlaceholder` scaffolding (WIP never compiled),
CRM index re-shaped to Laravel's LengthAwarePaginator toArray (links array included, page URLs from the request),
and the CRM/commission/report read surfaces wired with the exact Laravel route roles. Differential probes
(5 actors x 9 paths) match on status + shape incl. per-role commission breakdown; `EncryptArray` verified
CROSS-RUNTIME (Laravel decrypts Go output, Go decrypts Laravel encryptString). HONEST STATUS: the write paths
(users store/update, product/CMS admin writes, agent config writes, profile writes, whatsapp) are SERVED but
their contract comparisons are pending — every one is recorded PARTIAL in docs/21a, and CMS writes additionally
carry the not-ported HTMLPurifier policy. Matrix: 192/375 served (51.2%), 123 GO, 69 PARTIAL, 183 Laravel-only.
The Copilot worktree is preserved at /Users/kmsitcomputer/KMSIT-Server/apps/primeclassy-imp010-copilot-ef
(HEAD 93824f0 + d870e7f..bf30e4a) until every PARTIAL above is verified GO.

**A. The remaining order routes**: consent-context/events, refund-recipient, returns, assisted-returns, invoice
PDF (due-date is DONE with payments).

**B. Then Phase A remainder (inventory), Phase C (financial: refunds, commissions) and Phase D (remaining
domains), exactly as ordered in §3.**

**C. Finally non-HTTP**: jobs/queue, scheduler, installer, PDF/Excel, Google OAuth, protected media streaming,
then the Laravel archive/removal gate (§6).

**C. Then Phase A remainder (inventory), Phase C (financial: refunds, commissions) and Phase D (remaining
domains), exactly as ordered in §3.**

**D. Finally non-HTTP**: jobs/queue, scheduler, installer, PDF/Excel, Google OAuth, protected media streaming,
then the Laravel archive/removal gate (§6).

**Known gaps recorded, not silently skipped:** the order-creation notification/queue side effect
(`queue bridge outstanding`, same as registration); `OrderInitialSnapshotService` is ported but
`FulfillmentShortageService` is not; CMS write paths wait on the HTMLPurifier policy.

**DONE, do not redo:** `PATCH /warehouse/sub-locations/{id}`, `/assign-owner`, `/deactivate`; `/addresses` (4);
`/promo/vouchers` + `/promo/discounts` (8); `GET/POST /checkout/*`; Agent reservations; `POST /orders`;
warehouse stock-addition-requests + sub-adjustment-requests (6); stock-request proposals (8) + the media
pipeline; `/warehouse/stock-requests/{id}/fulfill` (fixed refusal); handover show + print (2); the Sub stock-request
workflow (7); order lifecycle status + cancel (2); order read surface (3); item lifecycle (4).

**Trap recorded for the next run:** `DATE` columns on these models carry a `date` cast, so Eloquent serialises
them as ISO timestamps at midnight UTC (`2026-11-01T00:00:00.000000Z`), **not** as `YYYY-MM-DD`. Rendering the
bare date silently changes the payload for every date-bounded row. Also: `min:1,max:100` on an integer produces
`min.numeric`/`max.numeric` sentences (not `between`). Both were caught by `compare_promo.py`.

**Note for the next run:** the i18n catalogue now also carries `attributes.*` (Laravel's field display names),
so any new validation message renders "nama penerima" instead of "recipient name". Always render field
sentences through `requiredField`/`maxString`/`invalidChoice`/`unknownChoice`/`betweenMessage` — never inline
literal text — or the two runtimes will diverge on the message. `address` shows the pattern to copy.
4. Then Phase B (orders) exactly as ordered in §3.2.

## 4b. Harness run order (important)

`compare_inventory.py` reads the stock fixtures as-is, while `compare_inventory_mutations.py`,
`compare_promo.py` and `compare_address.py` MUTATE their domain's rows. Run them in this order, or re-seed
between them, otherwise the read-only comparison fails on drift that has nothing to do with either
implementation:

```bash
# re-seed first
for f in tools/parity/seed_test_fixtures.sql tools/parity/seed_inventory_fixtures.sql; do
  docker exec -i primeclassy-mariadb sh -lc \
    'mariadb -uprimeclassy_app -p"$(cat $MARIADB_PASSWORD_FILE)" primeclassy_testing' < $f
done

python3 tools/parity/compare_public_api.py          # 26
python3 tools/parity/compare_auth.py                # 24
python3 tools/parity/compare_inventory.py           # 29   (read-only: needs fresh fixtures)
python3 tools/parity/compare_admin_config.py        # 17   (self-resets its own tables)
python3 tools/parity/compare_address.py             # 17   (self-resets its own table)
python3 tools/parity/compare_promo.py               # 21   (self-resets its own tables)
python3 tools/parity/compare_inventory_mutations.py # 78   (self-reseeds before every scenario)
```

## 5. How to resume (exact steps)

```bash
cd /Users/kmsitcomputer/KMSIT-Server/apps/primeclassy-imp010-go
git log --oneline -3                     # expect 0469508+ and the checkpoint-I commit
docker start primeclassy-mariadb primeclassy-fpm 2>/dev/null

# 1. Rebuild the isolated test DB from THIS worktree's migrations (never DEV):
docker run --rm --network primeclassy-net -v $PWD/backend:/app \
  -v /Users/kmsitcomputer/KMSIT-Server/apps/primeclassy/backend/vendor:/app/vendor \
  -w /app --entrypoint php kmsit-php:8.3-fpm artisan migrate --env=testing --force

# 2. Seed fixtures
for f in tools/parity/seed_test_fixtures.sql tools/parity/seed_inventory_fixtures.sql; do
  docker exec -i primeclassy-mariadb sh -lc \
    'mariadb -uprimeclassy_app -p"$(cat $MARIADB_PASSWORD_FILE)" primeclassy_testing' < $f
done

# 3. Run both runtimes
docker run -d --name pc_laravel_imp010 --network primeclassy-net -p 8899:8899 \
  -v $PWD/backend:/app -v /Users/kmsitcomputer/KMSIT-Server/apps/primeclassy/backend/vendor:/app/vendor \
  -w /app --entrypoint php kmsit-php:8.3-fpm artisan serve --env=testing --host 0.0.0.0 --port 8899
cd go-backend && docker run -d --name pc_go_imp010 --network primeclassy-net -p 8082:8082 \
  -v $PWD:/src -w /src -e APP_URL=http://127.0.0.1:8899 golang:1.27.4-alpine \
  sh -c 'export PATH=$PATH:/usr/local/go/bin; go run ./cmd/api'

# 4. Baseline before any new domain
cd .. && for s in compare_public_api compare_auth compare_inventory compare_inventory_mutations compare_admin_config; do
  python3 tools/parity/$s.py | tail -1; done
```

Then implement the next unit from §3.1 (`POST /warehouse/transfers/plan-to-transit`), following the established
pattern: **capture from the running Laravel → implement → compare responses AND database state → add invariant/
concurrency tests → register any deviation → bump `gen_matrix.py` → commit**.

## 6. Laravel removal gate — status

| Gate | Status |
|---|---|
| 375/375 implemented | ✗ 48/375 |
| Zero PARTIAL | ✗ 6 |
| Non-API functionality in Go (queues, PDF, Sheets, OAuth, protected streaming) | ✗ not started |
| Vue SPA + Nuxt verified against Go | ✗ not attempted |
| Recoverable Laravel archive created | ✗ not created (must be done **before** any deletion) |
| Laravel removed from the active repo | ✗ **deliberately not done** — removing it now would delete 87% of the working backend |

The archive step, whenever it is authorised, should be: `git branch archive/laravel-imp010-final <last-commit-with-backend>`
**plus** an external `tar` of `backend/` with a recorded digest, because Git history alone does not protect
untracked runtime files (`storage/app/installed.lock`, uploads).
