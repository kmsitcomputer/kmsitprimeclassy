#!/usr/bin/env python3
"""GLM differential harness — CONSENT OTP + EVIDENCE (8 Laravel-only routes).

  POST /consent/otp                      issue OTP (emailed; never returned)
  POST /consent/otp/{challenge}/verify   verify code (attempt-limited)
  POST /consent-events                   capture consent (OTP + evidence + scope + note)
  POST /consent-events/{e}/withdraw|dispute|reinstate  append-only chain
  GET  /orders/{order}/consent-events    history (order view policy)
  GET  /orders/{order}/consent-context   capture panel context

The OTP code is never returned; the harness recovers it by brute-forcing the 6-digit
HMAC (same APP_KEY in both runtimes of the isolated env).

Usage: python3 tools/parity/compare_consent.py
"""
import hashlib
import hmac as hmac_mod
import json
import re
import sys

import compare_fulfillment as f
from compare_fulfillment import LARAVEL, GO, db, norm_body

APP_KEY = "base64:zm5WTO0eNS5nc+bh5npzKXxjYGHHHSX5pD8bwynKImY="
SUBJ = "konsumen@imp010.test"
SALES = "sales@imp010.test"
AGEN = "agen@imp010.test"
ADMIN = "admin@imp010.test"
KONSUMEN_EMAIL = None  # resolved from db

TS = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+Z|\+00:00)?$")


def scrub(v):
    if isinstance(v, str) and TS.match(v):
        return "<ts>"
    if isinstance(v, tuple):
        return [scrub(x) for x in v]
    if isinstance(v, list):
        return sorted((scrub(x) for x in v), key=lambda d: json.dumps(d, sort_keys=True)) if v and all(isinstance(i, dict) for i in v) else [scrub(x) for x in v]
    if isinstance(v, dict):
        return {k: ("<id>" if k in ("id", "challenge_id", "subject_user_id", "order_id", "order_item_id",
                                    "root_event_id", "otp_challenge_id", "actor_id", "crm_customer_id")
                   and isinstance(x, int) else scrub(x))
                for k, x in v.items()}
    return v


def recover_code(challenge_id, subject_id, purpose, code_hash):
    for n in range(1000000):
        code = "%06d" % n
        msg = f"{challenge_id}|{subject_id}|{purpose}|{code}".encode()
        if hmac_mod.new(APP_KEY.encode(), msg, hashlib.sha256).hexdigest() == code_hash:
            return code
    return None


# Minimal 1x1 PNG evidence.
PNG = bytes.fromhex(
    "89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c489"
    "0000000d4944415478da63fcffff3f0300050001a5f645400000000049454e44ae426082")


def multipart(client, fields, files):
    boundary = "----pc-imp010-consent"
    body = []
    for key, value in fields.items():
        if isinstance(value, list):
            for i, line in enumerate(value):
                for sub in ("order_item_id", "quantity"):
                    body += [f'--{boundary}\r\nContent-Disposition: form-data; name="{key}[{i}][{sub}]"\r\n\r\n'.encode(),
                             str(line[sub]).encode(), b"\r\n"]
            continue
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
    import urllib.request
    req = urllib.request.Request(client.base + path_holder[0], data=b"".join(body), headers=headers, method="POST")
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


path_holder = [""]


