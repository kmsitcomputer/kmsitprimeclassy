#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for legacy return-item refund confirmation."""
import importlib.util, json, pathlib, re, uuid

ROOT = pathlib.Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("return_review_parity", ROOT / "tools/parity/compare_return_review.py")
review = importlib.util.module_from_spec(spec)
spec.loader.exec_module(review)


def norm(value):
    value = review.norm(value)
    if isinstance(value, dict):
        ignored = {"id", "return_id", "return_item_id", "order_id", "order_item_id", "order_no", "commission_id", "snapshot_id", "checksum"}
        return {key: norm(child) for key, child in value.items() if key not in ignored}
    if isinstance(value, (list, tuple)):
        return [norm(child) for child in value]
    if isinstance(value, str):
        value = re.sub(r"(return_item:)\d+", r"\1<ID>", value)
        value = re.sub(r"(return_id=)\d+", r"\1<ID>", value)
    return value


def cleanup(order_id, return_id, item_id, reserved, sequence_exists, old_sequence):
    for row in review.db(f"SELECT evidence_path FROM returns WHERE id={return_id}"):
        if row[0]:
            for root in review.PUBLIC_ROOTS:
                (root / row[0]).unlink(missing_ok=True)
    review.db(f"DELETE FROM activity_logs WHERE subject_type LIKE '%ReturnItem' AND subject_id={item_id}")
    review.db(f"DELETE FROM activity_logs WHERE subject_type LIKE '%ReturnRequest' AND subject_id={return_id}")
    review.db(f"DELETE FROM commission_adjustments WHERE return_item_id={item_id}")
    review.db(f"DELETE FROM return_items WHERE return_id={return_id}")
    review.db(f"DELETE FROM refund_ledger_events WHERE order_id={order_id}")
    review.db(f"DELETE FROM refund_payouts WHERE order_id={order_id}")
    review.db(f"DELETE FROM refund_attempts WHERE obligation_id IN (SELECT id FROM refund_obligations WHERE order_id={order_id})")
    review.db(f"DELETE FROM refund_obligations WHERE order_id={order_id}")
    review.db(f"DELETE FROM order_sales_reductions WHERE order_id={order_id}")
    review.db(f"DELETE FROM order_item_financial_bases WHERE order_id={order_id}")
    review.db(f"DELETE FROM order_financial_snapshot_units WHERE snapshot_id IN (SELECT id FROM order_financial_snapshots WHERE order_id={order_id})")
    review.db(f"DELETE FROM order_financial_snapshots WHERE order_id={order_id}")
    ids = ",".join(row[0] for row in review.db(f"SELECT id FROM order_items WHERE order_id={order_id}")) or "0"
    for sql in [f"DELETE FROM activity_logs WHERE subject_id IN ({ids}) AND subject_type LIKE '%OrderItem'",
                f"DELETE FROM order_initial_report_items WHERE order_id={order_id}",
                f"DELETE FROM stock_request_items WHERE stock_request_id IN (SELECT id FROM stock_requests WHERE order_id={order_id})",
                f"DELETE FROM stock_requests WHERE order_id={order_id}", f"DELETE FROM commissions WHERE order_id={order_id}",
                f"DELETE FROM payment_transactions WHERE order_id={order_id}",
                f"DELETE FROM stock_movements WHERE reference_type='order' AND reference_id={order_id} AND type='reserve'",
                f"DELETE FROM shipments WHERE order_id={order_id}", f"DELETE FROM order_items WHERE order_id={order_id}",
                f"DELETE FROM returns WHERE id={return_id}", f"DELETE FROM orders WHERE id={order_id}"]:
        review.db(sql)
    review.db(f"UPDATE product_stocks SET quantity_reserved={reserved} WHERE agent_id=10 AND product_id=3")
    review.db(f"UPDATE settings SET `value`='{old_sequence}' WHERE `key`='orders.invoice_sequence'" if sequence_exists else "DELETE FROM settings WHERE `key`='orders.invoice_sequence'")


def capture(order_id, return_id, item_id, order_item_id):
    state = {
        "order": review.db(f"SELECT CAST(paid_amount AS CHAR),CAST(gross_received_amount AS CHAR),CAST(remaining_amount AS CHAR),payment_status FROM orders WHERE id={order_id}"),
        "return": review.db(f"SELECT status FROM returns WHERE id={return_id}"),
        "item": review.db(f"SELECT status,refund_status FROM return_items WHERE id={item_id}"),
        "order_item": review.db(f"SELECT status,refund_quantity FROM order_items WHERE id={order_item_id}"),
        "obligations": review.db(f"SELECT source_type,status,CAST(amount AS CHAR),CAST(deferred_amount AS CHAR),CAST(deferred_remaining AS CHAR) FROM refund_obligations WHERE order_id={order_id}"),
        "attempts": review.db(f"SELECT method,CAST(amount AS CHAR),status FROM refund_attempts WHERE obligation_id IN (SELECT id FROM refund_obligations WHERE order_id={order_id}) ORDER BY attempt_no"),
        "payouts": review.db(f"SELECT CAST(amount AS CHAR),method,proof_type,verification_status,affects_paid_amount,recorded_on_cancelled_obligation,created_by FROM refund_payouts WHERE order_id={order_id}"),
        "invoice": review.db(f"SELECT `trigger`,CAST(total_amount AS CHAR),CAST(verified_paid_amount AS CHAR),CAST(outstanding_amount AS CHAR),payment_status FROM order_invoice_snapshots WHERE order_id={order_id} ORDER BY version"),
        "adjustments": review.db(f"SELECT beneficiary_role,amount,reason,policy_version FROM commission_adjustments WHERE return_item_id={item_id} ORDER BY id"),
        "ledger_events": review.db(f"SELECT event_type,reason,CAST(payload AS CHAR) FROM refund_ledger_events WHERE order_id={order_id} ORDER BY id"),
        "activity": review.db(f"SELECT event,description,CAST(properties AS CHAR),ip_address,user_agent FROM activity_logs WHERE (subject_id={return_id} AND subject_type LIKE '%ReturnRequest') OR (subject_id={item_id} AND subject_type LIKE '%ReturnItem') ORDER BY id"),
    }
    for row in state["ledger_events"]:
        if row[2]: row[2] = json.loads(row[2])
    for row in state["activity"]:
        if row[2]: row[2] = json.loads(row[2])
    return state


