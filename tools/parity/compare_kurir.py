#!/usr/bin/env python3
"""GLM differential harness — KURIR dashboard (6 Laravel-only routes).

  GET   /kurir/orders  /kurir/returns  /kurir/returns/pickup-today  /kurir/reports/delivered
  PATCH /kurir/returns/{item}/pickup  /kurir/returns/{item}/confirm

Usage: python3 tools/parity/compare_kurir.py
"""
import json
import re
import sys

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, db, norm_body
from compare_dispatch import seed_dispatch
from compare_users import KURIR, COORD, AGEN

TS = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+Z|\+00:00)?$")


def scrub(v):
    if isinstance(v, str) and TS.match(v):
        return "<ts>"
    if isinstance(v, tuple):
        return [scrub(x) for x in v]
    if isinstance(v, list):
        return sorted((scrub(x) for x in v), key=lambda d: json.dumps(d, sort_keys=True)) if v and all(isinstance(i, dict) for i in v) else [scrub(x) for x in v]
    if isinstance(v, dict):
        return {k: ("<id>" if k in ("id", "order_item_id", "shipment_id", "order_id", "courier_id") and isinstance(x, int) else scrub(x))
                for k, x in v.items()}
    return v


def seed_kurir():
    db("DELETE FROM activity_logs WHERE event IN ('return_item.picked_up','return_item.courier_confirmed')")
    db("DELETE FROM commissions WHERE order_id IN (999001,999011)")
    db("DELETE FROM return_items WHERE order_item_id IN (999003,999013)")
    db("DELETE FROM returns WHERE order_id IN (999001,999011)")
    db("DELETE FROM shipments WHERE order_id IN (999001,999011)")
    db("SET FOREIGN_KEY_CHECKS=0")
    db("DELETE FROM order_items WHERE order_id IN (999001,999011)")
    db("DELETE FROM orders WHERE id IN (999001,999011)")
    db("SET FOREIGN_KEY_CHECKS=1")
    for oid, no in ((999001, "GLM-KUR-1"), (999011, "GLM-KUR-2")):
        db(f"INSERT INTO orders (id, order_no, agent_id, konsumen_id, status, payment_status, dp_amount, paid_amount, "
           f"remaining_amount, subtotal_amount, total_amount, recipient_name_snapshot, recipient_phone_snapshot, "
           f"address_snapshot, village_snapshot, created_at, updated_at) VALUES ({oid},'{no}',10,13,'dikirim','paid',"
           f"0,0,0,0,0,'Rina','0812','jl x','Desa S',NOW(),NOW())")
    db("INSERT INTO shipments (id, order_id, courier_id, status, delivery_mode, created_at, updated_at) VALUES "
       "(999002, 999001, 902, 'in_transit', 'standard', NOW(), NOW()),"
       "(999012, 999011, 901, 'in_transit', 'standard', NOW(), NOW())")
    db("INSERT INTO order_items (id, order_id, product_id, stock_source, product_name_snapshot, sku_snapshot, "
       "unit_price_snapshot, subtotal_snapshot, original_quantity, fulfilled_quantity, returned_quantity, status, "
       "shipment_id, courier_fee_amount, created_at, updated_at) VALUES "
       "(999003,999001,1,'agent','Produk A','SKU-A',1000,1000,1,1,1,'pengembalian',999002,0,NOW(),NOW()),"
       "(999004,999001,1,'agent','Produk B','SKU-B',1000,2000,2,2,0,'terkirim',999002,3000,NOW(),NOW()),"
       "(999013,999011,1,'agent','Produk C','SKU-C',1000,1000,1,1,1,'pengembalian',999012,0,NOW(),NOW())")
    db("INSERT INTO returns (id, order_id, requested_by, reason, status, created_at, updated_at) VALUES "
       "(999005, 999001, 13, 'rusak', 'approved', NOW(), NOW()),(999015, 999011, 13, 'rusak', 'approved', NOW(), NOW())")
    db("INSERT INTO return_items (return_id, order_item_id, status, quantity_returned, refund_amount, courier_id, "
       "pickup_scheduled_for, created_at, updated_at) VALUES "
       "(999005, 999003, 'approved', 1, 0, 902, CURDATE(), NOW(), NOW()),"
       "(999015, 999013, 'approved', 1, 0, 901, CURDATE(), NOW(), NOW())")


def scenario(base):
    out = {}
    kurir = f.client_for(base, KURIR)
    koord = f.client_for(base, COORD)
    agen = f.client_for(base, AGEN)
    out["rbac_agen_orders"] = agen.call("GET", "/api/v1/kurir/orders")
    out["rbac_koord_returns"] = koord.call("GET", "/api/v1/kurir/returns")
    out["orders"] = kurir.call("GET", "/api/v1/kurir/orders")
    out["orders_terkirim"] = kurir.call("GET", "/api/v1/kurir/orders?status=terkirim")
    out["orders_koord"] = koord.call("GET", "/api/v1/kurir/orders")
    out["returns"] = kurir.call("GET", "/api/v1/kurir/returns")
    out["pickup_today"] = kurir.call("GET", "/api/v1/kurir/returns/pickup-today")
    out["delivered"] = kurir.call("GET", "/api/v1/kurir/reports/delivered")
    rid = db("SELECT id FROM return_items WHERE order_item_id=999003")[0][0]
    other = db("SELECT id FROM return_items WHERE order_item_id=999013")[0][0]
    out["pickup_other_courier"] = kurir.call("PATCH", f"/api/v1/kurir/returns/{other}/pickup", {})
    out["pickup_missing"] = kurir.call("PATCH", "/api/v1/kurir/returns/999999/pickup", {})
    out["pickup_note_long"] = kurir.call("PATCH", f"/api/v1/kurir/returns/{rid}/pickup", {"note": "x" * 256})
    out["pickup"] = kurir.call("PATCH", f"/api/v1/kurir/returns/{rid}/pickup", {"note": "diambil"})
    out["confirm_missing_received"] = kurir.call("PATCH", f"/api/v1/kurir/returns/{rid}/confirm", {})
    out["confirm_received"] = kurir.call("PATCH", f"/api/v1/kurir/returns/{rid}/confirm", {"received": True})
    out["item_after"] = db("SELECT status FROM order_items WHERE id=999003")
    out["confirm_again"] = kurir.call("PATCH", f"/api/v1/kurir/returns/{rid}/confirm", {"received": True})
    out["audit"] = db("SELECT event FROM activity_logs WHERE event IN ('return_item.picked_up','return_item.courier_confirmed') ORDER BY id")
    return out


def main():
    f.seed_full()
    seed_dispatch()
    seed_kurir()
    lar = scenario(LARAVEL)
    f.seed_full()
    seed_dispatch()
    seed_kurir()
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
    print(f"kurir: {total - failed} passed, {failed} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())

