# IMP-010 — Complete API parity matrix

Generated from `go-backend/internal/api/parity_routes.json` (375 `/api/v1` routes on the IMP-008/IMP-009 baseline, REM-11 excluded) by `tools/parity/gen_matrix.py`. **Do not edit by hand.**

Status legend:

- **GO** — served by the Go service, contract-compared against Laravel (`tools/parity/compare_*.py`).
- **PARTIAL** — served, but the route's own behaviour is still incomplete (see the note). A note alone does NOT make a route partial: informational notes and documented deviations stay **GO**.
- **LARAVEL** — still served by Laravel only; the Go service answers `501 not_implemented`.

## addresses — `addresses` (4/4 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/addresses` | session | konsumen | **GO** | own rows only; list ordering differs (deviation D-10) |
| POST | `/api/v1/addresses` | session | konsumen | **GO** |  |
| DELETE | `/api/v1/addresses/{address}` | session | konsumen | **GO** | a foreign id is a 404 |
| PATCH | `/api/v1/addresses/{address}` | session | konsumen | **GO** | promotes the default correctly where the reference leaves none (deviation D-9) |

## Admin operations — `admin` (59/59 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/admin/additional-payments` | session | admin, agen, keuangan, super_admin | **GO** | Branch-scoped operational/finance oversight list, current buyer/requester names, manual payment instructions, latest-first pagination; compare_admin_additional_payments.py |
| PATCH | `/api/v1/admin/additional-payments/{payment}/status` | session | keuangan, super_admin | **GO** | Keuangan/Super Admin pending-only settlement; proof→order→transaction lock order, transfer verification, COD and transfer balances, invoice snapshots, audit and stale replay; compare_admin_additional_payment_status.py |
| GET | `/api/v1/admin/agents` | session | super_admin | **GO** | AgentDirectoryResource projection ordered by name (set-compared), soft-delete excluded; 107/0 compared by compare_admin_agents.py |
| POST | `/api/v1/admin/agents` | session | super_admin | **GO** | accumulated store validation, not_an_agent / profile_already_exists 422s, 201 + directory resource, agent_profile.created audit; 107/0 compared by compare_admin_agents.py |
| DELETE | `/api/v1/admin/agents/{agentProfile}` | session | super_admin | **GO** | profile delete with SUBJECT=acting-user audit and localized response; 107/0 compared by compare_admin_agents.py |
| PATCH | `/api/v1/admin/agents/{agentProfile}` | session | super_admin | **GO** | sometimes-field partial update under lock, decimal:7 strings, old/new audit properties; 107/0 compared by compare_admin_agents.py |
| PATCH | `/api/v1/admin/agents/{agent}/toggle-status` | session | super_admin | **GO** | active<->inactive flip both directions, not_an_agent 422, binding-miss 404, status audit; 107/0 compared by compare_admin_agents.py |
| GET | `/api/v1/admin/audit-logs` | session | super_admin | **GO** | super_admin paged audit trail compared by compare_reports.py (GLM reporting slice, 150/150) |
| PATCH | `/api/v1/admin/cod-payment-proofs/{codPaymentProof}/confirm` | session | keuangan, super_admin | **GO** | keuangan/super_admin; confirm settles the real collection |
| GET | `/api/v1/admin/google-auth` | session | super_admin | **GO** | Super Admin global Google OAuth settings; secret is never returned; compare_google_auth_settings.py |
| PUT | `/api/v1/admin/google-auth` | session | super_admin | **GO** | Global Google OAuth config validation, Laravel-compatible secret preservation/clear, encrypted storage, audit; compare_google_auth_settings.py |
| GET | `/api/v1/admin/integrations/smtp` | session | super_admin | **GO** | Super Admin SMTP snapshot with write-only password state and active environment/dashboard source; compare_admin_smtp_settings.py |
| PUT | `/api/v1/admin/integrations/smtp` | session | super_admin | **GO** | validated SMTP config, encrypted write-only secret preserve/clear, completeness guard and redacted audit; compare_admin_smtp_update.py |
| POST | `/api/v1/admin/integrations/smtp/test-connection` | session | super_admin | **GO** | global saved SMTP connect, encryption/auth negotiation and sanitized status only; compare_admin_smtp_test.py against a local SMTP server |
| POST | `/api/v1/admin/integrations/smtp/test-email` | session | super_admin | **GO** | global SMTP test mail sent exclusively to the authenticated Super Admin address; compare_admin_smtp_test.py captures recipient using local SMTP server |
| GET | `/api/v1/admin/integrations/whatsapp` | session | super_admin | **GO** | Super Admin GOWA configuration snapshot, masked password/test numbers, event defaults and OTP/rate settings; compare_admin_whatsapp_settings.py |
| PUT | `/api/v1/admin/integrations/whatsapp` | session | super_admin | **GO** | Validated GOWA config, encrypted write-only password, normalized test-number allow-list, rate/OTP/event settings and redacted before/after audit; compare_admin_whatsapp_update.py |
| GET | `/api/v1/admin/integrations/whatsapp/messages` | session | super_admin | **GO** | filtered Super Admin WhatsApp message ledger, masked recipients, retry flags and pagination; compare_admin_whatsapp_messages.py |
| POST | `/api/v1/admin/integrations/whatsapp/messages/{message}/retry` | session | super_admin | **GO** | Super Admin failed/unknown retry, duplicate-risk gate, CAS, durable audit, Laravel-compatible primeclassy-notify database job containing only message ID, and gateway-disabled skip; compare_admin_whatsapp_retry.py + jobs payload test |
| DELETE | `/api/v1/admin/integrations/whatsapp/templates/{event}` | session | super_admin | **GO** | idempotent Super Admin global template reset to Laravel default with before/after audit; compare_admin_whatsapp_templates.py |
| PUT | `/api/v1/admin/integrations/whatsapp/templates/{event}` | session | super_admin | **GO** | Super Admin global message template validation, save, snapshot and audit; compare_admin_whatsapp_templates.py |
| POST | `/api/v1/admin/integrations/whatsapp/templates/{event}/preview` | session | super_admin | **GO** | Super Admin global template preview, token validation and optional-line rendering; compare_admin_whatsapp_preview.py |
| POST | `/api/v1/admin/integrations/whatsapp/test-connection` | session | super_admin | **GO** | read-only authenticated GOWA status/device probe with state audit; compare_admin_whatsapp_test_connection.py uses a local stub |
| POST | `/api/v1/admin/integrations/whatsapp/test-message` | session | super_admin | **GO** | Super Admin allow-listed test send, normalized destination, safe template cleaning, synchronous GOWA delivery and ledger result; compare_admin_whatsapp_test_message.py uses a local stub |
| GET | `/api/v1/admin/languages` | session | super_admin | **GO** | admin listing: every language, active or not |
| POST | `/api/v1/admin/languages` | session | super_admin | **GO** |  |
| PATCH | `/api/v1/admin/languages/{language}` | session | super_admin | **GO** |  |
| PATCH | `/api/v1/admin/languages/{language}/set-default` | session | super_admin | **GO** | exactly one default; the target must be active |
| PATCH | `/api/v1/admin/languages/{language}/toggle-active` | session | super_admin | **GO** | the default language can never be deactivated |
| GET | `/api/v1/admin/order-refunds` | session | admin, agen, keuangan, super_admin | **GO** | Branch-scoped fulfillment-shortfall refunds, current buyer/adjuster names, item snapshots, decimal refund amount and status, latest-first pagination; compare_admin_order_refunds.py |
| PATCH | `/api/v1/admin/order-refunds/{adjustment}/status` | session | keuangan, super_admin | **GO** | Keuangan/Super Admin pending-only status update with Order-first lock, CRM refund-recipient guard, ledger legacy settlement or payment reversal/invoice snapshot, branch scope and audit; compare_admin_order_refund_status.py |
| POST | `/api/v1/admin/order-sales-reductions/{reduction}/reinstate` | session | agen, keuangan, super_admin | **GO** | Owner-only order-scoped reinstatement with evidence validation, order/reduction locks, in-flight and financial-bound checks, durable idempotent refund-ledger event, preserved replay response; compare_admin_reinstate_reduction.py |
| POST | `/api/v1/admin/orders/{order}/cancellation/finalize` | session | agen, keuangan, super_admin | **GO** | Finance-only financial finalization under Order lock, defer-on-in-flight, supersedable forward-obligation closure, available paid refund obligation creation and append-only ledger audit; compare_admin_cancellation_finalize.py |
| GET | `/api/v1/admin/orders/{order}/refunds` | session | admin, agen, keuangan, super_admin | **GO** | Order-scoped refund obligations, attempts, payouts, children, reductions and integer-cent financial metrics; compare_admin_order_refund_ledger.py |
| POST | `/api/v1/admin/refund-attempts/{attempt}/cancel` | session | agen, keuangan, super_admin | **GO** | Finance-only created-attempt cancellation window, required reason, finished timestamp and append-only audit; compare_admin_refund_attempt_terminals.py |
| POST | `/api/v1/admin/refund-attempts/{attempt}/complete` | session | agen, keuangan, super_admin | **GO** | Finance-only processing-to-succeeded transition, self-exclusion reservation, payout + paid-amount effect, idempotent replay and durable audit; compare_admin_refund_attempt_complete.py |
| POST | `/api/v1/admin/refund-attempts/{attempt}/fail` | session | agen, keuangan, super_admin | **GO** | Finance-only processing-to-failed transition, validated failure details, preserved replay, attempt audit and cancelled-order finalization re-entry; compare_admin_refund_attempt_fail.py |
| POST | `/api/v1/admin/refund-attempts/{attempt}/initiate` | session | agen, keuangan, super_admin | **GO** | Finance-only created-to-processing transition, Order-first locking, external reference preservation on replay, initiated timestamps and idempotent append-only audit; compare_admin_refund_attempt_initiate.py |
| POST | `/api/v1/admin/refund-attempts/{attempt}/reconcile` | session | agen, keuangan, super_admin | **GO** | Finance reconciliation of unknown attempts to succeeded payout or failed state, evidence, audit and cancellation re-entry in one transaction; compare_admin_refund_attempt_reconcile.py |
| POST | `/api/v1/admin/refund-attempts/{attempt}/unknown` | session | agen, keuangan, super_admin | **GO** | Finance-only processing-to-unknown transition, optional note, replay preservation, unknown_since and durable audit; compare_admin_refund_attempt_terminals.py |
| POST | `/api/v1/admin/refund-obligations/{obligation}/approve` | session | agen, keuangan, super_admin | **GO** | Branch owner/Super Admin pending-only approval, owner authorization, idempotent approved replay and unique append-only ledger event; compare_admin_refund_obligation_approve.py |
| POST | `/api/v1/admin/refund-obligations/{obligation}/attempts` | session | agen, keuangan, super_admin | **GO** | Finance-only attempt creation with CRM recipient evidence guard, Order/obligation locking, scoped key replay/collision protection, reservation arithmetic and durable attempt-created event; compare_admin_refund_attempt_create.py |
| POST | `/api/v1/admin/refund-obligations/{obligation}/deferred/materialize` | session | agen, keuangan, super_admin | **GO** | Owner-only deferred entitlement materialization with replay-first idempotency, cash cap, child approval, parent balance update and append-only audit; compare_admin_refund_deferred.py |
| POST | `/api/v1/admin/refund-obligations/{obligation}/deferred/request` | session | agen, keuangan, super_admin | **GO** | Finance deferred materialization request, Order/obligation locks, idempotent event key and available-entitlement snapshot; compare_admin_refund_deferred.py |
| POST | `/api/v1/admin/refund-obligations/{obligation}/needs-verification/resolve` | session | agen, keuangan, super_admin | **GO** | Finance/Owner exits A/B with historical-owner enforcement, evidence, idempotent no-op, state audit and branch authorization; compare_admin_refund_needs_verification.py |
| GET | `/api/v1/admin/regions/export` | session | super_admin | **GO** | Super Admin four-level joined reference export with Laravel CSV quoting, filename and UTF-8 content type; compare_admin_regions_csv.py |
| POST | `/api/v1/admin/regions/import` | session | super_admin | **GO** | Super Admin multipart CSV import, validated hierarchy rows, one transaction, exact upsert counts and audit; compare_admin_regions_csv.py |
| PATCH | `/api/v1/admin/return-items/{item}/refund` | session | keuangan, super_admin | **GO** | Keuangan/Super Admin and same-Agent gates, approved/full-paid/CRM-recipient checks, idempotent legacy refund attempt and payout, paid balance/invoice reversal, refund completion, Sales-only fee adjustment and audit; compare_return_item_refund.py |
| GET | `/api/v1/admin/returns` | session | admin, agen, super_admin | **GO** | Admin/Agen branch-scoped, Super Admin optional agent_id, status list filter, latest-first pagination, full ReturnRequestResource + pagination meta; compare_admin_returns.py |
| GET | `/api/v1/admin/returns/{return}` | session | admin, agen, super_admin | **GO** | Super Admin or same-Agent Admin/Agen branch authorization; complete ReturnRequestResource, 404 for missing model and 403 for foreign branch; compare_admin_return_show.py |
| PATCH | `/api/v1/admin/returns/{return}/pickup-schedule` | session | admin, agen, super_admin | **GO** | Admin/Agen branch scope, exact Y-m-d and WIB not-past validation, pending/approved item updates only, no-eligible-item error, response and activity audit; compare_return_pickup_schedule.py |
| PATCH | `/api/v1/admin/returns/{return}/review` | session | admin, agen, super_admin | **GO** | Admin/Agen branch scope, Laravel validation and localized responses, order-first review transaction, return state reversal/approval, frozen financial units, refund obligations and audit; compare_return_review.py |
| GET | `/api/v1/admin/settings` | session | super_admin | **GO** | identical to the public read (single service in the reference) |
| PUT | `/api/v1/admin/settings` | session | super_admin | **GO** | unknown keys ignored, JSON keys encoded, returns the resolved settings |
| GET | `/api/v1/admin/shipping-couriers` | session | super_admin | **GO** | Super Admin provider-supported courier master list ordered by name; compare_admin_shipping_couriers.py |
| POST | `/api/v1/admin/shipping-couriers` | session | super_admin | **GO** | Super Admin courier create with lowercase code, regex/max/unique validation and audit; compare_admin_shipping_couriers.py |
| PATCH | `/api/v1/admin/shipping-couriers/{shippingCourier}/toggle` | session | super_admin | **GO** | Super Admin courier active-state toggle and audit; compare_admin_shipping_couriers.py |
| GET | `/api/v1/admin/shipping-providers` | session | super_admin | **GO** | Super Admin global provider list limited to supported codes, id order, no per-Agent secrets; compare_admin_shipping_providers.py |
| PATCH | `/api/v1/admin/shipping-providers/{provider}/toggle` | session | super_admin | **GO** | Super Admin global active toggle, same response and audited state; compare_admin_shipping_providers.py |

