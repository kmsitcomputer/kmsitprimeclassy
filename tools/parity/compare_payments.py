#!/usr/bin/env python3
"""Laravel <-> Go comparison for the POST-ORDER PAYMENT surface (9 routes).

    POST  /orders/{order}/payment/proof | cod-proof | installment | verify | settle
    PATCH /orders/{order}/payment/cod          PATCH /admin/cod-payment-proofs/{id}/confirm
    PUT   /orders/{order}/due-date             GET   /payment-proofs/{id}

Each scenario runs on BOTH runtimes from identical reset state and compares the HTTP status, the response body
(ids/timestamps normalised) and the resulting DATABASE state (orders, payment transactions, verifications, COD
proofs, additional payments, invoice snapshots, activity log). Money moves here, so "the response looked right"
is not evidence on its own.

Usage: python3 tools/parity/compare_payments.py
"""
import json
import re
import sys
import struct
import uuid
import zlib

import compare_fulfillment as f
from compare_fulfillment import (LARAVEL, GO, AGEN, ADMIN, KONSUMEN, SUPER, SALES, STATE_QUERIES, check,
                                 client_for, db, diff, norm_body, reset, rows, seed_full)

KEUANGAN = "keuangan@imp010.test"
KORSAL = "korsal@imp010.test"

# Order-scoped tables this unit adds, child -> parent (must precede payment_transactions / media).
for table in ("cod_payment_proofs", "bank_transfer_verifications"):
    if table not in f.CLEAR:
        f.CLEAR.insert(f.CLEAR.index("payment_transactions"), table)


def png(width=8, height=8):
    raw = b"".join(b"\x00" + b"\xff\x00\x00" * width for _ in range(height))

    def chunk(kind, data):
        body = kind + data
        return struct.pack(">I", len(data)) + body + struct.pack(">I", zlib.crc32(body) & 0xFFFFFFFF)
    return (b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", width, height, 8, 2, 0, 0, 0))
            + chunk(b"IDAT", zlib.compress(raw)) + chunk(b"IEND", b""))


def multipart(client, method, path, fields=None, files=None):
    boundary = "----pc" + uuid.uuid4().hex
    body = b""
    for name, value in (fields or {}).items():
        body += f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode()
    for name, (filename, content) in (files or {}).items():
        body += (f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"; filename="{filename}"\r\n'
                 f'Content-Type: image/png\r\n\r\n').encode() + content + b"\r\n"
    body += f"--{boundary}--\r\n".encode()
    import urllib.request
    import urllib.error
    headers = {"Accept": "application/json", "Origin": client.base, "Referer": client.base + "/",
               "X-Requested-With": "XMLHttpRequest", "Content-Type": f"multipart/form-data; boundary={boundary}"}
    token = client.token()
    if token:
        headers["X-XSRF-TOKEN"] = token
    request = urllib.request.Request(client.base + path, data=body, headers=headers, method=method)
    try:
        with client.opener.open(request, timeout=40) as res:
            raw = res.read().decode()
            return res.status, json.loads(raw) if raw else None
    except urllib.error.HTTPError as e:
        raw = e.read().decode()
        try:
            return e.code, json.loads(raw)
        except ValueError:
            return e.code, {"_raw": raw[:200]}


def raw_get(client, path):
    import urllib.request
    import urllib.error
    request = urllib.request.Request(client.base + path, headers={
        "Accept": "application/json", "Origin": client.base, "X-Requested-With": "XMLHttpRequest"})
    try:
        with client.opener.open(request, timeout=40) as res:
            return res.status, res.read(), res.headers.get("Cache-Control", "")
    except urllib.error.HTTPError as e:
        return e.code, e.read(), e.headers.get("Cache-Control", "")


