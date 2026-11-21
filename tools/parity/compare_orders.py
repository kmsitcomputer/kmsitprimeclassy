#!/usr/bin/env python3
"""Laravel ↔ Go comparison for POST /api/v1/orders — the order-creation chain.

What this verifies (and why each part matters):

  1. THE RESPONSE BODY, normalised only for genuinely non-deterministic values (ids, the invoice number, the random
     gateway reference, timestamps). Money, quantities, statuses, fee amounts, delivery groups and the payment
     summary must match EXACTLY — a difference here is a financial divergence.
  2. THE DATABASE STATE the creation produced: the order row's totals and snapshots, the order items with their
     price/fee snapshots, the commission rows (who earns what), the shipments (grouping + fee snapshot on the first
     one only), the stock request (Agent items only), the payment transaction, the initial report snapshot and the
     Agent reservation quantity. Response-only comparison would miss, for example, a commission paid to the wrong
     beneficiary.
  3. IDEMPOTENCY: replaying the same Idempotency-Key returns the SAME order (no second row, no second invoice
     sequence number, no double reservation), and the replay is verified AFTER the order advanced status.
  4. FAILURE ATOMICITY: an order that fails on insufficient stock leaves NOTHING behind — no order row, no
     reservation, no burned invoice number, no voucher redemption.

Every scenario reseeds the order-related tables, because both runtimes write to the same isolated database and the
two runs must start from identical state.

Usage: python3 tools/parity/compare_orders.py
"""
import http.cookiejar
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
    # Print immediately: a later crash must not hide the failures found before it.
    if not ok:
        print(f"FAIL {name}: {detail}")


class Client:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def token(self):
        for c in self.jar:
            if c.name == "XSRF-TOKEN":
                return urllib.parse.unquote(c.value)
        return None

    def call(self, method, path, body=None, headers=None):
        h = {"Accept": "application/json", "Origin": self.base, "Referer": self.base + "/",
             "X-Requested-With": "XMLHttpRequest"}
        if headers:
            h.update(headers)
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
    """Load both fixture files ONCE. They DELETE FROM users, which cascades to the sessions table — so this must
    happen before any login, never between scenarios."""
    for f in ("seed_test_fixtures.sql", "seed_inventory_fixtures.sql"):
        with open(f"tools/parity/{f}") as fh:
            cmd = ('mariadb -uprimeclassy_app -p"$(cat $MARIADB_PASSWORD_FILE)" primeclassy_testing')
            subprocess.run(["docker", "exec", "-i", "primeclassy-mariadb", "sh", "-lc", cmd],
                           stdin=fh, capture_output=True, text=True, timeout=120)


def seed():
    """Reset only what an ORDER writes, so the fixture users (and therefore the live sessions) survive.

    Reseeding the users between scenarios would invalidate both runtimes' session cookies mid-run and every later
    request would answer 419 — a harness artefact that looks exactly like a CSRF regression.
    """
    db("""DELETE FROM commissions""")
    db("""DELETE FROM order_initial_report_items""")
    db("""DELETE FROM stock_request_items""")
    db("""DELETE FROM stock_requests""")
    db("""DELETE FROM payment_transactions""")
    db("""DELETE FROM shipments""")
    db("""DELETE FROM order_items""")
    db("""DELETE FROM orders""")
    db("""DELETE FROM sub_stock_reservations""")
    db("""UPDATE product_stocks SET quantity_reserved = CASE product_id WHEN 1 THEN 5 WHEN 2 THEN 0 ELSE 3 END
             WHERE agent_id = 10""")
    db("""UPDATE product_variation_stocks SET quantity_reserved = CASE product_variation_id WHEN 1 THEN 2 ELSE 0 END
             WHERE agent_id = 10""")
    db("""UPDATE vouchers SET used_count = 0""")
    db("""UPDATE settings SET `value` = '0' WHERE `key` = 'orders.invoice_sequence'""")


