#!/usr/bin/env python3
"""GLM differential harness — DELIVERY-REFUSAL write paths (3 Laravel-only routes).

  POST /api/v1/delivery-refusals/{refusal}/hand-back   shipment executor custody transfer (idempotent)
  POST /api/v1/delivery-refusals/{refusal}/receive     koordinator receipt (idempotent)
  POST /api/v1/delivery-refusals/{refusal}/disposition koordinator redelivery / return_to_warehouse

RBAC (executor-only hand-back, koordinator-only receive/dispose), state machine
(with_courier → handed_back → received → redelivery|returned_to_warehouse), idempotent replay,
payload shape {id,status,quantity,order_item_id,shipment_id}, audit rows, DB state.

Usage: python3 tools/parity/compare_refusals.py
"""
import json
import re
import sys

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, db, norm_body, seed_full, KURIR
from compare_dispatch import seed_dispatch
KOORD = "koordinator@imp010.test"

TS = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{6}Z|\+00:00)$")

results = []


def scrub(v):
    if isinstance(v, tuple):
        return [scrub(x) for x in v]
    if isinstance(v, list):
        return sorted((scrub(x) for x in v), key=lambda d: json.dumps(d, sort_keys=True)) if v and all(isinstance(i, dict) for i in v) else [scrub(x) for x in v]
    if isinstance(v, dict):
        return {k: ("<id>" if k in ("id", "user_id", "agent_id", "order_id", "order_item_id", "shipment_id") and isinstance(x, int) else scrub(x))
                for k, x in v.items()}
    if isinstance(v, str) and TS.match(v):
        return "<ts>"
    return v


def seed_refusal():
    """One refused line with an assigned kurir (user 19 owns courier 902), agent-owned product line."""
    db("DELETE FROM activity_logs WHERE event LIKE 'delivery_refusal%' OR event LIKE 'shipment.refusal%'")
    db("DELETE FROM delivery_refusals")
    db("DELETE FROM shipments WHERE order_id=990001")
    db("UPDATE order_items SET shipment_id=NULL, status='diterima', handed_over_at=NULL WHERE id=991001")
    db("SET FOREIGN_KEY_CHECKS=0")
    db("DELETE FROM order_items WHERE id=991001")
    db("DELETE FROM orders WHERE id=990001")
    db("INSERT INTO orders (id, order_no, agent_id, konsumen_id, status, payment_status, dp_amount, paid_amount, "
       "remaining_amount, subtotal_amount, total_amount, recipient_name_snapshot, recipient_phone_snapshot, "
       "address_snapshot, created_at, updated_at) "
       "VALUES (990001,'GLM-REF-1',10,13,'dikirim','paid',0,0,0,0,0,'x','0812','jl',NOW(),NOW())")
    db("INSERT INTO order_items (id, order_id, product_id, stock_source, product_name_snapshot, "
       "unit_price_snapshot, subtotal_snapshot, original_quantity, fulfilled_quantity, status, shipment_id, "
       "created_at, updated_at) VALUES (991001,990001,1,'agent','P',1000,2000,2,2,'dikirim',NULL,NOW(),NOW())")
    db("SET FOREIGN_KEY_CHECKS=1")
    db("INSERT INTO shipments (id, order_id, courier_id, status, delivery_mode, created_at, updated_at) "
       "VALUES (991002, 990001, 902, 'in_transit', 'standard', NOW(), NOW())")
    db("UPDATE order_items SET shipment_id=991002 WHERE id=991001")
    db("INSERT INTO delivery_refusals (id, agent_id, order_id, order_item_id, shipment_id, quantity, reason, "
       "status, recorded_by, recorded_at, created_at, updated_at) "
       "VALUES (991003, 10, 990001, 991001, 991002, 2, 'alamat salah', 'with_courier', 15, NOW(), NOW(), NOW())")


def scenario(base):
    out = {}
    koord = f.client_for(base, KOORD)
    kurir = f.client_for(base, KURIR)
    agen = f.client_for(base, f.AGEN)

    out["rbac_agen_handback"] = agen.call("POST", "/api/v1/delivery-refusals/991003/hand-back")
    out["rbac_agen_receive"] = agen.call("POST", "/api/v1/delivery-refusals/991003/receive")
    out["rbac_agen_dispose"] = agen.call("POST", "/api/v1/delivery-refusals/991003/disposition", {"action": "redelivery"})
    out["handback"] = kurir.call("POST", "/api/v1/delivery-refusals/991003/hand-back")
    out["handback_replay"] = kurir.call("POST", "/api/v1/delivery-refusals/991003/hand-back")
    out["receive_wrong_role"] = kurir.call("POST", "/api/v1/delivery-refusals/991003/receive")
    out["receive"] = koord.call("POST", "/api/v1/delivery-refusals/991003/receive")
    out["receive_replay"] = koord.call("POST", "/api/v1/delivery-refusals/991003/receive")
    out["dispose_validation"] = koord.call("POST", "/api/v1/delivery-refusals/991003/disposition", {"action": "bogus"})
    out["dispose"] = koord.call("POST", "/api/v1/delivery-refusals/991003/disposition",
                                {"action": "return_to_warehouse", "note": "kembali ke gudang"})
    out["dispose_replay"] = koord.call("POST", "/api/v1/delivery-refusals/991003/disposition",
                                       {"action": "return_to_warehouse"})
    out["item_after"] = db("SELECT status, shipment_id IS NOT NULL, handed_over_at IS NULL FROM order_items WHERE id=991001")
    out["refusal_after"] = db("SELECT status, disposition_note IS NOT NULL FROM delivery_refusals WHERE id=991003")
    out["audit"] = db("SELECT event FROM activity_logs WHERE event LIKE 'delivery_refusal%' ORDER BY id")
    return out


def main():
    seed_full()
    seed_dispatch()
    seed_refusal()
    lar = scenario(LARAVEL)
    seed_full()
    seed_dispatch()
    seed_refusal()
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
            print("  L=" + json.dumps(lv)[:700])
            print("  G=" + json.dumps(gv)[:700])
    print(f"refusals: {total - failed} passed, {failed} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())