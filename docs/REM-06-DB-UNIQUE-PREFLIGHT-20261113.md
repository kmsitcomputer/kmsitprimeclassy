# REM-06 — Database Hardening Preflight (Owner Decision C — preparation only)

**Date:** 2026-11-13 · **Scope:** prepare a database-level uniqueness guarantee for global product categories. **Nothing was migrated on DEV or production.**

## 15. Final verdict — **READY WITH CONDITIONS**

The design, the preflight tooling and the migration file are ready for Owner review and are proven on two disposable engines (MariaDB 10.11.19 and MySQL 8.0.46). The conditions are not defects. They are things only the Owner can supply or decide:

1. **Production data has not been audited.** I cannot reach it. The Owner (or DevOps) must run `php artisan categories:unique-preflight` there and get exit 0 before any migration. DEV is clean: 4 categories, 0 conflicts.
2. **Deploy order matters** (see §6.3): application code first, migration second.
3. **Residual raw-SQL risk** is accepted or rejected by the Owner (see §6.2 and §13).
4. Owner decisions in §13.

The migration file is deliberately NOT in `database/migrations/`, so the runner cannot discover it.

---

## 1. Git baseline

| Item | Value |
|---|---|
| Path | `/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy` |
| Branch | `fix/transaction-reset-financial-dependencies` |
| HEAD | `6a14041c06e6258c9f011fcb11271c7d34d65d17` (unchanged; nothing committed, pushed, stashed or reset) |
| Working tree | 218 uncommitted entries (all earlier remediation work preserved) |
| Migration runner | Not run against DEV or production. `migrate:status --pending` on DEV: "No pending migrations" |

## 2. Database inventory (DEV, read-only)

| Fact | Value |
|---|---|
| Engine | MariaDB 10.11.19 (container `primeclassy-mariadb`), DB `primeclassy_db` |
| Production engine | MySQL 8.0.46 (per `docs/13`), so both engines were validated |
| Table | `product_categories`, InnoDB, ROW_FORMAT DYNAMIC, charset `utf8mb4`, collation `utf8mb4_unicode_ci` |
| Columns | `id` (PK), `parent_id` (self-FK, **ON DELETE SET NULL**), `name` varchar(100), `slug` varchar(120), `is_active`, `sort_order`, timestamps |
| Soft deletes | None. Rows are really deleted. |
| Indexes | PK, `UNIQUE(slug)`, FK index on `parent_id`. **No unique index on (parent, name).** |
| Slug generation | Application-side, unique-suffixed; unchanged by this work |
| References | `products.category_id` (FK SET NULL); `cms_homepage_blocks.content` JSON (category ids); no pivot, filter-table or import path creating categories |
| DEV data | 4 rows; 1 product reference (category 4); a CMS category block referencing ids 2, 3, 1 |
| MySQL vs MariaDB differences handled | `DROP CONSTRAINT` (MariaDB) vs `DROP CHECK` (MySQL); CHECK enforced from MySQL 8.0.16 / MariaDB 10.2+; indexed virtual columns; JSON key ordering differs (a test expectation was made order-insensitive) |

## 3. Index and collation analysis

Measured on both disposable engines:

* `utf8mb4_unicode_ci` folds case **and accents** ("é" = "e"), treats NFD ≠ NFC (a decomposed "é" is NOT equal to a precomposed one) and does not strip zero-width characters. A native `UNIQUE(parent_id, name)` therefore (a) would be stricter than the application on accents and (b) would **miss** the NFD, zero-width and whitespace variants the application treats as duplicates. The collation cannot replicate the application's normalisation.
* A plain `UNIQUE(parent_id, name)` also has the **NULL hole**: root categories have `parent_id IS NULL`, and NULLs never collide in a unique index, so root duplicates would pass.
* `STORED GENERATED parent_key = COALESCE(parent_id, 0)` is **rejected by the engine** (MariaDB error 1901) because `parent_id` is an FK column with ON DELETE SET NULL.
* A VIRTUAL generated column with a unique index works on both engines.
* Index length: `VARCHAR(255) utf8mb4_bin` is 1020 bytes, which needs ROW_FORMAT DYNAMIC (met; the migration asserts it). A 255-character key stores; 256 is rejected in strict mode (no silent truncation). Application names are ≤100 characters.

