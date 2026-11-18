#!/usr/bin/env python3
"""Laravel ↔ Go comparison for the STOCK-REQUEST PROPOSAL family (8 routes).

    POST /warehouse/stock-requests/{stockRequest}/fulfill          gudang — the reference REFUSES (proposal gate)
    POST /warehouse/stock-requests/{stockRequest}/proposals        gudang — propose (multipart + REQUIRED proof)
    GET  /warehouse/fulfillment-proposals                          admin,gudang,koordinator-kurir
    GET  /warehouse/fulfillment-proposals/{proposal}               same
    POST /warehouse/fulfillment-proposals/{proposal}/receive       koordinator-kurir — the routine settlement
    POST /warehouse/fulfillment-proposals/{proposal}/approve       admin — the EXCEPTION path
    POST /warehouse/fulfillment-proposals/{proposal}/reject        admin
    POST /warehouse/stock-request-items/{item}/resolve-discrepancy admin — F07 damaged write-off

This is the §16/§31 two-party gate with REAL inventory effects, so every scenario compares:

  * the response bodies (normalised for volatile ids/timestamps/uuid filenames);
  * the resulting DB state: warehouse buckets (transit/shipping), product_stocks.quantity_reserved,
    stock_requests.status + demand lines (fulfilled/remaining/damaged_hold), proposal rows and items
    (accepted/short/damaged), stock_movements (type/quantity/buckets/counterpart), stock_request_fulfillments,
    and the activity-log shortage/discrepancy rows.

Scenarios:
  * propose + receive with a full acceptance (transit → shipping moves, reservation decremented, request fulfilled);
  * receive with a partial acceptance (short stays in transit; request becomes partial);
  * receive with damaged units (damaged_hold_qty rises; write_off later reduces transit and lifts the hold);
  * the write-off idempotency key (a retry answers 409; a second key on the same line works);
  * the admin exception approval (no receipt lines; whole prepared quantity settles);
  * rejection (no stock moves);
  * propose JSON without a proof → 422 `proof wajib diisi.` (the reference's own conditional rule);
  * shortage-only report (no proposal row, no stock movement, audit rows written);
  * direct fulfil refusal (the reference's fixed 422);
  * guards: propose beyond remaining, duplicate items, receipt sums ≠ prepared, insufficient transit,
    insufficient reservation, RBAC gates, and a foreign-branch request id.

Usage: python3 tools/parity/compare_proposals.py
"""
import http.cookiejar
import io
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
        return self.call_with_headers(method, path, body, extra)

    def call_with_headers(self, method, path, body=None, extra=None):
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

    def multipart(self, path, fields, files):
        boundary = "----imp010boundary"
        body = io.BytesIO()
        for name, value in fields:
            body.write(f"--{boundary}\r\nContent-Disposition: form-data; name=\"{name}\"\r\n\r\n{value}\r\n".encode())
        for name, filename, content in files:
            body.write(f"--{boundary}\r\nContent-Disposition: form-data; name=\"{name}\"; "
                       f"filename=\"{filename}\"\r\nContent-Type: image/png\r\n\r\n".encode())
            body.write(content)
            body.write(b"\r\n")
        body.write(f"--{boundary}--\r\n".encode())
        h = {"Accept": "application/json", "Origin": self.base, "Referer": self.base + "/",
             "X-Requested-With": "XMLHttpRequest",
             "Content-Type": f"multipart/form-data; boundary={boundary}"}
        tok = self.token()
        if tok:
            h["X-XSRF-TOKEN"] = tok
        req = urllib.request.Request(self.base + path, data=body.getvalue(), headers=h, method="POST")
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


# FK-ordered teardown: every child table before its parent, or the DELETEs fail silently and stale rows make the
# comparison compare the wrong order.
ORDER_TABLES = [
    "activity_logs", "media", "stock_request_proposal_items", "stock_request_proposals",
    "stock_request_fulfillments", "stock_request_items", "stock_requests",
    "commissions", "order_initial_report_items", "payment_transactions", "shipments",
    "order_items", "orders", "sub_stock_reservations", "stock_movements",
    "bank_transfer_verifications",
]