## agent — `agent` (31/33 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/agent/google-auth` | session | admin, agen | **GO** | Agent/Admin own-branch Google OAuth config; no cross-branch identifier accepted, secret masked; compare_google_auth_settings.py |
| PUT | `/api/v1/agent/google-auth` | session | admin, agen | **GO** | Agent/Admin own-branch config validation, encrypted secret preserve/clear, scoped audit; compare_google_auth_settings.py |
| GET | `/api/v1/agent/payment-methods` | session | admin, agen | **GO** |  |
| PUT | `/api/v1/agent/payment-methods/{method}/config` | session | admin, agen | **GO** | FormRequest validation order, localized messages, encrypted config writes, and RBAC compared by compare_agentadmin.py |
| PATCH | `/api/v1/agent/payment-methods/{method}/toggle` | session | admin, agen | **GO** | role gates, toggle response shape, and resulting setting rows compared by compare_agentadmin.py |
| GET | `/api/v1/agent/shipping-providers` | session | agen | **GO** |  |
| PUT | `/api/v1/agent/shipping-providers/{provider}/config` | session | agen | **GO** | provider-specific validation, SSRF refusal, encrypted config side effect and localized response compared by compare_shipping_admin.py |
| GET | `/api/v1/agent/shipping-providers/{provider}/couriers` | session | agen | **GO** |  |
| PUT | `/api/v1/agent/shipping-providers/{provider}/couriers` | session | agen | **GO** | supported-courier validation, response, and encrypted config side effect compared by compare_shipping_admin.py |
| POST | `/api/v1/agent/shipping-providers/{provider}/destinations` | session | agen | LARAVEL |  |
| POST | `/api/v1/agent/shipping-providers/{provider}/test` | session | agen | LARAVEL |  |
| PATCH | `/api/v1/agent/shipping-providers/{provider}/toggle` | session | agen | **GO** | five-key response projection, localized message, audit and setting row compared by compare_shipping_admin.py |
| GET | `/api/v1/agent/shipping-tariff` | session | agen | **GO** |  |
| PUT | `/api/v1/agent/shipping-tariff` | session | agen | **GO** | pricing validation, Laravel response message, audit properties and resulting tariff row compared by compare_shipping_admin.py |
| GET | `/api/v1/agent/store-profile` | session | agen | **GO** |  |
| PUT | `/api/v1/agent/store-profile` | session | agen | **GO** | Laravel validation messages, decimal:7 response strings, ignored client ownership fields, activity old/new projection and owner-only scope compared by compare_agentadmin.py |
| GET | `/api/v1/agent/whatsapp` | session | admin, agen | **GO** | Scoped Agent device status refresh, masked pairing state and gateway readiness; compare_agent_whatsapp_status.py |
| POST | `/api/v1/agent/whatsapp/device` | session | admin, agen | **GO** | Idempotent one-device-per-Agent GOWA slot creation, ownership audit and refreshed masked status; compare_agent_whatsapp_status.py |
| GET | `/api/v1/agent/whatsapp/events` | session | admin, agen | **GO** | RBAC, agent_id selector, event ordering and full payload compared with database read-only evidence by compare_whatsapp_events.py |
| PUT | `/api/v1/agent/whatsapp/events/{event}` | session | admin, agen | **GO** | boolean/string/max validation, template token validation, role and agent scope, persisted settings and AgentWhatsAppEventSetting audit compared by compare_whatsapp_event_updates.py |
| POST | `/api/v1/agent/whatsapp/events/{event}/preview` | session | admin, agen | **GO** | Agen/Admin branch-authorized template preview with shared validation and sample rendering; compare_admin_whatsapp_preview.py |
| DELETE | `/api/v1/agent/whatsapp/events/{event}/template` | session | admin, agen | **GO** | idempotent reset, no-op persistence, event validation, branch scope and conditional AgentWhatsAppEventSetting audit compared by compare_whatsapp_event_resets.py |
| POST | `/api/v1/agent/whatsapp/logout` | session | admin, agen | **GO** | Agent-owned slot logout, persistent slot state transition and actor/target audit; compare_agent_whatsapp_status.py |
| GET | `/api/v1/agent/whatsapp/messages` | session | admin, agen | **GO** | One branch Agent's latest 50 delivery rows, masked destination and no message body; compare_agent_whatsapp_messages.py |
| POST | `/api/v1/agent/whatsapp/pair/code` | session | admin, agen | **GO** | Validated MSISDN pairing code request, pairing state and audit without storing the full number; compare_agent_whatsapp_status.py |
| POST | `/api/v1/agent/whatsapp/pair/qr` | session | admin, agen | **GO** | QR pairing with server-fetched allow-listed PNG path, data URI response and pairing state audit; compare_agent_whatsapp_status.py |
| POST | `/api/v1/agent/whatsapp/reconnect` | session | admin, agen | **GO** | Agent-owned GOWA reconnect, refreshed connection state and actor/target audit; compare_agent_whatsapp_status.py |
| POST | `/api/v1/agent/whatsapp/replace` | session | admin, agen | **GO** | Accepted-confirmation guard, create-before-delete slot replacement and replacement audit; compare_agent_whatsapp_status.py |
| GET | `/api/v1/agent/whatsapp/scope` | session | admin, agen | **GO** | Agen self-scope, Admin branch-scope, denied roles, ignored client agent_id, and read-only DB state compared by compare_whatsapp_scope.py |
| GET | `/api/v1/agent/whatsapp/test-numbers` | session | admin, agen | **GO** | masked numbers, max limit, Agen/Admin scope, forbidden roles and agent_id selector compared with read-only DB state by compare_whatsapp_test_numbers.py |
| POST | `/api/v1/agent/whatsapp/test-numbers` | session | admin, agen | **GO** | Laravel accepted/required/max validation, MSISDN normalization, duplicate and 5-number cap behavior, branch scope, resulting rows and conditional audit compared by compare_whatsapp_test_number_add.py |
| DELETE | `/api/v1/agent/whatsapp/test-numbers/{id}` | session | admin, agen | **GO** | owned/missing/foreign row behavior, selector scope, resulting rows and conditional AgentWhatsAppTestNumber audit compared by compare_whatsapp_test_number_delete.py |
| POST | `/api/v1/agent/whatsapp/test-send` | session | admin, agen | **GO** | Allow-listed Agent test message with per-Agent limits, paired-device enforcement, sanitized payload, durable ledger, delivery attempt and audit; compare_agent_whatsapp_status.py |

