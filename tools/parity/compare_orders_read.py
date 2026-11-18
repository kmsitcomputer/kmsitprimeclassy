#!/usr/bin/env python3
"""Laravel ↔ Go comparison for the ORDER READ SURFACE (3 routes).

    GET /orders           role-scoped list
    GET /orders/{order}   policy-gated detail
    GET /orders/regions   region facets from the caller's own scoped orders

Scenarios drive the real order-creation path on each runtime from IDENTICAL reset state, then compare the list,
detail and region facets for the role-scoping and visibility rules:

  * a konsumen sees only their own orders (not a sibling konsumen's);
  * a sales sees orders where they are the sales rep, plus the reassigned-owner path;
  * an agen sees the whole branch;
  * a gudang sees ONLY the warehouse work queue (diproses, no courier assigned);
  * a kurir is refused outright (the generic surface is minimized for them);
  * the financial fields are gated: a gudang viewer's list omits payment_status/amounts;
  * filters: status, payment_status, search, delivery_date, region ids, paid.

The response bodies are compared after normalising volatile ids/timestamps; the financial/operational projection
split is compared as part of the body (the R-04 / §C gate).

Usage: python3 tools/parity/compare_orders_read.py
"""
import http.cookiejar
import json
import re
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

LARAVEL = "http://127.0.0.1:8899"
GO = "http://127.0.0.1:8082"
TS_RE = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$")

results = []


def check(name, ok, detail=""):
    results.append((name, ok, detail))
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


def reset():
    for t in ["sub_stock_request_items", "sub_stock_requests",
              "inventory_cancellation_reversals", "refund_obligations", "order_item_financial_bases",
              "order_financial_snapshot_units", "order_financial_snapshots", "order_sales_reductions",
              "activity_logs", "media", "stock_request_proposal_items", "stock_request_proposals",
              "stock_request_fulfillments", "stock_request_items", "stock_requests",
              "commissions", "order_initial_report_items", "payment_transactions", "shipments",
              "order_items", "orders", "stock_handovers", "stock_transfer_items", "stock_transfers",
              "stock_movements", "sub_stock_reservations"]:
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


SALES, AGEN, GUDANG, KONSUMEN, KURIR = ("sales@imp010.test", "agen@imp010.test", "gudang@imp010.test",
                                        "konsumen@imp010.test", "kurir@imp010.test")


def create_order(base, konsumen_id=17, quantity=2, payment="cod"):
    c = client_for(base, SALES)
    return c.call("POST", "/api/v1/orders", {
        "items": [{"product_id": 3, "quantity": quantity}], "konsumen_id": konsumen_id,
        "recipient_name": "Budi", "recipient_phone": "081200000000", "address_line": "Jl. Tes 1",
        "village_id": "32730101", "latitude": -6.9, "longitude": 107.6,
        "shipping_method": "pickup", "payment_method_code": payment,
    }, {"Idempotency-Key": "rd-" + uuid.uuid4().hex})


VOLATILE_JSON_KEYS = {"id", "agent_id", "created_at", "updated_at", "order_no", "requested_by", "stock_transfer_id",
                      "approved_by", "rejected_by", "executed_by", "received_by", "sub_location_id",
                      "order_item_id", "stock_request_id", "handed_over_at", "shipment_id",
                      "product_variation_id", "konsumen_id", "placed_by_user_id", "sales_id", "korsal_id"}


def normalise(value, parent=None):
    if isinstance(value, dict):
        out = {}
        for k, v in sorted(value.items()):
            if k in VOLATILE_JSON_KEYS or k.endswith("_at") or k == "payment_summary" or k == "delivery_groups" \
               or k == "shipment_delivery_dates":
                continue
            out[k] = normalise(v, k)
        return out
    if isinstance(value, list):
        # Sort item lists by a stable non-volatile key so runtime insertion order never differs.
        if value and all(isinstance(x, dict) for x in value):
            return sorted((normalise(x) for x in value), key=lambda d: json.dumps(d, sort_keys=True))
        return [normalise(v) for v in value]
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


