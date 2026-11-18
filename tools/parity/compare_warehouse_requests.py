#!/usr/bin/env python3
"""Laravel ↔ Go comparison for the WAREHOUSE STOCK-REQUEST domain (6 routes).

    GET    /warehouse/stock-addition-requests
    POST   /warehouse/stock-addition-requests
    GET    /warehouse/stock-addition-requests/{id}
    POST   /warehouse/stock-addition-requests/{id}/approve
    POST   /warehouse/stock-addition-requests/{id}/reject
    POST   /warehouse/sub-adjustment-requests

This is a two-party gate with REAL inventory effects, so the comparison covers the response AND the resulting
database state:

  * an `addition` into transit → the transit bucket grows by exactly the requested quantity and a `factory_in`
    movement is written carrying the request id;
  * an `addition` into factory_plan → the plan bucket grows and a `factory_plan_in` movement is written;
  * a `sub_adjustment` (positive and negative) → the Sub bucket moves by the delta with a `sub_adjustment`
    movement whose note records before/after;
  * a negative Sub adjustment that would drop physical below the ACTIVE reservations is refused (422) and leaves
    stock untouched;
  * a factory_plan change that would break `transit + plan >= reserved` is refused;
  * rejection leaves stock untouched and records the reason;
  * re-approving an approved request is idempotent (no second movement, no second quantity change);
  * RBAC: `admin` cannot create (route gate), `gudang` cannot approve, and a request from another branch is a 404;
  * validation: quantity < 1, delta 0, missing target, product+variation together, a variation product ordered by
    product id alone.

Usage: python3 tools/parity/compare_warehouse_requests.py
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
    """Load both fixture files ONCE (they DELETE FROM users, which cascades to sessions)."""
    for f in ("seed_test_fixtures.sql", "seed_inventory_fixtures.sql"):
        with open(f"tools/parity/{f}") as fh:
            cmd = 'mariadb -uprimeclassy_app -p"$(cat $MARIADB_PASSWORD_FILE)" primeclassy_testing'
            subprocess.run(["docker", "exec", "-i", "primeclassy-mariadb", "sh", "-lc", cmd],
                           stdin=fh, capture_output=True, text=True, timeout=120)


def reset():
    """Reset only what this domain writes, so live sessions survive.

    AUTO_INCREMENT is reset too: several scenarios address `{id}` and a stale high id would 404 on both runtimes,
    hiding whether the route/RBAC answer matches.
    """
    db("DELETE FROM warehouse_stock_requests")
    db("ALTER TABLE warehouse_stock_requests AUTO_INCREMENT = 1")
    db("ALTER TABLE stock_movements AUTO_INCREMENT = 1")
    db("DELETE FROM stock_movements")
    db("UPDATE warehouse_stocks SET quantity = 12 WHERE id = 2")
    db("UPDATE warehouse_stocks SET quantity = 4  WHERE id = 3")
    db("UPDATE warehouse_stocks SET quantity = 9  WHERE id = 4")
    db("UPDATE warehouse_stocks SET quantity = 6  WHERE id = 5")
    db("UPDATE warehouse_stocks SET quantity = 8  WHERE id = 6")
    db("UPDATE warehouse_stocks SET quantity = 3  WHERE id = 7")
    db("DELETE FROM warehouse_stocks WHERE id > 7")
    db("UPDATE product_stocks SET quantity_reserved = CASE product_id WHEN 1 THEN 5 WHEN 2 THEN 0 ELSE 3 END "
       "WHERE agent_id = 10")
    db("UPDATE product_variation_stocks SET quantity_reserved = CASE product_variation_id WHEN 1 THEN 2 ELSE 0 END "
       "WHERE agent_id = 10")
    db("DELETE FROM sub_stock_reservations")


def state():
    return {
        "warehouse": db("SELECT product_id, product_variation_id, stock_type, sub_location_id, quantity "
                        "FROM warehouse_stocks ORDER BY stock_type, product_id, product_variation_id, sub_location_id"),
        "movements": db("SELECT type, quantity, stock_type, sub_location_id, "
                        "warehouse_stock_request_id IS NOT NULL AS linked, note "
                        "FROM stock_movements ORDER BY id"),
        "requests": db("SELECT request_type, target_stock_type, quantity, status, "
                       "approved_by IS NOT NULL, rejected_by IS NOT NULL, rejection_reason "
                       "FROM warehouse_stock_requests ORDER BY id"),
    }


def normalise(value, parent=None):
    if isinstance(value, dict):
        out = {}
        for k, v in sorted(value.items()):
            if k in ("id", "created_at", "updated_at", "approved_at", "rejected_at", "requested_by"):
                continue
            out[k] = normalise(v, k)
        return out
    if isinstance(value, list):
        return [normalise(v, parent) for v in value]
    if isinstance(v := value, str) and TS_RE.match(v):
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


_clients = {}


def client_for(base, email):
    key = (base, email)
    if key not in _clients:
        c = Client(base)
        if c.login(email) != 200:
            raise SystemExit(f"login failed for {email} on {base}")
        _clients[key] = c
    return _clients[key]


GUDANG = "gudang@imp010.test"
ADMIN = "admin@imp010.test"


def run_scenario(base, steps):
    """Execute one scenario against one runtime and return (statuses, bodies, final state).

    `{id}` in a path is replaced by the id of the most recently CREATED request, so a scenario never depends on the
    table's auto-increment state.
    """
    reset()
    statuses, bodies = [], []
    last_id = None
    for email, method, path, body in steps:
        if "{id}" in path:
            path = path.replace("{id}", str(last_id if last_id is not None else 1))
        c = client_for(base, email)
        st, bd = c.call(method, path, body)
        statuses.append(st)
        bodies.append(bd)
        if st == 201 and isinstance(bd, dict) and isinstance(bd.get("data"), dict) and "id" in bd["data"]:
            last_id = bd["data"]["id"]
    return statuses, bodies, state()


def compare(name, steps):
    """Run the same steps on both runtimes from identical state, then compare responses AND db state."""
    st_l, body_l, state_l = run_scenario(LARAVEL, steps)
    st_g, body_g, state_g = run_scenario(GO, steps)

    if st_l != st_g:
        check(name, False, f"statuses {st_l} vs {st_g}")
        return

    # Compare every response body except the volatile ids/timestamps.
    for i, (bl, bg) in enumerate(zip(body_l, body_g)):
        diffs = diff(normalise(bl), normalise(bg))
        if diffs:
            check(f"{name} [response {i + 1}]", False, "; ".join(diffs[:5]))
            return

    state_diffs = diff(state_l, state_g)
    check(name, not state_diffs, "; ".join(state_diffs[:6]))


CREATE_TRANSIT = (GUDANG, "POST", "/api/v1/warehouse/stock-addition-requests",
                  {"product_id": 3, "quantity": 10, "target_stock_type": "transit", "reference": "PO-1"})
CREATE_PLAN = (GUDANG, "POST", "/api/v1/warehouse/stock-addition-requests",
               {"product_id": 3, "quantity": 5, "target_stock_type": "factory_plan", "reference": "PLAN-1"})
CREATE_SUB_POS = (GUDANG, "POST", "/api/v1/warehouse/sub-adjustment-requests",
                  {"product_id": 1, "sub_location_id": 3, "delta": 4, "note": "tambah"})
CREATE_SUB_NEG = (GUDANG, "POST", "/api/v1/warehouse/sub-adjustment-requests",
                  {"product_id": 1, "sub_location_id": 3, "delta": -2, "note": "kurang"})


def approve(email, rid="{id}"):
    return (email, "POST", f"/api/v1/warehouse/stock-addition-requests/{rid}/approve", None)


def reject(email, reason, rid="{id}"):
    return (email, "POST", f"/api/v1/warehouse/stock-addition-requests/{rid}/reject", {"reason": reason})


def main():
    seed_full()

    # --- happy paths: each is create → approve, and the state diff proves the stock moved exactly once ---
    compare("addition into transit", [CREATE_TRANSIT, approve(ADMIN)])
    compare("addition into factory_plan", [CREATE_PLAN, approve(ADMIN)])
    compare("sub adjustment positive", [CREATE_SUB_POS, approve(ADMIN)])
    compare("sub adjustment negative", [CREATE_SUB_NEG, approve(ADMIN)])

    # --- idempotent approval: a second approve must not move stock again ---
    compare("re-approve is idempotent", [CREATE_TRANSIT, approve(ADMIN), approve(ADMIN)])

    # --- rejection ---
    compare("reject leaves stock untouched", [CREATE_TRANSIT, reject(ADMIN, "Tidak sesuai PO")])
    compare("re-approve after reject refuses", [CREATE_TRANSIT, reject(ADMIN, "x"), approve(ADMIN)])

    # --- guards ---
    # Sub physical (product 1 at location 3) is 8 with no active reservations, so -20 would go negative.
    compare("sub negative below zero is refused",
            [(GUDANG, "POST", "/api/v1/warehouse/sub-adjustment-requests",
              {"product_id": 1, "sub_location_id": 3, "delta": -20}), approve(ADMIN)])

    # --- RBAC ---
    compare("admin cannot create (route gate)",
            [(ADMIN, "POST", "/api/v1/warehouse/stock-addition-requests",
              {"product_id": 3, "quantity": 1, "target_stock_type": "transit"})])
    compare("gudang cannot approve (route gate)", [CREATE_TRANSIT, approve(GUDANG)])
    compare("gudang cannot reject (route gate)", [CREATE_TRANSIT, reject(GUDANG, "x")])
    compare("unknown request id is a 404", [approve(ADMIN, 9999)])

    # --- validation ---
    compare("quantity zero", [(GUDANG, "POST", "/api/v1/warehouse/stock-addition-requests",
                               {"product_id": 3, "quantity": 0, "target_stock_type": "transit"})])
    compare("invalid target stock type", [(GUDANG, "POST", "/api/v1/warehouse/stock-addition-requests",
                                          {"product_id": 3, "quantity": 1, "target_stock_type": "shipping"})])
    compare("product and variation together", [(GUDANG, "POST", "/api/v1/warehouse/stock-addition-requests",
                                               {"product_id": 3, "variation_id": 1, "quantity": 1,
                                                "target_stock_type": "transit"})])
    compare("neither product nor variation", [(GUDANG, "POST", "/api/v1/warehouse/stock-addition-requests",
                                              {"quantity": 1, "target_stock_type": "transit"})])
    compare("variation product ordered by product id", [(GUDANG, "POST", "/api/v1/warehouse/stock-addition-requests",
                                                        {"product_id": 2, "quantity": 1, "target_stock_type": "transit"})])
    compare("sub adjustment delta zero", [(GUDANG, "POST", "/api/v1/warehouse/sub-adjustment-requests",
                                          {"product_id": 1, "sub_location_id": 3, "delta": 0})])
    compare("reject without reason", [CREATE_TRANSIT, (ADMIN, "POST",
            "/api/v1/warehouse/stock-addition-requests/{id}/reject", {})])

    # --- listing and detail ---
    compare("list after create", [CREATE_TRANSIT, (GUDANG, "GET", "/api/v1/warehouse/stock-addition-requests", None)])
    compare("detail", [CREATE_TRANSIT, (GUDANG, "GET", "/api/v1/warehouse/stock-addition-requests/{id}", None)])
    compare("unknown detail is a 404", [(GUDANG, "GET", "/api/v1/warehouse/stock-addition-requests/9999", None)])

    failed = [r for r in results if not r[1]]
    print(f"{len(results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
