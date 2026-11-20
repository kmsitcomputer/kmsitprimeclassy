#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for deferred materialization."""
import importlib.util,json,pathlib,uuid
ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location("return_review_parity",ROOT/"tools/parity/compare_return_review.py");r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
pickup_spec=importlib.util.spec_from_file_location("return_pickup_parity",ROOT/"tools/parity/compare_return_pickup_schedule.py");pickup=importlib.util.module_from_spec(pickup_spec);pickup_spec.loader.exec_module(pickup)
def canon(v):
    if isinstance(v,dict):return {k:("<id>" if k in {"id","order_id","source_id","parent_obligation_id","approved_by","created_by"} else "<ulid>" if k=="obligation_no" else "<key>" if k=="obligation_key" else "<timestamp>" if k.endswith("_at") and child else canon(child)) for k,child in v.items()}
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
            r.order_ids,r.return_file_paths=[],[];_,order_id,_,_,_=r.create_case(base,token,base[-4:]);suffix=base[-4:];now=r.db("SELECT UTC_TIMESTAMP(6)")[0][0];first=int(r.db("SELECT COALESCE(MAX(id),0)+1 FROM refund_obligations")[0][0]);ids=[first,first+1]
            r.db(f"UPDATE orders SET total_amount=50.00,gross_received_amount=100.00,paid_amount=100.00,remaining_amount=0,payment_status='paid' WHERE id={order_id}")
            for i,oid in enumerate(ids):r.db(f"INSERT INTO refund_obligations(id,obligation_no,order_id,source_type,obligation_key,amount,status,provenance,deferred_amount,deferred_remaining,created_at,updated_at) VALUES ({oid},'PARITY-DEF-{token}-{suffix}-{i}',{order_id},'cancellation','parity-def-{token}-{suffix}-{i}',0,'approved','forward',40.00,40.00,'{now}','{now}')")
            owner=r.Client(base);owner.login("agen@imp010.test");path=f"/api/v1/admin/refund-obligations/{ids[0]}/deferred/materialize";key=token;headers={"Content-Type":"application/json","Idempotency-Key":key}
            over=owner.call("POST",path,json.dumps({"amount_cents":4100}).encode(),headers)
            body=json.dumps({"amount_cents":2500}).encode();created=owner.call("POST",path,body,headers);replay=owner.call("POST",path,body,headers)
            conflict=owner.call("POST",path,json.dumps({"amount_cents":2600}).encode(),headers)
            r.db(f"UPDATE refund_obligations SET status='cancelled' WHERE id={ids[1]}")
            cancelled=owner.call("POST",f"/api/v1/admin/refund-obligations/{ids[1]}/deferred/materialize",body,{**headers,"Idempotency-Key":key+"-cancelled"})
            parent=r.db(f"SELECT status,CAST(deferred_remaining AS CHAR) FROM refund_obligations WHERE id={ids[0]}")
            child=r.db(f"SELECT source_type,CAST(amount AS CHAR),status,provenance,approval_source,reason,CAST(metadata AS CHAR),(parent_obligation_id IS NOT NULL) FROM refund_obligations WHERE parent_obligation_id={ids[0]}")
            events=r.db(f"SELECT event_type,event_key,reason FROM refund_ledger_events WHERE order_id={order_id} AND event_type LIKE 'deferred.%' ORDER BY id")
            out.append({"over":over,"created":created,"replay":replay,"conflict":conflict,"cancelled":cancelled,"parent":parent,"child":child,"events":events})
            child_ids=[str(row[0]) for row in r.db(f"SELECT id FROM refund_obligations WHERE parent_obligation_id IN ({','.join(map(str,ids))})")]
            r.db(f"DELETE FROM refund_ledger_events WHERE order_id={order_id} AND event_type LIKE 'deferred.%'")
            if child_ids:r.db("DELETE FROM refund_obligations WHERE id IN ("+','.join(child_ids)+")")
            r.db(f"DELETE FROM refund_obligations WHERE id IN ({','.join(map(str,ids))})");pickup.cleanup(r.order_ids[:],reserved,seq_exists,old_seq);r.order_ids.clear();r.return_file_paths.clear()
    finally:
        if getattr(r,"order_ids",[]):pickup.cleanup(r.order_ids[:],reserved,seq_exists,old_seq)
    out=[canon(x) for x in out]
    if out[0]!=out[1]:print("admin deferred materialize differential: FAIL");print(json.dumps(out,ensure_ascii=False,sort_keys=True,default=str));return 1
    print("admin deferred materialize differential: 8 comparisons passed, 0 failed");return 0
if __name__=="__main__":raise SystemExit(main())
