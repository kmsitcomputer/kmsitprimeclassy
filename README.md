# Prime Classy Cake & Cookies

Multi-branch (multi-Agent) cake & cookies ordering platform: a **Laravel 11** JSON API plus a separate **Vue 3 / Vite** single-page app. Each Agent (branch) runs its own storefront presence, staff hierarchy, stock, warehouse and delivery operation; one Super Admin oversees the platform; consumers buy from a shared catalog and are attributed to a branch through a referral chain.

This README is the entry point only. The system is specified in:

| Doc | What it holds |
|---|---|
| [docs/MASTER-SYSTEM.md](docs/MASTER-SYSTEM.md) | Canonical description of the whole website (what exists today) |
| [docs/BUSINESS-RULES.md](docs/BUSINESS-RULES.md) | Locked business invariants, roles and authority matrix |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Services, locking, idempotency, API/frontend structure |
| [docs/OPERATIONS.md](docs/OPERATIONS.md) | DEV / TEST / PRODUCTION runbook, deployment and backup |
| [AGENTS.md](AGENTS.md) | AI / developer governance |

Historical specs, audits and checkpoints live in Git history only.

## Stack

- **Backend:** PHP 8.2+ (DEV runs 8.3), Laravel 11.56, Laravel Sanctum (SPA cookie sessions), MariaDB 10.11 (DEV and production; MySQL-compatible), HTMLPurifier, PhpSpreadsheet, Google API auth (Sheets export).
- **Frontend:** Vue 3 (Composition API), Vite 8, TypeScript, Pinia, Vue Router, vue-i18n (id / en / ar / zh), Tailwind CSS 4, CKEditor 5, Axios. Node ^22.18 or ≥24.12.
- **External services:** OpenRouteService (road-route pricing), RajaOngkir / Komerce V2 (expedition rates), Xendit / Tripay / Stripe (payment gateways, webhooks), Google Sheets (one-way report export), optional Google Maps (frontend picker).

## Major modules

Catalog & SKU registry · Referral hierarchy (10 roles) · Checkout & orders · Payment (COD, DP, manual transfer, gateways) · Fulfilment & shipments · Delivery proof, self-delivery and Admin delivery verification · Returns & refunds · Commissions · Warehouse (Transit / Factory Plan / Shipping / Sub stock, transfers, handovers, opname, stock requests) · Sales-Kurir-Sub & Sub Locations · Existing-order line addition (SC-03) · Reports & XLSX export · Google Sheets sync · CMS · Installer wizard · Audit log.

## Repository layout

```
primeclassy/
├── README.md  AGENTS.md  docs/        # the authoritative documentation set
├── backend/                           # Laravel 11 API
│   ├── app/{Http,Models,Policies,Services,Support,Console}
│   ├── routes/api_v1.php              # entire API surface
│   ├── database/{migrations,seeders,factories,data/regions}
│   ├── lang/{id,en,ar,zh}             # backend message catalogs
│   ├── tests/{Feature,Unit,Support}   # PHPUnit; real-DB + concurrency harness
│   └── scripts/run-tests-serialized.php
├── frontend/                          # Vue 3 SPA
│   └── src/{api,views,components,layouts,stores,router,dashboard,i18n,utils}
└── deploy/                            # build.sh, DEV nginx/php-fpm helpers, single-domain public_html files
```

## Local setup

```bash
# backend
cd backend
composer install
cp .env.example .env && php artisan key:generate
# create an empty database + user, set DB_* in .env, then:
php artisan migrate --seed          # roles, languages, settings, payment methods, shipping providers (no demo users)
php artisan serve                   # http://localhost:8000

# frontend
cd frontend
npm install
cp .env.example .env                # VITE_API_URL (empty = same origin)
npm run dev                         # http://localhost:5173
```

A first Super Admin is created by the browser installer (`/install`) on a fresh install, or via `php artisan tinker` — see [docs/OPERATIONS.md](docs/OPERATIONS.md). Sanctum needs both `FRONTEND_URLS` (with scheme) and `SANCTUM_STATEFUL_DOMAINS` (host:port, no scheme) in `backend/.env`.

## DEV / test commands

```bash
cd backend
php artisan test                                  # full suite (isolated DB primeclassy_testing)
php artisan test --filter=SomeTest                # focused
php scripts/run-tests-serialized.php tests/Feature/PermissionMapTest.php   # serialized, lock-protected runner
cd ../frontend
npm run type-check                                # vue-tsc
npm run build-only                                # vite build (npm run build = type-check + build)
```

No automated frontend test suite exists; frontend verification is type-check, build and manual browser checks. Tests refuse any database whose name does not contain `_test` / `_testing`.

## Build

`npm run build-only` outputs `frontend/dist/`. `deploy/build.sh` assembles the single-domain production package (SPA + `laravel.php` bridge + `.htaccess` + `config.js` + backend). Production deployment rules, including the `laravel.php` invariant, are in [docs/OPERATIONS.md](docs/OPERATIONS.md).

## Status

Package A (Sales-Kurir-Sub + Sub stock) and Package B (fulfilment/delivery lifecycle + authority/reporting) are **production closed**. Package C (SC-03, Admin-only add-line to an existing order) is implemented, independently reviewed, remediated and finally audited on branch `feat/package-c-sc03`; DEV UAT was a Human PASS and the DEV Stage Gate CLOSED/APPROVED; it was deployed to production with its migrations applied, but **Human Production UAT FAILED** (shipment/resi grouping, per-product approval, Stock Request quantity reconciliation, consumer delivery plan). Remediation is on branch `fix/package-c-production-uat` and is **not yet reviewed or deployed; Package C is NOT production closed.** See [docs/MASTER-SYSTEM.md §41](docs/MASTER-SYSTEM.md#41-current-roadmap-state).
