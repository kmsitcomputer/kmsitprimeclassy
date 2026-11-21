#!/usr/bin/env python3
"""Laravel ↔ Go comparison for the PER-ITEM FULFILLMENT lifecycle (4 routes).

    PATCH /orders/{order}/items/{item}/fulfillment          adjust the fulfilled quantity
    GET   /orders/{order}/items/{item}/cancel-eligibility   read-only cancellation projection
    POST  /orders/{order}/items/{item}/cancel-undelivered   cancel part of the undelivered remainder
    PATCH /orders/{order}/items/{item}/reschedule           re-date (optionally split) a line

Each scenario runs on BOTH runtimes from identical reset state and compares

  * the HTTP status and the response body (ids/timestamps normalised), and
  * the resulting DATABASE state for every table the operation may touch:
    order_items, orders, financial snapshots/units/bases, sales reductions, refund obligations
    + ledger events, item adjustments, additional payments, payment transactions, stock request
    demand + status, the Agent commitment reservation, shipments and the activity log.

That second half is the point: a quantity change is a financial event, and "the response looked
right" is not evidence that the ledger, the reservation and the frozen unit basis agree.

Usage: python3 tools/parity/compare_fulfillment.py
"""
import json
import os
import re
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

LARAVEL = os.environ.get("PARITY_LARAVEL_URL", "http://127.0.0.1:8899")
GO = os.environ.get("PARITY_GO_URL", "http://127.0.0.1:8082")
TS_RE = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$")

results = []


def check(name, ok, detail=""):
    results.append((name, ok, detail))
    if not ok:
        print(f"FAIL {name}: {detail}")


class Client:
    def __init__(self, base):
        import http.cookiejar
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def token(self):
        for c in self.jar:
            if c.name == "XSRF-TOKEN":
                return urllib.parse.unquote(c.value)
        return None

    def call(self, method, path, body=None, extra=None):
        h = {"Accept": "application/json", "Origin": self.base, "Referer": self.base + "/",
             "X-Requested-With": "XMLHttpRequest"}
        if extra:
            h.update(extra)
        data = None
        if body is not None:
            data = json.dumps(body).encode()
            h["Content-Type"] = "application/json"
        tok = self.token()
        if tok:
            h["X-XSRF-TOKEN"] = tok
        req = urllib.request.Request(self.base + path, data=data, headers=h, method=method)
        try:
            with self.opener.open(req, timeout=40) as res:
                raw = res.read().decode()
                return res.status, (json.loads(raw) if raw else None)
        except urllib.error.HTTPError as e:
            raw = e.read().decode()
            try:
                return e.code, (json.loads(raw) if raw else None)
            except json.JSONDecodeError:
                return e.code, {"_raw": raw}

    def login(self, email, password="Secret123!"):
        self.call("GET", "/sanctum/csrf-cookie")
        for _ in range(8):
            st, _ = self.call("POST", "/api/v1/auth/login", {"identifier": email, "password": password})
            if st != 429:
                return st
            time.sleep(10)
        return 429


def db(sql):
    sql = sql.replace(chr(96), chr(92) + chr(96))
    cmd = (f'mariadb -uprimeclassy_app -p"$(cat $MARIADB_PASSWORD_FILE)" -N -B primeclassy_testing '
           f'-e "{sql}"')
    out = subprocess.run(["docker", "exec", "primeclassy-mariadb", "sh", "-lc", cmd],
                         capture_output=True, text=True, timeout=90)
    if out.returncode != 0:
        raise RuntimeError(out.stderr[:400])
    return [l.split("\t") for l in out.stdout.strip().split("\n") if l]


def seed_full():
    for f in ("seed_test_fixtures.sql", "seed_inventory_fixtures.sql"):
        with open(f"tools/parity/{f}") as fh:
            cmd = 'mariadb -uprimeclassy_app -p"$(cat $MARIADB_PASSWORD_FILE)" primeclassy_testing'
            subprocess.run(["docker", "exec", "-i", "primeclassy-mariadb", "sh", "-lc", cmd],
                           stdin=fh, capture_output=True, text=True, timeout=120)


