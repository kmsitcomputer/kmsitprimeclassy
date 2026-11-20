#!/usr/bin/env python3
"""GOWA connection test differential against a local stub only; restores settings/audit rows."""
import importlib.util,json,pathlib,threading,uuid
from http.server import BaseHTTPRequestHandler,HTTPServer
ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('return_review',ROOT/'tools/parity/compare_return_review.py');r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.headers.get('Authorization')!='Basic c3ludGhldGljOnBhc3M=': self.send_response(401);self.end_headers();return
        data={'results':{'is_connected':True,'is_logged_in':True,'device_id':'device-synthetic'}} if self.path=='/app/status' else {'results':[{'id':'one'},{'id':'two'}]}
        body=json.dumps(data).encode();self.send_response(200);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
    def log_message(self,*args): pass
def quote(v):return 'NULL' if v is None or v=='NULL' else "'"+str(v).replace("'","''").replace('\\','\\\\')+"'"
def restore(rows):
    r.db("DELETE FROM settings WHERE `group`='integration.whatsapp'")
    for row in rows:r.db('INSERT INTO settings (id,`key`,value,`group`,is_public,created_at,updated_at) VALUES ('+','.join(quote(v) for v in row)+')')
def main():
    if r.db('SELECT DATABASE()')[0][0]!='primeclassy_testing':raise RuntimeError('refusing non-testing database')
    server=HTTPServer(('0.0.0.0',8991),Handler);thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start();outs=[]
    try:
        for base in (r.LARAVEL,r.GO):
            old=r.db("SELECT id,`key`,value,`group`,is_public,created_at,updated_at FROM settings WHERE `group`='integration.whatsapp' ORDER BY id");client=r.Client(base);client.login('super@imp010.test');log_before=int(r.db('SELECT COALESCE(MAX(id),0) FROM activity_logs')[0][0])
            try:
                updated=client.call('PUT','/api/v1/admin/integrations/whatsapp',json.dumps({'enabled':False,'base_url':'http://host.docker.internal:8991','username':'synthetic','password':'pass','device_id':'device-synthetic'}).encode(),{'Content-Type':'application/json'})
                status=client.call('POST','/api/v1/admin/integrations/whatsapp/test-connection')
                audit=r.db(f"SELECT event,CAST(properties AS CHAR) FROM activity_logs WHERE id>{log_before} AND event='whatsapp.connection.tested' ORDER BY id")
                for row in audit:
                    if row[1] and row[1]!='NULL':row[1]=json.loads(row[1])
                outs.append({'update_status':updated[0],'status':status,'audit':audit})
            finally:
                for row in r.db(f"SELECT id FROM activity_logs WHERE id>{log_before} AND event IN ('whatsapp.connection.tested','whatsapp.connection.test','whatsapp.settings.updated')"):r.db(f'DELETE FROM activity_logs WHERE id={row[0]}')
                restore(old)
    finally:server.shutdown();server.server_close()
    if outs[0]!=outs[1]:print('admin WhatsApp connection differential: FAIL',outs);return 1
    result=outs[0]['status'][1]['data']
    if outs[0]['status'][0]!=200 or result!={'state':'connected','reachable':True,'connected':True,'logged_in':True,'devices':2,'device_id':'device-synthetic','detail':None}:raise AssertionError('GOWA status contract mismatch')
    print('admin WhatsApp connection differential: 5 passed, 0 failed');return 0
if __name__=='__main__':raise SystemExit(main())
