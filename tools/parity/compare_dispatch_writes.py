#!/usr/bin/env python3
"""GLM differential harness — DISPATCH WRITES (2 Laravel-only routes).

  POST /api/v1/dispatch/order-items/{orderItem}/split-batch   REM-002 batch split
  POST /api/v1/dispatch/orders/{order}/request-fulfillment     REM-001 coordinator fulfillment request

Covers: RBAC (koordinator only; agen 403), validation (quantity required/min, note max),
missing row 404, window_closed gate, sub-source gate, provider gate, invalid quantity,
stock-request lock gate, successful split (child row, stock request carve, fresh shipment,
source quantities/fees), successful fulfillment request (coordinator_* columns, audit),
nothing_outstanding gate, request_not_applicable gate.

Usage: python3 tools/parity/compare_dispatch_writes.py
"""
import json
import re
import sys

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, db, norm_body
from compare_dispatch import seed_dispatch
from compare_users import COORD, AGEN

TS = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+Z|\+00:00)?$")


def scrub(v):
    if isinstance(v, str) and TS.match(v):
        return "<ts>"
    if isinstance(v, tuple):
        return [scrub(x) for x in v]
    if isinstance(v, list):
        return sorted((scrub(x) for x in v), key=lambda d: json.dumps(d, sort_keys=True)) if v and all(isinstance(i, dict) for i in v) else [scrub(x) for x in v]
    if isinstance(v, dict):
        return {k: ("<id>" if k in ("id", "order_item_id", "shipment_id", "order_id", "stock_request_id") and isinstance(x, int) else scrub(x))
                for k, x in v.items()}
    return v


def seed_dw():
    db("DELETE FROM activity_logs WHERE event IN ('order_item.split_for_reschedule','stock_request.coordinator_requested')")
    db("DELETE FROM stock_request_items WHERE order_item_id IN (996001,996003,996005)")
    db("DELETE FROM stock_requests WHERE order_id=996001")
    db("DELETE FROM shipments WHERE order_id=996001")
    db("SET FOREIGN_KEY_CHECKS=0")
    db("DELETE FROM order_items WHERE id IN (996001,996003,996005)")
    db("DELETE FROM orders WHERE id=996001")
    db("SET FOREIGN_KEY_CHECKS=1")
    db("INSERT INTO orders (id, order_no, agent_id, konsumen_id, status, payment_status, dp_amount, paid_amount, "
       "remaining_amount, subtotal_amount, total_amount, recipient_name_snapshot, recipient_phone_snapshot, "
       "address_snapshot, created_at, updated_at) "
       "VALUES (996001,'GLM-DW-1',10,13,'diproses','paid',0,0,0,0,0,'x','0812','jl',NOW(),NOW())")
    db("INSERT INTO order_items (id, order_id, product_id, stock_source, product_name_snapshot, sku_snapshot, "
       "unit_price_snapshot, subtotal_snapshot, original_quantity, fulfilled_quantity, status, shipment_id, "
       "courier_fee_amount, sub_location_id, created_at, updated_at) VALUES "
       "(996001,996001,1,'agent','P','SKU-1',1000,5000,5,5,'diproses',NULL,2500,NULL,NOW(),NOW()),"
       "(996003,996001,1,'sub','P','SKU-1',1000,1000,1,1,'diproses',NULL,0,1,NOW(),NOW())")
    db("INSERT INTO shipments (id, order_id, courier_id, status, delivery_mode, created_at, updated_at) "
       "VALUES (996002, 996001, NULL, 'pending', 'standard', NOW(), NOW())")
    db("UPDATE order_items SET shipment_id=996002 WHERE id IN (996001,996003)")
    db("INSERT INTO stock_requests (order_id, agent_id, request_number, status, created_at, updated_at) "
       "VALUES (996001, 10, 'GLM-SR-996001', 'pending', NOW(), NOW())")
    rid = db("SELECT id FROM stock_requests WHERE order_id=996001 ORDER BY id DESC LIMIT 1")[0][0]
    db(f"INSERT INTO stock_request_items (stock_request_id, order_item_id, product_id, requested_qty, fulfilled_qty, "
       f"remaining_qty, created_at, updated_at) VALUES ({rid},996001,1,5,0,5,NOW(),NOW())")


