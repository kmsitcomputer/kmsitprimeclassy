#!/usr/bin/env python3
"""GLM differential harness — COURIER ASSIGNMENT (2 Laravel-only routes).

  PATCH /api/v1/shipments/{shipment}/courier            koordinator dispatch assignment
  GET  /api/v1/shipments/{shipment}/eligible-couriers   per-shipment assignable couriers

Covers: RBAC (koordinator-only; others 403), missing shipment (404 route-not-found),
validation (missing courier_id), successful assignment (DB state + audit), idempotent
replay, already-assigned refusal, self-executor sentinel (courier_id=0), eligibility
listing (branch couriers + eligible SKS executors, read-only), assigned→empty list,
unaccepted-lines carve (split shipment).

Usage: python3 tools/parity/compare_courier_assign.py
"""
import json
import sys

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, db, norm_body
from compare_dispatch import seed_dispatch
from compare_users import COORD, AGEN, ADMIN, KURIR

results = []


def seed_assign():
    db("DELETE FROM commissions WHERE order_id=993001")
    db("DELETE FROM activity_logs WHERE event IN ('shipment.courier_assigned','shipment.courier_profile_resolved','shipment.split_for_unaccepted')")
    db("DELETE FROM stock_request_items WHERE order_item_id IN (993001,993003)")
    db("DELETE FROM stock_requests WHERE order_id=993001")
    db("DELETE FROM shipments WHERE order_id=993001")
    db("DELETE FROM couriers WHERE user_id=(SELECT id FROM users WHERE email='koordinator@imp010.test')")
    db("SET FOREIGN_KEY_CHECKS=0")
    db("DELETE FROM order_items WHERE id IN (993001,993003)")
    db("DELETE FROM orders WHERE id=993001")
    db("DELETE FROM couriers WHERE id=993004")
    db("SET FOREIGN_KEY_CHECKS=1")
    db("INSERT INTO orders (id, order_no, agent_id, konsumen_id, status, payment_status, dp_amount, paid_amount, "
       "remaining_amount, subtotal_amount, total_amount, recipient_name_snapshot, recipient_phone_snapshot, "
       "address_snapshot, created_at, updated_at) "
       "VALUES (993001,'GLM-ASSIGN-1',10,13,'diproses','paid',0,0,0,0,0,'x','0812','jl',NOW(),NOW())")
    db("INSERT INTO order_items (id, order_id, product_id, stock_source, product_name_snapshot, "
       "unit_price_snapshot, subtotal_snapshot, original_quantity, fulfilled_quantity, status, shipment_id, "
       "created_at, updated_at) VALUES "
       "(993001,993001,1,'agent','P',1000,2000,2,2,'diproses',NULL,NOW(),NOW()),"
       "(993003,993001,1,'agent','P',1000,1000,1,1,'diproses',NULL,NOW(),NOW())")
    db("INSERT INTO shipments (id, order_id, courier_id, status, delivery_mode, created_at, updated_at) "
       "VALUES (993002, 993001, NULL, 'pending', 'standard', NOW(), NOW())")
    db("UPDATE order_items SET shipment_id=993002 WHERE id IN (993001,993003)")
    # Accepted receipt coverage for line 993001 only (993003 wholly unaccepted → carved).
    db("INSERT INTO stock_requests (order_id, agent_id, request_number, status, created_at, updated_at) "
       "VALUES (993001, 10, 'GLM-SR-993001', 'fulfilled', NOW(), NOW())")
    rid = db("SELECT id FROM stock_requests WHERE order_id=993001 ORDER BY id DESC LIMIT 1")[0][0]
    db(f"INSERT INTO stock_request_items (stock_request_id, order_item_id, product_id, requested_qty, fulfilled_qty, "
       f"remaining_qty, created_at, updated_at) VALUES ({rid},993001,1,2,2,0,NOW(),NOW())")
    # A second profile-less branch courier for the eligible list / already-assigned path.
    db("INSERT INTO couriers (id, type, user_id, agent_id, external_code, name, is_active, created_at, updated_at) "
       "VALUES (993004,'internal',NULL,10,'GLMA1','Kurir GLM Assign',1,NOW(),NOW())")


def status_only(resp):
    if isinstance(resp, tuple):
        st, body = resp[0], resp[1]
        return [st, (body or {}).get("message") if isinstance(body, dict) else body]
    return resp


