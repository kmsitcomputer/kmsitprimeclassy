#!/usr/bin/env python3
"""Scoped read-only differential for the per-order refund ledger projection."""
import importlib.util, pathlib

ROOT=pathlib.Path(__file__).resolve().parents[2]
def load(name,path):
    spec=importlib.util.spec_from_file_location(name,ROOT/path); mod=importlib.util.module_from_spec(spec); spec.loader.exec_module(mod); return mod
r=load("return_review",pathlib.Path("tools/parity/compare_return_review.py"))
pickup=load("return_pickup",pathlib.Path("tools/parity/compare_return_pickup_schedule.py"))

def canonical(value):
    if isinstance(value,dict):
        return {k:("<id>" if k in {"id","order_id","parent_obligation_id","obligation_id","attempt_id","payout_id"} and v is not None else
                   "<timestamp>" if k.endswith("_at") and v is not None else canonical(v)) for k,v in value.items()}
    if isinstance(value,(list,tuple)): return [canonical(v) for v in value]
    return value

def main():
    if r.db("SELECT DATABASE()")[0][0]!="primeclassy_testing": raise RuntimeError("refusing non-testing database")
    outputs=[]
    reserved=int(r.db("SELECT quantity_reserved FROM product_stocks WHERE agent_id=10 AND product_id=3")[0][0])
    seq=r.db("SELECT `value` FROM settings WHERE `key`='orders.invoice_sequence'")
    sequence_exists,old_sequence=bool(seq),seq[0][0] if seq else "0"
    try:
        for base in (r.LARAVEL,r.GO):
            r.order_ids,r.return_file_paths=[],[]
            admin,order_id,*_=r.create_case(base,"refund-ledger-read","ledger")
            listing=admin.call("GET",f"/api/v1/admin/orders/{order_id}/refunds")
            consumer=r.Client(base); consumer.login("konsumen@imp010.test")
            denied=consumer.call("GET",f"/api/v1/admin/orders/{order_id}/refunds")
            outputs.append({"listing":listing,"consumer_denied":denied})
            pickup.cleanup(r.order_ids[:],reserved,sequence_exists,old_sequence)
            r.order_ids.clear(); r.return_file_paths.clear()
    finally:
        if getattr(r,"order_ids",[]): pickup.cleanup(r.order_ids[:],reserved,sequence_exists,old_sequence)
    outputs=[canonical(v) for v in outputs]
    if outputs[0]!=outputs[1]:
        print("admin order refund ledger differential: FAIL",outputs)
        return 1
    if outputs[0]["listing"][0]!=200 or outputs[0]["consumer_denied"][0]!=403:
        raise AssertionError("refund ledger read or role denial contract failed")
    print("admin order refund ledger differential: 3 passed, 0 failed")
    return 0

if __name__=="__main__": raise SystemExit(main())
