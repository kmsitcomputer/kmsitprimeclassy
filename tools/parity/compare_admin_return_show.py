#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for branch-scoped admin return detail."""
import base64, hashlib, http.cookiejar, json, pathlib, re, subprocess, urllib.error, urllib.parse, urllib.request, uuid

LARAVEL = "http://127.0.0.1:8899"
GO = "http://127.0.0.1:8082"
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
             "X-Requested-With": "XMLHttpRequest", "User-Agent": "IMP010-admin-return-show"}
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


def db(sql):
    sql = sql.replace("`", "\\`").replace('"', '\\"')
    cmd = f'mariadb -uprimeclassy_app -p"$(cat $MARIADB_PASSWORD_FILE)" -N -B primeclassy_testing -e "{sql}"'
    result = subprocess.run(["docker", "exec", "primeclassy-mariadb", "sh", "-lc", cmd], capture_output=True, text=True, timeout=90)
    if result.returncode:
        raise RuntimeError(result.stderr[:500])
    return [line.split("\t") for line in result.stdout.strip().splitlines() if line]


def normalize(value):
    if isinstance(value, dict):
        out = {}
        for key, child in value.items():
            if key in {"id", "order_id", "order_item_id", "order_no"}:
                continue
            if key == "evidence_url":
                out[key] = "<evidence>" if child else None
            else:
                out[key] = normalize(child)
        return out
    if isinstance(value, list):
        return [normalize(child) for child in value]
    if isinstance(value, str) and TS.match(value):
        return "<timestamp>"
    return value


def image_bytes():
    return base64.b64decode("/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdAB//2Q==")


def main():
    if db("SELECT DATABASE()")[0][0] != "primeclassy_testing":
        raise RuntimeError("refusing non-testing database")
    token = uuid.uuid4().hex[:12]
    orders, return_files = [], []
    reserved = int(db("SELECT quantity_reserved FROM product_stocks WHERE agent_id=10 AND product_id=3")[0][0])
    sequence = db("SELECT `value` FROM settings WHERE `key`='orders.invoice_sequence'")
    sequence_exists, old_sequence = bool(sequence), sequence[0][0] if sequence else "0"
    failures, comparisons = [], []
    try:
        for base in (LARAVEL, GO):
            consumer = Client(base)
            if consumer.login("konsumen@imp010.test") != 200:
                raise RuntimeError(f"consumer login failed for {base}")
            order_body = {"items": [{"product_id": 3, "quantity": 1}], "payment_method_code": "bank_transfer",
                          "shipping_method": "pickup", "recipient_name": "Synthetic return detail", "recipient_phone": "081200000003",
                          "address_line": "Parity test address", "village_id": "32730101", "latitude": -6.9, "longitude": 107.6}
            status, body = consumer.call("POST", "/api/v1/orders", json.dumps(order_body).encode(),
                                         {"Content-Type": "application/json", "Idempotency-Key": f"codex-rshow-{token}-{base[-4:]}"})
            if status != 201 or not body.get("data"):
                raise RuntimeError(f"synthetic order failed: {status} {body}")
            order_id = int(body["data"]["id"]); orders.append(order_id)
            item_id = int(db(f"SELECT id FROM order_items WHERE order_id={order_id} ORDER BY id LIMIT 1")[0][0])
            db(f"UPDATE order_items SET status='terkirim',fulfilled_quantity=1 WHERE id={item_id}")
            boundary = "----IMP010" + uuid.uuid4().hex
            parts = bytearray()
            for name, value in [("reason", "Admin return detail parity"), ("items[0][order_item_id]", str(item_id)), ("items[0][quantity]", "1")]:
                parts.extend(f"--{boundary}\r\nContent-Disposition: form-data; name=\"{name}\"\r\n\r\n{value}\r\n".encode())
            parts.extend(f"--{boundary}\r\nContent-Disposition: form-data; name=\"evidence\"; filename=\"return.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n".encode())
            parts.extend(image_bytes() + f"\r\n--{boundary}--\r\n".encode())
            status, created = consumer.call("POST", f"/api/v1/orders/{order_id}/returns", bytes(parts),
                                            {"Content-Type": f"multipart/form-data; boundary={boundary}"})
            if status != 201 or not created.get("data"):
                raise RuntimeError(f"synthetic return failed: {status} {created}")
            return_id = int(created["data"]["id"])
            evidence = urllib.parse.urlparse(created["data"]["evidence_url"]).path.removeprefix("/storage/")
            return_files.append(evidence)
            responses = {}
            for label, email in (("admin", "admin@imp010.test"), ("agen", "agen@imp010.test"), ("super", "super@imp010.test"), ("consumer", "konsumen@imp010.test")):
                client = Client(base)
                if client.login(email) != 200:
                    raise RuntimeError(f"{label} login failed")
                responses[label] = client.call("GET", f"/api/v1/admin/returns/{return_id}")
            admin = Client(base); admin.login("admin@imp010.test")
            responses["missing"] = admin.call("GET", "/api/v1/admin/returns/999999999")
            responses["malformed_id"] = admin.call("GET", "/api/v1/admin/returns/not-an-id")
            comparisons.append({key: (value[0], normalize(value[1])) for key, value in responses.items()})
    finally:
        for order_id in orders:
            for row in db(f"SELECT id,evidence_path FROM returns WHERE order_id={order_id}"):
                return_id, evidence_path = int(row[0]), row[1]
                db(f"DELETE FROM activity_logs WHERE subject_type LIKE '%ReturnRequest' AND subject_id={return_id}")
                db(f"DELETE FROM return_items WHERE return_id={return_id}")
                db(f"DELETE FROM returns WHERE id={return_id}")
                if evidence_path:
                    for root in PUBLIC_ROOTS: (root / evidence_path).unlink(missing_ok=True)
            ids = ",".join(row[0] for row in db(f"SELECT id FROM order_items WHERE order_id={order_id}")) or "0"
            for statement in [f"DELETE FROM activity_logs WHERE (subject_id={order_id} AND subject_type LIKE '%Order') OR (subject_id IN ({ids}) AND subject_type LIKE '%OrderItem')",
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
    if len(comparisons) == 2:
        for key in sorted(comparisons[0]):
            if comparisons[0][key] != comparisons[1][key]:
                failures.append(f"{key} differs: Laravel={comparisons[0][key]} Go={comparisons[1][key]}")
    else:
        failures.append("missing runtime response set")
    if failures:
        print(f"admin return detail: {max(0, 6-len(failures))} passed, {len(failures)} failed")
        for failure in failures: print("FAIL " + failure)
        return 1
    print("admin return detail: 6 passed, 0 failed")
    return 0


if __name__ == "__main__": raise SystemExit(main())
