#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for cancellation financial finalization."""
import importlib.util, json, pathlib, uuid

ROOT = pathlib.Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("return_review_parity", ROOT / "tools/parity/compare_return_review.py")
r = importlib.util.module_from_spec(spec); spec.loader.exec_module(r)
pickup_spec = importlib.util.spec_from_file_location("return_pickup_parity", ROOT / "tools/parity/compare_return_pickup_schedule.py")
pickup = importlib.util.module_from_spec(pickup_spec); pickup_spec.loader.exec_module(pickup)


def canon(value):
    if isinstance(value, dict):
        return {key: ("<id>" if key in {"id", "order_id", "konsumen_id", "agent_id", "sales_id", "korsal_id", "created_by"} else
                      "<order-no>" if key == "order_no" else
                      "<request-key>" if key == "idempotency_key" else
                      "<timestamp>" if key.endswith(("_at", "_date")) and child else canon(child))
                for key, child in value.items()}
    if isinstance(value, (list, tuple)):
        return [canon(child) for child in value]
    if isinstance(value, str) and value.startswith("{"):
        try:
            return json.dumps(json.loads(value), sort_keys=True, separators=(",", ":"))
        except json.JSONDecodeError:
            pass
    return value


def insert_reduction(order_id, amount):
    reduction_id = int(r.db("SELECT COALESCE(MAX(id),0)+1 FROM order_sales_reductions")[0][0])
    now = r.db("SELECT UTC_TIMESTAMP(6)")[0][0]
    r.db(f"INSERT INTO order_sales_reductions (id,order_id,source_type,source_id,amount,applied_to_order_total,provenance,created_at,updated_at) VALUES ({reduction_id},{order_id},'cancellation',{order_id},{amount},0,'forward','{now}','{now}')")
    return reduction_id


