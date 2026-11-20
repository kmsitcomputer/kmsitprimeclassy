#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for initiating refund attempts."""
import importlib.util,json,pathlib,uuid
ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location("return_review_parity",ROOT/"tools/parity/compare_return_review.py");r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
pickup_spec=importlib.util.spec_from_file_location("return_pickup_parity",ROOT/"tools/parity/compare_return_pickup_schedule.py");pickup=importlib.util.module_from_spec(pickup_spec);pickup_spec.loader.exec_module(pickup)
def canon(v):
    if isinstance(v,dict):return {k:("<id>" if k in {"id","obligation_id","processed_by"} else "<request-key>" if k=="idempotency_key" else "<timestamp>" if k.endswith("_at") and child else canon(child)) for k,child in v.items()}
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
            r.order_ids,r.return_file_paths=[],[];_,order_id,_,_,_=r.create_case(base,token,base[-4:]);suffix=base[-4:];now=r.db("SELECT UTC_TIMESTAMP(6)")[0][0]
            oid=int(r.db("SELECT COALESCE(MAX(id),0)+1 FROM refund_obligations")[0][0]);aid=int(r.db("SELECT COALESCE(MAX(id),0)+1 FROM refund_attempts")[0][0]);bad_id=aid+1
            r.db(f"INSERT INTO refund_obligations(id,obligation_no,order_id,source_type,obligation_key,amount,status,provenance,created_at,updated_at) VALUES ({oid},'PARITY-INI-{token}-{suffix}',{order_id},'manual','parity-ini-{token}-{suffix}',100.00,'approved','forward','{now}','{now}')")
            r.db(f"INSERT INTO refund_attempts(id,obligation_id,attempt_no,method,amount,status,idempotency_key,created_at,updated_at) VALUES ({aid},{oid},1,'bank_transfer',30.00,'created','parity-ini-{token}-{suffix}','{now}','{now}')")
            r.db(f"INSERT INTO refund_attempts(id,obligation_id,attempt_no,method,amount,status,idempotency_key,created_at,updated_at) VALUES ({bad_id},{oid},2,'bank_transfer',30.00,'failed','parity-ini-{token}-{suffix}-bad','{now}','{now}')")
            finance=r.Client(base);finance.login("keuangan@imp010.test");consumer=r.Client(base);consumer.login("konsumen@imp010.test")
            path=f"/api/v1/admin/refund-attempts/{aid}/initiate"
            first=finance.call("POST",path,json.dumps({"external_reference":"  REF-INIT  "}).encode(),{"Content-Type":"application/json"})
            replay=finance.call("POST",path,json.dumps({"external_reference":"CHANGED"}).encode(),{"Content-Type":"application/json"})
            invalid=finance.call("POST",f"/api/v1/admin/refund-attempts/{bad_id}/initiate",b"{}",{"Content-Type":"application/json"})
            missing=finance.call("POST",f"/api/v1/admin/refund-attempts/{aid+100000}/initiate",b"{}",{"Content-Type":"application/json"})
            denied=consumer.call("POST",path,b"{}",{"Content-Type":"application/json"})
            state=r.db(f"SELECT status,external_reference,(initiated_at IS NOT NULL),(started_at IS NOT NULL),processed_by FROM refund_attempts WHERE id={aid}")
            audit=r.db(f"SELECT event_type,actor_type,CAST(payload AS CHAR) FROM refund_ledger_events WHERE attempt_id={aid} AND event_type='attempt.initiated'")
            out.append({"first":first,"replay":replay,"invalid":invalid,"missing":missing,"denied":denied,"state":state,"audit":audit})
            r.db(f"DELETE FROM refund_ledger_events WHERE order_id={order_id} AND attempt_id IN ({aid},{bad_id})");r.db(f"DELETE FROM refund_attempts WHERE id IN ({aid},{bad_id})");r.db(f"DELETE FROM refund_obligations WHERE id={oid}")
            pickup.cleanup(r.order_ids[:],reserved,seq_exists,old_seq);r.order_ids.clear();r.return_file_paths.clear()
    finally:
        if getattr(r,"order_ids",[]):pickup.cleanup(r.order_ids[:],reserved,seq_exists,old_seq)
    out=[canon(x) for x in out]
    if out[0]!=out[1]:print("admin refund attempt initiate differential: FAIL");print(json.dumps(out,ensure_ascii=False,sort_keys=True,default=str));return 1
    print("admin refund attempt initiate differential: 7 comparisons passed, 0 failed");return 0
if __name__=="__main__":raise SystemExit(main())