# Order-scoped tables in child → parent order. Every table that can hold a row referencing orders or
# order_items MUST be here, otherwise the next reset's `DELETE FROM orders` fails on the FK.
CLEAR = [
    "order_financial_snapshot_units", "order_item_financial_bases", "order_financial_snapshots",
    "order_item_adjustments", "order_additional_payments", "order_sales_reductions",
    "refund_ledger_events", "refund_payouts", "refund_obligations", "refund_recipient_evidences",
    "order_invoice_snapshots", "order_initial_report_items", "order_fulfillment_change_proposals",
    "order_due_date_changes", "consent_events", "delivery_refusals", "order_item_receipts",
    "return_items", "returns", "admin_notifications", "email_messages", "whatsapp_messages",
    "commissions", "commission_adjustments", "inventory_cancellation_reversals",
    "payment_transactions", "stock_request_proposal_items", "stock_request_proposals",
    "stock_request_fulfillments", "stock_request_items", "stock_requests", "shipments",
    "sub_stock_request_items", "sub_stock_requests", "sub_stock_reservations",
    "order_items", "orders", "stock_handovers", "stock_transfer_items", "stock_transfers",
    "stock_movements", "activity_logs", "media",
]


def reset():
    # A reschedule split leaves order_items referencing their own parent; clear the lineage first so the
    # table can be emptied without disabling FK checks.
    db("UPDATE order_items SET split_from_order_item_id = NULL")
    for t in CLEAR:
        try:
            db(f"DELETE FROM {t}")
        except RuntimeError as exc:
            if "1146" not in str(exc) and "doesn't exist" not in str(exc):
                raise
    db("DELETE FROM warehouse_stocks WHERE stock_type = 'shipping' OR id > 7")
    db("UPDATE warehouse_stocks SET quantity = 12 WHERE id = 2")
    db("UPDATE warehouse_stocks SET quantity = 4  WHERE id = 3")
    db("UPDATE warehouse_stocks SET quantity = 9  WHERE id = 4")
    db("UPDATE warehouse_stocks SET quantity = 6  WHERE id = 5")
    db("UPDATE warehouse_stocks SET quantity = 8  WHERE id = 6")
    db("UPDATE warehouse_stocks SET quantity = 3  WHERE id = 7")
    db("UPDATE product_stocks SET quantity_reserved = CASE product_id WHEN 1 THEN 5 WHEN 2 THEN 0 ELSE 3 END "
       "WHERE agent_id = 10")
    db("UPDATE product_variation_stocks SET quantity_reserved = CASE product_variation_id WHEN 1 THEN 2 ELSE 0 END "
       "WHERE agent_id = 10")
    db("UPDATE settings SET `value` = '0' WHERE `key` = 'orders.invoice_sequence'")


_clients = {}


def client_for(base, email):
    key = (base, email)
    if key not in _clients:
        c = Client(base)
        if c.login(email) != 200:
            raise SystemExit(f"login failed for {email} on {base}")
        _clients[key] = c
    return _clients[key]


SALES, AGEN, ADMIN, KONSUMEN, SUPER, KURIR = (
    "sales@imp010.test", "agen@imp010.test", "admin@imp010.test", "konsumen@imp010.test",
    "super@imp010.test", "kurir@imp010.test")


def create_order(base, quantity=2, payment="cod", shipping="pickup", konsumen_id=17, product_id=3):
    c = client_for(base, SALES)
    body = {
        "items": [{"product_id": product_id, "quantity": quantity}],
        "recipient_name": "Budi", "recipient_phone": "081200000000", "address_line": "Jl. Tes 1",
        "village_id": "32730101", "latitude": -6.9, "longitude": 107.6,
        "shipping_method": shipping, "payment_method_code": payment,
    }
    if konsumen_id:
        body["konsumen_id"] = konsumen_id
    st, resp = c.call("POST", "/api/v1/orders", body, {"Idempotency-Key": "ff-" + uuid.uuid4().hex})
    if st != 201:
        raise SystemExit(f"order creation failed on {base}: {st} {json.dumps(resp)[:400]}")
    ids = db("SELECT o.id FROM orders o ORDER BY o.id DESC LIMIT 1")[0][0]
    item = db(f"SELECT i.id FROM order_items i WHERE i.order_id = {ids} ORDER BY i.id")[0][0]
    return int(ids), int(item)