## Agent directory — `agents` (1/1 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/agents` | public | — | **GO** |  |

## CMS — articles — `articles` (2/2 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/articles` | public | — | **GO** |  |
| GET | `/api/v1/articles/{slug}` | public | — | **GO** |  |

## Identity & session — `auth` (4/8 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| POST | `/api/v1/auth/forgot-password` | public | — | LARAVEL |  |
| GET | `/api/v1/auth/google/callback` | public | — | LARAVEL |  |
| GET | `/api/v1/auth/google/redirect` | public | — | LARAVEL |  |
| POST | `/api/v1/auth/login` | public | — | **GO** |  |
| POST | `/api/v1/auth/logout` | session | — | **GO** |  |
| GET | `/api/v1/auth/me` | session | — | **GO** |  |
| POST | `/api/v1/auth/register` | public | — | **GO** | user + referral chain + closures; welcome e-mail/WA notification not emitted (queue bridge outstanding) |
| POST | `/api/v1/auth/reset-password` | public | — | LARAVEL |  |

## Catalog — bestsellers — `bestsellers` (1/1 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/bestsellers` | public | — | **GO** | Agent-resolved receipt-based product ranking with accepted-return deductions, unresolved-allocation exclusion, WIB date windows and hidden metadata; compare_bestsellers.py |

## catalog — `catalog` (1/3 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/catalog/export` | session | admin, agen, super_admin | **GO** | Product and variation CSV/XLSX export, formula-safe cells, explicit single-Agent stock context and Sub-location ownership; compare_catalog_export.py |
| POST | `/api/v1/catalog/imports` | session | admin, agen, super_admin | LARAVEL |  |
| POST | `/api/v1/catalog/imports/preview` | session | admin, agen, super_admin | LARAVEL |  |

## Catalog — categories — `categories` (4/4 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/categories` | public | — | **GO** |  |
| POST | `/api/v1/categories` | session | admin, agen, super_admin | **GO** | global taxonomy create; super_admin/admin/agen; normalized name, sibling duplicate guard and server-generated slug |
| DELETE | `/api/v1/categories/{category}` | session | super_admin | **GO** | super_admin only; children are promoted by ON DELETE SET NULL |
| PATCH | `/api/v1/categories/{category}` | session | super_admin | **GO** | super_admin only; parent-cycle and sibling-duplicate guards |

## Checkout — `checkout` (2/4 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| POST | `/api/v1/checkout/courier-options` | session | agen, konsumen, korsal, sales, sales-kurir-sub | LARAVEL |  |
| POST | `/api/v1/checkout/geocode` | session | agen, konsumen, korsal, sales, sales-kurir-sub | LARAVEL |  |
| POST | `/api/v1/checkout/quote` | session | agen, konsumen, korsal, sales, sales-kurir-sub | **GO** | read-only Review preview through the same money/pricing arithmetic as order creation; never reserves stock or consumes a voucher |
| GET | `/api/v1/checkout/steps` | public | — | **GO** | public; dynamic step list with role-derived flags, agent provider switches, KMSIT fallback and the konsumen payment filter |

## CMS — admin — `cms` (14/14 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/cms/articles` | session | super_admin | **GO** |  |
| POST | `/api/v1/cms/articles` | session | super_admin | **GO** |  |
| DELETE | `/api/v1/cms/articles/{article}` | session | super_admin | **GO** |  |
| PATCH | `/api/v1/cms/articles/{article}` | session | super_admin | **GO** |  |
| GET | `/api/v1/cms/homepage-blocks` | session | super_admin | **GO** | resource projection, sort order, Super Admin gate, and read-only state compared by compare_cms_homepage_blocks.py |
| POST | `/api/v1/cms/homepage-blocks` | session | super_admin | **GO** | JSON + multipart validation, HTML sanitization, persistence and upload side effects compared with Laravel |
| PATCH | `/api/v1/cms/homepage-blocks/reorder` | session | super_admin | **GO** | ordering, partial and duplicate ID lists, ID coercion, per-item existence validation, RBAC, response and persisted sort_order compared with Laravel |
| DELETE | `/api/v1/cms/homepage-blocks/{block}` | session | super_admin | **GO** | block and referenced image files, missing IDs, Super Admin gate and response compared with Laravel |
| PATCH | `/api/v1/cms/homepage-blocks/{block}` | session | super_admin | **GO** |  |
| PATCH | `/api/v1/cms/homepage-blocks/{block}/toggle` | session | super_admin | **GO** |  |
| GET | `/api/v1/cms/pages` | session | super_admin | **GO** |  |
| POST | `/api/v1/cms/pages` | session | super_admin | **GO** |  |
| DELETE | `/api/v1/cms/pages/{page}` | session | super_admin | **GO** |  |
| PATCH | `/api/v1/cms/pages/{page}` | session | super_admin | **GO** |  |

## commissions — `commissions` (2/2 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/commissions` | session | admin, agen, keuangan, koordinator-kurir, korsal, kurir, sales, sales-kurir-sub, super_admin | **GO** |  |
| GET | `/api/v1/commissions/summary` | session | admin, agen, keuangan, koordinator-kurir, korsal, kurir, sales, sales-kurir-sub, super_admin | **GO** |  |

## consent-events — `consent-events` (4/4 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| POST | `/api/v1/consent-events` | session | agen, korsal, sales, sales-kurir-sub | **GO** | append-only consent capture: capture scope, order/subject binding, usable-challenge window (15 min, single-use), evidence sha256, scope validation, consent_key idempotency + 409 conflict; compared by compare_consent.py (26/0) |
| POST | `/api/v1/consent-events/{consentEvent}/dispute` | session | admin, agen, konsumen, korsal, sales, sales-kurir-sub, super_admin | **GO** | append-only chain dispute (allowed-from captured/reinstated/withdrawn); compared by compare_consent.py (26/0) |
| POST | `/api/v1/consent-events/{consentEvent}/reinstate` | session | admin, agen, super_admin | **GO** | authorized review reinstate (agen/admin/super_admin, allowed-from disputed); compared by compare_consent.py (26/0) |
| POST | `/api/v1/consent-events/{consentEvent}/withdraw` | session | admin, agen, konsumen, korsal, sales, sales-kurir-sub, super_admin | **GO** | append-only chain withdraw (root-only, allowed-from captured/reinstated); compared by compare_consent.py (26/0) |

