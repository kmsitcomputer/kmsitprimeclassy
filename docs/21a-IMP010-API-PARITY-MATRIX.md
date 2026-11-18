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

## Admin operations — `admin` (7/59 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/admin/additional-payments` | session | admin, agen, keuangan, super_admin | LARAVEL |  |
| PATCH | `/api/v1/admin/additional-payments/{payment}/status` | session | keuangan, super_admin | LARAVEL |  |
| GET | `/api/v1/admin/agents` | session | super_admin | LARAVEL |  |
| POST | `/api/v1/admin/agents` | session | super_admin | LARAVEL |  |
| DELETE | `/api/v1/admin/agents/{agentProfile}` | session | super_admin | LARAVEL |  |
| PATCH | `/api/v1/admin/agents/{agentProfile}` | session | super_admin | LARAVEL |  |
| PATCH | `/api/v1/admin/agents/{agent}/toggle-status` | session | super_admin | LARAVEL |  |
| GET | `/api/v1/admin/audit-logs` | session | super_admin | LARAVEL |  |
| PATCH | `/api/v1/admin/cod-payment-proofs/{codPaymentProof}/confirm` | session | keuangan, super_admin | LARAVEL |  |
| GET | `/api/v1/admin/google-auth` | session | super_admin | LARAVEL |  |
| PUT | `/api/v1/admin/google-auth` | session | super_admin | LARAVEL |  |
| GET | `/api/v1/admin/integrations/smtp` | session | super_admin | LARAVEL |  |
| PUT | `/api/v1/admin/integrations/smtp` | session | super_admin | LARAVEL |  |
| POST | `/api/v1/admin/integrations/smtp/test-connection` | session | super_admin | LARAVEL |  |
| POST | `/api/v1/admin/integrations/smtp/test-email` | session | super_admin | LARAVEL |  |
| GET | `/api/v1/admin/integrations/whatsapp` | session | super_admin | LARAVEL |  |
| PUT | `/api/v1/admin/integrations/whatsapp` | session | super_admin | LARAVEL |  |
| GET | `/api/v1/admin/integrations/whatsapp/messages` | session | super_admin | LARAVEL |  |
| POST | `/api/v1/admin/integrations/whatsapp/messages/{message}/retry` | session | super_admin | LARAVEL |  |
| DELETE | `/api/v1/admin/integrations/whatsapp/templates/{event}` | session | super_admin | LARAVEL |  |
| PUT | `/api/v1/admin/integrations/whatsapp/templates/{event}` | session | super_admin | LARAVEL |  |
| POST | `/api/v1/admin/integrations/whatsapp/templates/{event}/preview` | session | super_admin | LARAVEL |  |
| POST | `/api/v1/admin/integrations/whatsapp/test-connection` | session | super_admin | LARAVEL |  |
| POST | `/api/v1/admin/integrations/whatsapp/test-message` | session | super_admin | LARAVEL |  |
| GET | `/api/v1/admin/languages` | session | super_admin | **GO** | admin listing: every language, active or not |
| POST | `/api/v1/admin/languages` | session | super_admin | **GO** |  |
| PATCH | `/api/v1/admin/languages/{language}` | session | super_admin | **GO** |  |
| PATCH | `/api/v1/admin/languages/{language}/set-default` | session | super_admin | **GO** | exactly one default; the target must be active |
| PATCH | `/api/v1/admin/languages/{language}/toggle-active` | session | super_admin | **GO** | the default language can never be deactivated |
| GET | `/api/v1/admin/order-refunds` | session | admin, agen, keuangan, super_admin | LARAVEL |  |
| PATCH | `/api/v1/admin/order-refunds/{adjustment}/status` | session | keuangan, super_admin | LARAVEL |  |
| POST | `/api/v1/admin/order-sales-reductions/{reduction}/reinstate` | session | agen, keuangan, super_admin | LARAVEL |  |
| POST | `/api/v1/admin/orders/{order}/cancellation/finalize` | session | agen, keuangan, super_admin | LARAVEL |  |
| GET | `/api/v1/admin/orders/{order}/refunds` | session | admin, agen, keuangan, super_admin | LARAVEL |  |
| POST | `/api/v1/admin/refund-attempts/{attempt}/cancel` | session | agen, keuangan, super_admin | LARAVEL |  |
| POST | `/api/v1/admin/refund-attempts/{attempt}/complete` | session | agen, keuangan, super_admin | LARAVEL |  |
| POST | `/api/v1/admin/refund-attempts/{attempt}/fail` | session | agen, keuangan, super_admin | LARAVEL |  |
| POST | `/api/v1/admin/refund-attempts/{attempt}/initiate` | session | agen, keuangan, super_admin | LARAVEL |  |
| POST | `/api/v1/admin/refund-attempts/{attempt}/reconcile` | session | agen, keuangan, super_admin | LARAVEL |  |
| POST | `/api/v1/admin/refund-attempts/{attempt}/unknown` | session | agen, keuangan, super_admin | LARAVEL |  |
| POST | `/api/v1/admin/refund-obligations/{obligation}/approve` | session | agen, keuangan, super_admin | LARAVEL |  |
| POST | `/api/v1/admin/refund-obligations/{obligation}/attempts` | session | agen, keuangan, super_admin | LARAVEL |  |
| POST | `/api/v1/admin/refund-obligations/{obligation}/deferred/materialize` | session | agen, keuangan, super_admin | LARAVEL |  |
| POST | `/api/v1/admin/refund-obligations/{obligation}/deferred/request` | session | agen, keuangan, super_admin | LARAVEL |  |
| POST | `/api/v1/admin/refund-obligations/{obligation}/needs-verification/resolve` | session | agen, keuangan, super_admin | LARAVEL |  |
| GET | `/api/v1/admin/regions/export` | session | super_admin | LARAVEL |  |
| POST | `/api/v1/admin/regions/import` | session | super_admin | LARAVEL |  |
| PATCH | `/api/v1/admin/return-items/{item}/refund` | session | keuangan, super_admin | LARAVEL |  |
| GET | `/api/v1/admin/returns` | session | admin, agen, super_admin | LARAVEL |  |
| GET | `/api/v1/admin/returns/{return}` | session | admin, agen, super_admin | LARAVEL |  |
| PATCH | `/api/v1/admin/returns/{return}/pickup-schedule` | session | admin, agen, super_admin | LARAVEL |  |
| PATCH | `/api/v1/admin/returns/{return}/review` | session | admin, agen, super_admin | LARAVEL |  |
| GET | `/api/v1/admin/settings` | session | super_admin | **GO** | identical to the public read (single service in the reference) |
| PUT | `/api/v1/admin/settings` | session | super_admin | **GO** | unknown keys ignored, JSON keys encoded, returns the resolved settings |
| GET | `/api/v1/admin/shipping-couriers` | session | super_admin | LARAVEL |  |
| POST | `/api/v1/admin/shipping-couriers` | session | super_admin | LARAVEL |  |
| PATCH | `/api/v1/admin/shipping-couriers/{shippingCourier}/toggle` | session | super_admin | LARAVEL |  |
| GET | `/api/v1/admin/shipping-providers` | session | super_admin | LARAVEL |  |
| PATCH | `/api/v1/admin/shipping-providers/{provider}/toggle` | session | super_admin | LARAVEL |  |

