#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for Admin additional-payment settlement."""
import importlib.util, json, pathlib, uuid

ROOT = pathlib.Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("return_review_parity", ROOT / "tools/parity/compare_return_review.py")
r = importlib.util.module_from_spec(spec); spec.loader.exec_module(r)
pickup_spec = importlib.util.spec_from_file_location("return_pickup_parity", ROOT / "tools/parity/compare_return_pickup_schedule.py")
pickup = importlib.util.module_from_spec(pickup_spec); pickup_spec.loader.exec_module(pickup)


def db(sql):
    return r.db(sql)


def main():
    if db("SELECT DATABASE()")[0][0] != "primeclassy_testing":
        raise RuntimeError("refusing non-testing database")
    token = uuid.uuid4().hex[:10]
    reserved = int(db("SELECT quantity_reserved FROM product_stocks WHERE agent_id=10 AND product_id=3")[0][0])
    seq = db("SELECT `value` FROM settings WHERE `key`='orders.invoice_sequence'")
    sequence_exists, old_sequence = bool(seq), seq[0][0] if seq else "0"
    outputs = []
    try:
        for base in (r.LARAVEL, r.GO):
            r.order_ids, r.return_file_paths = [], []
            _, order_id, _, _, _ = r.create_case(base, token, base[-4:])
            now = db("SELECT UTC_TIMESTAMP()")[0][0]
            method_id = int(db("SELECT id FROM payment_methods ORDER BY id LIMIT 1")[0][0])
            payment_ids = []
            for suffix, amount, method, paid in (("failed", "1250.00", "transfer", False), ("settled", "2300.00", "transfer", True)):
                tx_id = int(db("SELECT COALESCE(MAX(id),0)+1 FROM payment_transactions")[0][0])
                ap_id = int(db("SELECT COALESCE(MAX(id),0)+1 FROM order_additional_payments")[0][0])
                raw = json.dumps({"channel": "manual_transfer", "instructions": {"bank": "BCA", "reference": f"PARITY-{token}-{suffix}"}})
                db(f"INSERT INTO payment_transactions (id,order_id,payment_method_id,type,amount,status,raw_payload,created_at,updated_at) VALUES ({tx_id},{order_id},{method_id},'additional_payment',{amount},'pending','{raw}','{now}','{now}')")
                db(f"INSERT INTO order_additional_payments (id,order_id,requested_by,payment_transaction_id,amount,reason,status,method,created_at,updated_at) VALUES ({ap_id},{order_id},11,{tx_id},{amount},'Additional payment status parity {suffix}','pending','{method}','{now}','{now}')")
                db(f"INSERT INTO bank_transfer_verifications (payment_transaction_id,bank_name,account_name,account_number,proof_image_path,status,channel,submitted_amount,proof_disk,created_at,updated_at) VALUES ({tx_id},'BCA','Parity','123','parity/{token}-{suffix}.jpg','pending','transfer',{amount},'public','{now}','{now}')")
                payment_ids.append((ap_id, tx_id, suffix, paid))
            finance = r.Client(base)
            if finance.login("keuangan@imp010.test") != 200:
                raise RuntimeError("Keuangan login failed")
            consumer = r.Client(base)
            consumer.login("konsumen@imp010.test")
            invalid_missing = finance.call("PATCH", f"/api/v1/admin/additional-payments/{payment_ids[0][0]}/status", json.dumps({}).encode(), {"Content-Type":"application/json"})
            invalid_bool = finance.call("PATCH", f"/api/v1/admin/additional-payments/{payment_ids[0][0]}/status", json.dumps({"paid":"yes"}).encode(), {"Content-Type":"application/json"})
            denied = consumer.call("PATCH", f"/api/v1/admin/additional-payments/{payment_ids[0][0]}/status", json.dumps({"paid":True}).encode(), {"Content-Type":"application/json"})
            results = []
            for ap_id, tx_id, suffix, paid in payment_ids:
                audit_before = int(db("SELECT COALESCE(MAX(id),0) FROM activity_logs")[0][0])
                status, body = finance.call("PATCH", f"/api/v1/admin/additional-payments/{ap_id}/status", json.dumps({"paid":paid}).encode(), {"Content-Type":"application/json"})
                state = {
                    "order": db(f"SELECT payment_status,CAST(paid_amount AS CHAR),CAST(gross_received_amount AS CHAR),CAST(remaining_amount AS CHAR),invoice_issued_at IS NOT NULL FROM orders WHERE id={order_id}"),
                    "additional": db(f"SELECT status FROM order_additional_payments WHERE id={ap_id}"),
                    "transaction": db(f"SELECT status,paid_at IS NOT NULL FROM payment_transactions WHERE id={tx_id}"),
                    "verification": db(f"SELECT status,verified_by,verified_at IS NOT NULL,CAST(verified_amount AS CHAR),rejection_reason FROM bank_transfer_verifications WHERE payment_transaction_id={tx_id}"),
                    "snapshots": db(f"SELECT version,`trigger`,CAST(total_amount AS CHAR),CAST(verified_paid_amount AS CHAR),CAST(outstanding_amount AS CHAR),payment_status FROM order_invoice_snapshots WHERE order_id={order_id} ORDER BY version"),
                    "audit": db(f"SELECT event,CAST(properties AS CHAR) FROM activity_logs WHERE id>{audit_before} AND subject_type LIKE '%OrderAdditionalPayment' AND subject_id={ap_id} ORDER BY id"),
                }
                for row in state["audit"]:
                    if row[1]: row[1] = json.loads(row[1])
                replay = finance.call("PATCH", f"/api/v1/admin/additional-payments/{ap_id}/status", json.dumps({"paid":paid}).encode(), {"Content-Type":"application/json"})
                results.append({"status":status,"body":body,"state":state,"replay":replay})
            outputs.append({"missing":invalid_missing,"bad_boolean":invalid_bool,"denied":denied,"results":results})
            pickup.cleanup(r.order_ids[:], reserved, sequence_exists, old_sequence)
            r.order_ids.clear(); r.return_file_paths.clear()
    finally:
        if getattr(r, "order_ids", []):
            pickup.cleanup(r.order_ids[:], reserved, sequence_exists, old_sequence)

    def normalize(value):
        if isinstance(value, dict):
            return {k: ("<id>" if k in {"id","order_id"} else "<order-no>" if k == "order_no" else "<timestamp>" if k == "created_at" else normalize(v)) for k,v in value.items()}
        if isinstance(value, list): return [normalize(v) for v in value]
        return value

    canon = [normalize(value) for value in outputs]
    if canon[0] != canon[1]:
        print("admin additional-payment status differential: FAIL")
        print(json.dumps(canon, ensure_ascii=False, sort_keys=True, default=str))
        return 1
    if outputs[0]["missing"][0] != 422 or outputs[0]["bad_boolean"][0] != 422 or outputs[0]["denied"][0] != 403:
        raise AssertionError("validation or authorization contract failed")
    if any(result["status"] != 200 or result["replay"][0] != 422 for result in outputs[0]["results"]):
        raise AssertionError("settlement or stale replay contract failed")
    print("admin additional-payment status differential: 7 passed, 0 failed")
    return 0


if __name__ == "__main__": raise SystemExit(main())
