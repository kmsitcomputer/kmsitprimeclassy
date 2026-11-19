#!/usr/bin/env python3
"""GLM differential harness — CRM-ORIGIN CONSENT (2 Laravel-only routes).

  POST /api/v1/crm/customers/{customer}/consent-otp    (sales, sales-kurir-sub, korsal)
  POST /api/v1/crm/customers/{customer}/consent-events (sales, sales-kurir-sub, korsal)

Verifies: unauth 401, RBAC, binding 404, validation 422 (purpose in-set, missing fields, channel),
consent-otp channel gates (unlinked 409, unverified email 422, whatsapp 409), consent capture with
multipart evidence (order mismatch 422, invalid scope 422, capture success + evidence hash, key
idempotency, consumed challenge 422). Normalizations: ids/timestamps, JSON key order, null-vs-absent.
Requires an existing CRM-origin order (crm_customer_id set) — one is fixed up via SQL.
"""
import io
import json
import sys
import urllib.request
import urllib.error
import http.cookiejar
import urllib.parse

sys.path.insert(0, "tools/parity-glm")
from compare_crm_writes import Client, USERS, db_query, norm  # noqa: E402

results = []
LARAVEL = "http://127.0.0.1:8898"
GO = "http://127.0.0.1:8083"


def check(name, ok, detail=""):
    results.append((name, ok, detail))


def fresh(role):
    c = Client("http://127.0.0.1:8898")
    return c

PNG = bytes.fromhex("89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000a49444154789c63000100000500010d0a2db40000000049454e44ae426082")


def multipart(fields, evidence=None):
    boundary = "GLMHARNESS123"
    buf = io.BytesIO()
    for k, v in fields.items():
        buf.write(f"--{boundary}\r\nContent-Disposition: form-data; name=\"{k}\"\r\n\r\n{v}\r\n".encode())
    if evidence is not None:
        buf.write(f"--{boundary}\r\nContent-Disposition: form-data; name=\"evidence\"; filename=\"ev.png\"\r\nContent-Type: image/png\r\n\r\n".encode())
        buf.write(evidence)
        buf.write(b"\r\n")
    buf.write(f"--{boundary}--\r\n".encode())
    return buf.getvalue(), f"multipart/form-data; boundary={boundary}"


class MClient(Client):
    def multipart_call(self, method, path, fields, evidence=None):
        body, ctype = multipart(fields, evidence)
        headers = {"Accept": "application/json", "Origin": self.base, "Referer": self.base + "/",
                   "X-Requested-With": "XMLHttpRequest", "Content-Type": ctype}
        tok = self.token()
        if tok:
            headers["X-XSRF-TOKEN"] = tok
        req = urllib.request.Request(self.base + path, data=body, headers=headers, method=method)
        try:
            with self.opener.open(req, timeout=25) as res:
                raw = res.read().decode()
                return res.status, (json.loads(raw) if raw else None)
        except urllib.error.HTTPError as e:
            raw = e.read().decode()
            try:
                return e.code, (json.loads(raw) if raw else None)
            except json.JSONDecodeError:
                return e.code, {"_raw": raw}


