#!/usr/bin/env python3
"""Agent device-status differential against a local GOWA stub; restores test DB rows/settings."""
import importlib.util,json,pathlib,threading
from http.server import BaseHTTPRequestHandler,HTTPServer
ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('return_review',ROOT/'tools/parity/compare_return_review.py');r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
class Gateway(BaseHTTPRequestHandler):
    creates=0
    sent=[]
    def respond(self,obj):
        data=json.dumps(obj).encode();self.send_response(200);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(data)));self.end_headers();self.wfile.write(data)
    def do_GET(self):
        if self.headers.get('Authorization')!='Basic c3ludGhldGljOnBhc3M=':self.send_response(401);self.end_headers();return
        if self.path.startswith('/statics/qrcode/'):
            data=b'PNGBYTES';self.send_response(200);self.send_header('Content-Type','image/png');self.send_header('Content-Length',str(len(data)));self.end_headers();self.wfile.write(data);return
        if self.path.endswith('/status') and self.path.endswith('pc-status-fixture/status'):self.respond({'results':{'is_connected':True,'is_logged_in':True}})
        elif self.path.endswith('/status'):self.respond({'results':{'is_connected':False,'is_logged_in':False}})
        elif self.path=='/devices/pc-status-fixture':self.respond({'results':{'phone_number':'6281234567890'}})
        elif self.path.endswith('/login'):self.respond({'results':{'qr_link':'http://localhost:3000/statics/qrcode/scan.png','qr_duration':30}})
        else:self.send_response(404);self.end_headers()
    def do_POST(self):
        if self.headers.get('Authorization')!='Basic c3ludGhldGljOnBhc3M=':self.send_response(401);self.end_headers();return
        if self.path=='/send/message':
            Gateway.sent.append((self.headers.get('X-Device-Id'),json.loads(self.rfile.read(int(self.headers.get('Content-Length','0'))))))
            self.respond({'code':'SUCCESS','results':{'message_id':'WA-TEST'}});return
        if self.path=='/devices':type(self).creates+=1;self.respond({'code':'SUCCESS','results':{'device_id':'created'}})
        elif '/login/code?' in self.path:self.respond({'results':{'pair_code':'ABCD-1234'}})
        elif self.path.endswith('/logout') or self.path.endswith('/reconnect'):self.respond({'code':'SUCCESS'})
        else:self.send_response(404);self.end_headers()
    def do_DELETE(self):
        if self.headers.get('Authorization')=='Basic c3ludGhldGljOnBhc3M=':self.respond({'code':'SUCCESS'})
        else:self.send_response(401);self.end_headers()
    def log_message(self,*args):pass
def q(v):return 'NULL' if v is None or v=='NULL' else "'"+str(v).replace("'","''").replace('\\','\\\\')+"'"
def restore_settings(rows):
    r.db("DELETE FROM settings WHERE `group`='integration.whatsapp'")
    for row in rows:r.db('INSERT INTO settings (id,`key`,value,`group`,is_public,created_at,updated_at) VALUES ('+','.join(q(v) for v in row)+')')
def restore_device(agent,rows):
    r.db(f'DELETE FROM agent_whatsapp_devices WHERE agent_id={agent}')
    for row in rows:r.db('INSERT INTO agent_whatsapp_devices (id,agent_id,device_id,state,phone_masked,paired_at,last_checked_at,replaced_count,created_at,updated_at) VALUES ('+','.join(q(v) for v in row)+')')
def restore_test_numbers(agent,rows):
    r.db(f'DELETE FROM agent_whatsapp_test_numbers WHERE agent_id={agent}')
    for row in rows:r.db('INSERT INTO agent_whatsapp_test_numbers (id,agent_id,msisdn,registered_by,created_at,updated_at) VALUES ('+','.join(q(v) for v in row)+')')
def scrub(pair):
    status,body=pair
    if isinstance(body,dict) and isinstance(body.get('data'),dict) and isinstance(body['data'].get('device'),dict):
        body['data']['device']['paired_at']='TIMESTAMP' if body['data']['device']['paired_at'] else None
        body['data']['device']['last_checked_at']='TIMESTAMP' if body['data']['device']['last_checked_at'] else None
    return status,body
def normalize_json(value):
    if isinstance(value,str):return value.replace('\\u2022','•')
    if isinstance(value,list):return [normalize_json(item) for item in value]
    if isinstance(value,dict):return {key:normalize_json(item) for key,item in value.items()}
    return value
def normalize_side(rows,logs):
    normalized=[]
    for state,phone,paired,last in rows:normalized.append((state,phone,'TIME' if paired else None,'TIME' if last else None))
    audit=[]
    for causer,subject,subject_id,event,props in logs:audit.append((causer,subject,event,normalize_json(props)))
    return normalized,audit
