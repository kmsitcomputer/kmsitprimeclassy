# REM-06 FINAL — Global Category CREATE-ONLY, Security, Deduplication & Browser UAT

**Date:** 2026-11-13 · **Owner decision lock:** Admin Agen and Agen may create parent and child categories in the existing GLOBAL taxonomy (create-only); Super Admin keeps existing global control.

## 14. Final verdict — **PASS WITH CONDITIONS**

Every gate is proven on evidence — authorization matrix, deduplication, concurrency (two real MySQL connections **and** 12 parallel browser requests), audit, UI, global visibility. The single condition is honest, not a defect: **database-level uniqueness is NOT in place** (no migration was authorized). Duplicate protection is enforced by the application under a serialising lock and *locking reads*, which is proven for every writer that goes through the service (the only category writer in the codebase). A writer that bypasses the service (raw SQL, future code) is not stopped by the database. A migration proposal is in §6.

## 1. Git baseline

`/Users/kmsitcomputer/KMSIT-Server/apps/primeclassy` · `fix/transaction-reset-financial-dependencies` · HEAD `6a14041c06e6258c9f011fcb11271c7d34d65d17` at start and end · working tree 206 → 211 entries (all Unified Remediation changes preserved; nothing reset/stashed/committed).

## 2. Files changed (this task)

Backend: `app/Services/Product/CategoryHierarchyService.php` (create: normalisation, lock, dedupe, audit), `app/Policies/ProductCategoryPolicy.php` (`create` → super_admin/admin/agen only), `app/Http/Controllers/Api/V1/Catalog/ProductCategoryController.php` (server-built attributes), `routes/api_v1.php` (POST opened; PATCH/DELETE unchanged), `.phpunit-concurrency-actor.php` (`category-create` race op), tests: **new** `CategoryCreateOnlyTest` (15), `CategoryCreateConcurrencyTest` (3), updated `CategoryHierarchyApiTest`, `ProductSystemTest` (old "super_admin only" expectation replaced).
Frontend: `views/dashboard/ProductManagementView.vue` (create form for creators, loading/double-submit guard, Indonesian errors), `views/dashboard/ProductFormView.vue` (category select in hierarchy order; removed dead code left by the earlier gallery change), `views/dashboard/__tests__/ProductManagementView.categories.spec.ts`.
Evidence: `docs/rem06-create-only-evidence/`.

## 3. Implementation summary

* Existing endpoint extended — **no parallel endpoint**: `POST /api/v1/categories` now allows `super_admin, admin, agen` (route middleware **and** `ProductCategoryPolicy::create`, defence in depth). `PATCH` and `DELETE` remain behind `role:super_admin` **and** the policy's `manage()`.
* Audited every write path: categories are written only by this controller → service (`ProductManagementController` only *reads* the hierarchy; catalog import and Google-Sheets import do not create categories; no bulk/alternative route exists).
* Category stays one global taxonomy: no owner/network column added; visibility unchanged; readable by everyone as before.
* Create-only roles cannot influence `sort_order`, `is_active` or `slug` — those payload fields are ignored for them (server-generated slug), so a create-only user can neither hide nor re-order the shared taxonomy. Super Admin behaviour is unchanged.
* Response now carries stored defaults (`is_active`, `sort_order`) via `refresh()` — found by the forged-payload test.

## 4. Final authorization matrix (backend-enforced, tested)

| Role | Create parent | Create child | Edit/rename | Delete | Reparent |
|---|---|---|---|---|---|
| Super Admin | ✔ (+ sort_order/is_active/slug) | ✔ | ✔ existing | ✔ existing | ✔ existing (cycle-safe) |
| Admin Agen | ✔ | ✔ | ✘ 403 | ✘ 403 | ✘ 403 (incl. detach) |
| Agen | ✔ | ✔ | ✘ 403 | ✘ 403 | ✘ 403 (incl. detach) |
| konsumen, keuangan, gudang, kurir, sales, korsal, koordinator-kurir, sales-kurir-sub | ✘ 403 | ✘ 403 | ✘ | ✘ | ✘ |
| anonymous | 401 | 401 | 401 | 401 | 401 |

