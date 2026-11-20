#!/usr/bin/env python3
"""GLM differential harness — RETURN PICKUP COURIER ASSIGNMENT (1 Laravel-only route).

  PATCH /api/v1/return-items/{item}/courier   office/dispatch assignment (no kurir self-claim)

Covers: RBAC (kurir 403, agen same-branch ok), missing row 404, validation (courier_id
required/exists), already_reviewed gate, invalid assignment (foreign courier),
success + DB state + audit, idempotent replay, 409 stale-assignment conflict,
switch with expected_courier_id.

Usage: python3 tools/parity/compare_return_assign.py
"""
import json
import re
import sys

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, db, norm_body
from compare_dispatch import seed_dispatch
from compare_users import COORD, AGEN, KURIR, SUPER

TS = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+Z|\+00:00)?$")


def scrub(v):
    if isinstance(v, str) and TS.match(v):
        return "<ts>"
    if isinstance(v, tuple):
        return [scrub(x) for x in v]
    if isinstance(v, list):
        return sorted((scrub(x) for x in v), key=lambda d: json.dumps(d, sort_keys=True)) if v and all(isinstance(i, dict) for i in v) else [scrub(x) for x in v]
    if isinstance(v, dict):
        return {k: ("<id>" if k in ("id", "courier_id") and isinstance(x, int) else scrub(x))
                for k, x in v.items()}
    return v


def seed_return():
    db("DELETE FROM activity_logs WHERE event='return_item.courier_assigned'")
    db("DELETE FROM return_items WHERE order_item_id=997001")
    db("DELETE FROM shipments WHERE order_id=997001")
    db("SET FOREIGN_KEY_CHECKS=0")
    db("DELETE FROM order_items WHERE order_id=997001")
    db("DELETE FROM orders WHERE id=997001")
    db("SET FOREIGN_KEY_CHECKS=1")
    db("INSERT INTO orders (id, order_no, agent_id, konsumen_id, status, payment_status, dp_amount, paid_amount, "
       "remaining_amount, subtotal_amount, total_amount, recipient_name_snapshot, recipient_phone_snapshot, "
       "address_snapshot, created_at, updated_at) "
       "VALUES (997001,'GLM-RET-1',10,13,'dikirim','paid',0,0,0,0,0,'x','0812','jl',NOW(),NOW())")
    db("INSERT INTO order_items (id, order_id, product_id, stock_source, product_name_snapshot, "
       "unit_price_snapshot, subtotal_snapshot, original_quantity, fulfilled_quantity, status, shipment_id, "
       "created_at, updated_at) VALUES "
       "(997001,997001,1,'agent','P',1000,1000,1,1,'pengembalian',NULL,NOW(),NOW())")
    db("INSERT INTO shipments (id, order_id, courier_id, status, delivery_mode, created_at, updated_at) "
       "VALUES (997002, 997001, 902, 'in_transit', 'standard', NOW(), NOW())")
    db("UPDATE order_items SET shipment_id=997002 WHERE id=997001")
    db("INSERT INTO returns (order_id, requested_by, reason, status, created_at, updated_at) "
       "VALUES (997001, 13, 'rusak', 'approved', NOW(), NOW())")
    rid2 = db("SELECT id FROM returns WHERE order_id=997001 ORDER BY id DESC LIMIT 1")[0][0]
    db(f"INSERT INTO return_items (return_id, order_item_id, status, quantity_returned, refund_amount, "
       f"created_at, updated_at) VALUES ({rid2}, 997001, 'pending', 1, 0, NOW(), NOW())")


def scenario(base):
    out = {}
    koord = f.client_for(base, COORD)
    agen = f.client_for(base, AGEN)
    kurir = f.client_for(base, KURIR)
    super_c = f.client_for(base, SUPER)
    rid = db("SELECT id FROM return_items WHERE order_item_id=997001 ORDER BY id DESC LIMIT 1")[0][0]
    path = f"/api/v1/return-items/{rid}/courier"

    out["rbac_kurir"] = kurir.call("PATCH", path, {"courier_id": 902})
    out["rbac_agen"] = agen.call("PATCH", path, {"courier_id": 902})
    out["missing_item"] = koord.call("PATCH", "/api/v1/return-items/999999/courier", {"courier_id": 902})
    out["missing_body"] = koord.call("PATCH", path, {})
    out["missing_courier_row"] = koord.call("PATCH", path, {"courier_id": 424242})
    out["invalid_courier_profileless"] = koord.call("PATCH", path, {"courier_id": 993004})

    out["assign"] = koord.call("PATCH", path, {"courier_id": 902})
    out["db_after"] = db("SELECT courier_id FROM return_items WHERE id=" + str(rid))
    out["audit"] = db("SELECT event FROM activity_logs WHERE event='return_item.courier_assigned'")

    out["replay"] = koord.call("PATCH", path, {"courier_id": 902})
    out["conflict_no_expected"] = koord.call("PATCH", path, {"courier_id": 901})
    out["switch_ok"] = koord.call("PATCH", path, {"courier_id": 901, "expected_courier_id": 902})
    out["db_after_switch"] = db("SELECT courier_id FROM return_items WHERE id=" + str(rid))
    out["super_reassign"] = super_c.call("PATCH", path, {"courier_id": 902, "expected_courier_id": 901})
    out["db_final"] = db("SELECT courier_id FROM return_items WHERE id=" + str(rid))
    return out


def main():
    f.seed_full()
    seed_dispatch()
    seed_return()
    lar = scenario(LARAVEL)
    f.seed_full()
    seed_dispatch()
    seed_return()
    go = scenario(GO)

    failed = 0
    total = 0
    for key in sorted(lar):
        total += 1
        lv, gv = scrub(norm_body(lar[key])), scrub(norm_body(go[key]))
        ok = lv == gv
        if not ok:
            failed += 1
        print(("PASS " if ok else "FAIL ") + key)
        if not ok:
            print("  L=" + json.dumps(lv, default=str)[:700])
            print("  G=" + json.dumps(gv, default=str)[:700])
    print(f"return_assign: {total - failed} passed, {failed} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
