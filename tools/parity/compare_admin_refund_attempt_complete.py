#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for completing refund attempts."""
import importlib.util,json,pathlib,uuid
ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location("return_review_parity",ROOT/"tools/parity/compare_return_review.py");r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
pickup_spec=importlib.util.spec_from_file_location("return_pickup_parity",ROOT/"tools/parity/compare_return_pickup_schedule.py");pickup=importlib.util.module_from_spec(pickup_spec);pickup_spec.loader.exec_module(pickup)
def canon(v):
    if isinstance(v,dict):return {k:("<id>" if k in {"id","order_id","obligation_id","attempt_id","processed_by","created_by"} else "<payout-no>" if k=="payout_no" else "<key>" if k=="payout_key" else "<timestamp>" if k.endswith("_at") and child else canon(child)) for k,child in v.items()}
    if isinstance(v,(list,tuple)):return [canon(x) for x in v]
    if isinstance(v,str) and v.startswith("{"):
        try:return json.dumps(json.loads(v),sort_keys=True,separators=(",",":"))
        except json.JSONDecodeError:pass
    return v
def main():
    if r.db("SELECT DATABASE()")[0][0]!="primeclassy_testing":raise RuntimeError("refusing non-testing database")
    token=uuid.uuid4().hex[:12];reserved=int(r.db("SELECT quantity_reserved FROM product_stocks WHERE agent_id=10 AND product_id=3")[0][0]);seq=r.db("SELECT `value` FROM settings WHERE `key`='orders.invoice_sequence'");seq_exists,old_seq=bool(seq),seq[0][0] if seq else "0";outputs=[]
    try:
        for base in (r.LARAVEL,r.GO):
            r.order_ids,r.return_file_paths=[],[];_,order_id,_,_,_=r.create_case(base,token,base[-4:]);suffix=base[-4:];now=r.db("SELECT UTC_TIMESTAMP(6)")[0][0]
            oid=int(r.db("SELECT COALESCE(MAX(id),0)+1 FROM refund_obligations")[0][0]);aid=int(r.db("SELECT COALESCE(MAX(id),0)+1 FROM refund_attempts")[0][0]);bad_id=aid+1
            r.db(f"UPDATE orders SET total_amount=100.00,gross_received_amount=50.00,paid_amount=50.00,remaining_amount=50.00,payment_status='partially_paid' WHERE id={order_id}")
            r.db(f"INSERT INTO refund_obligations(id,obligation_no,order_id,source_type,obligation_key,amount,status,provenance,created_at,updated_at) VALUES ({oid},'PARITY-COMP-{token}-{suffix}',{order_id},'manual','parity-comp-{token}-{suffix}',40.00,'approved','forward','{now}','{now}')")
            for i,(status,amount) in enumerate([("processing",20.00),("created",10.00)]):r.db(f"INSERT INTO refund_attempts(id,obligation_id,attempt_no,method,amount,status,idempotency_key,proof_type,external_reference,created_at,updated_at) VALUES ({aid+i},{oid},{i+1},'bank_transfer',{amount},'{status}','parity-comp-{token}-{suffix}-{i}','receipt','BANK-REF-{i}','{now}','{now}')")
            finance=r.Client(base);finance.login("keuangan@imp010.test");consumer=r.Client(base);consumer.login("konsumen@imp010.test");path=f"/api/v1/admin/refund-attempts/{aid}/complete"
            completed=finance.call("POST",path,json.dumps({"proof_type":"bank_receipt","gateway_reference":"  GW-42  "}).encode(),{"Content-Type":"application/json"})
            replay=finance.call("POST",path,b"{}",{"Content-Type":"application/json"})
            invalid=finance.call("POST",f"/api/v1/admin/refund-attempts/{bad_id}/complete",b"{}",{"Content-Type":"application/json"})
            denied=consumer.call("POST",path,b"{}",{"Content-Type":"application/json"})
            state=r.db(f"SELECT status,(finished_at IS NOT NULL),processed_by FROM refund_attempts WHERE id={aid}")
            payout=r.db(f"SELECT CAST(amount AS CHAR),method,verification_status,affects_paid_amount,(paid_effect_applied_at IS NOT NULL),recorded_on_cancelled_obligation,proof_type,gateway_reference FROM refund_payouts WHERE attempt_id={aid}")
            order=r.db(f"SELECT CAST(gross_received_amount AS CHAR),CAST(paid_amount AS CHAR),CAST(remaining_amount AS CHAR),payment_status FROM orders WHERE id={order_id}")
            obligation=r.db(f"SELECT status FROM refund_obligations WHERE id={oid}")
            events=r.db(f"SELECT event_type,CAST(payload AS CHAR) FROM refund_ledger_events WHERE attempt_id={aid} AND event_type='payout.recorded'")
            outputs.append({"completed":completed,"replay":replay,"invalid":invalid,"denied":denied,"state":state,"payout":payout,"order":order,"obligation":obligation,"events":events})
            r.db(f"DELETE FROM refund_ledger_events WHERE order_id={order_id} AND attempt_id IN ({aid},{bad_id})");r.db(f"DELETE FROM refund_payouts WHERE attempt_id={aid}");r.db(f"DELETE FROM refund_attempts WHERE id IN ({aid},{bad_id})");r.db(f"DELETE FROM refund_obligations WHERE id={oid}")
            pickup.cleanup(r.order_ids[:],reserved,seq_exists,old_seq);r.order_ids.clear();r.return_file_paths.clear()
    finally:
        if getattr(r,"order_ids",[]):pickup.cleanup(r.order_ids[:],reserved,seq_exists,old_seq)
    outputs=[canon(x) for x in outputs]
    if outputs[0]!=outputs[1]:print("admin refund attempt complete differential: FAIL");print(json.dumps(outputs,ensure_ascii=False,sort_keys=True,default=str));return 1
    print("admin refund attempt complete differential: 9 comparisons passed, 0 failed");return 0
if __name__=="__main__":raise SystemExit(main())