## 4. Duplicate inventory

Read-only, via `CategoryDuplicateScanner` using the same `CategoryHierarchyService::nameKey` as the application, inside a READ ONLY transaction. Reports category id, parent id and reason only (no names echoed). Reasons: `identical`, `case`, `whitespace`, `unicode_normalization`, `zero_width_or_control_characters`; plus empty keys, and an informational accent-only list.

| Environment | Result |
|---|---|
| DEV | 4 categories, **0 conflicts**, 0 empty keys, exit 0 |
| Production | **NOT AUDITED** (not accessible). Owner runs `php artisan categories:unique-preflight [--json]`. |

## 5. Unicode normalisation compatibility

Application key = NFC → control/format characters (`\p{Cc}\p{Cf}`) replaced by a space → all `[\p{Zs}\s]+` collapsed to one space → trim → `mb_strtolower`. The key is stored in a `utf8mb4_bin` column, so the database compares exactly what the application compared. Consequences:

* NFD/NFC, zero-width, NBSP/tab and case variants are caught.
* **Accent-only variants** ("kafe" vs "kafé") are different keys; the application already treated them as different names. They are listed informationally by the scanner and are allowed. This is the deliberate difference from native collation (Owner decision D3).

## 6. Recommended constraint design

### 6.1 Chosen: Option B, a materialised normalisation key

```
name_key       VARCHAR(255) utf8mb4_bin NOT NULL           -- written by the app
root_name_key  VARCHAR(255) utf8mb4_bin
               GENERATED ALWAYS AS (IF(parent_id IS NULL, name_key, NULL)) VIRTUAL
CHECK (name_key <> '')
UNIQUE uq_product_categories_parent_name_key (parent_id, name_key)   -- siblings
UNIQUE uq_product_categories_root_name_key   (root_name_key)         -- roots (NULL parent can't slip through)
```

Same name under a different parent stays allowed. Slug uniqueness and the FKs are untouched. No visibility or ownership change. The API is unchanged and create-only authorisation is kept.

### 6.2 Alternatives compared

| Option | Verdict |
|---|---|
| A. Native generated key on `name` with `unicode_ci` | Rejected. Cannot do NFC or zero-width removal; stricter on accents (changes behaviour); misses NFD duplicates. |
| B. App-materialised key + DB unique indexes | **Chosen.** Exactly the application semantics, DB-enforced, works on both engines. |
| C. Change the application to fold accents so it matches `unicode_ci` | Rejected. Business-visible behaviour change; still no NFC/zero-width protection. |
| D. Plain `UNIQUE(parent_id, name)` | Rejected. NULL-parent hole, and collation mismatch. |

**Residual risk of B (stated plainly):** the database guarantees that no two rows share the same `name_key` per parent. It cannot verify that a writer filled `name_key` correctly. A raw-SQL writer that deliberately stores a *different* key for the same name is not stopped (this is tested and documented). A raw insert **without** a key fails closed (NOT NULL, plus the CHECK in non-strict modes). Eloquent writers get the key automatically through the model `saving` hook.

### 6.3 Operational order (required)

1. Deploy application code (model hook; it works with or without the column).
2. `php artisan categories:unique-preflight` on the target; must exit 0.
3. Back up the database (mandatory per AGENTS §9).
4. Maintenance window, then run the migration.
Running the migration before step 1 would make old code's inserts fail (NOT NULL).

### 6.4 Behaviour changes that come with the constraint (handled in code)

