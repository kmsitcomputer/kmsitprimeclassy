# Prime Classy Cake & Cookies

Production e-commerce platform for a multi-branch cake & cookies business, built as a Laravel 11 API backend with a separate Vue 3 (Vite) single-page-application frontend.

This document reflects the codebase as it actually exists today. It does not describe planned or partially-built features as finished — where something is incomplete, that is stated explicitly (see **Known gaps** at the end of each relevant section, and the project's `backend/SECURITY_AUDIT.md` / `FINAL_AUDIT_REPORT.md` for full detail).

---

## 1. Project overview

Prime Classy Cake & Cookies is a multi-agent (multi-branch) cake and cookies ordering platform. Each **agen** (agent/branch) runs its own storefront presence, stock, staff hierarchy, and delivery operation, while a single **super_admin** oversees the whole platform. Customers (**konsumen**) browse a shared product catalog, are attributed to a branch via a referral chain (agen → korsal → sales → konsumen), and can check out with courier, COD, manual bank transfer, or a payment gateway (Xendit, Tripay, or Stripe).

## 2. Features

Implemented and working today:

- Session-based (Sanctum SPA cookie) authentication, 7 roles: `super_admin`, `agen`, `korsal`, `sales`, `konsumen`, `admin`, `kurir`.
- Role hierarchy with referral-code-based account creation (agen → korsal/sales, korsal → sales, sales/agen/korsal → konsumen).
- Per-agent branch data isolation, enforced at query, policy, and service layers.
- Catalog: products with optional variations, per-agent stock, per-product/variation fee configuration (agent fee, sales fee, courier fee).
- Shop, cart, wishlist, checkout with delivery-date selection and a choice of courier ("kurir online", distance-priced) or expedition ("ekspedisi"/RajaOngkir) shipping.
- Full order lifecycle: creation, per-item fulfillment adjustment, cancellation, per-item return/refund, additional-payment collection, per-shipment courier delivery tracking with photo proof.
- Payment: Cash-on-delivery (with photo-proof + admin confirmation), manual bank transfer (with photo-proof + admin verification), and 3 payment gateways (Xendit, Tripay, Stripe) with signature-verified webhooks.
- Shipping: OpenRoute (real road-distance courier pricing) and RajaOngkir (expedition rate lookup), both admin-configurable, both with a safe fallback (Haversine straight-line distance) if the provider is unavailable.
- CMS: homepage content blocks, articles/news, static pages — all edited via a CKEditor 5 rich-text editor and sanitized server-side before storage.
- Media upload pipeline with real MIME/type verification (not just extension checking).
- Public website settings and a public "Contact Agent" directory.
- 4-language backend message catalog (Indonesian, English, Arabic, Chinese).
- Excel (.xlsx) export for 5 management reports (transactions, sales/korsal fees, cancellations & refunds, courier fees, agent fees).
- A full backend audit trail (`ActivityLogger` → `activity_log` table) recording who did what, when, from which IP/user agent.
- 236 automated backend tests (Feature-level, hitting real routes/policies/services against a real test database).

**Known gaps** (not implemented — see the checklist in `FINAL_AUDIT_REPORT.md` for the authoritative, up-to-date status of every feature area):
- No installer wizard (`/install`) — the backend is set up via the standard Laravel `.env` + `artisan migrate` flow described below, not a web-based first-run wizard.
- No dedicated per-role dashboard frontend (Super Admin / Agen / Korsal / Sales / Admin / Kurir each need a real landing dashboard with navigation; today only a handful of individual admin settings pages exist — payment gateways, shipping settings, CMS, refunds/returns/additional-payments). Every backend API these would call already exists and is tested; only the frontend UI is missing.
- No frontend dark-mode toggle and no frontend automated test suite yet.
- No frontend language-switcher UI (the backend serves all 4 locales; the storefront currently renders in one locale at a time via `Accept-Language`/session state, with no visible switcher control).

## 3. Architecture

```
┌─────────────────────┐        HTTPS / JSON        ┌──────────────────────┐
│  Vue 3 SPA (Vite)    │ ─────────────────────────► │  Laravel 11 API      │
│  frontend/           │ ◄───────────────────────── │  backend/            │
│  served as static    │      Sanctum session        │  served by PHP-FPM   │
│  files by Apache/    │      cookie + CSRF          │  behind Apache/Nginx │
│  Nginx/any static     │                             │                      │
│  host                │                             └──────────┬───────────┘
└─────────────────────┘                                         │
                                                                  ▼
                                                          ┌───────────────┐
                                                          │  MySQL 8.0+   │
                                                          └───────────────┘
```

The frontend and backend are two independent deployables. The frontend is a static build (`frontend/dist/`) that talks to the backend purely over HTTP JSON — it is never rendered by Laravel/Blade. The backend never serves HTML pages other than its own `public/index.php` front controller.

Authentication is Sanctum's **SPA cookie** mode (not bearer tokens): the frontend and backend must share a registrable domain (or be proxied under one origin) for the session cookie to work, and both CORS (`FRONTEND_URLS`) and Sanctum's own stateful-domain list (`SANCTUM_STATEFUL_DOMAINS`) in `backend/.env` must list the frontend's exact origin — see §10, both are required, and missing either one breaks login differently (see §27).

## 4. Technology stack

**Backend**
- PHP 8.2+, Laravel 11
- MySQL 8.0+ (developed/verified against MySQL 8.4)
- Laravel Sanctum (SPA session auth)
- HTMLPurifier (`ezyang/htmlpurifier`) for CMS content sanitization
- PhpSpreadsheet (`phpoffice/phpspreadsheet`) for Excel export
- PHPUnit (Feature tests)

**Frontend**
- Vue 3 (Composition API), Vite
- Vue Router, Pinia
- Tailwind CSS v4
- CKEditor 5 (rich-text editor for CMS content)
- Axios

## 5. System requirements

- PHP **8.2 or newer**, with the extensions Laravel 11 requires (`pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo`, `gd` or `imagick` for image processing).
- Composer 2.x
- MySQL **8.0 or newer**
- Node.js **22.18+ or 24.12+** (see `frontend/package.json` → `engines`), with npm
- Apache 2.4+ (with `mod_rewrite`) or Nginx — either works; Apache-specific configuration is documented below since that's what this project ships `.htaccess` for.

## 6. Directory structure

```
primeclassy/
├── README.md                  # this file
├── .gitignore                 # root-level safety net (.env patterns, editor cruft)
├── backend/                   # Laravel 11 API
│   ├── app/
│   │   ├── Http/Controllers/Api/V1/   # one namespace per feature area
│   │   ├── Http/Requests/             # FormRequest validation, one per endpoint
│   │   ├── Http/Resources/            # JSON response shaping
│   │   ├── Models/                    # Eloquent models + global scopes
│   │   ├── Policies/                  # per-model authorization
│   │   ├── Services/                  # business logic (Order, Payment, Stock, Fee, Shipping, ...)
│   │   └── Support/                   # ApiResponse envelope, PermissionMap, HierarchyRules
│   ├── config/                        # Laravel config, incl. media.php (upload policy)
│   ├── database/
│   │   ├── migrations/                # schema history (source of truth)
│   │   ├── factories/                 # test-only data factories
│   │   └── seeders/                   # roles/languages/settings/payment methods/shipping providers — no demo users or business data
│   ├── database.sql                   # structure-and-seed-only MySQL dump (see §25)
│   ├── lang/                          # id / en / ar / zh message catalogs
│   ├── public/                        # Apache/Nginx document root — index.php front controller + .htaccess
│   ├── routes/api_v1.php              # the entire API surface
│   ├── tests/Feature/                 # 236 automated tests
│   ├── SECURITY_AUDIT.md              # prior security audit + fixes
│   ├── FINAL_AUDIT_REPORT.md          # final master audit (this pass)
│   ├── .env.example                   # documented, secret-free environment template
│   └── .env                           # real local config — NEVER committed (gitignored)
└── frontend/                  # Vue 3 SPA
    ├── src/
    │   ├── views/              # route-level pages (shop, checkout, order detail, admin/*)
    │   ├── components/         # shop/, checkout/, ui/ reusable components
    │   ├── api/                 # one file per backend resource area (orders.ts, payments.ts, ...)
    │   ├── router/index.ts      # all frontend routes + auth/role guards
    │   └── stores/               # Pinia stores (auth, cart, wishlist, ...)
    ├── vite.config.ts
    ├── .env.example              # VITE_API_URL, VITE_GOOGLE_MAPS_API_KEY
    └── .env                      # real local config — NEVER committed (gitignored)
```

## 7. Backend installation

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

Then configure `.env` (see §10) and continue with §9/§24 (MySQL + migrations) before starting the dev server:

```bash
php artisan serve   # http://localhost:8000
```

## 8. Frontend installation

```bash
cd frontend
npm install
cp .env.example .env
```

Set `VITE_API_URL` in `frontend/.env` to wherever the backend is reachable (default `http://localhost:8000`), then:

```bash
npm run dev   # http://localhost:5173
```

## 9. MySQL setup

Create an empty database and a dedicated user (do not use `root` in production):

```sql
CREATE DATABASE primeclassy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'primeclassy'@'localhost' IDENTIFIED BY 'a-strong-password-here';
GRANT ALL PRIVILEGES ON primeclassy.* TO 'primeclassy'@'localhost';
FLUSH PRIVILEGES;
```

Then point `backend/.env`'s `DB_*` keys at it (see §10). You can either run migrations fresh (§24) or import the provided schema dump (§25) — pick one, not both.

## 10. Environment configuration

All configuration lives in `backend/.env` (copied from `backend/.env.example`) and `frontend/.env` (copied from `frontend/.env.example`). **Neither `.env` file is ever committed** — both are listed in their respective `.gitignore`, and the root `.gitignore` adds a safety net for the same pattern. Only `.env.example` (secret-free, placeholder values only) is committed.

| Concern | Where it lives | Key(s) |
|---|---|---|
| App key / crypto | `backend/.env` | `APP_KEY` (generate with `php artisan key:generate`, never share it) |
| Database | `backend/.env` | `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` |
| Payment gateway credentials (Xendit / Tripay / Stripe) | **Database**, not `.env` | Set via the Super Admin → Payment Gateways admin screen (`PUT /admin/payment-gateways/{method}/config`); stored encrypted in the `payment_gateway_configs` table, never read back through any API response. There is deliberately no `XENDIT_*`/`TRIPAY_*`/`STRIPE_*` key in `.env` — this keeps credential rotation an in-app admin action instead of a redeploy. |
| Shipping credentials (RajaOngkir) | **Database**, not `.env` | Same pattern, via Super Admin → Shipping Providers (`PUT /admin/shipping-providers/{provider}/config`). |
| OpenRoute credentials | `backend/.env` (this one IS env-driven, since it's a platform-wide fallback distance calculator rather than a per-branch account) | `OPENROUTE_API_KEY`, `OPENROUTE_BASE_URL` — optional; leave `OPENROUTE_API_KEY` empty to fall back to the built-in Haversine straight-line distance calculator. |
| Google Sheets service account | Secure server filesystem + `backend/.env` | Set `GOOGLE_SHEETS_ENABLED=true` and `GOOGLE_SHEETS_CREDENTIALS_PATH` to an absolute path outside the public web root. Never place the JSON key in Git. |
| Mail | `backend/.env` | `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` |
| Storage | `backend/.env` | `FILESYSTEM_DISK` (`local` for the private disk, `public` disk backs `storage/app/public` — must be symlinked, see §16) |
| Application URL | `backend/.env` | `APP_URL` |
| Frontend URL (for CORS) | `backend/.env` | `FRONTEND_URLS` — comma-separated list of exact frontend origins allowed to make credentialed requests |
| Frontend host (for Sanctum sessions) | `backend/.env` | `SANCTUM_STATEFUL_DOMAINS` — comma-separated **host:port, no scheme** (e.g. `yourdomain.com` in production, `localhost:5173` in dev). This is not optional — without it every authenticated request fails with "Session store not set on request." It is a different value shape than `FRONTEND_URLS` (no `https://`) and both must point at the same real frontend. |
| API URL (frontend → backend) | `frontend/.env` | `VITE_API_URL` |
| Session cookie security | `backend/.env` | `SESSION_SECURE_COOKIE` — must be `true` in production (HTTPS only) |

No secret ever appears in source code — every credential-shaped value in `config/*.php` is read via `env(...)`, and payment/shipping provider secrets are encrypted-at-rest DB columns populated only through the admin API.

## 11. Installer usage

The first-run wizard is available at `/install`. It checks requirements, tests
and saves the MySQL connection, writes application settings, runs migrations
and seeders, creates the first Super Admin, warms caches, and creates the
permanent installation lock. The backend also treats an existing Super Admin
as installed if the lock file is missing, so installer write endpoints cannot
be reused against an initialized database.

## 12. Super Admin creation

There is no seeder or UI that creates a Super Admin account (deliberately — see `database/seeders/DatabaseSeeder.php`, which seeds only roles/languages/settings/payment-methods/shipping-providers, never a user). Create the first Super Admin via `php artisan tinker`:

```php
php artisan tinker
>>> $role = \App\Models\Role::where('slug', 'super_admin')->first();
>>> \App\Models\User::create([
...     'role_id' => $role->id,
...     'name' => 'Your Name',
...     'email' => 'you@example.com',
...     'phone' => '08xxxxxxxxxx',
...     'password' => 'a-strong-password',   // hashed automatically (User::password is cast 'hashed')
...     'status' => 'active',
... ]);
```

Log in at `POST /api/v1/auth/login` (or via the frontend login page) with that email/password.

Super Admin can create Agent accounts. Each Agent creates its own Admin,
Keuangan, Korsal, Sales, and Kurir accounts. Sales created by an Agent require
a Korsal from the same Agent network; Sales created by a Korsal are attached
to that Korsal automatically.

## 13. Payment gateway configuration

Log in as Super Admin and open the Payment Gateways admin screen (`/admin/payment-gateways`). For each of Xendit, Tripay, and Stripe:
1. Toggle it active.
2. Choose the environment (sandbox/production).
3. Fill in that gateway's credentials (API key / private key / webhook secret, per the gateway's own dashboard) — these are submitted once via `PUT /admin/payment-gateways/{method}/config` and stored encrypted; they are never shown again after saving (the API only ever returns `configured: true/false`, never the value).
4. Point the gateway's own webhook configuration at `POST {APP_URL}/api/v1/webhooks/payment/{method}` (`method` = `xendit`, `tripay`, or `stripe`).

No gateway credential belongs in `.env` — see §10.

## 14. RajaOngkir configuration

Same admin-driven pattern as §13, under Shipping Providers (`/admin/shipping-settings`): enable RajaOngkir, choose its account tier (starter/basic/pro — each has a different base API URL, handled automatically), and enter the API key. RajaOngkir is used for "ekspedisi" (courier-company) shipping quotes.

## 15. OpenRoute configuration

OpenRoute powers "kurir online" (in-house/branch courier) real road-distance pricing. Unlike the gateway/shipping-provider credentials above, this one **is** environment-driven (`backend/.env`): set `OPENROUTE_API_KEY` (get a free key at openrouteservice.org) and optionally override `OPENROUTE_BASE_URL`. Leave `OPENROUTE_API_KEY` empty to keep the built-in Haversine straight-line fallback distance calculator — the app works either way, just with a less precise distance figure.

### Google Sheets synchronization

Google Sheets is a one-way reporting integration: MySQL remains the source of
truth. Install the central service-account JSON outside `backend/public`, share
each destination spreadsheet with the service-account email, and configure:

```dotenv
GOOGLE_SHEETS_ENABLED=true
GOOGLE_SHEETS_CREDENTIALS_PATH=/absolute/private/path/service-account.json
```

Super Admin registers a spreadsheet and assigns it to global scope or one
Agent. Super Admin, Agent, and Admin can then configure allowed datasets,
columns, an optional status filter, and run manual sync from
`/dashboard/google-sheets`. Agent and Admin can only access their assigned
Agent scope. The target tab must already exist and is dedicated to the export:
each sync replaces its cell values. The integration exports at most 10,000
rows per manual run and records a sanitized audit log. It never imports data
from Google Sheets.

Available datasets are defined in the application registry; the UI cannot
select arbitrary tables or columns. Credentials, passwords, tokens, sessions,
and payment secrets are not exportable.

## 16. Storage configuration

Uploaded media (product images, CMS images, payment/delivery/return proof photos) are stored on the `public` disk (`backend/storage/app/public`) and served through the `storage/` symlink. After every fresh deployment, run:

```bash
php artisan storage:link
```

This creates `backend/public/storage -> backend/storage/app/public`. Without this symlink, every uploaded file's URL 404s. `FILESYSTEM_DISK` in `.env` controls the *default* disk (used for a few internal writes); uploaded media always explicitly targets the `public` disk regardless of that default (see `app/Services/Media/MediaService.php`).

## 17. Mail configuration

Set `MAIL_MAILER` (e.g. `smtp`), `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` in `backend/.env`. The default `.env.example` value (`MAIL_MAILER=log`) writes mail to the log file instead of sending it — fine for local development, must be changed before production.

## 18. Development mode

Run both halves side by side:

```bash
# terminal 1
cd backend && php artisan serve

# terminal 2
cd frontend && npm run dev
```

`backend/.env`: `APP_ENV=local`, `APP_DEBUG=true` (safe locally — leaks stack traces on 500s, which is what you want while developing). `frontend/.env`: `VITE_API_URL=http://localhost:8000`.

## 19. Production deployment

1. `backend/.env`: `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, a freshly-generated `APP_KEY` (do not reuse a development key), real `DB_*`/`MAIL_*` values, `FRONTEND_URLS` & `SANCTUM_STATEFUL_DOMAINS` set to the real frontend (see §10 for the difference between the two), `APP_URL` set to the real backend URL.
2. `composer install --no-dev --optimize-autoloader`
3. `php artisan migrate --force` (or import `database.sql`, see §25 — not both)
4. `php artisan storage:link`
5. `php artisan config:cache && php artisan route:cache`
6. Build the frontend (§26) and deploy the static `frontend/dist/` output to your web server / CDN.
7. Point the backend's web server document root at `backend/public` (§20), and the frontend's at `frontend/dist` (with SPA fallback routing, §21).

## 20. Apache

**Backend** — the Apache **document root must be `backend/public`**, never the repo root or `backend/` itself (that would expose `.env`, `app/`, `vendor/`, etc. — see §22). Example vhost:

```apache
<VirtualHost *:443>
    ServerName api.yourdomain.com
    DocumentRoot "/var/www/primeclassy/backend/public"

    <Directory "/var/www/primeclassy/backend/public">
        AllowOverride All
        Require all granted
    </Directory>

    SSLEngine on
    SSLCertificateFile      /path/to/cert.pem
    SSLCertificateKeyFile   /path/to/key.pem
</VirtualHost>
```

`mod_rewrite` must be enabled (`a2enmod rewrite`) — `backend/public/.htaccess` (shipped, unmodified Laravel default) depends on it to route every request through `index.php`.

**Frontend** — served as a separate static vhost pointed at `frontend/dist` (after `npm run build`, §26):

```apache
<VirtualHost *:443>
    ServerName yourdomain.com
    DocumentRoot "/var/www/primeclassy/frontend/dist"

    <Directory "/var/www/primeclassy/frontend/dist">
        AllowOverride All
        Require all granted
    </Directory>

    SSLEngine on
    SSLCertificateFile      /path/to/cert.pem
    SSLCertificateKeyFile   /path/to/key.pem
</VirtualHost>
```

## 21. .htaccess

**Backend** (`backend/public/.htaccess`, already present, unmodified Laravel default): rewrites every request that isn't an existing file/directory to `index.php`, and forwards the `Authorization`/`X-XSRF-Token` headers through to PHP. Do not remove or hand-edit this file.

**Frontend SPA fallback** — Vue Router uses client-side history-mode routing, so the web server must serve `index.html` for any path it doesn't recognize as a real static file (otherwise refreshing on `/products/some-slug` 404s at the server instead of reaching the Vue app). If serving `frontend/dist` via Apache, add an `.htaccess` there:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /
    RewriteRule ^index\.html$ - [L]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule . /index.html [L]
</IfModule>
```

(This file does not currently exist in the repo — add it alongside `frontend/dist/index.html` at deploy time, or configure the equivalent fallback in Nginx/your static host: `try_files $uri $uri/ /index.html;`.)

## 22. Security

See `backend/SECURITY_AUDIT.md` for the full audit (authentication, RBAC, IDOR, injection, XSS, CSRF, file upload, webhook/payment security, and every fix applied) and `backend/FINAL_AUDIT_REPORT.md` for the final master-audit pass. Deployment-specific rules:

- **Never expose**: the backend source tree (`app/`, `routes/`, `database/`), `.env`, `backend/storage/` (internal — only `backend/storage/app/public` is meant to be reachable, and only via the `public/storage` symlink), `node_modules/`, `vendor/`, or any `.git` directory. Setting the Apache document root to `backend/public` (§20) is what makes this true — everything else in `backend/` sits outside the web server's reachable tree entirely.
- `.env` is gitignored in `backend/`, `frontend/`, and the repo root — only `.env.example` (secret-free) is ever committed.
- Payment/shipping gateway credentials live encrypted in the database, entered only through the authenticated admin API — never in `.env`, never in source.
- Rate limiting is applied to login, registration, checkout, and the payment webhook endpoint.
- Every file upload is validated by real MIME-type/decode checks (not just file extension) and stored under a generated (never client-supplied) filename.

## 23. Testing

Backend tests run against an isolated MySQL test database. The test bootstrap
refuses databases whose name does not contain `_test` or `_testing`, protecting
development and production data:

```bash
cd backend
php artisan test
```

Before enforcing SKU on existing data, audit and preview the deterministic
backfill. The preview is read-only; apply it only after reviewing the output:

```bash
php artisan system:audit-catalog-hierarchy
php artisan products:backfill-sku
php artisan products:backfill-sku --apply
```

Frontend: **no automated test suite exists yet** (no test runner is configured in `frontend/package.json`, no `*.spec.ts`/`*.test.ts` files exist). Manual verification (dev server + browser) is the current practice for frontend changes.

## 24. Database migration

```bash
cd backend
php artisan migrate          # apply on top of an existing schema
php artisan migrate:fresh    # DESTRUCTIVE — drops every table first; local/dev only
php artisan migrate:fresh --seed   # fresh schema + roles/languages/settings/payment-methods/shipping-providers, no demo users
```

`backend/database/migrations/` (70 files) is the authoritative schema source; `database.sql` (§25) is a generated export of it, not a hand-maintained alternative — don't edit `database.sql` directly and expect it to affect the app.

## 25. database.sql import

`backend/database.sql` is a structure-and-seed-only MySQL dump generated from the real migrations (`mysqldump` after `migrate:fresh --seed`). It contains the 7 fixed roles, the 4 languages, and Laravel's own `migrations` bookkeeping table — no user accounts, no products, no orders, no payment configuration. Use it as a faster alternative to running all 70 migrations one-by-one:

```bash
mysql -u primeclassy -p primeclassy < backend/database.sql
```

Afterwards, `php artisan migrate` will correctly report "Nothing to migrate" (the bookkeeping table is already populated) rather than trying to re-create existing tables.

## 26. Build frontend

```bash
cd frontend
npm run build
```

Outputs to `frontend/dist/` (per `vite.config.ts`'s default). Deploy that directory's contents to your static host / Apache document root (§20/§21). `npm run build` also type-checks the project (`vue-tsc`) as part of the build.

## 27. Troubleshooting

- **Uploaded images 404**: you forgot `php artisan storage:link` (§16), or the web server's document root isn't `backend/public` (§20).
- **Login works but every subsequent request is 401/419**: CORS/Sanctum stateful-domain mismatch — check BOTH `FRONTEND_URLS` (with scheme, for CORS) AND `SANCTUM_STATEFUL_DOMAINS` (host:port, no scheme, for sessions) in `backend/.env` match the frontend's real origin; missing `SANCTUM_STATEFUL_DOMAINS` specifically causes a 500 "Session store not set on request" rather than a 401/419. The frontend must also send requests with `withCredentials`/cookies enabled.
- **Refreshing a frontend route like `/products/foo` 404s**: the web server isn't falling back to `index.html` for unknown paths — see §21.
- **500 error with no detail in production**: expected — `APP_DEBUG=false` hides exception detail from API responses by design (§22). Check `backend/storage/logs/laravel.log` instead.
- **Webhook returns 401 `invalid_signature`**: the gateway's webhook secret/callback token configured in the admin screen (§13) doesn't match what the gateway is actually signing with — re-check both sides.
- **RajaOngkir/OpenRoute quote always falls back to Haversine/free shipping**: the provider is either disabled or its API call failed (check `backend/storage/logs/laravel.log`) — both providers fail safe to a fallback rather than blocking checkout.

## 28. Backup

There is no built-in backup command in this codebase. At minimum, back up on a schedule:
- The MySQL database (`mysqldump primeclassy > backup.sql`, or your MySQL host's native backup/snapshot tooling).
- `backend/storage/app/public` (all uploaded media — product images, CMS images, payment/delivery/return proof photos; this is **not** reproducible from the database alone).
- `backend/.env` and `frontend/.env` (configuration only — store securely, never alongside a public backup, since they contain `APP_KEY`/`DB_PASSWORD`).

Payment/shipping gateway credentials live in the database (`payment_gateway_configs`/`shipping_providers` tables), so a database backup already covers them — there is nothing gateway-related to separately export from `.env`.

## 29. Update procedure

```bash
# backend
cd backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:clear && php artisan config:cache
php artisan route:clear && php artisan route:cache

# frontend
cd frontend
npm install
npm run build
# redeploy the new frontend/dist/ output
```

Always take a database backup (§28) before running `php artisan migrate --force` against production. Check `backend/database/migrations/` for any new migration file introduced since your last deploy to understand what's changing before it runs.
# kmsitprimeclassy
# kmsitprimeclassy
