# PrimeClassy Go backend

This directory contains the active Go API, worker, and additive Go-owned schema migrator. It uses the existing MariaDB schema and writes media/session-independent files to the repository-root `storage/` tree.

## Local development

Copy `.env.example` to `.env` and configure only local credentials. The repository Compose file builds the API from `docker/Dockerfile.go`; the canonical local database is `primeclassy_go_local` and the isolated test database is `primeclassy_testing`.

From the repository root:

```sh
docker compose up --build -d api spa nuxt
bash tools/parity/run_go_tests.sh
```

The test runner refuses database names without `_test` or `_testing`. Never point it at the imported local snapshot or production.

## Runtime boundaries

- The API serves the Vue SPA's API contract and public/private media endpoints.
- The worker is optional and is enabled through the `async` Compose profile.
- `storage/` at the repository root is the only runtime media/storage tree.
- The existing shared MariaDB and Caddy infrastructure are outside this repository's ownership.
- Historical Laravel terminology in API comments and parity fixtures describes compatibility contracts; no PHP/Laravel process is required to build or run this backend.
