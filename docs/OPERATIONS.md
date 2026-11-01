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

**Secrets live in the database, not `.env`:** per-Agent bank account settings (`agent_payment_gateway_configs`, legacy table name, encrypted, entered via the Agent dashboard) and shipping-provider credentials (`agent_shipping_provider_configs`, encrypted; RajaOngkir key, OpenRoute key). Super Admin only toggles providers globally. `.env` is gitignored everywhere; only `.env.example` is committed.

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

### 3.2 Local Windows workstation startup (ServBay) — commands and port-conflict safety

Merged 2026-10-28 from `docs/LOCAL-STABILIZATION-CHECKPOINT.md` §9, which remains as historical
evidence. These commands run the project on the local Windows dev workstation (`C:\ServBay\…`,
ServBay PHP 8.3). They are environment-distinct from the shared DEV server paths in §3 — do not mix
the two path sets.

```powershell
# Backend (Laravel API + SPA bridge) — port 8000
cd C:\ServBay\www\primeclassy\backend
php -c C:\ServBay\etc\php\8.3\php.ini artisan serve --host=127.0.0.1 --port=8000

# Frontend dev server (Vite) — port 5174 (5173 may belong to another local project)
cd C:\ServBay\www\primeclassy\frontend
$env:VITE_DEV_PROXY_TARGET="http://127.0.0.1:8000"
npm run dev -- --host 127.0.0.1 --port 5174 --strictPort

# Production-build preview (asset/PWA verification)
npm run build-only
npm run preview            # http://localhost:4173

# Tests (scoped first, then full; database rules stay §4)
cd C:\ServBay\www\primeclassy\backend
$env:OPENSSL_CONF="C:\ServBay\packages\php\8.3\extras\ssl\openssl.cnf"
php artisan test --filter=Name
php artisan test

# Queue worker (only when a queued job must run; drivers are database)
php artisan queue:work --stop-when-empty
```

### 3.3 PrimeClassy WhatsApp worker lane (HA-01, DEV configuration reference)

Transactional WhatsApp rows are dispatched on the dedicated `primeclassy-notify` queue
(`App\Jobs\SendWhatsAppMessageJob::$queue`; canonical name `App\Services\WhatsApp\WhatsAppSettings::NOTIFY_QUEUE`).
The production supervisor configuration MUST reserve at least one worker for this lane so a backlog on other
queues can never starve notifications, and MUST point failed jobs at the existing `failed_jobs` table for
visibility. DEV reference commands (reference only — never run against production):

```powershell
# DEV: drain only the notification lane
php artisan queue:work --queue=primeclassy-notify,default --stop-when-empty
# DEV: list failed notification jobs
php artisan queue:failed
# DEV: retry one failed job after reading the duplicate-risk note in the diagnostics entry
php artisan queue:retry <id>
```

Rules: retries stay bounded inside `WhatsAppDelivery` (`max_attempts`, backoff, RETRY/FAILED/UNKNOWN
classification); Laravel's own retry is off (`$tries = 1`) so a worker crash is a re-claim, never a duplicate send.
OTP rows are never retried (a fresh challenge must be issued). No worker configuration is started or changed by
implementation runs — this section documents the target state for Owner provisioning.

Port-conflict check — always identify the listener before terminating anything:

```powershell
Get-NetTCPConnection -LocalPort 8000 -State Listen |
    ForEach-Object { Get-CimInstance Win32_Process -Filter "ProcessId=$($_.OwningProcess)" |
        Select-Object ProcessId, CommandLine }
```

Only stop the process when the `CommandLine` points at **this** project
(`C:\ServBay\www\primeclassy\backend\…server.php`). A different project's dev server on the same port
is **not** a PrimeClassy fault — move your own server to another port (`--port=8001`, `--port=5174`)
or ask the Owner. Never kill another project's server automatically.

## 4. TEST

- Database `primeclassy_testing` only. `phpunit.xml` forces `APP_ENV=testing` and the DB name; `Tests\TestCase` requires exactly `APP_ENV=testing`, MySQL/MariaDB and database `primeclassy_testing`, protecting DEV and production data.
- Commands: `php artisan test`, `php artisan test --filter=Name`, and the serialized runner `php scripts/run-tests-serialized.php tests/Feature/Name.php` (lock-protected; refuses non-testing databases) for destructive refresh/migration suites. One runner per test database is enforced by a MySQL lock; full procedure in [TESTING.md](TESTING.md).
- Race suites spawn real second PHP processes (`.phpunit-concurrency-actor.php`); they need the same isolated DB and take ~10–20 s each. A full run takes roughly 12–15 minutes.
- Last recorded full run: 855 passed / 6075 assertions / 0 failures (includes the 4 DEV reset-command tests; frontend type-check and build also pass). Always re-run for current numbers.
- Frontend: `npm run type-check` and `npm run build-only`; there is no frontend test runner.

## 5. First install and first Super Admin

Fresh environments can use the browser installer at `/setup` (requirements → database → app URLs → first Super Admin → migrate + seed → finalize → lock). (`/install` is the public PWA installation landing page, not the backend installer.) The lock file `backend/storage/app/installed.lock` permanently seals the installer endpoints (403). Manual alternative:

```bash
php artisan migrate --seed       # roles (11), languages, settings, payment methods, shipping providers — no demo users
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

**A dump that cannot be restored is not a backup.** Before any schema change, also record a `--no-data` dump, a `COUNT(*)`/`CHECKSUM TABLE` manifest for every table, `SHOW CREATE TABLE` for the tables being touched, and `sha256sum -c` over the whole backup directory — and then **load the dump into an isolated scratch database and compare the manifest**. The FK-restoration backup (§8.3) is the worked example: `/home/developer/primeclassy-dev-backups/fk-restoration-20261008-154004/`, restored into a throwaway MariaDB 10.11 container and verified to 0 row-count and 0 checksum mismatches. Keep the scratch database on a different engine instance or at least a different schema name, and never point the restore test at the live DEV or TEST database.

When a step will **delete rows**, add a dedicated `--complete-insert` dump of exactly the affected tables plus the pre-delete `SELECT *` output, so the rows are restorable verbatim.

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
  --exclude='.well-known' \
  --exclude='storage' \
  --exclude='laravel.php' \
  /www/dev/primeclassy/frontend/dist/ \
  /www/wwwroot/primeccookies.com/primeclassy/public_html/
```

