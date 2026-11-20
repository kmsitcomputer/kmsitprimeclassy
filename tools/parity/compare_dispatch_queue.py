#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for the dispatchable shipment queue."""
import importlib.util, json, pathlib, uuid
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo

ROOT = pathlib.Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("dispatch_returns_parity", ROOT / "tools/parity/compare_dispatch_returns.py")
queue_parity = importlib.util.module_from_spec(spec)
spec.loader.exec_module(queue_parity)
review, pickup = queue_parity.review, queue_parity.pickup


def canonical(value):
    if isinstance(value, dict):
        return {k: ("<id>" if k in {"id", "shipment_id", "order_id", "courier_id"} else
                    "<order-no>" if k == "order_no" and v else
                    "<timestamp>" if k == "created_at" and v else canonical(v)) for k, v in value.items()}
    if isinstance(value, (tuple, list)):
        return [canonical(v) for v in value]
    return value


def main():
    if review.db("SELECT DATABASE()")[0][0] != "primeclassy_testing":
        raise RuntimeError("refusing non-testing database")
    token = uuid.uuid4().hex[:10]
    reserved = int(review.db("SELECT quantity_reserved FROM product_stocks WHERE agent_id=10 AND product_id=3")[0][0])
    seq = review.db("SELECT `value` FROM settings WHERE `key`='orders.invoice_sequence'")
    sequence_exists, old_sequence = bool(seq), seq[0][0] if seq else "0"
    tomorrow = datetime.now(ZoneInfo("Asia/Jakarta")).date() + timedelta(days=1)
    delivery_date = tomorrow.isoformat()
    fixed_utc = datetime.combine(tomorrow, datetime.min.time(), ZoneInfo("Asia/Jakarta")).astimezone(ZoneInfo("UTC")).strftime("%Y-%m-%d %H:%M:%S")
    outputs = []
    try:
        for base in (review.LARAVEL, review.GO):
            review.order_ids, review.return_file_paths = [], []
            consumer, order_id, _, _, item_id = review.create_case(base, token, base[-4:])
            agent_id = int(review.db(f"SELECT agent_id FROM orders WHERE id={order_id}")[0][0])
            shipment_id = int(review.db(f"SELECT id FROM shipments WHERE order_id={order_id}")[0][0])
            province_id = review.db(f"SELECT province_id FROM orders WHERE id={order_id}")[0][0]
            review.db(f"UPDATE orders SET status='diproses' WHERE id={order_id}")
            review.db(f"UPDATE order_items SET status='diproses',requested_delivery_date='{delivery_date}' WHERE id={item_id}")
            review.db(f"UPDATE shipments SET status='pending',delivery_mode='standard',courier_id=NULL,self_delivered_by_user_id=NULL,shipping_provider_code=NULL,created_at='{fixed_utc}' WHERE id={shipment_id}")
            stock_request_id = int(review.db("SELECT COALESCE(MAX(id),0)+1 FROM stock_requests")[0][0])
            review.db(f"INSERT INTO stock_requests (id,agent_id,order_id,request_number,status,created_by,coordinator_requested_at,created_at,updated_at) VALUES ({stock_request_id},{agent_id},{order_id},'PARITY-DQ-{token}','partial',10,'{fixed_utc}','{fixed_utc}','{fixed_utc}')")
            product_id = int(review.db(f"SELECT product_id FROM order_items WHERE id={item_id}")[0][0])
            review.db(f"INSERT INTO stock_request_items (stock_request_id,order_item_id,product_id,sku_snapshot,requested_qty,fulfilled_qty,remaining_qty,created_at,updated_at) VALUES ({stock_request_id},{item_id},{product_id},'SKU-SUSU',4,2,2,'{fixed_utc}','{fixed_utc}')")
            date_query = f"delivery_date={delivery_date}&province_id={province_id}"
            listed = consumer.call("GET", "/api/v1/dispatch?" + date_query)
            unpaid = consumer.call("GET", "/api/v1/dispatch?" + date_query + "&paid=unpaid&per_page=1")
            review.db(f"UPDATE orders SET paid_amount=total_amount,gross_received_amount=total_amount,remaining_amount=0,payment_status='paid' WHERE id={order_id}")
            paid = consumer.call("GET", "/api/v1/dispatch?" + date_query + "&paid=paid")
            paid_listed = consumer.call("GET", "/api/v1/dispatch?" + date_query)
            invalid_date = consumer.call("GET", "/api/v1/dispatch?delivery_date=not-a-date")
            page = consumer.call("GET", "/api/v1/dispatch?" + date_query + "&per_page=1&page=1")
            admin = review.Client(base)
            admin.login("admin@imp010.test")
            admin_scope = admin.call("GET", "/api/v1/dispatch?" + date_query)
            super_admin = review.Client(base)
            super_admin.login("super@imp010.test")
            missing_agent = super_admin.call("GET", "/api/v1/dispatch?" + date_query)
            bad_agent = super_admin.call("GET", "/api/v1/dispatch?agent_id=99999999")
            scoped_super = super_admin.call("GET", f"/api/v1/dispatch?agent_id={agent_id}&{date_query}")
            consumer_denied = review.Client(base)
            consumer_denied.login("konsumen@imp010.test")
            denied = consumer_denied.call("GET", "/api/v1/dispatch")
            outputs.append({"listed": listed, "unpaid": unpaid, "paid": paid, "paid_listed": paid_listed, "invalid_date": invalid_date,
                            "page": page, "admin_scope": admin_scope, "missing_agent": missing_agent,
                            "bad_agent": bad_agent, "super_scope": scoped_super, "denied": denied})
            pickup.cleanup(review.order_ids[:], reserved, sequence_exists, old_sequence)
    finally:
        if getattr(review, "order_ids", []):
            pickup.cleanup(review.order_ids[:], reserved, sequence_exists, old_sequence)
    outputs = [canonical(v) for v in outputs]
    if outputs[0] != outputs[1]:
        print("dispatch queue differential: FAIL")
        print(json.dumps(outputs, ensure_ascii=False, sort_keys=True, default=str))
        return 1
    print("dispatch queue differential: 10 passed, 0 failed")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
