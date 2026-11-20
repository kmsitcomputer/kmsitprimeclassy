#!/usr/bin/env python3
"""Retry safety differential in the testing DB; gateway-disabled fixture is cleaned after each runtime."""
import importlib.util,json,pathlib,uuid,threading
from http.server import BaseHTTPRequestHandler,HTTPServer
ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('return_review',ROOT/'tools/parity/compare_return_review.py');r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
def canon(v):
    if isinstance(v,dict):return {k:('<id>' if k=='id' else '<timestamp>' if k.endswith('_at') and x is not None else canon(x)) for k,x in v.items()}
    if isinstance(v,(list,tuple)):return [canon(x) for x in v]
    return v
class Gateway(BaseHTTPRequestHandler):
    def do_POST(self):
        payload=json.loads(self.rfile.read(int(self.headers.get('Content-Length','0'))))
        if self.path!='/send/message' or self.headers.get('Authorization')!='Basic c3ludGhldGljOnBhc3M=' or payload.get('phone')!='628123456789@s.whatsapp.net':
            self.send_response(400);self.end_headers();return
        body=json.dumps({'code':'SUCCESS','results':{'message_id':'retry-stub-id'}}).encode()
        self.send_response(200);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
    def log_message(self,*args):pass
def quote(v):return 'NULL' if v is None or v=='NULL' else "'"+str(v).replace("'","''").replace('\\','\\\\')+"'"
def restore_settings(rows):
    r.db("DELETE FROM settings WHERE `group`='integration.whatsapp'")
    for row in rows:r.db('INSERT INTO settings (id,`key`,value,`group`,is_public,created_at,updated_at) VALUES ('+','.join(quote(v) for v in row)+')')
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
    server=HTTPServer(('0.0.0.0',8994),Gateway);threading.Thread(target=server.serve_forever,daemon=True).start();configured=[]
    try:
        for base in (r.LARAVEL,r.GO):
            old=r.db("SELECT id,`key`,value,`group`,is_public,created_at,updated_at FROM settings WHERE `group`='integration.whatsapp' ORDER BY id")
            c=r.Client(base);c.login('super@imp010.test');token='IMP010-WA-RETRY-ACTIVE-'+uuid.uuid4().hex;logs=int(r.db('SELECT COALESCE(MAX(id),0) FROM activity_logs')[0][0]);jobs_before=int(r.db('SELECT COALESCE(MAX(id),0) FROM jobs')[0][0])
            r.db("INSERT INTO whatsapp_messages (idempotency_key,purpose,event,recipient_phone,body,status,attempts,max_attempts,created_by,queued_at,created_at,updated_at) VALUES ('"+token+"','test','test','628123456789','Synthetic active retry','unknown',1,3,1,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())")
            mid=int(r.db("SELECT id FROM whatsapp_messages WHERE idempotency_key='"+token+"'")[0][0])
            try:
                update=c.call('PUT','/api/v1/admin/integrations/whatsapp',json.dumps({'enabled':True,'base_url':'http://host.docker.internal:8994','username':'synthetic','password':'pass','device_id':'synthetic-device','test_numbers':['628123456789']}).encode(),{'Content-Type':'application/json'})
                response=c.call('POST',f'/api/v1/admin/integrations/whatsapp/messages/{mid}/retry',json.dumps({'accept_duplicate_risk':True}).encode(),{'Content-Type':'application/json'})
                state=r.db(f'SELECT status,attempts,provider_message_id,error_class,error_message,skip_reason FROM whatsapp_messages WHERE id={mid}')
                jobs=r.db(f'SELECT queue,payload,attempts FROM jobs WHERE id>{jobs_before}')
                audit=r.db(f"SELECT event,CAST(properties AS CHAR) FROM activity_logs WHERE id>{logs} AND event='whatsapp.message.retried' AND subject_id={mid}")
                for row in audit:
                    if row[1] and row[1]!='NULL':row[1]=json.loads(row[1])
                configured.append(canon({'update':update[0],'response':response,'state':state,'jobs':jobs,'audit':audit}))
            finally:
                r.db(f'DELETE FROM whatsapp_messages WHERE id={mid}')
                for row in r.db(f"SELECT id FROM jobs WHERE id>{jobs_before}"):r.db(f'DELETE FROM jobs WHERE id={row[0]}')
                for row in r.db(f"SELECT id FROM activity_logs WHERE id>{logs} AND event IN ('whatsapp.message.retried','whatsapp.settings.updated')"):r.db(f'DELETE FROM activity_logs WHERE id={row[0]}')
                restore_settings(old)
    finally:server.shutdown();server.server_close()
    if configured[0]!=configured[1]:print('admin WhatsApp active retry differential: FAIL',configured);return 1
    if configured[0]['state']!=[['sent','1','retry-stub-id','NULL','NULL','NULL']] or configured[0]['jobs']:
        raise AssertionError(f'configured sync retry delivery mismatch: {configured}')
    print('admin WhatsApp retry differential: 12 passed, 0 failed');return 0
if __name__=='__main__':raise SystemExit(main())