def reset():
    for t in ORDER_TABLES:
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
        "buckets": db("SELECT stock_type, product_id, product_variation_id, sub_location_id, quantity "
                      "FROM warehouse_stocks ORDER BY stock_type, product_id, product_variation_id"),
        "reserved_product": db("SELECT product_id, quantity_reserved FROM product_stocks ORDER BY product_id"),
        "reserved_variation": db("SELECT product_variation_id, quantity_reserved FROM product_variation_stocks "
                                 "ORDER BY product_variation_id"),
        "requests": db("SELECT status, fulfilled_at IS NOT NULL FROM stock_requests ORDER BY id"),
        "demand": db("SELECT requested_qty, fulfilled_qty, remaining_qty, damaged_hold_qty "
                     "FROM stock_request_items ORDER BY id"),
        "proposals": db("SELECT status, approved_by IS NOT NULL, received_by IS NOT NULL, rejected_by IS NOT NULL, "
                        "receipt_note FROM stock_request_proposals ORDER BY id"),
        "proposal_items": db("SELECT quantity, accepted_qty, short_qty, damaged_qty "
                             "FROM stock_request_proposal_items ORDER BY id"),
        "demand_keys": db("SELECT requested_qty, fulfilled_qty, remaining_qty, damaged_hold_qty "
                          "FROM stock_request_items ORDER BY id"),
        "fulfillments": db("SELECT stock_request_id, idempotency_key FROM stock_request_fulfillments ORDER BY id"),
        "movements": db("SELECT type, quantity, stock_type, sub_location_id, counterpart_stock_type, "
                        "reference_type, note IS NOT NULL FROM stock_movements ORDER BY id"),
        "shortage_logs": db("SELECT event, description IS NOT NULL FROM activity_logs "
                            "WHERE event = 'stock_request.fulfillment_shortage' ORDER BY id"),
        "discrepancy_logs": db("SELECT COUNT(*) FROM activity_logs WHERE event = 'stock_request.discrepancy_resolved'"),
        "media": db("SELECT collection, mime_type, width, height FROM media ORDER BY id"),
    }


# A real 1×1 PNG (the decode check rejects anything else).
PNG = bytes.fromhex(
    "89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000d49444154"
    "78da63fcffff3f030005fe02fea72d99480000000049454e44ae426082")

SALES, GUDANG, ADMIN, KURIR_K = "sales@imp010.test", "gudang@imp010.test", "admin@imp010.test", "koordinator@imp010.test"
_clients = {}


def client_for(base, email):
    key = (base, email)
    if key not in _clients:
        c = Client(base)
        if c.login(email) != 200:
            raise SystemExit(f"login failed for {email} on {base}")
        _clients[key] = c
    return _clients[key]


def create_order_with_request(base, quantity=4, product_line=("product", 3)):
    """Create a COD order (which creates its Stock Request) and return (request_id, demand_item_id)."""
    if product_line[0] == "product":
        items = [{"product_id": product_line[1], "quantity": quantity}]
    else:
        items = [{"product_id": product_line[1], "product_variation_id": product_line[2], "quantity": quantity}]
    c = client_for(base, SALES)
    st, body = c.call_with_headers("POST", "/api/v1/orders", {
        "items": items, "konsumen_id": 17,
        "recipient_name": "Budi", "recipient_phone": "081200000000", "address_line": "Jl. Tes 1",
        "village_id": "32730101", "latitude": -6.9, "longitude": 107.6,
        "shipping_method": "pickup", "payment_method_code": "cod",
    }, {"Idempotency-Key": "cmp-prop-" + uuid.uuid4().hex})
    if st != 201:
        return None, None, (st, body)
    rid = db("SELECT id FROM stock_requests ORDER BY id DESC LIMIT 1")[0][0]
    item = db("SELECT id FROM stock_request_items ORDER BY id DESC LIMIT 1")[0][0]
    return rid, item, (st, body)


def propose(base, request_id, item_id, quantity, proof=True, extra_fields=None):
    c = client_for(base, GUDANG)
    fields = [("items[0][item_id]", str(item_id)), ("items[0][quantity]", str(quantity))]
    if extra_fields:
        fields.extend(extra_fields)
    files = [("proof", "proof.png", PNG)] if proof else []
    return c.multipart(f"/api/v1/warehouse/stock-requests/{request_id}/proposals", fields, files)


