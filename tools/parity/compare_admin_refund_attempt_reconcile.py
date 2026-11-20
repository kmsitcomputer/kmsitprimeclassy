#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for reconciling unknown refund attempts."""
import importlib.util,json,pathlib,uuid
ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location("return_review_parity",ROOT/"tools/parity/compare_return_review.py");r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
pickup_spec=importlib.util.spec_from_file_location("return_pickup_parity",ROOT/"tools/parity/compare_return_pickup_schedule.py");pickup=importlib.util.module_from_spec(pickup_spec);pickup_spec.loader.exec_module(pickup)
def canon(v):
    if isinstance(v,dict):return {k:("<id>" if k in {"id","order_id","obligation_id","attempt_id","processed_by","reconciled_by","created_by"} else "<payout-no>" if k=="payout_no" else "<key>" if k in {"idempotency_key","payout_key"} else "<timestamp>" if k.endswith("_at") and child else "<fixture-key>" if k in {"obligation_no","obligation_key"} else canon(child)) for k,child in v.items()}
    if isinstance(v,(list,tuple)):return [canon(x) for x in v]
    if isinstance(v,str) and v.startswith("{"):
        try:return json.dumps(json.loads(v),sort_keys=True,separators=(",",":"))
        except json.JSONDecodeError:pass
    return v
def main():
    if r.db("SELECT DATABASE()")[0][0]!="primeclassy_testing":raise RuntimeError("refusing non-testing database")
    token=uuid.uuid4().hex[:12];reserved=int(r.db("SELECT quantity_reserved FROM product_stocks WHERE agent_id=10 AND product_id=3")[0][0]);seq=r.db("SELECT `value` FROM settings WHERE `key`='orders.invoice_sequence'");seq_exists,old_seq=bool(seq),seq[0][0] if seq else "0";out=[]
    try:
        for base in (r.LARAVEL,r.GO):
            r.order_ids,r.return_file_paths=[],[];cases=[];suffix=base[-4:]
            for i in range(2):
                _,order_id,_,_,_=r.create_case(base,token+str(i),suffix+str(i));cases.append(order_id)
            now=r.db("SELECT UTC_TIMESTAMP(6)")[0][0];oid=int(r.db("SELECT COALESCE(MAX(id),0)+1 FROM refund_obligations")[0][0]);aids=[int(r.db("SELECT COALESCE(MAX(id),0)+1 FROM refund_attempts")[0][0]),0];aids[1]=aids[0]+1
            for i,order_id in enumerate(cases):
                r.db(f"UPDATE orders SET total_amount=100.00,gross_received_amount=80.00,paid_amount=80.00,remaining_amount=20.00,payment_status='partially_paid' WHERE id={order_id}")
                r.db(f"INSERT INTO refund_obligations(id,obligation_no,order_id,source_type,obligation_key,amount,status,provenance,created_at,updated_at) VALUES ({oid+i},'PARITY-REC-{token}-{suffix}-{i}',{order_id},'cancellation','parity-rec-{token}-{suffix}-{i}',30.00,'approved','forward','{now}','{now}')")
                r.db(f"INSERT INTO refund_attempts(id,obligation_id,attempt_no,method,amount,status,idempotency_key,created_at,updated_at) VALUES ({aids[i]},{oid+i},1,'bank_transfer',20.00,'unknown','parity-rec-{token}-{suffix}-{i}','{now}','{now}')")
            finance=r.Client(base);finance.login("keuangan@imp010.test");consumer=r.Client(base);consumer.login("konsumen@imp010.test");path=f"/api/v1/admin/refund-attempts/{aids[0]}/reconcile";body=json.dumps({"succeeded":True,"evidence":"bank confirms paid"}).encode()
            succeeded=finance.call("POST",path,body,{"Content-Type":"application/json"})
            failed=finance.call("POST",f"/api/v1/admin/refund-attempts/{aids[1]}/reconcile",json.dumps({"succeeded":False,"evidence":"bank confirms no transfer"}).encode(),{"Content-Type":"application/json"})
            invalid=finance.call("POST",f"/api/v1/admin/refund-attempts/{aids[1]}/reconcile",json.dumps({"succeeded":False,"evidence":"changed"}).encode(),{"Content-Type":"application/json"})
            missing=finance.call("POST",path,json.dumps({"succeeded":True,"evidence":""}).encode(),{"Content-Type":"application/json"})
            denied=consumer.call("POST",path,body,{"Content-Type":"application/json"})
            states=r.db(f"SELECT status,failure_code,failure_message,reconciliation_evidence,(reconciled_at IS NOT NULL),(finished_at IS NOT NULL),processed_by IS NOT NULL,reconciled_by IS NOT NULL FROM refund_attempts WHERE id IN ({aids[0]},{aids[1]}) ORDER BY id")
            payouts=r.db(f"SELECT CAST(amount AS CHAR),verification_status,affects_paid_amount,(paid_effect_applied_at IS NOT NULL) FROM refund_payouts WHERE attempt_id IN ({aids[0]},{aids[1]}) ORDER BY attempt_id")
            events=r.db(f"SELECT event_type,reason,CAST(payload AS CHAR) FROM refund_ledger_events WHERE order_id IN ({cases[0]},{cases[1]}) AND event_type IN ('payout.recorded','attempt.failed') ORDER BY id")
            out.append({"succeeded":succeeded,"failed":failed,"invalid":invalid,"missing":missing,"denied":denied,"states":states,"payouts":payouts,"events":events})
            r.db(f"DELETE FROM refund_ledger_events WHERE order_id IN ({cases[0]},{cases[1]}) AND event_type IN ('payout.recorded','attempt.failed','cancellation.finalized')")
            r.db(f"DELETE FROM refund_payouts WHERE attempt_id IN ({aids[0]},{aids[1]})");r.db(f"DELETE FROM refund_attempts WHERE id IN ({aids[0]},{aids[1]})");r.db(f"DELETE FROM refund_obligations WHERE id IN ({oid},{oid+1})")
            pickup.cleanup(r.order_ids[:],reserved,seq_exists,old_seq);r.order_ids.clear();r.return_file_paths.clear()
    finally:
        if getattr(r,"order_ids",[]):pickup.cleanup(r.order_ids[:],reserved,seq_exists,old_seq)
    out=[canon(x) for x in out]
    if out[0]!=out[1]:print("admin refund attempt reconcile differential: FAIL");print(json.dumps(out,ensure_ascii=False,sort_keys=True,default=str));return 1
    print("admin refund attempt reconcile differential: 8 comparisons passed, 0 failed");return 0
if __name__=="__main__":raise SystemExit(main())
