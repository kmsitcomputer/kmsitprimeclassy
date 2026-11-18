# REM-06 — DEV Database Migration Execution

**Date:** 2026-11-13 · **Authority:** Owner authorisation, DEV only (D1–D4 approved). **Production: untouched.**

## 15. Final verdict — **PASS — DEV MIGRATION COMPLETE**

All 12 gates passed, the single approved migration ran on DEV in 126 ms, schema and data checks are clean, scoped regression is green, and rollback is proven on a restored copy of the real DEV data. One note, not a failure: the authenticated UI flows (create-only, product editor) were verified on the disposable database with the identical code and the migrated schema, not by logging in to DEV (no credentials were used, and writing synthetic data to DEV was avoided). See §11.

## 1. Git baseline

Branch `fix/transaction-reset-financial-dependencies` · HEAD `6a14041c06e6258c9f011fcb11271c7d34d65d17` (unchanged) · working tree 219 uncommitted entries, all earlier work preserved (nothing reset, cleaned, stashed, committed or pushed). Changes in this task: the migration moved into `backend/database/migrations/`, three test files adapted (§10), this report. The now-redundant copy in `database/proposed-migrations/` was removed.

## 2. DEV environment identity

| Item | Value |
|---|---|
| App env | `local` (container `primeclassy-fpm`) |
| DB host / database | `primeclassy-mariadb` / `primeclassy_db` |
| Engine | MariaDB 10.11.19 (`mariadb:10.11`), 135 tables |
| URL | `primeclassy.kmsit.localhost` (the `primeclassy.com` addresses in frontend env files are production and were not contacted) |
| Not production | local docker host and name, APP_ENV=local, production is a separate MySQL 8 server (docs/13) |
| Old-code processes | 0 queue/schedule workers running; the FPM mount already serves the new code (`ProductCategory` `saving` hook present) |

## 3. Preflight result (DEV, before)

`php artisan categories:unique-preflight` → **exit 0**: 4 categories, 0 conflict groups, 0 empty normalised names, 0 accent-only look-alikes. `migrate:status --pending` listed exactly the REM-06 migration after it was placed (nothing else pending).

## 4. Backup and restore verification

| Item | Value |
|---|---|
| Backup | `mariadb-dump --single-transaction --routines --triggers` of `primeclassy_db` (no locks, no changes) |
| File | `/private/tmp/claude-501/-Users-kmsitcomputer-KMSIT-Server-apps-primeclassy/ac13fef7-f1cc-48e6-a6b8-0abf31fd8aa0/scratchpad/bk/dev_pre_rem06.sql` (4,701,682 bytes; outside the repo) |
| SHA-256 | `d404537000bcb3c1666023290a8fe846c15309a19c074fdb7039785b9aa93aa8` |
| Integrity | exit 0, ends with "Dump completed", 135 `CREATE TABLE` |
| Restore test | into a throwaway MariaDB 10.11.19 (tmpfs, no volumes, internal network, DEV unreachable): exit 0, 135 tables, the 4 categories and the product count identical to DEV |
| Also saved | `schema_before.txt`, `data_before.txt` (categories, product references, CMS block), `schema_after.txt`, `data_after.txt` |

The first dump attempt failed (client demanded TLS; the DEV server has none), wrote 0 bytes and changed nothing; it was repeated with `--skip-ssl`, which is appropriate for the internal docker network.

## 5. Migration checksum

`2bc253828e20b8ef82a6acebdc266204f5ec8d27c9d7b4770c59a85b937f458d` — the file in `database/proposed-migrations/` and the copy in `database/migrations/2026_11_16_200000_add_unique_normalized_name_key_to_product_categories.php` were byte-identical (`cmp`) before running.

## 6. Exact command

```
php artisan migrate --path=database/migrations/2026_11_16_200000_add_unique_normalized_name_key_to_product_categories.php --force
```
(run inside `primeclassy-fpm`, working directory `/var/www/app/backend`). Path-restricted, so no other migration could run. No `migrate:fresh|refresh|reset`.

## 7. Execution result

Start 2026-11-13 06:23:02Z · end 06:23:03Z · migration `DONE` in **125.81 ms** · batch 49. The artisan exit code did not capture in my shell (piped to `tail`); success is established by the `DONE` line, `migrate:status` showing "Ran" and "No pending migrations", and the schema checks below.

## 8. Schema verification (DEV, after)

| Check | Result |
|---|---|
| `name_key` | `varchar(255) utf8mb4_bin NOT NULL` |
| `root_name_key` | `varchar(255) utf8mb4_bin` `VIRTUAL GENERATED` (`IF(parent_id IS NULL, name_key, NULL)`) |
| Sibling unique | `uq_product_categories_parent_name_key (parent_id, name_key)` |
| Root unique | `uq_product_categories_root_name_key (root_name_key)` |
| CHECK | `chk_product_categories_name_key_nonempty (name_key <> '')` |
| Preserved | PK, `product_categories_slug_unique`, parent index, FK `parent_id` ON DELETE SET NULL |
| Backfill | `cookies, cake, hampers, yummy class`; roots have `root_name_key` = key, the child has NULL |
| Diff vs before | only the 2 columns, 2 unique keys and 1 CHECK were added; nothing else changed |
| Table count | 135 before and after (no other migration ran) |