Also preserve `public_html/storage` (symlink to `../backend/storage/app/public`), `public_html/.well-known` (server-owned, e.g. ACME challenges — never synced from `dist/`), `config.js`, the production `.htaccess`/Nginx rules and anything production-specific — **dry-run first** (`rsync -an --itemize-changes …`) and read the deletions before running for real. If `laravel.php` is ever lost, restore it from the pre-deployment backup (`public_html/laravel.php`; template `deploy/public_html/laravel.php`).

**PWA branding source of truth:** Admin → Pengaturan Website → Favicon (`site_favicon_media_id`) is the single canonical branding source. `frontend/public/icons/*` are only technical release derivatives of that favicon (192/512 + maskable + apple-touch-icon, stable filenames referenced by `manifest.webmanifest`) — never a second branding setting. When the favicon changes, regenerate the PWA icon derivatives from the current canonical favicon on the next frontend release (aspect preserved, no crop/distort, maskable padded into the safe zone on brand `#8f1d3c`).

### 7.3 Deployment and migration procedure

1. **Preflight (read-only):** confirm branch/commit to ship, DEV UAT + Human Stage Gate recorded, working tree clean, scoped backend tests for every changed area + frontend type-check/build green. Full Test is **not** a release gate (Owner decision, 2026-11-02); the Owner may run it at their discretion. Check production `php artisan migrate:status` for the Pending set and review each migration (additive? MariaDB 10.11-compatible? production data impact?).
2. **Backup** (§6): database + `backend` + `public_html`; verify.
3. **Maintenance mode ON** for any incompatible code/schema window: `php artisan down`.
4. **Deploy backend by whitelist** (never copy DEV `.env`, `storage/`, uploads, sessions or logs); `composer install --no-dev --optimize-autoloader`.
5. **Migrate:** confirm the migrations are *Pending*, run `php artisan migrate --force`, confirm they are *Ran*. Never `migrate:fresh`, `db:wipe`, truncate or disable FK checks.
6. **Reconcile before going live:** row counts and FK/orphan checks on touched tables, historical defaults, business invariants (e.g. roles = 11 — the 10 pre-IMP-003 roles plus `koordinator-kurir`; `order_items` count unchanged; new nullable columns NULL on historical rows; Sub Location ownership unchanged; warehouse stock/movement counts preserved).
7. **Caches:** `php artisan config:clear && php artisan config:cache`, same for `route` and `view`. (`config:cache` freezes `.env` — re-run after any env change.) **Write them as the web server user:** on the Docker host run `docker exec -u www-data -w /var/www/backend primeccookies_php php artisan …`. Caches written through `docker exec` default user (root) leave `storage/`/`bootstrap/cache` root-owned, and PHP-FPM (`www-data`) then fails to compile views at runtime — observed 2026-11-01 after REL003: `GET /up` → 500 with `Failed to open stream: Permission denied` on `storage/framework/views/*.php` while precompiled pages stayed 200. Fix: `docker exec -u root … chown -R www-data:www-data /var/www/backend/storage /var/www/backend/bootstrap/cache`, rebuild the three caches `-u www-data`, restart `primeccookies_php`.
8. **Deploy frontend** with the canonical command (§7.2).
9. **Maintenance mode OFF:** `php artisan up`.
10. **Smoke** (§7.4). Record backup path, migrations Ran, reconciliation numbers and smoke results in the checkpoint.

**Package C pending migrations (not yet applied to production):** `2026_10_02_100000_add_idempotency_key_to_order_items_table` (nullable `idempotency_key` + `UNIQUE(order_id, idempotency_key)`) and `2026_10_02_110000_add_request_fingerprint_to_order_items_table` (nullable `request_fingerprint`). Both are additive; historical rows stay NULL (many NULLs are allowed in a UNIQUE index).

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
| `php artisan warehouse:reconcile [--agent_id=]` | Read-only diagnostics of warehouse buckets, reservations, movements, requests, opnames |
| `php artisan warehouse:migrate-legacy-stock --dry-run …` | Historical legacy-stock → Transit backfill tooling (the production cutover is already done). Any non-dry run needs verified backup + explicit Human authorization |
| `php artisan transactions:reset [--dry-run] [--force]` | Destructive operational command — removes transaction-derived data while preserving audited masters/configuration and current physical stock. Warehouse/Sub-aware (knows `stock_requests`, `stock_transfers`, `sub_stock_*`, `delivery_verifications`, `inventory_cancellation_reversals`, etc.). Production: verified backup + explicit Human authorization + `--dry-run` audit first, then `--force` (§8.1). Refuses production without `--force`; `--dry-run` never mutates |
| `php artisan products:reset [--force] [--backup-verified=<reference>]` | Destructive operational command — FULL Product domain reset: the catalog **and every row that exists because of it** (orders/order items, shipments, payments, commissions, returns, stock requests, stock movements, warehouse requests/opnames/transfers, migration markers, their audit rows and evidence files). Master data and configuration are preserved and verified. Dry-run by default; `--force` is refused without a verified-backup reference, and refused outright when the schema holds a dependency the delete scope does not cover (§8.2) |
| `php artisan primeclassy:reset-dev-transactions` | DEV only (§3.1) |

### 8.1 `transactions:reset` — production procedure (destructive)