def make_order(base, payment="bank_transfer", who=KONSUMEN, konsumen_id=None, dp=None, items=2):
    c = client_for(base, who)
    body = {"items": [{"product_id": 3, "quantity": items}], "recipient_name": "Budi",
            "recipient_phone": "081200000000", "address_line": "Jl. Tes 1", "village_id": "32730101",
            "latitude": -6.9, "longitude": 107.6, "shipping_method": "pickup", "payment_method_code": payment}
    if konsumen_id:
        body["konsumen_id"] = konsumen_id
    if dp:
        body["dp_amount"] = dp
    st, resp = c.call("POST", "/api/v1/orders", body, {"Idempotency-Key": "pay-" + uuid.uuid4().hex})
    if st != 201:
        raise SystemExit(f"order creation failed on {base}: {st} {json.dumps(resp)[:300]}")
    return int(db("SELECT id FROM orders ORDER BY id DESC LIMIT 1")[0][0])


def set_status(oid, status="diproses"):
    db(f"UPDATE orders SET status = '{status}' WHERE id = {oid}")
    db(f"UPDATE order_items SET status = '{status}' WHERE order_id = {oid}")


STATE_QUERIES["verifications"] = """SELECT status, channel, IFNULL(bank_name,'-'), submitted_amount, verified_amount,
        IFNULL(amount_adjustment_reason,'-'), IFNULL(rejection_reason,'-'), submitted_on_behalf,
        IFNULL(paid_by_role,'-'), proof_disk, proof_image_path <> '', verified_by IS NOT NULL,
        submitted_by_user_id IS NOT NULL, cash_received_by_user_id IS NOT NULL, IFNULL(receipt_reference,'-')
    FROM bank_transfer_verifications ORDER BY id"""
STATE_QUERIES["cod_proofs"] = """SELECT status, collected_amount, submitted_on_behalf, IFNULL(paid_by_role,'-'),
        confirmed_by IS NOT NULL, IFNULL(rejection_reason,'-') FROM cod_payment_proofs ORDER BY id"""
STATE_QUERIES["invoice"] = """SELECT version, `trigger`, total_amount, verified_paid_amount, payment_status
    FROM order_invoice_snapshots ORDER BY id"""
STATE_QUERIES["payments"] = """SELECT type, amount, status, IFNULL(raw_payload, ''), gateway_reference IS NOT NULL,
        paid_at IS NOT NULL FROM payment_transactions ORDER BY id"""
STATE_QUERIES["order"] = """SELECT status, payment_status, subtotal_amount, total_amount, paid_amount,
        remaining_amount, gross_received_amount, invoice_issued_at IS NOT NULL, IFNULL(CAST(due_date AS CHAR),'-')
    FROM orders ORDER BY id"""
STATE_QUERIES["due"] = """SELECT IFNULL(CAST(old_due_date AS CHAR),'-'), CAST(new_due_date AS CHAR), reason
    FROM order_due_date_changes ORDER BY id"""
STATE_QUERIES["media"] = """SELECT collection, mime_type, extension FROM media WHERE collection = 'cod_payment_proof'
    ORDER BY id"""
# Lifecycle in-app notifications (order.created / payment.proof_uploaded / payment.failed):
# recipients are seeded users (stable ids); volatile order/return numbers inside the body are
# normalised so only the behavioural contract (recipient, type, severity, title, text) is compared.
STATE_QUERIES["admin_notifications"] = """SELECT recipient_user_id, type, severity, title,
        REGEXP_REPLACE(body, '(D|RTN|RO)-[0-9]+', '\\\\1-#'), subject_type
    FROM admin_notifications ORDER BY id"""
f.state = lambda: {name: rows(sql) for name, sql in STATE_QUERIES.items()}


_VOLATILE = re.compile(r"(/payment-proofs/)\d+|(media/cod_payment_proof/)[0-9a-f-]+\.png|\b(MT|DP|ST|IN|CS|AP)-[A-Z0-9]{10}\b")


def scrub(value):
    """Drop what differs by construction: generated references, media filenames, row ids inside URLs, and the
    id-keyed delivery projections (their SHAPE is covered by compare_orders_read)."""
    if isinstance(value, dict):
        return {k: scrub(v) for k, v in value.items() if k not in ("delivery_groups", "shipment_delivery_dates")
                and k != "residual_of"}
    if isinstance(value, list):
        return [scrub(v) for v in value]
    if isinstance(value, str):
        return _VOLATILE.sub(lambda m: (m.group(1) or m.group(2) or m.group(3) + "-") + "<x>", value)
    return value