## Consent — `consent` (2/2 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| POST | `/api/v1/consent/otp` | session | agen, korsal, sales, sales-kurir-sub | **GO** | REM-005 OTP issue: ConsentAccess capture scope, verified-email channel gate, per-subject issuance serialization + rate limit, HMAC code hash, invalidate prior live challenges; compared by compare_consent.py (26/0) |
| POST | `/api/v1/consent/otp/{challenge}/verify` | session | agen, korsal, sales, sales-kurir-sub | **GO** | OTP verify: issuing-actor binding, attempt counter persists on mismatch, expiry/lock states; compared by compare_consent.py (26/0) |

## CRM — `crm` (16/16 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/crm/customers` | session | admin, agen, korsal, sales, sales-kurir-sub, super_admin | **GO** |  |
| POST | `/api/v1/crm/customers` | session | korsal, sales, sales-kurir-sub | **GO** | CRM write slice: validation, duplicate phone normalization, 201 + resource, audit; 36/0 compared by compare_crm_writes.py |
| GET | `/api/v1/crm/customers/{customer}` | session | admin, agen, korsal, sales, sales-kurir-sub, super_admin | **GO** |  |
| PATCH | `/api/v1/crm/customers/{customer}` | session | korsal, sales, sales-kurir-sub | **GO** | CRM write slice: partial update, no-change PATCH writes no audit row; 36/0 compared by compare_crm_writes.py |
| POST | `/api/v1/crm/customers/{customer}/archive` | session | korsal, sales, sales-kurir-sub | **GO** | CRM write slice: archive/unarchive lifecycle + audit; 36/0 compared by compare_crm_writes.py |
| POST | `/api/v1/crm/customers/{customer}/consent-events` | session | korsal, sales, sales-kurir-sub | **GO** | CRM consent: multipart evidence capture, real evidence file + hash, key idempotency, consumed challenge 422; 17/0 compared by compare_crm_consent.py |
| POST | `/api/v1/crm/customers/{customer}/consent-otp` | session | korsal, sales, sales-kurir-sub | **GO** | CRM consent: channel gates (unlinked 409, unverified email 422, whatsapp 409), challenge row; 17/0 compared by compare_crm_consent.py |
| POST | `/api/v1/crm/customers/{customer}/link` | session | korsal, sales, sales-kurir-sub | **GO** | CRM linking: link consumption, double-link 409/422, link event + audit; 26/0 compared by compare_crm_link.py |
| POST | `/api/v1/crm/customers/{customer}/link-otp` | session | korsal, sales, sales-kurir-sub | **GO** | CRM linking: OTP gates (unknown konsumen, unverified email, channel, rate limit), identity_otp_challenges row; 26/0 compared by compare_crm_link.py |
| POST | `/api/v1/crm/customers/{customer}/orders` | session | agen, korsal, sales, sales-kurir-sub | **GO** | CRM assisted order through canonical order creation; origin-scoped idempotency, owning seller attribution, Agent stock only; compare_crm_assisted_order.py |
| POST | `/api/v1/crm/customers/{customer}/unlink` | session | admin, agen, korsal, sales, sales-kurir-sub | **GO** | CRM linking: unlink scope + event + audit; 26/0 compared by compare_crm_link.py |
| GET | `/api/v1/crm/customers/{customer}/visits` | session | admin, agen, korsal, sales, sales-kurir-sub, super_admin | **GO** |  |
| POST | `/api/v1/crm/customers/{customer}/visits` | session | korsal, sales, sales-kurir-sub | **GO** | CRM write slice: visit validation (status set, date, plan_next_before rule) + audit; 36/0 compared by compare_crm_writes.py |
| GET | `/api/v1/crm/follow-ups` | session | admin, agen, korsal, sales, sales-kurir-sub, super_admin | **GO** |  |
| GET | `/api/v1/crm/link-candidates` | session | korsal, sales, sales-kurir-sub | **GO** | CRM linking: masked candidates, referral reach; 26/0 compared by compare_crm_link.py |
| PATCH | `/api/v1/crm/visits/{visit}` | session | korsal, sales, sales-kurir-sub | **GO** | CRM write slice: visit update + audit; 36/0 compared by compare_crm_writes.py |

## dashboard — `dashboard` (2/2 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/dashboard/kpis` | session | agen, gudang, korsal, kurir, sales, sales-kurir-sub | **GO** | Role-shaped own/team commission, attributed deals, follow-up, canonical stock and courier task KPIs; compare_dashboard_kpis.py |
| GET | `/api/v1/dashboard/summary` | session | admin, agen, korsal, sales, sales-kurir-sub, super_admin | **GO** | Role/branch scoped order, revenue, customer and roster totals with date filters; compare_dashboard_summary.py |

## delivery-refusals — `delivery-refusals` (3/3 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| POST | `/api/v1/delivery-refusals/{refusal}/disposition` | session | koordinator-kurir | **GO** | redelivery/return_to_warehouse: shipping→transit reversal, reservation restore, fresh shipment, aggregate resync; compared by compare_refusals.py (14/0) |
| POST | `/api/v1/delivery-refusals/{refusal}/hand-back` | session | admin, agen, koordinator-kurir, kurir, sales-kurir-sub, super_admin | **GO** | executor custody transfer, idempotent replay, audit; compared by compare_refusals.py (14/0) |
| POST | `/api/v1/delivery-refusals/{refusal}/receive` | session | koordinator-kurir | **GO** | koordinator receipt, state machine handed_back→received, audit; compared by compare_refusals.py (14/0) |

## Dispatch — `dispatch` (9/9 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/dispatch` | session | admin, agen, koordinator-kurir, super_admin | **GO** | Branch-scoped dispatchable standard shipments, matching order and shipment delivery-date/region/payment filters, per-shipment item/resource projection, stock fulfillment and paid-in-full signal; compare_dispatch_queue.py |
| GET | `/api/v1/dispatch/couriers` | session | admin, agen, koordinator-kurir, super_admin | **GO** | branch active couriers, for_returns narrows to kurir, super_admin agent_id gate; compared by compare_dispatch.py |
| GET | `/api/v1/dispatch/expeditions` | session | koordinator-kurir | **GO** | expedition shipments + four readiness gates; koordinator-kurir only; compared by compare_dispatch.py |
| GET | `/api/v1/dispatch/history` | session | admin, agen, koordinator-kurir, super_admin | **GO** | Delivered shipment history, same-Agent and Super Admin agent_id validation, WIB date bounds, courier/region/order search filters, executor and proof projection, capped pagination; compare_dispatch_history.py |
| POST | `/api/v1/dispatch/order-items/{orderItem}/split-batch` | session | koordinator-kurir | **GO** | REM-002 batch split: koordinator gates, window/sub/provider/quantity gates, stock-request demand carve, fresh shipment, proportional courier-fee; compared by compare_dispatch_writes.py (19/0) |
| POST | `/api/v1/dispatch/orders/{order}/request-fulfillment` | session | koordinator-kurir | **GO** | REM-001 coordinator fulfillment request: warehouse-scope gate, outstanding gate, coordinator_* columns, audit, StockRequestResource projection; compared by compare_dispatch_writes.py (19/0) |
| GET | `/api/v1/dispatch/refusals` | session | koordinator-kurir | **GO** | open refusals branch projection + status filter; koordinator-kurir only; compared by compare_dispatch.py |
| GET | `/api/v1/dispatch/regions` | session | admin, agen, koordinator-kurir, super_admin | **GO** | cascading region options from ELIGIBLE dispatch orders only, level composition; compared by compare_dispatch.py |
| GET | `/api/v1/dispatch/returns` | session | admin, agen, koordinator-kurir, super_admin | **GO** | Operational pickup queue, same-Agent and Super Admin agent_id validation, pending/approved return items whose order item is pengembalian, courier projection and capped pagination; compare_dispatch_returns.py |

## Integrations — Google Sheets — `google-sheets` (6/7 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/google-sheets` | session | admin, agen, super_admin | **GO** | Role-scoped destination/config/log dashboard, Admin commission redaction, and DatasetRegistry catalog; compare_google_sheets_index.py |
| POST | `/api/v1/google-sheets/configs` | session | admin, agen, super_admin | **GO** | Dataset/column/filter validation, Admin financial-data restriction, destination ownership and config creation; compare_google_sheets_configs.py |
| DELETE | `/api/v1/google-sheets/configs/{config}` | session | admin, agen, super_admin | **GO** | Scoped model-bound config deletion under shared sync lock; compare_google_sheets_configs.py |
| PUT | `/api/v1/google-sheets/configs/{config}` | session | admin, agen, super_admin | **GO** | Scoped config mutation under Laravel-compatible shared sync lock and validated replacement; compare_google_sheets_configs.py |
| POST | `/api/v1/google-sheets/configs/{config}/sync` | session | admin, agen, super_admin | LARAVEL |  |
| POST | `/api/v1/google-sheets/connection` | session | admin, agen, super_admin | **GO** | Service-account token, spreadsheet metadata probe, branch scope and durable connection status/error update; compare_google_sheets_connection.py |
| POST | `/api/v1/google-sheets/destinations` | session | admin, agen, super_admin | **GO** | Spreadsheet URL/ID normalization, uniqueness, Agent selection validation and caller-bound scope; compare_google_sheets_destinations.py |

## CMS — homepage — `homepage` (1/1 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/homepage` | public | — | **GO** | "bestseller" block returns [] for anonymous visitors (identical to the reference); authenticated ranking not ported |

