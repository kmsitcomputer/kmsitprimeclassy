#!/usr/bin/env python3
"""Laravel ↔ Go comparison for the SUB STOCK-REQUEST workflow (7 routes).

    GET  /sub-stock/requests                         (index — role-scoped)
    POST /sub-stock/requests                          (sales-kurir-sub creates, optional Idempotency-Key)
    GET  /sub-stock/requests/{id}                     (policy view)
    POST /sub-stock/requests/{id}/approve             (admin)
    POST /sub-stock/requests/{id}/reject              (admin, reason required)
    POST /sub-stock/requests/{id}/cancel              (the requester, while still `requested`)
    POST /sub-stock/requests/{id}/execute             (gudang — the ONLY stock-moving step)
    POST /sub-stock/requests/{id}/receive             (the requester, replenish only)

Every scenario compares the response bodies AND the resulting database state: the request rows, the Sub and
Transit buckets, the stock_movements ledger, the handover row (status + both actor columns), and the activity log
counts. Serial numbers (SSR-/TRF-/HOV- date+random) are normalised to their shape.

Scenarios: the full replenish chain (create → approve → execute → receive), the return chain (auto-closes the
handover at execute), idempotent re-execute, receive before execute refused, execute before approve refused,
return beyond Sub sellable refused, cancel then approve refused, rejection, RBAC gates, and the Idempotency-Key
replay.

Usage: python3 tools/parity/compare_subrequests.py
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
NUMBER_RE = re.compile(r"^(SSR|TRF|HOV)-\d{14}-[A-Z0-9]{5}$")

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
    # FK-ordered teardown: sub_stock_requests references stock_transfers, so the child goes first.
    for t in ["sub_stock_request_items", "sub_stock_requests",
              "activity_logs", "media", "stock_request_proposal_items", "stock_request_proposals",
              "stock_request_fulfillments", "stock_request_items", "stock_requests",
              "commissions", "order_initial_report_items", "payment_transactions", "shipments",
              "order_items", "orders", "stock_handovers", "stock_transfer_items", "stock_transfers",
              "stock_movements", "sub_stock_reservations"]:
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


def state():
    return {
        # The idempotency_key VALUE is random per run; only its PRESENCE is the assertion.
        "requests": db("SELECT direction, status, requested_by, approved_by IS NOT NULL, rejected_by IS NOT NULL, "
                       "cancelled_at IS NOT NULL, executed_by IS NOT NULL, received_by IS NOT NULL, "
                       "stock_transfer_id IS NOT NULL, idempotency_key IS NOT NULL, note "
                       "FROM sub_stock_requests ORDER BY id"),
        "items": db("SELECT product_id, product_variation_id, quantity FROM sub_stock_request_items ORDER BY id"),
        "buckets": db("SELECT stock_type, product_id, product_variation_id, sub_location_id, quantity "
                      "FROM warehouse_stocks ORDER BY stock_type, product_id, product_variation_id, sub_location_id"),
        "movements": db("SELECT type, quantity, stock_type, sub_location_id, counterpart_stock_type, "
                        "transfer_id IS NOT NULL, handover_id IS NOT NULL, note IS NOT NULL "
                        "FROM stock_movements ORDER BY id"),
        "handovers": db("SELECT status, handed_over_by IS NOT NULL, received_by IS NOT NULL, "
                        "received_at IS NOT NULL FROM stock_handovers ORDER BY id"),
        "transfers": db("SELECT source_stock_type, destination_stock_type, source_sub_location_id, "
                        "destination_sub_location_id, status, "
                        "IF(reference IS NULL, NULL, '<SSR-number>'), note FROM stock_transfers ORDER BY id"),
        "logs": db("SELECT event, COUNT(*) FROM activity_logs GROUP BY event ORDER BY event"),
    }


SUB, ADMIN, GUDANG = "sub@imp010.test", "admin@imp010.test", "gudang@imp010.test"
_clients = {}


def client_for(base, email):
    key = (base, email)
    if key not in _clients:
        c = Client(base)
        if c.login(email) != 200:
            raise SystemExit(f"login failed for {email} on {base}")
        _clients[key] = c
    return _clients[key]


def create_sub_request(base, direction="replenish", items=None, key=None, note=None):
    payload = {"direction": direction, "items": items or [{"product_id": 1, "quantity": 5}]}
    if note:
        payload["note"] = note
    extra = {"Idempotency-Key": key} if key else None
    return client_for(base, SUB).call("POST", "/api/v1/sub-stock/requests", payload, extra)


def act(base, email, action, rid=None):
    """Run one action on the latest (or given) request id; `action` is approve/reject/cancel/execute/receive."""
    if rid is None:
        rid = db("SELECT id FROM sub_stock_requests ORDER BY id DESC LIMIT 1")[0][0]
    path = f"/api/v1/sub-stock/requests/{rid}/{action}"
    if action == "reject":
        return client_for(base, email).call("POST", path, {"reason": "Stok tidak tersedia"})
    return client_for(base, email).call("POST", path, None)


VOLATILE_JSON_KEYS = {"id", "agent_id", "sub_location_id", "requested_by", "stock_transfer_id",
                      "approved_by", "rejected_by", "executed_by", "received_by", "created_at", "updated_at",
                      "approved_at", "rejected_at", "cancelled_at", "executed_at", "received_at",
                      "sub_stock_request_id", "request_number", "handed_over_by", "received_by",
                      "transfer"}


def normalise(value, parent=None):
    if isinstance(value, dict):
        out = {}
        for k, v in sorted(value.items()):
            if k in VOLATILE_JSON_KEYS or (isinstance(v, dict) and k in ("transfer",)):
                continue
            if k.endswith("_at") or k == "handover":
                continue
            out[k] = normalise(v, k)
        return out
    if isinstance(value, list):
        return [normalise(v, parent) for v in value]
    if isinstance(value, str) and TS_RE.match(value):
        return "<timestamp>"
    if isinstance(value, str) and NUMBER_RE.match(value):
        return f"<{NUMBER_RE.match(value).group(1)}-number>"
    if isinstance(value, str) and value.startswith("sub-req-"):
        return "<idempotency-key>"
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
        local = run(base)
        out[label] = ([r[0] for r in local], [r[1] for r in local], state())

    st_l, body_l, state_l = out["L"]
    st_g, body_g, state_g = out["G"]
    if st_l != st_g:
        check(name, False, f"statuses {st_l} vs {st_g}: {json.dumps(body_l)[:180]} | {json.dumps(body_g)[:180]}")
        return
    for i, (bl, bg) in enumerate(zip(body_l, body_g)):
        if bl is None or bg is None:
            continue
        diffs = diff(normalise(bl), normalise(bg))
        if diffs:
            check(f"{name} [response {i + 1}]", False, "; ".join(diffs[:5]))
            return
    state_diffs = diff(state_l, state_g)
    check(name, not state_diffs, "; ".join(state_diffs[:6]))


def main():
    seed_full()

    def full_replenish(base):
        # Location 3 holds product 1; replenishing 5 units moves 5 from the Transit bucket (12 seeded)
        # into Sub location 3, and the Transit-reservation guard must hold (5 reserved vs 12 transit).
        created = create_sub_request(base, items=[{"product_id": 1, "quantity": 5}])
        approved = act(base, ADMIN, "approve")
        executed = act(base, GUDANG, "execute")
        received = act(base, SUB, "receive")
        return [created, approved, executed, received]
    scenario("full replenish chain (execute moves Transit→Sub, receive closes the handover)", full_replenish)

    def full_return(base):
        created = create_sub_request(base, direction="return", items=[{"product_id": 1, "quantity": 3}])
        approved = act(base, ADMIN, "approve")
        # Sub location 3 holds 8 of product 1; returning 3 moves Sub→Transit and auto-closes the handover.
        executed = act(base, GUDANG, "execute")
        return [created, approved, executed]
    scenario("full return chain (Sub→Transit, handover auto-closed)", full_return)

    def reexecute_idempotent(base):
        create_sub_request(base)
        act(base, ADMIN, "approve")
        executed = act(base, GUDANG, "execute")
        reexecuted = act(base, GUDANG, "execute")
        return [executed, reexecuted]
    scenario("re-execute is idempotent (no second move)", reexecute_idempotent)

    def execute_before_approve(base):
        created = create_sub_request(base)
        executed = act(base, GUDANG, "execute")
        return [created, executed]
    scenario("execute before approve refused", execute_before_approve)

    def receive_before_execute(base):
        create_sub_request(base)
        act(base, ADMIN, "approve")
        received = act(base, SUB, "receive")
        return [received]
    scenario("receive before execute refused", receive_before_execute)

    def return_beyond_sellable(base):
        # Sub location 3 holds only 8 of product 1 — requesting 20 back must be refused at create time.
        created = create_sub_request(base, direction="return", items=[{"product_id": 1, "quantity": 20}])
        return [created]
    scenario("return beyond Sub sellable refused", return_beyond_sellable)

    def duplicate_target(base):
        # The same target twice is refused.
        created = create_sub_request(base, items=[{"product_id": 1, "quantity": 1},
                                                  {"product_id": 1, "quantity": 2}])
        return [created]
    scenario("duplicate target refused", duplicate_target)

    def variation_product_as_bare_target(base):
        # Product 2 HAS variations: it cannot be replenished as a bare product target.
        created = create_sub_request(base, items=[{"product_id": 2, "quantity": 1}])
        return [created]
    scenario("variation product as bare target refused", variation_product_as_bare_target)

    def variation_replenish(base):
        # Variation 2 belongs to the variation product 2; transit bucket id 4 holds 9 of it.
        created = create_sub_request(base, items=[{"product_variation_id": 2, "quantity": 3}])
        approved = act(base, ADMIN, "approve")
        executed = act(base, GUDANG, "execute")
        return [created, approved, executed]
    scenario("variation-target replenish executes", variation_replenish)

    def cancel_then_approve(base):
        created = create_sub_request(base)
        cancelled = act(base, SUB, "cancel")
        approved = act(base, ADMIN, "approve")
        return [created, cancelled, approved]
    scenario("cancel then approve refused", cancel_then_approve)

    def rejected(base):
        created = create_sub_request(base)
        rejected = act(base, ADMIN, "reject")
        executed = act(base, GUDANG, "execute")
        return [created, rejected, executed]
    scenario("rejection blocks execution", rejected)

    def idempotency_replay(base):
        key = "sub-req-" + uuid.uuid4().hex
        first = create_sub_request(base, key=key)
        second = create_sub_request(base, key=key)
        return [first, second]
    scenario("Idempotency-Key replay returns the same request", idempotency_replay)

    # RBAC
    def gudang_cannot_create(base):
        return [client_for(base, GUDANG).call("POST", "/api/v1/sub-stock/requests",
                                              {"direction": "replenish", "items": [{"product_id": 2, "quantity": 1}]})]
    scenario("gudang cannot create (route gate)", gudang_cannot_create)

    def sub_cannot_approve(base):
        create_sub_request(base)
        return [act(base, SUB, "approve")]
    scenario("requester cannot approve (route gate)", sub_cannot_approve)

    def admin_cannot_execute(base):
        create_sub_request(base)
        act(base, ADMIN, "approve")
        return [act(base, ADMIN, "execute")]
    scenario("admin cannot execute (route gate)", admin_cannot_execute)

    # Detail view
    def detail(base):
        create_sub_request(base)
        rid = db("SELECT id FROM sub_stock_requests ORDER BY id DESC LIMIT 1")[0][0]
        return [client_for(base, ADMIN).call("GET", f"/api/v1/sub-stock/requests/{rid}")]
    scenario("detail view", detail)

    failed = [r for r in results if not r[1]]
    print(f"{len(results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