DEST = {"recipient_name": "Budi", "recipient_phone": "081200000000", "address_line": "Jl. Tes 1",
        "village_id": "32730101", "latitude": -6.9, "longitude": 107.6}


def order_body(**overrides):
    body = {"items": [{"product_id": 3, "quantity": 2}], "shipping_method": "pickup",
            "payment_method_code": "bank_transfer"}
    body.update(DEST)
    body.update(overrides)
    return body


VOLATILE_KEYS = {"id", "order_no", "created_at", "payment_transaction", "viewer_can_download_invoice"}
VOLATILE_ITEM_KEYS = {"id", "shipment_id"}
# Row ids inside delivery_groups are volatile too, but how MANY items/shipments a group holds is the actual
# grouping assertion — so the lists are replaced by their length rather than dropped.
VOLATILE_LIST_KEYS = {"item_ids", "shipment_ids"}


def normalise(value, parent=None):
    if isinstance(value, dict):
        out = {}
        for k, v in sorted(value.items()):
            if parent is None and k in VOLATILE_KEYS:
                continue
            if parent == "items" and k in VOLATILE_ITEM_KEYS:
                continue
            if k in VOLATILE_LIST_KEYS and isinstance(v, list):
                out[k] = f"<list of {len(v)}>"
                continue
            if k == "shipment_delivery_dates" and isinstance(v, dict):
                out[k] = [normalise(x) for x in v.values()]
                continue
            out[k] = normalise(v, k)
        return out
    if isinstance(value, list):
        return [normalise(v, parent) for v in value]
    if isinstance(value, str) and TS_RE.match(value):
        return "<timestamp>"
    if isinstance(value, bool):
        return value
    if isinstance(value, (int, float)):
        return float(value)
    return value


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
    if isinstance(a, list):
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


def order_state():
    """The DB state one created order produces, in a comparable form."""
    rows = db("""SELECT o.status, o.payment_status,
        CAST(o.subtotal_amount AS CHAR), CAST(o.shipping_fee_amount AS CHAR), CAST(o.admin_fee_amount AS CHAR),
        CAST(o.discount_amount AS CHAR), CAST(o.total_amount AS CHAR), o.voucher_id,
        CAST(o.dp_amount AS CHAR), CAST(o.paid_amount AS CHAR), CAST(o.remaining_amount AS CHAR),
        o.recipient_name_snapshot, o.village_snapshot, o.district_snapshot, o.regency_snapshot, o.province_snapshot,
        o.village_id, o.payment_method_id
        FROM orders o ORDER BY o.id""")
    items = db("""SELECT i.product_id, i.product_variation_id, i.stock_source, i.sub_location_id,
        i.product_name_snapshot, i.variation_label_snapshot, i.sku_snapshot,
        CAST(i.unit_price_snapshot AS CHAR), CAST(i.agent_fee_amount AS CHAR), CAST(i.sales_fee_amount AS CHAR),
        CAST(i.courier_fee_amount AS CHAR), CAST(i.subtotal_snapshot AS CHAR),
        i.original_quantity, i.fulfilled_quantity, i.status
        FROM order_items i JOIN orders o ON o.id = i.order_id ORDER BY o.id, i.id""")
    commissions = db("""SELECT c.beneficiary_role, c.beneficiary_user_id, CAST(c.amount AS CHAR), c.status
        FROM commissions c ORDER BY c.id""")
    shipments = db("""SELECT s.shipping_provider_code, s.status, s.delivery_mode, s.self_delivered_by_user_id,
        CAST(s.shipping_fee_snapshot AS CHAR), s.courier_id
        FROM shipments s ORDER BY s.id""")
    stock_requests = db("""SELECT r.status, i.product_id, i.requested_qty, i.fulfilled_qty, i.remaining_qty
        FROM stock_requests r JOIN stock_request_items i ON i.stock_request_id = r.id ORDER BY r.id, i.id""")
    payments = db("""SELECT p.type, CAST(p.amount AS CHAR), p.status
        FROM payment_transactions p ORDER BY p.id""")
    snapshots = db("""SELECT s.order_no = o.order_no, s.initial_quantity, CAST(s.unit_price_snapshot AS CHAR),
        CAST(s.invoice_total_snapshot AS CHAR), CAST(s.initial_remaining_snapshot AS CHAR),
        s.initial_payment_status, s.payment_method_code_snapshot, s.item_position, s.category_name_snapshot
        FROM order_initial_report_items s JOIN orders o ON o.id = s.order_id ORDER BY s.id""")
    reservations = db("""SELECT p.quantity_reserved FROM product_stocks p WHERE p.agent_id = 10 ORDER BY p.product_id""")
    sub_res = db("""SELECT r.product_id, r.product_variation_id, r.quantity, r.status
        FROM sub_stock_reservations r ORDER BY r.id""")
    invoice_sequence = db("SELECT `value` FROM settings WHERE `key` = 'orders.invoice_sequence'")
    notifications = db("""SELECT recipient_user_id,type,severity,title,body,subject_type
        FROM admin_notifications WHERE order_id IN (SELECT id FROM orders) ORDER BY recipient_user_id""")
    return {
        "orders": rows, "items": items, "commissions": commissions, "shipments": shipments,
        "stock_requests": stock_requests, "payment_transactions": payments, "snapshots": snapshots,
        "agent_reserved": reservations, "sub_reservations": sub_res, "invoice_sequence": invoice_sequence,
        "admin_notifications": notifications,
    }