## Installer — `install` (1/8 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| POST | `/api/v1/install/configure-app` | public | — | LARAVEL |  |
| POST | `/api/v1/install/database/save` | public | — | LARAVEL |  |
| POST | `/api/v1/install/database/test` | public | — | LARAVEL |  |
| POST | `/api/v1/install/finalize` | public | — | LARAVEL |  |
| POST | `/api/v1/install/lock` | public | — | LARAVEL |  |
| GET | `/api/v1/install/requirements` | public | — | LARAVEL |  |
| POST | `/api/v1/install/run` | public | — | LARAVEL |  |
| GET | `/api/v1/install/status` | public | — | **GO** |  |

## Invoices — `invoice` (2/2 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/invoice/config` | session | agen, super_admin | **GO** | Super Admin global or Agen branch-scoped resolved invoice configuration with built-in defaults; compare_invoice_config.py |
| PUT | `/api/v1/invoice/config` | session | agen, super_admin | **GO** | Agen own-scope or Super Admin global display settings, Laravel validation, media existence and defaults resolution; compare_invoice_config.py |

## Courier — `kurir` (6/6 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/kurir/orders` | session | koordinator-kurir, kurir, sales-kurir-sub | **GO** | assignment-only delivery queue (kurir/koordinator own profile standard shipments; SKS own self_sub creator-only + assigned standard), CourierOrderResource projection; compared by compare_kurir.py (17/0) |
| GET | `/api/v1/kurir/reports/delivered` | session | kurir | **GO** | delivered report (terkirim items on own shipments, from/to date filters, own courier commission sum as fee_amount); compared by compare_kurir.py (17/0) |
| GET | `/api/v1/kurir/returns` | session | kurir | **GO** | assignment-only return queue (return_items.courier_id = own profile, pengembalian items); compared by compare_kurir.py (17/0) |
| GET | `/api/v1/kurir/returns/pickup-today` | session | kurir | **GO** | WIB business-date scheduled pickup count (branch + assignment gates); compared by compare_kurir.py (17/0) |
| PATCH | `/api/v1/kurir/returns/{item}/confirm` | session | kurir | **GO** | pengembalian → kembali (received) or → terkirim with return rejected/not_required and returned_quantity reversal; audit; compared by compare_kurir.py (17/0) |
| PATCH | `/api/v1/kurir/returns/{item}/pickup` | session | kurir | **GO** | physical pickup claim: same-branch + assignment gates, condition_note, audit; pickup_scheduled_for Carbon datetime; compared by compare_kurir.py (17/0) |

## Site configuration — languages — `languages` (1/1 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/languages` | public | — | **GO** |  |

## Catalog — management — `manage` (2/2 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/manage/products` | session | admin, agen, super_admin | **GO** | status/category/search/pagination filters, RBAC and per-agent availability compared by compare_products.py |
| GET | `/api/v1/manage/products/{product}` | session | admin, agen, super_admin | **GO** | simple/variation detail, stock projection, missing ID and RBAC compared by compare_products.py |

## Media — `media` (3/3 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/media` | session | super_admin | **GO** | super_admin-only route gate (narrower than the policy — verified), user_avatar excluded, collection filter, pagination; 73/0 compared by compare_media.py |
| POST | `/api/v1/media` | session | — | **GO** | FormRequest validation before MediaPolicy, MIME/extension sniffing, uuid storage, per-collection policy (user_avatar open to every role); 73/0 compared by compare_media.py |
| DELETE | `/api/v1/media/{media}` | session | — | **GO** | policy per collection, avatar owner-only delete, file cleanup, binding-miss 404; 73/0 compared by compare_media.py |

## Notifications — `notifications` (4/4 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/notifications/admin` | session | admin, agen, keuangan, super_admin | **GO** | per-recipient projection, pagination meta, unread filter, strict boolean rule; 97/0 compared by compare_notifications_admin.py |
| POST | `/api/v1/notifications/admin/read-all` | session | admin, agen, keuangan, super_admin | **GO** | idempotent; updated count; 97/0 compared by compare_notifications_admin.py |
| GET | `/api/v1/notifications/admin/unread-count` | session | admin, agen, keuangan, super_admin | **GO** | owned unread count; 97/0 compared by compare_notifications_admin.py |
| POST | `/api/v1/notifications/admin/{id}/read` | session | admin, agen, keuangan, super_admin | **GO** | own rows only, foreign/missing id is a 404, replay keeps read_at; 97/0 compared by compare_notifications_admin.py |

## Orders — item receipts — `order-items` (2/2 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/order-items/{item}/receipts` | session | admin, super_admin | **GO** | REM-07 per-item receipt events index (admin own-agent / super_admin), date + verifier projection; compared by compare_item_receipts.py (16/0) |
| POST | `/api/v1/order-items/{item}/receipts` | session | admin, super_admin | **GO** | REM-07 record actual receipt: Idempotency-Key 8–100, arrived-status gate, date window (not future/before order), time-date consistency, quantity bounded by fulfilled across all events, fingerprint replay/409; compared by compare_item_receipts.py (16/0) |

## Orders — `orders` (25/25 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/orders` | session | — | **GO** | role-scoped list; a kurir is refused; operational roles see the minimized projection; status/payment_status/search/region/delivery_date/paid filters |
| POST | `/api/v1/orders` | session | agen, konsumen, korsal, sales, sales-kurir-sub | **GO** | full chain: canonical target locks, reservation, fee/commission snapshots, voucher redemption, invoice sequence, shipment grouping, payment transaction, Stock Request (COD), initial report snapshot; idempotent replay and total rollback verified |
| GET | `/api/v1/orders/regions` | session | — | **GO** | region facets derived only from the caller's own scoped orders; each level empty until its parent is supplied |
| GET | `/api/v1/orders/{order}` | session | — | **GO** | policy-gated detail (own buyer, branch roles, korsal/sales team + the reassigned-owner leg, gudang queue-only); loads payment_transaction + returns, which the list omits |
| POST | `/api/v1/orders/{order}/assisted-returns` | session | korsal, sales, sales-kurir-sub | **GO** | CRM-origin only; owner Sales/SKS/Korsal, append-only consent current-state + item/quantity quota, public image evidence, transactional return items/order state/audit; compare_assisted_return.py |
| POST | `/api/v1/orders/{order}/cancel` | session | — | **GO** | konsumen/branch-agen/super_admin; reverses reservations (idempotent per item), returns accepted Shipping→Transit, cancels the Stock Request, books the sales reduction, freezes the financial snapshot and creates the refund obligation — all in ONE transaction |
| GET | `/api/v1/orders/{order}/consent-context` | session | admin, agen, konsumen, korsal, sales, sales-kurir-sub, super_admin | **GO** | capture panel context (subject id only to capturing actor; CRM mode + channel availability); compared by compare_consent.py (26/0) |
| GET | `/api/v1/orders/{order}/consent-events` | session | admin, agen, konsumen, korsal, sales, sales-kurir-sub, super_admin | **GO** | consent history per order (order view policy), ConsentEventResource; compared by compare_consent.py (26/0) |
| PUT | `/api/v1/orders/{order}/due-date` | session | admin, keuangan, super_admin | **GO** | admin/keuangan/super_admin; audited old/new + reason; no-op when unchanged |
| GET | `/api/v1/orders/{order}/invoice` | session | — | **GO** | OrderPolicy view+viewInvoice audience, lazy locked invoice issuance, viewer-safe PDF projection and dynamic branch/global configuration; compare_order_invoice.py |
| POST | `/api/v1/orders/{order}/items` | session | admin | **GO** | Admin-only SC-03; order-first locking, Agent reservation, canonical shipment grouping, Stock Request reopen/reconcile, additional-payment ledger and replay/conflict; compare_order_line_addition.py |
| GET | `/api/v1/orders/{order}/items/{item}/cancel-eligibility` | session | admin, agen, super_admin | **GO** | read-only projector: order window, stock source, item status, open refusal, remaining quantity and a pending additional payment decide `eligible` + `reason_code`; max_quantity is fulfilled_quantity only when eligible |
| POST | `/api/v1/orders/{order}/items/{item}/cancel-undelivered` | session | admin, agen, super_admin | **GO** | REM-003 Owner Option A: cancels part of the undelivered/undispatched remainder (also after first dispatch); expected_quantity mismatch is a 409 stale-quantity guard; recomputes the order status forward-only |
| PATCH | `/api/v1/orders/{order}/items/{item}/fulfillment` | session | admin, agen, super_admin | **GO** | super_admin/branch agen/admin while diproses; reduce consumes frozen snapshot units, books the sales reduction + refund obligation (or the needs_verification variant for a provisional-legacy basis), reverses inventory Order→Stock Request→stock; increase restores cancelled units first, reserves stock, then raises the total and creates ONE additional-payment obligation only when the order was fully settled |
| PATCH | `/api/v1/orders/{order}/items/{item}/reschedule` | session | admin, agen, super_admin | **GO** | re-dates a line; LOCKED to Kurir Online + Self Delivery/Sub (expedition/pickup refused), a dikirim order only for the line with an open shortage; a partial quantity splits the line onto a new OrderItem + Shipment, and the shipment is re-homed onto the canonical shipment for the date |
| PATCH | `/api/v1/orders/{order}/payment/cod` | session | keuangan, super_admin | **GO** | keuangan/super_admin; idempotent; single-full-collection undo only |
| POST | `/api/v1/orders/{order}/payment/cod-proof` | session | — | **GO** | COD proof via the media pipeline (cod_payment_proof); partial amount optional |
| POST | `/api/v1/orders/{order}/payment/installment` | session | — | **GO** | transfer/cash; idempotent replay; cash refuses the buyer and requires a receipt |
| POST | `/api/v1/orders/{order}/payment/proof` | session | — | **GO** | bank-transfer proof; konsumen/branch staff/payOnBehalf; MIME-sniffed storage on the private local disk |
| POST | `/api/v1/orders/{order}/payment/settle` | session | keuangan, super_admin | **GO** | keuangan/super_admin; DP only; equivalent replay, material-mismatch 422 |
| POST | `/api/v1/orders/{order}/payment/verify` | session | keuangan, super_admin | **GO** | keuangan/super_admin; self-verification forbidden; verified_amount ≤ outstanding/submitted |
| GET | `/api/v1/orders/{order}/refund-recipient` | session | admin, agen, keuangan, korsal, sales, sales-kurir-sub, super_admin | **GO** | G17-D4 refund-recipient evidence index (destination full only for keuangan/super_admin, masked tail otherwise); compared by compare_recipient.py (13/0) |
| POST | `/api/v1/orders/{order}/refund-recipient` | session | admin, agen, keuangan, korsal, sales, sales-kurir-sub, super_admin | **GO** | append-only recipient record: CRM-origin gate, canRecord authority, method validation, destination required for bank/ewallet, authorization (consent or evidence) gate, consent/return binding, supersedes chain, audit; compared by compare_recipient.py (13/0) |
| POST | `/api/v1/orders/{order}/returns` | session | konsumen | **GO** | Konsumen own-order return request; required public image evidence, item delivery/quantity checks, transactional item/status/refund/audit updates; compare_return_request.py |
| PATCH | `/api/v1/orders/{order}/status` | session | admin, agen, super_admin | **GO** | super_admin/agen/admin bulk diproses→dikirim→terkirim; payment eligibility under the Order lock; Sub items never advance here; first handover stamps the basis |