def seed_consent():
    global KONSUMEN_EMAIL, KONSUMEN_ID
    db("DELETE FROM consent_events WHERE root_event_id IN (SELECT id FROM (SELECT id FROM consent_events WHERE order_id=998001) t)")
    db("DELETE FROM consent_events WHERE order_id=998001")
    db("DELETE FROM identity_otp_challenges WHERE subject_user_id=(SELECT id FROM users WHERE email='konsumen@imp010.test')")
    db("DELETE FROM shipments WHERE order_id=998001")
    db("SET FOREIGN_KEY_CHECKS=0")
    db("DELETE FROM order_items WHERE order_id=998001")
    db("DELETE FROM orders WHERE id=998001")
    db("SET FOREIGN_KEY_CHECKS=1")
    KONSUMEN_ID = int(db("SELECT id FROM users WHERE email='konsumen@imp010.test'")[0][0])
    sales_id = db("SELECT id FROM users WHERE email='sales@imp010.test'")[0][0]
    # The capturing actor must own the referral, and the OTP channel needs a verified email.
    db(f"UPDATE users SET sales_id={sales_id}, email_verified_at=NOW() WHERE id={KONSUMEN_ID}")
    db("INSERT INTO orders (id, order_no, agent_id, konsumen_id, status, payment_status, dp_amount, paid_amount, "
       "remaining_amount, subtotal_amount, total_amount, recipient_name_snapshot, recipient_phone_snapshot, "
       "address_snapshot, created_at, updated_at) "
       "VALUES (998001,'GLM-CONS-1',10," + str(KONSUMEN_ID) + ",'dikirim','paid',0,0,0,0,0,'x','0812','jl',NOW(),NOW())")
    db("INSERT INTO order_items (id, order_id, product_id, stock_source, product_name_snapshot, "
       "unit_price_snapshot, subtotal_snapshot, original_quantity, fulfilled_quantity, status, shipment_id, "
       "created_at, updated_at) VALUES "
       "(998001,998001,1,'agent','P',1000,2000,2,2,'terkirim',NULL,NOW(),NOW())")
    db("INSERT INTO shipments (id, order_id, courier_id, status, delivery_mode, created_at, updated_at) "
       "VALUES (998002, 998001, 902, 'delivered', 'standard', NOW(), NOW())")
    db("UPDATE order_items SET shipment_id=998002 WHERE id=998001")