1. **Authorize + back up first:** explicit Human authorization for this step, then a verified backup (§6: database + media + `.env`).
2. **Dry-run and review the complete TRANSACTION RESET AUDIT:** `php artisan transactions:reset --dry-run`. Proceed only with no BLOCKER and no unsupported stock-movement types.
3. **Run for real:** `php artisan transactions:reset --force`, then review TRANSACTION RESET VERIFICATION.
4. **Success requires:** all covered transaction tables After=0; Remaining transaction stock movements=0; Remaining reserved-stock rows=0; Transaction file deletion failures=0; final message confirming transaction data is empty and audited master row counts are preserved.

**Stock semantics:** `product_stocks`/`product_variation_stocks` rows are preserved; transaction-derived Agent on-hand effects are restored by the command and `quantity_reserved` is cleared. `warehouse_stocks` is current physical warehouse state — its quantities are preserved exactly and never reversed; historical warehouse-domain stock movements are kept as history, not undone because transfer/request records are reset.

**Preserved:** `warehouse_settings`, `warehouse_sub_locations`, `warehouse_stocks`, `warehouse_migration_runs`, `warehouse_migration_markers`, `stock_opnames`/`stock_opname_items`, and all genuine master/configuration/catalog/user/region/payment/shipping data.

**Never substitute:** `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`, raw `TRUNCATE`, or `FOREIGN_KEY_CHECKS=0`.

**On error: STOP.** Do not retry repeatedly — investigate the exact FK/blocker. Database deletion runs in one transaction, so verify actual state before any retry. File/media deletion happens after the DB commit: if verification reports file deletion failures, the database reset did NOT roll back — investigate the file failure separately.

### 8.2 `products:reset` — full Product domain reset procedure (destructive)

> **HUMAN DECISION (LOCKED).** `products:reset` deletes ALL products **together with every relation that references them**, including warehouse transactions, stock movements, stock requests and the related operational history. This replaces the earlier policy in which warehouse history was a permanent blocker. Master data and configuration outside the product domain are preserved and verified.

`products:reset` is the single destructive operation for the Product domain. It does not delete anything outside that domain, it never edits the schema, and it never weakens a constraint to complete a delete.

**Invocation:** `php artisan products:reset [--force] [--backup-verified=<reference>]`

1. **Authorize + back up first** (§8.1 step 1): explicit Human authorization for this step, then a verified backup (§6: database **and** `storage/app/public`, because product image and transaction-evidence bytes are deleted). `--force` is **refused** without a non-empty `--backup-verified=<reference>`.
2. **Dry-run and review the whole plan:** `php artisan products:reset`. Zero database mutations. Read every per-scope row count, the derived preservation-guard size and the unmapped-dependency report.
3. **Run for real:** `php artisan products:reset --force --backup-verified="<backup reference>"`, then review PRODUCT RESET VERIFICATION.
4. **Success requires:** every delete scope `Rows after = 0`; `Preserved-domain tables checked` non-zero and `Preserved-domain counts unchanged`; `File deletion failures: 0`; `Products (incl. trashed)` and `Variations (incl. trashed)` both 0.

#### Deletion boundary

One rule: **a row goes if and only if it cannot exist without the catalog.**

| Boundary | Scope | Example |
|---|---|---|
| Product-derived rows | whole table | `order_items` (`product_id` NOT NULL), `stock_movements`, `warehouse_stock_requests`, `stock_opname_items`, `stock_transfer_items`, `sub_stock_request_items`, `sub_stock_reservations`, `warehouse_migration_markers` |
| Containers | whole table | `stock_requests`, `stock_transfers`, `stock_handovers`, `sub_stock_requests`, `stock_opnames`, `warehouse_migration_runs` — each exists only to carry product-targeted records, so there is no mixed non-product content they could keep |
| **Order aggregate** | **row-scoped** | `orders` only when it HAD order items. Its `shipments`, `payment_transactions`, `order_additional_payments`, `commissions`, `returns`, `inventory_cancellation_reversals`, `delivery_verifications`, `cod_payment_proofs` and `bank_transfer_verifications` follow **that exact order set** — never a blanket table wipe. An order with no product lines, and everything hanging off it, survives untouched |
| Targeted promotions | row-scoped | product/variation-targeted `vouchers` and `product_discounts` go; whole-catalog ones stay |
| SKU registry | row-scoped | only `catalog_skus.owner_type IN ('product','variant')` |
| Audit + evidence | row-scoped | `activity_logs` whose subject is a deleted record; media owned by a Product/ProductVariation; media in the `bank_transfer_proof` / `cod_payment_proof` / `shipment_proof` / `return_evidence` collections; legacy `bank_transfer_verifications.proof_image_path` and `returns.evidence_path` |

Consequences to review before approving: **orders, order items, shipments, payments, commissions, returns, delivery verifications and their audit rows are deleted** whenever they hang off a product order. `payment_methods` and the gateway configuration survive.

**Gateway webhook audit is row-scoped, not wiped.** `payment_webhook_logs` has no foreign key; `handleWebhook()` resolves a transaction through `(payment_method_id, gateway_reference)`, and that is the only link the table carries. The reset deletes exactly the logs whose reference matches a payment of a deleted order, and **keeps** independent gateway history: deliveries whose reference matches no transaction at all (`unknown_reference` — spoofed or attack traffic), logs of a payment whose order has no product lines, and any log with a NULL reference. The surviving count is compared before/after (`payment_webhook_logs (rows with no deleted payment behind them)`).

**Mixed documents:** an order with lines from several products goes entirely (every line references the catalog). Nothing is half-deleted: `order_items.split_from_order_item_id` (RESTRICT self-reference) and `order_items.shipment_id` / `additional_payment_id` (SET NULL) are broken in the same transaction, and children are always deleted before parents.

#### Preconditions — the run is refused, not attempted

