#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for creating a refund attempt."""
import importlib.util,json,pathlib,uuid
ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location("return_review_parity",ROOT/"tools/parity/compare_return_review.py");r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
pickup_spec=importlib.util.spec_from_file_location("return_pickup_parity",ROOT/"tools/parity/compare_return_pickup_schedule.py");pickup=importlib.util.module_from_spec(pickup_spec);pickup_spec.loader.exec_module(pickup)

def canon(value):
    if isinstance(value,dict):return {k:("<id>" if k in {"id","order_id","obligation_id","attempt_id","processed_by"} else "<timestamp>" if k.endswith("_at") and v else canon(v)) for k,v in value.items()}
    if isinstance(value,(list,tuple)):return [canon(v) for v in value]
    if isinstance(value,str) and value.startswith("{"):
        try:return json.dumps(json.loads(value),sort_keys=True,separators=(",",":"))
        except json.JSONDecodeError:pass
    return value

def main():
    if r.db("SELECT DATABASE()")[0][0]!="primeclassy_testing":raise RuntimeError("refusing non-testing database")
    token=uuid.uuid4().hex[:12];reserved=int(r.db("SELECT quantity_reserved FROM product_stocks WHERE agent_id=10 AND product_id=3")[0][0]);seq=r.db("SELECT `value` FROM settings WHERE `key`='orders.invoice_sequence'");seq_exists,old_seq=bool(seq),seq[0][0] if seq else "0";outputs=[]
    try:
        for base in (r.LARAVEL,r.GO):
            r.order_ids,r.return_file_paths=[],[]
            _,order_id,_,_,_=r.create_case(base,token,base[-4:]);now=r.db("SELECT UTC_TIMESTAMP(6)")[0][0];suffix=base[-4:]
            ids=[int(r.db("SELECT COALESCE(MAX(id),0)+1 FROM refund_obligations")[0][0]),0];ids[1]=ids[0]+1
            for i,oid in enumerate(ids):r.db(f"INSERT INTO refund_obligations(id,obligation_no,order_id,source_type,obligation_key,amount,status,provenance,created_at,updated_at) VALUES ({oid},'PARITY-ATT-{token}-{suffix}-{i}',{order_id},'manual','parity-att-{token}-{suffix}-{i}',100.00,'approved','forward','{now}','{now}')")
            finance=r.Client(base);finance.login("keuangan@imp010.test");consumer=r.Client(base);consumer.login("konsumen@imp010.test")
            path=f"/api/v1/admin/refund-obligations/{ids[0]}/attempts";key=f"codex-attempt-{token}"
            body={"amount_cents":3000,"method":"bank_transfer","external_reference":"REF-TEST","destination_snapshot":{"bank":"Test Bank","account":"0001"}}
            created=finance.call("POST",path,json.dumps(body).encode(),{"Content-Type":"application/json","Idempotency-Key":key})
            replay=finance.call("POST",path,json.dumps({**body,"external_reference":"changed replay"}).encode(),{"Content-Type":"application/json","Idempotency-Key":key})
            key_conflict=finance.call("POST",path,json.dumps({**body,"amount_cents":3500}).encode(),{"Content-Type":"application/json","Idempotency-Key":key})
            excess=finance.call("POST",path,json.dumps({**body,"amount_cents":8000}).encode(),{"Content-Type":"application/json","Idempotency-Key":f"{key}-excess"})
            missing_key=finance.call("POST",path,json.dumps(body).encode(),{"Content-Type":"application/json"})
            invalid=finance.call("POST",path,json.dumps({"amount_cents":3000}).encode(),{"Content-Type":"application/json","Idempotency-Key":f"{key}-invalid"})
            collision_path=f"/api/v1/admin/refund-obligations/{ids[1]}/attempts"
            collision=finance.call("POST",collision_path,json.dumps(body).encode(),{"Content-Type":"application/json","Idempotency-Key":key})
            denied=consumer.call("POST",path,json.dumps(body).encode(),{"Content-Type":"application/json","Idempotency-Key":f"{key}-denied"})
            state=r.db(f"SELECT attempt_no,method,CAST(amount AS CHAR),status,idempotency_key,external_reference,CAST(destination_snapshot AS CHAR) FROM refund_attempts WHERE obligation_id={ids[0]} ORDER BY id")
            audit=r.db(f"SELECT event_type,actor_type,CAST(payload AS CHAR) FROM refund_ledger_events WHERE order_id={order_id} AND event_type='attempt.created'")
            outputs.append({"created":created,"replay":replay,"key_conflict":key_conflict,"excess":excess,"missing_key":missing_key,"invalid":invalid,"collision":collision,"denied":denied,"state":state,"audit":audit})
            attempt_ids=[int(row[0]) for row in r.db(f"SELECT id FROM refund_attempts WHERE obligation_id IN ({ids[0]},{ids[1]})")]
            r.db(f"DELETE FROM refund_ledger_events WHERE order_id={order_id} AND event_type='attempt.created'")
            if attempt_ids:r.db("DELETE FROM refund_attempts WHERE id IN ("+",".join(map(str,attempt_ids))+")")
            r.db(f"DELETE FROM refund_obligations WHERE id IN ({ids[0]},{ids[1]})")
            pickup.cleanup(r.order_ids[:],reserved,seq_exists,old_seq);r.order_ids.clear();r.return_file_paths.clear()
    finally:
        if getattr(r,"order_ids",[]):pickup.cleanup(r.order_ids[:],reserved,seq_exists,old_seq)
    outputs=[canon(o) for o in outputs]
    if outputs[0]!=outputs[1]:print("admin refund attempt create differential: FAIL");print(json.dumps(outputs,ensure_ascii=False,sort_keys=True,default=str));return 1
    print("admin refund attempt create differential: 10 comparisons passed, 0 failed");return 0
if __name__=="__main__":raise SystemExit(main())
