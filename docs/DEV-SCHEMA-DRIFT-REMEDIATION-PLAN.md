# DEV SCHEMA DRIFT — FOREIGN KEY RESTORATION (SpaceBunny, 2026-10-08)

> **SUPERSEDED STATUS (original plan, 2026-10-08):** P1/P2 complete; P5, P3 and P4 pending. Retained as history only; the following current-state statement supersedes it.

**CURRENT DEV STATUS (2026-10-08): P1, P2, P3, P4a, P4b and P5 complete and verified.** DEV has 204/204 FKs. P4a and P4b followed their respective Human decisions; the product reset and P5 completed. The 220 unrelated historical orphan references and 26 orphan product image files remain untouched. No production inspection, migration, cleanup, deployment or authorization occurred; **production remains HOLD**. `primeclassy_testing` is used only through its guarded tests.

**Scope:** restore the **97** foreign-key constraints that `primeclassy_dev` is missing relative to the schema the migrations declare (reference: `primeclassy_testing`). All DEV data must survive.

**Execution history (2026-10-08):** P1 107→198 and P2 198→199 were schema-only and verified. Subsequently P3/P4a/P4b resolved the five P5 blockers according to locked decisions; P5 restored 199→204. Six NO ACTION/RESTRICT pairs remain accepted. See the latest checkpoint revisions 12–13 for P5 and orphan evidence. These DEV results are not production preflight or authorization.

**Baseline:** HEAD `759b58dc6a4fc3b30cc247e804d873bb984875c4`, branch `main`, DB `primeclassy_dev`. Audit input: `docs/PRODUCT-RESET-REMEDIATION-CHECKPOINT.md` rev 3. Progress and exact results: `docs/PRODUCT-RESET-REMEDIATION-CHECKPOINT.md` rev 5.

---

## 0. Correction to the previous count

The earlier report said "103 missing foreign keys". That number came from a plain text diff of `SHOW`-style lines and was **inflated by 6**: `inventory_cancellation_reversals.{agent_id,order_id,order_item_id,processed_by}`, `sheets_configs.destination_id` and `sheets_destinations.agent_id` exist on both databases but are declared `NO ACTION` on DEV instead of `RESTRICT`. For InnoDB deletes `NO ACTION` and `RESTRICT` behave identically, so these need no action.

**Corrected figures** (re-measured 2026-10-08 at HEAD, unchanged)

| Metric | Value |
|---|---|
| Foreign keys on `primeclassy_testing` (fully migrated) | 204 |
| Foreign keys on `primeclassy_dev` | 107 |
| **Genuinely missing on DEV** | **97** |
| Present on both, declared `NO ACTION` instead of `RESTRICT` | 6 (no functional difference; see §6) |
| Migrations recorded in both `migrations` tables | 128 — identical name sets, only `batch` numbers differ |
| **Migrations pending** | **0** |

This is **schema drift**, not a pending migration: DEV's `migrations` table is complete but the constraints the migrations declare are absent. `migrations` must not be used as evidence of DEV schema state — which is exactly why the restoration migrations decide what to do by reading `information_schema`.

---

## 1. The 97 missing foreign keys

`ON UPDATE RESTRICT` for all 97 — Laravel emits no `ON UPDATE` clause anywhere in this repository (`grep -c onUpdate database/migrations` = 0), so every constraint inherits the MySQL default.

