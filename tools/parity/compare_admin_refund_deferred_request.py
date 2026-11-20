#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for deferred materialization requests."""
import importlib.util,json,pathlib,uuid
ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location("return_review_parity",ROOT/"tools/parity/compare_return_review.py");r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
pickup_spec=importlib.util.spec_from_file_location("return_pickup_parity",ROOT/"tools/parity/compare_return_pickup_schedule.py");pickup=importlib.util.module_from_spec(pickup_spec);pickup_spec.loader.exec_module(pickup)
def main():
    if r.db("SELECT DATABASE()")[0][0]!="primeclassy_testing":raise RuntimeError("refusing non-testing database")
    token=uuid.uuid4().hex[:12];reserved=int(r.db("SELECT quantity_reserved FROM product_stocks WHERE agent_id=10 AND product_id=3")[0][0]);seq=r.db("SELECT `value` FROM settings WHERE `key`='orders.invoice_sequence'");seq_exists,old_seq=bool(seq),seq[0][0] if seq else "0";out=[]
    try:
        for base in (r.LARAVEL,r.GO):
            r.order_ids,r.return_file_paths=[],[];_,order_id,_,_,_=r.create_case(base,token,base[-4:]);suffix=base[-4:];now=r.db("SELECT UTC_TIMESTAMP(6)")[0][0];oid=int(r.db("SELECT COALESCE(MAX(id),0)+1 FROM refund_obligations")[0][0])
            r.db(f"INSERT INTO refund_obligations(id,obligation_no,order_id,source_type,obligation_key,amount,status,provenance,deferred_amount,deferred_remaining,created_at,updated_at) VALUES ({oid},'PARITY-DR-{token}-{suffix}',{order_id},'cancellation','parity-dr-{token}-{suffix}',0,'approved','forward',40.00,40.00,'{now}','{now}')")
            finance=r.Client(base);finance.login("keuangan@imp010.test");owner=r.Client(base);owner.login("agen@imp010.test");path=f"/api/v1/admin/refund-obligations/{oid}/deferred/request";key=token
            headers={"Content-Type":"application/json","Idempotency-Key":key};body=json.dumps({"amount_cents":1500}).encode()
            first=finance.call("POST",path,body,headers);replay=finance.call("POST",path,json.dumps({"amount_cents":2500}).encode(),headers)
            missing=finance.call("POST",path,b"{}",{"Content-Type":"application/json","Idempotency-Key":key+"-missing"});zero=finance.call("POST",path,json.dumps({"amount_cents":0}).encode(),{"Content-Type":"application/json","Idempotency-Key":key+"-zero"})
            owner_denied=owner.call("POST",path,body,{**headers,"Idempotency-Key":key+"-owner"})
            events=r.db(f"SELECT event_type,event_key,CAST(reason AS CHAR),CAST(payload AS CHAR) FROM refund_ledger_events WHERE order_id={order_id} AND event_type='deferred.materialization_requested' ORDER BY id")
            out.append({"first":first,"replay":replay,"missing":missing,"zero":zero,"owner_denied":owner_denied,"events":events})
            r.db(f"DELETE FROM refund_ledger_events WHERE order_id={order_id} AND event_type='deferred.materialization_requested'");r.db(f"DELETE FROM refund_obligations WHERE id={oid}");pickup.cleanup(r.order_ids[:],reserved,seq_exists,old_seq);r.order_ids.clear();r.return_file_paths.clear()
    finally:
        if getattr(r,"order_ids",[]):pickup.cleanup(r.order_ids[:],reserved,seq_exists,old_seq)
    if out[0]!=out[1]:print("admin deferred request differential: FAIL");print(json.dumps(out,ensure_ascii=False,sort_keys=True,default=str));return 1
    print("admin deferred request differential: 6 comparisons passed, 0 failed");return 0
if __name__=="__main__":raise SystemExit(main())
