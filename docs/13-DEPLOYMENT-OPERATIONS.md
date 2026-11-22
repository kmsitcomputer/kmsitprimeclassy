# 13 — Deployment & Operations

This document covers the Go-only local stack and data-safety boundaries. The finalization mandate did not authorize production changes. There is no Laravel or PHP deployment package in this repository, and this runbook does not provide a production cutover procedure.

## Local services

The local domain is `http://primeclassy-go-local.kmsit.localhost`. Compose starts the Go API, Vue SPA and Nuxt storefront on the existing shared `primeclassy-net` and `caddy-ingress` networks:

```sh
cp backend/.env.example backend/.env
cp .env.example .env
docker compose config --quiet
docker compose up --build -d api spa nuxt
```

The API uses local database `primeclassy_go_local` and repository-root `storage/`. MariaDB, Caddy and their volumes are shared infrastructure and must not be removed as part of application cleanup. Optional background work uses the `async` Compose profile; keep it disabled unless local sandbox transports are configured.

## Storage and database safety

- `storage/public` is served through the Go API; `storage/private` remains behind authorized API handlers.
- Keep the imported database and storage snapshot intact. Backups belong outside the repository.
- The Go migrator is additive and owns only Go-specific schema (`pc_go_sessions`, `go_migrations`). Never point it or tests at production.
- Test databases must be named with `_test` or `_testing`. The Go test runner rejects other names.
- Never use global Docker prune commands. Remove only resources whose PrimeClassy ownership and lack of shared data are verified.

## Local smoke checks

```sh
curl -i http://primeclassy-go-local.kmsit.localhost/
curl -i http://primeclassy-go-local.kmsit.localhost/products
curl -i http://primeclassy-go-local.kmsit.localhost/api/v1/install/status
curl -i http://primeclassy-go-local.kmsit.localhost/up
curl -i http://primeclassy-go-local.kmsit.localhost/api/v1/auth/me
```

Expected statuses are 200 for the first four requests and 401 for unauthenticated `/api/v1/auth/me`.

## Production boundary

Production VPS, live PHP/Laravel runtime, DNS, production database and production storage were not modified by this local source cleanup. Do not deploy, migrate, alter live services or change DNS from this repository without separate explicit authorization, a current production inventory, verified backup and reconciliation plan. Historical production routing constraints in older records apply only to the live host; this repository no longer contains its Laravel bridge or deployment tooling.