## agent — `agent` (0/33 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/agent/google-auth` | session | admin, agen | LARAVEL |  |
| PUT | `/api/v1/agent/google-auth` | session | admin, agen | LARAVEL |  |
| GET | `/api/v1/agent/payment-methods` | session | admin, agen | LARAVEL |  |
| PUT | `/api/v1/agent/payment-methods/{method}/config` | session | admin, agen | LARAVEL |  |
| PATCH | `/api/v1/agent/payment-methods/{method}/toggle` | session | admin, agen | LARAVEL |  |
| GET | `/api/v1/agent/shipping-providers` | session | agen | LARAVEL |  |
| PUT | `/api/v1/agent/shipping-providers/{provider}/config` | session | agen | LARAVEL |  |
| GET | `/api/v1/agent/shipping-providers/{provider}/couriers` | session | agen | LARAVEL |  |
| PUT | `/api/v1/agent/shipping-providers/{provider}/couriers` | session | agen | LARAVEL |  |
| POST | `/api/v1/agent/shipping-providers/{provider}/destinations` | session | agen | LARAVEL |  |
| POST | `/api/v1/agent/shipping-providers/{provider}/test` | session | agen | LARAVEL |  |
| PATCH | `/api/v1/agent/shipping-providers/{provider}/toggle` | session | agen | LARAVEL |  |
| GET | `/api/v1/agent/shipping-tariff` | session | agen | LARAVEL |  |
| PUT | `/api/v1/agent/shipping-tariff` | session | agen | LARAVEL |  |
| GET | `/api/v1/agent/store-profile` | session | agen | LARAVEL |  |
| PUT | `/api/v1/agent/store-profile` | session | agen | LARAVEL |  |
| GET | `/api/v1/agent/whatsapp` | session | admin, agen | LARAVEL |  |
| POST | `/api/v1/agent/whatsapp/device` | session | admin, agen | LARAVEL |  |
| GET | `/api/v1/agent/whatsapp/events` | session | admin, agen | LARAVEL |  |
| PUT | `/api/v1/agent/whatsapp/events/{event}` | session | admin, agen | LARAVEL |  |
| POST | `/api/v1/agent/whatsapp/events/{event}/preview` | session | admin, agen | LARAVEL |  |
| DELETE | `/api/v1/agent/whatsapp/events/{event}/template` | session | admin, agen | LARAVEL |  |
| POST | `/api/v1/agent/whatsapp/logout` | session | admin, agen | LARAVEL |  |
| GET | `/api/v1/agent/whatsapp/messages` | session | admin, agen | LARAVEL |  |
| POST | `/api/v1/agent/whatsapp/pair/code` | session | admin, agen | LARAVEL |  |
| POST | `/api/v1/agent/whatsapp/pair/qr` | session | admin, agen | LARAVEL |  |
| POST | `/api/v1/agent/whatsapp/reconnect` | session | admin, agen | LARAVEL |  |
| POST | `/api/v1/agent/whatsapp/replace` | session | admin, agen | LARAVEL |  |
| GET | `/api/v1/agent/whatsapp/scope` | session | admin, agen | LARAVEL |  |
| GET | `/api/v1/agent/whatsapp/test-numbers` | session | admin, agen | LARAVEL |  |
| POST | `/api/v1/agent/whatsapp/test-numbers` | session | admin, agen | LARAVEL |  |
| DELETE | `/api/v1/agent/whatsapp/test-numbers/{id}` | session | admin, agen | LARAVEL |  |
| POST | `/api/v1/agent/whatsapp/test-send` | session | admin, agen | LARAVEL |  |

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
| POST | `/api/v1/auth/register` | public | — | **PARTIAL** | user + referral chain + closures; welcome e-mail/WA notification not emitted (queue bridge outstanding) |
| POST | `/api/v1/auth/reset-password` | public | — | LARAVEL |  |

