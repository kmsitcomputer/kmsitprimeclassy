# IMP-010 PRODUCTION DATA SNAPSHOT & GO INTEGRATION REPORT — 2026-11-22

Verdict: **LOCAL DATA MIGRATION PASS** (pre-launch dataset; see Remaining Risks)

## A. Source production identity
- SSH alias `kmsit` → root@103.119.237.51:1210 (host `kmsitcomputer.com`) — verified
  against deployment `/opt/primeccookies` (compose: primeccookies_php/nginx/db).
- App: Laravel at `/opt/primeccookies/app/backend`, DB_CONNECTION=mysql,
  DB_DATABASE=`sql_prime`. No secrets printed; `.env` only probed by key name.

## B. SSH verification
- BatchMode key auth OK; production containers running (db `primeccookies_db` = mysql:8.0.46).

## C. Database snapshot
- `sql_prime`: 17.0 MB, 126 tables, 172 migrations, 5 triggers, 0 views/routines/events,
  utf8mb4. Dump: `mysqldump --single-transaction --set-gtid-purged=OFF --triggers
  --default-character-set=utf8mb4` (no FTWRL, InnoDB-consistent, streamed over SSH).
- Compressed 888 KB; SHA-256 b4ceb908…c93a9 (verification/sql_dump.sha256).

## D. Storage snapshot
- Laravel disks resolved from layout: `storage/app/public` (46 MB) + `storage/app/private`
  (19 MB, google/ + payments/). 113 files / 66,969,553 B via rsync -az (no delete ops).
- Per-file SHA-256 list in verification/storage.sha256 (113 entries).

## E. Transfer integrity
- rsync stats: 113 files transferred, total 66,969,553 B (exact match to source sizes).
- Local re-hash of restored storage == source hash list → STORAGE-INTEGRITY-PASS.

## F. Local database restore
- NEW isolated DB `primeclassy_go_local` (utf8mb4_unicode_ci) on primeclassy-mariadb;
  `primeclassy_app` granted; restore exit 0; NOT restored into sql_prime/development/
  primeclassy_testing.
- Post-restore: 126 tables, 172 migrations, 318 FK constraints.

## G. Local storage restore
- `/Users/kmsitcomputer/KMSIT-Server/data/primeclassy-go/storage` (public/ + private/),
  mounted read-write into `pc_go_local` at `/srv/storage`; MEDIA_ROOT=/srv/storage/public,
  STORAGE_ROOT=/srv/storage. No fallback to production paths.

## H. Backend Go connection
- New container `pc_go_local` (golang:1.27.4-alpine, repo mount, port 8086), env `local`,
  DB `primeclassy_go_local`, session cookie pc_sid_local, SANCTUM stateful domain
  `primeclassy-go-local.kmsit.localhost`. install/status: installed=true, has_tables=true,
  has_super_admin=true (restored production state, NOT the testing stack).
- NEW `publicStorageHandler` in `cmd/api/main.go`: serves the Laravel `public` disk at
  /storage/* from MEDIA_ROOT (the same URL contract production nginx satisfies via its
  symlink), path traversal blocked by rooted http.Dir; API mux fallback.

## I. Frontend integration
- `http://primeclassy-go-local.kmsit.localhost` (Caddy): SPA 200, /api → pc_go_local:8082,
  product image URL from the API resolves 200 image/jpeg via the same origin.
- Frontend uses relative /storage/... paths (no direct DB access, no production media fetch).
- Earlier in session: dev alias `http://primeclassy-go.kmsit.localhost` for the testing
  stack (pc_go_imp010) — login/me/orders verified 200 with pc_sid cookie.

## J. Data integrity comparison
- 12 core tables row-count identical source↔restored (users 213, products 52,
  product_images 70, categories 9, stock_movements 76; orders/payments/returns = 0).
- Production dataset is PRE-LAUNCH (no orders yet) — catalog+accounts only.

## K. File integrity comparison
- 113/113 checksums identical. Orphan check: media rows (8) all reference files present
  in restored public/private trees (site_logo/site_favicon/proofs/avatar).

## L. Security & privacy
- Production read-only throughout (SELECT/dump/rsync read). No production writes,
  restarts, config changes, or migrations. No email/WhatsApp/payment sends.
- Snapshots stored OUTSIDE Git at
  KMSIT-Server/backups/primeclassy-imp010/snapshot-20261122-015027 (private dir).
  No credentials/APP_KEY/personal data printed or committed.

## M. Local remediation & commits
- `cmd/api/main.go`: dedup double media.SetRoot; new publicStorageHandler (/storage
  contract + traversal safety). To be committed with this report.
- Caddy dev aliases: primeclassy-go.kmsit.localhost (testing stack) and
  primeclassy-go-local.kmsit.localhost (snapshot stack) in kmsit-caddy sites/.

## N. Remaining risks
- Production credentials unknown by design → full authed-flow smoke on snapshot stack
  requires a locally created test account (signup on the isolated DB is safe: it is a
  copy, not production). Recovery/verify flows already differentially proven on the
  testing stack (compare_recovery 15/0).
- Storage snapshot is near-timed with the dump but not atomic vs point-in-time; with a
  pre-launch dataset (0 orders) temporal skew risk is negligible; re-run rsync any time
  for re-sync.
- Media row count (8) is smaller than on-disk file count (113): the extra files are
  product/CMS images referenced by product_images/CMS path columns (paths verified
  present). No orphan action taken.
- welcome-email worker parity remains from prior checkpoint (21i) — orthogonal to data.

## O. Final verdict
**LOCAL DATA MIGRATION PASS** (with the pre-launch-dataset caveat documented above)

## Local env fix (post-report, local-only)

After a Docker host reboot, pc_laravel_imp010 returned 500 (Access denied for
user primeclassy_test on primeclassy_testing) — its MariaDB grant covered only
the w1..w5 scratch DBs. Remediation: GRANT primeclassy_test ALL on
primeclassy_testing (local MariaDB, no production impact). The Laravel serve
command also needs `--env=testing` (the recreated container CMD lost it).
