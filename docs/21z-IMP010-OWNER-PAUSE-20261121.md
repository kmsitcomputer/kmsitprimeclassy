# IMP-010 Owner Pause Checkpoint — 2026-11-21

## Status

**PAUSED BY OWNER.** Stop implementation here. Preserve the uncommitted endpoint WIP described below. Do not run its differential, continue coding, generate a new matrix, or commit the WIP until the Owner resumes work.

## Repository state at pause

- Repository: `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy-imp010-go`
- Branch: `imp010/go-backend`
- HEAD: `0bfdce420937d5673adcca6fb95b1346d0b87dff`
- Latest endpoint commit: `03a5cdf` — `Port admin order refunds list`
- Latest checkpoint commit: `0bfdce4` — `Checkpoint admin order refunds parity`
- Canonical committed matrix: **375 total / 289 GO / 0 PARTIAL / 86 Laravel-only**.
- Working tree has these uncommitted changes for `PATCH /api/v1/admin/additional-payments/{payment}/status`:
  - Modified `go-backend/internal/api/domain_e_routes.go`
  - Modified `tools/parity/gen_matrix.py`
  - New `go-backend/internal/api/handlers_admin_additional_payment_status.go`
  - New `go-backend/internal/orders/additional_payment_status.go`
- The status-write endpoint is **WIP, not GO**. No differential harness has been written or run for it. Do not regenerate the canonical matrix from the modified generator before differential parity passes; its in-progress mapping would otherwise make the unverified endpoint appear GO.
- The last scoped `go test ./internal/api ./internal/orders ./internal/i18n` and matching `go vet` both passed after the handler/service compile fix. These only establish compilation/static checks, not endpoint parity.
- No test fixture or database mutation was run for the WIP status endpoint. No migration/schema change. No production, remote push, deployment, or GLM environment operation.
- Preserved untouched untracked owner-pause notes:
  - `docs/21g-IMP010-OWNER-PAUSE-20261120.md`
  - `docs/21k-IMP010-OWNER-PAUSE-20261120.md`

## Completed since resuming

- `95c5d63` — dispatch history endpoint, differential, and matrix: 10/10 differential checks.
- `bfe866c` — dispatch history checkpoint.
- `2dad0da` — dispatch queue endpoint, differential, and matrix: 10/10 differential checks.
- `83d7ae8` — dispatch queue checkpoint.
- `59511b5` — admin additional-payment list, differential, and matrix: 7/7 checks.
- `14e9ea6` — additional-payment list checkpoint.
- `03a5cdf` — admin order-refunds list, differential, and matrix: 7/7 checks.
- Current canonical totals increased from 285 GO / 90 Laravel-only to **289 GO / 86 Laravel-only**. No PARTIAL endpoints remain.

## Laravel-only routes remaining

The following 86 method/path entries are classified `LARAVEL` in the canonical committed matrix at this HEAD. The first entry is the status endpoint currently under unverified WIP; it remains Laravel-only until verified and committed.

### Admin operations (38)

- `PATCH /api/v1/admin/additional-payments/{payment}/status` — WIP; unverified.
- `GET /api/v1/admin/google-auth`
- `PUT /api/v1/admin/google-auth`
- `GET /api/v1/admin/integrations/smtp`
- `PUT /api/v1/admin/integrations/smtp`
- `POST /api/v1/admin/integrations/smtp/test-connection`
- `POST /api/v1/admin/integrations/smtp/test-email`
- `GET /api/v1/admin/integrations/whatsapp`
- `PUT /api/v1/admin/integrations/whatsapp`
- `GET /api/v1/admin/integrations/whatsapp/messages`
- `POST /api/v1/admin/integrations/whatsapp/messages/{message}/retry`
- `DELETE /api/v1/admin/integrations/whatsapp/templates/{event}`
- `PUT /api/v1/admin/integrations/whatsapp/templates/{event}`
- `POST /api/v1/admin/integrations/whatsapp/templates/{event}/preview`
- `POST /api/v1/admin/integrations/whatsapp/test-connection`
- `POST /api/v1/admin/integrations/whatsapp/test-message`
- `PATCH /api/v1/admin/order-refunds/{adjustment}/status`
- `POST /api/v1/admin/order-sales-reductions/{reduction}/reinstate`
- `POST /api/v1/admin/orders/{order}/cancellation/finalize`
- `GET /api/v1/admin/orders/{order}/refunds`
- `POST /api/v1/admin/refund-attempts/{attempt}/cancel`
- `POST /api/v1/admin/refund-attempts/{attempt}/complete`
- `POST /api/v1/admin/refund-attempts/{attempt}/fail`
- `POST /api/v1/admin/refund-attempts/{attempt}/initiate`
- `POST /api/v1/admin/refund-attempts/{attempt}/reconcile`
- `POST /api/v1/admin/refund-attempts/{attempt}/unknown`
- `POST /api/v1/admin/refund-obligations/{obligation}/approve`
- `POST /api/v1/admin/refund-obligations/{obligation}/attempts`
- `POST /api/v1/admin/refund-obligations/{obligation}/deferred/materialize`
- `POST /api/v1/admin/refund-obligations/{obligation}/deferred/request`
- `POST /api/v1/admin/refund-obligations/{obligation}/needs-verification/resolve`
- `GET /api/v1/admin/regions/export`
- `POST /api/v1/admin/regions/import`
- `GET /api/v1/admin/shipping-couriers`
- `POST /api/v1/admin/shipping-couriers`
- `PATCH /api/v1/admin/shipping-couriers/{shippingCourier}/toggle`
- `GET /api/v1/admin/shipping-providers`
- `PATCH /api/v1/admin/shipping-providers/{provider}/toggle`

