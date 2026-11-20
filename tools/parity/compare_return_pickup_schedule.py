#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for scheduling return pickups."""
import importlib.util, json, pathlib, uuid
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo

ROOT = pathlib.Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("return_review_parity", ROOT / "tools/parity/compare_return_review.py")
review = importlib.util.module_from_spec(spec)
spec.loader.exec_module(review)
LARAVEL, GO = review.LARAVEL, review.GO


def normalized_state(return_id, item_id):
    activity = review.db(f"SELECT event,description,CAST(properties AS CHAR),ip_address,user_agent FROM activity_logs WHERE subject_id={return_id} AND subject_type LIKE '%ReturnRequest' ORDER BY id")
    for row in activity:
        row[2] = json.loads(row[2]) if row[2] else None
    return {
        "return": review.db(f"SELECT status FROM returns WHERE id={return_id}"),
        "item": review.db(f"SELECT status,CAST(pickup_scheduled_for AS CHAR) FROM return_items WHERE id={item_id}"),
        "activity": activity,
    }


def cleanup(order_ids, reserved, sequence_exists, old_sequence):
    for order_id in order_ids:
        for row in review.db(f"SELECT id,evidence_path FROM returns WHERE order_id={order_id}"):
            return_id, relative = int(row[0]), row[1]
            review.db(f"DELETE FROM activity_logs WHERE subject_type LIKE '%ReturnRequest' AND subject_id={return_id}")
            review.db(f"DELETE FROM return_items WHERE return_id={return_id}")
            review.db(f"DELETE FROM returns WHERE id={return_id}")
            if relative:
                for root in review.PUBLIC_ROOTS:
                    (root / relative).unlink(missing_ok=True)
        review.db(f"DELETE FROM activity_logs WHERE subject_id={order_id} AND subject_type LIKE '%Order'")
        review.db(f"DELETE FROM refund_ledger_events WHERE order_id={order_id}")
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
                    f"DELETE FROM orders WHERE id={order_id}"]:
            review.db(sql)
    review.db(f"UPDATE product_stocks SET quantity_reserved={reserved} WHERE agent_id=10 AND product_id=3")
    review.db(f"UPDATE settings SET `value`='{old_sequence}' WHERE `key`='orders.invoice_sequence'" if sequence_exists else "DELETE FROM settings WHERE `key`='orders.invoice_sequence'")


def main():
    if review.db("SELECT DATABASE()")[0][0] != "primeclassy_testing":
        raise RuntimeError("refusing non-testing database")
    token = uuid.uuid4().hex[:10]
    order_ids, paths, outputs = [], [], []
    reserved = int(review.db("SELECT quantity_reserved FROM product_stocks WHERE agent_id=10 AND product_id=3")[0][0])
    seq = review.db("SELECT `value` FROM settings WHERE `key`='orders.invoice_sequence'")
    sequence_exists, old_sequence = bool(seq), seq[0][0] if seq else "0"
    review.order_ids, review.return_file_paths = order_ids, paths
    today_date = datetime.now(ZoneInfo("Asia/Jakarta")).date()
    today = today_date.isoformat()
    tomorrow = (today_date + timedelta(days=1)).isoformat()
    yesterday = (today_date - timedelta(days=1)).isoformat()
    try:
        for base in (LARAVEL, GO):
            admin, order_id, return_id, item_id, _ = review.create_case(base, token, base[-4:])
            path = f"/api/v1/admin/returns/{return_id}/pickup-schedule"
            missing = admin.call("PATCH", path, json.dumps({}).encode(), {"Content-Type": "application/json"})
            malformed = admin.call("PATCH", path, json.dumps({"pickup_scheduled_for": "2030-02-31"}).encode(), {"Content-Type": "application/json"})
            past = admin.call("PATCH", path, json.dumps({"pickup_scheduled_for": yesterday}).encode(), {"Content-Type": "application/json"})
            scheduled = admin.call("PATCH", path, json.dumps({"pickup_scheduled_for": tomorrow}).encode(), {"Content-Type": "application/json"})
            success_state = normalized_state(return_id, item_id)
            review_status, review_body = admin.call("PATCH", f"/api/v1/admin/returns/{return_id}/review",
                json.dumps({"approved": False}).encode(), {"Content-Type": "application/json"})
            unavailable = admin.call("PATCH", path, json.dumps({"pickup_scheduled_for": tomorrow}).encode(), {"Content-Type": "application/json"})
            outputs.append({"missing": missing, "malformed": malformed, "past": past, "scheduled": scheduled,
                            "success_state": success_state, "reject": (review_status, review_body.get("message")),
                            "unavailable": unavailable, "final_state": normalized_state(return_id, item_id)})
    finally:
        cleanup(order_ids, reserved, sequence_exists, old_sequence)
    def norm(value):
        if isinstance(value, dict):
            return {key: ("<id>" if key in {"id", "order_id", "order_item_id", "order_no", "return_id"} else norm(child)) for key, child in value.items()}
        if isinstance(value, (list, tuple)):
            return [norm(child) for child in value]
        return value
    outputs = [norm(value) for value in outputs]
    if outputs[0] != outputs[1]:
        print("return pickup schedule differential: FAIL")
        print(json.dumps(outputs, ensure_ascii=False, sort_keys=True, default=str))
        return 1
    print("return pickup schedule differential: 6 passed, 0 failed")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
