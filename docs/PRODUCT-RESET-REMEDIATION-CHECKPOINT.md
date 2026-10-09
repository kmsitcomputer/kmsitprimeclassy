# CHECKPOINT — INDEPENDENT RE-AUDIT + REMEDIATION ROUND 3 (2026-10-09, revision 16)

**Current status: independent re-audit of the uncommitted change set vs baseline `759b58dc6a4fc3b30cc247e804d873bb984875c4` COMPLETE; two in-scope defects confirmed and fixed with regressions; full validation PASS. Ready for Human review / stage gate. No commit, push, deploy or DEV/production mutation was performed.**

This revision supersedes the "ready for re-audit" claim of revision 15. Revisions 1–15 remain historical evidence and are not rewritten.

## A. Re-audit result

Ran the diff against the stated baseline (not just the last commit), including the new `ResetProducts.php` delete engine, the three FK-restoration migrations, the two new DevSchema FK test files, and the `MigrationVerificationTest` / `PricingRemediationTest` drills. The implementation and its fail-closed guards were independently exercised: authorization/IDOR surface, financial-data exposure, inventory source and lock ordering, delivery/fulfilment state transitions, idempotency/replay, invoice grouping vs Order-level payment truth, historical-data compatibility, migration safety, API↔frontend contract, report scoping, realistic failure and concurrency paths. **Two in-scope defects found** (below); neither changes any locked business rule or delete-scope boundary — each closes a hole in the plan that the previous implementation had.
### FINDING R3-1 (functional, confirmed) — delete-ordering inversion on `stock_opnames`/`stock_movements` RESTRICT edge

`DELETE_STEPS` deleted `stock_opnames` (step 29) **before** `stock_movements` (step 31), yet `stock_movements.opname_id → stock_opnames` is `RESTRICT ON DELETE`. `StockOpnameService` writes adjustment/factory-plan movements with `opname_id` set (`tests/Feature/StockOpnameTest.php` lines 89, 123 assert exactly this), so any opname with an applied adjustment movement — the normal production shape — made `products:reset --force` hit errno 1451 inside the transaction and roll back, i.e. the reset could never complete. One-off schema walk (all 204 FKs, `information_schema`) confirms this is the ONLY parent-before-child RESTRICT inversion in the plan.

**Fix:** moved `stock_movements` ahead of `stock_opnames` (and `stock_opname_items`, which is CASCADE-safe either way) in `DELETE_STEPS`, so the RESTRICT child is deleted before its parent. No scope change — all three tables were already `SCOPE_ALL`.

**Regression:** `ProductResetCommandTest::test_an_opname_linked_stock_movement_is_deleted_before_its_opname` (new). The shared full-scope test also now links a movement to the opname via `opname_id` in its fixture, so the production shape is covered in both places.

### FINDING R3-2 (data-integrity, confirmed) — retained-order voucher attribution no longer guarded

The old implementation refused when a preserved order referenced a product-targeted voucher (`voucherAttributionBlockers()` + `assertNoBlockers()`). The reworked `unmappedDependencies()` dropped that entirely: `orders.voucher_id` is a **plain index, not a foreign key**, so the FK-based unmapped-dependency guard cannot see it. A retained (orderless) order's `voucher_id` would silently point at a deleted voucher — contradicting the new code's own "an order without product lines survives untouched" contract. Orders keep `voucher_id` (`OrderService` writes it), so this is a live, silently-orphaning path.

