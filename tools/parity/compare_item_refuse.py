#!/usr/bin/env python3
"""GLM differential harness — SHIPMENT ITEM REFUSE (1 Laravel-only route).

  POST /api/v1/shipments/{shipment}/items/{orderItem}/refuse

Covers: RBAC (executor-only), validation, item/shipment binding 404, not_in_delivery,
already_open, full refusal, partial refusal (split-out of refused units: fees/subtotals
apportioned, stock-request demand moved, sibling item), audit rows, DB state.

Usage: python3 tools/parity/compare_item_refuse.py
"""
import json
import re
import sys

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, db, norm_body
from compare_dispatch import seed_dispatch
from compare_users import KURIR, COORD as KOORD, AGEN

TS = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+Z|\+00:00)?$")


def scrub(v):
    if isinstance(v, str) and TS.match(v):
        return "<ts>"
    if isinstance(v, tuple):
        return [scrub(x) for x in v]
    if isinstance(v, list):
        return sorted((scrub(x) for x in v), key=lambda d: json.dumps(d, sort_keys=True)) if v and all(isinstance(i, dict) for i in v) else [scrub(x) for x in v]
    if isinstance(v, dict):
        return {k: ("<id>" if k in ("id", "order_item_id", "shipment_id") and isinstance(x, int) else scrub(x))
                for k, x in v.items()}
    return v


def seed_refuse():
    db("DELETE FROM delivery_refusals WHERE shipment_id=991002")
    db("DELETE FROM activity_logs WHERE event IN ('delivery_refusal.recorded','order_item.split_for_refusal')")
    db("DELETE FROM stock_request_items WHERE order_item_id IN (SELECT id FROM order_items WHERE order_id=991001)")
    db("DELETE FROM stock_requests WHERE order_id=991001")
    db("SET FOREIGN_KEY_CHECKS=0")
    db("DELETE FROM order_items WHERE split_from_order_item_id=991001")
    db("DELETE FROM order_items WHERE id=991001")
    db("DELETE FROM orders WHERE id=991001")
    db("SET FOREIGN_KEY_CHECKS=1")
    db("INSERT INTO orders (id, order_no, agent_id, konsumen_id, status, payment_status, dp_amount, paid_amount, "
       "remaining_amount, subtotal_amount, total_amount, recipient_name_snapshot, recipient_phone_snapshot, "
       "address_snapshot, created_at, updated_at) "
       "VALUES (991001,'GLM-REFU-1',10,13,'dikirim','paid',0,0,0,0,0,'x','0812','jl',NOW(),NOW())")
    db("INSERT INTO order_items (id, order_id, product_id, stock_source, product_name_snapshot, sku_snapshot, "
       "unit_price_snapshot, subtotal_snapshot, original_quantity, fulfilled_quantity, status, shipment_id, "
       "courier_fee_amount, handed_over_at, created_at, updated_at) "
       "VALUES (991001,991001,1,'agent','P','SKU-1',1000,4000,4,4,'dikirim',NULL,2000,NOW(),NOW(),NOW())")
    db("INSERT INTO shipments (id, order_id, courier_id, status, delivery_mode, created_at, updated_at) "
       "VALUES (991002, 991001, 902, 'in_transit', 'standard', NOW(), NOW())")
    db("UPDATE order_items SET shipment_id=991002 WHERE id=991001")
    db("INSERT INTO stock_requests (order_id, agent_id, request_number, status, created_at, updated_at) "
       "VALUES (991001, 10, 'GLM-SR-991001', 'fulfilled', NOW(), NOW())")
    rid = db("SELECT id FROM stock_requests WHERE order_id=991001 ORDER BY id DESC LIMIT 1")[0][0]
    db(f"INSERT INTO stock_request_items (stock_request_id, order_item_id, product_id, requested_qty, fulfilled_qty, "
       f"remaining_qty, created_at, updated_at) VALUES ({rid},991001,1,4,4,0,NOW(),NOW())")


def scenario(base):
    out = {}
    kurir = f.client_for(base, KURIR)
    koord = f.client_for(base, KOORD)
    agen = f.client_for(base, AGEN)
    path = "/api/v1/shipments/991002/items/991001/refuse"

    out["rbac_koord"] = koord.call("POST", path, {"reason": "ditolak"})
    out["rbac_agen"] = agen.call("POST", path, {"reason": "ditolak"})
    out["missing_reason"] = kurir.call("POST", path, {})
    out["reason_too_long"] = kurir.call("POST", path, {"reason": "x" * 256})
    out["missing_shipment"] = kurir.call("POST", "/api/v1/shipments/999999/items/991001/refuse", {"reason": "x"})
    out["missing_item"] = kurir.call("POST", "/api/v1/shipments/991002/items/999999/refuse", {"reason": "x"})
    out["wrong_item_binding"] = kurir.call("POST", "/api/v1/shipments/991002/items/991101/refuse", {"reason": "x"})

    # Partial refusal: 1 of 4 refused → split-out; sibling item dikirim with qty 1.
    out["refuse_partial"] = kurir.call("POST", path, {"reason": "rusak sebagian", "quantity": 1})
    out["source_after_split"] = db("SELECT fulfilled_quantity, CAST(subtotal_snapshot AS CHAR), "
                                   "CAST(courier_fee_amount AS CHAR) FROM order_items WHERE id=991001")
    out["child_after_split"] = db("SELECT fulfilled_quantity, CAST(subtotal_snapshot AS CHAR), "
                                  "CAST(courier_fee_amount AS CHAR), status, shipment_id FROM order_items "
                                  "WHERE split_from_order_item_id=991001")
    out["refusal_after_split"] = db("SELECT quantity, status, reason FROM delivery_refusals WHERE shipment_id=991002")

    # already_open on the carved child
    child = db("SELECT id FROM order_items WHERE split_from_order_item_id=991001")[0][0]
    out["already_open"] = kurir.call("POST", f"/api/v1/shipments/991002/items/{child}/refuse", {"reason": "lagi"})

    # Full refusal of the source (no quantity): refusedQty = remaining 3
    out["refuse_full"] = kurir.call("POST", path, {"reason": "ditolak semua"})
    out["refusal_count"] = db("SELECT COUNT(*) FROM delivery_refusals WHERE shipment_id=991002")
    out["audit"] = db("SELECT event FROM activity_logs WHERE event IN ('delivery_refusal.recorded','order_item.split_for_refusal') ORDER BY id")
    return out


def main():
    f.seed_full()
    seed_dispatch()
    seed_refuse()
    lar = scenario(LARAVEL)
    f.seed_full()
    seed_dispatch()
    seed_refuse()
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
            print("  L=" + json.dumps(lv, default=str)[:600])
            print("  G=" + json.dumps(gv, default=str)[:600])
    print(f"item_refuse: {total - failed} passed, {failed} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
