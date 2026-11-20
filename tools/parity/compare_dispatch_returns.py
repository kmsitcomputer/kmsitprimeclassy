#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for the branch-scoped dispatch return queue."""
import importlib.util, json, pathlib, uuid

ROOT = pathlib.Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("return_pickup_parity", ROOT / "tools/parity/compare_return_pickup_schedule.py")
pickup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pickup)
review = pickup.review


def norm(value):
    if isinstance(value, dict):
        return {key: ("<id>" if key in {"id", "order_id", "order_no"} else norm(child)) for key, child in value.items()}
    if isinstance(value, (tuple, list)):
        return [norm(child) for child in value]
    return value


def main():
    if review.db("SELECT DATABASE()")[0][0] != "primeclassy_testing":
        raise RuntimeError("refusing non-testing database")
    token = uuid.uuid4().hex[:10]
    order_ids, paths, outputs = [], [], []
    reserved = int(review.db("SELECT quantity_reserved FROM product_stocks WHERE agent_id=10 AND product_id=3")[0][0])
    seq = review.db("SELECT `value` FROM settings WHERE `key`='orders.invoice_sequence'")
    sequence_exists, old_sequence = bool(seq), seq[0][0] if seq else "0"
    review.order_ids, review.return_file_paths = order_ids, paths
    try:
        for base in (review.LARAVEL, review.GO):
            admin, order_id, _, _, _ = review.create_case(base, token, base[-4:])
            agent_id = int(review.db(f"SELECT agent_id FROM orders WHERE id={order_id}")[0][0])
            path = "/api/v1/dispatch/returns"
            queue = admin.call("GET", path + "?per_page=100")
            first_page = admin.call("GET", path + "?per_page=1&page=1")
            super_admin = review.Client(base)
            if super_admin.login("super@imp010.test") != 200:
                # Some fixtures use the admin account name for the super administrator.
                if super_admin.login("super@imp010.test") != 200:
                    raise RuntimeError("super admin login failed")
            missing_agent = super_admin.call("GET", path)
            invalid_agent = super_admin.call("GET", path + "?agent_id=99999999")
            scoped = super_admin.call("GET", path + f"?agent_id={agent_id}&per_page=100")
            consumer = review.Client(base)
            consumer.login("konsumen@imp010.test")
            denied = consumer.call("GET", path)
            outputs.append({"queue": queue, "first_page": first_page, "missing_agent": missing_agent,
                            "invalid_agent": invalid_agent, "super_admin_scope": scoped, "consumer_denied": denied})
            # The two runtimes share primeclassy_testing; remove one runtime's fixture before creating the next.
            pickup.cleanup(order_ids[:], reserved, sequence_exists, old_sequence)
            order_ids.clear()
            paths.clear()
    finally:
        if order_ids:
            pickup.cleanup(order_ids, reserved, sequence_exists, old_sequence)
    outputs = [norm(output) for output in outputs]
    if outputs[0] != outputs[1]:
        print("dispatch returns differential: FAIL")
        print(json.dumps(outputs, ensure_ascii=False, sort_keys=True, default=str))
        return 1
    print("dispatch returns differential: 6 passed, 0 failed")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
