#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for refund-obligation owner approval."""
import importlib.util, json, pathlib, uuid

ROOT = pathlib.Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("return_review_parity", ROOT / "tools/parity/compare_return_review.py")
r = importlib.util.module_from_spec(spec); spec.loader.exec_module(r)
pickup_spec = importlib.util.spec_from_file_location("return_pickup_parity", ROOT / "tools/parity/compare_return_pickup_schedule.py")
pickup = importlib.util.module_from_spec(pickup_spec); pickup_spec.loader.exec_module(pickup)

def canon(value):
    if isinstance(value, dict):
        return {key: ("<id>" if key in {"id","order_id","approved_by","created_by"} else "<timestamp>" if key.endswith("_at") and child else "<fixture-key>" if key in {"obligation_key","obligation_no"} else canon(child)) for key,child in value.items()}
    if isinstance(value,(list,tuple)):
        return [canon(child) for child in value]
    if isinstance(value,str) and value.startswith("{"):
        try:return json.dumps(json.loads(value),sort_keys=True,separators=(",",":"))
        except json.JSONDecodeError:pass
    return value

def main():
    if r.db("SELECT DATABASE()")[0][0]!="primeclassy_testing":raise RuntimeError("refusing non-testing database")
    token=uuid.uuid4().hex[:12];reserved=int(r.db("SELECT quantity_reserved FROM product_stocks WHERE agent_id=10 AND product_id=3")[0][0])
    seq=r.db("SELECT `value` FROM settings WHERE `key`='orders.invoice_sequence'");sequence_exists,old_sequence=bool(seq),seq[0][0] if seq else "0"
    outputs=[]
    try:
        for base in (r.LARAVEL,r.GO):
            r.order_ids,r.return_file_paths=[],[]
            _,order_id,_,_,_=r.create_case(base,token,base[-4:])
            obligation_id=int(r.db("SELECT COALESCE(MAX(id),0)+1 FROM refund_obligations")[0][0]);blocked_id=obligation_id+1
            now=r.db("SELECT UTC_TIMESTAMP(6)")[0][0];suffix=base[-4:]
            for oid,status,kind in [(obligation_id,"pending","approve"),(blocked_id,"cancelled","blocked")]:
                r.db(f"INSERT INTO refund_obligations (id,obligation_no,order_id,source_type,obligation_key,amount,status,provenance,created_at,updated_at) VALUES ({oid},'PARITY-APP-{token}-{suffix}-{kind}',{order_id},'manual','parity-app-{token}-{suffix}-{kind}',100.00,'{status}','forward','{now}','{now}')")
            owner=r.Client(base);owner.login("super@imp010.test")
            path=f"/api/v1/admin/refund-obligations/{obligation_id}/approve"
            approved=owner.call("POST",path)
            replay=owner.call("POST",path)
            nonapprovable=owner.call("POST",f"/api/v1/admin/refund-obligations/{blocked_id}/approve")
            missing_id=int(r.db("SELECT COALESCE(MAX(id),0)+100000 FROM refund_obligations")[0][0])
            missing=owner.call("POST",f"/api/v1/admin/refund-obligations/{missing_id}/approve")
            finance=r.Client(base);finance.login("keuangan@imp010.test")
            finance_denied=finance.call("POST",path)
            consumer=r.Client(base);consumer.login("konsumen@imp010.test")
            consumer_denied=consumer.call("POST",path)
            state=r.db(f"SELECT status,approved_by,approval_source,(approved_at IS NOT NULL) FROM refund_obligations WHERE id={obligation_id}")
            audit=r.db(f"SELECT event_type,actor_type,CAST(payload AS CHAR) FROM refund_ledger_events WHERE obligation_id={obligation_id} AND event_type='obligation.approved'")
            outputs.append({"approved":approved,"replay":replay,"nonapprovable":nonapprovable,"missing":missing,"finance_denied":finance_denied,"consumer_denied":consumer_denied,"state":state,"audit":audit})
            r.db(f"DELETE FROM refund_ledger_events WHERE order_id={order_id} AND event_type='obligation.approved'")
            r.db(f"DELETE FROM refund_obligations WHERE id IN ({obligation_id},{blocked_id})")
            pickup.cleanup(r.order_ids[:],reserved,sequence_exists,old_sequence)
            r.order_ids.clear();r.return_file_paths.clear()
    finally:
        if getattr(r,"order_ids",[]):pickup.cleanup(r.order_ids[:],reserved,sequence_exists,old_sequence)
    outputs=[canon(value) for value in outputs]
    if outputs[0]!=outputs[1]:
        print("admin refund-obligation approval differential: FAIL");print(json.dumps(outputs,ensure_ascii=False,sort_keys=True,default=str));return 1
    print("admin refund-obligation approval differential: 8 comparisons passed, 0 failed");return 0

if __name__=="__main__":raise SystemExit(main())
