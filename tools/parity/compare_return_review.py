#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for admin return review and refund-ledger effects."""
import base64, hashlib, http.cookiejar, json, os, pathlib, re, subprocess, urllib.error, urllib.parse, urllib.request, uuid

LARAVEL = os.environ.get("PARITY_LARAVEL_URL", "http://127.0.0.1:8899")
GO = os.environ.get("PARITY_GO_URL", "http://127.0.0.1:8082")
ROOT = pathlib.Path(__file__).resolve().parents[2]
PUBLIC_ROOTS = [ROOT / "backend/storage/app/public", ROOT / "go-backend/storage/app/public"]
TS = re.compile(r"^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d+)?(?:Z|\+00:00)?$")


class Client:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def call(self, method, path, body=None, headers=None):
        h = {"Accept": "application/json", "Origin": self.base, "Referer": self.base + "/",
             "X-Requested-With": "XMLHttpRequest", "User-Agent": "IMP010-return-review-parity"}
        if headers:
            h.update(headers)
        token = next((urllib.parse.unquote(c.value) for c in self.jar if c.name == "XSRF-TOKEN"), None)
        if token:
            h["X-XSRF-TOKEN"] = token
        try:
            with self.opener.open(urllib.request.Request(self.base + path, data=body, headers=h, method=method), timeout=60) as r:
                raw = r.read().decode()
                return r.status, json.loads(raw) if raw else None
        except urllib.error.HTTPError as e:
            raw = e.read().decode()
            try:
                return e.code, json.loads(raw)
            except Exception:
                return e.code, {"raw": raw}

    def login(self, email):
        self.call("GET", "/sanctum/csrf-cookie")
        return self.call("POST", "/api/v1/auth/login", json.dumps({"identifier": email, "password": "Secret123!"}).encode(),
                         {"Content-Type": "application/json"})[0]


def db(sql):
    sql = sql.replace("`", "\\`").replace('"', '\\"')
    cmd = f'mariadb -uprimeclassy_app -p"$(cat $MARIADB_PASSWORD_FILE)" -N -B primeclassy_testing -e "{sql}"'
    result = subprocess.run(["docker", "exec", "primeclassy-mariadb", "sh", "-lc", cmd], capture_output=True, text=True, timeout=90)
    if result.returncode:
        raise RuntimeError(result.stderr[:700])
    return [line.split("\t") for line in result.stdout.strip().splitlines() if line]


def image_bytes():
    return base64.b64decode("/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdAB//2Q==")


def norm(value):
    if isinstance(value, dict):
        result = {}
        for key, child in value.items():
            if key in {"id", "order_id", "order_item_id", "order_no"}:
                continue
            if key == "evidence_url":
                result[key] = "<evidence>" if child else None
            else:
                result[key] = norm(child)
        return result
    if isinstance(value, list):
        return [norm(child) for child in value]
    if isinstance(value, str) and TS.match(value):
        return "<timestamp>"
    return value


def normalize_json(raw, return_item_id, order_id):
    value = json.loads(raw) if raw else None
    if isinstance(value, dict):
        for key in ("order_id", "order_item_id", "return_item_id", "snapshot_id", "source_id", "obligation_id"):
            if key in value:
                value[key] = "<id>"
        if "checksum" in value:
            value["checksum"] = "<checksum>"
        if "event_value_cents" in value:
            pass
        if "allocations" in value and isinstance(value["allocations"], dict):
            pass
        if "obligation_key" in value:
            value["obligation_key"] = re.sub(r"return_item:\d+:", "return_item:<item>:", value["obligation_key"])
    return json.dumps(value, sort_keys=True, separators=(",", ":")) if value is not None else "<null>"


