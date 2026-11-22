# 01 — Project Overview

PrimeClassy V2 is a multi-branch cake and cookies commerce platform. Each Agent operates its own staff, stock, warehouse and delivery workflows. Consumers use a shared catalog and are attributed to a branch through referrals. Financial review, refunds and reporting follow the role boundaries in [05-BUSINESS-RULES](05-BUSINESS-RULES.md).

## Current application stack

| Layer | Technology | Active location |
|---|---|---|
| Backend | Go API, worker and additive Go-owned migrator | `backend/` |
| Frontend | Vue 3/Vite SPA and Nuxt storefront | `frontend/` |
| Database | Existing MariaDB schema; local database `primeclassy_go_local` | shared `primeclassy-mariadb` service |
| Runtime storage | Public and private application files | repository-root `storage/` |
| Local routing | Caddy → Go API, Vue SPA, Nuxt | `http://primeclassy-go-local.kmsit.localhost` |
| API | 375 in-scope routes; the 19 REM-11 Historical POS routes are excluded by Owner decision | `backend/internal/api/parity_routes.json` |

The Go service preserves the established API, session, database and business-rule contracts. Historical Laravel terminology in compatibility comments and parity artifacts describes those contracts; PHP/Laravel is not required to build or run the active application.

## Repository layout

```text
primeclassy-go/
├── backend/       Go API, worker, tests, additive migrator
├── frontend/      Vue SPA and Nuxt storefront
├── storage/       imported public/private files and Go runtime storage
├── docker/        Go-only local container builds and Caddy site
├── tools/parity/  canonical route matrix and migration verification artifacts
├── docs/          current project, business, operations and test documentation
└── compose.yaml   local Go API + SPA + Nuxt services
```

Laravel application source, Composer dependencies, PHP runtime packaging and Laravel-specific test infrastructure are not part of the active repository. Git history and the external consolidation backup remain archival; they are not runtime inputs.

## Production boundary

This repository finalization is local/source consolidation only. Production VPS, DNS, production database, production storage and live traffic were not changed. Production operations require a separate explicit mandate and a verified production runbook.

## Canonical documentation

Start with [README](../README.md). Locked business rules are in [05-BUSINESS-RULES](05-BUSINESS-RULES.md); architecture in [02-SYSTEM-ARCHITECTURE](02-SYSTEM-ARCHITECTURE.md); local operations in [13-DEPLOYMENT-OPERATIONS](13-DEPLOYMENT-OPERATIONS.md); and current test policy/evidence in [14-TESTING-STATUS](14-TESTING-STATUS.md).
