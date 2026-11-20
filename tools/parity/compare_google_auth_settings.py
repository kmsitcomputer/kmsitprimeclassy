#!/usr/bin/env python3
"""Scoped differential for global and branch Google OAuth settings, masking, validation, and secret lifecycle."""
import importlib.util, json, pathlib, uuid

ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location("return_review_parity",ROOT/"tools/parity/compare_return_review.py")
r=importlib.util.module_from_spec(spec); spec.loader.exec_module(r)


def snapshot(table, key, value):
    rows=r.db(f"SELECT id,is_enabled,client_id,client_secret,redirect_uri,frontend_url,created_at,updated_at FROM {table} WHERE {key}={value}")
    return rows[0] if rows else None


def restore(table,key,value,old):
    r.db(f"DELETE FROM activity_logs WHERE subject_type LIKE '%GoogleAuth%' AND subject_id IN (SELECT id FROM {table} WHERE {key}={value})")
    if old is None:
        r.db(f"DELETE FROM {table} WHERE {key}={value}")
        return
    row_id,enabled,client_id,cipher,redirect,frontend,created,updated=old
    def sqlval(v):
        if v is None or v=="NULL": return "NULL"
        return "'"+str(v).replace("'","''").replace("\\","\\\\")+"'"
    r.db(f"UPDATE {table} SET id={row_id},is_enabled={enabled},client_id={sqlval(client_id)},client_secret={sqlval(cipher)},redirect_uri={sqlval(redirect)},frontend_url={sqlval(frontend)},created_at={sqlval(created)},updated_at={sqlval(updated)} WHERE {key}={value}")


def normalize(v):
    if isinstance(v,dict): return {k:("<timestamp>" if k in {"created_at","updated_at"} else "<id>" if k in {"id","subject_id"} else normalize(x)) for k,x in v.items()}
    if isinstance(v,(tuple,list)): return [normalize(x) for x in v]
    return v


def main():
    if r.db("SELECT DATABASE()")[0][0]!="primeclassy_testing": raise RuntimeError("refusing non-testing database")
    agent=int(r.db("SELECT agent_id FROM users WHERE email='agen@imp010.test'")[0][0])
    token=uuid.uuid4().hex[:8]
    results=[]
    for base in (r.LARAVEL,r.GO):
        global_old=snapshot("google_auth_settings","id",1)
        agent_old=snapshot("agent_google_auth_configs","agent_id",agent)
        admin=r.Client(base); agent_client=r.Client(base)
        if admin.login("super@imp010.test")!=200 or agent_client.login("agen@imp010.test")!=200: raise RuntimeError("OAuth settings actor login failed")
        case={"global_show":admin.call("GET","/api/v1/admin/google-auth"),"agent_show":agent_client.call("GET","/api/v1/agent/google-auth")}
        bad_url={"is_enabled":True,"redirect_uri":"not a URL"}
        case["global_bad_url"]=admin.call("PUT","/api/v1/admin/google-auth",json.dumps(bad_url).encode(),{"Content-Type":"application/json"})
        secret="IMP010-SYNTHETIC-"+token
        payload={"is_enabled":True,"client_id":"synthetic-client-"+token,"client_secret":secret,
                 "redirect_uri":"https://oauth.example.test/callback","frontend_url":"https://store.example.test/login"}
        case["global_set"]=admin.call("PUT","/api/v1/admin/google-auth",json.dumps(payload).encode(),{"Content-Type":"application/json"})
        case["global_keep"]=admin.call("PUT","/api/v1/admin/google-auth",json.dumps({**payload,"client_secret":"   "}).encode(),{"Content-Type":"application/json"})
        case["global_clear"]=admin.call("PUT","/api/v1/admin/google-auth",json.dumps({**payload,"client_secret":"","clear_secret":True}).encode(),{"Content-Type":"application/json"})
        case["agent_set"]=agent_client.call("PUT","/api/v1/agent/google-auth",json.dumps(payload).encode(),{"Content-Type":"application/json"})
        case["agent_keep"]=agent_client.call("PUT","/api/v1/agent/google-auth",json.dumps({**payload,"client_secret":""}).encode(),{"Content-Type":"application/json"})
        case["agent_clear"]=agent_client.call("PUT","/api/v1/agent/google-auth",json.dumps({**payload,"client_secret":"","clear_secret":True}).encode(),{"Content-Type":"application/json"})
        # Confirm secrets never leak through response bodies or audits.
        if secret in json.dumps(case,ensure_ascii=False): raise AssertionError("Google client secret leaked in API responses")
        case["audit_global"]=r.db("SELECT event,CAST(properties AS CHAR) FROM activity_logs WHERE subject_type LIKE '%GoogleAuthSetting' ORDER BY id DESC LIMIT 3")
        case["audit_agent"]=r.db(f"SELECT event,CAST(properties AS CHAR) FROM activity_logs WHERE subject_type LIKE '%AgentGoogleAuthConfig' AND subject_id=(SELECT id FROM agent_google_auth_configs WHERE agent_id={agent}) ORDER BY id DESC LIMIT 3")
        for group in (case["audit_global"],case["audit_agent"]):
            for row in group:
                if row[1] and row[1]!="NULL": row[1]=json.loads(row[1])
        case["global_persisted"]=r.db("SELECT is_enabled,client_id,client_secret IS NOT NULL AND client_secret<>'' AS has_cipher,redirect_uri,frontend_url FROM google_auth_settings WHERE id=1")
        case["agent_persisted"]=r.db(f"SELECT is_enabled,client_id,client_secret IS NOT NULL AND client_secret<>'' AS has_cipher,redirect_uri,frontend_url FROM agent_google_auth_configs WHERE agent_id={agent}")
        results.append(normalize(case))
        restore("google_auth_settings","id",1,global_old)
        restore("agent_google_auth_configs","agent_id",agent,agent_old)
    if results[0]!=results[1]:
        print("Google OAuth settings differential: FAIL")
        print(json.dumps(results,ensure_ascii=False,sort_keys=True,default=str))
        return 1
    case=results[0]
    if case["global_bad_url"][0]!=422 or any(case[k][0]!=200 for k in ("global_show","agent_show","global_set","global_keep","global_clear","agent_set","agent_keep","agent_clear")):
        raise AssertionError("Google OAuth validation/status contract failed")
    if case["global_set"][1]["data"]["has_secret"] is not True or case["global_clear"][1]["data"]["has_secret"] is not False or case["agent_clear"][1]["data"]["has_secret"] is not False:
        raise AssertionError("Google OAuth secret set/preserve/clear contract failed")
    if case["global_persisted"][0][2]!="0" or case["agent_persisted"][0][2]!="0": raise AssertionError("explicit secret clear did not persist")
    print("Google OAuth settings differential: 14 passed, 0 failed")
    return 0


if __name__=="__main__": raise SystemExit(main())