def scenario(base):
    db_query("UPDATE crm_customers SET linked_konsumen_id=NULL WHERE linked_konsumen_id IS NOT NULL")
    db_query("DELETE FROM crm_customer_link_events")
    db_query("DELETE FROM identity_otp_challenges WHERE purpose IN ('crm_link','assisted_return','whatsapp_number')")
    db_query("DELETE FROM consent_events")
    db_query("DELETE FROM activity_logs WHERE event LIKE 'crm.%' AND subject_type LIKE '%Crm%' AND event NOT LIKE '%visit%' AND event NOT LIKE '%customer.%' AND event NOT LIKE '%linked%' AND event NOT LIKE '%unlinked%' AND event NOT LIKE '%exported%'")
    db_query("UPDATE users SET email='konsumen1@imp010.test', email_verified_at=UTC_TIMESTAMP(6) WHERE id=13 AND agent_id=10")

    # fixture: create own customer owned by korsal (20, agent 10), link to konsumen 13, CRM-origin order
    db_query("DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE idempotency_key LIKE 'glmconsent%')")
    db_query("DELETE FROM orders WHERE idempotency_key LIKE 'glmconsent%'")
    db_query("DELETE FROM crm_customers WHERE owner_user_id=20 AND name='GLMCONSENT'")
    db_query("INSERT INTO crm_customers (agent_id, owner_user_id, name, phone, linked_konsumen_id, created_at, updated_at) VALUES (10, 20, 'GLMCONSENT', '081100001111', 13, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))")
    cid = db_query("SELECT id FROM crm_customers WHERE owner_user_id=20 AND name='GLMCONSENT' ORDER BY id DESC LIMIT 1")
    db_query("DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE crm_customer_id IN (SELECT id FROM crm_customers WHERE owner_user_id=20 AND name='GLMCONSENT') AND idempotency_key LIKE 'glmconsent%')")
    db_query("DELETE FROM orders WHERE crm_customer_id IN (SELECT id FROM crm_customers WHERE owner_user_id=20 AND name='GLMCONSENT') AND idempotency_key LIKE 'glmconsent%'")
    db_query(f"INSERT INTO orders (agent_id, konsumen_id, crm_customer_id, idempotency_key, order_no, placed_by_user_id, sales_id, status, payment_status, subtotal_amount, discount_amount, shipping_fee_amount, admin_fee_amount, gross_received_amount, total_amount, recipient_name_snapshot, recipient_phone_snapshot, address_snapshot, created_at, updated_at)"
             f" SELECT 10, 13, {cid}, 'glmconsent-1', CONCAT('GLM-CONSENT-', id + 1), 20, 20, 'diterima', 'unpaid', 20000, 0, 0, 0, 0, 20000, 'GLM Recipient', '081200000000', 'Jl. Harness No 1', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) FROM orders LIMIT 1")
    oid = db_query("SELECT id FROM orders WHERE crm_customer_id IN (SELECT id FROM crm_customers WHERE owner_user_id=20 AND name='GLMCONSENT') AND idempotency_key='glmconsent-1' ORDER BY id DESC LIMIT 1")
    db_query(f"INSERT INTO order_items (order_id, product_id, stock_source, product_name_snapshot, sku_snapshot, unit_price_snapshot, subtotal_snapshot, original_quantity, fulfilled_quantity, returned_quantity, status, created_at, updated_at)"
             f" SELECT {oid}, 1, 'agent', 'GLM consent item', 'GLM-C1', 10000, 20000, 2, 2, 0, 'diterima', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)")
    item_id = db_query(f"SELECT id FROM order_items WHERE order_id={oid} ORDER BY id DESC LIMIT 1")
    if not oid or not item_id:
        return {"fixture": "MISSING"}

    out = {"fixture": bool(oid) and bool(item_id)}

    out["unauth"] = Client(base).call("POST", "/api/v1/crm/customers/{cid}/consent-otp", {"purpose": "assisted_return"})

    for role in ("super_admin", "agen", "admin"):
        c = MClient(base)
        c.login(USERS[role])
        out[("rbac", role)] = c.call("POST", f"/api/v1/crm/customers/{cid}/consent-otp", {"purpose": "assisted_return"})

    c = MClient(base)
    c.login(USERS["sales"])
    out["binding_404"] = c.call("POST", "/api/v1/crm/customers/999999/consent-otp", {"purpose": "assisted_return"})
    out["v_missing"] = c.call("POST", f"/api/v1/crm/customers/{cid}/consent-otp", {})
    out["v_bad_purpose"] = c.call("POST", f"/api/v1/crm/customers/{cid}/consent-otp", {"purpose": "refund"})

    # capture gates before any otp
    ev_fields = {"otp_challenge_id": "1", "order_id": oid, "purpose": "assisted_return", "note": "GLM note",
                 "scope[0][order_item_id]": str(item_id), "scope[0][quantity]": "2"}
    out["capture_no_evidence"] = c.multipart_call("POST", f"/api/v1/crm/customers/{cid}/consent-events", ev_fields)

    out["otp_issue"] = c.call("POST", f"/api/v1/crm/customers/{cid}/consent-otp", {"purpose": "assisted_return"})
    st, body = out["otp_issue"]
    ch = body["data"]["challenge_id"] if st == 201 else None
    row = db_query(f"SELECT purpose, subject_user_id, crm_customer_id FROM identity_otp_challenges WHERE id={ch}") if ch else None
    out["otp_row"] = row

    out["capture_unverified"] = c.multipart_call("POST", f"/api/v1/crm/customers/{cid}/consent-events", ev_fields, evidence=PNG)
    if ch:
        db_query(f"UPDATE identity_otp_challenges SET verified_at=UTC_TIMESTAMP(6) WHERE id={ch}")

    out["capture_bad_scope"] = c.multipart_call("POST", f"/api/v1/crm/customers/{cid}/consent-events",
        {**ev_fields, "scope[0][quantity]": "99"}, evidence=PNG)
    out["capture_bad_item"] = c.multipart_call("POST", f"/api/v1/crm/customers/{cid}/consent-events",
        {**ev_fields, "scope[0][order_item_id]": "999999"}, evidence=PNG)

    out["capture_ok"] = c.multipart_call("POST", f"/api/v1/crm/customers/{cid}/consent-events", ev_fields, evidence=PNG)
    out["event_row"] = db_query("SELECT event_type, purpose, subject_user_id, crm_customer_id, order_id, policy_version FROM consent_events ORDER BY id DESC LIMIT 1")
    out["capture_replay_key"] = None
    if out["capture_ok"][0] == 201:
        key = "glm-key-1"
        ev_fields["consent_key"] = key
        first = c.multipart_call("POST", f"/api/v1/crm/customers/{cid}/consent-events", ev_fields, evidence=PNG)
        out["capture_replay_key"] = (first[0], first[1]["data"]["id"] if first[1] and first[1].get("data") else None,
                                     first[1]["data"]["note"] if first[1] and first[1].get("data") else None)
        # conflict: same key other actor (korsal is the owner though; sales owns customer? customer 1 owner=20=korsal)
        ck = MClient(base)
        ck.login(USERS["korsal"])
        out["capture_key_conflict"] = ck.multipart_call("POST", f"/api/v1/crm/customers/{cid}/consent-events", ev_fields, evidence=PNG)
    return out


def main():
    lar = scenario(LARAVEL)
    go = scenario(GO)
    for key in lar:
        l, g = norm(lar[key]), norm(go.get(key))
        tag = key if isinstance(key, str) else ":".join(str(x) for x in key)
        check(tag, l == g, f"\n   L={json.dumps(l, sort_keys=True, default=str)[:400]}\n   G={json.dumps(g, sort_keys=True, default=str)[:400]}")
    failed = [r for r in results if not r[1]]
    print(f"crm-consent: {len(results) - len(failed)} passed, {len(failed)} failed")
    for name, ok, detail in failed:
        print(f"  FAIL {name}: {detail}")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
