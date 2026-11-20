#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for admin fulfillment-shortfall refunds."""
import importlib.util, json, pathlib, uuid

ROOT = pathlib.Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("return_review_parity", ROOT / "tools/parity/compare_return_review.py")
r = importlib.util.module_from_spec(spec)
spec.loader.exec_module(r)
pickup_spec = importlib.util.spec_from_file_location("return_pickup_parity", ROOT / "tools/parity/compare_return_pickup_schedule.py")
pickup = importlib.util.module_from_spec(pickup_spec)
pickup_spec.loader.exec_module(pickup)


def canonical(value):
    if isinstance(value, dict):
        return {key: ("<id>" if key in {"id", "order_id"} else
                      "<order-no>" if key == "order_no" and child else
                      "<timestamp>" if key == "created_at" and child else canonical(child))
                for key, child in value.items()}
    if isinstance(value, (tuple, list)):
        return [canonical(child) for child in value]
    return value


def main():
    if r.db("SELECT DATABASE()")[0][0] != "primeclassy_testing":
        raise RuntimeError("refusing non-testing database")
    token = uuid.uuid4().hex[:12]
    reserved = int(r.db("SELECT quantity_reserved FROM product_stocks WHERE agent_id=10 AND product_id=3")[0][0])
    seq = r.db("SELECT `value` FROM settings WHERE `key`='orders.invoice_sequence'")
    sequence_exists, old_sequence = bool(seq), seq[0][0] if seq else "0"
    outputs = []
    try:
        for base in (r.LARAVEL, r.GO):
            r.order_ids, r.return_file_paths = [], []
            admin, order_id, _, _, item_id = r.create_case(base, token, base[-4:])
            agent_id = int(r.db(f"SELECT agent_id FROM orders WHERE id={order_id}")[0][0])
            adjustment_id = int(r.db("SELECT COALESCE(MAX(id),0)+1 FROM order_item_adjustments")[0][0])
            now = r.db("SELECT UTC_TIMESTAMP()")[0][0]
            r.db(f"INSERT INTO order_item_adjustments (id,order_item_id,adjusted_by,quantity_reduced,reason,refund_amount,refund_status,created_at,updated_at) VALUES ({adjustment_id},{item_id},11,1,'Synthetic stock shortfall parity',12500.00,'pending','{now}','{now}')")
            rows = admin.call("GET", "/api/v1/admin/order-refunds?per_page=100")
            first_page = admin.call("GET", "/api/v1/admin/order-refunds?per_page=1&page=1")
            finance = r.Client(base)
            finance.login("keuangan@imp010.test")
            finance_rows = finance.call("GET", "/api/v1/admin/order-refunds?per_page=100")
            super_admin = r.Client(base)
            super_admin.login("super@imp010.test")
            super_all = super_admin.call("GET", "/api/v1/admin/order-refunds?per_page=100")
            super_agent = super_admin.call("GET", f"/api/v1/admin/order-refunds?agent_id={agent_id}&per_page=100")
            super_invalid = super_admin.call("GET", "/api/v1/admin/order-refunds?agent_id=99999999")
            consumer = r.Client(base)
            consumer.login("konsumen@imp010.test")
            denied = consumer.call("GET", "/api/v1/admin/order-refunds")
            outputs.append({"rows": rows, "first_page": first_page, "finance_rows": finance_rows,
                            "super_all": super_all, "super_agent": super_agent,
                            "super_invalid": super_invalid, "consumer_denied": denied})
            pickup.cleanup(r.order_ids[:], reserved, sequence_exists, old_sequence)
            r.order_ids.clear()
            r.return_file_paths.clear()
    finally:
        if getattr(r, "order_ids", []):
            pickup.cleanup(r.order_ids[:], reserved, sequence_exists, old_sequence)
    outputs = [canonical(value) for value in outputs]
    if outputs[0] != outputs[1]:
        print("admin order refunds differential: FAIL")
        print(json.dumps(outputs, ensure_ascii=False, sort_keys=True, default=str))
        return 1
    print("admin order refunds differential: 7 passed, 0 failed")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
