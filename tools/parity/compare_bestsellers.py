#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for public bestseller ranking and agent resolution."""
import importlib.util, json, pathlib, uuid

ROOT = pathlib.Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("return_review_parity", ROOT / "tools/parity/compare_return_review.py")
r = importlib.util.module_from_spec(spec); spec.loader.exec_module(r)
pickup_spec = importlib.util.spec_from_file_location("return_pickup_parity", ROOT / "tools/parity/compare_return_pickup_schedule.py")
pickup = importlib.util.module_from_spec(pickup_spec); pickup_spec.loader.exec_module(pickup)


def clean(value):
    if isinstance(value, dict):
        return {k: clean(v) for k, v in value.items()}
    if isinstance(value, list):
        return [clean(v) for v in value]
    return value


def main():
    if r.db("SELECT DATABASE()")[0][0] != "primeclassy_testing":
        raise RuntimeError("refusing non-testing database")
    token = uuid.uuid4().hex[:10]
    reserved = int(r.db("SELECT quantity_reserved FROM product_stocks WHERE agent_id=10 AND product_id=3")[0][0])
    seq = r.db("SELECT `value` FROM settings WHERE `key`='orders.invoice_sequence'")
    seq_exists, old_seq = bool(seq), seq[0][0] if seq else "0"
    order_ids, paths, outputs = [], [], []
    r.order_ids, r.return_file_paths = order_ids, paths
    try:
        for base in (r.LARAVEL, r.GO):
            _, order_id, _, return_item_id, order_item_id = r.create_case(base, token, base[-4:])
            r.db(f"UPDATE orders SET payment_status='paid',paid_amount=total_amount,gross_received_amount=total_amount,remaining_amount=0 WHERE id={order_id}")
            r.db(f"UPDATE return_items SET status='approved',quantity_received=1,good_quantity=1,inspected_at=NOW(),inspected_by=12 WHERE id={return_item_id}")
            r.db(f"INSERT INTO order_item_receipts(order_item_id,order_id,agent_id,quantity,received_on,verified_by,verified_at,idempotency_key,request_fingerprint,created_at,updated_at) VALUES ({order_item_id},{order_id},10,3,CURRENT_DATE,12,NOW(),'codex-bestseller-{token}-{base[-4:]}',REPEAT('a',64),NOW(),NOW())")
            receipt = int(r.db(f"SELECT id FROM order_item_receipts WHERE order_item_id={order_item_id}")[0][0])
            agent = r.Client(base)
            if agent.login("super@imp010.test") != 200:
                raise RuntimeError(f"super login failed at {base}")
            ranked = agent.call("GET", "/api/v1/bestsellers?period=7&limit=4&agent_id=10")
            no_scope = agent.call("GET", "/api/v1/bestsellers?period=7&limit=4")
            invalid_agent = agent.call("GET", "/api/v1/bestsellers?agent_id=999999")
            invalid_period = agent.call("GET", "/api/v1/bestsellers?period=8&agent_id=10")
            guest = r.Client(base)
            guest_ranked = guest.call("GET", "/api/v1/bestsellers?period=7&limit=4&ref=AG-IMP010")
            guest_hidden = guest.call("GET", "/api/v1/bestsellers?ref=not-a-real-ref")
            r.db(f"DELETE FROM order_item_receipts WHERE order_item_id={order_item_id}")
            for n, qty in enumerate((2, 3)):
                r.db(f"INSERT INTO order_item_receipts(order_item_id,order_id,agent_id,quantity,received_on,verified_by,verified_at,idempotency_key,request_fingerprint,created_at,updated_at) VALUES ({order_item_id},{order_id},10,{qty},CURRENT_DATE,12,NOW(),'codex-bestseller-multi-{token}-{base[-4:]}-{n}',REPEAT('b',64),NOW(),NOW())")
            unresolved = agent.call("GET", "/api/v1/bestsellers?period=7&limit=4&agent_id=10")
            receipt_ids = [int(row[0]) for row in r.db(f"SELECT id FROM order_item_receipts WHERE order_item_id={order_item_id} ORDER BY id")]
            r.db(f"INSERT INTO return_item_receipt_allocations(return_item_id,order_item_receipt_id,quantity,allocated_by,allocated_at,created_at,updated_at) VALUES ({return_item_id},{receipt_ids[0]},1,12,NOW(),NOW(),NOW())")
            resolved = agent.call("GET", "/api/v1/bestsellers?period=7&limit=4&agent_id=10")
            state = r.db(f"SELECT agent_id,quantity,CAST(received_on AS CHAR) FROM order_item_receipts WHERE order_item_id={order_item_id} ORDER BY id")
            outputs.append({"ranked": ranked, "no_scope": no_scope, "invalid_agent": invalid_agent,
                            "invalid_period": invalid_period, "guest_ranked": guest_ranked,
                            "guest_hidden": guest_hidden, "unresolved": unresolved, "resolved": resolved,
                            "receipt_state": [["<item>", *row] for row in state],
                            "return_state": r.db(f"SELECT status,quantity_received,(inspected_at IS NOT NULL) FROM return_items WHERE id={return_item_id}")})
            pickup.cleanup(order_ids[:], reserved, seq_exists, old_seq)
            order_ids.clear(); paths.clear()
    finally:
        if order_ids:
            pickup.cleanup(order_ids[:], reserved, seq_exists, old_seq)
    if outputs[0] != outputs[1]:
        print("bestsellers differential: FAIL")
        print(json.dumps(outputs, ensure_ascii=False, sort_keys=True, default=str))
        return 1
    body = outputs[0]["ranked"][1]
    if outputs[0]["ranked"][0] != 200 or not isinstance(body, dict) or body.get("meta", {}).get("hidden") is not False:
        raise AssertionError("expected a visible ranked product: " + repr(outputs[0]["ranked"]))
    ids = [int(p["id"]) for p in body.get("data", [])]
    if 3 not in ids:
        raise AssertionError("fixture product 3 absent from bestseller ranking: " + repr(ids))
    print("bestsellers differential: 9 comparisons passed, 0 failed")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