Forged payloads (`role`, `role_id`, `actor_id`, `agent_id`, `created_by`, forged `sort_order/is_active/slug`) neither raise privileges nor change ordering/visibility; the audit actor is always the authenticated user. Product authorization is untouched (existing tests green; a second network's Agen can assign a category created by another network).

## 5. Deduplication design

Key = **normalised name + parent identity** (root = `parent_id IS NULL`).
* Normalisation (`CategoryHierarchyService::normalizeName`): Unicode **NFC**; control and zero-width/format characters (U+200B…) removed; all Unicode whitespace (incl. NBSP, tabs) collapsed to one space; trimmed. The **stored** name is the normalised form. Comparison key = normalised + case-folded (`mb_strtolower`). The column collation is `utf8mb4_unicode_ci` (verified), which is additionally accent-insensitive for SQL lookups.
* Same name under a **different** parent, and root vs child of the same name, are allowed (tested).
* Existing data is never merged or deleted (tested with a legacy duplicate pair and an un-normalised legacy name, which still blocks new duplicates).
* Duplicate → HTTP 422 `name`: "Kategori dengan nama yang sama sudah ada pada induk ini."

## 6. Concurrency guarantee and residual risk

**Mechanism** (stack-supported, no schema change): (1) MariaDB advisory lock `GET_LOCK('primeclassy:product_categories:create')` acquired *before* the transaction and released in `finally` — serialises all creates across connections and is **deadlock-free even on an empty table**, where row/gap-lock-only designs deadlock; (2) inside the transaction the existing `lockTree()` plus a **locking read** (`FOR UPDATE`) of the siblings, so the duplicate check sees every committed row — not an application-only `exists()`. A lock timeout (10 s) yields a controlled 503.

**Proof:** `CategoryCreateConcurrencyTest` — two real PHP processes / two MySQL connections, true time overlap: same root on an **empty** taxonomy (`"Minuman"` vs `"  minuman  "`) → exactly 1 success + 1 duplicate `ValidationException`, no SQL error; same child under one parent → exactly 1 row; same name under different parents → both succeed (3 tests / 15 assertions). Browser: 12 concurrent requests from two users for one root → **1×201, 11×422, 0×5xx**; 10 concurrent child creates → exactly one row; UI double-click → one row. The advisory lock is verified released after success and after rejection.

**Residual risk (stated plainly):** there is **no UNIQUE index** on `(parent, name)`; the database itself cannot refuse a duplicate. Only writers using `CategoryHierarchyService::create` are protected. Existing `slug` uniqueness (DB-enforced) is unchanged. A crash between lock acquisition and release frees the lock automatically (session-scoped).
**Migration proposal (NOT run, needs Owner approval):** audit existing duplicates first; add a generated column `parent_key = COALESCE(parent_id, 0)` and `UNIQUE (parent_key, name)` (name is already `utf8mb4_unicode_ci`, so case/accent-insensitive at DB level); existing duplicate rows would have to be resolved by the Owner before the index can be created. Whitespace/NFC normalisation would remain application-side.

## 7. Audit evidence

Existing mechanism `ActivityLogger` → `activity_logs`, written **inside the same transaction** as the insert (no category without an audit row, no audit row for a rejected create): `event = category.create`, `subject = ProductCategory#id`, `causer_id` = authenticated user, `properties = {category_id, parent_id, actor_role, agent_id, result:"success"}`, `ip_address`/`user_agent` captured by the logger, `created_at`. Test asserts the exact property set for an agen root and an admin child, that a forged payload `actor_id` is ignored, and that a rejected duplicate writes nothing. No sensitive data beyond the category name already in the subject row.

## 8. Scoped tests (exact)

| Area | Command | Result |
|---|---|---|
| Backend | `docker exec -e APP_ENV=testing -e DB_HOST=primeclassy-mariadb primeclassy-fpm php artisan test --filter='CategoryCreateOnlyTest\|CategoryHierarchyApiTest\|CategoryCreateConcurrencyTest\|CategoryHierarchyConcurrencyTest\|CatalogRemediationTest\|ProductSystemTest'` | **53 tests pass** (one stale expectation in `ProductSystemTest` updated to the Owner decision, then 23/23) |
| Backend style | `pint --test` on the 6 changed PHP files | clean |
| Frontend | `vitest run src/views/dashboard/__tests__ src/utils src/views/__tests__` (+ `src/components/catalog`) | **23 files / 182 passed**; dashboard + catalog 16 files / 120 passed |
| Static | `vue-tsc --build`; `eslint` on the two edited views; `vite build` + `build-pwa` | all exit 0 |
Not run: full suites, REM-11 suites.

## 9. Real-browser UAT (real backend, disposable DB, synthetic data) — 20/20 PASS

Chromium → production bundle → Vite proxy → Laravel → `primeclassy_testing`. Script: `docs/rem06-create-only-evidence/uat-real-backend.mjs`.
U1 create-only UI (note, form, **no** rename/delete/reparent controls) · U2 Admin Agen root+child · U3 storefront nesting Minuman → Kopi → Kopi Susu · U4 new categories selectable in the product form (now shown in hierarchy order with indentation) · U5 Agen root+child · U6 a **different network's** Agen sees every category (global) · U7 Admin Agen **and** Agen: rename / reparent / detach / delete / forged-role edit all **403** · U8 nothing changed · U8b second Agen may create · U9 duplicate rejected with a clear Indonesian message, typed name kept, form usable · U10 same name under another parent allowed · U11 root duplicate 422 · U12 12 concurrent same-root creates → exactly 1 row, no 5xx · U13 concurrent same-child creates → 1 row · U14 UI double-click → 1 row · U15 persisted after reload, no duplicate pairs · U16 desktop & mobile, no overflow · U17 super_admin keeps full control.

## 10. Screenshots (`docs/rem06-create-only-evidence/`)

`U-admin-before.png`, `U-admin-created.png`, `U-storefront-hierarchy-d.png`, `U-product-form-category-select.png`, `U-duplicate-error.png`, `U-dashboard-agen-d.png`, `U-dashboard-agen-m.png`, `U-storefront-categories-d.png`, `U-storefront-categories-m.png` (real renders, not mocks).

## 11. Database / migration status

No migration run or created. `primeclassy_db` (DEV) and production untouched. UAT used the disposable `primeclassy_testing` database, rebuilt with `migrate:fresh` afterwards (the operation PHPUnit's `RefreshDatabase` performs); temporary seed/router files removed; helper network attachment reverted; the UAT created no image files.

## 12. REM-11 preservation

No Historical POS backend/frontend file modified in this task (verified by modification times); no REM-11 suite run; flags untouched. Finance/invoice modules untouched; REM-02/03/08/10 not repeated.

## 13. Owner acceptance checklist

1. Login as **Admin Agen** → Dashboard → Produk & Kategori → note says create-only → add a root and a child (pick the parent) → they appear indented.
2. Login as **Agen** (another network) → add a root and a child → confirm Admin Agen's categories are visible too; open `/categories` as anyone: nested hierarchy.
3. Create/edit a product → category list shows the new categories in hierarchy order.
4. As Admin Agen/Agen there is **no** rename, delete or parent-change control; (optional API check) any such call returns 403.
5. Try the same name twice under the same parent (try `kopi`, `  KOPI `) → clear error; same name under a different parent → allowed.
6. Click "Tambah" rapidly / two users add the same name at once → exactly one category.
7. Decide whether to authorize the DB-level unique index (§6).
