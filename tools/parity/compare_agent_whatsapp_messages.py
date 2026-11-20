#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for an Agent's masked WhatsApp delivery history."""
import importlib.util, json, pathlib, uuid
ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location("return_review_parity",ROOT/"tools/parity/compare_return_review.py");r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)

def main():
    if r.db("SELECT DATABASE()")[0][0]!="primeclassy_testing":raise RuntimeError("refusing non-testing database")
    key="codex-agent-wa-history-"+uuid.uuid4().hex
    r.db(f"INSERT INTO whatsapp_messages(idempotency_key,purpose,event,agent_id,recipient_phone,body,status,attempts,max_attempts,error_class,skip_reason,queued_at,sent_at,created_at,updated_at) VALUES ('{key}','transaction','order_shipped',10,'6281234567890','MUST NOT BE EXPOSED','sent',1,3,NULL,NULL,'2026-11-21 01:02:03','2026-11-21 01:02:04','2026-11-21 01:02:03','2026-11-21 01:02:04')")
    try:
        out=[]
        for base in (r.LARAVEL,r.GO):
            c=r.Client(base);status=c.login("agen@imp010.test")
            if status!=200:raise RuntimeError(f"Agen login failed at {base}: {status}")
            out.append(c.call("GET","/api/v1/agent/whatsapp/messages"))
            admin=r.Client(base);status=admin.login("admin@imp010.test")
            if status!=200:raise RuntimeError(f"Admin login failed at {base}: {status}")
            out.append(admin.call("GET","/api/v1/agent/whatsapp/messages?agent_id=999999"))
        if out[0]!=out[2] or out[1]!=out[3]:
            print("agent WhatsApp message differential: FAIL");print(json.dumps(out,ensure_ascii=False,sort_keys=True,default=str));return 1
        status,body=out[0]
        if status!=200 or not body.get("data") or body["data"][0].get("recipient")!="62••••890" or "body" in body["data"][0]:
            raise AssertionError("masked message projection contract failed: "+repr(out[0]))
        print("agent WhatsApp messages differential: 4 response comparisons passed, 0 failed")
        return 0
    finally:r.db(f"DELETE FROM whatsapp_messages WHERE idempotency_key='{key}'")
if __name__=="__main__":raise SystemExit(main())
