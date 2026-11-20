#!/usr/bin/env python3
"""SMTP connection and own-address test-email differential against a local capture server."""
import importlib.util, json, pathlib, socket, socketserver, threading

ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location("return_review",ROOT/"tools/parity/compare_return_review.py")
r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
captured=[]

class SMTP(socketserver.StreamRequestHandler):
    def handle(self):
        self.wfile.write(b"220 localhost synthetic SMTP\r\n"); data=False; lines=[]
        while True:
            raw=self.rfile.readline()
            if not raw: return
            line=raw.decode("utf-8","replace").rstrip("\r\n")
            if data:
                if line==".": captured.append("\n".join(lines)); data=False; lines=[]; self.wfile.write(b"250 queued synthetic\r\n")
                else: lines.append(line[1:] if line.startswith("..") else line)
            elif line.upper().startswith("EHLO") or line.upper().startswith("HELO"):
                self.wfile.write(b"250-localhost\r\n250 SIZE 1000000\r\n")
            elif line.upper().startswith("MAIL FROM") or line.upper().startswith("RCPT TO"):
                self.wfile.write(b"250 accepted\r\n")
            elif line.upper()=="DATA": data=True; self.wfile.write(b"354 end with dot\r\n")
            elif line.upper()=="QUIT": self.wfile.write(b"221 bye\r\n"); return
            elif line.upper()=="RSET": self.wfile.write(b"250 reset\r\n")
            else: self.wfile.write(b"250 ok\r\n")

class SMTPServer(socketserver.ThreadingTCPServer):
    allow_reuse_address=True

def quote(v): return "NULL" if v is None or v=="NULL" else "'"+str(v).replace("'","''").replace("\\","\\\\")+"'"
def restore(rows):
    r.db("DELETE FROM settings WHERE `group`='integration.smtp'")
    for row in rows:r.db("INSERT INTO settings (id,`key`,value,`group`,is_public,created_at,updated_at) VALUES ("+",".join(quote(v) for v in row)+")")

def main():
    if r.db("SELECT DATABASE()")[0][0]!="primeclassy_testing":raise RuntimeError("refusing non-testing database")
    port=2525
    server=SMTPServer(("0.0.0.0",port),SMTP);server.daemon_threads=True
    thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start();outs=[]
    try:
        for base in (r.LARAVEL,r.GO):
            old=r.db("SELECT id,`key`,value,`group`,is_public,created_at,updated_at FROM settings WHERE `group`='integration.smtp' ORDER BY id")
            c=r.Client(base);c.login("super@imp010.test");logs=int(r.db("SELECT COALESCE(MAX(id),0) FROM activity_logs")[0][0])
            try:
                incomplete_connection=c.call("POST","/api/v1/admin/integrations/smtp/test-connection")
                incomplete_email=c.call("POST","/api/v1/admin/integrations/smtp/test-email")
                saved=c.call("PUT","/api/v1/admin/integrations/smtp",json.dumps({"enabled":False,"host":"host.docker.internal","port":port,"encryption":"none","from_address":"no-reply@example.invalid","from_name":"PrimeClassy Test","timeout":8}).encode(),{"Content-Type":"application/json"})
                connection=c.call("POST","/api/v1/admin/integrations/smtp/test-connection")
                email=c.call("POST","/api/v1/admin/integrations/smtp/test-email")
                outs.append({"incomplete_connection":incomplete_connection,"incomplete_email":incomplete_email,"saved":saved,"connection":connection,"email":email})
            finally:
                for row in r.db(f"SELECT id FROM activity_logs WHERE id>{logs} AND event='smtp.settings.updated'"):r.db(f"DELETE FROM activity_logs WHERE id={row[0]}")
                restore(old)
    finally:server.shutdown();server.server_close()
    if outs[0]!=outs[1]:print("admin SMTP test differential: FAIL",outs);return 1
    if outs[0]["connection"][0]!=200 or outs[0]["connection"][1]["data"]!={"ok":True,"detail":None}:raise AssertionError(f"SMTP connection probe contract mismatch: {outs}")
    if outs[0]["email"][0]!=200 or outs[0]["email"][1]["data"]!={"ok":True,"detail":None}:raise AssertionError("SMTP test-email contract mismatch")
    incomplete={"ok":False,"detail":"Host, port, dan alamat pengirim wajib diisi sebelum SMTP dapat diaktifkan."}
    if outs[0]["incomplete_connection"][1]["data"]!=incomplete or outs[0]["incomplete_email"][1]["data"]!=incomplete:raise AssertionError("incomplete SMTP response mismatch")
    if len(captured)!=2 or any("super@imp010.test" not in message or "Subject: Uji SMTP PrimeClassy" not in message for message in captured):raise AssertionError(f"test mail recipient/content mismatch: {captured}")
    print("admin SMTP test differential: 9 passed, 0 failed");return 0

if __name__=="__main__":raise SystemExit(main())
