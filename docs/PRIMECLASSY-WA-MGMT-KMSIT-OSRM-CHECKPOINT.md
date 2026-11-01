# PRIMECLASSY-WA-MGMT-KMSIT-OSRM-CHECKPOINT

Branch `main`, HEAD `1f510e6` (unchanged; no commit/push/deploy). DEV only. Production not touched. Full Test not executed (Owner-reserved). **CURRENT STATUS: `DEV AUTOMATED QUALITY GATES PASSED — OWNER VERIFIED` (Full Test backend 1.721/1.721 + frontend 266/266, type-check, build — see "Owner Full Test executed") → `RELEASE AUTHORIZED (Owner, 2026-11-01)` (see "Release authorization" at the end; sections below are the chronological record and some early statements are superseded — e.g. the two migrations were later applied to `primeclassy_dev`, and B-01 is closed).**

## Phase
Phases 0-5 done. Phase 6 = this checkpoint + report.

## Locked decisions applied
WA-DEC-01..05, RT-DEC-01..05 (see the Owner prompt). Admin Agen = role `admin` (branch staff, `users.agent_id` = its Agen): scope = that one Agen.

## Workstream B — KMSIT / OSRM (backend + frontend)
- New: `Support/EndpointGuard` (SSRF), `Services/Shipping/KmsitDistanceCalculator`, `Services/Shipping/Providers/KmsitProvider`, migration `2026_11_04_100000_register_kmsit_shipping_provider` (inactive row), `tests/Feature/KmsitRoutingTest` (40).
- Changed: `OpenRouteProvider` (ORS → KMSIT → fail closed, shared tariff, `meta.routing_source`), `ShippingQuoteService` (Kurir Toko availability = ORS OR KMSIT; KMSIT never listed), `ShippingProviderFields`, `UpdateAgentShippingProviderConfigRequest`, `AgentShippingProviderController`, `Admin/ShippingProviderController`, `ShippingProviderSeeder` (no longer overwrites activation), `config/services.php` (`kmsit.trusted_hosts`), `.env.example`.
- Frontend: `AgentShippingProvidersView` KMSIT card, `api/agentSettings.ts`, admin shipping text, spec `AgentShippingProvidersKmsit.spec.ts` (3).