* Rename/reparent (Super Admin) is now deduplicated under the same lock; a collision returns 422 instead of a 500.
* Deleting a parent whose children would be promoted to a root that collides with an existing root: the database refuses (error 1761); the service returns a clear **409**.
* A unique-index violation that is not on the slug maps to the existing 422 "Kategori dengan nama yang sama sudah ada pada induk ini."

## 7. Legacy conflict resolution plan (no automatic action, ever)

The migration **aborts before any DDL** when conflicts exist and prints the category ids. Nothing is merged, renamed, moved or deleted by tooling. For each conflict group the Owner decides one of the options below. References to check: `products.category_id`; CMS category block ids in `cms_homepage_blocks.content`; child categories; storefront slugs/URLs (`/category/{slug}`); historical orders and reports that group by category.

| Conflict type | Options | Risks | Owner decision needed |
|---|---|---|---|
| Identical or case/whitespace duplicates at the same level | (1) Keep one, repoint products and CMS ids, then delete the other. (2) Rename one to a distinct, meaningful name. | Repointing changes product grouping and storefront URLs; the deleted slug will 404 (consider a redirect). CMS JSON must be edited by hand. | Which category survives; whether slug redirects are needed |
| Unicode-normalisation or zero-width duplicates | Same as above | Visually identical names, so confirm with ids, not by eye | Same |
| Duplicate roots | Same, or move one under a parent | Moving changes the storefront hierarchy | Which to keep or move |
| Empty or blank normalised name | Rename to a real name | None beyond naming | New name |
| Accent-only differences | Nothing required; allowed | Visually confusing to shoppers | Optional rename |

Each resolution is a separate, Owner-approved data change with its own backup and audit, not part of this migration.

## 8. Migration file location

* `backend/database/proposed-migrations/2026_11_16_200000_add_unique_normalized_name_key_to_product_categories.php`. It is outside `database/migrations`, so no `migrate` run can discover it (a test asserts this). To adopt it, the Owner authorises moving it into `database/migrations`.
* Preconditions and fail-closed behaviour: engine/version (MariaDB ≥ 10.3, MySQL ≥ 8.0.16), ROW_FORMAT DYNAMIC, the same advisory lock the application uses (so no category can be created between the check and the index), the duplicate scan and the empty-key scan. Additive DDL only; backfill uses the application's own `nameKey`; idempotent on re-run.
* Supporting code: `app/Support/CategoryDuplicateScanner.php`, `app/Console/Commands/CategoryUniquePreflight.php`, `ProductCategory` `saving` hook, `CategoryHierarchyService` (create/update/delete guards).

## 9. Rollback plan

`down()` drops both unique indexes, the CHECK and both columns, and loses only derived data. It is tolerant of a partially applied `up` (MySQL DDL is not transactional). A test proves `SHOW CREATE TABLE` is identical before `up` and after `down`, and that `up/down/up` is repeatable. The pre-migration backup is the real safety net. Application code remains compatible both with and without the column.

## 10. Isolated test evidence

**Isolation proof.** Throwaway containers `pc-preflight-db` (same MariaDB 10.11 image as DEV) and `pc-preflight-mysql8` (MySQL 8.0.46), data on `tmpfs`, no volume or bind mount, on the internal docker network `pc-preflight-net`. The PHP container is attached only to that network, and the DEV address returned "Network unreachable". Different hostnames and servers from DEV. The test bootstrap additionally requires a `_testing` database name. Environment names alone were not trusted.

| Suite | Engine | Result |
|---|---|---|
| `CategoryDuplicateScannerTest` + `CategoryUniqueKeyMigrationTest` | MariaDB 10.11.19 | 18/18 pass |
| same | MySQL 8.0.46 | 18/18 pass (105 assertions) |
| Category regression set (CategoryCreateOnly, CategoryHierarchyApi, CategoryCreateConcurrency, CategoryHierarchyConcurrency, CatalogRemediation, ProductSystem), pre-migration schema | MariaDB | 53/53 pass (336 assertions) |
| same | MySQL 8.0.46 | 53/53 pass after the fix below |