_clients = {}


def client_for(base, email):
    key = (base, email)
    if key not in _clients:
        c = Client(base)
        if email and c.login(email) != 200:
            raise SystemExit(f"login failed for {email} on {base}")
        _clients[key] = c
    return _clients[key]


def create(base, email, body, key):
    c = client_for(base, email)
    return c.call("POST", "/api/v1/orders", body, {"Idempotency-Key": key})


def compare_create(name, body, email="konsumen@imp010.test"):
    """Create the same order on both runtimes from identical state and compare body + DB state."""
    seed()
    key_l = "cmp-" + uuid.uuid4().hex
    st_l, body_l = create(LARAVEL, email, body, key_l)
    state_l = order_state()

    seed()
    key_g = "cmp-" + uuid.uuid4().hex
    st_g, body_g = create(GO, email, body, key_g)
    state_g = order_state()

    if st_l != st_g:
        check(name, False, f"status {st_l} vs {st_g}: {json.dumps(body_l)[:250]} | {json.dumps(body_g)[:250]}")
        return None, None

    if st_l >= 200 and st_l < 300:
        diffs = diff(normalise(body_l.get("data")), normalise(body_g.get("data")))
        check(name + " [response]", not diffs, "; ".join(diffs[:6]))
        state_diffs = diff(state_l, state_g)
        check(name + " [db state]", not state_diffs, "; ".join(state_diffs[:6]))
    else:
        check(name + " [error body]", body_l == body_g,
              f"{json.dumps(body_l)[:250]} | {json.dumps(body_g)[:250]}")
    return st_l, body_l


