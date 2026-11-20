#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for delivered dispatch history."""
import importlib.util, json, pathlib, uuid
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo

ROOT = pathlib.Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("dispatch_returns_parity", ROOT / "tools/parity/compare_dispatch_returns.py")
queue = importlib.util.module_from_spec(spec)
spec.loader.exec_module(queue)
review, pickup = queue.review, queue.pickup


def norm(value):
    if isinstance(value, dict):
        return {key: ("<id>" if key in {"id", "shipment_id", "order_id", "courier_id"}
                      else "<proof-url>" if key == "proof_url" and child else norm(child))
                for key, child in value.items()}
    if isinstance(value, list):
        return [norm(child) for child in value]
    return value


def canonical_value(value):
    if isinstance(value, dict):
        return {k: ("<id>" if k in ("id", "shipment_id", "order_id", "courier_id") else
                    "<proof-url>" if k == "proof_url" and v else canonical_value(v)) for k, v in value.items()}
    if isinstance(value, (list, tuple)):
        return [canonical_value(v) for v in value]
    return value


def main():
    if review.db("SELECT DATABASE()")[0][0] != "primeclassy_testing":
        raise RuntimeError("refusing non-testing database")
    token = uuid.uuid4().hex[:10]
    reserved = int(review.db("SELECT quantity_reserved FROM product_stocks WHERE agent_id=10 AND product_id=3")[0][0])
    seq = review.db("SELECT `value` FROM settings WHERE `key`='orders.invoice_sequence'")
    sequence_exists, old_sequence = bool(seq), seq[0][0] if seq else "0"
    outputs = []
    jakarta_now = datetime.now(ZoneInfo("Asia/Jakarta"))
    date = jakarta_now.date().isoformat()
    delivered_at = jakarta_now.replace(hour=1, minute=0, second=0, microsecond=0)
    prior_day = delivered_at - timedelta(days=1)
    delivered_utc = delivered_at.astimezone(ZoneInfo("UTC")).strftime("%Y-%m-%d %H:%M:%S")
    prior_utc = prior_day.astimezone(ZoneInfo("UTC")).strftime("%Y-%m-%d %H:%M:%S")
    try:
        for base in (review.LARAVEL, review.GO):
            review.order_ids, review.return_file_paths = [], []
            admin, first_order, _, _, _ = review.create_case(base, token, base[-4:] + "a")
            _, second_order, _, _, _ = review.create_case(base, token, base[-4:] + "b")
            courier = int(review.db("SELECT id FROM couriers WHERE agent_id=10 ORDER BY id LIMIT 1")[0][0])
            sks = int(review.db("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.agent_id=10 AND r.slug='sales-kurir-sub' AND u.status='active' ORDER BY u.id LIMIT 1")[0][0])
            media_id = int(review.db("SELECT COALESCE(MAX(id),0)+1 FROM media")[0][0])
            first_shipment = int(review.db(f"SELECT id FROM shipments WHERE order_id={first_order}")[0][0])
            second_shipment = int(review.db(f"SELECT id FROM shipments WHERE order_id={second_order}")[0][0])
            # Synthetic operational rows only; the shared test DB is restored before switching runtime.
            review.db(f"UPDATE shipments SET status='delivered',delivered_at='{delivered_utc}',delivery_mode='standard',courier_id={courier},self_delivered_by_user_id=NULL WHERE id={first_shipment}")
            review.db(f"UPDATE shipments SET status='delivered',delivered_at='{prior_utc}',delivery_mode='self_sub',courier_id=NULL,self_delivered_by_user_id={sks} WHERE id={second_shipment}")
            review.db(f"INSERT INTO media (id,disk,path,collection,original_filename,mime_type,extension,size,created_by,created_at,updated_at) VALUES ({media_id},'public','parity/dispatch-history-{token}.jpg','dispatch-proof','proof.jpg','image/jpeg','jpg',1,10,NOW(),NOW())")
            review.db(f"UPDATE shipments SET proof_media_id={media_id} WHERE id={first_shipment}")
            order_no = review.db(f"SELECT order_no FROM orders WHERE id={first_order}")[0][0]
            path = f"/api/v1/dispatch/history?search={order_no}&per_page=100"
            listed = admin.call("GET", path)
            date_filter = admin.call("GET", path + f"&delivered_from={date}&delivered_to={date}&courier_id={courier}")
            self_delivery = admin.call("GET", f"/api/v1/dispatch/history?search={review.db(f'SELECT order_no FROM orders WHERE id={second_order}')[0][0]}")
            invalid_date = admin.call("GET", "/api/v1/dispatch/history?delivered_from=2026-02-31")
            invalid_per_page = admin.call("GET", "/api/v1/dispatch/history?per_page=0")
            wildcard = admin.call("GET", "/api/v1/dispatch/history?search=%25%25")
            page = admin.call("GET", "/api/v1/dispatch/history?per_page=1&page=1")
            super_admin = review.Client(base)
            super_admin.login("super@imp010.test")
            missing_agent = super_admin.call("GET", "/api/v1/dispatch/history")
            bad_agent = super_admin.call("GET", "/api/v1/dispatch/history?agent_id=99999999")
            consumer = review.Client(base)
            consumer.login("konsumen@imp010.test")
            denied = consumer.call("GET", "/api/v1/dispatch/history")
            outputs.append({"listed": listed, "date_filter": date_filter, "self_delivery": self_delivery,
                            "invalid_date": invalid_date, "invalid_per_page": invalid_per_page,
                            "wildcard": wildcard, "page": page, "missing_agent": missing_agent,
                            "bad_agent": bad_agent, "denied": denied})
            review.db(f"UPDATE shipments SET proof_media_id=NULL WHERE proof_media_id={media_id}")
            review.db(f"DELETE FROM media WHERE id={media_id}")
            pickup.cleanup(review.order_ids[:], reserved, sequence_exists, old_sequence)
    finally:
        # Cleanup is idempotent and covers partial fixture creation on any runtime failure.
        if getattr(review, "order_ids", []):
            pickup.cleanup(review.order_ids[:], reserved, sequence_exists, old_sequence)
    outputs = [canonical_value(value) for value in outputs]
    if outputs[0] != outputs[1]:
        print("dispatch history differential: FAIL")
        print(json.dumps(outputs, ensure_ascii=False, sort_keys=True, default=str))
        return 1
    print("dispatch history differential: 10 passed, 0 failed")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