- **`--force` without `--backup-verified`** → refused, zero mutations.
- **Unmapped dependency** → refused, zero mutations. Three checks against the live schema:
  1. a **preserved** table with a foreign key into a table this command empties **completely** (RESTRICT/NO ACTION would abort, SET NULL would rewrite a preserved row, CASCADE would remove unreported rows);
  2. a **preserved row** whose foreign key points **into a partial delete scope** — a user avatar that happens to be a product-owned media row is refused, an ordinary avatar is not;
  3. any table carrying a `product_id` / `product_variation_id` target column that is **not** part of the delete scope.

  A later migration that adds such a dependency therefore stops the reset instead of silently orphaning or rewriting preserved data. The report names the exact table, column, parent table and delete rule.

#### Pre-P3 audit on DEV — completed 2026-10-08, then executed and verified

A pre-reset safety gate was run on `primeclassy_dev` (fresh backup + isolated restore test + scope audit + a second dry-run), the findings below were reviewed, and `--force` was then executed and **independently verified** against the backup: 77 tables byte-identical, exactly the 19 audited tables emptied, exactly the 56 audited activity logs removed with 0 collateral, **37/37 preservation-guard tables identical on both `COUNT(*)` and `CHECKSUM`**, branding files SHA-256-identical, FK unchanged at 199 with 0 added / 0 dropped. Recorded findings from the audit phase:

- the `activity_logs` delete scope is **type-based, not id-based**, so it removes logs about products that were deleted earlier as well as logs about the products being deleted now. On DEV today **0** in-scope logs belong to a surviving record, so the blast radius is correct — but the mechanism is wider than the documented rule. Treat it as technical debt, not as a bug to hot-fix.
- deleting a product order destroys a real recorded money movement: order 3 is `partially_paid` and its `payment_transactions` row is **`paid` with a verified bank transfer**. Accept this knowingly.
- `catalog_skus` and `warehouse_stocks` become **completely empty** (every SKU and every warehouse stock row is product/variant-owned).
- 26 orphaned product image files already on disk are **not** reclaimed: file deletion is row-driven and `product_images` has 0 rows. **Confirmed after the run** — `storage/app/public` still holds all 33 files. Reclaiming orphan bytes is a separate, separately authorized cleanup.

Backup reference and the full audit: `docs/PRODUCT-RESET-REMEDIATION-CHECKPOINT.md` revision 8.

#### Preserved and verified

Every other table in the database, compared before/after. The guard is **derived from the live schema**, so a table added by a later migration is protected without a code change: users, roles, `user_closures`, `agent_profiles`, konsumens and `konsumen_addresses`, `product_categories`, provinces/regencies/districts/villages, languages, settings, CMS, media that is neither product-owned nor transaction evidence, shipping/payment providers, `invoice_configs`, Sheets and OAuth configuration, `warehouse_settings` and `warehouse_sub_locations` (containers, not stock), couriers. Infrastructure tables a live application may write during the run (`cache`, `cache_locks`, `sessions`, `jobs`, `job_batches`, `failed_jobs`, `personal_access_tokens`, `migrations`) are counted and reported but never enforced.

#### Never substitute

`migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`, raw `TRUNCATE`, `FOREIGN_KEY_CHECKS=0`, disabling foreign-key constraints, dropping tables, or deleting master/configuration rows to satisfy a foreign key.

#### Ordering with `transactions:reset`

`products:reset` now covers the product side of everything `transactions:reset` deletes, so **the two are not complementary any more** — `products:reset` alone empties the catalog and its derived history. Use:

- `transactions:reset` to clear transactions **while keeping the catalog**;
- `products:reset` to clear the catalog **and everything that depends on it**.

Never run `transactions:reset` between a `products:reset` dry run and its `--force` run: the blocker set changes underneath the plan. If warehouse history is the reason a catalog reset was previously refused, that refusal is now obsolete — run `products:reset`.

#### Schema drift matters here

The unmapped-dependency gate reads the **live** schema, so its verdict is only as strong as the database it runs against. On `primeclassy_dev` the `migrations` table lists all 128 migrations as applied while the schema is missing **97 declared foreign keys** across 24 tables (full inventory and restoration plan: `DEV-SCHEMA-DRIFT-REMEDIATION-PLAN.md` — deleted 2026-10-28 by Owner-approved documentation hygiene; completion evidence below and the per-constraint lists inside the three `2026_10_08_*restore*` migration files remain the canonical record; verbatim copy: `C:\Users\KMSIT Komputer\AppData\Local\Temp\opencode\doc-backups\DEV-SCHEMA-DRIFT-REMEDIATION-PLAN-20261028.md`, git hash-object `f06a5a1fd85101bcf077969790f56551a9d9c470`) — the whole warehouse-era constraint set (`warehouse_stocks`, `stock_movements`, `stock_requests*`, `stock_transfers*`, `stock_opnames*`, `sub_stock_*`, `warehouse_migration_*`, `warehouse_stock_requests`) plus `users.*` (self/role/avatar), `user_closures`, `shipping_configurations`, `sheets_*` and `villages.district_id`. **Nothing is pending — the DEV schema simply does not match its migrations. (A further 6 constraints exist on both databases but are declared `NO ACTION` instead of `RESTRICT`; InnoDB-equivalent for deletes, no action required.)**

Consequence: a DEV `--force` run is **not** protected by foreign keys, so a child-before-parent mistake would not be caught by the database there. Two safeguards apply instead: the focused regression tests run against the fully migrated `primeclassy_testing` schema (which does enforce every FK), and the command's own row-count verification decides the verdict. Never read "no FK errors on DEV" as evidence that the ordering is correct — read the testing-schema test result. Treat the dry run on DEV as a count report, not as a constraint proof.

The DEV restoration is complete: P1, P2 and P5 Ran; the 220 unrelated historical orphan references remain unchanged. **Production has not been inspected or authorized for these migrations.** See §8.3 for the actual DEV state and the separate production hold.