## Catalog — bestsellers — `bestsellers` (0/1 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/bestsellers` | public | — | LARAVEL |  |

## catalog — `catalog` (0/3 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/catalog/export` | session | admin, agen, super_admin | LARAVEL |  |
| POST | `/api/v1/catalog/imports` | session | admin, agen, super_admin | LARAVEL |  |
| POST | `/api/v1/catalog/imports/preview` | session | admin, agen, super_admin | LARAVEL |  |

## Catalog — categories — `categories` (1/4 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/categories` | public | — | **GO** |  |
| POST | `/api/v1/categories` | session | admin, agen, super_admin | LARAVEL |  |
| DELETE | `/api/v1/categories/{category}` | session | super_admin | LARAVEL |  |
| PATCH | `/api/v1/categories/{category}` | session | super_admin | LARAVEL |  |

## Checkout — `checkout` (1/4 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| POST | `/api/v1/checkout/courier-options` | session | agen, konsumen, korsal, sales, sales-kurir-sub | LARAVEL |  |
| POST | `/api/v1/checkout/geocode` | session | agen, konsumen, korsal, sales, sales-kurir-sub | LARAVEL |  |
| POST | `/api/v1/checkout/quote` | session | agen, konsumen, korsal, sales, sales-kurir-sub | LARAVEL |  |
| GET | `/api/v1/checkout/steps` | public | — | **GO** | public; dynamic step list with role-derived flags, agent provider switches, KMSIT fallback and the konsumen payment filter |

## CMS — admin — `cms` (0/14 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/cms/articles` | session | super_admin | LARAVEL |  |
| POST | `/api/v1/cms/articles` | session | super_admin | LARAVEL |  |
| DELETE | `/api/v1/cms/articles/{article}` | session | super_admin | LARAVEL |  |
| PATCH | `/api/v1/cms/articles/{article}` | session | super_admin | LARAVEL |  |
| GET | `/api/v1/cms/homepage-blocks` | session | super_admin | LARAVEL |  |
| POST | `/api/v1/cms/homepage-blocks` | session | super_admin | LARAVEL |  |
| PATCH | `/api/v1/cms/homepage-blocks/reorder` | session | super_admin | LARAVEL |  |
| DELETE | `/api/v1/cms/homepage-blocks/{block}` | session | super_admin | LARAVEL |  |
| PATCH | `/api/v1/cms/homepage-blocks/{block}` | session | super_admin | LARAVEL |  |
| PATCH | `/api/v1/cms/homepage-blocks/{block}/toggle` | session | super_admin | LARAVEL |  |
| GET | `/api/v1/cms/pages` | session | super_admin | LARAVEL |  |
| POST | `/api/v1/cms/pages` | session | super_admin | LARAVEL |  |
| DELETE | `/api/v1/cms/pages/{page}` | session | super_admin | LARAVEL |  |
| PATCH | `/api/v1/cms/pages/{page}` | session | super_admin | LARAVEL |  |

## commissions — `commissions` (0/2 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/commissions` | session | admin, agen, keuangan, koordinator-kurir, korsal, kurir, sales, sales-kurir-sub, super_admin | LARAVEL |  |
| GET | `/api/v1/commissions/summary` | session | admin, agen, keuangan, koordinator-kurir, korsal, kurir, sales, sales-kurir-sub, super_admin | LARAVEL |  |

## consent-events — `consent-events` (0/4 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| POST | `/api/v1/consent-events` | session | agen, korsal, sales, sales-kurir-sub | LARAVEL |  |
| POST | `/api/v1/consent-events/{consentEvent}/dispute` | session | admin, agen, konsumen, korsal, sales, sales-kurir-sub, super_admin | LARAVEL |  |
| POST | `/api/v1/consent-events/{consentEvent}/reinstate` | session | admin, agen, super_admin | LARAVEL |  |
| POST | `/api/v1/consent-events/{consentEvent}/withdraw` | session | admin, agen, konsumen, korsal, sales, sales-kurir-sub, super_admin | LARAVEL |  |

## Consent — `consent` (0/2 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| POST | `/api/v1/consent/otp` | session | agen, korsal, sales, sales-kurir-sub | LARAVEL |  |
| POST | `/api/v1/consent/otp/{challenge}/verify` | session | agen, korsal, sales, sales-kurir-sub | LARAVEL |  |

