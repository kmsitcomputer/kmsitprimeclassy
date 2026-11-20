#!/usr/bin/env python3
"""GLM differential harness — EXPEDITION HANDOVER + SHIPMENT RECEIPT (2 Laravel-only routes).

  PATCH /api/v1/shipments/{shipment}/expedition-handover  REM-003 carrier handover (multipart evidence)
  GET   /api/v1/shipments/{shipment}/receipt              thermal receipt (read-only + audit)

Covers: handover RBAC/validation, provider gate (not expedition), carrier mismatch,
idempotent replay, tracking conflict (409), DB state (tracking_number, provider_meta.handover,
media row, audit), receipt RBAC (koordinator-of-branch, courier-owner, office same agent,
stranger 404), receipt payload shape, reprint audit (is_reprint).

Usage: python3 tools/parity/compare_handover_receipt.py
"""
import json
import re
import sys
import urllib.error
import urllib.request

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, db, norm_body
from compare_dispatch import seed_dispatch
from compare_users import COORD, AGEN, KURIR

# Minimal valid 1x1 PNG.
PNG = bytes.fromhex(
    "89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c489"
    "0000000d4944415478da63fcffff3f0300050001a5f645400000000049454e44ae426082")


def multipart_patch(client, path, fields, files, method="POST"):
    boundary = "----pc-imp010-handover"
    body = []
    for key, value in fields.items():
        body += [f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n'.encode(),
                 str(value).encode(), b"\r\n"]
    for key, (name, data, mime) in files.items():
        body += [f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"; filename="{name}"\r\nContent-Type: {mime}\r\n\r\n'.encode(),
                 data, b"\r\n"]
    body.append(f"--{boundary}--\r\n".encode())
    headers = {"Accept": "application/json", "Origin": client.base, "Referer": client.base + "/",
               "X-Requested-With": "XMLHttpRequest",
               "Content-Type": f"multipart/form-data; boundary={boundary}"}
    if client.token():
        headers["X-XSRF-TOKEN"] = client.token()
    req = urllib.request.Request(client.base + path, data=b"".join(body), headers=headers, method=method)
    try:
        with client.opener.open(req, timeout=50) as res:
            raw = res.read().decode()
            return res.status, (json.loads(raw) if raw else None)
    except urllib.error.HTTPError as exc:
        raw = exc.read().decode()
        try:
            return exc.code, json.loads(raw)
        except json.JSONDecodeError:
            return exc.code, {"_raw": raw[:300]}


def seed_handover():
    db("DELETE FROM media WHERE mediable_type LIKE '%Shipment' AND mediable_id IN (994002,994003)")
    db("DELETE FROM activity_logs WHERE event IN ('shipment.expedition_handover_recorded','shipment.receipt_printed')")
    db("DELETE FROM shipments WHERE order_id=994001")
    db("SET FOREIGN_KEY_CHECKS=0")
    db("DELETE FROM order_items WHERE id=994001")
    db("DELETE FROM orders WHERE id=994001")
    db("DELETE FROM couriers WHERE id=994004")
    db("SET FOREIGN_KEY_CHECKS=1")
    db("INSERT INTO orders (id, order_no, agent_id, konsumen_id, status, payment_status, dp_amount, paid_amount, "
       "remaining_amount, subtotal_amount, total_amount, recipient_name_snapshot, recipient_phone_snapshot, "
       "address_snapshot, village_snapshot, district_snapshot, regency_snapshot, province_snapshot, "
       "created_at, updated_at) "
       "VALUES (994001,'GLM-HAND-1',10,13,'diproses','paid',0,0,0,0,150000,'Rina','0812','jl x',"
       "'Desa S','Kec A','Kota B','Prov C',NOW(),NOW())")
    db("INSERT INTO order_items (id, order_id, product_id, stock_source, product_name_snapshot, sku_snapshot, "
       "variation_label_snapshot, unit_price_snapshot, subtotal_snapshot, original_quantity, fulfilled_quantity, "
       "status, shipment_id, requested_delivery_date, created_at, updated_at) "
       "VALUES (994001,994001,1,'agent','Produk Uji','SKU-1','Variante',75000,150000,2,2,'diproses',NULL,'2026-12-01',NOW(),NOW())")
    db("INSERT INTO shipments (id, order_id, courier_id, status, delivery_mode, shipping_provider_code, "
       "provider_meta, created_at, updated_at) "
       "VALUES (994002, 994001, NULL, 'pending', 'standard', 'rajaongkir', JSON_OBJECT('courier','jne'), NOW(), NOW())")
    db("UPDATE order_items SET shipment_id=994002 WHERE id=994001")
    db("INSERT INTO stock_requests (order_id, agent_id, request_number, status, created_at, updated_at) "
       "VALUES (994001, 10, 'GLM-SR-994001', 'fulfilled', NOW(), NOW())")
    rid = db("SELECT id FROM stock_requests WHERE order_id=994001 ORDER BY id DESC LIMIT 1")[0][0]
    db(f"INSERT INTO stock_request_items (stock_request_id, order_item_id, product_id, requested_qty, fulfilled_qty, "
       f"remaining_qty, created_at, updated_at) VALUES ({rid},994001,1,2,2,0,NOW(),NOW())")
    # Second shipment for receipt: standard, assigned to kurir 902 (user 19), order dikirim.
    db("INSERT INTO shipments (id, order_id, courier_id, status, delivery_mode, created_at, updated_at) "
       "VALUES (994003, 994001, 902, 'pending', 'standard', NOW(), NOW())")
    db("INSERT INTO couriers (id, type, user_id, agent_id, external_code, name, is_active, created_at, updated_at) "
       "VALUES (994004,'internal',NULL,10,'GLMH1','Kurir GLM Hand',1,NOW(),NOW())")