def main():
    if r.db('SELECT DATABASE()')[0][0]!='primeclassy_testing':raise RuntimeError('refusing non-testing database')
    agent=int(r.db("SELECT id FROM users WHERE email='agen@imp010.test'")[0][0]);server=HTTPServer(('0.0.0.0',8997),Gateway);threading.Thread(target=server.serve_forever,daemon=True).start();outputs=[];side=[]
    try:
        for base in (r.LARAVEL,r.GO):
            Gateway.creates=0
            Gateway.sent=[]
            settings=r.db("SELECT id,`key`,value,`group`,is_public,created_at,updated_at FROM settings WHERE `group`='integration.whatsapp' ORDER BY id")
            old_device=r.db(f'SELECT id,agent_id,device_id,state,phone_masked,paired_at,last_checked_at,replaced_count,created_at,updated_at FROM agent_whatsapp_devices WHERE agent_id={agent}')
            old_numbers=r.db(f'SELECT id,agent_id,msisdn,registered_by,created_at,updated_at FROM agent_whatsapp_test_numbers WHERE agent_id={agent}')
            message_before=int(r.db('SELECT COALESCE(MAX(id),0) FROM whatsapp_messages')[0][0])
            before=int(r.db('SELECT COALESCE(MAX(id),0) FROM activity_logs')[0][0]);client=r.Client(base);assert client.login('super@imp010.test')==200
            try:
                configured=client.call('PUT','/api/v1/admin/integrations/whatsapp',json.dumps({'enabled':False,'base_url':'http://host.docker.internal:8997','username':'synthetic','password':'pass','device_id':'fixture'}).encode(),{'Content-Type':'application/json'})
                if configured[0]!=200:raise AssertionError(f'WhatsApp test config failed: {configured!r}')
                r.db(f"INSERT INTO agent_whatsapp_devices(agent_id,device_id,state,created_at,updated_at) VALUES ({agent},'pc-status-fixture','not_paired',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE device_id='pc-status-fixture',state='not_paired',phone_masked=NULL,paired_at=NULL,last_checked_at=NULL,replaced_count=0")
                actor=r.Client(base);assert actor.login('agen@imp010.test')==200
                status=actor.call('GET','/api/v1/agent/whatsapp')
                unauth=r.Client(base).call('GET','/api/v1/agent/whatsapp')
                admin=r.Client(base);assert admin.login('admin@imp010.test')==200
                foreign=admin.call('GET','/api/v1/agent/whatsapp?agent_id=999999')
                status_row=r.db(f"SELECT state,phone_masked,paired_at,last_checked_at FROM agent_whatsapp_devices WHERE agent_id={agent}")
                r.db(f'DELETE FROM agent_whatsapp_devices WHERE agent_id={agent}')
                create=actor.call('POST','/api/v1/agent/whatsapp/device')
                create_again=actor.call('POST','/api/v1/agent/whatsapp/device')
                create_row=r.db(f"SELECT state,phone_masked,paired_at,last_checked_at FROM agent_whatsapp_devices WHERE agent_id={agent}")
                logout=actor.call('POST','/api/v1/agent/whatsapp/logout')
                reconnect=actor.call('POST','/api/v1/agent/whatsapp/reconnect')
                replace=actor.call('POST','/api/v1/agent/whatsapp/replace',json.dumps({'confirm':True}).encode(),{'Content-Type':'application/json'})
                paircode=actor.call('POST','/api/v1/agent/whatsapp/pair/code',json.dumps({'phone':'0813 9990 0077'}).encode(),{'Content-Type':'application/json'})
                qr=actor.call('POST','/api/v1/agent/whatsapp/pair/qr')
                pairing_row=r.db(f"SELECT state,phone_masked,paired_at,last_checked_at FROM agent_whatsapp_devices WHERE agent_id={agent}")
                r.db(f"UPDATE agent_whatsapp_devices SET state='connected',paired_at=UTC_TIMESTAMP(6),phone_masked='62••••890' WHERE agent_id={agent}")
                r.db(f"INSERT INTO agent_whatsapp_test_numbers(agent_id,msisdn,registered_by,created_at,updated_at) VALUES ({agent},'6281234567890',{agent},UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE registered_by=VALUES(registered_by)")
                enabled=client.call('PUT','/api/v1/admin/integrations/whatsapp',json.dumps({'enabled':True,'base_url':'http://host.docker.internal:8997','username':'synthetic','password':'pass','device_id':'fixture'}).encode(),{'Content-Type':'application/json'})
                if enabled[0]!=200:raise AssertionError(f'WhatsApp test-send config failed: {enabled!r}')
                send=actor.call('POST','/api/v1/agent/whatsapp/test-send',json.dumps({'number':'0812 3456 7890','message':'Hi {agent}'}).encode(),{'Content-Type':'application/json'})
                final_row=r.db(f"SELECT state,phone_masked,paired_at,last_checked_at FROM agent_whatsapp_devices WHERE agent_id={agent}")
                logs=r.db(f"SELECT causer_id,subject_type,subject_id,event,CAST(properties AS CHAR) FROM activity_logs WHERE id>{before} AND event LIKE 'whatsapp.device.%' ORDER BY id")
                for log in logs:
                    if log[4] and log[4]!='NULL':log[4]=json.loads(log[4])
                send_rows=r.db(f'SELECT purpose,event,agent_id,recipient_phone,body,status,attempts,provider_message_id,sender_device_id,error_class,error_message,skip_reason,created_by FROM whatsapp_messages WHERE id>{message_before} ORDER BY id')
                send_logs=r.db(f"SELECT causer_id,subject_type,subject_id,event,CAST(properties AS CHAR) FROM activity_logs WHERE id>{before} AND event='whatsapp.agent_test.sent' ORDER BY id")
                for log in send_logs:
                    if log[4] and log[4]!='NULL':log[4]=json.loads(log[4])
                send_side=[]
                for row in send_rows:send_side.append((*row[:8],'DEVICE' if row[8] else None,*row[9:]))
                normalized_send_logs=[(row[0],row[1],row[3],normalize_json((json.loads(row[4]) if isinstance(row[4],str) else row[4]) if row[4] and row[4]!='NULL' else None)) for row in send_logs]
                send_response=send
                if isinstance(send_response[1],dict) and isinstance(send_response[1].get('data'),dict):
                    send_response[1]['data']['id']='MESSAGE_ID'
                    send_response[1]['data']['sender_suffix']='DEVICE'
                sent_proof=(('DEVICE' if Gateway.sent[-1][0] else None),Gateway.sent[-1][1]) if Gateway.sent else None
                outputs.append([scrub(status),unauth,foreign,scrub(create),scrub(create_again),scrub(logout),scrub(reconnect),scrub(replace),scrub(paircode),scrub(qr),send_response,Gateway.creates,len(Gateway.sent)]);side.append((normalize_side(status_row,[])[0],normalize_side(create_row,[])[0],normalize_side(final_row,[])[0],normalize_side([],logs)[1],send_side,normalized_send_logs,sent_proof,normalize_side(pairing_row,[])[0]))
            finally:
                r.db(f"DELETE FROM activity_logs WHERE id>{before} AND (event LIKE 'whatsapp.device.%' OR event IN ('whatsapp.settings.updated','whatsapp.agent_test.sent'))")
                r.db(f'DELETE FROM whatsapp_messages WHERE id>{message_before}')
                restore_test_numbers(agent,old_numbers)
                restore_device(agent,old_device);restore_settings(settings)
    finally:server.shutdown();server.server_close()
    if outputs[0]!=outputs[1] or side[0]!=side[1]:print('agent WhatsApp status differential: FAIL');print(json.dumps({'responses':outputs,'side_effects':side},default=str,ensure_ascii=False));return 1
    status,body=outputs[0][0];data=body['data']
    if outputs[0][1][0]!=401 or outputs[0][2][0]!=403:raise AssertionError('WhatsApp status authorization contract mismatch')
    if status!=200 or data['device']['state']!='connected' or data['device']['phone_masked']!='62••••890' or not data['gateway_ready'] or side[0][0][0][0]!='connected':raise AssertionError('status/device refresh contract failed')
    create_status,create_body=outputs[0][3]
    if create_status!=201 or create_body['data']['device']['state']!='not_paired' or side[0][1][0][0]!='not_paired':raise AssertionError('device creation contract failed')
    if outputs[0][4][0]!=201 or outputs[0][4][1]['data']['device']['state']!='not_paired' or outputs[0][11]!=2:raise AssertionError('idempotent device creation contract failed')
    if outputs[0][5][0]!=200 or outputs[0][5][1]['data']['device']['state']!='not_paired':raise AssertionError('device logout contract failed')
    if outputs[0][6][0]!=200 or outputs[0][6][1]['data']['device']['state']!='not_paired':raise AssertionError('device reconnect contract failed')
    if outputs[0][7][0]!=200 or outputs[0][7][1]['data']['device']['replaced_count']!=1:raise AssertionError('device replacement contract failed')
    if outputs[0][8][0]!=200 or outputs[0][8][1]['data']['code']!='ABCD-1234' or outputs[0][8][1]['data']['device']['state']!='pairing':raise AssertionError('pair-code contract failed')
    if outputs[0][9][0]!=200 or outputs[0][9][1]['data']['image']!='data:image/png;base64,UE5HQllURVM=' or outputs[0][9][1]['data']['expires_in']!=30:raise AssertionError('QR pairing contract failed')
    if side[0][7][0][0]!='pairing':raise AssertionError('pairing state was not persisted')
    if outputs[0][10][0]!=200 or outputs[0][10][1]['data']['status']!='sent' or outputs[0][10][1]['data']['recipient']!='62••••890':raise AssertionError('test-send response contract failed')
    if outputs[0][11]!=2 or outputs[0][12]!=1 or side[0][4][0][5]!='sent' or side[0][4][0][3]!='6281234567890' or side[0][4][0][4]!='Hi (agent)':raise AssertionError('test-send ledger/payload mismatch')
    if side[0][5][0][2]!='whatsapp.agent_test.sent' or side[0][6][0]!='DEVICE' or side[0][6][1].get('message')!='Hi (agent)':raise AssertionError('test-send audit or explicit device header missing')
    print('agent WhatsApp status/create/logout/reconnect/replace/pair-code/QR/test-send differential: 9 responses, ledger DB effects, audits and 2 authorization checks passed, 0 failed');return 0
if __name__=='__main__':raise SystemExit(main())
