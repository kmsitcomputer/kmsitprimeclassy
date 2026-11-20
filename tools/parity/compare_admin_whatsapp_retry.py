#!/usr/bin/env python3
"""Retry safety differential in the testing DB; gateway-disabled fixture is cleaned after each runtime."""
import importlib.util,json,pathlib,uuid
ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('return_review',ROOT/'tools/parity/compare_return_review.py');r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
def canon(v):
    if isinstance(v,dict):return {k:('<id>' if k=='id' else '<timestamp>' if k.endswith('_at') and x is not None else canon(x)) for k,x in v.items()}
    if isinstance(v,(list,tuple)):return [canon(x) for x in v]
    return v
def main():
    if r.db('SELECT DATABASE()')[0][0]!='primeclassy_testing':raise RuntimeError('refusing non-testing database')
    outs=[];key='IMP010-WA-RETRY-'+uuid.uuid4().hex
    for base in (r.LARAVEL,r.GO):
        r.db("INSERT INTO whatsapp_messages (idempotency_key,purpose,event,recipient_phone,body,status,attempts,max_attempts,created_by,queued_at,created_at,updated_at) VALUES ('"+key+"','test','test','628123456789','Synthetic retry body','unknown',1,3,1,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())")
        mid=int(r.db("SELECT id FROM whatsapp_messages WHERE idempotency_key='"+key+"'")[0][0]);c=r.Client(base);c.login('super@imp010.test');log_before=int(r.db('SELECT COALESCE(MAX(id),0) FROM activity_logs')[0][0])
        try:
            refused=c.call('POST',f'/api/v1/admin/integrations/whatsapp/messages/{mid}/retry',json.dumps({}).encode(),{'Content-Type':'application/json'})
            retried=c.call('POST',f'/api/v1/admin/integrations/whatsapp/messages/{mid}/retry',json.dumps({'accept_duplicate_risk':True}).encode(),{'Content-Type':'application/json'})
            state=r.db(f"SELECT status,attempts,skip_reason FROM whatsapp_messages WHERE id={mid}")
            audit=r.db(f"SELECT event,CAST(properties AS CHAR) FROM activity_logs WHERE id>{log_before} AND subject_id={mid} AND event='whatsapp.message.retried'")
            for row in audit:
                if row[1] and row[1]!='NULL':row[1]=json.loads(row[1])
            outs.append(canon({'refused':refused,'retried':retried,'state':state,'audit':audit}))
        finally:
            for row in r.db(f"SELECT id FROM activity_logs WHERE id>{log_before} AND subject_id={mid} AND event='whatsapp.message.retried'"):r.db(f'DELETE FROM activity_logs WHERE id={row[0]}')
            r.db(f'DELETE FROM whatsapp_messages WHERE id={mid}')
    if outs[0]!=outs[1]:print('admin WhatsApp retry differential: FAIL',outs);return 1
    if outs[0]['refused'][0]!=409 or outs[0]['retried'][0]!=200 or outs[0]['state']!=[['skipped','1','gateway_disabled']]:raise AssertionError('retry duplicate-risk or dispatch contract failed')
    print('admin WhatsApp retry differential: 7 passed, 0 failed');return 0
if __name__=='__main__':raise SystemExit(main())