def capture(order_id, return_id, return_item_id, order_item_id):
    rows = {}
    rows["return"] = db(f"SELECT status,CAST(total_refund_amount AS CHAR),(reviewed_by IS NOT NULL),(reviewed_at IS NOT NULL) FROM returns WHERE id={return_id}")
    rows["return_item"] = db(f"SELECT status,refund_status,quantity_returned,CAST(refund_amount AS CHAR) FROM return_items WHERE id={return_item_id}")
    rows["order_item"] = db(f"SELECT status,returned_quantity FROM order_items WHERE id={order_item_id}")
    rows["snapshot"] = db(f"SELECT `trigger`,provenance,CAST(subtotal_basis AS CHAR),CAST(effective_voucher_basis AS CHAR) FROM order_financial_snapshots WHERE order_id={order_id}")
    rows["snapshot_unit_count"] = db(f"SELECT COUNT(*) FROM order_financial_snapshot_units WHERE snapshot_id IN (SELECT id FROM order_financial_snapshots WHERE order_id={order_id})")
    rows["snapshot_units"] = db(f"SELECT unit_index,CAST(unit_gross AS CHAR),CAST(allocated_discount AS CHAR),CAST(unit_net AS CHAR),(consumed_at IS NOT NULL),consumed_by_type,consumption_seq FROM order_financial_snapshot_units WHERE snapshot_id IN (SELECT id FROM order_financial_snapshots WHERE order_id={order_id}) ORDER BY unit_index")
    rows["line_basis"] = db(f"SELECT qty_basis,CAST(unit_gross_basis AS CHAR),CAST(line_gross_basis AS CHAR),CAST(allocated_discount_total AS CHAR),CAST(line_net_basis AS CHAR),consumed_units_count,CAST(consumed_net_total AS CHAR),provenance FROM order_item_financial_bases WHERE order_id={order_id}")
    rows["sales_reductions"] = db(f"SELECT source_type,amount,applied_to_order_total,provenance FROM order_sales_reductions WHERE order_id={order_id} ORDER BY id")
    obligations = db(f"SELECT source_type,amount,status,provenance,reason,approval_source,CAST(deferred_amount AS CHAR),CAST(deferred_remaining AS CHAR),CAST(metadata AS CHAR) FROM refund_obligations WHERE order_id={order_id} ORDER BY id")
    for row in obligations:
        row[-1] = normalize_json(row[-1], return_item_id, order_id)
    rows["obligations"] = obligations
    events = db(f"SELECT event_type,reason,CAST(payload AS CHAR) FROM refund_ledger_events WHERE order_id={order_id} ORDER BY id")
    for row in events:
        row[2] = normalize_json(row[2], return_item_id, order_id)
    rows["refund_events"] = events
    audit = db(f"SELECT event,description,CAST(properties AS CHAR),ip_address,user_agent FROM activity_logs WHERE subject_id={return_id} AND subject_type LIKE '%ReturnRequest' ORDER BY id")
    for row in audit:
        row[2] = normalize_json(row[2], return_item_id, order_id)
    rows["activity"] = audit
    return rows


def create_case(base, token, suffix):
    consumer = Client(base)
    if consumer.login("konsumen@imp010.test") != 200:
        raise RuntimeError("consumer login failed")
    payload = {"items": [{"product_id": 3, "quantity": 1}], "payment_method_code": "bank_transfer",
               "shipping_method": "pickup", "recipient_name": "Synthetic return review", "recipient_phone": "081200000004",
               "address_line": "Parity test address", "village_id": "32730101", "latitude": -6.9, "longitude": 107.6}
    status, response = consumer.call("POST", "/api/v1/orders", json.dumps(payload).encode(),
                                     {"Content-Type": "application/json", "Idempotency-Key": f"codex-rreview-{token}-{suffix}-{base[-4:]}"})
    if status != 201 or not response.get("data"):
        raise RuntimeError(f"order setup failed: {status} {response}")
    order_id = int(response["data"]["id"])
    order_ids.append(order_id)
    order_item_id = int(db(f"SELECT id FROM order_items WHERE order_id={order_id} ORDER BY id LIMIT 1")[0][0])
    db(f"UPDATE order_items SET status='terkirim',fulfilled_quantity=1 WHERE id={order_item_id}")
    boundary = "----IMP010" + uuid.uuid4().hex
    body = bytearray()
    for name, value in [("reason", "Admin return review parity"), ("items[0][order_item_id]", str(order_item_id)), ("items[0][quantity]", "1")]:
        body.extend(f"--{boundary}\r\nContent-Disposition: form-data; name=\"{name}\"\r\n\r\n{value}\r\n".encode())
    body.extend(f"--{boundary}\r\nContent-Disposition: form-data; name=\"evidence\"; filename=\"return.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n".encode())
    body.extend(image_bytes() + f"\r\n--{boundary}--\r\n".encode())
    status, response = consumer.call("POST", f"/api/v1/orders/{order_id}/returns", bytes(body),
                                     {"Content-Type": f"multipart/form-data; boundary={boundary}"})
    if status != 201 or not response.get("data"):
        raise RuntimeError(f"return setup failed: {status} {response}")
    return_id = int(response["data"]["id"])
    return_item_id = int(response["data"]["items"][0]["id"])
    return_file_paths.append(response["data"].get("evidence_url"))
    admin = Client(base)
    if admin.login("admin@imp010.test") != 200:
        raise RuntimeError("admin login failed")
    return admin, order_id, return_id, return_item_id, order_item_id