Covered: up/down, fail-closed with legacy duplicates (NFD, zero-width, case/whitespace, root) with no DDL and no data change and the lock released, clean up() with backfill and rows preserved, idempotency, root and sibling uniqueness, same-name-different-parent allowed, direct-SQL duplicate rejected, two real connections inserting concurrently leaves exactly one row, service race, API/rename/reparent/delete compatibility, raw insert without key fails closed, residual-risk case documented.

**Honest note on the MySQL 8 reruns.** The first reruns failed with `TEST DATABASE ALREADY IN USE`: stale connections of my earlier killed runs still held the harness lock on the disposable instance. I killed those two connections on the throwaway server and reran; one test then failed for a test-only reason: MySQL's JSON column returns object keys in a different order and the audit test used the order-sensitive `assertSame`. I changed those two assertions to `assertEquals` (still checks every key and value); `CategoryCreateOnlyTest` passed 15/15 and then the whole 53-test set passed.

## 11. Exact commands

```
# read-only on DEV (no writes)
php artisan categories:unique-preflight            # exit 0, 4 categories, 0 conflicts
php artisan migrate:status --pending               # "No pending migrations"

# isolated (disposable container, APP_ENV=testing, DB primeclassy_testing)
php artisan test --filter='CategoryDuplicateScannerTest|CategoryUniqueKeyMigrationTest'
php artisan test --filter='CategoryCreateOnlyTest|CategoryHierarchyApiTest|CategoryCreateConcurrencyTest|CategoryHierarchyConcurrencyTest|CatalogRemediationTest|ProductSystemTest'
vendor/bin/pint --test <changed files>             # PASS, 8 files
git diff --check
```

For the Owner, on the target environment (not run by me): `php artisan categories:unique-preflight --json` (must exit 0), then back up, then, once authorised, move the migration into `database/migrations` and run `php artisan migrate`.

## 12. Risk assessment

| Risk | Severity | Mitigation |
|---|---|---|
| Production data has conflicts nobody has seen | High until audited | Fail-closed preflight and migration; Owner resolution plan (§7) |
| Migration run before the new code is deployed | High | Required order §6.3; the migration comment states it; old inserts would fail closed |
| Raw writer filling a different key | Low–Medium | Documented; Eloquent is covered by the hook; the DB still blocks identical keys and keyless inserts |
| DDL not transactional on interruption | Medium | Idempotent `up`, tolerant `down`, backup |
| Parent delete colliding on SET NULL promotion | Low | DB refuses; the service returns 409 with an explanation |
| Rename/reparent now returns 422 on collision | Low | Intended; super_admin only |
| Accent-only variants allowed | Low | Matches the existing application behaviour (Owner decision D3) |
| Table lock during ALTER | Low (tiny table) | Maintenance window |

## 13. Owner decisions required

* **D1.** Approve Option B (materialised key) and its residual raw-SQL risk, or require stricter measures.
* **D2.** Run `categories:unique-preflight` on production (or authorise me to be given a read-only view) and decide any conflict groups (§7).
* **D3.** Confirm accent-only variants stay distinct (current behaviour), or ask for accent folding (a business change).
* **D4.** Authorise the deploy order and maintenance window; confirm the backup procedure.
* **D5.** Authorise moving the migration into `database/migrations` and running it on DEV first, then production. Each step needs its own approval (AGENTS §10).

## 14. Confirmations

* **DEV and production databases were not modified.** On DEV only SELECTs inside READ ONLY transactions and `migrate:status` were run; no ALTER, CREATE/DROP INDEX, UPDATE, DELETE, TRUNCATE or migration. Production was not accessed.
* **REM-11 untouched.** No Historical POS file was modified in this task (the Historical* files in the working tree predate it); no REM-11 suite was run.
* No commit, push, merge or deploy. Full test suite not run. No credentials were written to the repository or this document.
