# IMP-011 — Go Functional & Business Workflow Audit

**Audit state: PARTIAL — NOT ACCEPTED.** The route matrix is migration evidence, not proof of business workflow health. The requested end-to-end audit is not complete; this report records reproduced evidence, bounded fixes, and remaining blockers without claiming acceptance.

## Executive summary

- Baseline: `main` at `ed8c2959a8d49607642125e06aa9b7d78064ffb2`, matching the supplied IMP-010 finalization baseline. The worktree was clean before this audit.
- IMP-010 inventory remains 375 in-scope Go routes across 45 areas; REM-11’s 19 Historical POS/report-registry routes were not included.
- Local Go API, SPA, Nuxt, Caddy, and MariaDB containers were reported running. The host could not connect to the local domain or Caddy’s loopback port during this session, so browser reproduction was unavailable.
- Redacted 24-hour Go API log metadata confirms repeated 422s for login, provider config/couriers/test, store-profile and checkout quote. For example: couriers `9d65db9c7b6dc7ce7279c672f3cca1d7`; config `1f7e317b4214c3dcd0ae996cbc94e41f`; provider test `20b3c6f5a01f0bf7d2bed9102f6b2bc8`; store-profile `73f8a5f5f8e3b2f92177fabd36dad935`; quote `40dc5765f332d7614918d0f798bb0835`. Log output was filtered to path/status/request ID only; user IDs and bodies were omitted.
- Source maps the couriers GET’s 422 to failure to decrypt the saved provider config. The exact config bytes and key were not read or printed. The local `backend/.env` has `APP_KEY` and no `APP_PREVIOUS_KEYS` entry.
- Source contained unconditional 422 responses for RajaOngkir destination search and shipping-provider connection checks. These were replaced with read-only provider requests using stored credentials, config and existing guarded routing clients.
- Agent store-profile GET returns decimal coordinates as JSON strings per the existing response contract, while the Vue form expects numbers. The frontend API adapter now converts finite decimal strings to numbers before form use, preserving the backend response format.
- GPS reverse-geocode mapping used the same Nominatim `suburb` label for both district and village, preventing exact master-region matches. The mapping now separates those levels and recognizes Nominatim key variants.
- Local logs show checkout order requests returned HTTP 500 with MySQL error 1449. Read-only metadata queries identify `shipments_self_sub_no_courier_insert` and `shipments_self_sub_no_courier_update` as the affected triggers; both are defined as `prime_app@%`, but that account is absent. They enforce the locked invariant that `self_sub` shipments cannot carry a courier. This database-schema/runtime issue remains open; no local database schema was changed.
- Go `vet` and the full serialized Go test runner passed. An earlier concurrent attempt reported `primeclassy_testing` was already owned by another runner and was stopped; the serialized rerun completed successfully. Frontend unit/build/SSR checks passed. Browser E2E was not run because Playwright/Chromium is unavailable.

## Baseline and runtime inventory

| Component | Evidence | Result |
|---|---|---|
| Branch / HEAD | `git branch --show-current`; `git rev-parse HEAD` | `main`, `ed8c2959a8d49607642125e06aa9b7d78064ffb2` |
| Worktree | `git status --short --branch` before edits | Clean; `main...origin/main` |
| Go API / SPA / Nuxt | Docker container listing and API logs | Running at inspection time |
| Caddy / MariaDB | Docker container listing; Caddy and MariaDB health inspection | Running / healthy at inspection time |
| Local domain / host proxy | `curl` to configured domain and `127.0.0.1:80` | Could not connect from this host session |
| Database | Docs/environment indicate imported `primeclassy_go_local`; test runner targets `primeclassy_testing` | Imported DB was not used as a test target |
| Storage / migrations / worker | Repository docs and runner inventory | No storage or worker mutation performed; Go additive session migration already applied to test DB |
| Service worker | `frontend/src-pwa/sw.js` source inspection | API/session paths bypass cache; failed fetch not reproduced in a browser |
| API registry | `backend/internal/api/parity_routes.json`; IMP-010 matrix | 375 active routes; REM-11 excluded |