### Agent settings and WhatsApp (14)

- `GET /api/v1/agent/google-auth`
- `PUT /api/v1/agent/google-auth`
- `POST /api/v1/agent/shipping-providers/{provider}/destinations`
- `POST /api/v1/agent/shipping-providers/{provider}/test`
- `GET /api/v1/agent/whatsapp`
- `POST /api/v1/agent/whatsapp/device`
- `POST /api/v1/agent/whatsapp/events/{event}/preview`
- `POST /api/v1/agent/whatsapp/logout`
- `GET /api/v1/agent/whatsapp/messages`
- `POST /api/v1/agent/whatsapp/pair/code`
- `POST /api/v1/agent/whatsapp/pair/qr`
- `POST /api/v1/agent/whatsapp/reconnect`
- `POST /api/v1/agent/whatsapp/replace`
- `POST /api/v1/agent/whatsapp/test-send`

### Identity and session (4)

- `POST /api/v1/auth/forgot-password`
- `GET /api/v1/auth/google/callback`
- `GET /api/v1/auth/google/redirect`
- `POST /api/v1/auth/reset-password`

### Catalog and checkout (6)

- `GET /api/v1/bestsellers`
- `GET /api/v1/catalog/export`
- `POST /api/v1/catalog/imports`
- `POST /api/v1/catalog/imports/preview`
- `POST /api/v1/checkout/courier-options`
- `POST /api/v1/checkout/geocode`

### Dashboard (2)

- `GET /api/v1/dashboard/kpis`
- `GET /api/v1/dashboard/summary`

### Google Sheets (7)

- `GET /api/v1/google-sheets`
- `POST /api/v1/google-sheets/configs`
- `DELETE /api/v1/google-sheets/configs/{config}`
- `PUT /api/v1/google-sheets/configs/{config}`
- `POST /api/v1/google-sheets/configs/{config}/sync`
- `POST /api/v1/google-sheets/connection`
- `POST /api/v1/google-sheets/destinations`

### Installer (7)

- `POST /api/v1/install/configure-app`
- `POST /api/v1/install/database/save`
- `POST /api/v1/install/database/test`
- `POST /api/v1/install/finalize`
- `POST /api/v1/install/lock`
- `GET /api/v1/install/requirements`
- `POST /api/v1/install/run`

### Invoices (2)

- `GET /api/v1/invoice/config`
- `PUT /api/v1/invoice/config`

### Orders (1)

- `GET /api/v1/orders/{order}/invoice`

### Profile verification (5)

- `POST /api/v1/profile/email/verification`
- `POST /api/v1/profile/email/verification/{challenge}/confirm`
- `GET /api/v1/profile/whatsapp`
- `POST /api/v1/profile/whatsapp/verification`
- `POST /api/v1/profile/whatsapp/verification/{challenge}/confirm`

## EXACT NEXT ACTION WHEN RESUMED

Resume the preserved additional-payment status WIP. Inspect the diff against Laravel’s `markAdditionalPaymentStatus` and `markAdditionalPaymentPaid`; finish the scoped differential for validation, Keuangan/Super Admin access, transfer and COD success/failure, transfer-verification updates, order totals, invoice snapshots, audit, and stale replay. Fix any mismatch, rerun scoped Go tests/vet and the differential, regenerate the matrix only after parity passes, review the complete diff, then commit the verified endpoint and a fresh checkpoint. Do not discard or reset any WIP.