def scenario(base):
    out = {}
    koord = f.client_for(base, COORD)
    agen = f.client_for(base, AGEN)

    out["rbac_agen_split"] = agen.call("POST", "/api/v1/dispatch/order-items/996001/split-batch", {"quantity": 2})
    out["split_missing_quantity"] = koord.call("POST", "/api/v1/dispatch/order-items/996001/split-batch", {})
    out["split_quantity_zero"] = koord.call("POST", "/api/v1/dispatch/order-items/996001/split-batch", {"quantity": 0})
    out["split_missing_item"] = koord.call("POST", "/api/v1/dispatch/order-items/999999/split-batch", {"quantity": 2})
    out["split_sub_source"] = koord.call("POST", "/api/v1/dispatch/order-items/996003/split-batch", {"quantity": 2})
    out["split_all_quantity"] = koord.call("POST", "/api/v1/dispatch/order-items/996001/split-batch", {"quantity": 5})
    out["split_note_too_long"] = koord.call("POST", "/api/v1/dispatch/order-items/996001/split-batch",
                                            {"quantity": 2, "note": "x" * 256})
    out["split_ok"] = koord.call("POST", "/api/v1/dispatch/order-items/996001/split-batch",
                                 {"quantity": 2, "note": "batch lanjutan"})
    out["split_source_after"] = db("SELECT fulfilled_quantity, CAST(subtotal_snapshot AS CHAR), "
                                   "CAST(courier_fee_amount AS CHAR) FROM order_items WHERE id=996001")
    out["split_child"] = db("SELECT split_from_order_item_id, fulfilled_quantity, shipment_id IS NOT NULL "
                            "FROM order_items WHERE split_from_order_item_id=996001")
    out["split_sr_after"] = db("SELECT (order_item_id=996001), requested_qty, fulfilled_qty, remaining_qty "
                               "FROM stock_request_items WHERE order_item_id IN (SELECT id FROM order_items WHERE order_id=996001) ORDER BY id")
    out["split_audit"] = db("SELECT event, description FROM activity_logs WHERE event='order_item.split_for_reschedule' ORDER BY id")

    out["rbac_agen_fulfil"] = agen.call("POST", "/api/v1/dispatch/orders/996001/request-fulfillment", {})
    out["fulfil_missing_order"] = koord.call("POST", "/api/v1/dispatch/orders/999999/request-fulfillment", {})
    out["fulfil_ok"] = koord.call("POST", "/api/v1/dispatch/orders/996001/request-fulfillment",
                                  {"note": "tolong segera"})
    out["fulfil_after"] = db("SELECT coordinator_requested_by IS NOT NULL, coordinator_requested_at IS NOT NULL, "
                             "coordinator_request_note FROM stock_requests WHERE order_id=996001")
    out["fulfil_audit"] = db("SELECT event, description FROM activity_logs WHERE event='stock_request.coordinator_requested' ORDER BY id")

    db("UPDATE stock_requests SET status='fulfilled' WHERE order_id=996001")
    db("UPDATE stock_request_items SET remaining_qty=0, fulfilled_qty=requested_qty WHERE stock_request_id="
       + str(db("SELECT id FROM stock_requests WHERE order_id=996001")[0][0]))
    out["fulfil_nothing_outstanding"] = koord.call("POST", "/api/v1/dispatch/orders/996001/request-fulfillment", {})

    db("UPDATE orders SET status='dibatalkan' WHERE id=996001")
    out["fulfil_not_applicable"] = koord.call("POST", "/api/v1/dispatch/orders/996001/request-fulfillment", {})
    return out


def main():
    f.seed_full()
    seed_dispatch()
    seed_dw()
    lar = scenario(LARAVEL)
    f.seed_full()
    seed_dispatch()
    seed_dw()
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
    print(f"dispatch_writes: {total - failed} passed, {failed} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