| # | Child table | Column | → Referenced | ON DELETE | ON UPDATE | Rows | NULL | Orphans | Leading index |
|---|---|---|---|---|---|---|---|---|---|
| 1 | `shipping_configurations` | `agent_id` | `users.id` | CASCADE | RESTRICT | 2 | 0 | 1 | `shipping_configurations_agent_id_unique` |
| 2 | `shipping_configurations` | `shipping_provider_id` | `shipping_providers.id` | SET NULL | RESTRICT | 2 | 0 | 0 | `shipping_configurations_shipping_provider_id_foreign` |
| 3 | `stock_handovers` | `agent_id` | `users.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `stock_handovers_agent_id_status_index` |
| 4 | `stock_handovers` | `handed_over_by` | `users.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `stock_handovers_handed_over_by_foreign` |
| 5 | `stock_handovers` | `received_by` | `users.id` | SET NULL | RESTRICT | 0 | 0 | 0 | `stock_handovers_received_by_foreign` |
| 6 | `stock_handovers` | `stock_transfer_id` | `stock_transfers.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `stock_handovers_stock_transfer_id_unique` |
| 7 | `stock_movements` | `agent_id` | `users.id` | RESTRICT | RESTRICT | 4 | 0 | 0 | `stock_movements_agent_id_product_id_index, stock_movements_agent_id_product_variation_id_index, stock_movements_agent_type_target, stock_movements_sub_location` |
| 8 | `stock_movements` | `created_by` | `users.id` | SET NULL | RESTRICT | 4 | 0 | 0 | `stock_movements_created_by_foreign` |
| 9 | `stock_movements` | `opname_id` | `stock_opnames.id` | RESTRICT | RESTRICT | 4 | 4 | 0 | `stock_movements_opname_id_index` |
| 10 | `stock_movements` | `product_id` | `products.id` | RESTRICT | RESTRICT | 4 | 4 | 0 | `stock_movements_product_id_foreign` |
| 11 | `stock_movements` | `product_variation_id` | `product_variations.id` | RESTRICT | RESTRICT | 4 | 0 | 0 | `stock_movements_product_variation_id_foreign` |
| 12 | `stock_movements` | `sub_location_id` | `warehouse_sub_locations.id` | RESTRICT | RESTRICT | 4 | 4 | 0 | `stock_movements_sub_location_id_foreign` |
| 13 | `stock_movements` | `warehouse_stock_request_id` | `warehouse_stock_requests.id` | SET NULL | RESTRICT | 4 | 2 | 0 | `stock_movements_warehouse_stock_request_id_foreign` |
| 14 | `stock_opname_items` | `product_id` | `products.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `stock_opname_items_product_id_foreign` |
| 15 | `stock_opname_items` | `product_variation_id` | `product_variations.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `stock_opname_items_product_variation_id_foreign` |
| 16 | `stock_opname_items` | `stock_opname_id` | `stock_opnames.id` | CASCADE | RESTRICT | 0 | 0 | 0 | `stock_opname_items_stock_opname_id_index` |
| 17 | `stock_opname_items` | `sub_location_id` | `warehouse_sub_locations.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `stock_opname_items_sub_location_id_foreign` |
| 18 | `stock_opnames` | `agent_id` | `users.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `stock_opnames_agent_id_status_opname_type_index` |
| 19 | `stock_opnames` | `approved_by` | `users.id` | SET NULL | RESTRICT | 0 | 0 | 0 | `stock_opnames_approved_by_foreign` |
| 20 | `stock_opnames` | `created_by` | `users.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `stock_opnames_created_by_foreign` |
| 21 | `stock_opnames` | `rejected_by` | `users.id` | SET NULL | RESTRICT | 0 | 0 | 0 | `stock_opnames_rejected_by_foreign` |
| 22 | `stock_opnames` | `sub_location_id` | `warehouse_sub_locations.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `stock_opnames_sub_location_id_foreign` |
| 23 | `stock_opnames` | `submitted_by` | `users.id` | SET NULL | RESTRICT | 0 | 0 | 0 | `stock_opnames_submitted_by_foreign` |
| 24 | `stock_request_fulfillments` | `fulfilled_by` | `users.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `stock_request_fulfillments_fulfilled_by_foreign` |
| 25 | `stock_request_fulfillments` | `stock_request_id` | `stock_requests.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `sr_fulfillment_idempotency_unique` |
| 26 | `stock_request_items` | `order_item_id` | `order_items.id` | RESTRICT | RESTRICT | 2 | 0 | 0 | `stock_request_items_order_item_id_unique` |
| 27 | `stock_request_items` | `product_id` | `products.id` | RESTRICT | RESTRICT | 2 | 0 | 0 | `stock_request_items_product_id_foreign` |
| 28 | `stock_request_items` | `product_variation_id` | `product_variations.id` | RESTRICT | RESTRICT | 2 | 0 | 0 | `stock_request_items_product_variation_id_foreign` |
| 29 | `stock_request_items` | `stock_request_id` | `stock_requests.id` | CASCADE | RESTRICT | 2 | 0 | 0 | `stock_request_items_stock_request_id_index` |
| 30 | `stock_request_proposal_items` | `stock_request_item_id` | `stock_request_items.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `stock_request_proposal_items_stock_request_item_id_foreign` |
| 31 | `stock_request_proposal_items` | `stock_request_proposal_id` | `stock_request_proposals.id` | CASCADE | RESTRICT | 0 | 0 | 0 | `srpi_proposal_decision_idx, stock_request_proposal_items_stock_request_proposal_id_index` |
| 32 | `stock_request_proposals` | `agent_id` | `users.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `stock_request_proposals_agent_id_status_index` |
| 33 | `stock_request_proposals` | `approved_by` | `users.id` | SET NULL | RESTRICT | 0 | 0 | 0 | `stock_request_proposals_approved_by_foreign` |
| 34 | `stock_request_proposals` | `rejected_by` | `users.id` | SET NULL | RESTRICT | 0 | 0 | 0 | `stock_request_proposals_rejected_by_foreign` |
| 35 | `stock_request_proposals` | `requested_by` | `users.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `stock_request_proposals_requested_by_foreign` |
| 36 | `stock_request_proposals` | `stock_request_id` | `stock_requests.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `stock_request_proposals_stock_request_id_index` |
| 37 | `stock_requests` | `agent_id` | `users.id` | RESTRICT | RESTRICT | 1 | 0 | 0 | `stock_requests_agent_id_status_index` |
| 38 | `stock_requests` | `created_by` | `users.id` | SET NULL | RESTRICT | 1 | 1 | 0 | `stock_requests_created_by_foreign` |
| 39 | `stock_requests` | `order_id` | `orders.id` | RESTRICT | RESTRICT | 1 | 0 | 0 | `stock_requests_order_id_unique` |
| 40 | `stock_transfer_items` | `product_id` | `products.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `stock_transfer_items_product_id_product_variation_id_index` |
| 41 | `stock_transfer_items` | `product_variation_id` | `product_variations.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `stock_transfer_items_product_variation_id_foreign` |
| 42 | `stock_transfer_items` | `stock_transfer_id` | `stock_transfers.id` | CASCADE | RESTRICT | 0 | 0 | 0 | `stock_transfer_items_stock_transfer_id_index` |
| 43 | `stock_transfers` | `agent_id` | `users.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `stock_transfers_agent_id_status_index, stock_transfers_sub_locations` |
| 44 | `stock_transfers` | `completed_by` | `users.id` | SET NULL | RESTRICT | 0 | 0 | 0 | `stock_transfers_completed_by_foreign` |
| 45 | `stock_transfers` | `created_by` | `users.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `stock_transfers_created_by_foreign` |
| 46 | `stock_transfers` | `destination_sub_location_id` | `warehouse_sub_locations.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `stock_transfers_destination_sub_location_id_foreign` |
| 47 | `stock_transfers` | `rejected_by` | `users.id` | SET NULL | RESTRICT | 0 | 0 | 0 | `stock_transfers_rejected_by_foreign` |
| 48 | `stock_transfers` | `source_sub_location_id` | `warehouse_sub_locations.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `stock_transfers_source_sub_location_id_foreign` |
| 49 | `sub_stock_request_items` | `product_id` | `products.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `sub_stock_request_items_product_id_foreign` |
| 50 | `sub_stock_request_items` | `product_variation_id` | `product_variations.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `sub_stock_request_items_product_variation_id_foreign` |
| 51 | `sub_stock_request_items` | `sub_stock_request_id` | `sub_stock_requests.id` | CASCADE | RESTRICT | 0 | 0 | 0 | `sub_stock_request_items_sub_stock_request_id_foreign` |
| 52 | `sub_stock_requests` | `agent_id` | `users.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `sub_stock_requests_agent_id_status_index` |
| 53 | `sub_stock_requests` | `approved_by` | `users.id` | SET NULL | RESTRICT | 0 | 0 | 0 | `sub_stock_requests_approved_by_foreign` |
| 54 | `sub_stock_requests` | `executed_by` | `users.id` | SET NULL | RESTRICT | 0 | 0 | 0 | `sub_stock_requests_executed_by_foreign` |
| 55 | `sub_stock_requests` | `received_by` | `users.id` | SET NULL | RESTRICT | 0 | 0 | 0 | `sub_stock_requests_received_by_foreign` |
| 56 | `sub_stock_requests` | `rejected_by` | `users.id` | SET NULL | RESTRICT | 0 | 0 | 0 | `sub_stock_requests_rejected_by_foreign` |
| 57 | `sub_stock_requests` | `requested_by` | `users.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `sub_stock_requests_idempotency_unique` |
| 58 | `sub_stock_requests` | `stock_transfer_id` | `stock_transfers.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `sub_stock_requests_stock_transfer_id_unique` |
| 59 | `sub_stock_requests` | `sub_location_id` | `warehouse_sub_locations.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `sub_stock_requests_sub_location_id_status_index` |
| 60 | `sub_stock_reservations` | `agent_id` | `users.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `sub_stock_reservations_agent_id_status_index` |
| 61 | `sub_stock_reservations` | `consumed_by` | `users.id` | SET NULL | RESTRICT | 0 | 0 | 0 | `sub_stock_reservations_consumed_by_foreign` |
| 62 | `sub_stock_reservations` | `order_item_id` | `order_items.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `sub_stock_reservations_order_item_id_unique` |
| 63 | `sub_stock_reservations` | `product_id` | `products.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `sub_stock_reservations_product_id_foreign` |
| 64 | `sub_stock_reservations` | `product_variation_id` | `product_variations.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `sub_stock_reservations_product_variation_id_foreign` |
| 65 | `sub_stock_reservations` | `released_by` | `users.id` | SET NULL | RESTRICT | 0 | 0 | 0 | `sub_stock_reservations_released_by_foreign` |
| 66 | `sub_stock_reservations` | `reserved_by` | `users.id` | SET NULL | RESTRICT | 0 | 0 | 0 | `sub_stock_reservations_reserved_by_foreign` |
| 67 | `sub_stock_reservations` | `sub_location_id` | `warehouse_sub_locations.id` | RESTRICT | RESTRICT | 0 | 0 | 0 | `sub_stock_reservations_target_status` |
| 68 | `user_closures` | `ancestor_id` | `users.id` | CASCADE | RESTRICT | 32 | 0 | 1 | `user_closures_ancestor_id_depth_index, user_closures_ancestor_id_descendant_id_unique` |
| 69 | `user_closures` | `descendant_id` | `users.id` | CASCADE | RESTRICT | 32 | 0 | 1 | `user_closures_descendant_id_index` |
| 70 | `users` | `agent_id` | `users.id` | SET NULL | RESTRICT | 20 | 1 | 0 | `users_agent_id_foreign` |
| 71 | `users` | `avatar_media_id` | `media.id` | SET NULL | RESTRICT | 20 | 20 | 0 | `users_avatar_media_id_foreign` |
| 72 | `users` | `korsal_id` | `users.id` | SET NULL | RESTRICT | 20 | 13 | 0 | `users_korsal_id_foreign` |
| 73 | `users` | `parent_id` | `users.id` | SET NULL | RESTRICT | 20 | 2 | 0 | `users_parent_id_foreign` |
| 74 | `users` | `role_id` | `roles.id` | RESTRICT | RESTRICT | 20 | 0 | 0 | `users_role_id_foreign` |
| 75 | `users` | `sales_id` | `users.id` | SET NULL | RESTRICT | 20 | 16 | 0 | `users_sales_id_foreign` |
| 76 | `villages` | `district_id` | `districts.id` | CASCADE | RESTRICT | 83202 | 0 | 0 | `villages_district_id_index` |
| 77 | `warehouse_migration_markers` | `agent_id` | `users.id` | RESTRICT | RESTRICT | 4 | 0 | 0 | `warehouse_migration_markers_agent_id_status_index` |
| 78 | `warehouse_migration_markers` | `product_id` | `products.id` | RESTRICT | RESTRICT | 4 | 3 | 1 | `warehouse_migration_markers_product_id_foreign` |
| 79 | `warehouse_migration_markers` | `product_variation_id` | `product_variations.id` | RESTRICT | RESTRICT | 4 | 1 | 3 | `warehouse_migration_markers_product_variation_id_foreign` |
| 80 | `warehouse_migration_markers` | `run_id` | `warehouse_migration_runs.id` | CASCADE | RESTRICT | 4 | 0 | 0 | `warehouse_migration_markers_run_id_foreign` |
| 81 | `warehouse_migration_runs` | `started_by` | `users.id` | SET NULL | RESTRICT | 1 | 1 | 0 | `warehouse_migration_runs_started_by_foreign` |
| 82 | `warehouse_settings` | `agent_id` | `users.id` | RESTRICT | RESTRICT | 1 | 0 | 0 | `warehouse_settings_agent_id_unique` |
| 83 | `warehouse_stock_requests` | `agent_id` | `users.id` | RESTRICT | RESTRICT | 2 | 0 | 0 | `wsr_agent_status, wsr_agent_type_target` |
| 84 | `warehouse_stock_requests` | `approved_by` | `users.id` | SET NULL | RESTRICT | 2 | 0 | 0 | `warehouse_stock_requests_approved_by_foreign` |
| 85 | `warehouse_stock_requests` | `product_id` | `products.id` | RESTRICT | RESTRICT | 2 | 2 | 0 | `warehouse_stock_requests_product_id_foreign` |
| 86 | `warehouse_stock_requests` | `product_variation_id` | `product_variations.id` | RESTRICT | RESTRICT | 2 | 0 | 0 | `warehouse_stock_requests_product_variation_id_foreign` |
| 87 | `warehouse_stock_requests` | `rejected_by` | `users.id` | SET NULL | RESTRICT | 2 | 2 | 0 | `warehouse_stock_requests_rejected_by_foreign` |
| 88 | `warehouse_stock_requests` | `requested_by` | `users.id` | RESTRICT | RESTRICT | 2 | 0 | 0 | `warehouse_stock_requests_requested_by_foreign` |
| 89 | `warehouse_stock_requests` | `sub_location_id` | `warehouse_sub_locations.id` | RESTRICT | RESTRICT | 2 | 2 | 0 | `warehouse_stock_requests_sub_location_id_foreign` |
| 90 | `warehouse_stocks` | `agent_id` | `users.id` | RESTRICT | RESTRICT | 2 | 0 | 0 | `warehouse_stocks_agent_type_target, warehouse_stocks_target_location_unique, warehouse_stocks_unique_target, warehouse_sub_location_target` |
| 91 | `warehouse_stocks` | `product_id` | `products.id` | RESTRICT | RESTRICT | 2 | 2 | 0 | `warehouse_stocks_product_id_foreign` |
| 92 | `warehouse_stocks` | `product_variation_id` | `product_variations.id` | RESTRICT | RESTRICT | 2 | 0 | 0 | `warehouse_stocks_product_variation_id_foreign` |
| 93 | `warehouse_stocks` | `sub_location_id` | `warehouse_sub_locations.id` | RESTRICT | RESTRICT | 2 | 2 | 0 | `warehouse_stocks_sub_location_id_foreign` |
| 94 | `warehouse_sub_locations` | `agent_id` | `users.id` | RESTRICT | RESTRICT | 1 | 0 | 0 | `warehouse_sub_locations_agent_id_code_unique, warehouse_sub_locations_agent_id_is_active_index` |
| 95 | `warehouse_sub_locations` | `created_by` | `users.id` | RESTRICT | RESTRICT | 1 | 0 | 0 | `warehouse_sub_locations_created_by_foreign` |
| 96 | `warehouse_sub_locations` | `owner_user_id` | `users.id` | RESTRICT | RESTRICT | 1 | 0 | 0 | `warehouse_sub_locations_owner_unique` |
| 97 | `warehouse_sub_locations` | `previous_owner_user_id` | `users.id` | RESTRICT | RESTRICT | 1 | 1 | 0 | `warehouse_sub_locations_previous_owner_user_id_foreign` |

**Totals:** 24 tables · 92 constraints with zero orphans · 5 constraints blocked by orphans in 3 tables.

---

## 2. Compatibility pre-check (read-only, executed, re-confirmed 2026-10-08)

| Check | Result |
|---|---|
| Referenced table + `id` column exists on DEV | 97 / 97 OK |
| Child column type vs parent column type | **0 mismatches** (all `bigint unsigned` ↔ `bigint unsigned`) |
| Child `NOT NULL` → parent `NULL` (illegal) | **0** |
| Character set / collation conflicts | 0 (no string columns in scope) |
| Leading index on the child column | **97 / 97 already present** — no index has to be built |
| Constraint name equals `<table>_<column>_foreign` | **97 / 97** — identical to the name the create-table migrations produced |
| Rows in all 24 affected tables | 83,274 (`villages` = 83,202 of them) |
| Data + index size of the 24 tables | 10.34 MB (`villages` = 8.2 MB, every other table ≤ 224 KB) |

The pre-existing indexes are exactly Laravel's FK naming (`<table>_<column>_foreign`), i.e. the index that was created by `foreignId(...)` while the matching `->constrained(...)` constraint is missing. `ADD CONSTRAINT <table>_<column>_foreign` reuses that index, so **no duplicate-key-name error (1061) and no index build** — the drift is "the constraint half of `constrained()` never landed".

One index name is *not* of that shape: `warehouse_settings.agent_id` is covered by the UNIQUE index `warehouse_settings_agent_id_unique`. It is still a leading index on the column, so the constraint reuses it — but it is the case the preflight's index check exists for, and it is covered by a dedicated regression test.

---

## 3. Data integrity findings — 3 orphan conflicts, 5 blocked constraints

No orphan was deleted. **HUMAN DECISION (LOCKED 2026-10-08):** the `warehouse_migration_markers` orphans are cleared by `products:reset` (no bespoke SQL deletion); `user_closures` id 11 and `shipping_configurations` id 1 are deleted only after a backup and revalidation; the six `NO ACTION`/`RESTRICT` differences stay untouched.

### 3.1 `warehouse_migration_markers` — 4 blocked constraints, 4 orphan rows (1 table)

| id | run_id | source_type | source_id | product_id | product_variation_id | status |
|---|---|---|---|---|---|---|
| 1 | 1 | `product_stock` | 4 | **11 (missing)** | — | migrated |
| 2 | 1 | `product_variation_stock` | 26 | — | **32 (missing)** | migrated |
| 3 | 1 | `product_variation_stock` | 27 | — | **33 (missing)** | migrated |
| 4 | 1 | `product_variation_stock` | 28 | — | **34 (missing)** | migrated |

Live catalog: `products.id` 21–22, `product_variations.id` 39–43. Run 1 (`legacy-20260919081527-snhuuv`, completed, 4 rows migrated, qty 130) is a completed legacy-stock backfill whose source products were deleted long before this audit.

**Options**
- **(a) CHOSEN — let `products:reset` remove them.** All four marker rows are already inside the delete scope of `products:reset` (locked decision: warehouse migration markers are product history). Running the reset clears all four orphans with no bespoke data surgery, and it must run *before* this phase anyway.
- **(b) Keep the markers** → the two product FKs on this table **cannot be restored**; the table stays permanently unprotected. Not acceptable as a silent outcome, so this option implies accepting 92 of 97 constraints.
- **(c) Recreate products 11 / variations 32–34** → **forbidden.** Re-creating catalogue identities that were deliberately deleted would resurrect phantom SKUs (`catalog_skus` has no rows for them) and silently remap historical inventory. Explicitly rejected by AGENTS.md §5/§9.

### 3.2 `user_closures` — 2 blocked constraints, 1 orphan row (hierarchy integrity)

| id | ancestor_id | descendant_id | depth |
|---|---|---|---|
| 11 | **2 (missing)** | **7 (missing)** | 1 |

Live users: `1, 11, 17…34`. Row 11 is a closure edge between two users that no longer exist; every other closure row is consistent. This is a genuine hierarchy defect: the closure drives authorization scope, so a dangling edge is both an integrity violation and a latent authorization hazard.

**Options**
- **(a) CHOSEN — delete closure row 11 only.** Its two endpoints do not exist, so it carries no reachable meaning; it is the sole reason `user_closures.ancestor_id` and `.descendant_id` cannot be restored. 1 row of 32 is removed, all other hierarchy rows are byte-identical.
- **(b) Re-create users 2 and 7** → **forbidden.** Would invent identities and re-open access scope; also violates "never silently remap historical identities" (AGENTS.md §9).
- **(c) Leave it** → 2 constraints stay unrestored and the closure keeps an invalid edge.
- Requires its own dedicated backup before execution, because this is the only phase that deletes a row.

  **Prepared, not executed.** `pre-delete-user_closures-and-shipping_configurations.sql` (a `--complete-insert` dump of both tables, i.e. restorable verbatim) and `pre-delete-offending-rows.out` (the exact pre-delete rows) are already in the verified backup directory (§5). The deletion itself still needs its own authorization step.

### 3.3 `shipping_configurations` — 1 blocked constraint, 1 orphan row

| id | agent_id | shipping_provider_id | price_per_km | minimum_distance_km | is_active |
|---|---|---|---|---|---|
| 1 | **2 (missing)** | 2 | 1000.00 | 20.00 | **1 (active)** |
| 2 | 11 | 2 | 1000.00 | 30.00 | 1 |

An **active** shipping rate card owned by a non-existent agent. Unreachable by any order (no agent 2), but it breaks `shipping_configurations.agent_id → users.id ON DELETE CASCADE`.

**Options**
- **(a) CHOSEN — delete row 1.** The owning agent is gone, so the rate card is dead configuration. Preserves row 2 (the live agent-11 configuration) untouched.
- **(b) Deactivate instead of delete** (`is_active = 0`) — keeps the historical price card for audit but leaves the FK unrestored, because the row still points at user 2.
- **(c) Reassign to agent 11** → **forbidden without explicit Human approval.** It would silently move a financial configuration between owners (AGENTS.md §5/§9).
- **(d) Leave it** → 1 constraint stays unrestored.

  **Prepared, not executed.** Covered by the same dedicated pre-delete dump as §3.2.

---

## 4. Remediation phases and their migrations

Ordering rationale: phases that need no data change go first, so the destructive catalogue wipe (`products:reset`) happens on an FK-protected database; orphan-driven work goes last, because one of the three conflicts is resolved by the wipe itself.

| Phase | Migration | Content | Tables | Data change | Blocking |
|---|---|---|---|---|---|
| **P0** | — | Pre-flight: backup + restore test + schema/row manifest (§5) | — | none | Human authorization |
| **P1** | `2026_10_08_110000_restore_pre_reset_foreign_keys` | **91** constraints with zero orphans (all except `villages` and the 5 orphan-blocked ones) | 21 | **none** | backup verified |
| **P2** | `2026_10_08_111000_restore_villages_district_foreign_key` | `villages.district_id → districts.id ON DELETE CASCADE` — isolated because it is the only table rebuild (83,202 rows / 8.2 MB) | 1 | none | quiet window |
| **P3** | — | `products:reset` — wipes the catalogue and its derived history; clears the 4 marker orphans | many | yes (locked Human decision) | ✅ completed on DEV |
| **P4a** | — | remove orphan `user_closures` id 11 | 1 | 1 delete | ✅ completed on DEV after backup/revalidation |
| **P4b** | — | preserve `shipping_configurations` id 1, set `agent_id=NULL`, `is_active=0` | 1 | 1 update | ✅ completed on DEV by Human's revised decision; historical delete choice superseded |
| **P5** | `2026_10_08_112000_restore_post_cleanup_foreign_keys` | the **5** orphan-blocked constraints | 3 | none | ✅ completed on DEV; 199→204 |
| **P6** | — | Verification: FK inventory diff vs `primeclassy_testing` = 0 unaccounted, row counts + checksums unchanged | — | none | — |

`villages` is in its own file and the 5 orphan-blocked constraints are in a third file because the plan has three distinct *execution windows* (negligible lock, one table rebuild, after the destructive cleanup). One file per window means each window can be run, verified and rolled back on its own with `--path=`, and a refusal in a late window can never leave an early window half-applied.

### Why the phase split is a correctness property, not just tidiness

P1 must be runnable *before* `products:reset`, so it must contain **zero** constraints whose rows block them. P5 must not be runnable before the cleanup, so it must contain **only** those. The split is asserted by a regression test (`test_the_three_phases_partition_the_whole_missing_constraint_set`): 91 + 1 + 5 = 97, no overlap, and none of the five orphan-blocked pairs may appear in P1 or P2.

### What each migration actually does

Each file carries an explicit `[child table, child column, referenced table, referenced column, ON DELETE]` list — one entry per row of §1, transcribed from the schema the create-table migrations produce (verified: 97/97 names, referenced tables/columns and rules match `primeclassy_testing` exactly). On every run, both missing constraints and existing same-named constraints are checked: existing definitions must match child column, parent table/column and ON DELETE/ON UPDATE rules, else the entire window fails closed before any DDL. Rules they obey:

- **Idempotent, and the check reads `information_schema.REFERENTIAL_CONSTRAINTS`, never the `migrations` table.** That is the whole point: on DEV the `migrations` table claims 128 applied migrations while 97 constraints are absent, so a history-based check would have reported "nothing to do". On `primeclassy_testing` and on any correctly migrated database all three files are a **no-op**; on DEV only the missing ones are touched. Laravel has no `hasForeignKey()`, so the check is implemented directly. On an unknown driver the migration does **nothing** — absence of `information_schema` is absence of proof, and a constraint must never be assumed absent.
- **Fail-closed preflight before any DDL.** Every constraint that is actually missing is first checked: both tables and columns exist, child/parent column types are identical, a NOT NULL child does not reference a nullable column, an index already starts on the child column, and no row violates the constraint. If anything fails the file throws with the complete list of blockers and **not a single `ALTER` runs**. Without this, InnoDB's own errno 1452 would abort the file mid-way and leave the preceding constraints committed — measured in the negative control (§7).
- **Explicit constraint name** `<table>_<column>_foreign`, identical to the index that already exists, so nothing is renamed and no index is built.
- **Explicit `ON UPDATE RESTRICT`** instead of relying on a server default (Laravel emits no `onUpdate` anywhere in this repository).
- **One constraint per statement**, so a failure names the exact single offender.
- **Never** `Schema::disableForeignKeyConstraints()`, `FOREIGN_KEY_CHECKS=0`, `migrate:fresh`, truncate or a raw `DELETE`. A regression test asserts this against the *stripped* migration source, so the docblocks that describe the refused mechanisms cannot be mistaken for code.
- **`down()` is deliberately empty.** In a correctly migrated database these constraints belong to the create-table migrations, so rolling this file back must not drop 91 protections while those migrations stay recorded as applied. A rollback therefore only un-records the file; `up()` remains idempotent, so re-running it is still correct. This is a deliberate deviation from the earlier sketch ("`down()` drops exactly the constraints it added") and the reason for it is recorded in each migration's docblock. The inverse for the drifted DEV schema is the explicit `ALTER TABLE … DROP FOREIGN KEY` list in §5, which is metadata-only and instant.
- **Self-contained.** No migration in this repository references an `App\` class, so the driver checks and helpers are duplicated in each of the three files on purpose: a migration must keep working even if application code is refactored later.

### Why validation must stay on

`ALTER TABLE … ADD CONSTRAINT … FOREIGN KEY` only becomes `ALGORITHM=INPLACE` (metadata-only) when `foreign_key_checks = 0`, and in that mode **existing rows are not validated** — the database would then hold historical rows that violate the constraint it claims to enforce. So the plan keeps checks ON and accepts the table rebuild; errno 1452 is the desired fail-closed outcome and must be resolved by fixing the orphan, never by disabling checks.

---

## 5. Backup and rollback

### 5.1 The backup that has been taken (P0 complete)

**Path: `/home/developer/primeclassy-dev-backups/fk-restoration-20261008-154004/`** (outside the repository, per AGENTS.md §14; directory mode `700`, files mode `600`). No database password appears in any file or on any command line — the dumps were taken with a mode-600 `--defaults-extra-file`.

| File | Size | What it is |
|---|---|---|
| `database.sql` | 3,783,776 B | Full logical dump: `--single-transaction --routines --triggers --events --hex-blob --default-character-set=utf8mb4 primeclassy_dev`. 97 `CREATE TABLE`, ends with `-- Dump completed`, contains no `CREATE DATABASE`/`USE` (so it restores into any schema name). DEV defines no stored routines, triggers or events, so those flags added nothing. |
| `schema-only.sql` | 122,486 B | `--no-data --routines --triggers --events` snapshot of the exact pre-change DDL. |
| `show-create-table-affected.out` | 43,730 B | `SHOW CREATE TABLE` for all 24 affected tables plus `users`, `roles`, `orders`, `order_items`, `products`, `product_variations`, `payment_transactions`, `districts` (32 tables). |
| `row-manifest.tsv` | 3,419 B | `COUNT(*)` + `CHECKSUM TABLE` + engine for **all 97 tables**, 92,198 rows total. Compared again after P6. |
| `foreign-keys-before.tsv` | 11,320 B | The 107 constraints DEV actually had, with rules — the "before" side of the P6 diff. |
| `affected-tables.txt` | 474 B | The 24 tables the restoration touches. |
| `pre-delete-user_closures-and-shipping_configurations.sql` | 4,689 B | `--complete-insert` dump of the **only two tables from which a row deletion is planned** (P4a/P4b), so both rows are restorable verbatim. |
| `pre-delete-offending-rows.out` | 3,504 B | The exact pre-delete rows: `user_closures` id 11, `shipping_configurations` id 1, all 4 marker rows, and the live `users` / `products` / `product_variations` id lists. |
| `storage-app-public.tar.gz` | 4,512,707 B | 33 uploaded media files. Required because P3 `products:reset` deletes product images and transaction-evidence bytes; the database dump alone would not restore them. |
| `backend.env`, `backend.env.testing` | — | DEV and TEST environment files, mode 600, stored here and never in the repository. |
| `SHA256SUMS` | 1,089 B | Checksums of the other 12 files; re-verified after writing and again at the end of the verification: **12/12 OK, 0 FAILED**. |

### 5.2 Restore test — passed

A dump that cannot be restored is not a backup, so the dump was loaded into an **isolated throwaway MariaDB 10.11 container** (`mariadb:10.11`, the same engine version as the server), on `127.0.0.1:13306`, data directory on tmpfs. `primeclassy_dev` was only ever read; `primeclassy_testing` was not touched. The scratch database is `primeclassy_restore_scratch` and is disposable (the container was removed at the end of the verification session, so no copy of DEV data is left running; the exact `docker run` line is in the checkpoint).

| Verification | Result |
|---|---|
| Load | exit 0, **0 errors / 0 warnings**, 1,875 ms |
| Table count | 97 restored vs 97 dumped; table-name set **identical** |
| Row counts | **0 mismatches** across all 97 tables; 92,198 rows |
| `CHECKSUM TABLE` | **0 mismatches** across all 97 tables (45 tables return `0`, which is the correct value for an empty InnoDB table) |
| Constraints restored | 107 — the drifted DEV set, reproduced exactly |
| `migrations` rows | 128 — the drifted DEV history, reproduced exactly |

### 5.3 Rollback

- **P1, P2, P5 (metadata-only):** `ALTER TABLE <t> DROP FOREIGN KEY <name>` — instant, no data movement. These constraints are metadata; no phase rewrites a row, a column, a default or an index, so the reverse is exact and total. Measured on the scratch clone: reverting `villages.district_id` requires no data restoration at all.
- **P4a, P4b (the only two data deletions):** the dedicated `pre-delete-user_closures-and-shipping_configurations.sql` dump in §5.1 restores both rows verbatim. **Order matters:** if the constraint-add step fails after the deletion, restore the rows first and let P5 retry — never drop the constraint first, because `user_closures.ancestor_id`/`descendant_id` are `ON DELETE CASCADE` and dropping them would let a future user deletion corrupt the hierarchy with no database objection.
- **P3 (`products:reset`)** has its own documented rollback story in `docs/OPERATIONS.md` §8.2 and its own backup requirement; it is not part of this plan's rollback path.
- **Escalation:** if a window cannot be rolled back cleanly, restore `database.sql` into `primeclassy_dev` and stop. Never retry an `ALTER` blindly — `SHOW CREATE TABLE` from `show-create-table-affected.out` first, then decide.

**Rollback limitation to state plainly:** the *schema* rollback is total and instant, but the **migration record** is not symmetric. A file that completed stays recorded in `migrations`; a file that *refused* stays pending; and `down()` is empty, so `migrate:rollback` un-records a file without reversing its DDL. That asymmetry is intentional (a rollback must not be able to drop protections silently), but it means **`git`-level rollback of these three files is not a schema rollback**. The only complete reversals are the explicit `DROP FOREIGN KEY` list and the full dump restore.

---

## 6. Locking, failure recovery and downtime

### 6.1 Per-phase DDL locking — measured, not estimated

| Window | Mechanism | Measured / expected lock | Risk |
|---|---|---|---|
| **P1** (21 tables) | one `ADD CONSTRAINT` per table | **2.0 s total** for all 91 constraints, measured end-to-end on a faithful clone. Every table ≤ 224 KB / ≤ 32 rows, so the exclusive time per table is milliseconds | negligible — read traffic unaffected |
| **P2** (`villages`) | single table rebuild of 83,202 rows / 8.2 MB | **716 ms** measured on the clone. Exclusive metadata lock for the whole rebuild | **the only material lock** — still schedule a window (`php artisan down` / `up`) on DEV, and measure separately on a production clone |
| **P3** | `products:reset`: one DB transaction + post-commit file deletes | row locks only, short | see `docs/OPERATIONS.md` §8.2 |
| **P4a/P4b** | 2 single-row deletes | negligible | negligible |
| **P5** (3 tables) | three `ADD CONSTRAINT` statements | **151 ms** measured on the clone | negligible |
| **P6** | read-only verification | none | none |

The measurements come from a byte-identical copy of DEV running the same engine version; they are an indication for DEV, **not** a production figure. Production timing must be measured on its own clone and never extrapolated from DEV.

Additional constraints:
- No concurrent DDL — never run `migrate` in parallel with this plan.
- `ALGORITHM=INPLACE, LOCK=NONE` is unavailable for FK addition without disabling validation, so it is rejected by design (§4).
- A metadata lock can queue behind a long-running writer. If a window must be abandoned mid-way, stop and re-check `SHOW CREATE TABLE` — do not retry blindly.

### 6.2 Failure recovery

| Failure | What actually happens | Recovery |
|---|---|---|
| Preflight finds any blocker in the window | the file throws with every blocker named; **zero** `ALTER`s run; nothing is recorded in `migrations` | resolve the blocker (never disable checks), then rerun `migrate --path=` — the window is unchanged |
| InnoDB errno 1452 mid-window (should be unreachable — preflight precedes it) | the failing `ALTER` aborts; the constraints already added **stay added**; the file is not recorded | inspect `information_schema` for what landed, fix the orphan, rerun the same file — the existence check makes the rerun finish only the remainder |
| Lock wait timeout / deadlock during a window | the statement aborts; the same partial-state shape as above | `SHOW PROCESSLIST`, clear the blocking writer, rerun the file |
| Process killed mid-window | same partial-state shape; `migrations` has no row for the file | rerun the file; idempotency finishes it |
| Orphan found where a constraint was expected | the window refuses; the orphan row is **still there** | the plan's §3 decision applies; the migration never repairs data |
| Restore from the dump needed | `mysql primeclassy_dev < database.sql` into an emptied schema | re-verify against `row-manifest.tsv` and `foreign-keys-before.tsv` before resuming |

Because MySQL DDL is not transactional, **a partially-applied window is a normal state, not a corruption** — every window is independently rerunnable and independently revertible, and P6's verification is what decides whether the sequence finished.

### 6.3 The 6 `NO ACTION` vs `RESTRICT` constraints

`inventory_cancellation_reversals.{agent_id,order_id,order_item_id,processed_by}`, `sheets_configs.destination_id`, `sheets_destinations.agent_id`. **HUMAN DECISION (LOCKED 2026-10-08): leave them.** They enforce the same deletes; normalizing them would drop and re-add 6 constraints purely for cosmetic parity with `primeclassy_testing` and would only add risk. They are the *only* remaining difference between DEV and the canonical schema after the restoration, and P6's verification treats exactly those 6 as expected.

---

## 7. What was prepared, and the evidence

### Files

- NEW `backend/database/migrations/2026_10_08_110000_restore_pre_reset_foreign_keys.php` — P1, 91 constraints.
- NEW `backend/database/migrations/2026_10_08_111000_restore_villages_district_foreign_key.php` — P2, 1 constraint.
- NEW `backend/database/migrations/2026_10_08_112000_restore_post_cleanup_foreign_keys.php` — P5, 5 constraints.
- NEW `backend/tests/Feature/DevSchemaForeignKeyRestorationTest.php` — 8 tests, read-only against the canonical `primeclassy_testing` schema.
- NEW `backend/tests/Feature/DevSchemaForeignKeyRestorationDdlTest.php` — 5 tests, destructive DDL scenarios; rebuilds the isolated schema before and after each test via `RestoresIsolatedTestDatabase`.
- `docs/OPERATIONS.md` — §8.3 records the procedure.
- No model, service, controller or config change. No data migration unless P4a/P4b are authorized.

### Commands and exact results

- FK inventory diffed from `information_schema` on both databases → **97 missing on DEV**, 6 rule-only differences, 0 extra on DEV, 0 naming divergences, 97/97 types and leading indexes compatible.
- Orphan probe over all 97 constraints on DEV → **5 blocked, 7 orphan rows, 92 clean** (exactly as §1 records).
- `mysqldump` full / `--no-data` / `CHECKSUM TABLE` / `SHOW CREATE TABLE` for §5.1 → all exit 0.
- `sha256sum -c SHA256SUMS` → **12/12 OK, 0 FAILED**, run twice (after writing and at the end).
- Restore test into the isolated MariaDB 10.11 container → §5.2, **0 mismatches**.
- `php artisan migrate --force` against the restored clone: `110000` **DONE 2s**, `111000` **DONE 716ms**, `112000` **FAIL** with all 5 orphans named and **zero** mutations.
- Row counts and `CHECKSUM TABLE` re-measured on the clone after P1+P2 → **0 differences in 96 business tables**; only `migrations` changed (128 → 130), as expected.
- Container-only simulation of the locked cleanup (delete `user_closures` id 11, `shipping_configurations` id 1, the 4 marker rows) → `112000` **DONE 151ms**; the clone then carried **204** constraints and its FK inventory diffed against `primeclassy_testing` showed **only the 6 locked `NO ACTION`/`RESTRICT` differences**. This simulation ran only inside the throwaway container — no DEV or production row was deleted.
- Enforcement probed on the restored clone: orphan insert → **errno 1452**, delete of a referenced parent → **errno 1451**, district delete → **CASCADE** removed its 7 villages (rolled back), no residue.
- `php artisan test tests/Feature/DevSchemaForeignKeyRestorationTest.php tests/Feature/DevSchemaForeignKeyRestorationDdlTest.php` → **13 passed / 89 assertions / 0 failed**.
- **Negative controls** (each reverted afterwards): flipping one `ON DELETE` rule fails `…already_exists_on_the_fully_migrated_schema` naming the entry; moving an orphan-blocked constraint into P1 fails the partition test; commenting out `assertRestorable()` makes the phase-C test fail with a raw InnoDB 1452 mid-file instead of a clean refusal — which is precisely the partial-application hazard the preflight removes.
- `php -l` clean on all 5 new files; `./vendor/bin/pint --test` **passed** on all 5; `git diff --check` exit 0.
- **Executed on DEV (authorized, revisions 6 and 7):** P1 → batch [14] Ran, 107 → 198 constraints; P2 → batch [15] Ran, 198 → 199 constraints in 878 ms. After each: the **96 business tables were byte-identical to `row-manifest.tsv` on both `COUNT(*)` and `CHECKSUM TABLE`**, only `migrations` grew (128 → 129 → 130), 0 constraints were dropped and 0 pre-existing rules changed. The P2 window reused the existing `villages_district_id_index` and rebuilt nothing else.
- Full backend suite **not run** — out of scope for this task. Still owed before any commit per AGENTS.md §7.
