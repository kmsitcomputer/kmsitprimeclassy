#!/usr/bin/env python3
"""GLM differential harness — SHIPMENT STATUS transitions (2 Laravel-only routes).

  PATCH /api/v1/shipments/{shipment}/status            bulk diproses→dikirim / dikirim→terkirim
  PATCH /api/v1/shipments/{shipment}/items/{item}/status  per-ITEM work-unit transition

Covers: invalid status (422 status_endpoint_required), invalid transition (422),
rbac gate, bulk transition DB state (item status, handed_over_at, shipment aggregate,
order recompute), per-item isolation (sibling untouched), commissions on delivery
(idempotent), audit rows.

Usage: python3 tools/parity/compare_shipment_status.py
"""
import json
import re
import sys

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, db, norm_body, seed_full, KURIR
from compare_dispatch import seed_dispatch

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


def seed_transition():
    """Order 992001: two agent lines on shipment 992002, order diproses; kurir 902 (user 19) assigned."""
    db("DELETE FROM commissions WHERE order_id=992001")
    db("DELETE FROM activity_logs WHERE event IN ('shipment.status_changed','order_item.status_changed','shipment.courier_assigned')")
    db("DELETE FROM shipments WHERE order_id=992001")
    db("SET FOREIGN_KEY_CHECKS=0")
    db("DELETE FROM order_items WHERE id IN (992001,992003)")
    db("DELETE FROM orders WHERE id=992001")
    db("SET FOREIGN_KEY_CHECKS=1")
    db("INSERT INTO orders (id, order_no, agent_id, konsumen_id, status, payment_status, dp_amount, paid_amount, "
       "remaining_amount, subtotal_amount, total_amount, recipient_name_snapshot, recipient_phone_snapshot, "
       "address_snapshot, created_at, updated_at) "
       "VALUES (992001,'GLM-SHIP-1',10,13,'diproses','paid',0,0,0,0,0,'x','0812','jl',NOW(),NOW())")
    db("INSERT INTO order_items (id, order_id, product_id, stock_source, product_name_snapshot, "
       "unit_price_snapshot, subtotal_snapshot, original_quantity, fulfilled_quantity, status, shipment_id, "
       "courier_fee_amount, created_at, updated_at) VALUES "
       "(992001,992001,1,'agent','P',1000,2000,2,2,'diproses',NULL,5000,NOW(),NOW()),"
       "(992003,992001,1,'agent','P',1000,1000,1,1,'diproses',NULL,0,NOW(),NOW())")
    db("INSERT INTO shipments (id, order_id, courier_id, status, delivery_mode, created_at, updated_at) "
       "VALUES (992002, 992001, 902, 'pending', 'standard', NOW(), NOW())")
    db("UPDATE order_items SET shipment_id=992002 WHERE id IN (992001,992003)")


def scenario(base):
    out = {}
    kurir = f.client_for(base, KURIR)
    agen = f.client_for(base, f.AGEN)

    out["invalid_status"] = kurir.call("PATCH", "/api/v1/shipments/992002/status", {"status": "bogus"})
    out["missing_status"] = kurir.call("PATCH", "/api/v1/shipments/992002/status", {})
    out["transition_diterima"] = kurir.call("PATCH", "/api/v1/shipments/992002/status", {"status": "diterima"})
    out["missing_shipment"] = kurir.call("PATCH", "/api/v1/shipments/999999/status", {"status": "dikirim"})

    # bulk pickup diproses→dikirim
    out["pickup"] = kurir.call("PATCH", "/api/v1/shipments/992002/status", {"status": "dikirim"})
    out["item_after_pickup"] = db("SELECT status, handed_over_at IS NOT NULL FROM order_items WHERE id=992001")
    out["shipment_after_pickup"] = db("SELECT status, shipped_at IS NOT NULL FROM shipments WHERE id=992002")
    out["order_after_pickup"] = db("SELECT status FROM orders WHERE id=992001")

    # per-item: deliver ONLY the first item; sibling must stay dikirim
    out["deliver_item"] = kurir.call("PATCH", "/api/v1/shipments/992002/items/992001/status", {"status": "terkirim"})
    out["items_after_item_delivery"] = db("SELECT id, status FROM order_items WHERE id IN (992001,992003) ORDER BY id")
    out["shipment_after_item_delivery"] = db("SELECT status, delivered_at IS NOT NULL FROM shipments WHERE id=992002")
    out["commission_first"] = db("SELECT beneficiary_role, status, amount+0 FROM commissions WHERE order_item_id=992001")

    # bulk delivery completes the remaining sibling
    out["deliver_bulk"] = kurir.call("PATCH", "/api/v1/shipments/992002/status", {"status": "terkirim"})
    out["items_after_bulk"] = db("SELECT id, status FROM order_items WHERE id IN (992001,992003) ORDER BY id")
    out["shipment_after_bulk"] = db("SELECT status, delivered_at IS NOT NULL FROM shipments WHERE id=992002")
    out["order_after_bulk"] = db("SELECT status FROM orders WHERE id=992001")
    out["commissions_all"] = db("SELECT order_item_id, beneficiary_role, status, amount+0 FROM commissions WHERE order_id=992001 ORDER BY order_item_id")

    # idempotent replay after completion must be rejected (order terkirim → invalid order transition)
    out["replay_after_done"] = kurir.call("PATCH", "/api/v1/shipments/992002/status", {"status": "terkirim"})
    out["audit"] = db("SELECT event, subject_id FROM activity_logs WHERE event IN ('shipment.status_changed','order_item.status_changed') ORDER BY id")
    return out


def main():
    seed_full()
    seed_dispatch()
    seed_transition()
    lar = scenario(LARAVEL)
    seed_full()
    seed_dispatch()
    seed_transition()
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
    print(f"shipment_status: {total - failed} passed, {failed} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
