#!/usr/bin/env python3
"""GLM takeover harness — the 3 remaining PARTIAL routes.

  A. POST /api/v1/auth/register            WhatsApp ledger enqueue (gated by wa.enabled) + no-row-when-off
  B. GET  /api/v1/sub-stock/my             reserved derived from ACTIVE sub_stock_reservations (needs order_items)
  C. POST /api/v1/warehouse/opnames        pending_catalog_change published by the approve commit

Per-runtime pass with a full reset between them; DB state compared after each scenario.

Usage: python3 tools/parity/compare_partials_final.py
"""
import json
import re
import sys
import uuid

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, client_for, db, seed_full, ADMIN

TS = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{6}Z|\+00:00)$")

results = []


def scrub(v):
    if isinstance(v, tuple):
        return [scrub(x) for x in v]
    if isinstance(v, list):
        return sorted((scrub(x) for x in v), key=lambda d: json.dumps(d, sort_keys=True)) if v and all(isinstance(i, dict) for i in v) else [scrub(x) for x in v]
    if isinstance(v, dict):
        return {k: ("<id>" if k in ("id", "user_id", "agent_id", "order_id", "order_item_id", "product_id",
                                   "product_variation_id", "sub_location_id", "stock_opname_id", "parent_id") and isinstance(x, int) else scrub(x))
                for k, x in v.items()}
    if isinstance(v, str) and TS.match(v):
        return "<ts>"
    if isinstance(v, str) and re.match(r"^OPN-\d{14}-[A-Z0-9]{5}$", v):
        return "<opname_number>"
    return v


def clear_orders():
    db("UPDATE order_items SET split_from_order_item_id=NULL")
    db("DELETE FROM sub_stock_reservations")
    db("DELETE FROM order_items")
    db("DELETE FROM orders")


def scenario_register(base):
    """A: register twice — gateway OFF (no ledger row), then ON (ledger row, idempotent key)."""
    out = {}
    db("DELETE FROM whatsapp_messages WHERE event='account_registered'")
    db("DELETE FROM settings WHERE `key`='wa.enabled'")
    c = f.Client(base)
    suffix = uuid.uuid4().hex[:8]
    body = {"name": "GLM Reg " + suffix, "email": f"glmreg-{suffix}@imp010.test",
            "phone": "0819" + suffix[:8], "password": "Secret123!", "password_confirmation": "Secret123!"}
    st, resp = c.call("POST", "/api/v1/auth/register", body)
    out["register_off"] = (st, resp)
    uid = db(f"SELECT id FROM users WHERE email='glmreg-{suffix}@imp010.test'")
    out["wa_off"] = db(f"SELECT COUNT(*) FROM whatsapp_messages WHERE event='account_registered' "
                       f"AND recipient_user_id={uid[0][0]}") if uid else "no-user"

    db("INSERT INTO settings (`group`, `key`, value, created_at, updated_at) "
       "VALUES ('integration.whatsapp','wa.enabled','1',NOW(),NOW()) ON DUPLICATE KEY UPDATE value='1'")
    suffix2 = uuid.uuid4().hex[:8]
    body2 = {"name": "GLM Reg " + suffix2, "email": f"glmreg-{suffix2}@imp010.test",
             "phone": "0819" + suffix2[:8], "password": "Secret123!", "password_confirmation": "Secret123!"}
    st2, resp2 = c.call("POST", "/api/v1/auth/register", body2)
    out["register_on"] = (st2, resp2)
    uid2 = db(f"SELECT id FROM users WHERE email='glmreg-{suffix2}@imp010.test'")
    out["wa_on"] = db(f"SELECT idempotency_key,purpose,event,template_class,recipient_role,agent_id,subject_type,"
                      f"subject_id,recipient_user_id,recipient_phone,status,max_attempts,LEFT(body,40) "
                      f"FROM whatsapp_messages WHERE event='account_registered' AND recipient_user_id={uid2[0][0]}") if uid2 else []
    out["wa_dup_free"] = db("SELECT COUNT(*) FROM whatsapp_messages WHERE event='account_registered' GROUP BY recipient_user_id "
                            "HAVING COUNT(*) > 1")
    db("DELETE FROM whatsapp_messages WHERE event='account_registered'")
    db("DELETE FROM settings WHERE `key`='wa.enabled'")
    return out


