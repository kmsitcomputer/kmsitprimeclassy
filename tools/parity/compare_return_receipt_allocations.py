#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for accepted return receipt allocations."""
import base64, concurrent.futures, hashlib, http.cookiejar, json, pathlib, subprocess, urllib.error, urllib.parse, urllib.request, uuid

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
             "X-Requested-With": "XMLHttpRequest", "User-Agent": "IMP010-return-allocation-parity"}
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


def image_bytes():
    return base64.b64decode("/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdAB//2Q==")


def norm(value):
    if isinstance(value, dict):
        return {k: ("<time>" if k in {"allocated_at", "created_at", "updated_at"} else norm(v))
                for k, v in value.items() if k not in {"id", "return_item_id"}}
    if isinstance(value, list):
        return [norm(v) for v in value]
    return value


def label_receipt_ids(value, receipt_ids):
    labels = {int(receipt_id): f"receipt-{index}" for index, receipt_id in enumerate(receipt_ids)}
    if isinstance(value, dict):
        return {key: (labels.get(int(child), child) if key == "receipt_id" and str(child).isdigit()
                      else label_receipt_ids(child, receipt_ids)) for key, child in value.items()}
    if isinstance(value, list):
        return [label_receipt_ids(child, receipt_ids) for child in value]
    return value


def snapshot(return_item_id, order_id, receipt_ids):
    allocations = db(f"SELECT order_item_receipt_id,quantity,allocated_by,COALESCE(evidence_note,'<null>') FROM return_item_receipt_allocations WHERE return_item_id={return_item_id} ORDER BY id")
    labels = {str(receipt_id): f"receipt-{index}" for index, receipt_id in enumerate(receipt_ids)}
    allocations = [[labels.get(row[0], row[0]), *row[1:]] for row in allocations]
    audit = db(f"SELECT event,description,CAST(properties AS CHAR),ip_address,user_agent,subject_type,subject_id FROM activity_logs WHERE event='return_item.receipt_allocated' AND subject_id={order_id} ORDER BY id")
    if audit:
        properties = json.loads(audit[0][2])
        properties["allocations"] = {labels.get(key, key): value for key, value in properties["allocations"].items()}
        properties["return_item_id"] = "<item>"
        audit[0][2] = json.dumps(properties, sort_keys=True, separators=(",", ":"))
        audit[0][6] = "<order>"
    return allocations, audit