def main():
    global order_ids, return_file_paths
    if db("SELECT DATABASE()")[0][0] != "primeclassy_testing":
        raise RuntimeError("refusing non-testing database")
    token = uuid.uuid4().hex[:10]
    order_ids, return_file_paths = [], []
    reserved = int(db("SELECT quantity_reserved FROM product_stocks WHERE agent_id=10 AND product_id=3")[0][0])
    sequence = db("SELECT `value` FROM settings WHERE `key`='orders.invoice_sequence'")
    sequence_exists, old_sequence = bool(sequence), sequence[0][0] if sequence else "0"
    failures, runtime = [], []
    try:
        for base in (LARAVEL, GO):
            approved = create_case(base, token, "approve")
            rejected = create_case(base, token, "reject")
            admin, _, approved_id, approved_item_id, approved_order_item_id = approved
            route = f"/api/v1/admin/returns/{approved_id}/review"
            bad_boolean = admin.call("PATCH", route, json.dumps({"approved": "yes"}).encode(), {"Content-Type": "application/json"})
            missing = admin.call("PATCH", route, json.dumps({}).encode(), {"Content-Type": "application/json"})
            long_note = admin.call("PATCH", route, json.dumps({"approved": True, "note": "x" * 256}).encode(), {"Content-Type": "application/json"})
            sales = Client(base)
            sales.login("sales@imp010.test")
            denied = sales.call("PATCH", route, json.dumps({"approved": True}).encode(), {"Content-Type": "application/json"})
            status, response = admin.call("PATCH", route, json.dumps({"approved": True, "note": "  approved with review  "}).encode(), {"Content-Type": "application/json"})
            if status != 200:
                failures.append(f"{base}: approval failed {status} {response}")
            approved_state = capture(approved[1], approved_id, approved_item_id, approved_order_item_id)
            stale = admin.call("PATCH", route, json.dumps({"approved": True}).encode(), {"Content-Type": "application/json"})

            rejecting_admin, _, rejected_id, rejected_item_id, rejected_order_item_id = rejected
            reject_status, reject_response = rejecting_admin.call("PATCH", f"/api/v1/admin/returns/{rejected_id}/review",
                json.dumps({"approved": False, "note": "  rejected after review  "}).encode(), {"Content-Type": "application/json"})
            if reject_status != 200:
                failures.append(f"{base}: rejection failed {reject_status} {reject_response}")
            rejected_state = capture(rejected[1], rejected_id, rejected_item_id, rejected_order_item_id)
            runtime.append({"bad_boolean": bad_boolean, "missing": missing, "long_note": long_note, "denied": denied,
                            "approved": (status, norm(response), approved_state), "stale": stale,
                            "rejected": (reject_status, norm(reject_response), rejected_state)})
    finally:
        for order_id in order_ids:
            for row in db(f"SELECT id,evidence_path FROM returns WHERE order_id={order_id}"):
                return_id, relative = int(row[0]), row[1]
                db(f"DELETE FROM activity_logs WHERE subject_type LIKE '%ReturnRequest' AND subject_id={return_id}")
                db(f"DELETE FROM return_items WHERE return_id={return_id}")
                db(f"DELETE FROM returns WHERE id={return_id}")
                if relative:
                    for root in PUBLIC_ROOTS: (root / relative).unlink(missing_ok=True)
            db(f"DELETE FROM activity_logs WHERE subject_id={order_id} AND subject_type LIKE '%Order'")
            db(f"DELETE FROM refund_ledger_events WHERE order_id={order_id}")
            db(f"DELETE FROM refund_obligations WHERE order_id={order_id}")
            db(f"DELETE FROM order_sales_reductions WHERE order_id={order_id}")
            db(f"DELETE FROM order_item_financial_bases WHERE order_id={order_id}")
            db(f"DELETE FROM order_financial_snapshot_units WHERE snapshot_id IN (SELECT id FROM order_financial_snapshots WHERE order_id={order_id})")
            db(f"DELETE FROM order_financial_snapshots WHERE order_id={order_id}")
            ids = ",".join(row[0] for row in db(f"SELECT id FROM order_items WHERE order_id={order_id}")) or "0"
            for statement in [f"DELETE FROM activity_logs WHERE subject_id IN ({ids}) AND subject_type LIKE '%OrderItem'",
                              f"DELETE FROM order_initial_report_items WHERE order_id={order_id}",
                              f"DELETE FROM stock_request_items WHERE stock_request_id IN (SELECT id FROM stock_requests WHERE order_id={order_id})",
                              f"DELETE FROM stock_requests WHERE order_id={order_id}", f"DELETE FROM commissions WHERE order_id={order_id}",
                              f"DELETE FROM payment_transactions WHERE order_id={order_id}",
                              f"DELETE FROM stock_movements WHERE reference_type='order' AND reference_id={order_id} AND type='reserve'",
                              f"DELETE FROM shipments WHERE order_id={order_id}", f"DELETE FROM order_items WHERE order_id={order_id}",
                              f"DELETE FROM orders WHERE id={order_id}"]:
                db(statement)
        db(f"UPDATE product_stocks SET quantity_reserved={reserved} WHERE agent_id=10 AND product_id=3")
        db(f"UPDATE settings SET `value`='{old_sequence}' WHERE `key`='orders.invoice_sequence'" if sequence_exists else "DELETE FROM settings WHERE `key`='orders.invoice_sequence'")
    if len(runtime) == 2 and runtime[0] != runtime[1]:
        failures.append("Laravel and Go validation, transitions, resource, snapshot/ledger or audit state differ: " + json.dumps(runtime, sort_keys=True, default=str))
    if failures:
        print(f"return review differential: {max(0, 7-len(failures))} passed, {len(failures)} failed")
        for failure in failures: print("FAIL " + failure)
        return 1
    print("return review differential: 7 passed, 0 failed")
    return 0


if __name__ == "__main__": raise SystemExit(main())