def scenario_substock(base):
    """B: active reservations reduce reserved; released does not count."""
    out = {}
    clear_orders()
    db("SET FOREIGN_KEY_CHECKS=0")
    db("INSERT INTO orders (id, order_no, agent_id, konsumen_id, status, payment_status, dp_amount, paid_amount, "
       "remaining_amount, subtotal_amount, total_amount, recipient_name_snapshot, recipient_phone_snapshot, "
       "address_snapshot, created_at, updated_at) "
       "VALUES (990001,'GLM-RES-1',10,13,'diterima','paid',0,0,0,0,0,'x','0812','jl',NOW(),NOW())")
    db("INSERT INTO order_items (id, order_id, product_id, stock_source, product_name_snapshot, "
       "unit_price_snapshot, subtotal_snapshot, original_quantity, fulfilled_quantity, status, created_at, updated_at) "
       "VALUES (991001,990001,1,'agent','P',1000,2000,2,2,'diterima',NOW(),NOW())")
    db("INSERT INTO order_items (id, order_id, product_id, product_variation_id, stock_source, product_name_snapshot, "
       "unit_price_snapshot, subtotal_snapshot, original_quantity, fulfilled_quantity, status, created_at, updated_at) "
       "VALUES (991002,990001,1,1,'agent','P v1',1000,2000,2,2,'diterima',NOW(),NOW())")
    db("SET FOREIGN_KEY_CHECKS=1")
    db("INSERT INTO sub_stock_reservations (agent_id, sub_location_id, order_item_id, product_id, quantity, status, created_at, updated_at) "
       "VALUES (10,3,991001,1,3,'active',NOW(),NOW())")
    db("INSERT INTO sub_stock_reservations (agent_id, sub_location_id, order_item_id, product_id, product_variation_id, quantity, status, created_at, updated_at) "
       "VALUES (10,3,991002,NULL,1,2,'active',NOW(),NOW())")
    cs = f.client_for(base, "sub@imp010.test")
    st, resp = cs.call("GET", "/api/v1/sub-stock/my")
    out["substock_my"] = (st, resp)
    return out


def scenario_opname(base):
    """C: approve publishes the staged pending_catalog_change and stamps catalog_published_at."""
    out = {}
    db("DELETE FROM stock_movements WHERE opname_id IS NOT NULL")
    db("DELETE FROM warehouse_stock_revisions")
    db("DELETE FROM stock_opname_items")
    db("DELETE FROM stock_opnames")
    gudang = f.client_for(base, "gudang@imp010.test")
    admin_c = f.client_for(base, ADMIN)
    st, op = gudang.call("POST", "/api/v1/warehouse/opnames",
                         {"opname_type": "physical_opname", "stock_type": "transit",
                          "items": [{"product_id": 1}]})
    out["store"] = (st, op)
    if st != 201:
        return out
    oid = op["data"]["id"]
    gudang.call("PATCH", f"/api/v1/warehouse/opnames/{oid}/count",
                {"items": [{"product_id": 1, "counted_quantity": 5}]})
    out["submit_status"] = gudang.call("POST", f"/api/v1/warehouse/opnames/{oid}/submit")[0]
    # stage a withheld catalog change on the item (normally written by POST /catalog/imports)
    db(f"UPDATE stock_opname_items SET pending_catalog_change="
       f"'{{\\\"kind\\\":\\\"product\\\",\\\"id\\\":1,\\\"changes\\\":{{\\\"price\\\":55555,\\\"status\\\":\\\"active\\\"}},\\\"attributes\\\":null}}' "
       f"WHERE stock_opname_id={oid}")
    st, appr = admin_c.call("POST", f"/api/v1/warehouse/opnames/{oid}/approve")
    out["approve"] = (st, appr)
    out["product_after"] = db("SELECT id, base_price, status FROM products WHERE id=1")
    out["published_at"] = db(f"SELECT catalog_published_at IS NOT NULL FROM stock_opname_items WHERE stock_opname_id={oid}")
    out["movements"] = db(f"SELECT type, quantity, stock_type FROM stock_movements WHERE opname_id={oid}")
    return out


def main():
    seed_full()
    lar = {"register": scenario_register(LARAVEL)}
    seed_full()
    go = {"register": scenario_register(GO)}
    seed_full()
    lar["substock"] = scenario_substock(LARAVEL)
    seed_full()
    go["substock"] = scenario_substock(GO)
    seed_full()
    lar["opname"] = scenario_opname(LARAVEL)
    seed_full()
    go["opname"] = scenario_opname(GO)

    failed = 0
    total = 0
    for domain in sorted(lar):
        for key in sorted(lar[domain]):
            total += 1
            name = f"{domain}.{key}"
            lv, gv = scrub(lar[domain][key]), scrub(go[domain][key])
            ok = lv == gv
            if not ok:
                failed += 1
            print(("PASS " if ok else "FAIL ") + name)
            if not ok:
                print("  L=" + json.dumps(lv)[:1200])
                print("  G=" + json.dumps(gv)[:1200])
    print(f"partials-final: {total - failed} passed, {failed} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())