#### On error: STOP

A failure inside the transaction rolls the database back in full (retried once by the driver, then reported). File/media deletion happens only after the commit, so a reported `File deletion failures` / `skipped-unsafe` entry means the database reset is already complete and only the bytes were left behind — investigate the path separately, never re-run blindly.

### 8.3 DEV foreign-key restoration — three windows, executed in order (schema change)

> **CURRENT DEV STATE (verified 2026-10-08): P1, P2, P3, P4a, P4b and P5 are complete.** DEV has 204/204 FKs. P4a removed the approved orphan closure edge; P4b preserved its row but nulled/deactivated its missing Agent; P3 reset the product domain; P5 restored the final 5 FKs. The 220 unrelated orphan references remain untouched, as do 26 orphan product image files. Historical status statements below are superseded by this current state and retained as execution history. Production remains **HOLD**: its schema/data have not been inspected, no production migration/deploy is authorized, and nothing here authorizes production execution.

Restores the 97 constraints that DEV's `migrations` table claims are applied but the schema does not carry. Three forward-only migrations, one per execution window, each idempotent and each **fail-closed before any DDL**:

| Window | Migration | Constraints | Blocking |
|---|---|---|---|
| **P1** | `2026_10_08_110000_restore_pre_reset_foreign_keys` | 91, zero orphans | ✅ **ran on DEV, verified (96/96 business tables identical)** |
| **P2** | `2026_10_08_111000_restore_villages_district_foreign_key` | `villages.district_id` — the only table rebuild | ✅ **ran on DEV in 878 ms, verified (96/96 business tables identical)** |
| **P5** | `2026_10_08_112000_restore_post_cleanup_foreign_keys` | the 5 constraints blocked by orphans | after `products:reset` + the two authorized single-row deletions |

Historical DEV execution order was **P1 → P2 → `products:reset` (§8.2) → approved P4a closure cleanup → approved P4b configuration update → P5**. All completed on DEV and verified; see the current-state summary below. This sequence is not a production instruction.

**DEV completion evidence:** P1 107→198, P2 198→199, P5 199→204; all 204 constraints verified and the six approved NO ACTION/RESTRICT differences retained. P3/P4a/P4b completed under their separately authorized DEV steps. The 220 remaining historical orphan references (seven other FKs) and 26 orphan image files are explicitly out of scope and unchanged. Latest details: [PRODUCT-RESET-REMEDIATION-CHECKPOINT.md](PRODUCT-RESET-REMEDIATION-CHECKPOINT.md).

```bash
cd /www/dev/primeclassy/backend

# HISTORICAL DEV COMMANDS ONLY — already executed; do not replay on DEV.
# Production is on HOLD; these commands are not production authorization.
# one window at a time — never `php artisan migrate` bare, and never two at once
php artisan migrate --force --path=database/migrations/2026_10_08_110000_restore_pre_reset_foreign_keys.php
php artisan migrate --force --path=database/migrations/2026_10_08_111000_restore_villages_district_foreign_key.php
php artisan migrate --force --path=database/migrations/2026_10_08_112000_restore_post_cleanup_foreign_keys.php
```

**Properties to rely on**

- The existence check reads `information_schema.REFERENTIAL_CONSTRAINTS`, **never the `migrations` table** — that divergence was the defect being fixed. Existing same-named constraints are also compared by child column, referenced table/column, and ON DELETE/ON UPDATE rules; any mismatch refuses the entire window before DDL. On a correctly migrated database all three files are a no-op. Production safety is **not inferred** from DEV/testing and remains subject to the separate production hold above.
- A blocker anywhere in a window aborts **the whole window with every blocker named and zero mutations**, instead of InnoDB's errno 1452 landing after some constraints already committed.
- Foreign-key validation stays **ON**. `ALGORITHM=INPLACE` without a rebuild is only available with `foreign_key_checks = 0`, and in that mode existing rows are not validated — the database would then hold rows that violate the constraint it claims to enforce. **Never** weaken checks to get a migration through.
- No constraint is added without an existing leading index, so no migration builds an index nobody reviewed.
- The migrations **change schema only**. They never delete the orphan rows; the two authorized single-row deletions are separate operational steps with their own backup.

**Rollback**

- Schema: `ALTER TABLE <t> DROP FOREIGN KEY <name>` — metadata-only, instant, exact (no phase rewrites a row, column, default or index).
- Data: only `user_closures` id 11 and `shipping_configurations` id 1 are ever deleted; the dedicated `pre-delete-user_closures-and-shipping_configurations.sql` dump in the backup restores both verbatim. **P4a has been executed on DEV** (snapshot in `~/primeclassy-dev-backups/p4a-user-closures-orphan-20261008-182017/`), so that row is restorable only from those pre-P3 dumps, not from a fresh one. If a constraint-add fails after the deletion, **restore the rows first** and let the migration retry — never drop the constraint first, because `user_closures`' `ON DELETE CASCADE` is what keeps future user deletions from silently corrupting the hierarchy.
- `down()` is **intentionally empty** in all three files: `migrate:rollback` un-records a file without reversing its DDL, so a rollback can never drop protections silently. Git-level rollback of these files is therefore not a schema rollback — use the `DROP FOREIGN KEY` list or the dump restore.
- Full escalation: restore the verified dump and stop. Re-verify against `row-manifest.tsv` and `foreign-keys-before.tsv` before resuming.

**Production HOLD:** production FK/schema/data state is unknown. Do not include or execute these migrations in production without a separately authorized production package, read-only schema/orphan preflight, verified production backup plus isolated restore, production-clone lock measurement, reviewed pending migration set and explicit Human authorization for each mutation. DEV results do not establish production safety. Do not repair the 220 DEV orphans or remove the 26 files as part of this work.