def scenario(base):
    out = {}
    sales = f.client_for(base, SALES)
    agen = f.client_for(base, AGEN)
    admin = f.client_for(base, ADMIN)
    konsumen = f.client_for(base, SUBJ)

    # RBAC: admin cannot issue (capturing roles only)
    out["rbac_admin_issue"] = admin.call("POST", "/api/v1/consent/otp",
                                         {"subject_user_id": KONSUMEN_ID, "purpose": "assisted_return"})
    # Validation
    out["issue_missing_subject"] = sales.call("POST", "/api/v1/consent/otp", {"purpose": "assisted_return"})
    out["issue_bad_purpose"] = sales.call("POST", "/api/v1/consent/otp",
                                          {"subject_user_id": KONSUMEN_ID, "purpose": "bogus"})
    out["issue_missing_row"] = sales.call("POST", "/api/v1/consent/otp",
                                          {"subject_user_id": 99999999, "purpose": "assisted_return"})

    # Success issue (sales of own referral)
    out["issue"] = sales.call("POST", "/api/v1/consent/otp",
                              {"subject_user_id": KONSUMEN_ID, "purpose": "assisted_return"})
    ch = db("SELECT id, code_hash FROM identity_otp_challenges WHERE subject_user_id=" + str(KONSUMEN_ID) +
            " ORDER BY id DESC LIMIT 1")[0]
    code = recover_code(ch[0], KONSUMEN_ID, "assisted_return", ch[1])

    # Wrong code → invalid + attempt persists
    out["verify_wrong"] = sales.call("POST", f"/api/v1/consent/otp/{ch[0]}/verify", {"code": "000000" if code != "000000" else "000001"})
    out["attempts_after_wrong"] = db("SELECT attempts FROM identity_otp_challenges WHERE id=" + str(ch[0]))
    # Correct code
    out["verify_ok"] = sales.call("POST", f"/api/v1/consent/otp/{ch[0]}/verify", {"code": code})
    # Replay after verified
    out["verify_replay"] = sales.call("POST", f"/api/v1/consent/otp/{ch[0]}/verify", {"code": code})
    # Another actor cannot verify someone else's challenge
    out["verify_wrong_actor"] = agen.call("POST", f"/api/v1/consent/otp/{ch[0]}/verify", {"code": code})

    # Context (sales can capture)
    out["context"] = sales.call("GET", "/api/v1/orders/998001/consent-context")
    out["context_no_capture"] = konsumen.call("GET", "/api/v1/orders/998001/consent-context")

    # Capture: invalid scope (foreign item)
    path_holder[0] = "/api/v1/consent-events"
    out["capture_bad_scope"] = multipart(sales, {
        "subject_user_id": KONSUMEN_ID, "order_id": 998001, "purpose": "assisted_return",
        "otp_challenge_id": ch[0], "note": "setuju", "scope": [{"order_item_id": 997001, "quantity": 1}],
        "consent_key": "GLM-CK-0",
    }, {"evidence": ("bukti.png", PNG, "image/png")})
    # Capture: challenge already consumed
    out["capture_consumed"] = multipart(sales, {
        "subject_user_id": KONSUMEN_ID, "order_id": 998001, "purpose": "assisted_return",
        "otp_challenge_id": ch[0], "note": "setuju", "scope": [{"order_item_id": 998001, "quantity": 1}],
        "consent_key": "GLM-CK-00",
    }, {"evidence": ("bukti.png", PNG, "image/png")})

    # Fresh challenge for the capture success path
    out["issue2"] = sales.call("POST", "/api/v1/consent/otp",
                              {"subject_user_id": KONSUMEN_ID, "purpose": "assisted_return"})
    ch2 = db("SELECT id, code_hash FROM identity_otp_challenges WHERE subject_user_id=" + str(KONSUMEN_ID) +
             " ORDER BY id DESC LIMIT 1")[0]
    code2 = recover_code(ch2[0], KONSUMEN_ID, "assisted_return", ch2[1])
    out["verify2"] = sales.call("POST", f"/api/v1/consent/otp/{ch2[0]}/verify", {"code": code2})
    out["capture"] = multipart(sales, {
        "subject_user_id": KONSUMEN_ID, "order_id": 998001, "purpose": "assisted_return",
        "otp_challenge_id": ch2[0], "note": "disetujui pelanggan",
        "scope": [{"order_item_id": 998001, "quantity": 1}],
        "consent_key": "GLM-CK-1",
    }, {"evidence": ("bukti.png", PNG, "image/png")})
    ev = db("SELECT id FROM consent_events WHERE order_id=998001 AND root_event_id IS NULL ORDER BY id DESC LIMIT 1")[0][0]
    out["event_db"] = db("SELECT event_type, purpose, note, policy_version FROM consent_events WHERE id=" + str(ev))
    # Idempotent capture (same consent_key)
    out["capture_replay"] = multipart(sales, {
        "subject_user_id": KONSUMEN_ID, "order_id": 998001, "purpose": "assisted_return",
        "otp_challenge_id": ch2[0], "note": "disetujui pelanggan",
        "scope": [{"order_item_id": 998001, "quantity": 1}],
        "consent_key": "GLM-CK-1",
    }, {"evidence": ("bukti.png", PNG, "image/png")})

    # History
    out["history"] = sales.call("GET", "/api/v1/orders/998001/consent-events")

    # Chain transitions: withdraw → dispute → reinstate
    out["withdraw"] = konsumen.call("POST", f"/api/v1/consent-events/{ev}/withdraw", {"reason": "tidak jadi"})
    out["withdraw_twice"] = konsumen.call("POST", f"/api/v1/consent-events/{ev}/withdraw", {"reason": "lagi"})
    out["dispute"] = admin.call("POST", f"/api/v1/consent-events/{ev}/dispute", {"reason": "ada sengketa"})
    out["reinstate"] = admin.call("POST", f"/api/v1/consent-events/{ev}/reinstate", {"reason": "sudah selesai"})
    out["replay_reinstate"] = admin.call("POST", f"/api/v1/consent-events/{ev}/reinstate", {"reason": "x"})
    out["history_final"] = sales.call("GET", "/api/v1/orders/998001/consent-events")
    return out


def main():
    f.seed_full()
    seed_consent()
    lar = scenario(LARAVEL)
    f.seed_full()
    seed_consent()
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
    print(f"consent: {total - failed} passed, {failed} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
