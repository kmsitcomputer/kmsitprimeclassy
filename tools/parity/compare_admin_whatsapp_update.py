#!/usr/bin/env python3
"""Scoped Admin WhatsApp config update differential; synthetic settings are restored in finally."""
import importlib.util, json, pathlib, uuid
import re

ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location("return_review_parity",ROOT/"tools/parity/compare_return_review.py")
r=importlib.util.module_from_spec(spec); spec.loader.exec_module(r)


def quote(value):
    if value is None or value=="NULL": return "NULL"
    return "'"+str(value).replace("'","''").replace("\\","\\\\")+"'"


def save_settings(rows):
    r.db("DELETE FROM settings WHERE `group`='integration.whatsapp'")
    for row in rows:
        r.db("INSERT INTO settings (id,`key`,value,`group`,is_public,created_at,updated_at) VALUES ("+
             ",".join(quote(v) for v in row)+")")


def norm(value):
    if isinstance(value,dict): return {k:normalize_secret(v,k) for k,v in value.items()}
    if isinstance(value,(list,tuple)): return [norm(v) for v in value]
    return value

def differences(a,b,path="$",out=None):
    if out is None: out=[]
    if isinstance(a,dict) and isinstance(b,dict):
        for key in sorted(set(a)|set(b)):
            if key not in a or key not in b: out.append(f"{path}.{key}: key missing on {'Laravel' if key not in a else 'Go'}")
            else: differences(a[key],b[key],path+"."+key,out)
    elif isinstance(a,list) and isinstance(b,list):
        if len(a)!=len(b): out.append(f"{path}: length {len(a)} vs {len(b)}")
        else:
            for i,(x,y) in enumerate(zip(a,b)): differences(x,y,f"{path}[{i}]",out)
    elif a!=b: out.append(f"{path}: {a!r} != {b!r}")
    return out


def normalize_secret(value,key):
    if key in {"id","subject_id"}: return "<id>"
    if key in {"created_at","updated_at"}: return "<timestamp>"
    if isinstance(value,(dict,list,tuple)): return norm(value)
    return value

def normalize_cli_json(value):
    # MariaDB's batch CLI escapes JSON backslashes; decode those once after parsing the column.
    if isinstance(value,dict): return {k:normalize_cli_json(v) for k,v in value.items()}
    if isinstance(value,list): return [normalize_cli_json(v) for v in value]
    if isinstance(value,str):
        value=value.replace(r'\/', '/')
        value=value.replace(r'\n', '\n').replace(r'\r', '\r').replace(r'\t', '\t')
        return re.sub(r'\\u([0-9a-fA-F]{4})', lambda m: chr(int(m.group(1),16)), value)
    return value


def main():
    if r.db("SELECT DATABASE()")[0][0]!="primeclassy_testing": raise RuntimeError("refusing non-testing database")
    token=uuid.uuid4().hex[:8]; secret="IMP010-WA-SYNTHETIC-"+token; outputs=[]
    base_payload={"enabled":True,"base_url":"http://127.0.0.1:9999/","username":"synthetic-wa-user","password":secret,"device_id":"synthetic-device",
      "timeout":11,"max_attempts":4,"backoff_seconds":30,"limits":{"per_recipient_per_hour":7,"global_per_minute":22},
      "test_numbers":["08123456789","628123456789"],"otp":{"enabled":True,"ttl_minutes":8,"max_attempts":6,"cooldown_seconds":45,"limit":4,"limit_window_minutes":12},
      "events":{"order_created":True,"payment_paid":False}}
    for base in (r.LARAVEL,r.GO):
        old=r.db("SELECT id,`key`,value,`group`,is_public,created_at,updated_at FROM settings WHERE `group`='integration.whatsapp' ORDER BY id")
        client=r.Client(base)
        if client.login("super@imp010.test")!=200: raise RuntimeError("Super Admin login failed")
        log_before=int(r.db("SELECT COALESCE(MAX(id),0) FROM activity_logs")[0][0])
        try:
            bad_url=client.call("PUT","/api/v1/admin/integrations/whatsapp",json.dumps({"base_url":"http://169.254.169.254"}).encode(),{"Content-Type":"application/json"})
            bad_number=client.call("PUT","/api/v1/admin/integrations/whatsapp",json.dumps({"test_numbers":["not-a-number"]}).encode(),{"Content-Type":"application/json"})
            saved=client.call("PUT","/api/v1/admin/integrations/whatsapp",json.dumps(base_payload).encode(),{"Content-Type":"application/json"})
            keep=client.call("PUT","/api/v1/admin/integrations/whatsapp",json.dumps({"password":""}).encode(),{"Content-Type":"application/json"})
            state=r.db("SELECT `key`,value FROM settings WHERE `group`='integration.whatsapp' AND (`key` IN ('wa.enabled','wa.base_url','wa.username','wa.device_id','wa.timeout','wa.max_attempts','wa.backoff_seconds','wa.rate.recipient_hour','wa.rate.global_minute','wa.test_numbers','wa.otp.enabled','wa.otp.ttl_minutes','wa.otp.max_attempts','wa.otp.cooldown_seconds','wa.otp.limit','wa.otp.limit_window_minutes','wa.event.order_created','wa.event.payment_paid')) ORDER BY `key`")
            password_cipher=r.db("SELECT value FROM settings WHERE `group`='integration.whatsapp' AND `key`='wa.password'")
            audit=r.db(f"SELECT event,CAST(properties AS CHAR) FROM activity_logs WHERE id>{log_before} AND event='whatsapp.settings.updated' ORDER BY id")
            for row in audit:
                if row[1] and row[1]!="NULL": row[1]=normalize_cli_json(json.loads(row[1]))
            audit_text=json.dumps(audit,ensure_ascii=False)
            if secret in audit_text or (password_cipher and password_cipher[0][0]==secret): raise AssertionError("WhatsApp password leaked")
            consumer=r.Client(base); consumer.login("konsumen@imp010.test")
            denied=consumer.call("PUT","/api/v1/admin/integrations/whatsapp",json.dumps({"enabled":False}).encode(),{"Content-Type":"application/json"})
            outputs.append(norm({"bad_url":bad_url,"bad_number":bad_number,"saved":saved,"keep":keep,"state":state,
                                 "has_cipher":bool(password_cipher),"audit":audit,"denied":denied}))
        finally:
            new_logs=r.db(f"SELECT id FROM activity_logs WHERE id>{log_before} AND event='whatsapp.settings.updated'")
            for row in new_logs: r.db(f"DELETE FROM activity_logs WHERE id={row[0]}")
            save_settings(old)
    if outputs[0]!=outputs[1]:
        print("admin WhatsApp settings update differential: FAIL")
        print("\n".join(differences(outputs[0],outputs[1])[:60]))
        return 1
    out=outputs[0]
    if out["bad_url"][0]!=422 or out["bad_number"][0]!=422 or out["saved"][0]!=200 or out["keep"][0]!=200 or out["denied"][0]!=403:
        raise AssertionError("WhatsApp update validation, authorization, or save contract failed")
    if out["saved"][1]["data"]["has_password"] is not True or out["keep"][1]["data"]["has_password"] is not True or not out["has_cipher"]:
        raise AssertionError("WhatsApp write-only password preservation failed")
    print("admin WhatsApp settings update differential: 9 passed, 0 failed")
    return 0


if __name__=="__main__": raise SystemExit(main())
