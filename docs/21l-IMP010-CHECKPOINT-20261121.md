# IMP-010 checkpoint — 2026-11-21

## Objective and status

Continue the owner-authorized migration until all Laravel-only API routes have verified Go parity. Owner has now paused execution. Status: **PAUSED — SAFE TO RESUME**. No production, remote, deploy, push, or destructive operation was performed.

## Repository position

- Branch: `imp010/go-backend`
- HEAD: `4f155c48aecbda28ac95685c60feb66ca67010a8`
- Canonical matrix at HEAD: 375 Laravel API routes; **314 GO, 1 PARTIAL, 60 Laravel-only**; 315 routes are served by Go.
- The latest two committed units are `cf6787f Port admin WhatsApp test send and retry guard` and `4f155c4 Port admin SMTP test connection and email`.
- Existing untracked owner-pause notes remain untouched: `docs/21g-IMP010-OWNER-PAUSE-20261120.md`, `docs/21k-IMP010-OWNER-PAUSE-20261120.md`.

## Verified work immediately before pause

- WhatsApp test-message: local GOWA stub differential **9 passed, 0 failed**; scoped `go test` and `go vet` passed for `internal/api`, `internal/agentadmin`, `internal/admininfra`, and `internal/i18n`.
- WhatsApp retry: duplicate-risk and gateway-disabled scenarios **7 passed, 0 failed**. It remains **PARTIAL** because configured-gateway delivery and durable `SendWhatsAppMessageJob` dispatch are not ported.
- SMTP test connection/email: local SMTP differential **9 passed, 0 failed**. The test email went only to the authenticated Super Admin address. Config update differential **9/9** and snapshot differential **3/3** also passed. Scoped `go test`/`go vet` passed for `internal/api`, `internal/admininfra`, and `internal/i18n`.
- SMTP work fixed the Go validation limit for `encryption=none` (four characters), which Laravel accepts.

## Current uncommitted WIP

The Region CSV implementation is unfinished and must be preserved as-is:

- Modified `go-backend/internal/api/domain_e_routes.go` — registers Admin region import/export.
- New `go-backend/internal/api/handlers_admin_regions.go` — CSV export/import, transaction, audit, and response handling.
- Modified `go-backend/internal/i18n/catalog.json` and `tools/parity/gen_i18n.py` — region import messages.
- New `tools/parity/compare_admin_regions_csv.py` — isolated differential harness using `primeclassy_testing`.
- Existing owner-pause documents listed above remain untracked and are not part of this WIP.

`go test ./internal/api` passed after adding the Region handler. The Region CSV differential **failed** on export serialization only: Laravel returns `Content-Type: text/csv; charset=utf-8` and quotes CSV fields containing spaces (for example, `"Jawa Barat"`); the Go draft returns `text/csv` and leaves those fields unquoted. The harness’s `finally` cleanup removed its synthetic codes and audit rows. Do not mark either Region endpoint GO until this difference is fixed and the full scoped differential passes. `go vet` has not been run after the Region WIP was added.

## Remaining route inventory

There are 60 Laravel-only routes in the generated matrix, by area:

- Admin: 16 (refund/cancellation transitions, sales-reduction reinstatement, region CSV import/export, and remaining Admin routes)
- Agent: 11 (shipping destinations/provider probe and Agent WhatsApp device/session/send routes)
- Auth: 4 (forgot/reset password and Google OAuth redirect/callback)
- Bestsellers: 1
- Catalog import/export: 3
- Checkout courier options/geocode: 2
- Dashboard: 2
- Google Sheets: 7
- Installer: 7
- Invoice configuration: 2
- Profile email/WhatsApp verification: 5

The full route list is generated from `docs/21a-IMP010-API-PARITY-MATRIX.md`; regenerate after verified changes. The outstanding WhatsApp retry route is separately classified PARTIAL, not Laravel-only.

## Exact next action

Resume by reading `AGENTS.md`, the authoritative IMP-010 docs, this checkpoint, then `git status` and the current diff. Continue the Region CSV unit without resetting or overwriting it. First change the Go export to emit `text/csv; charset=utf-8` and Laravel-compatible field quoting, rerun `tools/parity/compare_admin_regions_csv.py`, then run scoped `go test ./internal/api ./internal/admininfra ./internal/i18n`, scoped `go vet`, review the full diff, regenerate the parity matrix, and commit only after the Region differential passes. Then continue remaining Admin routes, followed by Agent and WhatsApp routes, until the Laravel-only count reaches zero; resolve the PARTIAL retry path too before claiming complete parity.