## CRM — `crm` (0/16 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/crm/customers` | session | admin, agen, korsal, sales, sales-kurir-sub, super_admin | LARAVEL |  |
| POST | `/api/v1/crm/customers` | session | korsal, sales, sales-kurir-sub | LARAVEL |  |
| GET | `/api/v1/crm/customers/{customer}` | session | admin, agen, korsal, sales, sales-kurir-sub, super_admin | LARAVEL |  |
| PATCH | `/api/v1/crm/customers/{customer}` | session | korsal, sales, sales-kurir-sub | LARAVEL |  |
| POST | `/api/v1/crm/customers/{customer}/archive` | session | korsal, sales, sales-kurir-sub | LARAVEL |  |
| POST | `/api/v1/crm/customers/{customer}/consent-events` | session | korsal, sales, sales-kurir-sub | LARAVEL |  |
| POST | `/api/v1/crm/customers/{customer}/consent-otp` | session | korsal, sales, sales-kurir-sub | LARAVEL |  |
| POST | `/api/v1/crm/customers/{customer}/link` | session | korsal, sales, sales-kurir-sub | LARAVEL |  |
| POST | `/api/v1/crm/customers/{customer}/link-otp` | session | korsal, sales, sales-kurir-sub | LARAVEL |  |
| POST | `/api/v1/crm/customers/{customer}/orders` | session | agen, korsal, sales, sales-kurir-sub | LARAVEL |  |
| POST | `/api/v1/crm/customers/{customer}/unlink` | session | admin, agen, korsal, sales, sales-kurir-sub | LARAVEL |  |
| GET | `/api/v1/crm/customers/{customer}/visits` | session | admin, agen, korsal, sales, sales-kurir-sub, super_admin | LARAVEL |  |
| POST | `/api/v1/crm/customers/{customer}/visits` | session | korsal, sales, sales-kurir-sub | LARAVEL |  |
| GET | `/api/v1/crm/follow-ups` | session | admin, agen, korsal, sales, sales-kurir-sub, super_admin | LARAVEL |  |
| GET | `/api/v1/crm/link-candidates` | session | korsal, sales, sales-kurir-sub | LARAVEL |  |
| PATCH | `/api/v1/crm/visits/{visit}` | session | korsal, sales, sales-kurir-sub | LARAVEL |  |

## dashboard — `dashboard` (0/2 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/dashboard/kpis` | session | agen, gudang, korsal, kurir, sales, sales-kurir-sub | LARAVEL |  |
| GET | `/api/v1/dashboard/summary` | session | admin, agen, korsal, sales, sales-kurir-sub, super_admin | LARAVEL |  |

## delivery-refusals — `delivery-refusals` (0/3 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| POST | `/api/v1/delivery-refusals/{refusal}/disposition` | session | koordinator-kurir | LARAVEL |  |
| POST | `/api/v1/delivery-refusals/{refusal}/hand-back` | session | admin, agen, koordinator-kurir, kurir, sales-kurir-sub, super_admin | LARAVEL |  |
| POST | `/api/v1/delivery-refusals/{refusal}/receive` | session | koordinator-kurir | LARAVEL |  |

## Dispatch — `dispatch` (0/9 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/dispatch` | session | admin, agen, koordinator-kurir, super_admin | LARAVEL |  |
| GET | `/api/v1/dispatch/couriers` | session | admin, agen, koordinator-kurir, super_admin | LARAVEL |  |
| GET | `/api/v1/dispatch/expeditions` | session | koordinator-kurir | LARAVEL |  |
| GET | `/api/v1/dispatch/history` | session | admin, agen, koordinator-kurir, super_admin | LARAVEL |  |
| POST | `/api/v1/dispatch/order-items/{orderItem}/split-batch` | session | koordinator-kurir | LARAVEL |  |
| POST | `/api/v1/dispatch/orders/{order}/request-fulfillment` | session | koordinator-kurir | LARAVEL |  |
| GET | `/api/v1/dispatch/refusals` | session | koordinator-kurir | LARAVEL |  |
| GET | `/api/v1/dispatch/regions` | session | admin, agen, koordinator-kurir, super_admin | LARAVEL |  |
| GET | `/api/v1/dispatch/returns` | session | admin, agen, koordinator-kurir, super_admin | LARAVEL |  |

## Integrations — Google Sheets — `google-sheets` (0/7 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/google-sheets` | session | admin, agen, super_admin | LARAVEL |  |
| POST | `/api/v1/google-sheets/configs` | session | admin, agen, super_admin | LARAVEL |  |
| DELETE | `/api/v1/google-sheets/configs/{config}` | session | admin, agen, super_admin | LARAVEL |  |
| PUT | `/api/v1/google-sheets/configs/{config}` | session | admin, agen, super_admin | LARAVEL |  |
| POST | `/api/v1/google-sheets/configs/{config}/sync` | session | admin, agen, super_admin | LARAVEL |  |
| POST | `/api/v1/google-sheets/connection` | session | admin, agen, super_admin | LARAVEL |  |
| POST | `/api/v1/google-sheets/destinations` | session | admin, agen, super_admin | LARAVEL |  |

## CMS — homepage — `homepage` (1/1 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/homepage` | public | — | **PARTIAL** | "bestseller" block returns [] for anonymous visitors (identical to the reference); authenticated ranking not ported |

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

## Invoices — `invoice` (0/2 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/invoice/config` | session | agen, super_admin | LARAVEL |  |
| PUT | `/api/v1/invoice/config` | session | agen, super_admin | LARAVEL |  |