## 45-area route and workflow matrix

The route counts below are the checked-in IMP-010 parity snapshot. “Functional audit” is deliberately separate: areas not exercised in this session remain **NOT RUN**, even though their routes are marked GO in the migration matrix.

| API area | IMP-010 routes (GO) | IMP-011 functional audit |
|---|---:|---|
| addresses | 4 | NOT RUN |
| Admin operations | 59 | NOT RUN |
| agent | 33 | PARTIAL — courier config decrypt failure + provider/profile defects |
| Agent directory | 1 | NOT RUN |
| CMS — articles | 2 | NOT RUN |
| Identity & session | 8 | PARTIAL — login request/response unavailable |
| Catalog — bestsellers | 1 | NOT RUN |
| catalog | 3 | NOT RUN |
| Catalog — categories | 4 | NOT RUN |
| Checkout | 4 | PARTIAL — quote 422s; end-to-end blocked |
| CMS — admin | 14 | NOT RUN |
| commissions | 2 | NOT RUN |
| consent-events | 4 | NOT RUN |
| Consent | 2 | NOT RUN |
| CRM | 16 | NOT RUN |
| dashboard | 2 | NOT RUN |
| delivery-refusals | 3 | NOT RUN |
| Dispatch | 9 | NOT RUN |
| Integrations — Google Sheets | 7 | NOT RUN |
| CMS — homepage | 1 | NOT RUN |
| Installer | 8 | NOT RUN |
| Invoices | 2 | NOT RUN |
| Courier | 6 | NOT RUN |
| Site configuration — languages | 1 | NOT RUN |
| Catalog — management | 2 | NOT RUN |
| Media | 3 | NOT RUN |
| Notifications | 4 | NOT RUN |
| Orders — item receipts | 2 | NOT RUN |
| Orders | 25 | PARTIAL — local DB definer HTTP 500 |
| CMS — pages | 1 | NOT RUN |
| Payments — proofs | 1 | NOT RUN |
| Catalog — products | 17 | NOT RUN |
| Profile | 11 | NOT RUN |
| Promotions | 8 | NOT RUN |
| Referral | 1 | NOT RUN |
| Regions | 4 | PARTIAL — reverse geocode mapping fixed; workflow unverified |
| Reporting | 17 | NOT RUN |
| Returns — items | 2 | NOT RUN |
| Site configuration — website | 1 | NOT RUN |
| Shipping & delivery | 9 | PARTIAL — source handlers fixed; provider workflow unverified |
| Inventory — agent | 3 | NOT RUN |
| Inventory — sub stock | 10 | NOT RUN |
| User management | 8 | NOT RUN |
| Inventory — warehouse stock | 1 | NOT RUN |
| Inventory — warehouse | 49 | NOT RUN |

## HTTP 422 and integration findings

