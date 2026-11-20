#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for needs-verification exits."""
import importlib.util,json,pathlib,uuid
ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location("return_review_parity",ROOT/"tools/parity/compare_return_review.py");r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
pickup_spec=importlib.util.spec_from_file_location("return_pickup_parity",ROOT/"tools/parity/compare_return_pickup_schedule.py");pickup=importlib.util.module_from_spec(pickup_spec);pickup_spec.loader.exec_module(pickup)
def canon(v):
    if isinstance(v,dict):return {k:("<id>" if k in {"id","order_id","approved_by","created_by","cancelled_by"} else "<timestamp>" if k.endswith("_at") and child else "<fixture-key>" if k in {"obligation_key","obligation_no"} else canon(child)) for k,child in v.items()}
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
            r.order_ids,r.return_file_paths=[],[];_,order_id,_,_,_=r.create_case(base,token,base[-4:]);suffix=base[-4:];now=r.db("SELECT UTC_TIMESTAMP(6)")[0][0];first=int(r.db("SELECT COALESCE(MAX(id),0)+1 FROM refund_obligations")[0][0]);ids=[first,first+1,first+2]
            for i,oid in enumerate(ids):r.db(f"INSERT INTO refund_obligations(id,obligation_no,order_id,source_type,obligation_key,amount,status,provenance,deferred_amount,deferred_remaining,created_at,updated_at) VALUES ({oid},'PARITY-NV-{token}-{suffix}-{i}',{order_id},'return_stage','parity-nv-{token}-{suffix}-{i}',50.00,'needs_verification','forward',0,0,'{now}','{now}')")
            finance=r.Client(base);finance.login("keuangan@imp010.test");owner=r.Client(base);owner.login("agen@imp010.test");path=lambda oid:f"/api/v1/admin/refund-obligations/{oid}/needs-verification/resolve"
            exit_a=finance.call("POST",path(ids[0]),json.dumps({"exit":"A","evidence":"verified forward source","reason":"reviewed"}).encode(),{"Content-Type":"application/json"})
            replay=finance.call("POST",path(ids[0]),json.dumps({"exit":"A","evidence":"another evidence"}).encode(),{"Content-Type":"application/json"})
            exit_b=owner.call("POST",path(ids[1]),json.dumps({"exit":"B","evidence":"owner confirmed writeoff"}).encode(),{"Content-Type":"application/json"})
            invalid=finance.call("POST",path(ids[2]),json.dumps({"exit":"C","evidence":"xx"}).encode(),{"Content-Type":"application/json"})
            missing_evidence=finance.call("POST",path(ids[2]),json.dumps({"exit":"A","evidence":""}).encode(),{"Content-Type":"application/json"})
            replay_after_cancel=finance.call("POST",path(ids[1]),json.dumps({"exit":"B","evidence":"finance forbidden"}).encode(),{"Content-Type":"application/json"})
            states=r.db(f"SELECT status,approval_source,cancel_reason,(approved_at IS NOT NULL),(cancelled_at IS NOT NULL) FROM refund_obligations WHERE id IN ({','.join(map(str,ids))}) ORDER BY id")
            audit=r.db(f"SELECT event_type,reason,CAST(payload AS CHAR) FROM refund_ledger_events WHERE order_id={order_id} AND event_type='needs_verification.resolved' ORDER BY id")
            out.append({"exit_a":exit_a,"replay":replay,"exit_b":exit_b,"invalid":invalid,"missing_evidence":missing_evidence,"replay_after_cancel":replay_after_cancel,"states":states,"audit":audit})
            r.db(f"DELETE FROM refund_ledger_events WHERE order_id={order_id} AND event_type='needs_verification.resolved'");r.db(f"DELETE FROM refund_obligations WHERE id IN ({','.join(map(str,ids))})");pickup.cleanup(r.order_ids[:],reserved,seq_exists,old_seq);r.order_ids.clear();r.return_file_paths.clear()
    finally:
        if getattr(r,"order_ids",[]):pickup.cleanup(r.order_ids[:],reserved,seq_exists,old_seq)
    out=[canon(x) for x in out]
    if out[0]!=out[1]:print("admin refund needs-verification differential: FAIL");print(json.dumps(out,ensure_ascii=False,sort_keys=True,default=str));return 1
    print("admin refund needs-verification differential: 8 comparisons passed, 0 failed");return 0
if __name__=="__main__":raise SystemExit(main())