## Courier — `kurir` (0/6 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/kurir/orders` | session | koordinator-kurir, kurir, sales-kurir-sub | LARAVEL |  |
| GET | `/api/v1/kurir/reports/delivered` | session | kurir | LARAVEL |  |
| GET | `/api/v1/kurir/returns` | session | kurir | LARAVEL |  |
| GET | `/api/v1/kurir/returns/pickup-today` | session | kurir | LARAVEL |  |
| PATCH | `/api/v1/kurir/returns/{item}/confirm` | session | kurir | LARAVEL |  |
| PATCH | `/api/v1/kurir/returns/{item}/pickup` | session | kurir | LARAVEL |  |

## Site configuration — languages — `languages` (1/1 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/languages` | public | — | **GO** |  |

## Catalog — management — `manage` (0/2 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/manage/products` | session | admin, agen, super_admin | LARAVEL |  |
| GET | `/api/v1/manage/products/{product}` | session | admin, agen, super_admin | LARAVEL |  |

## Media — `media` (0/3 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/media` | session | super_admin | LARAVEL |  |
| POST | `/api/v1/media` | session | — | LARAVEL |  |
| DELETE | `/api/v1/media/{media}` | session | — | LARAVEL |  |

## Notifications — `notifications` (0/4 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/notifications/admin` | session | admin, agen, keuangan, super_admin | LARAVEL |  |
| POST | `/api/v1/notifications/admin/read-all` | session | admin, agen, keuangan, super_admin | LARAVEL |  |
| GET | `/api/v1/notifications/admin/unread-count` | session | admin, agen, keuangan, super_admin | LARAVEL |  |
| POST | `/api/v1/notifications/admin/{id}/read` | session | admin, agen, keuangan, super_admin | LARAVEL |  |

## Orders — item receipts — `order-items` (0/2 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/order-items/{item}/receipts` | session | admin, super_admin | LARAVEL |  |
| POST | `/api/v1/order-items/{item}/receipts` | session | admin, super_admin | LARAVEL |  |

## Orders — `orders` (0/25 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/orders` | session | — | LARAVEL |  |
| POST | `/api/v1/orders` | session | agen, konsumen, korsal, sales, sales-kurir-sub | LARAVEL |  |
| GET | `/api/v1/orders/regions` | session | — | LARAVEL |  |
| GET | `/api/v1/orders/{order}` | session | — | LARAVEL |  |
| POST | `/api/v1/orders/{order}/assisted-returns` | session | korsal, sales, sales-kurir-sub | LARAVEL |  |
| POST | `/api/v1/orders/{order}/cancel` | session | — | LARAVEL |  |
| GET | `/api/v1/orders/{order}/consent-context` | session | admin, agen, konsumen, korsal, sales, sales-kurir-sub, super_admin | LARAVEL |  |
| GET | `/api/v1/orders/{order}/consent-events` | session | admin, agen, konsumen, korsal, sales, sales-kurir-sub, super_admin | LARAVEL |  |
| PUT | `/api/v1/orders/{order}/due-date` | session | admin, keuangan, super_admin | LARAVEL |  |
| GET | `/api/v1/orders/{order}/invoice` | session | — | LARAVEL |  |
| POST | `/api/v1/orders/{order}/items` | session | admin | LARAVEL |  |
| GET | `/api/v1/orders/{order}/items/{item}/cancel-eligibility` | session | admin, agen, super_admin | LARAVEL |  |
| POST | `/api/v1/orders/{order}/items/{item}/cancel-undelivered` | session | admin, agen, super_admin | LARAVEL |  |
| PATCH | `/api/v1/orders/{order}/items/{item}/fulfillment` | session | admin, agen, super_admin | LARAVEL |  |
| PATCH | `/api/v1/orders/{order}/items/{item}/reschedule` | session | admin, agen, super_admin | LARAVEL |  |
| PATCH | `/api/v1/orders/{order}/payment/cod` | session | keuangan, super_admin | LARAVEL |  |
| POST | `/api/v1/orders/{order}/payment/cod-proof` | session | — | LARAVEL |  |
| POST | `/api/v1/orders/{order}/payment/installment` | session | — | LARAVEL |  |
| POST | `/api/v1/orders/{order}/payment/proof` | session | — | LARAVEL |  |
| POST | `/api/v1/orders/{order}/payment/settle` | session | keuangan, super_admin | LARAVEL |  |
| POST | `/api/v1/orders/{order}/payment/verify` | session | keuangan, super_admin | LARAVEL |  |
| GET | `/api/v1/orders/{order}/refund-recipient` | session | admin, agen, keuangan, korsal, sales, sales-kurir-sub, super_admin | LARAVEL |  |
| POST | `/api/v1/orders/{order}/refund-recipient` | session | admin, agen, keuangan, korsal, sales, sales-kurir-sub, super_admin | LARAVEL |  |
| POST | `/api/v1/orders/{order}/returns` | session | konsumen | LARAVEL |  |
| PATCH | `/api/v1/orders/{order}/status` | session | admin, agen, super_admin | LARAVEL |  |

## CMS — pages — `pages` (1/1 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/pages/{slug}` | public | — | **GO** |  |

## Payments — proofs — `payment-proofs` (0/1 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/payment-proofs/{verification}` | session | — | LARAVEL |  |