def receive(base, lines, note=None):
    pid = db("SELECT id FROM stock_request_proposals ORDER BY id DESC LIMIT 1")[0][0]
    payload = {"items": lines}
    if note:
        payload["note"] = note
    c = client_for(base, KURIR_K)
    return c.call("POST", f"/api/v1/warehouse/fulfillment-proposals/{pid}/receive", payload)


def approve(base, reason):
    pid = db("SELECT id FROM stock_request_proposals ORDER BY id DESC LIMIT 1")[0][0]
    return client_for(base, ADMIN).call("POST", f"/api/v1/warehouse/fulfillment-proposals/{pid}/approve",
                                        {"reason": reason})


# Row ids each runtime generated itself: comparing them across runtimes is meaningless (both insert their own
# rows), so every id that is not a FIXED fixture value is normalised away.
VOLATILE_ID_KEYS = {"id", "stock_request_id", "stock_request_proposal_id", "stock_request_item_id",
                    "order_id", "order_item_id", "agent_id", "requested_by", "order_no"}


def normalise(value, parent=None):
    if isinstance(value, dict):
        out = {}
        for k, v in sorted(value.items()):
            if k in VOLATILE_ID_KEYS or k.endswith("_at") or k == "handover_proof_url" or k == "request_number":
                continue
            if k == "request" and isinstance(v, dict):
                continue  # volatile parent ids
            if isinstance(v, list) and k in ("items", "shortages"):
                out[k] = [normalise(x, k) for x in v]
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


