# 14 — Testing Status

Test commands and verified evidence for the Go-only backend and Vue/Nuxt frontend. Run tests only against isolated `*_test` / `*_testing` databases. Production and the imported `primeclassy_go_local` snapshot are not test targets.

## Backend Go

From the repository root:

```sh
bash tools/parity/run_go_tests.sh
```

The runner verifies the database name is `primeclassy_testing`, applies only additive Go-owned migrations, loads synthetic fixtures, then runs `go vet ./...` and `go test -p 1 ./... -count=1` in the Go container. The full Go suite is serialized because the integration tests share the isolated test database. Never run two runners on that database at once.

A plain build is also performed by the API Dockerfile:

```sh
docker compose build api
```

## Frontend Vue and Nuxt

From `frontend/` with the lockfile dependencies installed:

```sh
npm run test -- --maxWorkers=2
npm run type-check
npm run build-only
npm run nuxt:typecheck
npm run nuxt:build
npx oxlint .
npx eslint . --no-cache
```

The bounded worker count avoids process-start timeouts in constrained local environments. SSR smoke and multilingual/i18n integration scripts use the mock API in `frontend/nuxt-storefront/tests/e2e/`; browser E2E requires Playwright/Chromium and must not be reported as passing unless actually run.

## Finalization verification — 2026-11-22

- `bash tools/parity/run_go_tests.sh` — PASS on rerun: Go vet and all packages passed against `primeclassy_testing`. One earlier run showed a transient duplicate WhatsApp queue row in an integration test; the focused test and complete rerun both passed. No production or imported-local database was used.
- `docker compose up --build --force-recreate -d api spa nuxt` — final Go API, SPA and Nuxt images built and services recreated.
- Frontend Vitest — 56 files, 435 tests passed (`--maxWorkers=2`).
- Vue type-check, Vite/PWA build, Nuxt type-check, Nuxt production build and Oxlint — PASS.
- ESLint — 0 errors, 1 pre-existing unused `eslint-disable` warning at `src-pwa/sw.js:19`.
- Nuxt SSR smoke — 56/56 PASS against the mock API.
- Nuxt SSR/i18n integration — 37/37 PASS against the mock API when the test origin matched the server origin.
- Proxy routing and rollback drill — 42/42 PASS against the mock Go API.
- HTTP smoke on the local Go domain — `/`, `/products`, `/api/v1/install/status`, `/up`: 200; unauthenticated `/api/v1/auth/me`: 401.
- Canonical parity generator — 375 routes, 375 GO, 0 PARTIAL, 0 Laravel-only; REM-11's 19 Historical POS routes remain excluded.
- The Laravel comparison harnesses were retired after their differential evidence was recorded. The matrix is a snapshot of that pre-removal evidence; no live Laravel differential was run after runtime removal.
- Database test isolation — Go tests ran only on `primeclassy_testing`. The imported `primeclassy_go_local` dump fingerprint changed from `68c82b2fa12bf250a551d94b222f5ee57dc21dd391343b3476c6ab54c07709e2` to `9190e45c16dde01f64ec84cbca79ada096460675977aef184b5c3136dacfacca` during local Go runtime verification. A row-level dump comparison found no removed rows: 11 activity-log rows, one Go migration record and five Go session rows were added; all 79 `catalog_skus` rows matched. The current fingerprint is saved outside the repository under the finalization backup directory.
- Storage integrity — all 113 files matched the preserved storage manifest after cleanup.
- Browser E2E — not run; Playwright/Chromium was unavailable in the earlier checkpoint.