def main_unlocked():
    seed_full()

    # --- happy paths -------------------------------------------------------------------------------
    compare_create("pickup + bank transfer", order_body())
    # COD and DP are not offered to a konsumen (Improvement 04), so a staff actor places these — and for a
    # konsumen inside their own network, which is the same order shape the UI produces.
    compare_create("COD starts in diproses (staff)", order_body(payment_method_code="cod", konsumen_id=17),
                   email="sales@imp010.test")
    compare_create("address book destination", {"items": [{"product_id": 3, "quantity": 1}],
                                                "address_id": 1, "shipping_method": "pickup",
                                                "payment_method_code": "bank_transfer"})
    compare_create("variation line", order_body(items=[{"product_id": 2, "product_variation_id": 2, "quantity": 1}]))
    compare_create("two lines, one shipment group",
                   order_body(items=[{"product_id": 3, "quantity": 1},
                                     {"product_id": 2, "product_variation_id": 2, "quantity": 2}]))
    compare_create("delivery date grouping", order_body(delivery_date=time.strftime("%Y-%m-%d", time.localtime(time.time() + 86400))))
    compare_create("fixed voucher", order_body(voucher_code="HEMAT10K"))
    compare_create("percentage voucher", order_body(voucher_code="PERSEN10"))
    compare_create("staff ordering for a konsumen", order_body(konsumen_id=17), email="sales@imp010.test")
    compare_create("staff buying for themselves (self-purchase)",
                   {k: v for k, v in order_body().items() if k != "konsumen_id"}, email="sales@imp010.test")

    # --- DP --------------------------------------------------------------------------------------
    seed()
    st_l, body_l = create(LARAVEL, "sales@imp010.test",
                          order_body(payment_method_code="down_payment", dp_amount=5000, konsumen_id=17),
                          "cmp-" + uuid.uuid4().hex)
    state_l = order_state()
    seed()
    st_g, body_g = create(GO, "sales@imp010.test",
                          order_body(payment_method_code="down_payment", dp_amount=5000, konsumen_id=17),
                          "cmp-" + uuid.uuid4().hex)
    state_g = order_state()
    check("down payment accepted", st_l == st_g == 201, f"{st_l} vs {st_g}: {json.dumps(body_g)[:200]}")
    if st_l == 201 and st_g == 201:
        diffs = diff(normalise(body_l["data"]), normalise(body_g["data"]))
        check("down payment [response]", not diffs, "; ".join(diffs[:5]))
        state_diffs = diff(state_l, state_g)
        check("down payment [db state]", not state_diffs, "; ".join(state_diffs[:5]))

    # --- failures ---------------------------------------------------------------------------------
    compare_create("insufficient stock (available 0)", order_body(items=[{"product_id": 1, "quantity": 1}]))
    compare_create("insufficient stock (over available)", order_body(items=[{"product_id": 3, "quantity": 100}]))
    compare_create("unknown voucher", order_body(voucher_code="NOPE"))
    compare_create("voucher inapplicable to cart",
                   order_body(items=[{"product_id": 3, "quantity": 1}], voucher_code="HANYA-LUMPUR"))
    compare_create("missing payment method", {k: v for k, v in order_body().items() if k != "payment_method_code"})
    compare_create("invalid payment method", order_body(payment_method_code="cash"))
    compare_create("variation required", order_body(items=[{"product_id": 2, "quantity": 1}]))
    compare_create("variation not allowed", order_body(items=[{"product_id": 3, "product_variation_id": 1, "quantity": 1}]))
    compare_create("dp amount above total (staff)",
                   order_body(payment_method_code="down_payment", dp_amount=999999, konsumen_id=17),
                   email="sales@imp010.test")
    compare_create("empty items", {"items": [], "shipping_method": "pickup", "payment_method_code": "bank_transfer", **DEST})
    compare_create("guest cannot order", order_body(), email=None)

    # --- idempotency -------------------------------------------------------------------------------
    seed()
    key = "idem-" + uuid.uuid4().hex
    st1, first = create(LARAVEL, "konsumen@imp010.test", order_body(), key)
    if st1 != 201 or not first or not first.get("data"):
        check("idempotency setup (laravel create)", False, f"{st1}: {json.dumps(first)[:300]}")
        return summary()
    # Advance the order so a replay is not simply matching a freshly-created row.
    db(f"UPDATE orders SET status = 'diproses' WHERE id = {first['data']['id']}")
    st2, replay = create(LARAVEL, "konsumen@imp010.test", order_body(), key)
    laravel_orders = db("SELECT COUNT(*) FROM orders")
    laravel_seq = db("SELECT `value` FROM settings WHERE `key` = 'orders.invoice_sequence'")

    seed()
    key = "idem-" + uuid.uuid4().hex
    g1, go_first = create(GO, "konsumen@imp010.test", order_body(), key)
    if g1 != 201 or not go_first or not go_first.get("data"):
        check("idempotency setup (go create)", False, f"{g1}: {json.dumps(go_first)[:300]}")
        return summary()
    db(f"UPDATE orders SET status = 'diproses' WHERE id = {go_first['data']['id']}")
    g2, go_replay = create(GO, "konsumen@imp010.test", order_body(), key)
    go_orders = db("SELECT COUNT(*) FROM orders")
    go_seq = db("SELECT `value` FROM settings WHERE `key` = 'orders.invoice_sequence'")

    check("idempotent replay returns 201", g2 == 201 and st2 == 201, f"{st2} vs {g2}")
    check("idempotent replay returns the SAME order",
          go_replay["data"]["id"] == go_first["data"]["id"] and
          go_replay["data"]["order_no"] == go_first["data"]["order_no"],
          f"{go_first['data']['order_no']} vs {go_replay['data']['order_no']}")
    check("idempotent replay creates no second order", go_orders == laravel_orders == [["1"]],
          f"laravel={laravel_orders} go={go_orders}")
    check("idempotent replay burns no invoice number", go_seq == laravel_seq == [["1"]],
          f"laravel={laravel_seq} go={go_seq}")

    # --- atomicity ---------------------------------------------------------------------------------
    seed()
    create(GO, "konsumen@imp010.test", order_body(items=[{"product_id": 3, "quantity": 100}]),
           "atom-" + uuid.uuid4().hex)
    state = order_state()
    check("failed order leaves no order row", state["orders"] == [], str(state["orders"]))
    check("failed order reserves nothing", state["agent_reserved"] == [["5"], ["0"], ["3"]], str(state["agent_reserved"]))
    check("failed order burns no invoice number", state["invoice_sequence"] == [["0"]], str(state["invoice_sequence"]))
    check("failed order writes no snapshot", state["snapshots"] == [], str(state["snapshots"]))

    seed()
    create(GO, "konsumen@imp010.test", order_body(voucher_code="HEMAT10K", items=[{"product_id": 1, "quantity": 1}]),
           "atom2-" + uuid.uuid4().hex)
    vouchers = db("SELECT used_count FROM vouchers ORDER BY id")
    check("failed order does not redeem the voucher", vouchers == [["0"], ["0"], ["0"]], str(vouchers))

    return summary()


