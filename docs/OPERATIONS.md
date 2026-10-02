# Prime Classy — Operations Runbook

DEV, TEST and PRODUCTION procedures. Governance rules are in [AGENTS.md](../AGENTS.md); behaviour is in [MASTER-SYSTEM.md](MASTER-SYSTEM.md). **No production action (deploy, migrate, cache, data) happens without explicit Human authorization for that step.**

## 1. Environments at a glance

| | DEV | TEST | PRODUCTION |
|---|---|---|---|
| Repo / app path | `/www/dev/primeclassy` | same checkout | `/www/wwwroot/primeccookies.com/primeclassy/{backend,public_html}` (not a Git working tree unless verified) |
| Database | `primeclassy_dev` (MariaDB 10.11) | `primeclassy_testing` | `primeclassy` (MariaDB 10.11.10) |
| `APP_ENV` | `local` | `testing` | `production` (`APP_DEBUG=false`) |
| Web | Nginx vhost on **:8080** serving `frontend/dist`, `/api|/sanctum|/up` → PHP-FPM socket → `backend/public/index.php` | PHPUnit only | Nginx single-domain: SPA + `public_html/laravel.php` bridge |
| Config | `backend/.env` (DEV only) | `backend/.env.testing` / `phpunit.xml` | its **own** `.env` — never replaced by DEV's |

## 2. Environment configuration (`backend/.env`)