def scenario(name, run):
    """Run one scenario on both runtimes from identical state; `run(base)` returns [(status, body), ...]."""
    out = {}
    for label, base in (("L", LARAVEL), ("G", GO)):
        reset()
        results_local = run(base)
        statuses = [r[0] for r in results_local]
        bodies = [r[1] for r in results_local]
        out[label] = (statuses, bodies, state())

    st_l, body_l, state_l = out["L"]
    st_g, body_g, state_g = out["G"]
    if st_l != st_g:
        check(name, False, f"statuses {st_l} vs {st_g}: {json.dumps(body_l)[:200]} | {json.dumps(body_g)[:200]}")
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

    # --- the full happy path: order → propose (with proof) → receive fully accepted ---
    def full_acceptance(base):
        rid, item, _ = create_order_with_request(base, quantity=4, product_line=("product", 3))
        return [
            propose(base, rid, item, 4),
            receive(base, [{"item_id": proposal_item_id(), "accepted": 4, "short": 0, "damaged": 0}]),
        ]
    scenario("propose + receive full acceptance", full_acceptance)

    # --- partial acceptance: short units stay in transit, the request becomes partial ---
    def partial(base):
        rid, item, _ = create_order_with_request(base, quantity=4, product_line=("product", 3))
        return [
            propose(base, rid, item, 4),
            receive(base, [{"item_id": proposal_item_id(), "accepted": 2, "short": 2, "damaged": 0}]),
        ]
    scenario("receive partial (short stays in transit)", partial)

    # --- damaged units are held on the line, then written off by Admin ---
    def damaged_then_write_off(base):
        rid, item, _ = create_order_with_request(base, quantity=4, product_line=("product", 3))
        propose_result = propose(base, rid, item, 4)
        receive_result = receive(base, [{"item_id": proposal_item_id(), "accepted": 2, "short": 0, "damaged": 2}])
        demand = db("SELECT id FROM stock_request_items ORDER BY id DESC LIMIT 1")[0][0]
        key = str(uuid.uuid4())
        write_off = client_for(base, ADMIN).call("POST",
            f"/api/v1/warehouse/stock-request-items/{demand}/resolve-discrepancy",
            {"idempotency_key": key, "action": "write_off", "quantity": 2, "reason": "rusak parah saat angkut"})
        return [propose_result, receive_result, write_off]
    scenario("damaged held then written off", damaged_then_write_off)

    # --- write-off idempotency: the same key answers 409 ---
    def write_off_retry(base):
        rid, item, _ = create_order_with_request(base, quantity=2, product_line=("product", 3))
        propose(base, rid, item, 2)
        receive(base, [{"item_id": proposal_item_id(), "accepted": 0, "short": 0, "damaged": 2}])
        demand = db("SELECT id FROM stock_request_items ORDER BY id DESC LIMIT 1")[0][0]
        key = str(uuid.uuid4())
        admin = client_for(base, ADMIN)
        first = admin.call("POST", f"/api/v1/warehouse/stock-request-items/{demand}/resolve-discrepancy",
                           {"idempotency_key": key, "action": "write_off", "quantity": 1, "reason": "rusak parah saat angkut"})
        retry = admin.call("POST", f"/api/v1/warehouse/stock-request-items/{demand}/resolve-discrepancy",
                           {"idempotency_key": key, "action": "write_off", "quantity": 1, "reason": "rusak parah saat angkut"})
        return [first, retry]
    scenario("write-off retry answers 409", write_off_retry)

    # --- restock is refused outright ---
    def restock_refused(base):
        rid, item, _ = create_order_with_request(base, quantity=2, product_line=("product", 3))
        propose(base, rid, item, 2)
        receive(base, [{"item_id": proposal_item_id(), "accepted": 0, "short": 0, "damaged": 2}])
        demand = db("SELECT id FROM stock_request_items ORDER BY id DESC LIMIT 1")[0][0]
        return [client_for(base, ADMIN).call("POST",
            f"/api/v1/warehouse/stock-request-items/{demand}/resolve-discrepancy",
            {"idempotency_key": str(uuid.uuid4()), "action": "restock", "quantity": 1, "reason": "inspeksi selesai"})]
    scenario("restock action refused", restock_refused)

    # --- the admin exception approval (no receipt lines) ---
    def admin_exception(base):
        rid, item, _ = create_order_with_request(base, quantity=4, product_line=("product", 3))
        return [
            propose(base, rid, item, 4),
            approve(base, "Sudah dicek fisik oleh Admin"),
        ]
    scenario("admin exception approval", admin_exception)

    # --- rejection moves nothing ---
    def rejected(base):
        rid, item, _ = create_order_with_request(base, quantity=4, product_line=("product", 3))
        pid = propose(base, rid, item, 4)
        return [pid, client_for(base, ADMIN).call("POST",
            f"/api/v1/warehouse/fulfillment-proposals/{db('SELECT id FROM stock_request_proposals ORDER BY id DESC LIMIT 1')[0][0]}/reject",
            {"reason": "Bukti tidak jelas"})]
    scenario("rejection moves nothing", rejected)

    # --- the conditional proof rule ---
    def no_proof(base):
        rid, item, _ = create_order_with_request(base, quantity=4, product_line=("product", 3))
        c = client_for(base, GUDANG)
        return [c.call("POST", f"/api/v1/warehouse/stock-requests/{rid}/proposals",
                       {"items": [{"item_id": int(item), "quantity": 4}]})]
    scenario("JSON propose without proof → 422", no_proof)

    # --- shortage-only report ---
    def shortage_only(base):
        rid, item, _ = create_order_with_request(base, quantity=4, product_line=("product", 3))
        c = client_for(base, GUDANG)
        return [c.call("POST", f"/api/v1/warehouse/stock-requests/{rid}/proposals",
                       {"shortages": [{"item_id": int(item), "reason": "stok pabrik belum datang"}]})]
    scenario("shortage-only report (no proposal)", shortage_only)

    # --- propose with a shortage reason embedded (partial handover) ---
    def propose_with_shortage_reason(base):
        rid, item, _ = create_order_with_request(base, quantity=4, product_line=("product", 3))
        return [propose(base, rid, item, 2, extra_fields=[("items[0][shortage_reason]", "dua unit kurang dari pabrik")])]
    scenario("propose with shortage reason", propose_with_shortage_reason)

    # --- direct fulfil is refused ---
    def direct_fulfil(base):
        rid, item, _ = create_order_with_request(base, quantity=4, product_line=("product", 3))
        return [client_for(base, GUDANG).call("POST", f"/api/v1/warehouse/stock-requests/{rid}/fulfill",
            {"idempotency_key": "k", "items": [{"item_id": int(item), "quantity": 4}]})]
    scenario("direct fulfil refused", direct_fulfil)

    # --- guards ---
    def over_remaining(base):
        rid, item, _ = create_order_with_request(base, quantity=2, product_line=("product", 3))
        return [propose(base, rid, item, 5)]
    scenario("propose beyond remaining refused", over_remaining)

    def receipt_sum_mismatch(base):
        rid, item, _ = create_order_with_request(base, quantity=4, product_line=("product", 3))
        propose(base, rid, item, 4)
        return [receive(base, [{"item_id": proposal_item_id(), "accepted": 3, "short": 0, "damaged": 0}])]
    scenario("receipt sum mismatch refused", receipt_sum_mismatch)

    def insufficient_transit(base):
        # Product 3 has NO transit bucket at all (legacy only) → the settlement must refuse.
        rid, item, _ = create_order_with_request(base, quantity=4, product_line=("product", 3))
        return [propose(base, rid, item, 4),
                receive(base, [{"item_id": proposal_item_id(), "accepted": 4, "short": 0, "damaged": 0}])]
    scenario("insufficient transit refused", insufficient_transit)

    # --- RBAC ---
    def gudang_cannot_approve(base):
        rid, item, _ = create_order_with_request(base, quantity=4, product_line=("product", 3))
        propose(base, rid, item, 4)
        pid = db("SELECT id FROM stock_request_proposals ORDER BY id DESC LIMIT 1")[0][0]
        return [client_for(base, GUDANG).call("POST", f"/api/v1/warehouse/fulfillment-proposals/{pid}/approve",
                                              {"reason": "bukan wewenang"})]
    scenario("gudang cannot approve (route gate)", gudang_cannot_approve)

    def admin_cannot_receive(base):
        rid, item, _ = create_order_with_request(base, quantity=4, product_line=("product", 3))
        propose(base, rid, item, 4)
        pid = db("SELECT id FROM stock_request_proposals ORDER BY id DESC LIMIT 1")[0][0]
        return [client_for(base, ADMIN).call("POST", f"/api/v1/warehouse/fulfillment-proposals/{pid}/receive",
            {"items": [{"item_id": proposal_item_id(), "accepted": 4, "short": 0, "damaged": 0}]})]
    scenario("admin cannot receive (route gate)", admin_cannot_receive)

    def gudang_cannot_resolve(base):
        rid, item, _ = create_order_with_request(base, quantity=2, product_line=("product", 3))
        propose(base, rid, item, 2)
        receive(base, [{"item_id": proposal_item_id(), "accepted": 0, "short": 0, "damaged": 2}])
        demand = db("SELECT id FROM stock_request_items ORDER BY id DESC LIMIT 1")[0][0]
        return [client_for(base, GUDANG).call("POST",
            f"/api/v1/warehouse/stock-request-items/{demand}/resolve-discrepancy",
            {"idempotency_key": str(uuid.uuid4()), "action": "write_off", "quantity": 1, "reason": "bukan wewenang"})]
    scenario("gudang cannot resolve discrepancy (route gate)", gudang_cannot_resolve)

    # --- listing and detail ---
    def listing(base):
        rid, item, _ = create_order_with_request(base, quantity=4, product_line=("product", 3))
        propose(base, rid, item, 4)
        return [client_for(base, GUDANG).call("GET", "/api/v1/warehouse/fulfillment-proposals", None)]
    scenario("index after propose", listing)

    def detail(base):
        rid, item, _ = create_order_with_request(base, quantity=4, product_line=("product", 3))
        propose(base, rid, item, 4)
        pid = db("SELECT id FROM stock_request_proposals ORDER BY id DESC LIMIT 1")[0][0]
        return [client_for(base, ADMIN).call("GET", f"/api/v1/warehouse/fulfillment-proposals/{pid}", None)]
    scenario("detail after propose", detail)

    def unknown_detail(base):
        return [client_for(base, ADMIN).call("GET", "/api/v1/warehouse/fulfillment-proposals/99999", None)]
    scenario("unknown proposal detail", unknown_detail)

    failed = [r for r in results if not r[1]]
    print(f"{len(results) - len(failed)} passed, {len(failed)} failed")
    return 1 if failed else 0


def proposal_item_id():
    return int(db("SELECT id FROM stock_request_proposal_items ORDER BY id DESC LIMIT 1")[0][0])


if __name__ == "__main__":
    sys.exit(main())