def order_base(oid, iid):
    return f"/api/v1/orders/{oid}/items/{iid}"


# ---- normalisation -------------------------------------------------------------------------------

VOLATILE_KEYS = {"id", "product_id", "agent_id", "created_at", "updated_at"}


def norm_body(value, key=None):
    if isinstance(value, dict):
        out = {}
        for k, v in sorted(value.items()):
            if k in VOLATILE_KEYS:
                out[k] = "<id>" if v is not None else None
            elif k.endswith("_id") and not k.endswith("_ids"):
                out[k] = "<id>" if v is not None else None
            elif k.endswith("_at"):
                out[k] = "<ts>" if v else None
            elif isinstance(v, (int, float)) and not isinstance(v, bool):
                out[k] = round(float(v), 2)
            else:
                out[k] = norm_body(v, k)
        return out
    if isinstance(value, list):
        return [norm_body(v) for v in value]
    if isinstance(value, str) and TS_RE.match(value):
        return "<ts>"
    if key == "proof_url" and isinstance(value, str):
        # Laravel's test server advertises its request host while Go uses its configured
        # canonical origin. The storage path is the behavioral contract under comparison.
        return re.sub(r"^https?://[^/]+", "<runtime-origin>", value)
    return value


def jsonify(cell):
    """Compare JSON columns as VALUES, not as bytes: PHP's json_encode keeps insertion order while Go's
    marshaller sorts keys, and both are the same document."""
    text = "" if cell == "NULL" else cell
    if text[:1] in ("{", "["):
        try:
            return squash(normalise_json_ids(json.loads(text)))
        except ValueError:
            return text
    return text


def normalise_json_ids(value):
    """Row ids differ between the two runtimes by construction; compare the SHAPE of the audit payload.

    The allocation checksum is a SHA-256 over a payload that CONTAINS the order id, so it necessarily
    differs; the snapshot table itself is compared by its structural columns instead."""
    if isinstance(value, dict):
        out = {}
        for k, v in value.items():
            if k == "checksum":
                out[k] = "<hash>"
            elif k == "obligation_key" and isinstance(v, str):
                out[k] = re.sub(r"\d+", "<id>", v)
            elif k.endswith("_id") and isinstance(v, int):
                out[k] = "<id>"
            else:
                out[k] = normalise_json_ids(v)
        return out
    if isinstance(value, list):
        return [normalise_json_ids(v) for v in value]
    return value


def squash(value):
    if isinstance(value, dict):
        return {k: squash(v) for k, v in value.items()}
    if isinstance(value, list):
        return [squash(v) for v in value]
    if isinstance(value, float):
        return round(value, 2)
    return value


def rows(sql):
    return [tuple(jsonify(c) for c in row) for row in db(sql)]