### 8.4 Refund ledger backfill (P4) and reporting read-switch (P5)

**Current operational boundary (enforced in code for this phase):** `--apply` is hard-refused unless
the connected database name ends in `_test`/`_testing`. It therefore cannot run on production or on
DEV today — that is the standing rule, not an oversight. A production apply is **not available** in
this phase; §8.4's "future production backfill" note below is a design sketch only and grants no
permission.

1. Migrate (additive) and deploy the code with `REFUNDS_REPORTING_SOURCE=legacy` (default).
2. Dry-run on a copy/restored snapshot (read-only; writes only the batch row):
   `php artisan refunds:backfill-ledger --batch=refund-ledger-v1`
   Review the table: `snapshots_pending/created`, `payouts_pending`, `verification_pending`,
   `duplicate_keys`, `unallocatable_orders`, `unexplained_cash`.
3. Acceptance gate: unallocatable/unexplained counts explained; duplicate keys are rerun noise only;
   `deferred=0` for historical rows; G = P + A checked per order.
4. Apply on the **isolated test database only**, after explicit Human approval:
   `php artisan refunds:backfill-ledger --apply --batch=refund-ledger-v1`
   The same batch key resumes with insert-or-skip; a rerun reports `duplicate_keys` without mutation.
5. Verify: order `gross_received_amount = paid_amount + Σ(affects_paid_amount payouts)`, payouts are
   `legacy_unverified`, no `paid_amount`/`total_amount` change, no duplicate legacy keys.
6. Only then flip `REFUNDS_REPORTING_SOURCE=ledger`, clear config cache and check the four decoupled
   metrics (gross sales / net sales reduction / net revenue / R_c). Rollback = set `legacy` again;
   ledger rows remain (insert-or-skip on rerun) and the additive migrations have `down()`.
   Flipping this flag on DEV or production is its own Owner-gated step; it is not implied by a
   successful test-database backfill.

**Future production backfill — design only, NOT AUTHORIZED.** Reaching production would require a
separate approved package that, at minimum: removes/relaxes the `_test`-suffix guard through a
reviewed code change, takes and restore-tests a verified production backup, reviews a dry-run
reconciliation on a restored snapshot, schedules a maintenance window, defines rollback/recovery, and
obtains explicit Human authorization per step. Nothing in this runbook authorizes that window today.

Legacy processed returns become `needs_verification` obligations; they are **not** paid
automatically. Resolve them through the Owner evidence workflow. A return approved before payment
leaves a deferred container that only Owner materialization can pay (documented boundary).

## 9. Integrations — operational notes

- **Google Sheets:** one central service account (Google Cloud project with the Sheets API enabled; key JSON stored privately, e.g. `backend/storage/app/private/google/service-account.json` with restrictive permissions, never in Git/`public/`). Set `GOOGLE_SHEETS_ENABLED=true` + `GOOGLE_SHEETS_CREDENTIALS_PATH`, then `config:cache`. Share each destination spreadsheet with the service-account email as **Editor**. Use **Periksa koneksi** (read-only `spreadsheets.get`) in `/dashboard/google-sheets`. Common errors: `PERMISSION_DENIED` (share missing), `API_DISABLED`, `SPREADSHEET_NOT_FOUND`, `RATE_LIMIT`. Sync is manual, one-way, ≤ 10,000 rows, replaces the tab's values; never a database backup. Rotate the key if exposure is suspected.
- **Dua laporan PrimeClassy (Google Sheets):** dataset `order_initial_transactions` = Laporan A "Transaksi Awal" (snapshot per item, 24 kolom), dataset `order_payment_positions` = Laporan B "Posisi Pembayaran Terkini" (posisi live per faktur, 15 kolom). Kontrak kolom dan aturan uangnya di [BUSINESS-RULES.md](BUSINESS-RULES.md) §20a; arsitekturnya di [ARCHITECTURE.md](ARCHITECTURE.md) §12.1.
  - Aktivasi di DEV (butuh persetujuan Owner, urutan tidak boleh diacak): (1) jalankan migration `2026_10_21_100000_create_order_initial_report_items_table` + `2026_10_21_110000_add_payment_method_snapshot_to_order_initial_report_items_table` pada database DEV; (2) deploy/refresh kode backend DEV; (3) daftarkan spreadsheet tujuan (destinations) dan bagikan ke service account; (4) buat **dua** konfigurasi (satu per laporan, tab berbeda pada spreadsheet yang sama) dengan dataset + pemetaan kolom default; (5) uji **Periksa koneksi**, lalu **Sync**; (6) verifikasi baris di sheet.
  - Filter periode (harian/mingguan/bulanan/kustom) diatur per konfigurasi; periode relatif dihitung saat sync pada kalender WIB (Asia/Jakarta) dan batasnya dikonversi ke UTC untuk query (database tetap UTC). Bila sebuah laporan melewati 10.000 baris, sync **gagal** dengan pesan "Persempit periode filter lalu sinkronkan ulang" dan tab **tidak** ditulis — tidak ada pemotongan senyap.
  - Laporan A hanya memuat order yang dibuat **setelah** snapshot aktif; order lama tidak direkonstruksi. Laporan B memuat order lama dan baru.
  - Laporan A memakai `ROW_NUMBER()`: deployment memerlukan **MariaDB ≥ 10.2 atau MySQL ≥ 8.0**; catat versi database produksi pada preflight.
  - `transactions:reset` / `products:reset` ikut menghapus baris snapshot (`order_initial_report_items`) sebagai bagian dari scope order — keduanya sudah dipetakan dan tidak lagi menolak jalan.
