#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for reinstating an order sales reduction."""
import importlib.util, json, pathlib, uuid

ROOT = pathlib.Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("return_review_parity", ROOT / "tools/parity/compare_return_review.py")
r = importlib.util.module_from_spec(spec)
spec.loader.exec_module(r)
pickup_spec = importlib.util.spec_from_file_location("return_pickup_parity", ROOT / "tools/parity/compare_return_pickup_schedule.py")
pickup = importlib.util.module_from_spec(pickup_spec)
pickup_spec.loader.exec_module(pickup)


def canon(value):
    if isinstance(value, dict):
        return {key: ("<id>" if key in {"id", "order_id", "source_id", "reinstated_by"} else
                      "reinstatement:<id>" if key == "event_key" and isinstance(child, str) and child.startswith("reinstatement:") else
                      "<timestamp>" if key.endswith("_at") and child else canon(child))
                for key, child in value.items()}
    if isinstance(value, (tuple, list)):
        return [canon(child) for child in value]
    return value


def main():
    if r.db("SELECT DATABASE()")[0][0] != "primeclassy_testing":
        raise RuntimeError("refusing non-testing database")
    token = uuid.uuid4().hex[:12]
    reserved = int(r.db("SELECT quantity_reserved FROM product_stocks WHERE agent_id=10 AND product_id=3")[0][0])
    seq = r.db("SELECT `value` FROM settings WHERE `key`='orders.invoice_sequence'")
    sequence_exists, old_sequence = bool(seq), seq[0][0] if seq else "0"
    results = []
    try:
        for base in (r.LARAVEL, r.GO):
            r.order_ids, r.return_file_paths = [], []
            _, order_id, _, _, _ = r.create_case(base, token, base[-4:])
            reduction_id = int(r.db("SELECT COALESCE(MAX(id),0)+1 FROM order_sales_reductions")[0][0])
            blocked_reduction_id = reduction_id + 1
            empty_reason_id = reduction_id + 2
            now = r.db("SELECT UTC_TIMESTAMP(6)")[0][0]
            r.db(f"INSERT INTO order_sales_reductions (id,order_id,source_type,source_id,amount,applied_to_order_total,provenance,created_at,updated_at) VALUES ({reduction_id},{order_id},'cancellation',NULL,100.00,0,'forward','{now}','{now}')")
            r.db(f"INSERT INTO order_sales_reductions (id,order_id,source_type,source_id,amount,applied_to_order_total,provenance,created_at,updated_at) VALUES ({blocked_reduction_id},{order_id},'cancellation',NULL,50.00,0,'forward','{now}','{now}')")
            r.db(f"INSERT INTO order_sales_reductions (id,order_id,source_type,source_id,amount,applied_to_order_total,provenance,created_at,updated_at) VALUES ({empty_reason_id},{order_id},'cancellation',NULL,5.00,0,'forward','{now}','{now}')")
            obligation_id = int(r.db("SELECT COALESCE(MAX(id),0)+1 FROM refund_obligations")[0][0])
            attempt_id = int(r.db("SELECT COALESCE(MAX(id),0)+1 FROM refund_attempts")[0][0])
            suffix = base[-4:]
            admin = r.Client(base)
            if admin.login("super@imp010.test") != 200:
                raise RuntimeError("super admin login failed")
            path = f"/api/v1/admin/order-sales-reductions/{reduction_id}/reinstate"
            success = admin.call("POST", path, json.dumps({"evidence": "Parity reconciliation evidence", "reason": "Scoped parity"}).encode(), {"Content-Type": "application/json"})
            replay = admin.call("POST", path, json.dumps({"evidence": "Replay must preserve original", "reason": "Changed replay"}).encode(), {"Content-Type": "application/json"})
            invalid = admin.call("POST", path, json.dumps({"evidence": "x"}).encode(), {"Content-Type": "application/json"})
            empty_reason = admin.call("POST", f"/api/v1/admin/order-sales-reductions/{empty_reason_id}/reinstate", json.dumps({"evidence": "Empty optional reason", "reason": "   "}).encode(), {"Content-Type": "application/json"})
            missing_id = int(r.db("SELECT COALESCE(MAX(id),0)+100000 FROM order_sales_reductions")[0][0])
            missing = admin.call("POST", f"/api/v1/admin/order-sales-reductions/{missing_id}/reinstate", json.dumps({"evidence": "Missing reduction"}).encode(), {"Content-Type": "application/json"})
            r.db(f"INSERT INTO refund_obligations (id,obligation_no,order_id,source_type,obligation_key,amount,status,provenance,created_at,updated_at) VALUES ({obligation_id},'PARITY-{token}-{suffix}',{order_id},'manual','parity-reinstate-{token}-{suffix}',10.00,'approved','forward','{now}','{now}')")
            r.db(f"INSERT INTO refund_attempts (id,obligation_id,attempt_no,method,amount,status,idempotency_key,created_at,updated_at) VALUES ({attempt_id},{obligation_id},1,'bank_transfer',10.00,'processing','parity-reinstate-{token}-{suffix}','{now}','{now}')")
            blocked_path = f"/api/v1/admin/order-sales-reductions/{blocked_reduction_id}/reinstate"
            blocked = admin.call("POST", blocked_path, json.dumps({"evidence": "In-flight attempt guard"}).encode(), {"Content-Type": "application/json"})
            r.db(f"UPDATE refund_attempts SET status='failed' WHERE id={attempt_id}")
            r.db(f"UPDATE refund_obligations SET amount=60.00 WHERE id={obligation_id}")
            r.db(f"UPDATE orders SET total_amount=100.00,paid_amount=60.00,remaining_amount=40.00,gross_received_amount=60.00,payment_status='partially_paid' WHERE id={order_id}")
            financial_bound = admin.call("POST", blocked_path, json.dumps({"evidence": "Reserved refund exceeds available balance"}).encode(), {"Content-Type": "application/json"})
            consumer = r.Client(base)
            consumer.login("konsumen@imp010.test")
            denied = consumer.call("POST", path, json.dumps({"evidence": "Consumer must be denied"}).encode(), {"Content-Type": "application/json"})
            finance = r.Client(base)
            finance.login("keuangan@imp010.test")
            finance_denied = finance.call("POST", path, json.dumps({"evidence": "Finance is not owner"}).encode(), {"Content-Type": "application/json"})
            state = r.db(f"SELECT reinstated_at IS NOT NULL,reinstated_by,reinstatement_reason,reinstatement_reconciliation_ref FROM order_sales_reductions WHERE id={reduction_id}")[0]
            audit = r.db(f"SELECT event_type,event_key,actor_type,reason,CAST(payload AS CHAR) FROM refund_ledger_events WHERE order_id={order_id} AND event_type='reinstatement.applied'")
            results.append({"success": success, "replay": replay, "empty_reason": empty_reason, "invalid": invalid, "missing": missing, "blocked": blocked, "financial_bound": financial_bound, "denied": denied, "finance_denied": finance_denied,
                            "state": state, "audit": audit})
            r.db(f"DELETE FROM refund_ledger_events WHERE order_id={order_id} AND event_type='reinstatement.applied'")
            r.db(f"DELETE FROM refund_attempts WHERE id={attempt_id}")
            r.db(f"DELETE FROM refund_obligations WHERE id={obligation_id}")
            r.db(f"DELETE FROM order_sales_reductions WHERE id IN ({reduction_id},{blocked_reduction_id},{empty_reason_id})")
            pickup.cleanup(r.order_ids[:], reserved, sequence_exists, old_sequence)
            r.order_ids.clear(); r.return_file_paths.clear()
    finally:
        if getattr(r, "order_ids", []):
            pickup.cleanup(r.order_ids[:], reserved, sequence_exists, old_sequence)
    results = [canon(item) for item in results]
    if results[0] != results[1]:
        print("admin reduction reinstatement differential: FAIL")
        print(json.dumps(results, ensure_ascii=False, sort_keys=True, default=str))
        return 1
    print("admin reduction reinstatement differential: 11 comparisons passed, 0 failed")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
