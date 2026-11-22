# PrimeClassy Go — local consolidated runtime

This worktree is the local Go-only application root. It contains the existing Vue/Vite SPA, the Nuxt public storefront, the Go API/worker, and the imported `storage/` tree. The local snapshot database and Caddy/MariaDB infrastructure remain shared services; this project does not own or remove those containers or volumes.

Production is unchanged. Production Laravel remains outside this local worktree and no production deployment or cutover is configured here.

## Local setup

1. Keep the imported database `primeclassy_go_local` and storage snapshot intact. Configure `backend/.env` from `backend/.env.example`; use only local snapshot credentials. The runtime reads the database and writes uploaded media/session-independent files under `storage/`.
2. Copy `.env.example` to `.env` and set `PC_UID` / `PC_GID` to the local account that owns `storage/`.
3. Install and build the existing frontend from `frontend/`:

   ```sh
   npm ci
   npm run type-check
   npm run test
   npm run build-only
   npm run nuxt:typecheck
   npm run nuxt:build
   ```

4. Build and start the local Go API and both frontend servers on the existing `primeclassy-net` and `caddy-ingress` networks:

   ```sh
   docker compose up --build -d api spa nuxt
   ```

5. The Caddy site fragment is `docker/primeclassy-go-local.caddyfile`. It routes API, Sanctum and public media to Go; public storefront routes to Nuxt; and SPA routes to the static Vue build. The site must be validated before reloading the shared Caddy service.

The Compose file intentionally uses the existing `primeclassy-mariadb` service and the restored `primeclassy_go_local` database. It does not define a new database, mutate production, or remove shared infrastructure. The Go schema migrator is additive and only owns `pc_go_sessions` / `go_migrations`.

## Verification

- Full isolated Go suite: `bash tools/parity/run_go_tests.sh` (uses `primeclassy_testing`, never the imported local snapshot database).
- Frontend unit/component tests: `npm run test`.
- Frontend and Nuxt type checks: `npm run type-check && npm run nuxt:typecheck`.
- Production builds: `npm run build-only && npm run nuxt:build`.
- SSR smoke/integration scripts are documented in `frontend/nuxt-storefront/tests/e2e/README.md`.

The optional `async` Compose profile starts the Go notification worker. Enable it only after reviewing the local queue and configuring sandbox/fake delivery transports; do not let test or restored data trigger real external messages.

## Data and safety

- `storage/public` is the only directory served by the Go `/storage/*` handler. `storage/private` remains private and is served only through authorized Go endpoints.
- `storage/` and all `.env` files are ignored by Git. Backups are stored outside the repository.
- Historical Laravel source, route inventories and differential scripts in Git history are migration evidence, not application runtime dependencies.
- Production VPS/data/DNS changes are outside this local finalization. GitHub `main` publication is part of the Owner-authorized consolidation; do not deploy or connect to production.