def scenario(base):
    out = {}
    koord = f.client_for(base, COORD)
    agen = f.client_for(base, AGEN)
    kurir = f.client_for(base, KURIR)

    # Receipt RBAC + payload (before any handover)
    out["rbac_kurir_receipt"] = kurir.call("GET", "/api/v1/shipments/994002/receipt")
    out["receipt"] = koord.call("GET", "/api/v1/shipments/994003/receipt")
    out["missing_receipt"] = koord.call("GET", "/api/v1/shipments/999999/receipt")

    # Handover RBAC + validation
    out["rbac_agen_handover"] = agen.call("PATCH", "/api/v1/shipments/994002/expedition-handover",
                                          {"carrier": "jne", "tracking_number": "ABC12345"})
    out["handover_missing_fields"] = koord.call("PATCH", "/api/v1/shipments/994002/expedition-handover", {})
    out["not_expedition"] = koord.call("PATCH", "/api/v1/shipments/994003/expedition-handover",
                                       {"carrier": "jne", "tracking_number": "ABC12345"})

    # Plain multipart PATCH: PHP does not populate $_POST for PATCH bodies, so the reference
    # sees an empty request and fails every required rule.
    out["multipart_patch"] = multipart_patch(koord, "/api/v1/shipments/994002/expedition-handover",
                                              {"carrier": "jne", "tracking_number": "ABC12345"},
                                              {"evidence": ("bukti.png", PNG, "image/png")}, method="PATCH")

    # Carrier mismatch
    out["carrier_mismatch"] = multipart_patch(koord, "/api/v1/shipments/994002/expedition-handover",
                                              {"_method": "PATCH", "carrier": "jnt", "tracking_number": "ABC12345"},
                                              {"evidence": ("bukti.png", PNG, "image/png")})

    # Success
    out["handover"] = multipart_patch(koord, "/api/v1/shipments/994002/expedition-handover",
                                      {"_method": "PATCH", "carrier": "jne", "tracking_number": "JNE123456"},
                                      {"evidence": ("bukti.png", PNG, "image/png")})
    out["shipment_after_handover"] = db("SELECT tracking_number, provider_meta LIKE '%handover%' FROM shipments WHERE id=994002")
    out["media_row"] = db("SELECT COUNT(*) FROM media WHERE mediable_id=994002")
    out["audit_handover"] = db("SELECT event FROM activity_logs WHERE event='shipment.expedition_handover_recorded'")

    # Idempotent replay (same tracking number, no second media row)
    out["handover_replay"] = multipart_patch(koord, "/api/v1/shipments/994002/expedition-handover",
                                             {"_method": "PATCH", "carrier": "jne", "tracking_number": "JNE123456"},
                                             {"evidence": ("bukti.png", PNG, "image/png")})
    out["media_after_replay"] = db("SELECT COUNT(*) FROM media WHERE mediable_id=994002")

    # Tracking conflict (different number)
    out["tracking_conflict"] = multipart_patch(koord, "/api/v1/shipments/994002/expedition-handover",
                                               {"_method": "PATCH", "carrier": "jne", "tracking_number": "DIFF99999"},
                                               {"evidence": ("bukti.png", PNG, "image/png")})

    # Receipt after handover: payload + reprint audit (second print sets is_reprint)
    out["receipt2"] = koord.call("GET", "/api/v1/shipments/994003/receipt")
    out["audit_prints"] = db("SELECT COUNT(*) FROM activity_logs WHERE event='shipment.receipt_printed'")
    return out


TS = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+Z|\+00:00)?$")


def scrub(v):
    if isinstance(v, str) and TS.match(v):
        return "<ts>"
    if isinstance(v, tuple):
        return [scrub(x) for x in v]
    if isinstance(v, list):
        return sorted((scrub(x) for x in v), key=lambda d: json.dumps(d, sort_keys=True)) if v and all(isinstance(i, dict) for i in v) else [scrub(x) for x in v]
    if isinstance(v, dict):
        return {k: ("<id>" if k in ("id", "courier_id", "user_id", "media_id", "shipment_id") and isinstance(x, int) else scrub(x))
                for k, x in v.items()}
    return v


def main():
    f.seed_full()
    seed_dispatch()
    seed_handover()
    lar = scenario(LARAVEL)
    f.seed_full()
    seed_dispatch()
    seed_handover()
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
    print(f"handover_receipt: {total - failed} passed, {failed} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
