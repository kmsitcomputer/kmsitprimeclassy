#!/usr/bin/env python3
"""GLM differential harness — ORDER ITEM RECEIPTS (2 Laravel-only routes).

  GET  /order-items/{item}/receipts  index (admin own-agent / super_admin)
  POST /order-items/{item}/receipts  record (REM-07: bounded, idempotent)

Usage: python3 tools/parity/compare_item_receipts.py
"""
import json
import re
import sys

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, db, norm_body
from compare_dispatch import seed_dispatch
from compare_users import ADMIN, SUPER, AGEN, COORD as KOORD

TS = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+Z|\+00:00)?$")


def scrub(v):
    if isinstance(v, str) and TS.match(v):
        return "<ts>"
    if isinstance(v, tuple):
        return [scrub(x) for x in v]
    if isinstance(v, list):
        return sorted((scrub(x) for x in v), key=lambda d: json.dumps(d, sort_keys=True)) if v and all(isinstance(i, dict) for i in v) else [scrub(x) for x in v]
    if isinstance(v, dict):
        return {k: ("<id>" if k in ("id", "order_item_id") and isinstance(x, int) else scrub(x))
                for k, x in v.items()}
    return v


def seed_receipts():
    db("DELETE FROM order_item_receipts WHERE order_item_id=998101")
    db("DELETE FROM activity_logs WHERE event='order_item.receipt_recorded'")
    db("DELETE FROM shipments WHERE order_id=998100")
    db("SET FOREIGN_KEY_CHECKS=0")
    db("DELETE FROM order_items WHERE order_id=998100")
    db("DELETE FROM orders WHERE id=998100")
    db("SET FOREIGN_KEY_CHECKS=1")
    db("INSERT INTO orders (id, order_no, agent_id, konsumen_id, status, payment_status, dp_amount, paid_amount, "
       "remaining_amount, subtotal_amount, total_amount, recipient_name_snapshot, recipient_phone_snapshot, "
       "address_snapshot, created_at, updated_at) "
       "VALUES (998100,'GLM-RCPT2-1',10,13,'terkirim','paid',0,0,0,0,0,'x','0812','jl',NOW(),NOW())")
    db("INSERT INTO order_items (id, order_id, product_id, stock_source, product_name_snapshot, "
       "unit_price_snapshot, subtotal_snapshot, original_quantity, fulfilled_quantity, status, shipment_id, "
       "created_at, updated_at) VALUES "
       "(998101,998100,1,'agent','P',1000,3000,3,3,'terkirim',NULL,NOW(),NOW()),"
       "(998103,998100,1,'agent','P',1000,1000,1,1,'dikirim',NULL,NOW(),NOW())")
    db("INSERT INTO shipments (id, order_id, courier_id, status, delivery_mode, created_at, updated_at) "
       "VALUES (998102, 998100, 902, 'delivered', 'standard', NOW(), NOW())")
    db("UPDATE order_items SET shipment_id=998102 WHERE id IN (998101,998103)")


def scenario(base):
    out = {}
    admin = f.client_for(base, ADMIN)
    super_c = f.client_for(base, SUPER)
    agen = f.client_for(base, AGEN)
    koord = f.client_for(base, KOORD)
    path = "/api/v1/order-items/998101/receipts"

    out["rbac_agen"] = agen.call("GET", path)
    out["rbac_koord"] = koord.call("GET", path)
    out["index_empty"] = admin.call("GET", path)
    out["missing_item"] = admin.call("GET", "/api/v1/order-items/999999/receipts")

    out["store_short_key"] = admin.call("POST", path, {"quantity": 1, "received_on": "2026-11-01"})
    out["store_no_key"] = admin.call("POST", path, {"quantity": 1, "received_on": "2026-11-01"})
    out["store_missing_qty"] = admin.call("POST", path, {"received_on": "2026-11-01"},
                                            extra={"Idempotency-Key": "GLM-IR-K0"})
    out["store_bad_date"] = admin.call("POST", path, {"quantity": 1, "received_on": "01-11-2026"},
                                         extra={"Idempotency-Key": "GLM-IR-K1"})
    out["store_not_arrived"] = admin.call("POST", "/api/v1/order-items/998103/receipts",
                                            {"quantity": 1, "received_on": "2026-11-01"},
                                            extra={"Idempotency-Key": "GLM-IR-K2"})
    out["store_over_quantity"] = admin.call("POST", path, {"quantity": 4, "received_on": "2026-11-01"},
                                              extra={"Idempotency-Key": "GLM-IR-K3"})
    out["store_ok"] = admin.call("POST", path, {"quantity": 2, "received_on": "2026-11-01"},
                                  extra={"Idempotency-Key": "GLM-IR-K4"})
    out["store_replay"] = admin.call("POST", path, {"quantity": 2, "received_on": "2026-11-01"},
                                      extra={"Idempotency-Key": "GLM-IR-K4"})
    out["store_conflict"] = admin.call("POST", path, {"quantity": 1, "received_on": "2026-11-02"},
                                         extra={"Idempotency-Key": "GLM-IR-K4"})
    out["store_second"] = admin.call("POST", path, {"quantity": 1, "received_on": "2026-11-02"},
                                      extra={"Idempotency-Key": "GLM-IR-K5"})
    out["index_after"] = admin.call("GET", path)
    out["super_index"] = super_c.call("GET", path)
    return out


def main():
    f.seed_full()
    seed_dispatch()
    seed_receipts()
    lar = scenario(LARAVEL)
    f.seed_full()
    seed_dispatch()
    seed_receipts()
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
    print(f"item_receipts: {total - failed} passed, {failed} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