## 9. Data integrity comparison

`diff data_before.txt data_after.txt` → **identical**: same 4 category IDs, parents, names, slugs, `is_active` (visibility), `sort_order`, `created_at`/`updated_at`; products 1 with a category and 1 referencing category 4 (unchanged); CMS block 8 content `{"category_ids":[2,3,1]}` (unchanged). `categories:unique-preflight` after: **exit 0**, 0 conflicts.

## 10. Scoped regression (disposable database only; no full suite)

Environment: throwaway MariaDB 10.11.19 + a PHP container on an internal network (resolved alias 172.24.0.2; DEV 172.21.0.2 unreachable); the test database is built by the standard migrations, so it **already contains the hardening** (the real post-migration state).

| Suite (covers) | Result |
|---|---|
| `CategoryDuplicateScannerTest` + `CategoryUniqueKeyMigrationTest` (root/sibling duplicates, same name under different parent, NFC/NFD, zero-width/control, direct-SQL rejection, two real connections concurrent insert, fail-closed legacy duplicates, up/down/idempotent, parent-deletion conflict → 409, API compatibility) | **18/18, 105 assertions** |
| `CategoryCreateOnlyTest` (Admin Agen and Agen create-only, forged payloads, audit trail, dedupe, normalisation) | **15/15** |
| `CategoryHierarchyApiTest`, `CategoryCreateConcurrencyTest`, `CategoryHierarchyConcurrencyTest` (Super Admin rename/reparent/delete, hierarchy, concurrent create), `CatalogRemediationTest`, `ProductSystemTest` (product category selection) | together with the above: **53 tests** (52 first run + 1 repaired) |
| Pint on changed files | PASS |

Test adjustments (all test-only, no behaviour change): the migration and scanner tests now run `down()` in `setUp()` to recreate the pre-migration shape and the schema is rebuilt afterwards; the "not discovered by the runner" test became "standard schema contains the hardening"; `CategoryCreateOnlyTest` no longer seeds a legacy duplicate *pair* (impossible once the constraint exists; fail-closed behaviour is covered in the migration test). First runs after the migration failed for exactly these reasons and for none other.

## 11. DEV application verification

Against the real DEV stack, read-only: `GET /api/v1/categories` → 200 with Cake, Cookies, Hampers, Yummy Class; `GET /api/v1/products` → 200, product "Kastangel" still carries category "Yummy Class"; frontend `/` → 200; anonymous `POST /api/v1/categories` → 401; `laravel.log` has **0 `local.ERROR`** entries since the migration (today's only errors are `testing.ERROR` noise from the disposable runs sharing the log file; the last older DEV errors are the known missing REM-11 `historical_dossiers` table). Authorization, product-editor category selection and create-only flows were verified by the scoped tests above and the earlier real-browser UAT (REM-06 final report) on the same code. I did not log in to DEV or insert test categories there. REM-11 and finance modules were not touched.

## 12. Rollback readiness

* **Backup:** §4 (file + checksum + proven restore).
* **Command (REM-06 only):** `php artisan migrate:rollback --path=database/migrations/2026_11_16_200000_add_unique_normalized_name_key_to_product_categories.php --force`. It drops both unique indexes, the CHECK and both columns, and is tolerant of partial states.
* **Proof:** on a restore of the real DEV backup (disposable server): `up` → `down` → `SHOW CREATE TABLE` **identical** to the pre-migration DEV schema and category rows unchanged; `up` again succeeded. **Not run on DEV** (migration succeeded).
* **Risks:** rolling back removes the database guarantee only (no data loss); the application code works with or without the column; after rollback, duplicates are again prevented only by the application lock. Do not roll back while writers are mid-request; use a brief maintenance window.
* **Partial-migration recovery:** `up` checks everything before any DDL, is idempotent, and `down` tolerates missing pieces, so re-running `up` or `down` converges; the full backup is the last resort (restore into a scratch database first and compare).

## 13. Production untouched

No connection to, read of, migration on, or deployment to production. No `primeclassy.com` request was made. Production still needs its own preflight (`categories:unique-preflight`), backup and explicit Owner authorisation; deploy order remains code first, migration second.

## 14. REM-11 untouched

No Historical POS file or flag was modified; no REM-11 suite was run. The path-restricted command executed only REM-06; before it ran, DEV's pending list contained only REM-06, so no REM-11 migration was affected.

## Housekeeping

Disposable containers and network removed; temporary password files deleted; the backup and snapshots remain in the scratchpad folder listed in §4 (delete when no longer needed). No commit, push, full test or production action. **Stopped as instructed.**