- **RajaOngkir / OpenRoute:** each Agent configures credentials in its dashboard; keys are never returned by the API. External payment gateways and their webhooks were removed (Owner decision). OpenRoute base URL is server-controlled. Rotate a RajaOngkir key that was ever exposed in a log or trace.
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
| RajaOngkir options 422 | Agent key/origin/courier codes, product weight, or incomplete buyer village hierarchy |
| `MAC is invalid` in old logs | Historical `APP_KEY` change; not an active defect |
| Stale config after editing `.env` | Run `php artisan config:clear` (and re-cache in production) |

## GPS reverse geocoding — Human-approved Nominatim default

**HUMAN BUSINESS DECISION — APPROVED (2026-10-06).** `GEOCODE_PROVIDER=nominatim` is the live default, no API key. `GEOCODE_BASE_URL` can switch to another Nominatim deployment without a code change. HTTPS only; `GEOCODE_TIMEOUT` (default 4 seconds, bounded 1–10), `GEOCODE_CONNECT_TIMEOUT` (default 2), `GEOCODE_USER_AGENT` (default PrimeClassy + APP_URL) and `GEOCODE_CACHE_STORE` are ordinary backend configuration. Set APP_URL to the public application address so identification is meaningful. No browser provider calls, background jobs, bulk queries, autocomplete or HTTP retries.

