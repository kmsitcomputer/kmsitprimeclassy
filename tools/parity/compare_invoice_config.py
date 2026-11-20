#!/usr/bin/env python3
"""Scoped Laravel ↔ Go differential for invoice display configuration."""
import importlib.util,json,pathlib
ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location("return_review_parity",ROOT/"tools/parity/compare_return_review.py");r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
def canon(v):
    if isinstance(v,dict):return {k:canon(child) for k,child in v.items()}
    if isinstance(v,(list,tuple)):return [canon(x) for x in v]
    if isinstance(v,str) and v.startswith("{"):
        try:return json.dumps(json.loads(v),sort_keys=True,separators=(",",":"),ensure_ascii=False)
        except json.JSONDecodeError:pass
    return v
def q(s):return "'"+s.replace("'","''")+"'"
def restore(agent,old):
    if old is None:r.db(f"DELETE FROM invoice_configs WHERE agent_id={agent}")
    else:r.db(f"INSERT INTO invoice_configs(agent_id,config,created_at,updated_at) VALUES ({agent},{q(old)},UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE config=VALUES(config)")
def main():
    if r.db("SELECT DATABASE()")[0][0]!="primeclassy_testing":raise RuntimeError("refusing non-testing database")
    original={int(row[0]):row[1] for row in r.db("SELECT agent_id,CAST(config AS CHAR) FROM invoice_configs WHERE agent_id IN (0,10)")};out=[]
    try:
        for base in (r.LARAVEL,r.GO):
            restore(0,original.get(0));restore(10,original.get(10))
            owner=r.Client(base);owner.login("super@imp010.test");agent=r.Client(base);agent.login("agen@imp010.test");finance=r.Client(base);finance.login("keuangan@imp010.test")
            global_before=owner.call("GET","/api/v1/invoice/config");agent_before=agent.call("GET","/api/v1/invoice/config")
            global_save=owner.call("PUT","/api/v1/invoice/config",json.dumps({"company_name":"Global <Classy>","show_sales":False,"notes":None,"ignored":"x"}).encode(),{"Content-Type":"application/json"})
            agent_save=agent.call("PUT","/api/v1/invoice/config",json.dumps({"title":"Branch Invoice","show_items":0,"contact":None}).encode(),{"Content-Type":"application/json"})
            agent_after=agent.call("GET","/api/v1/invoice/config")
            invalid=agent.call("PUT","/api/v1/invoice/config",json.dumps({"title":"x"*61,"show_voucher":"yes"}).encode(),{"Content-Type":"application/json"})
            missing_media=agent.call("PUT","/api/v1/invoice/config",json.dumps({"logo_media_id":999999999}).encode(),{"Content-Type":"application/json"})
            denied=finance.call("PUT","/api/v1/invoice/config",b"{}",{"Content-Type":"application/json"})
            states=r.db("SELECT agent_id,JSON_UNQUOTE(JSON_EXTRACT(config,'$.company_name')),JSON_TYPE(JSON_EXTRACT(config,'$.notes')),JSON_TYPE(JSON_EXTRACT(config,'$.show_sales')),JSON_TYPE(JSON_EXTRACT(config,'$.show_items')),JSON_UNQUOTE(JSON_EXTRACT(config,'$.title')),JSON_TYPE(JSON_EXTRACT(config,'$.contact')) FROM invoice_configs WHERE agent_id IN (0,10) ORDER BY agent_id")
            out.append({"global_before":global_before,"agent_before":agent_before,"global_save":global_save,"agent_save":agent_save,"agent_after":agent_after,"invalid":invalid,"missing_media":missing_media,"denied":denied,"states":states})
            restore(0,original.get(0));restore(10,original.get(10))
    finally:
        restore(0,original.get(0));restore(10,original.get(10))
    out=[canon(x) for x in out]
    if out[0]!=out[1]:print("invoice config differential: FAIL");print(json.dumps(out,ensure_ascii=False,sort_keys=True,default=str));return 1
    print("invoice config differential: 9 comparisons passed, 0 failed");return 0
if __name__=="__main__":raise SystemExit(main())