## CMS — pages — `pages` (1/1 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/pages/{slug}` | public | — | **GO** |  |

## Payments — proofs — `payment-proofs` (1/1 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/payment-proofs/{verification}` | session | — | **GO** | ownership-gated private proof download; Cache-Control private no-store |

## Catalog — products — `products` (17/17 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/products` | public | — | **GO** | anonymous and authenticated Agent/Sub stock projections, source authorization and filters compared by compare_products.py |
| POST | `/api/v1/products` | session | admin, agen, super_admin | **GO** | store validation, SKU uniqueness, sanitizer, variation mode, response and persisted rows compared by compare_products.py |
| DELETE | `/api/v1/products/{id}/permanent` | session | agen, super_admin | **GO** | confirmation, guarded references and hard-delete behavior compared by compare_products.py |
| DELETE | `/api/v1/products/{product}` | session | agen, super_admin | **GO** | soft-delete behavior, authorization and resulting product state compared by compare_products.py |
| PATCH | `/api/v1/products/{product}` | session | admin, agen, super_admin | **GO** | partial updates, immutable variation mode, validation, sanitization, audit and persisted rows compared by compare_products.py |
| GET | `/api/v1/products/{product}/fees` | session | agen, korsal, sales, sales-kurir-sub, super_admin | **GO** | role-scoped fee projection and response compared by compare_products.py |
| PUT | `/api/v1/products/{product}/fees` | session | agen, super_admin | **GO** | fee validation, authorization and persisted fee rows compared by compare_products.py |
| POST | `/api/v1/products/{product}/images` | session | admin, agen, super_admin | **GO** | multipart upload, gallery scope, primary image and stored file compared by compare_products.py |
| PUT | `/api/v1/products/{product}/images/order` | session | admin, agen, super_admin | **GO** | exact gallery membership, response order and persisted sort_order compared by compare_products.py |
| DELETE | `/api/v1/products/{product}/images/{image}` | session | admin, agen, super_admin | **GO** | primary promotion, row removal and uploaded-file cleanup compared by compare_products.py |
| PATCH | `/api/v1/products/{product}/images/{image}` | session | admin, agen, super_admin | **GO** | primary promotion limited to the image gallery compared by compare_products.py |
| POST | `/api/v1/products/{product}/variations` | session | admin, agen, super_admin | **GO** | variation rules, SKU uniqueness, composition attributes and resulting rows compared by compare_products.py |
| DELETE | `/api/v1/products/{product}/variations/{variation}` | session | agen, super_admin | **GO** | variation deletion and persisted rows compared by compare_products.py |
| PATCH | `/api/v1/products/{product}/variations/{variation}` | session | admin, agen, super_admin | **GO** | partial variation update and persisted values compared by compare_products.py |
| GET | `/api/v1/products/{product}/variations/{variation}/fees` | session | agen, korsal, sales, sales-kurir-sub, super_admin | **GO** | variation fee projection and authorization compared by compare_products.py |
| PUT | `/api/v1/products/{product}/variations/{variation}/fees` | session | agen, super_admin | **GO** | variation fee validation, authorization and persisted fee rows compared by compare_products.py |
| GET | `/api/v1/products/{slug}` | public | — | **GO** | anonymous and authenticated Agent/Sub stock projections, source authorization, and missing slug compared by compare_products.py |

## Profile — `profile` (7/11 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| PATCH | `/api/v1/profile` | session | — | **GO** | accumulated field validation, phone normalization, email verification reset, uniqueness, protected fields, self-scope, response and audit compared by compare_profile_update.py |
| GET | `/api/v1/profile/email/verification` | session | — | **GO** | masked current address, verified and active-pending state across roles, and read-only OTP state compared by compare_profile_email_verification_state.py |
| POST | `/api/v1/profile/email/verification` | session | — | LARAVEL |  |
| POST | `/api/v1/profile/email/verification/{challenge}/confirm` | session | — | LARAVEL |  |
| PATCH | `/api/v1/profile/password` | session | — | **GO** |  |
| DELETE | `/api/v1/profile/referral-code` | session | agen, korsal, sales, sales-kurir-sub | **GO** | response, cleared code, and old-only audit projection compared by compare_profile_referral.py |
| PATCH | `/api/v1/profile/referral-code` | session | agen, korsal, sales, sales-kurir-sub | **GO** | format/required/max/unique validation, success projection, self-scope and audit compared by compare_profile_referral.py |
| POST | `/api/v1/profile/referral-code/regenerate` | session | agen, korsal, sales, sales-kurir-sub | **GO** | generated role prefix/code format, response, DB update and audit compared by compare_profile_referral.py |
| GET | `/api/v1/profile/whatsapp` | session | — | **GO** | Self-only masked phone, derived verification proof, active challenge and WhatsApp OTP delivery availability; compare_profile_whatsapp.py |
| POST | `/api/v1/profile/whatsapp/verification` | session | — | LARAVEL |  |
| POST | `/api/v1/profile/whatsapp/verification/{challenge}/confirm` | session | — | LARAVEL |  |

## Promotions — `promo` (8/8 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/promo/discounts` | session | admin, agen, super_admin | **GO** |  |
| POST | `/api/v1/promo/discounts` | session | admin, agen, super_admin | **GO** | a discount MUST name a product; a variation must belong to it (audit A1-17) |
| DELETE | `/api/v1/promo/discounts/{discount}` | session | admin, agen, super_admin | **GO** |  |
| PATCH | `/api/v1/promo/discounts/{discount}` | session | admin, agen, super_admin | **GO** |  |
| GET | `/api/v1/promo/vouchers` | session | admin, agen, super_admin | **GO** |  |
| POST | `/api/v1/promo/vouchers` | session | admin, agen, super_admin | **GO** | code upper-cased/trimmed; super_admin must name the branch |
| DELETE | `/api/v1/promo/vouchers/{voucher}` | session | admin, agen, super_admin | **GO** |  |
| PATCH | `/api/v1/promo/vouchers/{voucher}` | session | admin, agen, super_admin | **GO** |  |

## Referral — `referral` (1/1 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/referral/{code}` | public | — | **GO** |  |

## Regions — `regions` (4/4 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/regions/districts` | public | — | **GO** |  |
| GET | `/api/v1/regions/provinces` | public | — | **GO** |  |
| GET | `/api/v1/regions/regencies` | public | — | **GO** |  |
| GET | `/api/v1/regions/villages` | public | — | **GO** |  |