STATE_QUERIES = {
    "items": """SELECT status, stock_source, original_quantity, fulfilled_quantity, cancelled_quantity,
            returned_quantity, refund_quantity, additional_quantity, unit_price_snapshot, courier_fee_amount,
            subtotal_snapshot, split_from_order_item_id IS NOT NULL, shipment_id IS NOT NULL,
            IFNULL(CAST(requested_delivery_date AS CHAR), '-')
        FROM order_items ORDER BY id""",
    "order": """SELECT status, payment_status, subtotal_amount, total_amount, paid_amount, remaining_amount,
            discount_amount, voucher_id IS NOT NULL, invoice_issued_at IS NOT NULL, gross_received_amount,
            IFNULL(cancellation_financial_status, '-')
        FROM orders ORDER BY id""",
    "snapshots": """SELECT snapshot_version, `trigger`, provenance, subtotal_basis, effective_voucher_basis,
            allocation_method, voucher_source_type IS NULL, IFNULL(metadata, '')
        FROM order_financial_snapshots ORDER BY id""",
    "units": """SELECT order_item_id = (SELECT MIN(id) FROM order_items), unit_index, unit_gross,
            allocated_discount, unit_net, consumed_at IS NOT NULL, IFNULL(consumption_seq, 0),
            IFNULL(consumed_by_type, '-')
        FROM order_financial_snapshot_units ORDER BY id""",
    "bases": """SELECT qty_basis, unit_gross_basis, line_gross_basis, allocated_discount_total, line_net_basis,
            consumed_units_count, consumed_gross_total, consumed_discount_total, consumed_net_total
        FROM order_item_financial_bases ORDER BY id""",
    "reductions": """SELECT source_type, amount, applied_to_order_total, provenance, source_id IS NOT NULL
        FROM order_sales_reductions ORDER BY id""",
    "obligations": """SELECT source_type, amount, status, provenance, IFNULL(approval_source, '-'),
            deferred_amount, deferred_remaining, IFNULL(deferred_reason, '-'), IFNULL(reason, '-'),
            IFNULL(metadata, ''), obligation_key LIKE 'shortfall:%', obligation_key LIKE 'adjustment:%'
        FROM refund_obligations ORDER BY id""",
    "events": """SELECT event_type, IFNULL(reason, '-'), IFNULL(payload, ''), actor_type
        FROM refund_ledger_events ORDER BY id""",
    "adjustments": """SELECT quantity_reduced, reason, refund_amount, refund_status
        FROM order_item_adjustments ORDER BY id""",
    "additional": """SELECT method, amount, reason, status, payment_transaction_id IS NOT NULL
        FROM order_additional_payments ORDER BY id""",
    "payments": """SELECT type, amount, status, IFNULL(raw_payload, ''), gateway_reference IS NOT NULL
        FROM payment_transactions ORDER BY id""",
    "demand": """SELECT requested_qty, fulfilled_qty, remaining_qty, damaged_hold_qty,
            order_item_id = (SELECT MIN(id) FROM order_items)
        FROM stock_request_items ORDER BY id""",
    "requests": """SELECT status, fulfilled_at IS NOT NULL FROM stock_requests ORDER BY id""",
    "reserved": """SELECT product_id, quantity_reserved FROM product_stocks WHERE agent_id = 10 ORDER BY product_id""",
    "shipments": """SELECT delivery_mode, IFNULL(shipping_provider_code, '-'), self_delivered_by_user_id IS NOT NULL,
            status, IFNULL(tracking_number, '-'), courier_id IS NOT NULL
        FROM shipments ORDER BY id""",
    "activity": """SELECT event, subject_type, IFNULL(description, '-'), IFNULL(properties, ''),
            causer_id IS NOT NULL
        FROM activity_logs ORDER BY id""",
}


def state():
    out = {}
    for name, sql in STATE_QUERIES.items():
        out[name] = rows(sql)
    return out


def diff(a, b, path="", out=None):
    if out is None:
        out = []
    if type(a) is not type(b) and not (isinstance(a, (int, float)) and isinstance(b, (int, float))):
        out.append(f"{path or '$'}: type {type(a).__name__} vs {type(b).__name__} ({a!r} vs {b!r})")
        return out
    if isinstance(a, dict):
        for key in sorted(set(a) | set(b)):
            child = f"{path}.{key}" if path else key
            if key not in a:
                out.append(f"{child}: missing in Laravel")
            elif key not in b:
                out.append(f"{child}: missing in Go")
            else:
                diff(a[key], b[key], child, out)
        return out
    if isinstance(a, (list, tuple)):
        if len(a) != len(b):
            out.append(f"{path}: length {len(a)} vs {len(b)}")
            return out
        for i, (x, y) in enumerate(zip(a, b)):
            diff(x, y, f"{path}[{i}]", out)
        return out
    if isinstance(a, float) and isinstance(b, float):
        if abs(a - b) > 1e-9:
            out.append(f"{path}: {a} vs {b}")
        return out
    if a != b:
        out.append(f"{path}: {a!r} vs {b!r}")
    return out


def scenario(name, run, compare_state=True):
    out = {}
    for label, base in (("L", LARAVEL), ("G", GO)):
        reset()
        pairs = run(base)
        out[label] = ([r[0] for r in pairs], [r[1] for r in pairs], state())

    st_l, body_l, db_l = out["L"]
    st_g, body_g, db_g = out["G"]
    if st_l != st_g:
        check(name, False, f"statuses {st_l} vs {st_g} :: {json.dumps(body_l)[:400]} || {json.dumps(body_g)[:400]}")
        return
    for i, (bl, bg) in enumerate(zip(body_l, body_g)):
        diffs = diff(norm_body(bl), norm_body(bg))
        if diffs:
            check(f"{name} [response {i + 1}]", False, "; ".join(diffs[:6]))
            return
    if compare_state:
        diffs = diff(db_l, db_g)
        if diffs:
            check(f"{name} [db]", False, "; ".join(diffs[:8]))
            return
    check(name, True)


