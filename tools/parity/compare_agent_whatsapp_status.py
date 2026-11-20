#!/usr/bin/env python3
"""Agent device-status differential against a local GOWA stub; restores test DB rows/settings."""
import importlib.util,json,pathlib,threading
from http.server import BaseHTTPRequestHandler,HTTPServer
ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('return_review',ROOT/'tools/parity/compare_return_review.py');r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
class Gateway(BaseHTTPRequestHandler):
    def respond(self,obj):
        data=json.dumps(obj).encode();self.send_response(200);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(data)));self.end_headers();self.wfile.write(data)
    def do_GET(self):
        if self.headers.get('Authorization')!='Basic c3ludGhldGljOnBhc3M=':self.send_response(401);self.end_headers();return
        if self.path.endswith('/status') and self.path.endswith('pc-status-fixture/status'):self.respond({'results':{'is_connected':True,'is_logged_in':True}})
        elif self.path.endswith('/status'):self.respond({'results':{'is_connected':False,'is_logged_in':False}})
        elif self.path=='/devices/pc-status-fixture':self.respond({'results':{'phone_number':'6281234567890'}})
        else:self.send_response(404);self.end_headers()
    def do_POST(self):
        if self.headers.get('Authorization')!='Basic c3ludGhldGljOnBhc3M=':self.send_response(401);self.end_headers();return
        if self.path=='/devices':self.respond({'code':'SUCCESS','results':{'device_id':'created'}})
        else:self.send_response(404);self.end_headers()
    def log_message(self,*args):pass
def q(v):return 'NULL' if v is None or v=='NULL' else "'"+str(v).replace("'","''").replace('\\','\\\\')+"'"
def restore_settings(rows):
    r.db("DELETE FROM settings WHERE `group`='integration.whatsapp'")
    for row in rows:r.db('INSERT INTO settings (id,`key`,value,`group`,is_public,created_at,updated_at) VALUES ('+','.join(q(v) for v in row)+')')
def restore_device(agent,rows):
    r.db(f'DELETE FROM agent_whatsapp_devices WHERE agent_id={agent}')
    for row in rows:r.db('INSERT INTO agent_whatsapp_devices (id,agent_id,device_id,state,phone_masked,paired_at,last_checked_at,replaced_count,created_at,updated_at) VALUES ('+','.join(q(v) for v in row)+')')
def scrub(pair):
    status,body=pair
    if isinstance(body,dict) and isinstance(body.get('data'),dict) and isinstance(body['data'].get('device'),dict):
        body['data']['device']['paired_at']='TIMESTAMP' if body['data']['device']['paired_at'] else None
        body['data']['device']['last_checked_at']='TIMESTAMP' if body['data']['device']['last_checked_at'] else None
    return status,body
def normalize_side(rows,logs):
    normalized=[]
    for state,phone,paired,last in rows:normalized.append((state,phone,'TIME' if paired else None,'TIME' if last else None))
    audit=[]
    for causer,subject,subject_id,event,props in logs:audit.append((causer,subject,event,props))
    return normalized,audit
def main():
    if r.db('SELECT DATABASE()')[0][0]!='primeclassy_testing':raise RuntimeError('refusing non-testing database')
    agent=int(r.db("SELECT id FROM users WHERE email='agen@imp010.test'")[0][0]);server=HTTPServer(('0.0.0.0',8997),Gateway);threading.Thread(target=server.serve_forever,daemon=True).start();outputs=[];side=[]
    try:
        for base in (r.LARAVEL,r.GO):
            settings=r.db("SELECT id,`key`,value,`group`,is_public,created_at,updated_at FROM settings WHERE `group`='integration.whatsapp' ORDER BY id")
            old_device=r.db(f'SELECT id,agent_id,device_id,state,phone_masked,paired_at,last_checked_at,replaced_count,created_at,updated_at FROM agent_whatsapp_devices WHERE agent_id={agent}')
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
                create_row=r.db(f"SELECT state,phone_masked,paired_at,last_checked_at FROM agent_whatsapp_devices WHERE agent_id={agent}")
                logs=r.db(f"SELECT causer_id,subject_type,subject_id,event,CAST(properties AS CHAR) FROM activity_logs WHERE id>{before} AND event IN ('whatsapp.device.paired','whatsapp.device.created') ORDER BY id")
                for log in logs:
                    if log[4] and log[4]!='NULL':log[4]=json.loads(log[4])
                outputs.append([scrub(status),unauth,foreign,scrub(create)]);side.append((normalize_side(status_row,[])[0],normalize_side(create_row,[])[0],normalize_side([],logs)[1]))
            finally:
                r.db(f"DELETE FROM activity_logs WHERE id>{before} AND event IN ('whatsapp.device.paired','whatsapp.settings.updated')")
                restore_device(agent,old_device);restore_settings(settings)
    finally:server.shutdown();server.server_close()
    if outputs[0]!=outputs[1] or side[0]!=side[1]:print('agent WhatsApp status differential: FAIL');print(json.dumps({'responses':outputs,'side_effects':side},default=str,ensure_ascii=False));return 1
    status,body=outputs[0][0];data=body['data']
    if outputs[0][1][0]!=401 or outputs[0][2][0]!=403:raise AssertionError('WhatsApp status authorization contract mismatch')
    if status!=200 or data['device']['state']!='connected' or data['device']['phone_masked']!='62••••890' or not data['gateway_ready'] or side[0][0][0][0]!='connected':raise AssertionError('status/device refresh contract failed')
    create_status,create_body=outputs[0][3]
    if create_status!=201 or create_body['data']['device']['state']!='not_paired' or side[0][1][0][0]!='not_paired':raise AssertionError('device creation contract failed')
    print('agent WhatsApp status/device-create differential: 2 responses, 2 database states, audit and 2 authorization checks passed, 0 failed');return 0
if __name__=='__main__':raise SystemExit(main())
