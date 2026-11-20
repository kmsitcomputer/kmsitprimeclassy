#!/usr/bin/env python3
"""GLM differential harness — REFUND RECIPIENT (2 Laravel-only routes).

  GET  /orders/{order}/refund-recipient  index (destination masked for non-keuangan)
  POST /orders/{order}/refund-recipient  record (append-only, supersedes chain)

Usage: python3 tools/parity/compare_recipient.py
"""
import json
import re
import sys
import urllib.error
import urllib.request

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, db, norm_body
from compare_dispatch import seed_dispatch
from compare_users import SALES, KEUANGAN as KEU

TS = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+Z|\+00:00)?$")

PNG = bytes.fromhex("89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c489"
                    "0000000d4944415478da63fcffff3f0300050001a5f645400000000049454e44ae426082")


def scrub(v):
    if isinstance(v, str) and TS.match(v):
        return "<ts>"
    if isinstance(v, tuple):
        return [scrub(x) for x in v]
    if isinstance(v, list):
        return sorted((scrub(x) for x in v), key=lambda d: json.dumps(d, sort_keys=True)) if v and all(isinstance(i, dict) for i in v) else [scrub(x) for x in v]
    if isinstance(v, dict):
        return {k: ("<id>" if k in ("id", "order_id", "consent_event_id", "supersedes_id", "captured_by", "return_id") and isinstance(x, int) else scrub(x))
                for k, x in v.items()}
    return v


def store_multipart(client, fields, files):
    boundary = "----pc-imp010-rcpt"
    body = []
    for k, v in fields.items():
        body += [f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n'.encode(),
                 str(v).encode(), b"\r\n"]
    for k, (name, data, mime) in files.items():
        body += [f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"; filename="{name}"\r\nContent-Type: {mime}\r\n\r\n'.encode(),
                 data, b"\r\n"]
    body.append(f"--{boundary}--\r\n".encode())
    headers = {"Accept": "application/json", "Origin": client.base, "Referer": client.base + "/",
               "X-Requested-With": "XMLHttpRequest",
               "Content-Type": f"multipart/form-data; boundary={boundary}"}
    if client.token():
        headers["X-XSRF-TOKEN"] = client.token()
    req = urllib.request.Request(client.base + "/api/v1/orders/997501/refund-recipient",
                                 data=b"".join(body), headers=headers, method="POST")
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


def seed_recipient():
    db("DELETE FROM refund_recipient_evidences WHERE supersedes_id IN (SELECT id FROM (SELECT id FROM refund_recipient_evidences WHERE order_id=997501) t)")
    db("DELETE FROM refund_recipient_evidences WHERE order_id=997501")
    db("DELETE FROM activity_logs WHERE event='refund.recipient_evidence.recorded'")
    db("DELETE FROM shipments WHERE order_id=997501")
    db("SET FOREIGN_KEY_CHECKS=0")
    db("DELETE FROM order_items WHERE order_id=997501")
    db("DELETE FROM orders WHERE id=997501")
    db("SET FOREIGN_KEY_CHECKS=1")
    sales_id = int(db("SELECT id FROM users WHERE email='sales@imp010.test'")[0][0])
    db("INSERT INTO crm_customers (id, agent_id, owner_user_id, name, phone, customer_type, created_at, updated_at) "
       f"VALUES (997502, 10, {sales_id}, 'Bu CRM', '0813', 'person', NOW(), NOW()) "
       "ON DUPLICATE KEY UPDATE owner_user_id=VALUES(owner_user_id)")
    db(f"INSERT INTO orders (id, order_no, agent_id, konsumen_id, crm_customer_id, sales_id, status, payment_status, "
       f"dp_amount, paid_amount, remaining_amount, subtotal_amount, total_amount, recipient_name_snapshot, "
       f"recipient_phone_snapshot, address_snapshot, created_at, updated_at) "
       f"VALUES (997501,'GLM-RCPT-1',10,13,997502,{sales_id},'dikirim','paid',0,0,0,0,0,'x','0812','jl',NOW(),NOW())")
    db("INSERT INTO shipments (id, order_id, courier_id, status, delivery_mode, created_at, updated_at) "
       "VALUES (997503, 997501, 902, 'in_transit', 'standard', NOW(), NOW())")
    db("INSERT INTO order_items (id, order_id, product_id, stock_source, product_name_snapshot, "
       "unit_price_snapshot, subtotal_snapshot, original_quantity, fulfilled_quantity, status, shipment_id, "
       "created_at, updated_at) VALUES (997504,997501,1,'agent','P',1000,1000,1,1,'dikirim',997503,NOW(),NOW())")


def scenario(base):
    out = {}
    sales = f.client_for(base, SALES)
    keu = f.client_for(base, KEU)

    out["index_empty"] = sales.call("GET", "/api/v1/orders/997501/refund-recipient")
    out["missing_order"] = sales.call("GET", "/api/v1/orders/999999/refund-recipient")
    out["store_missing"] = sales.call("POST", "/api/v1/orders/997501/refund-recipient", {})
    out["store_bad_method"] = sales.call("POST", "/api/v1/orders/997501/refund-recipient",
                                          {"recipient_name": "Ibu Ani", "method": "pulsa",
                                           "authorization_note": "disetujui"})
    out["store_not_crm"] = sales.call("POST", "/api/v1/orders/1/refund-recipient",
                                       {"recipient_name": "Ibu Ani", "method": "cash",
                                        "authorization_note": "disetujui"})
    out["store_auth_required"] = sales.call("POST", "/api/v1/orders/997501/refund-recipient",
                                              {"recipient_name": "Ibu Ani", "method": "cash",
                                               "authorization_note": "disetujui"})
    out["store_dest_required"] = sales.call("POST", "/api/v1/orders/997501/refund-recipient",
                                              {"recipient_name": "Ibu Ani", "method": "bank_transfer",
                                               "authorization_note": "disetujui",
                                               "destination": {"bank_or_provider": "BCA"}})
    out["store_ok"] = store_multipart(sales, {"recipient_name": "Ibu Ani", "recipient_phone": "0813",
                                                "recipient_relation": "ibu", "method": "bank_transfer",
                                                "authorization_note": "disetujui pelanggan",
                                                "destination[bank_or_provider]": "BCA",
                                                "destination[account_number]": "1234567890",
                                                "destination[account_holder]": "Ani Wijaya"},
                                        {"evidence": ("bukti.png", PNG, "image/png")})
    out["index_after"] = sales.call("GET", "/api/v1/orders/997501/refund-recipient")
    out["index_after_keu"] = keu.call("GET", "/api/v1/orders/997501/refund-recipient")
    out["store_second"] = store_multipart(sales, {"recipient_name": "Ani W", "method": "cash",
                                                    "authorization_note": "koreksi"},
                                            {"evidence": ("bukti2.png", PNG, "image/png")})
    out["index_final"] = sales.call("GET", "/api/v1/orders/997501/refund-recipient")
    out["audit"] = db("SELECT event FROM activity_logs WHERE event='refund.recipient_evidence.recorded' ORDER BY id")
    return out


def main():
    f.seed_full()
    seed_dispatch()
    seed_recipient()
    lar = scenario(LARAVEL)
    f.seed_full()
    seed_dispatch()
    seed_recipient()
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
    print(f"recipient: {total - failed} passed, {failed} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
