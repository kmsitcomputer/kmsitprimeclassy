#!/usr/bin/env python3
"""GLM differential harness — DELIVERY VERIFICATIONS (2 Laravel-only routes).

  GET  /api/v1/shipments/{shipment}/delivery-verifications  append-only history (admin/super_admin)
  POST /api/v1/shipments/{shipment}/delivery-verifications  record outcome (received/not_received/return)

Covers: RBAC (agen/koordinator 404 model-binding), missing shipment 404, validation
(outcome required/in, note max), Idempotency-Key header required/invalid, not_delivered
gate, success + history, append-only second record, idempotent replay, 409 key conflict,
audit rows, DB state.

Usage: python3 tools/parity/compare_delivery_verifications.py
"""
import json
import re
import sys

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, db, norm_body
from compare_dispatch import seed_dispatch
from compare_users import COORD, AGEN, ADMIN, SUPER

TS = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+Z|\+00:00)?$")


def scrub(v):
    if isinstance(v, str) and TS.match(v):
        return "<ts>"
    if isinstance(v, tuple):
        return [scrub(x) for x in v]
    if isinstance(v, list):
        return sorted((scrub(x) for x in v), key=lambda d: json.dumps(d, sort_keys=True)) if v and all(isinstance(i, dict) for i in v) else [scrub(x) for x in v]
    if isinstance(v, dict):
        return {k: ("<id>" if k in ("id", "shipment_id", "verified_by") and isinstance(x, int) else scrub(x))
                for k, x in v.items()}
    return v


def seed_dv():
    db("DELETE FROM delivery_verifications WHERE shipment_id IN (995002,995003)")
    db("DELETE FROM activity_logs WHERE event='shipment.delivery_verified'")
    db("DELETE FROM shipments WHERE order_id=995001")
    db("SET FOREIGN_KEY_CHECKS=0")
    db("DELETE FROM order_items WHERE id IN (995001,995003)")
    db("DELETE FROM orders WHERE id=995001")
    db("SET FOREIGN_KEY_CHECKS=1")
    db("INSERT INTO orders (id, order_no, agent_id, konsumen_id, status, payment_status, dp_amount, paid_amount, "
       "remaining_amount, subtotal_amount, total_amount, recipient_name_snapshot, recipient_phone_snapshot, "
       "address_snapshot, created_at, updated_at) "
       "VALUES (995001,'GLM-DV-1',10,13,'terkirim','paid',0,0,0,0,0,'x','0812','jl',NOW(),NOW())")
    db("INSERT INTO order_items (id, order_id, product_id, stock_source, product_name_snapshot, "
       "unit_price_snapshot, subtotal_snapshot, original_quantity, fulfilled_quantity, status, shipment_id, "
       "created_at, updated_at) VALUES "
       "(995001,995001,1,'agent','P',1000,2000,2,2,'terkirim',NULL,NOW(),NOW()),"
       "(995003,995001,1,'agent','P',1000,1000,1,1,'terkirim',NULL,NOW(),NOW())")
    db("INSERT INTO shipments (id, order_id, courier_id, status, delivery_mode, delivered_at, created_at, updated_at) "
       "VALUES (995002, 995001, 902, 'delivered', 'standard', NOW(), NOW(), NOW())")
    db("INSERT INTO shipments (id, order_id, courier_id, status, delivery_mode, created_at, updated_at) "
       "VALUES (995003, 995001, 902, 'pending', 'standard', NOW(), NOW())")
    db("UPDATE order_items SET shipment_id=995002 WHERE id=995001")
    db("UPDATE order_items SET shipment_id=995003 WHERE id=995003")


def store(client, shipment=995002, outcome="received", note=None, key="GLM-K-9"):
    body = {"outcome": outcome}
    if note is not None:
        body["note"] = note
    return client.call("POST", f"/api/v1/shipments/{shipment}/delivery-verifications", body,
                       extra={"Idempotency-Key": key})


def scenario(base):
    out = {}
    admin = f.client_for(base, ADMIN)
    super_c = f.client_for(base, SUPER)
    agen = f.client_for(base, AGEN)
    koord = f.client_for(base, COORD)

    out["rbac_agen_list"] = agen.call("GET", "/api/v1/shipments/995002/delivery-verifications")
    out["rbac_koord_list"] = koord.call("GET", "/api/v1/shipments/995002/delivery-verifications")
    out["rbac_agen_store"] = store(agen, key="GLM-K-R")
    out["missing_list"] = admin.call("GET", "/api/v1/shipments/999999/delivery-verifications")
    out["missing_store"] = store(admin, shipment=999999, key="GLM-K-M")
    out["empty_history"] = admin.call("GET", "/api/v1/shipments/995002/delivery-verifications")

    out["store_no_key"] = admin.call("POST", "/api/v1/shipments/995002/delivery-verifications", {"outcome": "received"})
    out["store_key_too_long"] = admin.call("POST", "/api/v1/shipments/995002/delivery-verifications", {"outcome": "received"},
                                           extra={"Idempotency-Key": "K" * 101})
    out["store_invalid_outcome"] = store(admin, outcome="bogus", key="GLM-K-0")
    out["store_missing_outcome"] = admin.call("POST", "/api/v1/shipments/995002/delivery-verifications", {},
                                              extra={"Idempotency-Key": "GLM-K-00"})
    out["store_note_too_long"] = store(admin, outcome="received", note="x" * 2001, key="GLM-K-000")
    out["store_not_delivered"] = store(admin, shipment=995003, outcome="received", key="GLM-K-1")

    out["store_ok"] = store(admin, outcome="received", note="diterima baik", key="GLM-K-2")
    out["history_after"] = admin.call("GET", "/api/v1/shipments/995002/delivery-verifications")
    out["store_second"] = store(admin, outcome="not_received", key="GLM-K-3")
    out["store_replay"] = store(admin, outcome="received", note="diterima baik", key="GLM-K-2")
    out["store_conflict"] = store(admin, outcome="return", key="GLM-K-2")
    out["db_rows"] = db("SELECT outcome, note FROM delivery_verifications WHERE shipment_id=995002 ORDER BY id")
    out["audit"] = db("SELECT event, description FROM activity_logs WHERE event='shipment.delivery_verified' ORDER BY id")
    out["super_list"] = super_c.call("GET", "/api/v1/shipments/995002/delivery-verifications")
    return out


def main():
    f.seed_full()
    seed_dispatch()
    seed_dv()
    lar = scenario(LARAVEL)
    f.seed_full()
    seed_dispatch()
    seed_dv()
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
    print(f"delivery_verifications: {total - failed} passed, {failed} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
