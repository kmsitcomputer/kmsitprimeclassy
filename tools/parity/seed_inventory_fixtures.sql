-- IMP-010 inventory fixtures (ISOLATED test database only). Synthetic data, never production.
-- Applied after seed_test_fixtures.sql. Idempotent: it clears the tables it owns first.

SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM stock_movements;
DELETE FROM stock_handovers;
DELETE FROM stock_transfer_items;
DELETE FROM stock_transfers;
DELETE FROM warehouse_stocks;
DELETE FROM product_stocks;
DELETE FROM product_variation_stocks;
DELETE FROM warehouse_settings;
DELETE FROM warehouse_sub_locations;
DELETE FROM sub_stock_request_items;
DELETE FROM sub_stock_requests;
DELETE FROM sub_stock_reservations;

-- Product 3 is the ONLY target with a legacy commitment row and NO warehouse row anywhere: it is what keeps
-- the "legacy fallback" branch of the canonical projection exercised (product 1 gained a `sub` row below).
INSERT INTO product_stocks (id, agent_id, product_id, quantity_on_hand, quantity_reserved, created_at, updated_at) VALUES
 (2, 10, 3, 25, 3, '2026-11-09 09:00:00', '2026-11-09 09:00:00'),
 -- Product 2 has a commitment row with ZERO reserved: it does not change the canonical projection (product 2
 -- already has warehouse rows, so the warehouse stays authoritative), but it gives the transit->sub capacity
 -- lock a real row to take — which is what the two-connection test needs to observe.
 (3, 10, 2, 0, 0, '2026-11-09 09:00:00', '2026-11-09 09:00:00');

-- Legacy (pre-warehouse) commitment rows for agent 10.
--   product 1  : on_hand 40, reserved 5  → a `sub` warehouse row exists below, so the WAREHOUSE is authoritative
--                and its transit base is 0 → available 0, NOT 35. This is the canonical-projection trap: legacy
--                on-hand must not survive once any warehouse row exists for the target.
--   product 3  : on_hand 25, reserved 3  → available 22, source legacy (no warehouse row at all)
--   variation 1: on_hand 10, reserved 2  → available  8, source legacy
INSERT INTO product_stocks (id, agent_id, product_id, quantity_on_hand, quantity_reserved, created_at, updated_at) VALUES
 (1, 10, 1, 40, 5, '2026-11-10 09:00:00', '2026-11-10 09:00:00');

INSERT INTO product_variation_stocks (id, agent_id, product_variation_id, quantity_on_hand, quantity_reserved, created_at, updated_at) VALUES
 (1, 10, 1, 10, 2, '2026-11-10 09:00:00', '2026-11-10 09:00:00'),
 (2, 10, 2, 7, 0, '2026-11-10 09:00:00', '2026-11-10 09:00:00');

-- Warehouse buckets. The presence of ANY row for a target makes the warehouse authoritative for it
-- (SellableStockService::calculate), which is the invariant these fixtures exercise:
--   product 2  (variation product, but a warehouse row for the PRODUCT id keeps it a product target)
--              transit 12 + factory_plan 4, plan ENABLED → base 16
--   variation 2 transit 9, plan disabled → base 9
--   product 1  keeps its legacy row but HAS a `sub` row below → available 0 (warehouse authoritative)
INSERT INTO warehouse_sub_locations (id, agent_id, owner_user_id, previous_owner_user_id, code, name, address, contact_number, description, is_active, created_by, created_at, updated_at) VALUES
 (1, 10, 13, NULL, 'SUB-BDG-01', 'Sub Bandung', 'Jl. Sub 1', '081200000001', 'Sub outlet', 1, 10, '2026-11-10 09:00:00', '2026-11-10 09:00:00'),
 (2, 10, 14, 13, 'SUB-BDG-02', 'Sub Bandung Dua', 'Jl. Sub 2', '081200000002', NULL, 1, 10, '2026-11-10 09:00:00', '2026-11-10 09:00:00'),
 (3, 10, 16, NULL, 'SUB-BDG-03', 'Sub Bandung Tiga', 'Jl. Sub 3', '081200000003', NULL, 1, 10, '2026-11-10 09:00:00', '2026-11-10 09:00:00'),
 -- Sub location 4 has NO owner: the only kind a plain warehouse transfer may target (an owned Sub moves
 -- exclusively through the Sub stock-request workflow).
 (4, 10, NULL, NULL, 'SUB-BDG-04', 'Sub Bandung Empat', 'Jl. Sub 4', '081200000004', NULL, 1, 10, '2026-11-10 09:00:00', '2026-11-10 09:00:00');