def scenario(base):
    out = {}
    koord = f.client_for(base, COORD)
    agen = f.client_for(base, AGEN)
    admin_c = f.client_for(base, ADMIN)
    kurir = f.client_for(base, KURIR)

    # RBAC + 404 + validation
    out["rbac_agen"] = status_only(agen.call("PATCH", "/api/v1/shipments/993002/courier", {"courier_id": 902}))
    out["rbac_admin"] = status_only(admin_c.call("PATCH", "/api/v1/shipments/993002/courier", {"courier_id": 902}))
    out["rbac_kurir"] = status_only(kurir.call("PATCH", "/api/v1/shipments/993002/courier", {"courier_id": 902}))
    out["rbac_agen_eligible"] = status_only(agen.call("GET", "/api/v1/shipments/993002/eligible-couriers"))
    out["missing_shipment"] = status_only(koord.call("PATCH", "/api/v1/shipments/999999/courier", {"courier_id": 902}))
    out["missing_shipment_eligible"] = status_only(koord.call("GET", "/api/v1/shipments/999999/eligible-couriers"))
    out["missing_body"] = status_only(koord.call("PATCH", "/api/v1/shipments/993002/courier", {}))
    out["missing_courier_row"] = status_only(koord.call("PATCH", "/api/v1/shipments/993002/courier", {"courier_id": 424242}))

    # Eligible list before assignment (branch couriers; SKS candidates of THIS order only)
    out["eligible_before"] = koord.call("GET", "/api/v1/shipments/993002/eligible-couriers")

    # Assignment of the profile-less courier 993004 (no user) succeeds
    out["assign_profileless"] = status_only(koord.call("PATCH", "/api/v1/shipments/993002/courier", {"courier_id": 993004}))
    out["shipment_after_assign"] = db("SELECT courier_id FROM shipments WHERE id=993002")
    out["audit_assign"] = db("SELECT event FROM activity_logs WHERE event='shipment.courier_assigned' ORDER BY id")

    # Idempotent replay
    out["assign_replay"] = status_only(koord.call("PATCH", "/api/v1/shipments/993002/courier", {"courier_id": 993004}))
    out["audit_replay"] = db("SELECT COUNT(*) FROM activity_logs WHERE event='shipment.courier_assigned'")

    # Reassign to a DIFFERENT courier -> already_assigned
    out["already_assigned"] = status_only(koord.call("PATCH", "/api/v1/shipments/993002/courier", {"courier_id": 902}))

    # Eligible list after assignment (assigned shipment -> [])
    out["eligible_after"] = koord.call("GET", "/api/v1/shipments/993002/eligible-couriers")

    # Self-executor sentinel on a fresh auto-id unassigned shipment
    db("INSERT INTO shipments (order_id, courier_id, status, delivery_mode, created_at, updated_at) "
       "VALUES (993001, NULL, 'pending', 'standard', NOW(), NOW())")
    db("DELETE sh FROM shipments sh LEFT JOIN order_items i ON i.shipment_id = sh.id WHERE sh.order_id=993001 AND i.id IS NULL")
    sid = db("SELECT id FROM shipments WHERE order_id=993001 AND courier_id IS NULL ORDER BY id DESC LIMIT 1")[0][0]
    out["self_sentinel"] = status_only(koord.call("PATCH", f"/api/v1/shipments/{sid}/courier", {"courier_id": 0}))
    out["self_sentinel_shipment"] = db("SELECT sh.courier_id, c.user_id FROM shipments sh JOIN couriers c ON c.id=sh.courier_id WHERE sh.id=" + str(sid))
    out["items_after_carve"] = db("SELECT id, shipment_id IS NOT NULL FROM order_items WHERE id IN (993001,993003) ORDER BY id")
    out["audit_all"] = db("SELECT event FROM activity_logs WHERE event LIKE 'shipment.courier%' ORDER BY id")
    return out


def scrub(v):
    if isinstance(v, tuple):
        return [scrub(x) for x in v]
    if isinstance(v, list):
        return sorted((scrub(x) for x in v), key=lambda d: json.dumps(d, sort_keys=True)) if v and all(isinstance(i, dict) for i in v) else [scrub(x) for x in v]
    if isinstance(v, dict):
        return {k: ("<id>" if k in ("id", "courier_id", "user_id") and isinstance(x, int) else scrub(x))
                for k, x in v.items()}
    return v


def main():
    seed_full_ok = True
    try:
        f.seed_full()
    except Exception:
        seed_full_ok = False
    seed_dispatch()
    seed_assign()
    lar = scenario(LARAVEL)
    f.seed_full()
    seed_dispatch()
    seed_assign()
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
    print(f"courier_assign: {total - failed} passed, {failed} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
