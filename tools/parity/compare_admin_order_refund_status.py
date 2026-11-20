#!/usr/bin/env python3
"""Differential for fulfillment-shortfall refund status and actual payment reversal."""
import importlib.util,json,pathlib,uuid
ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('return_review',ROOT/'tools/parity/compare_return_review.py');r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
pickup_spec=importlib.util.spec_from_file_location('pickup',ROOT/'tools/parity/compare_return_pickup_schedule.py');pickup=importlib.util.module_from_spec(pickup_spec);pickup_spec.loader.exec_module(pickup)

def canon(v):
    if isinstance(v,dict):return {k:('<id>' if k in ('id','order_id') else '<timestamp>' if k.endswith('_at') and x is not None else canon(x)) for k,x in v.items()}
    if isinstance(v,(list,tuple)):return [canon(x) for x in v]
    return v

def main():
    if r.db('SELECT DATABASE()')[0][0]!='primeclassy_testing':raise RuntimeError('refusing non-testing database')
    token=uuid.uuid4().hex[:10];reserved=int(r.db('SELECT quantity_reserved FROM product_stocks WHERE agent_id=10 AND product_id=3')[0][0]);seq=r.db("SELECT `value` FROM settings WHERE `key`='orders.invoice_sequence'");seq_exists=bool(seq);old_seq=seq[0][0] if seq else '0';outs=[]
    try:
        for base in (r.LARAVEL,r.GO):
            r.order_ids=[];r.return_file_paths=[]
            admin,order_id,_,_,item_id=r.create_case(base,token,base[-4:]);finance=r.Client(base);finance.login('keuangan@imp010.test')
            r.db(f"UPDATE orders SET paid_amount=total_amount,remaining_amount=0,payment_status='paid' WHERE id={order_id}")
            now=r.db('SELECT UTC_TIMESTAMP()')[0][0];first=int(r.db('SELECT COALESCE(MAX(id),0)+1 FROM order_item_adjustments')[0][0]);second=first+1
            r.db(f"INSERT INTO order_item_adjustments(id,order_item_id,adjusted_by,quantity_reduced,reason,refund_amount,refund_status,created_at,updated_at) VALUES ({first},{item_id},11,1,'Synthetic status failed',125.00,'pending','{now}','{now}'),({second},{item_id},11,1,'Synthetic status processed',125.00,'pending','{now}','{now}')")
            before=int(r.db('SELECT COALESCE(MAX(id),0) FROM activity_logs')[0][0])
            failed=finance.call('PATCH',f'/api/v1/admin/order-refunds/{first}/status',json.dumps({'refund_status':'failed'}).encode(),{'Content-Type':'application/json'})
            processed=finance.call('PATCH',f'/api/v1/admin/order-refunds/{second}/status',json.dumps({'refund_status':'processed'}).encode(),{'Content-Type':'application/json'})
            replay=finance.call('PATCH',f'/api/v1/admin/order-refunds/{first}/status',json.dumps({'refund_status':'processed'}).encode(),{'Content-Type':'application/json'})
            rows=r.db(f"SELECT refund_status FROM order_item_adjustments WHERE id IN ({first},{second}) ORDER BY id")
            order=r.db(f"SELECT CAST(paid_amount AS CHAR),CAST(remaining_amount AS CHAR),payment_status FROM orders WHERE id={order_id}")
            audit=r.db(f"SELECT event,CAST(properties AS CHAR) FROM activity_logs WHERE id>{before} AND event='order_item_adjustment.refund_status_changed' ORDER BY id")
            for row in audit:
                if row[1] and row[1]!='NULL':row[1]=json.loads(row[1])
            consumer=r.Client(base);consumer.login('konsumen@imp010.test');denied=consumer.call('PATCH',f'/api/v1/admin/order-refunds/{first}/status',json.dumps({'refund_status':'failed'}).encode(),{'Content-Type':'application/json'})
            outs.append(canon({'failed':failed,'processed':processed,'replay':replay,'rows':rows,'order':order,'audit':audit,'denied':denied}))
            pickup.cleanup(r.order_ids[:],reserved,seq_exists,old_seq);r.order_ids.clear();r.return_file_paths.clear()
    finally:
        if getattr(r,'order_ids',[]):pickup.cleanup(r.order_ids[:],reserved,seq_exists,old_seq)
    if outs[0]!=outs[1]:print('admin order refund status differential: FAIL',json.dumps(outs,ensure_ascii=False,default=str));return 1
    if outs[0]['failed'][0]!=200 or outs[0]['processed'][0]!=200 or outs[0]['replay'][0]!=422 or outs[0]['denied'][0]!=403 or outs[0]['rows']!=[['failed'],['processed']]:raise AssertionError('refund status transitions or gates mismatch')
    print('admin order refund status differential: 10 passed, 0 failed');return 0

if __name__=='__main__':raise SystemExit(main())