## Reporting — `reports` (17/17 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/reports/cancellations-refunds` | session | admin, agen, keuangan, super_admin | **GO** | GLM reporting slice compared by compare_reports.py (150/150) |
| GET | `/api/v1/reports/couriers` | session | admin, agen, keuangan, super_admin | **GO** | GLM reporting slice compared by compare_reports.py (150/150) |
| GET | `/api/v1/reports/couriers-per-agent` | session | admin, agen, keuangan, super_admin | **GO** | GLM reporting slice compared by compare_reports.py (150/150) |
| GET | `/api/v1/reports/crm-visits` | session | admin, agen, korsal, sales, sales-kurir-sub, super_admin | **GO** | GLM reporting slice compared by compare_reports.py (150/150) |
| GET | `/api/v1/reports/customers` | session | admin, agen, keuangan, super_admin | **GO** | GLM reporting slice compared by compare_reports.py (150/150) |
| GET | `/api/v1/reports/fees/agent` | session | agen, super_admin | **GO** | GLM reporting slice compared by compare_reports.py (150/150) |
| GET | `/api/v1/reports/fees/courier` | session | admin, agen, keuangan, super_admin | **GO** | GLM reporting slice compared by compare_reports.py (150/150) |
| GET | `/api/v1/reports/fees/sales-korsal` | session | admin, agen, keuangan, super_admin | **GO** | GLM reporting slice compared by compare_reports.py (150/150) |
| GET | `/api/v1/reports/finance-orders` | session | admin, agen, keuangan, super_admin | **GO** | GLM reporting slice compared by compare_reports.py (150/150) |
| GET | `/api/v1/reports/finance-summary` | session | admin, agen, keuangan, super_admin | **GO** | GLM reporting slice compared by compare_reports.py (150/150) |
| GET | `/api/v1/reports/korsal` | session | admin, agen, keuangan, super_admin | **GO** | GLM reporting slice compared by compare_reports.py (150/150) |
| GET | `/api/v1/reports/my-customers` | session | korsal, sales, sales-kurir-sub | **GO** | GLM reporting slice compared by compare_reports.py (150/150) |
| GET | `/api/v1/reports/network-summary` | session | agen, keuangan, super_admin | **GO** | GLM reporting slice compared by compare_reports.py (150/150) |
| GET | `/api/v1/reports/network-transactions` | session | admin, agen, korsal, sales, sales-kurir-sub, super_admin | **GO** | GLM reporting slice compared by compare_reports.py (150/150) |
| GET | `/api/v1/reports/payment-status` | session | admin, agen, keuangan, super_admin | **GO** | GLM reporting slice compared by compare_reports.py (150/150) |
| GET | `/api/v1/reports/sales` | session | admin, agen, korsal, super_admin | **GO** | GLM reporting slice compared by compare_reports.py (150/150) |
| GET | `/api/v1/reports/transactions` | session | admin, agen, korsal, super_admin | **GO** |  |

## Returns — items — `return-items` (2/2 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| PATCH | `/api/v1/return-items/{item}/courier` | session | admin, agen, koordinator-kurir, super_admin | **GO** | return pickup courier assignment (never self-claimed): office/dispatch gates, already_reviewed gate, courier eligibility, idempotent replay, 409 stale-assignment with expected_courier_id, audit; compared by compare_return_assign.py (15/0) |
| POST | `/api/v1/return-items/{returnItem}/receipt-allocations` | session | admin, super_admin | **GO** | Admin own-Agent/Super Admin; approved inspected return only, exact quantity allocation across same-item receipts, capacity locks, one-shot identical replay, conflict on changed replay, audit; compare_return_receipt_allocations.py |

## Site configuration — website — `settings` (1/1 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/settings` | public | — | **GO** |  |

## Shipping & delivery — `shipments` (9/9 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| PATCH | `/api/v1/shipments/{shipment}/courier` | session | koordinator-kurir | **GO** | CourierService::assignCourier: self_sub ban, coordinator-only, eligibility under Order→Shipment lock, SKS executor resolve, SC-03 gate, carve of wholly-unaccepted lines, idempotent replay; compared by compare_courier_assign.py (20/0) |
| GET | `/api/v1/shipments/{shipment}/delivery-verifications` | session | admin, super_admin | **GO** | R-03 append-only verification history (admin/super_admin, same-agent policy → 404), DeliveryVerificationResource; compared by compare_delivery_verifications.py (20/0) |
| POST | `/api/v1/shipments/{shipment}/delivery-verifications` | session | admin, super_admin | **GO** | R-03 record outcome: Idempotency-Key required/≤100, outcome validation, not_delivered gate (delivered_at + item re-derive), append-only, idempotent replay, 409 conflict, audit; compared by compare_delivery_verifications.py (20/0) |
| GET | `/api/v1/shipments/{shipment}/eligible-couriers` | session | koordinator-kurir | **GO** | per-shipment assignable couriers (branch kurir/koordinator profiles + eligible SKS executors), read-only; compared by compare_courier_assign.py (20/0) |
| PATCH | `/api/v1/shipments/{shipment}/expedition-handover` | session | koordinator-kurir | **GO** | REM-003 carrier handover: provider gate (rajaongkir), carrier mismatch, idempotent replay, 409 tracking conflict, evidence media + provider_meta.handover, audit; multipart success via POST _method=PATCH spoofing (PHP does not parse multipart PATCH); compared by compare_handover_receipt.py (17/0) |
| POST | `/api/v1/shipments/{shipment}/items/{orderItem}/refuse` | session | admin, agen, koordinator-kurir, kurir, sales-kurir-sub, super_admin | **GO** | courier on-the-spot refusal: executor gates (policy-vs-service denial split), not_in_delivery, already_open, partial-refusal unit carving (fees/subtotals, stock-request demand move, sibling item), audit; compared by compare_item_refuse.py (15/0) |
| PATCH | `/api/v1/shipments/{shipment}/items/{orderItem}/status` | session | admin, agen, koordinator-kurir, kurir, sales-kurir-sub, super_admin | **GO** | per-ITEM work-unit transition (LOCKED courier work-unit model): one item, siblings untouched; compared by compare_shipment_status.py |
| GET | `/api/v1/shipments/{shipment}/receipt` | session | admin, agen, koordinator-kurir, kurir, sales-kurir-sub, super_admin | **GO** | thermal receipt (pre/post-pickup mode derived from shipped_at), printReceipt policy, read-only + shipment.receipt_printed audit (is_reprint); compared by compare_handover_receipt.py (17/0) |
| PATCH | `/api/v1/shipments/{shipment}/status` | session | admin, agen, koordinator-kurir, kurir, sales-kurir-sub, super_admin | **GO** | bulk diproses→dikirim / dikirim→terkirim transition: executor authorization, refusal guard, handover timestamps, Sub consume, aggregate sync, commissions; compared by compare_shipment_status.py |

## Inventory — agent — `stock` (3/3 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| POST | `/api/v1/stock/adjust` | session | admin, agen | **GO** | 403 for every caller in warehouse-authoritative mode (the default) — identical to the reference; deviation register D-3 |
| GET | `/api/v1/stock/products` | session | admin, agen, gudang, super_admin | **GO** |  |
| GET | `/api/v1/stock/variations` | session | admin, agen, gudang, super_admin | **GO** |  |

## Inventory — sub stock — `sub-stock` (10/10 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/sub-stock/my` | session | sales-kurir-sub | **GO** | reserved always 0 in the verified fixture set (needs an order_item; belongs to the orders checkpoint) |
| GET | `/api/v1/sub-stock/replenishment-targets` | session | sales-kurir-sub | **GO** |  |
| GET | `/api/v1/sub-stock/requests` | session | admin, agen, gudang, sales-kurir-sub | **GO** | list only; create/approve/execute/receive/cancel/reject are mutation domain and not ported |
| POST | `/api/v1/sub-stock/requests` | session | sales-kurir-sub | **GO** | sales-kurir-sub; direction replenish/return; the create response serialises ONLY the insert-time attributes (Eloquent fresh-create shape), an idempotent replay serialises fully |
| GET | `/api/v1/sub-stock/requests/{subStockRequest}` | session | admin, agen, gudang, sales-kurir-sub | **GO** | policy view: super_admin all, requester own, branch roles same agent; denial is a 404 |
| POST | `/api/v1/sub-stock/requests/{subStockRequest}/approve` | session | admin | **GO** | admin; requested→approved only |
| POST | `/api/v1/sub-stock/requests/{subStockRequest}/cancel` | session | sales-kurir-sub | **GO** | the requester only, while still requested |
| POST | `/api/v1/sub-stock/requests/{subStockRequest}/execute` | session | gudang | **GO** | gudang — the ONLY stock-moving step, idempotent; Sub Location EXCLUSIVE lock then buckets; replenish passes the Transit-reservation guard, return refuses below active Sub reservations |
| POST | `/api/v1/sub-stock/requests/{subStockRequest}/receive` | session | sales-kurir-sub | **GO** | the requester, replenish only; closes the handover |
| POST | `/api/v1/sub-stock/requests/{subStockRequest}/reject` | session | admin | **GO** | admin; reason required |

## User management — `users` (8/8 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/users` | session | admin, agen, keuangan, koordinator-kurir, korsal, kurir, sales, sales-kurir-sub, super_admin | **GO** |  |
| POST | `/api/v1/users` | session | agen, koordinator-kurir, korsal, super_admin | **GO** |  |
| DELETE | `/api/v1/users/{user}` | session | admin, agen, keuangan, koordinator-kurir, korsal, kurir, sales, sales-kurir-sub, super_admin | **GO** |  |
| GET | `/api/v1/users/{user}` | session | admin, agen, keuangan, koordinator-kurir, korsal, kurir, sales, sales-kurir-sub, super_admin | **GO** |  |
| PATCH | `/api/v1/users/{user}` | session | admin, agen, keuangan, koordinator-kurir, korsal, kurir, sales, sales-kurir-sub, super_admin | **GO** |  |
| PATCH | `/api/v1/users/{user}/convert-to-sales-kurir` | session | admin, agen, keuangan, koordinator-kurir, korsal, kurir, sales, sales-kurir-sub, super_admin | **GO** |  |
| PATCH | `/api/v1/users/{user}/convert-to-sales-kurir-sub` | session | admin, agen, keuangan, koordinator-kurir, korsal, kurir, sales, sales-kurir-sub, super_admin | **GO** |  |
| PATCH | `/api/v1/users/{user}/reassign-referral` | session | admin, agen, keuangan, koordinator-kurir, korsal, kurir, sales, sales-kurir-sub, super_admin | **GO** |  |

