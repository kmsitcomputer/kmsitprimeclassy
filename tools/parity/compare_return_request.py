#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for consumer return requests."""
import base64, hashlib, http.cookiejar, json, pathlib, re, subprocess, urllib.error, urllib.parse, urllib.request, uuid
from compare_orders import TestDatabaseLock

LARAVEL = "http://127.0.0.1:8899"
GO = "http://127.0.0.1:8082"
ROOT = pathlib.Path(__file__).resolve().parents[2]
PUBLIC_ROOTS = [ROOT / "backend/storage/app/public", ROOT / "go-backend/storage/app/public"]


class Client:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def call(self, method, path, body=None, headers=None):
        h = {"Accept": "application/json", "Origin": self.base, "Referer": self.base + "/",
             "X-Requested-With": "XMLHttpRequest", "User-Agent": "IMP010-return-parity"}
        if headers:
            h.update(headers)
        token = next((urllib.parse.unquote(c.value) for c in self.jar if c.name == "XSRF-TOKEN"), None)
        if token:
            h["X-XSRF-TOKEN"] = token
        try:
            with self.opener.open(urllib.request.Request(self.base + path, data=body, headers=h, method=method), timeout=45) as r:
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

    def multipart(self, path, values, content):
        boundary = "----IMP010" + uuid.uuid4().hex
        body = bytearray()
        for name, value in values:
            body.extend(f"--{boundary}\r\nContent-Disposition: form-data; name=\"{name}\"\r\n\r\n{value}\r\n".encode())
        if content is not None:
            body.extend(f"--{boundary}\r\nContent-Disposition: form-data; name=\"evidence\"; filename=\"return.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n".encode())
            body.extend(content + b"\r\n")
        body.extend(f"--{boundary}--\r\n".encode())
        return self.call("POST", path, bytes(body), {"Content-Type": f"multipart/form-data; boundary={boundary}"})


def db(sql):
    sql = sql.replace("`", "\\`").replace('"', '\\"')
    cmd = f'mariadb -uprimeclassy_app -p"$(cat $MARIADB_PASSWORD_FILE)" -N -B primeclassy_testing -e "{sql}"'
    result = subprocess.run(["docker", "exec", "primeclassy-mariadb", "sh", "-lc", cmd], capture_output=True, text=True, timeout=90)
    if result.returncode:
        raise RuntimeError(result.stderr[:500])
    return [line.split("\t") for line in result.stdout.strip().splitlines() if line]


def norm(value):
    if isinstance(value, dict):
        result = {}
        for key, child in value.items():
            if key in {"id", "order_id", "order_item_id", "created_at", "order_no"}:
                continue
            if key == "evidence_url" and child:
                result[key] = "/storage/returns/evidence/<file>"
            else:
                result[key] = norm(child)
        return result
    if isinstance(value, list):
        return [norm(child) for child in value]
    if isinstance(value, str) and len(value) >= 20 and value[4:5] == "-" and "T" in value:
        return "<timestamp>"
    return value


def image_bytes():
    return base64.b64decode("/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdAB//2Q==")


def fields(item_id, quantity=1, reason="Consumer return parity"):
    return [("reason", reason), ("items[0][order_item_id]", str(item_id)), ("items[0][quantity]", str(quantity))]


def capture_state(order_id, item_id, return_id, file_path):
    audit = db(f"SELECT event,description,CAST(properties AS CHAR),ip_address,user_agent,subject_type FROM activity_logs WHERE subject_id={return_id} AND event='return.requested' ORDER BY id DESC LIMIT 1")
    if audit:
        props = json.loads(audit[0][2]); props.pop("order_id", None)
        audit[0][2] = json.dumps(props, sort_keys=True, separators=(",", ":"))
        # Laravel and Go are reached through different container network paths in this harness.
        audit[0][3] = "<client-ip>"
    notifications = db(f"SELECT recipient_user_id,type,severity,title,body,subject_type FROM admin_notifications WHERE order_id={order_id} ORDER BY recipient_user_id,type")
    for row in notifications:
        row[4] = re.sub(r"D-\d+", "D-<sequence>", row[4])
        row[4] = re.sub(r"RTN-\d+", "RTN-<sequence>", row[4])
    return {
        "return": db(f"SELECT status,reason,CAST(total_refund_amount AS CHAR),(consent_event_id IS NOT NULL) FROM returns WHERE id={return_id}"),
        "return_items": db(f"SELECT quantity_returned,CAST(refund_amount AS CHAR),restock,status,refund_status FROM return_items WHERE return_id={return_id} ORDER BY id"),
        "order_item": db(f"SELECT returned_quantity,status FROM order_items WHERE id={item_id} AND order_id={order_id}"),
        "audit": audit,
        "admin_notifications": notifications,
        "file_sha256": hashlib.sha256(file_path.read_bytes()).hexdigest(),
    }