| Endpoint / symptom | Evidence and root cause | Remediation / state |
|---|---|---|
| `POST /api/v1/auth/login` | Redacted logs show 422 request IDs `1a115d9dd91c767990369618abe52839` and `cc9eec104f04a4d0c49972905e25f39a`; frontend sends `{identifier,password}` as expected by Go. A 422 is also the defined response for invalid credentials. The bodies were not captured, so a credential or validation defect is not proven. | **Unresolved reproduction**; no credentials were guessed or exposed. |
| `GET /api/v1/agent/shipping-providers/1/couriers` | API logs confirm repeated 422s. This service returns 422 specifically when the Laravel-encrypted config cannot be decrypted with current `APP_KEY`; a key/config incompatibility is the source-level cause path. No previous key is configured or available in this worktree. | **Open** pending recovery of the historical encryption key or source config; the existing secret was not changed. |
| `PUT /api/v1/agent/shipping-providers/{id}/config` | Logs confirm repeated 422s, e.g. `1f7e317b4214c3dcd0ae996cbc94e41f`. Go validation is provider-specific and persists encrypted config; the actual request payload and response errors are not logged. | No validation relaxation. Exact UI payload is needed to identify which provider rule rejected it. |
| `POST /api/v1/agent/shipping-providers/{id}/test` | Logs confirm 422s; source always returned 422, regardless of saved credentials or connectivity. | Replaced unconditional failure with read-only RajaOngkir origin verification and ORS/KMSIT routing probes. Added a database-backed stub regression; it passed in the full Go suite. |
| `POST /api/v1/agent/shipping-providers/{id}/destinations` | Source always returned 422 after validating the request; it never called Komerce. | Replaced with read-only Komerce search; request-supplied unsaved key is used only in-memory. Numeric destination IDs are normalized to strings. The database-backed stub regression passed in the full Go suite. |
| `PUT /api/v1/agent/store-profile` | Logs confirm 422s, e.g. `73f8a5f5f8e3b2f92177fabd36dad935`. GET response coordinates are decimal strings, but SPA typed and treated them as numbers. A returned string could be submitted unchanged. Request bodies were not logged, so this is a proven contract mismatch but not proven as the cause of every logged 422. | Frontend adapter normalizes valid numeric strings and preserves null for invalid/missing coordinates; its regression passed. Backend response contract remains unchanged. |
| `POST /api/v1/checkout/geocode` | Reverse lookup assigned `suburb` to district and village, then required exact hierarchical master matches. Nominatim field names vary by region. | Split administrative levels and added alternate Nominatim keys with two focused regressions (passed). No unsupported fuzzy matching was introduced. |
| `POST /api/v1/checkout/quote` | Recent local log shows both 422 and 200 for the same authenticated user; without request payloads, the 422 cause is not attributable. The Go quote service has RajaOngkir, ORS, and guarded KMSIT routing implementations. | Provider test/search stubs fixed. Quote acceptance and provider live connectivity remain unverified. |
| `POST /api/v1/orders` | Local logs show HTTP 500, MySQL 1449. Read-only metadata confirms the two `shipments_self_sub_no_courier_*` triggers reference the missing `prime_app@%` definer. This is not a validation 422. | **Critical open blocker.** No data/schema mutation attempted. Repair those local trigger definers through a controlled DB schema change before checkout can pass. |
| `sw.js` “Failed to fetch” | Host could not reach configured local origin; no browser console/network trace was available. The service worker intentionally lets API and dynamic requests use network directly. | Cause not proven. Browser E2E not run. |

## Shipping, GPS, map and checkout status

- **RajaOngkir/Komerce V2:** existing quote client uses encrypted per-agent credentials, destination hierarchy matching, configured courier intersection, weight, timeouts and a short rate cache. The test/search handlers now invoke the existing API client. No real provider request was made because external connectivity and a live saved key could not be safely verified from this session.
- **ORS:** existing quote path uses the stored or deployment key, profile and `[longitude, latitude]` coordinates; ORS errors proceed through the existing KMSIT fallback path. A live ORS response was not obtained.
- **KMSIT routing:** existing route client enforces trusted-host/SSRF policy, HTTPS or explicit trusted-host HTTP, DNS pinning, profile and response validation. The new provider test delegates to that guarded client. Reachability was not tested.
- **Pricing/fallback:** no rate, fallback order, minimum distance, minimum charge, free-shipping rule, or activation rule was changed.
- **GPS:** exact region matching remains fail-closed; unmatched results retain manual-address fallback.
- **Map:** checkout uses Google Maps when configured. Configuration and tile/API-key behavior were not observable from this host session.
- **Checkout/order:** quote endpoint has a recorded successful response and 422 responses in logs; request details were unavailable. Order creation is blocked by the missing DB definer noted above, so checkout cannot meet acceptance.