## Catalog — products — `products` (2/17 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/products` | public | — | **PARTIAL** | anonymous exact; authenticated agent/Sub availability (stock) not ported |
| POST | `/api/v1/products` | session | admin, agen, super_admin | LARAVEL |  |
| DELETE | `/api/v1/products/{id}/permanent` | session | agen, super_admin | LARAVEL |  |
| DELETE | `/api/v1/products/{product}` | session | agen, super_admin | LARAVEL |  |
| PATCH | `/api/v1/products/{product}` | session | admin, agen, super_admin | LARAVEL |  |
| GET | `/api/v1/products/{product}/fees` | session | agen, korsal, sales, sales-kurir-sub, super_admin | LARAVEL |  |
| PUT | `/api/v1/products/{product}/fees` | session | agen, super_admin | LARAVEL |  |
| POST | `/api/v1/products/{product}/images` | session | admin, agen, super_admin | LARAVEL |  |
| PUT | `/api/v1/products/{product}/images/order` | session | admin, agen, super_admin | LARAVEL |  |
| DELETE | `/api/v1/products/{product}/images/{image}` | session | admin, agen, super_admin | LARAVEL |  |
| PATCH | `/api/v1/products/{product}/images/{image}` | session | admin, agen, super_admin | LARAVEL |  |
| POST | `/api/v1/products/{product}/variations` | session | admin, agen, super_admin | LARAVEL |  |
| DELETE | `/api/v1/products/{product}/variations/{variation}` | session | agen, super_admin | LARAVEL |  |
| PATCH | `/api/v1/products/{product}/variations/{variation}` | session | admin, agen, super_admin | LARAVEL |  |
| GET | `/api/v1/products/{product}/variations/{variation}/fees` | session | agen, korsal, sales, sales-kurir-sub, super_admin | LARAVEL |  |
| PUT | `/api/v1/products/{product}/variations/{variation}/fees` | session | agen, super_admin | LARAVEL |  |
| GET | `/api/v1/products/{slug}` | public | — | **PARTIAL** | anonymous exact; authenticated agent/Sub availability (stock) not ported |

## Profile — `profile` (1/11 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| PATCH | `/api/v1/profile` | session | — | LARAVEL |  |
| GET | `/api/v1/profile/email/verification` | session | — | LARAVEL |  |
| POST | `/api/v1/profile/email/verification` | session | — | LARAVEL |  |
| POST | `/api/v1/profile/email/verification/{challenge}/confirm` | session | — | LARAVEL |  |
| PATCH | `/api/v1/profile/password` | session | — | **GO** |  |
| DELETE | `/api/v1/profile/referral-code` | session | agen, korsal, sales, sales-kurir-sub | LARAVEL |  |
| PATCH | `/api/v1/profile/referral-code` | session | agen, korsal, sales, sales-kurir-sub | LARAVEL |  |
| POST | `/api/v1/profile/referral-code/regenerate` | session | agen, korsal, sales, sales-kurir-sub | LARAVEL |  |
| GET | `/api/v1/profile/whatsapp` | session | — | LARAVEL |  |
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

## Referral — `referral` (0/1 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/referral/{code}` | public | — | LARAVEL |  |

## Regions — `regions` (4/4 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/regions/districts` | public | — | **GO** |  |
| GET | `/api/v1/regions/provinces` | public | — | **GO** |  |
| GET | `/api/v1/regions/regencies` | public | — | **GO** |  |
| GET | `/api/v1/regions/villages` | public | — | **GO** |  |

## Reporting — `reports` (0/17 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/reports/cancellations-refunds` | session | admin, agen, keuangan, super_admin | LARAVEL |  |
| GET | `/api/v1/reports/couriers` | session | admin, agen, keuangan, super_admin | LARAVEL |  |
| GET | `/api/v1/reports/couriers-per-agent` | session | admin, agen, keuangan, super_admin | LARAVEL |  |
| GET | `/api/v1/reports/crm-visits` | session | admin, agen, korsal, sales, sales-kurir-sub, super_admin | LARAVEL |  |
| GET | `/api/v1/reports/customers` | session | admin, agen, keuangan, super_admin | LARAVEL |  |
| GET | `/api/v1/reports/fees/agent` | session | agen, super_admin | LARAVEL |  |
| GET | `/api/v1/reports/fees/courier` | session | admin, agen, keuangan, super_admin | LARAVEL |  |
| GET | `/api/v1/reports/fees/sales-korsal` | session | admin, agen, keuangan, super_admin | LARAVEL |  |
| GET | `/api/v1/reports/finance-orders` | session | admin, agen, keuangan, super_admin | LARAVEL |  |
| GET | `/api/v1/reports/finance-summary` | session | admin, agen, keuangan, super_admin | LARAVEL |  |
| GET | `/api/v1/reports/korsal` | session | admin, agen, keuangan, super_admin | LARAVEL |  |
| GET | `/api/v1/reports/my-customers` | session | korsal, sales, sales-kurir-sub | LARAVEL |  |
| GET | `/api/v1/reports/network-summary` | session | agen, keuangan, super_admin | LARAVEL |  |
| GET | `/api/v1/reports/network-transactions` | session | admin, agen, korsal, sales, sales-kurir-sub, super_admin | LARAVEL |  |
| GET | `/api/v1/reports/payment-status` | session | admin, agen, keuangan, super_admin | LARAVEL |  |
| GET | `/api/v1/reports/sales` | session | admin, agen, korsal, super_admin | LARAVEL |  |
| GET | `/api/v1/reports/transactions` | session | admin, agen, korsal, super_admin | LARAVEL |  |