def main():
    if review.db("SELECT DATABASE()")[0][0] != "primeclassy_testing":
        raise RuntimeError("refusing non-testing database")
    token = uuid.uuid4().hex[:10]
    reserved = int(review.db("SELECT quantity_reserved FROM product_stocks WHERE agent_id=10 AND product_id=3")[0][0])
    seq = review.db("SELECT `value` FROM settings WHERE `key`='orders.invoice_sequence'")
    sequence_exists, old_sequence = bool(seq), seq[0][0] if seq else "0"
    runs = []
    try:
        for base in (review.LARAVEL, review.GO):
            review.order_ids, review.return_file_paths = [], []
            admin, order_id, return_id, item_id, order_item_id = review.create_case(base, token, base[-4:])
            review.db(f"UPDATE order_items SET status='pengembalian',returned_quantity=1 WHERE id={order_item_id}")
            fin = review.Client(base)
            if fin.login("keuangan@imp010.test") != 200:
                raise RuntimeError("finance login failed")
            endpoint = f"/api/v1/admin/return-items/{item_id}/refund"
            # The fixture is forced unpaid to prove the paid-order guard before subsequent mutation.
            total = review.db(f"SELECT CAST(total_amount AS CHAR) FROM orders WHERE id={order_id}")[0][0]
            review.db(f"UPDATE orders SET paid_amount=0,gross_received_amount=0,remaining_amount={total},payment_status='unpaid' WHERE id={order_id}")
            # Approval creates the return entitlement. Keep the order unpaid to compare the paid-order guard.
            review_status, review_body = admin.call("PATCH", f"/api/v1/admin/returns/{return_id}/review",
                json.dumps({"approved": True}).encode(), {"Content-Type": "application/json"})
            if review_status != 200:
                raise RuntimeError(f"return approval failed: {review_status} {review_body}")
            admin_denied = admin.call("PATCH", endpoint)
            unpaid = fin.call("PATCH", endpoint)
            review.db(f"UPDATE orders SET paid_amount={total},gross_received_amount=0,remaining_amount=0,payment_status='paid' WHERE id={order_id}")
            obligation = review.db(f"SELECT id,CAST(amount AS CHAR),CAST(deferred_amount AS CHAR),status FROM refund_obligations WHERE order_id={order_id} AND source_type='return_stage' AND source_id={item_id} ORDER BY id DESC LIMIT 1")
            if not obligation:
                raise RuntimeError("return approval did not create a return_stage obligation")
            review.db(f"UPDATE refund_obligations SET status='needs_verification' WHERE id={int(obligation[0][0])}")
            needs_verification = fin.call("PATCH", endpoint)
            # Restore actual received-money basis and arrange an Owner-approved payable synthetic obligation.
            review.db(f"UPDATE orders SET gross_received_amount={total} WHERE id={order_id}")
            refund_amount = review.db(f"SELECT CAST(refund_amount AS CHAR) FROM return_items WHERE id={item_id}")[0][0]
            review.db(f"UPDATE refund_obligations SET amount={refund_amount},deferred_amount=0,deferred_remaining=0,status='approved',approved_at=UTC_TIMESTAMP(),approval_source='return_approval' WHERE id={int(obligation[0][0])}")
            success_status, success_body = fin.call("PATCH", endpoint)
            after_success = norm(success_body)
            success_state = capture(order_id, return_id, item_id, order_item_id)
            stale = fin.call("PATCH", endpoint)
            runs.append({"admin_denied": admin_denied, "unpaid": unpaid, "needs_verification": needs_verification,
                         "success": (success_status, after_success), "success_state": norm(success_state),
                         "stale": stale})
            cleanup(order_id, return_id, item_id, reserved, sequence_exists, old_sequence)
        if runs[0] != runs[1]:
            print("return item refund differential: FAIL")
            print(json.dumps(runs, ensure_ascii=False, sort_keys=True, default=str))
            return 1
        print("return item refund differential: 10 passed, 0 failed")
        return 0
    finally:
        # Each successful loop cleans its fixture immediately; this safety pass handles exceptions.
        if review.order_ids:
            for order_id in list(review.order_ids):
                rows = review.db(f"SELECT id FROM returns WHERE order_id={order_id}")
                if rows:
                    item_id = int(review.db(f"SELECT id FROM return_items WHERE return_id={rows[0][0]} LIMIT 1")[0][0])
                    cleanup(order_id, int(rows[0][0]), item_id, reserved, sequence_exists, old_sequence)


if __name__ == "__main__":
    raise SystemExit(main())