def files_snapshot():
    found = set()
    for root in PUBLIC_ROOTS:
        directory = root / "returns/evidence"
        if directory.exists():
            found.update(path.resolve() for path in directory.iterdir() if path.is_file())
    return found


def new_files(before):
    after = files_snapshot()
    additions = after - before
    return {str(path.relative_to(next(root for root in PUBLIC_ROOTS if path.is_relative_to(root)))):
            hashlib.sha256(path.read_bytes()).hexdigest() for path in additions}


def cleanup_files(paths):
    for path in paths:
        pathlib.Path(path).unlink(missing_ok=True)


def main_unlocked():
    if db("SELECT DATABASE()")[0][0] != "primeclassy_testing":
        raise RuntimeError("refusing non-testing database")
    suffix = uuid.uuid4().hex[:12]
    key = "codex-return-" + suffix
    reserved = int(db("SELECT quantity_reserved FROM product_stocks WHERE agent_id=10 AND product_id=3")[0][0])
    seq = db("SELECT `value` FROM settings WHERE `key`='orders.invoice_sequence'")
    seq_exists, old_seq = bool(seq), seq[0][0] if seq else "0"
    orders, successes, failures = [], [], []
    error_outcomes = []
    try:
        for base in (LARAVEL, GO):
            consumer = Client(base)
            if consumer.login("konsumen@imp010.test") != 200:
                raise RuntimeError(f"test login failed for {base}")
            order_body = {"items": [{"product_id": 3, "quantity": 1}], "payment_method_code": "bank_transfer",
                          "shipping_method": "pickup", "recipient_name": "Synthetic recipient", "recipient_phone": "081200000001",
                          "address_line": "Parity test address", "village_id": "32730101", "latitude": -6.9, "longitude": 107.6}
            status, body = consumer.call("POST", "/api/v1/orders", json.dumps(order_body).encode(),
                                         {"Content-Type": "application/json", "Idempotency-Key": key + base[-4:]})
            if status != 201 or not body.get("data"):
                failures.append(f"{base}: assisted order {status} {body}"); successes.append(None); continue
            order_id = int(body["data"]["id"]); orders.append(order_id)
            item_id = int(db(f"SELECT id FROM order_items WHERE order_id={order_id} ORDER BY id LIMIT 1")[0][0])
            db(f"UPDATE order_items SET status='terkirim',fulfilled_quantity=1 WHERE id={item_id}")
            path = f"/api/v1/orders/{order_id}/returns"
            status, body = consumer.multipart(path, fields(item_id), image_bytes())
            if status != 201 or not body.get("data"):
                failures.append(f"{base}: return request {status} {body}"); successes.append(None); continue
            data = body["data"]; rid = int(data["id"])
            relative = urllib.parse.urlparse(data["evidence_url"]).path.removeprefix("/storage/")
            file_path = next((root / relative for root in PUBLIC_ROOTS if (root / relative).is_file()), None)
            if not file_path:
                failures.append(f"{base}: return evidence missing: {relative}"); successes.append(None)
            else:
                successes.append((norm(data), capture_state(order_id, item_id, rid, file_path), body.get("message")))

            # A rejected return is tested through fresh delivered-state after restoring the synthetic row.
            # Rejected attempts store the photo before ReturnService detects the state/quantity problem;
            # compare that filesystem side effect and clean only the files created by this harness.
            before = files_snapshot()
            db(f"UPDATE order_items SET status='diproses',returned_quantity=0 WHERE id={item_id}")
            pre_status, pre_body = consumer.multipart(path, fields(item_id), image_bytes())
            pre_files = new_files(before)
            if pre_status != 422 or "terkirim" not in pre_body.get("message", ""):
                failures.append(f"{base}: pre-delivery guard differs: {pre_status} {pre_body}")
            pre_db = db(f"SELECT status,returned_quantity FROM order_items WHERE id={item_id}")[0]
            db(f"UPDATE order_items SET status='terkirim',returned_quantity=0 WHERE id={item_id}")
            before = files_snapshot()
            qty_status, qty_body = consumer.multipart(path, fields(item_id, 2), image_bytes())
            qty_files = new_files(before)
            if qty_status != 422:
                failures.append(f"{base}: quantity guard differs: {qty_status} {qty_body}")
            qty_db = db(f"SELECT status,returned_quantity FROM order_items WHERE id={item_id}")[0]
            seller = Client(base)
            if seller.login("sales@imp010.test") != 200:
                raise RuntimeError(f"seller login failed for {base}")
            owner_status, owner_body = seller.multipart(path, fields(item_id), image_bytes())
            if owner_status != 403:
                failures.append(f"{base}: role policy differs: {owner_status} {owner_body}")
            json_status, json_body = consumer.call("POST", path, json.dumps({"reason": "", "items": []}).encode(),
                                                    {"Content-Type": "application/json"})
            expected = {"reason", "items", "evidence"}
            if json_status != 422 or not expected.issubset(set(json_body.get("errors", {}))):
                failures.append(f"{base}: JSON validation differs: {json_status} {json_body}")
            nested_status, nested_body = consumer.call("POST", path, json.dumps({"reason": "missing child fields", "items": [{}]}).encode(),
                                                        {"Content-Type": "application/json"})
            nested_expected = {"evidence", "items.0.order_item_id", "items.0.quantity"}
            if nested_status != 422 or not nested_expected.issubset(set(nested_body.get("errors", {}))):
                failures.append(f"{base}: nested JSON validation differs: {nested_status} {nested_body}")
            urlencoded_body = urllib.parse.urlencode([("reason", ""), ("items[0][order_item_id]", ""),
                                                       ("items[0][quantity]", "")]).encode()
            form_status, form_body = consumer.call("POST", path, urlencoded_body,
                                                    {"Content-Type": "application/x-www-form-urlencoded"})
            form_expected = {"evidence", "reason", "items.0.order_item_id", "items.0.quantity"}
            if form_status != 422 or not form_expected.issubset(set(form_body.get("errors", {}))):
                failures.append(f"{base}: URL-encoded form validation differs: {form_status} {form_body}")
            # Duplicate lines force a failure after the first line has been applied inside the transaction.
            before_rollback_rows = db(f"SELECT (SELECT COUNT(*) FROM returns WHERE order_id={order_id}) + (SELECT COUNT(*) FROM return_items WHERE order_item_id={item_id}) + (SELECT COUNT(*) FROM activity_logs WHERE event='return.requested' AND subject_id IN (SELECT id FROM returns WHERE order_id={order_id}))")[0][0]
            before = files_snapshot()
            rollback_status, rollback_body = consumer.multipart(path,
                fields(item_id) + [("items[1][order_item_id]", str(item_id)), ("items[1][quantity]", "1")], image_bytes())
            rollback_files = new_files(before)
            rollback_state = db(f"SELECT status,returned_quantity FROM order_items WHERE id={item_id}")[0]
            rollback_count = db(f"SELECT (SELECT COUNT(*) FROM returns WHERE order_id={order_id}) + (SELECT COUNT(*) FROM return_items WHERE order_item_id={item_id}) + (SELECT COUNT(*) FROM activity_logs WHERE event='return.requested' AND subject_id IN (SELECT id FROM returns WHERE order_id={order_id}))")[0][0]
            if rollback_status != 422 or rollback_state != ["terkirim", "0"] or rollback_count != before_rollback_rows:
                failures.append(f"{base}: mid-transaction failure did not roll back: {rollback_status} {rollback_state} rows={rollback_count} {rollback_body}")
            error_outcomes.append({"pre_delivery": (pre_status, norm(pre_body), pre_db, sorted(pre_files.values())),
                                   "quantity": (qty_status, norm(qty_body), qty_db, sorted(qty_files.values())),
                                   "rollback": (rollback_status, norm(rollback_body), rollback_state, rollback_count, sorted(rollback_files.values())),
                                   "json": (json_status, norm(json_body)), "nested_json": (nested_status, norm(nested_body)),
                                   "urlencoded": (form_status, norm(form_body))})
            cleanup_files([str(pathlib.Path(root) / rel) for root in PUBLIC_ROOTS
                           for rel in set(pre_files) | set(qty_files) | set(rollback_files)
                           if (root / rel).exists()])
        if len(successes) == 2 and all(successes):
            if successes[0][0] != successes[1][0]: failures.append("success resource mismatch: " + json.dumps(successes[0][0], sort_keys=True) + " vs " + json.dumps(successes[1][0], sort_keys=True))
            if successes[0][1] != successes[1][1]:
                failures.append("persisted state/evidence/audit mismatch")
                for key in sorted(set(successes[0][1]) | set(successes[1][1])):
                    if successes[0][1].get(key) != successes[1][1].get(key):
                        failures.append(f"  {key}: Laravel={successes[0][1].get(key)!r} Go={successes[1][1].get(key)!r}")
            if successes[0][2] != successes[1][2]: failures.append(f"success message mismatch: {successes[0][2]} vs {successes[1][2]}")
        if len(successes) != 2 or any(item is None for item in successes): failures.append("missing runtime success evidence")
        if len(error_outcomes) == 2 and error_outcomes[0] != error_outcomes[1]:
            failures.append("error/rollback response, persisted state, or filesystem side effect mismatch: " + json.dumps(error_outcomes, sort_keys=True))
    finally:
        for order_id in orders:
            db(f"DELETE FROM admin_notifications WHERE order_id={order_id}")
            for row in db(f"SELECT id,evidence_path FROM returns WHERE order_id={order_id}"):
                rid, rel = int(row[0]), row[1]
                db(f"DELETE FROM activity_logs WHERE subject_type LIKE '%ReturnRequest' AND subject_id={rid}")
                db(f"DELETE FROM return_items WHERE return_id={rid}"); db(f"DELETE FROM returns WHERE id={rid}")
                if rel:
                    for root in PUBLIC_ROOTS: (root / rel).unlink(missing_ok=True)
            item_ids = db(f"SELECT id FROM order_items WHERE order_id={order_id}"); ids = ",".join(x[0] for x in item_ids) or "0"
            db(f"DELETE FROM activity_logs WHERE (subject_id={order_id} AND subject_type LIKE '%Order') OR (subject_id IN ({ids}) AND subject_type LIKE '%OrderItem')")
            for stmt in [f"DELETE FROM order_initial_report_items WHERE order_id={order_id}",
                         f"DELETE FROM stock_request_items WHERE stock_request_id IN (SELECT id FROM stock_requests WHERE order_id={order_id})",
                         f"DELETE FROM stock_requests WHERE order_id={order_id}", f"DELETE FROM commissions WHERE order_id={order_id}",
                         f"DELETE FROM payment_transactions WHERE order_id={order_id}",
                         f"DELETE FROM stock_movements WHERE reference_type='order' AND reference_id={order_id} AND type='reserve'",
                         f"DELETE FROM shipments WHERE order_id={order_id}", f"DELETE FROM order_items WHERE order_id={order_id}",
                         f"DELETE FROM orders WHERE id={order_id}"]:
                db(stmt)
        db(f"UPDATE product_stocks SET quantity_reserved={reserved} WHERE agent_id=10 AND product_id=3")
        db(f"UPDATE settings SET `value`='{old_seq}' WHERE `key`='orders.invoice_sequence'" if seq_exists else "DELETE FROM settings WHERE `key`='orders.invoice_sequence'")
    if failures:
        print(f"ordinary return differential: {max(0, 9-len(failures))} passed, {len(failures)} failed")
        for failure in failures: print("FAIL " + failure)
        return 1
    print("ordinary return differential: 9 passed, 0 failed")
    return 0


def main():
    with TestDatabaseLock():
        return main_unlocked()


if __name__ == "__main__": raise SystemExit(main())