def main():
    seed_full()

    # --- adjust: reduce, then increase back ------------------------------------------------------
    def adjust_roundtrip(base):
        oid, iid = create_order(base)
        c = client_for(base, AGEN)
        p = order_base(oid, iid)
        out = [c.call("PATCH", p + "/fulfillment", {"fulfilled_quantity": 1, "reason": "barang kurang satu"})]
        out.append(c.call("PATCH", p + "/fulfillment", {"fulfilled_quantity": 2, "reason": "tambah lagi"}))
        out.append(c.call("PATCH", p + "/fulfillment", {"fulfilled_quantity": 2, "reason": "noop"}))
        return out
    scenario("adjust reduce → increase → no-op", adjust_roundtrip)

    # --- adjust: full reduction turns the line terminal, then increase is refused ----------------
    def adjust_full_then_increase(base):
        oid, iid = create_order(base)
        c = client_for(base, AGEN)
        p = order_base(oid, iid)
        out = [c.call("PATCH", p + "/fulfillment", {"fulfilled_quantity": 0, "reason": "batal semua"})]
        out.append(c.call("PATCH", p + "/fulfillment", {"fulfilled_quantity": 1, "reason": "coba hidupkan"}))
        return out
    scenario("adjust to zero → increase refused", adjust_full_then_increase)

    # --- adjust: the diproses window is closed for a transfer order (status diterima) -----------
    def adjust_window_closed(base):
        oid, iid = create_order(base, payment="bank_transfer")
        c = client_for(base, AGEN)
        return [c.call("PATCH", order_base(oid, iid) + "/fulfillment",
                       {"fulfilled_quantity": 1, "reason": "x"})]
    scenario("adjust refused outside diproses", adjust_window_closed)

    # --- adjust: validation sentences match byte for byte --------------------------------------
    def adjust_validation(base):
        oid, iid = create_order(base)
        c = client_for(base, AGEN)
        p = order_base(oid, iid)
        return [
            c.call("PATCH", p + "/fulfillment", {}),
            c.call("PATCH", p + "/fulfillment", {"fulfilled_quantity": "abc", "reason": "x"}),
            c.call("PATCH", p + "/fulfillment", {"fulfilled_quantity": -1, "reason": "x"}),
            c.call("PATCH", p + "/fulfillment", {"fulfilled_quantity": 1, "reason": "x",
                                                 "additional_payment_method": "bitcoin"}),
        ]
    scenario("adjust validation", adjust_validation)

    # --- adjust: another branch's admin/agen is refused ---------------------------------------
    def adjust_foreign_branch(base):
        oid, iid = create_order(base)
        c = client_for(base, KONSUMEN)
        return [c.call("PATCH", order_base(oid, iid) + "/fulfillment",
                       {"fulfilled_quantity": 1, "reason": "x"}),
                c.call("GET", order_base(oid, iid) + "/cancel-eligibility")]
    scenario("fulfillment is refused to a konsumen", adjust_foreign_branch)

    # --- cancel-eligibility + cancel-undelivered ----------------------------------------------
    def cancel_flow(base):
        oid, iid = create_order(base)
        c = client_for(base, AGEN)
        p = order_base(oid, iid)
        out = [c.call("GET", p + "/cancel-eligibility")]
        out.append(c.call("POST", p + "/cancel-undelivered",
                          {"quantity": 1, "expected_quantity": 2, "reason": "tidak jadi dikirim"}))
        out.append(c.call("GET", p + "/cancel-eligibility"))
        return out
    scenario("cancel-undelivered partial", cancel_flow)

    def cancel_stale(base):
        oid, iid = create_order(base)
        c = client_for(base, AGEN)
        p = order_base(oid, iid)
        return [c.call("POST", p + "/cancel-undelivered",
                       {"quantity": 1, "expected_quantity": 1, "reason": "stale"})]
    scenario("cancel-undelivered stale quantity", cancel_stale)

    def cancel_then_eligibility(base):
        oid, iid = create_order(base)
        c = client_for(base, AGEN)
        p = order_base(oid, iid)
        out = [c.call("POST", p + "/cancel-undelivered",
                      {"quantity": 2, "expected_quantity": 2, "reason": "batal semua"})]
        out.append(c.call("GET", p + "/cancel-eligibility"))
        out.append(c.call("POST", p + "/cancel-undelivered",
                          {"quantity": 1, "expected_quantity": 2, "reason": "lagi"}))
        return out
    scenario("cancel-undelivered full then refused", cancel_then_eligibility)

    def cancel_validation(base):
        oid, iid = create_order(base)
        c = client_for(base, AGEN)
        p = order_base(oid, iid)
        return [
            c.call("POST", p + "/cancel-undelivered", {}),
            c.call("POST", p + "/cancel-undelivered", {"quantity": 0, "expected_quantity": 0, "reason": ""}),
        ]
    scenario("cancel-undelivered validation", cancel_validation)

    # --- reschedule: the delivery-method allowlist --------------------------------------------
    def reschedule_pickup_refused(base):
        oid, iid = create_order(base)
        date = time.strftime("%Y-%m-%d", time.localtime(time.time() + 86400 * 3))
        c = client_for(base, AGEN)
        return [c.call("PATCH", order_base(oid, iid) + "/reschedule",
                       {"requested_delivery_date": date, "reason": "pindah hari"})]
    scenario("reschedule refused for pickup", reschedule_pickup_refused)

    def reschedule_validation(base):
        oid, iid = create_order(base)
        c = client_for(base, AGEN)
        p = order_base(oid, iid)
        return [
            c.call("PATCH", p + "/reschedule", {}),
            c.call("PATCH", p + "/reschedule", {"requested_delivery_date": "2020-01-01", "reason": "x"}),
            c.call("PATCH", p + "/reschedule", {"requested_delivery_date": "notadate", "reason": "x"}),
            c.call("PATCH", p + "/reschedule", {"requested_delivery_date": "2030-01-01", "reason": "x",
                                                "quantity": 0}),
        ]
    scenario("reschedule validation", reschedule_validation)

    # A Kurir Online shipment is what the allowlist accepts; the creation path for it needs an
    # external quote, so the shipment is re-labelled IN THE DATABASE identically for both runtimes.
    def reschedule_openroute(base, split=None):
        oid, iid = create_order(base)
        db(f"UPDATE shipments SET shipping_provider_code = 'openroute', shipping_provider_id = 2 "
           f"WHERE order_id = {oid}")
        date = time.strftime("%Y-%m-%d", time.localtime(time.time() + 86400 * 4))
        payload = {"requested_delivery_date": date, "reason": "pindah hari"}
        if split is not None:
            payload["quantity"] = split
        c = client_for(base, AGEN)
        return [c.call("PATCH", order_base(oid, iid) + "/reschedule", payload)]
    scenario("reschedule a Kurir Online line", lambda base: reschedule_openroute(base))
    scenario("reschedule part of a line (split)", lambda base: reschedule_openroute(base, split=1))

    # --- CSRF: every new route must still answer 419 without a token ---------------------------
    def csrf(base):
        oid, iid = create_order(base)
        p = order_base(oid, iid)
        out = []
        for method, path, body in (("PATCH", p + "/fulfillment", {"fulfilled_quantity": 1}),
                                   ("PATCH", p + "/reschedule", {"requested_delivery_date": "2030-01-01"}),
                                   ("POST", p + "/cancel-undelivered", {"quantity": 1})):
            req = urllib.request.Request(base + path, data=json.dumps(body).encode(), method=method,
                                         headers={"Accept": "application/json",
                                                  "Content-Type": "application/json"})
            try:
                with urllib.request.urlopen(req, timeout=20) as res:
                    out.append((res.status, None))
            except urllib.error.HTTPError as e:
                out.append((e.code, None))
        return out
    scenario("CSRF is enforced on the mutating routes", csrf, compare_state=False)

    failed = [r for r in results if not r[1]]
    print(f"{len(results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
