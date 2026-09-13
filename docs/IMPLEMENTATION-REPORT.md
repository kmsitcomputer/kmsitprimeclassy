# Implementation report — 2026-09-13

## User hierarchy

- Super Admin can create only Agent accounts.
- Agent can create Admin, Keuangan, Korsal, Sales, and Kurir in its own network.
- Agent-created Sales requires a selected Korsal from the same network and is
  stored with `parent_id = korsal_id`, while `agent_id` is derived from the
  authenticated Agent.
- Korsal-created Sales derives all ownership fields from the authenticated
  Korsal.
- Request validation, policy, service, API middleware, frontend choices, and
  capability hints apply the same matrix.

## Catalog SKU

- Simple products require SKU; variation products cannot carry a parent SKU.
- Products and variants reserve values in one `catalog_skus` namespace.
- Existing variant SKUs populate that registry during migration.
- New simple and variant orders copy the applicable SKU into the immutable
  order-item snapshot.
- Audit and explicit dry-run/apply backfill commands were added.

## Google Sheets

- Central service-account authentication is server-side and environment based.
- Spreadsheet destinations have global or Agent ownership, assigned by Super
  Admin. Configs and logs inherit that ownership.
- Access is limited to Super Admin, Agent, and Admin; backend policies enforce
  ID-based access.
- Thirteen application-defined datasets expose explicit allowed fields.
- Manual synchronization replaces values in a dedicated existing tab, is
  concurrency locked, limited to 10,000 rows, and records sanitized success or
  failure logs. There is no Sheets-to-MySQL path.

## Database migrations

- `2026_09_24_090000_add_global_catalog_skus.php`
- `2026_09_24_090001_create_google_sheets_tables.php`

The migrations and SKU backfill were not applied to the existing database.

## Cleanup

Six verified Finder `.DS_Store` metadata files were removed. Archives,
documentation, deployment files, build output, SQLite data, logs, and Laravel
runtime directories were retained. See `REPOSITORY-CLEANUP-REPORT.md`.

## Verification

- User hierarchy/network suite: 24 tests, 93 assertions passed.
- Final backend regression: 377 tests, 1,872 assertions passed on an isolated
  MySQL database.
- Pre-install DDL isolation suite: 11 tests, 29 assertions passed.
- Frontend production build and TypeScript checking passed.
- Google API behavior was tested with a mocked client. Live connection and
  write verification require a deployed service-account key and shared test
  spreadsheet.

## Known dependency risk

`composer audit --locked --no-dev` reports three advisories against the locked
Laravel 11.56.1 framework, including CVE-2026-48019. The published affected
range covers Laravel 11 and does not provide an 11.x patched version. Moving to
Laravel 12 is a major framework upgrade and was not bundled into this scoped
implementation. The newly added `google/auth` 1.53.0 package was not identified
by the audit as vulnerable.