def summary():
    failed = [r for r in results if not r[1]]
    print(f"{len(results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


class TestDatabaseLock:
    """Hold the repository named test-run lock for this mutating differential harness."""

    def __enter__(self):
        command = 'mariadb --unbuffered -N -B -uprimeclassy_app -p"$(cat $MARIADB_PASSWORD_FILE)" primeclassy_testing'
        self.process = subprocess.Popen(
            ["docker", "exec", "-i", "primeclassy-mariadb", "sh", "-lc", command],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, bufsize=1,
        )
        assert self.process.stdin is not None and self.process.stdout is not None
        self.process.stdin.write("SELECT GET_LOCK('primeclassy:testrun:primeclassy_testing',0);\n")
        self.process.stdin.flush()
        result = self.process.stdout.readline().strip()
        if result != "1":
            self.process.kill()
            self.process.wait(timeout=20)
            raise RuntimeError(f"primeclassy_testing named lock unavailable: {result!r}")
        return self

    def __exit__(self, exc_type, exc, tb):
        if self.process.stdin is not None:
            try:
                if self.process.poll() is None:
                    self.process.stdin.write("SELECT RELEASE_LOCK('primeclassy:testrun:primeclassy_testing');\n")
                    self.process.stdin.flush()
            except BrokenPipeError:
                # If the client is already gone, MariaDB has released the connection lock.
                pass
            finally:
                self.process.stdin.close()
        try:
            self.process.wait(timeout=20)
        except subprocess.TimeoutExpired:
            self.process.kill()
            self.process.wait(timeout=20)


def main():
    with TestDatabaseLock():
        return main_unlocked()


if __name__ == "__main__":
    sys.exit(main())