Public Nominatim usage must follow [its usage policy](https://operations.osmfoundation.org/policies/nominatim/): application-wide maximum one outbound request per second, identifying User-Agent, cache and visible OSM attribution. The adapter defaults to shared file cache for this single-host deployment, caches successful coordinates for 24 hours and response failures/no-match for 60 seconds, and falls back immediately when its atomic global lock/rate gate is busy. `GEOCODE_MIN_INTERVAL` cannot lower the one-second floor. Multiple hosts must use one shared Redis/database cache store, not host-local file caches. Shared lock/rate storage failure is manual fallback. No credentials are required or written.

Automated tests fake HTTP and use isolated cache; no live provider is called. Network outage, timeout, non-2xx, malformed/non-Indonesian response, no unique master chain and rate gating preserve manual entry. Canonical ids always come from PrimeClassy master regions; external OSM ids are not persisted. Reverse geocoding is approximate and regional coverage may produce no match; the user can edit the prefill. See [Nominatim reverse API](https://nominatim.org/release-docs/latest/api/Reverse/).


## WhatsApp Gateway (GOWA) — operational notes

- **Per-Agent devices (2026-10-29).** Migration `2026_11_01_100000_create_agent_whatsapp_devices_table` (additive) was applied to `primeclassy_dev` after a backup (`C:\Users\KMSIT Komputer\primeclassy-backups\primeclassy_dev-pre-agent-wa-devices-14480518-102657.sql`); production: not applied. Each Agen pairs its own number at `Pengaturan › WhatsApp Saya` (QR or pairing code). The Super Admin only has the global ON/OFF; there is no cross-Agent device management by design. A stale/forgotten slot shows as `Belum terhubung`; messages of an Agent without a paired device are skipped (`agent_device_not_ready`) and visible in its history.
- **SMTP (2026-10-29).** Super Admin: `Pengaturan › Integrasi · SMTP`. Saving does not switch it on; tick the switch to override `.env`. Turn it off to return to `.env` instantly. Never test with an address that is not your own.
- **Retention minimisation:** `php artisan primeclassy:whatsapp-retention-minimize` (dry-run). Real run only with explicit Owner authorisation: verified backup first, then `--execute --backup-confirmed`. Do NOT schedule it without a separate approval.
- **AR evidence:** `php artisan primeclassy:ar-reconciliation-report` (read-only).

- **Retention dry-run:** `php artisan primeclassy:whatsapp-retention-report` (read-only JSON: eligible counts, protected evidence, legal-hold state). There is no execute option and no schedule; do not add one without Owner approval, a verified backup and a fresh dry-run.
- **Volume limits:** Koneksi tab (`Batas pesan per penerima / jam`, `… seluruh gateway / menit`; defaults 6 and 20). A message held by a cap shows `rate_limited:*` in the Riwayat tab and is retried by its delayed job.
- **`GoogleAuthTest` in a local CLI:** the test generates an RSA key with PHP OpenSSL. If `OPENSSL_CONF` is unset PHP cannot find `openssl.cnf` (`error:07000072 … no such file`) and the whole class fails with "Unable to generate Google test RSA key". Set it for the session, e.g. PowerShell: `$env:OPENSSL_CONF='C:\ServBay\packages\php\8.3\extras\ssl\openssl.cnf'` (matches the PHP in use; verified: 33/33 `GoogleAuth` tests pass). No application code is involved.

- **Queue worker (DEV, 2026-10-29).** `QUEUE_CONNECTION=database`; no worker is kept running. Inspect first (`jobs`, `failed_jobs` counts, `php.exe` processes), then run a bounded, dedicated one only: `php artisan queue:work database --stop-when-empty --max-jobs=10 --max-time=60 --tries=1`. Never run it against production or another application's queue. A job in backoff keeps its row in `jobs` — delete it deliberately (and close its `whatsapp_messages` row) before restoring a different gateway URL, so it cannot fire at the wrong target.
- **Migration `2026_10_31_100000_create_whatsapp_messages_table`** was applied to `primeclassy_dev` only (single `--path`, after a backup at `C:\Users\KMSIT Komputer\primeclassy-backups\primeclassy_dev-pre-whatsapp-messages-14480518-070822.sql`). Production: not applied; needs its own backup and authorisation.

- **Where it is configured:** dashboard `Pengaturan › Integrasi · WhatsApp` (Super Admin only) — Base URL, API username/password (encrypted, write-only), optional Device ID. There are no WhatsApp values in `.env`.
- **Local DEV:** GOWA runs as container `primeclassy-gowa-dev` (`C:\ServBay\www\primeclassy-gowa-dev`, API `http://127.0.0.1:3001`, credentials in that folder's `.env`). Do not touch other GOWA containers (`sapakita-gowa`, `kmsitaics-dev-gowa-1`) or their volumes/sessions.
- **Production (NOT authorised yet):** plan `https://wagateway.kmsitcomputer.com`. Switching is configuration only: change Base URL/credentials in the console. DNS/VPS/production changes are separate, Owner-approved steps.
- **Queue worker:** notifications and retries run on the `database` queue; a worker (`php artisan queue:work`) must be running or messages stay `queued`. OTP and test messages are delivered synchronously.
- **Migration:** `2026_10_31_100000_create_whatsapp_messages_table` (one new table, additive). Until it is approved and applied the notifier is inert and the console shows "migration pending". Before applying: backup, `migrate --pretend`, apply that single file, verify.
- **Reading the log:** `queued` waiting/retrying · `sent` provider acknowledged · `failed` definite failure · `unknown` may or may not have been delivered (check the phone BEFORE retrying) · `skipped` deliberately not sent (reason shown).
- **Real messages in DEV** go only to Owner-authorised numbers (console → "Nomor uji yang diizinkan"). Never point DEV at production customers.

### KMSIT Routing Server — Kurir Toko routing fallback (2026-11-04, DEV implemented; live gateway verified)
- **What it is.** A transparent distance fallback for the existing *Kurir Toko* quote, not a checkout method and not RajaOngkir. Order: ORS (enabled, credentialed, healthy) → the Agen's own KMSIT endpoint → fail closed (422, no fabricated distance or price). The tariff (minimum distance, per-km, minimum charge, free-shipping override, server requote) is unchanged and shared (`KurirTokoTariff`); the quote still reports provider `openroute` with `meta.routing_source` = `openrouteservice` | `kmsit`.
- **Contract (LOCKED baseline `openapi.yaml` 1.1.0; `App\Services\Shipping\KmsitDistanceCalculator`).** `GET {base}/api/v1/routes?profile=car|motorcycle&coordinates=lon,lat;lon,lat` with header `X-API-Key` (write-only per-Agen key, encrypted at rest). 200 `{request_id, profile, distance_m, duration_s, …}`; the error envelope maps to safe machine reasons. Native OSRM paths/Bearer auth are NOT spoken; a legacy saved profile `driving` maps to `car`. LIVE verified 2026-11-04: HTTP 200, 12,216.3 m / 659.4 s against `routing.kmsitcomputer.com` through the stored DEV config (Agen 11). Gateway coverage is Bandung Raya (contract bbox) — outside it the gateway answers VALIDATION_ERROR and PrimeClassy fails closed.
- **Config.** Each Agen saves its own `base_url` (gateway origin; a trailing `/api/v1` is tolerated, never doubled), profile and the write-only key at *Penyedia Pengiriman*; the tariff is edited on the separate *Pengaturan Tarif Kurir Toko* card/endpoint, independent of ORS/KMSIT state. Super Admin sees only the global ON/OFF row (`kmsit`, **inactive by default**). Migration `2026_11_04_100000_register_kmsit_shipping_provider` (additive, inserts the inactive row only) is applied to DEV (batch 42) and **pending on production** — apply it with this release. The seeder never overwrites an existing row's activation state.
- **SSRF policy** (`App\Support\EndpointGuard`, enforced on save, test and every use): http(s) only, no userinfo/query, no localhost/loopback/link-local/metadata/private ranges (every resolved address checked), vetted IP pinned for the request, ambient proxies disabled, redirects off. A private or plain-http server is allowed only if its exact host is in `KMSIT_TRUSTED_HOSTS` (operator env; metadata/link-local stay blocked even then).

### WhatsApp management by Agen / Admin Agen (2026-11-04, DEV implemented)
- Routes `/agent/whatsapp/*` now allow `agen` and `admin` (scope resolved server-side, see BUSINESS-RULES §28); new: `scope`, `events`, `test-numbers`, `test-send` (registered-number allow-list, the target Agen's own paired slot only, 3/min & 20/day, ≤5 numbers). Migration `2026_11_04_100100_create_agent_whatsapp_scoped_settings` (two additive tables) is applied to DEV (batch 42) and **pending on production** — apply it with this release; until then scoped settings fail closed (503) and the legacy global behaviour continues unchanged.
- The Super Admin console no longer has the Notifikasi (templates/events) tab; its backend endpoints remain only as the platform-default fallback.

### Notification contract — email & in-app (2026-11-02, branch `docs/notification-contract-v1`)
Contract and status: [PRIMECLASSY-MASTER-NOTIFICATION-CONTRACT.md](PRIMECLASSY-MASTER-NOTIFICATION-CONTRACT.md) §24.
- **Migrations (additive, pending on production):** `2026_11_05_100000_create_email_messages_table`, `2026_11_05_100100_create_admin_notifications_table`. Until applied, e-mail and the in-app centre stay inert (the API answers `migration_pending`).
- **Transactional e-mail:** Super Admin → SMTP → "Kirim email transaksi" (`smtp.transactional_enabled`, **default OFF**; also needs SMTP ON + complete). Recipients are branch admins and order creators with a **verified** e-mail only. Jobs run on `primeclassy-notify` (same worker); no automatic retry — failures stay `failed` in `email_messages` and raise an in-app alert.
- **New WhatsApp/e-mail events** (`payment_failed`, `return_rejected`, `refund_requested`, `refund_approved`, `refund_rejected`) are OFF until switched on per Agen. New default texts apply immediately to events that are already ON and still use the default template.
- **Order links** use `APP_FRONTEND_URL` (falls back to `APP_URL`; production is single-domain, no change needed) and the numeric `/orders/{id}` route. Re-run `config:cache` after changing it.
- **In-app centre:** `/dashboard/notifications` (Super Admin, Agen, Admin; own rows only). Retention: `php artisan primeclassy:admin-notifications-prune` (dry-run) / `--execute`; **not scheduled** — running it on production is an Owner-authorised step.