def main():
    if r.db("SELECT DATABASE()")[0][0] != "primeclassy_testing":
        raise RuntimeError("refusing non-testing database")
    token = uuid.uuid4().hex[:12]
    reserved = int(r.db("SELECT quantity_reserved FROM product_stocks WHERE agent_id=10 AND product_id=3")[0][0])
    seq = r.db("SELECT `value` FROM settings WHERE `key`='orders.invoice_sequence'")
    sequence_exists, old_sequence = bool(seq), seq[0][0] if seq else "0"
    outputs = []
    custom_attempts, custom_obligations, custom_reductions, custom_orders = [], [], [], []
    try:
        for base in (r.LARAVEL, r.GO):
            r.order_ids, r.return_file_paths = [], []
            _, paid_order, _, _, _ = r.create_case(base, token, "paid-" + base[-4:])
            _, pending_order, _, _, _ = r.create_case(base, token, "pending-" + base[-4:])
            admin = r.Client(base)
            if admin.login("keuangan@imp010.test") != 200:
                raise RuntimeError("finance login failed")
            custom_orders.extend([paid_order, pending_order])
            reduction_id = insert_reduction(paid_order, "100.00"); custom_reductions.append(reduction_id)
            r.db(f"UPDATE orders SET total_amount=100.00,paid_amount=100.00,gross_received_amount=100.00,remaining_amount=0.00,payment_status='paid',cancellation_financial_status='pending_reconciliation' WHERE id={paid_order}")
            obligation_id = int(r.db("SELECT COALESCE(MAX(id),0)+1 FROM refund_obligations")[0][0])
            attempt_id = int(r.db("SELECT COALESCE(MAX(id),0)+1 FROM refund_attempts")[0][0])
            now = r.db("SELECT UTC_TIMESTAMP(6)")[0][0]
            suffix = base[-4:]
            r.db(f"INSERT INTO refund_obligations (id,obligation_no,order_id,source_type,obligation_key,amount,status,provenance,created_at,updated_at) VALUES ({obligation_id},'PARITY-CF-{token}-{suffix}',{pending_order},'manual','parity-cf-{token}-{suffix}',20.50,'approved','forward','{now}','{now}')")
            r.db(f"INSERT INTO refund_attempts (id,obligation_id,attempt_no,method,amount,status,idempotency_key,created_at,updated_at) VALUES ({attempt_id},{obligation_id},1,'bank_transfer',20.50,'processing','parity-cf-{token}-{suffix}','{now}','{now}')")
            custom_obligations.append(obligation_id); custom_attempts.append(attempt_id)
            r.db(f"UPDATE orders SET total_amount=100.00,paid_amount=100.00,gross_received_amount=100.00,remaining_amount=0.00,payment_status='paid',cancellation_financial_status='pending_reconciliation' WHERE id={pending_order}")
            base_path = f"/api/v1/admin/orders/{{}}/cancellation/finalize"
            first = admin.call("POST", base_path.format(paid_order))
            replay = admin.call("POST", base_path.format(paid_order))
            deferred = admin.call("POST", base_path.format(pending_order))
            pending_status = r.db(f"SELECT cancellation_financial_status FROM orders WHERE id={pending_order}")[0][0]
            r.db(f"UPDATE refund_attempts SET status='failed' WHERE id={attempt_id}")
            finalized = admin.call("POST", base_path.format(pending_order))
            orders = r.db(f"SELECT cancellation_financial_status,cancellation_finalized_at IS NOT NULL FROM orders WHERE id IN ({paid_order},{pending_order}) ORDER BY id")
            obligations = r.db(f"SELECT source_type,CAST(amount AS CHAR),status,approval_source,reason FROM refund_obligations WHERE order_id IN ({paid_order},{pending_order}) ORDER BY order_id,id")
            events = r.db(f"SELECT event_type,actor_type,reason,CAST(payload AS CHAR) FROM refund_ledger_events WHERE order_id IN ({paid_order},{pending_order}) AND event_type LIKE 'cancellation.%' ORDER BY id")
            outputs.append({"first": first, "replay": replay, "deferred": deferred, "pending_status": pending_status,
                            "finalized": finalized, "orders": orders, "obligations": obligations, "events": events})
            r.db(f"DELETE FROM refund_ledger_events WHERE order_id IN ({paid_order},{pending_order}) AND (event_type LIKE 'cancellation.%' OR event_type='obligation.created')")
            r.db(f"DELETE FROM refund_attempts WHERE id={attempt_id}")
            r.db(f"DELETE FROM refund_obligations WHERE id={obligation_id}")
            r.db(f"DELETE FROM order_sales_reductions WHERE id={reduction_id}")
            pickup.cleanup(r.order_ids[:], reserved, sequence_exists, old_sequence)
            r.order_ids.clear(); r.return_file_paths.clear()
            custom_orders.clear(); custom_attempts.clear(); custom_obligations.clear(); custom_reductions.clear()
    finally:
        if getattr(r, "order_ids", []):
            for order_id in custom_orders:
                r.db(f"DELETE FROM refund_ledger_events WHERE order_id={order_id} AND event_type LIKE 'cancellation.%'")
            for attempt_id in custom_attempts: r.db(f"DELETE FROM refund_attempts WHERE id={attempt_id}")
            for obligation_id in custom_obligations: r.db(f"DELETE FROM refund_obligations WHERE id={obligation_id}")
            for reduction_id in custom_reductions: r.db(f"DELETE FROM order_sales_reductions WHERE id={reduction_id}")
            pickup.cleanup(r.order_ids[:], reserved, sequence_exists, old_sequence)
    outputs = [canon(value) for value in outputs]
    if outputs[0] != outputs[1]:
        print("admin cancellation finalize differential: FAIL")
        print(json.dumps(outputs, ensure_ascii=False, sort_keys=True, default=str))
        return 1
    print("admin cancellation finalize differential: 8 comparisons passed, 0 failed")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