_orig_normalise = f.normalise_json_ids


def _normalise_ids(value):
    # The base normaliser already collapses `*_id` ints; `residual_of` is the one id-valued key without the suffix.
    if isinstance(value, dict):
        value = {k: ("<id>" if k == "residual_of" and isinstance(v, int) else v) for k, v in value.items()}
    return _orig_normalise(value)


f.normalise_json_ids = _normalise_ids


def scenario(name, run):
    out = {}
    for label, base in (("L", LARAVEL), ("G", GO)):
        reset()
        pairs = run(base)
        out[label] = ([p[0] for p in pairs], [p[1] for p in pairs], f.state())
    st_l, body_l, db_l = out["L"]
    st_g, body_g, db_g = out["G"]
    if st_l != st_g:
        check(name, False, f"statuses {st_l} vs {st_g} :: {json.dumps(body_l)[:350]} || {json.dumps(body_g)[:350]}")
        return
    for i, (bl, bg) in enumerate(zip(body_l, body_g)):
        diffs = diff(norm_body(scrub(bl)), norm_body(scrub(bg)))
        if diffs:
            check(f"{name} [response {i + 1}]", False, "; ".join(diffs[:6]))
            return
    diffs = diff(db_l, db_g)
    if diffs:
        check(f"{name} [db]", False, "; ".join(diffs[:8]))
        return
    check(name, True)


PNG = png()
FILES = {"proof": ("bukti.png", PNG)}