def main():
    if db("SELECT DATABASE()")[0][0] != "primeclassy_testing":
        raise RuntimeError("refusing non-testing database")
    token = uuid.uuid4().hex[:12]
    orders_created = []
    reserved = int(db("SELECT quantity_reserved FROM product_stocks WHERE agent_id=10 AND product_id=3")[0][0])
    seq = db("SELECT `value` FROM settings WHERE `key`='orders.invoice_sequence'")
    seq_exists, old_seq = bool(seq), seq[0][0] if seq else "0"
    failures, runtime = [], []
    try:
        for base in (LARAVEL, GO):
            consumer = Client(base)
            if consumer.login("konsumen@imp010.test") != 200:
                raise RuntimeError(f"consumer login failed for {base}")
            order_body = {"items": [{"product_id": 3, "quantity": 2}], "payment_method_code": "bank_transfer",
                          "shipping_method": "pickup", "recipient_name": "Synthetic receipt allocation", "recipient_phone": "081200000002",
                          "address_line": "Parity test address", "village_id": "32730101", "latitude": -6.9, "longitude": 107.6}
            status, body = consumer.call("POST", "/api/v1/orders", json.dumps(order_body).encode(),
                                         {"Content-Type": "application/json", "Idempotency-Key": f"codex-ralloc-{token}-{base[-4:]}"})
            if status != 201 or not body.get("data"):
                raise RuntimeError(f"synthetic order creation failed {base}: {status} {body}")
            order_id = int(body["data"]["id"]); orders_created.append(order_id)
            item_id = int(db(f"SELECT id FROM order_items WHERE order_id={order_id} ORDER BY id LIMIT 1")[0][0])
            db(f"UPDATE order_items SET status='terkirim',fulfilled_quantity=2 WHERE id={item_id}")
            boundary = "----IMP010" + uuid.uuid4().hex
            multipart = bytearray()
            for name, value in [("reason", "Return receipt allocation parity"), ("items[0][order_item_id]", str(item_id)), ("items[0][quantity]", "2")]:
                multipart.extend(f"--{boundary}\r\nContent-Disposition: form-data; name=\"{name}\"\r\n\r\n{value}\r\n".encode())
            jpeg = image_bytes()
            multipart.extend(f"--{boundary}\r\nContent-Disposition: form-data; name=\"evidence\"; filename=\"return.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n".encode())
            multipart.extend(jpeg + f"\r\n--{boundary}--\r\n".encode())
            status, body = consumer.call("POST", f"/api/v1/orders/{order_id}/returns", bytes(multipart),
                                         {"Content-Type": f"multipart/form-data; boundary={boundary}"})
            if status != 201 or not body.get("data"):
                raise RuntimeError(f"synthetic return creation failed {base}: {status} {body}")
            return_id = int(body["data"]["id"])
            return_item_id = int(body["data"]["items"][0]["id"])
            evidence_path = urllib.parse.urlparse(body["data"]["evidence_url"]).path.removeprefix("/storage/")
            db(f"UPDATE return_items SET status='approved',inspected_at=UTC_TIMESTAMP(6),quantity_received=2,good_quantity=1,damaged_quantity=1,condition_status='mixed' WHERE id={return_item_id}")
            receipts = []
            for label, quantity in (("a", 1), ("b", 1)):
                fingerprint = hashlib.sha256(f"{token}-{label}".encode()).hexdigest()
                db(f"INSERT INTO order_item_receipts(order_item_id,order_id,agent_id,quantity,received_on,verified_by,verified_at,idempotency_key,request_fingerprint,created_at,updated_at) VALUES ({item_id},{order_id},10,1,CURDATE(),11,UTC_TIMESTAMP(6),'codex-ralloc-{token}-{order_id}-{label}','{fingerprint}',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")
                receipts.append(int(db(f"SELECT MAX(id) FROM order_item_receipts WHERE order_id={order_id} AND order_item_id={item_id}")[0][0]))
            extra_items = []
            for state, received in (("pending", 0), ("approved", 2), ("approved", 1)):
                db(f"INSERT INTO return_items(return_id,order_item_id,quantity_returned,refund_amount,restock,status,refund_status,quantity_received,created_at,updated_at) SELECT return_id,order_item_id,quantity_returned,refund_amount,restock,'{state}','not_required',{received},UTC_TIMESTAMP(6),UTC_TIMESTAMP(6) FROM return_items WHERE id={return_item_id}")
                extra_items.append(int(db(f"SELECT MAX(id) FROM return_items WHERE return_id={return_id}")[0][0]))
            db(f"UPDATE return_items SET inspected_at=NULL WHERE id={extra_items[0]}")
            db(f"UPDATE return_items SET inspected_at=UTC_TIMESTAMP(6) WHERE id IN ({extra_items[1]},{extra_items[2]})")
            admin = Client(base)
            if admin.login("admin@imp010.test") != 200:
                raise RuntimeError(f"admin login failed for {base}")
            sales = Client(base)
            if sales.login("sales@imp010.test") != 200:
                raise RuntimeError(f"sales login failed for {base}")
            path = f"/api/v1/return-items/{return_item_id}/receipt-allocations"
            body = {"allocations": [{"receipt_id": receipts[0], "quantity": 1}, {"receipt_id": receipts[1], "quantity": 1}], "evidence_note": "  physical receipt checked  "}
            status, response = admin.call("POST", path, json.dumps(body).encode(), {"Content-Type": "application/json"})
            if status != 201:
                failures.append(f"{base}: allocation success {status} {response}")
            initial = (label_receipt_ids(norm(response), receipts), snapshot(return_item_id, order_id, receipts))
            replay_status, replay = admin.call("POST", path, json.dumps(body).encode(), {"Content-Type": "application/json"})
            conflict_body = {"allocations": [{"receipt_id": receipts[0], "quantity": 2}], "evidence_note": "different"}
            conflict_status, conflict = admin.call("POST", path, json.dumps(conflict_body).encode(), {"Content-Type": "application/json"})
            other_role = sales.call("POST", path, json.dumps(body).encode(), {"Content-Type": "application/json"})
            missing = admin.call("POST", path, json.dumps({}).encode(), {"Content-Type": "application/json"})
            empty = admin.call("POST", path, json.dumps({"allocations": []}).encode(), {"Content-Type": "application/json"})
            bad_nested = admin.call("POST", path, json.dumps({"allocations": [{"receipt_id": "x", "quantity": 0}]}).encode(), {"Content-Type": "application/json"})
            oversized_note = admin.call("POST", path, json.dumps({"allocations": body["allocations"], "evidence_note": "x" * 501}).encode(), {"Content-Type": "application/json"})
            not_eligible = admin.call("POST", f"/api/v1/return-items/{extra_items[0]}/receipt-allocations",
                                      json.dumps(body).encode(), {"Content-Type": "application/json"})
            total_mismatch = admin.call("POST", f"/api/v1/return-items/{extra_items[1]}/receipt-allocations",
                                        json.dumps({"allocations": [{"receipt_id": receipts[0], "quantity": 1}]}).encode(),
                                        {"Content-Type": "application/json"})
            capacity = admin.call("POST", f"/api/v1/return-items/{extra_items[2]}/receipt-allocations",
                                  json.dumps({"allocations": [{"receipt_id": receipts[0], "quantity": 1}]}).encode(),
                                  {"Content-Type": "application/json"})
            after = snapshot(return_item_id, order_id, receipts)
            concurrent_items = []
            for _ in range(2):
                db(f"INSERT INTO return_items(return_id,order_item_id,quantity_returned,refund_amount,restock,status,refund_status,quantity_received,inspected_at,created_at,updated_at) SELECT return_id,order_item_id,quantity_returned,refund_amount,restock,'approved','not_required',1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6) FROM return_items WHERE id={return_item_id}")
                concurrent_items.append(int(db(f"SELECT MAX(id) FROM return_items WHERE return_id={return_id}")[0][0]))
            fingerprint = hashlib.sha256(f"{token}-c".encode()).hexdigest()
            db(f"INSERT INTO order_item_receipts(order_item_id,order_id,agent_id,quantity,received_on,verified_by,verified_at,idempotency_key,request_fingerprint,created_at,updated_at) VALUES ({item_id},{order_id},10,1,CURDATE(),11,UTC_TIMESTAMP(6),'codex-ralloc-{token}-{order_id}-c','{fingerprint}',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")
            concurrent_receipt = int(db(f"SELECT MAX(id) FROM order_item_receipts WHERE order_id={order_id} AND order_item_id={item_id}")[0][0])
            concurrent_clients = [Client(base), Client(base)]
            if any(client.login("admin@imp010.test") != 200 for client in concurrent_clients):
                raise RuntimeError(f"concurrent admin login failed for {base}")
            def allocate_concurrently(index):
                concurrent_body = {"allocations": [{"receipt_id": concurrent_receipt, "quantity": 1}]}
                return concurrent_clients[index].call("POST", f"/api/v1/return-items/{concurrent_items[index]}/receipt-allocations",
                                                      json.dumps(concurrent_body).encode(), {"Content-Type": "application/json"})
            with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
                concurrent_results = list(pool.map(allocate_concurrently, (0, 1)))
            concurrent_statuses = sorted(status for status, _ in concurrent_results)
            concurrent_total = int(db(f"SELECT COALESCE(SUM(quantity),0) FROM return_item_receipt_allocations WHERE order_item_receipt_id={concurrent_receipt}")[0][0])
            if concurrent_statuses != [201, 422] or concurrent_total != 1:
                failures.append(f"{base}: concurrent allocation must serialize to one success/one capacity rejection, got {concurrent_statuses} with total={concurrent_total}")
            mismatch = admin.call("POST", f"/api/v1/return-items/999999999/receipt-allocations", json.dumps(body).encode(), {"Content-Type": "application/json"})
            runtime.append({"success": initial, "replay": (replay_status, label_receipt_ids(norm(replay), receipts)), "conflict": (conflict_status, norm(conflict)),
                            "role": other_role, "missing": missing, "empty": empty, "bad_nested": bad_nested,
                            "oversized_note": oversized_note, "not_eligible": not_eligible, "total_mismatch": total_mismatch,
                            "capacity": capacity, "missing_item": mismatch, "after": after,
                            "concurrent": (concurrent_statuses, concurrent_total)})
    finally:
        for order_id in orders_created:
            for row in db(f"SELECT id,evidence_path FROM returns WHERE order_id={order_id}"):
                rid, relative = int(row[0]), row[1]
                db(f"DELETE FROM activity_logs WHERE subject_id={order_id} AND event='return_item.receipt_allocated'")
                db(f"DELETE FROM activity_logs WHERE subject_id={rid} AND subject_type LIKE '%ReturnRequest'")
                db(f"DELETE FROM return_items WHERE return_id={rid}")
                db(f"DELETE FROM returns WHERE id={rid}")
                if relative:
                    for root in PUBLIC_ROOTS: (root / relative).unlink(missing_ok=True)
            db(f"DELETE FROM order_item_receipts WHERE order_id={order_id}")
            ids = ",".join(row[0] for row in db(f"SELECT id FROM order_items WHERE order_id={order_id}")) or "0"
            for stmt in [f"DELETE FROM activity_logs WHERE (subject_id={order_id} AND subject_type LIKE '%Order') OR (subject_id IN ({ids}) AND subject_type LIKE '%OrderItem')",
                         f"DELETE FROM order_initial_report_items WHERE order_id={order_id}",
                         f"DELETE FROM stock_request_items WHERE stock_request_id IN (SELECT id FROM stock_requests WHERE order_id={order_id})",
                         f"DELETE FROM stock_requests WHERE order_id={order_id}", f"DELETE FROM commissions WHERE order_id={order_id}",
                         f"DELETE FROM payment_transactions WHERE order_id={order_id}",
                         f"DELETE FROM stock_movements WHERE reference_type='order' AND reference_id={order_id} AND type='reserve'",
                         f"DELETE FROM shipments WHERE order_id={order_id}", f"DELETE FROM order_items WHERE order_id={order_id}",
                         f"DELETE FROM orders WHERE id={order_id}"]:
                db(stmt)
        db(f"UPDATE product_stocks SET quantity_reserved={reserved} WHERE agent_id=10 AND product_id=3")
        db(f"UPDATE settings SET `value`='{old_seq}' WHERE `key`='orders.invoice_sequence'" if seq_exists else "DELETE FROM settings WHERE `key`='orders.invoice_sequence'")
    if len(runtime) == 2 and runtime[0] != runtime[1]:
        failures.append("Laravel and Go allocation responses, validation, replay/conflict, or database/audit effects differ: " + json.dumps(runtime, sort_keys=True, default=str))
    if failures:
        print(f"return receipt allocation differential: {max(0, 13-len(failures))} passed, {len(failures)} failed")
        for failure in failures: print("FAIL " + failure)
        return 1
    print("return receipt allocation differential: 13 passed, 0 failed")
    return 0


if __name__ == "__main__": raise SystemExit(main())