| Concern | Keys / location |
|---|---|
| App | `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_KEY` (generate per environment, never reuse DEV's in production) |
| Database | `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` |
| Sanctum / CORS | `FRONTEND_URLS` (comma list **with scheme**, CORS), `SANCTUM_STATEFUL_DOMAINS` (host[:port], **no scheme**), `SESSION_SECURE_COOKIE=true` in production (HTTPS), `SESSION_DOMAIN` only for cross-subdomain setups. Missing `SANCTUM_STATEFUL_DOMAINS` ⇒ 500 "Session store not set on request" |
| Warehouse mode | `WAREHOUSE_STOCK_AUTHORITATIVE` (default `true`; omit it — never set `false` in production) |
| Google Sheets | `GOOGLE_SHEETS_ENABLED`, `GOOGLE_SHEETS_CREDENTIALS_PATH` (absolute path to the service-account JSON, outside `public/`, never in Git) |
| Mail / storage | `MAIL_*`, `FILESYSTEM_DISK` |
| Frontend | `frontend/.env` → `VITE_API_URL` (empty = same origin), optional `VITE_GOOGLE_MAPS_API_KEY`; production runtime override in `public_html/config.js` (`window.__APP_CONFIG__.API_URL`) — no rebuild needed |

**Secrets live in the database, not `.env`:** payment-gateway credentials (`agent_payment_gateway_configs`, encrypted, per Agent, entered via the Agent dashboard) and shipping-provider credentials (`agent_shipping_provider_configs`, encrypted; RajaOngkir key, OpenRoute key). Super Admin only toggles providers globally. `.env` is gitignored everywhere; only `.env.example` is committed.

## 3. DEV

**Paths and services:** repo `/www/dev/primeclassy`; backend `backend/` (`APP_ENV=local`, DB `primeclassy_dev`); frontend built into `frontend/dist/` and served by the DEV Nginx vhost (`deploy/nginx-dev-8080.conf`, installed with `deploy/apply-nginx-dev-8080.sh` as root; PHP-FPM 8.3 socket `/tmp/primeclassy-dev-php83.sock`, started by `deploy/start-php-fpm-dev.sh`). The vhost does not touch any other site.

**Daily commands**

```bash
cd /www/dev/primeclassy/backend
php artisan test                      # full suite (TEST DB, never DEV)
php artisan migrate:status
php artisan migrate                   # DEV DB only
cd ../frontend && npm run type-check && npm run build-only   # rebuild dist for the :8080 vhost
```

Benign CLI noise on this host: `Cannot load Zend OPcache - it was already loaded` and `Module "mbstring" is already loaded`.

### 3.1 DEV maintenance tool — `primeclassy:reset-dev-transactions` (DEV ONLY)

**This is a DEV maintenance tool. It is not a production reset procedure and must never be run against production.**

Wipes transactional data so UAT can restart from a clean state, keeping every master record and the **current physical stock**.

- **Hard guards (no override flag exists):** refuses when `APP_ENV=production` or when the connected database is not exactly `primeclassy_dev`; requires the phrase `RESET DEV TRANSACTIONS` (`--confirm="RESET DEV TRANSACTIONS"` or an interactive prompt).
- **Deletes** (child → parent, FK checks stay on, one DB transaction): sub_stock_reservations; stock_request_{proposal_items, proposals, fulfillments, items}; inventory_cancellation_reversals; stock_requests; return_items; returns; commissions; order_item_adjustments; delivery_verifications; cod_payment_proofs; bank_transfer_verifications; order_items (self/cross FKs nulled first); shipments; order_additional_payments; payment_webhook_logs; payment_transactions; orders; stock_handovers; sub_stock_request_{items, requests}; stock_transfer_{items, transfers}; stock_movements; stock_opname_{items, opnames}; warehouse_stock_requests. AUTO_INCREMENT is reset only on those tables, after the data commit.
- **Preserves:** users, roles, agents, products, variations, SKUs, fees, CMS, settings, gateway/shipping config, warehouse settings, Sub Locations and ownership, media, `activity_logs`, and **all physical balances** (`product_stocks.quantity_on_hand`, `product_variation_stocks.quantity_on_hand`, `warehouse_stocks.quantity`). Only `quantity_reserved` is zeroed. Inside the transaction the service re-checks per-row physical checksums, master counts and Sub ownership and rolls back on any difference.
- **Procedure:** (1) take a DEV dump (§6, DEV variant), (2) run it, (3) review the printed before/after report, (4) `php artisan cache:clear`, (5) smoke the app.

```bash
php artisan primeclassy:reset-dev-transactions --confirm="RESET DEV TRANSACTIONS"
```

## 4. TEST

- Database `primeclassy_testing` only. `phpunit.xml` forces `APP_ENV=testing` and the DB name; `Tests\TestCase` throws if the database name does not match `_test`/`_testing`, protecting DEV and production data.
- Commands: `php artisan test`, `php artisan test --filter=Name`, and the serialized runner `php scripts/run-tests-serialized.php tests/Feature/Name.php` (lock-protected; refuses non-testing databases) for destructive refresh/migration suites.
- Race suites spawn real second PHP processes (`.phpunit-concurrency-actor.php`); they need the same isolated DB and take ~10–20 s each. A full run takes roughly 12–15 minutes.
- Last recorded full run: 911 passed / 6644 assertions / 0 failures (includes the 4 DEV reset-command tests; frontend type-check and build also pass). Always re-run for current numbers.
- Frontend: `npm run type-check` and `npm run build-only`; there is no frontend test runner.

## 5. First install and first Super Admin

Fresh environments can use the browser installer at `/install` (requirements → database → app URLs → first Super Admin → migrate + seed → finalize → lock). The lock file `backend/storage/app/installed.lock` permanently seals the installer endpoints (403). Manual alternative:

```bash
php artisan migrate --seed       # roles (10), languages, settings, payment methods, shipping providers — no demo users
php artisan tinker               # create the first super_admin (role slug 'super_admin', hashed password cast)
php artisan storage:link         # non-single-domain layouts only (single-domain uses the public_html/storage symlink)
```

Never run `php artisan migrate:fresh` / `--seed` on production.

## 6. Backup (mandatory before any production migration or deployment)

Back up **three things**: the database, uploaded media (`backend/storage/app/public` — not reproducible from the DB), and `.env` (stored securely, never alongside public backups). Do not put database passwords on the command line or in the repository; use a temporary `--defaults-extra-file` with mode 600.

```bash
# production example layout: /root/primeclassy-backup-<package>-<YYYYMMDD>/{backend,public_html,database.sql}
mysqldump --defaults-extra-file=<tmp-600-file> --single-transaction --routines --triggers <database> > database.sql
```

Verify before proceeding: the command succeeded, the file exists and is non-empty, it ends with `-- Dump completed`, and the `CREATE TABLE` count looks right (Package B production dump: 3.6 MB, 89 tables). DEV dumps go outside the repository (e.g. `~/primeclassy-dev-backups/`, mode 600).

## 7. PRODUCTION

### 7.1 Layout

```
/www/wwwroot/primeccookies.com/primeclassy/
├── backend/        Laravel app (own .env, storage/, uploads) — not publicly reachable
└── public_html/    document root: Vue build + laravel.php + .htaccess + config.js + storage symlink
```

Nginx routes `/api`, `/sanctum`, `/up` to Laravel through `public_html/laravel.php` (which locates `../backend`); everything else is the SPA with history fallback. `bootstrap/app.php` switches Laravel's public path to `public_html` when that file exists. Production runs MariaDB 10.11.10 with `log_bin=OFF`.

### 7.2 CRITICAL INVARIANT — `public_html/laravel.php`

> **Never delete `public_html/laravel.php` during a frontend deployment.** A destructive sync removes it and every API call returns 404 while the frontend still loads (a real production incident). Always exclude it.

Canonical frontend deploy (after a verified backup and authorization):

```bash
sudo rsync -a --delete \
  --exclude='laravel.php' \
  /www/dev/primeclassy/frontend/dist/ \
  /www/wwwroot/primeccookies.com/primeclassy/public_html/
```

Also preserve `public_html/storage` (symlink to `../backend/storage/app/public`), `config.js`, the production `.htaccess`/Nginx rules and anything production-specific — **dry-run first** (`rsync -an --itemize-changes …`) and read the deletions before running for real. If `laravel.php` is ever lost, restore it from the pre-deployment backup (`public_html/laravel.php`; template `deploy/public_html/laravel.php`).

### 7.3 Deployment and migration procedure

1. **Preflight (read-only):** confirm branch/commit to ship, DEV UAT + Human Stage Gate recorded, working tree clean, full backend suite + frontend type-check/build green. Check production `php artisan migrate:status` for the Pending set and review each migration (additive? MariaDB 10.11-compatible? production data impact?).
2. **Backup** (§6): database + `backend` + `public_html`; verify.
3. **Maintenance mode ON** for any incompatible code/schema window: `php artisan down`.
4. **Deploy backend by whitelist** (never copy DEV `.env`, `storage/`, uploads, sessions or logs); `composer install --no-dev --optimize-autoloader`.
5. **Migrate:** confirm the migrations are *Pending*, run `php artisan migrate --force`, confirm they are *Ran*. Never `migrate:fresh`, `db:wipe`, truncate or disable FK checks.
6. **Reconcile before going live:** row counts and FK/orphan checks on touched tables, historical defaults, business invariants (e.g. roles = 10; `order_items` count unchanged; new nullable columns NULL on historical rows; Sub Location ownership unchanged; warehouse stock/movement counts preserved).
7. **Caches:** `php artisan config:clear && php artisan config:cache`, same for `route` and `view`. (`config:cache` freezes `.env` — re-run after any env change.)
8. **Deploy frontend** with the canonical command (§7.2).
9. **Maintenance mode OFF:** `php artisan up`.
10. **Smoke** (§7.4). Record backup path, migrations Ran, reconciliation numbers and smoke results in the checkpoint.

**Package C pending migrations (not yet applied to production):** `2026_10_02_100000_add_idempotency_key_to_order_items_table` (nullable `idempotency_key` + `UNIQUE(order_id, idempotency_key)`) and `2026_10_02_110000_add_request_fingerprint_to_order_items_table` (nullable `request_fingerprint`). Both are additive; historical rows stay NULL (many NULLs are allowed in a UNIQUE index).

**Production-UAT remediation migration (pending, not applied to production):** `2026_10_03_100000_add_item_decision_to_stock_request_proposal_items` — adds `decision_status/decided_by/decided_at/decision_reason` to `stock_request_proposal_items` and a `partial` value to `stock_request_proposals.status`; backfills only from the proposal header's recorded approval/rejection. After deploy, run `php artisan shipments:regroup` (dry-run, review, then `--apply` with authorization) so existing active orders get the Order + delivery date grouping.

**Migration notes from earlier packages:** `2026_10_01_100000` creates two BEFORE INSERT/UPDATE triggers on `shipments` — on a server with binary logging enabled the migrating user needs `SUPER`/`SET_USER_ID` or `log_bin_trust_function_creators=1` (not needed on production, where `log_bin=OFF`). The Package A role-rename migration's `down()` is not a clean inverse after a "fold" case — do not rely on rolling it back.

### 7.4 Smoke tests

- HTTPS home → 200; `GET /up` → 200.
- `GET /api/v1/install/status` → 200; `GET /api/v1/homepage` → 200; unauthenticated `GET /api/v1/auth/me` → 401 (route exists, auth enforced). A 404 on `/api/*` with a loading frontend means `laravel.php` is missing — restore it.
- Browser smoke: login per key role, product list, order detail, a warehouse page; for a package that changed an API, hit that route unauthenticated (expect 401/403, not 404) and exercise it as the authorized role.
- `php artisan about` → environment `production`, config/route/view cached, maintenance OFF.

### 7.5 Rollback

Before warehouse/order writes resume: put the site in maintenance, restore the verified database dump and the backed-up `backend`/`public_html`, re-verify, and bring the site up. Schema `down()` migrations are used only with explicit Human approval and only for additive changes. After real traffic has written new data, do **not** roll back with arithmetic or truncation — use reconciliation and a controlled correction workflow.

### 7.6 Production safety rules (recap)

Never copy DEV `.env`/credentials to production · preserve production `.env`, uploads, private credentials, sessions, logs and installed lock · never run `migrate:fresh`/destructive reset · back up before migrating · keep maintenance mode on during incompatible windows · reconcile before going live · keep production changes whitelisted and dry-run-checked.

## 8. Maintenance and data commands

| Command | Purpose / caution |
|---|---|
| `php artisan regions:import [--reset] [--force]` | Load province/regency/district/village data from `backend/database/data/regions` (source and licence in `SOURCE.md` there). `--reset` deletes region rows first |
| `php artisan products:backfill-sku [--apply]` | Deterministic SKU backfill; dry-run by default; run `system:audit-catalog-hierarchy` first |
| `php artisan system:audit-catalog-hierarchy` | Read-only SKU/hierarchy audit (JSON) |
| `php artisan shipments:regroup [--apply] [--order=ID]` | Merges **mutable** same-date shipments of existing orders (Order + delivery date rule); assigned/in-flight/delivered/**tracked**/verified shipments are never touched; dry-run by default (rolls back). Production use needs a verified backup and explicit Human authorization; preserves the shipping-fee snapshot (nonzero conservation); idempotent. Reports processed/changed/skipped/failed and exits **non-zero** when any order failed (each failed order is rolled back individually) |
| `php artisan warehouse:reconcile [--agent_id=]` | Read-only diagnostics of warehouse buckets, reservations, movements, requests, opnames |
| `php artisan warehouse:migrate-legacy-stock --dry-run …` | Historical legacy-stock → Transit backfill tooling (the production cutover is already done). Any non-dry run needs verified backup + explicit Human authorization |
| `php artisan transactions:reset [--dry-run] [--force]` | **Legacy tool — do not use.** It predates the warehouse/Sub tables and does not know `stock_requests`, `stock_transfers`, `sub_stock_*`, `delivery_verifications`, `inventory_cancellation_reversals`, etc. Their FKs are `RESTRICT`, so on a database with such rows it fails and rolls back, and it can never be a safe production reset. For DEV use §3.1; there is no authorized production reset procedure |
| `php artisan primeclassy:reset-dev-transactions` | DEV only (§3.1) |

## 9. Integrations — operational notes

- **Google Sheets:** one central service account (Google Cloud project with the Sheets API enabled; key JSON stored privately, e.g. `backend/storage/app/private/google/service-account.json` with restrictive permissions, never in Git/`public/`). Set `GOOGLE_SHEETS_ENABLED=true` + `GOOGLE_SHEETS_CREDENTIALS_PATH`, then `config:cache`. Share each destination spreadsheet with the service-account email as **Editor**. Use **Periksa koneksi** (read-only `spreadsheets.get`) in `/dashboard/google-sheets`. Common errors: `PERMISSION_DENIED` (share missing), `API_DISABLED`, `SPREADSHEET_NOT_FOUND`, `RATE_LIMIT`. Sync is manual, one-way, ≤ 10,000 rows, replaces the tab's values; never a database backup. Rotate the key if exposure is suspected.
- **RajaOngkir / OpenRoute / payment gateways:** each Agent configures credentials in its dashboard; keys are never returned by the API. Point gateway webhooks at `POST {APP_URL}/api/v1/webhooks/payment/{method}` (`xendit`, `tripay`, `stripe`). OpenRoute base URL is server-controlled. Rotate a RajaOngkir key that was ever exposed in a log or trace.
- **Uploads:** stored on the `public` disk (`backend/storage/app/public`), served through the `storage` symlink; a 404 on every uploaded file usually means the symlink is missing.

## 10. Troubleshooting

| Symptom | Likely cause |
|---|---|
| Login works, later requests 401/419 | `FRONTEND_URLS` / `SANCTUM_STATEFUL_DOMAINS` mismatch (both must match the real origin) |
| 500 "Session store not set on request" | `SANCTUM_STATEFUL_DOMAINS` missing |
| Frontend loads but `/api/*` → 404 (production) | `public_html/laravel.php` was deleted — restore (§7.2) |
| Reload of `/products/foo` → 404 | SPA fallback missing in the web server (`try_files … /index.html` / `.htaccess`) |
| Uploaded images 404 | `storage` symlink missing or wrong document root |
| 500 with no detail in production | Expected (`APP_DEBUG=false`); read `backend/storage/logs/laravel.log` |
| Webhook 401 `invalid_signature` | Gateway secret mismatch between provider and Agent config |
| RajaOngkir options 422 | Agent key/origin/courier codes, product weight, or incomplete buyer village hierarchy |
| `MAC is invalid` in old logs | Historical `APP_KEY` change; not an active defect |
| Stale config after editing `.env` | Run `php artisan config:clear` (and re-cache in production) |