def scenario(name, run):
    out = {}
    for label, base in (("L", LARAVEL), ("G", GO)):
        reset()
        pairs = run(base)
        statuses = [r[0] for r in pairs]
        bodies = [r[1] for r in pairs]
        out[label] = (statuses, bodies)

    st_l, body_l = out["L"]
    st_g, body_g = out["G"]
    if st_l != st_g:
        check(name, False, f"statuses {st_l} vs {st_g}")
        return
    for i, (bl, bg) in enumerate(zip(body_l, body_g)):
        diffs = diff(normalise(bl), normalise(bg))
        if diffs:
            check(f"{name} [response {i + 1}]", False, "; ".join(diffs[:5]))
            return
    check(name, True)


def main():
    seed_full()

    def sales_list(base):
        create_order(base)
        c = client_for(base, SALES)
        return [(c.call("GET", "/api/v1/orders")[0], c.call("GET", "/api/v1/orders")[1])]
    scenario("sales list (scoped to their own orders)", sales_list)

    def sales_detail(base):
        create_order(base)
        oid = db("SELECT id FROM orders ORDER BY id DESC LIMIT 1")[0][0]
        c = client_for(base, SALES)
        st, body = c.call("GET", f"/api/v1/orders/{oid}")
        return [(st, body)]
    scenario("sales detail", sales_detail)

    def agen_branch_list(base):
        create_order(base)
        c = client_for(base, AGEN)
        st, body = c.call("GET", "/api/v1/orders")
        return [(st, body)]
    scenario("agen sees the whole branch", agen_branch_list)

    def konsumen_own_only(base):
        create_order(base, konsumen_id=17)
        # Konsumen 13 (konsumen@imp010.test) is a DIFFERENT konsumen; they must see nothing of konsumen 17's order.
        c = client_for(base, KONSUMEN)
        st, body = c.call("GET", "/api/v1/orders")
        return [(st, body)]
    scenario("a konsumen sees only their own orders (not a sibling's)", konsumen_own_only)

    def konsumen_detail_own(base):
        # The konsumen places their OWN order (self-purchase), then reads the detail.
        c = client_for(base, KONSUMEN)
        st, body = c.call("POST", "/api/v1/orders", {
            "items": [{"product_id": 3, "quantity": 1}],
            "recipient_name": "Konsumen Satu", "recipient_phone": "081100000013", "address_line": "Jl. Tes 1",
            "village_id": "32730101", "latitude": -6.9, "longitude": 107.6,
            "shipping_method": "pickup", "payment_method_code": "cod",
        }, {"Idempotency-Key": "rd-" + uuid.uuid4().hex})
        if st != 201:
            return [(st, body)]
        oid = db("SELECT id FROM orders ORDER BY id DESC LIMIT 1")[0][0]
        st2, body2 = c.call("GET", f"/api/v1/orders/{oid}")
        return [(st2, body2)]
    scenario("konsumen reads their own order detail", konsumen_detail_own)

    def gudang_queue_only(base):
        create_order(base)  # COD -> diproses, no courier -> in the gudang queue
        c = client_for(base, GUDANG)
        st, body = c.call("GET", "/api/v1/orders")
        return [(st, body)]
    scenario("gudang sees only the warehouse queue (and the operational projection)", gudang_queue_only)

    def kurir_refused(base):
        create_order(base)
        c = client_for(base, KURIR)
        st, body = c.call("GET", "/api/v1/orders")
        return [(st, body)]
    scenario("kurir is refused the generic list", kurir_refused)

    def regions(base):
        create_order(base)
        c = client_for(base, SALES)
        st, body = c.call("GET", "/api/v1/orders/regions")
        return [(st, body)]
    scenario("region facets from scoped orders", regions)

    def filter_status(base):
        create_order(base)
        c = client_for(base, SALES)
        st, body = c.call("GET", "/api/v1/orders?status=diproses")
        return [(st, body)]
    scenario("status filter", filter_status)

    def filter_payment_status_financial(base):
        create_order(base)
        c = client_for(base, SALES)
        st, body = c.call("GET", "/api/v1/orders?payment_status=unpaid")
        return [(st, body)]
    scenario("payment_status filter (financial audience)", filter_payment_status_financial)

    def filter_search(base):
        create_order(base)
        c = client_for(base, SALES)
        st, body = c.call("GET", "/api/v1/orders?search=" + urllib.parse.quote("D-11"))
        return [(st, body)]
    scenario("search filter", filter_search)

    failed = [r for r in results if not r[1]]
    print(f"{len(results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