## Returns — items — `return-items` (0/2 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| PATCH | `/api/v1/return-items/{item}/courier` | session | admin, agen, koordinator-kurir, super_admin | LARAVEL |  |
| POST | `/api/v1/return-items/{returnItem}/receipt-allocations` | session | admin, super_admin | LARAVEL |  |

## Site configuration — website — `settings` (1/1 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/settings` | public | — | **GO** |  |

## Shipping & delivery — `shipments` (0/9 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| PATCH | `/api/v1/shipments/{shipment}/courier` | session | koordinator-kurir | LARAVEL |  |
| GET | `/api/v1/shipments/{shipment}/delivery-verifications` | session | admin, super_admin | LARAVEL |  |
| POST | `/api/v1/shipments/{shipment}/delivery-verifications` | session | admin, super_admin | LARAVEL |  |
| GET | `/api/v1/shipments/{shipment}/eligible-couriers` | session | koordinator-kurir | LARAVEL |  |
| PATCH | `/api/v1/shipments/{shipment}/expedition-handover` | session | koordinator-kurir | LARAVEL |  |
| POST | `/api/v1/shipments/{shipment}/items/{orderItem}/refuse` | session | admin, agen, koordinator-kurir, kurir, sales-kurir-sub, super_admin | LARAVEL |  |
| PATCH | `/api/v1/shipments/{shipment}/items/{orderItem}/status` | session | admin, agen, koordinator-kurir, kurir, sales-kurir-sub, super_admin | LARAVEL |  |
| GET | `/api/v1/shipments/{shipment}/receipt` | session | admin, agen, koordinator-kurir, kurir, sales-kurir-sub, super_admin | LARAVEL |  |
| PATCH | `/api/v1/shipments/{shipment}/status` | session | admin, agen, koordinator-kurir, kurir, sales-kurir-sub, super_admin | LARAVEL |  |

## Inventory — agent — `stock` (3/3 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| POST | `/api/v1/stock/adjust` | session | admin, agen | **GO** | 403 for every caller in warehouse-authoritative mode (the default) — identical to the reference; deviation register D-3 |
| GET | `/api/v1/stock/products` | session | admin, agen, gudang, super_admin | **GO** |  |
| GET | `/api/v1/stock/variations` | session | admin, agen, gudang, super_admin | **GO** |  |

## Inventory — sub stock — `sub-stock` (3/10 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/sub-stock/my` | session | sales-kurir-sub | **PARTIAL** | reserved always 0 in the verified fixture set (needs an order_item; belongs to the orders checkpoint) |
| GET | `/api/v1/sub-stock/replenishment-targets` | session | sales-kurir-sub | **GO** |  |
| GET | `/api/v1/sub-stock/requests` | session | admin, agen, gudang, sales-kurir-sub | **GO** | list only; create/approve/execute/receive/cancel/reject are mutation domain and not ported |
| POST | `/api/v1/sub-stock/requests` | session | sales-kurir-sub | LARAVEL |  |
| GET | `/api/v1/sub-stock/requests/{subStockRequest}` | session | admin, agen, gudang, sales-kurir-sub | LARAVEL |  |
| POST | `/api/v1/sub-stock/requests/{subStockRequest}/approve` | session | admin | LARAVEL |  |
| POST | `/api/v1/sub-stock/requests/{subStockRequest}/cancel` | session | sales-kurir-sub | LARAVEL |  |
| POST | `/api/v1/sub-stock/requests/{subStockRequest}/execute` | session | gudang | LARAVEL |  |
| POST | `/api/v1/sub-stock/requests/{subStockRequest}/receive` | session | sales-kurir-sub | LARAVEL |  |
| POST | `/api/v1/sub-stock/requests/{subStockRequest}/reject` | session | admin | LARAVEL |  |

## User management — `users` (0/8 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/users` | session | admin, agen, keuangan, koordinator-kurir, korsal, kurir, sales, sales-kurir-sub, super_admin | LARAVEL |  |
| POST | `/api/v1/users` | session | agen, koordinator-kurir, korsal, super_admin | LARAVEL |  |
| DELETE | `/api/v1/users/{user}` | session | admin, agen, keuangan, koordinator-kurir, korsal, kurir, sales, sales-kurir-sub, super_admin | LARAVEL |  |
| GET | `/api/v1/users/{user}` | session | admin, agen, keuangan, koordinator-kurir, korsal, kurir, sales, sales-kurir-sub, super_admin | LARAVEL |  |
| PATCH | `/api/v1/users/{user}` | session | admin, agen, keuangan, koordinator-kurir, korsal, kurir, sales, sales-kurir-sub, super_admin | LARAVEL |  |
| PATCH | `/api/v1/users/{user}/convert-to-sales-kurir` | session | admin, agen, keuangan, koordinator-kurir, korsal, kurir, sales, sales-kurir-sub, super_admin | LARAVEL |  |
| PATCH | `/api/v1/users/{user}/convert-to-sales-kurir-sub` | session | admin, agen, keuangan, koordinator-kurir, korsal, kurir, sales, sales-kurir-sub, super_admin | LARAVEL |  |
| PATCH | `/api/v1/users/{user}/reassign-referral` | session | admin, agen, keuangan, koordinator-kurir, korsal, kurir, sales, sales-kurir-sub, super_admin | LARAVEL |  |