INSERT INTO warehouse_settings (id, agent_id, factory_plan_enabled, created_at, updated_at) VALUES
 (1, 10, 1, '2026-11-10 09:00:00', '2026-11-10 09:00:00');

INSERT INTO warehouse_stocks (id, agent_id, product_id, product_variation_id, stock_type, sub_location_id, quantity, created_at, updated_at) VALUES
 (2, 10, 2, NULL, 'transit',      NULL, 12, '2026-11-10 09:00:00', '2026-11-10 09:00:00'),
 (3, 10, 2, NULL, 'factory_plan', NULL, 4,  '2026-11-10 09:00:00', '2026-11-10 09:00:00'),
 (4, 10, NULL, 2, 'transit',      NULL, 9,  '2026-11-10 09:00:00', '2026-11-10 09:00:00'),
 (5, 10, NULL, 1, 'sub',          1,    6,  '2026-11-10 09:00:00', '2026-11-10 09:00:00');

INSERT INTO stock_movements (id, agent_id, product_id, product_variation_id, type, quantity, stock_type, sub_location_id, reference_type, reference_id, note, created_by, created_at) VALUES
 (1, 10, 2, NULL, 'in', 12, 'transit', NULL, NULL, NULL, 'seed transit', 10, '2026-11-10 09:05:00'),
 (2, 10, 2, NULL, 'factory_plan_in', 4, 'factory_plan', NULL, NULL, NULL, 'seed plan', 10, '2026-11-10 09:06:00'),
 (3, 10, NULL, 2, 'in', 9, 'transit', NULL, NULL, NULL, 'seed variation transit', 10, '2026-11-10 09:07:00'),
 (4, 10, 1, NULL, 'in', 40, NULL, NULL, NULL, NULL, 'seed legacy', 10, '2026-11-10 09:08:00');

-- A pending replenishment request from the Sales-Kurir-Sub owner of sub location 3, so the list endpoint is
-- exercised with a real row (not just the empty case).
INSERT INTO sub_stock_requests (id, agent_id, sub_location_id, request_number, direction, status, requested_by,
    idempotency_key, note, created_at, updated_at) VALUES
 (1, 10, 3, 'SSR-IMP010-001', 'replenish', 'requested', 16, 'imp010-fixture-key-1', 'Perlu tambahan', '2026-11-10 10:00:00', '2026-11-10 10:00:00');

INSERT INTO sub_stock_request_items (id, sub_stock_request_id, product_id, product_variation_id, quantity, created_at, updated_at) VALUES
 (1, 1, 1, NULL, 5, '2026-11-10 10:00:00', '2026-11-10 10:00:00');

-- NOTE: no sub_stock_reservations row is seeded. That table requires an order_item_id (NOT NULL), which would
-- pull the whole orders/order_items fixture chain into this inventory checkpoint. /sub-stock/my is therefore
-- verified with reserved = 0; a reservation-bearing case belongs to the orders checkpoint.

-- Sub stock for location 3 so /sub-stock/my has rows.
INSERT INTO warehouse_stocks (id, agent_id, product_id, product_variation_id, stock_type, sub_location_id, quantity, created_at, updated_at) VALUES
 (6, 10, 1, NULL, 'sub', 3, 8, '2026-11-10 09:00:00', '2026-11-10 09:00:00'),
 (7, 10, NULL, 1, 'sub', 3, 3, '2026-11-10 09:00:00', '2026-11-10 09:00:00');

-- Reset the transfer-scoped AUTO_INCREMENT counters so two comparison runs starting from this same fixture
-- produce identical row ids. Only touches the isolated test database, and only tables this file empties.
ALTER TABLE stock_transfers AUTO_INCREMENT = 1;
ALTER TABLE stock_transfer_items AUTO_INCREMENT = 1;
ALTER TABLE stock_handovers AUTO_INCREMENT = 1;
ALTER TABLE stock_movements AUTO_INCREMENT = 1;

SET FOREIGN_KEY_CHECKS = 1;