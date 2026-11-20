#!/usr/bin/env python3
"""Allow-listed admin WhatsApp test send differential using a local GOWA stub only."""
import importlib.util, json, pathlib, threading, uuid
from http.server import BaseHTTPRequestHandler, HTTPServer

ROOT = pathlib.Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("return_review", ROOT / "tools/parity/compare_return_review.py")
r = importlib.util.module_from_spec(spec); spec.loader.exec_module(r)
received = []

class Stub(BaseHTTPRequestHandler):
    def do_POST(self):
        received.append((self.path, self.headers.get("Authorization"), self.headers.get("X-Device-Id"), json.loads(self.rfile.read(int(self.headers["Content-Length"])))) )
        body = json.dumps({"code":"SUCCESS", "results":{"message_id":"stub-message-id"}}).encode()
        self.send_response(200); self.send_header("Content-Type","application/json"); self.send_header("Content-Length",str(len(body))); self.end_headers(); self.wfile.write(body)
    def log_message(self, *args): pass

def quote(v):
    return "NULL" if v is None or v == "NULL" else "'" + str(v).replace("'", "''").replace("\\", "\\\\") + "'"

def restore(rows):
    r.db("DELETE FROM settings WHERE `group`='integration.whatsapp'")
    for row in rows:
        r.db("INSERT INTO settings (id,`key`,value,`group`,is_public,created_at,updated_at) VALUES (" + ",".join(quote(v) for v in row) + ")")

def canon(v):
    if isinstance(v, dict): return {k: ("<id>" if k == "id" else "<timestamp>" if k.endswith("_at") and x is not None else canon(x)) for k,x in v.items()}
    if isinstance(v, (list,tuple)): return [canon(x) for x in v]
    return v

def main():
    if r.db("SELECT DATABASE()")[0][0] != "primeclassy_testing": raise RuntimeError("refusing non-testing database")
    server = HTTPServer(("0.0.0.0", 8992), Stub); thread = threading.Thread(target=server.serve_forever, daemon=True); thread.start()
    outs=[]; key="IMP010-WA-TEST-"+uuid.uuid4().hex; number="628123456789"
    try:
        for base in (r.LARAVEL, r.GO):
            old=r.db("SELECT id,`key`,value,`group`,is_public,created_at,updated_at FROM settings WHERE `group`='integration.whatsapp' ORDER BY id")
            c=r.Client(base); c.login("super@imp010.test")
            before=int(r.db("SELECT COALESCE(MAX(id),0) FROM whatsapp_messages")[0][0])
            try:
                updated=c.call("PUT","/api/v1/admin/integrations/whatsapp",json.dumps({"enabled":True,"base_url":"http://host.docker.internal:8992","username":"synthetic","password":"pass","device_id":"synthetic-device","test_numbers":[number]}).encode(),{"Content-Type":"application/json"})
                sent=c.call("POST","/api/v1/admin/integrations/whatsapp/test-message",json.dumps({"number":"+62 812-3456-789","message":" synthetic test {name} "}).encode(),{"Content-Type":"application/json"})
                denied=c.call("POST","/api/v1/admin/integrations/whatsapp/test-message",json.dumps({"number":"628987654321"}).encode(),{"Content-Type":"application/json"})
                missing=c.call("POST","/api/v1/admin/integrations/whatsapp/test-message",b"{}",{"Content-Type":"application/json"})
                rows=r.db(f"SELECT purpose,event,recipient_phone,body,status,attempts,max_attempts,provider_message_id,skip_reason FROM whatsapp_messages WHERE id>{before} ORDER BY id")
                outs.append(canon({"update":updated[0],"sent":sent,"denied":denied,"missing":missing,"rows":rows}))
            finally:
                r.db(f"DELETE FROM whatsapp_messages WHERE id>{before}")
                restore(old)
    finally:
        server.shutdown(); server.server_close()
    if outs[0] != outs[1]: print("admin WhatsApp test-message differential: FAIL", outs); return 1
    if len(received)!=2 or any(x[0]!="/send/message" or x[1]!="Basic c3ludGhldGljOnBhc3M=" or x[2]!="synthetic-device" or x[3]!={"phone":number+"@s.whatsapp.net","message":"synthetic test (name)"} for x in received):
        raise AssertionError(f"unexpected local stub request: {received}")
    if outs[0]["update"]!=200 or outs[0]["sent"][0]!=200 or outs[0]["sent"][1]["data"]["status"]!="sent" or outs[0]["rows"][0][4]!="sent" or outs[0]["denied"][0]!=422 or outs[0]["missing"][0]!=422:
        raise AssertionError("allow-listed test send did not persist sent status")
    print("admin WhatsApp test-message differential: 9 passed, 0 failed")
    return 0

if __name__ == "__main__": raise SystemExit(main())