## Business workflows and 45-area audit coverage

This session did not exercise all 45 areas’ happy paths, invalid input, permission denial, missing configuration, edge cases, database effects, and UI/backend compatibility. The matrix records that status honestly. In particular, authentication/RBAC, inventory reservation/fulfillment, payments/refunds, returns, commissions, notifications, CMS, reports, Google Sheets, Nuxt SSR, PWA lifecycle and audit-log consistency remain unverified by this IMP-011 run. No production payments, external messages, production data, or production services were touched. REM-11 was excluded.

## Defects and changes

| ID | Defect | Change |
|---|---|---|
| IMP011-F01 | Provider test/search endpoints were guaranteed 422 without calling integrations. | Added read-only provider calls; no credential persistence or activation changes. |
| IMP011-F02 | Komerce destination IDs encoded as JSON numbers could fail Go string decoding. | Accept raw provider ID representation and emit the API’s string ID contract. |
| IMP011-F03 | Nominatim suburb conflated district and village; city/county prefix differences could also prevent matches. | Separate region labels, support key variants, and normalize `Kota`/`Kabupaten` on both names. |
| IMP011-F04 | Store-profile decimal strings could flow back to Go as string coordinates despite frontend number contract. | Normalize coordinates at frontend API boundary and preserve null safely. |
| IMP011-B01 | Order creation fails on imported local schema due absent `prime_app` definer. | Not fixed; requires DB metadata inventory and repair outside source-only changes. |
| IMP011-B02 | Existing provider ciphertext cannot be decrypted on couriers read path. | Not fixed; prior key/config source is unknown and must not be guessed or overwritten. |

## Verification evidence

- `GOCACHE=/private/tmp/primeclassy-imp011-gocache go test ./internal/agentadmin -count=1` — PASS.
- `GOCACHE=/private/tmp/primeclassy-imp011-gocache go test ./internal/api -run 'TestNominatimAddressLabels' -count=1` — PASS, 2 tests.
- `GOCACHE=/private/tmp/primeclassy-imp011-gocache go test ./internal/shipping -run 'TestSameRegencyNormalizes' -count=1` — PASS.
- `GOCACHE=/private/tmp/primeclassy-imp011-gocache go build ./...` — PASS.
- `bash tools/parity/run_go_tests.sh` — PASS on the serialized rerun: additive Go migrator had nothing new to apply, synthetic fixtures loaded, `go vet ./...` passed, and `go test -p 1 ./... -count=1` passed all packages, including the new provider stub regression, against `primeclassy_testing`.
- `npm run test -- --maxWorkers=2` — PASS, 57 files / 437 tests.
- `npm run type-check`, `npm run build-only`, `npm run nuxt:typecheck`, `npm run nuxt:build` — PASS. Vite reports an existing large-chunk advisory; Nuxt reports an annotation removal advisory.
- `npx oxlint .` — PASS, 0 warnings/errors. `npx eslint . --no-cache` — PASS, 0 errors and 1 unused `eslint-disable` warning in `src-pwa/sw.js:19`.
- Built Nuxt smoke — PASS, 56/56; i18n SSR integration — PASS, 37/37; production-shaped proxy/rollback — PASS, 42/42. All ran against the checked-in mock API.
- Browser E2E (login/dashboard/shipping/profile/catalog/cart/checkout/order) — **NOT RUN**, no Playwright/Chromium installation available.
- `git diff --check` — PASS.
- Imported local DB / production DB / production services — no writes from this audit.

## Remaining limitations and final verdict

**Verdict: NOT PASS.** Checkout/order is a primary workflow and local order creation has a confirmed HTTP 500 from the two missing-definer triggers. The saved provider ciphertext 422, exact login/config payloads, live external integration responses, full business workflow coverage, and browser E2E remain open. The automated Go/frontend/SSR checks pass, but they do not clear the live local DB blocker or prove provider credentials.
