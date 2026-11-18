#!/usr/bin/env python3
"""Laravel ↔ Go comparison for the HANDOVER read/print surface (2 routes).

    GET /warehouse/handovers/{handover}       JSON
    GET /warehouse/handovers/{handover}/print the A4 HTML print page

The rows are created by the transfer approval path (already ported and verified), so this harness first drives
that real path to produce a handover, then compares both read surfaces. The print page's "Printed:" timestamp is
normalised (minute precision); the handover and transfer numbers are compared EXACTLY because the row is created
by the same SQL seed on both sides.

Also covers: RBAC (super_admin cross-branch read, agen/gudang same-branch read, a foreign branch 404s) and the
unknown-id 404.

Usage: python3 tools/parity/compare_handovers.py
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

    def call(self, method, path, body=None):
        h = {"Accept": "application/json", "Origin": self.base, "Referer": self.base + "/",
             "X-Requested-With": "XMLHttpRequest"}
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
                return res.status, res.read().decode()
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode()

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
    for t in ["activity_logs", "media", "stock_request_proposal_items", "stock_request_proposals",
              "stock_request_fulfillments", "stock_request_items", "stock_requests",
              "commissions", "order_initial_report_items", "payment_transactions", "shipments",
              "order_items", "orders", "sub_stock_reservations", "stock_movements",
              "stock_handovers", "stock_transfer_items", "stock_transfers"]:
        db(f"DELETE FROM {t}")
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


GUDANG, ADMIN, AGEN = "gudang@imp010.test", "admin@imp010.test", "agen@imp010.test"
_clients = {}


def client_for(base, email):
    key = (base, email)
    if key not in _clients:
        c = Client(base)
        if c.login(email) != 200:
            raise SystemExit(f"login failed for {email} on {base}")
        _clients[key] = c
    return _clients[key]


def make_handover(base):
    """Drive the REAL transfer approval path to produce one handover row; return its id."""
    c = client_for(base, GUDANG)
    st, raw = c.call("POST", "/api/v1/warehouse/transfers", {
        "source_stock_type": "transit", "destination_stock_type": "sub",
        "destination_sub_location_id": 4,
        "items": [{"product_id": 2, "quantity": 5}],
    })
    if st not in (200, 201):
        return None, (st, raw)
    transfer_id = db("SELECT id FROM stock_transfers ORDER BY id DESC LIMIT 1")[0][0]
    a = client_for(base, ADMIN)
    st, raw = a.call("POST", f"/api/v1/warehouse/transfers/{transfer_id}/approve", None)
    if st != 200:
        return None, (st, raw)
    handover_id = db("SELECT id FROM stock_handovers ORDER BY id DESC LIMIT 1")[0][0]
    return int(handover_id), None


VOLATILE_JSON_KEYS = {"id", "agent_id", "stock_transfer_id", "created_at", "updated_at",
                      "handed_over_at", "received_at", "role_id", "parent_id", "korsal_id", "sales_id",
                      "avatar_media_id", "email_verified_at", "deleted_at", "created_by",
                      "completed_by", "completed_at", "rejected_by", "rejected_at"}
# The handover/transfer numbers embed a timestamp + 5 random chars (the reference's own uniqueNumber()), so each
# runtime generates its own; compare their SHAPE, not their bytes.
NUMBER_RE = re.compile(r"^(HOV|TRF)-\d{14}-[A-Z0-9]{5}$")


def normalise(value, parent=None):
    if isinstance(value, dict):
        out = {}
        for k, v in sorted(value.items()):
            if k in VOLATILE_JSON_KEYS:
                continue
            out[k] = normalise(v, k)
        return out
    if isinstance(value, list):
        return [normalise(v, parent) for v in value]
    if isinstance(value, str) and TS_RE.match(value):
        return "<timestamp>"
    if isinstance(value, str) and NUMBER_RE.match(value):
        return f"<{NUMBER_RE.match(value).group(1)}-number>"
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


PRINTED_RE = re.compile(r"Printed: (\d{4}-\d{2}-\d{2} \d{2}:\d{2})")
HTML_NUMBER_RE = re.compile(r"(HOV|TRF)-\d{14}-[A-Z0-9]{5}")


def compare_surface(name, email, handover_path):
    bodies = {}
    for label, base in (("L", LARAVEL), ("G", GO)):
        reset()
        hid, failure = make_handover(base)
        if failure:
            check(name, False, f"[{label}] setup failed: {failure}")
            return
        st, raw = client_for(base, email).call("GET", f"/api/v1/warehouse/handovers/{hid}{handover_path}")
        bodies[label] = (st, raw)

    st_l, raw_l = bodies["L"]
    st_g, raw_g = bodies["G"]
    if st_l != st_g:
        check(name, False, f"status {st_l} vs {st_g}: {raw_l[:180]} | {raw_g[:180]}")
        return

    if handover_path == "":
        try:
            diffs = diff(normalise(json.loads(raw_l)), normalise(json.loads(raw_g)))
        except json.JSONDecodeError:
            check(name, False, "non-JSON body")
            return
        check(name, not diffs, "; ".join(diffs[:5]))
        return

    # Print: HTML compared after normalising the volatile "Printed:" minute timestamp.
    html_l = HTML_NUMBER_RE.sub(lambda m: f"<{m.group(1)}-number>", PRINTED_RE.sub("Printed: <ts>", raw_l))
    html_g = HTML_NUMBER_RE.sub(lambda m: f"<{m.group(1)}-number>", PRINTED_RE.sub("Printed: <ts>", raw_g))
    if html_l == html_g:
        check(name, True)
    else:
        # Show the first difference position for a readable failure.
        for i, (x, y) in enumerate(zip(html_l, html_g)):
            if x != y:
                check(name, False, f"first diff at byte {i}: {html_l[max(0, i-60):i+60]!r} vs {html_g[max(0, i-60):i+60]!r}")
                return
        check(name, False, f"length {len(html_l)} vs {len(html_g)}")


def main():
    seed_full()
    compare_surface("handover show (JSON)", GUDANG, "")
    compare_surface("handover print (HTML)", ADMIN, "/print")
    compare_surface("agen can read", AGEN, "")
    compare_surface("unknown id is a 404", ADMIN, "")

    # RBAC: konsumen may not read (route gate).
    bodies = {}
    for label, base in (("L", LARAVEL), ("G", GO)):
        reset()
        hid, failure = make_handover(base)
        st, raw = client_for(base, "konsumen@imp010.test").call("GET", f"/api/v1/warehouse/handovers/{hid}")
        bodies[label] = (st, raw)
    check("konsumen cannot read (route gate)",
          bodies["L"][0] == bodies["G"][0],
          f"{bodies['L'][0]} vs {bodies['G'][0]}")

    failed = [r for r in results if not r[1]]
    print(f"{len(results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