def main():
    seed_full()

    def proof_then_approve(base):
        oid = make_order(base)
        k, fin = client_for(base, KONSUMEN), client_for(base, KEUANGAN)
        out = [multipart(k, "POST", f"/api/v1/orders/{oid}/payment/proof", {"paid_by": "konsumen"}, FILES)]
        out.append(fin.call("POST", f"/api/v1/orders/{oid}/payment/verify", {"approved": True}))
        out.append(fin.call("POST", f"/api/v1/orders/{oid}/payment/verify", {"approved": True}))
        return out
    scenario("transfer proof -> approve -> replay refused", proof_then_approve)

    def proof_then_reject(base):
        oid = make_order(base)
        k, fin = client_for(base, KONSUMEN), client_for(base, KEUANGAN)
        out = [multipart(k, "POST", f"/api/v1/orders/{oid}/payment/proof", {}, FILES)]
        out.append(fin.call("POST", f"/api/v1/orders/{oid}/payment/verify", {"approved": False}))
        out.append(fin.call("POST", f"/api/v1/orders/{oid}/payment/verify",
                            {"approved": False, "rejection_reason": "buram"}))
        return out
    scenario("transfer proof -> reject needs reason, then rejected", proof_then_reject)

    def adjusted_amount(base):
        oid = make_order(base)
        k, fin = client_for(base, KONSUMEN), client_for(base, KEUANGAN)
        out = [multipart(k, "POST", f"/api/v1/orders/{oid}/payment/proof", {}, FILES)]
        out.append(fin.call("POST", f"/api/v1/orders/{oid}/payment/verify", {"approved": True, "verified_amount": 1000}))
        out.append(fin.call("POST", f"/api/v1/orders/{oid}/payment/verify",
                            {"approved": True, "verified_amount": 1000, "amount_adjustment_reason": "kurang transfer"}))
        return out
    scenario("verified_amount adjustment needs a reason", adjusted_amount)

    def proof_validation(base):
        oid = make_order(base)
        k = client_for(base, KONSUMEN)
        return [multipart(k, "POST", f"/api/v1/orders/{oid}/payment/proof", {}, {}),
                multipart(k, "POST", f"/api/v1/orders/{oid}/payment/proof", {"paid_by": "sales"}, FILES),
                multipart(k, "POST", f"/api/v1/orders/{oid}/payment/proof", {}, {"proof": ("x.txt", b"hello")})]
    scenario("proof validation + paid_by allowlist", proof_validation)

    def rbac(base):
        oid = make_order(base)
        s, a = client_for(base, SALES), client_for(base, ADMIN)
        multipart(client_for(base, KONSUMEN), "POST", f"/api/v1/orders/{oid}/payment/proof", {}, FILES)
        return [s.call("POST", f"/api/v1/orders/{oid}/payment/verify", {"approved": True}),
                a.call("POST", f"/api/v1/orders/{oid}/payment/verify", {"approved": True}),
                a.call("POST", f"/api/v1/orders/{oid}/payment/settle", {}),
                a.call("PATCH", f"/api/v1/orders/{oid}/payment/cod", {"paid": True})]
    scenario("sales/admin are never payment approvers", rbac)

    def cod_flow(base):
        oid = make_order(base, payment="cod", who=SALES, konsumen_id=17)
        fin = client_for(base, KEUANGAN)
        return [fin.call("PATCH", f"/api/v1/orders/{oid}/payment/cod", {}),
                fin.call("PATCH", f"/api/v1/orders/{oid}/payment/cod", {"paid": True}),
                fin.call("PATCH", f"/api/v1/orders/{oid}/payment/cod", {"paid": False}),
                fin.call("PATCH", f"/api/v1/orders/{oid}/payment/cod", {"paid": True})]
    scenario("COD mark paid -> undo -> paid again", cod_flow)

    def cod_not_cod(base):
        oid = make_order(base)
        return [client_for(base, KEUANGAN).call("PATCH", f"/api/v1/orders/{oid}/payment/cod", {"paid": True})]
    scenario("markCod refuses a non-COD order", cod_not_cod)

    def cod_proof_confirm(base):
        oid = make_order(base, payment="cod", who=SALES, konsumen_id=17)
        s, fin = client_for(base, SALES), client_for(base, KEUANGAN)
        out = [multipart(s, "POST", f"/api/v1/orders/{oid}/payment/cod-proof", {"paid_by": "sales", "amount": 30000}, FILES)]
        pid = db("SELECT id FROM cod_payment_proofs ORDER BY id DESC LIMIT 1")
        proof_id = pid[0][0] if pid else 999999
        out.append(fin.call("PATCH", f"/api/v1/admin/cod-payment-proofs/{proof_id}/confirm", {"confirmed": True}))
        out.append(fin.call("PATCH", f"/api/v1/admin/cod-payment-proofs/{proof_id}/confirm", {"confirmed": True}))
        return out
    scenario("COD proof (partial) -> confirm -> reconfirm refused", cod_proof_confirm)

    def cod_proof_reject(base):
        oid = make_order(base, payment="cod", who=SALES, konsumen_id=17)
        s, fin = client_for(base, SALES), client_for(base, KEUANGAN)
        out = [multipart(s, "POST", f"/api/v1/orders/{oid}/payment/cod-proof", {"paid_by": "sales"}, FILES)]
        proof_id = db("SELECT id FROM cod_payment_proofs ORDER BY id DESC LIMIT 1")[0][0]
        out.append(fin.call("PATCH", f"/api/v1/admin/cod-payment-proofs/{proof_id}/confirm", {"confirmed": False}))
        out.append(fin.call("PATCH", f"/api/v1/admin/cod-payment-proofs/{proof_id}/confirm",
                            {"confirmed": False, "rejection_reason": "foto tidak jelas"}))
        return out
    scenario("COD proof reject", cod_proof_reject)

    def installment_transfer(base):
        oid = make_order(base)
        set_status(oid)
        k = client_for(base, KONSUMEN)
        out = [k.call("POST", f"/api/v1/orders/{oid}/payment/installment", {"amount": 10000}),
               k.call("POST", f"/api/v1/orders/{oid}/payment/installment", {"amount": 10000}),
               k.call("POST", f"/api/v1/orders/{oid}/payment/installment", {"amount": 5000}),
               k.call("POST", f"/api/v1/orders/{oid}/payment/installment", {"amount": 0}),
               k.call("POST", f"/api/v1/orders/{oid}/payment/installment", {})]
        return out
    scenario("installment transfer + replay + conflict + validation", installment_transfer)

    def installment_cash(base):
        oid = make_order(base, who=SALES, konsumen_id=17)
        set_status(oid)
        s, fin = client_for(base, SALES), client_for(base, KEUANGAN)
        out = [s.call("POST", f"/api/v1/orders/{oid}/payment/installment", {"amount": 10000, "channel": "cash"}),
               s.call("POST", f"/api/v1/orders/{oid}/payment/installment",
                      {"amount": 10000, "channel": "cash", "receipt_reference": "KW-1"}),
               fin.call("POST", f"/api/v1/orders/{oid}/payment/verify", {"approved": True})]
        return out
    scenario("cash installment -> keuangan verifies", installment_cash)

    def installment_early(base):
        oid = make_order(base)
        return [client_for(base, KONSUMEN).call("POST", f"/api/v1/orders/{oid}/payment/installment", {"amount": 1000})]
    scenario("installment refused before fulfilment", installment_early)

    def dp_settle(base):
        oid = make_order(base, payment="down_payment", who=SALES, konsumen_id=17, dp=20000)
        s, fin = client_for(base, SALES), client_for(base, KEUANGAN)
        multipart(s, "POST", f"/api/v1/orders/{oid}/payment/proof", {"paid_by": "sales"}, FILES)
        out = [fin.call("POST", f"/api/v1/orders/{oid}/payment/verify", {"approved": True}),
               fin.call("POST", f"/api/v1/orders/{oid}/payment/settle", {}),
               fin.call("POST", f"/api/v1/orders/{oid}/payment/settle", {})]
        return out
    scenario("DP verify -> settle -> settle replay", dp_settle)

    def settle_not_dp(base):
        oid = make_order(base)
        return [client_for(base, KEUANGAN).call("POST", f"/api/v1/orders/{oid}/payment/settle", {})]
    scenario("settle refuses a non-DP order", settle_not_dp)

    def due_date(base):
        oid = make_order(base)
        a = client_for(base, ADMIN)
        return [a.call("PUT", f"/api/v1/orders/{oid}/due-date", {}),
                a.call("PUT", f"/api/v1/orders/{oid}/due-date", {"due_date": "2031-01-15", "reason": "negosiasi"}),
                a.call("PUT", f"/api/v1/orders/{oid}/due-date", {"due_date": "2031-01-15", "reason": "sama"}),
                a.call("PUT", f"/api/v1/orders/{oid}/due-date", {"due_date": "bukan tanggal", "reason": "x"}),
                client_for(base, SALES).call("PUT", f"/api/v1/orders/{oid}/due-date",
                                             {"due_date": "2031-01-15", "reason": "x"})]
    scenario("due date set / unchanged / invalid / forbidden", due_date)

    def proof_file(base):
        oid = make_order(base)
        k = client_for(base, KONSUMEN)
        multipart(k, "POST", f"/api/v1/orders/{oid}/payment/proof", {}, FILES)
        vid = db("SELECT id FROM bank_transfer_verifications ORDER BY id DESC LIMIT 1")[0][0]
        res = []
        for who in (KONSUMEN, KEUANGAN, SALES, SUPER):
            st, content, cache = raw_get(client_for(base, who), f"/api/v1/payment-proofs/{vid}")
            res.append((st, {"len": len(content) if st == 200 else 0, "cache": cache if st == 200 else "",
                             "body": content[:0].decode() if st == 200 else json.loads(content.decode() or "{}")}))
        st, content, _ = raw_get(client_for(base, SUPER), "/api/v1/payment-proofs/999999")
        res.append((st, json.loads(content.decode() or "{}")))
        return res
    scenario("proof download is ownership-gated and private", proof_file)

    failed = [r for r in f.results if not r[1]]
    print(f"{len(f.results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