## Workstream A — WhatsApp management
- New: `WhatsAppScope`, `AgentWhatsAppSettingsService`, models `AgentWhatsAppEventSetting` / `AgentWhatsAppTestNumber`, migration `2026_11_04_100100_create_agent_whatsapp_scoped_settings` (2 tables), `tests/Feature/WhatsAppAgentScopeTest` (15), lang keys x4.
- Changed: `AgentWhatsAppController` (target resolved server-side; events, test numbers, test-send), `AgentDeviceService` (`actingAs`, audit actor ≠ target), `WhatsAppSettings` (`eventEnabledFor`/`templateFor`: scoped row → legacy global), `OrderNotifier` (per ordering-Agen switch + template), `WhatsAppDelivery` (`sendAgentTest`; Agen-scoped test uses that Agen's slot only), `routes/api_v1.php` (`role:agen,admin`), `AgentWhatsAppDeviceTest` (admin removed from the forbidden list; covered by scope tests).
- Frontend: `AgentWhatsAppView` (scope, "WhatsApp Cabang"), `components/whatsapp/AgentWaEvents|AgentWaTesting` + spec (6), `api/agentWhatsapp.ts`, router/nav allow `admin`, Super Admin `WhatsAppGatewayView` loses the Notifikasi tab (spec updated).

## Scoped test evidence (DB `primeclassy_testing`, faked HTTP; no real sends)
Backend: KmsitRoutingTest 40/94; WhatsAppAgentScopeTest 15/144; ShippingProviderTest 30/114; CheckoutCoordinateTest 11/29; AgentWhatsAppDeviceTest 17; WhatsAppGatewayTest 19; WhatsAppNotificationTest 17; WhatsAppThreeRecipientTest 5; WhatsAppNotifyInfraTest 3; WhatsAppRestrictedDeliveryTest 9; WhatsAppVolumeLimitTest 9; WhatsAppOtpTest 11; WhatsAppNumberVerificationTest 17; RegistrationNotificationTest 11 — all passed.
Frontend vitest (scoped): AgentShippingProvidersKmsit 3, AgentWhatsAppView + AgentWaPanels 17, WhatsAppGatewayView 13 — passed. `vue-tsc`: only pre-existing `ImageUploader.vue(181,21)`.
NOT RUN: Full Test, full suites, `npm run build-only`, any migration on `primeclassy_dev`, any live GOWA/KMSIT call.

## Open items / blockers
- B-01 ~~No live KMSIT server contract~~ **CLOSED** — contract read from the official OpenAPI, adapter rewritten, live HTTP 200 verified (see below).
- B-02 Super Admin console still has the test-message to Owner numbers on the console default device (central infra check) and the legacy `/admin/integrations/whatsapp/templates/*` + `events` API as platform defaults. Owner may want these removed from the API too.
- B-03 Admin Agen has exactly one branch Agen; multi-Agen selection UI exists but only activates if the org model ever grants more than one.

## EXACT NEXT ACTION
Owner: review diff; authorise DEV migrations `2026_11_04_100000` and `2026_11_04_100100` (backup first) → DEV UAT (Admin Agen pairing, scoped templates, registered-number test-send, KMSIT endpoint + Test Connection against a real server, ORS-off fallback) → Full Test (Owner-only, Windows PowerShell).

## GLM remediation + DEV migration pass (2026-11-04)
- Baseline: `main` @ `1f510e6`, 124 working-tree entries at start. DB verified: `primeclassy_dev`, 127.0.0.1:3306, MySQL 8.4.11, datadir `C:\ServBay\db\mysql\8.4\`.
- Backup (before migrating): `C:\Users\KMSIT Komputer\primeclassy-backups\primeclassy_dev-pre-wa-scope-kmsit-14480521-192054.sql` — 4,488,360 bytes, 118 CREATE TABLE, ends with "Dump completed".
- Migrations applied by explicit `--path` (only these two; nothing else was pending): `2026_11_04_100000_register_kmsit_shipping_provider`, `2026_11_04_100100_create_agent_whatsapp_scoped_settings` → batch 42, status Ran. migrations table 158 → 160.
- Verified after: providers rajaongkir=1, openroute=1 (unchanged), kmsit=0; users 44, orders 10, devices 1, whatsapp_messages 39, settings 43 (all unchanged); new tables empty; FK `agent_id → users` on both; unique `(agent_id,event)` and `(agent_id,msisdn)`.
- BL-5 fixed: `EndpointGuard::pin()` now sets `proxy => ''` and `CURLOPT_NOPROXY='*'` (Guzzle otherwise honours ambient HTTP(S)_PROXY, letting a proxy resolve the host and skip the IP pin). Redirects off, TLS verification untouched, pin kept. Test added.
- BL-4: limits unchanged (5 numbers, 3/min, 20/day). Hardened: atomic `RateLimiter::hit` then compare, keyed on the server-resolved target Agen (shared by Agen+Admin, not resettable by session or forged `agent_id`). Test added.
- BL-2 (B-02) kept as legacy fallback, unchanged. BL-3: no live KMSIT call; static review only.
- BL-6 inventory (nothing deleted/moved): `backend/2048])!` (95 B, PHP parse-error text from a mis-quoted command, 2026-10-30) and `backend/holder)` (699 B, PsySH history-permission error output, 2026-11-01) = generated temporary, cleanup candidates; `backend-fulltest-remediation-001.log` (265 KB) = generated log, candidate; `frontend/pnpm-lock.yaml`, `artifacts/`, `design/`, `docs/06-review/`, `SECURITY-AUDIT-20261004.md`, many `docs/*CHECKPOINT/AUDIT*.md` = unknown ownership, Owner to classify.
- Scoped tests after changes: KmsitRoutingTest 41/99, WhatsAppAgentScopeTest 16/152 (testing DB, faked HTTP).

## KMSIT connection recovery (HTTP 422) — 2026-11-04
- Credential file `kmsit-routing-smoketest.local.txt`: present, key non-placeholder (43 chars, never printed), `KMSIT_AUTH_SCHEME=UNVERIFIED`, **`KMSIT_ALLOW_LIVE_REQUESTS=false`** → NO live request was issued by this pass. File not modified; excluded via `.git/info/exclude` (untracked, not in `.gitignore`).
- Evidence (app log from the Owner's real UAT calls to `routing.kmsitcomputer.com`, DEV agent 11, profile `driving`, token stored 43 chars): 10 × HTTP 404 `provider_error` (2.7–3.6 s). The 422 is PrimeClassy's generic mapping of any `ShippingQuoteException`; the server answered 404 (not 401/403, not an OSRM `code`), i.e. the requested path `{base}/route/v1/driving/{lon,lat};{lon,lat}` is not served as sent (gateway path prefix / different path / auth-hidden 404 — NOT determinable without a live request or the server's documented contract).
- Defect fixed (diagnosability): `KmsitRouteException` carries a safe `reason` + HTTP status; Test Connection now returns `errors.reason` (`not_found`, `invalid_credential`, `endpoint_blocked`, `connection_error`, `osrm_noroute`, `osrm_nosegment`, `rate_limited`, `invalid_response`, `provider_error`); UI shows it. No URL/token/body ever returned. Auth is currently `Authorization: Bearer <token>` — an ASSUMPTION, unverified. A gateway path prefix can already be put in the Agen's base URL (e.g. `https://host/prefix`).
- Tests: KmsitRoutingTest 47/127, AgentShippingProvidersKmsit.spec 4; vue-tsc only pre-existing ImageUploader error. Mocked only.
- EXACT NEXT ACTION (Owner): set `KMSIT_ALLOW_LIVE_REQUESTS=true` in the local file (or provide the server's API doc: auth header scheme + path), then re-run this recovery for ≤3 bounded live requests.

## KMSIT Gateway contract fix — 2026-11-04 (read from WSL `Ubuntu`, read-only)
- Contract: `/home/kmsit_komputer/projects/kmsit-routing/packages/api-contracts/openapi.yaml`, OpenAPI 3.1.0, `info.version 1.1.0-aud-remediation` (LOCKED BASELINE). servers: local `http://127.0.0.1:8080` + production placeholder `https://ORIGIN-NOT-SET`.
- Route: `GET /api/v1/routes?profile=car|motorcycle&coordinates=lon,lat;lon,lat[&alternatives=bool]`; auth `X-API-Key` (securityScheme `serviceApiKey`, scope `routes:read`; session cookie is the admin-console alternative); 200 `{request_id, profile, distance_m, duration_s, geometry, waypoints, warnings}`; errors `{error:{code,message,request_id}}` — VALIDATION_ERROR (outside Bandung Raya bbox), UNAUTHENTICATED/AUTH_FAILED, FORBIDDEN, ROUTE_NOT_FOUND (404), RATE_LIMITED (429 + X-RateLimit-*), UPSTREAM_UNAVAILABLE/TIMEOUT (504).
- PROVEN root cause of the 404s/422: PrimeClassy spoke native OSRM (`/route/v1/driving/…`, `Authorization: Bearer`, profiles driving/bike/foot, `routes[0].distance`) to a Gateway that serves none of it.
- Fix: `KmsitDistanceCalculator` rewritten to the Gateway contract (path, `X-API-Key`, `car|motorcycle`, legacy `driving`→`car` mapping, `distance_m/duration_s`, error-envelope → safe reasons incl. `forbidden`, `validation_error`, `route_not_found`, `upstream_unavailable`, `missing_credential`, `invalid_profile`); key mandatory on first save; field validation `car|motorcycle`; UI profile list/reason texts/labels; base URL = gateway origin (trailing `/api/v1` tolerated, never doubled). SSRF/pin/no-proxy/no-redirect/TLS, ORS→KMSIT→fail-closed, tariff, cache isolation unchanged. DEV Agen 11 stored config (root host, profile `driving`→car at runtime, 43-char key) needs no change.
- Tests (mocked to the OpenAPI shapes): KmsitRoutingTest 56/151, AgentShippingProvidersKmsit.spec 5; vue-tsc only pre-existing ImageUploader error.
- LIVE: NOT RUN — `KMSIT_ALLOW_LIVE_REQUESTS=false` in the local file (re-checked after the contract was read). EXACT NEXT ACTION: Owner sets it to `true`; then ≤2 live GETs on the primary URL (+ Test Connection from the UI) and a PASS/FAIL verdict.

## KMSIT LIVE verification — 2026-11-04 (switch `KMSIT_ALLOW_LIVE_REQUESTS=true` on disk)
- Request 1 (direct, host-locked, no redirects, TLS default, key in-process only): `GET https://routing.kmsitcomputer.com/api/v1/routes?profile=car&coordinates=<UAT lon,lat;lon,lat>` + `X-API-Key` → **HTTP 200**, no error code, request_id `e73513fae15a0e6bcf8bada68ad23113`, profile `car`, **distance_m 12216.3, duration_s 659.4**, `X-RateLimit-Limit 50000` / Remaining 49995, JSON, no redirect.
- Request 2 (PrimeClassy code path = Test Connection backend, `KmsitProvider::testForAgent` with DEV Agen 11's stored encrypted config: root host, profile `driving`→`car`, stored key) → `connected:true, profile car, distance_km 12.22, duration_seconds 659.4`. The stored key and URL are therefore valid; no config change was needed.
- UI click not operated by the agent → Owner clicks Test Connection once for UAT.
- Owner request "tampilkan jarak yang dihitung": checkout review now shows "Jarak tempuh / Route distance: N km" under the shipping row (from the server quote's `distance_km`, hidden when null or free-shipping-disabled); i18n id/en/ar/zh `checkout.review.distance`. vue-tsc: only the pre-existing ImageUploader error. The KMSIT card already shows distance + duration after Test Connection.

## Kurir Toko shared tariff refactor — 2026-11-04 (no migration, no schema change)
- Baseline matrix (before): READ path was already independent of the ORS toggle (quote read `shipping_configurations` by Agen anchored to the `openroute` provider row; agent toggle writes `agent_shipping_provider_settings`, global flag lives on the provider row — neither consulted for the tariff). COUPLING was on WRITE/UI: the tariff could only be saved through the ORS credentials form (api_key required, ORS config overwritten) and the fields sat inside the ORS card.
- After: `Services/Shipping/KurirTokoTariff` is the single accessor (same table/row, anchor = `openroute` row id, documented); new `GET|PUT /agent/shipping-tariff` (`AgentShippingTariffController`, own Agen only, server validation, free-shipping fields preserved when not sent, audited); `OpenRouteProvider::quote` reads the tariff via the accessor; ORS form no longer requires/overwrites the tariff (still accepted together for old clients); provider list `pricing` served from the same record. Formula, rounding, minimum charge, free-shipping rule and requote unchanged.
- Frontend: new "Pengaturan Tarif Kurir Toko" card (always visible/editable), ORS card = key/profile/origin/test coords only, KMSIT card = URL/key/profile/test only; `api/agentSettings.ts` get/saveKurirTokoTariff.
- Scoped tests: KurirTokoTariffTest 10/58 (ORS off/unconfigured save+read; toggles & ORS save don't change the row; free-shipping preserved; matrix ORS-primary / failover / ORS-off→KMSIT same cost; both off and no-tariff fail closed; legacy row readable; cross-Agen isolation + forged id ignored; validation + roles; audit), KmsitRoutingTest 56, ShippingProviderTest 30 (RajaOngkir, requote, client-manipulation), CheckoutCoordinateTest 11, AgentScopedSettingsTest 9; frontend KMSIT/tariff spec 7; vue-tsc only pre-existing ImageUploader error. No migration, no Full Test, no production, no commit.

## Acceptance freeze & handoff — DEV FUNCTIONAL ACCEPTED (documentation-only entry)
**Verdict: `DEV FUNCTIONAL ACCEPTED — OWNER UAT PASS`. Next status: `AWAITING OWNER FULL TEST AND RELEASE AUTHORIZATION`.** This is NOT a production-ready statement; production-readiness needs the Owner's Full Test and explicit release approval.

| # | Acceptance item | Result | Evidence / who |
|---|---|---|---|
| 1 | KMSIT Gateway live route (`GET /api/v1/routes`, `X-API-Key`, profile `car`) | **HTTP 200**, 12,216.3 m / 659.4 s, rate-limit headers present, no redirect, TLS verified | Claude, one bounded live request (see "KMSIT LIVE verification") |
| 2 | PrimeClassy Test Connection backend path (`KmsitProvider::testForAgent`, Agen 11 stored encrypted config) | `connected:true`, 12.22 km, 659.4 s | Claude, backend invocation |
| 3 | Owner browser UAT — five Kurir Toko checks | **PASS** | **Owner's confirmation. The browser UAT was NOT executed by Claude.** |
| 4 | Kurir Toko tariff independent of ORS | Tariff has its own endpoint/card; ORS toggle/credentials never read or written for it; same cost whichever provider supplies distance | scoped tests + Owner UAT |
| 5 | Routing priority | ORS primary → KMSIT fallback → fail-closed; RajaOngkir untouched | scoped tests + Owner UAT |
| 6 | Backend scoped tests | **123 PASS** (figure reported by the Owner). Itemised suites Claude ran in the last pass: KurirTokoTariff 10, KmsitRouting 56, ShippingProvider 30, CheckoutCoordinate 11, AgentScopedSettings 9 = 116; the remaining 7 are not itemised in Claude's records — the 123 total is recorded as the Owner's figure and was not re-run for this entry | Owner / Claude |
| 7 | Frontend scoped tests | **7 PASS** (KMSIT + tariff card spec) | Claude |

Frozen scope: WhatsApp management (Agen/Admin Agen), KMSIT Gateway adapter (X-API-Key, `car|motorcycle`, per-Agen endpoint, SSRF/IP-pin/no-proxy/no-redirect/TLS), shared Kurir Toko tariff, checkout "Route distance" line. DEV migrations `2026_11_04_100000` and `2026_11_04_100100` applied to `primeclassy_dev` (batch 42, after backup); the tariff refactor needed no migration.

### Technical debt (recorded, not fixed)
- **TD-1 Tariff anchor.** The shared tariff is still stored in `shipping_configurations` anchored to the `openroute` `shipping_providers` row id. Provider status (global flag, Agen toggle, credentials) does not affect it — `KurirTokoTariff` never reads them — but the dependency on that row's existence is structural; deleting the row would orphan every Agen's tariff. A future cleanup (neutral anchor / dedicated table) needs Owner approval and an additive migration.
- **TD-2 Legacy Super Admin WhatsApp endpoints** (templates/events/console test-message) kept as platform defaults (B-02).
- **TD-3 KMSIT gateway coverage** is Bandung Raya only (contract bbox); outside it the gateway answers VALIDATION_ERROR and PrimeClassy fails closed.
- **TD-4 Test-count reconciliation** (item 6) and PHPUnit doc-comment `@dataProvider` deprecation warnings in `KmsitRoutingTest`.
- **TD-5 Working-tree hygiene** (stray `backend/2048])!`, `backend/holder)`, root log) still unresolved; nothing was deleted.

### Explicit statuses
- **Full Test: NOT EXECUTED — reserved for the Owner (Windows PowerShell).**
- Production: NOT touched. No commit, push, merge or deploy; HEAD `1f510e6`, working tree uncleaned and all prior changes preserved.
- No secrets in this document (API keys and tokens are referred to by length/presence only; no credential value, header value or full phone number is recorded).

### EXACT NEXT ACTION
Owner: run the Full Test, review the diff, and decide on release authorization. Until then the status stays `AWAITING OWNER FULL TEST AND RELEASE AUTHORIZATION`.

## Owner Full Test executed — quality gates PASSED, OWNER VERIFIED (2026-11-01)
- **Backend (Owner-run, PowerShell):** PHPUnit **1.721 tests, 12.702 assertions, exit code 0**, duration 7.507,8 s; environment failures 0, migration failures 0, concurrency failures 0, test failures 0; PHPUnit deprecations 3 (non-blocking). Run ID `5ca38852-af26-441c-bcaf-d288d3d0fc67` (runner `scripts/run-tests-serialized.php --log-junit`), DB `primeclassy_testing`, PHP 8.3.33, git HEAD `1f510e6` **dirty-paths 132** (the artifact records the working tree the suite covered). Summary artifact: `backend/storage/framework/testing/runs/5ca38852-af26-441c-bcaf-d288d3d0fc67.summary.json` (git-ignored; junit + stats alongside). Console log: `primeclassy-fulltest-backend-14480521-2041.log` (untracked, outside the commit).
- **Frontend (Owner-run):** Vitest **266/266 PASS, 31 files** (log `primeclassy-fulltest-frontend-14480521-2043.log`); **Type Check PASS**; **Production Build PASS** (Vite 1.942 modules transformed; PWA `sw.js` generated, 153 precache entries). Non-blocking observations: Vue Router/RouterLink warnings and a chunk-size warning; reaktivitas tombol retry-upload belum dibuktikan oleh test komponen khusus.
- **`ImageUploader.vue` TS2339 blocker FIXED** (type-only change: `let lastFile = null as File | null`). Timing note, verified by this release audit: the backend run started 20:41 WIB with dirty-paths 132 (= the release tree); the only source file touched after the runs began is this type-only ImageUploader fix (20:49), covered by the Owner's type-check + production build — behaviour-neutral, so the Vitest 266/266 result remains valid for it.
- **Quality gate status: `DEV AUTOMATED QUALITY GATES PASSED — OWNER VERIFIED`.**

## Independent implementation review (GLM, 2026-11-01)
Read-only diff-first review of the WA + KMSIT implementation concluded **PASS WITH CONDITIONS** (no blocking code defect; conditions = DEV/production migrations, B-02 kept-as-legacy decision, live-server evidence — all since resolved: migrations DEV batch 42, B-02 kept per Section F, live route verified HTTP 200). Full matrix available in the review response record; key confirmations: server-side scope resolution (403 matrix), explicit sender slot with no fallback, restricted-lane closed allow-list re-checked at send time, ORS→KMSIT→fail-closed fallback matrix, unchanged tariff and RajaOngkir path, per-Agen/endpoint/profile/coordinate cache isolation, EndpointGuard SSRF/pin/no-proxy/no-redirect.

## Release authorization (Owner, 2026-11-01)
Owner authorized — explicitly and for this release only: documentation finalisation, commit of the verified tree, push to `main` on the PrimeClassy V2 GitHub remote, and production deployment of that exact SHA. Production data/`.env`/uploads stay untouched; migrations are the three additive files listed above (`2026_11_03_100000` ledger columns — DEV applied, `2026_11_04_100000` kmsit row, `2026_11_04_100100` scoped WA settings — both DEV batch 42); production applies them via `migrate --force` after the mandatory backup. Rollback = the pre-deploy backup (extract tars + restart; no data reset).

## Post-deploy record (to be completed after deployment)
- Commit SHA pushed/deployed: *(filled after deployment)*
- Production backup path: *(filled)*
- Migrations Ran: *(filled)*; smoke results: *(filled)*
