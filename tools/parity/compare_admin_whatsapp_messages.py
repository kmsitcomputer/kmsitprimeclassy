#!/usr/bin/env python3
"""Differential read test for the global WhatsApp delivery ledger; synthetic row is removed."""
import importlib.util,json,pathlib,uuid
ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('return_review',ROOT/'tools/parity/compare_return_review.py');r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
def main():
    if r.db('SELECT DATABASE()')[0][0]!='primeclassy_testing':raise RuntimeError('refusing non-testing database')
    key='IMP010-WA-READ-'+uuid.uuid4().hex
    r.db("INSERT INTO whatsapp_messages (idempotency_key,purpose,event,template_class,recipient_role,agent_id,recipient_phone,body,status,attempts,max_attempts,created_by,queued_at,created_at,updated_at) VALUES ('"+key+"','test','order_shipped','full','recipient',10,'628123456789','Synthetic message body','unknown',1,3,1,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())")
    outputs=[]
    try:
        for base in (r.LARAVEL,r.GO):
            admin=r.Client(base);admin.login('super@imp010.test')
            all_rows=admin.call('GET','/api/v1/admin/integrations/whatsapp/messages?per_page=1')
            filtered=admin.call('GET','/api/v1/admin/integrations/whatsapp/messages?status=unknown&purpose=test&event=order_shipped')
            consumer=r.Client(base);consumer.login('konsumen@imp010.test');denied=consumer.call('GET','/api/v1/admin/integrations/whatsapp/messages')
            outputs.append({'all':all_rows,'filtered':filtered,'denied':denied})
        if outputs[0]!=outputs[1]:
            print('admin WhatsApp messages differential: FAIL')
            for key in outputs[0]:
                if outputs[0][key]!=outputs[1][key]:print(key,outputs[0][key],outputs[1][key])
            return 1
        row=outputs[0]['filtered'][1]['data']['data'][0]
        if row['recipient']!='62••••789' or row['can_retry'] is not True or row['needs_duplicate_risk_confirmation'] is not True:raise AssertionError('message privacy/retry flags incorrect')
        print('admin WhatsApp messages differential: 6 passed, 0 failed');return 0
    finally:
        r.db("DELETE FROM whatsapp_messages WHERE idempotency_key='"+key+"'")
if __name__=='__main__':raise SystemExit(main())