## Inventory — warehouse stock — `warehouse-stock` (1/1 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/warehouse-stock` | session | admin, agen, gudang, super_admin | **GO** | Laravel paginates without ORDER BY (upstream defect); Go orders by id, so cross-page SET is compared instead of page bytes |

## Inventory — warehouse — `warehouse` (28/49 in Go)

| Method | Path | Auth | Roles | Go status | Notes |
|---|---|---|---|---|---|
| GET | `/api/v1/warehouse/fulfillment-proposals` | session | admin, gudang, koordinator-kurir | LARAVEL |  |
| GET | `/api/v1/warehouse/fulfillment-proposals/{proposal}` | session | admin, gudang, koordinator-kurir | LARAVEL |  |
| POST | `/api/v1/warehouse/fulfillment-proposals/{proposal}/approve` | session | admin | LARAVEL |  |
| POST | `/api/v1/warehouse/fulfillment-proposals/{proposal}/receive` | session | koordinator-kurir | LARAVEL |  |
| POST | `/api/v1/warehouse/fulfillment-proposals/{proposal}/reject` | session | admin | LARAVEL |  |
| GET | `/api/v1/warehouse/handovers/{handover}` | session | admin, agen, gudang, super_admin | LARAVEL |  |
| GET | `/api/v1/warehouse/handovers/{handover}/print` | session | admin, agen, gudang, super_admin | LARAVEL |  |
| GET | `/api/v1/warehouse/opnames` | session | admin, agen, gudang, super_admin | **GO** |  |
| POST | `/api/v1/warehouse/opnames` | session | gudang | **PARTIAL** | snapshots system_quantity + stock id + physical revision per target |
| GET | `/api/v1/warehouse/opnames/{opname}` | session | admin, agen, gudang, super_admin | **GO** |  |
| POST | `/api/v1/warehouse/opnames/{opname}/approve` | session | admin | **GO** | writes the correction; 409 when the stock changed after counting (quantity OR revision); idempotent |
| POST | `/api/v1/warehouse/opnames/{opname}/cancel` | session | gudang | **GO** |  |
| PATCH | `/api/v1/warehouse/opnames/{opname}/count` | session | gudang | **GO** |  |
| POST | `/api/v1/warehouse/opnames/{opname}/reject` | session | admin | **GO** |  |
| POST | `/api/v1/warehouse/opnames/{opname}/submit` | session | gudang | **GO** | refuses while any item is uncounted |
| GET | `/api/v1/warehouse/orders/diproses` | session | gudang | LARAVEL |  |
| GET | `/api/v1/warehouse/orders/{order}/stock-request` | session | gudang | LARAVEL |  |
| GET | `/api/v1/warehouse/returns` | session | gudang | LARAVEL |  |
| POST | `/api/v1/warehouse/returns/{item}/finalize` | session | admin | LARAVEL |  |
| POST | `/api/v1/warehouse/returns/{item}/inspect` | session | gudang | LARAVEL |  |
| GET | `/api/v1/warehouse/sellable` | session | admin, agen, gudang, super_admin | **GO** | Go answers 422 when neither product_id nor variation_id is given; Laravel 500s there (upstream defect, documented divergence) |
| GET | `/api/v1/warehouse/settings` | session | admin, agen, gudang, super_admin | **GO** | reproduces Laravel's firstOrCreate side effect (a GET may create the row) |
| PATCH | `/api/v1/warehouse/settings/factory-plan` | session | admin | **GO** |  |
| GET | `/api/v1/warehouse/stock-addition-requests` | session | admin, gudang | LARAVEL |  |
| POST | `/api/v1/warehouse/stock-addition-requests` | session | gudang | LARAVEL |  |
| GET | `/api/v1/warehouse/stock-addition-requests/{warehouseStockRequest}` | session | admin, gudang | LARAVEL |  |
| POST | `/api/v1/warehouse/stock-addition-requests/{warehouseStockRequest}/approve` | session | admin | LARAVEL |  |
| POST | `/api/v1/warehouse/stock-addition-requests/{warehouseStockRequest}/reject` | session | admin | LARAVEL |  |
| GET | `/api/v1/warehouse/stock-cards` | session | admin, gudang | **GO** | reads warehouse buckets only (no legacy fallback) — same asymmetry as the reference |
| POST | `/api/v1/warehouse/stock-request-items/{item}/resolve-discrepancy` | session | admin | LARAVEL |  |
| POST | `/api/v1/warehouse/stock-requests/{stockRequest}/fulfill` | session | gudang | LARAVEL |  |
| POST | `/api/v1/warehouse/stock-requests/{stockRequest}/proposals` | session | gudang | LARAVEL |  |
| POST | `/api/v1/warehouse/sub-adjustment-requests` | session | gudang | LARAVEL |  |
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
| Served by Go now | 75 (20.0%) |
|   of which fully GO | 69 |
|   of which PARTIAL (incomplete behaviour) | 6 |
| Still Laravel-only | 300 |
| Areas fully ported | 11 / 45 |