## Inventory — warehouse stock — `warehouse-stock` (1/1 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/warehouse-stock` | session | admin, agen, gudang, super_admin | **GO** | Laravel paginates without ORDER BY (upstream defect); Go orders by id, so cross-page SET is compared instead of page bytes |

## Inventory — warehouse — `warehouse` (49/49 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/warehouse/fulfillment-proposals` | session | admin, gudang, koordinator-kurir | **GO** | index loads product.images (key renders even when empty), unlike the propose/settle responses |
| GET | `/api/v1/warehouse/fulfillment-proposals/{proposal}` | session | admin, gudang, koordinator-kurir | **GO** | same detail shape as the index |
| POST | `/api/v1/warehouse/fulfillment-proposals/{proposal}/approve` | session | admin | **GO** | admin EXCEPTION path; a reason (min 5 chars) is mandatory and audited; settles the whole prepared quantity |
| POST | `/api/v1/warehouse/fulfillment-proposals/{proposal}/receive` | session | koordinator-kurir | **GO** | koordinator-kurir — the routine settlement; accepted+short+damaged must equal the prepared quantity; stock moves transit→shipping for accepted units only; damaged units are held on the line |
| POST | `/api/v1/warehouse/fulfillment-proposals/{proposal}/reject` | session | admin | **GO** | admin; moves no stock |
| GET | `/api/v1/warehouse/handovers/{handover}` | session | admin, agen, gudang, super_admin | **GO** | the full model with transfer.items.product/variation and both actors (users serialize with NO hidden fields here) |
| GET | `/api/v1/warehouse/handovers/{handover}/print` | session | admin, agen, gudang, super_admin | **GO** | the A4 print page, HTML; the Blade template is ported 1:1 including its escaping |
| GET | `/api/v1/warehouse/opnames` | session | admin, agen, gudang, super_admin | **GO** |  |
| POST | `/api/v1/warehouse/opnames` | session | gudang | **GO** | snapshots system_quantity + stock id + physical revision per target |
| GET | `/api/v1/warehouse/opnames/{opname}` | session | admin, agen, gudang, super_admin | **GO** |  |
| POST | `/api/v1/warehouse/opnames/{opname}/approve` | session | admin | **GO** | writes the correction; 409 when the stock changed after counting (quantity OR revision); idempotent |
| POST | `/api/v1/warehouse/opnames/{opname}/cancel` | session | gudang | **GO** |  |
| PATCH | `/api/v1/warehouse/opnames/{opname}/count` | session | gudang | **GO** |  |
| POST | `/api/v1/warehouse/opnames/{opname}/reject` | session | admin | **GO** |  |
| POST | `/api/v1/warehouse/opnames/{opname}/submit` | session | gudang | **GO** | refuses while any item is uncounted |
| GET | `/api/v1/warehouse/orders/diproses` | session | gudang | **GO** | Gudang branch queue with requested-order priority, no-courier/self-delivery exclusion, warehouse OrderResource projection and fulfillment-request overlay; compare_warehouse_orders.py |
| GET | `/api/v1/warehouse/orders/{order}/stock-request` | session | gudang | **GO** | same-branch warehouse-scope guard, StockRequestResource/product+variation projection and Transit/Shipping/reserved buckets; compare_warehouse_orders.py |
| GET | `/api/v1/warehouse/returns` | session | gudang | **GO** | approved and uninspected return lines only, same-branch queue, ReturnRequestResource projection and pagination; compare_warehouse_orders.py |
| POST | `/api/v1/warehouse/returns/{item}/finalize` | session | admin | **GO** | Admin same-branch idempotent disposition; good units restock Agent Transit or original active Sub Location with return_restock audit, damaged-only adds no stock; compare_warehouse_orders.py |
| POST | `/api/v1/warehouse/returns/{item}/inspect` | session | gudang | **GO** | Gudang same-branch validation and transactional received/good/damaged inspection update; compare_warehouse_orders.py |
| GET | `/api/v1/warehouse/sellable` | session | admin, agen, gudang, super_admin | **GO** | Go answers 422 when neither product_id nor variation_id is given; Laravel 500s there (upstream defect, documented divergence) |
| GET | `/api/v1/warehouse/settings` | session | admin, agen, gudang, super_admin | **GO** | reproduces Laravel's firstOrCreate side effect (a GET may create the row) |
| PATCH | `/api/v1/warehouse/settings/factory-plan` | session | admin | **GO** |  |
| GET | `/api/v1/warehouse/stock-addition-requests` | session | admin, gudang | **GO** | agent-scoped list; sub_location is loaded here (unlike show) |
| POST | `/api/v1/warehouse/stock-addition-requests` | session | gudang | **GO** | gudang only; target is product XOR variation; quantity > 0 |
| GET | `/api/v1/warehouse/stock-addition-requests/{warehouseStockRequest}` | session | admin, gudang | **GO** | show does NOT eager-load sub_location, so that key is absent — the reference's own asymmetry |
| POST | `/api/v1/warehouse/stock-addition-requests/{warehouseStockRequest}/approve` | session | admin | **GO** | admin only; idempotent (a second approve moves no stock); transit receipt or factory_plan change under the commitment guard |
| POST | `/api/v1/warehouse/stock-addition-requests/{warehouseStockRequest}/reject` | session | admin | **GO** | admin only; leaves stock untouched; a missing id answers the endpoint-not-found sentence |
| GET | `/api/v1/warehouse/stock-cards` | session | admin, gudang | **GO** | reads warehouse buckets only (no legacy fallback) — same asymmetry as the reference |
| POST | `/api/v1/warehouse/stock-request-items/{item}/resolve-discrepancy` | session | admin | **GO** | F07: write_off reduces Transit by an adjustment movement and lifts the hold under a UUID idempotency key (retry answers 409); restock is refused pending an Owner inspection rule |
| POST | `/api/v1/warehouse/stock-requests/{stockRequest}/fulfill` | session | gudang | **GO** | the reference REFUSES direct Gudang fulfilment (the proposal gate is the only path); reproduced verbatim |
| POST | `/api/v1/warehouse/stock-requests/{stockRequest}/proposals` | session | gudang | **GO** | gudang; multipart with a REQUIRED proof image when goods are handed over; shortage-only reports carry none and create no proposal row |
| POST | `/api/v1/warehouse/sub-adjustment-requests` | session | gudang | **GO** | gudang only; a negative delta may never cut Sub physical below the active reservations |
| GET | `/api/v1/warehouse/sub-locations` | session | admin, agen, gudang, super_admin | **GO** |  |
| POST | `/api/v1/warehouse/sub-locations` | session | admin, agen | **GO** | owner must be an active Sales-Kurir-Sub of the network that owns no other location |
| GET | `/api/v1/warehouse/sub-locations/eligible-owners` | session | admin, agen | **GO** | active Sales-Kurir-Sub without a sub-location |
| GET | `/api/v1/warehouse/sub-locations/{subLocation}` | session | admin, agen, gudang, super_admin | **GO** |  |
| PATCH | `/api/v1/warehouse/sub-locations/{subLocation}` | session | admin, agen, gudang | **GO** |  |
| POST | `/api/v1/warehouse/sub-locations/{subLocation}/assign-owner` | session | admin, agen | **GO** | unowned + active only; owner row locked before the location on create |
| POST | `/api/v1/warehouse/sub-locations/{subLocation}/deactivate` | session | admin, agen, gudang | **GO** | refused while physical stock remains; releases the owner and remembers them in previous_owner_user_id |
| GET | `/api/v1/warehouse/sub-locations/{subLocation}/stock-cards` | session | admin, gudang | **GO** |  |
| GET | `/api/v1/warehouse/sub-locations/{subLocation}/stocks` | session | admin, agen, gudang, super_admin | **GO** |  |
| GET | `/api/v1/warehouse/transfers` | session | admin, agen, gudang, super_admin | **GO** |  |
| POST | `/api/v1/warehouse/transfers` | session | gudang | **GO** | physical buckets only; factory_plan needs /transfers/plan-to-transit (not ported) |
| POST | `/api/v1/warehouse/transfers/plan-to-transit` | session | gudang | **GO** | requires the factory plan to be enabled |
| GET | `/api/v1/warehouse/transfers/{transfer}` | session | admin, agen, gudang, super_admin | **GO** |  |
| POST | `/api/v1/warehouse/transfers/{transfer}/approve` | session | admin | **GO** | executes + writes the handover; canonical lock order preserved, verified by two-connection tests |
| POST | `/api/v1/warehouse/transfers/{transfer}/cancel` | session | gudang | **GO** |  |
| POST | `/api/v1/warehouse/transfers/{transfer}/reject` | session | admin | **GO** |  |

## Totals

| Metric | Value |
|---|---|
| Laravel `/api/v1` routes on this baseline | 375 |
| Served by Go now | 353 (94.1%) |
|   of which fully GO | 353 |
|   of which PARTIAL (incomplete behaviour) | 0 |
| Still Laravel-only | 22 |
| Areas fully ported | 38 / 45 |