**Fix:** restored the guard as `voucherAttributionReference()` inside `unmappedDependencies()` — a **retained** order (one not in this reset's deleted order set) whose `voucher_id` points at a product-/variation-targeted voucher is reported as unmapped, so both dry-run and `--force` fail closed with zero mutations. Attribution that dies with its own (deleted) order remains allowed by the locked product-reset rule.

**Regression:** `ProductResetCommandTest::test_retained_order_referencing_a_deleted_targeted_voucher_is_refused` (new).

## B. Validation (all against the guarded `primeclassy_testing` only)

- `ProductResetCommandTest` — **19 passed, 187 assertions, exit 0** (17 original + 2 new).
- `DevSchemaForeignKeyRestorationTest` + `DevSchemaForeignKeyRestorationDdlTest` + `MigrationVerificationTest` + `PricingRemediationTest` + `TransactionResetCommandTest` — **32 passed, 365 assertions, exit 0**.
- **Full backend suite** (`DB_DATABASE=primeclassy_testing DB_USERNAME=primeclassy_test APP_ENV=testing php scripts/run-tests-serialized.php`) — **OK (1169 tests, 8589 assertions), Time 31:56.480, exit 0**. The "failed" strings in the log are only deterministic two-connection harness scenario names (`actor_b_outcome":"failed"`, `"a":"success","b":"failed"`), not test failures; final line is `OK (1169 tests, 8589 assertions)`. (Rev-15 recorded 1167/8585; the +2 tests are the two new regressions.)
- `php -l` clean on all 9 touched PHP files; `./vendor/bin/pint --test` → `"result":"passed"`; `git diff --check` → exit 0, no output.

## C. State & next step

Branch `main`, HEAD still `759b58dc6a4fc3b30cc247e804d873bb984875c4` (unchanged). Working tree still consists of the same uncommitted files plus the two `ResetProducts.php`/`ProductResetCommandTest.php` edits in this round. No DEV migration, no reset, no orphan cleanup, no commit/push/deploy, production untouched/still HOLD. The 220 historical orphan references and 26 orphan product image files remain out of scope and untouched.

**EXACT NEXT ACTION:** stop and report to Human. The uncommitted change set (including the two R3 fixes) is ready for Human review / stage-gate approval; no commit or production action without Human authorization.

---

# CHECKPOINT — RE-AUDIT REMEDIATION ROUND 2 (2026-10-08, revision 15)

**Current status: remediation implemented; full verification PASS; ready for independent re-audit.** This revision supersedes the status statements in revision 14; revisions 1–14 remain historical evidence and are not rewritten. No DEV or production database mutation, reset, migration, commit, push or deploy was performed. Branch `main`, baseline HEAD `759b58dc6a4fc3b30cc247e804d873bb984875c4` (HEAD unchanged).

**Task A — delete-scope consistency.** `SCOPE_ALL` no longer lists the order-item child tables. New `SCOPE_BY_ORDER_ITEM` scopes `return_items`, `order_item_adjustments`, `order_fulfillment_change_proposals` through concrete deleted order-item/return owners; all still appear in `DELETE_STEPS` so the dry-run count and executed DELETE share one source of truth. `orderItemOwnedQuery()` fails closed on a step with no recognized owner column. `assertScopeConsistency()` runs before the first write and refuses malformed cross-scope rows (a product-order return with an out-of-scope item, a retained return item referencing a deleted item, a cross-owner adjustment/proposal). The frame-based unmapped-dependency guard now only checks preserved rows against actual delete-scope slices and is restored as the fail-closed gate.

**Task B — shared evidence path protection.** Before any mutation, `excludeSharedEvidencePaths()` drops from the delete list every physical path that any retained database reference still points to (media rows, product images, legacy bank/return evidence, retained shipment/COD proof), using canonical disk/path identity. `assertSelectedMediaSafe()` refuses when a media row in the delete scope is still the FK target of a retained shipment or payment. The post-commit file deletion therefore cannot remove bytes a retained owner relies on.

**Regressions added:** `test_shared_evidence_path_used_by_a_retained_owner_is_kept` (retained media row + physical file survive), `test_evidence_media_referenced_by_a_retained_shipment_is_refused` (fail closed, zero mutations). Existing preservation/deletion/coverage tests for the order aggregate and evidence remain unchanged and passing.

**Test evidence:**
- `DB_DATABASE=primeclassy_testing DB_USERNAME=primeclassy_test APP_ENV=testing php artisan test tests/Feature/ProductResetCommandTest.php` — **17 passed, 177 assertions, exit 0**.
- `DB_DATABASE=primeclassy_testing DB_USERNAME=primeclassy_test APP_ENV=testing php artisan test tests/Feature/DevSchemaForeignKeyRestorationDdlTest.php tests/Feature/MigrationVerificationTest.php tests/Feature/PricingRemediationTest.php tests/Feature/TransactionResetCommandTest.php` — **24 passed, 306 assertions, exit 0**.
- Final full suite after all source/test changes: `DB_DATABASE=primeclassy_testing DB_USERNAME=primeclassy_test APP_ENV=testing php artisan test` — **1167 passed, 8585 assertions, 0 failed, exit 0**, duration 1925.39 s.
- PHP syntax (`php -l`), Pint (`./vendor/bin/pint --test …`), and `git diff --check` — **all passed / exit 0**.

**Remaining risk/status:** production state is unknown and deployment is not approved; the 220 historical orphan references and 26 orphan product image files remain untouched. No commit, push, deploy, DEV reset/migration, orphan cleanup or production action was performed and none is authorized by this remediation request.

**EXACT NEXT ACTION:** hand this uncommitted diff to an independent reviewer for re-audit against baseline `759b58dc6a4fc3b30cc247e804d873bb984875c4`.

---

# HISTORICAL CHECKPOINT — POST-P5 BASELINE & QUALITY GATE (2026-10-08, revision 13; superseded by revision 14)

**Status: GATE PARTIAL — 2 test failures, both test-side, awaiting Human decision.** No DELETE/UPDATE/TRUNCATE, no migration, no reset, no commit/push/deploy, production untouched. Branch `main`, HEAD `759b58dc6a4fc3b30cc247e804d873bb984875c4`.

**1. Post-P5 baseline (outside repo, mode 700):** `/home/developer/primeclassy-dev-backups/post-p5-baseline-20261008-193657/` — `schema-only.sql` (routines/triggers included), `fk-keys.tsv` (204), `fk-rules.tsv` (204), `tables.txt` (97), `row-counts-checksums.tsv` (97), `data-full.sql` (9.2 MB full data dump, complete-insert off, single-transaction). `sha256sum -c`: **6/6 OK**.

**2. 220 orphan audit (READ-ONLY, unchanged, nothing repaired):**

| FK | Orphans | Missing parent ids | Business function / owner | Likely cause | Remediation options (none executed) |
|---|---|---|---|---|---|
| `activity_logs.causer_id → users` | 212 (in 750 logs) | 2–10, 12–16 | Audit trail. Owner: system/admins. 163 of the 212 also have a deleted user as subject. | Users 2–16 hard-deleted in earlier cleanup/reset while the FK was not enforced. The FK is `RESTRICT`, so deletion with checks on would have failed. | Keep as historical snapshot (value is an id, not a live relation); or `SET NULL` for these rows under an approved, backed-up update. |
| `sheets_destinations.agent_id` | 1 (`id=1`) | 2 | Sheets integration config for deleted agent 2. **Live**: `sheets_configs.destination_id=1` still references it. | Agent 2 deletion. | Owner decision: reassign or deactivate. Must not guess the new agent. |
| `agent_shipping_provider_configs.agent_id` | 2 (`id=2,3`) | 2 | Shipping provider config of deleted agent 2. | Agent 2 deletion. | Owner decision; `CASCADE` FK would have removed them. |
| `agent_payment_gateway_configs.agent_id` | 1 (`id=1`) | 2 | Payment gateway config of deleted agent 2. Financial/credential-adjacent. | Agent 2 deletion. | Owner decision; treat as sensitive, do not reassign. |
| `couriers.agent_id` | 1 (`id=1`, "Isyraqi") | 2 | Courier master of deleted agent 2. No shipment or return references it. | Agent 2 deletion. | `SET NULL` candidate (nullable). Human decision. |
| `couriers.user_id` | 1 (same row) | 6 | Courier's linked user, deleted. | User 6 deletion. | Same row as above. |
| `konsumen_addresses.user_id` | 2 (`id=1,2`) | 4 | Customer addresses of deleted customer 4. `orders.source_address_id` references none of them (0 orders in DEV). | Customer 4 deletion. | `CASCADE` FK would delete; candidate for removal only after Human approval. |

Totals: 212 + 8 = **220**, identical to revisions 10–12. Context: DEV has **0 orders**, so no live order is affected. The `activity_logs` 212 rows are the bulk and are not a live relation. Only `sheets_configs → sheets_destinations` is a live chain (1 row).

**3. Full backend suite (testing DB only):** `DB_DATABASE=primeclassy_testing DB_USERNAME=primeclassy_test APP_ENV=testing php artisan test` (credentials of `.env.testing`; DEV user not used).
- Result: **Tests 2 failed, 1162 passed (8494 assertions), EXIT=2.**
- FAIL A — `Tests\Feature\MigrationVerificationTest::rollback removes everything` (line 68): test does `migrate:rollback --step 14` and expects `delivery_verifications` gone. There are now **16** migration files after `2026_10_01_100001_create_delivery_verifications_table` (the 2026_10_02, 10_04, 10_05, 10_08 ones were added after the test was written). Test drift: step count hard-coded. Not a schema defect.
- FAIL B — `Tests\Feature\PricingRemediationTest` discount/voucher test (line 293): `PaymentService::requestSettlement()` returns `array` (`[$transaction, bool]`, service line 498), but the test passes the whole tuple to `submitBankTransferProof(PaymentTransaction …)`. TypeError. Test is stale vs service signature. Test file unmodified since `b85d0f0`.
- Both are **test-side**; no production code changed to make them pass. Remediation (test edit) needs Human approval.
- Caveat: not re-run against `HEAD` without the uncommitted changes, so "pre-existing" is supported by the code evidence, not by a clean-HEAD run.

**4. Gate checks:**
- `git diff --check`: **exit 0, no output** (clean).
- Migrations: `migrate:status` → **131 Ran, 0 Pending** (includes P1 `110000`, P2 `111000`, P5 `112000`).
- Endpoints (live DEV, GET only): `https://dev.primeccookies.com/api/v1/settings` → **200** (JSON `success:true`, branding present). `/api/v1/categories` → **200** (data). `/api/v1/products` → 200, `data: []` (DEV catalogue empty, expected after P3). `/up` → 200. `/api/settings` → 404 (no such route, not an outage).

**Findings (for Human):**
- F1 (test drift, MINOR): hard-coded rollback step in MigrationVerificationTest.
- F2 (test stale, MINOR-MAJOR for suite trust): PricingRemediationTest passes a tuple; the test is not covering the discount/voucher path it claims to cover while failing.
- F3 (data debt, see table above): 220 orphan refs; no live order affected; 1 live chain via sheets config.
- F4 (carry-over): 26 orphan product image files on disk (rev 9 F4) — unchanged.

**EXACT NEXT ACTION:** stop and report. Await Human decision on: (a) approve a test-only fix for F1 and F2 followed by a re-run of the full suite; (b) per-FK decision for the 220 orphan rows (owner/policy per table above — no remediation without approval); (c) whether to commit the working tree (currently uncommitted changes in `ResetProducts.php`, tests, docs and new migrations). No DB action taken.

---

# CHECKPOINT — P5 POST-MIGRATION VERIFICATION (2026-10-08, revision 12)

**Status: P5 VERIFIED ON DEV — PASS (with one documented inconclusive probe).** Human ran `2026_10_08_112000_restore_post_cleanup_foreign_keys` on `primeclassy_dev` (DONE 130.35 ms, MIGRATE_EXIT=0). This session was **READ-ONLY**: no DELETE, UPDATE, migration, reset, commit, push, deploy; testing and production not touched.

**Identity:** `primeclassy_dev` on host `kmsitcomputer.com`, MariaDB 10.11.10. Testing (`primeclassy_testing`) read via `.env.testing` user `primeclassy_test`.

**Migrations:** `110000` (P1), `111000` (P2), `112000` (P5) all **Ran** — batches 14, 15, 16. Total `migrations` = **131** (was 130; +1 = P5 bookkeeping only).

**FK count:** dev **199 → 204** (+5). Testing 204. Diff of the full sorted FK set (name, table, column, referenced table/column, DELETE, UPDATE) against `primeclassy_testing`: **exactly 6 pairs differ, all NO ACTION (dev) vs RESTRICT (testing)** — `inventory_cancellation_reversals` ×4 and `sheets_configs.destination_id`, `sheets_destinations.agent_id`. Approved; not changed.

**Five P5 FKs present, correct:** `shipping_configurations_agent_id_foreign` (CASCADE/RESTRICT), `user_closures_ancestor_id_foreign` (CASCADE/RESTRICT), `user_closures_descendant_id_foreign` (CASCADE/RESTRICT), `warehouse_migration_markers_product_id_foreign` (RESTRICT/RESTRICT), `warehouse_migration_markers_product_variation_id_foreign` (RESTRICT/RESTRICT). Nothing lost: all **107** pre-P1 FKs from `foreign-keys-before.tsv` present with identical rules (0 missing, 0 changed). 97 added = P1 91 + P2 1 + P5 5.

**Orphans for the 5 P5 FKs: all 0** (`shipping_configurations.agent_id` 0, `user_closures.ancestor_id` 0, `user_closures.descendant_id` 0, `warehouse_migration_markers.product_id` 0, `warehouse_migration_markers.product_variation_id` 0).

**220 orphan references — re-audit over all 204 FKs: UNCHANGED, not repaired.** Same 7 FKs, same distribution: `activity_logs.causer_id→users` 212 · `agent_shipping_provider_configs.agent_id` 2 · `konsumen_addresses.user_id` 2 · `sheets_destinations.agent_id` 1 · `couriers.agent_id` 1 · `couriers.user_id` 1 · `agent_payment_gateway_configs.agent_id` 1 = **220**. None of these is a P5 FK. Recorded as technical debt.

**COUNT / CHECKSUM vs baseline (`fk-restoration-20261008-154004/row-manifest.tsv`, pre-reset, 97 tables):**
- Protected/master tables **identical** (count and checksum): `users` 20, `roles` 11, `settings` 18 (branding), `districts` 7,265, `villages` 83,202, `provinces` 38, `regencies` 514, `product_categories` 3, `warehouse_settings` 1, `warehouse_sub_locations` 1, `media` 2.
- `shipping_configurations`: count 2 unchanged; checksum changed as expected by P4b (id 1 `agent_id NULL`, `is_active 0`).
- `user_closures` 32 → 31 (P4a). `migrations` 128 → 131 (P1, P2, P5 bookkeeping).
- Other differences are from the P3 `products:reset` (products, variations, orders, order_items, shipments, stock/warehouse tables, catalog_skus, payment_transactions, bank_transfer_verifications, etc.) and runtime tables (`activity_logs` 814 → 758 via reset, `cache`, `sessions`). None of these is changed by P5, which is a schema-only migration.
- Limitation: no full post-P4b checksum snapshot was saved, so "only P5 bookkeeping changed" is proven by the FK/schema diff and by the protected-table identity above, not by a full P4b→P5 checksum diff.

**Liveness:** `https://dev.primeccookies.com/up` → **200**. `/api/` → 404 on the root; this is **inconclusive** (root has no route) and was not probed further.

**Recommendations (not executed):**
1. Decide on the 220 orphans in a separate authorized step; `activity_logs.causer_id` (212) is the bulk and likely a `SET NULL`-style historical reference, so do not delete it blindly.
2. Decide whether the 6 NO ACTION/RESTRICT differences should be aligned to canonical or accepted permanently (currently accepted).
3. Save a full post-P5 `row-manifest` snapshot as the new baseline in a backup folder.
4. Probe a real `/api/...` endpoint for the frontend contract check.
5. Full backend suite (`php artisan test`) and `git diff --check` still owed before any commit (AGENTS.md §7); nothing committed.

**EXACT NEXT ACTION:** stop and report to Human. Await decision on (a) the 220 orphan debt, (b) whether to save the post-P5 baseline, (c) the commit/review step. No further DB action.

---

# CHECKPOINT — P4b EXECUTED (Resume P4b, 2026-10-08, revision 11)

**Status: P4b EXECUTED AND VERIFIED — PASS (DEV only).** Branch `main`, HEAD `759b58dc6a4fc3b30cc247e804d873bb984875c4`. Working tree already had uncommitted changes from earlier sessions (not touched here). No commit, push, deploy, production or testing change. P5 **not** run.

**Authorized action differs from revision 10's text:** Human's instruction is an **UPDATE, not a DELETE** — row id 1 is kept. Executed exactly: `agent_id = NULL`, `is_active = 0`.

**Pre-state verified before acting (was NOT yet executed):** `shipping_configurations` id 1 `agent_id=2` (user 2 missing → orphan), id 2 `agent_id=11` (live). Had P4b already run, id 1 would show `agent_id NULL / is_active 0`; it did not.

**Snapshot:** `/home/developer/primeclassy-dev-backups/p4b-shipping-configurations-orphan-20261008-192702/` (mode 700, files 600) — `shipping_configurations-BEFORE.txt`, `shipping_configurations-complete-insert.sql` (both rows, 1 multi-row INSERT), `counts-BEFORE.txt`, `fk-BEFORE.txt`, `SHA256SUMS` (4 entries).

**Executed statement (single statement, atomic, pinned):**
```sql
UPDATE shipping_configurations sc
   SET sc.agent_id = NULL, sc.is_active = 0
 WHERE sc.id = 1 AND sc.agent_id = 2
   AND NOT EXISTS (SELECT 1 FROM users u WHERE u.id = 2);
```
Result: **affected rows = 1**. Column `agent_id` is nullable; no triggers on the table; `shipping_provider_id` FK is `SET NULL`/`RESTRICT`, unaffected. `updated_at` did not change (no ON UPDATE clause).

**Post-verification:**
| Check | Result |
|---|---|
| id 1 | `agent_id NULL`, `is_active 0`, all other columns identical |
| id 2 | `agent_id 11`, `is_active 1`, unchanged |
| `shipping_configurations` rows | 2 → 2 (no delete) |
| agent_id orphans | **1 → 0** |
| `user_closures` / `users` / `migrations` / FK count | 31 / 20 / 130 / 199 — unchanged by this step |

**FK orphan audit (READ-ONLY, all 199 FK on primeclassy_dev):** total **220 orphan references across 7 FKs** — matches the Human's report:
- `activity_logs.causer_id → users` **212**
- `agent_shipping_provider_configs.agent_id → users` 2
- `konsumen_addresses.user_id → users` 2
- `sheets_destinations.agent_id → users` 1
- `couriers.agent_id → users` 1
- `couriers.user_id → users` 1
- `agent_payment_gateway_configs.agent_id → users` 1

None of these 7 is a P5 constraint; **none was touched** (no delete, no repair). All other 192 FK have zero orphans. Note: these 220 are out of the P5 blocker set; they are recorded as technical debt, not fixed.

**P5 blocker status now:** `shipping_configurations.agent_id` 0, `user_closures.ancestor_id` 0, `user_closures.descendant_id` 0, `warehouse_migration_markers.product_id` 0, `warehouse_migration_markers.product_variation_id` 0, `villages.district_id` 0. P5 preflight is no longer blocked by data; **P5 awaits a separate Human go-ahead.**

**EXACT NEXT ACTION:** stop. Report to Human. Do not run P5 until the Human explicitly authorizes it. Before P5: re-run the 5-constraint orphan check (expected all 0) and `php artisan migrate --force --path=…112000…` only on explicit approval.

---

# CHECKPOINT — P4a ORPHAN CLEANUP EXECUTED (SpaceBunny, 2026-10-08, revision 10)

**Status: P4a EXECUTED AND VERIFIED — PASS.** One row deleted from `user_closures` on DEV, nothing else touched. No user deleted, no other table changed, P4b and P5 **not** run, no commit/push/deploy.

**HUMAN DECISION (LOCKED 2026-10-08):** delete only the `user_closures` rows whose `ancestor_id` or `descendant_id` is not found in `users`. Nothing else.

## 1. Database identity

`.env DB_DATABASE=primeclassy_dev` · `SCHEMA()=primeclassy_dev` · local MariaDB 10.11.10. `SHOW DATABASES` returns only `information_schema` and `primeclassy_dev` — testing and production are not visible to this account.

## 2–3. Orphan audit — `LEFT JOIN users` over all 32 rows

| Metric | Value |
|---|---|
| Closure rows audited | **32** |
| Rows with a missing `ancestor_id` | **1** |
| Rows with a missing `descendant_id` | **1** |
| **Orphan rows** | **1** |
| **Fully valid rows** | **31** |
| Missing user ids | **2** and **7** |
| Live users | `1, 11, 17…34` (20 users) |

**The single orphan: `id=11`, `ancestor_id=2`, `descendant_id=7`, `depth=1`.** Both endpoints no longer exist; the edge is unreachable and is the sole reason `user_closures.ancestor_id` and `.descendant_id` cannot be restored. Every one of the 31 remaining rows shows `ancestor ok / descendant ok`, including all the multi-hop closure edges (depth 1 and 2 chains through users 20, 21, 22, 26, 27, 28, 29, 30, 31, 32, 33) — the hierarchy is structurally intact.

## 4. Every valid relation preserved

All 31 valid rows kept, listed and re-verified after the deletion. Self-closure roots (depth 0) for users 1 and 17–34 are all present.

## 5. Backup intact and contains the deleted row

`pre-products-reset-20261008-163744`: `sha256sum -c SHA256SUMS` → **9/9 OK, 0 FAILED**; `database.sql` ends with `-- Dump completed`. Its `user_closures` INSERT block holds **32 tuples** — ids `1, 11, 43…72` — and **`id=11` with `ancestor_id=2, descendant_id=7, depth=1` is present verbatim**, matching the live row byte for byte. The dump count (32) agrees with `row-manifest.tsv` (`user_closures 32 / 2346827439`) and with the live table at audit time (32 / `2346827439`), so **no backup inconsistency**.

## 6. Gate check → deletion

All three stop-conditions were checked before touching anything and none fired: exactly one orphan (id 11), no data change since the rev 9 verification (32 rows / checksum `2346827439` unchanged), backup intact and containing the row.

**Pre-delete snapshot: `/home/developer/primeclassy-dev-backups/p4a-user-closures-orphan-20261008-182017/`** (mode `700`, files `600`) — `user_closures-BEFORE.txt`, `orphan-row-BEFORE.txt` (with the LEFT JOIN verdict), `user_closures-complete-insert.sql`, plus the executed statement, the queries, the after-state and `SHA256SUMS` (**9 files, 9/9 OK**).

**The executed statement — one row, and it re-validates the orphan condition atomically at execution time:**

```sql
DELETE uc
  FROM user_closures uc
  LEFT JOIN users a ON a.id = uc.ancestor_id
  LEFT JOIN users d ON d.id = uc.descendant_id
 WHERE uc.id = 11
   AND a.id IS NULL
   AND d.id IS NULL;
```

`uc.id = 11` is a hard pin, and both `IS NULL` predicates re-prove the row is still an orphan inside the same statement — if a user 2 or 7 had reappeared, or the row had changed, the statement would have matched **0 rows** and deleted nothing.

## 7–8. Post-delete verification

| Check | Result |
|---|---|
| `user_closures` rows | **32 → 31** |
| Row id=11 still present | **0** |
| Re-audit with `LEFT JOIN` | **0 orphans, 31 fully valid** |
| Before/after set diff vs the pre-delete snapshot | **removed exactly `{(11,2,7,1)}`, added nothing, 31 identical** |
| `users` | **20** — untouched · `roles` **11** — untouched |
| **All 97 tables re-measured vs the post-P3 state** | **exactly 1 differs: `user_closures` 32→31, checksum `2346827439`→`4132631048`. No other business table changed.** |
| Foreign keys | **199 — unchanged** (no migration ran) |
| `migrations` | 130, unchanged · P5 still **Pending** |

**Read-only simulation of P5's preflight:** `user_closures.ancestor_id` orphans **0**, `user_closures.descendant_id` orphans **0** — both P5 blockers on this table are cleared. `shipping_configurations.agent_id` orphans **1** (row id 1 still present) — P4b remains the only remaining blocker, and **P5 will correctly keep failing closed until P4b runs.**

## Residual risks

- The closure now contains **no row for the deleted users 2 and 7**, which is correct: they do not exist. If users 2/7 identities are ever legitimately restored, their closure edges would have to be rebuilt from the referral history — this delete is not reversible by re-inserting the row alone, because the endpoints are absent (and re-creating the users is forbidden by AGENTS.md §9).
- `user_closures.ancestor_id`/`.descendant_id` remain unrestored until P5 runs, so the closure is still unguarded against new dangling edges until then.
- P5 is blocked only by P4b (`shipping_configurations` id 1).
- DEV still has an empty catalogue and 26 orphaned product image files on disk (rev 9 F4).
- Full backend suite still unrun — owed before any commit (AGENTS.md §7).

---

# CHECKPOINT — P3 POST-RESET INDEPENDENT VERIFICATION (SpaceBunny, 2026-10-08, revision 9)

**Status: P3 EXECUTED AND INDEPENDENTLY VERIFIED — PASS.** `products:reset --force` ran on DEV with backup `pre-products-reset-20261008-163744`. Verified independently against that backup, not from the command's own report. No further migration, no DELETE/TRUNCATE/DROP, no change to testing or production, no commit/push/deploy.

Baseline: pre-P3 `row-manifest.tsv` (97 tables, 92,200 rows, 199 constraints, 130 migrations). Backup re-verified first: `sha256sum -c SHA256SUMS` → **9/9 OK**.

## 1. Database identity

`.env DB_DATABASE=primeclassy_dev` · `SCHEMA()=primeclassy_dev` · local MariaDB 10.11.10. `SHOW DATABASES` for this account returns only `information_schema` and `primeclassy_dev` — testing and production are **not visible to it**, so a wrong-database read was impossible.

## 2. Full comparison against the pre-P3 manifest

97 tables before and after; table-name set **identical** (no table dropped or added).

| Outcome | Tables | Detail |
|---|---|---|
| **Identical row_count AND checksum** | **77** | includes all 37 preservation-guard tables |
| **Emptied (rows > 0 → 0)** | **19** | all inside the audited delete scope |
| **Changed but not emptied** | **1** | `activity_logs` 814 → 758 |
| Already empty, still empty | 45 | — |
| Rows | 92,200 → 92,095 | −105 |

**Emptied tables (19) — every one inside the scope audited in rev 8, 0 outside:**
`orders`, `order_items`, `shipments`, `payment_transactions`, `bank_transfer_verifications`, `stock_requests`, `stock_request_items`, `stock_movements`, `warehouse_stocks`, `warehouse_stock_requests`, `warehouse_migration_runs`, `warehouse_migration_markers`, `catalog_skus`, `products`, `product_variations`, `product_variation_stocks`, `product_variation_attributes`, `product_variation_attribute_options`, `product_variation_compositions`.

## 3. The three specifically named items

- **56 activity logs — exact and no collateral.** Reconstructed the pre-P3 log ids straight out of the backup dump: **56** in-scope ids (`App\Models\Product` 52 + `App\Models\Order` 4) and **758** survivors. Current DB: **758 rows, identical id set**. **0 in-scope ids remain, 0 audited survivors missing.** `subject_type` counts now: Product 0, Order 0, User 666, ShippingProvider 32, ProductVariationStock 28 — exactly the preserved set.
- **Order id 3 — gone.** `orders` = 0, `order id=3` = 0, `order_items`/`shipments`/`payment_transactions`/`bank_transfer_verifications` = 0/0/0/0.
- **The Rp50.000 payment — gone.** `payment_transactions WHERE id=2 OR gateway_reference='DP-QSAQCMT1GY'` → **0 rows**. Also 0 in `returns`, `return_items`, `commissions`, `inventory_cancellation_reversals`, `payment_webhook_logs`, `order_additional_payments`, `order_item_adjustments`, `delivery_verifications`.

## 4. The 37 preservation-guard tables — verified by COUNT *and* CHECKSUM

**37/37 identical on both `COUNT(*)` and `CHECKSUM TABLE`.** Not one row count changed, not one checksum changed. Highlights: `roles` 11/`929623568` · `users` 20/`3062609602` · `villages` 83,202/`3428906191` · `user_closures` 32/`2346827439` · `shipping_configurations` 2/`3122448241` · `settings` 18/`112737217` · `product_categories` 3/`3623388428` · `districts` 7,265/`1656997441` · `warehouse_sub_locations` 1/`334935247` · `warehouse_settings` 1/`4165870515`.

## 5. Users, roles, categories, configuration, branding — intact

| Area | State |
|---|---|
| Users / roles / profiles / addresses | `users` 20, `roles` **11** (locked invariant holds), `agent_profiles` 1, `konsumen_addresses` 4, `user_social_identities` 3 — all checksum-identical |
| Categories / regions | `product_categories` 3, provinces 38, regencies 514, districts 7,265, villages 83,202 — identical |
| Payment + shipping config | `payment_methods` 6, `shipping_providers` 2, `shipping_couriers` 17, `couriers` 5, `agent_payment_gateway_configs` **2**, `agent_shipping_provider_configs` **4**, `google_auth_settings` 1, `agent_google_auth_configs` 1 — identical |
| Site configuration | `settings` 18, `languages` 4, `sheets_configs` 1, `sheets_destinations` 2 — identical |
| CMS | `cms_homepage_blocks` 4 + 2 image files on disk — identical |
| **Branding** | `media` **2 rows kept** (`site_logo` id 1, `site_favicon` id 2); `settings.site_logo_media_id=1` and `settings.site_favicon_media_id=2` still resolve to live rows; **both image files byte-identical to the backup tarball** (SHA-256 verified). The `public_html/config.js` / `site_favicon_media_id` invariant is intact |
| Warehouse containers | `warehouse_settings` 1, `warehouse_sub_locations` 1 — identical |

## 6. Warehouse history and product relations — fully cleaned

`warehouse_migration_markers` **0** · `warehouse_migration_runs` **0** · `warehouse_stock_requests` 0 · `stock_movements` 0 · `warehouse_stocks` 0 · `stock_requests` 0 · `stock_request_items` 0 · `stock_transfers`/`_items`/`stock_handovers` 0 · `stock_opnames`/`_items` 0 · `sub_stock_requests`/`_items`/`_reservations` 0 · `catalog_skus` 0 · `products` 0 · `product_variations` 0 · `product_stocks` 0 · `product_variation_stocks` 0 · `product_variation_compositions` 0 · `product_variation_attributes` 0 · `product_variation_attribute_options` 0 · `product_images` 0.

**Independent sweep:** every one of the **29** `product_id` / `product_variation_id` columns that carry an FK into `products`/`product_variations` was counted directly — **all 29 hold 0 non-null rows.** No table anywhere in the schema still references a product or variation.

**The four `warehouse_migration_markers` orphans that blocked P5 are gone.** Remaining blockers for P5 are only the two identity/configuration rows.

## 7. FK and migration state

`foreign-keys-before.tsv` (199) vs now (199): **0 added, 0 dropped, 0 rules changed.** The reset is schema-neutral, as designed. `migrations` = 130, unchanged. Still missing vs canonical `primeclassy_testing` = **5**, exactly the P5 set: `shipping_configurations.agent_id`, `user_closures.ancestor_id`, `user_closures.descendant_id`, `warehouse_migration_markers.product_id`, `warehouse_migration_markers.product_variation_id`.

Migration status: P1 `[14] Ran` · P2 `[15] Ran` · **P5 `Pending`**. DEV is up (no maintenance file).

## F4 confirmed — orphan bytes were NOT reclaimed (as predicted)

`storage/app/public` still holds **33 files, exactly as the backup captured them**, including **all 26 orphaned files in `products/`**. File deletion is row-driven and `product_images` had 0 rows, so the reset removed nothing from disk. This matches prediction and `File deletion failures = 0` is accurate — there was nothing to delete. Consequence: the product-domain bytes are still on disk with no database row pointing at them. **Not deleted by me**; reclaiming orphan bytes remains a separate, separately authorized cleanup.

## Residual risks

- P5 is now blocked by only two single-row deletions (P4a `user_closures` id 11, P4b `shipping_configurations` id 1); both rows were deliberately **preserved** by the reset (they are in guarded tables) and remain in the backup verbatim.
- 26 orphaned product image files remain on disk with no DB row — dead bytes, not data loss.
- The pre-existing 6 `NO ACTION` vs `RESTRICT` differences are still untouched, by locked decision.
- DEV now has an empty catalogue: no products, no variations, no SKU registry, no warehouse stock. Any UAT that needs catalogue data must seed it first.
- Full backend suite still unrun — owed before any commit (AGENTS.md §7).

---

# CHECKPOINT — P3 PRE-RESET SAFETY GATE (SpaceBunny, 2026-10-08, revision 8)

**Status (rev 8, historical): READY FOR AUTHORIZATION — NOT EXECUTED.** This revision recorded the pre-reset gate. It was executed and verified in revision 9 above. No `--force` beyond that single authorized run, no extra migration, no change to `primeclassy_testing` or production, no commit/push/deploy.

**Final status: READY** (no BLOCKER). Four findings recorded below — none is a scope-widening defect, but two are material business consequences the Human must accept knowingly before authorizing, and two are dead bytes the reset will not reclaim.

## Backup reference for P3

**Path: `/home/developer/primeclassy-dev-backups/pre-products-reset-20261008-163744/`** (outside the repository, dir `700`, files `600`, mode-600 `--defaults-extra-file`, no password on any command line). 10 files, 9 checksummed, `sha256sum -c SHA256SUMS` → **9/9 OK**.

| File | Size | Content |
|---|---|---|
| `database.sql` | 3,795,416 B | Full dump of `primeclassy_dev` at 199 constraints / 130 migrations: `--single-transaction --routines --triggers --events --hex-blob`, 97 `CREATE TABLE`, `-- Dump completed`, no `CREATE DATABASE`/`USE` (restores into any schema) |
| `schema-only.sql` | 133,997 B | `--no-data` DDL snapshot |
| `show-create-table-all.out` | 117,329 B | `SHOW CREATE TABLE` for **all 97 tables** |
| `row-manifest.tsv` | 2,734 B | `COUNT(*)` + `CHECKSUM TABLE` for all 97 tables, 92,200 rows |
| `foreign-keys-before.tsv` | 21,101 B | the 199 constraints live before P3 |
| `tables.txt` | 1,842 B | table list |
| `storage-app-public.tar.gz` | 4,512,707 B | 33 media files |
| `backend.env`, `SHA256SUMS` | — | DEV env (600) and checksums |

## Restore test — PASS

Isolated throwaway **MariaDB 10.11** container (same engine version), `127.0.0.1:13307`, tmpfs, scratch schema `primeclassy_restore_test`. `primeclassy_dev` only read.

| Check | Result |
|---|---|
| Load | exit 0, **0 errors / 0 warnings**, 2,138 ms |
| Tables | 97/97, name set **identical** |
| `COUNT(*)` vs manifest | **0 mismatches**, 92,200 rows |
| `CHECKSUM TABLE` vs manifest | **0 mismatches** |
| Constraints / migrations reproduced | 199 / 130 — the post-P2 DEV state, exactly |

Container removed afterwards; temp credential file shredded.

## Task 3 — audit of the 56 in-scope `activity_logs`

Scope = `subject_type IN (ResetProducts::ACTIVITY_SUBJECT_TYPES)` — **26** types. Live breakdown of all 814 rows:

| Bucket | Rows | Verdict |
|---|---|---|
| `App\Models\Order`, subject_id 3 | 4 | **VALID** — order 3 has product lines → deleted by this run |
| `App\Models\Order`, any other subject_id | 0 | — |
| `App\Models\Product`, subject_id **21/22** (live) | 4 | **VALID** — those products are deleted by this run |
| `App\Models\Product`, subject_id **1–20** | **48** | **NOT tied to a record this run deletes** — see F1 |
| **Total in scope** | **56** | matches the dry run exactly |
| Preserved: `User` 666, `ShippingProvider` 32, `ProductVariationStock` 28, `PaymentMethod` 9, `GoogleAuthSetting` 8, `AgentProfile` 7, `ProductStock` 4, `AgentGoogleAuthConfig` 3, `WarehouseSubLocation` 1 | **758** | correct |
| The other 24 scope types | 0 rows present | — |

**Rows that must NOT be deleted: 0.** No in-scope log belongs to a surviving order (0 orders survive without product lines), and every log about a live product points at product 21/22 which are deleted. The reset would not destroy audit for a record it keeps.

### F1 — 48 of the 56 rows are historical residue of products deleted long ago (MINOR, needs Human awareness)

Products **1–20 no longer exist** (they were hard-deleted before this run; only 21 and 22 are live). Their 48 logs — including **20 `product.created` events** documenting the original catalog build — are already dangling. Deleting them is consistent with the locked decision ("every relation that references them") and arguably tidier than leaving logs pointing at phantom SKUs, but it permanently destroys the lifecycle history of products 1–20, **including product 11** (log ids 47/63/304/305/823) — the exact product whose absence caused the four `warehouse_migration_markers` orphans. **Flagged, not changed.** I did not widen or narrow the scope.

### F2 — the `activity_logs` scope is type-based, not id-based (MINOR, latent)

`whereIn('subject_type', …)` with no id filter means a log about a **surviving** order would also be deleted. On DEV today that is **0 rows**, so the current blast radius is exactly right — but the mechanism is wider than the documented rule ("whose subject is a deleted record"). Recorded as technical debt; out of scope to change now (it would alter a locked decision's implementation).

## Task 4 — transaction and warehouse scope audit

Everything in scope traces to order 3 or the catalog. **No over-broad deletion found.**

| Table | Rows | Justification |
|---|---|---|
| `orders` | 1 (id 3, `PC-261007-000003`, `diproses`/`partially_paid`) | has product lines |
| `order_items` | 2 (ids 10, 11 → product 21, variations 40 & 41) | product lines |
| `shipments` | 1 (id 7, order 3, `standard`, no proof media) | hangs off order 3 |
| `payment_transactions` | 1 (id 2, order 3, **status `paid`**, 50,000.00, gateway ref `DP-QSAQCMT1GY`) | hangs off order 3 |
| `bank_transfer_verifications` | 1 (id 2, txn 2, **`verified`**, verified_by 18) | follows its payment |
| `stock_requests` / `stock_request_items` | 1 / 2 (order 3) | container + product-targeted lines |
| `warehouse_stock_requests` | 2 (variation 41 qty 144, variation 40 qty 72, agent 11, approved) | product-targeted |
| `warehouse_stocks` | 2 (both product-targeted → **table becomes empty**) | product-targeted |
| `product_variation_stocks` | 2 (on_hand 0, reserved 2 and 1) | catalog stock |
| `stock_movements` | 4 (factory_in 144/72, reserve 2/1) | product-targeted |
| `warehouse_migration_runs` / `markers` | 1 / 4 | clears the four P5 orphans |
| `catalog_skus` | 5 (all `variant`-owned → **registry becomes empty**) | variant-owned |
| `delivery_verifications`, `cod_payment_proofs`, `returns`, `return_items`, `commissions`, `inventory_cancellation_reversals`, `payment_webhook_logs`, `order_additional_payments`, `order_item_adjustments` | 0 | nothing to delete |

### F3 — a verified, already-paid DP and its bank proof will be permanently destroyed (material, needs Human acceptance)

Order 3 is `partially_paid` and `payment_transactions` id 2 is **`paid`** with a real bank transfer **verified** by user 18 (50,000.00). `products:reset` deletes both, plus the `bank_transfer_verifications` row. This is exactly what the locked decision authorises, but it is a real recorded money movement on DEV and must be accepted knowingly.

Note: the referenced proof file `payments/bank-transfer-proofs/2bZqirP0fBitY8BkzJLhUQRAIyAHQ0pCUAlgEVbt.png` **does not exist on disk** (the `payments/` directory is empty), so the file-deletion phase has nothing to remove there.

### F4 — 26 orphaned image files survive the reset (MINOR, dead bytes)

`product_images` has **0 rows**, yet `storage/app/public/products/` holds **26 orphaned files** from products deleted earlier. File deletion is driven by DB rows, so the reset will **not** remove them. Likewise `media/site_logo/` and `media/site_favicon/` each hold 2 files for 1 media row. Neither is data loss (no row references them) — the reset simply will not reclaim the disk. **Not deleted by me**; removing orphan bytes is a separate, separately authorized cleanup.

## Task 5 — preservation guard and out-of-guard risk

Independent derivation from the live schema, not from the command's own report:

`97 tables = 52 delete-scope + 5 shared + 8 runtime + 37 guarded` — **reproduces the reported 37 exactly.** Every scope key maps to a real table; no scope key is dangling.

The **37 guarded tables** are all master/configuration/region/user data: `agent_google_auth_configs`, `agent_payment_gateway_configs`, `agent_payment_method_settings`, `agent_profiles`, `agent_shipping_provider_configs`, `agent_shipping_provider_settings`, `cms_articles`(+`_translations`), `cms_homepage_blocks`(+`_translations`), `cms_pages`(+`_translations`), `couriers`, `districts`, `google_auth_settings`, `invoice_configs`, `konsumen_addresses`, `languages`, `password_reset_tokens`, `payment_methods`, `product_categories`, `provinces`, `regencies`, `roles`, `settings`, `sheets_configs`, `sheets_destinations`, `sheets_sync_logs`, `shipping_configurations`, `shipping_couriers`, `shipping_providers`, `users`, `user_closures`, `user_social_identities`, `villages`, `warehouse_settings`, `warehouse_sub_locations`.

Not one product or transaction table leaked into the guard.

**Independent unmapped-dependency check.** All 47 `child → deleted-parent` FK edges were enumerated from `information_schema`; every child is itself in the delete scope, so nothing preserved is silently cascaded, nulled or orphaned. Two edge shapes worth naming, both safe here:
- `commissions.order_item_id → order_items SET NULL` — `commissions` is itself in the delete scope (0 rows).
- `media` holds 2 rows: `site_logo` and `site_favicon`, both with `mediable_type` NULL and no evidence collection — i.e. **site branding, preserved**. This is precisely the branding asset that `public_html/config.js` and `site_favicon_media_id` depend on; confirmed out of the blast radius.

**Runtime tables are reported, not enforced** (by design): `cache` 38 rows, `sessions` 2, `personal_access_tokens` 0, `jobs` 0, `cache_locks` 0. The command has **no run lock**, so a concurrent writer during `--force` can invalidate the plan — unchanged residual risk.

## Task 6 — dry-run re-executed

`php artisan products:reset` (no `--force`), exit 0, `SAFE TO DELETE — preservation guard covers 37 table(s), no unmapped dependency`. The plan is **byte-identical** to the pre-audit dry run (diff clean).

Dry-run purity proven, not assumed: file fingerprint before/after identical (`f2c478b2…`), constraints 199 → 199, migrations 130 → 130, and a **full 97-table `COUNT(*)`+`CHECKSUM` re-measure shows 0 differences** against the pre-P3 manifest.

---

# CHECKPOINT — DEV FK RESTORATION: P1 + P2 EXECUTED AND VERIFIED (SpaceBunny, 2026-10-08, revision 7)

**Revision 10** (above) is the current state: P4a orphan cleanup executed on DEV. Revision 9 recorded the `products:reset` execution and its independent verification; revision 8 remains the pre-reset audit.

**Revision 7** records the Human-authorized execution of **P2** on DEV (`villages.district_id`) and its post-migration verification. **Revision 6** recorded P1. Revisions 1–5 are the plan/backup/prepared state below; **where they say "NOT EXECUTED", read "P1 and P2 have now been executed and verified; P5 has not."**

## P2 — EXECUTED, VERIFIED PASS

Reported execution: `2026_10_08_111000_restore_villages_district_foreign_key`, DONE in 878.15 ms, `MIGRATE_EXIT=0`, DEV returned live.

| Check | Result |
|---|---|
| Target database | **`primeclassy_dev`** (`.env DB_DATABASE=primeclassy_dev`, `SCHEMA()=primeclassy_dev`, local MariaDB 10.11.10). `primeclassy_testing` and production are **not visible to this account at all** |
| Migration status | `2026_10_08_111000_restore_villages_district_foreign_key` → **batch [15] Ran** |
| P5 status | `2026_10_08_112000_restore_post_cleanup_foreign_keys` → **Pending** |
| Constraints before / after | **198 → 199** (+1, exactly P2) |
| `villages.district_id` constraint | `villages_district_id_foreign` → `districts(id)`, **ON DELETE CASCADE**, **ON UPDATE RESTRICT** — identical to what the migration declares and to the canonical `primeclassy_testing` schema |
| Column legality | `villages.district_id` `varchar(6) NOT NULL` ↔ `districts.id` `varchar(6) NOT NULL` |
| Index reuse | pre-existing `villages_district_id_index` reused; **no index was built**; `villages` has exactly 1 FK |
| Constraints **dropped** (cumulative vs original baseline) | **0** |
| Pre-existing constraints with a changed name or rule | **0** |
| **96 business tables: `COUNT(*)` + `CHECKSUM TABLE` vs `row-manifest.tsv`** | **96 identical, 0 different** |
| `villages` specifically | **83,202 rows, checksum 3428906191 — both identical to the baseline** |
| Only differing table | `migrations` **129 → 130** (P2's own bookkeeping row; 128 baseline → 130 cumulative) |
| Total rows | 92,198 → 92,200 (P1 + P2 bookkeeping) |
| Orphans / protected data untouched | `user_closures` 32, `shipping_configurations` 2, `warehouse_migration_markers` 4, `districts` 7,265, `users` 20, `roles` 11, `products` 2, `orders` 1, `order_items` 2 — all unchanged |
| Still missing vs canonical | **exactly the 5 orphan-blocked** (P5): `shipping_configurations.agent_id`, `user_closures.ancestor_id`, `user_closures.descendant_id`, `warehouse_migration_markers.product_id`, `warehouse_migration_markers.product_variation_id` |
| App state | no maintenance file — DEV is up |

**Verdict: PASS.** P2 changed schema only, restored exactly the one constraint it declares, reused the existing index, rebuilt nothing else, and altered **no data** — the 83,202-row `villages` checksum is byte-identical to the pre-P1 baseline. Cumulative: **92 constraints restored, 0 dropped, 96/96 business tables untouched.**

---

# CHECKPOINT — DEV FK RESTORATION: P1 EXECUTED AND VERIFIED (SpaceBunny, 2026-10-08, revision 6)

**Revision 6** records the Human-authorized execution of **P1** on DEV and its post-migration verification. Revisions 1–5 (plan, backup, prepared-but-not-executed state) are the history below; **where rev 5 says "NOT EXECUTED", read "P1 has now been executed and verified; P2 and P5 have not."**

## P1 — EXECUTED, VERIFIED PASS

| Check | Result |
|---|---|
| Migration status | `2026_10_08_110000_restore_pre_reset_foreign_keys` → **batch [14] Ran** |
| Constraints before / after | **107 → 198** (+91, exactly P1) |
| Tables before / after | 97 / 97, table-name set **identical** — none added or dropped |
| Constraints **dropped** | **0** |
| Pre-existing constraints with a changed name or rule | **0** |
| 91 added constraints | set-equal to the 91 entries the migration declares; referenced table/column and `ON DELETE`/`ON UPDATE` rules **match the canonical `primeclassy_testing` schema exactly** (0 divergences) |
| ON UPDATE / ON DELETE mix of the 91 | RESTRICT/RESTRICT 58 · RESTRICT/SET NULL 27 · RESTRICT/CASCADE 6 |
| **96 business tables: `COUNT(*)` + `CHECKSUM TABLE` vs `row-manifest.tsv`** | **96 identical, 0 different** |
| Only differing table | `migrations` 128 → 129 — the expected migration bookkeeping row |
| Total rows | 92,198 → 92,199 (that one row) |
| Orphans / protected data untouched | `user_closures` 32, `shipping_configurations` 2, `warehouse_migration_markers` 4, `villages` 83,202, `roles` 11, `users` 20, `products` 2, `product_variations` 5, `orders` 1, `order_items` 2 — all unchanged |
| Still missing vs canonical | exactly the 6 deferred: `villages.district_id` (P2) + the 5 orphan-blocked (P5) |

**Verdict: PASS.** P1 changed schema only, restored precisely the 91 constraints it declares, left every pre-existing constraint untouched, and altered **no business data** — verified against the pre-P1 manifest captured at backup time.

Still pending, not run: `2026_10_08_112000_restore_post_cleanup_foreign_keys` (P5).

**EXACT NEXT ACTION:** stop and let the Human confirm this verification. **P5 must not be run yet** — it is the window that only becomes executable after the two authorized single-row deletions. The next step is therefore a *decision plus two authorized deletions*, not a migration:

1. Human confirms this P2 verification.
2. Human authorizes P4a (`DELETE FROM user_closures WHERE id = 11`) and P4b (`DELETE FROM shipping_configurations WHERE id = 1`). Both rows are already captured verbatim in the verified backup (`pre-delete-user_closures-and-shipping_configurations.sql`), and each must be executed as its own single-row statement, capturing the pre-delete `SELECT *` in the same step — **not** as a batch and **not** through any migration.
3. Re-verify immediately: `user_closures` 32 → 31, `shipping_configurations` 2 → 1, and every other business table still identical to `row-manifest.tsv` (the two tables now legitimately differ from the baseline by exactly one row each).
4. Only then `php artisan migrate --force --path=database/migrations/2026_10_08_112000_restore_post_cleanup_foreign_keys.php`, and verify constraints **199 → 204** with 0 further data change.

Note for the ordering that is *not* authorized yet: `products:reset` (P3) also has to run before P5, because it is what clears the four `warehouse_migration_markers` orphans. Its dry run must be re-read now that DEV enforces 92 additional constraints, because the unmapped-dependency gate now sees a different schema than when the plan was written.

---

# CHECKPOINT — DEV FK RESTORATION: PREPARED AND VERIFIED (SpaceBunny, 2026-10-08, revision 5)

Supersedes revision 4 (`PRODUCT-RESET-REMEDIATION-CHECKPOINT.md` at its previous content) for the DEV-schema-drift thread. Rev 4's product-reset decision stays locked and is unchanged; this file records the FK restoration work built on top of it.

**Baseline / HEAD:** `759b58dc6a4fc3b30cc247e804d873bb984875c4`, branch `main`. Working tree: the five new files below, plus the pre-existing modifications from rev 4 (`backend/app/Console/Commands/ResetProducts.php`, `backend/tests/Feature/ProductResetCommandTest.php`, `docs/ARCHITECTURE.md`, `docs/OPERATIONS.md`) and the pre-existing untracked audit artefacts (`SECURITY-AUDIT-20261004.md`, `docs/06-review/`, `docs/CODEX-*`, `docs/SPACEBUNNY-*`, `frontend/dist-prev/`, `frontend/dist-prod-tmp/`) — **none of the pre-existing artefacts touched, staged or deleted**. No commit, no push, no deploy.

**Environment:** DEV `primeclassy_dev` (read-only in this session). Tests ran only against the guarded `primeclassy_testing`. Production never contacted. `.env` untouched. No `ALTER TABLE` against DEV. No migration executed against DEV. No orphan row deleted in DEV or production.

## Objective

Restore the 97 foreign-key constraints DEV's `migrations` table claims are applied but the schema does not carry — prepared, verified and ready to execute on authorization, without touching DEV in the meantime.

## HUMAN DECISIONS (LOCKED, 2026-10-08)

- `warehouse_migration_markers` orphan rows → cleared by `products:reset`, **not** by direct SQL deletion.
- `user_closures` id 11 → deletion only after backup and revalidation.
- `shipping_configurations` id 1 → deletion only after backup and revalidation.
- The six `NO ACTION` vs `RESTRICT` differences → left unchanged.

## What was verified (read-only, re-measured at HEAD)

- DEV 107 constraints vs `primeclassy_testing` 204 → **97 genuinely missing**, 6 rule-only differences, **0** extra on DEV, **0** naming divergences.
- **92** constraints have zero orphans; **5** are blocked by **7** orphan rows: `shipping_configurations.agent_id` (1), `user_closures.ancestor_id` (1), `user_closures.descendant_id` (1), `warehouse_migration_markers.product_id` (1), `warehouse_migration_markers.product_variation_id` (3). Identical to the plan's §1 — the drift has not moved.
- 97/97 leading indexes present, 97/97 child/parent column types identical, 0 NOT-NULL→nullable violations, 97/97 constraint names equal `<table>_<column>_foreign`.
- 0 pending migrations on DEV (128 recorded, 128 on `primeclassy_testing`, identical names).

## Backup — TAKEN, restore-tested, verified

**Exact path: `/home/developer/primeclassy-dev-backups/fk-restoration-20261008-154004/`** — outside the repository, directory mode `700`, every file mode `600`, no database password in any file or on any command line (mode-600 `--defaults-extra-file`).

13 files, 12 of them checksummed in `SHA256SUMS` (which is the 13th): `database.sql` (3,783,776 B, 97 `CREATE TABLE`, ends `-- Dump completed`, no `CREATE DATABASE`/`USE` so it restores into any schema), `schema-only.sql` (122,486 B), `show-create-table-affected.out` (32 tables), `row-manifest.tsv` (**all 97 tables**, `COUNT` + `CHECKSUM` + engine, 92,198 rows), `foreign-keys-before.tsv` (the 107 pre-existing constraints), `affected-tables.txt`, `pre-delete-user_closures-and-shipping_configurations.sql` (`--complete-insert`, the only two tables a row deletion is planned for), `pre-delete-offending-rows.out` (the exact pre-delete rows plus live id lists), `storage-app-public.tar.gz` (33 media files — `products:reset` deletes product images and evidence bytes, which a database dump cannot restore), `backend.env`, `backend.env.testing`, `SHA256SUMS`.

`sha256sum -c SHA256SUMS` → **12/12 OK, 0 FAILED** (re-verified after all verification work).

## Restore test — PASSED

Isolated throwaway MariaDB **10.11** container (same engine version as the server), `127.0.0.1:13306`, tmpfs data directory, scratch schema `primeclassy_restore_scratch`. `primeclassy_dev` was only read; `primeclassy_testing` was not touched.

| Check | Result |
|---|---|
| Load | exit 0, **0 errors / 0 warnings**, 1,875 ms |
| Table count | 97 vs 97, name set **identical** |
| Row counts | **0 mismatches**, 92,198 rows |
| `CHECKSUM TABLE` | **0 mismatches** (45 zeros = correct for empty tables) |
| Constraints / migrations | 107 / 128 — the drifted DEV state reproduced exactly |

The scratch container (`primeclassy-restore-scratch`) is disposable and was removed at the end of the session, so no copy of DEV data is left running. Recreate it with `docker run -d --name primeclassy-restore-scratch -p 127.0.0.1:13306:3306 -e MARIADB_ROOT_PASSWORD=… -e MARIADB_DATABASE=primeclassy_restore_scratch --tmpfs /var/lib/mysql:rw,size=2g mariadb:10.11` to reproduce any of the verification below.

## The scratch clone was then used as a faithful DEV replica

Not a DEV change — a throwaway container that only ever read DEV.

- `php artisan migrate --force` (DB pointed at `primeclassy_restore_scratch`): `110000` **DONE 2s**, `111000` **DONE 716ms**, `112000` **FAIL** — all 5 orphans named, **zero** mutations.
- Row counts + checksums after P1+P2 → **0 differences in 96 business tables**; only `migrations` changed (128 → 130).
- Container-only simulation of the locked cleanup (delete `user_closures` id 11, `shipping_configurations` id 1, the 4 marker rows — **inside the container only**) → `112000` **DONE 151ms**; the clone then carried **204** constraints and its FK inventory diffed against `primeclassy_testing` showed **only the 6 locked `NO ACTION`/`RESTRICT` differences**.
- Enforcement probed on the restored clone: orphan insert → **errno 1452**; delete of a referenced parent → **errno 1451**; district delete → **CASCADE** removed its 7 villages (rolled back); no residue.

## Files added

- `backend/database/migrations/2026_10_08_110000_restore_pre_reset_foreign_keys.php` — P1, **91** constraints.
- `backend/database/migrations/2026_10_08_111000_restore_villages_district_foreign_key.php` — P2, **1** constraint (`villages.district_id`), isolated because it is the only table rebuild.
- `backend/database/migrations/2026_10_08_112000_restore_post_cleanup_foreign_keys.php` — P5, the **5** orphan-blocked constraints.
- `backend/tests/Feature/DevSchemaForeignKeyRestorationTest.php` — 8 read-only tests against the canonical schema.
- `backend/tests/Feature/DevSchemaForeignKeyRestorationDdlTest.php` — 5 destructive DDL tests under `RestoresIsolatedTestDatabase`.

Docs updated: `docs/DEV-SCHEMA-DRIFT-REMEDIATION-PLAN.md` (§4–§7 rewritten to the prepared/verified state, §3 options marked CHOSEN), `docs/OPERATIONS.md` (new **§8.3**, §2 cross-reference, §6 backup/restore-test rule), `docs/ARCHITECTURE.md` (§14 testing architecture).

No model, service, controller or config change. No data migration.

## Commands and exact results

- FK inventory + orphan probe over both databases (read-only SQL) → figures above.
- `mysqldump` full / `--no-data` / `CHECKSUM TABLE` / `SHOW CREATE TABLE` → all exit 0; `sha256sum -c SHA256SUMS` → 13 OK.
- Restore into the isolated container → §"Restore test".
- `php artisan test tests/Feature/DevSchemaForeignKeyRestorationTest.php tests/Feature/DevSchemaForeignKeyRestorationDdlTest.php` → **13 passed / 89 assertions / 0 failed** (80.9 s).
- **Negative controls, each reverted immediately afterwards** (proving the tests discriminate rather than pass vacuously):
  1. flipping `users.role_id` to `cascade` → `…already_exists_on_the_fully_migrated_schema` fails, naming `expected roles.id / CASCADE, canonical schema has roles.id / RESTRICT`;
  2. moving `user_closures.ancestor_id` into P1 → the partition test fails ("Phase A must hold exactly the 91 constraints with zero orphans");
  3. commenting out `assertRestorable()` in P5 → the phase-C test fails with a raw InnoDB **1452 mid-file** instead of a clean refusal — i.e. the preflight is what prevents a partially applied window.
- `php -l` clean on all 5 new files; `./vendor/bin/pint --test` **passed** on all 5; `git diff --check` exit 0.
- Full backend suite **not run** — out of scope for this task, still owed before any commit per AGENTS.md §7.

## Deliberate deviation from plan §7 (for Human review)

Plan §7 specified "`down()` drops exactly the constraints it added". Implemented differently: **`down()` is intentionally empty in all three files.** Reason: in a correctly migrated database these constraints belong to the create-table migrations, so rolling this file back must not drop 91 protections while those migrations stay recorded as applied. `migrate:rollback` therefore only un-records the file; `up()` stays idempotent, so re-running is still correct. This also keeps `MigrationVerificationTest`'s `migrate:rollback --step 14` working — a throwing `down()` would have broken it. The inverse for DEV is the explicit `ALTER TABLE … DROP FOREIGN KEY` list, documented in the plan §5 and `OPERATIONS.md` §8.3. Consequence stated plainly: **git-level rollback of these files is not a schema rollback.**

## Residual risks

- **Production state is unknown.** These migrations run automatically under `php artisan migrate` wherever they are deployed. If production is also drifted, `112000` will fail closed there too — which is safe, but a production deploy of this branch must not happen without its own inspection, backup, measured timing and authorization.
- Measured lock times come from a same-engine scratch clone of DEV. They are an indication, **not** a production figure; production timing must be measured on its own clone.
- The fail-closed preflight makes the migrations strict: any future drift (a renamed column, a dropped index, a new orphan) makes a whole window refuse rather than partially apply. That is the intended trade, but it means a window can be blocked by an unrelated pre-existing problem.
- `products:reset` (P3) is the destructive step this whole sequence protects, and it has not been re-verified against the FK-restored schema. After P1/P2 run on DEV, the reset dry-run verdict must be read again, and the unmapped-dependency gate will now see 91 more constraints than before.
- The P4a/P4b row deletions still have no authorization. Their dedicated dump exists, but the deletion step itself has not been designed, rehearsed or approved beyond the locked decision.
- `warehouse_settings.agent_id` is covered by a UNIQUE index rather than the usual `<table>_<column>_foreign` index — harmless (the constraint reuses it) but the shape that motivated the preflight's index check and its regression test.
- Schema introspection is MySQL/MariaDB-only by design; on another driver all three files are a no-op (they never assume a constraint is absent).

## Production state

Production not contacted, not read, not written, not inspected. Production's FK state is therefore **unknown** — that is an open question for the Human, not a finding.

## Ready / not ready

**Ready:** the backup and its restore test; the three migrations and their 13 focused tests; the per-phase locking, failure-recovery and rollback documentation; the phase split and its regression test. **P1 is now executed and verified — see revision 6 at the top.**

**Not ready (blocked on Human authorization):** running P2 or P5 against DEV; `products:reset --force`; the P4a/P4b deletions; any commit, push or deployment.

**Revision 5's EXACT NEXT ACTION (superseded by revision 6 — kept for traceability):** Human reviews this checkpoint, `docs/DEV-SCHEMA-DRIFT-REMEDIATION-PLAN.md` §4–§7 and `docs/OPERATIONS.md` §8.3, then authorizes **P1 alone**:

```bash
cd /www/dev/primeclassy/backend
php artisan migrate --force --path=database/migrations/2026_10_08_110000_restore_pre_reset_foreign_keys.php
```

then P6-partial verification: re-run the §1 FK inventory diff against `/home/developer/primeclassy-dev-backups/fk-restoration-20261008-154004/foreign-keys-before.tsv` and `row-manifest.tsv` and confirm **107 → 198** constraints with 0 row-count and 0 checksum differences in the 96 business tables. Do **not** run P2 in the same window (it is the only table rebuild — a quiet window), and do not touch P3/P4a/P4b/P5 before that verification is recorded.
---

# CARRIED FORWARD FROM REVISION 4 (product reset — unchanged, still LOCKED)

Retained verbatim in substance because the decision below is the reason the FK restoration exists. The FK work above **does not alter it**; the only new fact is that P1/P2 now make the reset run under real FK protection.

## HUMAN DECISION (LOCKED)

`products:reset` deletes ALL products **together with every relation that references them**, including warehouse transactions, stock movements, stock requests and the related operational history. This **replaces** the earlier policy in which warehouse history was a permanent blocker. Master data and configuration outside the product domain are preserved.

## Deletion boundary (one rule: a row goes iff it cannot exist without the catalog)

- **Product-derived rows, whole table:** `order_items` (`product_id` NOT NULL), `sub_stock_reservations`, `stock_request_items`, `stock_request_proposal_items`, `stock_request_proposals`, `stock_request_fulfillments`, `return_items`, `order_item_adjustments`, `order_fulfillment_change_proposals`, `sub_stock_request_items`, `stock_handovers`, `stock_transfer_items`, `stock_opname_items`, `warehouse_stock_requests`, `stock_movements`, `warehouse_migration_markers`, `payment_webhook_logs` (row-scoped — see below).
- **Containers, whole table:** `stock_requests`, `stock_transfers`, `sub_stock_requests`, `stock_opnames`, `warehouse_migration_runs`.
- **Order aggregate, ROW-scoped:** `orders` only when it HAD order items; `shipments`, `payment_transactions`, `order_additional_payments`, `commissions`, `returns`, `inventory_cancellation_reversals` follow that exact order set; `delivery_verifications` follow its shipments; `cod_payment_proofs` / `bank_transfer_verifications` follow its payments.
- **Targeted promotions, row-scoped:** product/variation-targeted `vouchers` + `product_discounts` go; whole-catalog ones stay.
- **SKU registry, row-scoped:** `catalog_skus.owner_type IN ('product','variant')`.
- **Audit + evidence, row-scoped:** `activity_logs` whose `subject_type` is a deleted model; media owned by Product/ProductVariation; media in `bank_transfer_proof` / `cod_payment_proof` / `shipment_proof` / `return_evidence`; legacy `bank_transfer_verifications.proof_image_path` + `returns.evidence_path`.
- **Catalog:** variations' compositions/options/attributes (+ translations), `products_translations`, `product_attributes`, `product_images`, `product_fees`, `product_variation_fees`, `product_stocks`, `product_variation_stocks`, product-targeted `warehouse_stocks`, `product_variations`, `products` (soft-deleted included — plain `DELETE` ignores `deleted_at`).

**`payment_webhook_logs` is row-scoped** (revision 3): `handleWebhook()` (PaymentService) resolves a transaction through `(payment_method_id, gateway_reference)` — the table's only link. Whole-table deletion would have destroyed `unknown_reference` / spoofed-delivery audit rows that belong to no order. The surviving count is compared before/after.

## Preconditions (run is refused, not attempted)

1. `--force` without a non-empty `--backup-verified=<reference>` → refused.
2. **Unmapped dependency**, three live-schema checks → refused: (a) a preserved table with an FK into a table the command empties completely; (b) a preserved row whose FK points into a partial delete scope; (c) any table with a `product_id` / `product_variation_id` target column outside the delete scope.
3. Preservation guard must be derivable from the schema (non-zero) — derived from the live schema, so a table added by a later migration is protected without a code change.

## Files modified in revision 4

- `backend/app/Console/Commands/ResetProducts.php` (unified ordered delete steps; one `scopeQuery()` serving both the dry-run count and the executed DELETE so plan and action can never diverge).
- `backend/tests/Feature/ProductResetCommandTest.php` (15 tests).
- `docs/OPERATIONS.md` (§8 command table + §8.2), `docs/ARCHITECTURE.md` (console row).

## Commands and exact results (revision 4)

- `php artisan products:reset` (DEV, read-only) → `SAFE TO DELETE — preservation guard covers 37 table(s), no unmapped dependency`, `DRY RUN complete`.
- Same command with `APP_ENV=testing DB_DATABASE=primeclassy_testing` → same verdict; proves the gate works against the **fully migrated** schema.
- `php artisan test tests/Feature/ProductResetCommandTest.php` → **15 passed / 161 assertions / 0 failed**.
- Focused suite (10 files incl. `ProductResetCommandTest`, `TransactionResetCommandTest`, warehouse/stock suites) → **85 passed / 832 assertions / 0 failed**.
- `php -l` clean; `./vendor/bin/pint --test` **passed** on both PHP files; `git diff --check` exit 0.
- Full backend suite **not run** (out of scope then; still owed before any commit per AGENTS.md §7).

## Product-reset residual risks (unchanged)

- `payment_webhook_logs` correlation relies on `(payment_method_id, gateway_reference)` staying the gateway's resolution key.
- A future child table added without a foreign key and without a product target column cannot be detected generically; the reviewer must map it.
- No run lock: a concurrent writer during `--force` can invalidate the plan (fail-closed reporting, no serialization).
- Schema introspection is MySQL-only by design; on another driver the gate degrades to nothing.

**Revision 4's EXACT NEXT ACTION** (now superseded by revision 5, kept for traceability): authorize the DEV `products:reset --force` run after a verified backup. The correct order is now `P1 → P2 → products:reset` — see `docs/OPERATIONS.md` §8.3.
