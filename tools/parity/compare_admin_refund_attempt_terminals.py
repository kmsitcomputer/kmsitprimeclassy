#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for refund-attempt cancel and unknown transitions."""
import importlib.util,json,pathlib,uuid
ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location("return_review_parity",ROOT/"tools/parity/compare_return_review.py");r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
pickup_spec=importlib.util.spec_from_file_location("return_pickup_parity",ROOT/"tools/parity/compare_return_pickup_schedule.py");pickup=importlib.util.module_from_spec(pickup_spec);pickup_spec.loader.exec_module(pickup)
def canon(v):
    if isinstance(v,dict):return {k:("<id>" if k in {"id","obligation_id","processed_by"} else "<request-key>" if k=="idempotency_key" else "<timestamp>" if (k.endswith("_at") or k=="unknown_since") and child else canon(child)) for k,child in v.items()}
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
            oid=int(r.db("SELECT COALESCE(MAX(id),0)+1 FROM refund_obligations")[0][0]);aids=[int(r.db("SELECT COALESCE(MAX(id),0)+1 FROM refund_attempts")[0][0]),0,0];aids[1]=aids[0]+1;aids[2]=aids[0]+2
            r.db(f"INSERT INTO refund_obligations(id,obligation_no,order_id,source_type,obligation_key,amount,status,provenance,created_at,updated_at) VALUES ({oid},'PARITY-TERM-{token}-{suffix}',{order_id},'manual','parity-term-{token}-{suffix}',100.00,'approved','forward','{now}','{now}')")
            for i,status in enumerate(["created","processing","created"]):r.db(f"INSERT INTO refund_attempts(id,obligation_id,attempt_no,method,amount,status,idempotency_key,created_at,updated_at) VALUES ({aids[i]},{oid},{i+1},'bank_transfer',10.00,'{status}','parity-term-{token}-{suffix}-{i}','{now}','{now}')")
            finance=r.Client(base);finance.login("keuangan@imp010.test");p=lambda aid:f"/api/v1/admin/refund-attempts/{aid}"
            cancel=finance.call("POST",p(aids[0])+"/cancel",json.dumps({"reason":"  pre-init cancelled  "}).encode(),{"Content-Type":"application/json"})
            cancel_replay=finance.call("POST",p(aids[0])+"/cancel",json.dumps({"reason":"again"}).encode(),{"Content-Type":"application/json"})
            cancel_missing_reason=finance.call("POST",p(aids[2])+"/cancel",b"{}",{"Content-Type":"application/json"})
            unknown=finance.call("POST",p(aids[1])+"/unknown",json.dumps({"note":"  provider timeout  "}).encode(),{"Content-Type":"application/json"})
            unknown_replay=finance.call("POST",p(aids[1])+"/unknown",json.dumps({"note":"changed"}).encode(),{"Content-Type":"application/json"})
            unknown_invalid=finance.call("POST",p(aids[2])+"/unknown",b"{}",{"Content-Type":"application/json"})
            state=r.db(f"SELECT status,failure_message,(finished_at IS NOT NULL),(unknown_since IS NOT NULL) FROM refund_attempts WHERE id IN ({aids[0]},{aids[1]},{aids[2]}) ORDER BY id")
            events=r.db(f"SELECT event_type,reason,CAST(payload AS CHAR) FROM refund_ledger_events WHERE order_id={order_id} AND event_type IN ('attempt.cancelled','attempt.unknown') ORDER BY event_type")
            outputs.append({"cancel":cancel,"cancel_replay":cancel_replay,"cancel_missing_reason":cancel_missing_reason,"unknown":unknown,"unknown_replay":unknown_replay,"unknown_invalid":unknown_invalid,"state":state,"events":events})
            r.db(f"DELETE FROM refund_ledger_events WHERE order_id={order_id} AND attempt_id IN ({','.join(map(str,aids))})");r.db(f"DELETE FROM refund_attempts WHERE id IN ({','.join(map(str,aids))})");r.db(f"DELETE FROM refund_obligations WHERE id={oid}")
            pickup.cleanup(r.order_ids[:],reserved,seq_exists,old_seq);r.order_ids.clear();r.return_file_paths.clear()
    finally:
        if getattr(r,"order_ids",[]):pickup.cleanup(r.order_ids[:],reserved,seq_exists,old_seq)
    outputs=[canon(x) for x in outputs]
    if outputs[0]!=outputs[1]:print("admin refund attempt terminal differential: FAIL");print(json.dumps(outputs,ensure_ascii=False,sort_keys=True,default=str));return 1
    print("admin refund attempt terminal differential: 8 comparisons passed, 0 failed");return 0
if __name__=="__main__":raise SystemExit(main())
